<?php
declare(strict_types=1);

/**
 * cron/accounting_generate_periods.php
 *
 * Monthly cron — 1st of month at 04:00 server time.
 *
 * Ensures acc_periods always has at least 12 months of `open` periods
 * extending into the future, so `AccountingService::validatePeriodForPosting()`
 * never fails due to period exhaustion.
 *
 * Idempotency: uses acc_periods uq_period UNIQUE(`year`,`month`) so re-runs
 * are safe (existence check before INSERT, no race conditions under the
 * advisory lock).
 *
 * Crontab: `0 4 1 * * php /var/www/fleetforge/cron/accounting_generate_periods.php`
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.4
 * Session:  S037-CRONS
 */

require_once __DIR__ . '/../config/app.php';

$lock = db_row("SELECT GET_LOCK('ff_acct_generate_periods', 10) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/accounting_generate_periods: another instance is running — exiting.');
    exit(0);
}

$generated = 0;
$cursorYM = null;

try {
    $today = date('Y-m-d');
    // Target horizon = 12 calendar months from today. Use date('Y-m-t', ...)
    // so we land on the last day of the target month (not the same day-of-
    // month, which can shift due to PHP date math edge cases).
    $targetHorizon = date('Y-m-t', strtotime('+12 months', strtotime($today)));

    // Find the latest period currently in the table.
    $latest = db_row("SELECT `year`, `month`, end_date FROM acc_periods ORDER BY `year` DESC, `month` DESC LIMIT 1");
    if (!$latest) {
        throw new \RuntimeException('No existing periods found — refusing to bootstrap from empty state.');
    }

    $cursorYear  = (int) $latest['year'];
    $cursorMonth = (int) $latest['month'];
    $cursorEnd   = (string) $latest['end_date'];

    while ($cursorEnd < $targetHorizon) {
        $cursorMonth++;
        if ($cursorMonth > 12) {
            $cursorMonth = 1;
            $cursorYear++;
        }
        $start = sprintf('%04d-%02d-01', $cursorYear, $cursorMonth);
        $end   = date('Y-m-t', strtotime($start));
        $name  = date('F Y', strtotime($start));

        // Idempotent insert: uq_period(year, month) catches duplicates.
        $existing = db_row("SELECT id FROM acc_periods WHERE `year` = ? AND `month` = ?", [$cursorYear, $cursorMonth]);
        if (!$existing) {
            db_insert('acc_periods', [
                'year'        => $cursorYear,
                'month'       => $cursorMonth,
                'name'        => $name,
                'start_date'  => $start,
                'end_date'    => $end,
                'status'      => 'open',
                'is_year_end' => $cursorMonth === 12 ? 1 : 0,
            ]);
            $generated++;
        }
        $cursorEnd = $end;
    }
    $cursorYM = sprintf('%04d-%02d', $cursorYear, $cursorMonth);

    db_insert('audit_log', [
        'user_id'     => null,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'accounting',
        'entity_type' => 'period_generation',
        'notes'       => sprintf(
            'Generate-periods cron %s: %d new period(s) created. Horizon now through %s.',
            $today,
            $generated,
            $cursorEnd
        ),
        'ip_address'  => '127.0.0.1',
    ]);

    echo sprintf("Generated %d period(s). Horizon through %s.\n", $generated, $cursorEnd);
    db_execute("SELECT RELEASE_LOCK('ff_acct_generate_periods')", []);
    exit(0);
} catch (\Throwable $e) {
    error_log('cron/accounting_generate_periods: ' . $e->getMessage());
    if (class_exists('\FleetForge\Observability\Sentry')) {
        \FleetForge\Observability\Sentry::captureException($e);
    }
    try {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'accounting',
            'entity_type' => 'period_generation',
            'notes'       => 'Cron error: ' . $e->getMessage(),
            'ip_address'  => '127.0.0.1',
        ]);
    } catch (\Throwable) { /* non-fatal */ }
    db_execute("SELECT RELEASE_LOCK('ff_acct_generate_periods')", []);
    exit(1);
}
