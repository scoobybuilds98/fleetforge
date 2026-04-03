<?php
declare(strict_types=1);

/**
 * api/v1/users/show.php
 *
 * Single user detail.
 * Explicit column list — NEVER returns password_hash, invite_token,
 * invite_token_expiry, remember_token, or password_reset_token.
 *
 * SOFT_DELETE: users has deleted_at — 404 if deleted_at IS NOT NULL.
 *
 * @method  GET
 * @param   id (int, query string) — user ID
 * @auth    Session required; require_permission('users','view')
 * @returns 200 { user fields (safe subset) }
 *          404 NOT_FOUND if user does not exist or is soft-deleted
 *
 * Decisions: D5 (soft delete), D7 (routing)
 * Session: S017
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('users', 'view');

// -----------------------------------------------------------------------
// 1. Resolve user ID
// -----------------------------------------------------------------------
$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Fetch user — explicit safe column list (no password_hash, no tokens)
// -----------------------------------------------------------------------
$user = db_row(
    "SELECT
         u.id, u.name, u.email, u.status, u.phone, u.timezone,
         u.theme_preference, u.last_login_at, u.last_login_ip,
         u.invite_sent_at, u.created_at, u.updated_at,
         ur.name AS role_name, ur.slug AS role_slug, ur.id AS role_id
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$id]
);

if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

json_success($user);
