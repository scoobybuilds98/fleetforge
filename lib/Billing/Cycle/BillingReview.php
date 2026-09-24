<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

/**
 * lib/Billing/Cycle/BillingReview.php
 *
 * S-BILLING-MODULE — the review of a cycle's invoices before they go out.
 *
 * Readiness looks at the INPUTS before generation; review looks at the
 * OUTPUT after it. Every live invoice in the cycle is compared with the same
 * lease's billing last cycle and inspected for the shapes that have caused
 * real billing mistakes here:
 *
 *   duplicate_period  another live invoice for the lease overlaps this one's
 *                     period — double billing                        (danger)
 *   double_mileage    a "Mileage usage" AND a "Mileage overage" line on one
 *                     invoice — the F71 double-bill shape             (danger)
 *   held              the lease is on a billing hold but was billed    (warning)
 *   swing             total moved more than the configured % AND $
 *                     against last cycle                              (warning)
 *   zero_total        a $0.00 invoice                                  (warning)
 *   no_tax            taxable customer, $0 tax                         (warning)
 *   usd_no_rate       US-dollar invoice without a frozen rate          (warning)
 *   no_recipient      email delivery but no address / email bounced    (warning)
 *   credit_line       carries a reconciliation / usage credit          (info)
 *   usage_true_up     a mileage or hours true-up above the $ threshold (info)
 *   first_invoice     the lease's first invoice                        (info)
 *   several           more than one invoice for the lease this cycle   (info)
 *
 * Plus `missing`: leases billed last cycle, still on rent this month, with
 * nothing billed, held or excepted this cycle.
 *
 * Each invoice carries its review mark (billing_cycle_reviews): 'reviewed'
 * or 'query', with who and when. Marks are advisory unless
 * billing_cycle.close_requires_review is on (CycleClose enforces it).
 *
 * @session S-BILLING-MODULE
 */
final class BillingReview
{
    private function __construct() {}

    private const CREDIT_TYPES   = ['base_rental_reconciliation_credit', 'mileage_credit', 'hours_credit', 'early_return_credit'];
    private const TRUE_UP_TYPES  = ['mileage_adjustment', 'mileage_credit', 'hours_adjustment', 'hours_credit'];

    /**
     * @return array{rows: array, missing: array, flag_counts: array<string,int>, thresholds: array}
     */
    public static function run(array $cycle, bool $withMoney = true): array
    {
        $pct = (string) \settings_get('billing_cycle.variance_pct', '25');
        $min = (string) \settings_get('billing_cycle.variance_min_amount', '100');
        $pct = \clean_non_negative_decimal($pct) ?? '25';
        $min = \clean_non_negative_decimal($min) ?? '100';

        $invoices = \db_select(
            "SELECT i.id, i.invoice_number, i.lease_id, i.customer_id, i.status, i.invoice_type, i.billing_type,
                    i.billing_period_start, i.billing_period_end, i.currency, i.exchange_rate_to_cad,
                    i.subtotal, i.tax_total, i.total_amount, i.balance_due, i.created_at, i.sent_at,
                    i.generation_source, i.tax_exempt_snapshot, i.gst_exempt_snapshot, i.pst_exempt_snapshot,
                    COALESCE(i.contract_number_snapshot, l.contract_number) AS contract_number,
                    COALESCE(c.company_name, i.company_name_snapshot) AS company_name,
                    COALESCE(i.unit_number_invoice_snapshot, l.unit_number_snapshot) AS unit_number,
                    c.invoice_delivery, c.invoice_email, c.billing_email, c.email AS customer_email, c.email_disabled,
                    r.status AS review_status, r.note AS review_note, r.reviewed_at, ru.name AS reviewed_by_name
               FROM invoices i
               LEFT JOIN leases l ON l.id = i.lease_id
               LEFT JOIN customers c ON c.id = i.customer_id
               LEFT JOIN billing_cycle_reviews r ON r.cycle_id = ? AND r.invoice_id = i.id
               LEFT JOIN users ru ON ru.id = r.reviewed_by
              WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void'
              ORDER BY company_name, i.invoice_number",
            array_merge([$cycle['id']], BillingCycles::scopeParams($cycle))
        );

        $invIds   = array_map(static fn($r) => (int) $r['id'], $invoices);
        $leaseIds = array_values(array_unique(array_map(static fn($r) => (int) $r['lease_id'], $invoices)));

        // Line-type sums per invoice (signed: credit lines negative).
        $lines = [];
        if ($invIds) {
            $ph = implode(',', array_fill(0, count($invIds), '?'));
            foreach (\db_select(
                "SELECT invoice_id, item_type, COUNT(*) AS n,
                        SUM(CASE WHEN is_credit = 1 THEN -amount ELSE amount END) AS signed
                   FROM invoice_line_items WHERE invoice_id IN ({$ph})
                  GROUP BY invoice_id, item_type",
                $invIds
            ) as $r) {
                $lines[(int) $r['invoice_id']][$r['item_type']] = (string) $r['signed'];
            }
        }

        // Last cycle's live total per lease, and each lease's first billed period.
        $prevMonth = BillingCycles::shiftMonth(substr((string) $cycle['period_start'], 0, 7), -1);
        [$ps, $pe] = BillingCycles::monthBounds($prevMonth);
        $prev = [];
        $first = [];
        $allOverlaps = [];
        if ($leaseIds) {
            $lph = implode(',', array_fill(0, count($leaseIds), '?'));
            foreach (\db_select(
                "SELECT i.lease_id, SUM(i.total_amount) AS total, COUNT(*) AS n
                   FROM invoices i
                  WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void' AND i.lease_id IN ({$lph})
                  GROUP BY i.lease_id",
                array_merge([$ps, $pe], $leaseIds)
            ) as $r) {
                $prev[(int) $r['lease_id']] = (string) $r['total'];
            }
            foreach (\db_select(
                "SELECT lease_id, MIN(billing_period_start) AS first_start
                   FROM invoices
                  WHERE deleted_at IS NULL AND status <> 'void' AND lease_id IN ({$lph})
                    AND invoice_type IN ('" . implode("','", BillingCycles::CYCLE_INVOICE_TYPES) . "')
                  GROUP BY lease_id",
                $leaseIds
            ) as $r) {
                $first[(int) $r['lease_id']] = (string) $r['first_start'];
            }
            // Every live invoice for these leases that could overlap anything in the month.
            foreach (\db_select(
                "SELECT id, lease_id, invoice_number, billing_period_start, billing_period_end, status
                   FROM invoices
                  WHERE deleted_at IS NULL AND status <> 'void' AND lease_id IN ({$lph})
                    AND invoice_type IN ('" . implode("','", BillingCycles::CYCLE_INVOICE_TYPES) . "')
                    AND billing_type IN ('" . implode("','", BillingCycles::RENTAL_BILLING_TYPES) . "')
                    AND billing_period_end >= ? AND billing_period_start <= ?",
                array_merge($leaseIds, [
                    date('Y-m-d', strtotime($cycle['period_start'] . ' -62 days')),
                    date('Y-m-d', strtotime($cycle['period_end'] . ' +62 days')),
                ])
            ) as $r) {
                $allOverlaps[(int) $r['lease_id']][] = $r;
            }
        }
        $held = BillingHolds::heldMap($leaseIds, (string) $cycle['period_start'], (string) $cycle['period_end']);

        $perLease = [];
        foreach ($invoices as $inv) {
            $perLease[(int) $inv['lease_id']] = ($perLease[(int) $inv['lease_id']] ?? 0) + 1;
        }

        $rows = [];
        $flagCounts = [];
        foreach ($invoices as $inv) {
            $id    = (int) $inv['id'];
            $lid   = (int) $inv['lease_id'];
            $types = $lines[$id] ?? [];
            $total = (string) $inv['total_amount'];
            $flags = [];

            // Only rental-bearing invoices can double-bill days (a close-time
            // mileage_only / adjustment invoice shares days by design).
            $isRental = in_array($inv['billing_type'], BillingCycles::RENTAL_BILLING_TYPES, true);
            foreach ($isRental ? ($allOverlaps[$lid] ?? []) : [] as $o) {
                if ((int) $o['id'] === $id) continue;
                if ($o['billing_period_start'] <= $inv['billing_period_end'] && $o['billing_period_end'] >= $inv['billing_period_start']) {
                    $flags[] = ['key' => 'duplicate_period', 'severity' => 'danger',
                        'text' => "Overlaps {$o['invoice_number']} ({$o['billing_period_start']} to {$o['billing_period_end']}, {$o['status']}) — double billing"];
                    break;
                }
            }
            if (isset($types['mileage_usage']) && isset($types['mileage'])) {
                $flags[] = ['key' => 'double_mileage', 'severity' => 'danger',
                    'text' => 'Has both a Mileage usage and a Mileage overage line — the same distance billed twice (remove the overage line)'];
            }
            if (isset($held[$lid])) {
                $flags[] = ['key' => 'held', 'severity' => 'warning',
                    'text' => 'Billed while on hold — ' . BillingHolds::describe($held[$lid])];
            }
            if (isset($prev[$lid]) && bccomp($prev[$lid], '0', 2) !== 0) {
                $diff = bcsub($total, $prev[$lid], 2);
                $absDiff = ltrim($diff, '-');
                $absPrev = ltrim($prev[$lid], '-');
                $pctMoved = bcmul(bcdiv($absDiff, $absPrev, 6), '100', 2);
                if (bccomp($absDiff, $min, 2) >= 0 && bccomp($pctMoved, $pct, 2) >= 0) {
                    $dir = bccomp($diff, '0', 2) > 0 ? 'up' : 'down';
                    $flags[] = ['key' => 'swing', 'severity' => 'warning',
                        'text' => $withMoney
                            ? "{$dir} {$pctMoved}% on last month (" . \format_currency($prev[$lid]) . ' → ' . \format_currency($total) . ')'
                            : "{$dir} {$pctMoved}% on last month"];
                }
            }
            if (bccomp($total, '0', 2) === 0) {
                $flags[] = ['key' => 'zero_total', 'severity' => 'warning', 'text' => 'Total is $0.00'];
            }
            $exempt = (int) $inv['tax_exempt_snapshot'] === 1 || ((int) $inv['gst_exempt_snapshot'] === 1 && (int) $inv['pst_exempt_snapshot'] === 1);
            if (!$exempt && bccomp((string) $inv['tax_total'], '0', 2) === 0 && bccomp((string) $inv['subtotal'], '0', 2) > 0) {
                $flags[] = ['key' => 'no_tax', 'severity' => 'warning', 'text' => 'No tax charged, and the customer is not tax-exempt'];
            }
            if ($inv['currency'] === 'USD' && $inv['exchange_rate_to_cad'] === null) {
                $flags[] = ['key' => 'usd_no_rate', 'severity' => 'warning', 'text' => 'US-dollar invoice with no exchange rate frozen on it'];
            }
            if (($inv['invoice_delivery'] ?? 'email') === 'email') {
                $hasEmail = trim((string) $inv['invoice_email']) !== '' || trim((string) $inv['billing_email']) !== '' || trim((string) $inv['customer_email']) !== '';
                if (!$hasEmail) {
                    $flags[] = ['key' => 'no_recipient', 'severity' => 'warning', 'text' => 'Customer has no email address to send it to'];
                } elseif ((int) $inv['email_disabled'] === 1) {
                    $flags[] = ['key' => 'no_recipient', 'severity' => 'warning', 'text' => 'Customer email is switched off after a bounce'];
                }
            }
            foreach (self::CREDIT_TYPES as $t) {
                if (isset($types[$t]) && bccomp($types[$t], '0', 2) !== 0) {
                    $flags[] = ['key' => 'credit_line', 'severity' => 'info',
                        'text' => 'Includes a credit line (' . str_replace('_', ' ', $t) . ')'
                                . ($withMoney ? ': ' . \format_currency($types[$t]) : '')];
                    break;
                }
            }
            foreach (self::TRUE_UP_TYPES as $t) {
                if (isset($types[$t]) && bccomp(ltrim($types[$t], '-'), $min, 2) >= 0) {
                    $flags[] = ['key' => 'usage_true_up', 'severity' => 'info',
                        'text' => 'Large usage true-up (' . str_replace('_', ' ', $t) . ')'
                                . ($withMoney ? ': ' . \format_currency($types[$t]) : '')];
                    break;
                }
            }
            if (($first[$lid] ?? null) === $inv['billing_period_start']) {
                $flags[] = ['key' => 'first_invoice', 'severity' => 'info', 'text' => 'First invoice for this lease'];
            }
            if (($perLease[$lid] ?? 0) > 1) {
                $flags[] = ['key' => 'several', 'severity' => 'info', 'text' => ($perLease[$lid]) . ' invoices for this lease this month'];
            }

            foreach ($flags as $f) {
                $flagCounts[$f['key']] = ($flagCounts[$f['key']] ?? 0) + 1;
            }
            $worst = 'none';
            foreach (['danger', 'warning', 'info'] as $sev) {
                foreach ($flags as $f) {
                    if ($f['severity'] === $sev) { $worst = $sev; break 2; }
                }
            }

            $rows[] = [
                'invoice_id'      => $id,
                'invoice_number'  => $inv['invoice_number'],
                'lease_id'        => $lid,
                'customer_id'     => $inv['customer_id'] !== null ? (int) $inv['customer_id'] : null,
                'contract_number' => $inv['contract_number'],
                'company_name'    => $inv['company_name'],
                'unit_number'     => $inv['unit_number'],
                'status'          => $inv['status'],
                'invoice_type'    => $inv['invoice_type'],
                'billing_type'    => $inv['billing_type'],
                'period_start'    => $inv['billing_period_start'],
                'period_end'      => $inv['billing_period_end'],
                'currency'        => $inv['currency'],
                'total_amount'    => $withMoney ? $total : null,
                'previous_total'  => $withMoney ? ($prev[$lid] ?? null) : null,
                'change'          => ($withMoney && isset($prev[$lid])) ? bcsub($total, $prev[$lid], 2) : null,
                'generation_source' => $inv['generation_source'],
                'created_at'      => $inv['created_at'],
                'flags'           => $flags,
                'worst'           => $worst,
                'review'          => $inv['review_status'] ? [
                    'status' => $inv['review_status'], 'note' => $inv['review_note'],
                    'at' => $inv['reviewed_at'], 'by' => $inv['reviewed_by_name'],
                ] : null,
            ];
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'none' => 3];
        usort($rows, static fn($a, $b) => [$rank[$a['worst']], $a['company_name']] <=> [$rank[$b['worst']], $b['company_name']]);

        return [
            'rows'        => $rows,
            'missing'     => self::missing($cycle, $prevMonth),
            'flag_counts' => $flagCounts,
            'thresholds'  => ['pct' => $pct, 'min_amount' => $min],
        ];
    }

    /** Billed last cycle, on rent now, nothing this cycle (not held / excepted / covered). */
    private static function missing(array $cycle, string $prevMonth): array
    {
        [$ps, $pe] = BillingCycles::monthBounds($prevMonth);
        $billedLast = [];
        foreach (\db_select(
            "SELECT DISTINCT i.lease_id FROM invoices i
              WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void'",
            [$ps, $pe]
        ) as $r) {
            $billedLast[(int) $r['lease_id']] = true;
        }
        if (!$billedLast) return [];
        $out = [];
        foreach (BillingCycles::coverage($cycle, false)['rows'] as $row) {
            if (!isset($billedLast[$row['lease_id']])) continue;
            if (!in_array($row['status'], ['to_bill', 'void_rebillable', 'closed_unbilled'], true)) continue;
            $out[] = [
                'lease_id'        => $row['lease_id'],
                'contract_number' => $row['contract_number'],
                'company_name'    => $row['company_name'],
                'unit_number'     => $row['unit_number'],
                'status'          => $row['status'],
            ];
        }
        return $out;
    }

    /**
     * Mark (or clear) the review status of invoices in a cycle.
     *
     * @param int[] $invoiceIds
     * @return int rows changed
     */
    public static function mark(array $cycle, array $invoiceIds, string $status, ?string $note, ?int $userId): int
    {
        if (!$invoiceIds) return 0;
        $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
        // Only invoices that actually belong to this cycle can be marked.
        $valid = array_map(static fn($r) => (int) $r['id'], \db_select(
            "SELECT i.id FROM invoices i WHERE " . BillingCycles::scopeSql('i') . " AND i.id IN ({$ph})",
            array_merge(BillingCycles::scopeParams($cycle), $invoiceIds)
        ));
        if (!$valid) return 0;

        $changed = 0;
        foreach ($valid as $iid) {
            if ($status === 'clear') {
                $changed += \db_execute("DELETE FROM billing_cycle_reviews WHERE cycle_id = ? AND invoice_id = ?", [$cycle['id'], $iid]);
                continue;
            }
            $changed += \db_execute(
                "INSERT INTO billing_cycle_reviews (cycle_id, invoice_id, status, note, reviewed_by, reviewed_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note),
                    reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at)",
                [$cycle['id'], $iid, $status, $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null, $userId, \ff_now_utc()]
            ) > 0 ? 1 : 0;
        }
        BillingCycles::audit($cycle, 'update',
            ($status === 'clear' ? 'Cleared review marks on ' : 'Marked ' . $status . ': ') . count($valid) . ' invoice(s)'
            . ($note ? " — {$note}" : '') . '.');
        return $changed;
    }
}
