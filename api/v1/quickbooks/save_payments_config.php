<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/save_payments_config.php
 *
 * POST endpoint — saves the QBO Payments embed configuration keys (F11 /
 * S-QBO-PAYMENTS-SETTINGS-UI). The master `payments_enabled` toggle stays in
 * Master Controls (save_master_controls.php, super-admin); these are the
 * operational URL/TTL knobs an operator with edit_credentials can set:
 *   - quickbooks.payments.success_url     (portal success redirect path)
 *   - quickbooks.payments.cancel_url      (portal cancel redirect path)
 *   - quickbooks.payments.url_ttl_minutes (hosted-page URL lifetime; positive int)
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'edit_credentials')
 * @body    JSON: { success_url?, cancel_url?, url_ttl_minutes? }
 * @returns 200 { success: true, applied: object } | 422 VALIDATION_ERROR
 *
 * @session  S-QBO-PAYMENTS-SETTINGS-UI (F11)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body = json_body();

$applied = [];
$errors  = [];

// success_url / cancel_url — non-empty strings (relative portal paths or
// absolute URLs). Trim; reject blank-after-trim if the key was sent.
foreach (['success_url', 'cancel_url'] as $k) {
    if (!array_key_exists($k, $body)) {
        continue;
    }
    $v = trim((string) $body[$k]);
    if ($v === '') {
        $errors[$k] = 'Must not be empty.';
        continue;
    }
    if (strlen($v) > 500) {
        $errors[$k] = 'Too long (max 500 chars).';
        continue;
    }
    $applied['payments.' . $k] = $v;
}

// url_ttl_minutes — positive integer, sane upper bound.
if (array_key_exists('url_ttl_minutes', $body)) {
    $ttl = (int) $body['url_ttl_minutes'];
    if ($ttl < 1 || $ttl > 1440) {
        $errors['url_ttl_minutes'] = 'Must be between 1 and 1440 minutes.';
    } else {
        $applied['payments.url_ttl_minutes'] = (string) $ttl;
    }
}

if ($errors !== []) {
    json_validation_error($errors);
}

db_transaction(function () use (&$applied): void {
    foreach ($applied as $shortKey => $value) {
        QuickBooksClient::settings_write_qbo($shortKey, $value);
    }
    if ($applied !== []) {
        $user = current_user();
        db_insert('audit_log', [
            'user_id'     => $user['id'] ?? null,
            'user_name'   => $user['name'] ?? 'system',
            'action'      => 'update',
            'module'      => 'quickbooks',
            'entity_type' => 'qbo_payments_config',
            'notes'       => 'QBO Payments config updated: ' . json_encode($applied),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
});

json_success(['applied' => $applied]);
