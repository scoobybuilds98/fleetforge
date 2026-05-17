<?php
declare(strict_types=1);

/**
 * tests/_unit/tier1_wpm.php
 *
 * Tier 1 — Whichever-Pays-More (30 tests). Spec §7.
 *
 *   WPM-001..WPM-010  basic A-wins (10)
 *   WPM-020..WPM-024  B-wins (5)
 *   WPM-030..WPM-037  activation-only (8)
 *   WPM-040..WPM-046  edge cases (7)
 *
 * WPM-030..037 require the integration path because activation status
 * depends on the already_billed SUM against the DB. Other tests use
 * the pure-math whicheverPaysMore() entry point.
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 */

use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\Billing\BillingRateException;
use FleetForge\Tests\Assert;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

function _wpm(int $days, string $d = '50.00', string $w = '350.00', string $m = '700.00'): array {
    return (new HolisticLeaseEngine())->whicheverPaysMore($days, $d, $w, $m);
}

// ── WPM-001..010 basic A-wins (10) ───────────────────────────
function test_WPM_001(): void { $r = _wpm(1);  Assert::bcequal('50.00',   $r['chosen_amount']); Assert::equal('A', $r['chosen_option']); }
function test_WPM_002(): void { $r = _wpm(2);  Assert::bcequal('100.00',  $r['chosen_amount']); }
function test_WPM_003(): void { $r = _wpm(3);  Assert::bcequal('150.00',  $r['chosen_amount']); }
function test_WPM_004(): void { $r = _wpm(4);  Assert::bcequal('200.00',  $r['chosen_amount']); /* boss's example activation */ }
function test_WPM_005(): void { $r = _wpm(5);  Assert::bcequal('250.00',  $r['chosen_amount']); }
function test_WPM_006(): void { $r = _wpm(6);  Assert::bcequal('350.00',  $r['chosen_amount']); }
function test_WPM_007(): void { $r = _wpm(7);  Assert::bcequal('350.00',  $r['chosen_amount']); }
function test_WPM_008(): void { $r = _wpm(15); Assert::bcequal('700.00',  $r['chosen_amount']); }
// WPM-009: 30 days — A and B both equal $700. Tie → engine picks A.
function test_WPM_009(): void { $r = _wpm(30); Assert::bcequal('700.00',  $r['chosen_amount']); Assert::equal('tie', $r['chosen_option']); }
function test_WPM_010(): void { $r = _wpm(31); Assert::bcequal('723.33',  $r['chosen_amount']); }

// ── WPM-020..024 B-wins (5) ──────────────────────────────────
// $10/$70/$3000. 1 day: A = 1×$10 = $10. B = 1 × ($3000/30) = $100. B wins.
function test_WPM_020(): void { $r = _wpm(1,  '10.00', '70.00',  '3000.00'); Assert::bcequal('100.00', $r['chosen_amount']); Assert::equal('B', $r['chosen_option']); }
function test_WPM_021(): void { $r = _wpm(5,  '10.00', '70.00',  '3000.00'); Assert::bcequal('500.00', $r['chosen_amount']); Assert::equal('B', $r['chosen_option']); }
// $5/$50/$2000. 3 days: A = 3×$5 = $15. B = 3 × ($2000/30) = 3×$66.67 = $200.
function test_WPM_022(): void { $r = _wpm(3,  '5.00',  '50.00',  '2000.00'); Assert::bcequal('200.00', $r['chosen_amount']); Assert::equal('B', $r['chosen_option']); }
// $50/$350/$3000. 5 days: A=$250. B=5×$100=$500.
function test_WPM_023(): void { $r = _wpm(5,  '50.00', '350.00', '3000.00'); Assert::bcequal('500.00', $r['chosen_amount']); }
// $50/$350/$3000. 6 days: A=$350 (weekly_flat). B=6×$100=$600.
function test_WPM_024(): void { $r = _wpm(6,  '50.00', '350.00', '3000.00'); Assert::bcequal('600.00', $r['chosen_amount']); }

// ── WPM-030..037 activation-only (8) — DB tests ──────────────
function _wpm_lease_inv(string $start, string $end, string $type = 'partial_start'): array {
    $custId = Fixtures::createCustomer(['province' => 'BC']);
    $lease  = Fixtures::createLease($custId, [
        'start_date'     => $start,
        'engine_version' => 'holistic',
    ]);
    return Fixtures::generateInvoice($lease, $start, $end, $type);
}

// WPM-030: Inv 1 activation, 4 days → $200 (WPM picks A=$200 over B=$93.33)
function test_WPM_030(): void {
    DbState::inTransaction(function() {
        $inv = _wpm_lease_inv('2026-03-28', '2026-03-31');
        $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv['invoice_id']]);
        Assert::bcequal('200.00', (string)$row['amount']);
    });
}
// WPM-031: After Inv 1 ($200), Inv 2 covering April (30 days). delta = $793.33 - $200 = $593.33 (NO WPM).
function test_WPM_031(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2   = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row    = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv2['invoice_id']]);
        Assert::bcequal('593.33', (string)$row['amount']);
    });
}
// WPM-032: Void Inv 1 first, then Inv 2 → WPM applies again because already_billed = $0.
function test_WPM_032(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $inv1   = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        db_execute("UPDATE invoices SET status='void' WHERE id=?", [$inv1['invoice_id']]);
        $inv2   = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $line   = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv2['invoice_id']]);
        // After void, already_billed = 0. Inv 2 is the new "activation". WPM uses period_days (30).
        // A = applyTier(30) = $700; B = 30 × ($700/30) = $700. Tie → A. Engine picks $700.
        Assert::bcequal('700.00', (string)$line['amount']);
    });
}
// WPM-033: Inv 1 in DRAFT, Inv 2 → already_billed includes draft. WPM does NOT apply.
function test_WPM_033(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        // Inv 1 stays draft by default
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv2['invoice_id']]);
        Assert::bcequal('593.33', (string)$row['amount']);  // reconciliation, not WPM
    });
}
// WPM-034: Three sequential invoices — WPM never re-applies after Inv 1.
function test_WPM_034(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $inv3 = Fixtures::generateInvoice($lease, '2026-05-01', '2026-05-31', 'full_month');
        // 65 days cumulative → engine $1516.67. Already billed $200 + $593.33 = $793.33. delta = $723.34.
        $line = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv3['invoice_id']]);
        Assert::bcequal('723.34', (string)$line['amount']);
    });
}
// WPM-035: Spec scenario "Inv 1 with $0 base (mileage_only)". InvoiceGenerator's
// unconditional lease_billing_periods INSERT (period_type = billing_type) rejects
// 'mileage_only' because the ENUM only accepts partial_start/full_month/partial_end/
// single_period. That's a pre-existing engine/schema interaction (not introduced
// by S-BILLING-HOLISTIC-ENGINE) — out of scope for this test session.
//
// Equivalent state: simulate "prior invoice exists but contributes $0 to already_billed"
// by inserting a base_rental line with amount=$0 via direct SQL. WPM should still
// apply on Inv 2 because already_billed (signed sum) remains $0.
function test_WPM_035(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        // Manually craft a $0 prior invoice + $0 base_rental line.
        $invId = db_insert('invoices', [
            'invoice_number'        => 'TEST-MIL-' . uniqid(),
            'invoice_type'          => 'regular',
            'customer_id'           => $custId,
            'lease_id'              => $lease,
            'billing_period_start'  => '2026-03-28',
            'billing_period_end'    => '2026-03-31',
            'billing_period_days'   => 4,
            'billing_type'          => 'single_period',
            'rate_method_used'      => 'none',
            'invoice_date'          => '2026-03-28',
            'due_date'              => '2026-04-28',
            'status'                => 'draft',
            'subtotal'              => '0.00',
            'subtotal_after_discount' => '0.00',
            'total_amount'          => '0.00',
            'balance_due'           => '0.00',
            'currency'              => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id' => $invId, 'sort_order' => 0,
            'item_type'  => 'base_rental', 'description' => 'zero base',
            'unit_price' => '0.00', 'amount' => '0.00', 'is_credit' => 0, 'taxable' => 1,
        ]);
        // Now run a real activation invoice.
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-04', 'partial_start');
        $line = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv2['invoice_id']]);
        // already_billed = $0 (the manual line contributes $0). Engine sees this as activation.
        // Period = 4 days. WPM: A = 4×$50 = $200, B = 4×$23.33 = $93.33 → A = $200.
        Assert::bcequal('200.00', (string)$line['amount']);
    });
}
// WPM-036: full_month activation. Inv 1 covers 30 days. A = applyTier(30) = $700. B = 30×$23.33 = $700. Tie → A.
function test_WPM_036(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-04-01', 'engine_version' => 'holistic']);
        $inv1   = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $line   = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv1['invoice_id']]);
        Assert::bcequal('700.00', (string)$line['amount']);
    });
}
// WPM-037: First invoice after all prior invoices voided behaves as a new activation.
function test_WPM_037(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $inv1   = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2   = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        db_execute("UPDATE invoices SET status='void' WHERE id IN (?, ?)", [$inv1['invoice_id'], $inv2['invoice_id']]);
        $inv3   = Fixtures::generateInvoice($lease, '2026-05-01', '2026-05-04', 'partial_start');
        $line   = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv3['invoice_id']]);
        // Period = 4 days (May 1-4). Total days cumulative = 68 (Mar 28 - May 4). But already_billed=0 (both voided).
        // Engine: WPM A = applyTierFormula(4 period days) = $200. B = 4 × ($700/30) = $93.33. → $200.
        // But cumulative_correct from total_days=68 = engine compute. WPM REPLACES that for activation.
        // Engine chose: cumulative_correct = WPM($200) = $200. delta = $200 - 0 = $200.
        Assert::bcequal('200.00', (string)$line['amount']);
    });
}

// ── WPM-040..046 edge cases (7) ──────────────────────────────
// WPM-040: 0 days period. A = 0×$50 = 0. B = 0 × $23.33 = 0. → $0.
function test_WPM_040(): void { $r = _wpm(0); Assert::bcequal('0.00', $r['chosen_amount']); }
// WPM-041: daily = monthly/30 exactly. Day 1: A = $23.33, B = $23.33 → tie, engine picks A.
function test_WPM_041(): void {
    $r = _wpm(1, '23.33', '163.33', '700.00');
    // A = 1×$23.33 = $23.33; B = 1 × bcdiv('700','30',6) = $23.333333... → bcround = $23.33.
    Assert::bcequal('23.33', $r['chosen_amount']);
    Assert::true($r['chosen_option'] === 'A' || $r['chosen_option'] === 'tie');
}
// WPM-042: 10 days (weekly_math tier). A = applyTier(10) = $500. B = 10×$23.33 = $233.33. → A.
function test_WPM_042(): void { $r = _wpm(10); Assert::bcequal('500.00', $r['chosen_amount']); }
// WPM-043: negative rates. applyTierFormula with negative daily produces negative — engine doesn't reject.
// WPM picks max(neg, neg) — still a number, no throw. Documented engine behavior.
function test_WPM_043(): void {
    $r = _wpm(1, '-50.00', '-350.00', '-700.00');
    Assert::true(bccomp($r['chosen_amount'], '0', 2) <= 0);
}
// WPM-044: monthly=$0. B = days × 0/30 = 0. A might be > 0. WPM picks A.
function test_WPM_044(): void { $r = _wpm(1, '50.00', '350.00', '0.00'); Assert::bcequal('50.00', $r['chosen_amount']); }
// WPM-045: daily=$0, period 30 days. A = applyTier(30) = $700 (monthly_math tier with monthly=$700). B = 30 × ($700/30) = $700. Tie → A.
function test_WPM_045(): void { $r = _wpm(30, '0.00', '350.00', '700.00'); Assert::bcequal('700.00', $r['chosen_amount']); }
// WPM-046: 5-year (1825 day) period activation. Engine handles huge periods.
function test_WPM_046(): void {
    $r = _wpm(1825);
    Assert::true(bccomp($r['chosen_amount'], '0', 2) > 0);
}
