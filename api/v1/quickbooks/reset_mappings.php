<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/reset_mappings.php
 *
 * POST endpoint. Wipes every FF↔QBO mapping (and the other rows that hold
 * QBO Ids from one company) so FleetForge can be used with a DIFFERENT
 * QuickBooks company — the sandbox → real-company switch at go-live.
 * Clears quickbooks.realm_mismatch, which un-blocks the QBO client.
 *
 * Replaces the manual TRUNCATE block in docs/runbooks/qbo_realm_change.md
 * (Step 2). Destructive and not undoable, hence super_admin + a typed
 * confirmation. Preconditions enforced by RealmGuard::resetMappings():
 * master sync OFF, and connected to the company being adopted.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'disconnect')
 *          + super_admin
 * @body    JSON: { confirm: "RESET" }
 * @returns 200 { reset: true, realm_id, removed: {table: rows} }
 *          403 FORBIDDEN | 409 PRECONDITION | 422 VALIDATION_ERROR
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QboPushers\RealmGuard;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'disconnect');

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may reset QuickBooks mappings.', 403);
}

$body = json_body();
if ((string) ($body['confirm'] ?? '') !== 'RESET') {
    json_validation_error(['confirm' => 'Type RESET to confirm — this deletes every QuickBooks mapping.']);
}

$user = current_user();

try {
    $removed = RealmGuard::resetMappings(
        isset($user['id']) ? (int) $user['id'] : null,
        (string) ($user['name'] ?? 'system')
    );
} catch (\RuntimeException $e) {
    json_error('PRECONDITION', $e->getMessage(), 409);
}

json_success([
    'reset'    => true,
    'realm_id' => (string) settings_get('quickbooks.realm_id', ''),
    'removed'  => $removed,
]);
