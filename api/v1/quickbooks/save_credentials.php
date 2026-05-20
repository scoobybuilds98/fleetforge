<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/save_credentials.php
 *
 * POST endpoint. Updates the Intuit-Developer-side credentials that
 * the operator pastes in from the Intuit Developer dashboard:
 *   - environment              (sandbox | production)
 *   - client_id
 *   - client_secret
 *   - webhook_verifier_token
 *   - sandbox_redirect_uri
 *
 * Sensitive-value semantics (D-QBO-CORE-8): blank or all-bullet
 * placeholder values are SKIPPED so the operator can edit one
 * sensitive field without re-typing the others. Only non-empty
 * non-placeholder values overwrite the stored row.
 *
 * Audit-log discipline: the audit_log notes field NEVER contains
 * the actual credential value. We log "client_id changed" not
 * "client_id changed to ABCD123…".
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'edit_credentials')
 * @body    JSON: { environment, client_id, client_secret, webhook_verifier_token, sandbox_redirect_uri }
 * @returns 200 { success: true, changed: string[] }
 *        | 422 VALIDATION_ERROR
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.2
 * Session:  S-QBO-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body = json_body();

$environment          = isset($body['environment'])          ? (string) $body['environment']          : null;
$clientId             = isset($body['client_id'])            ? (string) $body['client_id']            : null;
$clientSecret         = isset($body['client_secret'])        ? (string) $body['client_secret']        : null;
$webhookVerifierToken = isset($body['webhook_verifier_token']) ? (string) $body['webhook_verifier_token'] : null;
$sandboxRedirectUri   = isset($body['sandbox_redirect_uri']) ? (string) $body['sandbox_redirect_uri'] : null;

// ── Environment validation (only enum the table understands) ──
if ($environment !== null && !in_array($environment, ['sandbox', 'production'], true)) {
    json_validation_error(['environment' => 'Must be sandbox or production.']);
}

/**
 * Detect the masked placeholder pattern emitted by the settings UI.
 * The page renders "••••••••XXXX" (8 bullets + last 4 chars). A
 * value matching that shape means the operator did not retype the
 * field, so we preserve the existing stored value.
 */
$isMaskedPlaceholder = static function (string $v): bool {
    return $v !== '' && str_starts_with($v, '••••••••');
};

$changed = [];

db_transaction(function () use (
    &$changed,
    $environment, $clientId, $clientSecret, $webhookVerifierToken, $sandboxRedirectUri,
    $isMaskedPlaceholder
): void {
    // Environment + sandbox_redirect_uri are NON-sensitive — empty
    // string is a legitimate value (clearing the override). Persist
    // verbatim whenever the key was present in the payload.
    if ($environment !== null) {
        QuickBooksClient::settings_write_qbo('environment', $environment);
        $changed[] = 'environment';
    }
    if ($sandboxRedirectUri !== null) {
        QuickBooksClient::settings_write_qbo('sandbox_redirect_uri', $sandboxRedirectUri);
        $changed[] = 'sandbox_redirect_uri';
    }

    // Sensitive fields: only persist if non-empty AND not the masked
    // placeholder. Empty / placeholder → leave the stored value alone.
    foreach ([
        'client_id'              => $clientId,
        'client_secret'          => $clientSecret,
        'webhook_verifier_token' => $webhookVerifierToken,
    ] as $shortKey => $value) {
        if ($value === null || $value === '' || $isMaskedPlaceholder($value)) {
            continue;
        }
        QuickBooksClient::settings_write_qbo($shortKey, $value);
        $changed[] = $shortKey;
    }

    if ($changed !== []) {
        $user = current_user();
        db_insert('audit_log', [
            'user_id'     => $user['id'] ?? null,
            'user_name'   => $user['name'] ?? 'system',
            'action'      => 'update',
            'module'      => 'quickbooks',
            'entity_type' => 'qbo_credentials',
            // WHY: NEVER log the actual credential values. Just the
            // names of the fields the operator changed.
            'notes'       => 'QBO credentials updated: ' . implode(', ', $changed),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
});

json_success(['changed' => $changed]);
