<?php
declare(strict_types=1);

/**
 * tests/_smoke_balance_sheet_balances.php
 *
 * Regression lock: the Balance Sheet balances (Assets = Liabilities + Equity)
 * and every line is signed the way a reader expects.
 *
 * Bugs this pins (all fixed in lib/Accounting/ReportingService.php +
 * AccountingService::LEDGER_STATUSES_SQL):
 *
 *   1. CONTRA SIGN — balanceSheetSection() summed each account's balance on
 *      its OWN normal side, so 1220 Accumulated Depreciation (a credit-normal
 *      asset) was ADDED to Total Assets. Dev drift was $2.16M: 2 × $956,846.69
 *      of depreciation plus (2).
 *   2. UNCLOSED PRIOR YEARS — only year-to-date income was injected into
 *      equity, so every never-closed earlier year's profit was missing.
 *   3. REVERSED ENTRIES — reports read status = 'posted' only; reverse() flips
 *      the original to 'reversed' and posts an offsetting entry, so a reversed
 *      entry counted as its own negative instead of netting to zero.
 *   4. YEAR-END CLOSE — the P&L included the closing JE, reading $0 for any
 *      closed year; the balance sheet must still include it.
 *   5. TAX PROVISION — GST/PST remittances (a liability settlement) were
 *      subtracted from net income.
 *
 * Part A checks the REAL dev ledger balances on several as-of dates and ties
 * the totals to a raw SQL sum over journal lines. Part B seeds dedicated
 * accounts + entries and asserts exact deltas.
 *
 * HERMETIC: everything runs inside one transaction that is ROLLED BACK.
 * InnoDB REPEATABLE READ gives the transaction a stable snapshot, so entries
 * other sessions commit mid-run cannot skew the before/after deltas.
 *
 * Run:  php tests/_smoke_balance_sheet_balances.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\ReportingService;

$passes = 0;
$failures = [];
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };
$eq   = static function (string $label, string $got, string $want) use ($pass, $fail): void {
    bccomp($got, $want, 2) === 0 ? $pass("{$label} = {$want}") : $fail("{$label}: got {$got}, want {$want}");
};

/**
 * Find a row by account_id across the sections of a balance-sheet block.
 *
 * @param array      $bs        ReportingService::balanceSheet() result
 * @param int|string $accountId account id (or synthetic key)
 * @return array{0:string|null,1:array|null} [section name, row]
 */
function bs_find_row(array $bs, int|string $accountId): array
{
    foreach (['current_assets', 'long_term_assets', 'current_liabilities', 'long_term_liabilities', 'equity'] as $sec) {
        foreach ($bs[$sec] as $row) {
            if ((string) $row['account_id'] === (string) $accountId) return [$sec, $row];
        }
    }
    return [null, null];
}

/**
 * Raw ledger totals as of a date, straight from journal lines — the independent
 * cross-check for the report (posted + reversed entries; see LEDGER_STATUSES_SQL).
 *
 * @param string $asOf YYYY-MM-DD
 * @return array{assets:string, liabilities:string, equity:string}
 */
function bs_sql_totals(string $asOf): array
{
    $r = db_row(
        "SELECT
            COALESCE(SUM(CASE WHEN a.account_type = 'asset' THEN l.debit - l.credit END), 0) AS assets,
            COALESCE(SUM(CASE WHEN a.account_type = 'liability' THEN l.credit - l.debit END), 0) AS liabilities,
            COALESCE(SUM(CASE WHEN a.account_type NOT IN ('asset','liability') THEN l.credit - l.debit END), 0) AS equity
           FROM acc_journal_entry_lines l
           JOIN acc_journal_entries je ON je.id = l.journal_entry_id
           JOIN acc_accounts a ON a.id = l.account_id
          WHERE je.status IN ('posted','reversed')
            AND je.entry_date <= ?",
        [$asOf]
    );
    return [
        'assets'      => bcadd((string) $r['assets'], '0', 2),
        'liabilities' => bcadd((string) $r['liabilities'], '0', 2),
        'equity'      => bcadd((string) $r['equity'], '0', 2),
    ];
}

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    // ── PART A — the real dev ledger ─────────────────────────────────────
    echo str_repeat('─', 72) . "\nPART A — real ledger balances on several as-of dates\n" . str_repeat('─', 72) . "\n";
    foreach (['2024-06-30', '2025-12-31', date('Y-m-d')] as $asOf) {
        $bs = ReportingService::balanceSheet($asOf);
        $bs['is_balanced']
            ? $pass("{$asOf}: balanced (assets {$bs['total_assets']} = L+E {$bs['total_liabilities_and_equity']})")
            : $fail("{$asOf}: UNBALANCED — drift {$bs['drift']}");
        $sql = bs_sql_totals($asOf);
        $eq("{$asOf}: total_assets vs SQL sum of asset lines", $bs['total_assets'], $sql['assets']);
        $eq("{$asOf}: total_liabilities vs SQL", $bs['total_liabilities'], $sql['liabilities']);
        // Equity + earnings ≡ credit − debit over every non-asset, non-liability line.
        $eq("{$asOf}: total_equity (accounts + earnings) vs SQL", $bs['total_equity'], $sql['equity']);
    }

    // ── PART B — seeded scenario, exact deltas ───────────────────────────
    echo str_repeat('─', 72) . "\nPART B — seeded contra / drawings / reversal / year-end entries\n" . str_repeat('─', 72) . "\n";
    $PID  = getmypid();
    $ASOF = '2027-06-30';
    $anyPeriod = (int) (db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$anyPeriod) { echo "SETUP FAIL: no acc_periods\n"; $pdo->rollBack(); exit(2); }
    $periodFor = static function (string $date) use ($anyPeriod): int {
        $p = db_row("SELECT id FROM acc_periods WHERE start_date <= ? AND end_date >= ? LIMIT 1", [$date, $date]);
        return $p ? (int) $p['id'] : $anyPeriod;
    };

    $before = ReportingService::balanceSheet($ASOF);
    $plPyBefore = ReportingService::profitAndLoss('2026-11-01', '2026-12-31');

    $mkAcct = static function (string $tag, string $type, string $normal, ?string $subtype = null) use ($PID): int {
        return (int) db_insert('acc_accounts', [
            'code' => "ZB{$tag}{$PID}", 'name' => "BS smoke {$tag}", 'account_type' => $type,
            'account_subtype' => $subtype, 'normal_balance' => $normal, 'is_active' => 1, 'is_header' => 0,
        ]);
    };
    $cash     = $mkAcct('CA', 'asset', 'debit');
    $accumDep = $mkAcct('AD', 'asset', 'credit', 'fixed_asset');   // contra asset
    $loan     = $mkAcct('LN', 'liability', 'credit');
    $drawings = $mkAcct('DR', 'equity', 'debit');                  // contra equity
    $retained = $mkAcct('RE', 'equity', 'credit');
    $revenue  = $mkAcct('RV', 'revenue', 'credit');
    $deprExp  = $mkAcct('DX', 'cost_of_revenue', 'debit');

    $seq = 0;
    $je = static function (string $date, string $status, int $drAcct, int $crAcct, string $amt, ?string $source = null)
        use (&$seq, $PID, $periodFor): int {
        $seq++;
        $id = (int) db_insert('acc_journal_entries', [
            'entry_number' => "BS-{$PID}-{$seq}", 'period_id' => $periodFor($date), 'entry_date' => $date,
            'description' => "BS smoke {$seq}", 'entry_type' => $source === 'year_end' ? 'year_end' : 'manual',
            'status' => $status, 'source_type' => $source,
        ]);
        db_insert('acc_journal_entry_lines', ['journal_entry_id' => $id, 'account_id' => $drAcct, 'line_number' => 1, 'debit' => $amt, 'credit' => '0.00']);
        db_insert('acc_journal_entry_lines', ['journal_entry_id' => $id, 'account_id' => $crAcct, 'line_number' => 2, 'debit' => '0.00', 'credit' => $amt]);
        return $id;
    };

    $je('2026-11-15', 'posted', $cash, $revenue, '500.00');          // prior-year (unclosed) revenue
    $je('2027-03-15', 'posted', $deprExp, $accumDep, '1000.00');     // depreciation → contra asset
    $je('2027-04-10', 'posted', $drawings, $cash, '200.00');         // owner draw → contra equity
    $je('2027-05-01', 'posted', $cash, $loan, '300.00');             // loan
    $orig = $je('2027-05-10', 'reversed', $cash, $revenue, '700.00'); // original, later reversed…
    $rev  = $je('2027-05-20', 'posted', $revenue, $cash, '700.00');   // …by this offsetting entry
    db_execute("UPDATE acc_journal_entries SET reversed_by_id = ? WHERE id = ?", [$rev, $orig]);
    db_execute("UPDATE acc_journal_entries SET is_reversal = 1, reversal_of_id = ? WHERE id = ?", [$orig, $rev]);

    $after = ReportingService::balanceSheet($ASOF);
    $after['is_balanced'] ? $pass("seeded: still balanced (drift 0.00)") : $fail("seeded: UNBALANCED — drift {$after['drift']}");
    $eq('Δ total_assets (+500 −1000 −200 +300 +700 −700)', bcsub($after['total_assets'], $before['total_assets'], 2), '-400.00');
    $eq('Δ total_liabilities', bcsub($after['total_liabilities'], $before['total_liabilities'], 2), '300.00');
    $eq('Δ total_equity (+500 prior −1000 NI −200 drawings)', bcsub($after['total_equity'], $before['total_equity'], 2), '-700.00');
    $eq('Δ prior_unclosed_earnings', bcsub($after['prior_unclosed_earnings'], $before['prior_unclosed_earnings'], 2), '500.00');
    $eq('Δ net_income_injected', bcsub($after['net_income_injected'], $before['net_income_injected'], 2), '-1000.00');

    [$sec, $row] = bs_find_row($after, $accumDep);
    $eq('Accumulated depreciation row is NEGATIVE', $row['amount'] ?? 'absent', '-1000.00');
    $sec === 'long_term_assets' ? $pass('contra fixed_asset lands in long_term_assets (subtype hint)') : $fail("contra row section = " . var_export($sec, true));
    [, $row] = bs_find_row($after, $drawings);
    $eq('Owner drawings row is NEGATIVE', $row['amount'] ?? 'absent', '-200.00');
    [, $row] = bs_find_row($after, $cash);
    $eq('Cash row nets the reversed entry to zero (500 −200 +300 +700 −700)', $row['amount'] ?? 'absent', '600.00');

    $sql = bs_sql_totals($ASOF);
    $eq('seeded: total_assets vs SQL', $after['total_assets'], $sql['assets']);
    $eq('seeded: total_equity vs SQL', $after['total_equity'], $sql['equity']);

    // Section rows add up to the section total (earnings are rows, not a hidden add-on).
    $eqRows = '0.00';
    foreach ($after['equity'] as $r) $eqRows = bcadd($eqRows, $r['amount'], 2);
    $eq('equity rows sum to total_equity', $eqRows, $after['total_equity']);

    $plCy = ReportingService::profitAndLoss('2027-01-01', $ASOF);
    $revRow = null;
    foreach ($plCy['revenue'] as $r) if ((int) $r['account_id'] === $revenue) $revRow = $r;
    $revRow === null ? $pass('P&L: reversed revenue entry nets to zero (row absent)') : $fail('P&L: reversed revenue still shows ' . $revRow['amount']);
    $eq('P&L tax_provision (sales-tax remittances are not an expense)', $plCy['tax_provision'], '0.00');

    // Year-end close of 2026: DR revenue / CR retained earnings, source_type year_end.
    $je('2026-12-31', 'posted', $revenue, $retained, '500.00', 'year_end');
    $closed = ReportingService::balanceSheet($ASOF);
    $closed['is_balanced'] ? $pass('after year-end close JE: still balanced') : $fail("after close: UNBALANCED — drift {$closed['drift']}");
    $eq('after close: Δ prior_unclosed_earnings back to 0 (moved into RE)', bcsub($closed['prior_unclosed_earnings'], $before['prior_unclosed_earnings'], 2), '0.00');
    [, $row] = bs_find_row($closed, $retained);
    $eq('after close: retained earnings account carries it', $row['amount'] ?? 'absent', '500.00');
    $eq('after close: Δ total_equity unchanged', bcsub($closed['total_equity'], $before['total_equity'], 2), '-700.00');

    $plPy = ReportingService::profitAndLoss('2026-11-01', '2026-12-31');
    $revRow = null;
    foreach ($plPy['revenue'] as $r) if ((int) $r['account_id'] === $revenue) $revRow = $r;
    $eq('P&L of the CLOSED year still shows the revenue (closing JE excluded)', $revRow['amount'] ?? 'absent', '500.00');
    $eq('P&L closed-year Δ net income', bcsub($plPy['net_income'], $plPyBefore['net_income'], 2), '500.00');
} catch (\Throwable $e) {
    $fail('exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n  (rolled back — no rows persisted)\n";
}

echo str_repeat('─', 72) . "\n";
echo 'BALANCE SHEET BALANCES — ' . $passes . ' passed, ' . count($failures) . " failed\n";
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
