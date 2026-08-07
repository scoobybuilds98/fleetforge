<?php
declare(strict_types=1);

/**
 * api/v1/admin/customer_notifications/save.php
 *
 * Persist the Customer Emails control-centre config (Settings → Customer
 * Emails). Writes to the `settings` table under group 'customer_notifications'
 * (plus the cron.customer_reminders_enabled toggle). Only keys/fields known to
 * the reminder registry are written, with the correct value_type, so the UI can
 * never inject arbitrary settings rows.
 *
 * POST JSON:
 *   { global: {master_enabled, respect_portal_optout, reply_to, bcc, send_hour,
 *              send_days:[...], footer_note, cron_enabled},
 *     types: { <reminder_key>: {enabled, channels:[...], lead_days, offset_days,
 *              repeat_days, max_count, send_day, audience_mode, subject, docs:{}} } }
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Notifications\CustomerReminders;

require_method('POST');
require_auth_api();
require_permission('settings_customer_notifications', 'edit');

$body = json_body();

$allowedChannels = ['email', 'in_app', 'sms'];
$allowedModes    = ['all', 'selected', 'all_except'];

// (key, value, value_type) tuples to upsert; built with validation below.
$writes = [];

/** Coerce a truthy JSON value to the '1'/'0' the settings table stores. */
$boolStr = static fn($v): string => ($v === true || $v === '1' || $v === 1 || $v === 'true') ? '1' : '0';

// ── Global settings ─────────────────────────────────────────────────────────
$g = is_array($body['global'] ?? null) ? $body['global'] : [];
if (array_key_exists('master_enabled', $g)) {
    $writes[] = ['customer_notifications.master_enabled', $boolStr($g['master_enabled']), 'boolean'];
}
if (array_key_exists('respect_portal_optout', $g)) {
    $writes[] = ['customer_notifications.respect_portal_optout', $boolStr($g['respect_portal_optout']), 'boolean'];
}
if (array_key_exists('reply_to', $g)) {
    $rt = trim((string) $g['reply_to']);
    if ($rt !== '' && filter_var($rt, FILTER_VALIDATE_EMAIL) === false) {
        json_validation_error(['reply_to' => 'Must be a valid email address.'], 'Invalid reply-to address.');
    }
    $writes[] = ['customer_notifications.reply_to', $rt, 'string'];
}
if (array_key_exists('bcc', $g)) {
    // Allow multiple comma/space separated addresses; validate each.
    $raw = trim((string) $g['bcc']);
    if ($raw !== '') {
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $addr) {
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                json_validation_error(['bcc' => "Invalid address: {$addr}"], 'Invalid BCC address.');
            }
        }
    }
    $writes[] = ['customer_notifications.bcc', $raw, 'string'];
}
if (array_key_exists('send_hour', $g)) {
    $h = max(0, min(23, (int) $g['send_hour']));
    $writes[] = ['customer_notifications.send_hour', (string) $h, 'integer'];
}
if (array_key_exists('send_days', $g)) {
    $valid = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $days  = is_array($g['send_days']) ? $g['send_days'] : [];
    $days  = array_values(array_intersect($valid, array_map(static fn($d) => strtolower((string) $d), $days)));
    if (empty($days)) {
        $days = $valid; // never persist an empty window (would silently mute everything)
    }
    $writes[] = ['customer_notifications.send_days', json_encode($days), 'json'];
}
if (array_key_exists('footer_note', $g)) {
    $writes[] = ['customer_notifications.footer_note', mb_substr((string) $g['footer_note'], 0, 500), 'text'];
}
// The Scheduled Jobs dispatcher toggle also lives here for convenience.
if (array_key_exists('cron_enabled', $g)) {
    $writes[] = ['cron.customer_reminders_enabled', $boolStr($g['cron_enabled']), 'boolean'];
}

// ── Per-type settings ───────────────────────────────────────────────────────
$types = is_array($body['types'] ?? null) ? $body['types'] : [];
foreach ($types as $key => $t) {
    $meta = CustomerReminders::meta((string) $key);
    if ($meta === null || !is_array($t)) {
        continue; // ignore unknown reminder keys
    }
    $p = 'customer_notifications.' . $key . '.';

    if (array_key_exists('enabled', $t)) {
        $writes[] = [$p . 'enabled', $boolStr($t['enabled']), 'boolean'];
    }
    if (array_key_exists('channels', $t)) {
        $ch = is_array($t['channels']) ? array_values(array_intersect($allowedChannels, $t['channels'])) : [];
        if (empty($ch)) { $ch = ['email']; }
        $writes[] = [$p . 'channels', json_encode($ch), 'json'];
    }
    foreach (['lead_days', 'offset_days', 'repeat_days', 'max_count', 'send_day'] as $intField) {
        if (array_key_exists($intField, $t)) {
            $v = max(0, (int) $t[$intField]);
            if ($intField === 'send_day') { $v = max(1, min(28, $v)); }
            $writes[] = [$p . $intField, (string) $v, 'integer'];
        }
    }
    if (array_key_exists('audience_mode', $t)) {
        $mode = in_array($t['audience_mode'], $allowedModes, true) ? $t['audience_mode'] : 'all';
        $writes[] = [$p . 'audience_mode', $mode, 'string'];
    }
    if (array_key_exists('subject', $t)) {
        $writes[] = [$p . 'subject', mb_substr((string) $t['subject'], 0, 300), 'string'];
    }
    // Per-document toggles only make sense for a type that supports them.
    if (array_key_exists('docs', $t) && !empty($meta['supports_docs']) && is_array($t['docs'])) {
        $docsDefault = (array) ($meta['docs'] ?? []);
        $docs = [];
        foreach (array_keys($docsDefault) as $slug) {
            $docs[$slug] = ($t['docs'][$slug] ?? false) ? true : false;
        }
        $writes[] = [$p . 'docs', json_encode($docs), 'json'];
    }
}

if (empty($writes)) {
    json_error('VALIDATION_ERROR', 'No recognized settings to save.', 422);
}

$userId = (int) current_user_id();
db_transaction(function () use ($writes, $userId) {
    foreach ($writes as [$key, $value, $vtype]) {
        // Backtick key/value — both are MySQL reserved words. Upsert preserves
        // any pre-existing label/description seeded by the migration.
        db_execute(
            "INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `updated_by`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `value_type` = VALUES(`value_type`),
                                     `updated_by` = VALUES(`updated_by`), `updated_at` = NOW()",
            [$key, $value, $vtype, str_starts_with($key, 'cron.') ? 'cron' : 'customer_notifications', $userId]
        );
    }
});

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'update',
    'module'       => 'settings',
    'entity_type'  => 'customer_notifications',
    'entity_id'    => null,
    'entity_label' => 'Customer Emails settings',
    'notes'        => count($writes) . ' setting(s) updated',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['updated' => count($writes)]);
