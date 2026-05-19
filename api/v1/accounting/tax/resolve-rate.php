<?php
declare(strict_types=1);

/**
 * api/v1/accounting/tax/resolve-rate.php
 *
 * Resolve the place of supply + applicable tax rates for a transaction
 * per spec §23.6. Read-only — no writes. Used by the Tax admin page's
 * Test Resolution form and by callers that want to confirm POS derivation
 * before creating a tax-bearing transaction.
 *
 * @method  GET
 * @query   customer_id (required), transaction_type (required), transaction_date,
 *          lease_id (optional), asset_id (optional), delivery_province (optional)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { resolved_province, resolution_method, applicable_rates[],
 *                is_out_of_province, derivation_trail }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.6
 * Session: S-ACCT-POS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\PlaceOfSupplyService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
$type       = clean_string($_GET['transaction_type'] ?? null, 30);
$date       = clean_date($_GET['transaction_date'] ?? null) ?? date('Y-m-d');
$leaseId    = clean_int($_GET['lease_id'] ?? null);
$assetId    = clean_int($_GET['asset_id'] ?? null);
$delivery   = clean_string($_GET['delivery_province'] ?? null, 3);

$errors = [];
if (!$customerId) $errors['customer_id'] = 'customer_id is required.';
if (!$type)       $errors['transaction_type'] = 'transaction_type is required.';
if ($errors) {
    json_validation_error($errors);
}

try {
    $result = PlaceOfSupplyService::resolve([
        'customer_id'       => $customerId,
        'transaction_type'  => $type,
        'transaction_date'  => $date,
        'lease_id'          => $leaseId,
        'asset_id'          => $assetId,
        'delivery_province' => $delivery,
    ]);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result);
