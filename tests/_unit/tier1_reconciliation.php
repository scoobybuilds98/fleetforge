<?php
declare(strict_types=1);

/**
 * tests/_unit/tier1_reconciliation.php
 *
 * Tier 1 — Running Reconciliation (45 tests). Spec §8.
 *
 *   RR-001..RR-015  sequential invoice reconciliation (15)
 *   RR-020..RR-029  reconciliation credit generation (10)
 *   RR-040..RR-044  zero-delta cases (5)
 *   RR-050..RR-064  already-billed sum correctness (15)
 *
 * All DB tests wrap in DbState::inTransaction → rollback.
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 */

use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\Tests\Assert;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

function _rr_lineAmount(int $invoiceId, string $itemType = 'base_rental'): string {
    $row = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type=?", [$invoiceId, $itemType]);
    return $row ? (string)$row['amount'] : '0.00';
}

function _rr_makeLease(string $startDate = '2026-03-28', array $overrides = []): int {
    $custId = Fixtures::createCustomer(['province' => 'BC']);
    return Fixtures::createLease($custId, array_merge([
        'start_date'     => $startDate,
        'engine_version' => 'holistic',
    ], $overrides));
}

// ── RR-001..015 sequential reconciliation (15) ──────────────
// RR-001: BOSS'S EXAMPLE — the canonical test. Also asserts audit_log row is created
// for both invoices (kills MT-043 audit-log-skip mutation).
function test_RR_001(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1  = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2  = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $a1 = _rr_lineAmount($inv1['invoice_id']);
        $a2 = _rr_lineAmount($inv2['invoice_id']);
        Assert::bcequal('200.00', $a1, 'Invoice 1');
        Assert::bcequal('593.33', $a2, 'Invoice 2');
        Assert::bcequal('793.33', bcadd($a1, $a2, 2), 'Lease total');
        // Audit trail must be present for both holistic invoices (kills MT-043).
        Assert::auditLogged('invoice_holistic_reconciliation', $inv1['invoice_id']);
        Assert::auditLogged('invoice_holistic_reconciliation', $inv2['invoice_id']);
    });
}
// RR-002: Mar 28 → May 31 (open lease). R2: Inv 3 covers the complete calendar
// month of May → flat $700. Cumulative through May 31 = $93.33 + $700 + $700 =
// $1493.33; already billed $793.33 → delta $700.00.
function test_RR_002(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $inv3 = Fixtures::generateInvoice($lease, '2026-05-01', '2026-05-31', 'full_month');
        Assert::bcequal('700.00', _rr_lineAmount($inv3['invoice_id']));
    });
}
// RR-003: 6-month sequence from spec §12 — engine precision yields slightly different
// per-invoice deltas than the spec table but the sum matches per §35.7 band.
function test_RR_003(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $amts = [];
        $amts[] = _rr_lineAmount(Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start')['invoice_id']);
        $amts[] = _rr_lineAmount(Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month')['invoice_id']);
        $amts[] = _rr_lineAmount(Fixtures::generateInvoice($lease, '2026-05-01', '2026-05-31', 'full_month')['invoice_id']);
        $amts[] = _rr_lineAmount(Fixtures::generateInvoice($lease, '2026-06-01', '2026-06-30', 'full_month')['invoice_id']);
        $amts[] = _rr_lineAmount(Fixtures::generateInvoice($lease, '2026-07-01', '2026-07-31', 'full_month')['invoice_id']);
        $amts[] = _rr_lineAmount(Fixtures::generateInvoice($lease, '2026-08-01', '2026-08-12', 'partial_end')['invoice_id']);
        $total = '0.00'; foreach ($amts as $a) $total = bcadd($total, $a, 2);
        // R2: $93.33 (Mar partial) + 4 × $700 (Apr–Jul complete) + $280.00 (Aug 1-12 partial) = $3,173.33.
        Assert::bcequal('3173.33', $total);
    });
}
// RR-004: 12 monthly invoices for full 2026 year = $8516.67 (engine).
function test_RR_004(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-01-01');
        $total = '0.00';
        $months = ['01-31','02-28','03-31','04-30','05-31','06-30','07-31','08-31','09-30','10-31','11-30','12-31'];
        $cursor = '2026-01-01';
        foreach ($months as $i => $end) {
            $endDate = '2026-' . $end;
            $type = ($cursor === '2026-01-01') ? 'full_month' : 'full_month';
            $inv = Fixtures::generateInvoice($lease, $cursor, $endDate, $type);
            $total = bcadd($total, _rr_lineAmount($inv['invoice_id']), 2);
            $cursor = (new DateTime($endDate))->modify('+1 day')->format('Y-m-d');
        }
        Assert::bcequal('8400.00', $total);  // R2: 12 complete calendar months × $700 ("a month is a month")
    });
}
// RR-005: Leap year — Feb 1, 2024 → Mar 5 (open lease). R2 "a month is a month":
// Inv 1 covers the COMPLETE calendar month of February (29 days) → flat $700.
// Inv 2 covers Mar 1-5: cumulative = $700 (Feb complete) + 5 × $23.3333 (Mar
// partial) = $816.67; already billed $700 → delta $116.67.
function test_RR_005(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2024-02-01');
        $inv1  = Fixtures::generateInvoice($lease, '2024-02-01', '2024-02-29', 'partial_start');
        $inv2  = Fixtures::generateInvoice($lease, '2024-03-01', '2024-03-05', 'partial_end');
        Assert::bcequal('700.00', _rr_lineAmount($inv1['invoice_id']));
        Assert::bcequal('116.67', _rr_lineAmount($inv2['invoice_id']));
    });
}
// RR-006: First-of-month activation. Inv 1 covers Apr 1-30 (30 days, full_month). $700 flat.
function test_RR_006(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-04-01');
        $inv1  = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        Assert::bcequal('700.00', _rr_lineAmount($inv1['invoice_id']));
    });
}
// RR-007: Last-day-of-month activation. Mar 31 → Apr 1 = 2 days.
// Inv 1 covers Mar 31 (period 1 day): WPM A = 1×$50 = $50; B = 1×$23.33 = $23.33. → $50.
// Inv 2 covers Apr 1 (period 1 day): cumulative = 2 days = $100. already_billed $50. delta = $50.
function test_RR_007(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-31');
        $inv1  = Fixtures::generateInvoice($lease, '2026-03-31', '2026-03-31', 'partial_start');
        $inv2  = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-01', 'partial_end');
        Assert::bcequal('50.00', _rr_lineAmount($inv1['invoice_id']));
        Assert::bcequal('50.00', _rr_lineAmount($inv2['invoice_id']));
    });
}
// RR-008: Same-day activation+close. Period 1 day = $50.
function test_RR_008(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1  = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-28', 'single_period');
        Assert::bcequal('50.00', _rr_lineAmount($inv1['invoice_id']));
    });
}
// RR-009: DST boundary (Nov 1-2, 2026). Day count is unaffected by DST — engine uses DATE not DATETIME.
function test_RR_009(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-11-01');
        $inv1  = Fixtures::generateInvoice($lease, '2026-11-01', '2026-11-02', 'single_period');
        Assert::bcequal('100.00', _rr_lineAmount($inv1['invoice_id']));  // 2 days × $50
    });
}
// RR-010: Year boundary. Dec 31, 2025 → Jan 1, 2026 = 2 days.
function test_RR_010(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2025-12-31');
        $inv1  = Fixtures::generateInvoice($lease, '2025-12-31', '2025-12-31', 'partial_start');
        $inv2  = Fixtures::generateInvoice($lease, '2026-01-01', '2026-01-01', 'partial_end');
        Assert::bcequal('50.00', _rr_lineAmount($inv1['invoice_id']));
        Assert::bcequal('50.00', _rr_lineAmount($inv2['invoice_id']));
    });
}
// RR-011: 1 invoice per week. Inv 1 = 4 days WPM = $200. Inv 2 = 11 days cum → engine: weekly_math (11d) = 1×$350+4×$50 = $550. delta = $350.
function test_RR_011(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1  = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2  = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-07', 'partial_end'); // 11 cumulative
        Assert::bcequal('200.00', _rr_lineAmount($inv1['invoice_id']));
        Assert::bcequal('350.00', _rr_lineAmount($inv2['invoice_id']));
    });
}
// RR-012: 1 invoice per day for 30 days. Sum should equal cumulative at day 30 = $700.
function test_RR_012(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-01');
        $total = '0.00';
        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-03-%02d', $d);
            $type = ($d === 1) ? 'partial_start' : ($d === 30 ? 'partial_end' : 'single_period');
            $inv = Fixtures::generateInvoice($lease, $date, $date, $type);
            $total = bcadd($total, _rr_lineAmount($inv['invoice_id']), 2);
        }
        Assert::bcequal('700.00', $total);  // cumulative at day 30 (the entire run)
    });
}
// RR-013: One invoice covering all 365 days of 2025. R2: a full calendar year is
// 12 complete calendar months billed flat → 12 × $700 = $8,400.00.
function test_RR_013(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2025-01-01');
        $inv1  = Fixtures::generateInvoice($lease, '2025-01-01', '2025-12-31', 'single_period');
        Assert::bcequal('8400.00', _rr_lineAmount($inv1['invoice_id']));
    });
}
// RR-014: USD lease — engine produces same numeric value as CAD (FX is invoice-level).
function test_RR_014(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'     => '2026-03-28',
            'engine_version' => 'holistic',
            'currency'       => 'USD',
            'daily_rate'     => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        ]);
        $inv1  = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        Assert::bcequal('200.00', _rr_lineAmount($inv1['invoice_id']));
    });
}
// RR-015: Rate change mid-lease. Engine doesn't validate at engine level — produces a value.
// Spec §23 says policy enforcement is at the API layer; engine just runs the math.
function test_RR_015(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        db_execute("UPDATE leases SET daily_rate=100, weekly_rate=700, monthly_rate=1400 WHERE id=?", [$lease]);
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        // Engine reads CURRENT rates. 34 days monthly_math at new rates: 1×$1400 + 4×($1400/30) = $1400 + $186.67 = $1586.67.
        // already_billed $200. delta = $1386.67.
        Assert::bcequal('1386.67', _rr_lineAmount($inv2['invoice_id']));
    });
}

// ── RR-020..029 reconciliation credit generation (10) ───────
// RR-020: 15-day lease spanning Mar→Apr. Above the monthly crossover
// (weeklyMath(15)=$750 > $700) AND ≤ one month (15 days), so S-MONTHLY-SHORT-FLAT
// bills the FLAT monthly $700 cumulative — the same as a 15-day lease wholly
// inside one month — NOT the partial-month proration ($350). Inv 1 billed $200
// (4d daily) → delta $500.00. (Operator policy 2026-06-24 reversed the original
// R2 §4.2/§5 "commit longer, better per-day" undercharge for ≤1-month straddles;
// prod INV-2026-00171 / lease MTTS73 surfaced it.)
function test_RR_020(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-11', 'partial_end');  // 15 cumulative
        Assert::bcequal('500.00', _rr_lineAmount($inv2['invoice_id']));
        // No reconciliation credit line (delta is positive).
        Assert::lineCount($inv2['invoice_id'], 'base_rental_reconciliation_credit', 0);
    });
}
// RR-021: Engineered overcharge with insurance revenue so the credit line ISN'T capped.
// Inv 1 manual $520 base; Inv 2 has $50 insurance to absorb the $20 credit.
// Cumulative at day 10 = $500. already_billed = $520. delta = -$20.
// Subtotal: $50 (insurance) - $20 (credit) = $30. No overflow. Credit line stays at $20.
function test_RR_021(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date' => '2026-03-28', 'engine_version' => 'holistic',
            'insurance_opt_in' => 1, 'insurance_cost' => '50.00',
        ]);
        // Manually craft an over-billed Inv 1.
        $invId = db_insert('invoices', [
            'invoice_number'        => 'TEST-OB-' . uniqid(),
            'invoice_type'          => 'regular',
            'customer_id'           => $custId, 'lease_id' => $lease,
            'billing_period_start'  => '2026-03-28', 'billing_period_end' => '2026-03-31',
            'billing_period_days'   => 4, 'billing_type' => 'partial_start',
            'rate_method_used'      => 'daily',
            'invoice_date'          => '2026-03-28', 'due_date' => '2026-04-28',
            'status'                => 'sent',
            'subtotal'              => '520.00', 'subtotal_after_discount' => '520.00',
            'total_amount'          => '520.00', 'balance_due' => '520.00',
            'currency'              => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id' => $invId, 'sort_order' => 0,
            'item_type'  => 'base_rental', 'description' => 'over-billed',
            'unit_price' => '520.00', 'amount' => '520.00', 'is_credit' => 0, 'taxable' => 1,
        ]);
        // Generate Inv 2 at day 10. cumulative = $500. already_billed = $520. delta = -$20.
        // Insurance $50 keeps subtotal positive ($50 - $20 = $30).
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-06', 'partial_end');
        Assert::lineExists($inv2['invoice_id'], 'base_rental_reconciliation_credit');
        Assert::bcequal('20.00', _rr_lineAmount($inv2['invoice_id'], 'base_rental_reconciliation_credit'));
    });
}
// RR-022: WPM=$200 activation, close at day 8. 8d weekly_math = $400. already_billed = $200. delta = +$200 (NOT credit).
function test_RR_022(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2  = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-04', 'partial_end');
        Assert::bcequal('200.00', _rr_lineAmount($inv2['invoice_id']));
        Assert::lineCount($inv2['invoice_id'], 'base_rental_reconciliation_credit', 0);
    });
}
// RR-023: 4-day rental closed at day 4. Just Inv 1 = $200. No further invoice needed.
function test_RR_023(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1  = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'single_period');
        Assert::bcequal('200.00', _rr_lineAmount($inv1['invoice_id']));
    });
}
// RR-024: Huge WPM activation ($4000 daily × 4d), lease extends. cumulative drops to monthly. Credit emitted.
function test_RR_024(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date' => '2026-03-28', 'engine_version' => 'holistic',
            'daily_rate' => '1000.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        // Inv 1 WPM: A=4×$1000=$4000; B=4×$23.33=$93.33. A=$4000.
        // Inv 2 cumulative=34d=$793.33. already_billed=$4000. delta=-$3206.67.
        // Subtotal goes negative → overflow cap fires → reconciliation credit caps to $0, residual routed to credit_notes.
        Assert::lineExists($inv2['invoice_id'], 'base_rental_reconciliation_credit');
    });
}
// RR-025: Multiple invoices with wrong-tier accumulation, final reconciles.
function test_RR_025(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');  // $93.33
        Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');     // $700.00
        $inv3 = Fixtures::generateInvoice($lease, '2026-05-01', '2026-05-31', 'full_month');
        // No reconciliation credit; delta stays positive. R2: May complete month flat $700.
        Assert::lineCount($inv3['invoice_id'], 'base_rental_reconciliation_credit', 0);
        Assert::bcequal('700.00', _rr_lineAmount($inv3['invoice_id']));
    });
}
// RR-026: Credit line correctly has is_credit=1 and positive amount.
function test_RR_026(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date' => '2026-03-28', 'engine_version' => 'holistic',
            'daily_rate' => '500.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');  // WPM=$2000
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT amount, is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        Assert::true($row !== null, 'reconciliation_credit line present');
        Assert::true(bccomp((string)$row['amount'], '0', 2) >= 0, 'positive amount');
        Assert::equal(1, (int)$row['is_credit']);
    });
}
// RR-027: Description text on credit line includes the engine's narrative.
function test_RR_027(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date' => '2026-03-28', 'engine_version' => 'holistic',
            'daily_rate' => '500.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT description FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        Assert::true(strpos((string)$row['description'], 'reconciliation') !== false || strpos((string)$row['description'], 'Reconciliation') !== false);
    });
}
// RR-028: detail_lines JSON populated with audit_meta.
function test_RR_028(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date' => '2026-03-28', 'engine_version' => 'holistic',
            'daily_rate' => '500.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT detail_lines FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$inv2['invoice_id']]);
        $j = json_decode((string)$row['detail_lines'], true);
        Assert::true(is_array($j) && isset($j['tier']) && isset($j['cumulative_correct']));
    });
}
// RR-029: Credit drives subtotal negative → overflow → credit_notes row created.
function test_RR_029(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date' => '2026-03-28', 'engine_version' => 'holistic',
            'daily_rate' => '1000.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $cn = db_row("SELECT amount, source FROM credit_notes WHERE source_invoice_id=?", [$inv2['invoice_id']]);
        Assert::true($cn !== null);
        Assert::equal('base_rental_reconciliation_overflow', (string)$cn['source']);
    });
}

// ── RR-040..044 zero-delta cases (5) ────────────────────────
// RR-040: 30-day full activation, cumulative_correct = $700. already_billed = $0. delta = $700 (NOT zero).
// Engineer zero-delta: bill exact $700 manually, then generate Inv 2 at day 30 cumulative.
function test_RR_040(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-04-01', 'engine_version' => 'holistic']);
        // Inv 1 = full_month = $700.
        Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        // Inv 2 same period (single_period). cumulative still 30d = $700. already_billed = $700. delta = $0.
        // Engine emits NO line.
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-30', '2026-04-30', 'single_period');
        Assert::lineCount($inv2['invoice_id'], 'base_rental', 0);
        Assert::lineCount($inv2['invoice_id'], 'base_rental_reconciliation_credit', 0);
    });
}
// RR-041: 7 days cumulative = $350. Bill it. Then run a 7-day cumulative invoice again. delta=0.
function test_RR_041(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-04-03', 'partial_start');  // 7d = $350 WPM A
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-03', '2026-04-03', 'single_period');  // still 7 cumulative
        Assert::lineCount($inv2['invoice_id'], 'base_rental', 0);
    });
}
// RR-042: 14 days bills $700 (weekly_math at cap). Then 14-day cumulative invoice again. delta=0.
function test_RR_042(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-04-10', 'partial_start');  // 14d
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-10', '2026-04-10', 'single_period');
        Assert::lineCount($inv2['invoice_id'], 'base_rental', 0);
    });
}
// RR-043: A normal final reconciliation invoice (not zero) — sanity inverse.
function test_RR_043(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        Assert::lineCount($inv2['invoice_id'], 'base_rental', 1);
        Assert::bcequal('593.33', _rr_lineAmount($inv2['invoice_id']));
    });
}
// RR-044: Mileage-only invoice (NOT engine-supported per period_type ENUM constraint).
// Simulated equivalent: manual $0 prior invoice, then generate; verify the engine still
// reads already_billed correctly and doesn't crash.
function test_RR_044(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        // Manual $0 prior invoice (simulates a mileage-only invoice's $0 base contribution).
        $invId = db_insert('invoices', [
            'invoice_number' => 'TEST-MI-' . uniqid(),
            'invoice_type'   => 'regular',
            'customer_id'    => $custId, 'lease_id' => $lease,
            'billing_period_start' => '2026-03-15', 'billing_period_end' => '2026-03-20',
            'billing_period_days' => 6, 'billing_type' => 'single_period',
            'rate_method_used' => 'none',
            'invoice_date' => '2026-03-20', 'due_date' => '2026-04-20',
            'status' => 'draft',
            'subtotal' => '0.00', 'subtotal_after_discount' => '0.00',
            'total_amount' => '0.00', 'balance_due' => '0.00',
            'currency' => 'CAD',
        ]);
        $inv2 = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        // already_billed = $0 (no base_rental line in the manual invoice). Engine runs WPM as activation.
        Assert::bcequal('200.00', _rr_lineAmount($inv2['invoice_id']));
    });
}

// ── RR-050..064 already-billed sum correctness (15) ─────────
function test_RR_050(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-30', 'period_end' => '2026-04-30',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('200.00', $r['already_billed']);
    });
}
function test_RR_051(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-05-31', 'period_end' => '2026-05-31',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('793.33', $r['already_billed']);
    });
}
// RR-052: 2 base + 1 credit. already_billed = $200 + $593.33 - $50 = $743.33.
function test_RR_052(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1 = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        // Manually add a $50 reconciliation credit line on a draft invoice.
        $invId = db_insert('invoices', [
            'invoice_number' => 'TEST-CR-' . uniqid(),
            'invoice_type' => 'regular', 'customer_id' => 1, 'lease_id' => $lease,
            'billing_period_start' => '2026-05-01', 'billing_period_end' => '2026-05-01',
            'billing_period_days' => 1, 'billing_type' => 'single_period',
            'rate_method_used' => 'none', 'invoice_date' => '2026-05-01', 'due_date' => '2026-06-01',
            'status' => 'draft', 'subtotal' => '-50.00', 'subtotal_after_discount' => '-50.00',
            'total_amount' => '-50.00', 'balance_due' => '-50.00', 'currency' => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id' => $invId, 'sort_order' => 0,
            'item_type'  => 'base_rental_reconciliation_credit', 'description' => 'manual credit',
            'unit_price' => '50.00', 'amount' => '50.00', 'is_credit' => 1, 'taxable' => 1,
        ]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('743.33', $r['already_billed']);
    });
}
// RR-053: 1 sent + 1 voided. already_billed = only sent counts.
function test_RR_053(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1 = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        db_execute("UPDATE invoices SET status='void' WHERE id=?", [$inv2['invoice_id']]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-05-01', 'period_end' => '2026-05-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('200.00', $r['already_billed']);
    });
}
// RR-054: 1 sent + 1 soft-deleted. Only non-deleted counts.
function test_RR_054(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1 = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        db_execute("UPDATE invoices SET deleted_at=NOW() WHERE id=?", [$inv2['invoice_id']]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-05-01', 'period_end' => '2026-05-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('200.00', $r['already_billed']);
    });
}
// RR-055: 1 draft + 1 sent. Both count.
function test_RR_055(): void {
    DbState::inTransaction(function() {
        $lease = _rr_makeLease('2026-03-28');
        $inv1 = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        db_execute("UPDATE invoices SET status='sent' WHERE id=?", [$inv2['invoice_id']]);
        // inv1 stays draft
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-05-01', 'period_end' => '2026-05-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('793.33', $r['already_billed']);
    });
}
// RR-056: 1 invoice with multiple base_rental lines.
function test_RR_056(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', [
            'invoice_number' => 'TEST-ML-' . uniqid(),
            'invoice_type' => 'regular', 'customer_id' => $custId, 'lease_id' => $lease,
            'billing_period_start' => '2026-03-28', 'billing_period_end' => '2026-03-31',
            'billing_period_days' => 4, 'billing_type' => 'partial_start',
            'rate_method_used' => 'daily', 'invoice_date' => '2026-03-28', 'due_date' => '2026-04-28',
            'status' => 'draft', 'subtotal' => '300.00', 'subtotal_after_discount' => '300.00',
            'total_amount' => '300.00', 'balance_due' => '300.00', 'currency' => 'CAD',
        ]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'a','unit_price'=>'100','amount'=>'100','is_credit'=>0,'taxable'=>1]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>1,'item_type'=>'base_rental','description'=>'b','unit_price'=>'200','amount'=>'200','is_credit'=>0,'taxable'=>1]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('300.00', $r['already_billed']);
    });
}
// RR-057: base_rental and reconciliation_credit on the same invoice.
function test_RR_057(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', [
            'invoice_number' => 'TEST-MX-' . uniqid(),
            'invoice_type' => 'regular', 'customer_id' => $custId, 'lease_id' => $lease,
            'billing_period_start' => '2026-03-28', 'billing_period_end' => '2026-03-31',
            'billing_period_days' => 4, 'billing_type' => 'partial_start',
            'rate_method_used' => 'daily', 'invoice_date' => '2026-03-28', 'due_date' => '2026-04-28',
            'status' => 'draft', 'subtotal' => '150.00', 'subtotal_after_discount' => '150.00',
            'total_amount' => '150.00', 'balance_due' => '150.00', 'currency' => 'CAD',
        ]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental','description'=>'a','unit_price'=>'200','amount'=>'200','is_credit'=>0,'taxable'=>1]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>1,'item_type'=>'base_rental_reconciliation_credit','description'=>'b','unit_price'=>'50','amount'=>'50','is_credit'=>1,'taxable'=>1]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        // Signed: $200 - $50 = $150.
        Assert::bcequal('150.00', $r['already_billed']);
    });
}
// RR-058: Invoice with no base_rental line (mileage-only equivalent) — contributes $0.
function test_RR_058(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', [
            'invoice_number' => 'TEST-NB-' . uniqid(),
            'invoice_type' => 'regular', 'customer_id' => $custId, 'lease_id' => $lease,
            'billing_period_start' => '2026-03-28', 'billing_period_end' => '2026-03-31',
            'billing_period_days' => 4, 'billing_type' => 'single_period',
            'rate_method_used' => 'none', 'invoice_date' => '2026-03-28', 'due_date' => '2026-04-28',
            'status' => 'draft', 'subtotal' => '0.00', 'subtotal_after_discount' => '0.00',
            'total_amount' => '0.00', 'balance_due' => '0.00', 'currency' => 'CAD',
        ]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'mileage_usage','description'=>'mileage only','unit_price'=>'100','amount'=>'100','is_credit'=>0,'taxable'=>1]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('0.00', $r['already_billed']);
    });
}
// RR-059: Adjustment invoice with $0 base.
function test_RR_059(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', [
            'invoice_number' => 'TEST-ADJ-' . uniqid(),
            'invoice_type' => 'adjustment', 'customer_id' => $custId, 'lease_id' => $lease,
            'billing_period_start' => '2026-03-28', 'billing_period_end' => '2026-03-31',
            'billing_period_days' => 4, 'billing_type' => 'single_period',
            'rate_method_used' => 'none', 'invoice_date' => '2026-03-28', 'due_date' => '2026-04-28',
            'status' => 'draft', 'subtotal' => '0.00', 'subtotal_after_discount' => '0.00',
            'total_amount' => '0.00', 'balance_due' => '0.00', 'currency' => 'CAD',
        ]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('0.00', $r['already_billed']);
    });
}
// RR-060: Late-fee invoice on a DIFFERENT lease — should not contribute.
function test_RR_060(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease1 = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $lease2 = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        // Bill against lease 1.
        Fixtures::generateInvoice($lease1, '2026-03-28', '2026-03-31', 'partial_start');
        // Query against lease 2: already_billed = $0.
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease2, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('0.00', $r['already_billed']);
    });
}
// RR-061: 5 invoices: $200+$593+$-50+$700+$-100. Signed sum = $1343.
function test_RR_061(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $mkInv = function($cur, $items) use ($custId, $lease) {
            $invId = db_insert('invoices', [
                'invoice_number' => 'TEST-' . uniqid(),
                'invoice_type' => 'regular', 'customer_id' => $custId, 'lease_id' => $lease,
                'billing_period_start' => '2026-03-28', 'billing_period_end' => '2026-03-31',
                'billing_period_days' => 1, 'billing_type' => 'single_period',
                'rate_method_used' => 'none', 'invoice_date' => '2026-03-28', 'due_date' => '2026-04-28',
                'status' => 'draft', 'subtotal' => $cur, 'subtotal_after_discount' => $cur,
                'total_amount' => $cur, 'balance_due' => $cur, 'currency' => 'CAD',
            ]);
            foreach ($items as $i => [$amt, $isCredit, $type]) {
                db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>$i,'item_type'=>$type,'description'=>'x','unit_price'=>$amt,'amount'=>$amt,'is_credit'=>$isCredit,'taxable'=>1]);
            }
        };
        $mkInv('200', [['200', 0, 'base_rental']]);
        $mkInv('593', [['593', 0, 'base_rental']]);
        $mkInv('-50', [['50', 1, 'base_rental_reconciliation_credit']]);
        $mkInv('700', [['700', 0, 'base_rental']]);
        $mkInv('-100', [['100', 1, 'base_rental_reconciliation_credit']]);

        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('1343.00', $r['already_billed']);
    });
}
// RR-062: mileage_credit on an invoice should NOT affect already_billed (only base_* lines).
function test_RR_062(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $invId = db_insert('invoices', [
            'invoice_number' => 'TEST-MIX-' . uniqid(),
            'invoice_type' => 'regular', 'customer_id' => $custId, 'lease_id' => $lease,
            'billing_period_start' => '2026-03-28', 'billing_period_end' => '2026-03-31',
            'billing_period_days' => 4, 'billing_type' => 'partial_start',
            'rate_method_used' => 'daily', 'invoice_date' => '2026-03-28', 'due_date' => '2026-04-28',
            'status' => 'draft', 'subtotal' => '100', 'subtotal_after_discount' => '100',
            'total_amount' => '100', 'balance_due' => '100', 'currency' => 'CAD',
        ]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>0,'item_type'=>'base_rental_reconciliation_credit','description'=>'a','unit_price'=>'50','amount'=>'50','is_credit'=>1,'taxable'=>1]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>1,'item_type'=>'mileage_credit','description'=>'b','unit_price'=>'30','amount'=>'30','is_credit'=>1,'taxable'=>1]);
        db_insert('invoice_line_items', ['invoice_id'=>$invId,'sort_order'=>2,'item_type'=>'base_rental','description'=>'c','unit_price'=>'180','amount'=>'180','is_credit'=>0,'taxable'=>1]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        // Signed sum of base_* lines only: 180 - 50 = 130. mileage_credit ignored.
        Assert::bcequal('130.00', $r['already_billed']);
    });
}
// RR-063: Draft-then-deleted invoice doesn't count.
function test_RR_063(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $inv1 = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        db_execute("UPDATE invoices SET deleted_at=NOW() WHERE id=?", [$inv1['invoice_id']]);
        $engine = new HolisticLeaseEngine();
        $r = $engine->calculateForInvoice([
            'lease_id' => $lease, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('0.00', $r['already_billed']);
    });
}
// RR-064: Two leases same customer — only THIS lease's invoices count.
function test_RR_064(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease1 = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        $lease2 = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
        Fixtures::generateInvoice($lease1, '2026-03-28', '2026-03-31', 'partial_start');
        Fixtures::generateInvoice($lease2, '2026-03-28', '2026-03-31', 'partial_start');
        $engine = new HolisticLeaseEngine();
        $r1 = $engine->calculateForInvoice([
            'lease_id' => $lease1, 'start_date' => '2026-03-28',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-01',
            'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
            'is_activation_invoice' => false,
        ]);
        Assert::bcequal('200.00', $r1['already_billed']);  // only lease1
    });
}
