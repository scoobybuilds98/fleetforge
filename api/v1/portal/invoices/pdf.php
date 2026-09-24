<?php
declare(strict_types=1);

/**
 * api/v1/portal/invoices/pdf.php
 *
 * Customer portal — download one of the customer's own invoices as PDF
 * (S-PDF-LETTERHEAD).
 *
 * The portal's "Download PDF" button pointed at api/v1/invoices/download.php,
 * a file that never existed, and only showed when a PDF had already been
 * stored — which on production was never. This endpoint generates the PDF
 * on demand (InvoicePdfGenerator::pdfBytes) and streams it.
 *
 * Visibility mirrors app/portal/invoices/view.php exactly: the customer's
 * own invoices only (Trap 8 — portal_customer_id() scoping), never void, and
 * drafts only when they are customer-facing advance bills.
 *
 * @method  GET
 * @query   id (int, required), download (0|1, optional)
 * @auth    portal session (require_portal_auth)
 * @returns application/pdf body; 404 JSON when the invoice isn't theirs/visible
 *
 * @session S-PDF-LETTERHEAD
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';

use FleetForge\Billing\InvoicePdfGenerator;
use FleetForge\Pdf\PdfKit;

require_method('GET');
require_portal_auth();

$invoiceId = clean_int($_GET['id'] ?? null);
if (!$invoiceId || $invoiceId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// Trap 8: the customer_id filter is the whole security boundary here — an
// id from another customer must look exactly like a missing invoice.
$visible = db_row(
    "SELECT id FROM invoices
      WHERE id = ? AND customer_id = ? AND deleted_at IS NULL
        AND status <> 'void'
        AND (status <> 'draft' OR generation_source = 'advance')",
    [$invoiceId, portal_customer_id()]
);
if (!$visible) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

try {
    $pdf = InvoicePdfGenerator::pdfBytes($invoiceId);
} catch (\Throwable $e) {
    error_log("[portal/invoices/pdf] Invoice #{$invoiceId}: " . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Your invoice PDF could not be produced right now. Please try again shortly.', 500);
}

PdfKit::stream($pdf['bytes'], $pdf['filename'], ($_GET['download'] ?? '') === '1');
