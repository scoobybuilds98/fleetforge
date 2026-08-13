<?php
declare(strict_types=1);

/**
 * api/v1/invoices/generate_pdf.php
 *
 * S-INVOICE-PDF — generate (or reuse) an invoice's PDF and return a
 * time-limited signed download URL for it. One endpoint covers both the
 * "Generate PDF" and "View/Download PDF" actions — the frontend doesn't
 * need to know whether a PDF already existed; it always gets back a
 * ready-to-open URL.
 *
 * Draft invoices are always regenerated (still editable — a cached PDF
 * could be stale). Sent+ invoices reuse an existing PDF unless force=true
 * (D12 immutability — nothing about a sent invoice can change, so its PDF
 * never needs to either).
 *
 * @method  POST
 * @body    { id (required), force (optional bool) }
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { pdf_path_exists: true, download_url: string, regenerated: bool,
 *                pdf_generated_at: string, pdf_version: int }
 *          404 NOT_FOUND, 500 PDF_GENERATION_FAILED
 *
 * @depends lib/Billing/InvoicePdfGenerator.php, lib/Storage/StorageClient.php
 * @session S-INVOICE-PDF
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\InvoicePdfGenerator;
use FleetForge\Storage\StorageClient;

$body = json_body();

$invoiceId = clean_int($body['id'] ?? null);
if (!$invoiceId || $invoiceId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$force = !empty($body['force']);

$invoice = db_row(
    "SELECT id, invoice_number, status FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

try {
    $result = InvoicePdfGenerator::generate($invoiceId, $force);
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
} catch (\Throwable $e) {
    error_log("[generate_pdf] Invoice #{$invoiceId}: " . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Could not generate the invoice PDF. Please try again.', 500);
}

if ($result['regenerated']) {
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $invoiceId,
        'entity_label' => $invoice['invoice_number'],
        'notes'        => "PDF generated for invoice {$invoice['invoice_number']} (v{$result['pdf_version']}).",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
}

json_success([
    'pdf_path_exists'  => true,
    'download_url'     => StorageClient::url($result['pdf_path'], 900),
    'regenerated'       => $result['regenerated'],
    'pdf_generated_at' => $result['pdf_generated_at'],
    'pdf_version'       => $result['pdf_version'],
]);
