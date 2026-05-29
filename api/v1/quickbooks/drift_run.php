<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/drift_run.php
 *
 * Operator-initiated "Run drift check now" — invokes DriftChecker::runCheck()
 * synchronously (the same engine the nightly cron calls) and returns the run
 * stats. Surfaces newly-detected drift into acc_qbo_drift_events for the
 * /quickbooks/drift dashboard.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @returns 200 { checked, drift_events, by_entity, notified, live } | { skipped }
 *
 * @session  S-QBO-24
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §15.2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

try {
    $result = \FleetForge\QboPushers\DriftChecker::runCheck();

    // Audit the manual trigger (mirrors the cron's audit_log row but
    // attributed to the operator).
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'manual_trigger',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_drift_check',
        'entity_label' => 'Drift check (manual)',
        'notes'        => 'Operator ran drift check via /quickbooks/drift. '
            . 'mode=' . (($result['live'] ?? false) ? 'live+snapshot' : 'snapshot-only')
            . ' drift_events=' . (int) ($result['drift_events'] ?? 0),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success($result);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Drift check failed: ' . $e->getMessage(), 500);
}
