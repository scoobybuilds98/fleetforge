<?php
declare(strict_types=1);

/**
 * cron/accounting_recurring_entries.php
 *
 * Daily recurring-JE cron. Intended schedule: 03:00 server time.
 *
 * Behavior:
 *   1. Advisory-lock ('ff_acct_recurring') — skip if another instance running.
 *   2. Fetch every active template with next_post_date <= today
 *      (RecurringEntryService::fetchDueTemplates()).
 *   3. For each: RecurringEntryService::catchUp() posts EVERY due occurrence
 *      from next_post_date through today, oldest first, each dated on its own
 *      scheduled date (idempotent per month via the reference key).
 *      WHY: this used to post only when today's day-of-month matched
 *      (isDueToday()). One missed run — server down on the 1st, the job
 *      toggled off that day, a closed period — lost that month for good,
 *      and the next successful run moved next_post_date past the gap.
 *   4. A template stops at its first failing occurrence (closed period, bad
 *      account…) and stays overdue with the reason logged; one template's
 *      failure does not abort the batch.
 *   5. Summary audit_log row at end.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.3
 * Session:  S037-REC
 */

require_once __DIR__ . '/../config/app.php';

// Operator on/off switch (Settings -> Intelligence -> Scheduled Jobs). S-CRON-TOGGLES.
if (!cron_enabled('accounting_recurring_entries')) { error_log('[CRON] accounting_recurring_entries disabled - skipping.'); exit(0); }

use FleetForge\Accounting\RecurringEntryService;
use FleetForge\Accounting\AutoEntryBridge;

$lock = db_row("SELECT GET_LOCK('ff_acct_recurring', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/accounting_recurring_entries: another instance is running — exiting.');
    exit(0);
}

// Business-local "today" (settings.company.timezone) — the same day source the
// billing engine uses — so a 03:00 run can't pick up tomorrow's occurrence.
$today = \FleetForge\Accounting\AccountingService::businessToday();

// Safety cap per template per run. A long outage just takes a few nightly runs
// (or one "Post Now" per template) to drain; it never posts an unbounded burst.
const RECURRING_CATCHUP_LIMIT = 24;
$posted  = 0;
$skipped = 0;
$failed  = 0;
$failureMessages = [];

try {
    // Resolve system user — same pattern as accounting_fx_revaluation.php
    $systemUser = db_row(
        "SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = 'super_admin' AND u.status = 'active'
          ORDER BY u.id ASC LIMIT 1"
    );
    $systemUserId = $systemUser ? (int) $systemUser['id'] : 1;

    $templates = RecurringEntryService::fetchDueTemplates($today);
    foreach ($templates as $t) {
        try {
            $result = RecurringEntryService::catchUp($t, $today, $systemUserId, RECURRING_CATCHUP_LIMIT);
        } catch (\Throwable $e) {
            $result = ['posted' => [], 'skipped' => [], 'error' => $e->getMessage(), 'failed_date' => null, 'remaining' => 0];
        }

        foreach ($result['posted'] as $p) {
            $posted++;
            echo sprintf("POSTED  template=%d %s  date=%s  je=%s\n", (int) $t['id'], $t['name'], $p['date'], $p['entry_number'] ?? '?');
        }
        foreach ($result['skipped'] as $sk) {
            $skipped++;
            echo sprintf("SKIP    template=%d %s  date=%s  reason=%s\n", (int) $t['id'], $t['name'], $sk['date'], $sk['reason'] ?? '?');
        }
        if (!empty($result['error'])) {
            $failed++;
            $msg = "template=" . (int) $t['id'] . " " . $t['name']
                 . ($result['failed_date'] ? " occurrence={$result['failed_date']}" : '')
                 . ": " . $result['error'];
            $failureMessages[] = $msg;
            error_log("cron/accounting_recurring_entries: {$msg}");
            \FleetForge\Observability\Sentry::captureException(new \RuntimeException($msg));
            echo "FAIL    {$msg}\n";
            // Continue to next template — one failure must not abort the batch.
        } elseif (($result['remaining'] ?? 0) > 0) {
            echo sprintf("MORE    template=%d %s  %d occurrence(s) still due — capped at %d per run\n",
                (int) $t['id'], $t['name'], (int) $result['remaining'], RECURRING_CATCHUP_LIMIT);
        }
    }

    db_insert('audit_log', [
        'user_id'     => $systemUserId,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'accounting',
        'entity_type' => 'recurring_entries_batch',
        'notes'       => sprintf(
            'Recurring entries cron %s: %d posted, %d skipped, %d failed.%s',
            $today,
            $posted,
            $skipped,
            $failed,
            $failed > 0 ? ' Failures: ' . implode(' | ', $failureMessages) : ''
        ),
        'ip_address'  => '127.0.0.1',
    ]);

    echo sprintf("\nSummary %s: posted=%d skipped=%d failed=%d\n", $today, $posted, $skipped, $failed);

    // ── S-ACCT-LESSOR-3: lease amortization period batch ────────────
    // Walks every acc_lease_amortization_schedules row whose period_date
    // is today-or-earlier AND status='scheduled' AND whose lease is
    // active + capital. Each row posts via
    // AutoEntryBridge::onLeasePeriodPosting_Capital (generic for sales-
    // type AND direct-financing per D-LESSOR-3-PERIOD-GENERIC). The
    // bridge gates on `accounting.lessor_module_enabled='1'`; when
    // that's '0' (default) the batch logs every skip via error_log
    // and the cron returns posted=0 — operator must flip the gate before
    // any period JE posts.
    //
    // Separate counters so the cron summary distinguishes recurring-
    // template work from amortization work. Per-row try/catch keeps a
    // single bad row from killing the batch (same convention as the
    // recurring-template loop above).
    $leasePosted = 0;
    $leaseFailed = 0;
    $leaseFailMessages = [];

    $duePeriods = db_select(
        "SELECT s.id, s.lease_id, s.period_number, s.period_date,
                l.contract_number, l.classification
           FROM acc_lease_amortization_schedules s
           JOIN leases l ON l.id = s.lease_id AND l.deleted_at IS NULL
          WHERE s.status = 'scheduled'
            AND s.period_date <= ?
            AND l.classification IN ('sales_type','direct_financing')
            AND l.status = 'active'
          ORDER BY s.lease_id ASC, s.period_number ASC",
        [$today]
    );

    foreach ($duePeriods as $p) {
        try {
            $result = AutoEntryBridge::onLeasePeriodPosting_Capital(
                (int) $p['lease_id'],
                (int) $p['period_number'],
                $systemUserId
            );
            if ($result && !empty($result['je'])) {
                $leasePosted++;
                echo sprintf(
                    "POSTED  lease=%d period=%d %s je=%s\n",
                    (int) $p['lease_id'], (int) $p['period_number'],
                    $p['contract_number'],
                    $result['je']['entry_number'] ?? '?'
                );
            } else {
                echo sprintf(
                    "SKIP    lease=%d period=%d %s reason=%s\n",
                    (int) $p['lease_id'], (int) $p['period_number'],
                    $p['contract_number'],
                    'bridge disabled or lessor module off'
                );
            }
        } catch (\Throwable $e) {
            $leaseFailed++;
            $msg = sprintf('lease=%d period=%d %s: %s',
                (int) $p['lease_id'], (int) $p['period_number'],
                $p['contract_number'], $e->getMessage()
            );
            $leaseFailMessages[] = $msg;
            error_log("cron/accounting_recurring_entries [lease-amort]: {$msg}");
            echo "FAIL    {$msg}\n";
        }
    }

    if (!empty($duePeriods)) {
        db_insert('audit_log', [
            'user_id'     => $systemUserId,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'accounting',
            'entity_type' => 'lease_amortization_batch',
            'notes'       => sprintf(
                'Lease amortization batch %s: %d posted, %d failed (of %d due).%s',
                $today,
                $leasePosted,
                $leaseFailed,
                count($duePeriods),
                $leaseFailed > 0 ? ' Failures: ' . implode(' | ', $leaseFailMessages) : ''
            ),
            'ip_address'  => '127.0.0.1',
        ]);
        echo sprintf("Lease-amort %s: posted=%d failed=%d (of %d due)\n",
            $today, $leasePosted, $leaseFailed, count($duePeriods));
    }

    db_execute("SELECT RELEASE_LOCK('ff_acct_recurring')", []);
    exit(0);
} catch (\Throwable $e) {
    error_log('cron/accounting_recurring_entries: ' . $e->getMessage());
    \FleetForge\Observability\Sentry::captureException($e);
    try {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'     => 'cron',
            'module'     => 'accounting',
            'entity_type'=> 'recurring_entries_batch',
            'notes'      => 'Cron-level error: ' . $e->getMessage(),
            'ip_address' => '127.0.0.1',
        ]);
    } catch (\Throwable) { /* non-fatal */ }
    db_execute("SELECT RELEASE_LOCK('ff_acct_recurring')", []);
    exit(1);
}
