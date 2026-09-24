<?php
declare(strict_types=1);

/**
 * api/v1/portal/invoices/zip.php
 *
 * Customer portal — download several invoices at once as a ZIP of PDFs
 * (S-PORTAL-REDESIGN). A plain GET link (never fetch-then-popup — browsers
 * block pop-ups opened after an await; see S-PDF-LETTERHEAD).
 *
 * Trap 8: only the signed-in customer's visible invoices are included; an id
 * from another customer is silently skipped, exactly like a missing one.
 *
 * @method  GET
 * @query   ids (comma-separated, max 50)
 * @auth    portal session
 * @returns application/zip (binary) — JSON error before the first byte
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/ui.php';

use FleetForge\Billing\InvoicePdfGenerator;

require_method('GET');
require_portal_auth_api();

$ids = pt_parse_ids($_GET['ids'] ?? '', 50);
if (!$ids) {
    json_error('MISSING_REQUIRED', 'Choose at least one invoice.', 422);
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$invoices = db_select(
    "SELECT i.id, i.invoice_number FROM invoices i
      WHERE i.customer_id = ? AND " . pt_invoice_visible_sql('i') . " AND i.id IN ({$ph})
      ORDER BY i.invoice_number ASC",
    array_merge([portal_customer_id()], $ids)
);
if (!$invoices) {
    json_error('NOT_FOUND', 'Invoices not found.', 404);
}

$files = [];
foreach ($invoices as $inv) {
    try {
        $pdf = InvoicePdfGenerator::pdfBytes((int) $inv['id']);
        $files[(string) $inv['invoice_number']] = $pdf['bytes'];
    } catch (\Throwable $e) {
        error_log('[portal/invoices/zip] invoice ' . $inv['id'] . ': ' . $e->getMessage());
    }
}
if (!$files) {
    json_error('PDF_GENERATION_FAILED', 'Your invoice PDFs could not be produced right now. Please try again shortly.', 500);
}

$tmpDir = FF_ROOT . '/storage/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
$zipPath = $tmpDir . '/portal_invoices_' . bin2hex(random_bytes(6)) . '.zip';

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    json_error('ZIP_FAILED', 'Could not build the download. Please try again.', 500);
}
foreach ($files as $number => $bytes) {
    $zip->addFromString(preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $number) . '.pdf', $bytes);
}
$zip->close();

if (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="invoices_' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . (string) filesize($zipPath));
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);
exit;
