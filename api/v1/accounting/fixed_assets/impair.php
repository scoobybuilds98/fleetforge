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

$body   = json_body();
$fields = [];

$assetId        = clean_int($body['asset_id'] ?? null);
$impairmentDate = clean_date($body['impairment_date'] ?? null);
$impairmentLoss = clean_decimal($body['impairment_loss'] ?? null);
$reason         = clean_string($body['reason'] ?? null, 2000);

if (!$assetId) {
    $fields['asset_id'] = 'Asset ID is required.';
}
if (!$impairmentDate) {
    $fields['impairment_date'] = 'Impairment date is required.';
}
if ($impairmentLoss === null || $impairmentLoss === '') {
    $fields['impairment_loss'] = 'Impairment loss amount is required.';
} elseif (bccomp($impairmentLoss, '0.00', 2) <= 0) {
    $fields['impairment_loss'] = 'Impairment loss must be greater than zero.';
}
if (!$reason) {
    $fields['reason'] = 'Please provide a reason for the impairment.';
}

if ($fields) {
    json_validation_error($fields);
}

$data = [
    'asset_id'        => $assetId,
    'impairment_date' => $impairmentDate,
    'impairment_loss' => $impairmentLoss,
    'reason'          => $reason,
];

$user = current_user();
$role = $user['role_slug'] ?? '';

try {
    $impairment = FixedAssetService::impair($data, current_user_id(), $role);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Only managers')) {
        json_error('FORBIDDEN', $msg, 403,
            ['fields' => ['_general' => 'Only managers can record an impairment.']]);
    }
    $slot = '_general';
    if (stripos($msg, 'not found') !== false)       $slot = 'asset_id';
    elseif (stripos($msg, 'disposed') !== false)    $slot = 'asset_id';
    elseif (stripos($msg, 'impairment_loss') !== false || stripos($msg, 'NBV') !== false)
                                                    $slot = 'impairment_loss';
    elseif (stripos($msg, 'period') !== false)      $slot = 'impairment_date';
    elseif (stripos($msg, 'Impairment loss account') !== false) $slot = '_general';
    json_validation_error([$slot => $msg], $msg);
}

json_success($impairment, 201);
