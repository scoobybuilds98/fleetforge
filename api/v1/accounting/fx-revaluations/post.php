<?php
declare(strict_types=1);

/**
 * api/v1/accounting/fx-revaluations/post.php
 *
 * Post a revaluation for a period. Requires explicit `confirm=1` to
 * defend against double-clicks and accidental refresh-after-preview.
 *
 * @method  POST
 * @body    { period_id, rate, confirm: 1 }
 * @auth    Session required; require_permission('journal_entries','create')
 *
 * Session: S037-FX
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FxRevaluationService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

$body = json_body();
$input = !empty($body) ? $body : $_POST;

$periodId = clean_int($input['period_id'] ?? null);
$rate     = (string) ($input['rate'] ?? '');
$confirm  = !empty($input['confirm']);

if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}
if ($rate === '' || !is_numeric($rate)) {
    json_error('VALIDATION_ERROR', 'rate must be a numeric string (e.g. "1.365000").', 422);
}
if (!$confirm) {
    json_error('CONFIRM_REQUIRED', 'confirm flag must be set to 1 to post the revaluation.', 422);
}

try {
    $result = FxRevaluationService::post($periodId, $rate, current_user_id());
    json_success($result, 201);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'already posted')) {
        json_error('ALREADY_POSTED', $msg, 409);
    }
    if (str_contains($msg, 'disabled')) {
        json_error('FX_DISABLED', $msg, 422);
    }
    if (str_contains($msg, 'GL account not configured')) {
        json_error('CONFIG_INCOMPLETE', $msg, 422);
    }
    json_error('FX_POST_FAILED', $msg, 422);
}
