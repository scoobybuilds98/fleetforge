<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/expense.php
 *
 * Mark a flagged work order as 'expensed' (no asset, no JE). Creates
 * an acc_capex_requests row in 'rejected' state with the supplied
 * reason so the WO disappears from the flagged list and there is an
 * audit trail of the decision.
 *
 * @method  POST
 * @body    JSON: work_order_id, reason
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 capex_request row | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body = json_body();

$woId   = clean_int($body['work_order_id'] ?? null);
$reason = clean_string($body['reason'] ?? null);

if (!$woId) {
    json_error('MISSING_REQUIRED', 'work_order_id is required.', 422);
}
if (!$reason) {
    json_error('MISSING_REQUIRED', 'reason is required.', 422);
}

try {
    $u = current_user();
    $cr = FixedAssetService::expenseFlaggedWorkOrder(
        $woId,
        $reason,
        current_user_id(),
        (string) ($u['role_slug'] ?? '')
    );
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($cr);
