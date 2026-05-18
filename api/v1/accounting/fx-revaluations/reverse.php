<?php
declare(strict_types=1);

/**
 * api/v1/accounting/fx-revaluations/reverse.php
 *
 * Reverse a posted revaluation. Calls JournalEntryService::reverse() on
 * the backing JE inside the same db_transaction; flips status to 'reversed'.
 *
 * @method  POST
 * @body    { id }
 * @auth    Session required; require_permission('journal_entries','edit')
 *
 * Session: S037-FX
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FxRevaluationService;

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
    $result = FxRevaluationService::reverse($id, current_user_id());
    json_success($result);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'not found')) {
        json_error('NOT_FOUND', $msg, 404);
    }
    if (str_contains($msg, "not in 'posted'")) {
        json_error('INVALID_STATUS', $msg, 422);
    }
    json_error('FX_REVERSE_FAILED', $msg, 422);
}
