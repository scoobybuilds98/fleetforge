<?php
declare(strict_types=1);

/**
 * cron/qbo_bank_cdc.php
 *
 * Daily QBO bank-CDC pull — Exception #2 per D-QBO-CORE-2. Mirrors
 * BankTransactionPuller::runCdc() at 02:30 server time (per spec
 * §SCHEDULED CRONS table; S-QBO-20 K-22 catch: §8.12 narrative said
 * 02:00 but cron table is operationally canonical — fix-up applied to
 * §8.12 in same session).
 *
 * Crontab:
 *   30 2 * * * php /var/www/fleetforge/cron/qbo_bank_cdc.php
 *
 * Do NOT install the crontab in S-QBO-20 — documented only. Operator
 * wires it during S-QBO-30 production cutover alongside the other QBO
 * crons (token_refresh, sync_worker, drift_check, webhook_replay).
 *
 * Decision tree:
 *
 *   GET_LOCK('ff_cron_qbo_bank_cdc', 0)         ── single-host advisory lock per D21
 *     │
 *     ├─ already held → exit 0 silently (a prior tick is still running;
 *     │                  next day's cron will catch up)
 *     │
 *     ▼
 *   Read quickbooks.banking.cdc_enabled         ── master toggle
 *     │
 *     ├─ '0' → log + RELEASE_LOCK + exit 0
 *     │
 *     ▼
 *   BankTransactionPuller::runCdc()             ── per-account loop + upsert
 *     │
 *     ▼
 *   Write audit_log row (action='cron')         ── operator can confirm cadence
 *     │
 *     ▼
 *   RELEASE_LOCK
 *
 * Errors are caught, recorded via error_log + STDERR + audit_log, and
 * the cron exits 0 (per qbo_token_refresh.php precedent — non-zero exit
 * mostly just spams cron logs without changing behavior).
 *
 * @session  S-QBO-20
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.12, §SCHEDULED CRONS table
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\BankTransactionPuller;
use FleetForge\Observability\Sentry;

Sentry::init();

// ── D21 advisory lock ───────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_qbo_bank_cdc', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    // Another tick is in flight (rare for a daily cron — defensive
    // against operator manually invoking while the scheduled run is
    // mid-flight).
    error_log('cron/qbo_bank_cdc: another instance is running — exiting.');
    exit(0);
}

$startedAt = date('c');
echo "[{$startedAt}] QBO bank CDC starting…\n";

try {
    // Master toggle. cdc_enabled='0' is the emergency stop — operator
    // can flip during incident response without disabling the rest of
    // QBO sync.
    $enabled = (string) settings_get('quickbooks.banking.cdc_enabled', '1') === '1';
    if (!$enabled) {
        echo "[{$startedAt}] cdc_enabled='0' — skipping run.\n";
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'quickbooks',
            'entity_type' => 'qbo_bank_cdc',
            'notes'       => 'Skipped run — quickbooks.banking.cdc_enabled=0 (master toggle off).',
            'ip_address'  => '127.0.0.1',
        ]);
        exit(0);
    }

    $result = BankTransactionPuller::runCdc();

    $summary = sprintf(
        'CDC run completed. pulled=%d updated=%d unchanged=%d skipped=%d errors=%d. since=%s. accounts=%d.',
        (int) ($result['pulled']     ?? 0),
        (int) ($result['updated']    ?? 0),
        (int) ($result['unchanged']  ?? 0),
        (int) ($result['skipped']    ?? 0),
        (int) ($result['errors']     ?? 0),
        (string) ($result['since']   ?? ''),
        count((array) ($result['by_account'] ?? []))
    );
    echo "[{$startedAt}] {$summary}\n";

    db_insert('audit_log', [
        'user_id'     => null,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_bank_cdc',
        'notes'       => $summary . ' ' . json_encode([
            'by_account' => (array) ($result['by_account'] ?? []),
            'last_bank_cdc_at' => (string) ($result['last_bank_cdc_at'] ?? ''),
        ]),
        'ip_address'  => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    // Failure branch — record the failure but don't propagate (the cron
    // log would just accumulate noise). The audit_log row is the
    // operator's surface for "did the cron run + what happened".
    $msg = $e->getMessage();
    error_log('cron/qbo_bank_cdc: ' . $msg);
    fwrite(STDERR, "QBO bank CDC FAILED: {$msg}\n");
    Sentry::captureException($e);

    db_insert('audit_log', [
        'user_id'     => null,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_bank_cdc',
        'notes'       => 'Run FAILED: ' . substr($msg, 0, 1000),
        'ip_address'  => '127.0.0.1',
    ]);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_qbo_bank_cdc')", []);
}
