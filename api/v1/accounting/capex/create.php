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

$body   = json_body();
$fields = [];

$title        = clean_string($body['title'] ?? null, 255);
$assetClass   = clean_string($body['asset_class'] ?? null, 50);
$budgetAmount = clean_decimal($body['budget_amount'] ?? null);

// VALID-2: phase 1 — required-field accumulator with spec-exact messages
if (!$title)      $fields['title']       = 'Project title is required.';
if (!$assetClass) $fields['asset_class'] = 'Please select an asset class.';
if ($budgetAmount === null || $budgetAmount === '') {
    $fields['budget_amount'] = 'Budget amount is required.';
} elseif (bccomp($budgetAmount, '0.00', 2) <= 0) {
    $fields['budget_amount'] = 'Budget amount must be greater than zero.';
}

if ($fields) {
    json_validation_error($fields);
}

$data = [
    'title'         => $title,
    'description'   => clean_string($body['description'] ?? null, 2000),
    'asset_class'   => $assetClass,
    'budget_amount' => $budgetAmount,
    'justification' => clean_string($body['justification'] ?? null, 2000),
    'vendor_id'     => clean_int($body['vendor_id'] ?? null),
    'work_order_id' => clean_int($body['work_order_id'] ?? null),
    'notes'         => clean_string($body['notes'] ?? null, 2000),
];

try {
    $capex = FixedAssetService::createCapex($data, current_user_id());
} catch (\RuntimeException $e) {
    // Route service exceptions to the most-relevant field slot
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'title') !== false)       $slot = 'title';
    elseif (stripos($msg, 'budget') !== false)  $slot = 'budget_amount';
    elseif (stripos($msg, 'asset_class') !== false) $slot = 'asset_class';
    elseif (stripos($msg, 'vendor') !== false)  $slot = 'vendor_id';
    json_validation_error([$slot => $msg], $msg);
}

json_success($capex, 201);
