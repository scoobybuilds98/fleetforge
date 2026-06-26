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

$body   = json_body();
$fields = [];

$id         = clean_int($body['id'] ?? null);
$updatedAt  = clean_string($body['updated_at'] ?? null);

if (!$id)        $fields['id']         = 'Template ID is required.';
if (!$updatedAt) $fields['updated_at'] = 'Optimistic lock token is required.';

if ($fields) {
    json_validation_error($fields);
}

// ── Fetch existing record ──────────────────────────────────────
$existing = db_row(
    "SELECT id, name, updated_at, category, category_id, subcategory_id
       FROM equipment_templates WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Equipment template not found.', 404);
}

// ── D19: Optimistic lock check ─────────────────────────────────
// WHY: prevents last-write-wins race when two users edit simultaneously
if (!optimistic_lock_matches($updatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
        'This template was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This template was modified by another user. Refresh and try again.']]);
}

// ── Collect updated fields — VALID-2: accumulate every error ───
$updates = [];
$fields  = [];

if (isset($body['name'])) {
    $name = clean_string($body['name'], 100);
    if (!$name) {
        $fields['name'] = 'Template name is required.';
    } else {
        $conflict = db_row(
            "SELECT id FROM equipment_templates WHERE name = ? AND id != ? AND deleted_at IS NULL",
            [$name, $id]
        );
        if ($conflict) {
            $fields['name'] = 'A template with this name already exists.';
        } else {
            $updates['name'] = $name;

            if (!function_exists('make_template_slug')) {
                function make_template_slug(string $n): string {
                    $s = strtolower(trim($n));
                    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
                    return trim($s, '-');
                }
            }
            $baseSlug = make_template_slug($name);
            $newSlug  = $baseSlug;
            $suffix   = 2;
            // slug UNIQUE index is GLOBAL (spans soft-deleted rows) — count ALL
            // rows except self so a slug held by a soft-deleted sibling is seen;
            // db_exists would hide it and the db_update would 1062 → HTTP 500.
            while (db_count("SELECT COUNT(*) FROM equipment_templates WHERE slug = ? AND id != ?", [$newSlug, $id]) > 0) {
                $newSlug = $baseSlug . '-' . $suffix;
                $suffix++;
            }
            $updates['slug'] = $newSlug;
        }
    }
}

// ── S-EQTAX: two-level taxonomy on edit. Accept category_id (+ optional
// subcategory_id) from the new form; fall back to the legacy `category` slug for
// back-compat callers. The legacy `category` mirror is only RECOMPUTED when the
// classification actually changes, so an unrelated edit never shifts an existing
// template's report line or rate-card matching.
$catTouched = array_key_exists('category_id', $body)
           || array_key_exists('subcategory_id', $body)
           || array_key_exists('category', $body);
if ($catTouched) {
    $categoryId    = clean_int($body['category_id'] ?? null);
    $subcategoryId = clean_int($body['subcategory_id'] ?? null);
    $legacyCatSlug = array_key_exists('category', $body) ? clean_string($body['category'], 50) : null;

    $categoryRow = null;
    if ($categoryId) {
        $categoryRow = db_row("SELECT id, slug FROM equipment_categories WHERE id = ? AND deleted_at IS NULL", [$categoryId]);
        if (!$categoryRow) { $fields['category_id'] = 'Please select a valid category.'; }
    } elseif ($legacyCatSlug !== null && $legacyCatSlug !== '') {
        $categoryRow = db_row("SELECT id, slug FROM equipment_categories WHERE slug = ? AND deleted_at IS NULL", [$legacyCatSlug]);
        if (!$categoryRow) { $fields['category'] = 'Please select a valid category.'; }
    } elseif ($existing['category_id']) {
        // Only a subcategory change was sent — keep the current category.
        $categoryRow = db_row("SELECT id, slug FROM equipment_categories WHERE id = ?", [(int) $existing['category_id']]);
    }

    $subcategoryRow = null;
    if ($subcategoryId && $categoryRow) {
        $subcategoryRow = db_row(
            "SELECT id, slug FROM equipment_subcategories WHERE id = ? AND category_id = ? AND deleted_at IS NULL",
            [$subcategoryId, (int) $categoryRow['id']]
        );
        if (!$subcategoryRow) { $fields['subcategory_id'] = 'Please select a valid sub-category for this category.'; }
    }

    if ($categoryRow && !isset($fields['category_id']) && !isset($fields['category']) && !isset($fields['subcategory_id'])) {
        $newCatId = (int) $categoryRow['id'];
        $newSubId = $subcategoryRow ? (int) $subcategoryRow['id'] : null;
        $updates['category_id']    = $newCatId;
        $updates['subcategory_id'] = $newSubId;
        $oldCatId = $existing['category_id'] !== null ? (int) $existing['category_id'] : null;
        $oldSubId = $existing['subcategory_id'] !== null ? (int) $existing['subcategory_id'] : null;
        if ($newCatId !== $oldCatId || $newSubId !== $oldSubId) {
            $updates['category'] = (string) ($subcategoryRow['slug'] ?? $categoryRow['slug']);
        }
    }
}

$stringFields = ['description','brand','model','default_wheel_size','default_tire_size',
                 'default_yard_location','default_notes','default_inspection_notes'];
foreach ($stringFields as $field) {
    if (array_key_exists($field, $body)) {
        $updates[$field] = clean_string($body[$field], $field === 'description' || str_ends_with($field, '_notes') ? 5000 : 100);
    }
}

// VALID-2: dimensions must be > 0 with specific labels
$dimLabels = [
    'default_length_ft' => 'Length',
    'default_height_ft' => 'Height',
    'default_width_ft'  => 'Width',
];
foreach ($dimLabels as $field => $label) {
    if (array_key_exists($field, $body)) {
        $raw = $body[$field];
        if ($raw === null || $raw === '') {
            $updates[$field] = null;
        } else {
            $d = clean_decimal($raw);
            if ($d === null || bccomp($d, '0', 4) <= 0) {
                $fields[$field] = "{$label} must be greater than zero.";
            } else {
                $updates[$field] = $d;
            }
        }
    }
}

// VALID-2: rates must be >= 0 — spec-exact messages
$rateLabels = [
    'default_daily_rate'   => 'Daily rate',
    'default_weekly_rate'  => 'Weekly rate',
    'default_monthly_rate' => 'Monthly rate',
    'default_mileage_rate' => 'Mileage rate',
];
foreach ($rateLabels as $field => $label) {
    if (array_key_exists($field, $body)) {
        $raw = $body[$field];
        if ($raw === null || $raw === '') {
            $updates[$field] = null;
        } else {
            $d = clean_decimal($raw);
            if ($d === null || bccomp($d, '0', 4) < 0) {
                $fields[$field] = "{$label} cannot be negative.";
            } else {
                $updates[$field] = $d;
            }
        }
    }
}

// VALID-2: weight_capacity_lbs and axle_count > 0
$posIntLabels = [
    'default_weight_capacity_lbs' => 'Weight capacity',
    'default_axle_count'          => 'Axle count',
];
foreach ($posIntLabels as $field => $label) {
    if (array_key_exists($field, $body)) {
        $raw = $body[$field];
        if ($raw === null || $raw === '') {
            $updates[$field] = null;
        } else {
            $i = clean_int($raw);
            if ($i === null || $i <= 0) {
                $fields[$field] = "{$label} must be greater than zero.";
            } else {
                $updates[$field] = $i;
            }
        }
    }
}

// VALID-2: interval days must be > 0
$intervalLabels = [
    'default_cvi_interval_days'          => 'CVI interval',
    'default_mvi_interval_days'          => 'MVI interval',
    'default_registration_interval_days' => 'Registration interval',
    'default_insurance_interval_days'    => 'Insurance interval',
];
foreach ($intervalLabels as $field => $label) {
    if (array_key_exists($field, $body)) {
        $raw = $body[$field];
        if ($raw === null || $raw === '') {
            $updates[$field] = null;
        } else {
            $i = clean_int($raw);
            if ($i === null || $i <= 0) {
                $fields[$field] = "{$label} must be greater than zero.";
            } else {
                $updates[$field] = $i;
            }
        }
    }
}

// sort_order >= 0
if (array_key_exists('sort_order', $body)) {
    $raw = $body['sort_order'];
    if ($raw === null || $raw === '') {
        $updates['sort_order'] = 0;
    } else {
        $i = clean_int($raw);
        if ($i === null || $i < 0) {
            $fields['sort_order'] = 'Sort order cannot be negative.';
        } else {
            $updates['sort_order'] = $i;
        }
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

// ── D132 / I3 default rate-tier completeness (partial-update aware) ──
// Compute the EFFECTIVE post-update tiers: the provided value when this request
// touched the field, else the current DB value. When any effective tier is > 0,
// all three (daily/weekly/monthly) must be > 0 — a NULL or 0 tier alongside a
// positive sibling is the rate-tier hole. Mirrors templates/create.php and the
// lease guards (D132); stops an edit from re-opening the hole that breaks
// holistic partial-period billing on leases seeded from this template. Skipped
// when the request touched no tier field (nothing could newly create a hole).
$tierCols = ['default_daily_rate', 'default_weekly_rate', 'default_monthly_rate'];
$touchesTier = false;
foreach ($tierCols as $col) {
    if (array_key_exists($col, $body)) { $touchesTier = true; break; }
}
if ($touchesTier) {
    $cur = db_row(
        "SELECT default_daily_rate, default_weekly_rate, default_monthly_rate
           FROM equipment_templates WHERE id = ?",
        [$id]
    );
    $effective = [];
    foreach ($tierCols as $col) {
        $effective[$col] = array_key_exists($col, $updates) ? $updates[$col] : ($cur[$col] ?? null);
    }
    $anyTierPositive = false;
    foreach ($effective as $v) {
        if ($v !== null && bccomp((string) $v, '0', 4) > 0) { $anyTierPositive = true; break; }
    }
    if ($anyTierPositive) {
        foreach ($effective as $col => $v) {
            if (!isset($fields[$col]) && ($v === null || bccomp((string) $v, '0', 4) <= 0)) {
                $fields[$col] = 'Must be greater than zero when other default rate tiers are set (D132). Configure all three tiers, or leave all unset.';
            }
        }
    }
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($updates)) {
    json_validation_error([], 'No fields provided to update.');
}

// ── Update ─────────────────────────────────────────────────────
$userId = current_user_id();
$newUpdatedAt = null;

// Belt-and-suspenders: translate a 1062 on the slug UNIQUE index (concurrent
// rename, or a slug held by a soft-deleted template) into the same 422 the name
// pre-check returns, instead of an uncaught PDOException → HTTP 500.
try {
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
} catch (\PDOException $e) {
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'slug') !== false) {
        json_validation_error(['name' => 'A template with this name already exists.']);
    }
    throw $e;
}

json_success(['id' => $id, 'updated_at' => $newUpdatedAt]);
