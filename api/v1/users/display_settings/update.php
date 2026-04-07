<?php
declare(strict_types=1);

/**
 * api/v1/users/display_settings/update.php
 *
 * PERM-1 Feature 2 — Update the authenticated user's display settings
 * (main-content font size + density).
 *
 * Every logged-in user updates their OWN settings (like save_preference.php
 * for theme). No permission gate, no target user parameter.
 *
 * Validation:
 *   font_size: integer, one of 70, 75, 80, 85, 90, 95, 100, 105, 110,
 *              115, 120, 125, 130
 *   density:   enum 'compact' | 'comfortable' | 'spacious'
 *
 * Partial updates are allowed — send only the field(s) you want to
 * change. At least one field must be present.
 *
 * Persistence:
 *   1. Writes to users.display_font_size / users.display_density
 *   2. Syncs $_SESSION['ff_user']['display_font_size' / 'display_density']
 *      so the next page render (same request or next) uses the new values
 *
 * @method  POST
 * @body    JSON: { font_size?: int, density?: string }
 * @auth    Session required (any authenticated user)
 * @returns 200 { font_size, density }
 *          422 VALIDATION_ERROR
 *
 * @session PERM-1
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$body = json_body();

$validSteps   = [70, 75, 80, 85, 90, 95, 100, 105, 110, 115, 120, 125, 130];
$validDensity = ['compact', 'comfortable', 'spacious'];

$fields = [];
$updates = [];

// ── font_size (optional) ─────────────────────────────────────
if (array_key_exists('font_size', $body)) {
    $fontSize = clean_int($body['font_size']);
    if ($fontSize === null || !in_array($fontSize, $validSteps, true)) {
        $fields['font_size'] = 'Font size must be one of: ' . implode(', ', $validSteps) . '.';
    } else {
        $updates['display_font_size'] = $fontSize;
    }
}

// ── density (optional) ───────────────────────────────────────
if (array_key_exists('density', $body)) {
    $density = clean_string($body['density'], 20);
    if ($density === null || !in_array($density, $validDensity, true)) {
        $fields['density'] = 'Density must be one of: ' . implode(', ', $validDensity) . '.';
    } else {
        $updates['display_density'] = $density;
    }
}

if (!empty($fields)) {
    json_validation_error($fields);
}

if (empty($updates)) {
    json_validation_error(
        ['font_size' => 'At least one of font_size or density is required.'],
        'Nothing to update.'
    );
}

// ── Persist ──────────────────────────────────────────────────
$userId = current_user_id();

db_update('users', $updates, 'id = ? AND deleted_at IS NULL', [$userId]);

// ── Sync session so header.php/topbar.php see the new values
// on the next page render. WHY: without this, the user would have
// to log out and back in for the visual change to persist across
// pages — the change would still save but wouldn't be visible.
if (isset($_SESSION['ff_user'])) {
    if (array_key_exists('display_font_size', $updates)) {
        $_SESSION['ff_user']['display_font_size'] = (int) $updates['display_font_size'];
    }
    if (array_key_exists('display_density', $updates)) {
        $_SESSION['ff_user']['display_density'] = $updates['display_density'];
    }
}

json_success([
    'font_size' => (int) ($_SESSION['ff_user']['display_font_size'] ?? 100),
    'density'   => $_SESSION['ff_user']['display_density'] ?? 'comfortable',
]);
