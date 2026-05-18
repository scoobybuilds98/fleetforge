<?php
declare(strict_types=1);

/**
 * api/v1/accounting/budgets/update.php
 *
 * Update budget header and/or line items. D19 optimistic lock via updated_at.
 *
 * @method  POST
 * @body    { id, updated_at, name?, version?, status?, is_active?, notes?,
 *            lines?: [{ account_id, jan..dec, notes? }] }
 * @auth    Session required; require_permission('journal_entries','edit')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BudgetService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    $row = BudgetService::update($id, $input, current_user_id());
    json_success($row);
} catch (\InvalidArgumentException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
} catch (\RuntimeException $e) {
    if (str_starts_with($e->getMessage(), 'STALE_DATA')) {
        json_error('STALE_DATA', 'This budget was modified by someone else. Reload and try again.', 409);
    }
    json_error('NOT_FOUND', $e->getMessage(), 404);
}
