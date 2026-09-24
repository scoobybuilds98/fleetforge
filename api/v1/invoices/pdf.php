<?php
declare(strict_types=1);

/**
 * api/v1/invoices/pdf.php
 *
 * Stream an invoice PDF straight to the browser (S-PDF-LETTERHEAD).
 *
 * WHY a GET that streams: the invoice page used to POST generate_pdf, await
 * the JSON, then window.open() the returned storage URL. A window.open()
 * after an await is no longer tied to the click, so Safari (and Chrome
 * after a few seconds) blocks it silently — "nothing happens". A plain
 * <a href target="_blank"> to this endpoint is never blocked, and because
 * the bytes come through PHP it does not depend on a presigned storage URL
 * either (prod had lost every pre-S3 file, so those URLs 404'd).
 *
 * Generates the PDF first when needed (drafts always re-render; sent+
 * invoices reuse the stored copy — D12 — unless it is from an older layout
 * or missing). See InvoicePdfGenerator::pdfBytes().
 *
 * @method  GET
 * @query   id (int, required), download (0|1, optional — 1 forces a file save)
 * @auth    Session required; require_permission('invoices','view')
 * @returns application/pdf body; 404 JSON if the invoice doesn't exist;
 *          500 JSON if it cannot be rendered
 *
 * Decisions: D12 (sent invoices frozen), D-PDF-LETTERHEAD-1
 * @session S-PDF-LETTERHEAD
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Billing\InvoicePdfGenerator;
use FleetForge\Pdf\PdfKit;

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

$invoiceId = clean_int($_GET['id'] ?? null);
if (!$invoiceId || $invoiceId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    $pdf = InvoicePdfGenerator::pdfBytes($invoiceId);
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
} catch (\Throwable $e) {
    error_log("[invoices/pdf] Invoice #{$invoiceId}: " . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Could not produce the invoice PDF. Please try again.', 500);
}

PdfKit::stream($pdf['bytes'], $pdf['filename'], ($_GET['download'] ?? '') === '1');
