<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/approve.php
 *
 * Approve a pending CapEx request. Manager-only — service enforces.
 *
 * @method  POST
 * @body    JSON: id
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 capex row | 422 | 403 manager-only
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

$user = current_user();
$role = $user['role_slug'] ?? '';

try {
    $capex = FixedAssetService::approveCapex($id, current_user_id(), $role);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Only managers')) {
        json_error('FORBIDDEN', $msg, 403);
    }
    json_error('VALIDATION_ERROR', $msg, 422);
}

json_success($capex);
