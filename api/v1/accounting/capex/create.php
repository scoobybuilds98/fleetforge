<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/create.php
 *
 * Submit a new CapEx request (status starts at pending_approval).
 *
 * @method  POST
 * @body    JSON: title, asset_class, budget_amount, description?, justification?,
 *                vendor_id?, work_order_id?, notes?
 * @auth    Session required; require_permission('fixed_assets','create')
 * @returns 201 capex row | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'create');

$body = json_body();

$data = [
    'title'         => clean_string($body['title'] ?? null, 255),
    'description'   => clean_string($body['description'] ?? null, 2000),
    'asset_class'   => clean_string($body['asset_class'] ?? null, 50),
    'budget_amount' => clean_decimal($body['budget_amount'] ?? null),
    'justification' => clean_string($body['justification'] ?? null, 2000),
    'vendor_id'     => clean_int($body['vendor_id'] ?? null),
    'work_order_id' => clean_int($body['work_order_id'] ?? null),
    'notes'         => clean_string($body['notes'] ?? null, 2000),
];

try {
    $capex = FixedAssetService::createCapex($data, current_user_id());
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($capex, 201);
