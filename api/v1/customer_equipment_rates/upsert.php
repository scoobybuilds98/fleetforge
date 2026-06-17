<?php
declare(strict_types=1);

/**
 * api/v1/customer_equipment_rates/upsert.php
 *
 * @deprecated S-RATES-CONSOLIDATE — customer pricing moved onto rate cards.
 *   No UI writes overrides anymore (lookup_rates.php ignores this table). Kept
 *   functional-but-dormant for reversibility until a cleanup migration.
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
 *               hourly_rate?, mileage_unit?, currency?, minimum_charge?, notes?, effective_to?
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
// 1. Parse body — VALID-2: accumulate every error
// -----------------------------------------------------------------------
$body   = json_body();
$fields = [];

$customerId    = clean_int($body['customer_id'] ?? null);
$equipType     = clean_string($body['equipment_type'] ?? null, 255);
$effectiveFrom = clean_date($body['effective_from'] ?? null);

if (!$customerId) {
    $fields['customer_id'] = 'Please select a customer.';
}
if (!$equipType) {
    $fields['equipment_type'] = 'Equipment type is required.';
}
if (!$effectiveFrom) {
    $fields['effective_from'] = 'Effective from date is required.';
}

if ($fields) {
    json_validation_error($fields);
}

// Verify customer exists
if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
    json_validation_error(['customer_id' => 'Customer not found.']);
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
        json_validation_error(['updated_at' => 'Optimistic lock token is required.']);
    }
    if (!optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
        json_error('STALE_DATA',
            'This rate was modified by another user. Refresh and try again.', 409,
            ['fields' => ['updated_at' => 'This rate was modified by another user. Refresh and try again.']]);
    }
} else {
    // Create path — ensure no existing record for this combination
    $duplicate = db_row(
        "SELECT id FROM customer_equipment_rates
         WHERE customer_id = ? AND equipment_type = ? AND effective_from = ?",
        [$customerId, $equipType, $effectiveFrom]
    );
    if ($duplicate) {
        json_validation_error([
            'equipment_type' => 'A rate override for this customer, equipment type, and effective date already exists.',
        ], 'A rate override for this customer, equipment type, and effective date already exists.');
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
$hourlyRate    = null;
$minimumCharge = null;

// Friendly labels for rate fields
$rateFieldMap = [
    'daily_rate'     => ['var' => 'dailyRate',     'label' => 'Daily rate'],
    'weekly_rate'    => ['var' => 'weeklyRate',    'label' => 'Weekly rate'],
    'monthly_rate'   => ['var' => 'monthlyRate',   'label' => 'Monthly rate'],
    'mileage_rate'   => ['var' => 'mileageRate',   'label' => 'Mileage rate'],
    'hourly_rate'    => ['var' => 'hourlyRate',    'label' => 'Hourly rate'],
    'minimum_charge' => ['var' => 'minimumCharge', 'label' => 'Minimum charge'],
];

foreach ($rateFieldMap as $field => $info) {
    $varName = $info['var'];
    $label   = $info['label'];
    if (array_key_exists($field, $body)) {
        if ($body[$field] === null || $body[$field] === '') {
            $$varName = null;
        } else {
            $val = clean_decimal((string)$body[$field]);
            if ($val === null) {
                $fields[$field] = "{$label} must be a valid number.";
            } elseif (bccomp($val, '0', 6) < 0) {
                $fields[$field] = "{$label} cannot be negative.";
            } else {
                $$varName = $val;
            }
        }
    } elseif ($isUpdate && $existing) {
        $$varName = $existing[$field];
    }
}

$currency    = clean_string($body['currency'] ?? null, 10)
               ?? ($isUpdate ? $existing['currency'] : 'CAD');
$mileageUnit = clean_string($body['mileage_unit'] ?? null, 10)
               ?? ($isUpdate ? $existing['mileage_unit'] : 'km');

if (!in_array($currency, $validCurrencies, true)) {
    $fields['currency'] = 'Currency must be CAD or USD.';
}
if (!in_array($mileageUnit, $validMileageUnits, true)) {
    $fields['mileage_unit'] = 'Mileage unit must be km or miles.';
}

$notes       = clean_string($body['notes'] ?? null, 2000)
               ?? ($isUpdate ? $existing['notes'] : null);

$effectiveTo = null;
if (array_key_exists('effective_to', $body)) {
    $effectiveTo = !empty($body['effective_to']) ? clean_date($body['effective_to']) : null;
    if (!empty($body['effective_to']) && !$effectiveTo) {
        $fields['effective_to'] = 'Effective to must be a valid date.';
    }
    if ($effectiveTo && $effectiveTo < $effectiveFrom) {
        $fields['effective_to'] = 'End date must be on or after the start date.';
    }
} elseif ($isUpdate && $existing) {
    $effectiveTo = $existing['effective_to'];
}

if ($fields) {
    json_validation_error($fields);
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
    'hourly_rate'    => $hourlyRate,
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
        'hourly_rate'    => $rateData['hourly_rate'],
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
