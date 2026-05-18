<?php
declare(strict_types=1);

/**
 * api/v1/accounting/budgets/copy.php
 *
 * Clone a budget header + all line items into a new year.
 *
 * @method  POST
 * @body    { source_id, new_year }
 * @auth    Session required; require_permission('journal_entries','create')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BudgetService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$sourceId = clean_int($input['source_id'] ?? null);
$newYear  = clean_int($input['new_year'] ?? null);
if (!$sourceId || !$newYear) {
    json_error('MISSING_REQUIRED', 'source_id and new_year are required.', 422);
}

try {
    $row = BudgetService::copy($sourceId, $newYear, current_user_id());
    json_success($row, 201);
} catch (\InvalidArgumentException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
} catch (\Throwable $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}
