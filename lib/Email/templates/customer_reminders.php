<?php
declare(strict_types=1);

/**
 * lib/Email/templates/customer_reminders.php
 *
 * Customer-facing reminder email bodies for the reminder types in
 * config/customer_notifications.php that are sent by cron/customer_reminders.php.
 * Each function returns the INNER HTML body only (inline styles, email-client
 * safe) — the caller wraps it in EmailService::renderEmailHtml() (brand shell)
 * before Mailer::send(), exactly like customer_compliance_expiring.php.
 *
 * All monetary/date values arrive PRE-FORMATTED as display strings so these
 * functions stay pure presentation and never touch the DB or global helpers.
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 */

if (!function_exists('ff_reminder_greeting')) {
    /** Standard greeting + one-line intro shared by every reminder body. */
    function ff_reminder_greeting(string $customerName, string $intro): string
    {
        $name = htmlspecialchars($customerName !== '' ? $customerName : 'there', ENT_QUOTES, 'UTF-8');
        return '<p style="margin:0 0 16px;">Hi ' . $name . ',</p>'
             . '<p style="margin:0 0 16px;">' . $intro . '</p>';
    }
}

if (!function_exists('ff_reminder_kv_box')) {
    /**
     * A light grey key→value detail box.
     * @param array<int,array{0:string,1:string}> $rows [label, value] pairs (value may contain safe HTML).
     */
    function ff_reminder_kv_box(array $rows): string
    {
        $html = '<div style="margin:0 0 16px;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">'
              . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;">';
        foreach ($rows as $r) {
            $label = htmlspecialchars((string) ($r[0] ?? ''), ENT_QUOTES, 'UTF-8');
            $value = (string) ($r[1] ?? '');
            $html .= '<tr>'
                . '<td style="padding:4px 12px 4px 0;font-size:13px;color:#6b7280;white-space:nowrap;">' . $label . '</td>'
                . '<td style="padding:4px 0;font-size:13px;color:#1c1c1a;font-weight:600;text-align:right;">' . $value . '</td>'
                . '</tr>';
        }
        $html .= '</table></div>';
        return $html;
    }
}

if (!function_exists('ff_reminder_cta')) {
    /** Dark primary call-to-action button. */
    function ff_reminder_cta(string $url, string $label): string
    {
        $url   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return '';
        }
        return '<p style="margin:0 0 16px;">'
            . '<a href="' . $url . '" style="display:inline-block;padding:10px 20px;background:#1c1c1a;'
            . 'color:#ffffff;text-decoration:none;border-radius:5px;font-size:14px;font-weight:600;">'
            . $label . '</a></p>';
    }
}

if (!function_exists('ff_reminder_footer_note')) {
    /** Small muted closing line, shared. */
    function ff_reminder_footer_note(string $text): string
    {
        return '<p style="margin:16px 0 0;font-size:12px;color:#6b7280;">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

// =====================================================================
// invoice_due_soon
// =====================================================================
if (!function_exists('render_customer_invoice_due_soon')) {
    function render_customer_invoice_due_soon(array $d): string
    {
        $company = htmlspecialchars((string) ($d['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $daysTxt = (string) ($d['days_until_text'] ?? 'soon');
        return ff_reminder_greeting(
                (string) ($d['customer_name'] ?? ''),
                'This is a friendly reminder from <strong>' . $company . '</strong> that the following invoice is due ' . htmlspecialchars($daysTxt, ENT_QUOTES, 'UTF-8') . '.'
            )
            . ff_reminder_kv_box([
                ['Invoice',   htmlspecialchars((string) ($d['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8')],
                ['Due date',  htmlspecialchars((string) ($d['due_date'] ?? ''), ENT_QUOTES, 'UTF-8')],
                ['Amount due', htmlspecialchars((string) ($d['amount'] ?? ''), ENT_QUOTES, 'UTF-8')],
            ])
            . ff_reminder_cta((string) ($d['portal_url'] ?? ''), 'View & Pay Invoice')
            . ff_reminder_footer_note('If you have already sent payment, thank you — please disregard this notice.');
    }
}

// =====================================================================
// invoice_overdue
// =====================================================================
if (!function_exists('render_customer_invoice_overdue')) {
    function render_customer_invoice_overdue(array $d): string
    {
        $company = htmlspecialchars((string) ($d['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $daysOverdue = (int) ($d['days_overdue'] ?? 0);
        $overdueTxt = $daysOverdue === 1 ? '1 day past due' : ($daysOverdue . ' days past due');
        return ff_reminder_greeting(
                (string) ($d['customer_name'] ?? ''),
                'Our records show the following invoice from <strong>' . $company . '</strong> is now <strong style="color:#b91c1c;">' . $overdueTxt . '</strong>.'
            )
            . ff_reminder_kv_box([
                ['Invoice',   htmlspecialchars((string) ($d['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8')],
                ['Due date',  htmlspecialchars((string) ($d['due_date'] ?? ''), ENT_QUOTES, 'UTF-8')],
                ['Balance due', htmlspecialchars((string) ($d['amount'] ?? ''), ENT_QUOTES, 'UTF-8')],
            ])
            . '<p style="margin:0 0 16px;">Please arrange payment at your earliest convenience to keep your account in good standing.</p>'
            . ff_reminder_cta((string) ($d['portal_url'] ?? ''), 'Pay Now')
            . ff_reminder_footer_note('If payment is already on its way, thank you — please disregard this reminder.');
    }
}

// =====================================================================
// payment_receipt
// =====================================================================
if (!function_exists('render_customer_payment_receipt')) {
    function render_customer_payment_receipt(array $d): string
    {
        $company = htmlspecialchars((string) ($d['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rows = [
            ['Receipt #', htmlspecialchars((string) ($d['payment_number'] ?? ''), ENT_QUOTES, 'UTF-8')],
            ['Date',      htmlspecialchars((string) ($d['payment_date'] ?? ''), ENT_QUOTES, 'UTF-8')],
            ['Amount',    htmlspecialchars((string) ($d['amount'] ?? ''), ENT_QUOTES, 'UTF-8')],
        ];
        if (!empty($d['method'])) {
            $rows[] = ['Method', htmlspecialchars((string) $d['method'], ENT_QUOTES, 'UTF-8')];
        }
        if (!empty($d['applied_to'])) {
            $rows[] = ['Applied to', htmlspecialchars((string) $d['applied_to'], ENT_QUOTES, 'UTF-8')];
        }
        return ff_reminder_greeting(
                (string) ($d['customer_name'] ?? ''),
                'Thank you — we have received your payment. Here is your receipt from <strong>' . $company . '</strong>.'
            )
            . ff_reminder_kv_box($rows)
            . ff_reminder_cta((string) ($d['portal_url'] ?? ''), 'View Account')
            . ff_reminder_footer_note('This receipt confirms the payment above was recorded to your account.');
    }
}

// =====================================================================
// statement (account summary)
// =====================================================================
if (!function_exists('render_customer_statement')) {
    /**
     * @param array $d {
     *   customer_name, company_name, portal_url, balance_total,
     *   invoices: list<array{invoice_number, invoice_date, due_date, balance, status}>
     * }
     */
    function render_customer_statement(array $d): string
    {
        $company  = htmlspecialchars((string) ($d['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $invoices = is_array($d['invoices'] ?? null) ? $d['invoices'] : [];

        $rowsHtml = '';
        foreach ($invoices as $inv) {
            $rowsHtml .= '<tr>'
                . '<td style="padding:6px 8px 6px 0;font-size:13px;color:#1c1c1a;font-family:monospace;">' . htmlspecialchars((string) ($inv['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:6px 8px;font-size:13px;color:#6b7280;">' . htmlspecialchars((string) ($inv['due_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:6px 0 6px 8px;font-size:13px;color:#1c1c1a;font-weight:600;text-align:right;">' . htmlspecialchars((string) ($inv['balance'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="3" style="padding:6px 0;font-size:13px;color:#6b7280;">No open invoices.</td></tr>';
        }

        $table = '<div style="margin:0 0 16px;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">'
            . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;">'
            . '<thead><tr>'
            . '<th style="text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;padding-bottom:6px;">Invoice</th>'
            . '<th style="text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;padding-bottom:6px;padding-left:8px;">Due</th>'
            . '<th style="text-align:right;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;padding-bottom:6px;padding-left:8px;">Balance</th>'
            . '</tr></thead><tbody>' . $rowsHtml . '</tbody>'
            . '<tfoot><tr>'
            . '<td colspan="2" style="padding-top:10px;font-size:13px;font-weight:700;color:#1c1c1a;border-top:1px solid #e5e7eb;">Total balance</td>'
            . '<td style="padding-top:10px;font-size:14px;font-weight:700;color:#1c1c1a;text-align:right;border-top:1px solid #e5e7eb;">' . htmlspecialchars((string) ($d['balance_total'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr></tfoot>'
            . '</table></div>';

        return ff_reminder_greeting(
                (string) ($d['customer_name'] ?? ''),
                'Here is your current account statement from <strong>' . $company . '</strong>.'
            )
            . $table
            . ff_reminder_cta((string) ($d['portal_url'] ?? ''), 'View Statement in Portal')
            . ff_reminder_footer_note('Please contact us if you have any questions about your account.');
    }
}

// =====================================================================
// lease_ending_soon
// =====================================================================
if (!function_exists('render_customer_lease_ending')) {
    function render_customer_lease_ending(array $d): string
    {
        $company = htmlspecialchars((string) ($d['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $daysTxt = (string) ($d['days_until_text'] ?? 'soon');
        $rows = [
            ['Contract',  htmlspecialchars((string) ($d['contract_number'] ?? ''), ENT_QUOTES, 'UTF-8')],
            ['End date',  htmlspecialchars((string) ($d['end_date'] ?? ''), ENT_QUOTES, 'UTF-8')],
        ];
        if (!empty($d['unit_number'])) {
            array_splice($rows, 1, 0, [['Unit', htmlspecialchars((string) $d['unit_number'], ENT_QUOTES, 'UTF-8')]]);
        }
        return ff_reminder_greeting(
                (string) ($d['customer_name'] ?? ''),
                'A heads-up from <strong>' . $company . '</strong>: the following rental agreement is scheduled to end ' . htmlspecialchars($daysTxt, ENT_QUOTES, 'UTF-8') . '.'
            )
            . ff_reminder_kv_box($rows)
            . '<p style="margin:0 0 16px;">If you would like to extend, return, or discuss this equipment, please get in touch and we will be happy to help.</p>'
            . ff_reminder_cta((string) ($d['portal_url'] ?? ''), 'View Rental')
            . ff_reminder_footer_note('No action is required if your plans are already set.');
    }
}

// =====================================================================
// reservation_pickup
// =====================================================================
if (!function_exists('render_customer_reservation_pickup')) {
    function render_customer_reservation_pickup(array $d): string
    {
        $company = htmlspecialchars((string) ($d['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $daysTxt = (string) ($d['days_until_text'] ?? 'soon');
        $rows = [
            ['Pickup date', htmlspecialchars((string) ($d['pickup_date'] ?? ''), ENT_QUOTES, 'UTF-8')],
        ];
        if (!empty($d['pickup_time'])) {
            $rows[] = ['Pickup time', htmlspecialchars((string) $d['pickup_time'], ENT_QUOTES, 'UTF-8')];
        }
        if (!empty($d['quantity'])) {
            $rows[] = ['Units reserved', htmlspecialchars((string) $d['quantity'], ENT_QUOTES, 'UTF-8')];
        }
        return ff_reminder_greeting(
                (string) ($d['customer_name'] ?? ''),
                'A reminder from <strong>' . $company . '</strong> about your upcoming equipment reservation, scheduled for pickup ' . htmlspecialchars($daysTxt, ENT_QUOTES, 'UTF-8') . '.'
            )
            . ff_reminder_kv_box($rows)
            . '<p style="margin:0 0 16px;">Please ensure any required paperwork and payment arrangements are ready so your pickup goes smoothly.</p>'
            . ff_reminder_cta((string) ($d['portal_url'] ?? ''), 'View Reservation')
            . ff_reminder_footer_note('Need to change your reservation? Contact us and we will assist.');
    }
}
