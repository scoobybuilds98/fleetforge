<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/start.php
 *
 * Move an approved CapEx request to in_progress.
 *
 * @method  POST
 * @body    JSON: id
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 capex row | 422
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    $capex = FixedAssetService::startCapex($id, current_user_id());
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($capex);
