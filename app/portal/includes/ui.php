<?php
declare(strict_types=1);

/**
 * app/portal/includes/ui.php
 *
 * Shared view helpers for the customer portal (S-PORTAL-REDESIGN).
 *
 * Every portal page used to hand-roll its own badges, money formatting,
 * status maps and "is this invoice payable" rules, and they drifted (the
 * sidebar counted `sent` as overdue, the dashboard didn't, the pay button
 * had three different gates). This file is the one place those rules live:
 *
 *   pt_account_summary()  balances, past-due, due-soon, credits, open counts
 *   pt_online_pay_enabled()  whether customers can pay online right now
 *   pt_invoice_visible_sql() which invoices a customer may ever see
 *   pt_invoice_status()    customer-facing label + tone for an invoice
 *   pt_page_head()         the page header every portal page opens with
 *   pt_icon() / pt_money() / pt_url() small render helpers
 *
 * Trap 8: every query here is scoped by the customer id passed in, which
 * callers take from portal_customer_id() — never from the request.
 *
 * Loaded by: app/portal/includes/header.php (so every page has it) and the
 * portal JSON endpoints that need the same rules.
 *
 * @session S-PORTAL-REDESIGN
 */

use FleetForge\Sop\SopIcons;

if (!function_exists('pt_icon')) {

    /** Inline Heroicon (outline) from public/assets/icons — escaped class. */
    function pt_icon(string $name, string $class = 'pt-ic'): string
    {
        return SopIcons::svg($name, $class);
    }

    /** Absolute URL of a portal page, e.g. pt_url('invoices/view?id=4'). */
    function pt_url(string $path = ''): string
    {
        return base_url('portal' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }

    /**
     * Money for display. CAD is the house currency and prints bare; any other
     * currency gets its code appended so a USD invoice is never read as CAD.
     */
    function pt_money(mixed $amount, string $currency = 'CAD'): string
    {
        $out = format_currency($amount ?? '0');
        $cur = strtoupper(trim($currency));
        return ($cur !== '' && $cur !== 'CAD') ? $out . ' ' . $cur : $out;
    }

    /**
     * Invoice visibility predicate for SQL. Customers never see voids or
     * internal drafts; advance-bill drafts are customer-facing (pre-billed
     * future periods) and stay visible — the rule the invoice list, detail
     * page and PDF endpoint already agreed on.
     */
    function pt_invoice_visible_sql(string $alias = 'i'): string
    {
        $a = preg_replace('/[^a-z_]/i', '', $alias) ?: 'i';
        return "{$a}.deleted_at IS NULL AND {$a}.status <> 'void'"
             . " AND ({$a}.status <> 'draft' OR {$a}.generation_source = 'advance')";
    }

    /**
     * SELECT … FROM … for invoice rows feeding pt_invoice_json(). Callers
     * append "WHERE i.customer_id = ? AND " . pt_invoice_visible_sql() …
     * acc_qbo_invoice_map is UNIQUE on ff_invoice_id, so the join never fans out.
     */
    function pt_invoice_select_sql(): string
    {
        return "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
                       i.billing_period_start, i.billing_period_end, i.generation_source,
                       i.total_amount, i.balance_due, i.currency, i.status, i.lease_id,
                       l.contract_number, l.actual_return_date, l.actual_return_time,
                       l.start_time, l.billing_days_removed,
                       qm.qbo_invoice_id
                  FROM invoices i
                  LEFT JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
                  LEFT JOIN acc_qbo_invoice_map qm ON qm.ff_invoice_id = i.id";
    }

    /** Statuses a customer can pay against (mirrors PayLink::PAYABLE_STATUSES). */
    function pt_open_statuses_sql(): string
    {
        return "'sent','partially_paid','overdue'";
    }

    /**
     * True when customers can pay online right now: QuickBooks Payments is
     * switched on AND QuickBooks is connected. Per-invoice readiness (the
     * invoice must already be in QuickBooks) is checked at click time.
     */
    function pt_online_pay_enabled(): bool
    {
        return (string) settings_get('quickbooks.payments_enabled', '0') === '1'
            && (string) settings_get('quickbooks.connection_status', '') === 'connected';
    }

    /**
     * Customer-facing status for an invoice row: [label, tone, key].
     * tone ∈ neutral|info|success|warning|danger. An open invoice whose due
     * date has passed reads "Past due" even before the nightly job flips its
     * status, because that's what it is to the customer.
     *
     * @param array<string,mixed> $inv needs status, due_date, balance_due, total_amount
     * @return array{0:string,1:string,2:string}
     */
    function pt_invoice_status(array $inv): array
    {
        $status = (string) ($inv['status'] ?? '');
        $today  = ff_today();
        $due    = (string) ($inv['due_date'] ?? '');
        $open   = in_array($status, ['sent', 'partially_paid', 'overdue'], true);

        if ($open && ($status === 'overdue' || ($due !== '' && $due < $today))) {
            return ['Past due', 'danger', 'past_due'];
        }
        return match ($status) {
            'paid'           => ['Paid', 'success', 'paid'],
            'partially_paid' => ['Partly paid', 'warning', 'partial'],
            'sent'           => ($due !== '' && $due <= ff_local_date_add($today, 7))
                                    ? ['Due soon', 'warning', 'due_soon']
                                    : ['Open', 'info', 'open'],
            'draft'          => ['Upcoming', 'neutral', 'upcoming'],
            'written_off'    => ['Closed', 'neutral', 'closed'],
            default          => [ucfirst(str_replace('_', ' ', $status)), 'neutral', $status],
        };
    }

    /**
     * One invoice as the portal's JSON endpoints return it (list, payable,
     * search). Money stays as bcmath strings; *_fmt are display copies.
     *
     * @param array<string,mixed> $r invoice row; optional keys: qbo_invoice_id,
     *        contract_number, billing_period_start/end + lease period fields
     * @return array<string,mixed>
     */
    function pt_invoice_json(array $r): array
    {
        [$label, $tone, $key] = pt_invoice_status($r);
        $open     = in_array((string) $r['status'], ['sent', 'partially_paid', 'overdue'], true);
        $balance  = (string) ($r['balance_due'] ?? '0.00');
        $total    = (string) ($r['total_amount'] ?? '0.00');
        $currency = (string) ($r['currency'] ?? 'CAD');
        $payable  = $open && bccomp($balance, '0', 2) > 0;
        $period   = '';
        if (!empty($r['billing_period_start'])) {
            $end = function_exists('ff_invoice_display_period_end') ? ff_invoice_display_period_end($r) : ($r['billing_period_end'] ?? null);
            $period = format_date($r['billing_period_start']) . ' – ' . format_date($end);
        }
        return [
            'id'             => (int) $r['id'],
            'number'         => (string) $r['invoice_number'],
            'invoice_date'   => (string) ($r['invoice_date'] ?? ''),
            'due_date'       => (string) ($r['due_date'] ?? ''),
            'period'         => $period,
            'lease'          => (string) ($r['contract_number'] ?? ''),
            'currency'       => $currency,
            'total'          => $total,
            'balance'        => $balance,
            'balance_due'    => $balance,
            'paid'           => bcsub($total, $balance, 2),
            'total_fmt'      => pt_money($total, $currency),
            'balance_fmt'    => pt_money($balance, $currency),
            'status'         => (string) $r['status'],
            'status_label'   => $label,
            'status_tone'    => $tone,
            'status_key'     => $key,
            'days_late'      => $key === 'past_due' ? max(0, pt_days_between((string) $r['due_date'], ff_today())) : 0,
            'payable'        => $payable,
            'online_ready'   => $payable && !empty($r['qbo_invoice_id']),
        ];
    }

    /** Status pill markup. */
    function pt_badge(string $label, string $tone = 'neutral'): string
    {
        $tone = in_array($tone, ['neutral', 'info', 'success', 'warning', 'danger', 'brand'], true) ? $tone : 'neutral';
        return '<span class="pt-pill pt-pill--' . $tone . '">' . e($label) . '</span>';
    }

    /**
     * Whole days from $from to $to (both Y-m-d). Negative when $to is earlier.
     */
    function pt_days_between(?string $from, ?string $to): int
    {
        if (!$from || !$to) return 0;
        try {
            $a = new DateTimeImmutable(substr($from, 0, 10));
            $b = new DateTimeImmutable(substr($to, 0, 10));
        } catch (\Throwable) {
            return 0;
        }
        return (int) $a->diff($b)->format('%r%a');
    }

    /**
     * "in 3 days" / "today" / "5 days ago" for a business date.
     */
    function pt_relative_day(?string $date): string
    {
        if (!$date) return '';
        $d = pt_days_between(ff_today(), $date);
        return match (true) {
            $d === 0  => 'today',
            $d === 1  => 'tomorrow',
            $d === -1 => 'yesterday',
            $d > 1    => 'in ' . $d . ' days',
            default   => abs($d) . ' days ago',
        };
    }

    /**
     * Balances for the portal chrome, dashboard and pay page — computed once
     * per request. All money is bcmath strings (D16).
     *
     * @return array{
     *   outstanding:string, open_count:int,
     *   past_due:string, past_due_count:int, oldest_past_due_days:int,
     *   due_soon:string, due_soon_count:int, next_due_date:?string,
     *   credit:string, currency:string, active_leases:int
     * }
     */
    function pt_account_summary(int $customerId): array
    {
        static $memo = [];
        if (isset($memo[$customerId])) {
            return $memo[$customerId];
        }

        $today = ff_today();
        $soon  = ff_local_date_add($today, 7);

        $row = db_row(
            "SELECT COALESCE(SUM(balance_due), 0) AS outstanding,
                    COUNT(*) AS open_count,
                    COALESCE(SUM(CASE WHEN status = 'overdue' OR due_date < ? THEN balance_due ELSE 0 END), 0) AS past_due,
                    SUM(CASE WHEN status = 'overdue' OR due_date < ? THEN 1 ELSE 0 END) AS past_due_count,
                    MIN(CASE WHEN status = 'overdue' OR due_date < ? THEN due_date END) AS oldest_past_due,
                    COALESCE(SUM(CASE WHEN status <> 'overdue' AND due_date BETWEEN ? AND ? THEN balance_due ELSE 0 END), 0) AS due_soon,
                    SUM(CASE WHEN status <> 'overdue' AND due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS due_soon_count,
                    MIN(CASE WHEN status <> 'overdue' AND due_date >= ? THEN due_date END) AS next_due
               FROM invoices
              WHERE customer_id = ? AND deleted_at IS NULL
                AND status IN (" . pt_open_statuses_sql() . ")
                AND balance_due > 0",
            [$today, $today, $today, $today, $soon, $today, $soon, $today, $customerId]
        ) ?: [];

        // Credits the customer holds with us: unused credit-note balances.
        // (customers.account_credit_balance is not maintained — credit notes are.)
        $credit = (string) (db_row(
            "SELECT COALESCE(SUM(amount_remaining), 0) AS c
               FROM credit_notes
              WHERE customer_id = ? AND deleted_at IS NULL
                AND status IN ('active','partially_used')
                AND amount_remaining > 0
                AND (expires_at IS NULL OR expires_at >= ?)",
            [$customerId, $today]
        )['c'] ?? '0.00');

        $cust = db_row("SELECT currency FROM customers WHERE id = ?", [$customerId]) ?: [];

        $activeLeases = db_count(
            "SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
            [$customerId]
        );

        $oldest = (string) ($row['oldest_past_due'] ?? '');

        return $memo[$customerId] = [
            'outstanding'          => (string) ($row['outstanding'] ?? '0.00'),
            'open_count'           => (int) ($row['open_count'] ?? 0),
            'past_due'             => (string) ($row['past_due'] ?? '0.00'),
            'past_due_count'       => (int) ($row['past_due_count'] ?? 0),
            'oldest_past_due_days' => $oldest !== '' ? max(0, pt_days_between($oldest, $today)) : 0,
            'due_soon'             => (string) ($row['due_soon'] ?? '0.00'),
            'due_soon_count'       => (int) ($row['due_soon_count'] ?? 0),
            'next_due_date'        => $row['next_due'] ?? null,
            'credit'               => $credit,
            'currency'             => (string) ($cust['currency'] ?? 'CAD'),
            'active_leases'        => $activeLeases,
        ];
    }

    /**
     * The page header every portal page opens with.
     *
     * @param array{
     *   title:string, eyebrow?:string, sub?:string, actions?:string,
     *   back?:array{0:string,1:string}, meta?:string, class?:string
     * } $o  actions/meta/sub are raw HTML (caller escapes)
     */
    function pt_page_head(array $o): string
    {
        $h = '<header class="pt-head' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '">';
        $h .= '<div class="pt-head-main">';
        if (!empty($o['back'])) {
            $h .= '<a class="pt-back" href="' . e($o['back'][1]) . '">' . pt_icon('chevron-left', 'pt-ic pt-ic--sm') . '<span>' . e($o['back'][0]) . '</span></a>';
        } elseif (!empty($o['eyebrow'])) {
            $h .= '<div class="pt-eyebrow">' . e($o['eyebrow']) . '</div>';
        }
        $h .= '<h1 class="pt-title">' . e($o['title']) . '</h1>';
        if (!empty($o['meta'])) {
            $h .= '<div class="pt-head-meta">' . $o['meta'] . '</div>';
        }
        if (!empty($o['sub'])) {
            $h .= '<p class="pt-sub">' . $o['sub'] . '</p>';
        }
        $h .= '</div>';
        if (!empty($o['actions'])) {
            $h .= '<div class="pt-head-actions">' . $o['actions'] . '</div>';
        }
        return $h . '</header>';
    }

    /**
     * Empty state (the canonical recipe, portal-sized).
     */
    function pt_empty(string $icon, string $title, string $text = '', string $actionHtml = ''): string
    {
        return '<div class="pt-empty">'
            . '<span class="pt-empty-ic">' . pt_icon($icon, 'pt-ic') . '</span>'
            . '<p class="pt-empty-title">' . e($title) . '</p>'
            . ($text !== '' ? '<p class="pt-empty-text">' . e($text) . '</p>' : '')
            . ($actionHtml !== '' ? '<div class="pt-empty-action">' . $actionHtml . '</div>' : '')
            . '</div>';
    }

    /**
     * Customer-safe message for a PaymentInitiator::generate() status.
     * Trap 7: the initiator's own `error` text is operator-facing (setting
     * keys, QuickBooks ids, "run invoice sync first") and must never reach a
     * customer — map the status instead.
     */
    function pt_payment_error_message(?string $status): string
    {
        return match ((string) $status) {
            'feature_disabled', 'not_connected'
                => 'Online payment isn\'t available right now. You can still pay by bank transfer, e-Transfer or cheque — details are on the Pay page.',
            'invoice_not_synced'
                => 'This invoice is still being set up for online payment. Please try again in a few minutes.',
            'invoice_not_payable', 'invoice_no_balance'
                => 'This invoice doesn\'t have a balance to pay. If it was just paid, your account will update shortly.',
            'currency_mismatch'
                => 'This invoice can\'t be paid online. Please contact us and we\'ll help you pay it another way.',
            'invoice_not_found', 'unauthorized', 'portal_user_not_found'
                => 'We couldn\'t find that invoice on your account.',
            default
                => 'We couldn\'t open the secure payment page just now. Please try again shortly, or pay another way.',
        };
    }

    /**
     * Which documents a customer may see — the ONE rule the Documents page,
     * the lease page and the file stream all use (alias `d` = documents).
     *
     *   customer documents   the customer's own record
     *   lease documents      the customer's own leases
     *   equipment documents  units currently on an ACTIVE lease with them
     *                        (was: any unit they ever leased — which showed
     *                        files uploaded while another customer had it)
     *   always               not deleted, not private, current version
     *
     * @return array{0:string,1:list<int>} [sql fragment, params]
     */
    function pt_portal_documents_sql(int $customerId): array
    {
        $sql = "d.deleted_at IS NULL
            AND COALESCE(d.is_private, 0) = 0
            AND COALESCE(d.is_current, 1) = 1
            AND (
                (d.entity_type = 'customer' AND d.entity_id = ?)
             OR (d.entity_type = 'lease' AND d.entity_id IN (
                    SELECT pl.id FROM leases pl WHERE pl.customer_id = ? AND pl.deleted_at IS NULL))
             OR (d.entity_type = 'equipment_unit' AND d.entity_id IN (
                    SELECT pe.equipment_unit_id FROM leases pe
                     WHERE pe.customer_id = ? AND pe.status = 'active' AND pe.deleted_at IS NULL))
            )";
        return [$sql, [$customerId, $customerId, $customerId]];
    }

    /** Customer-facing label for a documents.document_type value. */
    function pt_document_type_label(?string $type): string
    {
        return match ((string) $type) {
            'contract'           => 'Lease contract',
            'amendment'          => 'Amendment',
            'inspection_in'      => 'Inspection (check-in)',
            'inspection_out'     => 'Inspection (check-out)',
            'report'             => 'Report',
            'cvi'                => 'CVI certificate',
            'registration'       => 'Registration',
            'insurance'          => 'Insurance',
            'tax_exemption'      => 'Tax exemption',
            'credit_agreement'   => 'Credit agreement',
            'credit_application' => 'Credit application',
            'estimate'           => 'Estimate',
            'repair_invoice'     => 'Repair invoice',
            ''                   => 'Document',
            default              => ucfirst(str_replace('_', ' ', (string) $type)),
        };
    }

    /**
     * Customer-facing labels for portal request types (the DB enum). The
     * notifier keeps its own staff-facing labels (PortalRequestNotifier).
     *
     * @return array<string,array{0:string,1:string,2:string}> type => [label, icon, blurb]
     */
    function pt_request_types(): array
    {
        return [
            'lease_extension'   => ['Extend a lease',       'calendar-days',     'Keep a unit longer than planned'],
            'early_return'      => ['Return a unit',        'arrow-uturn-left',  'Book a pickup or drop-off'],
            'damage_report'     => ['Report damage / repair', 'wrench-screwdriver', 'Something broke or needs fixing'],
            'billing_inquiry'   => ['Billing question',     'receipt-percent',   'Invoices, payments, credits'],
            'document_request'  => ['Request a document',   'document-text',     'Registration, insurance, contract'],
            'new_lease_inquiry' => ['Rent more equipment',  'truck',             'Get a quote for another unit'],
            'general'           => ['Something else',       'chat-bubble-left-ellipsis', 'Any other question'],
        ];
    }

    /**
     * Create a portal service request and notify the routed staff — the one
     * write path shared by requests/create.php and the payment notice.
     * Lease/equipment ids must already be validated as the customer's own.
     * Notification is best-effort (PortalRequestNotifier never throws).
     *
     * @param array{type:string, subject:string, message:string, lease_id?:?int, equipment_unit_id?:?int} $r
     * @return int new portal_service_requests.id
     */
    function pt_create_request(array $r): int
    {
        $id = (int) db_insert('portal_service_requests', [
            'portal_user_id'    => portal_user_id(),
            'customer_id'       => portal_customer_id(),
            'equipment_unit_id' => $r['equipment_unit_id'] ?? null,
            'lease_id'          => $r['lease_id'] ?? null,
            'request_type'      => $r['type'],
            'subject'           => mb_substr($r['subject'], 0, 500),
            'message'           => mb_substr($r['message'], 0, 5000),
            'status'            => 'open',
        ]);
        try {
            \FleetForge\Notifications\PortalRequestNotifier::notify($id);
        } catch (\Throwable $e) {
            error_log('[portal] PortalRequestNotifier threw despite best-effort contract: ' . $e->getMessage());
        }
        return $id;
    }

    /**
     * "4,7,9" (or an array) → unique positive ints, capped at $max.
     *
     * @return list<int>
     */
    function pt_parse_ids(mixed $raw, int $max = 100): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];
        foreach ($parts as $p) {
            $id = clean_int(is_string($p) ? trim($p) : $p);
            if ($id && $id > 0) $ids[$id] = $id;
            if (count($ids) >= $max) break;
        }
        return array_values($ids);
    }

    /** Escape a user search term for a LIKE '%…%' pattern (%, _ and \ are literal). */
    function pt_like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    /** Two-letter initials for an avatar. */
    function pt_initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $i = strtoupper(mb_substr($parts[0] ?? '', 0, 1));
        if (count($parts) > 1) {
            $i .= strtoupper(mb_substr((string) end($parts), 0, 1));
        }
        return $i !== '' ? $i : '·';
    }

    /**
     * Payment-method label a customer understands.
     */
    function pt_payment_method(?string $m): string
    {
        return match ((string) $m) {
            'check'          => 'Cheque',
            'ach'            => 'Bank transfer (EFT)',
            'wire'           => 'Wire transfer',
            'credit_card'    => 'Card',
            'cash'           => 'Cash',
            'e_transfer'     => 'Interac e-Transfer',
            'account_credit' => 'Account credit',
            ''               => '—',
            default          => ucfirst(str_replace('_', ' ', (string) $m)),
        };
    }

    /**
     * How to pay us offline, from Settings (bank, cheque, instructions).
     * Same resolution the invoice PDF uses (Bug #23): invoice-level
     * instructions win over the company-wide fallback.
     *
     * @return array{instructions:string, bank_name:string, bank_account:string, payable_to:string, remit_address:string, email:string, phone:string}
     */
    function pt_offline_payment_details(): array
    {
        $addr = trim((string) settings_get('company.address', ''));
        return [
            'instructions'  => (string) (settings_get('invoice.payment_instructions', '') ?: settings_get('company.payment_instructions', '')),
            'bank_name'     => (string) settings_get('company.bank_name', ''),
            'bank_account'  => (string) settings_get('company.bank_account', ''),
            'payable_to'    => (string) (settings_get('company.check_payable_to', '') ?: settings_get('company.name', '')),
            'remit_address' => $addr,
            'email'         => (string) settings_get('company.email', ''),
            'phone'         => (string) settings_get('company.phone', ''),
        ];
    }
}
