<?php
declare(strict_types=1);

/**
 * api/v1/vendors/update.php
 *
 * Update an existing vendor.
 *
 * Business rules:
 *   - D19 optimistic lock: submitted updated_at must match the DB value.
 *   - name uniqueness excludes the current vendor.
 *   - rating must be 1–5 or null.
 *   - specializations replaces the entire array (not merged).
 *   - total_spent is NOT editable — managed by work order module.
 *   - Audit log: action='update', old_values/new_values captured.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required, D19 lock),
 *               name?, vendor_type?, contact_name?, email?, phone?,
 *               address?, city?, state?, specializations (array)?,
 *               hourly_rate?, rating?, notes?, is_preferred?
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { id, name, vendor_type, updated_at }
 *          404 NOT_FOUND | 409 STALE_DATA | 409 ALREADY_EXISTS
 *
 * Decisions: D5 (soft delete), D16 (bcmath), D19 (optimistic lock), §7 (audit log)
 * Session: S014
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

// -----------------------------------------------------------------------
// 1. Parse body + resolve vendor
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_error('MISSING_REQUIRED', 'updated_at is required for optimistic locking.', 422);
}

$existing = db_row(
    "SELECT * FROM vendors WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Vendor not found.', 404);
}

// -----------------------------------------------------------------------
// 2. D19 optimistic lock
// -----------------------------------------------------------------------
if (!optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA', 'Vendor was modified by another user. Refresh and try again.', 409);
}

// -----------------------------------------------------------------------
// 3. Validate fields (only those present in body are updated)
// -----------------------------------------------------------------------
$name = isset($body['name']) ? clean_string($body['name'], 255) : $existing['name'];
if (!$name) {
    json_error('VALIDATION_ERROR', 'name cannot be empty.', 422);
}

// Uniqueness check — exclude self
if ($name !== $existing['name']) {
    if (db_exists('vendors', 'name = ? AND id != ? AND deleted_at IS NULL', [$name, $id])) {
        json_error('ALREADY_EXISTS', 'A vendor with that name already exists.', 409);
    }
}

$vendorType = $existing['vendor_type'];
if (isset($body['vendor_type'])) {
    $vendorType  = clean_string($body['vendor_type']);
    $validTypes  = ['maintenance', 'repair', 'parts', 'inspection', 'towing', 'other'];
    if (!in_array($vendorType, $validTypes, true)) {
        json_error('VALIDATION_ERROR', 'vendor_type must be one of: ' . implode(', ', $validTypes) . '.', 422);
    }
}

$contactName = isset($body['contact_name']) ? clean_string($body['contact_name'], 255) : $existing['contact_name'];
$email       = isset($body['email'])        ? clean_email($body['email'])               : $existing['email'];
$phone       = isset($body['phone'])        ? clean_string($body['phone'], 50)          : $existing['phone'];
$address     = isset($body['address'])      ? clean_string($body['address'], 500)       : $existing['address'];
$city        = isset($body['city'])         ? clean_string($body['city'], 100)          : $existing['city'];
$state       = isset($body['state'])        ? clean_string($body['state'], 100)         : $existing['state'];
$notes       = isset($body['notes'])        ? clean_string($body['notes'], 5000)        : $existing['notes'];
$isPreferred = isset($body['is_preferred']) ? (int)(bool)$body['is_preferred']          : (int)$existing['is_preferred'];

// rating: 1–5 or null
$rating = $existing['rating'];
if (array_key_exists('rating', $body)) {
    if ($body['rating'] === null || $body['rating'] === '') {
        $rating = null;
    } else {
        $rating = clean_int($body['rating']);
        if ($rating === null || $rating < 1 || $rating > 5) {
            json_error('VALIDATION_ERROR', 'rating must be an integer between 1 and 5.', 422);
        }
    }
}

// hourly_rate: D16 bcmath string or null
$hourlyRate = $existing['hourly_rate'];
if (array_key_exists('hourly_rate', $body)) {
    if ($body['hourly_rate'] === null || $body['hourly_rate'] === '') {
        $hourlyRate = null;
    } else {
        $hourlyRate = clean_decimal($body['hourly_rate']);
        if ($hourlyRate === null) {
            json_error('VALIDATION_ERROR', 'hourly_rate must be a valid decimal number.', 422);
        }
    }
}

// specializations: replace entire array
$specializations = $existing['specializations'];
if (array_key_exists('specializations', $body)) {
    if (is_array($body['specializations'])) {
        $specs = [];
        foreach ($body['specializations'] as $s) {
            $cleaned = clean_string((string)$s, 100);
            if ($cleaned) {
                $specs[] = $cleaned;
            }
        }
        $specializations = !empty($specs) ? json_encode($specs) : null;
    } else {
        $specializations = null;
    }
}

// -----------------------------------------------------------------------
// 4. Update inside transaction + audit log
// -----------------------------------------------------------------------
$newValues = [
    'name'            => $name,
    'vendor_type'     => $vendorType,
    'contact_name'    => $contactName,
    'email'           => $email,
    'phone'           => $phone,
    'address'         => $address,
    'city'            => $city,
    'state'           => $state,
    'specializations' => $specializations,
    'hourly_rate'     => $hourlyRate,
    'rating'          => $rating,
    'notes'           => $notes,
    'is_preferred'    => $isPreferred,
];

db_transaction(function() use ($id, $newValues, $existing) {
    db_update('vendors', $newValues, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'maintenance',
        'entity_type'  => 'vendor',
        'entity_id'    => $id,
        'entity_label' => $newValues['name'],
        'old_values'   => json_encode([
            'name'         => $existing['name'],
            'vendor_type'  => $existing['vendor_type'],
            'is_preferred' => $existing['is_preferred'],
        ]),
        'new_values'   => json_encode([
            'name'         => $newValues['name'],
            'vendor_type'  => $newValues['vendor_type'],
            'is_preferred' => $newValues['is_preferred'],
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Fetch fresh updated_at to return to client for next D19 lock cycle
$fresh = db_row("SELECT updated_at FROM vendors WHERE id = ?", [$id]);

json_success([
    'id'         => $id,
    'name'       => $name,
    'vendor_type' => $vendorType,
    'updated_at' => $fresh['updated_at'],
]);
