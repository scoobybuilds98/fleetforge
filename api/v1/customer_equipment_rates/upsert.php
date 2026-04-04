<?php
declare(strict_types=1);

/**
 * api/v1/customer_equipment_rates/upsert.php
 *
 * Create or update a custom rate override for a customer + equipment_type combination.
 *
 * Business rules:
 *   - customer_id + equipment_type + effective_from form a unique key.
 *   - If a record already exists for this combination: UPDATE (D19 lock on updated_at).
 *   - If no record exists: INSERT (create path — no updated_at required).
 *   - updated_at required ONLY on update path. If id is provided → update path.
 *     If id is omitted → create path (will fail with ALREADY_EXISTS if duplicate).
 *   - All rate fields optional (at least one rate should be non-null for usefulness).
 *   - D16: clean_decimal() for all rate values.
 *   - Writes to customer_rate_history on both create and update.
 *   - Audit log: action='create' or 'update', module='rates'.
 *
 * @method  POST
 * @body    JSON: customer_id (required), equipment_type (required),
 *               effective_from (required), id?, updated_at (D19 — required for update),
 *               daily_rate?, weekly_rate?, monthly_rate?, mileage_rate?,
 *               mileage_unit?, currency?, minimum_charge?, notes?, effective_to?
 * @auth    Session required; require_permission('rates','edit')
 * @returns 201/200 { id, customer_id, equipment_type, updated_at }
 *
 * Decisions: D7, D16 (bcmath), D19 (optimistic lock on update), §7 (audit)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('rates', 'edit');

// -----------------------------------------------------------------------
// 1. Parse body
// -----------------------------------------------------------------------
$body = json_body();

$customerId   = clean_int($body['customer_id'] ?? null);
$equipType    = clean_string($body['equipment_type'] ?? null, 255);
$effectiveFrom = clean_date($body['effective_from'] ?? null);

if (!$customerId)    json_error('MISSING_REQUIRED', 'customer_id is required.', 422);
if (!$equipType)     json_error('MISSING_REQUIRED', 'equipment_type is required.', 422);
if (!$effectiveFrom) json_error('MISSING_REQUIRED', 'effective_from is required (Y-m-d).', 422);

// Verify customer exists
if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Determine create vs update path
// -----------------------------------------------------------------------
$recordId  = clean_int($body['id'] ?? null);
$isUpdate  = ($recordId !== null);
$existing  = null;

if ($isUpdate) {
    $existing = db_row(
        "SELECT * FROM customer_equipment_rates WHERE id = ? AND customer_id = ?",
        [$recordId, $customerId]
    );
    if (!$existing) {
        json_error('NOT_FOUND', 'Rate override not found.', 404);
    }

    // D19 optimistic lock on update
    $submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
    if (!$submittedUpdatedAt) {
        json_error('MISSING_REQUIRED', 'updated_at is required when updating an existing rate.', 422);
    }
    if ($existing['updated_at'] !== $submittedUpdatedAt) {
        json_error('STALE_DATA', 'Rate was modified by another user. Refresh and try again.', 409);
    }
} else {
    // Create path — ensure no existing record for this combination
    $existing = db_row(
        "SELECT id FROM customer_equipment_rates
         WHERE customer_id = ? AND equipment_type = ? AND effective_from = ?",
        [$customerId, $equipType, $effectiveFrom]
    );
    if ($existing) {
        json_error(
            'ALREADY_EXISTS',
            "A rate override for this customer, equipment type, and effective date already exists. " .
            "Pass id + updated_at to update it.",
            409
        );
    }
}

// -----------------------------------------------------------------------
// 3. Validate optional rate fields (D16 bcmath strings)
// -----------------------------------------------------------------------
$validCurrencies   = ['CAD', 'USD'];
$validMileageUnits = ['km', 'miles'];

$dailyRate     = null;
$weeklyRate    = null;
$monthlyRate   = null;
$mileageRate   = null;
$minimumCharge = null;

foreach (['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'minimum_charge'] as $field) {
    $bodyField = $field;
    if (array_key_exists($bodyField, $body)) {
        if ($body[$bodyField] === null || $body[$bodyField] === '') {
            $$field = null;
        } else {
            $val = clean_decimal($body[$bodyField]);
            if ($val === null) {
                json_error('VALIDATION_ERROR', "$field must be a valid decimal number.", 422);
            }
            $$field = $val;
        }
    } elseif ($isUpdate && $existing) {
        $$field = $existing[$field];
    }
}

$currency    = clean_string($body['currency'] ?? null, 10)
               ?? ($isUpdate ? $existing['currency'] : 'CAD');
$mileageUnit = clean_string($body['mileage_unit'] ?? null, 10)
               ?? ($isUpdate ? $existing['mileage_unit'] : 'km');

if (!in_array($currency, $validCurrencies, true)) {
    json_error('VALIDATION_ERROR', 'currency must be CAD or USD.', 422);
}
if (!in_array($mileageUnit, $validMileageUnits, true)) {
    json_error('VALIDATION_ERROR', 'mileage_unit must be km or miles.', 422);
}

$notes       = clean_string($body['notes'] ?? null, 2000)
               ?? ($isUpdate ? $existing['notes'] : null);

$effectiveTo = null;
if (array_key_exists('effective_to', $body)) {
    $effectiveTo = !empty($body['effective_to']) ? clean_date($body['effective_to']) : null;
    if (!empty($body['effective_to']) && !$effectiveTo) {
        json_error('VALIDATION_ERROR', 'effective_to must be a valid date (Y-m-d).', 422);
    }
    if ($effectiveTo && $effectiveTo < $effectiveFrom) {
        json_error('VALIDATION_ERROR', 'effective_to must be on or after effective_from.', 422);
    }
} elseif ($isUpdate && $existing) {
    $effectiveTo = $existing['effective_to'];
}

// -----------------------------------------------------------------------
// 4. Build data array
// -----------------------------------------------------------------------
$rateData = [
    'customer_id'    => $customerId,
    'equipment_type' => $equipType,
    'effective_from' => $effectiveFrom,
    'effective_to'   => $effectiveTo,
    'daily_rate'     => $dailyRate,
    'weekly_rate'    => $weeklyRate,
    'monthly_rate'   => $monthlyRate,
    'mileage_rate'   => $mileageRate,
    'mileage_unit'   => $mileageUnit,
    'currency'       => $currency,
    'minimum_charge' => $minimumCharge,
    'notes'          => $notes,
    'created_by'     => $isUpdate ? ($existing['created_by'] ?? current_user_id()) : current_user_id(),
];

// -----------------------------------------------------------------------
// 5. Insert/update + rate history + audit log in one transaction
// -----------------------------------------------------------------------
$savedId = db_transaction(function() use (
    $isUpdate, $recordId, $customerId, $equipType, $rateData, $existing
) {
    $action      = $isUpdate ? 'update' : 'create';
    $changeType  = $isUpdate ? 'updated' : 'created';
    $httpStatus  = $isUpdate ? 200 : 201;

    if ($isUpdate) {
        db_update('customer_equipment_rates', $rateData, 'id = ?', [$recordId]);
        $savedId = $recordId;
    } else {
        $savedId = db_insert('customer_equipment_rates', $rateData);
    }

    // Record in customer_rate_history for every change
    db_insert('customer_rate_history', [
        'customer_id'    => $customerId,
        'equipment_type' => $equipType,
        'daily_rate'     => $rateData['daily_rate'],
        'weekly_rate'    => $rateData['weekly_rate'],
        'monthly_rate'   => $rateData['monthly_rate'],
        'mileage_rate'   => $rateData['mileage_rate'],
        'mileage_unit'   => $rateData['mileage_unit'],
        'currency'       => $rateData['currency'],
        'change_type'    => $changeType,
        'change_source'  => 'manual',
        'change_notes'   => $rateData['notes'],
        'created_by'     => current_user_id(),
    ]);

    // §7 audit log
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => $action,
        'module'       => 'rates',
        'entity_type'  => 'customer_equipment_rate',
        'entity_id'    => $savedId,
        'entity_label' => "Customer #$customerId — $equipType",
        'old_values'   => $isUpdate ? json_encode([
            'daily_rate'   => $existing['daily_rate'],
            'weekly_rate'  => $existing['weekly_rate'],
            'monthly_rate' => $existing['monthly_rate'],
        ]) : null,
        'new_values'   => json_encode([
            'daily_rate'   => $rateData['daily_rate'],
            'weekly_rate'  => $rateData['weekly_rate'],
            'monthly_rate' => $rateData['monthly_rate'],
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $savedId;
});

$fresh = db_row(
    "SELECT updated_at FROM customer_equipment_rates WHERE id = ?",
    [$savedId]
);

$httpStatus = $isUpdate ? 200 : 201;
json_success([
    'id'             => $savedId,
    'customer_id'    => $customerId,
    'equipment_type' => $equipType,
    'updated_at'     => $fresh['updated_at'],
], $httpStatus);
