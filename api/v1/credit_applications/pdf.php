<?php
declare(strict_types=1);

/**
 * api/v1/credit_applications/pdf.php
 *
 * Open a submitted credit application's PDF (S-PDF-LETTERHEAD).
 *
 * Replaces the page's direct presigned-storage link: when the stored file
 * was gone (production lost every file saved before storage moved to S3)
 * that link was an S3 404. This streams the stored PDF when present and
 * otherwise rebuilds it from the frozen rendered_html snapshot and stores it
 * again (CreditApplicationPdf::bytes).
 *
 * @method  GET
 * @query   id (int, required), download (0|1, optional)
 * @auth    Session required; require_permission('customers','view') — the
 *          same gate as the application page (credit_applications/show.php)
 * @returns application/pdf body; 404 not found; 409 not submitted yet
 *
 * @session S-PDF-LETTERHEAD
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Pdf\CreditApplicationPdf;
use FleetForge\Pdf\PdfKit;

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$appId = clean_int($_GET['id'] ?? null);
if (!$appId || $appId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    $pdf = CreditApplicationPdf::bytes($appId, current_user_id());
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', 'Credit application not found.', 404);
} catch (\DomainException $e) {
    json_error('NOT_SUBMITTED', $e->getMessage(), 409);
} catch (\Throwable $e) {
    error_log("[credit_applications/pdf] #{$appId}: " . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Could not produce the credit application PDF. Please try again.', 500);
}

PdfKit::stream($pdf['bytes'], $pdf['filename'], ($_GET['download'] ?? '') === '1');
