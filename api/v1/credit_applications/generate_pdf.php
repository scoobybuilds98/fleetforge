<?php
declare(strict_types=1);

/**
 * api/v1/credit_applications/generate_pdf.php
 *
 * Regenerate the PDF for a credit application whose generated_pdf_document_id
 * IS NULL (idempotent recovery for rows where the synchronous PDF gen failed
 * at submit time — D-CCA-3-G).
 *
 * Uses the stored rendered_html to re-run mPDF — no re-submission required.
 * If rendered_html is also NULL/empty, returns 422 (unrecoverable without
 * re-submission; operator must ask the applicant to resubmit or the row stays
 * as-is until the full review session — S-CCA-4 — provides a manual override).
 *
 * Idempotent: if generated_pdf_document_id is already set, returns 200 with
 * the existing document_id and does nothing.
 *
 * @method  POST
 * @body    JSON { id: int }
 * @auth    Session required; require_permission('customers', 'edit')
 * @returns 200 { pdf_document_id: int }
 *          400 MISSING_ID
 *          404 NOT_FOUND
 *          409 ALREADY_GENERATED  (pdf exists — idempotent)
 *          422 NO_RENDERED_HTML   (cannot regenerate without base HTML)
 *          500 PDF_GEN_FAILED
 *
 * Trap 7: rendered_html and file_path are NEVER returned to the client.
 *
 * @session S-CCA-3
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/includes/partials/credit_application_render.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Storage\StorageClient;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

require_method('POST');
require_auth_api();
require_permission('customers', 'edit');

$body = json_body();
$appId = clean_int($body['id'] ?? null);
if (!$appId || $appId <= 0) {
    json_error('MISSING_ID', 'A valid credit application id is required.', 400);
}

$app = db_row(
    "SELECT ca.id, ca.customer_id, ca.status, ca.rendered_html,
            ca.generated_pdf_document_id,
            ca.print_name_first, ca.print_name_last, ca.signed_date,
            ca.submitted_at,
            c.company_name AS customer_company_name
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
      WHERE ca.id = ? AND ca.deleted_at IS NULL",
    [$appId]
);

if (!$app) {
    json_error('NOT_FOUND', 'Credit application not found.', 404);
}

// Idempotent: already has a PDF
if ($app['generated_pdf_document_id'] !== null) {
    json_success(['pdf_document_id' => (int)$app['generated_pdf_document_id'], 'already_existed' => true]);
}

// Cannot regenerate without the stored rendered_html
$renderedHtml = (string)($app['rendered_html'] ?? '');
if ($renderedHtml === '') {
    json_error('NO_RENDERED_HTML',
        'This application has no stored rendered_html snapshot. The PDF cannot be regenerated; the applicant would need to resubmit.',
        422);
}

// ── Generate PDF from stored rendered_html ──────────────────────────────────
$tmpDir = FF_ROOT . '/storage/tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}

try {
    $mpdf = new Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_top'    => 12,
        'margin_bottom' => 12,
        'margin_left'   => 12,
        'margin_right'  => 12,
        'default_font'  => 'dejavusans',
        'tempDir'       => $tmpDir,
    ]);
    $submittedDateLabel = $app['signed_date'] ?: date('Y-m-d');
    $mpdf->SetTitle('Credit Application — ' . ($app['customer_company_name'] ?? '') . ' ' . $submittedDateLabel);
    $mpdf->SetAuthor((string)(settings_get('company.name') ?: 'FleetForge'));
    $mpdf->WriteHTML($renderedHtml);
    $pdfBytes = $mpdf->Output('', Destination::STRING_RETURN);
} catch (\Throwable $e) {
    error_log('[S-CCA-3] generate_pdf regeneration mPDF error for app ' . $appId . ': ' . $e->getMessage());
    json_error('PDF_GEN_FAILED', 'PDF generation failed: ' . $e->getMessage(), 500);
}

if ($pdfBytes === '') {
    json_error('PDF_GEN_FAILED', 'mPDF produced an empty PDF.', 500);
}

$tmpPdf = tempnam(sys_get_temp_dir(), 'ff_cca_regen_');
if ($tmpPdf === false) {
    json_error('PDF_GEN_FAILED', 'Could not create temporary file.', 500);
}

try {
    file_put_contents($tmpPdf, $pdfBytes);
    $pdfPath = 'credit_applications/' . $appId . '/credit_application_' . date('Ymd') . '_regen.pdf';
    StorageClient::upload($tmpPdf, $pdfPath);

    $docTitle = 'Credit Application — '
        . ($app['customer_company_name'] ?? 'Customer')
        . ' (' . $submittedDateLabel . ')';
    $docId = db_insert('documents', [
        'entity_type'   => 'customer',
        'entity_id'     => (int)$app['customer_id'],
        'document_type' => 'credit_application',
        'title'         => substr($docTitle, 0, 255),
        'file_path'     => $pdfPath,
        'file_name'     => basename($pdfPath),
        'file_size_kb'  => (int)ceil(strlen($pdfBytes) / 1024),
        'mime_type'     => 'application/pdf',
        'uploaded_by'   => current_user_id(),
    ]);

    db_execute(
        "UPDATE customer_credit_applications SET generated_pdf_document_id = ? WHERE id = ?",
        [(int)$docId, $appId]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'admin',
        'action'       => 'update',
        'module'       => 'customers',
        'entity_type'  => 'credit_application',
        'entity_id'    => $appId,
        'entity_label' => 'Credit Application #' . $appId . ' — PDF regenerated',
        'new_values'   => json_encode(['generated_pdf_document_id' => $docId]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    json_success(['pdf_document_id' => (int)$docId, 'regenerated' => true]);
} catch (\Throwable $e) {
    error_log('[S-CCA-3] generate_pdf upload/documents failed for app ' . $appId . ': ' . $e->getMessage());
    json_error('PDF_GEN_FAILED', 'PDF upload or documents row creation failed: ' . $e->getMessage(), 500);
} finally {
    @unlink($tmpPdf);
}
