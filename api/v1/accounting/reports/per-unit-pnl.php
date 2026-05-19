<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/per-unit-pnl.php
 *
 * Per-unit profitability report per FLEETFORGE_ACCOUNTING_SPEC.md §23.10.
 * Returns the per-unit P&L for an arbitrary date range — one unit or the
 * full fleet, with optional CSV / PDF export.
 *
 * @method  GET
 * @query   period_start (YYYY-MM-DD, required),
 *          period_end   (YYYY-MM-DD, required, >= period_start),
 *          unit_id      (int, optional — full fleet when omitted),
 *          format       (json|csv|pdf, default json)
 * @auth    Session required; require_permission('journal_entries','view')
 *          per REFERENCE.md Trap 15 (accounting reports use
 *          journal_entries, not financial_reports).
 * @returns 200 json|csv|pdf | 422 validation
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.10
 * Session: S-ACCT-UNIT
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\UnitProfitabilityService;
use FleetForge\Accounting\ReportPdfRenderer;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$from   = clean_string($_GET['period_start'] ?? '', 10);
$to     = clean_string($_GET['period_end']   ?? '', 10);
$unitId = isset($_GET['unit_id']) ? clean_int($_GET['unit_id']) : null;
$format = strtolower((string) ($_GET['format'] ?? 'json'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    json_error('VALIDATION_ERROR', 'period_start must be YYYY-MM-DD.', 422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    json_error('VALIDATION_ERROR', 'period_end must be YYYY-MM-DD.', 422);
}
if ($to < $from) {
    json_error('VALIDATION_ERROR', 'period_end must be on or after period_start.', 422);
}
if (!in_array($format, ['json', 'csv', 'pdf'], true)) {
    json_error('VALIDATION_ERROR', "format must be one of: json, csv, pdf.", 422);
}
if ($unitId !== null && $unitId <= 0) {
    json_error('VALIDATION_ERROR', 'unit_id must be a positive integer.', 422);
}

$report = UnitProfitabilityService::getFleetPnl($from, $to, $unitId);

if ($format === 'csv') {
    $filename = "per_unit_pnl_{$from}_to_{$to}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename={$filename}");

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Unit #', 'Display Label', 'Year', 'Status',
        'Revenue Total', 'Base Rental', 'Mileage', 'GPS', 'Other Revenue',
        'Direct Costs Total', 'Depreciation', 'Insurance', 'Licensing', 'GPS Cost', 'Other Direct',
        'Contribution Margin', 'Contribution %',
        'Overhead Allocated', 'EBIT', 'ROIC %', 'Attribution',
    ]);
    foreach ($report['units'] as $u) {
        fputcsv($out, [
            $u['unit_number'] ?? '',
            $u['display_label'] ?? '',
            $u['year'] ?? '',
            $u['status'] ?? '',
            $u['revenue']['total'] ?? '0.00',
            $u['revenue']['base_rental'] ?? '0.00',
            $u['revenue']['mileage'] ?? '0.00',
            $u['revenue']['gps'] ?? '0.00',
            $u['revenue']['other'] ?? '0.00',
            $u['direct_costs']['total_direct'] ?? '0.00',
            $u['direct_costs']['depreciation'] ?? '0.00',
            $u['direct_costs']['insurance'] ?? '0.00',
            $u['direct_costs']['licensing'] ?? '0.00',
            $u['direct_costs']['gps_cost'] ?? '0.00',
            $u['direct_costs']['other_direct'] ?? '0.00',
            $u['contribution_margin'] ?? '0.00',
            $u['contribution_margin_pct'] ?? '',
            $u['overhead_allocated'] ?? '0.00',
            $u['ebit'] ?? '0.00',
            $u['roic_pct'] ?? '',
            $u['attribution_method'] ?? '',
        ]);
    }
    fclose($out);
    return;
}

if ($format === 'pdf') {
    ReportPdfRenderer::perUnitPnl($report);
    return;
}

json_success($report);
