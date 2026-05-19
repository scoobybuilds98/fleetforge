<?php
declare(strict_types=1);

/**
 * api/v1/accounting/tax/gst34.php
 *
 * GST34 13-line return generator per ACCOUNTING_SPEC §23.7. Serves json/
 * csv/pdf/xml depending on ?format. CSV + XML are direct downloads (Content-
 * Disposition attachment); PDF renders inline via mPDF; JSON is the default.
 *
 * @method  GET
 * @query   period_id (required), format=json|csv|pdf|xml (default json)
 * @auth    Session required; require_permission('tax_management','view')
 * @returns 200 GST34 result (or download) | 404 NOT_FOUND | 422 VALIDATION_ERROR
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.7
 * Session: S-ACCT-GST34
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\Gst34Service;

require_method('GET');
require_auth_api();
require_permission('tax_management', 'view');

$periodId = clean_int($_GET['period_id'] ?? null);
$format   = strtolower((string) ($_GET['format'] ?? 'json'));

if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}
if (!in_array($format, ['json', 'csv', 'pdf', 'xml'], true)) {
    json_error('VALIDATION_ERROR', 'format must be json, csv, pdf, or xml.', 422);
}

try {
    $data = Gst34Service::compute($periodId);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'not found') !== false) {
        json_error('NOT_FOUND', $msg, 404);
    }
    json_error('VALIDATION_ERROR', $msg, 422);
}

if ($format === 'json') {
    json_success($data);
}

// File-download formats.
$p = $data['period'];
$baseName = "GST34_{$p['period_start']}_to_{$p['period_end']}";

if ($format === 'csv') {
    $csv = Gst34Service::generateCsv($data);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$baseName}.csv\"");
    echo $csv;
    exit;
}

if ($format === 'xml') {
    $xml = Gst34Service::generateXml($data);
    header('Content-Type: application/xml; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$baseName}.xml\"");
    echo $xml;
    exit;
}

// PDF
require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
\FleetForge\Accounting\ReportPdfRenderer::gst34($data);
exit;
