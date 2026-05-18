<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/profit-loss.php
 *
 * Profit & Loss statement for a date range with optional comparison
 * (prior period, prior year, or budget). Returns JSON by default;
 * ?format=pdf renders an mPDF document inline.
 *
 * @method  GET
 * @query   period_start (required), period_end (required),
 *          comparison? (none|prior_period|prior_year|budget),
 *          budget_id? (required if comparison=budget),
 *          format? (json|pdf, default json)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §10 + §21.1
 * Session:  S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ReportingService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$from       = clean_date($_GET['period_start'] ?? null);
$to         = clean_date($_GET['period_end'] ?? null);
$comparison = clean_string($_GET['comparison'] ?? 'none') ?: 'none';
$budgetId   = clean_int($_GET['budget_id'] ?? null);
$format     = strtolower((string) ($_GET['format'] ?? 'json'));

$validComparisons = ['none', 'prior_period', 'prior_year', 'budget'];
if (!in_array($comparison, $validComparisons, true)) {
    json_error('VALIDATION_ERROR', 'comparison must be one of: ' . implode(', ', $validComparisons), 422);
}
if ($comparison === 'budget' && !$budgetId) {
    json_error('MISSING_REQUIRED', 'budget_id is required when comparison=budget.', 422);
}
if (!$from || !$to) {
    json_error('MISSING_REQUIRED', 'period_start and period_end are required.', 422);
}
if (strtotime($from) > strtotime($to)) {
    json_error('VALIDATION_ERROR', 'period_start must be on or before period_end.', 422);
}

$report = ReportingService::profitAndLoss($from, $to, $comparison, $budgetId);

if ($format === 'pdf') {
    require_once FF_ROOT . '/lib/Accounting/ReportPdfRenderer.php';
    \FleetForge\Accounting\ReportPdfRenderer::profitAndLoss($report);
    exit;
}

json_success($report);
