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
$body   = json_body();
$fields = [];

// -----------------------------------------------------------------------
// 2. Validate every field — VALID-2: accumulate, never fail-fast.
//    All errors are collected into $fields and emitted via
//    json_validation_error() so the client can highlight every
//    offending input on a single response.
// -----------------------------------------------------------------------

// name
$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    $fields['name'] = 'Vendor name is required.';
}

// vendor_type
$vendorType = clean_string($body['vendor_type'] ?? null);
$validTypes = ['maintenance', 'repair', 'parts', 'inspection', 'towing', 'other'];
if (!$vendorType) {
    $fields['vendor_type'] = 'Vendor type is required.';
} elseif (!in_array($vendorType, $validTypes, true)) {
    $fields['vendor_type'] = 'Vendor type must be one of: ' . implode(', ', $validTypes) . '.';
}

// optional scalars
$contactName = clean_string($body['contact_name'] ?? null, 255);
$phone       = clean_string($body['phone'] ?? null, 50);
$address     = clean_string($body['address'] ?? null, 500);
$city        = clean_string($body['city'] ?? null, 100);
$state       = clean_string($body['state'] ?? null, 100);
$notes       = clean_string($body['notes'] ?? null, 5000);
$isPreferred = isset($body['is_preferred']) ? (int)(bool)$body['is_preferred'] : 0;

// S-VENDOR-CURRENCY-COLUMN: currency ENUM('CAD','USD') default 'CAD'.
// Matches the customer create.php pattern (validates, defaults, persists).
// VendorPusher reads $ff['currency'] post-S-VENDOR-CURRENCY-COLUMN to emit
// the QBO CurrencyRef (was hardcoded 'CAD' per D-QBO-FIXPACK-8 backlog).
$rawCurrency = $body['currency'] ?? 'CAD';
$currency    = in_array($rawCurrency, ['CAD', 'USD'], true) ? $rawCurrency : 'CAD';
if (isset($body['currency']) && !in_array($body['currency'], ['CAD', 'USD'], true)) {
    $fields['currency'] = "Currency must be 'CAD' or 'USD'.";
}

// VALID-2: email format check (only when provided)
$email     = null;
$rawEmail  = $body['email'] ?? null;
if ($rawEmail !== null && $rawEmail !== '') {
    $email = clean_email($rawEmail);
    if ($email === null) {
        $fields['email'] = 'Please enter a valid email address.';
    }
}

// rating: 1–5 or null
$rating = null;
if (isset($body['rating']) && $body['rating'] !== null && $body['rating'] !== '') {
    $rating = clean_int($body['rating']);
    if ($rating === null || $rating < 1 || $rating > 5) {
        $fields['rating'] = 'Rating must be an integer between 1 and 5.';
    }
}

// hourly_rate: D16 bcmath string; must be >= 0
$hourlyRate = null;
if (isset($body['hourly_rate']) && $body['hourly_rate'] !== null && $body['hourly_rate'] !== '') {
    $hourlyRate = clean_decimal($body['hourly_rate']);
    if ($hourlyRate === null) {
        $fields['hourly_rate'] = 'Hourly rate must be a valid decimal number.';
    } elseif (bccomp($hourlyRate, '0', 6) < 0) {
        // WHY: a negative hourly rate is nonsensical
        $fields['hourly_rate'] = 'Hourly rate must be a non-negative number.';
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

// Short-circuit before duplicate lookup if anything failed above
if ($fields) {
    json_validation_error($fields);
}

// -----------------------------------------------------------------------
// 3. Uniqueness check — name must be unique among active vendors.
//    VALID-2: report collisions through the same envelope so the
//    client can attach the message to the offending field.
// -----------------------------------------------------------------------
if (db_exists('vendors', 'name = ? AND deleted_at IS NULL', [$name])) {
    json_validation_error(
        ['name' => 'A vendor with that name already exists.'],
        'A vendor with that name already exists.'
    );
}

// -----------------------------------------------------------------------
// 5. Insert inside transaction + audit log
// -----------------------------------------------------------------------
$newId = db_transaction(function() use (
    $name, $vendorType, $contactName, $email, $phone,
    $address, $city, $state, $specializations,
    $hourlyRate, $rating, $notes, $isPreferred, $currency
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
        'currency'        => $currency,
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

// QBO sync enqueue (S-QBO-7). No-op when sync_enabled='0' (D-CPA-5
// default) or mode rejects pushes. Never throws — sync is best-effort
// and must not break vendor-create flows.
\FleetForge\QboPushers\VendorEnqueuer::enqueue($newId, 'create');

json_success(['id' => $newId, 'name' => $name, 'vendor_type' => $vendorType], 201);
