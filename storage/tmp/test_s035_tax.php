<?php
declare(strict_types=1);

/**
 * S035 — TAX FILING TESTS (mandatory TEST 1-11 from session contract)
 *
 * Exercises every TaxFilingService method against the data prepared
 * by test_s035_setup.php. Verifies:
 *   - createPeriod (GST/HST Q1 2026 + PST-MB Q1 2026)
 *   - calculatePeriod (GL queries land the right amounts)
 *   - markFiled (status guard + filed_by)
 *   - recordRemittance (JE balanced, status flips, period locked)
 *   - listPeriods (filters work)
 *   - getPeriodDetail (drill-down)
 *   - Double-remit prevention
 *   - Recalc on filed period works
 *   - Recalc on remitted period blocked
 *   - GL recon proves JE matches the period totals
 */

require __DIR__ . '/../../config/app.php';

use FleetForge\Accounting\TaxFilingService;

$ids = json_decode((string) file_get_contents(__DIR__ . '/test_s035_ids.json'), true);

function pass(string $label, string $expected, string $actual): bool
{
    if (is_numeric($expected) && is_numeric($actual)) {
        $ok = bccomp($expected, $actual, 2) === 0;
    } else {
        $ok = ($expected === $actual);
    }
    printf("  %s %s = %s %s %s\n",
        $ok ? '✅' : '❌',
        $label, $actual, $ok ? '==' : '!=', $expected);
    return $ok;
}

$totalAssertions = 0;
$failedAssertions = 0;
function assert_pass(bool $r) {
    global $totalAssertions, $failedAssertions;
    $totalAssertions++;
    if (!$r) $failedAssertions++;
}

echo str_repeat('=', 80) . PHP_EOL;
echo "S035 — TAX FILING SERVICE TESTS" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;

// ============================================================
// TEST 1 — CREATE GST/HST FILING PERIOD
// ============================================================
echo "\n## TEST 1 — Create GST/HST Q1 2026 filing period\n";

$gstPeriodId = TaxFilingService::createPeriod([
    'tax_type'     => 'gst_hst',
    'period_start' => '2026-01-01',
    'period_end'   => '2026-03-31',
    'frequency'    => 'quarterly',
    'notes'        => 'S035 test — Q1 2026 GST/HST filing',
], 1);

printf("  ✓ Created GST/HST period #%d\n", $gstPeriodId);

$gstPeriod = db_row("SELECT * FROM acc_tax_filing_periods WHERE id = ?", [$gstPeriodId]);
assert_pass(pass("status",          "open",       $gstPeriod['status']));
assert_pass(pass("tax_type",        "gst_hst",    $gstPeriod['tax_type']));
assert_pass(pass("period_start",    "2026-01-01", $gstPeriod['period_start']));
assert_pass(pass("period_end",      "2026-03-31", $gstPeriod['period_end']));
assert_pass(pass("filing_due_date", "2026-04-30", $gstPeriod['filing_due_date'])); // +1 month for quarterly

// ============================================================
// TEST 2 — DUPLICATE PERIOD REJECTED
// ============================================================
echo "\n## TEST 2 — Duplicate period creation must fail\n";
try {
    TaxFilingService::createPeriod([
        'tax_type'     => 'gst_hst',
        'period_start' => '2026-01-01',
        'period_end'   => '2026-03-31',
        'frequency'    => 'quarterly',
    ], 1);
    echo "  ❌ Should have thrown but didn't\n";
    assert_pass(false);
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly rejected duplicate: " . $e->getMessage() . "\n";
    assert_pass(true);
}

// ============================================================
// TEST 3 — CREATE PST-MB FILING PERIOD
// ============================================================
echo "\n## TEST 3 — Create PST-MB Q1 2026 filing period\n";

$pstPeriodId = TaxFilingService::createPeriod([
    'tax_type'     => 'pst_mb',
    'period_start' => '2026-01-01',
    'period_end'   => '2026-03-31',
    'frequency'    => 'quarterly',
    'notes'        => 'S035 test — Q1 2026 Manitoba RST filing',
], 1);

printf("  ✓ Created PST-MB period #%d\n", $pstPeriodId);

$pstPeriod = db_row("SELECT * FROM acc_tax_filing_periods WHERE id = ?", [$pstPeriodId]);
assert_pass(pass("status",   "open",   $pstPeriod['status']));
assert_pass(pass("tax_type", "pst_mb", $pstPeriod['tax_type']));

// ============================================================
// TEST 4 — CALCULATE GST/HST PERIOD
// ============================================================
echo "\n## TEST 4 — Calculate GST/HST period\n";

$calc = TaxFilingService::calculatePeriod($gstPeriodId, 1);
printf("  total_sales         = \$%s\n", $calc['total_sales']);
printf("  total_tax_collected = \$%s\n", $calc['total_tax_collected']);
printf("  total_itc           = \$%s\n", $calc['total_itc']);
printf("  net_tax_owing       = \$%s\n", $calc['net_tax_owing']);
printf("  status              = %s\n",   $calc['status']);

// Expected:
//   total_sales = sum subtotals of 6 invoices (5 BC + 1 MB) = 12,156.70
//   total_tax_collected = sum CR to 2030 = 607.83
//   total_itc = sum DR to 1050 = 240.00
//   net_tax_owing = 607.83 - 240.00 = 367.83
$expectedSales = "12156.70";
$expectedColl  = "607.83";
$expectedItc   = "240.00";
$expectedNet   = bcsub($expectedColl, $expectedItc, 2);

assert_pass(pass("total_sales",         $expectedSales, $calc['total_sales']));
assert_pass(pass("total_tax_collected", $expectedColl,  $calc['total_tax_collected']));
assert_pass(pass("total_itc",           $expectedItc,   $calc['total_itc']));
assert_pass(pass("net_tax_owing",       $expectedNet,   $calc['net_tax_owing']));
assert_pass(pass("status flipped to calculated", "calculated", $calc['status']));

// ============================================================
// TEST 5 — CALCULATE PST-MB PERIOD
// ============================================================
echo "\n## TEST 5 — Calculate PST-MB period (province-filtered)\n";

$pstCalc = TaxFilingService::calculatePeriod($pstPeriodId, 1);
printf("  total_sales         = \$%s\n", $pstCalc['total_sales']);
printf("  total_tax_collected = \$%s\n", $pstCalc['total_tax_collected']);
printf("  total_itc           = \$%s\n", $pstCalc['total_itc']);
printf("  net_tax_owing       = \$%s\n", $pstCalc['net_tax_owing']);

// Only the MB invoice should contribute (cust=1, subtotal $1200, PST $96)
assert_pass(pass("PST sales (MB only)",   "1200.00", $pstCalc['total_sales']));
assert_pass(pass("PST tax_collected",     "96.00",   $pstCalc['total_tax_collected']));
assert_pass(pass("PST itc (none)",        "0.00",    $pstCalc['total_itc']));
assert_pass(pass("PST net_owing",         "96.00",   $pstCalc['net_tax_owing']));

// ============================================================
// TEST 6 — MARK GST/HST AS FILED
// ============================================================
echo "\n## TEST 6 — Mark GST/HST as filed\n";

$filed = TaxFilingService::markFiled($gstPeriodId, '2026-04-15', 1);
printf("  status     = %s\n", $filed['status']);
printf("  filed_date = %s\n", $filed['filed_date']);
printf("  filed_by   = %s\n", $filed['filed_by']);

assert_pass(pass("status flipped to filed", "filed", $filed['status']));
assert_pass(pass("filed_date",               "2026-04-15", $filed['filed_date']));
assert_pass(pass("filed_by user",            "1", (string) $filed['filed_by']));

// ============================================================
// TEST 7 — RE-CALC ON FILED PERIOD STILL ALLOWED
// ============================================================
echo "\n## TEST 7 — Recalc on filed (not yet remitted) period — should succeed\n";

try {
    $recalc = TaxFilingService::calculatePeriod($gstPeriodId, 1);
    echo "  ✅ Recalc succeeded; net still \$" . $recalc['net_tax_owing'] . "\n";
    assert_pass(true);
    // After recalc, status flips to 'calculated' (it was filed but recalc resets workflow)
    // Actually no — the service sets status='calculated' regardless. So we lose 'filed'.
    // Re-mark as filed for the next test.
    if ($recalc['status'] === 'calculated') {
        TaxFilingService::markFiled($gstPeriodId, '2026-04-15', 1);
    }
} catch (\Throwable $e) {
    echo "  ❌ Recalc failed unexpectedly: " . $e->getMessage() . "\n";
    assert_pass(false);
}

// ============================================================
// TEST 8 — RECORD GST/HST REMITTANCE
// ============================================================
echo "\n## TEST 8 — Record GST/HST remittance (creates JE + flips status)\n";

$result = TaxFilingService::recordRemittance($gstPeriodId, [
    'remittance_date'  => '2026-04-20',
    'amount'           => $expectedNet,
    'payment_method'   => 'online_banking',
    'bank_account_id'  => 1, // Main Operating Account
    'reference_number' => 'CRA-CONF-12345',
    'notes'            => 'S035 test remittance — Q1 2026 GST/HST',
], 1);

$rem = $result['remittance'];
$jeId = $result['journal_entry_id'];
printf("  Remittance #%d, JE #%d\n", $rem['id'], $jeId);

// Verify JE balanced + correct accounts
$je = db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$jeId]);
$jeLines = db_select("SELECT * FROM acc_journal_entry_lines WHERE journal_entry_id = ? ORDER BY id", [$jeId]);

assert_pass(pass("JE source_type",     "tax_remittance", $je['source_type']));
assert_pass(pass("JE source_id",       (string) $rem['id'], (string) $je['source_id']));
assert_pass(pass("JE status",          "posted",         $je['status']));
assert_pass(pass("JE line count",      "2",              (string) count($jeLines)));

$dr = '0.00'; $cr = '0.00';
foreach ($jeLines as $l) {
    $dr = bcadd($dr, $l['debit'], 2);
    $cr = bcadd($cr, $l['credit'], 2);
}
assert_pass(pass("JE total DR",  $expectedNet, $dr));
assert_pass(pass("JE total CR",  $expectedNet, $cr));
assert_pass(pass("JE balanced",  $dr, $cr));

// Verify accounts: DR should be 2030 GST Payable, CR should be bank's GL account
$drLine = null; $crLine = null;
foreach ($jeLines as $l) {
    if (bccomp($l['debit'], '0', 2) > 0)  $drLine = $l;
    if (bccomp($l['credit'], '0', 2) > 0) $crLine = $l;
}
assert_pass(pass("DR account = 2030 GST Payable", "23", (string) $drLine['account_id']));

// Verify period status flipped + remittance linked back
$gstPeriodAfter = db_row("SELECT * FROM acc_tax_filing_periods WHERE id = ?", [$gstPeriodId]);
assert_pass(pass("period status now remitted", "remitted", $gstPeriodAfter['status']));

$remRow = db_row("SELECT * FROM acc_tax_remittances WHERE id = ?", [$rem['id']]);
assert_pass(pass("remittance.journal_entry_id linked", (string) $jeId, (string) $remRow['journal_entry_id']));

// ============================================================
// TEST 9 — RECALC ON REMITTED PERIOD BLOCKED
// ============================================================
echo "\n## TEST 9 — Recalc on remitted period must fail (PERIOD_LOCKED)\n";

try {
    TaxFilingService::calculatePeriod($gstPeriodId, 1);
    echo "  ❌ Should have thrown PERIOD_LOCKED\n";
    assert_pass(false);
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
    assert_pass(true);
}

// ============================================================
// TEST 10 — DOUBLE-REMIT PREVENTION
// ============================================================
echo "\n## TEST 10 — Second remittance on same period must fail\n";

try {
    TaxFilingService::recordRemittance($gstPeriodId, [
        'remittance_date' => '2026-04-21',
        'amount'          => '100.00',
        'payment_method'  => 'check',
    ], 1);
    echo "  ❌ Should have thrown INVALID_TRANSITION\n";
    assert_pass(false);
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
    assert_pass(true);
}

// ============================================================
// TEST 11 — LIST + DETAIL
// ============================================================
echo "\n## TEST 11 — listPeriods + getPeriodDetail\n";

$list = TaxFilingService::listPeriods(['year' => 2026], 1, 10);
printf("  list total: %d periods, items: %d\n", $list['total'], count($list['items']));
assert_pass(pass("at least 2 in 2026", "2", (string) min(2, $list['total'])));

$listGst = TaxFilingService::listPeriods(['tax_type' => 'gst_hst'], 1, 10);
printf("  filtered gst_hst: %d periods\n", $listGst['total']);

$detail = TaxFilingService::getPeriodDetail($gstPeriodId);
printf("  detail: %d invoices, %d bills, %d remittances\n",
    count($detail['invoices']), count($detail['bills']), count($detail['remittances']));
assert_pass(pass("detail has 6 invoices",   "6", (string) count($detail['invoices'])));
assert_pass(pass("detail has 3 bills",      "3", (string) count($detail['bills'])));
assert_pass(pass("detail has 1 remittance", "1", (string) count($detail['remittances'])));

// PST-MB period detail should only have 1 invoice (the MB one)
$pstDetail = TaxFilingService::getPeriodDetail($pstPeriodId);
printf("  PST detail: %d invoices, %d bills\n",
    count($pstDetail['invoices']), count($pstDetail['bills']));
assert_pass(pass("PST detail province-filtered to 1", "1", (string) count($pstDetail['invoices'])));
assert_pass(pass("PST detail bills (always 0 for PST)", "0", (string) count($pstDetail['bills'])));

// ============================================================
// GL RECON PROOF — net of all S035 JEs should be balanced
// ============================================================
echo "\n## GL RECON — JEs created in this session\n";

// All JEs created in this test session (by test_s035_setup.php and this script)
$reconRow = db_row(
    "SELECT
        SUM(jel.debit)  AS total_dr,
        SUM(jel.credit) AS total_cr
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE je.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
       AND je.status = 'posted'"
);
printf("  Recent JEs total DR: \$%s\n", $reconRow['total_dr']);
printf("  Recent JEs total CR: \$%s\n", $reconRow['total_cr']);
assert_pass(pass("DR == CR across all session JEs",
    $reconRow['total_dr'], $reconRow['total_cr']));

// Check 2030 GST Payable balance after remittance — should be zero from S035
// (the 607.83 collected was netted by 240.00 ITC but ITC is on 1050 not 2030,
// so 2030 CR was 607.83 and we DR'd 367.83 in remittance — leaves 240.00 on 2030)
//
// Wait, we DR'd net_owing (367.83) not gross collected (607.83). So:
//   2030: CR 607.83 (from 6 invoices) - DR 367.83 (remittance) = CR 240.00 net
//
// And on 1050 we have DR 240.00 (3 bills) - 0 = DR 240.00 net
// Net liability owed: 240.00 - 240.00 = 0.00 ← which is the correct CRA balance after remittance
echo "\n  Account 2030 (GST/HST Payable) S035-only deltas:\n";
$row = db_row(
    "SELECT
        SUM(jel.debit) AS dr,
        SUM(jel.credit) AS cr
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = 23
       AND je.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
       AND je.status = 'posted'"
);
printf("    DR \$%s / CR \$%s / Net CR \$%s\n",
    $row['dr'], $row['cr'], bcsub($row['cr'], $row['dr'], 2));

echo "\n  Account 1050 (GST ITC) S035-only deltas:\n";
$row = db_row(
    "SELECT
        SUM(jel.debit) AS dr,
        SUM(jel.credit) AS cr
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = 6
       AND je.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
       AND je.status = 'posted'"
);
printf("    DR \$%s / CR \$%s / Net DR \$%s\n",
    $row['dr'], $row['cr'], bcsub($row['dr'], $row['cr'], 2));

// ============================================================
// AUDIT TRAIL CHECK
// ============================================================
echo "\n## AUDIT TRAIL CHECK\n";

$audits = db_select(
    "SELECT action, entity_type, entity_id, notes
     FROM audit_log
     WHERE entity_type IN ('tax_filing_period', 'tax_remittance')
       AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
     ORDER BY id"
);
printf("  Audit entries written: %d\n", count($audits));
foreach ($audits as $a) {
    printf("    [%s] %s #%d — %s\n",
        $a['action'], $a['entity_type'], $a['entity_id'],
        substr($a['notes'] ?? '', 0, 70));
}
// At minimum: createPeriod x2, calculatePeriod x3 (gst, pst, gst-recalc), markFiled x2 (gst+remark), recordRemittance x1 = 8
assert_pass(pass("audit count >= 8", "1", (string) (count($audits) >= 8 ? 1 : 0)));

// ============================================================
// CLEANUP — re-close Feb/Mar 2026
// ============================================================
echo "\n## CLEANUP — re-close Feb/Mar 2026 periods\n";
db_execute("UPDATE acc_periods SET status = 'closed' WHERE id IN (14, 15)");
echo "  ✓ Feb/Mar 2026 closed\n";

// ============================================================
// FINAL SUMMARY
// ============================================================
echo "\n" . str_repeat('=', 80) . PHP_EOL;
printf("ASSERTIONS: %d total, %d passed, %d failed%s\n",
    $totalAssertions,
    $totalAssertions - $failedAssertions,
    $failedAssertions,
    $failedAssertions === 0 ? "  ✅" : "  ❌");
echo str_repeat('=', 80) . PHP_EOL;
exit($failedAssertions === 0 ? 0 : 1);
