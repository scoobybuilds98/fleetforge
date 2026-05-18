<?php
declare(strict_types=1);

/**
 * api/v1/accounting/budgets/delete.php
 *
 * @method  POST
 * @body    { id }
 * @auth    Session required; require_permission('journal_entries','delete')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BudgetService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'delete');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    BudgetService::delete($id, current_user_id());
    json_success(['deleted' => true]);
} catch (\Throwable $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}
