<?php
declare(strict_types=1);

/**
 * cron/accounting_fx_revaluation.php
 *
 * Monthly FX revaluation cron. Intended schedule: 1st of each month at
 * 02:00 server time (operator-managed crontab entry).
 *
 * Behavior:
 *   1. Exit silently if accounting.fx_revaluation_enabled = '0'.
 *   2. Find the prior month's period. It must still be OPEN — the JE is
 *      dated the period end and posts immediately, so a closed period can
 *      never take it (SOP I7: this cron used to look for CLOSED periods
 *      only, so it could never post). If the month is already closed: log
 *      and exit 0 — the accountant revalues by adjusting entry instead.
 *   3. Skip if a 'posted' revaluation already exists for that period.
 *   4. Fetch the closing rate (Bank of Canada or manual per setting).
 *   5. Call FxRevaluationService::post().
 *   6. Audit-log the result.
 *
 * Exit codes:
 *   0 = success or "nothing to do" (disabled, month already closed, already run)
 *   1 = exception thrown — error logged to error_log + audit_log
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.1 (FX revaluation cron).
 * Session:  S037-FX
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\FxRevaluationService;

// Advisory lock — refuse to overlap with another in-flight run.
$lock = db_row("SELECT GET_LOCK('ff_cron_accounting_fx_revaluation', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/accounting_fx_revaluation: another instance is running — exiting.');
    exit(0);
}

try {
    // 1. Enabled?
    if ((string) AccountingService::setting('accounting.fx_revaluation_enabled', '0') !== '1') {
        echo "FX revaluation disabled — exiting.\n";
        db_execute("SELECT RELEASE_LOCK('ff_cron_accounting_fx_revaluation')", []);
        exit(0);
    }

    // 2. The prior calendar month's period, which must still be open.
    // The cron runs on the 1st of month M; we want the period ending on the
    // last day of M-1. Business-local today (ff_today), not server date().
    $today = ff_today();
    $priorMonthEnd = date('Y-m-t', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    $period = db_row(
        "SELECT id, name, start_date, end_date, status
           FROM acc_periods
          WHERE end_date = ?
          LIMIT 1",
        [$priorMonthEnd]
    );
    if (!$period || (string) $period['status'] !== 'open') {
        $why = $period
            ? "period {$period['name']} is already {$period['status']} — revalue it with an Adjusting journal entry, or revalue before closing next time"
            : "no accounting period ends on {$priorMonthEnd}";
        error_log("cron/accounting_fx_revaluation: skipped — {$why}.");
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'     => 'cron',
            'module'     => 'accounting',
            'entity_type'=> 'fx_revaluation',
            'notes'      => "FX revaluation cron skipped: {$why}.",
            'ip_address' => '127.0.0.1',
        ]);
        db_execute("SELECT RELEASE_LOCK('ff_cron_accounting_fx_revaluation')", []);
        exit(0);
    }

    // 3. Already posted for this period?
    $existing = db_row(
        "SELECT id FROM acc_fx_revaluations
          WHERE period_id = ? AND status = 'posted' LIMIT 1",
        [(int) $period['id']]
    );
    if ($existing) {
        echo "FX revaluation already posted for {$period['name']} (id={$existing['id']}) — exiting.\n";
        db_execute("SELECT RELEASE_LOCK('ff_cron_accounting_fx_revaluation')", []);
        exit(0);
    }

    // 4. Fetch rate
    $rate = FxRevaluationService::fetchBankOfCanadaRate((string) $period['end_date']);

    // 5. System user — prefer oldest super_admin
    $systemUser = db_row(
        "SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = 'super_admin' AND u.status = 'active'
          ORDER BY u.id ASC LIMIT 1"
    );
    $systemUserId = $systemUser ? (int) $systemUser['id'] : 1;

    // 6. Post
    $result = FxRevaluationService::post((int) $period['id'], $rate, $systemUserId);

    $revRow = $result['revaluation'];
    echo sprintf(
        "FX revaluation posted: period=%s rate=%s gain_loss=%s je_id=%s\n",
        $period['name'],
        $rate,
        $revRow['unrealized_gain_loss'],
        $revRow['journal_entry_id'] ?? '(none — zero delta)'
    );

    db_insert('audit_log', [
        'user_id'     => $systemUserId,
        'user_name'   => 'system',
        'action'     => 'cron',
        'module'     => 'accounting',
        'entity_type'=> 'fx_revaluation',
        'entity_id'  => (int) $revRow['id'],
        'notes'      => sprintf(
            'Cron: FX revaluation posted for %s. Rate=%s. Gain/loss=%s. JE=%s.',
            $period['name'],
            $rate,
            $revRow['unrealized_gain_loss'],
            $revRow['journal_entry_id'] ?? '(none)'
        ),
        'ip_address' => '127.0.0.1',
    ]);

    db_execute("SELECT RELEASE_LOCK('ff_cron_accounting_fx_revaluation')", []);
    exit(0);
} catch (\Throwable $e) {
    error_log('cron/accounting_fx_revaluation: ' . $e->getMessage());
    \FleetForge\Observability\Sentry::captureException($e);
    try {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'     => 'cron',
            'module'     => 'accounting',
            'entity_type'=> 'fx_revaluation',
            'notes'      => 'Cron error: ' . $e->getMessage(),
            'ip_address' => '127.0.0.1',
        ]);
    } catch (\Throwable) { /* audit failure non-fatal */ }
    db_execute("SELECT RELEASE_LOCK('ff_cron_accounting_fx_revaluation')", []);
    exit(1);
}
