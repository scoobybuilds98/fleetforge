<?php
declare(strict_types=1);

/**
 * cron/accounting_tax_filing_reminders.php
 *
 * Daily cron — 07:30 server time.
 *
 * Dispatches notifications when GST/HST/PST filing deadlines are
 * approaching. Threshold days: 30, 14, 7, 1.
 *
 * Schema-on-disk: acc_tax_filing_periods uses `filing_due_date` (not
 * `due_date` as the prompt described) + status ENUM
 * ('open','calculated','filed','remitted'). Only periods NOT yet filed
 * or remitted are checked.
 *
 * Severity: critical when ≤ 7 days; warning otherwise. Notifications
 * route via NotificationService::notify() using the type prefix
 * 'accounting.tax_filing_due' — which maps to the `reports` module
 * audience per NotificationService::MODULE_TYPE_MAP.
 *
 * Idempotency: skip if a notification for the same (entity_id, day_bucket)
 * was already sent today.
 *
 * Crontab: `30 7 * * * php /var/www/fleetforge/cron/accounting_tax_filing_reminders.php`
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.4
 * Session:  S037-CRONS
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Notifications\NotificationService;

$lock = db_row("SELECT GET_LOCK('ff_acct_tax_reminders', 10) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/accounting_tax_filing_reminders: another instance is running — exiting.');
    exit(0);
}

$sent    = 0;
$skipped = 0;
$failed  = 0;

try {
    $today = date('Y-m-d');
    $todayTs = strtotime($today);
    $checkDays = [30, 14, 7, 1];

    $periods = db_select(
        "SELECT id, tax_type, period_start, period_end, filing_due_date, frequency, status
           FROM acc_tax_filing_periods
          WHERE status NOT IN ('filed','remitted')
            AND filing_due_date IS NOT NULL
          ORDER BY filing_due_date ASC"
    );

    $taxTypeLabel = static function (string $tt): string {
        return match ($tt) {
            'gst_hst' => 'GST/HST',
            'pst_bc'  => 'PST (BC)',
            'pst_sk'  => 'PST (SK)',
            'pst_mb'  => 'PST (MB)',
            default   => strtoupper($tt),
        };
    };

    foreach ($periods as $p) {
        $dueTs = strtotime((string) $p['filing_due_date']);
        if ($dueTs === false) continue;
        $daysUntilDue = (int) round(($dueTs - $todayTs) / 86400);

        if (!in_array($daysUntilDue, $checkDays, true)) {
            continue;
        }

        // Idempotency: have we already sent a notification today for this
        // (entity_id, day_bucket)? title contains "{N} day" so we look for
        // a matching row created today.
        $exists = db_row(
            "SELECT id FROM notifications
              WHERE entity_type = 'tax_filing_period'
                AND entity_id = ?
                AND DATE(created_at) = ?
                AND title LIKE ?
              LIMIT 1",
            [(int) $p['id'], $today, "%{$daysUntilDue} day%"]
        );
        if ($exists) {
            $skipped++;
            continue;
        }

        $severity = $daysUntilDue <= 7 ? 'critical' : 'warning';
        $label = $taxTypeLabel((string) $p['tax_type']);
        $title = sprintf(
            '%s filing due in %d day%s',
            $label,
            $daysUntilDue,
            $daysUntilDue === 1 ? '' : 's'
        );
        $message = sprintf(
            '%s filing period %s → %s is due %s (%d day%s from today).',
            $label,
            (string) $p['period_start'],
            (string) $p['period_end'],
            (string) $p['filing_due_date'],
            $daysUntilDue,
            $daysUntilDue === 1 ? '' : 's'
        );
        $url = '/fleetforge/accounting/tax/show?id=' . (int) $p['id'];

        try {
            NotificationService::notify(
                'accounting.tax_filing_due',
                $title,
                $message,
                'tax_filing_period',
                (int) $p['id'],
                $url,
                null, // resolve audience via module rules
                $severity
            );
            $sent++;
            echo sprintf(
                "SENT      period_id=%d %s due=%s days=%d severity=%s\n",
                (int) $p['id'],
                $label,
                $p['filing_due_date'],
                $daysUntilDue,
                $severity
            );
        } catch (\Throwable $e) {
            $failed++;
            error_log("cron/accounting_tax_filing_reminders: period_id=" . (int) $p['id'] . " — " . $e->getMessage());
            if (class_exists('\FleetForge\Observability\Sentry')) {
                \FleetForge\Observability\Sentry::captureException($e);
            }
        }
    }

    db_insert('audit_log', [
        'user_id'     => null,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'accounting',
        'entity_type' => 'tax_filing_reminders',
        'notes'       => sprintf(
            'Tax-filing reminders cron %s: %d notifications sent, %d skipped (already sent today), %d failed.',
            $today,
            $sent,
            $skipped,
            $failed
        ),
        'ip_address'  => '127.0.0.1',
    ]);

    echo sprintf("\nSummary %s: sent=%d skipped=%d failed=%d\n", $today, $sent, $skipped, $failed);
    db_execute("SELECT RELEASE_LOCK('ff_acct_tax_reminders')", []);
    exit(0);
} catch (\Throwable $e) {
    error_log('cron/accounting_tax_filing_reminders: ' . $e->getMessage());
    if (class_exists('\FleetForge\Observability\Sentry')) {
        \FleetForge\Observability\Sentry::captureException($e);
    }
    try {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'     => 'cron',
            'module'     => 'accounting',
            'entity_type'=> 'tax_filing_reminders',
            'notes'      => 'Cron-level error: ' . $e->getMessage(),
            'ip_address' => '127.0.0.1',
        ]);
    } catch (\Throwable) { /* non-fatal */ }
    db_execute("SELECT RELEASE_LOCK('ff_acct_tax_reminders')", []);
    exit(1);
}
