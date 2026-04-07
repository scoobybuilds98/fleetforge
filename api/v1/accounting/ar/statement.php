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
 * Session: S031
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);

$dateTo   = clean_date($_GET['date_to'] ?? null) ?? date('Y-m-d');
$dateFrom = clean_date($_GET['date_from'] ?? null) ?? date('Y-m-01', strtotime('-3 months'));

// Fetch customer
$customer = db_row(
    "SELECT id, company_name, contact_name, email, phone,
            address, billing_address, city, province, postal_code, country
     FROM customers
     WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) json_error('NOT_FOUND', 'Customer not found.', 404);

// Fetch company settings for PDF header
$companyName    = settings_get('company.name', 'FleetForge');
$companyAddress = settings_get('company.address', '');
$companyCity    = settings_get('company.city', '');
$companyProvince = settings_get('company.province', '');
$companyPostal  = settings_get('company.postal_code', '');
$companyPhone   = settings_get('company.phone', '');
$companyEmail   = settings_get('company.email', '');
$currencySymbol = settings_get('company.currency_symbol', '$');
$companyGst     = settings_get('company.gst_number', '');
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
    [$customerId, $dateFrom, $dateFrom, $customerId, $dateFrom, $dateFrom, $customerId, $dateFrom]
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
       AND DATE(ca.applied_at) BETWEEN ? AND ?
     ORDER BY ca.applied_at ASC",
    [$customerId, $dateFrom, $dateTo]
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
        'date'        => substr($cr['applied_at'], 0, 10),
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

// ── Generate PDF via mPDF ──
// WHY: tempDir must exist before mPDF constructor runs
$tmpDir = FF_ROOT . '/storage/tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}

$mpdf = new \Mpdf\Mpdf([
    'margin_top'    => 10,
    'margin_bottom' => 15,
    'margin_left'   => 12,
    'margin_right'  => 12,
    'default_font'  => 'dejavusans',
    'tempDir'       => $tmpDir,
]);

$mpdf->SetTitle("Statement — {$customer['company_name']}");
$mpdf->SetAuthor($companyName);

// Helper for formatting currency in PDF
$fmt = fn(string $val) => $currencySymbol . number_format((float)$val, 2);
$fmtDate = fn(string $d) => date('M j, Y', strtotime($d));

// Build HTML
$html = '
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #222; }
    h1 { font-size: 18pt; margin: 0 0 2px; }
    .header-table { width: 100%; margin-bottom: 14px; }
    .header-table td { vertical-align: top; padding: 0; }
    .company-info { font-size: 8pt; color: #555; line-height: 1.5; }
    .customer-box { border: 1px solid #ccc; padding: 10px; border-radius: 4px; background: #f9f9f9; }
    .customer-box strong { font-size: 10pt; }
    .summary-row { margin: 12px 0; }
    .summary-row td { padding: 6px 10px; font-size: 9pt; }
    .txn-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .txn-table th { background: #2563eb; color: #fff; font-size: 8pt; padding: 6px 8px; text-align: left; }
    .txn-table th.amt { text-align: right; }
    .txn-table td { padding: 5px 8px; border-bottom: 1px solid #e5e5e5; font-size: 8.5pt; }
    .txn-table td.amt { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .txn-table tr.alt { background: #f7f7f7; }
    .aging-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .aging-table th { background: #f3f4f6; font-size: 8pt; padding: 6px 8px; text-align: right; border-bottom: 2px solid #ccc; }
    .aging-table th:first-child { text-align: left; }
    .aging-table td { padding: 5px 8px; text-align: right; font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; border-bottom: 1px solid #e5e5e5; }
    .aging-table td:first-child { text-align: left; font-family: DejaVu Sans, sans-serif; }
    .total-row td { font-weight: bold; border-top: 2px solid #333; }
    .footer { font-size: 7pt; color: #999; text-align: center; margin-top: 20px; }
</style>

<table class="header-table">
<tr>
    <td style="width:55%;">
        <h1>' . e($companyName) . '</h1>
        <div class="company-info">
            ' . e($companyAddress) . '<br>
            ' . e($companyCity) . ', ' . e($companyProvince) . ' ' . e($companyPostal) . '<br>
            ' . ($companyPhone ? 'Tel: ' . e($companyPhone) . '<br>' : '') . '
            ' . ($companyEmail ? e($companyEmail) . '<br>' : '') . '
            ' . ($companyGst ? 'GST#: ' . e($companyGst) . '<br>' : '') . '
            ' . ($companyPst ? 'PST#: ' . e($companyPst) : '') . '
        </div>
    </td>
    <td style="width:45%;text-align:right;">
        <div style="font-size:14pt;font-weight:bold;color:#2563eb;margin-bottom:8px;">STATEMENT</div>
        <div style="font-size:8.5pt;color:#555;">
            Statement Date: <strong>' . e($fmtDate($dateTo)) . '</strong><br>
            Period: ' . e($fmtDate($dateFrom)) . ' — ' . e($fmtDate($dateTo)) . '<br>
            Customer ID: ' . e((string)$customer['id']) . '
        </div>
    </td>
</tr>
</table>

<div class="customer-box">
    <strong>' . e($customer['company_name']) . '</strong><br>
    ' . ($customer['contact_name'] ? e($customer['contact_name']) . '<br>' : '') . '
    ' . ($customer['billing_address'] ? e($customer['billing_address']) . '<br>' : ($customer['address'] ? e($customer['address']) . '<br>' : '')) . '
    ' . ($customer['city'] ? e($customer['city']) . ', ' . e($customer['province'] ?? '') . ' ' . e($customer['postal_code'] ?? '') : '') . '
</div>

<table style="width:100%;margin-top:14px;">
<tr>
    <td style="width:50%;padding:8px 12px;background:#f0f0f0;border-radius:4px;">
        <span style="font-size:8pt;color:#666;">Opening Balance</span><br>
        <span style="font-size:12pt;font-weight:bold;">' . e($fmt($openingBalance)) . '</span>
    </td>
    <td style="width:50%;padding:8px 12px;background:' . (bccomp($closingBalance, '0', 2) > 0 ? '#fee2e2' : '#dcfce7') . ';border-radius:4px;text-align:right;">
        <span style="font-size:8pt;color:#666;">Balance Due</span><br>
        <span style="font-size:12pt;font-weight:bold;">' . e($fmt($closingBalance)) . '</span>
    </td>
</tr>
</table>';

// Transaction table
if (count($transactions) > 0) {
    $html .= '
<table class="txn-table">
<thead>
<tr>
    <th style="width:12%;">Date</th>
    <th style="width:10%;">Type</th>
    <th style="width:14%;">Reference</th>
    <th style="width:28%;">Description</th>
    <th class="amt" style="width:12%;">Charges</th>
    <th class="amt" style="width:12%;">Credits</th>
    <th class="amt" style="width:12%;">Balance</th>
</tr>
</thead>
<tbody>
<tr>
    <td colspan="4" style="font-weight:bold;font-size:8pt;color:#666;">Brought Forward</td>
    <td class="amt"></td>
    <td class="amt"></td>
    <td class="amt" style="font-weight:bold;">' . e($fmt($openingBalance)) . '</td>
</tr>';

    foreach ($transactions as $i => $txn) {
        $altClass = $i % 2 === 0 ? '' : ' class="alt"';
        $html .= "
<tr{$altClass}>
    <td>" . e($fmtDate($txn['date'])) . "</td>
    <td>" . e($txn['type']) . "</td>
    <td>" . e($txn['reference']) . "</td>
    <td>" . e($txn['description']) . "</td>
    <td class=\"amt\">" . (bccomp($txn['debit'], '0', 2) > 0 ? e($fmt($txn['debit'])) : '') . "</td>
    <td class=\"amt\">" . (bccomp($txn['credit'], '0', 2) > 0 ? e($fmt($txn['credit'])) : '') . "</td>
    <td class=\"amt\">" . e($fmt($txn['balance'])) . "</td>
</tr>";
    }

    $totalCharges = '0.00';
    $totalCredits = '0.00';
    foreach ($transactions as $t) {
        $totalCharges = bcadd($totalCharges, $t['debit'], 2);
        $totalCredits = bcadd($totalCredits, $t['credit'], 2);
    }

    $html .= '
<tr class="total-row">
    <td colspan="4" style="font-weight:bold;">Period Totals</td>
    <td class="amt">' . e($fmt($totalCharges)) . '</td>
    <td class="amt">' . e($fmt($totalCredits)) . '</td>
    <td class="amt">' . e($fmt($closingBalance)) . '</td>
</tr>
</tbody>
</table>';
} else {
    $html .= '<div style="text-align:center;padding:30px;color:#999;font-size:10pt;">No transactions in this period.</div>';
}

// Aging summary
$html .= '
<div style="margin-top:20px;">
    <div style="font-size:10pt;font-weight:bold;margin-bottom:6px;">Aged Balance Summary</div>
    <table class="aging-table">
    <thead>
    <tr>
        <th style="text-align:left;">Current</th>
        <th>1–30 Days</th>
        <th>31–60 Days</th>
        <th>61–90 Days</th>
        <th>90+ Days</th>
        <th>Total</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td style="text-align:right;">' . e($fmt($aging['current'])) . '</td>
        <td>' . e($fmt($aging['days_1_30'])) . '</td>
        <td>' . e($fmt($aging['days_31_60'])) . '</td>
        <td>' . e($fmt($aging['days_61_90'])) . '</td>
        <td>' . e($fmt($aging['days_90_plus'])) . '</td>
        <td style="font-weight:bold;">' . e($fmt($agingTotal)) . '</td>
    </tr>
    </tbody>
    </table>
</div>

<div class="footer">
    <br>' . e($companyName) . ' | ' . e($companyAddress) . ', ' . e($companyCity) . ', ' . e($companyProvince) . ' ' . e($companyPostal) . '
    ' . ($companyPhone ? ' | ' . e($companyPhone) : '') . '
    <br>Generated ' . date('M j, Y g:i A') . ' | Powered by FleetForge
</div>';

$mpdf->WriteHTML($html);

// Output PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="statement_' . $customer['id'] . '_' . $dateTo . '.pdf"');
$mpdf->Output('', \Mpdf\Output\Destination::INLINE);
exit;
