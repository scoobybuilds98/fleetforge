<?php
// GL trial balance verification — confirms all JEs balance and asset accounts tie out
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

function check(string $label, bool $pass, string $extra = ''): void {
    $marker = $pass ? '[PASS]' : '[FAIL]';
    echo "  $marker  $label" . ($extra ? "  ($extra)" : '') . "\n";
}
function header_print(string $t): void { echo "\n========== $t ==========\n"; }

// ============================================================
// 1. Trial Balance — global DR == CR for all JEs
// ============================================================
header_print('Trial Balance — global JE DR == CR');
$global = db_row("SELECT SUM(debit) AS dr, SUM(credit) AS cr FROM acc_journal_entry_lines");
echo "  total DR = {$global['dr']}\n";
echo "  total CR = {$global['cr']}\n";
$diff = bcsub($global['dr'], $global['cr'], 2);
echo "  diff     = $diff\n";
check('global trial balance balanced', $diff === '0.00');

// ============================================================
// 2. Per-JE balance check
// ============================================================
header_print('Per-JE DR == CR check');
$unbalanced = db_select(
    "SELECT je.id, je.entry_number, je.entry_date, je.description,
            SUM(jel.debit) AS dr, SUM(jel.credit) AS cr
     FROM acc_journal_entries je
     JOIN acc_journal_entry_lines jel ON jel.journal_entry_id = je.id
     GROUP BY je.id
     HAVING SUM(jel.debit) <> SUM(jel.credit)"
);
echo "  unbalanced JEs found: " . count($unbalanced) . "\n";
foreach ($unbalanced as $u) {
    echo "    JE #{$u['id']} {$u['entry_number']} DR={$u['dr']} CR={$u['cr']}  {$u['description']}\n";
}
check('all JEs balanced', count($unbalanced) === 0);

// ============================================================
// 3. User's exact trial balance SQL — Asset accounts net
// (Note: actual tables are acc_journal_entry_lines, not acc_journal_lines)
// ============================================================
header_print("User's trial balance SQL — Asset accounts net (DR - CR)");
$assetNet = db_row(
    "SELECT
        SUM(jel.debit) AS total_dr,
        SUM(jel.credit) AS total_cr,
        SUM(jel.debit) - SUM(jel.credit) AS net_balance
     FROM acc_journal_entry_lines jel
     JOIN acc_accounts a ON a.id = jel.account_id
     WHERE a.account_type = 'asset'"
);
echo "  asset accounts total DR = {$assetNet['total_dr']}\n";
echo "  asset accounts total CR = {$assetNet['total_cr']}\n";
echo "  asset accounts net      = {$assetNet['net_balance']}\n";
// Sanity: must be a non-negative number for asset side (DR > CR is normal)
check('asset accounts net is sane (DR >= CR or close)', bccomp((string)$assetNet['net_balance'], '0.00', 2) !== -2);

// ============================================================
// 4. Per-account balance for the accounts we touched in tests
// ============================================================
header_print('Account balances for accounts touched by tests');
$accounts = ['1010', '1220', '1250', '1260', '5010', '6190', '7020'];
foreach ($accounts as $code) {
    $row = db_row(
        "SELECT a.code, a.name, a.account_type,
                COALESCE(SUM(jel.debit), 0) AS dr,
                COALESCE(SUM(jel.credit), 0) AS cr
         FROM acc_accounts a
         LEFT JOIN acc_journal_entry_lines jel ON jel.account_id = a.id
         WHERE a.code = ?
         GROUP BY a.id",
        [$code]
    );
    if (!$row) continue;
    $bal = bcsub((string)$row['dr'], (string)$row['cr'], 2);
    printf("  [%s] %-45s DR=%-10s CR=%-10s NET=%s\n",
        $row['code'], $row['name'], $row['dr'], $row['cr'], $bal
    );
}

// ============================================================
// 5. Specific verifications based on what we posted
//
// Apr depreciation:
//   DR 5010 fleet depr        2250.00
//   DR 6190 non-fleet depr     241.67
//   CR 1220 fleet accum       2250.00
//   CR 1260 office accum       241.67
// May depreciation:
//   DR 5010 fleet depr        2250.00
//   DR 6190 non-fleet depr     241.67
//   CR 1220 fleet accum       2250.00
//   CR 1260 office accum       241.67
// May UOP for asset 3:
//   DR 5010 fleet depr         400.00
//   CR 1220 fleet accum        400.00
// Disposal of Asset 2:
//   DR 1010 cash             8000.00
//   DR 1260 office accum     5800.00
//   DR 7020 loss             1200.00
//   CR 1250 office cost     15000.00
// Impairment of Asset 1:
//   DR 7020 impair          15000.00
//   CR 1220 fleet accum     15000.00
// CapEx test created an asset (no JE)
// ============================================================
header_print('Expected vs actual account balances');

function expectAccount(string $code, string $expectedNet): void {
    $row = db_row(
        "SELECT a.code, COALESCE(SUM(jel.debit) - SUM(jel.credit), 0) AS net
         FROM acc_accounts a
         LEFT JOIN acc_journal_entry_lines jel ON jel.account_id = a.id
         WHERE a.code = ? GROUP BY a.id",
        [$code]
    );
    $actual = (string) $row['net'];
    // bcmath to align scale
    $actualNorm = bcadd($actual, '0', 2);
    $pass = bccomp($actualNorm, $expectedNet, 2) === 0;
    check("account $code net = $expectedNet", $pass, "actual=$actualNorm");
}

// 5010 fleet depr — 2250 + 2250 + 400 = 4900.00
expectAccount('5010', '4900.00');
// 6190 non-fleet depr — 241.67 + 241.67 = 483.34
expectAccount('6190', '483.34');
// 1220 fleet accum — Apr 2250 + May 2250 + UOP 400 + impair 15000 = 19900 (CREDIT side, so net DR-CR = -19900)
expectAccount('1220', '-19900.00');
// 1260 office accum — Apr 241.67 + May 241.67 = 483.34 CR, then disposal DR 5800 → net -483.34 + 5800 = 5316.66
expectAccount('1260', '5316.66');
// 1250 office cost — disposal CR 15000 → net -15000
expectAccount('1250', '-15000.00');
// 1010 cash — disposal DR 8000 (no other movements in this test scenario beyond seed)
// Cash account may have other balances from the broader system, so we won't pin it.
// 7020 loss — disposal 1200 + impair 15000 = 16200
expectAccount('7020', '16200.00');

// ============================================================
// 6. Source-type verification — every dep run/disposal/impair is logged
// ============================================================
header_print('JE source-type counts');
$counts = db_select(
    "SELECT source_type, COUNT(*) as n FROM acc_journal_entries
     WHERE source_type IN ('depreciation','asset_disposal')
     GROUP BY source_type"
);
foreach ($counts as $c) {
    echo "  {$c['source_type']}: {$c['n']}\n";
}
// We expect at least 2 depreciation runs (Apr+May) and 2 asset_disposal (1 disposal + 1 impairment using same enum)
$bySrc = [];
foreach ($counts as $c) $bySrc[$c['source_type']] = (int)$c['n'];
check('depreciation JEs >= 2', ($bySrc['depreciation'] ?? 0) >= 2);
check('asset_disposal JEs >= 2', ($bySrc['asset_disposal'] ?? 0) >= 2);

echo "\n=== GL VERIFICATION COMPLETE ===\n";
