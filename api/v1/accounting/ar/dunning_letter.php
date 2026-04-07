<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/dunning_letter.php
 *
 * Generate and record a dunning letter for a customer.
 * Creates a PDF via mPDF and logs to acc_dunning_letters.
 * In development, "sends" by logging to mail.log.
 *
 * @method  POST
 * @body    customer_id (required), letter_type (required), sent_method?
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id, letter_type, pdf_filename }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Dunning letters)
 * Session: S031
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

$customerId = clean_int($_POST['customer_id'] ?? null);
$letterType = clean_string($_POST['letter_type'] ?? null);
$sentMethod = clean_string($_POST['sent_method'] ?? null) ?? 'email';

if (!$customerId) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);
if (!$letterType) json_error('VALIDATION_ERROR', 'letter_type is required.', 422);

$validTypes = ['reminder_30', 'reminder_60', 'warning_90', 'final_notice'];
if (!in_array($letterType, $validTypes, true)) {
    json_error('VALIDATION_ERROR', 'letter_type must be one of: ' . implode(', ', $validTypes), 422);
}

$validMethods = ['email', 'mail', 'both'];
if (!in_array($sentMethod, $validMethods, true)) {
    json_error('VALIDATION_ERROR', 'sent_method must be one of: ' . implode(', ', $validMethods), 422);
}

// Fetch customer
$customer = db_row(
    "SELECT id, company_name, contact_name, email,
            address, billing_address, city, province, postal_code
     FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) json_error('NOT_FOUND', 'Customer not found.', 404);

// Fetch overdue invoices
$overdueInvoices = db_select(
    "SELECT id, invoice_number, invoice_date, due_date, balance_due, total_amount
     FROM invoices
     WHERE customer_id = ? AND deleted_at IS NULL
       AND status IN ('sent','overdue','partially_paid')
       AND balance_due > 0
       AND due_date < CURDATE()
     ORDER BY due_date ASC",
    [$customerId]
);

if (count($overdueInvoices) === 0) {
    json_error('VALIDATION_ERROR', 'No overdue invoices found for this customer.', 422);
}

$totalOverdue = '0.00';
foreach ($overdueInvoices as $inv) {
    $totalOverdue = bcadd($totalOverdue, (string)$inv['balance_due'], 2);
}

// Company settings
$companyName    = settings_get('company.name', 'FleetForge');
$companyAddress = settings_get('company.address', '');
$companyCity    = settings_get('company.city', '');
$companyProvince = settings_get('company.province', '');
$companyPostal  = settings_get('company.postal_code', '');
$companyPhone   = settings_get('company.phone', '');
$companyEmail   = settings_get('company.email', '');
$currencySymbol = settings_get('company.currency_symbol', '$');

$fmt = fn(string $val) => $currencySymbol . number_format((float)$val, 2);
$fmtDate = fn(string $d) => date('M j, Y', strtotime($d));

// Letter content by type
$letterContent = match ($letterType) {
    'reminder_30' => [
        'subject' => 'Payment Reminder — Overdue Invoice(s)',
        'heading' => 'Friendly Reminder',
        'body'    => 'We would like to bring to your attention that the following invoice(s) are past due. '
                   . 'We kindly request that you remit payment at your earliest convenience.',
        'closing' => 'If payment has already been sent, please disregard this notice. '
                   . 'If you have any questions regarding your account, please do not hesitate to contact us.',
    ],
    'reminder_60' => [
        'subject' => 'Second Notice — Overdue Invoice(s)',
        'heading' => 'Second Notice',
        'body'    => 'Despite our previous reminder, the following invoice(s) remain unpaid. '
                   . 'We ask that you arrange payment immediately to avoid any disruption to your account.',
        'closing' => 'Please contact our accounts receivable department if you are experiencing difficulties '
                   . 'or would like to arrange a payment plan.',
    ],
    'warning_90' => [
        'subject' => 'Final Warning — Overdue Invoice(s)',
        'heading' => 'Final Warning Before Collections',
        'body'    => 'This is our final notice regarding the overdue amount(s) listed below. '
                   . 'If payment is not received within 14 days, your account may be referred to collections.',
        'closing' => 'To avoid further action, please remit payment immediately or contact us to discuss your account.',
    ],
    'final_notice' => [
        'subject' => 'Collections Notice — Immediate Payment Required',
        'heading' => 'Collections Notice',
        'body'    => 'Your account has been flagged for collections action. The following invoice(s) are significantly overdue. '
                   . 'Immediate payment is required to resolve this matter.',
        'closing' => 'Failure to respond to this notice may result in additional fees, credit reporting, '
                   . 'or referral to a third-party collections agency.',
    ],
};

// Generate PDF
// WHY: tempDir must exist before mPDF constructor runs
$tmpDir = FF_ROOT . '/storage/tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}

$mpdf = new \Mpdf\Mpdf([
    'margin_top'    => 12,
    'margin_bottom' => 15,
    'margin_left'   => 15,
    'margin_right'  => 15,
    'default_font'  => 'dejavusans',
    'tempDir'       => $tmpDir,
]);

$mpdf->SetTitle($letterContent['subject']);

$html = '
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; line-height: 1.6; }
    .header { margin-bottom: 20px; }
    .header h1 { font-size: 14pt; margin: 0; }
    .header .company { font-size: 8.5pt; color: #555; }
    .letter-type { font-size: 12pt; font-weight: bold; color: #dc2626; margin: 16px 0 8px; text-transform: uppercase; }
    .invoice-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    .invoice-table th { background: #f3f4f6; padding: 6px 10px; font-size: 8.5pt; text-align: left; border-bottom: 2px solid #ccc; }
    .invoice-table th.amt { text-align: right; }
    .invoice-table td { padding: 5px 10px; font-size: 9pt; border-bottom: 1px solid #e5e5e5; }
    .invoice-table td.amt { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .total-row td { font-weight: bold; border-top: 2px solid #333; }
    .footer { font-size: 7.5pt; color: #999; text-align: center; margin-top: 30px; border-top: 1px solid #ccc; padding-top: 8px; }
</style>

<div class="header">
    <h1>' . e($companyName) . '</h1>
    <div class="company">
        ' . e($companyAddress) . ', ' . e($companyCity) . ', ' . e($companyProvince) . ' ' . e($companyPostal) . '<br>
        ' . ($companyPhone ? 'Tel: ' . e($companyPhone) . ' | ' : '') . ($companyEmail ? e($companyEmail) : '') . '
    </div>
</div>

<div style="font-size:9pt;color:#666;margin-bottom:16px;">Date: ' . e($fmtDate(date('Y-m-d'))) . '</div>

<div style="margin-bottom:16px;">
    <strong>' . e($customer['company_name']) . '</strong><br>
    ' . ($customer['contact_name'] ? e($customer['contact_name']) . '<br>' : '') . '
    ' . ($customer['billing_address'] ? e($customer['billing_address']) . '<br>' : ($customer['address'] ? e($customer['address']) . '<br>' : '')) . '
    ' . ($customer['city'] ? e($customer['city']) . ', ' . e($customer['province'] ?? '') . ' ' . e($customer['postal_code'] ?? '') : '') . '
</div>

<div class="letter-type">' . e($letterContent['heading']) . '</div>

<p>Dear ' . e($customer['contact_name'] ?: $customer['company_name']) . ',</p>

<p>' . e($letterContent['body']) . '</p>

<table class="invoice-table">
<thead>
<tr>
    <th>Invoice #</th>
    <th>Invoice Date</th>
    <th>Due Date</th>
    <th>Days Overdue</th>
    <th class="amt">Amount Due</th>
</tr>
</thead>
<tbody>';

foreach ($overdueInvoices as $inv) {
    $daysOverdue = (int)((new \DateTime())->diff(new \DateTime($inv['due_date']))->days);
    $html .= '
<tr>
    <td>' . e($inv['invoice_number']) . '</td>
    <td>' . e($fmtDate($inv['invoice_date'])) . '</td>
    <td>' . e($fmtDate($inv['due_date'])) . '</td>
    <td>' . $daysOverdue . ' days</td>
    <td class="amt">' . e($fmt((string)$inv['balance_due'])) . '</td>
</tr>';
}

$html .= '
<tr class="total-row">
    <td colspan="4">Total Overdue</td>
    <td class="amt">' . e($fmt($totalOverdue)) . '</td>
</tr>
</tbody>
</table>

<p>' . e($letterContent['closing']) . '</p>

<p>Sincerely,<br><strong>' . e($companyName) . '</strong><br>Accounts Receivable Department</p>

<div class="footer">
    ' . e($companyName) . ' | ' . e($companyAddress) . ', ' . e($companyCity) . ', ' . e($companyProvince) . ' ' . e($companyPostal) . '
    <br>This is an automatically generated letter. | Powered by FleetForge
</div>';

$mpdf->WriteHTML($html);

// Save PDF to storage
$storageDir = FF_ROOT . '/storage/dunning';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
$pdfFilename = "dunning_{$customerId}_{$letterType}_" . date('Ymd_His') . '.pdf';
$pdfPath = $storageDir . '/' . $pdfFilename;
$mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);

// Record in acc_dunning_letters
$id = db_insert('acc_dunning_letters', [
    'customer_id'   => $customerId,
    'letter_type'   => $letterType,
    'sent_date'     => date('Y-m-d'),
    'sent_method'   => $sentMethod,
    'sent_to_email' => $customer['email'],
    'total_overdue' => $totalOverdue,
    'invoice_count' => count($overdueInvoices),
    'pdf_path'      => 'dunning/' . $pdfFilename,
    'created_by'    => current_user_id(),
]);

// Log email in development (SES keys blank → log to file)
if (in_array($sentMethod, ['email', 'both'], true) && $customer['email']) {
    $logDir = FF_ROOT . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $mailLog = "[" . date('Y-m-d H:i:s') . "] DUNNING LETTER\n"
             . "To: {$customer['email']}\n"
             . "Subject: {$letterContent['subject']}\n"
             . "Customer: {$customer['company_name']}\n"
             . "Type: {$letterType}\n"
             . "Total Overdue: {$totalOverdue}\n"
             . "PDF: {$pdfFilename}\n"
             . str_repeat('-', 60) . "\n";
    file_put_contents($logDir . '/mail.log', $mailLog, FILE_APPEND);
}

db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'action'      => 'create',
    'module'      => 'accounting',
    'entity_type' => 'dunning_letter',
    'entity_id'   => $id,
    'notes'       => "Dunning letter ({$letterType}) generated for {$customer['company_name']} — {$fmt($totalOverdue)} overdue",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success([
    'id'           => $id,
    'letter_type'  => $letterType,
    'pdf_filename' => $pdfFilename,
    'total_overdue' => $totalOverdue,
    'invoice_count' => count($overdueInvoices),
], 201);
