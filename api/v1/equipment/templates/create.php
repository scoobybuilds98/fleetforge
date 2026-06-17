<?php
declare(strict_types=1);

/**
 * api/v1/equipment/templates/create.php
 *
 * Creates a new equipment template. The slug is auto-generated from the name
 * and de-duplicated with a numeric suffix if needed. All default rate fields
 * are optional — units can override them individually.
 *
 * @method   POST
 * @body     JSON
 * @required name, category
 * @optional description, brand, model, default_length_ft, default_height_ft,
 *           default_width_ft, default_weight_capacity_lbs, default_wheel_size,
 *           default_tire_size, default_axle_count, default_ownership_type,
 *           default_yard_location, default_tracking_provider,
 *           default_cvi_interval_days, default_mvi_interval_days,
 *           default_registration_interval_days, default_insurance_interval_days,
 *           default_daily_rate, default_weekly_rate, default_monthly_rate,
 *           default_mileage_rate, default_currency, default_mileage_unit,
 *           default_notes, default_inspection_notes,
 *           is_active (bool, default true), sort_order (int, default 0)
 * @auth     Session required; require_permission('equipment','create')
 * @returns  201 { id, name, slug }
 *
 * @depends  api/bootstrap.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.3 Equipment Templates
 * @session  S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'create');

$body   = json_body();
$fields = [];

// ── Required fields — VALID-2: accumulate, single 422 response ──
$name = clean_string($body['name'] ?? null, 100);
if (!$name) {
    $fields['name'] = 'Template name is required.';
}

$validCategories = ['chassis','dry_van','reefer','container','flatbed',
                    'step_deck','lowboy','tanker','dump','combo','other'];
$category = clean_string($body['category'] ?? null);
if (!$category) {
    $fields['category'] = 'Please select a category.';
} elseif (!in_array($category, $validCategories, true)) {
    $fields['category'] = 'Please select a valid category.';
}

// Helper: check decimal >= 0 with specific message
$checkNonNegDecimal = function ($raw, string $fieldName, string $label) use (&$fields) {
    if ($raw === null || $raw === '') return null;
    $d = clean_decimal($raw);
    if ($d === null || bccomp($d, '0', 4) < 0) {
        $fields[$fieldName] = "{$label} cannot be negative.";
        return null;
    }
    return $d;
};

// Helper: check decimal > 0 with specific message
$checkPosDecimal = function ($raw, string $fieldName, string $label) use (&$fields) {
    if ($raw === null || $raw === '') return null;
    $d = clean_decimal($raw);
    if ($d === null || bccomp($d, '0', 4) <= 0) {
        $fields[$fieldName] = "{$label} must be greater than zero.";
        return null;
    }
    return $d;
};

// Helper: check int > 0
$checkPosInt = function ($raw, string $fieldName, string $label) use (&$fields) {
    if ($raw === null || $raw === '') return null;
    $i = clean_int($raw);
    if ($i === null || $i <= 0) {
        $fields[$fieldName] = "{$label} must be greater than zero.";
        return null;
    }
    return $i;
};

// ── Optional fields ────────────────────────────────────────────
$description   = clean_string($body['description'] ?? null, 5000);
$brand         = clean_string($body['brand'] ?? null, 100);
$model         = clean_string($body['model'] ?? null, 100);
$lengthFt      = $checkPosDecimal($body['default_length_ft']           ?? null, 'default_length_ft',           'Length');
$heightFt      = $checkPosDecimal($body['default_height_ft']           ?? null, 'default_height_ft',           'Height');
$widthFt       = $checkPosDecimal($body['default_width_ft']            ?? null, 'default_width_ft',            'Width');
$weightCap     = $checkPosInt   ($body['default_weight_capacity_lbs']  ?? null, 'default_weight_capacity_lbs', 'Weight capacity');
$wheelSize     = clean_string($body['default_wheel_size'] ?? null, 50);
$tireSize      = clean_string($body['default_tire_size'] ?? null, 50);
$axleCount     = $checkPosInt   ($body['default_axle_count']           ?? null, 'default_axle_count',          'Axle count');
$yardLocation  = clean_string($body['default_yard_location'] ?? null, 100);
$notes         = clean_string($body['default_notes'] ?? null, 5000);
$inspNotes     = clean_string($body['default_inspection_notes'] ?? null, 5000);
$isActive      = isset($body['is_active']) ? (bool) $body['is_active'] : true;

// sort_order must be >= 0
$sortOrder = 0;
if (isset($body['sort_order']) && $body['sort_order'] !== '' && $body['sort_order'] !== null) {
    $i = clean_int($body['sort_order']);
    if ($i === null || $i < 0) {
        $fields['sort_order'] = 'Sort order cannot be negative.';
    } else {
        $sortOrder = $i;
    }
}

// Ownership type
$validOwnership = ['owned','leased','brokered'];
$rawOwnership   = $body['default_ownership_type'] ?? null;
$ownershipType  = ($rawOwnership && in_array($rawOwnership, $validOwnership, true)) ? $rawOwnership : null;

// Tracking provider
$rawTracking = $body['default_tracking_provider'] ?? 'none';
$trackingProvider = in_array($rawTracking, ['samsara','none'], true) ? $rawTracking : 'none';

// Interval fields — must be > 0
$cviInterval  = $checkPosInt($body['default_cvi_interval_days']          ?? null, 'default_cvi_interval_days',          'CVI interval');
$mviInterval  = $checkPosInt($body['default_mvi_interval_days']          ?? null, 'default_mvi_interval_days',          'MVI interval');
$regInterval  = $checkPosInt($body['default_registration_interval_days'] ?? null, 'default_registration_interval_days', 'Registration interval');
$insInterval  = $checkPosInt($body['default_insurance_interval_days']    ?? null, 'default_insurance_interval_days',    'Insurance interval');

// Rate fields — VALID-2: spec-exact messages "Daily/Weekly/Monthly/Mileage rate cannot be negative"
$dailyRate    = $checkNonNegDecimal($body['default_daily_rate']   ?? null, 'default_daily_rate',   'Daily rate');
$weeklyRate   = $checkNonNegDecimal($body['default_weekly_rate']  ?? null, 'default_weekly_rate',  'Weekly rate');
$monthlyRate  = $checkNonNegDecimal($body['default_monthly_rate'] ?? null, 'default_monthly_rate', 'Monthly rate');
$mileageRate  = $checkNonNegDecimal($body['default_mileage_rate'] ?? null, 'default_mileage_rate', 'Mileage rate');
// default_mileage_rate is NOT NULL DEFAULT '0.0000' (unlike daily/weekly/monthly
// which are nullable = "tier not configured"). $checkNonNegDecimal returns null
// when the field is omitted, and inserting an explicit NULL into the NOT-NULL
// column throws 1048 → HTTP 500. Fall back to the column default so an omitted
// mileage rate behaves as "0", not a fatal. (Invalid values still set a field
// error above and are rejected before the insert.)
if ($mileageRate === null && !isset($fields['default_mileage_rate'])) {
    $mileageRate = '0.0000';
}

$rawCurrency  = $body['default_currency'] ?? 'CAD';
$currency     = in_array($rawCurrency, ['CAD','USD'], true) ? $rawCurrency : 'CAD';
$rawMileage   = $body['default_mileage_unit'] ?? 'km';
$mileageUnit  = in_array($rawMileage, ['km','miles'], true) ? $rawMileage : 'km';

// ── D132 / I3 default rate-tier completeness ───────────────────
// Templates use NULL to mean "tier not configured". When any default tier is
// > 0, all three (daily/weekly/monthly) must be > 0 — a NULL or 0 tier
// alongside a positive sibling is the rate-tier hole. A template with that
// hole seeds leases with the same hole, which breaks holistic partial-period
// billing (computes $0 base_rental). Mirrors leases/create.php (D132) and the
// _smoke_billing_invariants I3 guard. A template with all tiers unset (all
// NULL) is fine — "no rates configured" is a separate, valid state.
$tplTiers = [
    'default_daily_rate'   => $dailyRate,
    'default_weekly_rate'  => $weeklyRate,
    'default_monthly_rate' => $monthlyRate,
];
$anyTierPositive = false;
foreach ($tplTiers as $v) {
    if ($v !== null && bccomp((string) $v, '0', 4) > 0) { $anyTierPositive = true; break; }
}
if ($anyTierPositive) {
    foreach ($tplTiers as $field => $v) {
        if (!isset($fields[$field]) && ($v === null || bccomp((string) $v, '0', 4) <= 0)) {
            $fields[$field] = 'Must be greater than zero when other default rate tiers are set (D132). Configure all three tiers, or leave all unset.';
        }
    }
}

// ── Duplicate name check ───────────────────────────────────────
if ($name && db_exists('equipment_templates', 'name = ? AND deleted_at IS NULL', [$name])) {
    $fields['name'] = 'A template with this name already exists.';
}

if ($fields) {
    json_validation_error($fields);
}

// ── Generate unique slug ───────────────────────────────────────
// WHY: slug is used for URL-friendly references — must be unique
// FIX #39: guard against redeclaration if file is somehow included more than once
if (!function_exists('make_template_slug')) {
    function make_template_slug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}

$baseSlug = make_template_slug($name);
$slug     = $baseSlug;
$suffix   = 2;
// The slug UNIQUE index is GLOBAL (spans soft-deleted rows), so the dedup loop
// must count ALL rows. db_exists() would auto-append "AND deleted_at IS NULL"
// and miss a soft-deleted template still holding the slug, letting the INSERT
// 1062 → HTTP 500 when an archived template's name is reused (FLEETFORGE-P class).
while (db_count("SELECT COUNT(*) FROM equipment_templates WHERE slug = ?", [$slug]) > 0) {
    $slug = $baseSlug . '-' . $suffix;
    $suffix++;
}

$userId = current_user_id();

// ── Insert ─────────────────────────────────────────────────────
$newId = null;

// Belt-and-suspenders for the slug-collision race: translate a 1062 on the slug
// UNIQUE index into the same 422 the name pre-check returns, instead of an
// uncaught PDOException → HTTP 500. Narrow on 'slug' so FK/other 23000s surface.
try {
db_transaction(function () use (
    &$newId, $userId,
    $name, $slug, $description, $category, $brand, $model,
    $lengthFt, $heightFt, $widthFt, $weightCap, $wheelSize, $tireSize, $axleCount,
    $ownershipType, $yardLocation, $trackingProvider,
    $cviInterval, $mviInterval, $regInterval, $insInterval,
    $dailyRate, $weeklyRate, $monthlyRate, $mileageRate, $currency, $mileageUnit,
    $notes, $inspNotes, $isActive, $sortOrder
): void {
    $newId = db_insert('equipment_templates', [
        'name'                               => $name,
        'slug'                               => $slug,
        'description'                        => $description,
        'category'                           => $category,
        'brand'                              => $brand,
        'model'                              => $model,
        'default_length_ft'                  => $lengthFt,
        'default_height_ft'                  => $heightFt,
        'default_width_ft'                   => $widthFt,
        'default_weight_capacity_lbs'        => $weightCap,
        'default_wheel_size'                 => $wheelSize,
        'default_tire_size'                  => $tireSize,
        'default_axle_count'                 => $axleCount,
        'default_ownership_type'             => $ownershipType,
        'default_yard_location'              => $yardLocation,
        'default_tracking_provider'          => $trackingProvider,
        'default_cvi_interval_days'          => $cviInterval,
        'default_mvi_interval_days'          => $mviInterval,
        'default_registration_interval_days' => $regInterval,
        'default_insurance_interval_days'    => $insInterval,
        'default_daily_rate'                 => $dailyRate,
        'default_weekly_rate'                => $weeklyRate,
        'default_monthly_rate'               => $monthlyRate,
        'default_mileage_rate'               => $mileageRate,
        'default_currency'                   => $currency,
        'default_mileage_unit'               => $mileageUnit,
        'default_notes'                      => $notes,
        'default_inspection_notes'           => $inspNotes,
        'is_active'                          => $isActive ? 1 : 0,
        'sort_order'                         => $sortOrder,
        'created_by'                         => $userId,
    ]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_template',
        'entity_id'    => $newId,
        'entity_label' => $name,
        'new_values'   => json_encode(['name' => $name, 'category' => $category]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});
} catch (\PDOException $e) {
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'slug') !== false) {
        json_validation_error(['name' => 'A template with this name already exists.']);
    }
    throw $e;
}

json_success(['id' => $newId, 'name' => $name, 'slug' => $slug], 201);
