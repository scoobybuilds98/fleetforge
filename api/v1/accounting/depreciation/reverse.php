<?php declare(strict_types=1);

/**
 * api/v1/accounting/depreciation/reverse.php
 *
 * Reverse a previously POSTED depreciation run. This:
 *  - Creates a reversal JE (DR/CR swapped, source_type='depreciation')
 *  - Marks the original JE as 'reversed'
 *  - Restores each asset's accumulated_depreciation and net_book_value
 *  - Restores 'active' status if a 'fully_depreciated' asset was put there
 *    by this very run
 *  - Marks the run row status='reversed'
 *  - Audit logs the event
 *
 * The period MUST NOT be 'locked'. Closed periods can still be reversed
 * (the accountant intentionally reaches back); locked periods need to be
 * re-opened first via Accounting → Periods.
 *
 * @method  POST
 * @body    JSON: run_id
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 { run, reversal_je } | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body  = json_body();
$runId = clean_int($body['run_id'] ?? null);
if (!$runId) {
    json_validation_error(['run_id' => 'Depreciation run ID is required.']);
}

try {
    $result = FixedAssetService::reverseRun($runId, current_user_id());
} catch (\RuntimeException $e) {
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'not found') !== false
        || stripos($msg, 'already') !== false
        || stripos($msg, 'status') !== false
        || stripos($msg, 'period') !== false
        || stripos($msg, 'locked') !== false) {
        $slot = 'run_id';
    }
    json_validation_error([$slot => $msg], $msg);
}

json_success($result);
