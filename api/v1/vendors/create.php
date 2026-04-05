<?php
declare(strict_types=1);

/**
 * api/v1/vendors/create.php
 *
 * Create a new vendor.
 *
 * Business rules:
 *   - name is required and must be unique among non-deleted vendors.
 *   - vendor_type must be a valid ENUM value.
 *   - rating, if provided, must be 1–5 inclusive.
 *   - specializations, if provided, must be a JSON array of strings.
 *   - hourly_rate uses D16 bcmath via clean_decimal().
 *   - total_spent always starts at 0.00 — updated by work order module.
 *   - Audit log: action='create', module='maintenance', entity_type='vendor'.
 *
 * @method  POST
 * @body    JSON: name (required), vendor_type (required), contact_name?,
 *               email?, phone?, address?, city?, state?,
 *               specializations (array)?, hourly_rate?, rating?,
 *               notes?, is_preferred?
 * @auth    Session required; require_permission('maintenance','create')
 * @returns 201 { id, name, vendor_type }
 *
 * Decisions: D5 (soft delete), D7 (routing), D16 (bcmath), §7 (audit log)
 * Session: S014
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'create');

// -----------------------------------------------------------------------
// 1. Parse JSON body
// -----------------------------------------------------------------------
$body = json_body();

// -----------------------------------------------------------------------
// 2. Validate required fields
// -----------------------------------------------------------------------
$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    json_error('MISSING_REQUIRED', 'name is required.', 422);
}

$vendorType = clean_string($body['vendor_type'] ?? null);
$validTypes = ['maintenance', 'repair', 'parts', 'inspection', 'towing', 'other'];
if (!$vendorType || !in_array($vendorType, $validTypes, true)) {
    json_error('VALIDATION_ERROR', 'vendor_type is required and must be one of: ' . implode(', ', $validTypes) . '.', 422);
}

// -----------------------------------------------------------------------
// 3. Uniqueness check — name must be unique among active vendors
// -----------------------------------------------------------------------
if (db_exists('vendors', 'name = ? AND deleted_at IS NULL', [$name])) {
    json_error('ALREADY_EXISTS', 'A vendor with that name already exists.', 409);
}

// -----------------------------------------------------------------------
// 4. Optional fields with validation
// -----------------------------------------------------------------------
$contactName = clean_string($body['contact_name'] ?? null, 255);
$email       = clean_email($body['email'] ?? null);
$phone       = clean_string($body['phone'] ?? null, 50);
$address     = clean_string($body['address'] ?? null, 500);
$city        = clean_string($body['city'] ?? null, 100);
$state       = clean_string($body['state'] ?? null, 100);
$notes       = clean_string($body['notes'] ?? null, 5000);
$isPreferred = isset($body['is_preferred']) ? (int)(bool)$body['is_preferred'] : 0;

// rating: 1–5 or null
$rating = null;
if (isset($body['rating']) && $body['rating'] !== null && $body['rating'] !== '') {
    $rating = clean_int($body['rating']);
    if ($rating === null || $rating < 1 || $rating > 5) {
        json_error('VALIDATION_ERROR', 'rating must be an integer between 1 and 5.', 422);
    }
}

// hourly_rate: D16 bcmath string; must be >= 0
$hourlyRate = null;
if (isset($body['hourly_rate']) && $body['hourly_rate'] !== null && $body['hourly_rate'] !== '') {
    $hourlyRate = clean_decimal($body['hourly_rate']);
    if ($hourlyRate === null) {
        json_error('VALIDATION_ERROR', 'hourly_rate must be a valid decimal number.', 422);
    }
    // WHY: a negative hourly rate is nonsensical — reject it server-side
    if (bccomp($hourlyRate, '0', 6) < 0) {
        json_error('VALIDATION_ERROR', 'hourly_rate must be a non-negative number.', 422);
    }
}

// specializations: JSON array of strings
$specializations = null;
if (isset($body['specializations']) && is_array($body['specializations'])) {
    $specs = [];
    foreach ($body['specializations'] as $s) {
        $cleaned = clean_string((string)$s, 100);
        if ($cleaned) {
            $specs[] = $cleaned;
        }
    }
    $specializations = !empty($specs) ? json_encode($specs) : null;
}

// -----------------------------------------------------------------------
// 5. Insert inside transaction + audit log
// -----------------------------------------------------------------------
$newId = db_transaction(function() use (
    $name, $vendorType, $contactName, $email, $phone,
    $address, $city, $state, $specializations,
    $hourlyRate, $rating, $notes, $isPreferred
) {
    $id = db_insert('vendors', [
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
        'total_spent'     => '0.00',
        'created_by'      => current_user_id(),
    ]);

    // §7 audit log — actual column names (not reference template)
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'maintenance',
        'entity_type'  => 'vendor',
        'entity_id'    => $id,
        'entity_label' => $name,
        'new_values'   => json_encode(['name' => $name, 'vendor_type' => $vendorType]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

json_success(['id' => $newId, 'name' => $name, 'vendor_type' => $vendorType], 201);
