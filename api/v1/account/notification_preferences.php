<?php
declare(strict_types=1);

/**
 * api/v1/account/notification_preferences.php
 *
 * Self-service: which UPDATE categories the signed-in person receives
 * (S-ATTENTION-INBOX — profile → Notifications). Before this, only a super
 * admin could change anyone's notification categories
 * (api/v1/users/notification_preferences/update.php, kept for that screen).
 *
 * Stores the opted-OUT list in users.notification_preferences (NULL = get
 * everything), the same column NotificationService::resolveUsersForType()
 * reads. Needs attention items are not affected: they're the team's shared
 * list, routed per role in Settings → Notifications.
 *
 * @method  POST
 * @auth    require_auth_api + CSRF (api/bootstrap.php)
 * @body    { opted_out: string[] }  category slugs to NOT receive
 * @returns 200 { opted_out }
 *
 * @session S-ATTENTION-INBOX
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$userId = current_user_id();
if (!$userId) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}

// Same allowlist as the super-admin endpoint (NotificationService categories).
$validCategories = [
    'customers', 'leases', 'invoices', 'payments', 'equipment',
    'compliance', 'maintenance', 'damage', 'reservations',
    'samsara', 'accounting', 'quickbooks', 'system',
];

$raw      = json_body()['opted_out'] ?? [];
$optedOut = is_array($raw)
    ? array_values(array_unique(array_filter(array_map('strval', $raw), static fn($c) => in_array($c, $validCategories, true))))
    : [];

db_execute(
    'UPDATE users SET notification_preferences = ? WHERE id = ? AND deleted_at IS NULL',
    [$optedOut ? json_encode($optedOut) : null, $userId]
);

json_success(['opted_out' => $optedOut]);
