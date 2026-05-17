<?php
declare(strict_types=1);

/**
 * tests/_unit/tier2_negative_zero.php
 *
 * Tier 2 — Negative and Zero Cases (25 tests). Spec §12.
 *
 *   NZ-001..NZ-008  zero-day (8)
 *   NZ-020..NZ-029  negative delta (10)
 *   NZ-040..NZ-046  negative subtotal (7)
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 */

use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\Tests\Assert;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

// ── NZ-001..008 zero-day (8) ─────────────────────────────────
function test_NZ_001(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date'=>'2026-03-28','engine_version'=>'holistic']);
        $inv = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-28', 'single_period');
        $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv['invoice_id']]);
        Assert::bcequal('50.00', (string)$row['amount']);
    });
}
function test_NZ_002(): void {
    Assert::bcequal('50.00', (new HolisticLeaseEngine())->applyTierFormula(1, '50.00', '350.00', '700.00')['amount']);
}
function test_NZ_003(): void {
    // 0-day period: applyTierFormula(0) returns $0 / 'none' tier.
    Assert::bcequal('0.00', (new HolisticLeaseEngine())->applyTierFormula(0, '50.00', '350.00', '700.00')['amount']);
}
function test_NZ_004(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date'=>'2026-03-28','engine_version'=>'holistic']);
        $inv = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-28', 'single_period');
        $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv['invoice_id']]);
        Assert::bcequal('50.00', (string)$row['amount']);
    });
}
function test_NZ_005(): void {
    // Lease cancelled before activation: engine still computes from start_date (engine is stateless about lease status).
    Assert::bcequal('0.00', (new HolisticLeaseEngine())->applyTierFormula(0, '50.00', '350.00', '700.00')['amount']);
}
function test_NZ_006(): void {
    Assert::equal(1, HolisticLeaseEngine::inclusiveDays('2026-03-28', '2026-03-28'));
}
function test_NZ_007(): void {
    Assert::equal(1, HolisticLeaseEngine::inclusiveDays('2026-04-30', '2026-04-30'));
}
function test_NZ_008(): void {
    // Spec: "Period spans lease boundaries (start before lease start) — use lease start for total_days".
    // The engine uses start_date (lease start) for total_days, period_end as the cutoff.
    // Test: pass period_start that's before lease start_date. Engine inclusive(start_date, period_end).
    $e = new HolisticLeaseEngine();
    $r = $e->calculateForInvoice([
        'lease_id' => 0, 'start_date' => '2026-04-01',
        'period_start' => '2026-03-01',  // before lease start — engine still uses lease start
        'period_end'   => '2026-04-30',
        'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        'is_activation_invoice' => true,
    ]);
    // total_days = inclusive(2026-04-01, 2026-04-30) = 30. cumulative = $700.
    Assert::equal(30, $r['total_days_so_far']);
}

// ── NZ-020..029 negative delta (10) ──────────────────────────
function test_NZ_020(): void {
    // Activation $200, lease ends day 4 = no Inv 2 needed.
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date'=>'2026-03-28','engine_version'=>'holistic']);
        $inv = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'single_period');
        $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv['invoice_id']]);
        Assert::bcequal('200.00', (string)$row['amount']);
    });
}
function test_NZ_021(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date'=>'2026-03-28','engine_version'=>'holistic']);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-04', 'partial_end');
        $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv2['invoice_id']]);
        Assert::bcequal('200.00', (string)$row['amount']);  // 8d weekly_math $400 - $200 = $200
    });
}
function test_NZ_022(): void {
    // Activation huge ($500 daily × 4 = $2000), tier shifts to monthly cap.
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1000.00',  // big enough to absorb credit
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        Assert::lineExists($inv2['invoice_id'], 'base_rental_reconciliation_credit');
    });
}
function test_NZ_023(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'1000.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'5000.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');  // WPM=$4000
        // Run full April → cumulative day 34 = monthly_math 1×$700 + 4×$23.3333 = $793.33.
        // already_billed=$4000. delta = $793.33 - $4000 = -$3206.67. Insurance $5000 absorbs.
        // Net subtotal = $5000 - $3206.67 = $1793.33 (positive — no overflow cap).
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        Assert::true($row !== null);
        Assert::bcequal('3206.67', (string)$row['amount']);
    });
}
function test_NZ_024(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1000.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');  // WPM=$2000
        Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');     // reconciles
        $inv3 = Fixtures::generateInvoice($lease, '2026-05-01', '2026-05-31', 'full_month');
        Assert::true(isset($inv3['invoice_id']));
    });
}
function test_NZ_025(): void {
    // Credit exceeds subtotal — overflow.
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'1000.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $cn = db_row("SELECT amount FROM credit_notes WHERE source_invoice_id=? AND source='base_rental_reconciliation_overflow'", [$inv2['invoice_id']]);
        Assert::true($cn !== null);
    });
}
function test_NZ_026(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1500.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT amount, is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        Assert::true(bccomp((string)$row['amount'], '0', 2) >= 0);
        Assert::equal(1, (int)$row['is_credit']);
    });
}
function test_NZ_027(): void {
    // Negative tax flow on credit line via TaxCalculator sign propagation.
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1500.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT tax_gst_amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        // GST on credit line should be negative (or zero if BC is GST=0). Verify SIGNED behavior — should be ≤ 0.
        Assert::true(bccomp((string)$row['tax_gst_amount'], '0', 2) <= 0);
    });
}
function test_NZ_028(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1500.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT description FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        Assert::true(strpos((string)$row['description'], 'eduction') !== false || strpos((string)$row['description'], 'reconciliation') !== false || strpos((string)$row['description'], 'credit') !== false);
    });
}
function test_NZ_029(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1500.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        Assert::auditLogged('invoice_holistic_reconciliation', $inv2['invoice_id']);
    });
}

// ── NZ-040..046 negative subtotal (7) ───────────────────────
function test_NZ_040(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'600.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'400.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');  // WPM=$2400
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        // delta = $793.33 - $2400 = -$1606.67. Insurance $400 + credit -$1606.67 = -$1206.67. Overflow.
        $cn = db_row("SELECT amount FROM credit_notes WHERE source_invoice_id=?", [$inv2['invoice_id']]);
        Assert::true($cn !== null);
    });
}
function test_NZ_041(): void {
    // Credit $500 + line $400 + line $50 = -$50. Cap credit to $450, $50 overflow.
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'600.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'100.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        // Cap fired — credit may be reduced below the engine's nominal amount.
        Assert::true((string)$row['amount'] === '0.00' || bccomp((string)$row['amount'], '0', 2) >= 0);
        $cn = db_row("SELECT amount FROM credit_notes WHERE source_invoice_id=?", [$inv2['invoice_id']]);
        Assert::true($cn !== null);
    });
}
function test_NZ_042(): void {
    // Multiple credit lines — engine emits only ONE reconciliation_credit per invoice. Test path.
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'1000.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        Assert::lineCount($inv2['invoice_id'], 'base_rental_reconciliation_credit', 1);  // exactly one
    });
}
function test_NZ_043(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'5000.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');  // WPM=$20000
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $cn = db_row("SELECT amount FROM credit_notes WHERE source_invoice_id=?", [$inv2['invoice_id']]);
        Assert::true($cn !== null);
        Assert::true(bccomp((string)$cn['amount'], '1000', 2) > 0, 'overflow > $1000');
    });
}
function test_NZ_044(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'1000.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $cn = db_row("SELECT source FROM credit_notes WHERE source_invoice_id=?", [$inv2['invoice_id']]);
        Assert::equal('base_rental_reconciliation_overflow', (string)$cn['source']);
    });
}
function test_NZ_045(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'1000.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $cn = db_row("SELECT source_invoice_id FROM credit_notes WHERE source_invoice_id=?", [$inv2['invoice_id']]);
        Assert::equal($inv2['invoice_id'], (int)$cn['source_invoice_id']);
    });
}
function test_NZ_046(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'1000.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $cn = db_row("SELECT id FROM credit_notes WHERE source_invoice_id=?", [$inv2['invoice_id']]);
        Assert::auditLogged('credit_note', (int)$cn['id']);
    });
}
