<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/working-trial-balance.php
 *
 * Working Trial Balance v2 — 10-column practitioner-grade trial balance
 * per spec §23.2. Every amount column is a BALANCE (balance-sheet accounts
 * cumulative, income-statement accounts fiscal year-to-date): Adj CY at the
 * period end, AJEs = this period's adjusting/reclassifying/prior_period
 * entries, Unadj CY = Adj CY − AJEs, PY at the comparison date, Var = Adj CY
 * − PY. Computed by ReportingService::workingTrialBalance(); this endpoint
 * validates input, resolves the periods, adds the annotation count.
 *
 * Columns: GL# | Account | Lead | PY Balance | Unadj CY | AJEs |
 *          Adj CY | Var $ | Var % | Ref
 *
 * @method  GET
 * @query   period_id (required), materiality? (decimal string, default '0.00'),
 *          comparison_period_id? (PY period; if omitted, PY end = current
 *          period's year - 1, Dec 31)
 * @auth    Session required; require_permission('journal_entries','view')
 *          (existing reports use this module — no 'financial_reports' module
 *          exists on disk per pre-flight scan)
 * @returns 200 { period, py_period, materiality, basis, accounts[], totals,
 *                is_balanced, annotations_count }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.2
 * Session: S-ACCT-WTB, S-CASHFLOW-TIE (balance basis)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ReportingService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$periodId           = clean_int($_GET['period_id'] ?? null);
$materiality        = clean_decimal($_GET['materiality'] ?? '0.00') ?? '0.00';
$comparisonPeriodId = clean_int($_GET['comparison_period_id'] ?? null);

if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}
if (bccomp($materiality, '0', 2) < 0) {
    json_error('VALIDATION_ERROR', 'materiality must be non-negative.', 422);
}

$period = db_row(
    "SELECT id, name, start_date, end_date, year, status
     FROM acc_periods WHERE id = ?",
    [$periodId]
);
if (!$period) {
    json_error('NOT_FOUND', "Period {$periodId} not found.", 404);
}

// WHY: PY balance is point-in-time as of (current period year - 1) Dec 31,
// unless caller passes a specific comparison_period_id.
$pyPeriod = null;
if ($comparisonPeriodId) {
    $pyPeriod = db_row(
        "SELECT id, name, end_date FROM acc_periods WHERE id = ?",
        [$comparisonPeriodId]
    );
    if (!$pyPeriod) {
        json_error('NOT_FOUND', "Comparison period {$comparisonPeriodId} not found.", 404);
    }
    $pyEndDate = $pyPeriod['end_date'];
} else {
    $pyEndDate = ((int) $period['year'] - 1) . '-12-31';
    $pyPeriod = ['id' => null, 'name' => 'PY end of fiscal year', 'end_date' => $pyEndDate];
}

// Balances, AJE split, variance and materiality flags all live in the service
// (S-CASHFLOW-TIE) so the smoke can exercise them without an HTTP session.
$report = ReportingService::workingTrialBalance($period, $pyPeriod, $materiality);

$report['annotations_count'] = (int) db_count(
    "SELECT COUNT(*) FROM acc_workpaper_annotations
      WHERE workpaper_type = 'trial_balance'
        AND period_id = ?",
    [$periodId]
);

$format = strtolower((string) ($_GET['format'] ?? 'json'));
if ($format === 'pdf') {
    require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
    \FleetForge\Accounting\ReportPdfRenderer::workingTrialBalance($report);
    exit;
}

json_success($report);
