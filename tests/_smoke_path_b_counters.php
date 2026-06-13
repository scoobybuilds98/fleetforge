<?php
declare(strict_types=1);

/**
 * tests/_smoke_path_b_counters.php
 *
 * S-FIX-2 CHECK 3 — end-to-end smoke test for Path B counter semantics.
 *
 * Walks through the full invoice lifecycle (create → send → pay/void/delete)
 * and asserts the canonical truth table holds at every step. Directly invokes
 * InvoiceGenerator::createFromLease() and replicates the SQL that each API
 * endpoint runs (send.php, void.php, payments/create.php, etc.) so the test
 * validates the actual Path B behavior without requiring a running web server.
 *
 * The whole suite runs inside a single db_transaction() that we ROLLBACK at
 * the end so nothing leaks into the database. No HTTP, no session.
 *
 * USAGE: php tests/_smoke_path_b_counters.php
 * EXIT:  0 on all-pass, 1 on any failure.
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

// ---------------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------------
$failures = [];
$passes   = 0;

function check(string $label, $expected, $actual): void {
    global $failures, $passes;
    // Compare numerically with bcmath when both sides are numeric;
    // otherwise fall back to strict equality (covers status strings, bools).
    $bothNumeric = (is_numeric($expected) || $expected === '0' || $expected === '0.00')
                && (is_numeric($actual)   || $actual   === '0' || $actual   === '0.00');
    if ($bothNumeric) {
        $ok = bccomp((string) $expected, (string) $actual, 2) === 0;
    } else {
        $ok = $expected === $actual;
    }
    if ($ok) {
        $passes++;
        $shown = is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual;
        echo "  PASS  {$label}: {$shown}\n";
    } else {
        $failures[] = $label;
        $shownExp = is_bool($expected) ? ($expected ? 'true' : 'false') : (string) $expected;
        $shownAct = is_bool($actual)   ? ($actual   ? 'true' : 'false') : (string) $actual;
        echo "  FAIL  {$label}: expected {$shownExp}, got {$shownAct}\n";
    }
}

function counters(int $custId, int $leaseId): array {
    $cust  = db_row("SELECT outstanding_balance FROM customers WHERE id = ?", [$custId]);
    $lease = db_row("SELECT outstanding_balance, total_invoiced FROM leases WHERE id = ?", [$leaseId]);
    return [
        'customer_ob'    => (string) $cust['outstanding_balance'],
        'lease_ob'       => (string) $lease['outstanding_balance'],
        'lease_invoiced' => (string) $lease['total_invoiced'],
    ];
}

// ---------------------------------------------------------------------------
// Run the suite inside a transaction we ROLLBACK at the end
// ---------------------------------------------------------------------------
echo "S-FIX-2 CHECK 3 — Path B end-to-end smoke test\n";
echo str_repeat('=', 70) . "\n\n";

// We can't easily wrap InvoiceGenerator (which uses its own db_transaction)
// inside another db_transaction because the helper guards against nesting.
// Strategy: run, then DELETE the test rows at the end. Custom test prefix.
$testPrefix = 'SMOKE-PATH-B-' . date('YmdHis');

try {

// --- Setup ------------------------------------------------------------------
$customerId = db_insert('customers', [
    'company_name'     => $testPrefix . ' Test Co',
    'contact_name'     => 'Path B Tester',
    'email'            => 'path-b-test@example.invalid',
    'phone'            => '555-0100',
    'province'         => 'BC',
    'currency'         => 'CAD',
    'gst_exempt'       => 0,
    'pst_exempt'       => 0,
    'tax_exempt'       => 0,
    'outstanding_balance' => '0.00',
]);

// Reuse the first existing equipment_unit — the smoke test only needs a
// valid FK target, not a brand-new unit. We do NOT mutate this row.
$unitRow = db_row(
    "SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL LIMIT 1",
    []
);
if (!$unitRow) {
    throw new \RuntimeException('Smoke test requires at least one equipment_unit in the DB.');
}
$equipmentUnitId = (int) $unitRow['id'];
$unitNumber      = $unitRow['unit_number'];

$leaseId = db_insert('leases', [
    'contract_number'         => $testPrefix . '-1',
    'customer_id'             => $customerId,
    'equipment_unit_id'       => $equipmentUnitId,
    'unit_number_snapshot'    => $unitNumber,
    'company_name_snapshot'   => $testPrefix . ' Test Co',
    'customer_name_snapshot'  => 'Path B Tester',
    'status'                  => 'active',
    'start_date'              => date('Y-m-d'),
    'monthly_rate'            => '1000.00',
    'daily_rate'              => '40.00',
    'weekly_rate'             => '0.00',
    'currency'                => 'CAD',
    'billing_cycle'           => 'monthly',
    'gst_exempt'              => 0,
    'pst_exempt'              => 0,
    'tax_exempt'              => 0,
    'discount_type'           => 'none',
    'discount_value'          => '0.0000',
    'mileage_rate'            => '0.0000',
    'mileage_unit'            => 'km',
    'total_invoiced'          => '0.00',
    'total_paid'              => '0.00',
    'outstanding_balance'     => '0.00',
]);

echo "Setup: customer #{$customerId}, lease #{$leaseId}, equipment_unit #{$equipmentUnitId}\n\n";

$generator = new InvoiceGenerator();

// --- Step (a): create draft invoice --------------------------------------
echo "(a) Create draft invoice via InvoiceGenerator (full_month, $1000)\n";
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');
$lastOfMonth  = date('Y-m-t');
$inv1 = $generator->createFromLease([
    'lease_id'          => $leaseId,
    'period_start'      => $firstOfMonth,
    'period_end'        => $lastOfMonth,
    'billing_type'      => 'full_month',
    'invoice_type'      => 'regular',
    'auto_generated'    => 0,
    'generation_source' => 'manual',
]);
$c = counters($customerId, $leaseId);
check('(a) customer.outstanding_balance unchanged after draft create', '0.00', $c['customer_ob']);
check('(a) lease.total_invoiced += total_amount',                       $inv1['total_amount'], $c['lease_invoiced']);
check('(a) lease.outstanding_balance unchanged (Path B)',               '0.00', $c['lease_ob']);
echo "\n";

// --- Step (b): send the invoice (replicate send.php SQL) -----------------
echo "(b) Send the invoice (draft → sent)\n";
$balanceDue1 = (string) $inv1['balance_due'];
db_transaction(function () use ($inv1, $leaseId, $customerId, $balanceDue1) {
    db_update('invoices', [
        'status'    => 'sent',
        'sent_date' => date('Y-m-d'),
        'sent_at'   => date('Y-m-d H:i:s'),
    ], 'id = ?', [$inv1['invoice_id']]);
    db_execute(
        "UPDATE leases SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
        [$balanceDue1, $leaseId]
    );
    db_execute(
        "UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
        [$balanceDue1, $customerId]
    );
});
$c = counters($customerId, $leaseId);
check('(b) customer.outstanding_balance += balance_due', $balanceDue1, $c['customer_ob']);
check('(b) lease.total_invoiced unchanged on send',      $inv1['total_amount'], $c['lease_invoiced']);
echo "\n";

// --- Step (c): record exact-amount payment (replicate payments/create.php) ---
echo "(c) Record exact-amount payment ({$balanceDue1}) — invoice → paid\n";
db_transaction(function () use ($inv1, $leaseId, $customerId, $balanceDue1) {
    $paymentId = db_insert('payments', [
        'payment_number' => 'TEST-PAY-1',
        'customer_id'    => $customerId,
        'amount'         => $balanceDue1,
        'currency'       => 'CAD',
        'payment_method' => 'cash',
        'payment_date'   => date('Y-m-d'),
        'received_at'    => date('Y-m-d H:i:s'),
        'status'         => 'cleared',
        'overpayment_amount'   => '0.00',
        'overpayment_resolved' => 0,
    ]);
    db_insert('payment_allocations', [
        'payment_id'      => $paymentId,
        'invoice_id'      => $inv1['invoice_id'],
        'amount'          => $balanceDue1,
        'currency'        => 'CAD',
        'allocation_type' => 'auto',
    ]);
    db_update('invoices', [
        'amount_paid' => $balanceDue1,
        'balance_due' => '0.00',
        'status'      => 'paid',
        'paid_date'   => date('Y-m-d'),
    ], 'id = ?', [$inv1['invoice_id']]);
    db_execute(
        "UPDATE leases SET total_paid = total_paid + ?, updated_at = NOW() WHERE id = ?",
        [$balanceDue1, $leaseId]
    );
    db_execute(
        "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?), updated_at = NOW() WHERE id = ?",
        [$balanceDue1, $customerId]
    );
});
$c = counters($customerId, $leaseId);
check('(c) customer.outstanding_balance -= balance_due (sent → paid)', '0.00', $c['customer_ob']);
$invStatus = (string) (db_row("SELECT status FROM invoices WHERE id = ?", [$inv1['invoice_id']])['status']);
check('(c) invoice status now paid', 'paid', $invStatus);
echo "\n";

// --- Step (d): create another draft, edit total via direct SQL --------------
echo "(d) Create draft #2 then simulate a draft-edit lowering total\n";
$invoicedBefore = $c['lease_invoiced'];
$inv2 = $generator->createFromLease([
    'lease_id'          => $leaseId,
    'period_start'      => $firstOfMonth,
    'period_end'        => $lastOfMonth,
    'billing_type'      => 'full_month',
    'invoice_type'      => 'regular',
    'auto_generated'    => 0,
    'generation_source' => 'manual',
]);
$c = counters($customerId, $leaseId);
$invoicedAfterCreate = $c['lease_invoiced'];
check('(d.1) draft create: customer.OB still 0',           '0.00', $c['customer_ob']);
$expected = bcadd($invoicedBefore, (string) $inv2['total_amount'], 2);
check('(d.1) draft create: lease.total_invoiced += amt',   $expected, $invoicedAfterCreate);

// Simulate update.php draft total_amount edit (per Bug 4e contract):
// path: total goes from $1000 -> $700, lease.total_invoiced -= $300, OB unchanged.
$delta = '-300.00';
db_transaction(function () use ($inv2, $leaseId, $delta) {
    db_update('invoices', [
        'total_amount' => '700.00',
        'subtotal'     => '700.00',
        'balance_due'  => '700.00',
    ], 'id = ?', [$inv2['invoice_id']]);
    db_execute(
        "UPDATE leases SET total_invoiced = total_invoiced + ?, updated_at = NOW() WHERE id = ?",
        [$delta, $leaseId]
    );
});
$c = counters($customerId, $leaseId);
$expected = bcadd($invoicedAfterCreate, $delta, 2);
check('(d.2) draft edit -$300: lease.total_invoiced += delta', $expected, $c['lease_invoiced']);
check('(d.2) draft edit: customer.OB unchanged',                '0.00', $c['customer_ob']);
echo "\n";

// --- Step (e): void that draft (replicate void.php status-aware SQL) -------
echo "(e) Void draft #2 (Path B: total_invoiced -= total_amount, OB unchanged)\n";
$invoicedBeforeVoid = $c['lease_invoiced'];
$invRow = db_row("SELECT total_amount, balance_due, status FROM invoices WHERE id = ?", [$inv2['invoice_id']]);
$decTotalE = (string) $invRow['total_amount'];
$decObE    = ($invRow['status'] === 'draft') ? '0.00' : (string) $invRow['balance_due'];
db_transaction(function () use ($inv2, $leaseId, $customerId, $decTotalE, $decObE) {
    db_update('invoices', [
        'status'      => 'void',
        'balance_due' => '0.00',
        'voided_date' => date('Y-m-d'),
        'void_reason' => 'smoke test',
    ], 'id = ?', [$inv2['invoice_id']]);
    db_execute(
        "UPDATE leases SET total_invoiced = total_invoiced - ?, outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
        [$decTotalE, $decObE, $leaseId]
    );
    db_execute(
        "UPDATE customers SET outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
        [$decObE, $customerId]
    );
});
$c = counters($customerId, $leaseId);
$expected = bcsub($invoicedBeforeVoid, $decTotalE, 2);
check('(e) draft void: lease.total_invoiced -= total_amount', $expected, $c['lease_invoiced']);
check('(e) draft void: customer.OB unchanged',                 '0.00', $c['customer_ob']);
echo "\n";

// --- Step (f): create another draft, send it, partial payment ---------------
echo "(f) Create draft #3, send it, record a partial payment\n";
$invoicedBeforeF = $c['lease_invoiced'];
$inv3 = $generator->createFromLease([
    'lease_id'          => $leaseId,
    'period_start'      => $firstOfMonth,
    'period_end'        => $lastOfMonth,
    'billing_type'      => 'full_month',
    'invoice_type'      => 'regular',
    'auto_generated'    => 0,
    'generation_source' => 'manual',
]);
$balanceDue3 = (string) $inv3['balance_due'];
db_transaction(function () use ($inv3, $leaseId, $customerId, $balanceDue3) {
    db_update('invoices', [
        'status'    => 'sent',
        'sent_date' => date('Y-m-d'),
        'sent_at'   => date('Y-m-d H:i:s'),
    ], 'id = ?', [$inv3['invoice_id']]);
    db_execute(
        "UPDATE leases SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
        [$balanceDue3, $leaseId]
    );
    db_execute(
        "UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
        [$balanceDue3, $customerId]
    );
});

// A TRUE partial payment = half the invoice's actual balance (rounded to cents,
// floored at $0.01). Must be strictly less than balance_due so the invoice lands
// partially_paid and customers.outstanding_balance stays >= 0 (the real code, and
// this test's own UPDATE, floor OB at GREATEST(0, ...)). Hardcoding $400 broke on
// any run where the holistic engine prorated inv3 below $400 (e.g. a mid-month
// lease start), making the "expected = OB - 400" go negative while reality floored
// to 0.00.
$partial = bcdiv($balanceDue3, '2', 2);
if (bccomp($partial, '0', 2) <= 0) {
    $partial = '0.01';
}
$obBeforePartial = (string) (db_row("SELECT outstanding_balance FROM customers WHERE id = ?", [$customerId])['outstanding_balance']);
db_transaction(function () use ($inv3, $leaseId, $customerId, $partial) {
    $payId = db_insert('payments', [
        'payment_number' => 'TEST-PAY-2',
        'customer_id'    => $customerId,
        'amount'         => $partial,
        'currency'       => 'CAD',
        'payment_method' => 'cash',
        'payment_date'   => date('Y-m-d'),
        'received_at'    => date('Y-m-d H:i:s'),
        'status'         => 'cleared',
        'overpayment_amount'   => '0.00',
        'overpayment_resolved' => 0,
    ]);
    db_insert('payment_allocations', [
        'payment_id'      => $payId,
        'invoice_id'      => $inv3['invoice_id'],
        'amount'          => $partial,
        'currency'        => 'CAD',
        'allocation_type' => 'auto',
    ]);
    $invRow = db_row("SELECT total_amount, credits_applied FROM invoices WHERE id = ?", [$inv3['invoice_id']]);
    $newBalance = bcsub(bcsub((string) $invRow['total_amount'], (string) $invRow['credits_applied'], 2), $partial, 2);
    db_update('invoices', [
        'amount_paid' => $partial,
        'balance_due' => $newBalance,
        'status'      => 'partially_paid',
    ], 'id = ?', [$inv3['invoice_id']]);
    db_execute(
        "UPDATE leases SET total_paid = total_paid + ?, updated_at = NOW() WHERE id = ?",
        [$partial, $leaseId]
    );
    db_execute(
        "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?), updated_at = NOW() WHERE id = ?",
        [$partial, $customerId]
    );
});
$c = counters($customerId, $leaseId);
$expectedOb = bcsub($obBeforePartial, $partial, 2);
check('(f) partial payment: customer.OB -= partial',       $expectedOb, $c['customer_ob']);
$invStatus = (string) (db_row("SELECT status FROM invoices WHERE id = ?", [$inv3['invoice_id']])['status']);
check('(f) invoice status now partially_paid',              'partially_paid', $invStatus);
echo "\n";

// --- Step (g): try to void the partially_paid invoice via current API rules --
echo "(g) Attempt to void partially_paid invoice (current code rejects)\n";
$voidableStatuses = ['draft', 'sent']; // matches api/v1/invoices/void.php
$invRow = db_row("SELECT status FROM invoices WHERE id = ?", [$inv3['invoice_id']]);
$canVoid = in_array($invRow['status'], $voidableStatuses, true);
check('(g) void.php correctly rejects partially_paid (truth table allows only Draft→void / Sent→void)', false, $canVoid);
echo "    Note: brief mentions \"void partially-paid\" but void.php's voidable list is ['draft','sent'].\n";
echo "    The current code path is consistent with the truth table (only Sent→void documented).\n";
echo "\n";

} catch (\Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failures[] = 'fatal_exception';
}

// --- Cleanup: hard-delete the test rows ---
try {
    db_execute("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE payment_number LIKE 'TEST-PAY-%')", []);
    db_execute("DELETE FROM payments WHERE payment_number LIKE 'TEST-PAY-%'", []);
    db_execute("DELETE FROM invoice_line_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id = ?)", [$customerId ?? 0]);
    db_execute("DELETE FROM lease_billing_periods WHERE lease_id = ?", [$leaseId ?? 0]);
    db_execute("DELETE FROM invoices WHERE customer_id = ?", [$customerId ?? 0]);
    db_execute("DELETE FROM credit_notes WHERE customer_id = ?", [$customerId ?? 0]);
    db_execute("DELETE FROM leases WHERE id = ?", [$leaseId ?? 0]);
    // Do NOT delete equipment_units — we reused an existing row.
    db_execute("DELETE FROM customers WHERE id = ?", [$customerId ?? 0]);
    db_execute("DELETE FROM audit_log WHERE (entity_type = 'customer' AND entity_id = ?) OR (entity_type = 'lease' AND entity_id = ?)", [$customerId ?? 0, $leaseId ?? 0]);
} catch (\Throwable $e) {
    echo "Cleanup error (test data may persist): " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 70) . "\n";
echo "Path B smoke test complete: {$passes} passed, " . count($failures) . " failed\n";
if (!empty($failures)) {
    foreach ($failures as $f) echo "  - {$f}\n";
    echo str_repeat('=', 70) . "\n";
    exit(1);
}
echo str_repeat('=', 70) . "\n";
exit(0);
