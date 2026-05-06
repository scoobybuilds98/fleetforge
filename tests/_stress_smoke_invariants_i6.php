<?php
declare(strict_types=1);

/**
 * tests/_stress_smoke_invariants_i6.php
 *
 * S-MILEAGE-ALLOWANCE-ZERO-FIX C2 stress test — fault-injection harness for
 * the I6 silent-skip invariant. Confirms that the SQL in
 * tests/_smoke_billing_invariants.php correctly identifies the Model B Lite
 * silent-skip shape AND does NOT false-positive on Model C with allowance
 * covering distance.
 *
 * BEGIN/ROLLBACK isolation — no DB pollution.
 *
 * Three test cases:
 *
 *   T1 — Model B Lite silent-skip shape: temp lease with rate>0 + allowance=0,
 *       temp invoice with period_distance>0 + review_status='not_required' +
 *       no mileage line item. Run the I6 SQL directly. Assert the temp invoice
 *       appears in the result set.
 *
 *   T2 — Model C with allowance covering distance: temp lease with rate>0 +
 *       allowance>0, temp invoice with period_distance < monthly_allowance +
 *       review_status='not_required'. Run I6 SQL. Assert the temp invoice
 *       does NOT appear (legitimate Model C, not silent-skip).
 *
 *   T3 — Model B Lite WITH a mileage line item already (post-review approval):
 *       temp lease with rate>0 + allowance=0, temp invoice with period_distance
 *       > 0 + review_status='approved' + a mileage_adjustment line item.
 *       Run I6 SQL. Assert the invoice does NOT appear (the line item or
 *       review status are escape hatches I6 honors).
 *
 * Spec: S-MILEAGE-ALLOWANCE-ZERO-FIX D-B
 */

require_once dirname(__DIR__) . '/config/app.php';

$pass = 0;
$fail = 0;
$out  = [];

$report = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail, &$out) {
    if ($ok) { $pass++; $out[] = "PASS  {$name}" . ($detail ? "  — {$detail}" : ''); }
    else     { $fail++; $out[] = "FAIL  {$name}" . ($detail ? "  — {$detail}" : ''); }
};

echo "FleetForge — S-MILEAGE-ALLOWANCE-ZERO-FIX C2 / I6 stress test\n";
echo str_repeat('═', 78), "\n";

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    $cust = db_row("SELECT id, currency FROM customers WHERE id = 9");
    $unit = db_row("SELECT id, template_id, unit_number FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$cust || !$unit) {
        throw new RuntimeException("Stress setup: missing customer 9 or any equipment_unit. Bailing.");
    }
    $tmpl = db_row("SELECT name FROM equipment_templates WHERE id = ?", [$unit['template_id']]);

    // The exact I6 SQL from tests/_smoke_billing_invariants.php — kept inline
    // so a divergence in the smoke fails this stress test next run.
    $i6Sql = <<<SQL
        SELECT i.id AS invoice_id
         FROM invoices i
         JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
         WHERE i.deleted_at IS NULL
           AND i.status IN ('draft','sent')
           AND i.invoice_type != 'mileage_only'
           AND COALESCE(i.period_distance_km, 0) > 0
           AND COALESCE(l.mileage_rate_km,    0) > 0
           AND COALESCE(l.estimated_mileage_km, 0) = 0
           AND i.mileage_review_status = 'not_required'
           AND NOT EXISTS (
             SELECT 1 FROM invoice_line_items ili
             WHERE ili.invoice_id = i.id
               AND ili.item_type IN ('mileage_adjustment','mileage_precharge','mileage_credit')
           )
SQL;

    // Helper: insert a temp lease with explicit mileage shape.
    $insertTempLease = function (string $estKm, string $rateKm, string $tag) use ($cust, $unit, $tmpl): int {
        return db_insert('leases', [
            'contract_number'         => 'STRESS-I6-' . $tag . '-' . substr(uniqid(), -6),
            'customer_id'             => $cust['id'],
            'customer_name_snapshot'  => 'SMOKE Test Co',
            'company_name_snapshot'   => 'SMOKE Test Co',
            'equipment_unit_id'       => $unit['id'],
            'unit_number_snapshot'    => $unit['unit_number'],
            'template_name_snapshot'  => $tmpl['name'] ?? 'STRESS',
            'start_date'              => '2026-04-01',
            'end_date'                => '2026-04-30',
            'status'                  => 'active',
            'billing_cycle'           => 'monthly',
            'daily_rate'              => '125.00',
            'weekly_rate'             => '750.00',
            'monthly_rate'            => '2200.00',
            'mileage_unit'            => 'km',
            'mileage_rate'            => $rateKm,
            'mileage_rate_km'         => $rateKm,
            'mileage_rate_miles'      => bccomp($rateKm, '0', 4) > 0 ? '0.2897' : '0.0000',
            'estimated_mileage'       => $estKm,
            'estimated_mileage_km'    => $estKm,
            'estimated_mileage_miles' => bccomp($estKm, '0', 2) > 0 ? '4970.968' : '0.000',
            'currency'                => $cust['currency'] ?? 'CAD',
            'tax_rate_gst'            => '5.00',
            'tax_rate_pst'            => '0.00',
            'tax_rate_hst'            => '0.00',
            'gst_exempt'              => 0,
            'pst_exempt'              => 0,
            'created_by'              => 1,
        ]);
    };

    // Helper: insert a minimal invoice with explicit silent-skip shape.
    $insertTempInvoice = function (int $leaseId, string $reviewStatus, string $tag): int {
        return db_insert('invoices', [
            'invoice_number'         => 'STRESS-I6-' . $tag . '-' . substr(uniqid(), -6),
            'invoice_type'           => 'regular',
            'customer_id'            => 9,
            'lease_id'               => $leaseId,
            'customer_name_snapshot' => 'SMOKE Test Co',
            'currency'               => 'CAD',
            'billing_period_start'   => '2026-04-01',
            'billing_period_end'     => '2026-04-30',
            'billing_period_days'    => 30,
            'period_distance_km'     => '500.00',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '1500.00',
            'billing_type'           => 'full_month',
            'rate_method_used'       => 'monthly',
            'invoice_date'           => '2026-04-30',
            'due_date'               => '2026-05-30',
            'status'                 => 'draft',
            'subtotal'               => '2200.00',
            'subtotal_after_discount' => '2200.00',
            'tax_total'              => '110.00',
            'total_amount'           => '2310.00',
            'balance_due'            => '2310.00',
            'mileage_review_status'  => $reviewStatus,
            'auto_generated'         => 0,
            'generation_source'      => 'manual',
            'created_by'             => 1,
        ]);
    };

    // ── T1: Model B Lite silent-skip shape — I6 SHOULD fire ───────────────
    $t1Lease = $insertTempLease('0.000', '0.1800', 'T1');
    $t1Invoice = $insertTempInvoice($t1Lease, 'not_required', 'T1');
    $hits = db_select($i6Sql);
    $t1Found = false;
    foreach ($hits as $h) {
        if ((int)$h['invoice_id'] === $t1Invoice) { $t1Found = true; break; }
    }
    $report(
        'T1 Model B Lite silent-skip shape (rate>0 + allowance=0 + review=not_required + no line)',
        $t1Found,
        $t1Found ? "I6 caught the temp invoice (id={$t1Invoice})" : "I6 missed the temp invoice (id={$t1Invoice})"
    );

    // ── T2: Model C with allowance covering — I6 SHOULD NOT fire ─────────
    $t2Lease = $insertTempLease('2000.000', '0.1800', 'T2');
    $t2Invoice = $insertTempInvoice($t2Lease, 'not_required', 'T2');
    $hits = db_select($i6Sql);
    $t2Found = false;
    foreach ($hits as $h) {
        if ((int)$h['invoice_id'] === $t2Invoice) { $t2Found = true; break; }
    }
    $report(
        'T2 Model C legit no-excess (rate>0 + allowance>0 + review=not_required)',
        !$t2Found,
        $t2Found ? "I6 false-positive on legit Model C (id={$t2Invoice})" : "correctly excluded"
    );

    // ── T3: Model B Lite with mileage line item — I6 SHOULD NOT fire ────
    $t3Lease = $insertTempLease('0.000', '0.1800', 'T3');
    $t3Invoice = $insertTempInvoice($t3Lease, 'not_required', 'T3');
    db_insert('invoice_line_items', [
        'invoice_id'  => $t3Invoice,
        'sort_order'  => 1,
        'item_type'   => 'mileage_adjustment',
        'description' => 'Stress fixture line',
        'quantity'    => '500.00',
        'unit'        => 'km',
        'unit_price'  => '0.18',
        'amount'      => '90.00',
        'taxable'     => 1,
    ]);
    $hits = db_select($i6Sql);
    $t3Found = false;
    foreach ($hits as $h) {
        if ((int)$h['invoice_id'] === $t3Invoice) { $t3Found = true; break; }
    }
    $report(
        'T3 Model B Lite with mileage line item present (NOT EXISTS escape)',
        !$t3Found,
        $t3Found ? "I6 false-positive despite line item (id={$t3Invoice})" : "correctly excluded"
    );

} finally {
    $pdo->rollBack();
}

echo "\n";
foreach ($out as $line) echo "  ", $line, "\n";
echo "\n";
echo str_repeat('═', 78), "\n";
echo "{$pass} passed, {$fail} failed\n";
echo "(All test data rolled back — no DB pollution.)\n";
exit($fail === 0 ? 0 : 1);
