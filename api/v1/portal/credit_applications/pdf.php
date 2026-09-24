<?php
declare(strict_types=1);

/**
 * api/v1/portal/credit_applications/pdf.php
 *
 * Customer portal — download the customer's own submitted credit
 * application as PDF (S-PDF-LETTERHEAD). Same service as the staff endpoint
 * (stored PDF, or rebuilt from the frozen snapshot); the difference is the
 * Trap 8 scoping to portal_customer_id() and submitted/reviewed only —
 * matching app/portal/credit-applications/view.php.
 *
 * @method  GET
 * @query   id (int, required), download (0|1, optional)
 * @auth    portal session (require_portal_auth)
 * @returns application/pdf body; 404 JSON when not theirs / not submitted
 *
 * @session S-PDF-LETTERHEAD
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';

use FleetForge\Pdf\CreditApplicationPdf;
use FleetForge\Pdf\PdfKit;

require_method('GET');
require_portal_auth();

$appId = clean_int($_GET['id'] ?? null);
if (!$appId || $appId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// Trap 8: another customer's application must look exactly like a missing one.
$visible = db_row(
    "SELECT id FROM customer_credit_applications
      WHERE id = ? AND customer_id = ? AND deleted_at IS NULL
        AND status IN ('submitted', 'reviewed')",
    [$appId, portal_customer_id()]
);
if (!$visible) {
    json_error('NOT_FOUND', 'Credit application not found.', 404);
}

try {
    $pdf = CreditApplicationPdf::bytes($appId);
} catch (\Throwable $e) {
    error_log("[portal/credit_applications/pdf] #{$appId}: " . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Your credit application PDF could not be produced right now. Please try again shortly.', 500);
}

PdfKit::stream($pdf['bytes'], $pdf['filename'], ($_GET['download'] ?? '') === '1');
