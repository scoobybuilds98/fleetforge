<?php
declare(strict_types=1);

/**
 * FleetForge — Yard Create API
 *
 * @file        api/v1/yards/create.php
 * @description Creates a new yard. Name must be unique (UNIQUE constraint on DB).
 *              Slug is auto-generated from name if not provided (lowercase, hyphens).
 *              New yards are active by default.
 *
 * @method      POST
 * @body        JSON — name (req), address, city, state, postal_code,
 *              capacity, phone, notes, manager_id
 * @auth        Session required; require_permission('settings','edit')
 *              (yards are a configuration-level entity — manager+ can create)
 * @returns     201 { id, name, slug } | 409 ALREADY_EXISTS | 422 VALIDATION_ERROR
 *
 * @depends     api/bootstrap.php
 * @session     S018-EXT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
// Yard management requires manager-level access (settings module or manager role)
if (!can('settings', 'view') && !in_array(current_user()['role_slug'] ?? '', ['super_admin', 'manager', 'dispatcher'])) {
    json_error('FORBIDDEN', 'Insufficient permissions to manage yards.', 403);
}

$body   = json_body();
$fields = [];

// ── Required ── VALID-2: accumulate every error before responding ──
$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    $fields['name'] = 'Yard name is required.';
}

// ── Optional fields ───────────────────────────────────────────────
$address    = clean_string($body['address']    ?? null, 500);
$city       = clean_string($body['city']       ?? null, 100);
$state      = clean_string($body['state']      ?? null, 100);
$postalCode = clean_string($body['postal_code'] ?? null, 20);
$phone      = clean_string($body['phone']      ?? null, 50);
$notes      = clean_string($body['notes']      ?? null, 65535);
$managerId  = clean_int($body['manager_id']    ?? null);

// VALID-2: capacity (if provided) must be a non-negative integer
$capacity = null;
$rawCap   = $body['capacity'] ?? null;
if ($rawCap !== null && $rawCap !== '') {
    $c = clean_int($rawCap);
    if ($c === null || $c < 0) {
        $fields['capacity'] = 'Capacity must be a non-negative whole number.';
    } else {
        $capacity = $c;
    }
}

// Validate manager if provided
if ($managerId && !db_exists('users', 'id = ? AND deleted_at IS NULL', [$managerId])) {
    $fields['manager_id'] = 'Manager user not found.';
}

// Short-circuit on collected validation errors
if ($fields) {
    json_validation_error($fields);
}

// ── Auto-generate slug from name ─────────────────────────────────
$slug = clean_string($body['slug'] ?? null, 100);
if (!$slug) {
    // lowercase, replace non-alphanumeric with hyphens, trim
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 100);
}

// ── Uniqueness check (name + slug) ───────────────────────────────
// VALID-2: report collisions through the standard fields envelope so
// the client can highlight the offending input. (HTTP 422)
// MEDIUM [09]: yards has a GLOBAL unique key on name (not scoped to deleted_at),
// and yard delete is a SOFT delete. Block only on an ACTIVE collision; if a
// SOFT-DELETED yard holds this name, revive it in place below instead of either
// a misleading lockout or a 1062 on the insert (mirrors the C2 customer revive).
if (db_exists('yards', 'name = ? AND deleted_at IS NULL', [$name])) {
    json_validation_error(
        ['name' => "A yard named '{$name}' already exists."],
        "A yard named '{$name}' already exists."
    );
}
$reviveRow = db_row("SELECT id FROM yards WHERE name = ? AND deleted_at IS NOT NULL LIMIT 1", [$name]);
$reviveId  = $reviveRow !== null ? (int) $reviveRow['id'] : null;
// slug check stays GLOBAL (incl. soft-deleted) so a generated slug never 1062s
// against a tombstoned row's slug.
if (db_exists('yards', 'slug = ?', [$slug])) {
    // Append numeric suffix to slug to resolve collision
    $slug = $slug . '-' . time();
}

// ── Insert (or revive a soft-deleted yard with the same name) ──────
$yardData = [
    'name'        => $name,
    'slug'        => $slug,
    'address'     => $address,
    'city'        => $city,
    'state'       => $state,
    'postal_code' => $postalCode,
    'capacity'    => $capacity,
    'phone'       => $phone,
    'notes'       => $notes,
    'manager_id'  => $managerId,
    'is_active'   => 1,
];
try {
    if ($reviveId !== null) {
        db_update('yards', $yardData + ['deleted_at' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$reviveId]);
        $yardId = $reviveId;
    } else {
        $yardId = db_insert('yards', $yardData);
    }
} catch (\PDOException $e) {
    // Belt for the global name/slug unique key (TOCTOU race). Narrow on the key
    // names so the manager_id FK (yards_ibfk_1) / other 23000s still surface.
    if ($e->getCode() === '23000'
        && (stripos($e->getMessage(), 'name') !== false || stripos($e->getMessage(), 'slug') !== false)) {
        json_validation_error(
            ['name' => "A yard named '{$name}' already exists."],
            "A yard named '{$name}' already exists."
        );
    }
    throw $e;
}

// ── Audit ─────────────────────────────────────────────────────────
db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'create',
    'module'       => 'settings',
    'entity_type'  => 'yard',
    'entity_id'    => $yardId,
    'entity_label' => $name,
    'notes'        => "Yard '{$name}' created",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['id' => $yardId, 'name' => $name, 'slug' => $slug], 201);
