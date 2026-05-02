<?php
declare(strict_types=1);

/**
 * FleetForge — Yard Update API
 *
 * @file        api/v1/yards/update.php
 * @description Updates an existing yard's details.
 *              Name uniqueness validated excluding current yard (self-check).
 *              D19 optimistic lock via updated_at.
 *
 * @method      POST
 * @body        JSON — id (req), updated_at (req for D19), name (req),
 *              address, city, state, postal_code, capacity, phone, notes,
 *              manager_id, is_active
 * @auth        Session required; manager or super_admin role
 * @returns     200 { id, updated_at } | 409 STALE_DATA | 422 VALIDATION_ERROR | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @session     S018-EXT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
if (!in_array(current_user()['role_slug'] ?? '', ['super_admin', 'manager'])) {
    json_error('FORBIDDEN', 'Insufficient permissions to update yards.', 403);
}

$body        = json_body();
$id          = clean_int($body['id'] ?? null);
$submittedAt = clean_string($body['updated_at'] ?? null, 30);

if (!$id)          json_error('MISSING_REQUIRED', 'id is required.', 422);
if (!$submittedAt) json_error('MISSING_REQUIRED', 'updated_at is required.', 422);

// ── Fetch existing ────────────────────────────────────────────────
$existing = db_row("SELECT id, name, updated_at FROM yards WHERE id = ?", [$id]);
if (!$existing) json_error('NOT_FOUND', 'Yard not found.', 404);

// ── D19 optimistic lock ───────────────────────────────────────────
if (!optimistic_lock_matches($submittedAt, $existing['updated_at'])) {
    json_error('STALE_DATA', 'Yard was modified by another user. Refresh and try again.', 409);
}

// ── Validate ──────────────────────────────────────────────────────
$name = clean_string($body['name'] ?? null, 255);
if (!$name) json_error('VALIDATION_ERROR', 'Yard name is required.', 422,
    ['errors' => ['name' => 'Yard name is required.']]);

// Name uniqueness excluding self
if (db_exists('yards', 'name = ? AND id != ?', [$name, $id])) {
    json_error('ALREADY_EXISTS', "A yard named '{$name}' already exists.", 409);
}

$isActive   = isset($body['is_active']) ? (int)(bool)$body['is_active'] : (int)$existing['is_active'] ?? 1;
$address    = clean_string($body['address']    ?? null, 500);
$city       = clean_string($body['city']       ?? null, 100);
$state      = clean_string($body['state']      ?? null, 100);
$postalCode = clean_string($body['postal_code'] ?? null, 20);
$capacity   = clean_int($body['capacity']      ?? null);
$phone      = clean_string($body['phone']      ?? null, 50);
$notes      = clean_string($body['notes']      ?? null, 65535);
$managerId  = clean_int($body['manager_id']    ?? null) ?: null;

$oldValues = $existing;

db_update('yards', [
    'name'        => $name,
    'address'     => $address,
    'city'        => $city,
    'state'       => $state,
    'postal_code' => $postalCode,
    'capacity'    => $capacity,
    'phone'       => $phone,
    'notes'       => $notes,
    'manager_id'  => $managerId,
    'is_active'   => $isActive,
], 'id = ?', [$id]);

// ── Audit ─────────────────────────────────────────────────────────
db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'update',
    'module'       => 'settings',
    'entity_type'  => 'yard',
    'entity_id'    => $id,
    'entity_label' => $name,
    'notes'        => "Yard '{$name}' updated",
    'old_values'   => json_encode($oldValues),
    'new_values'   => json_encode(['name' => $name, 'is_active' => $isActive, 'city' => $city]),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

$fresh = db_row("SELECT updated_at FROM yards WHERE id = ?", [$id]);
json_success(['id' => $id, 'updated_at' => $fresh['updated_at']]);
