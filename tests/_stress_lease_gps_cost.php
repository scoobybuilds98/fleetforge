<?php
declare(strict_types=1);

/**
 * tests/_stress_lease_gps_cost.php
 *
 * S-LEASE-GPS-COST C3 stress test — exercises the InvoiceGenerator GPS
 * line emission added in lib/Billing/InvoiceGenerator.php after the
 * warranty add-on block.
 *
 * GPS bills per-day (gps_cost × billing_days), unlike insurance/warranty
 * which are flat-per-period. The fixture leases below all run a
 * 30-day inclusive billing window (2026-04-01 → 2026-04-30) so the
 * arithmetic for $1/day default is $30.00.
 *
 * Four test cases (all rolled back at the end):
 *
 *   T1 (default rate, opt-in): gps_opt_in=1, gps_cost=1.00
 *       → gps line present, quantity=30.0000, unit='day',
 *         unit_price=1.00, amount=30.00
 *
 *   T2 (cost=0 silent skip): gps_opt_in=1, gps_cost=0.00
 *       → NO gps line emitted (cost > 0 guard)
 *
 *   T3 (toggle off): gps_opt_in=0, gps_cost=25.00
 *       → NO gps line emitted (opt_in guard)
 *
 *   T4 (combined add-ons): insurance + warranty + gps all opt_in with
 *       non-zero costs → all three lines render and sum into subtotal
 *
 * Note on mileage_only billing: GPS shares the exact
 * `$billingType !== 'mileage_only'` guard with insurance/warranty —
 * if those skip mileage_only invoices (covered elsewhere), GPS does
 * too. A direct mileage_only test requires period_type fixture setup
 * that isn't worth duplicating here.
 *
 * Spec: S-LEASE-GPS-COST D-D (engine integration)
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

$pass = 0;
$fail = 0;
$out  = [];

$report = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail, &$out) {
    if ($ok) { $pass++; $out[] = "PASS  {$name}" . ($detail ? "  — {$detail}" : ''); }
    else     { $fail++; $out[] = "FAIL  {$name}" . ($detail ? "  — {$detail}" : ''); }
};

echo "FleetForge — S-LEASE-GPS-COST C3 stress test\n";
echo str_repeat('═', 78), "\n";

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    $cust = db_row("SELECT id, province, currency, gst_exempt FROM customers WHERE id = 9");
    $unit = db_row("SELECT id, template_id, unit_number FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$cust || !$unit) {
        throw new RuntimeException("Stress setup: missing customer 9 or any equipment_unit.");
    }
    $tmpl = db_row("SELECT name FROM equipment_templates WHERE id = ?", [$unit['template_id']]);

    // Helper — insert a temp lease with GPS + insurance + warranty shapes specified.
    $mkLease = function (
        string $tag,
        int $gpsOptIn, string $gpsCost,
        int $insOptIn = 0, string $insCost = '0.00',
        int $warOptIn = 0, string $warCost = '0.00'
    ) use ($cust, $unit, $tmpl): int {
        return db_insert('leases', [
            'contract_number'         => 'STRESS-GPS-' . $tag . '-' . substr(uniqid(), -6),
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
            'mileage_rate'            => '0.0000',
            'mileage_rate_km'         => '0.0000',
            'mileage_rate_miles'      => '0.0000',
            'estimated_mileage'       => '0.000',
            'estimated_mileage_km'    => '0.000',
            'estimated_mileage_miles' => '0.000',
            'currency'                => $cust['currency'] ?? 'CAD',
            'tax_rate_gst'            => '5.00',
            'tax_rate_pst'            => '0.00',
            'tax_rate_hst'            => '0.00',
            'gst_exempt'              => (int) ($cust['gst_exempt'] ?? 0),
            'pst_exempt'              => 0,
            'gps_opt_in'              => $gpsOptIn,
            'gps_cost'                => $gpsCost,
            'insurance_opt_in'        => $insOptIn,
            'insurance_cost'          => $insCost,
            'warranty_opt_in'         => $warOptIn,
            'warranty_cost'           => $warCost,
            'created_by'              => 1,
        ]);
    };

    $invoiceGen = new InvoiceGenerator();

    // ── T1: default $1/day, opt-in ON, 30-day period → $30.00 ────────────
    $t1Lease = $mkLease('T1', 1, '1.00');
    $t1 = $invoiceGen->createFromLease([
        'lease_id'     => $t1Lease,
        'period_start' => '2026-04-01',
        'period_end'   => '2026-04-30',
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
    ]);
    $t1Gps = db_row(
        "SELECT quantity, unit, unit_price, amount, billing_days, period_start, period_end
         FROM invoice_line_items
         WHERE invoice_id = ? AND item_type = 'gps'",
        [$t1['invoice_id']]
    );
    $t1Ok =
        $t1Gps !== null
        && bccomp((string)$t1Gps['quantity'], '30.0000', 4) === 0
        && $t1Gps['unit'] === 'day'
        && bccomp((string)$t1Gps['unit_price'], '1.00', 2) === 0
        && bccomp((string)$t1Gps['amount'], '30.00', 2) === 0
        && (int)$t1Gps['billing_days'] === 30;
    $report(
        'T1 default $1/day × 30 days = $30.00',
        $t1Ok,
        $t1Gps
            ? sprintf('qty=%s unit=%s price=%s amount=%s billing_days=%s',
                $t1Gps['quantity'], $t1Gps['unit'], $t1Gps['unit_price'],
                $t1Gps['amount'], $t1Gps['billing_days'])
            : 'no gps line emitted (expected one)'
    );

    // ── T2: opt_in=1, cost=0 → silent skip ───────────────────────────────
    $t2Lease = $mkLease('T2', 1, '0.00');
    $t2 = $invoiceGen->createFromLease([
        'lease_id'     => $t2Lease,
        'period_start' => '2026-04-01',
        'period_end'   => '2026-04-30',
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
    ]);
    $t2Count = db_count(
        "SELECT COUNT(*) FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'gps'",
        [$t2['invoice_id']]
    );
    $report(
        'T2 cost=0 → no gps line',
        $t2Count === 0,
        "found {$t2Count} gps lines (want 0)"
    );

    // ── T3: opt_in=0, cost=25 → silent skip ──────────────────────────────
    $t3Lease = $mkLease('T3', 0, '25.00');
    $t3 = $invoiceGen->createFromLease([
        'lease_id'     => $t3Lease,
        'period_start' => '2026-04-01',
        'period_end'   => '2026-04-30',
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
    ]);
    $t3Count = db_count(
        "SELECT COUNT(*) FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'gps'",
        [$t3['invoice_id']]
    );
    $report(
        'T3 opt_in=0 → no gps line',
        $t3Count === 0,
        "found {$t3Count} gps lines (want 0)"
    );

    // ── T4: combined insurance + warranty + gps ──────────────────────────
    // Insurance $50 (flat) + Warranty $40 (flat) + GPS $2/day × 30 = $60
    // → all three lines must render and contribute to subtotal.
    $t4Lease = $mkLease('T4', 1, '2.00', 1, '50.00', 1, '40.00');
    $t4 = $invoiceGen->createFromLease([
        'lease_id'     => $t4Lease,
        'period_start' => '2026-04-01',
        'period_end'   => '2026-04-30',
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
    ]);
    $t4Lines = db_select(
        "SELECT item_type, amount FROM invoice_line_items
         WHERE invoice_id = ? AND item_type IN ('insurance','warranty','gps')
         ORDER BY sort_order",
        [$t4['invoice_id']]
    );
    $byType = [];
    foreach ($t4Lines as $row) {
        $byType[$row['item_type']] = $row['amount'];
    }
    $t4Ok =
        isset($byType['insurance'], $byType['warranty'], $byType['gps'])
        && bccomp((string)$byType['insurance'], '50.00', 2) === 0
        && bccomp((string)$byType['warranty'], '40.00', 2) === 0
        && bccomp((string)$byType['gps'], '60.00', 2) === 0;
    $report(
        'T4 combined add-ons (ins=50 + war=40 + gps=2/d×30=60)',
        $t4Ok,
        sprintf('ins=%s war=%s gps=%s',
            $byType['insurance'] ?? 'MISSING',
            $byType['warranty'] ?? 'MISSING',
            $byType['gps'] ?? 'MISSING')
    );

} finally {
    $pdo->rollBack();
}

echo implode("\n", $out), "\n";
echo str_repeat('═', 78), "\n";
echo ($fail === 0 ? 'STRESS OK' : 'STRESS FAIL'), " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
