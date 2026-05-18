<?php
declare(strict_types=1);

/**
 * api/v1/accounting/budgets/create.php
 *
 * @method  POST
 * @body    { name, year, version?, notes?, copy_prior_year? }
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

try {
    $row = BudgetService::create($input, current_user_id());
    json_success($row, 201);
} catch (\InvalidArgumentException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
} catch (\Throwable $e) {
    json_error('SERVER_ERROR', $e->getMessage(), 500);
}
