<?php
declare(strict_types=1);

/**
 * api/v1/account/whatsapp.php
 *
 * The signed-in person's WhatsApp choices (Profile → Notifications)
 * (S-ATTENTION-WHATSAPP). Turning WhatsApp on here IS the person's opt-in
 * (Meta requires one): it's recorded in users.whatsapp_opted_in_at.
 *
 * @method  POST
 * @auth    require_auth_api + CSRF
 * @body    { action?: 'save' | 'summary_now',
 *            phone: string, mode: off|summary|urgent, summary_hour: 0-23,
 *            quiet_start: 0-23, quiet_end: 0-23, updates: string[] }
 *          summary_now queues today's summary for this person right away (a
 *          way to see what it looks like); it goes out within a minute.
 * @returns 200 { phone, mode, summary_hour, quiet_start, quiet_end, updates, queued? }
 *
 * @session S-ATTENTION-WHATSAPP
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Notifications\WhatsApp\WhatsAppClient;
use FleetForge\Notifications\WhatsApp\WhatsAppDeliveries;

require_method('POST');
require_auth_api();

$userId = (int) current_user_id();
if ($userId <= 0) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}
$b = json_body();

if (($b['action'] ?? 'save') === 'summary_now') {
    if (!WhatsAppClient::configured()) {
        json_error('WHATSAPP_OFF', 'WhatsApp isn\'t connected yet. Your super admin sets it up in Settings → Notifications.', 422);
    }
    WhatsAppDeliveries::reset();
    $n = WhatsAppDeliveries::enqueueSummaries($userId);
    if ($n === 0) {
        json_error('NOTHING_TO_SEND', 'Nothing to send: turn WhatsApp on with your number first, or there\'s nothing open and no activity yesterday.', 422);
    }
    json_success(['queued' => $n]);
}

$mode = (string) ($b['mode'] ?? 'off');
if (!in_array($mode, ['off', 'summary', 'urgent'], true)) {
    json_error('VALIDATION_ERROR', 'Pick what to receive.', 422);
}
$rawPhone = trim((string) ($b['phone'] ?? ''));
$phone    = $rawPhone === '' ? null : WhatsAppClient::normalizePhone($rawPhone);
if ($rawPhone !== '' && $phone === null) {
    json_error('VALIDATION_ERROR', 'That doesn\'t look like a mobile number. Include the country code, e.g. +1 604 555 0142.', 422, ['phone' => 'Invalid number']);
}
if ($mode !== 'off' && $phone === null) {
    json_error('VALIDATION_ERROR', 'Add your WhatsApp number to turn messages on.', 422, ['phone' => 'Required']);
}
$hour = static function (mixed $v, ?int $default): ?int {
    if ($v === null || $v === '') {
        return $default;
    }
    $i = clean_int($v);
    return ($i !== null && $i >= 0 && $i <= 23) ? $i : $default;
};
$updates = array_values(array_intersect(
    array_map('strval', is_array($b['updates'] ?? null) ? $b['updates'] : []),
    array_keys(WhatsAppDeliveries::UPDATE_CHOICES)
));

$before = db_row('SELECT whatsapp_mode, whatsapp_opted_in_at FROM users WHERE id = ?', [$userId]);
$fields = [
    'phone_e164'            => $phone,
    'whatsapp_mode'         => $mode,
    'whatsapp_summary_hour' => $hour($b['summary_hour'] ?? null, 7),
    'whatsapp_quiet_start'  => $hour($b['quiet_start'] ?? null, 21),
    'whatsapp_quiet_end'    => $hour($b['quiet_end'] ?? null, 7),
    'whatsapp_updates'      => $updates ? json_encode($updates) : null,
];
// The person switching WhatsApp on themselves is the opt-in record.
if ($mode !== 'off' && (($before['whatsapp_mode'] ?? 'off') === 'off' || $before['whatsapp_opted_in_at'] === null)) {
    $fields['whatsapp_opted_in_at'] = ff_now_utc();
}
db_update('users', $fields, 'id = ?', [$userId]);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'unknown',
    'action'       => 'update',
    'module'       => 'users',
    'entity_type'  => 'user',
    'entity_id'    => $userId,
    'entity_label' => current_user()['name'] ?? '',
    'notes'        => 'WhatsApp notifications set to ' . $mode . ($phone ? ' (number ending ' . substr($phone, -3) . ')' : '') . '.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
]);

json_success([
    'phone'        => $phone,
    'mode'         => $mode,
    'summary_hour' => $fields['whatsapp_summary_hour'],
    'quiet_start'  => $fields['whatsapp_quiet_start'],
    'quiet_end'    => $fields['whatsapp_quiet_end'],
    'updates'      => $updates,
]);
