<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/save_master_controls.php
 *
 * POST endpoint. Updates the three QBO master-control kill-switches:
 *   - sync_enabled       ('0' | '1')   — overall sync on/off, per D-CPA-5
 *   - dry_run_mode       ('0' | '1')   — push logged but not sent
 *   - payments_enabled   ('0' | '1')   — QBO Payments portal embed
 *
 * super_admin only — these flags govern the entire integration's
 * production behaviour; accountant can edit credentials but not flip
 * the master sync switch.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'view')
 *          + super_admin check
 * @body    JSON: { sync_enabled, dry_run_mode, payments_enabled }
 * @returns 200 { success: true, applied: object }
 *        | 403 FORBIDDEN | 422 VALIDATION_ERROR
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.2 (Master controls)
 *           D-CPA-5 (sync_enabled defaults '0' until S-QBO-30)
 * Session:  S-QBO-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may toggle QBO master controls.', 403);
}

$body = json_body();

$applied = [];
$errors  = [];

// Strict '0' | '1' string validation — front-end serialises booleans
// to these literal strings before POSTing. Tightens the surface so a
// junk value like 'true' / 'yes' / 1 doesn't slip through.
foreach (['sync_enabled', 'dry_run_mode', 'payments_enabled'] as $shortKey) {
    if (!isset($body[$shortKey])) {
        continue; // not present → leave existing value alone
    }
    $value = (string) $body[$shortKey];
    if (!in_array($value, ['0', '1'], true)) {
        $errors[$shortKey] = "Must be '0' or '1'.";
        continue;
    }
    $applied[$shortKey] = $value;
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
            'entity_type' => 'qbo_master_controls',
            'notes'       => 'QBO master controls updated: ' . json_encode($applied),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
});

json_success(['applied' => $applied]);
