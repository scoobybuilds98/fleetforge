<?php
declare(strict_types=1);

/**
 * tests/_smoke_advance_close_mileage.php
 *
 * Schema-real regression smoke for the advance-lease-close mileage path.
 *
 * WHY THIS EXISTS — a pre-existing prod fatal that no unit round-trip caught:
 *   api/v1/leases/close.php (advance path) closes an advance lease that has a
 *   mileage overage by calling InvoiceGenerator::createFromLease() with
 *   billing_type='mileage_only'. createFromLease writes a lease_billing_periods
 *   row with period_type = the billing_type. But lease_billing_periods.period_type
 *   is enum('partial_start','full_month','partial_end','single_period') — it has
 *   NO 'mileage_only' member. Under STRICT_TRANS_TABLES (which BOTH dev and prod
 *   run) the INSERT throws SQLSTATE[01000] 1265 "Data truncated for column
 *   'period_type'", aborting the ENTIRE lease-close transaction. A unit test that
 *   round-trips arrays would never see it; only EXECUTING the real createFromLease
 *   against the real db.php + schema surfaces it (per the schema-real-smoke memo).
 *
 * Fix under guard: createFromLease maps any non-period billing type
 * (mileage_only / adjustment / credit_note) to a valid period_type
 * ('single_period') before the lease_billing_periods insert.
 *
 * This smoke FORCES STRICT_TRANS_TABLES on the session (so it is deterministic
 * regardless of the server default), clones a real active lease, and runs the
 * exact createFromLease(billing_type='mileage_only') call close.php makes —
 * with a mileage overage line — inside a BEGIN/ROLLBACK. Pre-fix this fatals;
 * post-fix it succeeds and records a valid period_type.
 *
 * Read-only: everything runs inside a transaction that is ROLLED BACK. No writes
 * are committed; no prod access.
 *
 * Run:  php tests/_smoke_advance_close_mileage.php
 * Exit: 0 all pass, 1 on failure (or SKIP=0 when no seedable lease exists).
 *
 * @session S-BILLING-MILEAGE-CLOSE-ENUM
 */

require_once __DIR__ . '/../config/app.php';

$pass = 0;
$fail = 0;
$fails = [];
function ok(bool $cond, string $label): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else       { $fail++; $fails[] = $label; echo "  FAIL  {$label}\n"; }
}

echo "advance-close mileage smoke (period_type enum / STRICT_TRANS_TABLES)\n";

// ── Force strict mode so enum truncation is deterministic on any server ──
db_execute("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$mode = db_row("SELECT @@SESSION.sql_mode AS m")['m'] ?? '';
ok(str_contains($mode, 'STRICT_TRANS_TABLES'), 'session is STRICT_TRANS_TABLES (truncation would fatal pre-fix)');

// ── Pick a seed lease to clone (any active lease with rates) ──
$seed = db_row(
    "SELECT id FROM leases
      WHERE deleted_at IS NULL
        AND monthly_rate IS NOT NULL AND monthly_rate > 0
      ORDER BY id DESC LIMIT 1"
);
if (!$seed) {
    echo "SKIP — no active lease with rates to clone; cannot exercise the path.\n";
    exit(0);
}

$exitCode = 1;
db_pdo()->beginTransaction();
try {
    if (function_exists('mbcron_bump_counter')) { mbcron_bump_counter(); }

    // Clone the seed lease into a fresh row (unique contract_number) with no invoices.
    $cols = array_values(array_filter(
        array_column(db_select("SHOW COLUMNS FROM leases"), 'Field'),
        fn($c) => $c !== 'id'
    ));
    $insertList = implode(',', array_map(fn($c) => "`$c`", $cols));
    $selectList = implode(',', array_map(fn($c) => $c === 'contract_number' ? '?' : "`$c`", $cols));
    $cn = 'SMOKE-MILEAGE-CLOSE-' . uniqid();
    db_execute("INSERT INTO leases ($insertList) SELECT $selectList FROM leases WHERE id = ?", [$cn, $seed['id']]);
    $leaseId = (int) db_pdo()->lastInsertId();
    db_execute(
        "UPDATE leases SET status='active', start_date='2026-01-01',
                last_billed_date=NULL, last_billed_invoice_id=NULL, total_invoiced=0
          WHERE id=?",
        [$leaseId]
    );

    $returnDate = '2026-03-15';

    // EXACT shape of the close.php advance-mileage call (api/v1/leases/close.php),
    // including a mileage overage extra line so it mirrors a real close.
    $gen = new \FleetForge\Billing\InvoiceGenerator();
    $res = $gen->createFromLease([
        'lease_id'          => $leaseId,
        'period_start'      => $returnDate,
        'period_end'        => $returnDate,
        'billing_type'      => 'mileage_only',
        'invoice_type'      => 'final',
        'notes'             => 'smoke: advance close mileage overage',
        'created_by'        => 1,
        'auto_generated'    => 1,
        'generation_source' => 'lease_close',
        'extra_lines'       => [[
            'item_type'   => 'mileage_usage',
            'description' => 'Mileage overage at close',
            'quantity'    => '250.0000',
            'unit'        => 'km',
            'unit_price'  => '0.40',
            'amount'      => '100.00',
            'is_credit'   => 0,
            'taxable'     => 1,
        ]],
    ]);

    ok(!empty($res['invoice_id']), 'createFromLease(mileage_only) did NOT fatal and returned an invoice id');

    // The lease_billing_periods row must exist with a VALID enum period_type.
    $lbp = db_row(
        "SELECT period_type, base_amount, total_amount FROM lease_billing_periods WHERE invoice_id = ?",
        [(int) $res['invoice_id']]
    );
    ok($lbp !== null, 'lease_billing_periods row was written for the mileage_only invoice');
    $validEnum = ['partial_start', 'full_month', 'partial_end', 'single_period'];
    ok($lbp && in_array($lbp['period_type'], $validEnum, true),
        'lease_billing_periods.period_type is a valid enum member (got: ' . ($lbp['period_type'] ?? 'NULL') . ')');
    ok($lbp && bccomp((string) $lbp['base_amount'], '0', 2) === 0,
        'mileage_only ledger row carries $0 base_amount (no base rental)');

    // The mileage line landed on the invoice.
    $mileage = db_row(
        "SELECT amount FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_usage'",
        [(int) $res['invoice_id']]
    );
    ok($mileage && bccomp((string) $mileage['amount'], '100.00', 2) === 0,
        'mileage overage line ($100.00) is present on the invoice');

} catch (\Throwable $e) {
    $fail++;
    $fails[] = 'EXCEPTION: ' . $e->getMessage();
    echo "  FAIL  EXCEPTION: " . $e->getMessage() . "\n";
} finally {
    if (db_pdo()->inTransaction()) { db_pdo()->rollBack(); }
}

echo "----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
if ($fail > 0) {
    echo "FAILURES:\n  - " . implode("\n  - ", $fails) . "\n";
    exit(1);
}
echo "OK — advance-close mileage path survives STRICT_TRANS_TABLES.\n";
exit(0);
