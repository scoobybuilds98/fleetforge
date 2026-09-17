<?php
declare(strict_types=1);

/**
 * tests/_smoke_cash_flow_ties.php
 *
 * Regression lock: the Cash Flow Statement ties to the GL — opening cash + net
 * change in cash = closing cash on EVERY cash account — and the Working Trial
 * Balance compares balances with balances.
 *
 * Bugs this pins (fixed in lib/Accounting/ReportingService.php, S-CASHFLOW-TIE):
 *
 *   1. CASH SET — only account 1010 counted as cash; 1020 Cash — USD Account
 *      (and any other bank / undeposited-funds account) was ignored. Dev 2026
 *      YTD was off $73,698.48 = 1020's +$99,083.78 − the unmapped 2060 below.
 *   2. WORKING CAPITAL — six hard-coded codes, two of them wrong (1050 GST
 *      receivable read as "prepaid", 2070 current DEBT read as "accrued") and
 *      1040/1055/1060/1065/1070/1080/2020/2060 missing (2060 Customer Credits
 *      = −$25,385.30 on dev 2026 YTD). Now every balance-sheet account is
 *      classified from type/subtype/flags.
 *   3. INVESTING — read acc_fixed_assets.acquisition_cost instead of the GL, so
 *      register-only assets showed as cash spent (dev 2023: −$659,924 of cash
 *      that never moved).
 *   4. FINANCING — long-term debt looked for code 25xx (the chart uses 22xx)
 *      and draws looked for "dividend" in the name (3030 Owner Drawings missed).
 *   5. NON-CASH ENTRIES — impairments / lease inception had no add-back, and a
 *      disposal's or FX revaluation's balance-sheet side would double count.
 *   6. WTB — Unadj CY / AJEs / Adj CY were ONE PERIOD's activity while PY
 *      Balance was cumulative (Var $ compared a month with a multi-year
 *      balance); PY revenue was every unclosed year since inception.
 *
 * Part A checks the real dev ledger on several ranges / periods against raw
 * SQL. Part B seeds a scenario touching every classification path and asserts
 * exact statement rows. Part C seeds a WTB scenario with an adjusting entry and
 * a prior unclosed year.
 *
 * HERMETIC: everything runs inside one transaction that is ROLLED BACK. Seeded
 * entries are dated 2031, and every Part B/C figure is a before/after delta, so
 * other data cannot skew it.
 *
 * Run:  php tests/_smoke_cash_flow_ties.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\ReportingService;

$passes = 0;
$failures = [];
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };
$eq   = static function (string $label, string $got, string $want) use ($pass, $fail): void {
    bccomp($got, $want, 2) === 0 ? $pass("{$label} = {$want}") : $fail("{$label}: got {$got}, want {$want}");
};

/**
 * Independent SQL: Σ(debit − credit) on cash accounts over an optional date
 * window. Cash = bank-flagged asset accounts, checking/savings bank-account
 * links, the default cash setting, QBO undeposited funds, "undeposited" names.
 *
 * @param string|null $from YYYY-MM-DD inclusive, or null for inception
 * @param string      $to   YYYY-MM-DD inclusive
 * @return string
 */
function cf_sql_cash(?string $from, string $to): string
{
    $defaultCash = (int) AccountingService::setting('accounting.default_cash_account_id', 0);
    $params = [$defaultCash];
    $fromSql = '';
    if ($from !== null) { $fromSql = 'AND je.entry_date >= ?'; $params[] = $from; }
    $params[] = $to;
    $r = db_row(
        "SELECT COALESCE(SUM(l.debit - l.credit), 0) AS net
           FROM acc_journal_entry_lines l
           JOIN acc_journal_entries je ON je.id = l.journal_entry_id
           JOIN acc_accounts a ON a.id = l.account_id
          WHERE je.status IN ('posted','reversed')
            AND a.account_type = 'asset'
            AND (a.is_bank_account = 1
                 OR a.id = ?
                 OR LOWER(a.name) LIKE '%undeposited%'
                 OR a.id IN (SELECT gl_account_id FROM acc_bank_accounts WHERE account_type IN ('checking','savings'))
                 OR a.id IN (SELECT ff_account_id FROM acc_qbo_account_map WHERE critical_category = 'undeposited_funds' AND ff_account_id IS NOT NULL))
            {$fromSql}
            AND je.entry_date <= ?",
        $params
    );
    return bcadd((string) $r['net'], '0', 2);
}

/**
 * Cash impact of one account's row in a statement section (0.00 when absent).
 *
 * @param array $rows working_capital / investing.lines / financing.lines
 * @param int   $accountId
 * @return string
 */
function cf_row(array $rows, int $accountId): string
{
    foreach ($rows as $r) if ((int) ($r['account_id'] ?? 0) === $accountId) return $r['cash_impact'];
    return '0.00';
}

/**
 * Full statement self-consistency: tie-out, subtotals, and the shared display rows.
 */
function cf_assert_internal(string $tag, array $cf, callable $eq, callable $pass, callable $fail): void
{
    $eq("{$tag}: opening + net change = closing cash per GL", bcadd($cf['opening_cash'], $cf['net_change'], 2), $cf['closing_cash_gl']);
    $cf['is_tied_out'] && bccomp($cf['tie_diff'], '0', 2) === 0
        ? $pass("{$tag}: is_tied_out, tie_diff 0.00") : $fail("{$tag}: tie_diff {$cf['tie_diff']}");
    $eq("{$tag}: operating = NI + non-cash + working capital",
        $cf['operating_cash'], bcadd(bcadd($cf['net_income'], $cf['non_cash']['total'], 2), $cf['working_capital_total'], 2));
    $sum = static fn(array $rows): string => array_reduce($rows, static fn($c, $r) => bcadd($c, $r['cash_impact'], 2), '0.00');
    $eq("{$tag}: investing = lines + disposal proceeds",
        $cf['investing']['net'], bcadd($sum($cf['investing']['lines']), $cf['investing']['asset_disposal_proceeds'], 2));
    $eq("{$tag}: financing = lines", $cf['financing']['net'], $sum($cf['financing']['lines']));
    $eq("{$tag}: net change = op + inv + fin + FX effect", $cf['net_change'],
        bcadd(bcadd(bcadd($cf['operating_cash'], $cf['investing']['net'], 2), $cf['financing']['net'], 2), $cf['fx_effect_on_cash'], 2));
    $closingRow = null;
    foreach (ReportingService::cashFlowStatementRows($cf) as $row) {
        if ($row['type'] === 'total' && $row['label'] === 'Closing cash') $closingRow = $row;
    }
    $eq("{$tag}: display rows carry closing cash", $closingRow['amount'] ?? 'absent', $cf['closing_cash_calc']);
}

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    // ── PART A — the real dev ledger ─────────────────────────────────────
    echo str_repeat('─', 72) . "\nPART A — real ledger: cash flow ranges + WTB periods\n" . str_repeat('─', 72) . "\n";
    $today = date('Y-m-d');
    $ranges = [
        ['2023-01-01', '2023-12-31'], ['2024-01-01', '2024-12-31'], ['2025-01-01', '2025-12-31'],
        ['2026-01-01', $today], ['2000-01-01', $today], ['2025-04-01', '2025-09-30'],
        ['2026-08-01', '2026-08-01'], ['2030-01-01', '2030-12-31'],
    ];
    foreach ($ranges as [$from, $to]) {
        $tag = "{$from}..{$to}";
        $cf  = ReportingService::cashFlow($from, $to);
        cf_assert_internal($tag, $cf, $eq, $pass, $fail);
        $beforeFrom = date('Y-m-d', strtotime($from . ' -1 day'));
        $eq("{$tag}: net change vs SQL Σ cash lines in window", $cf['net_change'], cf_sql_cash($from, $to));
        $eq("{$tag}: opening cash vs SQL", $cf['opening_cash'], cf_sql_cash(null, $beforeFrom));
        $eq("{$tag}: net income vs P&L report", $cf['net_income'], ReportingService::profitAndLoss($from, $to)['net_income']);
    }

    // Every bank-flagged asset account is cash (the 1020 regression).
    $cf = ReportingService::cashFlow('2026-01-01', $today);
    $listed = array_map(static fn($a) => (int) $a['account_id'], $cf['cash_accounts']);
    foreach (db_select("SELECT id, code FROM acc_accounts WHERE account_type = 'asset' AND is_bank_account = 1 AND is_active = 1") as $b) {
        in_array((int) $b['id'], $listed, true)
            ? $pass("bank account {$b['code']} counted as cash") : $fail("bank account {$b['code']} missing from cash_accounts");
    }

    // WTB on real periods: balanced, balance basis, like-for-like variance.
    $periods = db_select(
        "SELECT id, name, start_date, end_date, year, status FROM acc_periods
          WHERE (year = 2025 AND month IN (1, 12)) OR (year = 2026 AND month IN (7, 9)) ORDER BY year, month"
    );
    foreach ($periods as $p) {
        $pyEnd = ((int) $p['year'] - 1) . '-12-31';
        $w = ReportingService::workingTrialBalance($p, ['id' => null, 'name' => 'PY', 'end_date' => $pyEnd]);
        $w['is_balanced'] ? $pass("WTB {$p['name']}: balanced ({$w['totals']['debits']})")
                          : $fail("WTB {$p['name']}: UNBALANCED dr {$w['totals']['debits']} cr {$w['totals']['credits']}");
        $bs = ReportingService::balanceSheet($p['end_date']);
        $fyStart = $p['year'] . '-01-01';
        $bad = 0;
        foreach ($w['accounts'] as $r) {
            if (bccomp(bcadd($r['unadj_cy'], $r['ajes'], 2), $r['adj_cy'], 2) !== 0) $bad++;
            if (bccomp(bcsub($r['adj_cy'], $r['py_balance'], 2), $r['var_amt'], 2) !== 0) $bad++;
            if ($r['is_computed']) {
                $eq("WTB {$p['name']}: unclosed prior earnings = balance sheet's", $r['adj_cy'], $bs['prior_unclosed_earnings']);
                continue;
            }
            if (in_array($r['account_type'], ['asset', 'liability', 'equity'], true)) {
                // Balance-sheet rows are the cumulative balance at period end, not the period's activity.
                if (bccomp($r['adj_cy'], AccountingService::accountBalance((int) $r['account_id'], $p['end_date']), 2) !== 0) $bad++;
            } else {
                $ytd = db_row(
                    "SELECT COALESCE(SUM(l.debit - l.credit), 0) AS n FROM acc_journal_entry_lines l
                       JOIN acc_journal_entries je ON je.id = l.journal_entry_id
                      WHERE je.status IN ('posted','reversed') AND l.account_id = ? AND je.entry_date BETWEEN ? AND ?",
                    [(int) $r['account_id'], $fyStart, $p['end_date']]
                )['n'];
                $want = $r['normal_balance'] === 'debit' ? bcadd((string) $ytd, '0', 2) : bcmul((string) $ytd, '-1', 2);
                if (bccomp($r['adj_cy'], $want, 2) !== 0) $bad++;
            }
        }
        $bad === 0 ? $pass("WTB {$p['name']}: every row is a balance (BS cumulative, P&L fiscal YTD) and Unadj+AJE=Adj, Var=Adj−PY")
                   : $fail("WTB {$p['name']}: {$bad} row check(s) failed");
    }

    // ── PART B — seeded cash flow scenario, exact rows ───────────────────
    echo str_repeat('─', 72) . "\nPART B — seeded entries across every classification path\n" . str_repeat('─', 72) . "\n";
    $PID  = getmypid();
    $FROM = '2031-01-01';
    $TO   = '2031-12-31';
    $anyPeriod = (int) (db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$anyPeriod) { echo "SETUP FAIL: no acc_periods\n"; $pdo->rollBack(); exit(2); }

    $before = ReportingService::cashFlow($FROM, $TO);
    $beforeHalf = ReportingService::cashFlow($FROM, '2031-06-30');

    $mkAcct = static function (string $tag, string $type, string $normal, ?string $subtype = null, ?string $name = null, int $bank = 0) use ($PID): int {
        return (int) db_insert('acc_accounts', [
            'code' => "ZC{$tag}{$PID}", 'name' => $name ?? "CF smoke {$tag}", 'account_type' => $type,
            'account_subtype' => $subtype, 'normal_balance' => $normal, 'is_active' => 1, 'is_header' => 0,
            'is_bank_account' => $bank,
        ]);
    };
    $bank      = $mkAcct('BK', 'asset', 'debit', 'current_asset', null, 1);                 // flagged bank
    $usdBank   = $mkAcct('US', 'asset', 'debit', 'current_asset');                          // bank only via acc_bank_accounts
    $undep     = $mkAcct('UF', 'asset', 'debit', 'current_asset', "Undeposited Funds smoke {$PID}");
    $ar        = $mkAcct('AR', 'asset', 'debit', 'current_asset');
    $allowance = $mkAcct('AL', 'asset', 'credit', 'current_asset');                         // contra
    $prepaid   = $mkAcct('PP', 'asset', 'debit', 'current_asset');
    $faCost    = $mkAcct('FC', 'asset', 'debit', 'fixed_asset');
    $accumDep  = $mkAcct('AD', 'asset', 'credit', 'fixed_asset');
    $ap        = $mkAcct('AP', 'liability', 'credit', 'current_liability');
    $credits   = $mkAcct('CC', 'liability', 'credit', 'current_liability');
    $loan      = $mkAcct('LN', 'liability', 'credit', 'long_term_liability');
    $curDebt   = $mkAcct('CD', 'liability', 'credit', 'current_liability', "Current Portion of Long-Term Debt smoke {$PID}");
    $loc       = $mkAcct('LC', 'liability', 'credit', 'current_liability');                 // financing via bank link
    $shares    = $mkAcct('SH', 'equity', 'credit', 'equity');
    $drawings  = $mkAcct('DR', 'equity', 'debit', 'equity');
    $retained  = $mkAcct('RE', 'equity', 'credit', 'equity');
    $revenue   = $mkAcct('RV', 'revenue', 'credit', 'revenue');
    $expense   = $mkAcct('EX', 'operating_expense', 'debit', 'operating_expense');
    $deprExp   = $mkAcct('DX', 'cost_of_revenue', 'debit', 'cost_of_revenue');
    $gain      = $mkAcct('GN', 'other_income', 'credit', 'other');
    $impLoss   = $mkAcct('IL', 'other_expense', 'debit', 'other');
    db_insert('acc_bank_accounts', ['name' => "CF smoke USD {$PID}", 'gl_account_id' => $usdBank, 'account_type' => 'checking', 'currency' => 'USD']);
    db_insert('acc_bank_accounts', ['name' => "CF smoke LOC {$PID}", 'gl_account_id' => $loc, 'account_type' => 'line_of_credit']);
    $niCurrent = (int) AccountingService::setting('accounting.lessor_ni_current_account_id', 0);
    if ($niCurrent <= 0) { echo "SETUP FAIL: accounting.lessor_ni_current_account_id not set\n"; $pdo->rollBack(); exit(2); }

    $seq = 0;
    /** @param array<int, array{0:int,1:string,2:string}> $lines [account, debit, credit] */
    $je = static function (string $date, array $lines, ?string $source = null, string $status = 'posted', string $type = 'manual')
        use (&$seq, $PID, $anyPeriod): int {
        $seq++;
        $id = (int) db_insert('acc_journal_entries', [
            'entry_number' => "CF-{$PID}-{$seq}", 'period_id' => $anyPeriod, 'entry_date' => $date,
            'description' => "CF smoke {$seq}", 'entry_type' => $type, 'status' => $status, 'source_type' => $source,
        ]);
        foreach ($lines as $n => [$acct, $dr, $cr]) {
            db_insert('acc_journal_entry_lines', ['journal_entry_id' => $id, 'account_id' => $acct, 'line_number' => $n + 1, 'debit' => $dr, 'credit' => $cr]);
        }
        return $id;
    };

    $je('2031-01-10', [[$ar, '1000.00', '0'], [$revenue, '0', '1000.00']], 'invoice');
    $je('2031-01-20', [[$bank, '600.00', '0'], [$ar, '0', '600.00']], 'payment');
    $je('2031-01-21', [[$usdBank, '300.00', '0'], [$ar, '0', '300.00']], 'payment');
    $je('2031-01-25', [[$undep, '75.00', '0'], [$ar, '0', '75.00']], 'payment');
    $je('2031-02-01', [[$prepaid, '120.00', '0'], [$bank, '0', '120.00']], 'bank_transaction');
    $je('2031-02-05', [[$expense, '400.00', '0'], [$ap, '0', '400.00']], 'ap_bill');
    $je('2031-02-15', [[$ap, '250.00', '0'], [$bank, '0', '250.00']], 'ap_payment');
    $je('2031-03-01', [[$faCost, '5000.00', '0'], [$bank, '0', '2000.00'], [$loan, '0', '3000.00']]);   // equipment, part loan-financed
    $je('2031-03-31', [[$deprExp, '700.00', '0'], [$accumDep, '0', '700.00']], 'depreciation');
    $je('2031-04-15', [[$bank, '900.00', '0'], [$accumDep, '300.00', '0'], [$faCost, '0', '1000.00'], [$gain, '0', '200.00']], 'asset_disposal');
    $je('2031-04-30', [[$usdBank, '50.00', '0'], [$ar, '20.00', '0'], [$gain, '0', '70.00']], 'fx_revaluation');
    $je('2031-05-01', [[$loan, '400.00', '0'], [$curDebt, '0', '400.00']]);                             // reclass to current
    $je('2031-05-15', [[$curDebt, '400.00', '0'], [$bank, '0', '400.00']]);                             // repayment
    $je('2031-05-20', [[$drawings, '150.00', '0'], [$bank, '0', '150.00']]);
    $je('2031-05-21', [[$bank, '1000.00', '0'], [$shares, '0', '1000.00']]);
    $je('2031-05-22', [[$bank, '500.00', '0'], [$loc, '0', '500.00']]);
    $je('2031-06-01', [[$revenue, '80.00', '0'], [$credits, '0', '80.00']], 'credit_note');
    $orig = $je('2031-06-10', [[$bank, '999.00', '0'], [$revenue, '0', '999.00']], 'payment', 'reversed');
    $rev  = $je('2031-06-12', [[$revenue, '999.00', '0'], [$bank, '0', '999.00']], 'payment');
    db_execute("UPDATE acc_journal_entries SET reversed_by_id = ? WHERE id = ?", [$rev, $orig]);
    db_execute("UPDATE acc_journal_entries SET is_reversal = 1, reversal_of_id = ? WHERE id = ?", [$orig, $rev]);
    $je('2031-06-15', [[$bank, '12345.00', '0'], [$revenue, '0', '12345.00']], null, 'draft');          // drafts never count
    $je('2031-07-01', [[$impLoss, '60.00', '0'], [$accumDep, '0', '60.00']], 'impairment');
    $je('2031-07-02', [[$expense, '45.00', '0'], [$allowance, '0', '45.00']]);                         // provision → WC
    // Sales-type lease inception: equipment out, NI receivable in, gain 200 — no cash.
    $je('2031-08-01', [[$niCurrent, '700.00', '0'], [$accumDep, '100.00', '0'], [$expense, '500.00', '0'],
                       [$faCost, '0', '600.00'], [$revenue, '0', '700.00']], 'lease_inception');
    $je('2031-09-01', [[$ar, '100.00', '0'], [$niCurrent, '0', '80.00'], [$revenue, '0', '20.00']], 'lease_period');
    $je('2031-09-15', [[$bank, '100.00', '0'], [$ar, '0', '100.00']], 'payment');
    $je('2031-12-31', [[$revenue, '1640.00', '0'], [$retained, '0', '1640.00']], 'year_end', 'posted', 'year_end');

    $after = ReportingService::cashFlow($FROM, $TO);
    cf_assert_internal('seeded 2031', $after, $eq, $pass, $fail);
    $d = static fn(string $a, string $b): string => bcsub($a, $b, 2);

    // Cash: bank 180 + USD bank 350 + undeposited 75.
    $eq('Δ net change in cash', $d($after['net_change'], $before['net_change']), '605.00');
    $eq('Δ net change vs SQL Σ cash lines', $d($after['net_change'], $before['net_change']),
        bcsub(cf_sql_cash($FROM, $TO), $before['net_change'], 2));
    $cashIds = array_map(static fn($a) => (int) $a['account_id'], $after['cash_accounts']);
    in_array($usdBank, $cashIds, true) ? $pass('checking-linked account (no flag) is cash') : $fail('checking-linked account not cash');
    in_array($undep, $cashIds, true)   ? $pass('"Undeposited Funds" account is cash')      : $fail('undeposited funds not cash');
    in_array($loc, $cashIds, true)     ? $fail('line-of-credit account treated as cash')   : $pass('line-of-credit account is not cash');

    // Net income 205 = revenue (1000−80+700+20) − expenses (400+45+500) − depreciation 700 + gains 270 − impairment 60.
    // The year-end close and the draft are excluded; the reversed pair nets out.
    $eq('Δ net income', $d($after['net_income'], $before['net_income']), '205.00');
    $eq('Δ depreciation add-back', $d($after['non_cash']['depreciation'], $before['non_cash']['depreciation']), '700.00');
    $eq('Δ impairment add-back', $d($after['non_cash']['impairment'], $before['non_cash']['impairment']), '60.00');
    $eq('Δ disposal (gain) add-back', $d($after['non_cash']['asset_disposal'], $before['non_cash']['asset_disposal']), '-200.00');
    $eq('Δ unrealized FX (gain) add-back', $d($after['non_cash']['fx_revaluation'], $before['non_cash']['fx_revaluation']), '-70.00');
    $eq('Δ lease inception (gain) add-back', $d($after['non_cash']['lease_inception'], $before['non_cash']['lease_inception']), '-200.00');

    $wc = $after['working_capital'];
    $eq('WC AR (−1000 +600 +300 +75 −100 +100; FX line excluded)', cf_row($wc, $ar), '-25.00');
    $eq('WC contra allowance', cf_row($wc, $allowance), '45.00');
    $eq('WC prepaid', cf_row($wc, $prepaid), '-120.00');
    $eq('WC AP', cf_row($wc, $ap), '150.00');
    $eq('WC customer credits (was unmapped)', cf_row($wc, $credits), '80.00');
    $eq('Δ operating cash', $d($after['operating_cash'], $before['operating_cash']), '625.00');

    $inv = $after['investing']['lines'];
    $eq('investing: equipment purchase (disposal + lease derecognition excluded)', cf_row($inv, $faCost), '-5000.00');
    $eq('investing: accumulated depreciation never a cash row', cf_row($inv, $accumDep), '0.00');
    $eq('Δ investing: NI lease principal collected', $d(cf_row($inv, $niCurrent), cf_row($before['investing']['lines'], $niCurrent)), '80.00');
    $eq('Δ disposal proceeds (the cash line)', $d($after['investing']['asset_disposal_proceeds'], $before['investing']['asset_disposal_proceeds']), '900.00');
    $eq('Δ investing', $d($after['investing']['net'], $before['investing']['net']), '-4020.00');

    $fin = $after['financing']['lines'];
    $eq('financing: long-term loan (22xx-style subtype, not code 25xx)', cf_row($fin, $loan), '2600.00');
    $eq('financing: current portion of LTD nets reclass vs repayment', cf_row($fin, $curDebt), '0.00');
    $eq('financing: line of credit (bank link)', cf_row($fin, $loc), '500.00');
    $eq('financing: share capital', cf_row($fin, $shares), '1000.00');
    $eq('financing: owner drawings (not name-matched "dividend")', cf_row($fin, $drawings), '-150.00');
    $eq('financing: retained earnings untouched by the year-end close', cf_row($fin, $retained), '0.00');
    $eq('Δ financing', $d($after['financing']['net'], $before['financing']['net']), '3950.00');
    $eq('Δ FX effect on cash (USD bank revaluation)', $d($after['fx_effect_on_cash'], $before['fx_effect_on_cash']), '50.00');

    // A window that stops mid-scenario still ties.
    $half = ReportingService::cashFlow($FROM, '2031-06-30');
    cf_assert_internal('seeded 2031 H1', $half, $eq, $pass, $fail);
    $eq('H1 Δ net change vs SQL', $d($half['net_change'], $beforeHalf['net_change']),
        bcsub(cf_sql_cash($FROM, '2031-06-30'), $beforeHalf['net_change'], 2));
    $h2 = ReportingService::cashFlow('2031-07-01', $TO);
    cf_assert_internal('seeded 2031 H2', $h2, $eq, $pass, $fail);
    $eq('H2 opening cash = H1 closing cash', $h2['opening_cash'], $half['closing_cash_gl']);

    // ── PART C — WTB seeded: balances, AJE split, unclosed prior year ────
    echo str_repeat('─', 72) . "\nPART C — Working Trial Balance on a seeded period\n" . str_repeat('─', 72) . "\n";
    $mar = db_row("SELECT id, name, start_date, end_date, year, status FROM acc_periods WHERE year = 2031 AND month = 3");
    if (!$mar) {
        $marId = (int) db_insert('acc_periods', ['year' => 2031, 'month' => 3, 'name' => 'March 2031', 'start_date' => '2031-03-01', 'end_date' => '2031-03-31']);
        $mar = db_row("SELECT id, name, start_date, end_date, year, status FROM acc_periods WHERE id = ?", [$marId]);
    }
    $pyP = ['id' => null, 'name' => 'PY', 'end_date' => '2030-12-31'];
    $wBefore = ReportingService::workingTrialBalance($mar, $pyP);

    $wRev = $mkAcct('WR', 'revenue', 'credit', 'revenue');
    $wAr  = $mkAcct('WA', 'asset', 'debit', 'current_asset');
    $je('2030-11-15', [[$wAr, '500.00', '0'], [$wRev, '0', '500.00']]);                 // prior (unclosed) year
    $je('2031-01-15', [[$wAr, '300.00', '0'], [$wRev, '0', '300.00']]);
    $je('2031-03-10', [[$wAr, '200.00', '0'], [$wRev, '0', '200.00']]);
    $ajeId = $je('2031-03-31', [[$wRev, '50.00', '0'], [$wAr, '0', '50.00']], null, 'posted', 'adjusting');
    db_execute("UPDATE acc_journal_entries SET period_id = ? WHERE id = ?", [(int) $mar['id'], $ajeId]);
    db_execute("UPDATE acc_accounts SET is_active = 0 WHERE id = ?", [$wAr]);          // inactive, still carries a balance

    $w = ReportingService::workingTrialBalance($mar, $pyP);
    $w['is_balanced'] ? $pass('seeded WTB balanced') : $fail("seeded WTB UNBALANCED dr {$w['totals']['debits']} cr {$w['totals']['credits']}");
    $find = static function (array $wtb, int|string $id): ?array {
        foreach ($wtb['accounts'] as $r) if ((string) $r['account_id'] === (string) $id) return $r;
        return null;
    };
    $r = $find($w, $wRev);
    $eq('revenue Adj CY = fiscal YTD (300 + 200 − 50), not since inception', $r['adj_cy'] ?? 'absent', '450.00');
    $eq('revenue AJEs', $r['ajes'] ?? 'absent', '-50.00');
    $eq('revenue Unadj CY = Adj − AJEs', $r['unadj_cy'] ?? 'absent', '500.00');
    $eq('revenue PY = 2030 fiscal year', $r['py_balance'] ?? 'absent', '500.00');
    $eq('revenue Var = Adj − PY', $r['var_amt'] ?? 'absent', '-50.00');
    $r = $find($w, $wAr);
    $r ? $pass('inactive account with a balance still listed') : $fail('inactive account with a balance dropped');
    $eq('AR Adj CY = cumulative balance at period end (500 + 300 + 200 − 50)', $r['adj_cy'] ?? 'absent', '950.00');
    $eq('AR Unadj CY', $r['unadj_cy'] ?? 'absent', '1000.00');
    $eq('AR PY = balance at 2030-12-31', $r['py_balance'] ?? 'absent', '500.00');
    $eq('AR Var', $r['var_amt'] ?? 'absent', '450.00');
    $re0 = $find($wBefore, 'retained_earnings_unclosed');
    $re1 = $find($w, 'retained_earnings_unclosed');
    $eq('Δ unclosed prior-year earnings row (2030 revenue now prior year)',
        bcsub($re1['adj_cy'] ?? '0', $re0['adj_cy'] ?? '0', 2), '500.00');
    $eq('Δ unclosed row PY column (2030 was the PY fiscal year itself)',
        bcsub($re1['py_balance'] ?? '0', $re0['py_balance'] ?? '0', 2), '0.00');
} catch (\Throwable $e) {
    $fail('exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n  (rolled back — no rows persisted)\n";
}

echo str_repeat('─', 72) . "\n";
echo 'CASH FLOW TIES + WTB BALANCES — ' . $passes . ' passed, ' . count($failures) . " failed\n";
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
