<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

/**
 * lib/Billing/Cycle/BillingCharges.php
 *
 * S-BILLING-MODULE-2 — charges queued for a lease's invoices.
 *
 * Two kinds:
 *   once     bills on the FIRST rental invoice whose period ends on/after
 *            bill_from (a damage charge, an extra wash, an admin fee…)
 *   monthly  bills once per calendar month from bill_from until bill_until
 *            (or until cancelled) — a standing fee (yard parking, a second
 *            tracker…). One line per month: the first rental invoice of the
 *            month carries it.
 *
 * "Billed" is DERIVED, never stored — the same live-aware rule cartage uses:
 * a charge (or a monthly charge's month) is billed when a LIVE invoice
 * (non-void, non-deleted) carries a line with reference_type =
 * 'billing_charge' and reference_id = the charge. So voiding, deleting or
 * regenerating a draft puts the charge straight back in the queue, a dry run
 * (rolled back) never consumes it, and no removal path needs a hook.
 *
 * Who adds the lines: InvoiceGenerator::createFromLease() calls dueLines()
 * for rental invoices (billing types partial_start / full_month /
 * partial_end / single_period; invoice types regular / final) — every
 * generation path (workbench, approved run, monthly job, a lease's Generate
 * Invoice, close, activation) therefore picks charges up, under the lease
 * row lock it already holds, so two concurrent generations cannot both
 * bill the same charge.
 *
 * Amounts are in the lease's currency. Positive only — a credit belongs on a
 * credit note (and a credit line could leave a subtotal negative, which the
 * generator refuses).
 *
 * @session S-BILLING-MODULE-2
 */
final class BillingCharges
{
    private function __construct() {}

    public const ITEM_TYPES = [
        'other'             => 'Service / other charge',
        'damage'            => 'Damage / repair',
        'fuel'              => 'Fuel',
        'wash'              => 'Wash',
        'sweep'             => 'Sweep',
        'manual_adjustment' => 'Adjustment',
    ];

    /** Invoice shapes that carry charges (rental invoices only). */
    public const CHARGE_BILLING_TYPES = ['partial_start', 'full_month', 'partial_end', 'single_period'];
    public const CHARGE_INVOICE_TYPES = ['regular', 'final'];

    /**
     * Line items for every charge due on an invoice for this lease + period.
     * Called INSIDE createFromLease's transaction (lease row already locked).
     * Never throws: a lookup failure bills without charges (logged).
     *
     * @return array<int, array> line arrays in the generator's extra-line shape
     */
    public static function dueLines(int $leaseId, string $periodStart, string $periodEnd): array
    {
        try {
            $rows = \db_select(
                "SELECT c.* FROM billing_charges c
                  WHERE c.lease_id = ? AND c.status = 'active' AND c.bill_from <= ?
                    AND (c.recurrence = 'once' OR c.bill_until IS NULL OR c.bill_until >= ?)
                  ORDER BY c.id",
                [$leaseId, $periodEnd, $periodStart]
            );
        } catch (\Throwable $e) {
            error_log('[BillingCharges] dueLines failed for lease #' . $leaseId . ': ' . $e->getMessage());
            return [];
        }
        if (!$rows) return [];

        $monthStart = substr($periodStart, 0, 7) . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $lines = [];
        foreach ($rows as $c) {
            $cid = (int) $c['id'];
            if ($c['recurrence'] === 'once') {
                if (self::liveLineExists($cid, null, null)) continue;
                $desc = (string) $c['description'];
            } else {
                // One line per calendar month of the invoice's period start.
                if ($monthEnd < (string) $c['bill_from']) continue;
                if (self::liveLineExists($cid, $monthStart, $monthEnd)) continue;
                $desc = $c['description'] . ' — ' . date('F Y', strtotime($monthStart));
            }
            $lines[] = [
                'item_type'      => (string) $c['item_type'],
                'description'    => mb_substr($desc, 0, 500),
                'quantity'       => (string) $c['quantity'],
                'unit'           => null,
                'unit_price'     => (string) $c['unit_price'],
                'amount'         => (string) $c['amount'],
                'is_credit'      => 0,
                'taxable'        => (int) $c['taxable'],
                'reference_type' => 'billing_charge',
                'reference_id'   => $cid,
                'period_start'   => $periodStart,
                'period_end'     => $periodEnd,
            ];
        }
        return $lines;
    }

    /**
     * Is there a live invoice line for this charge (in the given month)?
     * Month = the invoice's billing_period_start month.
     */
    private static function liveLineExists(int $chargeId, ?string $monthStart, ?string $monthEnd): bool
    {
        $sql = "SELECT 1 FROM invoice_line_items li
                  JOIN invoices i ON i.id = li.invoice_id
                 WHERE li.reference_type = 'billing_charge' AND li.reference_id = ?
                   AND i.deleted_at IS NULL AND i.status <> 'void'";
        $params = [$chargeId];
        if ($monthStart !== null) {
            $sql .= " AND i.billing_period_start BETWEEN ? AND ?";
            $params[] = $monthStart;
            $params[] = $monthEnd;
        }
        return (bool) \db_row($sql . ' LIMIT 1', $params);
    }

    /**
     * Charges for a list/tab, with their billing state.
     *
     * @param array $f  state (pending|billed|cancelled|all), lease_id, customer_id, month (YYYY-MM)
     */
    public static function list(array $f = []): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($f['lease_id']))    { $where[] = 'c.lease_id = ?';    $params[] = (int) $f['lease_id']; }
        if (!empty($f['customer_id'])) { $where[] = 'c.customer_id = ?'; $params[] = (int) $f['customer_id']; }
        $state = (string) ($f['state'] ?? 'all');
        if ($state === 'cancelled') {
            $where[] = "c.status = 'cancelled'";
        } elseif ($state !== 'all') {
            $where[] = "c.status = 'active'";
        }
        $month = (string) ($f['month'] ?? '');
        $ms = $me = null;
        if (BillingCycles::isValidMonth($month)) {
            [$ms, $me] = BillingCycles::monthBounds($month);
            // Charges that could bill in (or already billed in) this month.
            $where[] = "c.bill_from <= ? AND (c.recurrence = 'once' OR c.bill_until IS NULL OR c.bill_until >= ?)";
            $params[] = $me;
            $params[] = $ms;
        }

        $rows = \db_select(
            "SELECT c.*, l.contract_number, l.status AS lease_status, l.currency,
                    cu.company_name, COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number,
                    ub.name AS created_by_name, uc.name AS cancelled_by_name
               FROM billing_charges c
               JOIN leases l ON l.id = c.lease_id
               JOIN customers cu ON cu.id = c.customer_id
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
               LEFT JOIN users ub ON ub.id = c.created_by
               LEFT JOIN users uc ON uc.id = c.cancelled_by
              WHERE " . implode(' AND ', $where) . "
              ORDER BY c.status = 'active' DESC, c.bill_from DESC, c.id DESC
              LIMIT 1000",
            $params
        );
        if (!$rows) return [];

        // Every live line for these charges, with the invoice it sits on.
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $billedOn = [];
        foreach (\db_select(
            "SELECT li.reference_id, i.id AS invoice_id, i.invoice_number, i.status, i.billing_period_start
               FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE li.reference_type = 'billing_charge' AND li.reference_id IN ({$ph})
                AND i.deleted_at IS NULL AND i.status <> 'void'
              ORDER BY i.billing_period_start",
            $ids
        ) as $b) {
            $billedOn[(int) $b['reference_id']][] = [
                'invoice_id'     => (int) $b['invoice_id'],
                'invoice_number' => $b['invoice_number'],
                'status'         => $b['status'],
                'month'          => substr((string) $b['billing_period_start'], 0, 7),
            ];
        }

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['id'];
            $on  = $billedOn[$cid] ?? [];
            if ($r['status'] === 'cancelled') {
                $st = 'cancelled';
            } elseif ($r['recurrence'] === 'once') {
                $st = $on ? 'billed' : 'pending';
            } elseif ($ms !== null) {
                $st = array_filter($on, static fn($x) => $x['month'] === substr($ms, 0, 7)) ? 'billed' : 'pending';
            } else {
                $st = ($r['bill_until'] !== null && $r['bill_until'] < \ff_today()) ? 'ended' : 'recurring';
            }
            if ($state === 'pending' && $st !== 'pending' && $st !== 'recurring') continue;
            if ($state === 'billed' && $st !== 'billed') continue;
            $out[] = [
                'id'              => $cid,
                'lease_id'        => (int) $r['lease_id'],
                'contract_number' => $r['contract_number'],
                'lease_status'    => $r['lease_status'],
                'unit_number'     => $r['unit_number'],
                'customer_id'     => (int) $r['customer_id'],
                'company_name'    => $r['company_name'],
                'description'     => $r['description'],
                'item_type'       => $r['item_type'],
                'item_label'      => self::ITEM_TYPES[$r['item_type']] ?? $r['item_type'],
                'quantity'        => $r['quantity'],
                'unit_price'      => $r['unit_price'],
                'amount'          => $r['amount'],
                'currency'        => $r['currency'],
                'taxable'         => (int) $r['taxable'] === 1,
                'recurrence'      => $r['recurrence'],
                'bill_from'       => $r['bill_from'],
                'bill_until'      => $r['bill_until'],
                'status'          => $r['status'],
                'state'           => $st,
                'billed_on'       => $on,
                'notes'           => $r['notes'],
                'created_by_name' => $r['created_by_name'],
                'created_at'      => $r['created_at'],
                'cancelled_by_name' => $r['cancelled_by_name'],
                'cancelled_at'    => $r['cancelled_at'],
                'cancel_reason'   => $r['cancel_reason'],
            ];
        }
        return $out;
    }

    /**
     * Queue a charge. Validation failures throw \InvalidArgumentException
     * carrying a JSON field map.
     */
    public static function create(array $in, ?int $userId): int
    {
        $fields = [];
        $leaseId = (int) ($in['lease_id'] ?? 0);
        $lease = $leaseId ? \db_row(
            "SELECT id, customer_id, contract_number, status FROM leases WHERE id = ? AND deleted_at IS NULL",
            [$leaseId]
        ) : null;
        if (!$lease) {
            $fields['lease_id'] = 'Pick the lease to charge.';
        } elseif (!in_array($lease['status'], ['active', 'pending'], true)) {
            $fields['lease_id'] = 'That lease is ' . $lease['status'] . ' — charge it on its own invoice instead (Invoices → New, or Edit Line Items on a draft).';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if ($desc === '') $fields['description'] = 'Describe the charge — it prints on the invoice.';
        elseif (mb_strlen($desc) > 255) $fields['description'] = 'Keep it under 255 characters.';
        $type = (string) ($in['item_type'] ?? 'other');
        if (!isset(self::ITEM_TYPES[$type])) $fields['item_type'] = 'Pick a charge type.';
        $qty = \clean_positive_decimal($in['quantity'] ?? '1');
        if ($qty === null || bccomp($qty, '100000', 4) > 0) $fields['quantity'] = 'Quantity must be more than 0.';
        $price = \clean_positive_decimal($in['unit_price'] ?? null);
        if ($price === null || bccomp($price, '10000000', 2) >= 0) $fields['unit_price'] = 'Enter the price, more than $0 (credits go on a credit note).';
        $rec = (string) ($in['recurrence'] ?? 'once');
        if (!in_array($rec, ['once', 'monthly'], true)) $fields['recurrence'] = 'Choose once or every month.';
        $from = \clean_date($in['bill_from'] ?? null) ?: \ff_today();
        $until = ($in['bill_until'] ?? '') !== '' ? \clean_date($in['bill_until']) : null;
        if (($in['bill_until'] ?? '') !== '' && !$until) $fields['bill_until'] = 'Enter a valid date.';
        if ($until && $until < $from) $fields['bill_until'] = 'The last month cannot be before the first.';
        if ($rec === 'once') $until = null;
        $notes = trim((string) ($in['notes'] ?? ''));
        if ($fields) {
            throw new \InvalidArgumentException(json_encode($fields));
        }

        $amount = bcmul($qty, $price, 4);
        $amount = \bcround($amount, 2);
        return (int) \db_transaction(static function () use ($lease, $desc, $type, $qty, $price, $amount, $in, $rec, $from, $until, $notes, $userId) {
            $id = (int) \db_insert('billing_charges', [
                'lease_id'    => (int) $lease['id'],
                'customer_id' => (int) $lease['customer_id'],
                'description' => $desc,
                'item_type'   => $type,
                'quantity'    => $qty,
                'unit_price'  => \bcround($price, 2),
                'amount'      => $amount,
                'taxable'     => !array_key_exists('taxable', $in) || !empty($in['taxable']) ? 1 : 0,
                'recurrence'  => $rec,
                'bill_from'   => $from,
                'bill_until'  => $until,
                'notes'       => $notes !== '' ? mb_substr($notes, 0, 500) : null,
                'created_by'  => $userId,
            ]);
            $actor = BillingCycles::actor();
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $actor['name'],
                'action'       => 'create',
                'module'       => 'billing',
                'entity_type'  => 'billing_charge',
                'entity_id'    => $id,
                'entity_label' => $lease['contract_number'],
                'new_values'   => json_encode(compact('desc', 'type', 'qty', 'amount', 'rec', 'from', 'until')),
                'notes'        => "Charge queued on {$lease['contract_number']}: {$desc} ({$amount}"
                                . ($rec === 'monthly' ? ' every month' : ', once') . ") from {$from}.",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            return $id;
        });
    }

    /**
     * Cancel a charge. A charge already billed keeps its invoice line (a sent
     * invoice is never edited — D12); cancelling stops FUTURE billing only.
     */
    public static function cancel(int $id, string $reason, ?int $userId): bool
    {
        $c = \db_row("SELECT c.*, l.contract_number FROM billing_charges c JOIN leases l ON l.id = c.lease_id WHERE c.id = ?", [$id]);
        if (!$c || $c['status'] !== 'active') return false;
        $n = \db_execute(
            "UPDATE billing_charges SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = ?
              WHERE id = ? AND status = 'active'",
            [$userId, \ff_now_utc(), $reason !== '' ? mb_substr($reason, 0, 500) : null, $id]
        );
        if ($n > 0) {
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => BillingCycles::actor()['name'],
                'action'       => 'status_change',
                'module'       => 'billing',
                'entity_type'  => 'billing_charge',
                'entity_id'    => $id,
                'entity_label' => $c['contract_number'],
                'notes'        => "Charge cancelled on {$c['contract_number']}: {$c['description']}" . ($reason !== '' ? " — {$reason}" : '') . '.',
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        }
        return $n > 0;
    }
}
