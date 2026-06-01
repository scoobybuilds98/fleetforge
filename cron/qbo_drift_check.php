<?php
declare(strict_types=1);

/**
 * cron/qbo_drift_check.php
 *
 * Daily QBO drift-detection cron (S-QBO-24, Phase QBO-12 / 1 of 3). Runs
 * nightly at 03:30 server time (after token refresh at 02:00, after bank
 * CDC at 02:30) — per spec §15.2. Delegates to DriftChecker::runCheck()
 * which surfaces FF↔QBO divergence into acc_qbo_drift_events for the
 * /quickbooks/drift dashboard.
 *
 * Crontab (NOT installed this session — operator wires it during S-QBO-30
 * cutover alongside the other QBO crons; see OPERATOR_FOLLOWUPS F22):
 *   30 3 * * * php /var/www/fleetforge/cron/qbo_drift_check.php
 *
 * Decision tree:
 *   GET_LOCK('ff_qbo_drift_check', 0)        ── advisory lock per spec §15.2
 *     ├─ already held → exit 0 silently
 *     ▼
 *   Read quickbooks.drift.enabled            ── master toggle
 *     ├─ '0' → audit_log skip + exit 0
 *     ▼
 *   DriftChecker::runCheck()                 ── hybrid snapshot + live
 *     ▼
 *   audit_log row (action='cron')            ── operator confirms cadence
 *     ▼
 *   RELEASE_LOCK
 *
 * K-22 (spec §15.2): advisory lock is `ff_qbo_drift_check` (NOT the
 * `ff_cron_qbo_*` form qbo_bank_cdc uses) — followed spec.
 *
 * Errors are caught + recorded (error_log + STDERR + Sentry + audit_log);
 * cron exits 0 per qbo_bank_cdc.php / qbo_token_refresh.php precedent.
 *
 * @session  S-QBO-24
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §15
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\DriftChecker;
use FleetForge\Observability\Sentry;

Sentry::init();

// ── Advisory lock (spec §15.2; D21 single-host) ──────────────────
$lock = db_row("SELECT GET_LOCK('ff_qbo_drift_check', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/qbo_drift_check: another instance is running — exiting.');
    exit(0);
}

$startedAt = date('c');
echo "[{$startedAt}] QBO drift check starting…\n";

try {
    // Master toggle — emergency stop independent of the rest of QBO sync.
    if ((string) settings_get('quickbooks.drift.enabled', '1') !== '1') {
        echo "[{$startedAt}] quickbooks.drift.enabled='0' — skipping run.\n";
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'quickbooks',
            'entity_type' => 'qbo_drift_check',
            'notes'       => 'Skipped run — quickbooks.drift.enabled=0 (master toggle off).',
            'ip_address'  => '127.0.0.1',
        ]);
        exit(0);
    }

    $result = DriftChecker::runCheck();

    if (isset($result['skipped'])) {
        $summary = "Drift check skipped: {$result['skipped']}.";
    } else {
        $fa = (array) ($result['fixed_asset_reference'] ?? []);
        $summary = sprintf(
            'Drift check completed. mode=%s entities_checked=%d drift_events=%d notified=%d resolved=%d. FA-ref refreshed=%d (synced=%d pending=%d).',
            ($result['live'] ?? false) ? 'live+snapshot' : 'snapshot-only',
            (int) ($result['checked'] ?? 0),
            (int) ($result['drift_events'] ?? 0),
            (int) ($result['notified'] ?? 0),
            (int) ($result['resolved'] ?? 0),
            (int) ($fa['total'] ?? 0),
            (int) ($fa['synced'] ?? 0),
            (int) ($fa['pending'] ?? 0)
        );
    }
    echo "[{$startedAt}] {$summary}\n";

    db_insert('audit_log', [
        'user_id'     => null,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_drift_check',
        'notes'       => $summary . ' ' . json_encode(['by_entity' => (array) ($result['by_entity'] ?? [])]),
        'ip_address'  => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    $msg = $e->getMessage();
    error_log('cron/qbo_drift_check: ' . $msg);
    fwrite(STDERR, "QBO drift check FAILED: {$msg}\n");
    Sentry::captureException($e);

    db_insert('audit_log', [
        'user_id'     => null,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_drift_check',
        'notes'       => 'Run FAILED: ' . substr($msg, 0, 1000),
        'ip_address'  => '127.0.0.1',
    ]);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_qbo_drift_check')", []);
}
