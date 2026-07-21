<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/update.php
 *
 * Updates an equipment unit. Applies D19 optimistic locking.
 * Status changes are NOT handled here — use the dedicated status-change
 * endpoint (future session) which validates state machine transitions and
 * writes to equipment_status_log. This endpoint handles metadata/spec fields only.
 *
 * @method   POST
 * @body     JSON
 * @required id, updated_at
 * @optional template_id, unit_number, vin, year, gps_device_id, samsara_vehicle_url,
 *           tracking_provider, owner_company_id, yard_location,
 *           length_ft, height_ft, width_ft, weight_capacity_lbs,
 *           wheel_size, tire_size, axle_count, license_plate, license_state,
 *           cvi_expiry, registration_expiry, mvi_expiry, insurance_expiry,
 *           cvi_interval_days, mvi_interval_days, registration_interval_days,
 *           insurance_interval_days, mileage, notes, inspection_notes,
 *           internal_notes, tags, acquired_date, acquisition_cost
 * @auth     Session required; require_permission('equipment','edit')
 * @returns  200 { id, updated_at } or 409 STALE_DATA or 404 NOT_FOUND
 *
 * @depends  api/bootstrap.php
 * @decisions D19 (optimistic lock), D20 (not needed here — no financial state)
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.4
 * @session  S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/lib/GPS/SamsaraClient.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body   = json_body();
$fields = [];

$id        = clean_int($body['id'] ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);

if (!$id)        $fields['id']         = 'Unit ID is required.';
if (!$updatedAt) $fields['updated_at'] = 'Optimistic lock token is required.';

if ($fields) {
    json_validation_error($fields);
}

// ── Fetch existing ─────────────────────────────────────────────
// SAMSARA-3: include samsara_vehicle_id so we can PATCH Samsara after update
$existing = db_row(
    "SELECT id, unit_number, status, updated_at, template_id, brand_id, samsara_vehicle_id, samsara_entity_type
       FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// ── D19: Optimistic lock ───────────────────────────────────────
if (!optimistic_lock_matches($updatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
        'This unit was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This unit was modified by another user. Refresh and try again.']]);
}

// ── Collect updates — VALID-2: accumulate field errors ─────────
$updates = [];
$fields  = [];

if (isset($body['unit_number'])) {
    $un = clean_string($body['unit_number'], 100);
    if (!$un) {
        $fields['unit_number'] = 'Unit number is required.';
    } else {
        // The unit_number UNIQUE index is GLOBAL (spans soft-deleted rows), so
        // check ALL rows except self — a number held only by a soft-deleted unit
        // is still taken and would 1062 on db_update → HTTP 500 (FLEETFORGE-M).
        $conflict = db_count(
            "SELECT COUNT(*) FROM equipment_units WHERE unit_number = ? AND id != ?",
            [$un, $id]
        ) > 0;
        if ($conflict) {
            $fields['unit_number'] = 'Unit number already exists.';
        } else {
            $updates['unit_number'] = $un;
        }
    }
}

// vin: GLOBAL UNIQUE index spanning soft-deleted rows — check ALL rows except
// self, skipping blank (NULL clears the VIN; a unique index allows multi-NULL).
// Handled here rather than in the generic $stringFields loop so a collision
// returns a clean 422 instead of a 1062 → HTTP 500 (FLEETFORGE-M).
if (array_key_exists('vin', $body)) {
    $vinVal = clean_string($body['vin'], 50);
    // Names the conflicting unit so the operator can resolve the real cause
    // (usually a VIN mis-assigned to another unit during import). NULL vin clears
    // the field (a unique index permits multiple NULLs) and never conflicts.
    $vinMsg = ($vinVal !== null) ? vin_conflict_message($vinVal, $id) : null;
    if ($vinMsg !== null) {
        $fields['vin'] = $vinMsg;
    } else {
        $updates['vin'] = $vinVal;
    }
}

// String fields
$stringFields = [
    'gps_device_id'      => 100,
    'samsara_vehicle_url'=> 500,
    'yard_location'      => 100,
    'wheel_size'         => 50,
    'tire_size'          => 50,
    'license_plate'      => 50,
    'license_state'      => 50,
    'notes'              => 5000,
    'inspection_notes'   => 5000,
    'internal_notes'     => 5000,
    'decommission_reason'=> 5000,
];
foreach ($stringFields as $field => $maxLen) {
    if (array_key_exists($field, $body)) {
        $updates[$field] = clean_string($body[$field], $maxLen);
    }
}

// VALID-2: dimensions must be > 0 with specific messages
$dimensionLabels = [
    'length_ft' => 'Length',
    'height_ft' => 'Height',
    'width_ft'  => 'Width',
];
foreach ($dimensionLabels as $f => $label) {
    if (array_key_exists($f, $body)) {
        $raw = $body[$f];
        if ($raw === null || $raw === '') {
            $updates[$f] = null;
        } else {
            $d = clean_decimal($raw);
            if ($d === null || bccomp($d, '0', 4) <= 0) {
                $fields[$f] = "{$label} must be greater than zero.";
            } elseif (bccomp($d, '9999.99', 4) > 0) {
                // length_ft/height_ft/width_ft are DECIMAL(6,2) — max 9999.99 ft.
                // Reject overflow with a clean 422 instead of letting PDO raise
                // SQLSTATE[22003] "Out of range value" (Sentry FLEETFORGE-M).
                $fields[$f] = "{$label} must be 9999.99 ft or less.";
            } else {
                $updates[$f] = $d;
            }
        }
    }
}

// VALID-2: acquisition_cost must be >= 0
if (array_key_exists('acquisition_cost', $body)) {
    $raw = $body['acquisition_cost'];
    if ($raw === null || $raw === '') {
        $updates['acquisition_cost'] = null;
    } else {
        $d = clean_decimal($raw);
        if ($d === null || bccomp($d, '0', 4) < 0) {
            $fields['acquisition_cost'] = 'Acquisition cost cannot be negative.';
        } else {
            $updates['acquisition_cost'] = $d;
        }
    }
}

// VALID-2: year must be between 1900 and current+1
if (array_key_exists('year', $body)) {
    $yearRaw = null;
    if ($body['year'] !== null && $body['year'] !== '') {
        $yi = clean_int($body['year']);
        $currentYear = (int) date('Y');
        $maxYear     = $currentYear + 1;
        if ($yi === null || $yi < 1900 || $yi > $maxYear) {
            $fields['year'] = "Year must be between 1900 and {$maxYear}.";
        } else {
            $yearRaw = $yi;
        }
    }
    if (!isset($fields['year'])) {
        $updates['year'] = $yearRaw;
    }
}

// VALID-2: weight_capacity_lbs and axle_count must be > 0 and within column range.
// Per-field max matches the DB column type so an oversized value returns a clean
// 422 rather than a PDO SQLSTATE[22003] overflow (same root cause as FLEETFORGE-M):
//   weight_capacity_lbs INT UNSIGNED  → 4294967295
//   axle_count          TINYINT UNSIGNED → 255
$positiveIntLabels = [
    'weight_capacity_lbs' => ['Weight capacity', 4294967295],
    'axle_count'          => ['Axle count', 255],
];
foreach ($positiveIntLabels as $f => [$label, $max]) {
    if (array_key_exists($f, $body)) {
        $raw = $body[$f];
        if ($raw === null || $raw === '') {
            $updates[$f] = null;
        } else {
            $i = clean_int($raw);
            if ($i === null || $i <= 0) {
                $fields[$f] = "{$label} must be greater than zero.";
            } elseif ($i > $max) {
                $fields[$f] = "{$label} must be {$max} or less.";
            } else {
                $updates[$f] = $i;
            }
        }
    }
}

// VALID-2: mileage / odometer >= 0
if (array_key_exists('mileage', $body)) {
    $raw = $body['mileage'];
    if ($raw === null || $raw === '') {
        $updates['mileage'] = 0;
    } else {
        $i = clean_int($raw);
        if ($i === null || $i < 0) {
            $fields['mileage'] = 'Odometer cannot be negative.';
        } else {
            $updates['mileage'] = $i;
        }
    }
}

// VALID-2: interval days must be > 0
$intervalLabels = [
    'cvi_interval_days'          => 'CVI interval days',
    'mvi_interval_days'          => 'MVI interval days',
    'registration_interval_days' => 'Registration interval days',
    'insurance_interval_days'    => 'Insurance interval days',
];
foreach ($intervalLabels as $f => $label) {
    if (array_key_exists($f, $body)) {
        $raw = $body[$f];
        if ($raw === null || $raw === '') {
            $updates[$f] = null;
        } else {
            $i = clean_int($raw);
            if ($i === null || $i <= 0) {
                $fields[$f] = "{$label} must be greater than zero.";
            } else {
                $updates[$f] = $i;
            }
        }
    }
}

// Equipment type (template_id) — a live FK; changing it is allowed. The unit's
// stored specs and any existing lease rate/snapshots are independent, so this
// only re-points display joins and the rate source for FUTURE leases. The
// column is NOT NULL with ON DELETE RESTRICT, so the target must resolve to a
// live (non-deleted) template.
if (array_key_exists('template_id', $body)) {
    $tplId = clean_int($body['template_id']);
    if (!$tplId) {
        $fields['template_id'] = 'Please select an equipment type.';
    } elseif ($tplId === (int) $existing['template_id']) {
        // Unchanged — always accepted (no-op write). Don't block editing other
        // fields just because this unit's current type was later deactivated or
        // soft-deleted; keeping the existing type is always valid.
    } elseif (!db_exists('equipment_templates', 'id = ? AND deleted_at IS NULL AND is_active = 1', [$tplId])) {
        // Switching types is only allowed to a live, active template — mirrors
        // the create-time rule (units/create.php requires is_active = 1) and
        // keeps future lease rate lookups (lookup_rates.php) working.
        $fields['template_id'] = 'Selected equipment type is not available.';
    } else {
        $updates['template_id'] = $tplId;
    }
}

// S-UNIT-BRAND: brand_id — nullable FK to equipment_brands. An empty value
// CLEARS the brand ("— No brand —" in the picker), which is a legitimate edit,
// so '' / null / 0 all resolve to NULL rather than a validation error.
//
// Keeping the CURRENT brand is always allowed even if it has since been
// deactivated or soft-deleted — same rule as template_id above. Without that
// carve-out, editing any other field on a unit whose brand was retired would
// be rejected, or worse, silently blank the brand.
if (array_key_exists('brand_id', $body)) {
    $bId = clean_int($body['brand_id']) ?: null;
    if ($bId === null) {
        $updates['brand_id'] = null;
    } elseif ($bId === ($existing['brand_id'] !== null ? (int) $existing['brand_id'] : null)) {
        // Unchanged — no-op, always accepted.
    } elseif (!db_exists('equipment_brands', 'id = ? AND deleted_at IS NULL AND is_active = 1', [$bId])) {
        $fields['brand_id'] = 'That brand is not available. Pick one from the list.';
    } else {
        $updates['brand_id'] = $bId;
    }
}

// owner_company_id: any valid int (FK reference — can be null to clear)
if (array_key_exists('owner_company_id', $body)) {
    $updates['owner_company_id'] = clean_int($body['owner_company_id']);
}

// Date fields
foreach (['cvi_expiry','registration_expiry','mvi_expiry','insurance_expiry',
          'acquired_date','decommissioned_date'] as $f) {
    if (array_key_exists($f, $body)) {
        $updates[$f] = clean_date($body[$f]);
    }
}

// Enum fields
if (isset($body['tracking_provider'])) {
    $v = clean_string($body['tracking_provider']);
    $updates['tracking_provider'] = in_array($v, ['samsara','none'], true) ? $v : 'none';
}
if (isset($body['ownership_type'])) {
    $v = clean_string($body['ownership_type']);
    if (!in_array($v, ['owned','leased','brokered'], true)) {
        $fields['ownership_type'] = 'Please select an ownership type (owned, leased, or brokered).';
    } else {
        $updates['ownership_type'] = $v;
    }
}

// Tags — JSON column
if (array_key_exists('tags', $body)) {
    $tagsInput = is_array($body['tags']) ? $body['tags'] : [];
    $tags      = array_values(array_filter($tagsInput, fn($t) => is_string($t) && $t !== ''));
    $updates['tags'] = !empty($tags) ? json_encode($tags) : null;
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($updates)) {
    json_validation_error([], 'No fields provided to update.');
}

// Add updated_by
$updates['updated_by'] = current_user_id();

$userId = current_user_id();
$newUpdatedAt = null;

// Belt-and-suspenders: translate a 1062 on the vin/unit_number UNIQUE index
// (concurrent rename, or a value held by a soft-deleted unit that slipped past
// the pre-check) into the same clean 422 instead of an uncaught PDOException →
// HTTP 500. Narrow on the key name so other 23000s still surface (FLEETFORGE-M).
try {
db_transaction(function () use (&$newUpdatedAt, $id, $updates, $userId, $existing): void {
    db_update('equipment_units', $updates, 'id = ?', [$id]);

    $newUpdatedAt = db_row(
        "SELECT updated_at FROM equipment_units WHERE id = ?", [$id]
    )['updated_at'];

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $id,
        'entity_label' => $existing['unit_number'],
        'old_values'   => json_encode([
            'unit_number' => $existing['unit_number'],
            'template_id' => $existing['template_id'],
        ]),
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        if (stripos($e->getMessage(), 'vin') !== false) {
            $msg = (isset($vinVal) && $vinVal !== null)
                ? (vin_conflict_message($vinVal, $id) ?? 'VIN already exists.')
                : 'VIN already exists.';
            json_validation_error(['vin' => $msg]);
        }
        if (stripos($e->getMessage(), 'unit_number') !== false) {
            json_validation_error(['unit_number' => 'Unit number already exists.']);
        }
    }
    throw $e;
}

// ── SAMSARA-3: PATCH the Samsara trailer with any changed fields ──
// WHY after the transaction: DB is source of truth. Samsara failure
// is non-blocking — we log it and still return 200.
// Only trailer-type units are Samsara-managed (vehicles need hardware).
// Only sync fields that Samsara cares about (name, vin, year, plate).
if (!empty($existing['samsara_vehicle_id'])
    && ($existing['samsara_entity_type'] ?? 'vehicle') === 'trailer'
) {
    $samsaraFields = array_filter([
        'name'               => $updates['unit_number']   ?? null,
        'vin'                => $updates['vin']            ?? null,
        'year'               => isset($updates['year']) ? (int) $updates['year'] : null,
        'licensePlateNumber' => $updates['license_plate'] ?? null,
    ], fn($v) => $v !== null);

    if (!empty($samsaraFields)) {
        try {
            $samsara = new \FleetForge\GPS\SamsaraClient();
            $samsara->updateTrailer((string) $existing['samsara_vehicle_id'], $samsaraFields);
        } catch (\Throwable) {
            // Samsara sync failure is never blocking — it's already logged by SamsaraClient
        }
    }
}

json_success(['id' => $id, 'updated_at' => $newUpdatedAt]);
