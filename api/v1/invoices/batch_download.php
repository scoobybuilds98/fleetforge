<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_download.php
 *
 * S-BATCH-INVOICING-2 — download a whole batch of invoices in one file:
 * either a ZIP of individual PDFs (one file per invoice, good for filing
 * or handing to an accountant) or a single concatenated PDF (good for
 * printing / mailing a month's run in one pass).
 *
 * Any invoice missing a PDF is generated on demand first, so the operator
 * never has to click "Generate PDF" per row before downloading.
 *
 * Streams the file directly rather than storing it — a batch download is a
 * transient artifact, and writing every one to storage would accumulate
 * junk that nothing ever cleans up. NOTE this endpoint therefore emits a
 * binary body, not the usual JSON envelope; errors before the first byte
 * still use json_error(), so the client must check the response
 * Content-Type before assuming success.
 *
 * @method  POST
 * @body    { ids: [int,...], format: 'zip'|'pdf' }   (ids max 100)
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 application/zip | application/pdf (binary stream)
 *          422 on validation, 500 PDF_GENERATION_FAILED
 *
 * @depends lib/Billing/InvoicePdfGenerator.php, lib/Storage/StorageClient.php
 * @session S-BATCH-INVOICING-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\InvoicePdfGenerator;
use FleetForge\Storage\StorageClient;

$body = json_body();

$rawIds = $body['ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}
if (count($rawIds) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 invoices per download.', 422);
}

$ids = [];
foreach ($rawIds as $raw) {
    $id = clean_int($raw);
    if ($id && $id > 0) $ids[] = $id;
}
$ids = array_values(array_unique($ids));
if (!$ids) {
    json_error('VALIDATION_ERROR', 'No valid invoice ids submitted.', 422);
}

$format = clean_string($body['format'] ?? 'zip');
if (!in_array($format, ['zip', 'pdf'], true)) {
    json_error('VALIDATION_ERROR', "format must be 'zip' or 'pdf'.", 422);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$invoices = db_select(
    "SELECT id, invoice_number, pdf_path, status
       FROM invoices
      WHERE id IN ({$placeholders}) AND deleted_at IS NULL
      ORDER BY invoice_number ASC",
    $ids
);
if (!$invoices) {
    json_error('NOT_FOUND', 'None of the requested invoices exist.', 404);
}

// ── Ensure every invoice has a PDF, then pull its bytes ──────────────
$files = [];   // [invoice_number => bytes]
$failed = [];
foreach ($invoices as $inv) {
    $invId = (int) $inv['id'];
    try {
        $pdf   = InvoicePdfGenerator::generate($invId);
        $bytes = StorageClient::read($pdf['pdf_path']);
        if ($bytes === null || $bytes === '') {
            $failed[] = $inv['invoice_number'];
            continue;
        }
        $files[(string) $inv['invoice_number']] = $bytes;
    } catch (\Throwable $e) {
        $failed[] = $inv['invoice_number'];
        error_log("[batch_download] Invoice #{$invId}: " . $e->getMessage());
    }
}

if (!$files) {
    json_error('PDF_GENERATION_FAILED', 'No invoice PDFs could be produced.'
        . ($failed ? ' Failed: ' . implode(', ', array_slice($failed, 0, 5)) : ''), 500);
}

$stamp    = date('Ymd_His');
$tmpDir   = FF_ROOT . '/storage/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'System',
    'action'       => 'export',
    'module'       => 'invoices',
    'entity_type'  => 'invoice',
    'entity_id'    => null,
    'entity_label' => $format === 'zip' ? 'batch_zip' : 'batch_pdf',
    'notes'        => 'Batch download (' . $format . ') of ' . count($files) . ' invoice PDF(s): '
                    . implode(', ', array_slice(array_keys($files), 0, 20))
                    . (count($files) > 20 ? '…' : '')
                    . ($failed ? ' | failed: ' . implode(', ', $failed) : ''),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

// Everything below writes a BINARY body — no json_success() past this point.
if (ob_get_level() > 0) { @ob_end_clean(); }

if ($format === 'zip') {
    $zipPath = $tmpDir . '/invoices_' . $stamp . '_' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        json_error('ZIP_FAILED', 'Could not create the ZIP archive.', 500);
    }
    foreach ($files as $number => $bytes) {
        // Invoice numbers are app-generated (INV-YYYY-NNNNN) but sanitise
        // anyway — this string becomes a filename inside the archive.
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $number);
        $zip->addFromString($safe . '.pdf', $bytes);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="invoices_' . $stamp . '.zip"');
    header('Content-Length: ' . (string) filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// ── format === 'pdf': concatenate every invoice into one document ────
// mPDF imports each source PDF page-by-page via its FPDI integration.
require_once FF_ROOT . '/vendor/autoload.php';
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'    => 'utf-8',
        'format'  => 'A4',
        'tempDir' => $tmpDir,
        'margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0,
    ]);
    $mpdf->SetTitle('Invoices ' . $stamp);

    $tmpParts = [];
    $first = true;
    foreach ($files as $number => $bytes) {
        $part = $tmpDir . '/part_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($part, $bytes);
        $tmpParts[] = $part;

        // FPDI 2 API (mpdf pulls in setasign/fpdi and exposes it via
        // Mpdf\FpdiTrait) — camelCase, not the FPDI 1 PascalCase names.
        $pageCount = $mpdf->setSourceFile($part);
        for ($p = 1; $p <= $pageCount; $p++) {
            if (!$first) { $mpdf->AddPage(); }
            $first = false;
            $tplId = $mpdf->importPage($p);
            $mpdf->useTemplate($tplId);
        }
    }

    $out = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    foreach ($tmpParts as $p) { @unlink($p); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="invoices_' . $stamp . '.pdf"');
    header('Content-Length: ' . (string) strlen($out));
    echo $out;
    exit;

} catch (\Throwable $e) {
    error_log('[batch_download] combined PDF failed: ' . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Could not build the combined PDF: ' . $e->getMessage(), 500);
}
