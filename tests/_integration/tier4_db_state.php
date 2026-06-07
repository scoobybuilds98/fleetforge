<?php
declare(strict_types=1);

/**
 * tests/_integration/tier4_db_state.php
 *
 * Tier 4 — Database State Tests (25 tests). Spec §18.
 *
 *   DB-001..DB-008  invoices table state (8)
 *   DB-009..DB-016  invoice_line_items state (8)
 *   DB-017..DB-021  lease_billing_periods state (5)
 *   DB-022..DB-025  leases counter updates (4)
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 */

use FleetForge\Tests\Assert;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

function _db_mk(): array {
    $custId = Fixtures::createCustomer(['province' => 'BC']);
    $lease  = Fixtures::createLease($custId, ['start_date' => '2026-03-28', 'engine_version' => 'holistic']);
    return [$custId, $lease];
}

// ── DB-001..008 invoices table state ────────────────────────
function test_DB_001(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT customer_name_snapshot, company_name_snapshot, contract_number_snapshot FROM invoices WHERE id=?", [$inv['invoice_id']]);
        Assert::true($row['customer_name_snapshot'] !== null);
        Assert::true($row['company_name_snapshot'] !== null);
        Assert::true($row['contract_number_snapshot'] !== null);
    });
}
function test_DB_002(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT status FROM invoices WHERE id=?", [$inv['invoice_id']]);
        Assert::equal('draft', (string)$row['status']);
    });
}
function test_DB_003(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv1 = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $inv2 = Fixtures::generateInvoice($l, '2026-04-01', '2026-04-30', 'full_month');
        // Invoice numbers are sequential (gap-free per session).
        $r1 = db_row("SELECT invoice_number FROM invoices WHERE id=?", [$inv1['invoice_id']]);
        $r2 = db_row("SELECT invoice_number FROM invoices WHERE id=?", [$inv2['invoice_id']]);
        Assert::true($r1['invoice_number'] !== $r2['invoice_number']);
    });
}
function test_DB_004(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT total_days_at_period_end, cumulative_correct_amount, already_billed_before_this FROM invoices WHERE id=?", [$inv['invoice_id']]);
        Assert::equal(4, (int)$row['total_days_at_period_end']);
        Assert::bcequal('200.00', (string)$row['cumulative_correct_amount']);
        Assert::bcequal('0.00', (string)$row['already_billed_before_this']);
    });
}
function test_DB_005(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT billing_period_days FROM invoices WHERE id=?", [$inv['invoice_id']]);
        Assert::equal(4, (int)$row['billing_period_days']);
    });
}
function test_DB_006(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT invoice_date FROM invoices WHERE id=?", [$inv['invoice_id']]);
        // S-INVOICE-DATING-FIX: invoice_date derives from the billing period
        // (advance: issue = billing_period_start), NOT the generation timestamp.
        Assert::equal('2026-03-28', (string)$row['invoice_date']);
    });
}
function test_DB_007(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT invoice_date, due_date FROM invoices WHERE id=?", [$inv['invoice_id']]);
        // Due date = invoice_date + due_days_default (usually 30).
        $diff = (new DateTime((string)$row['due_date']))->diff(new DateTime((string)$row['invoice_date']))->days;
        Assert::true($diff > 0 && $diff <= 90);
    });
}
function test_DB_008(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease = Fixtures::createLease($custId, ['start_date'=>'2026-03-28','engine_version'=>'holistic','currency'=>'USD']);
        $inv = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT currency_markup_pct FROM invoices WHERE id=?", [$inv['invoice_id']]);
        // markup_pct snapshotted (may be 0.0000 if no setting).
        Assert::true($row['currency_markup_pct'] !== null);
    });
}

// ── DB-009..016 invoice_line_items state ────────────────────
function test_DB_009(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT detail_lines FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv['invoice_id']]);
        $j = json_decode((string)$row['detail_lines'], true);
        Assert::true(is_array($j) && (isset($j['tier']) || count($j) > 0));
    });
}
function test_DB_010(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1500.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $i2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        Assert::lineExists($i2['invoice_id'], 'base_rental_reconciliation_credit');
    });
}
function test_DB_011(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        // No $0 line items except where intentional (zero-delta).
        $rows = db_select("SELECT amount, item_type FROM invoice_line_items WHERE invoice_id=? AND amount=0", [$inv['invoice_id']]);
        Assert::true(count($rows) === 0);
    });
}
function test_DB_012(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, ['start_date'=>'2026-03-28','engine_version'=>'holistic','insurance_opt_in'=>1,'insurance_cost'=>'50.00','gps_opt_in'=>1,'gps_cost'=>'2.00']);
        $inv = Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $rows = db_select("SELECT sort_order FROM invoice_line_items WHERE invoice_id=? ORDER BY sort_order", [$inv['invoice_id']]);
        $prev = -1;
        foreach ($rows as $row) {
            Assert::true((int)$row['sort_order'] >= $prev);
            $prev = (int)$row['sort_order'];
        }
    });
}
function test_DB_013(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $rows = db_select("SELECT tax_gst_amount, tax_pst_amount, tax_hst_amount FROM invoice_line_items WHERE invoice_id=?", [$inv['invoice_id']]);
        $sum = '0.00';
        foreach ($rows as $r) {
            $sum = bcadd($sum, bcadd(bcadd((string)$r['tax_gst_amount'], (string)$r['tax_pst_amount'], 2), (string)$r['tax_hst_amount'], 2), 2);
        }
        $invRow = db_row("SELECT tax_total FROM invoices WHERE id=?", [$inv['invoice_id']]);
        // Per-line tax sum may differ from invoice tax_total by rounding (different bases).
        Assert::near($sum, (string)$invRow['tax_total'], '0.05');
    });
}
function test_DB_014(): void {
    DbState::inTransaction(function() {
        $custId = Fixtures::createCustomer(['province' => 'BC']);
        $lease  = Fixtures::createLease($custId, [
            'start_date'=>'2026-03-28','engine_version'=>'holistic',
            'daily_rate'=>'500.00','weekly_rate'=>'350.00','monthly_rate'=>'700.00',
            'insurance_opt_in'=>1,'insurance_cost'=>'1500.00',
        ]);
        Fixtures::generateInvoice($lease, '2026-03-28', '2026-03-31', 'partial_start');
        $i2 = Fixtures::generateInvoice($lease, '2026-04-01', '2026-04-30', 'full_month');
        $row = db_row("SELECT is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental_reconciliation_credit'", [$i2['invoice_id']]);
        Assert::equal(1, (int)$row['is_credit']);
    });
}
function test_DB_015(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT description FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv['invoice_id']]);
        Assert::true(strlen((string)$row['description']) > 5);
    });
}
function test_DB_016(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT period_start, period_end FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$inv['invoice_id']]);
        Assert::equal('2026-03-28', (string)$row['period_start']);
        Assert::equal('2026-03-31', (string)$row['period_end']);
    });
}

// ── DB-017..021 lease_billing_periods state ─────────────────
function test_DB_017(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT id FROM lease_billing_periods WHERE invoice_id=?", [$inv['invoice_id']]);
        Assert::true($row !== null);
    });
}
function test_DB_018(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT status FROM lease_billing_periods WHERE invoice_id=?", [$inv['invoice_id']]);
        Assert::equal('pending', (string)$row['status']);
    });
}
function test_DB_019(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT base_amount FROM lease_billing_periods WHERE invoice_id=?", [$inv['invoice_id']]);
        Assert::bcequal('200.00', (string)$row['base_amount']);
    });
}
function test_DB_020(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT period_type FROM lease_billing_periods WHERE invoice_id=?", [$inv['invoice_id']]);
        Assert::equal('partial_start', (string)$row['period_type']);
    });
}
function test_DB_021(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT invoice_id FROM lease_billing_periods WHERE invoice_id=?", [$inv['invoice_id']]);
        Assert::equal($inv['invoice_id'], (int)$row['invoice_id']);
    });
}

// ── DB-022..025 leases counter updates ──────────────────────
function test_DB_022(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $before = db_row("SELECT total_invoiced FROM leases WHERE id=?", [$l])['total_invoiced'];
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $after  = db_row("SELECT total_invoiced FROM leases WHERE id=?", [$l])['total_invoiced'];
        Assert::true(bccomp((string)$after, (string)$before, 2) > 0);
    });
}
function test_DB_023(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT last_billed_date FROM leases WHERE id=?", [$l]);
        Assert::true($row['last_billed_date'] !== null);
    });
}
function test_DB_024(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $inv = Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $row = db_row("SELECT last_billed_invoice_id FROM leases WHERE id=?", [$l]);
        Assert::equal($inv['invoice_id'], (int)$row['last_billed_invoice_id']);
    });
}
function test_DB_025(): void {
    DbState::inTransaction(function() {
        [$c, $l] = _db_mk();
        $before = db_row("SELECT outstanding_balance FROM customers WHERE id=?", [$c])['outstanding_balance'];
        Fixtures::generateInvoice($l, '2026-03-28', '2026-03-31', 'partial_start');
        $after  = db_row("SELECT outstanding_balance FROM customers WHERE id=?", [$c])['outstanding_balance'];
        // Path B: draft creation does NOT touch outstanding_balance.
        Assert::bcequal((string)$before, (string)$after);
    });
}
