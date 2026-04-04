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

$body = json_body();

// ── Required ────────────────────────────────────────────────────
$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    json_error('VALIDATION_ERROR', 'Yard name is required.', 422,
        ['errors' => ['name' => 'Yard name is required.']]);
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
if (db_exists('yards', 'name = ?', [$name])) {
    json_error('ALREADY_EXISTS', "A yard named '{$name}' already exists.", 409);
}
if (db_exists('yards', 'slug = ?', [$slug])) {
    // Append numeric suffix to slug to resolve collision
    $slug = $slug . '-' . time();
}

// ── Optional fields ───────────────────────────────────────────────
$address    = clean_string($body['address']    ?? null, 500);
$city       = clean_string($body['city']       ?? null, 100);
$state      = clean_string($body['state']      ?? null, 100);
$postalCode = clean_string($body['postal_code'] ?? null, 20);
$capacity   = clean_int($body['capacity']      ?? null);
$phone      = clean_string($body['phone']      ?? null, 50);
$notes      = clean_string($body['notes']      ?? null, 65535);
$managerId  = clean_int($body['manager_id']    ?? null);

// Validate manager if provided
if ($managerId && !db_exists('users', 'id = ? AND deleted_at IS NULL', [$managerId])) {
    json_error('NOT_FOUND', 'Manager user not found.', 404);
}

// ── Insert ────────────────────────────────────────────────────────
$yardId = db_insert('yards', [
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
]);

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
