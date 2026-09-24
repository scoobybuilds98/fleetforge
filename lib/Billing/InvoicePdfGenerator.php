<?php
declare(strict_types=1);

namespace FleetForge\Billing;

use FleetForge\Pdf\PdfKit;
use FleetForge\Storage\StorageClient;
use RuntimeException;

/**
 * lib/Billing/InvoicePdfGenerator.php
 *
 * S-INVOICE-PDF — renders an invoice to PDF and persists it directly on the
 * invoices row (`pdf_path`/`pdf_generated_at`/`pdf_version`), following the
 * exact pattern lib/Accounting/DunningLetterGenerator.php already uses for
 * dunning letters: render -> PDF bytes -> tmp file -> StorageClient::upload()
 * -> write the returned storage key back to the source row. Deliberately
 * NOT registered in the `documents` table — that table's entity_type enum
 * has no 'invoice' value, and both existing consumers of this column
 * (EmailService::getCustomerInvoices(), api/v1/email/send.php's attachment
 * resolver) already expect a direct `invoices.pdf_path` column, not a
 * documents FK.
 *
 * Regeneration policy: a sent+ invoice is financially frozen (D12) — once
 * it has a PDF, reuse it unless the caller passes force=true. A draft can
 * still be edited (line-item edits, regenerate-from-lease), so its PDF is
 * ALWAYS rebuilt on request rather than risk emailing a stale snapshot.
 *
 * S-PDF-LETTERHEAD: the layout now comes from FleetForge\Pdf\PdfKit (logo
 * band, company block, page numbers, US Letter). Stored PDFs live under
 * generated/pdfs/invoices/{id}/v{PdfKit::LAYOUT}/ — a stored copy from an
 * older layout, or one whose file has gone missing from storage (prod lost
 * every pre-S3 file), is rebuilt instead of reused. Rebuilding a sent
 * invoice does not break D12: every figure prints from the invoice row's
 * frozen snapshot columns and its line items, which never change after send.
 * pdfBytes() is the one entry point for anything that needs the bytes
 * (the streaming endpoints, batch download).
 *
 * @depends includes/db.php (db_row/db_select/db_execute), includes/functions.php
 *          (format_date, e, ff_invoice_display_period_end,
 *          ff_expand_capped_invoice_lines), lib/Storage/StorageClient.php,
 *          lib/Pdf/PdfKit.php
 * @decisions D12 (immutability after send), D16 (bcmath), D-PDF-LETTERHEAD-1
 * @session S-INVOICE-PDF, S-PDF-LETTERHEAD
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
        $invoice = self::loadInvoice($invoiceId);

        // Reuse a frozen invoice's PDF only when it is in the current layout
        // AND still present in storage — a missing file must never turn into
        // a dead "View PDF" link or a failed email attachment.
        $stored = (string) ($invoice['pdf_path'] ?? '');
        if (!$force && $invoice['status'] !== 'draft' && $stored !== ''
            && str_contains($stored, '/v' . PdfKit::LAYOUT . '/')
            && self::storedExists($stored)) {
            return [
                'pdf_path'         => $stored,
                'pdf_generated_at' => (string) $invoice['pdf_generated_at'],
                'pdf_version'      => (int) $invoice['pdf_version'],
                'regenerated'      => false,
            ];
        }

        $bytes = self::render($invoice);

        $tmpDir = FF_ROOT . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpPdfPath = $tmpDir . '/invoice_' . $invoiceId . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (file_put_contents($tmpPdfPath, $bytes) === false) {
            throw new RuntimeException("Invoice PDF for #{$invoiceId} could not be written to {$tmpDir}.");
        }

        // StorageClient key convention: {module}/{id}/{file} — matches
        // DunningLetterGenerator's "dunning/{customerId}/{file}". The v{N}
        // folder is the layout generation (see class docblock); the file
        // name stays INV-….pdf because email attachments are named from it.
        $storagePath = "generated/pdfs/invoices/{$invoiceId}/v" . PdfKit::LAYOUT . '/' . PdfKit::filename((string) $invoice['invoice_number']);
        try {
            $storageKey = StorageClient::upload($tmpPdfPath, $storagePath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Invoice PDF upload failed for #{$invoiceId}: " . $e->getMessage(), 0, $e
            );
        } finally {
            @unlink($tmpPdfPath);
        }

        // S-UTC-STAMPS: pdf_generated_at is rendered via format_datetime (UTC column).
        $generatedAt = \ff_now_utc();
        // pdf_version is cosmetic bookkeeping (nothing reads its value) —
        // bump it only when replacing an existing PDF.
        $newVersion = $stored === '' ? (int) $invoice['pdf_version'] : (int) $invoice['pdf_version'] + 1;

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
     * The invoice's PDF bytes, generating/storing them first when needed.
     *
     * Falls back to an in-memory render if storage refuses the upload or
     * the read-back — a storage outage must not stop someone viewing or
     * printing an invoice.
     *
     * @return array{bytes:string, filename:string}
     * @throws \InvalidArgumentException Invoice not found.
     * @throws RuntimeException          The PDF could not be rendered at all.
     */
    public static function pdfBytes(int $invoiceId): array
    {
        $invoice  = self::loadInvoice($invoiceId);
        $filename = PdfKit::filename((string) $invoice['invoice_number']);

        try {
            $r     = self::generate($invoiceId);
            $bytes = StorageClient::read($r['pdf_path']);
            if (($bytes === null || $bytes === '') && !$r['regenerated']) {
                // Stored copy vanished between the exists() check and the read.
                $r     = self::generate($invoiceId, true);
                $bytes = StorageClient::read($r['pdf_path']);
            }
            if ($bytes !== null && $bytes !== '') {
                return ['bytes' => $bytes, 'filename' => $filename];
            }
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log("[InvoicePdfGenerator] storage path failed for #{$invoiceId}, rendering in memory: " . $e->getMessage());
        }

        return ['bytes' => self::render($invoice), 'filename' => $filename];
    }

    /** @return array<string,mixed> */
    private static function loadInvoice(int $invoiceId): array
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
        return $invoice;
    }

    private static function storedExists(string $key): bool
    {
        try {
            return StorageClient::exists($key);
        } catch (\Throwable $e) {
            error_log('[InvoicePdfGenerator] exists() failed for ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Render the invoice to PDF bytes (no storage side effects).
     *
     * @param array<string,mixed> $invoice row from loadInvoice()
     */
    private static function render(array $invoice): string
    {
        $invoiceId = (int) $invoice['id'];
        $lineItems = \db_select(
            "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC",
            [$invoiceId]
        );
        $lineItems = \ff_expand_capped_invoice_lines($lineItems, $invoiceId);

        // Mirror show.php's on-screen $renderLineItems filter: a draft can
        // carry stray $0.00 lines the editor hasn't cleaned up — don't put
        // them in a customer-facing PDF. Sent+ invoices never filter (D12
        // immutability — what was billed is what prints, permanently).
        if ($invoice['status'] === 'draft') {
            $lineItems = array_values(array_filter(
                $lineItems,
                static fn (array $li): bool => bccomp((string) $li['amount'], '0', 2) !== 0
            ));
        }

        $status = self::statusBadge($invoice);
        $meta = [
            'Invoice date' => PdfKit::date($invoice['invoice_date']),
            'Due date'     => PdfKit::date($invoice['due_date']),
        ];
        if (!empty($invoice['po_number'])) {
            $meta['PO number'] = (string) $invoice['po_number'];
        }
        if (($invoice['currency'] ?? 'CAD') !== 'CAD') {
            $meta['Currency'] = (string) $invoice['currency'];
        }

        try {
            return PdfKit::render(self::renderHtml($invoice, $lineItems), [
                'title'       => 'Invoice',
                'reference'   => (string) $invoice['invoice_number'],
                'meta'        => $meta,
                'status'      => $status,
                'footer_note' => PdfKit::brand()['footer_text'],
                // A draft or void copy must never pass for a live bill. An
                // advance bill is a draft the customer is MEANT to see (the
                // portal shows it), so it prints clean.
                'watermark'   => match (true) {
                    $invoice['status'] === 'draft' && ($invoice['generation_source'] ?? '') !== 'advance' => 'DRAFT',
                    $invoice['status'] === 'void' => 'VOID',
                    default => '',
                },
                'css'         => self::css(),
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Invoice PDF generation failed for #{$invoiceId}: " . $e->getMessage(), 0, $e
            );
        }
    }

    /**
     * Label + tone for the status pill. Overdue is derived (sent/partial and
     * past due with a balance) exactly as the invoice page does.
     *
     * @return array{label:string, tone:string}
     */
    private static function statusBadge(array $invoice): array
    {
        $status = (string) $invoice['status'];
        $isOverdue = $status === 'overdue'
            || (in_array($status, ['sent', 'partially_paid'], true)
                && (string) $invoice['due_date'] < \ff_today()
                && bccomp((string) $invoice['balance_due'], '0', 2) > 0);
        if ($isOverdue) {
            return ['label' => 'Overdue', 'tone' => 'bad'];
        }
        return match ($status) {
            'paid'           => ['label' => 'Paid', 'tone' => 'good'],
            'partially_paid' => ['label' => 'Partially paid', 'tone' => 'info'],
            'sent'           => ['label' => 'Sent', 'tone' => 'info'],
            'void'           => ['label' => 'Void', 'tone' => 'bad'],
            'written_off'    => ['label' => 'Written off', 'tone' => 'warn'],
            default          => ['label' => ucfirst(str_replace('_', ' ', $status)), 'tone' => 'neutral'],
        };
    }

    /** Invoice-only CSS on top of the kit (the pay-now box). */
    private static function css(): string
    {
        return '
            .paynow { width: 100%; margin-top: 5mm; border: 0.6pt solid #e2e8f0; background-color: #f8fafc; }
            .paynow td { vertical-align: middle; padding: 3.5mm 4mm; }
            .paynow-title { font-size: 11pt; font-weight: bold; color: #111827; margin-bottom: 1mm; }
            .paynow-sub { font-size: 8pt; color: #4b5563; line-height: 1.45; margin-bottom: 2.5mm; }
            .paynow-btn td { padding: 2mm 5mm; font-size: 10pt; font-weight: bold; }
            .paynow-btn a { color: #ffffff; text-decoration: none; }
            .paynow-url { font-size: 7pt; color: #6b7280; margin-top: 1.5mm; }
        ';
    }

    /**
     * renderHtml() — the invoice BODY (the letterhead, company block, dates
     * and status come from PdfKit). Mirrors show.php's content sections
     * (bill-to, rental details, line items, financial summary) so the
     * numbers and dates read identically to what the operator sees.
     *
     * @param array<string,mixed> $invoice
     * @param array<int,array<string,mixed>> $lineItems
     */
    private static function renderHtml(array $invoice, array $lineItems): string
    {
        $e = static fn ($v): string => \e((string) $v);
        $cur = (string) ($invoice['currency'] ?? 'CAD');

        // ── Bill to / Rental details ────────────────────────────────
        $bill = '';
        if (!empty($invoice['company_name_snapshot'])) {
            $bill .= '<strong>' . $e($invoice['company_name_snapshot']) . '</strong><br>';
        }
        if (!empty($invoice['customer_name_snapshot']) && $invoice['customer_name_snapshot'] !== $invoice['company_name_snapshot']) {
            $bill .= 'Attn: ' . $e($invoice['customer_name_snapshot']) . '<br>';
        }
        if (!empty($invoice['billing_address_snapshot'])) {
            $bill .= nl2br($e($invoice['billing_address_snapshot'])) . '<br>';
        }
        if (!empty($invoice['customer_email_snapshot'])) {
            $bill .= '<span class="muted">' . $e($invoice['customer_email_snapshot']) . '</span>';
        }
        if (!empty($invoice['tax_exempt_snapshot'])) {
            $bill .= '<br><em>Tax exempt' . (!empty($invoice['tax_exempt_number_snapshot']) ? ' (' . $e($invoice['tax_exempt_number_snapshot']) . ')' : '') . '</em>';
        } else {
            if (!empty($invoice['gst_exempt_snapshot'])) {
                $bill .= '<br><em>GST exempt' . (!empty($invoice['gst_exempt_number_snapshot']) ? ' (' . $e($invoice['gst_exempt_number_snapshot']) . ')' : '') . '</em>';
            }
            if (!empty($invoice['pst_exempt_snapshot'])) {
                $bill .= '<br><em>PST exempt' . (!empty($invoice['pst_exempt_number_snapshot']) ? ' (' . $e($invoice['pst_exempt_number_snapshot']) . ')' : '') . '</em>';
            }
        }

        $details = [];
        if (!empty($invoice['billing_period_start'])) {
            $details['Billing period'] = PdfKit::period($invoice['billing_period_start'], \ff_invoice_display_period_end($invoice));
        }
        if ((int) $invoice['billing_period_days'] > 0) {
            $details['Billing days'] = (string) (int) $invoice['billing_period_days'];
        }
        if (!empty($invoice['contract_number_snapshot'])) {
            $details['Contract'] = (string) $invoice['contract_number_snapshot'];
        }
        if (!empty($invoice['unit_number_invoice_snapshot'])) {
            $details['Unit'] = (string) $invoice['unit_number_invoice_snapshot'];
        }
        if (!empty($invoice['rate_method_used']) && $invoice['rate_method_used'] !== 'none') {
            $details['Rate basis'] = ucfirst(str_replace('_', ' ', (string) $invoice['rate_method_used']));
        }
        $detailRows = '';
        foreach ($details as $k => $v) {
            $detailRows .= '<tr><td class="k">' . $e($k) . '</td><td>' . $e($v) . '</td></tr>';
        }

        $html = '<table class="ff-panels"><tr>'
            . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Bill to</div>' . ($bill !== '' ? $bill : '—') . '</td>'
            . '<td class="ff-gap"></td>'
            . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Rental details</div>'
            . ($detailRows !== '' ? '<table class="ff-kv">' . $detailRows . '</table>' : '—') . '</td>'
            . '</tr></table>';

        // ── Line items ──────────────────────────────────────────────
        $html .= '<div class="ff-h">Charges</div><table class="ff-grid"><thead><tr>'
            . '<th width="4%">#</th><th width="52%">Description</th>'
            . '<th class="num" width="13%">Qty</th><th class="num" width="14%">Rate</th><th class="num" width="17%">Amount</th>'
            . '</tr></thead><tbody>';

        $idx = 0;
        foreach ($lineItems as $li) {
            $idx++;
            $credit = (int) $li['is_credit'] === 1;
            $amount = PdfKit::money($li['amount']);
            if ($credit && !str_starts_with($amount, '-')) {
                $amount = '-' . $amount;
            }
            $period = ($li['period_start'] && $li['period_end'])
                ? '<div class="ff-sub">' . $e(PdfKit::period($li['period_start'], $li['period_end'])) . '</div>'
                : '';
            $qty = self::trimNumber((string) $li['quantity']);
            $unit = trim((string) ($li['unit'] ?? ''));

            $html .= '<tr>'
                . '<td class="muted">' . $idx . '</td>'
                . '<td><div class="ff-kicker">' . $e(self::itemLabel((string) $li['item_type'])) . '</div>'
                . $e((string) $li['description']) . $period . '</td>'
                . '<td class="num">' . $e($qty) . ($unit !== '' ? ' <span class="ff-sub">' . $e($unit) . '</span>' : '') . '</td>'
                . '<td class="num">' . $e(self::rate((string) $li['unit_price'])) . '</td>'
                . '<td class="num">' . $e($amount) . '</td>'
                . '</tr>';
        }
        if ($idx === 0) {
            $html .= '<tr><td colspan="5" class="muted" style="text-align:center;padding:5mm;">No charges on this invoice.</td></tr>';
        }
        $html .= '</tbody></table>';

        // ── Totals ──────────────────────────────────────────────────
        $rows = '<tr><td class="l">Subtotal</td><td class="v">' . $e(PdfKit::money($invoice['subtotal'])) . '</td></tr>';
        if (bccomp((string) $invoice['discount_amount'], '0', 2) > 0) {
            $discountLabel = $invoice['discount_type'] === 'percentage'
                ? 'Discount (' . self::trimNumber((string) $invoice['discount_value']) . '%)'
                : 'Discount';
            $rows .= '<tr><td class="l">' . $e($discountLabel) . '</td><td class="v">-' . $e(PdfKit::money($invoice['discount_amount'])) . '</td></tr>'
                . '<tr><td class="l">Subtotal after discount</td><td class="v">' . $e(PdfKit::money($invoice['subtotal_after_discount'])) . '</td></tr>';
        }
        foreach (['gst' => 'GST', 'pst' => 'PST', 'hst' => 'HST'] as $key => $label) {
            $amt = (string) $invoice["tax_{$key}_amount"];
            if (bccomp($amt, '0', 2) > 0) {
                $pct = self::trimNumber(bcmul((string) $invoice["tax_{$key}_rate"], '100', 4));
                $rows .= '<tr><td class="l">' . $label . ' (' . $e($pct) . '%)</td><td class="v">' . $e(PdfKit::money($amt)) . '</td></tr>';
            }
        }
        $rows .= '<tr class="ff-total"><td class="l">Total</td><td class="v">' . $e(PdfKit::money($invoice['total_amount'], $cur)) . '</td></tr>';
        if (bccomp((string) $invoice['amount_paid'], '0', 2) > 0) {
            $rows .= '<tr><td class="l">Payments received</td><td class="v">-' . $e(PdfKit::money($invoice['amount_paid'])) . '</td></tr>';
        }
        if (bccomp((string) $invoice['credits_applied'], '0', 2) > 0) {
            $rows .= '<tr><td class="l">Credits applied</td><td class="v">-' . $e(PdfKit::money($invoice['credits_applied'])) . '</td></tr>';
        }
        if (!empty($invoice['late_fee_applied']) && bccomp((string) $invoice['late_fee_amount'], '0', 2) > 0) {
            $rows .= '<tr><td class="l">Late fee</td><td class="v">' . $e(PdfKit::money($invoice['late_fee_amount'])) . '</td></tr>';
        }
        $rows .= '<tr class="ff-due"><td class="l" style="color:#ffffff;">Balance due</td><td class="v">' . $e(PdfKit::money($invoice['balance_due'], $cur)) . '</td></tr>';

        $html .= '<table width="100%" style="margin-top:4mm;"><tr><td width="56%"></td><td width="44%">'
            . '<table class="ff-totals" width="100%">' . $rows . '</table>'
            . ($cur !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])
                ? '<div class="ff-sub" style="text-align:right;margin-top:1mm;">Exchange rate to CAD: ' . $e((string) $invoice['exchange_rate_to_cad']) . '</div>'
                : '')
            . '</td></tr></table>';

        // ── Notes (customer-facing only — never internal_notes) ─────
        if (!empty($invoice['notes'])) {
            $html .= '<div class="ff-note"><strong>Notes</strong><br>' . nl2br($e($invoice['notes'])) . '</div>';
        }

        // ── Pay online (S-QBO-INVOICE-PAYNOW) ───────────────────────
        // While QuickBooks Payments is on, an unpaid invoice's PDF carries a
        // "Pay now" button (a live link in the PDF) and a QR code for a
        // printed copy.
        $html .= self::payNowBlock($invoice);

        // ── Payment instructions (bug #23) ──────────────────────────
        // Invoice-specific text first, company-wide as the fallback; omitted
        // entirely when both are blank.
        $paymentInstructions = trim((string) (\settings_get('invoice.payment_instructions', '') ?: \settings_get('company.payment_instructions', '')));
        if ($paymentInstructions !== '') {
            $html .= '<div class="ff-note"><strong>Payment instructions</strong><br>' . nl2br($e($paymentInstructions)) . '</div>';
        }

        return $html;
    }

    /** "Base rental", "GPS", "Mileage (estimate)" … for the line kicker. */
    private static function itemLabel(string $type): string
    {
        $map = [
            'base_rental'        => 'Base rental',
            'gps'                => 'GPS tracking',
            'mileage'            => 'Mileage',
            'mileage_estimate'   => 'Mileage (estimate)',
            'mileage_usage'      => 'Mileage (actual)',
            'mileage_adjustment' => 'Mileage true-up',
            'mileage_credit'     => 'Mileage credit',
            'hourly_usage'       => 'Engine hours',
            'hours_estimate'     => 'Engine hours (estimate)',
        ];
        return $map[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /** "700", "14", "1.5" — no trailing zeros. */
    private static function trimNumber(string $n): string
    {
        if (!str_contains($n, '.')) {
            return $n;
        }
        return rtrim(rtrim($n, '0'), '.');
    }

    /**
     * Unit price with the precision it was set at: "$625.00", "$0.04",
     * but "$0.0425/km" keeps its 4 decimals instead of rounding to $0.04.
     */
    private static function rate(string $price): string
    {
        if (!preg_match('/^(-?\d+)\.(\d+)$/', $price, $m) || strlen(rtrim($m[2], '0')) <= 2) {
            return PdfKit::money($price);
        }
        $int = ltrim($m[1], '-');
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        return (str_starts_with($m[1], '-') ? '-' : '') . '$' . $int . '.' . rtrim($m[2], '0');
    }

    /**
     * The PDF's "Pay this invoice online" box (button + QR code), or '' when
     * QuickBooks Payments is off or nothing is owing. Drafts get it too: the
     * PDF emailed on send is usually rendered while the invoice is still a
     * draft, and the link is stable.
     */
    public static function payNowBlock(array $invoice): string
    {
        if (in_array((string) $invoice['status'], ['void', 'paid', 'written_off'], true)
            || bccomp((string) $invoice['balance_due'], '0', 2) <= 0) {
            return '';
        }
        $url = \FleetForge\QboPushers\PayLink::url((int) $invoice['id']);
        if ($url === '') {
            return '';
        }
        $e     = static fn ($v) => \e((string) $v);
        $color = PdfKit::brand()['accent'];

        $qr = '';
        try {
            $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(240, 1),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            );
            $svg = (new \BaconQrCode\Writer($renderer))->writeString($url);
            $qr  = '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" width="92" height="92" alt="">'
                 . '<div class="paynow-url" style="text-align:center;">Scan to pay</div>';
        } catch (\Throwable $ex) {
            // The button still works without the code; say why in the log.
            error_log('[InvoicePdfGenerator] QR code for invoice ' . $invoice['id'] . ' failed: ' . $ex->getMessage());
        }

        return '<table class="paynow"><tr>
            <td>
                <div class="paynow-title">Pay this invoice online</div>
                <div class="paynow-sub">Secure payment through QuickBooks &mdash; card or bank transfer. Your payment is recorded on your account automatically.</div>
                <table class="paynow-btn"><tr><td style="background-color:' . $e($color) . ';"><a href="' . $e($url) . '" style="color:#ffffff;text-decoration:none;font-weight:bold;">Pay now &mdash; '
                    . $e(PdfKit::money($invoice['balance_due'])) . ' ' . $e($invoice['currency']) . '</a></td></tr></table>
                <div class="paynow-url">' . $e($url) . '</div>
            </td>' . ($qr !== '' ? '
            <td width="110" style="text-align:center;">' . $qr . '</td>' : '') . '
        </tr></table>';
    }
}
