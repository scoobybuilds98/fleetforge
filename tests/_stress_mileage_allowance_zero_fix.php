<?php
declare(strict_types=1);

/**
 * tests/_stress_mileage_allowance_zero_fix.php
 *
 * S-MILEAGE-ALLOWANCE-ZERO-FIX C1 stress test — exercise the InvoiceGenerator
 * mileage block AFTER the D-A engine guard fix that promoted the gate from
 * `estimated_mileage_km > 0` to `mileage_rate_km > 0`.
 *
 * Mirrors the pattern of tests/_stress_mileage_rate_validation.php (T1/T2/T3
 * D-B/D-C/clean path). Adds T4 (Model B Lite — the new accepted shape) and
 * T5 (D-B throw regression — must still fire on allowance>0 + rate=0).
 *
 * Five test cases:
 *
 *   T1 (Model B Lite NEW): rate=0.18, allowance=0, distance=500 km, period
 *       1 month. Pre-fix: silent skip, excess_charge_amount=0.
 *       Post-fix: excess_distance_km=500, excess_charge_amount=$90.00,
 *       mileage_review_status='pending'.
 *
 *   T2 (Model C unchanged, allowance covers): rate=0.18, allowance=2000,
 *       distance=500 km, period 1 month. Excess=0, charge=$0,
 *       review_status='not_required'.
 *
 *   T3 (Model C unchanged, excess): rate=0.18, allowance=2000,
 *       distance=3000 km, period 1 month. Excess > 0, charge > $0,
 *       review_status='pending'.
 *
 *   T4 (D-C WARNING regression): rate=0, allowance=0, distance=100 km.
 *       No exception, no charge, audit_log warning row written.
 *
 *   T5 (D-B HARD throw regression): rate=0, allowance=8000, distance=4000 km.
 *       BillingRateException with method='mileage_excess'.
 *
 * All five cases run inside a single outer transaction that is rolled back
 * at the end — InvoiceGenerator's inner db_transaction reuses the outer per
 * includes/db.php nesting semantics.
 *
 * Spec: S-MILEAGE-ALLOWANCE-ZERO-FIX D-A
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\BillingRateException;

$pass = 0;
$fail = 0;
$out  = [];

$report = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail, &$out) {
    if ($ok) { $pass++; $out[] = "PASS  {$name}" . ($detail ? "  — {$detail}" : ''); }
    else     { $fail++; $out[] = "FAIL  {$name}" . ($detail ? "  — {$detail}" : ''); }
};

echo "FleetForge — S-MILEAGE-ALLOWANCE-ZERO-FIX C1 stress test\n";
echo str_repeat('═', 78), "\n";

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    $cust = db_row("SELECT id, province, currency, gst_exempt FROM customers WHERE id = 9");
    $unit = db_row("SELECT id, template_id, unit_number FROM equipment_units WHERE status = 'available' AND deleted_at IS NULL ORDER BY id LIMIT 1")
         ?: db_row("SELECT id, template_id, unit_number FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$cust || !$unit) {
        throw new RuntimeException("Stress setup: missing customer 9 or any equipment_unit. Bailing.");
    }
    $tmpl = db_row("SELECT name FROM equipment_templates WHERE id = ?", [$unit['template_id']]);

    // Helper — insert a temp lease with the requested mileage shape.
    $insertTempLease = function (string $estKm, string $rateKm, string $tag) use ($cust, $unit, $tmpl): int {
        return db_insert('leases', [
            'contract_number'         => 'STRESS-AZF-' . $tag . '-' . substr(uniqid(), -6),
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
            'gst_exempt'              => (int) ($cust['gst_exempt'] ?? 0),
            'pst_exempt'              => 0,
            'created_by'              => 1,
        ]);
    };

    $invoiceGen = new InvoiceGenerator();

    // ── T1 (Model B Lite NEW): rate>0 + allowance=0 + distance>0 ──────────
    $t1Lease = $insertTempLease('0.000', '0.1800', 'T1');
    $t1Result = null;
    $t1Threw = false;
    try {
        $t1Result = $invoiceGen->createFromLease([
            'lease_id'                    => $t1Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '1500.00',  // 500 km period
        ]);
    } catch (\Throwable $e) {
        $t1Threw = true;
        $report('T1 Model B Lite (rate>0 + allowance=0)', false,
                'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }
    if (!$t1Threw) {
        $t1Inv = db_row(
            "SELECT excess_distance_km, excess_charge_amount, mileage_review_status
             FROM invoices WHERE id = ?",
            [$t1Result['invoice_id'] ?? 0]
        );
        // 500 km × $0.18 = $90.00; allowance=0 means full distance is excess.
        $expectedKm     = '500.00';
        $expectedCharge = '90.00';
        $kmOk     = bccomp((string) $t1Inv['excess_distance_km'], $expectedKm, 2) === 0;
        $chargeOk = bccomp((string) $t1Inv['excess_charge_amount'], $expectedCharge, 2) === 0;
        $reviewOk = $t1Inv['mileage_review_status'] === 'pending';
        $report(
            'T1 Model B Lite (rate>0 + allowance=0)',
            $kmOk && $chargeOk && $reviewOk,
            sprintf('excess_km=%s (want %s), charge=%s (want %s), review=%s (want pending)',
                $t1Inv['excess_distance_km'], $expectedKm,
                $t1Inv['excess_charge_amount'], $expectedCharge,
                $t1Inv['mileage_review_status'])
        );
    }

    // ── T2 (Model C unchanged, allowance covers): rate>0 + allowance>>distance ─
    $t2Lease = $insertTempLease('2000.000', '0.1800', 'T2');
    $t2Result = null;
    try {
        $t2Result = $invoiceGen->createFromLease([
            'lease_id'                    => $t2Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '1500.00',  // 500 km
        ]);
        $t2Inv = db_row(
            "SELECT excess_distance_km, excess_charge_amount, mileage_review_status
             FROM invoices WHERE id = ?",
            [$t2Result['invoice_id']]
        );
        // 1-month period, allowance=2000/1=2000 km. distance=500 < allowance.
        $kmOk     = bccomp((string) $t2Inv['excess_distance_km'], '0.00', 2) === 0;
        $chargeOk = bccomp((string) $t2Inv['excess_charge_amount'], '0.00', 2) === 0;
        $reviewOk = $t2Inv['mileage_review_status'] === 'not_required';
        $report(
            'T2 Model C unchanged (allowance covers)',
            $kmOk && $chargeOk && $reviewOk,
            sprintf('excess_km=%s, charge=%s, review=%s',
                $t2Inv['excess_distance_km'], $t2Inv['excess_charge_amount'],
                $t2Inv['mileage_review_status'])
        );
    } catch (\Throwable $e) {
        $report('T2 Model C unchanged (allowance covers)', false,
                'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }

    // ── T3 (Model C unchanged, excess): rate>0 + allowance>0, distance>>allowance ─
    $t3Lease = $insertTempLease('2000.000', '0.1800', 'T3');
    try {
        $t3Result = $invoiceGen->createFromLease([
            'lease_id'                    => $t3Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '4000.00',  // 3000 km
        ]);
        $t3Inv = db_row(
            "SELECT excess_distance_km, excess_charge_amount, mileage_review_status
             FROM invoices WHERE id = ?",
            [$t3Result['invoice_id']]
        );
        // distance=3000, monthly_allowance=2000 → excess=1000 km × $0.18 = $180.
        $kmOk     = bccomp((string) $t3Inv['excess_distance_km'], '1000.00', 2) === 0;
        $chargeOk = bccomp((string) $t3Inv['excess_charge_amount'], '180.00', 2) === 0;
        $reviewOk = $t3Inv['mileage_review_status'] === 'pending';
        $report(
            'T3 Model C unchanged (excess > 0)',
            $kmOk && $chargeOk && $reviewOk,
            sprintf('excess_km=%s (want 1000), charge=%s (want 180), review=%s',
                $t3Inv['excess_distance_km'], $t3Inv['excess_charge_amount'],
                $t3Inv['mileage_review_status'])
        );
    } catch (\Throwable $e) {
        $report('T3 Model C unchanged (excess > 0)', false,
                'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }

    // ── T4 (D-C WARNING regression): rate=0 + allowance=0 + distance>0 ────
    $t4Lease = $insertTempLease('0.000', '0.0000', 'T4');
    $t4BeforeWarn = db_count(
        "SELECT COUNT(*) FROM audit_log WHERE entity_type='lease' AND entity_id=? AND notes LIKE '[FLEETFORGE_BILLING_WARNING]%'",
        [$t4Lease]
    );
    try {
        $t4Result = $invoiceGen->createFromLease([
            'lease_id'                    => $t4Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '1100.00',  // 100 km
        ]);
        $t4AfterWarn = db_count(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type='lease' AND entity_id=? AND notes LIKE '[FLEETFORGE_BILLING_WARNING]%'",
            [$t4Lease]
        );
        $report(
            'T4 D-C WARNING regression (rate=0 + allowance=0)',
            ($t4AfterWarn - $t4BeforeWarn) === 1,
            "audit_log warnings: before={$t4BeforeWarn} after={$t4AfterWarn}"
        );
    } catch (\Throwable $e) {
        $report('T4 D-C WARNING regression (rate=0 + allowance=0)', false,
                'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }

    // ── T5 (D-B HARD throw regression): rate=0 + allowance>0 + distance>0 ─
    $t5Lease = $insertTempLease('8000.000', '0.0000', 'T5');
    $t5Threw = false;
    $t5Method = null;
    try {
        $invoiceGen->createFromLease([
            'lease_id'                    => $t5Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '5000.00',  // 4000 km
        ]);
    } catch (BillingRateException $e) {
        $t5Threw = true;
        $t5Method = $e->method;
    } catch (\Throwable $e) {
        $report('T5 D-B HARD throw regression (rate=0 + allowance>0)', false,
                'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }
    if (!$t5Threw) {
        $report('T5 D-B HARD throw regression (rate=0 + allowance>0)', false, 'expected throw, none raised');
    } else {
        $report(
            'T5 D-B HARD throw regression (rate=0 + allowance>0)',
            $t5Method === 'mileage_excess',
            "method={$t5Method}"
        );
    }

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
