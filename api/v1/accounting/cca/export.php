<?php
declare(strict_types=1);

/**
 * api/v1/accounting/cca/export.php
 *
 * Export the CCA Schedule 8 continuity for a fiscal year as CSV (default)
 * or PDF in T2 Schedule 8 column order, ready for T2 preparer ingestion
 * (TaxCycle, ProFile, TaxPrep) per spec §23.3.
 *
 * CSV column order: Class | Opening UCC | Additions | Disposals |
 *   UCC Before CCA | Rate% | Half-Year | CCA Claimed |
 *   Closing UCC | Recapture | Terminal Loss
 *
 * @method  GET
 * @query   fiscal_year (required), format? (csv|pdf, default csv)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.3
 * Session: S-ACCT-CCA-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\CcaService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
$format     = strtolower((string) ($_GET['format'] ?? 'csv'));

if (!$fiscalYear) {
    json_error('MISSING_REQUIRED', 'fiscal_year is required.', 422);
}
if (!in_array($format, ['csv', 'pdf'], true)) {
    json_error('VALIDATION_ERROR', 'format must be csv or pdf.', 422);
}

$schedule = CcaService::getSchedule($fiscalYear);
$rows     = $schedule['rows'] ?? [];

if ($format === 'csv') {
    $filename = "CCA_Schedule8_{$fiscalYear}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");

    $out = fopen('php://output', 'w');
    fputcsv($out, ["FleetForge CCA Schedule 8 — FY{$fiscalYear}"]);
    fputcsv($out, [
        'Class', 'Opening UCC', 'Additions', 'Disposals',
        'UCC Before CCA', 'Rate%', 'Half-Year Adjustment',
        'CCA Claimed', 'Closing UCC', 'Recapture', 'Terminal Loss',
    ]);

    $totOpening = '0.00';
    $totAdd     = '0.00';
    $totDisp    = '0.00';
    $totUcc     = '0.00';
    $totHalf    = '0.00';
    $totCca     = '0.00';
    $totClose   = '0.00';
    $totRecap   = '0.00';
    $totTerm    = '0.00';

    foreach ($rows as $r) {
        $ratePct = number_format(((float) $r['class_rate']) * 100, 2);
        fputcsv($out, [
            $r['class_number'],
            $r['opening_ucc'],
            $r['cost_of_additions'],
            $r['proceeds_of_disposition'],
            $r['ucc_after_additions_dispositions'],
            $ratePct . '%',
            $r['half_year_adjustment'],
            $r['cca_claimed'],
            $r['closing_ucc'],
            $r['recapture'],
            $r['terminal_loss'],
        ]);
        $totOpening = bcadd($totOpening, $r['opening_ucc'], 2);
        $totAdd     = bcadd($totAdd,     $r['cost_of_additions'], 2);
        $totDisp    = bcadd($totDisp,    $r['proceeds_of_disposition'], 2);
        $totUcc     = bcadd($totUcc,     $r['ucc_after_additions_dispositions'], 2);
        $totHalf    = bcadd($totHalf,    $r['half_year_adjustment'], 2);
        $totCca     = bcadd($totCca,     $r['cca_claimed'], 2);
        $totClose   = bcadd($totClose,   $r['closing_ucc'], 2);
        $totRecap   = bcadd($totRecap,   $r['recapture'], 2);
        $totTerm    = bcadd($totTerm,    $r['terminal_loss'], 2);
    }

    fputcsv($out, [
        'TOTAL', $totOpening, $totAdd, $totDisp, $totUcc, '',
        $totHalf, $totCca, $totClose, $totRecap, $totTerm,
    ]);
    fclose($out);
    exit;
}

// PDF
require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
\FleetForge\Accounting\ReportPdfRenderer::ccaSchedule8($schedule);
exit;
