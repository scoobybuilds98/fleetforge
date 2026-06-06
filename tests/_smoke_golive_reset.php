<?php
declare(strict_types=1);

/**
 * tests/_smoke_golive_reset.php
 *
 * S-GOLIVE-RESET-TXN — pre-go-live guarded transaction wipe.
 *
 * Test plan:
 *   C1  --dry-run: seeds WIPE rows + stale counters; dry-run exits 0; seeded
 *       rows still present; KEEP counters unchanged.
 *   C2  --confirm-prod (live wipe): WIPE tables empty; customers + equipment
 *       rows preserved; counters reset to 0; equipment status → 'available';
 *       report_cache empty; audit_log row written; acc_accounts / acc_periods /
 *       settings row counts unchanged.
 *   C3  KEEP table guard (positive): dry-run output confirms all KEEP tables
 *       were found (guard ran and passed — fail-safe path verified structurally
 *       since creating a partial-schema DB inline is too invasive for a smoke).
 *   C4  Wrong confirmation string → exit 1, no rows deleted.
 *   C5  Idempotency: re-running confirm-prod on already-wiped DB exits 0.
 *
 * NOTE: C2 permanently wipes WIPE tables (that IS the feature). Test rows
 * inserted into KEEP tables (customers, equipment_units) are deleted in the
 * finally block. WIPE table rows are removed by the script itself.
 *
 * Usage:  php tests/_smoke_golive_reset.php   ·   Exit 0 = all pass
 * Session: S-GOLIVE-RESET-TXN
 */

require_once __DIR__ . '/../config/app.php';

// Number of KEEP tables the script defines — must match KEEP_TABLES in the script.
const SMOKE_KEEP_COUNT = 42;

$pass = 0;
$fail = 0;
function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  {$label}\n";
}
function ko(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$scriptPath = dirname(__DIR__) . '/scripts/golive_reset_transactions.php';
$rand       = bin2hex(random_bytes(4));

/**
 * Run the reset script as a subprocess. Returns [exitCode, combinedOutput].
 */
function run_reset(string $mode, string $stdin = ''): array
{
    global $scriptPath;
    $cmd  = 'php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($mode);
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        return [-1, 'proc_open failed'];
    }
    if ($stdin !== '') {
        fwrite($pipes[0], $stdin . "\n");
    }
    fclose($pipes[0]);
    $out  = (string) stream_get_contents($pipes[1]);
    $err  = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $out . $err];
}

echo "=== _smoke_golive_reset ===\n\n";

// ── Pre-test snapshot of KEEP table row counts ────────────────────────────────
$preCustCount     = db_count('SELECT COUNT(*) FROM customers', []);
$preUnitCount     = db_count('SELECT COUNT(*) FROM equipment_units', []);
$preAcctCount     = db_count('SELECT COUNT(*) FROM acc_accounts', []);
$prePeriodCount   = db_count('SELECT COUNT(*) FROM acc_periods', []);
$preSettingsCount = db_count('SELECT COUNT(*) FROM settings', []);

$testCustomerId = null;
$testUnitId     = null;

try {
    // ── Seed: one test customer (KEEP table) with stale counters ─────────────
    $testCustomerId = db_insert('customers', [
        'company_name'         => "_SMOKE_GOLIVE_{$rand}",
        'outstanding_balance'  => '99.99',
        'account_credit_balance' => '10.00',
        'lease_count'          => 2,
        'active_lease_count'   => 1,
        'total_revenue'        => '500.00',
        'collection_status'    => 'watch',
    ]);

    // ── Seed: one test equipment unit (KEEP table) with on_lease status ───────
    $tmpl = db_row('SELECT id FROM equipment_templates LIMIT 1', []);
    if ($tmpl !== null) {
        $testUnitId = db_insert('equipment_units', [
            'template_id'            => (int) $tmpl['id'],
            'unit_number'            => "_SMOKE_GOLIVE_{$rand}",
            'ownership_type'         => 'owned',
            'status'                 => 'on_lease',
            'lease_count'            => 3,
            'total_revenue'          => '1200.00',
            'total_maintenance_cost' => '80.00',
        ]);
    }

    // ── Seed: rows in WIPE tables ─────────────────────────────────────────────
    // acc_journal_entries (requires a period_id FK to acc_periods [KEEP])
    $jeId = null;
    $periodRow = db_row('SELECT id FROM acc_periods LIMIT 1', []);
    if ($periodRow !== null) {
        $jeId = db_insert('acc_journal_entries', [
            'entry_number' => "SMOKE-JE-{$rand}",
            'period_id'    => (int) $periodRow['id'],
            'entry_date'   => date('Y-m-d'),
            'description'  => "_smoke_golive_{$rand}",
        ]);
    }

    // report_cache
    db_execute(
        "INSERT INTO report_cache (report_type, parameters_hash, parameters, result_data, expires_at)
         VALUES ('smoke_test', MD5(?), '{}', '{}', NOW() + INTERVAL 1 HOUR)",
        ["smoke_{$rand}"]
    );

    // notification_log (channel + recipient are NOT NULL)
    db_insert('notification_log', [
        'channel'   => 'email',
        'recipient' => 'smoke@test.local',
        'status'    => 'sent',
    ]);

    // ──────────────────────────────────────────────────────────────────────────
    // C1: --dry-run — seeded rows must survive; counters unchanged; exit 0
    // ──────────────────────────────────────────────────────────────────────────
    echo "C1: --dry-run mode\n";

    [$exitCode, $output] = run_reset('--dry-run');

    ($exitCode === 0)
        ? ok('C1.1 dry-run exits 0')
        : ko('C1.1 dry-run exits 0', "exit={$exitCode}");

    str_contains($output, 'DRY-RUN COMPLETE')
        ? ok('C1.2 output contains DRY-RUN COMPLETE')
        : ko('C1.2 output contains DRY-RUN COMPLETE');

    // Seeded acc_journal_entries row still present (if period exists)
    if ($jeId !== null) {
        $jeCount = db_count(
            "SELECT COUNT(*) FROM acc_journal_entries WHERE entry_number = ?",
            ["SMOKE-JE-{$rand}"]
        );
        ($jeCount === 1)
            ? ok('C1.3 dry-run left acc_journal_entries row intact')
            : ko('C1.3 dry-run left acc_journal_entries row intact', "count={$jeCount}");
    } else {
        ok('C1.3 dry-run left acc_journal_entries row intact (skipped — no acc_periods rows)');
    }

    // Seeded report_cache row still present
    $cacheCount = db_count(
        "SELECT COUNT(*) FROM report_cache WHERE parameters_hash = MD5(?)",
        ["smoke_{$rand}"]
    );
    ($cacheCount === 1)
        ? ok('C1.4 dry-run left report_cache row intact')
        : ko('C1.4 dry-run left report_cache row intact', "count={$cacheCount}");

    // customers.outstanding_balance NOT reset
    $custRow = db_row(
        'SELECT outstanding_balance, collection_status FROM customers WHERE id = ?',
        [$testCustomerId]
    );
    ($custRow !== null && (float) $custRow['outstanding_balance'] === 99.99)
        ? ok('C1.5 dry-run did not reset customers.outstanding_balance')
        : ko('C1.5 dry-run did not reset customers.outstanding_balance', json_encode($custRow));

    echo "\n";

    // ──────────────────────────────────────────────────────────────────────────
    // C4: Wrong confirmation string → exit 1; no rows deleted
    // ──────────────────────────────────────────────────────────────────────────
    echo "C4: wrong confirmation string\n";

    [$exitCode, $output] = run_reset('--confirm-prod', 'nope wrong string');

    ($exitCode !== 0)
        ? ok('C4.1 wrong-confirm exits non-zero')
        : ko('C4.1 wrong-confirm exits non-zero', "exit={$exitCode}");

    // report_cache row should still be present
    $cacheC4 = db_count(
        "SELECT COUNT(*) FROM report_cache WHERE parameters_hash = MD5(?)",
        ["smoke_{$rand}"]
    ) === 1;
    $cacheC4
        ? ok('C4.2 wrong-confirm left WIPE rows intact (report_cache still present)')
        : ko('C4.2 wrong-confirm left WIPE rows intact (report_cache still present)');

    echo "\n";

    // ──────────────────────────────────────────────────────────────────────────
    // C2: --confirm-prod + correct STDIN → full wipe; assert correctness
    // ──────────────────────────────────────────────────────────────────────────
    echo "C2: --confirm-prod live wipe\n";

    [$exitCode, $output] = run_reset('--confirm-prod', 'yes wipe transactions');

    ($exitCode === 0)
        ? ok('C2.1 confirm-prod exits 0')
        : ko('C2.1 confirm-prod exits 0', "exit={$exitCode}\n" . substr($output, 0, 400));

    str_contains($output, 'WIPE COMPLETE')
        ? ok('C2.2 output contains WIPE COMPLETE')
        : ko('C2.2 output contains WIPE COMPLETE', substr($output, -300));

    // WIPE tables empty
    $jeGone = db_count('SELECT COUNT(*) FROM acc_journal_entries', []) === 0;
    $jeGone
        ? ok('C2.3 acc_journal_entries is empty')
        : ko('C2.3 acc_journal_entries is empty', 'count=' . db_count('SELECT COUNT(*) FROM acc_journal_entries', []));

    $cacheEmpty = db_count('SELECT COUNT(*) FROM report_cache', []) === 0;
    $cacheEmpty
        ? ok('C2.4 report_cache is empty')
        : ko('C2.4 report_cache is empty');

    $notifEmpty = db_count('SELECT COUNT(*) FROM notification_log', []) === 0;
    $notifEmpty
        ? ok('C2.5 notification_log is empty')
        : ko('C2.5 notification_log is empty');

    $leasesEmpty = db_count('SELECT COUNT(*) FROM leases', []) === 0;
    $leasesEmpty
        ? ok('C2.6 leases is empty')
        : ko('C2.6 leases is empty');

    $invEmpty = db_count('SELECT COUNT(*) FROM invoices', []) === 0;
    $invEmpty
        ? ok('C2.7 invoices is empty')
        : ko('C2.7 invoices is empty');

    $payEmpty = db_count('SELECT COUNT(*) FROM payments', []) === 0;
    $payEmpty
        ? ok('C2.8 payments is empty')
        : ko('C2.8 payments is empty');

    $qboSyncEmpty = db_count('SELECT COUNT(*) FROM acc_qbo_sync_log', []) === 0;
    $qboSyncEmpty
        ? ok('C2.9 acc_qbo_sync_log is empty')
        : ko('C2.9 acc_qbo_sync_log is empty');

    // KEEP tables: row counts match pre-test baseline + our seeded rows
    $postCustCount  = db_count('SELECT COUNT(*) FROM customers', []);
    $postUnitCount  = db_count('SELECT COUNT(*) FROM equipment_units', []);
    $postAcctCount  = db_count('SELECT COUNT(*) FROM acc_accounts', []);
    $postPeriodCount  = db_count('SELECT COUNT(*) FROM acc_periods', []);
    $postSettingsCount = db_count('SELECT COUNT(*) FROM settings', []);

    ($postCustCount === $preCustCount + 1)
        ? ok('C2.10 customers row count preserved (baseline + 1 test row)')
        : ko('C2.10 customers row count preserved', "expected=" . ($preCustCount + 1) . " got={$postCustCount}");

    $expUnits = $preUnitCount + ($testUnitId !== null ? 1 : 0);
    ($postUnitCount === $expUnits)
        ? ok('C2.11 equipment_units row count preserved')
        : ko('C2.11 equipment_units row count preserved', "expected={$expUnits} got={$postUnitCount}");

    ($postAcctCount === $preAcctCount)
        ? ok('C2.12 acc_accounts untouched')
        : ko('C2.12 acc_accounts untouched', "expected={$preAcctCount} got={$postAcctCount}");

    ($postPeriodCount === $prePeriodCount)
        ? ok('C2.13 acc_periods untouched')
        : ko('C2.13 acc_periods untouched', "expected={$prePeriodCount} got={$postPeriodCount}");

    ($postSettingsCount === $preSettingsCount)
        ? ok('C2.14 settings untouched')
        : ko('C2.14 settings untouched', "expected={$preSettingsCount} got={$postSettingsCount}");

    // Customer counters reset
    $custRow = db_row(
        'SELECT outstanding_balance, account_credit_balance, lease_count,
                active_lease_count, total_revenue, collection_status
         FROM customers WHERE id = ?',
        [$testCustomerId]
    );
    if ($custRow !== null) {
        $ok = (float) $custRow['outstanding_balance']    === 0.0
           && (float) $custRow['account_credit_balance'] === 0.0
           && (int)   $custRow['lease_count']            === 0
           && (int)   $custRow['active_lease_count']     === 0
           && (float) $custRow['total_revenue']          === 0.0
           && $custRow['collection_status']              === 'current';
        $ok
            ? ok('C2.15 customers counters reset to 0 / collection_status=current')
            : ko('C2.15 customers counters reset to 0 / collection_status=current', json_encode($custRow));
    } else {
        ko('C2.15 customers counters reset to 0 / collection_status=current', 'test customer row missing');
    }

    // Equipment unit status + counters reset
    if ($testUnitId !== null) {
        $unitRow = db_row(
            'SELECT status, lease_count, total_revenue, total_maintenance_cost
             FROM equipment_units WHERE id = ?',
            [$testUnitId]
        );
        if ($unitRow !== null) {
            $ok = $unitRow['status']                    === 'available'
               && (int)   $unitRow['lease_count']       === 0
               && (float) $unitRow['total_revenue']     === 0.0
               && (float) $unitRow['total_maintenance_cost'] === 0.0;
            $ok
                ? ok('C2.16 equipment_units status=available, counters reset to 0')
                : ko('C2.16 equipment_units status=available, counters reset to 0', json_encode($unitRow));
        } else {
            ko('C2.16 equipment_units status=available, counters reset to 0', 'test unit row missing');
        }
    } else {
        ok('C2.16 equipment_units test skipped (no equipment_template row in DB)');
    }

    // audit_log row written (action=manual_trigger, entity_label=golive_reset_transactions)
    $auditWritten = db_count(
        "SELECT COUNT(*) FROM audit_log
         WHERE action = 'manual_trigger' AND module = 'admin'
           AND entity_label = 'golive_reset_transactions'",
        []
    ) > 0;
    $auditWritten
        ? ok('C2.17 audit_log row written (action=manual_trigger, entity_label=golive_reset_transactions)')
        : ko('C2.17 audit_log row written (action=manual_trigger, entity_label=golive_reset_transactions)');

    echo "\n";

    // ──────────────────────────────────────────────────────────────────────────
    // C3: KEEP table guard (positive case — guard ran and passed)
    // Verify the dry-run output confirms the expected KEEP table count.
    // ──────────────────────────────────────────────────────────────────────────
    echo "C3: KEEP table guard (positive verification)\n";

    [$exitCode, $output] = run_reset('--dry-run');
    $guardPassed = str_contains($output, 'KEEP tables present');
    $guardPassed
        ? ok('C3.1 dry-run confirmed all KEEP tables present (guard passed)')
        : ko('C3.1 dry-run confirmed all KEEP tables present (guard passed)', 'output missing "KEEP tables present"');

    $expectedMsg = SMOKE_KEEP_COUNT . ' KEEP tables present';
    str_contains($output, $expectedMsg)
        ? ok("C3.2 guard verified exactly " . SMOKE_KEEP_COUNT . " KEEP tables")
        : ko("C3.2 guard verified exactly " . SMOKE_KEEP_COUNT . " KEEP tables",
             "looking for '{$expectedMsg}' in output");

    echo "\n";

    // ──────────────────────────────────────────────────────────────────────────
    // C5: Idempotency — re-running confirm-prod on already-wiped DB exits 0
    // ──────────────────────────────────────────────────────────────────────────
    echo "C5: idempotency (re-run on already-wiped DB)\n";

    [$exitCode, $output] = run_reset('--confirm-prod', 'yes wipe transactions');

    ($exitCode === 0)
        ? ok('C5.1 idempotent re-run exits 0')
        : ko('C5.1 idempotent re-run exits 0', "exit={$exitCode}\n" . substr($output, 0, 300));

    str_contains($output, 'WIPE COMPLETE')
        ? ok('C5.2 idempotent re-run prints WIPE COMPLETE')
        : ko('C5.2 idempotent re-run prints WIPE COMPLETE');

    $jeStillGone = db_count('SELECT COUNT(*) FROM acc_journal_entries', []) === 0;
    $jeStillGone
        ? ok('C5.3 acc_journal_entries still empty after idempotent run')
        : ko('C5.3 acc_journal_entries still empty after idempotent run');

    echo "\n";

} finally {
    // ── Remove test rows from KEEP tables ─────────────────────────────────────
    if ($testCustomerId !== null) {
        db_execute('DELETE FROM customers WHERE id = ?', [$testCustomerId]);
    }
    if ($testUnitId !== null) {
        db_execute('DELETE FROM equipment_units WHERE id = ?', [$testUnitId]);
    }
}

// ── Result ────────────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo str_repeat('─', 50) . "\n";
printf("  %d/%d PASS   %d FAIL\n", $pass, $total, $fail);
echo str_repeat('─', 50) . "\n";

exit($fail > 0 ? 1 : 0);
