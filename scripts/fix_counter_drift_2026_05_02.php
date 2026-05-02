<?php
declare(strict_types=1);

/**
 * scripts/fix_counter_drift_2026_05_02.php
 *
 * S-FIX-2 / DRIFT-FIX-1..6 — One-shot drift remediation.
 *
 * Resets the two denormalized counters (customers.outstanding_balance and
 * leases.total_invoiced) from the invoice ledger using the Path B canonical
 * truth formulas, plus repairs the inverted billing_period_* dates on Lease
 * 21's INV-27.
 *
 * Path B canonical truth:
 *   customers.outstanding_balance = SUM(invoices.balance_due)
 *     WHERE customer_id = ?
 *       AND status IN ('sent', 'partially_paid', 'overdue')
 *       AND deleted_at IS NULL
 *
 *   leases.total_invoiced = SUM(invoices.total_amount)
 *     WHERE lease_id = ?
 *       AND status != 'void'      -- include drafts; exclude void
 *       AND deleted_at IS NULL
 *
 * USAGE:
 *   php scripts/fix_counter_drift_2026_05_02.php --dry-run    (default — no writes)
 *   php scripts/fix_counter_drift_2026_05_02.php --execute    (prompts for 'yes' before writing)
 *
 * SAFETY:
 *   - Default mode is --dry-run. Writes nothing, exits 0.
 *   - --execute prints the diff first, then prompts for the literal string 'yes'.
 *   - Single db_transaction wraps all writes — full rollback on any error.
 *   - Idempotent: re-running with --execute on already-correct counters writes
 *     zero rows (the `if computed_truth != counter` guard makes this a no-op).
 *   - Audit log entry per corrected entity.
 *
 * AUTHOR:  S-FIX-2
 * DATE:    2026-05-02
 * SPEC:    audit findings #1, #2, #11, #12 + Phase 0.5 root-cause report
 */

require_once dirname(__DIR__) . '/config/app.php';

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if ($arg === '--execute') {
        $mode = 'execute';
    } elseif ($arg === '--dry-run') {
        $mode = 'dry-run';
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/fix_counter_drift_2026_05_02.php [--dry-run|--execute]\n");
        exit(2);
    }
}

$isDryRun = ($mode === 'dry-run');

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------
function out(string $line = ''): void {
    echo $line . "\n";
}
function fmt_money(string $s): string {
    return number_format((float) $s, 2, '.', ',');
}

out(str_repeat('=', 78));
out('S-FIX-2 — Counter Drift Remediation Script');
out('Mode: ' . ($isDryRun ? 'DRY-RUN (no writes)' : 'EXECUTE (will write after confirmation)'));
out('Date: ' . date('Y-m-d H:i:s'));
out(str_repeat('=', 78));
out();

// ---------------------------------------------------------------------------
// PASS 1 — Compute the diff (always runs, regardless of mode)
// ---------------------------------------------------------------------------

// 1a. Customer counter diff
$customerDiffs = [];
$customers = db_select(
    "SELECT id, company_name, outstanding_balance, deleted_at
     FROM customers
     ORDER BY id",
    []
);
foreach ($customers as $cust) {
    $truthRow = db_row(
        "SELECT COALESCE(SUM(balance_due), 0) AS truth
         FROM invoices
         WHERE customer_id = ?
           AND status IN ('sent', 'partially_paid', 'overdue')
           AND deleted_at IS NULL",
        [(int) $cust['id']]
    );
    $truth   = (string) ($truthRow['truth'] ?? '0.00');
    $counter = (string) $cust['outstanding_balance'];

    if (bccomp($truth, $counter, 2) !== 0) {
        $delta = bcsub($truth, $counter, 2);
        $customerDiffs[] = [
            'id'        => (int) $cust['id'],
            'name'      => $cust['company_name'],
            'deleted'   => $cust['deleted_at'] !== null,
            'counter'   => $counter,
            'truth'     => $truth,
            'delta'     => $delta,
        ];
    }
}

// 1b. Lease counter diff
$leaseDiffs = [];
$leases = db_select(
    "SELECT id, contract_number, total_invoiced, deleted_at
     FROM leases
     ORDER BY id",
    []
);
foreach ($leases as $lease) {
    $truthRow = db_row(
        "SELECT COALESCE(SUM(total_amount), 0) AS truth
         FROM invoices
         WHERE lease_id = ?
           AND status != 'void'
           AND deleted_at IS NULL",
        [(int) $lease['id']]
    );
    $truth   = (string) ($truthRow['truth'] ?? '0.00');
    $counter = (string) $lease['total_invoiced'];

    if (bccomp($truth, $counter, 2) !== 0) {
        $delta = bcsub($truth, $counter, 2);
        $leaseDiffs[] = [
            'id'             => (int) $lease['id'],
            'contract'       => $lease['contract_number'],
            'deleted'        => $lease['deleted_at'] !== null,
            'counter'        => $counter,
            'truth'          => $truth,
            'delta'          => $delta,
        ];
    }
}

// 1c. Lease 21 INV-27 inverted-dates check
$inv27 = db_row(
    "SELECT id, invoice_number, lease_id, billing_period_start, billing_period_end,
            billing_period_days, status, total_amount
     FROM invoices WHERE id = 27",
    []
);
$inv27NeedsFix = $inv27
    && $inv27['lease_id'] === 21
    && $inv27['billing_period_start'] > $inv27['billing_period_end'];

// ---------------------------------------------------------------------------
// PASS 2 — Print the planned changes
// ---------------------------------------------------------------------------
out('--- Step 1: customers.outstanding_balance corrections ---');
if (empty($customerDiffs)) {
    out('  (none — counters match canonical truth)');
} else {
    $totalAbsCustDelta = '0.00';
    foreach ($customerDiffs as $d) {
        $absDelta = bccomp($d['delta'], '0', 2) < 0 ? bcmul($d['delta'], '-1', 2) : $d['delta'];
        $totalAbsCustDelta = bcadd($totalAbsCustDelta, $absDelta, 2);
        $deletedTag = $d['deleted'] ? ' [soft-deleted]' : '';
        $sign = bccomp($d['delta'], '0', 2) >= 0 ? '+' : '';
        out(sprintf(
            '  customer #%d %s%s: $%s → $%s (delta %s$%s)',
            $d['id'],
            $d['name'],
            $deletedTag,
            fmt_money($d['counter']),
            fmt_money($d['truth']),
            $sign,
            fmt_money($d['delta'])
        ));
    }
    out(sprintf('  TOTAL |delta|: $%s across %d customer(s)', fmt_money($totalAbsCustDelta), count($customerDiffs)));
}
out();

out('--- Step 2: leases.total_invoiced corrections ---');
if (empty($leaseDiffs)) {
    out('  (none — counters match canonical truth)');
} else {
    $totalAbsLeaseDelta = '0.00';
    foreach ($leaseDiffs as $d) {
        $absDelta = bccomp($d['delta'], '0', 2) < 0 ? bcmul($d['delta'], '-1', 2) : $d['delta'];
        $totalAbsLeaseDelta = bcadd($totalAbsLeaseDelta, $absDelta, 2);
        $deletedTag = $d['deleted'] ? ' [soft-deleted]' : '';
        $sign = bccomp($d['delta'], '0', 2) >= 0 ? '+' : '';
        out(sprintf(
            '  lease #%d %s%s: $%s → $%s (delta %s$%s)',
            $d['id'],
            $d['contract'],
            $deletedTag,
            fmt_money($d['counter']),
            fmt_money($d['truth']),
            $sign,
            fmt_money($d['delta'])
        ));
    }
    out(sprintf('  TOTAL |delta|: $%s across %d lease(s)', fmt_money($totalAbsLeaseDelta), count($leaseDiffs)));
}
out();

out('--- Step 3: Lease 21 INV-27 inverted billing dates (DRIFT-FIX-6) ---');
if (!$inv27) {
    out('  Skipped — INV-27 not found.');
} elseif (!$inv27NeedsFix) {
    out('  Skipped — INV-27 dates are already in the correct order.');
    out(sprintf('    current: start=%s, end=%s', $inv27['billing_period_start'], $inv27['billing_period_end']));
} else {
    out(sprintf(
        '  INV-27 (id=%d, lease=%d, status=%s) currently:  start=%s, end=%s, days=%d',
        $inv27['id'], $inv27['lease_id'], $inv27['status'],
        $inv27['billing_period_start'], $inv27['billing_period_end'], (int) $inv27['billing_period_days']
    ));
    out('  Will be corrected to:                            start=2026-04-09, end=2026-04-09, days=1');
    out('  Reason: lease closed same day as activation; previous invoice INV-26 already covers the period.');
    out('          The S-FIX-2 close.php fix prevents new occurrences (Bug #6 code guard).');
}
out();

// ---------------------------------------------------------------------------
// DRY-RUN MODE — exit here
// ---------------------------------------------------------------------------
if ($isDryRun) {
    out(str_repeat('=', 78));
    out('Dry-run complete. No changes written.');
    out('Re-run with --execute to apply (you will be prompted for confirmation first).');
    out(str_repeat('=', 78));
    exit(0);
}

// ---------------------------------------------------------------------------
// EXECUTE MODE — confirmation prompt
// ---------------------------------------------------------------------------
if (empty($customerDiffs) && empty($leaseDiffs) && !$inv27NeedsFix) {
    out('Nothing to do. All counters already match canonical truth and INV-27 dates are valid.');
    exit(0);
}

out(str_repeat('-', 78));
out("Apply these changes? Type 'yes' to confirm or anything else to abort.");
out(str_repeat('-', 78));
echo 'Confirmation: ';
$line = fgets(STDIN);
$confirm = trim((string) $line);
if ($confirm !== 'yes') {
    out("Aborted (received '{$confirm}'). No changes written.");
    exit(1);
}

// ---------------------------------------------------------------------------
// PASS 3 — write the corrections
// ---------------------------------------------------------------------------
$auditEntriesWritten = 0;

db_transaction(function () use (
    $customerDiffs, $leaseDiffs, $inv27, $inv27NeedsFix, &$auditEntriesWritten
) {
    $now      = date('Y-m-d H:i:s');
    $userId   = null; // system-run script
    $userName = 'system (S-FIX-2 drift script)';

    foreach ($customerDiffs as $d) {
        db_update('customers', [
            'outstanding_balance' => $d['truth'],
            'updated_at'          => $now,
        ], 'id = ?', [$d['id']]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'customers',
            'entity_type'  => 'customer',
            'entity_id'    => $d['id'],
            'entity_label' => $d['name'],
            'notes'        => sprintf(
                'S-FIX-2 drift remediation — Path B counter semantics + Phase 0.5 root cause cleanup; pre-correction value was $%s, corrected to $%s (delta $%s); sources: bulk-delete bypass + Path B recount excluding drafts.',
                fmt_money($d['counter']),
                fmt_money($d['truth']),
                fmt_money($d['delta'])
            ),
            'old_values'   => json_encode(['outstanding_balance' => $d['counter']]),
            'new_values'   => json_encode(['outstanding_balance' => $d['truth']]),
            'ip_address'   => '127.0.0.1',
        ]);
        $auditEntriesWritten++;
    }

    foreach ($leaseDiffs as $d) {
        db_update('leases', [
            'total_invoiced' => $d['truth'],
            'updated_at'     => $now,
        ], 'id = ?', [$d['id']]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease',
            'entity_id'    => $d['id'],
            'entity_label' => $d['contract'],
            'notes'        => sprintf(
                'S-FIX-2 drift remediation — leases.total_invoiced reset from invoice ledger (Path B: include drafts, exclude void/deleted); pre-correction $%s, corrected to $%s (delta $%s).',
                fmt_money($d['counter']),
                fmt_money($d['truth']),
                fmt_money($d['delta'])
            ),
            'old_values'   => json_encode(['total_invoiced' => $d['counter']]),
            'new_values'   => json_encode(['total_invoiced' => $d['truth']]),
            'ip_address'   => '127.0.0.1',
        ]);
        $auditEntriesWritten++;
    }

    // Step 3 — Lease 21 INV-27 inverted dates (DRIFT-FIX-6)
    if ($inv27NeedsFix) {
        $oldStart = $inv27['billing_period_start'];
        $oldEnd   = $inv27['billing_period_end'];
        $oldDays  = (int) $inv27['billing_period_days'];

        $newStart = '2026-04-09';
        $newEnd   = '2026-04-09';
        $newDays  = 1;

        db_update('invoices', [
            'billing_period_start' => $newStart,
            'billing_period_end'   => $newEnd,
            'billing_period_days'  => $newDays,
        ], 'id = ?', [27]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => 27,
            'entity_label' => $inv27['invoice_number'],
            'notes'        => sprintf(
                'S-FIX-2 DRIFT-FIX-6 — fixed inverted billing period dates on INV-27 (lease 21 same-day close anomaly). Was: start=%s end=%s days=%d. Now: start=%s end=%s days=%d. Code-level cause fixed in close.php Bug #6 guard.',
                $oldStart, $oldEnd, $oldDays, $newStart, $newEnd, $newDays
            ),
            'old_values'   => json_encode([
                'billing_period_start' => $oldStart,
                'billing_period_end'   => $oldEnd,
                'billing_period_days'  => $oldDays,
            ]),
            'new_values'   => json_encode([
                'billing_period_start' => $newStart,
                'billing_period_end'   => $newEnd,
                'billing_period_days'  => $newDays,
            ]),
            'ip_address'   => '127.0.0.1',
        ]);
        $auditEntriesWritten++;
    }
});

// ---------------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------------
out();
out(str_repeat('=', 78));
out('Drift remediation complete.');
out(sprintf('  Customers corrected: %d', count($customerDiffs)));
out(sprintf('  Leases corrected:    %d', count($leaseDiffs)));
out(sprintf('  Data anomalies fixed: %d (Lease 21 INV-27 dates: %s)', $inv27NeedsFix ? 1 : 0, $inv27NeedsFix ? 'applied' : 'skipped'));
out(sprintf('  Audit log entries written: %d', $auditEntriesWritten));
out();
out('Re-run Phase 0 reconciliation queries to verify zero drift.');
out(str_repeat('=', 78));
exit(0);
