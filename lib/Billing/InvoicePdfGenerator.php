<?php
declare(strict_types=1);

namespace FleetForge\Billing;

use FleetForge\Storage\StorageClient;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * lib/Billing/InvoicePdfGenerator.php
 *
 * S-INVOICE-PDF — renders an invoice to PDF and persists it directly on the
 * invoices row (`pdf_path`/`pdf_generated_at`/`pdf_version`), following the
 * exact pattern lib/Accounting/DunningLetterGenerator.php already uses for
 * dunning letters: render HTML -> mPDF -> tmp file -> StorageClient::upload()
 * -> write the returned storage key back to the source row. Deliberately
 * NOT registered in the `documents` table — that table's entity_type enum
 * has no 'invoice' value, and both existing consumers of this column
 * (EmailService::getCustomerInvoices(), api/v1/email/send.php's attachment
 * resolver) already expect a direct `invoices.pdf_path` column, not a
 * documents FK. Populating this column is what turns those two ALREADY
 * WIRED but dormant code paths on — no changes needed there.
 *
 * Regeneration policy: a sent+ invoice is financially frozen (D12) — once
 * it has a PDF, reuse it unless the caller passes force=true. A draft can
 * still be edited (line-item edits, regenerate-from-lease), so its PDF is
 * ALWAYS rebuilt on request rather than risk emailing a stale snapshot.
 *
 * @depends includes/db.php (db_row/db_select/db_execute), includes/functions.php
 *          (format_currency, format_date, e, ff_invoice_display_period_end,
 *          ff_expand_capped_invoice_lines), lib/Storage/StorageClient.php
 * @decisions D12 (immutability after send), D16 (bcmath)
 * @session S-INVOICE-PDF
 */
class InvoicePdfGenerator
{
    private function __construct() {}

    /**
     * @return array{pdf_path:string, pdf_generated_at:string, pdf_version:int, regenerated:bool}
     * @throws \InvalidArgumentException Invoice not found.
     * @throws RuntimeException          PDF render or storage upload failed.
     */
    public static function generate(int $invoiceId, bool $force = false): array
    {
        // Same join shape as app/admin/invoices/show.php's own query — the
        // lease fields feed ff_invoice_display_period_end()'s time-of-day
        // trim logic (S-LEASE-CLOSE-ACTUAL-DATE).
        $invoice = \db_row(
            "SELECT i.*,
                    l.actual_return_date, l.actual_return_time, l.start_time, l.billing_days_removed
               FROM invoices i
               LEFT JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
              WHERE i.id = ? AND i.deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$invoice) {
            throw new \InvalidArgumentException("Invoice #{$invoiceId} not found.");
        }

        if (!$force && $invoice['status'] !== 'draft' && !empty($invoice['pdf_path'])) {
            return [
                'pdf_path'         => (string) $invoice['pdf_path'],
                'pdf_generated_at' => (string) $invoice['pdf_generated_at'],
                'pdf_version'      => (int) $invoice['pdf_version'],
                'regenerated'      => false,
            ];
        }

        $lineItems = \db_select(
            "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC",
            [$invoiceId]
        );
        $lineItems = \ff_expand_capped_invoice_lines($lineItems, $invoiceId);

        // Mirror show.php's on-screen $renderLineItems filter: a draft can
        // carry stray $0.00 lines the editor hasn't cleaned up — don't put
        // them in a customer-facing PDF. Sent+ invoices never filter (D12
        // immutability — what was billed is what prints, permanently).
        $renderLineItems = $lineItems;
        if ($invoice['status'] === 'draft') {
            $renderLineItems = array_values(array_filter(
                $lineItems,
                static fn (array $li): bool => bccomp((string) $li['amount'], '0', 2) !== 0
            ));
        }

        $html = self::renderHtml($invoice, $renderLineItems);

        $pdfFilename = $invoice['invoice_number'] . '.pdf';
        $tmpDir = FF_ROOT . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpPdfPath = $tmpDir . '/invoice_' . $invoiceId . '_' . date('YmdHis') . '.pdf';

        try {
            $mpdf = new Mpdf([
                'mode'          => 'utf-8',
                'format'        => 'A4',
                'margin_top'    => 14,
                'margin_bottom' => 16,
                'margin_left'   => 15,
                'margin_right'  => 15,
                'default_font'  => 'dejavusans',
                'tempDir'       => $tmpDir,
            ]);
            $mpdf->SetTitle('Invoice ' . $invoice['invoice_number']);
            $mpdf->SetAuthor((string) (\settings_get('company.name') ?: 'FleetForge'));
            $mpdf->WriteHTML($html);
            $mpdf->Output($tmpPdfPath, Destination::FILE);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Invoice PDF generation failed for #{$invoiceId}: " . $e->getMessage(), 0, $e
            );
        }

        // StorageClient key convention: {module}/{id}/{file} — matches
        // DunningLetterGenerator's "dunning/{customerId}/{file}" and
        // credit_applications' "credit_applications/{id}/{file}".
        $storagePath = "generated/pdfs/invoices/{$invoiceId}/{$pdfFilename}";
        try {
            $storageKey = StorageClient::upload($tmpPdfPath, $storagePath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Invoice PDF upload failed for #{$invoiceId}: " . $e->getMessage(), 0, $e
            );
        } finally {
            @unlink($tmpPdfPath);
        }

        $generatedAt = date('Y-m-d H:i:s');
        // pdf_version is cosmetic bookkeeping today (no reader depends on its
        // value — confirmed no code reads it besides this class) — bump it
        // only when replacing an existing PDF, so it still means something
        // if a future feature wants "how many times was this regenerated".
        $newVersion = empty($invoice['pdf_path']) ? (int) $invoice['pdf_version'] : (int) $invoice['pdf_version'] + 1;

        \db_execute(
            "UPDATE invoices SET pdf_path = ?, pdf_generated_at = ?, pdf_version = ? WHERE id = ?",
            [$storageKey, $generatedAt, $newVersion, $invoiceId]
        );

        return [
            'pdf_path'         => $storageKey,
            'pdf_generated_at' => $generatedAt,
            'pdf_version'      => $newVersion,
            'regenerated'      => true,
        ];
    }

    /**
     * renderHtml() — standalone invoice document, independent of
     * show.php's admin-page markup (mPDF's HTML/CSS support is far more
     * limited than a real browser — no flexbox/grid, table-based layout
     * throughout). Mirrors show.php's content sections (letterhead,
     * bill-to, invoice details, line items, financial summary) using the
     * SAME shared helpers (format_currency/format_date/e) so the numbers
     * and dates read identically to what the operator already sees
     * on-screen.
     *
     * @param array<string,mixed> $invoice
     * @param array<int,array<string,mixed>> $lineItems
     */
    private static function renderHtml(array $invoice, array $lineItems): string
    {
        $companyName    = (string) \settings_get('company.name', 'FleetForge');
        $companyAddress = (string) \settings_get('company.address', '');
        $companyCity    = (string) \settings_get('company.city', '');
        $companyProv    = (string) \settings_get('company.province', '');
        $companyPostal  = (string) \settings_get('company.postal_code', '');
        $companyPhone   = (string) \settings_get('company.phone', '');
        $companyEmail   = (string) \settings_get('company.email', '');
        $companyWebsite = (string) \settings_get('company.website', '');
        $companyGst     = (string) \settings_get('company.gst_number', '');
        $companyPst     = (string) \settings_get('company.pst_number', '');

        $companyCityLine = trim(
            $companyCity
            . ($companyProv   ? ($companyCity ? ', ' : '') . $companyProv : '')
            . ($companyPostal ? ' ' . $companyPostal : '')
        );

        $statusLabel = ucfirst(str_replace('_', ' ', (string) $invoice['status']));
        $isOverdue = in_array($invoice['status'], ['sent', 'partially_paid'], true)
            && (string) $invoice['due_date'] < date('Y-m-d')
            && bccomp((string) $invoice['balance_due'], '0', 2) > 0;
        if ($isOverdue) $statusLabel = 'Overdue';

        $e = static fn ($v) => \e((string) $v);

        // ── Letterhead ──────────────────────────────────────────────
        $html = '<style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.5; }
            table { border-collapse: collapse; }
            .hdr-table { width: 100%; margin-bottom: 18px; }
            .hdr-table td { vertical-align: top; }
            .co-name { font-size: 15pt; font-weight: 700; margin: 0 0 4px; }
            .co-meta { font-size: 8.5pt; color: #555; line-height: 1.5; }
            .inv-title { font-size: 20pt; font-weight: 700; text-align: right; letter-spacing: 1px; color: #374151; }
            .inv-number { font-size: 11pt; font-weight: 600; text-align: right; margin: 2px 0 8px; }
            .inv-meta { font-size: 8.5pt; text-align: right; color: #444; line-height: 1.6; }
            .status-badge { display: inline-block; border: 1.5pt solid #374151; border-radius: 3px; padding: 2px 10px; font-size: 8.5pt; font-weight: 700; text-transform: uppercase; margin-top: 6px; }
            .status-badge.is-alert { background: #111827; color: #fff; border-color: #111827; }
            .addr-table { width: 100%; margin-bottom: 16px; }
            .addr-table td { vertical-align: top; width: 50%; padding: 10px 12px; background: #f9fafb; font-size: 9pt; }
            .addr-table td.right { padding-left: 20px; }
            .addr-label { font-size: 7.5pt; font-weight: 700; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; margin-bottom: 5px; }
            .li-table { width: 100%; margin: 10px 0 6px; }
            .li-table th { background: #f3f4f6; padding: 6px 8px; font-size: 8pt; text-transform: uppercase; text-align: left; border-bottom: 1.5pt solid #d1d5db; }
            .li-table th.num, .li-table td.num { text-align: right; }
            .li-table td { padding: 6px 8px; font-size: 8.5pt; border-bottom: 0.5pt solid #e5e7eb; vertical-align: top; }
            .li-desc-sub { font-size: 7.5pt; color: #6b7280; }
            .fs-table { width: 60%; margin-left: 40%; margin-top: 8px; }
            .fs-table td { padding: 3px 6px; font-size: 9pt; white-space: nowrap; }
            .fs-table td.label { color: #4b5563; }
            .fs-table td.val { text-align: right; }
            .fs-total td { border-top: 1.5pt solid #374151; font-weight: 700; font-size: 11pt; padding-top: 6px; }
            .fs-balance td { font-weight: 700; font-size: 10.5pt; }
            .notes-box { margin-top: 20px; padding: 10px 12px; background: #f9fafb; font-size: 8.5pt; }
            .footer { margin-top: 24px; padding-top: 8px; border-top: 0.5pt solid #d1d5db; font-size: 7.5pt; color: #9ca3af; text-align: center; }
        </style>';

        $html .= '<table class="hdr-table"><tr>
            <td width="55%">
                <div class="co-name">' . $e($companyName) . '</div>
                <div class="co-meta">';
        if ($companyAddress) $html .= nl2br($e($companyAddress)) . '<br>';
        if ($companyCityLine) $html .= $e($companyCityLine) . '<br>';
        if ($companyPhone) $html .= 'Tel: ' . $e($companyPhone);
        if ($companyPhone && $companyEmail) $html .= ' &middot; ';
        if ($companyEmail) $html .= $e($companyEmail);
        if ($companyWebsite) $html .= '<br>' . $e($companyWebsite);
        if ($companyGst || $companyPst) {
            $html .= '<br>';
            if ($companyGst) $html .= 'GST/HST: ' . $e($companyGst);
            if ($companyGst && $companyPst) $html .= ' &middot; ';
            if ($companyPst) $html .= 'PST: ' . $e($companyPst);
        }
        $html .= '</div>
            </td>
            <td width="45%">
                <div class="inv-title">INVOICE</div>
                <div class="inv-number">#' . $e($invoice['invoice_number']) . '</div>
                <div class="inv-meta">
                    Date: ' . $e(\format_date($invoice['invoice_date'])) . '<br>
                    Due: ' . $e(\format_date($invoice['due_date']));
        if (!empty($invoice['po_number'])) $html .= '<br>PO: ' . $e($invoice['po_number']);
        if ($invoice['currency'] !== 'CAD') $html .= '<br>Currency: ' . $e($invoice['currency']);
        $html .= '</div>
                <div style="text-align:right;">
                    <span class="status-badge' . ($isOverdue || in_array($invoice['status'], ['void', 'written_off'], true) ? ' is-alert' : '') . '">' . $e($statusLabel) . '</span>
                </div>
            </td>
        </tr></table>';

        // ── Bill To / Invoice Details ───────────────────────────────
        $html .= '<table class="addr-table"><tr>
            <td>
                <div class="addr-label">Bill To</div>';
        if (!empty($invoice['company_name_snapshot'])) $html .= '<strong>' . $e($invoice['company_name_snapshot']) . '</strong><br>';
        if (!empty($invoice['customer_name_snapshot']) && $invoice['customer_name_snapshot'] !== $invoice['company_name_snapshot']) {
            $html .= $e($invoice['customer_name_snapshot']) . '<br>';
        }
        if (!empty($invoice['billing_address_snapshot'])) $html .= nl2br($e($invoice['billing_address_snapshot'])) . '<br>';
        if (!empty($invoice['customer_email_snapshot'])) $html .= 'Email: ' . $e($invoice['customer_email_snapshot']);
        if (!empty($invoice['tax_exempt_snapshot'])) {
            $html .= '<br><em>Tax exempt' . (!empty($invoice['tax_exempt_number_snapshot']) ? ' (' . $e($invoice['tax_exempt_number_snapshot']) . ')' : '') . '</em>';
        } else {
            if (!empty($invoice['gst_exempt_snapshot'])) $html .= '<br><em>GST exempt' . (!empty($invoice['gst_exempt_number_snapshot']) ? ' (' . $e($invoice['gst_exempt_number_snapshot']) . ')' : '') . '</em>';
            if (!empty($invoice['pst_exempt_snapshot'])) $html .= '<br><em>PST exempt' . (!empty($invoice['pst_exempt_number_snapshot']) ? ' (' . $e($invoice['pst_exempt_number_snapshot']) . ')' : '') . '</em>';
        }
        $html .= '</td>
            <td class="right">
                <div class="addr-label">Invoice Details</div>
                Billing Period: ' . $e(\format_date($invoice['billing_period_start'])) . ' &rarr; ' . $e(\format_date(\ff_invoice_display_period_end($invoice))) . '<br>
                Billing Days: ' . (int) $invoice['billing_period_days'] . '<br>';
        if (!empty($invoice['rate_method_used']) && $invoice['rate_method_used'] !== 'none') {
            $html .= 'Rate Method: ' . $e(ucfirst($invoice['rate_method_used'])) . '<br>';
        }
        if (!empty($invoice['contract_number_snapshot'])) $html .= 'Contract: ' . $e($invoice['contract_number_snapshot']) . '<br>';
        if (!empty($invoice['unit_number_invoice_snapshot'])) $html .= 'Unit: ' . $e($invoice['unit_number_invoice_snapshot']) . '<br>';
        $html .= '</td>
        </tr></table>';

        // ── Line items ──────────────────────────────────────────────
        $itemTypeLabel = static fn (string $t): string => ucwords(str_replace('_', ' ', $t));

        $html .= '<table class="li-table"><thead><tr>
            <th width="4%">#</th>
            <th width="16%">Type</th>
            <th width="30%">Description</th>
            <th width="16%">Period</th>
            <th class="num" width="8%">Qty</th>
            <th class="num" width="12%">Unit Price</th>
            <th class="num" width="14%">Amount</th>
        </tr></thead><tbody>';

        $idx = 0;
        foreach ($lineItems as $li) {
            $idx++;
            $period = ($li['period_start'] && $li['period_end'])
                ? \format_date($li['period_start']) . ' &rarr; ' . \format_date($li['period_end'])
                : '&mdash;';
            $qty = rtrim(rtrim(number_format((float) $li['quantity'], 4), '0'), '.');
            $amount = \format_currency($li['amount']);
            if ((int) $li['is_credit'] === 1) $amount = '&minus;' . $amount;

            $html .= '<tr>
                <td>' . $idx . '</td>
                <td>' . $e($itemTypeLabel((string) $li['item_type'])) . '</td>
                <td>' . $e((string) $li['description']) . '</td>
                <td>' . $period . '</td>
                <td class="num">' . $e($qty) . ($li['unit'] ? '<br><span class="li-desc-sub">' . $e($li['unit']) . '</span>' : '') . '</td>
                <td class="num">' . $e(\format_currency($li['unit_price'])) . '</td>
                <td class="num">' . $amount . '</td>
            </tr>';
        }
        $html .= '</tbody></table>';

        // ── Financial summary ───────────────────────────────────────
        $html .= '<table class="fs-table">
            <tr><td class="label">Subtotal</td><td class="val">' . $e(\format_currency($invoice['subtotal'])) . '</td></tr>';

        if (bccomp((string) $invoice['discount_amount'], '0', 2) > 0) {
            $discountLabel = $invoice['discount_type'] === 'percentage'
                ? 'Discount (' . rtrim(rtrim((string) $invoice['discount_value'], '0'), '.') . '%)'
                : 'Discount';
            $html .= '<tr><td class="label">' . $e($discountLabel) . '</td><td class="val">&minus;' . $e(\format_currency($invoice['discount_amount'])) . '</td></tr>
                <tr><td class="label">Subtotal after discount</td><td class="val">' . $e(\format_currency($invoice['subtotal_after_discount'])) . '</td></tr>';
        }

        foreach (['gst' => 'GST', 'pst' => 'PST', 'hst' => 'HST'] as $key => $label) {
            $amt = (string) $invoice["tax_{$key}_amount"];
            $rate = (string) $invoice["tax_{$key}_rate"];
            if (bccomp($amt, '0', 2) > 0) {
                $pct = rtrim(rtrim(bcmul($rate, '100', 4), '0'), '.');
                $html .= '<tr><td class="label">' . $label . ' (' . $e($pct) . '%)</td><td class="val">' . $e(\format_currency($amt)) . '</td></tr>';
            }
        }
        if (bccomp((string) $invoice['tax_total'], '0', 2) > 0) {
            $html .= '<tr><td class="label">Tax total</td><td class="val">' . $e(\format_currency($invoice['tax_total'])) . '</td></tr>';
        }

        $html .= '<tr class="fs-total"><td class="label">Total</td><td class="val">' . $e(\format_currency($invoice['total_amount'])) . ' ' . $e($invoice['currency']) . '</td></tr>';

        if (bccomp((string) $invoice['amount_paid'], '0', 2) > 0) {
            $html .= '<tr><td class="label">Payments received</td><td class="val">&minus;' . $e(\format_currency($invoice['amount_paid'])) . '</td></tr>';
        }
        if (bccomp((string) $invoice['credits_applied'], '0', 2) > 0) {
            $html .= '<tr><td class="label">Credits applied</td><td class="val">&minus;' . $e(\format_currency($invoice['credits_applied'])) . '</td></tr>';
        }
        if (!empty($invoice['late_fee_applied']) && bccomp((string) $invoice['late_fee_amount'], '0', 2) > 0) {
            $html .= '<tr><td class="label">Late fee</td><td class="val">' . $e(\format_currency($invoice['late_fee_amount'])) . '</td></tr>';
        }

        $html .= '<tr class="fs-balance"><td class="label">Balance Due</td><td class="val">' . $e(\format_currency($invoice['balance_due'])) . ' ' . $e($invoice['currency']) . '</td></tr>
        </table>';

        if ($invoice['currency'] !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])) {
            $html .= '<div style="clear:both; font-size:7.5pt; color:#6b7280; text-align:right; margin-top:4px;">Exchange rate to CAD: ' . $e((string) $invoice['exchange_rate_to_cad']) . '</div>';
        }

        // ── Notes (customer-facing only — never internal_notes) ─────
        if (!empty($invoice['notes'])) {
            $html .= '<div class="notes-box"><strong>Notes</strong><br>' . nl2br($e($invoice['notes'])) . '</div>';
        }

        $html .= '<div class="footer">' . $e($companyName) . ($companyWebsite ? ' &middot; ' . $e($companyWebsite) : '') . ($companyEmail ? ' &middot; ' . $e($companyEmail) : '') . '</div>';

        return $html;
    }
}
