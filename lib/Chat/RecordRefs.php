<?php
declare(strict_types=1);

namespace FleetForge\Chat;

/**
 * lib/Chat/RecordRefs.php
 *
 * The ONE place that turns a (type, id) record reference into a message card,
 * searches records for the composer's "Attach" picker, and decides whether a
 * given viewer may see / attach a record.
 *
 * WHY resolve live instead of storing a preview: the old chat trusted title,
 * status and URL text sent by the browser and froze it forever. Cards are now
 * built at read time from the real row, so an invoice attached as "Sent"
 * reads "Paid" once it's paid, a voided record goes "Not available", and money
 * follows the CURRENT viewer's can_view_financials() — a dispatcher sees the
 * card without the amount, a portal user only ever sees their own records.
 *
 * Viewer shapes (built by Conversations::staffViewer() / portalViewer()):
 *   ['side' => 'staff',  'user_id' => int, 'money' => bool, 'perms' => [type => bool]]
 *   ['side' => 'portal', 'portal_user_id' => int, 'customer_id' => int]
 *
 * Scope: $scopeCustomerId (a customer conversation's customer) restricts
 * search + attach to that customer's records AND to the types a customer can
 * open in the portal (lease / invoice / payment), so staff can never drop an
 * internal record into a customer's thread.
 *
 * Card shape (JSON-ready):
 *   { key:"invoice:42", type, id, kind:"Invoice", title, subtitle, status,
 *     tone: success|info|warning|danger|neutral, amount: string|null,
 *     url: string|null, available: bool }
 *
 * Dependencies: includes/db.php (db_select), includes/functions.php
 *               (format_currency, format_date, ff_today, base_url)
 * @session S-CHAT-REBUILD
 */
final class RecordRefs
{
    /** type => [kind label, staff permission module]. Order = picker tab order. */
    public const TYPES = [
        'lease'        => ['Lease',       'leases'],
        'invoice'      => ['Invoice',     'invoices'],
        'payment'      => ['Payment',     'payments'],
        'equipment'    => ['Unit',        'equipment'],
        'customer'     => ['Customer',    'customers'],
        'reservation'  => ['Reservation', 'reservations'],
        'work_order'   => ['Work order',  'maintenance'],
        'damage_claim' => ['Damage claim', 'maintenance'],
    ];

    /** Types a customer can open in the portal — the only ones allowed in customer threads. */
    public const CUSTOMER_TYPES = ['lease', 'invoice', 'payment'];

    /** Max records on one message. */
    public const MAX_PER_MESSAGE = 5;

    /** Invoice rows a customer may see (mirrors pt_invoice_visible_sql()). */
    private const PORTAL_INVOICE_SQL = "i.deleted_at IS NULL AND i.status <> 'void' AND (i.status <> 'draft' OR i.generation_source = 'advance')";

    /**
     * Types this viewer may attach in this conversation.
     *
     * @return list<string>
     */
    public static function attachableTypes(array $viewer, ?int $scopeCustomerId): array
    {
        $types = array_keys(self::TYPES);
        if ($viewer['side'] === 'portal' || $scopeCustomerId) {
            $types = self::CUSTOMER_TYPES;
        }
        if ($viewer['side'] === 'staff') {
            $types = array_values(array_filter($types, fn($t) => !empty($viewer['perms'][$t])));
        }
        return array_values($types);
    }

    /**
     * Search one record type for the attach picker. Empty $q = most recent.
     *
     * @return list<array<string,mixed>> cards
     */
    public static function search(string $type, string $q, array $viewer, ?int $scopeCustomerId, int $limit = 8): array
    {
        if (!in_array($type, self::attachableTypes($viewer, $scopeCustomerId), true)) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $like  = '%' . addcslashes(trim($q), '%_\\') . '%';
        $cust  = $viewer['side'] === 'portal' ? (int) $viewer['customer_id'] : ($scopeCustomerId ?: null);

        [$where, $params] = match ($type) {
            'lease'        => ["(l.contract_number LIKE ? OR c.company_name LIKE ? OR eu.unit_number LIKE ? OR l.unit_number_snapshot LIKE ?)", [$like, $like, $like, $like]],
            'invoice'      => ["(i.invoice_number LIKE ? OR c.company_name LIKE ? OR l.contract_number LIKE ?)", [$like, $like, $like]],
            'payment'      => ["(p.payment_number LIKE ? OR c.company_name LIKE ? OR p.reference_number LIKE ? OR p.check_number LIKE ?)", [$like, $like, $like, $like]],
            'equipment'    => ["(eu.unit_number LIKE ? OR eu.vin LIKE ? OR et.name LIKE ?)", [$like, $like, $like]],
            'customer'     => ["(c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ?)", [$like, $like, $like]],
            'reservation'  => ["(CAST(r.id AS CHAR) LIKE ? OR c.company_name LIKE ? OR r.contact_name LIKE ? OR r.company_name LIKE ?)", [$like, $like, $like, $like]],
            'work_order'   => ["(w.work_order_number LIKE ? OR w.title LIKE ? OR eu.unit_number LIKE ?)", [$like, $like, $like]],
            'damage_claim' => ["(d.claim_number LIKE ? OR eu.unit_number LIKE ? OR c.company_name LIKE ?)", [$like, $like, $like]],
        };

        $rows = self::fetch($type, $where, $params, $viewer, $cust, "LIMIT {$limit}");
        return array_map(fn($r) => self::card($type, $r, $viewer), $rows);
    }

    /**
     * Resolve many refs at once (one query per type). Refs the viewer can't
     * see — deleted, out of scope, no permission — come back as a
     * "Not available" card so the message still reads sensibly.
     *
     * @param list<array{type:string,id:int}> $refs
     * @return array<string,array<string,mixed>> keyed "type:id"
     */
    public static function resolve(array $refs, array $viewer, ?int $scopeCustomerId = null): array
    {
        $byType = [];
        foreach ($refs as $r) {
            $t = (string) ($r['type'] ?? '');
            $id = (int) ($r['id'] ?? 0);
            if (isset(self::TYPES[$t]) && $id > 0) {
                $byType[$t][$id] = $id;
            }
        }

        $out  = [];
        $cust = $viewer['side'] === 'portal' ? (int) $viewer['customer_id'] : ($scopeCustomerId ?: null);
        foreach ($byType as $type => $ids) {
            $allowed = $viewer['side'] === 'portal'
                ? in_array($type, self::CUSTOMER_TYPES, true)
                : !empty($viewer['perms'][$type]);
            $found = [];
            if ($allowed) {
                $alias = self::alias($type);
                $in    = implode(',', array_fill(0, count($ids), '?'));
                foreach (self::fetch($type, "{$alias}.id IN ({$in})", array_values($ids), $viewer, $cust, '') as $row) {
                    $found[(int) $row['id']] = $row;
                }
            }
            foreach ($ids as $id) {
                $out["{$type}:{$id}"] = isset($found[$id])
                    ? self::card($type, $found[$id], $viewer)
                    : self::unavailable($type, $id);
            }
        }
        return $out;
    }

    /**
     * Validate + normalise refs a sender wants to attach. Silently drops
     * duplicates; rejects (returns null) if ANY ref is not attachable, so a
     * crafted request can't sneak an out-of-scope record into a thread.
     *
     * @return list<array{type:string,id:int}>|null
     */
    public static function validateForSend(array $raw, array $viewer, ?int $scopeCustomerId): ?array
    {
        $types = self::attachableTypes($viewer, $scopeCustomerId);
        $clean = [];
        foreach ($raw as $r) {
            if (!is_array($r)) return null;
            $t  = (string) ($r['type'] ?? '');
            $id = (int) ($r['id'] ?? 0);
            if (!in_array($t, $types, true) || $id <= 0) return null;
            $clean["{$t}:{$id}"] = ['type' => $t, 'id' => $id];
        }
        if (count($clean) > self::MAX_PER_MESSAGE) return null;
        if (!$clean) return [];

        foreach (self::resolve(array_values($clean), $viewer, $scopeCustomerId) as $card) {
            if (!$card['available']) return null;
        }
        return array_values($clean);
    }

    // ── internals ────────────────────────────────────────────────────────

    private static function alias(string $type): string
    {
        return match ($type) {
            'lease' => 'l', 'invoice' => 'i', 'payment' => 'p', 'equipment' => 'eu',
            'customer' => 'c', 'reservation' => 'r', 'work_order' => 'w', 'damage_claim' => 'd',
        };
    }

    /**
     * Row fetcher shared by search + resolve. $cust (when set) pins rows to
     * one customer and — like the portal side — applies the portal
     * visibility rules (no void/internal-draft invoices, no void payments).
     */
    private static function fetch(string $type, string $where, array $params, array $viewer, ?int $cust, string $limitSql): array
    {
        $portal = $viewer['side'] === 'portal';
        // Customer-facing rows: the portal itself, OR staff working inside a
        // customer's thread — they may only surface what that customer can open.
        $facing = $portal || $cust !== null;
        $scope  = '';
        if ($cust) {
            $col = match ($type) {
                'customer'  => 'c.id',
                'equipment' => null,     // units carry no customer — never customer-scoped
                default     => self::alias($type) . '.customer_id',
            };
            if ($col === null) return [];
            $scope   = " AND {$col} = ?";
            $params[] = $cust;
        }

        $sql = match ($type) {
            'lease' => "SELECT l.id, l.contract_number, l.status, l.start_date, l.end_date, l.actual_return_date,
                               l.currency, COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number,
                               COALESCE(c.company_name, l.company_name_snapshot) AS company_name
                          FROM leases l
                          LEFT JOIN customers c ON c.id = l.customer_id
                          LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
                         WHERE l.deleted_at IS NULL AND {$where}{$scope}
                         ORDER BY l.id DESC {$limitSql}",
            'invoice' => "SELECT i.id, i.invoice_number, i.status, i.due_date, i.total_amount, i.balance_due, i.currency,
                                 i.billing_period_start, i.billing_period_end, l.contract_number,
                                 COALESCE(c.company_name, i.company_name_snapshot) AS company_name
                            FROM invoices i
                            LEFT JOIN customers c ON c.id = i.customer_id
                            LEFT JOIN leases l ON l.id = i.lease_id
                           WHERE " . ($facing ? self::PORTAL_INVOICE_SQL : 'i.deleted_at IS NULL') . " AND {$where}{$scope}
                           ORDER BY i.id DESC {$limitSql}",
            'payment' => "SELECT p.id, p.payment_number, p.status, p.amount, p.currency, p.payment_date, p.payment_method,
                                 c.company_name
                            FROM payments p
                            LEFT JOIN customers c ON c.id = p.customer_id
                           WHERE p.deleted_at IS NULL" . ($facing ? " AND p.status <> 'void'" : '') . " AND {$where}{$scope}
                           ORDER BY p.id DESC {$limitSql}",
            'equipment' => "SELECT eu.id, eu.unit_number, eu.status, eu.year, et.name AS template_name
                              FROM equipment_units eu
                              LEFT JOIN equipment_templates et ON et.id = eu.template_id
                             WHERE eu.deleted_at IS NULL AND {$where}{$scope}
                             ORDER BY eu.unit_number ASC {$limitSql}",
            'customer' => "SELECT c.id, c.company_name, c.contact_name, c.status, c.outstanding_balance, c.currency
                             FROM customers c
                            WHERE c.deleted_at IS NULL AND {$where}{$scope}
                            ORDER BY c.company_name ASC {$limitSql}",
            'reservation' => "SELECT r.id, r.status, r.pickup_date, r.quantity, r.contact_name,
                                     COALESCE(c.company_name, r.company_name) AS company_name
                                FROM reservations r
                                LEFT JOIN customers c ON c.id = r.customer_id
                               WHERE r.deleted_at IS NULL AND {$where}{$scope}
                               ORDER BY r.id DESC {$limitSql}",
            'work_order' => "SELECT w.id, w.work_order_number, w.status, w.title, w.total_cost, eu.unit_number
                               FROM maintenance_work_orders w
                               LEFT JOIN equipment_units eu ON eu.id = w.equipment_unit_id
                              WHERE w.deleted_at IS NULL AND {$where}{$scope}
                              ORDER BY w.id DESC {$limitSql}",
            'damage_claim' => "SELECT d.id, d.claim_number, d.status, d.severity, d.customer_liable_amount, eu.unit_number,
                                      COALESCE(c.company_name, d.customer_name) AS company_name
                                 FROM damage_claims d
                                 LEFT JOIN equipment_units eu ON eu.id = d.equipment_unit_id
                                 LEFT JOIN customers c ON c.id = d.customer_id
                                WHERE d.deleted_at IS NULL AND {$where}{$scope}
                                ORDER BY d.id DESC {$limitSql}",
        };

        return \db_select($sql, $params);
    }

    /** Build the display card for one fetched row. */
    private static function card(string $type, array $r, array $viewer): array
    {
        $portal = $viewer['side'] === 'portal';
        $money  = $portal || !empty($viewer['money']);
        $id     = (int) $r['id'];
        $status = (string) ($r['status'] ?? '');
        $amount = null;
        $sub    = [];
        $statusLabel = null;   // set when a type has its own customer-facing wording

        switch ($type) {
            case 'lease':
                $title = (string) $r['contract_number'];
                if (!$portal && $r['company_name']) $sub[] = $r['company_name'];
                if ($r['unit_number']) $sub[] = 'Unit ' . $r['unit_number'];
                $end   = $r['actual_return_date'] ?: $r['end_date'];
                $sub[] = \format_date($r['start_date']) . ' – ' . ($end ? \format_date($end) : 'open');
                $tone  = match ($status) { 'active' => 'success', 'pending' => 'warning', 'cancelled' => 'danger', default => 'neutral' };
                $url   = $portal ? 'portal/leases/view?id=' . $id : 'leases/show?id=' . $id;
                break;

            case 'invoice':
                $title = (string) $r['invoice_number'];
                if (!$portal && $r['company_name']) $sub[] = $r['company_name'];
                if ($r['billing_period_start']) {
                    $sub[] = \format_date($r['billing_period_start']) . ' – ' . \format_date($r['billing_period_end']);
                } elseif ($r['contract_number']) {
                    $sub[] = $r['contract_number'];
                }
                $open = in_array($status, ['sent', 'partially_paid', 'overdue'], true);
                if ($open && $r['due_date']) $sub[] = 'Due ' . \format_date($r['due_date']);
                $pastDue = $open && ($status === 'overdue' || ($r['due_date'] && $r['due_date'] < \ff_today()));
                [$statusLabel, $tone] = match (true) {
                    $pastDue                   => ['Past due', 'danger'],
                    $status === 'paid'         => ['Paid', 'success'],
                    $status === 'partially_paid' => ['Partly paid', 'warning'],
                    $status === 'sent'         => ['Open', 'info'],
                    $status === 'draft'        => [$portal ? 'Upcoming' : 'Draft', 'neutral'],
                    $status === 'written_off'  => [$portal ? 'Closed' : 'Written off', 'neutral'],
                    $status === 'void'         => ['Void', 'danger'],
                    default                    => [self::label($status), 'neutral'],
                };
                if ($money) {
                    $amount = $open
                        ? self::money($r['balance_due'], $r['currency']) . ' due'
                        : self::money($r['total_amount'], $r['currency']);
                }
                $url = $portal ? 'portal/invoices/view?id=' . $id : 'invoices/show?id=' . $id;
                break;

            case 'payment':
                $title = (string) $r['payment_number'];
                if (!$portal && $r['company_name']) $sub[] = $r['company_name'];
                if ($r['payment_date']) $sub[] = \format_date($r['payment_date']);
                if ($r['payment_method']) $sub[] = self::label((string) $r['payment_method']);
                $tone   = match ($status) { 'cleared' => 'success', 'pending' => 'warning', 'failed', 'returned', 'void' => 'danger', default => 'neutral' };
                $amount = $money ? self::money($r['amount'], $r['currency']) : null;
                $url    = $portal ? 'portal/payments' : 'payments/show?id=' . $id;
                break;

            case 'equipment':
                $title = 'Unit ' . $r['unit_number'];
                $sub[] = trim(($r['year'] ? $r['year'] . ' ' : '') . ($r['template_name'] ?? ''));
                $tone  = match ($status) { 'available' => 'success', 'on_lease' => 'info', 'reserved', 'maintenance' => 'warning', 'decommissioned' => 'danger', default => 'neutral' };
                $url   = 'equipment/show?id=' . $id;
                break;

            case 'customer':
                $title = (string) $r['company_name'];
                if ($r['contact_name']) $sub[] = $r['contact_name'];
                $tone  = match ($status) { 'active' => 'success', 'pending' => 'warning', 'suspended', 'credit_hold' => 'danger', default => 'neutral' };
                if ($money && bccomp((string) ($r['outstanding_balance'] ?? '0'), '0', 2) !== 0) {
                    $amount = self::money($r['outstanding_balance'], $r['currency'] ?? 'CAD') . ' owing';
                }
                $url = 'customers/show?id=' . $id;
                break;

            case 'reservation':
                $title = 'Reservation #' . $id;
                if ($r['company_name'] ?: $r['contact_name']) $sub[] = $r['company_name'] ?: $r['contact_name'];
                if ($r['pickup_date']) $sub[] = 'Pickup ' . \format_date($r['pickup_date']);
                if ((int) $r['quantity'] > 1) $sub[] = (int) $r['quantity'] . ' units';
                $tone = match ($status) { 'confirmed' => 'success', 'pending' => 'warning', 'cancelled' => 'danger', default => 'neutral' };
                $url  = 'reservations/show?id=' . $id;
                break;

            case 'work_order':
                $title = (string) $r['work_order_number'];
                if ($r['unit_number']) $sub[] = 'Unit ' . $r['unit_number'];
                if ($r['title']) $sub[] = $r['title'];
                $tone   = match ($status) { 'completed' => 'success', 'in_progress' => 'info', 'waiting_parts' => 'warning', 'cancelled' => 'danger', default => 'neutral' };
                $amount = ($money && $r['total_cost'] !== null && bccomp((string) $r['total_cost'], '0', 2) > 0) ? self::money($r['total_cost'], 'CAD') : null;
                $url    = 'maintenance_work_orders/show?id=' . $id;
                break;

            default: // damage_claim
                $title = (string) $r['claim_number'];
                if ($r['unit_number']) $sub[] = 'Unit ' . $r['unit_number'];
                if ($r['company_name']) $sub[] = $r['company_name'];
                $tone   = match ($status) { 'resolved' => 'success', 'reported' => 'warning', 'written_off' => 'danger', default => 'info' };
                $amount = ($money && $r['customer_liable_amount'] !== null && bccomp((string) $r['customer_liable_amount'], '0', 2) > 0)
                    ? self::money($r['customer_liable_amount'], 'CAD') . ' liable' : null;
                $url    = 'damage_claims/show?id=' . $id;
        }

        return [
            'key'       => "{$type}:{$id}",
            'type'      => $type,
            'id'        => $id,
            'kind'      => self::TYPES[$type][0],
            'title'     => $title,
            'subtitle'  => implode(' · ', array_filter(array_map('strval', $sub), fn($s) => trim($s) !== '')),
            'status'    => $statusLabel ?? self::label($status),
            'tone'      => $tone,
            'amount'    => $amount,
            'url'       => \base_url($url),
            'available' => true,
        ];
    }

    private static function unavailable(string $type, int $id): array
    {
        return [
            'key' => "{$type}:{$id}", 'type' => $type, 'id' => $id,
            // Deliberately doesn't say WHICH: "deleted" vs "not yours to see"
            // would itself leak that the record exists.
            'kind' => self::TYPES[$type][0] ?? 'Record', 'title' => 'Not available',
            'subtitle' => 'Removed, or not something you have access to', 'status' => '', 'tone' => 'neutral', 'amount' => null,
            'url' => null, 'available' => false,
        ];
    }

    /** CAD prints bare; any other currency gets its code so USD is never read as CAD. */
    private static function money(mixed $amount, ?string $currency): string
    {
        $cur = strtoupper(trim((string) $currency));
        $out = \format_currency($amount ?? '0');
        return ($cur !== '' && $cur !== 'CAD') ? "{$out} {$cur}" : $out;
    }

    private static function label(string $s): string
    {
        return $s === '' ? '' : ucfirst(str_replace('_', ' ', $s));
    }
}
