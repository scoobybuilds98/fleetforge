<?php
declare(strict_types=1);

/**
 * api/v1/attention/whatsapp_settings.php
 *
 * Save the WhatsApp connection (Settings → Notifications → WhatsApp)
 * (S-ATTENTION-WHATSAPP). Super admin only.
 *
 * Secrets (access token, app secret) are stored encrypted (ENC:,
 * MfaService::encryptSecret) and never sent back to the browser: an empty
 * field means "keep what's saved"; `clear_<field>: true` removes it.
 *
 * @method  POST
 * @auth    require_auth_api + super admin + CSRF
 * @body    { enabled: bool, phone_number_id, access_token?, app_secret?, verify_token,
 *            api_version, template_alert, template_summary, template_language,
 *            clear_access_token?: bool, clear_app_secret?: bool }
 * @returns 200 { status: on|off|incomplete, has_access_token, has_app_secret }
 *
 * @session S-ATTENTION-WHATSAPP
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Auth\MfaService;
use FleetForge\Notifications\WhatsApp\WhatsAppClient;

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only a super admin can change the WhatsApp connection.', 403);
}

$b      = json_body();
$userId = current_user_id();
$errors = [];

$text = static function (string $field, string $pattern, int $max) use ($b, &$errors): string {
    $v = trim((string) ($b[$field] ?? ''));
    if ($v !== '' && (mb_strlen($v) > $max || !preg_match($pattern, $v))) {
        $errors[$field] = 'Check this value.';
    }
    return $v;
};

$phoneId  = $text('phone_number_id', '/^\d{5,30}$/', 30);
$verify   = $text('verify_token', '/^[A-Za-z0-9_\-.]{8,100}$/', 100);
$version  = $text('api_version', '/^v\d{1,2}\.\d$/', 8);
$tAlert   = $text('template_alert', '/^[a-z0-9_]{1,100}$/', 100);
$tSummary = $text('template_summary', '/^[a-z0-9_]{1,100}$/', 100);
$lang     = $text('template_language', '/^[a-z]{2,3}(_[A-Z]{2})?$/', 8);
$enabled  = !empty($b['enabled']);

if ($errors) {
    json_error('VALIDATION_ERROR', 'Some WhatsApp settings need fixing.', 422, $errors);
}

/**
 * Upsert one whatsapp.* setting.
 *
 * @param string   $key
 * @param string   $value
 * @param int|null $userId
 */
$put = static function (string $key, string $value, ?int $userId): void {
    db_execute(
        "INSERT INTO settings (`key`, `value`, value_type, group_name, updated_by)
         VALUES (?, ?, 'string', 'whatsapp', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)",
        [$key, $value, $userId]
    );
};

$put('whatsapp.phone_number_id', $phoneId, $userId);
$put('whatsapp.verify_token', $verify, $userId);
$put('whatsapp.api_version', $version !== '' ? $version : 'v23.0', $userId);
$put('whatsapp.template_alert', $tAlert !== '' ? $tAlert : 'fleetforge_alert', $userId);
$put('whatsapp.template_summary', $tSummary !== '' ? $tSummary : 'fleetforge_summary', $userId);
$put('whatsapp.template_language', $lang !== '' ? $lang : 'en', $userId);

// Secrets: blank = keep; clear_* = remove; anything else = encrypt + store.
foreach (['access_token', 'app_secret'] as $f) {
    $key = 'whatsapp.' . $f;
    if (!empty($b['clear_' . $f])) {
        $put($key, '', $userId);
    } elseif (trim((string) ($b[$f] ?? '')) !== '') {
        $put($key, MfaService::encryptSecret(trim((string) $b[$f])), $userId);
    }
}

db_execute(
    "INSERT INTO settings (`key`, `value`, value_type, group_name, updated_by)
     VALUES ('whatsapp.enabled', ?, 'boolean', 'whatsapp', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)",
    [$enabled ? '1' : '0', $userId]
);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'unknown',
    'action'       => 'update',
    'module'       => 'settings',
    'entity_type'  => 'settings',
    'entity_id'    => null,
    'entity_label' => 'whatsapp',
    'notes'        => 'WhatsApp connection saved (' . ($enabled ? 'on' : 'off') . '). Secrets not logged.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
]);

json_success([
    'status'           => WhatsAppClient::status(),
    'has_access_token' => WhatsAppClient::secret('whatsapp.access_token') !== '',
    'has_app_secret'   => WhatsAppClient::secret('whatsapp.app_secret') !== '',
]);
