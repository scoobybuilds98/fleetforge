<?php
declare(strict_types=1);

/**
 * tests/_smoke_sop_known_issues.php
 *
 * S-SOP-KNOWN-ISSUES — the ledger fixes for the in-app SOP's chapter 12
 * backlog (I1–I11, I15, I17 rules), executed against the REAL schema and the
 * REAL services inside ONE transaction that is rolled back at the end
 * (hermetic: nothing it posts survives).
 *
 *   I1  rental lines resolve per equipment category; mileage types → 4060
 *   I2  asset create posts DR asset / CR paid-from; opening balance posts
 *       nothing; no basis is refused; betterment from an account posts
 *   I3  an unapplied vendor credit does not show as AP drift
 *   I4  GST remittance clears 2030 AND 1050; refund periods post DR bank
 *   I5  CAD→USD transfer posts each leg at its CAD value + foreign amount
 *   I6  a year_end entry may post into a CLOSED (not locked) month; nothing
 *       else may; reopen/unlock endpoint exists
 *   I7  FX revaluation refuses a closed period up front
 *   I8  bank opening balance posts DR bank / CR 3050, re-posts on change
 *   I9  automatic entries are blocked from manual reversal
 *   I10 a payment's bank account decides the cash GL
 *   I11 reconciliation Difference = statement − (beginning + cleared)
 *   I15 unit-tagged operating expenses are direct costs, not overhead
 *
 * Run:  php tests/_smoke_sop_known_issues.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-SOP-KNOWN-ISSUES
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\AutoEntryBridge;
use FleetForge\Accounting\BankService;
use FleetForge\Accounting\FixedAssetService;
use FleetForge\Accounting\FxRevaluationService;
use FleetForge\Accounting\JournalEntryService;
use FleetForge\Accounting\TaxFilingService;
use FleetForge\Accounting\UnitProfitabilityService;

$failures = [];
$passes   = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$passes): void {
    if ($ok) { $passes++; echo "  \033[32mPASS\033[0m — {$label}\n"; }
    else     { $failures[] = $label; echo "  \033[31mFAIL\033[0m — {$label}\n"; }
};
$throws = static function (callable $fn, string $needle = ''): ?string {
    try { $fn(); return null; } catch (\Throwable $e) {
        return ($needle === '' || stripos($e->getMessage(), $needle) !== false) ? $e->getMessage() : ('UNEXPECTED: ' . $e->getMessage());
    }
};
$acct = static fn(string $code): int => (int) (db_row("SELECT id FROM acc_accounts WHERE code = ?", [$code])['id'] ?? 0);
$jeLines = static fn(int $jeId): array => db_select(
    "SELECT a.code, l.debit, l.credit, l.foreign_amount, l.foreign_currency FROM acc_journal_entry_lines l
       JOIN acc_accounts a ON a.id = l.account_id WHERE l.journal_entry_id = ? ORDER BY l.line_number, l.id", [$jeId]);
$lineFor = static function (array $lines, string $code, string $side): string {
    $t = '0.00';
    foreach ($lines as $l) if ($l['code'] === $code) $t = bcadd($t, (string) $l[$side], 2);
    return $t;
};

// ── Setup ──────────────────────────────────────────────────────
$user = db_row("SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id WHERE r.slug = 'super_admin' AND u.status = 'active' ORDER BY u.id LIMIT 1");
if (!$user) { fwrite(STDERR, "setup: no active super admin\n"); exit(2); }
$uid = (int) $user['id'];
$openPeriod = db_row("SELECT * FROM acc_periods WHERE status = 'open' AND end_date < ? ORDER BY end_date DESC LIMIT 1", [ff_today()]);
if (!$openPeriod) { fwrite(STDERR, "setup: need a finished, open accounting period\n"); exit(2); }
$D = (string) $openPeriod['start_date'];           // a date in an open month
$D2 = date('Y-m-d', strtotime($D . ' +1 day'));
foreach (['1010', '1020', '1030', '1050', '1210', '2010', '2030', '2210', '3050', '4060', '6010', '7030', '7040'] as $c) {
    if (!$acct($c)) { fwrite(STDERR, "setup: account {$c} missing (run migrations)\n"); exit(2); }
}

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    // ── I1 ──────────────────────────────────────────────────────
    echo "I1 revenue mapping\n";
    $d = AutoEntryBridge::resolveRevenueAccountDetail('base_rental', 'reefer');
    $check($d['account_id'] === $acct('4030') && !$d['fallback'], 'base_rental on a reefer → 4030 via base_rental_reefer');
    $d = AutoEntryBridge::resolveRevenueAccountDetail('base_rental', 'tanker');
    $check($d['account_id'] === $acct('4050') && $d['key'] === 'base_rental_other', 'category without a key → base_rental_other (4050)');
    $d = AutoEntryBridge::resolveRevenueAccountDetail('mileage_estimate');
    $check($d['account_id'] === $acct('4060'), 'mileage_estimate → 4060 (was 4110 fallback)');
    $d = AutoEntryBridge::resolveRevenueAccountDetail('hourly_usage');
    $check($d['fallback'] === true && $d['key'] === 'other', 'unmapped type reports fallback=other');

    // ── I9 ──────────────────────────────────────────────────────
    echo "I9 manual reversal of automatic entries\n";
    $check(JournalEntryService::manualReversalBlockReason(['source_type' => 'invoice', 'entry_type' => 'system', 'is_reversal' => 0]) !== null, 'invoice entry blocked');
    $check(JournalEntryService::manualReversalBlockReason(['source_type' => null, 'entry_type' => 'manual', 'is_reversal' => 0]) === null, 'manual entry allowed');
    $check(JournalEntryService::manualReversalBlockReason(['source_type' => null, 'entry_type' => 'manual', 'is_reversal' => 1]) !== null, 'reversal of a reversal blocked');
    $check(JournalEntryService::manualReversalBlockReason(['source_type' => 'manual', 'entry_type' => 'system', 'is_reversal' => 0]) !== null, 'vendor-credit (system/manual) blocked');

    // ── I6 ──────────────────────────────────────────────────────
    echo "I6 closed-period posting\n";
    db_execute("UPDATE acc_periods SET status = 'closed' WHERE id = ?", [(int) $openPeriod['id']]);
    $lines2 = [
        ['account_id' => $acct('6010'), 'debit' => '10.00', 'credit' => '0.00'],
        ['account_id' => $acct('1010'), 'debit' => '0.00', 'credit' => '10.00'],
    ];
    $e = $throws(fn() => JournalEntryService::create(['entry_date' => $D, 'description' => 't', 'entry_type' => 'manual', 'post_immediately' => true, 'allow_closed_period' => true], $lines2, $uid), 'closed');
    $check($e !== null && !str_starts_with($e, 'UNEXPECTED'), 'manual entry with allow_closed_period still refused in a closed month');
    $ye = JournalEntryService::create(['entry_date' => $D, 'description' => 't', 'entry_type' => 'year_end', 'post_immediately' => true, 'allow_closed_period' => true], $lines2, $uid);
    $check(!empty($ye['id']), 'year_end entry posts into a closed month');
    $rev = JournalEntryService::reverse((int) $ye['id'], $D, $uid, true);
    $check(!empty($rev['id']), 'year_end reversal posts into the closed month');
    db_execute("UPDATE acc_periods SET status = 'locked' WHERE id = ?", [(int) $openPeriod['id']]);
    $e = $throws(fn() => JournalEntryService::create(['entry_date' => $D, 'description' => 't', 'entry_type' => 'year_end', 'post_immediately' => true, 'allow_closed_period' => true], $lines2, $uid), 'locked');
    $check($e !== null && !str_starts_with($e, 'UNEXPECTED'), 'a LOCKED month refuses even a year_end entry');
    $check(is_file(dirname(__DIR__) . '/api/v1/accounting/periods/reopen.php'), 'periods/reopen.php endpoint exists');

    // ── I7 ──────────────────────────────────────────────────────
    echo "I7 FX revaluation\n";
    db_execute("UPDATE acc_periods SET status = 'closed' WHERE id = ?", [(int) $openPeriod['id']]);
    db_execute("INSERT INTO settings (`key`, `value`, value_type, group_name) VALUES ('accounting.fx_revaluation_enabled','1','string','accounting') ON DUPLICATE KEY UPDATE `value` = '1'");
    $e = $throws(fn() => FxRevaluationService::preview((int) $openPeriod['id'], '1.37'), 'Revalue a month before closing');
    $check($e !== null && !str_starts_with($e, 'UNEXPECTED'), 'preview refuses a closed period with a clear message');
    db_execute("UPDATE acc_periods SET status = 'open' WHERE id = ?", [(int) $openPeriod['id']]);

    // ── I10 / I8 / I5 / I11 — bank accounts ─────────────────────
    echo "I10 / I8 / I5 / I11 banking\n";
    db_execute("INSERT INTO exchange_rates (from_currency, to_currency, rate, rate_date, source) VALUES ('USD','CAD','1.400000', ?, 'manual')", [$D]);
    $cadBank = db_insert('acc_bank_accounts', ['name' => 'SMK CAD', 'account_type' => 'checking', 'currency' => 'CAD', 'gl_account_id' => $acct('1010'), 'opening_balance' => '1000.00', 'opening_balance_date' => $D, 'is_active' => 1, 'is_default' => 0]);
    $usdBank = db_insert('acc_bank_accounts', ['name' => 'SMK USD', 'account_type' => 'checking', 'currency' => 'USD', 'gl_account_id' => $acct('1020'), 'opening_balance' => '0.00', 'is_active' => 1, 'is_default' => 0]);

    $check(AutoEntryBridge::cashAccountForBank($usdBank) === $acct('1020'), 'I10 a payment into the USD bank debits 1020');
    $check(AutoEntryBridge::cashAccountForBank(null) === (int) AccountingService::setting('accounting.default_cash_account_id'), 'I10 no bank → Settings cash account');
    $r = BankService::resolveReceivingBank($usdBank, 'CAD');
    $check($r['error'] !== null, 'I10 a CAD payment cannot go into the USD bank');

    $jeId = BankService::syncOpeningBalanceEntry($cadBank, $uid);
    $l = $jeLines((int) $jeId);
    $check($lineFor($l, '1010', 'debit') === '1000.00' && $lineFor($l, '3050', 'credit') === '1000.00', 'I8 opening balance posts DR 1010 / CR 3050');
    db_update('acc_bank_accounts', ['opening_balance' => '1500.00'], 'id = ?', [$cadBank]);
    $jeId2 = BankService::syncOpeningBalanceEntry($cadBank, $uid);
    $old = db_row("SELECT status FROM acc_journal_entries WHERE id = ?", [(int) $jeId]);
    $check($old['status'] === 'reversed' && $lineFor($jeLines((int) $jeId2), '1010', 'debit') === '1500.00', 'I8 a changed opening balance reverses and re-posts');

    // I5: send 1,400 CAD, receive 1,000 USD, no rate → implied 1.4, no FX line.
    $t = BankService::recordTransfer($cadBank, $usdBank, '1400.00', '1000.00', $D, null, 'SMK', $uid);
    $l = $jeLines((int) $t['journal_entry_id']);
    $usdLine = array_values(array_filter($l, fn($x) => $x['code'] === '1020'))[0] ?? [];
    $check($lineFor($l, '1020', 'debit') === '1400.00' && (string) ($usdLine['foreign_amount'] ?? '') === '1000.00', 'I5 USD leg booked at CAD 1,400.00 with foreign 1,000 USD');
    $check($lineFor($l, '7030', 'credit') === '0.00' && $lineFor($l, '7040', 'debit') === '0.00', 'I5 no fake FX gain/loss at the implied rate');
    $t2 = BankService::recordTransfer($cadBank, $usdBank, '1400.00', '1000.00', $D, '1.37', 'SMK2', $uid);
    $check($lineFor($jeLines((int) $t2['journal_entry_id']), '7040', 'debit') === '30.00', 'I5 spot rate 1.37 books the 30.00 spread as FX loss');
    $cad2 = db_insert('acc_bank_accounts', ['name' => 'SMK CAD2', 'account_type' => 'savings', 'currency' => 'CAD', 'gl_account_id' => $acct('1010'), 'opening_balance' => '0.00', 'is_active' => 1, 'is_default' => 0]);
    $e = $throws(fn() => BankService::recordTransfer($cadBank, $cad2, '10.00', '12.00', $D, null, 'x', $uid), 'must be the same');
    $check($e !== null && !str_starts_with($e, 'UNEXPECTED'), 'I5 same-currency transfer with two different amounts is refused');

    // I11: the example from the audit — beginning 10,000; +2,000 and −500
    // cleared; −1,200 outstanding; statement 11,500 → Difference 0.
    $rBank = db_insert('acc_bank_accounts', ['name' => 'SMK REC', 'account_type' => 'checking', 'currency' => 'CAD', 'gl_account_id' => $acct('1010'), 'opening_balance' => '10000.00', 'opening_balance_date' => $D, 'is_active' => 1, 'is_default' => 0]);
    $recon = db_insert('acc_bank_reconciliations', ['bank_account_id' => $rBank, 'period_id' => (int) $openPeriod['id'], 'statement_date' => $D2, 'statement_ending_balance' => '11500.00', 'book_balance' => '0.00', 'adjusted_book_balance' => '0.00', 'status' => 'in_progress', 'created_by' => $uid]);
    foreach ([['2000.00', 1], ['-500.00', 1], ['-1200.00', 0]] as [$amt, $clr]) {
        db_insert('acc_bank_transactions', ['bank_account_id' => $rBank, 'transaction_date' => $D, 'description' => 'SMK', 'amount' => $amt, 'transaction_type' => $amt[0] === '-' ? 'withdrawal' : 'deposit', 'source' => 'manual', 'status' => 'unmatched', 'is_cleared' => $clr, 'reconciliation_id' => $clr ? $recon : null]);
    }
    $sum = BankService::reconciliationSummary($rBank, $recon, '11500.00');
    $check($sum['beginning_balance'] === '10000.00' && $sum['cleared_balance'] === '11500.00', 'I11 beginning 10,000 + 2,000 − 500 = cleared 11,500');
    $check($sum['difference'] === '0.00' && $sum['is_balanced'] === true, 'I11 Difference 0.00 with a cheque in transit (old formula: −2,400)');

    // ── I4 ──────────────────────────────────────────────────────
    echo "I4 GST remittance\n";
    $gstStart = $D; $gstEnd = $D2;
    $tp = db_insert('acc_tax_filing_periods', ['tax_type' => 'gst_hst', 'period_start' => $gstStart, 'period_end' => $gstEnd, 'frequency' => 'monthly', 'filing_due_date' => $gstEnd, 'status' => 'open']);
    $base = TaxFilingService::calculatePeriod($tp, $uid);
    // + 500 GST collected, + 200 ITC in the window
    JournalEntryService::create(['entry_date' => $D, 'description' => 'SMK sale', 'entry_type' => 'manual', 'post_immediately' => true], [
        ['account_id' => $acct('1030'), 'debit' => '500.00', 'credit' => '0.00'],
        ['account_id' => $acct('2030'), 'debit' => '0.00', 'credit' => '500.00'],
    ], $uid);
    JournalEntryService::create(['entry_date' => $D, 'description' => 'SMK bill', 'entry_type' => 'manual', 'post_immediately' => true], [
        ['account_id' => $acct('1050'), 'debit' => '200.00', 'credit' => '0.00'],
        ['account_id' => $acct('2010'), 'debit' => '0.00', 'credit' => '200.00'],
    ], $uid);
    $calc = TaxFilingService::calculatePeriod($tp, $uid);
    $check(bcsub((string) $calc['total_tax_collected'], (string) $base['total_tax_collected'], 2) === '500.00'
        && bcsub((string) $calc['total_itc'], (string) $base['total_itc'], 2) === '200.00', 'I4 period picks up +500 collected / +200 ITC');
    db_update('acc_tax_filing_periods', ['status' => 'filed'], 'id = ?', [$tp]);
    $net = (string) $calc['net_tax_owing'];
    $abs = bccomp($net, '0', 2) < 0 ? bcmul($net, '-1', 2) : $net;
    $wrong = bcadd($abs, '1.00', 2);
    $e = $throws(fn() => TaxFilingService::recordRemittance($tp, ['remittance_date' => $D2, 'amount' => $wrong, 'payment_method' => 'online_banking', 'bank_account_id' => $cadBank], $uid), 'net tax on the filed return');
    $check($e !== null && !str_starts_with($e, 'UNEXPECTED'), 'I4 an amount that differs from the return is refused');
    $rem = TaxFilingService::recordRemittance($tp, ['remittance_date' => $D2, 'amount' => $abs, 'payment_method' => 'online_banking', 'bank_account_id' => $cadBank], $uid);
    $l = $jeLines((int) $rem['journal_entry_id']);
    $check($lineFor($l, '2030', 'debit') === bcadd((string) $calc['total_tax_collected'], '0', 2)
        && $lineFor($l, '1050', 'credit') === bcadd((string) $calc['total_itc'], '0', 2), 'I4 remittance clears 2030 (collected) AND 1050 (ITCs)');
    $bankSide = bccomp($net, '0', 2) >= 0 ? $lineFor($l, '1010', 'credit') : $lineFor($l, '1010', 'debit');
    $check($bankSide === $abs && $rem['remittance']['direction'] === (bccomp($net, '0', 2) < 0 ? 'refund' : 'payment'), 'I4 bank takes the net (' . $net . ')');

    // Refund period: ITC 900 > collected 0 in a fresh window.
    $rs = date('Y-m-d', strtotime($D . ' +3 day')); $re = date('Y-m-d', strtotime($D . ' +4 day'));
    $tp2 = db_insert('acc_tax_filing_periods', ['tax_type' => 'gst_hst', 'period_start' => $rs, 'period_end' => $re, 'frequency' => 'monthly', 'filing_due_date' => $re, 'status' => 'open']);
    $b2 = TaxFilingService::calculatePeriod($tp2, $uid);
    JournalEntryService::create(['entry_date' => $rs, 'description' => 'SMK capex ITC', 'entry_type' => 'manual', 'post_immediately' => true], [
        ['account_id' => $acct('1050'), 'debit' => '900000.00', 'credit' => '0.00'],
        ['account_id' => $acct('2010'), 'debit' => '0.00', 'credit' => '900000.00'],
    ], $uid);
    $c2 = TaxFilingService::calculatePeriod($tp2, $uid);
    $check(bccomp((string) $c2['net_tax_owing'], '0', 2) < 0, 'I4 refund period nets negative');
    db_update('acc_tax_filing_periods', ['status' => 'filed'], 'id = ?', [$tp2]);
    $refundAmt = bcmul((string) $c2['net_tax_owing'], '-1', 2);
    $rem2 = TaxFilingService::recordRemittance($tp2, ['remittance_date' => $re, 'amount' => $refundAmt, 'payment_method' => 'online_banking', 'bank_account_id' => $cadBank], $uid);
    $l = $jeLines((int) $rem2['journal_entry_id']);
    $check($rem2['remittance']['direction'] === 'refund' && $lineFor($l, '1010', 'debit') === $refundAmt, 'I4 refund recorded: DR bank ' . $refundAmt);

    // ── I3 ──────────────────────────────────────────────────────
    echo "I3 vendor credit\n";
    $apBefore = AccountingService::apReconciliationCheck()['difference'];
    $vendor = db_row("SELECT id FROM vendors WHERE deleted_at IS NULL LIMIT 1");
    $vcJe = JournalEntryService::create(['entry_date' => $D, 'description' => 'SMK VC', 'entry_type' => 'system', 'source_type' => 'manual', 'post_immediately' => true], [
        ['account_id' => (int) AccountingService::setting('accounting.ap_account_id'), 'debit' => '75.00', 'credit' => '0.00'],
        ['account_id' => $acct('6010'), 'debit' => '0.00', 'credit' => '75.00'],
    ], $uid);
    db_insert('acc_vendor_credits', ['credit_number' => 'VC-SMK-' . getmypid(), 'vendor_id' => (int) $vendor['id'], 'credit_date' => $D, 'reason' => 'smk', 'amount' => '75.00', 'amount_remaining' => '75.00', 'currency' => 'CAD', 'status' => 'active', 'journal_entry_id' => (int) $vcJe['id']]);
    $check(AccountingService::apReconciliationCheck()['difference'] === $apBefore, 'I3 an unapplied, correctly posted vendor credit is not AP drift');

    // ── I2 ──────────────────────────────────────────────────────
    echo "I2 fixed assets\n";
    $assetBase = ['name' => 'SMK asset', 'asset_class' => 'fleet_equipment', 'acquisition_date' => $D, 'acquisition_cost' => '50000.00',
        'depreciation_method' => 'straight_line', 'useful_life_years' => 5, 'salvage_value' => '0.00',
        'asset_account_id' => $acct('1210'), 'accum_depr_account_id' => $acct('1220') ?: $acct('1210'), 'depr_expense_account_id' => $acct('6190') ?: $acct('6010')];
    $e = $throws(fn() => FixedAssetService::create($assetBase, $uid), 'how the asset was paid');
    $check($e !== null && !str_starts_with($e, 'UNEXPECTED'), 'I2 an asset with no payment basis is refused');
    $a = FixedAssetService::create($assetBase + ['funding_account_id' => $acct('2210')], $uid);
    $je = db_row("SELECT id FROM acc_journal_entries WHERE source_type = 'asset_acquisition' AND source_id = ?", [(int) $a['id']]);
    $l = $je ? $jeLines((int) $je['id']) : [];
    $check($lineFor($l, '1210', 'debit') === '50000.00' && $lineFor($l, '2210', 'credit') === '50000.00', 'I2 purchase posts DR 1210 / CR 2210 (loan)');
    $o = FixedAssetService::create($assetBase + ['is_opening_balance' => 1], $uid);
    $check(!db_row("SELECT id FROM acc_journal_entries WHERE source_type = 'asset_acquisition' AND source_id = ?", [(int) $o['id']]), 'I2 opening-balance asset posts nothing');
    $b = FixedAssetService::capitalize((int) $a['id'], '5000.00', $uid, 'SMK new reefer unit', null, $acct('1010'));
    $bj = db_row("SELECT id FROM acc_journal_entries WHERE source_type = 'asset_betterment' AND source_id = ?", [(int) $a['id']]);
    $check($bj && $lineFor($jeLines((int) $bj['id']), '1210', 'debit') === '5000.00' && bccomp((string) $b['net_book_value'], '55000.00', 2) === 0, 'I2 betterment posts DR 1210 / CR 1010 and raises NBV');

    // ── I15 ─────────────────────────────────────────────────────
    echo "I15 per-unit direct costs\n";
    $unit = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    $poolBefore = UnitProfitabilityService::getOverheadPool($D, $D);
    $dcBefore   = UnitProfitabilityService::getUnitDirectCosts((int) $unit['id'], $D, $D)['total_direct'];
    JournalEntryService::create(['entry_date' => $D, 'description' => 'SMK repair', 'entry_type' => 'manual', 'post_immediately' => true], [
        ['account_id' => $acct('6010'), 'debit' => '321.00', 'credit' => '0.00', 'equipment_unit_id' => (int) $unit['id']],
        ['account_id' => (int) AccountingService::setting('accounting.ap_account_id'), 'debit' => '0.00', 'credit' => '321.00'],
    ], $uid);
    $check(bcsub(UnitProfitabilityService::getUnitDirectCosts((int) $unit['id'], $D, $D)['total_direct'], $dcBefore, 2) === '321.00', 'I15 a unit-tagged repair is that unit\'s direct cost');
    $check(bcsub(UnitProfitabilityService::getOverheadPool($D, $D), $poolBefore, 2) === '0.00', 'I15 …and is not also in the overhead pool');
} catch (\Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
    echo "  \033[31mERROR\033[0m — " . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n{$passes} passed, " . count($failures) . " failed\n";
exit($failures ? 1 : 0);
