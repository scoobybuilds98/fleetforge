<?php
declare(strict_types=1);

/**
 * api/v1/attention/whatsapp_test.php
 *
 * "Send me a test" (Settings → Notifications → WhatsApp)
 * (S-ATTENTION-WHATSAPP). Sends one alert-template message RIGHT NOW (not
 * via the queue) to the signed-in super admin's own WhatsApp number, so a
 * wrong token / template / number shows its real error on the spot. Logged
 * in the delivery log as purpose 'test'.
 *
 * @method  POST
 * @auth    require_auth_api + super admin + CSRF
 * @returns 200 { sent: true, to } · 422 with Meta's reason in plain words
 *
 * @session S-ATTENTION-WHATSAPP
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Notifications\WhatsApp\WhatsAppClient;
use FleetForge\Notifications\WhatsApp\WhatsAppDeliveries;

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only a super admin can send a WhatsApp test.', 403);
}

$userId = (int) current_user_id();
$me     = db_row('SELECT phone_e164 FROM users WHERE id = ?', [$userId]);
$phone  = (string) ($me['phone_e164'] ?? '');
if (WhatsAppClient::toWaId($phone) === null) {
    json_error('VALIDATION_ERROR', 'Add your WhatsApp number first: Profile → Notifications.', 422);
}

$template = WhatsAppDeliveries::template('alert');
$params   = ['Test', 'WhatsApp is connected', 'This is a test from Settings, Notifications', base_url('notifications')];
$res      = WhatsAppClient::sendTemplate($phone, $template, $params);

db_insert('notification_deliveries', [
    'user_id'             => $userId,
    'to_phone'            => $phone,
    'purpose'             => 'test',
    'template'            => $template,
    'params'              => json_encode($params, JSON_UNESCAPED_UNICODE),
    'preview'             => 'Test: WhatsApp is connected',
    'status'              => $res['ok'] ? 'sent' : 'failed',
    'attempts'            => 1,
    'next_attempt_at'     => ff_now_utc(),
    'provider_message_id' => $res['id'] ?? null,
    'error'               => $res['ok'] ? null : mb_substr((string) ($res['error'] ?? ''), 0, 500),
    'sent_at'             => $res['ok'] ? ff_now_utc() : null,
]);

if (!$res['ok']) {
    json_error('WHATSAPP_FAILED', (string) ($res['error'] ?? 'WhatsApp refused the message.'), 422);
}
json_success(['sent' => true, 'to' => substr($phone, 0, 3) . '•••' . substr($phone, -3)]);
