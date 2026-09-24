<?php
declare(strict_types=1);

/**
 * cron/billing_cycle_open.php
 *
 * S-BILLING-MODULE — opens each month's billing cycle and keeps it moving.
 * Creates NO invoices (generation stays a person's decision in the Billing
 * workbench; the separate monthly invoice job keeps its own toggle, OFF).
 *
 * Daily:
 *   1. OPEN — from the configured open day (Billing → Settings, default 1st)
 *      the cycle for the month to bill (billing_cycle.mode: arrears = last
 *      month, advance = this month) is opened if it does not exist yet, its
 *      readiness checks run, and the owner (or everyone who can see
 *      invoices) is told: how many leases, blockers, warnings, readings
 *      needed. "On or after the open day" (not "on the day") so a missed
 *      run catches up; opening is idempotent, so the notice goes out once.
 *   2. NUDGE — for every open cycle past a target date with work left
 *      (drafts unsent after send-by, or leases unbilled / drafts unreviewed
 *      after review-by), remind the owner — at most once a week per cycle
 *      (billing_cycles.last_nudged_at).
 *
 * Crontab (production): 15 15 * * * php /var/www/fleetforge/cron/billing_cycle_open.php
 *   (15:15 UTC = 08:15 Pacific — after the overnight syncs, before the
 *    working day; the company-local date is what counts, via ff_today()).
 * Local test:           php cron/billing_cycle_open.php
 *
 * Toggle: Settings → Intelligence → Scheduled Jobs → "Open billing cycles"
 *         (config/cron_jobs.php 'billing_cycle_open', default ON).
 * Lock:   GET_LOCK('ff_cron_billing_cycle_open') — one run at a time.
 *
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

if (!defined('FF_BILLING_CYCLE_OPEN_INCLUDE')) {
    if (!cron_enabled('billing_cycle_open')) { error_log('[CRON] billing_cycle_open disabled - skipping.'); exit(0); }

    $lock = db_row("SELECT GET_LOCK('ff_cron_billing_cycle_open', 0) AS ok", []);
    if (!$lock || (int) $lock['ok'] !== 1) {
        exit(0);
    }
    try {
        $result = ff_run_billing_cycle_open(ff_today());
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'system',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'billing_cycle_open',
            'notes'        => 'billing_cycle_open: ' . json_encode($result),
            'ip_address'   => '127.0.0.1',
        ]);
        echo json_encode($result), PHP_EOL;
        exit(0);
    } catch (\Throwable $e) {
        \FleetForge\Observability\Sentry::captureException($e);
        error_log('[CRON] billing_cycle_open failed: ' . $e->getMessage());
        exit(1);
    } finally {
        db_execute("SELECT RELEASE_LOCK('ff_cron_billing_cycle_open')", []);
    }
}

/**
 * One run of the job for a company-local date. Returns what it did.
 * Separate from the script body so the smoke can call it with a fixed date
 * (define FF_BILLING_CYCLE_OPEN_INCLUDE before requiring this file).
 *
 * @return array{opened: ?string, nudged: string[]}
 */
function ff_run_billing_cycle_open(string $today): array
{
    $out = ['opened' => null, 'nudged' => []];
    $openDay = max(1, min(28, (int) settings_get('billing_cycle.open_day', '1')));
    $owner   = (int) settings_get('billing_cycle.owner_user_id', '0');

    // ── 1. Open ────────────────────────────────────────────────────────
    if ((int) date('j', strtotime($today)) >= $openDay) {
        $month = \FleetForge\Billing\Cycle\BillingCycles::targetMonth($today);
        [$cycle, $created] = \FleetForge\Billing\Cycle\BillingCycles::ensure($month, null);
        if ($created) {
            $out['opened'] = $cycle['reference'];
            $ready = \FleetForge\Billing\Cycle\BillingReadiness::run($cycle);
            $ov    = \FleetForge\Billing\Cycle\BillingCycles::overview(\FleetForge\Billing\Cycle\BillingCycles::find($cycle['id']), false);
            $leases = array_sum($ov['coverage']);
            $sum = $ready['summary'];
            $msg = "{$leases} lease(s) to account for"
                 . ($sum['blocker'] ? ", {$sum['blocker']} blocker(s)" : '')
                 . ($sum['warning'] ? ", {$sum['warning']} warning(s)" : '')
                 . ($ov['readings']['missing'] ? ", {$ov['readings']['missing']} reading(s) to enter" : '')
                 . ($cycle['send_by_date'] ? '. Send by ' . date('M j', strtotime($cycle['send_by_date'])) : '') . '.';
            ff_billing_cycle_notify($cycle, "{$cycle['label']} billing is open", $msg, $owner, $sum['blocker'] ? 'warning' : 'info');
        }
    }

    // ── 2. Nudge cycles that are behind ────────────────────────────────
    $weekAgo = ff_now_utc('-7 days');
    foreach (db_select(
        "SELECT id FROM billing_cycles
          WHERE status = 'open'
            AND ((send_by_date IS NOT NULL AND send_by_date < ?) OR (bill_by_date IS NOT NULL AND bill_by_date < ?))
            AND (last_nudged_at IS NULL OR last_nudged_at < ?)
          ORDER BY period_start",
        [$today, $today, $weekAgo]
    ) as $row) {
        $cycle = \FleetForge\Billing\Cycle\BillingCycles::find((int) $row['id']);
        if (!$cycle) continue;
        $ov = \FleetForge\Billing\Cycle\BillingCycles::overview($cycle, false);
        $toBill = (int) ($ov['coverage']['to_bill'] ?? 0) + (int) ($ov['coverage']['void_rebillable'] ?? 0);
        $parts = [];
        if ($cycle['send_by_date'] && $cycle['send_by_date'] < $today && $ov['stats']['drafts'] > 0) {
            $parts[] = "{$ov['stats']['drafts']} draft(s) still unsent (send-by was " . date('M j', strtotime($cycle['send_by_date'])) . ')';
        }
        if ($cycle['bill_by_date'] && $cycle['bill_by_date'] < $today) {
            if ($toBill > 0) $parts[] = "{$toBill} lease(s) not billed yet";
            if ($ov['stats']['unreviewed_drafts'] > 0) $parts[] = "{$ov['stats']['unreviewed_drafts']} draft(s) not reviewed";
        }
        if (!$parts) continue;
        ff_billing_cycle_notify(
            $cycle,
            "{$cycle['label']} billing is behind",
            ucfirst(implode('; ', $parts)) . '.',
            (int) ($cycle['owner_user_id'] ?? 0) ?: $owner,
            'warning'
        );
        db_execute("UPDATE billing_cycles SET last_nudged_at = ? WHERE id = ?", [ff_now_utc(), $cycle['id']]);
        $out['nudged'][] = $cycle['reference'];
    }
    return $out;
}

/** Notify the owner, or everyone the invoice notifications reach. Never throws. */
function ff_billing_cycle_notify(array $cycle, string $title, string $message, int $ownerId, string $severity): void
{
    try {
        \FleetForge\Notifications\NotificationService::notify(
            'invoice.billing_cycle',
            $title,
            $message,
            'billing_cycle',
            (int) $cycle['id'],
            '/fleetforge/billing/cycle?id=' . (int) $cycle['id'],
            $ownerId > 0 ? [$ownerId] : null,
            $severity
        );
    } catch (\Throwable $e) {
        error_log('[CRON billing_cycle_open] notify failed: ' . $e->getMessage());
    }
}
