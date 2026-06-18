<?php
declare(strict_types=1);

/**
 * tests/_stress_mileage_rate_validation.php
 *
 * S-MILEAGE-RATE-VALIDATION C1 stress test — exercise the InvoiceGenerator
 * defensive layer (D-B throw + D-C WARNING) against real DB state with full
 * BEGIN/ROLLBACK isolation so no test data persists.
 *
 * Mirrors the pattern of tests/_stress_billing_rate_exception.php
 * (S-BILLING-RATE-FIX C3): exercise the engine end-to-end, assert the
 * defensive code path fires, leave the database untouched.
 *
 * Three test cases:
 *
 *   T1 (D-B throw): temp lease with estimated_mileage_km > 0 + mileage_rate_km = 0;
 *       run InvoiceGenerator with positive period_distance_km. Assert
 *       BillingRateException thrown with method='mileage_excess'.
 *
 *   T2 (D-C WARNING): temp lease with estimated_mileage_km = 0 + mileage_rate_km = 0;
 *       run InvoiceGenerator with positive period_distance_km. Assert NO exception
 *       AND audit_log row written with '[FLEETFORGE_BILLING_WARNING]' prefix.
 *
 *   T3 (clean path): temp lease with estimated_mileage_km > 0 + mileage_rate_km > 0;
 *       run InvoiceGenerator with positive period_distance_km. Assert no exception
 *       AND no warning audit_log row.
 *
 * All three cases run inside a single outer transaction that is rolled back
 * at the end — InvoiceGenerator's inner db_transaction reuses the outer per
 * includes/db.php nesting semantics.
 *
 * Spec: S-MILEAGE-RATE-VALIDATION D-B + D-C
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

echo "FleetForge — S-MILEAGE-RATE-VALIDATION C1 stress test\n";
echo str_repeat('═', 78), "\n";

// ────────────────────────────────────────────────────────────────────────────
// Outer BEGIN — everything below rolls back at the end
// ────────────────────────────────────────────────────────────────────────────
$pdo = db_pdo();
$pdo->beginTransaction();

try {
    // Pull a viable customer + equipment_unit pair from existing data so we
    // don't have to fabricate full FK chains. Customer 9 (SMOKE Test Co) has
    // zero customer_equipment_rates rows — perfect, no rate-source ambiguity.
    $cust = db_row("SELECT id, province, currency, gst_exempt FROM customers WHERE id = 9");
    $unit = db_row("SELECT id, template_id, unit_number FROM equipment_units WHERE status = 'available' AND deleted_at IS NULL ORDER BY id LIMIT 1")
         ?: db_row("SELECT id, template_id, unit_number FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");

    if (!$cust || !$unit) {
        throw new RuntimeException("Stress setup: missing customer 9 or any equipment_unit. Bailing.");
    }

    $tmpl = db_row("SELECT name FROM equipment_templates WHERE id = ?", [$unit['template_id']]);

    // ── Helper: insert a temp lease with the requested mileage state ────────
    $insertTempLease = function (string $estKm, string $rateKm, string $tag) use ($cust, $unit, $tmpl): int {
        return db_insert('leases', [
            'contract_number'         => 'STRESS-' . $tag . '-' . substr(uniqid(), -6),
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
            'mileage_tracking_mode'   => 'samsara',  // S-LEASE-MILEAGE-MODE: mileage billing active (col default 'off' suppresses it)
            'daily_rate'              => '125.00',
            'weekly_rate'             => '750.00',
            'monthly_rate'            => '2200.00',
            'mileage_unit'            => 'km',
            'mileage_rate'            => $rateKm,
            'mileage_rate_km'         => $rateKm,
            'mileage_rate_miles'      => $rateKm === '0' || $rateKm === '0.0000' ? '0.0000' : '0.2897',
            'estimated_mileage'       => $estKm,
            'estimated_mileage_km'    => $estKm,
            'estimated_mileage_miles' => $estKm === '0' || $estKm === '0.000' ? '0.000' : '4970.968',
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

    // ── T1: D-B throw path ─────────────────────────────────────────────────
    $t1Lease = $insertTempLease('8000.000', '0.0000', 'T1');
    $t1Threw = false;
    $t1Method = null;
    try {
        $invoiceGen->createFromLease([
            'lease_id'                    => $t1Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '5000.00',
        ]);
    } catch (BillingRateException $e) {
        $t1Threw = true;
        $t1Method = $e->method;
    } catch (\Throwable $e) {
        $report('T1 D-B throw on rate=0+allowance>0+distance>0', false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }
    if (!$t1Threw) {
        $report('T1 D-B throw on rate=0+allowance>0+distance>0', false, 'expected throw, no exception');
    } else {
        $report('T1 D-B throw on rate=0+allowance>0+distance>0', $t1Method === 'mileage_excess', "method={$t1Method}");
    }

    // ── T2: D-C WARNING path ───────────────────────────────────────────────
    // Snapshot audit_log row count before, then run, then check row count after
    // for the marker prefix. Same outer transaction means the audit_log row is
    // visible to our SELECT but rolled back at the end.
    $t2Lease = $insertTempLease('0.000', '0.0000', 'T2');
    $beforeWarnings = db_count(
        "SELECT COUNT(*) FROM audit_log WHERE entity_type='lease' AND entity_id=? AND notes LIKE '[FLEETFORGE_BILLING_WARNING]%'",
        [$t2Lease]
    );
    $t2Threw = false;
    try {
        $invoiceGen->createFromLease([
            'lease_id'                    => $t2Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '5000.00',
        ]);
    } catch (\Throwable $e) {
        $t2Threw = true;
        $report('T2 D-C WARNING on rate=0+allowance=0+distance>0', false, 'unexpected throw ' . get_class($e) . ': ' . $e->getMessage());
    }
    if (!$t2Threw) {
        $afterWarnings = db_count(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type='lease' AND entity_id=? AND notes LIKE '[FLEETFORGE_BILLING_WARNING]%'",
            [$t2Lease]
        );
        $report(
            'T2 D-C WARNING on rate=0+allowance=0+distance>0',
            ($afterWarnings - $beforeWarnings) === 1,
            "audit_log warnings: before={$beforeWarnings} after={$afterWarnings}"
        );
    }

    // ── T3: clean path (no throw, no warning) ──────────────────────────────
    $t3Lease = $insertTempLease('8000.000', '0.1800', 'T3');
    $t3Threw = false;
    try {
        $invoiceGen->createFromLease([
            'lease_id'                    => $t3Lease,
            'period_start'                => '2026-04-01',
            'period_end'                  => '2026-04-30',
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '1000.00',
            'odometer_at_period_end_km'   => '5000.00',
        ]);
    } catch (\Throwable $e) {
        $t3Threw = true;
        $report('T3 clean path: rate>0+allowance>0', false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }
    if (!$t3Threw) {
        $t3Warnings = db_count(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type='lease' AND entity_id=? AND notes LIKE '[FLEETFORGE_BILLING_WARNING]%'",
            [$t3Lease]
        );
        $report(
            'T3 clean path: rate>0+allowance>0',
            $t3Warnings === 0,
            "no warnings written ({$t3Warnings})"
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
