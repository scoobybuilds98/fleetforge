<?php
declare(strict_types=1);

/**
 * cron/accounting_auto_reverse.php
 *
 * Daily cron — 02:30 server time.
 *
 * Auto-posts the reversing entry for every JE that:
 *   - auto_reverse = 1
 *   - auto_reverse_date = today
 *   - reversed_by_id IS NULL  (not yet reversed)
 *   - status = 'posted'
 *
 * Primary consumer: FX revaluation JEs (S037-FX writes auto_reverse=1
 * + auto_reverse_date=first-day-of-next-month, so each month's revaluation
 * reverses cleanly when the next month opens and the engine re-marks).
 *
 * Per-JE failures (e.g. period locked, JE already partially reversed) are
 * caught locally and logged — one failure does NOT abort the batch.
 *
 * Crontab: `30 2 * * * php /var/www/fleetforge/cron/accounting_auto_reverse.php`
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.4
 * Session:  S037-CRONS
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Accounting\JournalEntryService;

$lock = db_row("SELECT GET_LOCK('ff_acct_auto_reverse', 10) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/accounting_auto_reverse: another instance is running — exiting.');
    exit(0);
}

$reversed = 0;
$failed   = 0;
$failureMessages = [];

try {
    $today = date('Y-m-d');

    // System user = oldest active super_admin (same pattern as accounting_fx_revaluation.php)
    $systemUser = db_row(
        "SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = 'super_admin' AND u.status = 'active'
          ORDER BY u.id ASC LIMIT 1"
    );
    $systemUserId = $systemUser ? (int) $systemUser['id'] : 1;

    // Find candidate JEs. Without FOR UPDATE — JournalEntryService::reverse()
    // does its own FOR UPDATE inside the inner transaction, and we'd otherwise
    // hold a long-running outer transaction across N reversals.
    $candidates = db_select(
        "SELECT id, entry_number, entry_date, description, auto_reverse_date
           FROM acc_journal_entries
          WHERE auto_reverse = 1
            AND auto_reverse_date = ?
            AND reversed_by_id IS NULL
            AND status = 'posted'
          ORDER BY id ASC",
        [$today]
    );

    foreach ($candidates as $je) {
        try {
            $result = JournalEntryService::reverse((int) $je['id'], $today, $systemUserId);
            $reversed++;
            echo sprintf(
                "REVERSED  je_id=%d %s  ->  reversal_je=%s\n",
                (int) $je['id'],
                $je['entry_number'],
                $result['entry_number'] ?? '(unknown)'
            );

            db_insert('audit_log', [
                'user_id'     => $systemUserId,
                'user_name'   => 'system',
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'journal_entry',
                'entity_id'   => (int) $je['id'],
                'entity_label' => (string) $je['entry_number'],
                'notes'       => sprintf(
                    'Auto-reversed JE %s per auto_reverse_date=%s. Reversal JE: %s.',
                    $je['entry_number'],
                    $today,
                    $result['entry_number'] ?? '(unknown)'
                ),
                'ip_address'  => '127.0.0.1',
            ]);
        } catch (\Throwable $e) {
            $failed++;
            $msg = "je_id=" . (int) $je['id'] . " " . $je['entry_number'] . ": " . $e->getMessage();
            $failureMessages[] = $msg;
            error_log("cron/accounting_auto_reverse: {$msg}");
            if (class_exists('\FleetForge\Observability\Sentry')) {
                \FleetForge\Observability\Sentry::captureException($e);
            }
            echo "FAIL    {$msg}\n";
            // Continue to next JE.
        }
    }

    db_insert('audit_log', [
        'user_id'     => $systemUserId,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'accounting',
        'entity_type' => 'auto_reverse_batch',
        'notes'       => sprintf(
            'Auto-reverse cron %s: %d reversed, %d failed.%s',
            $today,
            $reversed,
            $failed,
            $failed > 0 ? ' Failures: ' . implode(' | ', $failureMessages) : ''
        ),
        'ip_address'  => '127.0.0.1',
    ]);

    echo sprintf("\nSummary %s: reversed=%d failed=%d\n", $today, $reversed, $failed);
    db_execute("SELECT RELEASE_LOCK('ff_acct_auto_reverse')", []);
    exit(0);
} catch (\Throwable $e) {
    error_log('cron/accounting_auto_reverse: ' . $e->getMessage());
    if (class_exists('\FleetForge\Observability\Sentry')) {
        \FleetForge\Observability\Sentry::captureException($e);
    }
    try {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'     => 'cron',
            'module'     => 'accounting',
            'entity_type'=> 'auto_reverse_batch',
            'notes'      => 'Cron-level error: ' . $e->getMessage(),
            'ip_address' => '127.0.0.1',
        ]);
    } catch (\Throwable) { /* non-fatal */ }
    db_execute("SELECT RELEASE_LOCK('ff_acct_auto_reverse')", []);
    exit(1);
}
