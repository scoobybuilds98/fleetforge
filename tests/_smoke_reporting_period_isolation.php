<?php
declare(strict_types=1);

/**
 * tests/_smoke_reporting_period_isolation.php
 *
 * C5 (Wave 2) — P&L / financial-statement period + status isolation.
 *
 * ReportingService::accountSection() (the engine behind profitAndLoss, and the
 * shape the audit flagged for Balance Sheet / Cash Flow) placed
 *   je.status='posted' AND je.entry_date BETWEEN ? AND ?
 * in a LEFT JOIN ... ON clause instead of WHERE. On a non-matching row the je
 * columns null out but the acc_journal_entry_lines row SURVIVES the LEFT JOIN,
 * so SUM(jel.debit/credit) still counts it → draft (unposted) lines AND
 * out-of-period lines both leak into every period total.
 *
 * This smoke seeds, on a DEDICATED test revenue account (so the assertion is
 * isolated from all other ledger data):
 *   - a POSTED, in-period line     (counts)        — credit 1000
 *   - a DRAFT,  in-period line      (must NOT count) — credit 500
 *   - a POSTED, out-of-period line  (must NOT count) — credit 700
 * then runs the REAL ReportingService::profitAndLoss() for the in-period range
 * and asserts the account's P&L amount is exactly 1000.00.
 *
 * PRE-FIX  : amount = 1000 + 500 + 700 = 2200.00 → FAIL.
 * POST-FIX : amount = 1000.00 (only the posted in-period line).
 *
 * All writes are removed in finally.
 *
 * Run:  php tests/_smoke_reporting_period_isolation.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session C5-REPORTING-PERIOD-ISOLATION
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\ReportingService;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$FROM = '2026-01-01';
$TO   = '2026-01-31';
$IN   = '2026-01-15';   // in-period
$OUT  = '2026-02-15';   // out-of-period (after $TO)

$acctId = null;
$jeIds  = [];

$cleanup = static function () use (&$acctId, &$jeIds) {
    foreach ($jeIds as $jid) {
        db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [$jid]);
        db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [$jid]);
    }
    if ($acctId) {
        db_execute("DELETE FROM acc_journal_entry_lines WHERE account_id = ?", [$acctId]);
        db_execute("DELETE FROM acc_accounts WHERE id = ?", [$acctId]);
    }
};

try {
    $period = db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1");
    if (!$period) { echo "SETUP FAIL no acc_periods\n"; exit(2); }
    $periodId = (int) $period['id'];

    $cashId = (int) (db_row("SELECT id FROM acc_accounts WHERE account_type='asset' AND is_active=1 AND is_header=0 LIMIT 1")['id'] ?? 0);
    if (!$cashId) { echo "SETUP FAIL no asset account\n"; exit(2); }

    // Dedicated test revenue account — isolates the assertion from real ledger data.
    $acctId = db_insert('acc_accounts', [
        'code'           => '4Z' . $PID,
        'name'           => 'C5 Smoke Revenue ' . $PID,
        'account_type'   => 'revenue',
        'normal_balance' => 'credit',
        'is_active'      => 1,
        'is_header'      => 0,
    ]);

    // Helper: seed a JE (revenue credit + cash debit) with a given status + date.
    $seedJe = static function (string $status, string $date, string $amount, string $tag)
        use (&$jeIds, $periodId, $acctId, $cashId, $PID): void {
        $jid = db_insert('acc_journal_entries', [
            'entry_number' => "C5-{$PID}-{$tag}",
            'period_id'    => $periodId,
            'entry_date'   => $date,
            'description'  => "C5 smoke {$tag}",
            'entry_type'   => 'manual',
            'status'       => $status,
        ]);
        $jeIds[] = $jid;
        db_insert('acc_journal_entry_lines', [
            'journal_entry_id' => $jid, 'account_id' => $acctId,
            'debit' => '0.00', 'credit' => $amount,
        ]);
        db_insert('acc_journal_entry_lines', [
            'journal_entry_id' => $jid, 'account_id' => $cashId,
            'debit' => $amount, 'credit' => '0.00',
        ]);
    };

    $seedJe('posted', $IN,  '1000.00', 'posted-in');   // counts
    $seedJe('draft',  $IN,  '500.00',  'draft-in');    // must NOT count
    $seedJe('posted', $OUT, '700.00',  'posted-out');  // must NOT count

    echo str_repeat('─', 72) . "\n";
    echo "C5 REPORTING PERIOD ISOLATION — test rev acct #{$acctId}, range {$FROM}..{$TO}\n";
    echo str_repeat('─', 72) . "\n";

    $pl = ReportingService::profitAndLoss($FROM, $TO);

    // Find our account's row in the revenue section.
    $amount = null;
    foreach ($pl['revenue'] as $row) {
        if ((int) $row['account_id'] === (int) $acctId) { $amount = (string) $row['amount']; break; }
    }

    if ($amount === null) {
        $fail("C5 — test revenue account absent from P&L (expected it with the posted in-period 1000 line)");
    } elseif (bccomp($amount, '1000.00', 2) === 0) {
        $pass("C5 — P&L counts ONLY the posted in-period line: amount=1000.00 (draft 500 + out-of-period 700 excluded)");
    } else {
        $fail("C5 — LEAK: P&L amount={$amount} (expected 1000.00; pre-fix 2200.00 = 1000 + draft 500 + out-of-period 700)");
    }

    // Cross-check: the posted in-period line IS counted (non-vacuous — the fix
    // must not over-exclude and drop legitimate posted-in-period activity).
    if ($amount !== null && bccomp($amount, '0', 2) > 0) {
        $pass("C5 — posted in-period activity is present (non-vacuous; amount > 0)");
    } else {
        $fail("C5 — posted in-period line was dropped (over-exclusion): amount=" . ($amount ?? 'absent'));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  removed C5 smoke account + JEs\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("C5 REPORTING PERIOD ISOLATION — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
