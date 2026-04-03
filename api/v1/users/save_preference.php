<?php
declare(strict_types=1);

/**
 * api/v1/users/save_preference.php
 *
 * Persists the authenticated user's theme preference ('dark' or 'light')
 * to the users table and syncs it into the current session.
 *
 * No module permission check — every logged-in user can update their own theme.
 *
 * @method  POST
 * @body    form-encoded: theme ('dark' | 'light')
 * @auth    Session required (any authenticated user)
 * @returns 200 { theme }
 *          422 VALIDATION_ERROR
 *
 * @decisions D5 (soft-delete guard), D7 (path resolution)
 * @session   S017-B
 */

// dirname(__DIR__, 3): api/v1/users/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

// ── Validate theme value ─────────────────────────────────────────────────────
// WHY: FF_Api.post() sends JSON body (Content-Type: application/json), so we
//      read from json_body() rather than $_POST
$body  = json_body();
$theme = clean_string($body['theme'] ?? ($_POST['theme'] ?? null));

if (!in_array($theme, ['dark', 'light'], true)) {
    json_error('VALIDATION_ERROR', 'Invalid theme value. Must be "dark" or "light".', 422);
}

// ── Persist to DB ────────────────────────────────────────────────────────────
$userId = current_user_id();

db_execute(
    "UPDATE users SET theme_preference = ? WHERE id = ? AND deleted_at IS NULL",
    [$theme, $userId]
);

// WHY: sync session so current_user()['theme'] stays consistent for this request
if (isset($_SESSION['ff_user'])) {
    $_SESSION['ff_user']['theme'] = $theme;
}

json_success(['theme' => $theme]);
