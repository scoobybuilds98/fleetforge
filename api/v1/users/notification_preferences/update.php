<?php
declare(strict_types=1);

/**
 * api/v1/users/notification_preferences/update.php
 *
 * Save per-user notification category opt-outs.
 *
 * @method  POST
 * @body    { user_id: int, opted_out: string[] }
 *          opted_out = array of category slugs the user should NOT receive.
 *          Pass an empty array to receive all categories.
 * @auth    super_admin only
 * @returns 200 { user_id, opted_out }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage notification preferences.', 403);
}

$body   = json_body();
$userId = clean_int($body['user_id'] ?? null);
if (!$userId) json_error('INVALID_ID', 'A valid user_id is required.', 400);

$user = db_row("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
if (!$user) json_error('NOT_FOUND', 'User not found.', 404);

$validCategories = [
    'customers', 'leases', 'invoices', 'payments', 'equipment',
    'compliance', 'maintenance', 'damage', 'reservations',
    'samsara', 'accounting', 'quickbooks', 'system',
];

$raw      = $body['opted_out'] ?? [];
$optedOut = is_array($raw)
    ? array_values(array_filter($raw, fn($c) => in_array($c, $validCategories, true)))
    : [];

$value = empty($optedOut) ? null : json_encode($optedOut);
db_execute(
    "UPDATE users SET notification_preferences = ? WHERE id = ?",
    [$value, $userId]
);

json_success(['user_id' => $userId, 'opted_out' => $optedOut]);
