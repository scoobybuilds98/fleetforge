<?php
declare(strict_types=1);

/**
 * api/v1/accounting/budgets/show.php
 *
 * Get a single budget header + all line items.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BudgetService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    $header = BudgetService::loadHeader($id);
} catch (\Throwable $e) {
    json_error('NOT_FOUND', 'Budget not found.', 404);
}

$lines = BudgetService::loadLines($id);

json_success([
    'budget' => $header,
    'lines'  => $lines,
]);
