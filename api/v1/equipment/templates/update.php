<?php
declare(strict_types=1);

/**
 * api/v1/equipment/templates/update.php
 *
 * Updates an equipment template. Applies D19 optimistic locking — the caller
 * must supply the updated_at value from when they loaded the record. If another
 * user modified it in the meantime, returns 409 STALE_DATA.
 *
 * @method   POST
 * @body     JSON
 * @required id, updated_at
 * @optional name, description, category, brand, model, default_length_ft,
 *           default_height_ft, default_width_ft, default_weight_capacity_lbs,
 *           default_wheel_size, default_tire_size, default_axle_count,
 *           default_ownership_type, default_yard_location, default_tracking_provider,
 *           default_cvi_interval_days, default_mvi_interval_days,
 *           default_registration_interval_days, default_insurance_interval_days,
 *           default_daily_rate, default_weekly_rate, default_monthly_rate,
 *           default_mileage_rate, default_currency, default_mileage_unit,
 *           default_notes, default_inspection_notes, is_active, sort_order
 * @auth     Session required; require_permission('equipment','edit')
 * @returns  200 { id, name, updated_at } or 409 STALE_DATA or 404 NOT_FOUND
 *
 * @depends  api/bootstrap.php
 * @decisions D19 (optimistic lock on updated_at)
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.3 Equipment Templates
 * @session  S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body = json_body();

$id         = clean_int($body['id'] ?? null);
$updatedAt  = clean_string($body['updated_at'] ?? null);

if (!$id)        json_error('VALIDATION_ERROR', 'id is required.', 422);
if (!$updatedAt) json_error('VALIDATION_ERROR', 'updated_at is required for optimistic locking.', 422);

// ── Fetch existing record ──────────────────────────────────────
$existing = db_row(
    "SELECT id, name, updated_at FROM equipment_templates WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Equipment template not found.', 404);
}

// ── D19: Optimistic lock check ─────────────────────────────────
// WHY: prevents last-write-wins race when two users edit simultaneously
if ($existing['updated_at'] !== $updatedAt) {
    json_error('STALE_DATA', 'This template was modified by another user. Refresh and try again.', 409);
}

// ── Collect updated fields (only what was sent) ────────────────
$updates = [];

if (isset($body['name'])) {
    $name = clean_string($body['name'], 100);
    if (!$name) json_error('VALIDATION_ERROR', 'name cannot be empty.', 422);
    // Check for name conflict with OTHER templates
    $conflict = db_row(
        "SELECT id FROM equipment_templates WHERE name = ? AND id != ? AND deleted_at IS NULL",
        [$name, $id]
    );
    if ($conflict) json_error('ALREADY_EXISTS', 'Another template with this name already exists.', 409);
    $updates['name'] = $name;
}

if (isset($body['category'])) {
    $validCategories = ['chassis','dry_van','reefer','container','flatbed',
                        'step_deck','lowboy','tanker','dump','other'];
    $cat = clean_string($body['category']);
    if (!in_array($cat, $validCategories, true)) {
        json_error('VALIDATION_ERROR', 'Invalid category.', 422);
    }
    $updates['category'] = $cat;
}

$stringFields = ['description','brand','model','default_wheel_size','default_tire_size',
                 'default_yard_location','default_notes','default_inspection_notes'];
foreach ($stringFields as $field) {
    if (array_key_exists($field, $body)) {
        $updates[$field] = clean_string($body[$field], $field === 'description' || str_ends_with($field, '_notes') ? 5000 : 100);
    }
}

$decimalFields = ['default_length_ft','default_height_ft','default_width_ft',
                  'default_daily_rate','default_weekly_rate','default_monthly_rate',
                  'default_mileage_rate'];
foreach ($decimalFields as $field) {
    if (array_key_exists($field, $body)) {
        $updates[$field] = clean_decimal($body[$field]);
    }
}

$intFields = ['default_weight_capacity_lbs','default_axle_count',
              'default_cvi_interval_days','default_mvi_interval_days',
              'default_registration_interval_days','default_insurance_interval_days',
              'sort_order'];
foreach ($intFields as $field) {
    if (array_key_exists($field, $body)) {
        $updates[$field] = clean_int($body[$field]);
    }
}

if (isset($body['default_ownership_type'])) {
    $v = clean_string($body['default_ownership_type']);
    $updates['default_ownership_type'] = in_array($v, ['owned','leased','brokered'], true) ? $v : null;
}
if (isset($body['default_tracking_provider'])) {
    $v = clean_string($body['default_tracking_provider']);
    $updates['default_tracking_provider'] = in_array($v, ['samsara','none'], true) ? $v : 'none';
}
if (isset($body['default_currency'])) {
    $v = clean_string($body['default_currency']);
    $updates['default_currency'] = in_array($v, ['CAD','USD'], true) ? $v : 'CAD';
}
if (isset($body['default_mileage_unit'])) {
    $v = clean_string($body['default_mileage_unit']);
    $updates['default_mileage_unit'] = in_array($v, ['km','miles'], true) ? $v : 'km';
}
if (isset($body['is_active'])) {
    $updates['is_active'] = (bool) $body['is_active'] ? 1 : 0;
}

if (empty($updates)) {
    json_error('VALIDATION_ERROR', 'No fields provided to update.', 422);
}

// ── Update ─────────────────────────────────────────────────────
$userId = current_user_id();
$newUpdatedAt = null;

db_transaction(function () use (&$newUpdatedAt, $id, $updates, $userId, $existing): void {
    db_update('equipment_templates', $updates, 'id = ?', [$id]);

    $newUpdatedAt = db_row(
        "SELECT updated_at FROM equipment_templates WHERE id = ?", [$id]
    )['updated_at'];

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_template',
        'entity_id'    => $id,
        'entity_label' => $existing['name'],
        'old_values'   => json_encode(array_intersect_key(
            ['name' => $existing['name']], $updates
        )),
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'updated_at' => $newUpdatedAt]);
