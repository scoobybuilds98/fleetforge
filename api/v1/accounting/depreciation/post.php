<?php declare(strict_types=1);

/**
 * api/v1/accounting/depreciation/post.php
 *
 * Post a previously previewed depreciation run. This:
 *  - Creates a single consolidated JE (depreciation source_type)
 *  - Updates each asset's accumulated_depreciation, net_book_value
 *  - Marks fully_depreciated assets when NBV touches salvage
 *  - Sets run.status='posted'
 *
 * Period must still be open. Cannot post twice.
 *
 * @method  POST
 * @body    JSON: run_id
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 posted run row | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body = json_body();

$runId = clean_int($body['run_id'] ?? null);
if (!$runId) {
    json_validation_error(['run_id' => 'Depreciation run ID is required.']);
}

try {
    $run = FixedAssetService::postRun($runId, current_user_id());
} catch (\RuntimeException $e) {
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'not found') !== false || stripos($msg, 'already') !== false || stripos($msg, 'status') !== false) {
        $slot = 'run_id';
    } elseif (stripos($msg, 'period') !== false || stripos($msg, 'locked') !== false || stripos($msg, 'closed') !== false) {
        $slot = 'run_id';
    }
    json_validation_error([$slot => $msg], $msg);
}

json_success($run);
