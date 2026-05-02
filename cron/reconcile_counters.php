<?php
declare(strict_types=1);

/**
 * cron/reconcile_counters.php
 *
 * Nightly counter-drift detector — runs daily at 02:00 UTC (low-traffic,
 * after the monthly invoice cron at 01:00).
 *
 * Compares customers.outstanding_balance and leases.total_invoiced against the
 * canonical Path B truth formulas from S-FIX-2.  READ-ONLY — never writes to
 * customers or leases.  Use scripts/fix_counter_drift_2026_05_02.php to correct.
 *
 * Crontab (production): 0 2 * * *  /usr/bin/php /var/www/fleetforge/cron/reconcile_counters.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/reconcile_counters.php
 *
 * Path B canonical formulas (locked as D45 / D52):
 *   customers.outstanding_balance:
 *     SUM(invoices.balance_due) WHERE status IN ('sent','partially_paid','overdue')
 *                                  AND deleted_at IS NULL
 *
 *   leases.total_invoiced:
 *     SUM(invoices.total_amount) WHERE status != 'void'  ← drafts included
 *                                  AND deleted_at IS NULL
 *
 * Alert logic:
 *   - Any drift > $0.01 → notify via NotificationService (type accounting.reconciliation_drift)
 *     + write logs/reconciliation/{date}.log + audit_log entry
 *   - Spike detection: prior run was clean AND today's total drift ≥ $1,000.00
 *     → escalate to 'critical' (likely code regression — not just data drift)
 *
 * Decisions: D21 (advisory lock), D45 (Path B counter semantics), D52 (this cron)
 * Audit findings resolved: part of #3 (missing reconciler cron)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Notifications\NotificationService;

// ── Advisory lock (D21) ──────────────────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_reconcile_counters', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$start = time();
$today = date('Y-m-d');

try {
    // ── Log cron start ───────────────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'reconciliation',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'reconcile_counters',
        'notes'        => 'Counter reconciliation started',
        'ip_address'   => '127.0.0.1',
    ]);

    // ── Ensure log directory exists ──────────────────────────────────────────
    $logDir = FF_ROOT . '/logs/reconciliation';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // ── Customer outstanding_balance drift check ─────────────────────────────
    $customers     = db_select(
        "SELECT id, company_name, outstanding_balance FROM customers ORDER BY id",
        []
    );
    $customerDrifts  = [];
    $totalCustDrift  = '0.00';

    foreach ($customers as $c) {
        $row = db_row(
            "SELECT COALESCE(SUM(balance_due), '0.00') AS truth
               FROM invoices
              WHERE customer_id = ?
                AND status IN ('sent', 'partially_paid', 'overdue')
                AND deleted_at IS NULL",
            [(int) $c['id']]
        );
        $truth    = (string) ($row['truth'] ?? '0.00');
        $counter  = (string) $c['outstanding_balance'];
        $delta    = bcsub($truth, $counter, 2);
        $absDelta = bccomp($delta, '0', 2) < 0 ? bcmul($delta, '-1', 2) : $delta;

        if (bccomp($absDelta, '0.01', 2) > 0) {
            $customerDrifts[] = [
                'id'      => (int) $c['id'],
                'name'    => $c['company_name'],
                'counter' => $counter,
                'truth'   => $truth,
                'delta'   => $delta,
            ];
            $totalCustDrift = bcadd($totalCustDrift, $absDelta, 2);
        }
    }

    // ── Lease total_invoiced drift check ─────────────────────────────────────
    $leases      = db_select(
        "SELECT id, contract_number, total_invoiced, deleted_at FROM leases ORDER BY id",
        []
    );
    $leaseDrifts   = [];
    $totalLeaseDrift = '0.00';

    foreach ($leases as $l) {
        $row = db_row(
            "SELECT COALESCE(SUM(total_amount), '0.00') AS truth
               FROM invoices
              WHERE lease_id = ?
                AND status != 'void'
                AND deleted_at IS NULL",
            [(int) $l['id']]
        );
        $truth    = (string) ($row['truth'] ?? '0.00');
        $counter  = (string) $l['total_invoiced'];
        $delta    = bcsub($truth, $counter, 2);
        $absDelta = bccomp($delta, '0', 2) < 0 ? bcmul($delta, '-1', 2) : $delta;

        if (bccomp($absDelta, '0.01', 2) > 0) {
            $leaseDrifts[] = [
                'id'       => (int) $l['id'],
                'contract' => $l['contract_number'],
                'deleted'  => $l['deleted_at'] !== null,
                'counter'  => $counter,
                'truth'    => $truth,
                'delta'    => $delta,
            ];
            $totalLeaseDrift = bcadd($totalLeaseDrift, $absDelta, 2);
        }
    }

    $nCust        = count($customerDrifts);
    $nLease       = count($leaseDrifts);
    $driftDetected = $nCust > 0 || $nLease > 0;
    $totalDrift   = bcadd($totalCustDrift, $totalLeaseDrift, 2);

    // ── Spike detection: compare to previous run state ───────────────────────
    // State file tracks previous total drift so a sudden appearance of large
    // drift (code regression) triggers a 'critical' escalation instead of 'warning'.
    $stateFile  = $logDir . '/last_run.json';
    $priorState = [];
    if (file_exists($stateFile)) {
        $decoded = json_decode((string) file_get_contents($stateFile), true);
        $priorState = is_array($decoded) ? $decoded : [];
    }
    $priorTotalDrift = (string) ($priorState['total_drift'] ?? '0.00');
    $priorWasClean   = bccomp($priorTotalDrift, '1.00', 2) <= 0;
    // Spike = was clean yesterday AND today has ≥ $1,000 drift (suggests regression)
    $isSpike = $priorWasClean && bccomp($totalDrift, '1000.00', 2) >= 0;

    // ── Write detailed reconciliation log ────────────────────────────────────
    $logPath  = $logDir . '/' . $today . '.log';
    $logLines = [];
    $logLines[] = str_repeat('=', 60);
    $logLines[] = 'FleetForge Counter Reconciliation — ' . $today;
    $logLines[] = 'Run at: ' . date('Y-m-d H:i:s');
    $logLines[] = str_repeat('=', 60);
    $logLines[] = '';

    if ($driftDetected) {
        $logLines[] = "DRIFT DETECTED: {$nCust} customer(s), {$nLease} lease(s)";
        $logLines[] = sprintf('  Customer OB drift total:   $%s', number_format((float) $totalCustDrift, 2));
        $logLines[] = sprintf('  Lease total_invoiced drift: $%s', number_format((float) $totalLeaseDrift, 2));
        $logLines[] = sprintf('  Combined total drift:       $%s', number_format((float) $totalDrift, 2));
        if ($isSpike) {
            $logLines[] = '*** SPIKE ALERT: drift appeared suddenly (prior run was clean) ***';
            $logLines[] = '*** This may indicate a code regression — investigate immediately ***';
        }
        $logLines[] = '';
        $logLines[] = '--- customers.outstanding_balance ---';
        if (empty($customerDrifts)) {
            $logLines[] = '  (none)';
        } else {
            foreach ($customerDrifts as $d) {
                $sign = bccomp($d['delta'], '0', 2) >= 0 ? '+' : '';
                $logLines[] = sprintf(
                    '  Customer #%d %-30s  stored=$%-12s  truth=$%-12s  delta=%s$%s',
                    $d['id'],
                    $d['name'],
                    number_format((float) $d['counter'], 2),
                    number_format((float) $d['truth'], 2),
                    $sign,
                    number_format((float) $d['delta'], 2)
                );
            }
        }
        $logLines[] = '';
        $logLines[] = '--- leases.total_invoiced ---';
        if (empty($leaseDrifts)) {
            $logLines[] = '  (none)';
        } else {
            foreach ($leaseDrifts as $d) {
                $tag  = $d['deleted'] ? ' [soft-deleted]' : '';
                $sign = bccomp($d['delta'], '0', 2) >= 0 ? '+' : '';
                $logLines[] = sprintf(
                    '  Lease #%d %-20s%s  stored=$%-12s  truth=$%-12s  delta=%s$%s',
                    $d['id'],
                    $d['contract'],
                    $tag,
                    number_format((float) $d['counter'], 2),
                    number_format((float) $d['truth'], 2),
                    $sign,
                    number_format((float) $d['delta'], 2)
                );
            }
        }
        $logLines[] = '';
        $logLines[] = 'Remediation command:';
        $logLines[] = '  php scripts/fix_counter_drift_2026_05_02.php --dry-run';
        $logLines[] = '  php scripts/fix_counter_drift_2026_05_02.php --execute';
    } else {
        $logLines[] = sprintf(
            'CLEAN: Zero drift on %d customer(s), %d lease(s).',
            count($customers),
            count($leases)
        );
    }

    $duration   = time() - $start;
    $logLines[] = '';
    $logLines[] = sprintf('Duration: %ds', $duration);
    $logLines[] = str_repeat('=', 60);

    file_put_contents($logPath, implode("\n", $logLines) . "\n");

    // ── Persist state for tomorrow's spike comparison ────────────────────────
    file_put_contents($stateFile, json_encode([
        'run_date'            => $today,
        'total_drift'         => $totalDrift,
        'customer_drift_count' => $nCust,
        'lease_drift_count'   => $nLease,
    ], JSON_PRETTY_PRINT));

    // ── Notify and audit if drift found ─────────────────────────────────────
    if ($driftDetected) {
        $severity = $isSpike ? 'critical' : 'warning';
        $title    = $isSpike
            ? "CRITICAL drift spike: {$nCust} customer(s), {$nLease} lease(s) — was clean yesterday"
            : "Counter drift detected: {$nCust} customer(s), {$nLease} lease(s)";

        // Build body with top 3 drifts by absolute magnitude
        $allDrifts = [];
        foreach ($customerDrifts as $d) {
            $abs = bccomp($d['delta'], '0', 2) < 0 ? bcmul($d['delta'], '-1', 2) : $d['delta'];
            $allDrifts[] = ['label' => "Customer #{$d['id']} {$d['name']}", 'abs' => $abs, 'delta' => $d['delta']];
        }
        foreach ($leaseDrifts as $d) {
            $abs = bccomp($d['delta'], '0', 2) < 0 ? bcmul($d['delta'], '-1', 2) : $d['delta'];
            $allDrifts[] = ['label' => "Lease #{$d['id']} {$d['contract']}", 'abs' => $abs, 'delta' => $d['delta']];
        }
        usort($allDrifts, static fn($a, $b) => bccomp($b['abs'], $a['abs'], 2));

        $bodyLines = [
            "Customer OB drift:   \$" . number_format((float) $totalCustDrift, 2),
            "Lease inv drift:     \$" . number_format((float) $totalLeaseDrift, 2),
            "Top affected entities:",
        ];
        foreach (array_slice($allDrifts, 0, 3) as $t) {
            $sign = bccomp($t['delta'], '0', 2) >= 0 ? '+' : '';
            $bodyLines[] = "  {$t['label']}: {$sign}\$" . number_format((float) $t['delta'], 2);
        }
        $bodyLines[] = "Run: php scripts/fix_counter_drift_2026_05_02.php --dry-run";

        NotificationService::notify(
            'accounting.reconciliation_drift',
            $title,
            implode("\n", $bodyLines),
            'reconciliation',
            null,
            '/fleetforge/accounting/dashboard',
            null,
            $severity
        );

        $notes = "Drift detected: {$nCust} customer(s) totaling \$" . number_format((float) $totalCustDrift, 2)
            . ", {$nLease} lease(s) totaling \$" . number_format((float) $totalLeaseDrift, 2)
            . ($isSpike ? ' — SPIKE (prior run was clean).' : '.')
            . ' Run scripts/fix_counter_drift_2026_05_02.php to investigate.';
    } else {
        $notes = 'Reconciliation clean — zero drift on '
            . count($customers) . ' customer(s), '
            . count($leases) . ' lease(s).';
    }

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'reconciliation',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'reconcile_counters',
        'notes'        => $notes . " ({$duration}s)",
        'ip_address'   => '127.0.0.1',
    ]);

    error_log(
        '[CRON reconcile_counters] '
        . ($driftDetected
            ? "DRIFT: {$nCust} customers \${$totalCustDrift}, {$nLease} leases \${$totalLeaseDrift}"
            : 'Clean — zero drift')
        . " ({$duration}s)"
    );

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    $msg = $e->getMessage();
    error_log("[CRON reconcile_counters] FAILED: {$msg}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'reconciliation',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'reconcile_counters',
        'notes'        => "Counter reconciliation FAILED: {$msg}",
        'ip_address'   => '127.0.0.1',
    ]);

    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_reconcile_counters')", []);
}
