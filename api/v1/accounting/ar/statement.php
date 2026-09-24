<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/statement.php
 *
 * Customer statement PDF — generates a statement showing opening balance,
 * all invoices, payments, credit notes, and write-offs for a date range,
 * with a closing balance and aged summary.
 *
 * @method  GET
 * @query   customer_id (required), date_from?, date_to?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns PDF binary stream (Content-Type: application/pdf)
 *
 * Decisions: A9 (AR subledger is FleetForge billing)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Customer statements)
 * Session: S031, S-PDF-LETTERHEAD (letterhead + layout via FleetForge\Pdf\PdfKit)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Pdf\PdfKit;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);

$dateTo   = clean_date($_GET['date_to'] ?? null) ?? date('Y-m-d');
$dateFrom = clean_date($_GET['date_from'] ?? null) ?? date('Y-m-01', strtotime('-3 months'));

// S-UTC-STAMPS: credit_note_applications.applied_at is a UTC DATETIME, while
// date_from/date_to are company-local business dates. Bound the applications
// by the UTC instants of local midnight (sargable, DST-correct).
$fromStartUtc = ff_local_day_start_utc($dateFrom);
$toEndUtc     = ff_local_day_start_utc(ff_local_date_add($dateTo, 1));

// Fetch customer
$customer = db_row(
    "SELECT id, company_name, contact_name, email, phone,
            address, billing_address, city, province, postal_code, country
     FROM customers
     WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) json_error('NOT_FOUND', 'Customer not found.', 404);

$companyPst     = settings_get('company.pst_number', '');

// ── Opening balance: sum of all open invoice balances BEFORE date_from ─���
// This includes invoices created before date_from that still had balance
$openingRow = db_row(
    "SELECT COALESCE(SUM(i.total_amount), 0) AS inv_total,
            COALESCE(
                (SELECT SUM(pa.amount)
                 FROM payment_allocations pa
                 JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL
                 WHERE pa.invoice_id IN (
                     SELECT id FROM invoices
                     WHERE customer_id = ? AND deleted_at IS NULL
                       AND invoice_date < ?
                       AND status NOT IN ('draft','void')
                 ) AND p.payment_date < ?
                ), 0) AS pay_total,
            COALESCE(
                (SELECT SUM(ca.amount_applied)
                 FROM credit_note_applications ca
                 JOIN credit_notes cn ON cn.id = ca.credit_note_id AND cn.deleted_at IS NULL
                 WHERE ca.invoice_id IN (
                     SELECT id FROM invoices
                     WHERE customer_id = ? AND deleted_at IS NULL
                       AND invoice_date < ?
                       AND status NOT IN ('draft','void')
                 ) AND ca.applied_at < ?
                ), 0) AS credit_total
     FROM invoices i
     WHERE i.customer_id = ? AND i.deleted_at IS NULL
       AND i.invoice_date < ?
       AND i.status NOT IN ('draft','void')",
    // 6th param = credit applications before 00:00 local on date_from (UTC instant)
    [$customerId, $dateFrom, $dateFrom, $customerId, $dateFrom, $fromStartUtc, $customerId, $dateFrom]
);
$openingBalance = bcsub(
    (string)($openingRow['inv_total'] ?? '0.00'),
    bcadd((string)($openingRow['pay_total'] ?? '0.00'), (string)($openingRow['credit_total'] ?? '0.00'), 2),
    2
);

// ── Transactions within the date range ──
// Invoices sent/created in range
$invoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
            i.total_amount, i.balance_due, i.status
     FROM invoices i
     WHERE i.customer_id = ? AND i.deleted_at IS NULL
       AND i.invoice_date BETWEEN ? AND ?
       AND i.status NOT IN ('draft','void')
     ORDER BY i.invoice_date ASC, i.id ASC",
    [$customerId, $dateFrom, $dateTo]
);

// Payments in range
$payments = db_select(
    "SELECT p.id, p.payment_number, p.payment_date, p.amount,
            p.payment_method, pa.invoice_id, pa.amount AS amount_applied,
            inv.invoice_number AS applied_to_invoice
     FROM payments p
     JOIN payment_allocations pa ON pa.payment_id = p.id
     JOIN invoices inv ON inv.id = pa.invoice_id AND inv.deleted_at IS NULL
     WHERE p.deleted_at IS NULL
       AND inv.customer_id = ?
       AND p.payment_date BETWEEN ? AND ?
     ORDER BY p.payment_date ASC, p.id ASC",
    [$customerId, $dateFrom, $dateTo]
);

// Credit note applications in range
$credits = db_select(
    "SELECT cn.id, cn.credit_note_number, cn.amount AS cn_amount,
            ca.amount_applied, ca.applied_at,
            inv.invoice_number AS applied_to_invoice
     FROM credit_note_applications ca
     JOIN credit_notes cn ON cn.id = ca.credit_note_id AND cn.deleted_at IS NULL
     JOIN invoices inv ON inv.id = ca.invoice_id AND inv.deleted_at IS NULL
     WHERE cn.customer_id = ?
       AND ca.applied_at >= ? AND ca.applied_at < ?
     ORDER BY ca.applied_at ASC",
    [$customerId, $fromStartUtc, $toEndUtc]
);

// Bad debt write-offs in range
$writeoffs = db_select(
    "SELECT bw.id, bw.writeoff_date, bw.amount, bw.reason,
            inv.invoice_number
     FROM acc_bad_debt_writeoffs bw
     JOIN invoices inv ON inv.id = bw.invoice_id
     WHERE bw.customer_id = ?
       AND bw.writeoff_date BETWEEN ? AND ?
     ORDER BY bw.writeoff_date ASC",
    [$customerId, $dateFrom, $dateTo]
);

// ── Build chronological transaction list ──
$transactions = [];

foreach ($invoices as $inv) {
    $transactions[] = [
        'date'        => $inv['invoice_date'],
        'type'        => 'Invoice',
        'reference'   => $inv['invoice_number'],
        'description' => "Invoice {$inv['invoice_number']}",
        'debit'       => (string)$inv['total_amount'],
        'credit'      => '0.00',
    ];
}

foreach ($payments as $pay) {
    $transactions[] = [
        'date'        => $pay['payment_date'],
        'type'        => 'Payment',
        'reference'   => $pay['payment_number'],
        'description' => "Payment {$pay['payment_number']} → {$pay['applied_to_invoice']}",
        'debit'       => '0.00',
        'credit'      => (string)$pay['amount_applied'],
    ];
}

foreach ($credits as $cr) {
    $transactions[] = [
        // S-UTC-STAMPS: the statement date is the LOCAL business day of the UTC stamp.
        'date'        => ff_utc_to_local((string) $cr['applied_at']),
        'type'        => 'Credit',
        'reference'   => $cr['credit_note_number'],
        'description' => "Credit {$cr['credit_note_number']} → {$cr['applied_to_invoice']}",
        'debit'       => '0.00',
        'credit'      => (string)$cr['amount_applied'],
    ];
}

foreach ($writeoffs as $wo) {
    $transactions[] = [
        'date'        => $wo['writeoff_date'],
        'type'        => 'Write-off',
        'reference'   => $wo['invoice_number'],
        'description' => "Bad debt write-off — {$wo['invoice_number']}",
        'debit'       => '0.00',
        'credit'      => (string)$wo['amount'],
    ];
}

// Sort chronologically
usort($transactions, fn($a, $b) => strcmp($a['date'], $b['date']));

// Calculate running balance and closing balance
$runningBalance = $openingBalance;
foreach ($transactions as &$txn) {
    $runningBalance = bcadd(bcsub($runningBalance, $txn['credit'], 2), $txn['debit'], 2);
    $txn['balance'] = $runningBalance;
}
unset($txn);
$closingBalance = $runningBalance;

// ── AR Aging summary (as of date_to) ──
$agingInvoices = db_select(
    "SELECT id, invoice_number, due_date, balance_due
     FROM invoices
     WHERE customer_id = ? AND deleted_at IS NULL
       AND status NOT IN ('paid','void','written_off','draft')
       AND balance_due > 0
     ORDER BY due_date ASC",
    [$customerId]
);

$aging = ['current' => '0.00', 'days_1_30' => '0.00', 'days_31_60' => '0.00', 'days_61_90' => '0.00', 'days_90_plus' => '0.00'];
$asOf = new \DateTime($dateTo);
foreach ($agingInvoices as $ai) {
    $due = new \DateTime($ai['due_date']);
    $diff = (int) $asOf->diff($due)->format('%r%a');
    $daysPast = $diff < 0 ? abs($diff) : 0;
    $bucket = match (true) {
        $daysPast === 0  => 'current',
        $daysPast <= 30  => 'days_1_30',
        $daysPast <= 60  => 'days_31_60',
        $daysPast <= 90  => 'days_61_90',
        default          => 'days_90_plus',
    };
    $aging[$bucket] = bcadd($aging[$bucket], (string)$ai['balance_due'], 2);
}
$agingTotal = '0.00';
foreach ($aging as $v) $agingTotal = bcadd($agingTotal, $v, 2);

// ── Render (S-PDF-LETTERHEAD) ──────────────────────────────────────────────
// The letterhead (logo, company block, page numbers) comes from PdfKit so a
// statement matches the invoices it lists. Money prints via PdfKit::money
// (bcmath, D16) — the old number_format((float)…) was a float leak.
$m = static fn (string $v): string => e(PdfKit::money($v));

// Customer block: billing address when set (same rule as invoices), else the
// main address + its city line — without doubling a city already in it.
$custLines = [];
$addr = trim((string) ($customer['billing_address'] ?: $customer['address'] ?? ''));
if ($addr !== '') {
    $custLines = array_values(array_filter(array_map('trim', preg_split('/\R/', $addr) ?: [])));
}
if (!$customer['billing_address']) {
    $cityLine = trim((string) $customer['city']
        . (!empty($customer['province']) ? ((string) $customer['city'] !== '' ? ', ' : '') . $customer['province'] : '')
        . (!empty($customer['postal_code']) ? ' ' . $customer['postal_code'] : ''));
    $norm = static fn (string $v): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v));
    if ($cityLine !== '' && !str_contains($norm($addr), $norm((string) ($customer['postal_code'] ?: $customer['city'])))) {
        $custLines[] = $cityLine;
    }
}

$totalCharges = '0.00';
$totalCredits = '0.00';
foreach ($transactions as $t) {
    $totalCharges = bcadd($totalCharges, $t['debit'], 2);
    $totalCredits = bcadd($totalCredits, $t['credit'], 2);
}

$html = '<table class="ff-panels"><tr>'
    . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Statement for</div>'
    . '<strong>' . e($customer['company_name']) . '</strong><br>'
    . ($customer['contact_name'] ? 'Attn: ' . e($customer['contact_name']) . '<br>' : '')
    . implode('<br>', array_map('e', $custLines))
    . ($customer['email'] ? '<br><span class="muted">' . e($customer['email']) . '</span>' : '')
    . '</td><td class="ff-gap"></td>'
    . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Account summary</div>'
    . '<table class="ff-kv" width="100%">'
    . '<tr><td class="k">Opening balance</td><td class="num">' . $m($openingBalance) . '</td></tr>'
    . '<tr><td class="k">Charges this period</td><td class="num">' . $m($totalCharges) . '</td></tr>'
    . '<tr><td class="k">Payments &amp; credits</td><td class="num">-' . $m($totalCredits) . '</td></tr>'
    . '</table>'
    . '<table width="100%" style="margin-top:2mm;"><tr>'
    . '<td style="background-color:' . e(PdfKit::brand()['accent']) . ';color:#ffffff;font-weight:bold;padding:2mm 2.5mm;">Balance due</td>'
    . '<td style="background-color:' . e(PdfKit::brand()['accent']) . ';color:#ffffff;font-weight:bold;font-size:11pt;text-align:right;padding:2mm 2.5mm;">' . $m($closingBalance) . '</td>'
    . '</tr></table>'
    . '</td></tr></table>';

$html .= '<div class="ff-h">Account activity</div>';
if (count($transactions) > 0) {
    $html .= '<table class="ff-grid"><thead><tr>'
        . '<th width="13%">Date</th><th width="9%">Type</th><th width="17%">Reference</th><th>Description</th>'
        . '<th class="num" width="12%">Charges</th><th class="num" width="12%">Credits</th><th class="num" width="12%">Balance</th>'
        . '</tr></thead><tbody>'
        . '<tr><td colspan="4" class="muted"><em>Balance brought forward</em></td><td></td><td></td>'
        . '<td class="num"><strong>' . $m($openingBalance) . '</strong></td></tr>';
    foreach ($transactions as $txn) {
        $html .= '<tr>'
            . '<td class="nw">' . e(PdfKit::date($txn['date'])) . '</td>'
            . '<td>' . e($txn['type']) . '</td>'
            . '<td class="nw">' . e($txn['reference']) . '</td>'
            . '<td>' . e($txn['description']) . '</td>'
            . '<td class="num">' . (bccomp($txn['debit'], '0', 2) > 0 ? $m($txn['debit']) : '') . '</td>'
            . '<td class="num">' . (bccomp($txn['credit'], '0', 2) > 0 ? $m($txn['credit']) : '') . '</td>'
            . '<td class="num">' . $m($txn['balance']) . '</td>'
            . '</tr>';
    }
    $html .= '<tr class="ff-sum"><td colspan="4">Period totals</td>'
        . '<td class="num">' . $m($totalCharges) . '</td><td class="num">' . $m($totalCredits) . '</td>'
        . '<td class="num">' . $m($closingBalance) . '</td></tr>'
        . '</tbody></table>';
} else {
    $html .= '<div class="ff-panel-label" style="text-align:center;padding:6mm 0;">No transactions in this period.</div>';
}

$html .= '<div class="ff-h">Aged balance</div>'
    . '<table class="ff-grid"><thead><tr>'
    . '<th class="num">Current</th><th class="num">1–30 days</th><th class="num">31–60 days</th>'
    . '<th class="num">61–90 days</th><th class="num">90+ days</th><th class="num">Total owing</th>'
    . '</tr></thead><tbody><tr>'
    . '<td class="num">' . $m($aging['current']) . '</td>'
    . '<td class="num">' . $m($aging['days_1_30']) . '</td>'
    . '<td class="num">' . $m($aging['days_31_60']) . '</td>'
    . '<td class="num">' . $m($aging['days_61_90']) . '</td>'
    . '<td class="num">' . $m($aging['days_90_plus']) . '</td>'
    . '<td class="num"><strong>' . $m($agingTotal) . '</strong></td>'
    . '</tr></tbody></table>';

try {
    $bytes = PdfKit::render($html, [
        'title'     => 'Statement',
        'reference' => (string) $customer['company_name'],
        'meta'      => [
            'Statement date' => PdfKit::date($dateTo),
            'Period'         => PdfKit::period($dateFrom, $dateTo),
            'Account no.'    => (string) $customer['id'],
        ],
        'footer_note' => 'Please contact us with any questions about your account.',
    ]);
} catch (\Throwable $e) {
    error_log('[ar/statement] customer ' . $customerId . ': ' . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Could not produce the statement PDF. Please try again.', 500);
}

PdfKit::stream($bytes, 'statement_' . $customer['id'] . '_' . $dateTo);
