<?php
declare(strict_types=1);

/**
 * api/v1/accounting/budgets/variance.php
 *
 * Budget vs actual variance report for a date range. Pro-rates monthly
 * budget amounts to the requested range and compares against posted JE
 * activity in the same range.
 *
 * @method  GET
 * @query   budget_id (required), period_start, period_end
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BudgetService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$budgetId = clean_int($_GET['budget_id'] ?? null);
$from     = clean_date($_GET['period_start'] ?? null);
$to       = clean_date($_GET['period_end'] ?? null);

if (!$budgetId) {
    json_error('MISSING_REQUIRED', 'budget_id is required.', 422);
}
if (!$from || !$to) {
    json_error('MISSING_REQUIRED', 'period_start and period_end are required.', 422);
}
if (strtotime($from) > strtotime($to)) {
    json_error('VALIDATION_ERROR', 'period_start must be on or before period_end.', 422);
}

try {
    $variance = BudgetService::variance($budgetId, $from, $to);
    json_success($variance);
} catch (\Throwable $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}
