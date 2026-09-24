<?php
declare(strict_types=1);

namespace FleetForge\Accounting;

use FleetForge\Storage\StorageClient;
use FleetForge\Pdf\PdfKit;
use RuntimeException;

/**
 * lib/Accounting/DunningLetterGenerator.php
 *
 * Generates dunning letter PDFs and records them in acc_dunning_letters.
 *
 * Single source of truth for dunning letter PDFs. Used by both:
 *   - api/v1/accounting/ar/dunning_letter.php  (manual generate-and-send)
 *   - cron/notification_digest.php             (auto 30/60/90 day cycle)
 *
 * Letter is per-CUSTOMER (matching acc_dunning_letters schema, which has
 * customer_id and aggregates total_overdue + invoice_count). One PDF
 * lists every overdue invoice for the customer.
 *
 * Letter types (schema enum, locked):
 *   reminder_30   — 30-59 days max overdue, friendly reminder
 *   reminder_60   — 60-89 days max overdue, second notice
 *   warning_90    — 90+ days max overdue, final warning before collections
 *   final_notice  — manager-issued only (collections referral)
 *
 * The generator does NOT send email — caller is responsible for that.
 * Returning the letter metadata gives the caller (cron or endpoint) the
 * data they need to email + audit on their own terms.
 *
 * @session S-CRON-3
 */
class DunningLetterGenerator
{
    /** @var array<string,array{subject:string,heading:string,body:string,closing:string}> */
    private const LETTER_CONTENT = [
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
    ];

    public const LETTER_TYPES = ['reminder_30', 'reminder_60', 'warning_90', 'final_notice'];

    private function __construct() {}

    /**
     * generate() — render PDF, upload to storage, insert acc_dunning_letters row.
     *
     * Returns the letter metadata. Caller is responsible for emailing the
     * customer and any additional notifications.
     *
     * @param int      $customerId  Target customer.
     * @param string   $letterType  One of self::LETTER_TYPES.
     * @param string   $sentMethod  'email' | 'mail' | 'both'. Recorded in
     *                              acc_dunning_letters.sent_method.
     * @param int|null $createdBy   user_id who triggered generation, or null
     *                              for cron/system.
     * @param string|null $sentToEmail Address the caller will email the letter
     *                              to (I22: resolved by CustomerReminders::
     *                              mayEmailCustomer — invoice/billing/main
     *                              email). Recorded in sent_to_email. null →
     *                              legacy fallback to customers.email. Ignored
     *                              (recorded NULL) when $sentMethod is 'mail',
     *                              so a letter that was not emailed never
     *                              claims an email recipient.
     *
     * @return array{
     *   id:int,
     *   letter_type:string,
     *   pdf_filename:string,
     *   pdf_path:string,
     *   pdf_storage_key:string,
     *   subject:string,
     *   total_overdue:string,
     *   invoice_count:int,
     *   customer:array<string,mixed>,
     *   overdue_invoices:array<int,array<string,mixed>>,
     *   html_body:string,
     *   sent_at:string
     * }
     *
     * @throws \InvalidArgumentException  Bad letter_type or unknown customer.
     * @throws \DomainException           Customer has no overdue invoices.
     * @throws RuntimeException           PDF generation or storage failed.
     */
    public static function generate(
        int $customerId,
        string $letterType,
        string $sentMethod = 'email',
        ?int $createdBy = null,
        ?string $sentToEmail = null
    ): array {
        if (!in_array($letterType, self::LETTER_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid letter_type '{$letterType}'. Must be one of: "
                . implode(', ', self::LETTER_TYPES)
            );
        }

        $customer = \db_row(
            "SELECT id, company_name, contact_name, email,
                    address, billing_address, city, province, postal_code
             FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$customerId]
        );
        if (!$customer) {
            throw new \InvalidArgumentException("Customer #{$customerId} not found.");
        }

        // Business DATE vs company-local today: due_date is a Pacific calendar
        // day but SQL CURDATE() is the UTC day — after 5pm Pacific (4pm in winter) an invoice
        // due today would be dunned as overdue (ff_today).
        $overdueInvoices = \db_select(
            "SELECT id, invoice_number, invoice_date, due_date, balance_due, total_amount
             FROM invoices
             WHERE customer_id = ? AND deleted_at IS NULL
               AND status IN ('sent','overdue','partially_paid')
               AND balance_due > 0
               AND due_date < ?
             ORDER BY due_date ASC",
            [$customerId, \ff_today()]
        );

        if (count($overdueInvoices) === 0) {
            throw new \DomainException(
                "Customer #{$customerId} has no overdue invoices — nothing to dun."
            );
        }

        // bcmath for the total. Path B + invoice schema use DECIMAL — never float.
        $totalOverdue = '0.00';
        foreach ($overdueInvoices as $inv) {
            $totalOverdue = bcadd($totalOverdue, (string)$inv['balance_due'], 2);
        }

        $letterContent = self::LETTER_CONTENT[$letterType];
        $html          = self::renderHtml($customer, $overdueInvoices, $letterContent, $totalOverdue);

        // Generate PDF to a tmp location, then upload via StorageClient so
        // local + S3 backends both work without endpoint-aware code.
        $pdfFilename = "dunning_{$customerId}_{$letterType}_" . date('Ymd_His') . '.pdf';
        $tmpDir      = FF_ROOT . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpPdfPath  = $tmpDir . '/' . $pdfFilename;

        try {
            // S-PDF-LETTERHEAD: the PDF is a proper letter on the shared
            // letterhead; $html (above) stays the EMAIL body, whose inline
            // styles suit mail clients rather than print.
            $pdfBytes = PdfKit::render(
                self::renderPdfBody($customer, $overdueInvoices, $letterContent, $totalOverdue),
                [
                    'title'     => $letterContent['heading'],
                    'reference' => (string) $customer['company_name'],
                    'meta'      => [
                        'Date'           => PdfKit::date(\ff_today()),
                        'Account no.'    => (string) $customer['id'],
                        'Amount overdue' => PdfKit::money($totalOverdue),
                    ],
                    'status'    => [
                        'label' => in_array($letterType, ['warning_90', 'final_notice'], true) ? 'Final notice' : 'Past due',
                        'tone'  => in_array($letterType, ['warning_90', 'final_notice'], true) ? 'bad' : 'warn',
                    ],
                ]
            );
            if (file_put_contents($tmpPdfPath, $pdfBytes) === false) {
                throw new RuntimeException('could not write ' . $tmpPdfPath);
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Dunning PDF generation failed for customer {$customerId}: " . $e->getMessage(),
                0, $e
            );
        }

        $storagePath = "dunning/{$customerId}/{$pdfFilename}";
        try {
            $storageKey = StorageClient::upload($tmpPdfPath, $storagePath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Dunning PDF upload failed for customer {$customerId}: " . $e->getMessage(),
                0, $e
            );
        } finally {
            // Always remove the tmp file even on success — StorageClient::upload
            // copies it. Leftover tmp files would accumulate on every cron run.
            @unlink($tmpPdfPath);
        }

        $sentAt = date('Y-m-d H:i:s');
        $id = \db_insert('acc_dunning_letters', [
            'customer_id'   => $customerId,
            'letter_type'   => $letterType,
            'sent_date'     => date('Y-m-d'),
            'sent_method'   => $sentMethod,
            // I22: the address actually emailed (not always customers.email);
            // NULL for a mail-only letter.
            'sent_to_email' => $sentMethod === 'mail' ? null : ($sentToEmail ?? $customer['email']),
            'total_overdue' => $totalOverdue,
            'invoice_count' => count($overdueInvoices),
            'pdf_path'      => $storageKey,
            'created_by'    => $createdBy,
        ]);

        // audit_log.user_name is NOT NULL (default 'system'): the old
        // `$createdBy ? null : 'system'` made every MANUAL letter (createdBy
        // set) fail with SQLSTATE 1048 → the endpoint's GENERATION_FAILED 500.
        // Found while verifying I22; resolve the user's name instead.
        $userName = 'system';
        if ($createdBy) {
            $userRow  = \db_row("SELECT name FROM users WHERE id = ?", [$createdBy]);
            $userName = (string) ($userRow['name'] ?? '') !== '' ? (string) $userRow['name'] : "user #{$createdBy}";
        }
        \db_insert('audit_log', [
            'user_id'      => $createdBy,
            'user_name'    => $userName,
            'action'       => 'create',
            'module'       => 'accounting',
            'entity_type'  => 'dunning_letter',
            'entity_id'    => $id,
            'entity_label' => $letterType,
            'notes'        => "Dunning letter ({$letterType}) generated for customer #{$customerId} ({$customer['company_name']}) — "
                            . "\${$totalOverdue} overdue across " . count($overdueInvoices) . " invoice(s)",
            'ip_address'   => '127.0.0.1',
        ]);

        return [
            'id'               => $id,
            'letter_type'      => $letterType,
            'pdf_filename'     => $pdfFilename,
            'pdf_path'         => $storageKey,
            'pdf_storage_key'  => $storageKey,
            'subject'          => $letterContent['subject'],
            'total_overdue'    => $totalOverdue,
            'invoice_count'    => count($overdueInvoices),
            'customer'         => $customer,
            'overdue_invoices' => $overdueInvoices,
            'html_body'        => $html,
            'sent_at'          => $sentAt,
        ];
    }

    /**
     * renderHtml() — build the letter HTML. Pulled out so the endpoint can
     * also use it as the email body.
     *
     * @param array<string,mixed> $customer
     * @param array<int,array<string,mixed>> $invoices
     * @param array{subject:string,heading:string,body:string,closing:string} $content
     */
    private static function renderHtml(
        array $customer,
        array $invoices,
        array $content,
        string $totalOverdue
    ): string {
        $companyName     = (string) \settings_get('company.name', 'FleetForge');
        $companyAddress  = (string) \settings_get('company.address', '');
        $companyCity     = (string) \settings_get('company.city', '');
        $companyProvince = (string) \settings_get('company.province', '');
        $companyPostal   = (string) \settings_get('company.postal_code', '');
        $companyPhone    = (string) \settings_get('company.phone', '');
        $companyEmail    = (string) \settings_get('company.email', '');
        $currencySymbol  = (string) \settings_get('company.currency_symbol', '$');

        $fmt     = fn(string $val) => $currencySymbol . number_format((float)$val, 2);
        $fmtDate = fn(string $d) => date('M j, Y', strtotime($d));

        // S-QBO-INVOICE-PAYNOW: a Pay-now link per overdue invoice while
        // QuickBooks Payments is on (PayLink); no column at all otherwise.
        $payUrls = [];
        foreach ($invoices as $inv) {
            $payUrls[(int) $inv['id']] = \FleetForge\QboPushers\PayLink::payableUrl((int) $inv['id']);
        }
        $hasPay = array_filter($payUrls) !== [];

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
    <h1>' . \e($companyName) . '</h1>
    <div class="company">
        ' . \e($companyAddress) . ', ' . \e($companyCity) . ', ' . \e($companyProvince) . ' ' . \e($companyPostal) . '<br>
        ' . ($companyPhone ? 'Tel: ' . \e($companyPhone) . ' | ' : '') . ($companyEmail ? \e($companyEmail) : '') . '
    </div>
</div>

<div style="font-size:9pt;color:#666;margin-bottom:16px;">Date: ' . \e($fmtDate(date('Y-m-d'))) . '</div>

<div style="margin-bottom:16px;">
    <strong>' . \e($customer['company_name']) . '</strong><br>
    ' . ($customer['contact_name'] ? \e($customer['contact_name']) . '<br>' : '') . '
    ' . ($customer['billing_address']
        ? \e($customer['billing_address']) . '<br>'
        : ($customer['address'] ? \e($customer['address']) . '<br>' : '')) . '
    ' . ($customer['city']
        ? \e($customer['city']) . ', ' . \e($customer['province'] ?? '') . ' ' . \e($customer['postal_code'] ?? '')
        : '') . '
</div>

<div class="letter-type">' . \e($content['heading']) . '</div>

<p>Dear ' . \e($customer['contact_name'] ?: $customer['company_name']) . ',</p>

<p>' . \e($content['body']) . '</p>

<table class="invoice-table">
<thead>
<tr>
    <th>Invoice #</th>
    <th>Invoice Date</th>
    <th>Due Date</th>
    <th>Days Overdue</th>
    <th class="amt">Amount Due</th>' . ($hasPay ? '
    <th>Pay online</th>' : '') . '
</tr>
</thead>
<tbody>';

        foreach ($invoices as $inv) {
            $daysOverdue = (int)((new \DateTime())->diff(new \DateTime($inv['due_date']))->days);
            $payUrl      = $payUrls[(int) $inv['id']] ?? '';
            $html .= '
<tr>
    <td>' . \e($inv['invoice_number']) . '</td>
    <td>' . \e($fmtDate($inv['invoice_date'])) . '</td>
    <td>' . \e($fmtDate($inv['due_date'])) . '</td>
    <td>' . $daysOverdue . ' days</td>
    <td class="amt">' . \e($fmt((string)$inv['balance_due'])) . '</td>' . ($hasPay ? '
    <td>' . ($payUrl !== '' ? '<a href="' . \e($payUrl) . '" style="color:#c2410c;font-weight:bold;">Pay now</a>' : '') . '</td>' : '') . '
</tr>';
        }

        $html .= '
<tr class="total-row">
    <td colspan="4">Total Overdue</td>
    <td class="amt">' . \e($fmt($totalOverdue)) . '</td>' . ($hasPay ? '
    <td></td>' : '') . '
</tr>
</tbody>
</table>' . ($hasPay ? '
<p style="font-size:9pt;color:#555;">Pay any invoice above online with its <strong>Pay now</strong> link — secure payment through QuickBooks, recorded on your account automatically.</p>' : '') . '

<p>' . \e($content['closing']) . '</p>

<p>Sincerely,<br><strong>' . \e($companyName) . '</strong><br>Accounts Receivable Department</p>

<div class="footer">
    ' . \e($companyName) . ' | ' . \e($companyAddress) . ', ' . \e($companyCity) . ', ' . \e($companyProvince) . ' ' . \e($companyPostal) . '
    <br>This is an automatically generated letter. | Powered by FleetForge
</div>';

        return $html;
    }

    /**
     * renderPdfBody() — the printed letter (S-PDF-LETTERHEAD). Same wording,
     * invoices and pay links as renderHtml() (the email body); the logo,
     * company block, date and page numbers come from PdfKit.
     *
     * @param array<string,mixed> $customer
     * @param array<int,array<string,mixed>> $invoices
     * @param array{subject:string,heading:string,body:string,closing:string} $content
     */
    private static function renderPdfBody(
        array $customer,
        array $invoices,
        array $content,
        string $totalOverdue
    ): string {
        $e = static fn ($v): string => \e((string) $v);
        $companyName = PdfKit::brand()['name'];

        $payUrls = [];
        foreach ($invoices as $inv) {
            $payUrls[(int) $inv['id']] = \FleetForge\QboPushers\PayLink::payableUrl((int) $inv['id']);
        }
        $hasPay = array_filter($payUrls) !== [];

        // Recipient block, as it would sit in a window envelope.
        $addr = trim((string) ($customer['billing_address'] ?: ($customer['address'] ?? '')));
        $to = '<strong>' . $e($customer['company_name']) . '</strong>';
        if (!empty($customer['contact_name'])) {
            $to .= '<br>Attn: ' . $e($customer['contact_name']);
        }
        if ($addr !== '') {
            $to .= '<br>' . nl2br($e($addr));
        }
        if (!$customer['billing_address'] && !empty($customer['city'])) {
            $to .= '<br>' . $e(trim($customer['city'] . ', ' . ($customer['province'] ?? '') . ' ' . ($customer['postal_code'] ?? '')));
        }

        $html = '<table class="ff-panels"><tr><td class="ff-panel" width="55%"><div class="ff-panel-label">To</div>' . $to . '</td>'
            . '<td width="45%"></td></tr></table>'
            . '<p style="margin-top:6mm;">Dear ' . $e($customer['contact_name'] ?: $customer['company_name']) . ',</p>'
            . '<p>' . $e($content['body']) . '</p>';

        $html .= '<table class="ff-grid" style="margin-top:3mm;"><thead><tr>'
            . '<th>Invoice</th><th>Invoice date</th><th>Due date</th><th class="num">Days overdue</th><th class="num">Amount due</th>'
            . ($hasPay ? '<th>Pay online</th>' : '')
            . '</tr></thead><tbody>';
        foreach ($invoices as $inv) {
            $daysOverdue = (int) ((new \DateTime(\ff_today()))->diff(new \DateTime($inv['due_date']))->days);
            $payUrl = $payUrls[(int) $inv['id']] ?? '';
            $html .= '<tr>'
                . '<td class="nw">' . $e($inv['invoice_number']) . '</td>'
                . '<td class="nw">' . $e(PdfKit::date($inv['invoice_date'])) . '</td>'
                . '<td class="nw">' . $e(PdfKit::date($inv['due_date'])) . '</td>'
                . '<td class="num">' . $daysOverdue . '</td>'
                . '<td class="num">' . $e(PdfKit::money((string) $inv['balance_due'])) . '</td>'
                . ($hasPay ? '<td>' . ($payUrl !== '' ? '<a href="' . $e($payUrl) . '"><strong>Pay now</strong></a>' : '') . '</td>' : '')
                . '</tr>';
        }
        $html .= '<tr class="ff-sum"><td colspan="4">Total overdue</td><td class="num">' . $e(PdfKit::money($totalOverdue)) . '</td>'
            . ($hasPay ? '<td></td>' : '') . '</tr></tbody></table>';

        if ($hasPay) {
            $html .= '<p class="muted" style="font-size:8.5pt;">Pay any invoice above online with its <strong>Pay now</strong> link — secure payment through QuickBooks, recorded on your account automatically.</p>';
        }

        $html .= '<p style="margin-top:5mm;">' . $e($content['closing']) . '</p>'
            . '<p style="margin-top:6mm;">Sincerely,<br><br><strong>' . $e($companyName) . '</strong><br>Accounts Receivable</p>';

        return $html;
    }
}
