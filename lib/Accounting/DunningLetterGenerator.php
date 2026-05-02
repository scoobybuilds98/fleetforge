<?php
declare(strict_types=1);

namespace FleetForge\Accounting;

use FleetForge\Storage\StorageClient;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
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
        ?int $createdBy = null
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

        $overdueInvoices = \db_select(
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
            $mpdf = new Mpdf([
                'margin_top'    => 12,
                'margin_bottom' => 15,
                'margin_left'   => 15,
                'margin_right'  => 15,
                'default_font'  => 'dejavusans',
                'tempDir'       => $tmpDir,
            ]);
            $mpdf->SetTitle($letterContent['subject']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($tmpPdfPath, Destination::FILE);
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
            'sent_to_email' => $customer['email'],
            'total_overdue' => $totalOverdue,
            'invoice_count' => count($overdueInvoices),
            'pdf_path'      => $storageKey,
            'created_by'    => $createdBy,
        ]);

        \db_insert('audit_log', [
            'user_id'      => $createdBy,
            'user_name'    => $createdBy ? null : 'system',
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
    <th class="amt">Amount Due</th>
</tr>
</thead>
<tbody>';

        foreach ($invoices as $inv) {
            $daysOverdue = (int)((new \DateTime())->diff(new \DateTime($inv['due_date']))->days);
            $html .= '
<tr>
    <td>' . \e($inv['invoice_number']) . '</td>
    <td>' . \e($fmtDate($inv['invoice_date'])) . '</td>
    <td>' . \e($fmtDate($inv['due_date'])) . '</td>
    <td>' . $daysOverdue . ' days</td>
    <td class="amt">' . \e($fmt((string)$inv['balance_due'])) . '</td>
</tr>';
        }

        $html .= '
<tr class="total-row">
    <td colspan="4">Total Overdue</td>
    <td class="amt">' . \e($fmt($totalOverdue)) . '</td>
</tr>
</tbody>
</table>

<p>' . \e($content['closing']) . '</p>

<p>Sincerely,<br><strong>' . \e($companyName) . '</strong><br>Accounts Receivable Department</p>

<div class="footer">
    ' . \e($companyName) . ' | ' . \e($companyAddress) . ', ' . \e($companyCity) . ', ' . \e($companyProvince) . ' ' . \e($companyPostal) . '
    <br>This is an automatically generated letter. | Powered by FleetForge
</div>';

        return $html;
    }
}
