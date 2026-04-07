<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/impair.php
 *
 * Record an impairment loss for a fixed asset.
 * Posts: DR 7020 Impairment Loss / CR Accumulated Depreciation
 * Sets asset status = 'impaired'.
 *
 * Manager-only — service enforces.
 *
 * @method  POST
 * @body    JSON: asset_id, impairment_date, impairment_loss, reason
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 impairment row | 422 validation error | 403 manager-only
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body = json_body();

$data = [
    'asset_id'        => clean_int($body['asset_id'] ?? null),
    'impairment_date' => clean_date($body['impairment_date'] ?? null),
    'impairment_loss' => clean_decimal($body['impairment_loss'] ?? null),
    'reason'          => clean_string($body['reason'] ?? null, 2000),
];

$user = current_user();
$role = $user['role_slug'] ?? '';

try {
    $impairment = FixedAssetService::impair($data, current_user_id(), $role);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Only managers')) {
        json_error('FORBIDDEN', $msg, 403);
    }
    json_error('VALIDATION_ERROR', $msg, 422);
}

json_success($impairment, 201);
