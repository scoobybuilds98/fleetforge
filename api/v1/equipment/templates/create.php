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

$body = json_body();

// ── Required fields ────────────────────────────────────────────
$name = clean_string($body['name'] ?? null, 100);
if (!$name) {
    json_error('VALIDATION_ERROR', 'name is required.', 422);
}

$validCategories = ['chassis','dry_van','reefer','container','flatbed',
                    'step_deck','lowboy','tanker','dump','other'];
$category = clean_string($body['category'] ?? null);
if (!$category || !in_array($category, $validCategories, true)) {
    json_error('VALIDATION_ERROR', 'category must be one of: ' . implode(', ', $validCategories), 422);
}

// ── Optional fields ────────────────────────────────────────────
$description   = clean_string($body['description'] ?? null, 5000);
$brand         = clean_string($body['brand'] ?? null, 100);
$model         = clean_string($body['model'] ?? null, 100);
$lengthFt      = isset($body['default_length_ft']) ? clean_decimal($body['default_length_ft']) : null;
$heightFt      = isset($body['default_height_ft']) ? clean_decimal($body['default_height_ft']) : null;
$widthFt       = isset($body['default_width_ft'])  ? clean_decimal($body['default_width_ft'])  : null;
$weightCap     = clean_int($body['default_weight_capacity_lbs'] ?? null);
$wheelSize     = clean_string($body['default_wheel_size'] ?? null, 50);
$tireSize      = clean_string($body['default_tire_size'] ?? null, 50);
$axleCount     = clean_int($body['default_axle_count'] ?? null);
$yardLocation  = clean_string($body['default_yard_location'] ?? null, 100);
$notes         = clean_string($body['default_notes'] ?? null, 5000);
$inspNotes     = clean_string($body['default_inspection_notes'] ?? null, 5000);
$isActive      = isset($body['is_active']) ? (bool) $body['is_active'] : true;
$sortOrder     = clean_int($body['sort_order'] ?? null) ?? 0;

// Ownership type
$validOwnership = ['owned','leased','brokered'];
$rawOwnership   = $body['default_ownership_type'] ?? null;
$ownershipType  = ($rawOwnership && in_array($rawOwnership, $validOwnership, true)) ? $rawOwnership : null;

// Tracking provider
$rawTracking = $body['default_tracking_provider'] ?? 'none';
$trackingProvider = in_array($rawTracking, ['samsara','none'], true) ? $rawTracking : 'none';

// Interval fields (smallint unsigned)
$cviInterval  = clean_int($body['default_cvi_interval_days'] ?? null);
$mviInterval  = clean_int($body['default_mvi_interval_days'] ?? null);
$regInterval  = clean_int($body['default_registration_interval_days'] ?? null);
$insInterval  = clean_int($body['default_insurance_interval_days'] ?? null);

// Rate fields (bcmath strings — D16)
$dailyRate    = isset($body['default_daily_rate'])   ? clean_decimal($body['default_daily_rate'])   : null;
$weeklyRate   = isset($body['default_weekly_rate'])  ? clean_decimal($body['default_weekly_rate'])  : null;
$monthlyRate  = isset($body['default_monthly_rate']) ? clean_decimal($body['default_monthly_rate']) : null;
$mileageRate  = isset($body['default_mileage_rate']) ? clean_decimal($body['default_mileage_rate']) : null;

$rawCurrency  = $body['default_currency'] ?? 'CAD';
$currency     = in_array($rawCurrency, ['CAD','USD'], true) ? $rawCurrency : 'CAD';
$rawMileage   = $body['default_mileage_unit'] ?? 'km';
$mileageUnit  = in_array($rawMileage, ['km','miles'], true) ? $rawMileage : 'km';

// ── Duplicate name check ───────────────────────────────────────
if (db_exists('equipment_templates', 'name = ? AND deleted_at IS NULL', [$name])) {
    json_error('ALREADY_EXISTS', 'A template with this name already exists.', 409);
}

// ── Generate unique slug ───────────────────────────────────────
// WHY: slug is used for URL-friendly references — must be unique
function make_template_slug(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

$baseSlug = make_template_slug($name);
$slug     = $baseSlug;
$suffix   = 2;
while (db_exists('equipment_templates', 'slug = ?', [$slug])) {
    $slug = $baseSlug . '-' . $suffix;
    $suffix++;
}

$userId = current_user_id();

// ── Insert ─────────────────────────────────────────────────────
$newId = null;

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

json_success(['id' => $newId, 'name' => $name, 'slug' => $slug], 201);
