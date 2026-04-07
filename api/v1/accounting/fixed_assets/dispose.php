<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/dispose.php
 *
 * Record an asset disposal — sale, scrap, donation, or trade-in.
 * Posts a JE: DR Cash + DR Accum Depr + DR/CR Gain/Loss, CR Asset Cost.
 *
 * Manager-only — service layer enforces this via $userRoleSlug.
 *
 * @method  POST
 * @body    JSON: asset_id, disposal_date, disposal_type (sale|scrap|donation|trade_in),
 *                proceeds (default 0.00), buyer_name?, buyer_reference?,
 *                proceeds_account_id? (defaults to settings cash account),
 *                gain_loss_account_id? (defaults to 7010/7020), notes?
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 disposal row | 422 validation error | 403 manager-only
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
    'asset_id'             => clean_int($body['asset_id'] ?? null),
    'disposal_date'        => clean_date($body['disposal_date'] ?? null),
    'disposal_type'        => clean_string($body['disposal_type'] ?? null, 50),
    'proceeds'             => clean_decimal($body['proceeds'] ?? '0.00'),
    'buyer_name'           => clean_string($body['buyer_name'] ?? null, 255),
    'buyer_reference'      => clean_string($body['buyer_reference'] ?? null, 100),
    'proceeds_account_id'  => clean_int($body['proceeds_account_id'] ?? null),
    'gain_loss_account_id' => clean_int($body['gain_loss_account_id'] ?? null),
    'notes'                => clean_string($body['notes'] ?? null, 2000),
];

$user = current_user();
$role = $user['role_slug'] ?? '';

try {
    $disposal = FixedAssetService::dispose($data, current_user_id(), $role);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Only managers')) {
        json_error('FORBIDDEN', $msg, 403);
    }
    json_error('VALIDATION_ERROR', $msg, 422);
}

json_success($disposal, 201);
