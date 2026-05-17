<?php
declare(strict_types=1);

/**
 * tests/_unit/tier2_boundary.php
 *
 * Tier 2 — Boundary Value Tests (50 tests). Spec §9.
 *
 *   BV-001..BV-015  day count boundaries (15)
 *   BV-020..BV-034  rate value boundaries (15)
 *   BV-040..BV-049  date range boundaries (10)
 *   BV-050..BV-059  already-billed sum boundaries (10)
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 */

use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\Tests\Assert;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

function _bv_apply(int $d, string $da='50.00', string $w='350.00', string $m='700.00'): array {
    return (new HolisticLeaseEngine())->applyTierFormula($d, $da, $w, $m);
}

// ── BV-001..015 day count boundaries ─────────────────────────
function test_BV_001(): void { Assert::equal('none',  _bv_apply(0)['tier']);  Assert::equal('daily', _bv_apply(1)['tier']); }
function test_BV_002(): void { Assert::equal('daily',       _bv_apply(5)['tier']); Assert::equal('weekly_flat',  _bv_apply(6)['tier']); }
function test_BV_003(): void { Assert::equal('weekly_flat', _bv_apply(7)['tier']); Assert::equal('weekly_math',  _bv_apply(8)['tier']); }
function test_BV_004(): void { Assert::true(in_array(_bv_apply(29)['tier'], ['weekly_math','weekly_capped'])); Assert::equal('monthly_math', _bv_apply(30)['tier']); }
function test_BV_005(): void { Assert::bcequal('50.00',  _bv_apply(1)['amount']); }
function test_BV_006(): void { Assert::bcequal('250.00', _bv_apply(5)['amount']); }
function test_BV_007(): void { Assert::bcequal('350.00', _bv_apply(6)['amount']); }
function test_BV_008(): void { Assert::bcequal('350.00', _bv_apply(7)['amount']); }
function test_BV_009(): void { Assert::bcequal('700.00', _bv_apply(14)['amount']); }
function test_BV_010(): void { Assert::bcequal('700.00', _bv_apply(30)['amount']); }
function test_BV_011(): void {
    Assert::bcequal('700.00',  _bv_apply(30)['amount']);
    Assert::bcequal('1400.00', _bv_apply(60)['amount']);
    Assert::bcequal('2100.00', _bv_apply(90)['amount']);
    Assert::bcequal('2800.00', _bv_apply(120)['amount']);
}
function test_BV_012(): void { Assert::bcequal('723.33', _bv_apply(31)['amount']); Assert::bcequal('746.67', _bv_apply(32)['amount']); }
function test_BV_013(): void { Assert::bcequal('2076.67', _bv_apply(89)['amount']); Assert::bcequal('2100.00', _bv_apply(90)['amount']); }
function test_BV_014(): void { Assert::bcequal('8516.67', _bv_apply(365)['amount']); }
function test_BV_015(): void { Assert::bcequal('8540.00', _bv_apply(366)['amount']); }

// ── BV-020..034 rate value boundaries ───────────────────────
function test_BV_020(): void { Assert::bcequal('0.01', _bv_apply(1, '0.01', '0.07', '0.30')['amount']); }
// BV-021: zero rate + billable days. applyTierFormula returns $0 (no throw at this layer).
// D132 throw happens at calculateForInvoice — covered by TF-048.
function test_BV_021(): void { Assert::bcequal('0.00', _bv_apply(1, '0.00', '0.00', '0.00')['amount']); }
function test_BV_022(): void { Assert::bcequal('999999.99', _bv_apply(1, '999999.99', '0.07', '0.30')['amount']); }
function test_BV_023(): void { Assert::bcequal('0.00', _bv_apply(1, '0.001', '0.07', '0.30')['amount']); /* rounds to $0 at 2 dp */ }
function test_BV_024(): void {
    $r = _bv_apply(1, '-50.00', '350.00', '700.00');
    Assert::true(bccomp($r['amount'], '0', 2) < 0, 'negative rate produces negative output');
}
function test_BV_025(): void { Assert::bcequal('250.00', _bv_apply(5, '50', '350', '700')['amount']); }
function test_BV_026(): void { Assert::bcequal('250.00', _bv_apply(5, '50.00', '350.00', '700.00')['amount']); }
// BV-027: scientific notation. bcmath rejects it — throws ValueError.
function test_BV_027(): void {
    Assert::throws(\Error::class, fn() => _bv_apply(1, '5e1', '350', '700'));
}
// BV-028: locale comma. bcmath rejects.
function test_BV_028(): void {
    Assert::throws(\Error::class, fn() => _bv_apply(1, '50,00', '350', '700'));
}
function test_BV_029(): void { Assert::bcequal('50.00', _bv_apply(1, '50.00', '50.00', '50.00')['amount']); }
function test_BV_030(): void {
    // Inverted rates — engine still computes per spec (no validation).
    $r = _bv_apply(1, '700.00', '350.00', '50.00');
    Assert::bcequal('700.00', $r['amount']);  // 1 day × $700 daily
}
function test_BV_031(): void { Assert::bcequal('1000000000.00', _bv_apply(1, '1000000000', '350', '700')['amount']); }
// BV-032: rate = $23.33 (matches monthly/30 exactly).
function test_BV_032(): void { Assert::bcequal('23.33', _bv_apply(1, '23.33', '163.33', '700')['amount']); }
// BV-033: rate $0.33 (recurring decimal /30 = $0.011).
function test_BV_033(): void {
    $r = _bv_apply(30, '0.33', '2.31', '10.00');  // 30 days monthly tier = $10
    Assert::bcequal('10.00', $r['amount']);
}
function test_BV_034(): void {
    // Rate lookup is outside engine scope — engine takes rates as parameters. Sanity: passing
    // explicit rate strings works regardless of where they came from.
    Assert::bcequal('700.00', _bv_apply(30, '50.00', '350.00', '700.00')['amount']);
}

// ── BV-040..049 date range boundaries ───────────────────────
function test_BV_040(): void { Assert::equal(28, HolisticLeaseEngine::inclusiveDays('2026-02-01', '2026-02-28')); }
function test_BV_041(): void { Assert::equal(29, HolisticLeaseEngine::inclusiveDays('2024-02-01', '2024-02-29')); }
function test_BV_042(): void { Assert::equal(2,  HolisticLeaseEngine::inclusiveDays('2025-12-31', '2026-01-01')); }
function test_BV_043(): void { Assert::equal(365, HolisticLeaseEngine::inclusiveDays('2026-01-01', '2026-12-31')); }
function test_BV_044(): void { Assert::equal(0,   HolisticLeaseEngine::inclusiveDays('2026-04-30', '2026-04-15')); /* defensive 0 */ }
function test_BV_045(): void { Assert::equal(1,   HolisticLeaseEngine::inclusiveDays('2026-03-28', '2026-03-28')); }
function test_BV_046(): void { Assert::equal(1,   HolisticLeaseEngine::inclusiveDays('1970-01-01', '1970-01-01')); }
function test_BV_047(): void { Assert::equal(1,   HolisticLeaseEngine::inclusiveDays('2099-12-31', '2099-12-31')); }
// BV-048: DST. Engine uses DATE (no DST sensitivity).
function test_BV_048(): void { Assert::equal(2,   HolisticLeaseEngine::inclusiveDays('2026-03-13', '2026-03-14')); /* Spring forward in US 2026-03-08 */ }
function test_BV_049(): void { Assert::equal(366, HolisticLeaseEngine::inclusiveDays('2024-01-01', '2024-12-31')); }

// ── BV-050..059 already-billed sum boundaries ───────────────
function _bv_alreadyBilled(int $leaseId): string {
    $engine = new HolisticLeaseEngine();
    $r = $engine->calculateForInvoice([
        'lease_id' => $leaseId, 'start_date' => '2026-03-28',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        'is_activation_invoice' => false,
    ]);
    return $r['already_billed'];
}
function test_BV_050(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        Assert::bcequal('0.00', _bv_alreadyBilled($lease));
    });
}
function test_BV_051(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'draft','subtotal'=>'0.01','subtotal_after_discount'=>'0.01','total_amount'=>'0.01','balance_due'=>'0.01','currency'=>'CAD']);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'x','unit_price'=>'0.01','amount'=>'0.01','is_credit'=>0,'taxable'=>1]);
        Assert::bcequal('0.01', _bv_alreadyBilled($lease));
    });
}
// BV-052: already_billed = cumulative_correct (delta=0). Need to engineer this.
function test_BV_052(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        // Pre-bill the full $50 cumulative (1 day daily). Then query the engine for period covering day 1.
        $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'draft','subtotal'=>'50','subtotal_after_discount'=>'50','total_amount'=>'50','balance_due'=>'50','currency'=>'CAD']);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'x','unit_price'=>'50','amount'=>'50','is_credit'=>0,'taxable'=>1]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id'=>$lease,'start_date'=>'2026-03-28','period_start'=>'2026-03-28','period_end'=>'2026-03-28',
            'daily_rate'=>'50.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00','is_activation_invoice'=>false,
        ]);
        Assert::bcequal('50.00', $r['cumulative_correct']);
        Assert::bcequal('50.00', $r['already_billed']);
        Assert::bcequal('0.00',  $r['delta']);
    });
}
function test_BV_053(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'draft','subtotal'=>'50.01','subtotal_after_discount'=>'50.01','total_amount'=>'50.01','balance_due'=>'50.01','currency'=>'CAD']);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'x','unit_price'=>'50.01','amount'=>'50.01','is_credit'=>0,'taxable'=>1]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id'=>$lease,'start_date'=>'2026-03-28','period_start'=>'2026-03-28','period_end'=>'2026-03-28',
            'daily_rate'=>'50.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00','is_activation_invoice'=>false,
        ]);
        Assert::bcequal('-0.01', $r['delta']);
        Assert::equal('base_rental_reconciliation_credit', $r['line_item_type']);
    });
}
function test_BV_054(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'draft','subtotal'=>'1050','subtotal_after_discount'=>'1050','total_amount'=>'1050','balance_due'=>'1050','currency'=>'CAD']);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'x','unit_price'=>'1050','amount'=>'1050','is_credit'=>0,'taxable'=>1]);
        Assert::bcequal('1050.00', _bv_alreadyBilled($lease));
    });
}
function test_BV_055(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        // Mileage-only equivalent: invoice with mileage_usage but no base_rental.
        $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'draft','subtotal'=>'100','subtotal_after_discount'=>'100','total_amount'=>'100','balance_due'=>'100','currency'=>'CAD']);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'mileage_usage','description'=>'m','unit_price'=>'100','amount'=>'100','is_credit'=>0,'taxable'=>1]);
        Assert::bcequal('0.00', _bv_alreadyBilled($lease));  // mileage doesn't count toward base
    });
}
function test_BV_056(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-01-01', 'engine_version' => 'holistic']);
        // 50 prior $10 invoices.
        for ($i = 0; $i < 50; $i++) {
            $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-01-01','billing_period_end'=>'2026-01-01','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-01-01','due_date'=>'2026-02-01','status'=>'draft','subtotal'=>'10','subtotal_after_discount'=>'10','total_amount'=>'10','balance_due'=>'10','currency'=>'CAD']);
            db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'x','unit_price'=>'10','amount'=>'10','is_credit'=>0,'taxable'=>1]);
        }
        Assert::bcequal('500.00', _bv_alreadyBilled($lease));
    });
}
function test_BV_057(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'draft','subtotal'=>'0','subtotal_after_discount'=>'0','total_amount'=>'0','balance_due'=>'0','currency'=>'CAD']);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'a','unit_price'=>'200','amount'=>'200','is_credit'=>0,'taxable'=>1]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>1,'item_type'=>'base_rental_reconciliation_credit','description'=>'b','unit_price'=>'200','amount'=>'200','is_credit'=>1,'taxable'=>1]);
        Assert::bcequal('0.00', _bv_alreadyBilled($lease));  // $200 - $200 = $0
    });
}
function test_BV_058(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        for ($i = 0; $i < 3; $i++) {
            $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'void','subtotal'=>'200','subtotal_after_discount'=>'200','total_amount'=>'200','balance_due'=>'200','currency'=>'CAD']);
            db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'x','unit_price'=>'200','amount'=>'200','is_credit'=>0,'taxable'=>1]);
        }
        Assert::bcequal('0.00', _bv_alreadyBilled($lease));
    });
}
function test_BV_059(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', ['invoice_number'=>'TEST-'.uniqid(),'invoice_type'=>'regular','customer_id'=>$custId,'lease_id'=>$lease,'billing_period_start'=>'2026-03-28','billing_period_end'=>'2026-03-28','billing_period_days'=>1,'billing_type'=>'single_period','rate_method_used'=>'none','invoice_date'=>'2026-03-28','due_date'=>'2026-04-28','status'=>'draft','subtotal'=>'300','subtotal_after_discount'=>'300','total_amount'=>'300','balance_due'=>'300','currency'=>'CAD']);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'a','unit_price'=>'100','amount'=>'100','is_credit'=>0,'taxable'=>1]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>1,'item_type'=>'base_rental','description'=>'b','unit_price'=>'200','amount'=>'200','is_credit'=>0,'taxable'=>1]);
        Assert::bcequal('300.00', _bv_alreadyBilled($lease));
    });
}
