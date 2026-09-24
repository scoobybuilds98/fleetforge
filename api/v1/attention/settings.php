<?php
declare(strict_types=1);

/**
 * api/v1/attention/settings.php
 *
 * Save Settings → Notifications (S-ATTENTION-INBOX): per-kind roles /
 * priority / WhatsApp, and the escalation window. Super admin only — who is
 * told about what is an owner decision.
 *
 * Only values that differ from a kind's defaults are stored
 * (KindRegistry::saveOverrides), so unchanged kinds keep following their
 * defaults if those improve later.
 *
 * @method  POST
 * @auth    require_auth_api + super admin + CSRF (api/bootstrap.php)
 * @body    { kinds: { <kind>: { roles: string[], priority?: urgent|todo, whatsapp: now|summary|off } },
 *            escalate_after_hours: int 1–168 }
 * @returns 200 { overrides, escalate_after_hours }
 *
 * @session S-ATTENTION-INBOX
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Attention\KindRegistry;

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only a super admin can change notification settings.', 403);
}

$body  = json_body();
$kinds = is_array($body['kinds'] ?? null) ? $body['kinds'] : [];
$hours = clean_int($body['escalate_after_hours'] ?? null);
if ($hours === null || $hours < 1 || $hours > 168) {
    json_error('VALIDATION_ERROR', 'Escalation must be between 1 and 168 hours.', 422);
}

$validRoles = array_map(
    static fn(array $r) => (string) $r['slug'],
    db_select("SELECT slug FROM user_roles WHERE slug <> 'super_admin'")
);
$userId = current_user_id();

$stored = KindRegistry::saveOverrides($kinds, $validRoles, $userId);

db_execute(
    "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, updated_by)
     VALUES ('notifications.escalate_after_hours', ?, 'integer', 'notifications',
             'Escalate urgent items after (hours)',
             'An urgent Needs attention item nobody has taken after this many hours goes to super admins, once.', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)",
    [(string) $hours, $userId]
);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'unknown',
    'action'       => 'update',
    'module'       => 'settings',
    'entity_type'  => 'settings',
    'entity_id'    => null,
    'entity_label' => 'notifications',
    'notes'        => 'Notification settings saved: ' . count($stored) . ' kind(s) customised; escalate after ' . $hours . 'h.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
]);

json_success(['overrides' => (object) $stored, 'escalate_after_hours' => $hours]);
