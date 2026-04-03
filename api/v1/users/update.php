<?php
declare(strict_types=1);

/**
 * api/v1/users/update.php
 *
 * Update a user's profile fields.
 *
 * Business rules:
 *   - D19 optimistic lock: submitted updated_at must match the DB value.
 *   - Updatable fields: name, email, phone, timezone, role_id.
 *   - email uniqueness check excludes self (AND id != $id).
 *   - role_id validated against user_roles if provided.
 *   - Audit log: action='update', old_values/new_values captured.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required, D19 lock),
 *               name?, email?, phone?, timezone?, role_id?
 * @auth    Session required; require_permission('users','edit')
 * @returns 200 { id, name, email, updated_at }
 *          404 NOT_FOUND | 409 STALE_DATA | 409 ALREADY_EXISTS
 *
 * Decisions: D5 (soft delete), D7 (routing), D19 (optimistic lock), §7 (audit log)
 * Session: S017
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'edit');

// -----------------------------------------------------------------------
// 1. Parse body + resolve user
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_error('MISSING_REQUIRED', 'updated_at is required for optimistic locking.', 422);
}

// Fetch existing — explicit safe columns only
$existing = db_row(
    "SELECT u.id, u.name, u.email, u.phone, u.timezone, u.role_id,
            u.status, u.updated_at, ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// -----------------------------------------------------------------------
// 2. D19 optimistic lock
// -----------------------------------------------------------------------
if ($existing['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'User was modified by another user. Refresh and try again.', 409);
}

// -----------------------------------------------------------------------
// 3. Validate fields (only those present in body are updated)
// -----------------------------------------------------------------------
$name = isset($body['name']) ? clean_string($body['name'], 255) : $existing['name'];
if (!$name) {
    json_error('VALIDATION_ERROR', 'name cannot be empty.', 422);
}

$email = $existing['email'];
if (isset($body['email'])) {
    $email = clean_email($body['email']);
    if (!$email) {
        json_error('VALIDATION_ERROR', 'A valid email address is required.', 422);
    }
    // Uniqueness check — exclude self
    if ($email !== $existing['email']) {
        if (db_exists('users', 'email = ? AND id != ? AND deleted_at IS NULL', [$email, $id])) {
            json_error('ALREADY_EXISTS', 'A user with that email already exists.', 409);
        }
    }
}

$phone    = isset($body['phone'])    ? clean_string($body['phone'], 50)      : $existing['phone'];
$timezone = isset($body['timezone']) ? clean_string($body['timezone'], 100)  : $existing['timezone'];

$roleId = $existing['role_id'];
if (isset($body['role_id'])) {
    $roleId = clean_int($body['role_id']);
    if (!$roleId) {
        json_error('VALIDATION_ERROR', 'role_id must be a valid integer.', 422);
    }
    // Validate role exists
    if (!db_exists('user_roles', 'id = ?', [$roleId])) {
        json_error('VALIDATION_ERROR', 'Invalid role_id — role does not exist.', 422);
    }
}

// -----------------------------------------------------------------------
// 4. Update inside transaction + audit log
// -----------------------------------------------------------------------
db_transaction(function () use ($id, $name, $email, $phone, $timezone, $roleId, $existing) {
    db_update('users', [
        'name'     => $name,
        'email'    => $email,
        'phone'    => $phone,
        'timezone' => $timezone,
        'role_id'  => $roleId,
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $id,
        'entity_label' => $email,
        'old_values'   => json_encode([
            'name'     => $existing['name'],
            'email'    => $existing['email'],
            'role_id'  => $existing['role_id'],
        ]),
        'new_values'   => json_encode([
            'name'     => $name,
            'email'    => $email,
            'role_id'  => $roleId,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

// Fetch fresh updated_at for next D19 lock cycle
$fresh = db_row(
    "SELECT u.id, u.name, u.email, u.status, u.phone, u.timezone,
            u.updated_at, ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ?",
    [$id]
);

json_success($fresh);
