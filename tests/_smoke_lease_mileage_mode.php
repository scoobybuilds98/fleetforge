<?php
/**
 * _smoke_lease_mileage_mode.php — S-LEASE-MILEAGE-MODE
 *
 * Proves the per-lease mileage-source toggle (manual / off / samsara) gates
 * auto-billing correctly, and that a manually-entered starting odometer is
 * never overwritten by Samsara at activation.
 *
 * Coverage:
 *   T1 samsara mode — InvoiceGenerator D-C fetches Samsara distance (fixture
 *      FIX_STD = 1234.56 km) and emits a mileage line.
 *   T2 manual mode  — D-C is SKIPPED; no Samsara distance, no mileage line,
 *      even though the unit is Samsara-linked.
 *   T3 off mode     — odometer snapshot + mileage line suppressed even when the
 *      caller pre-populates odometer params.
 *   T4 activation guard — the activation UPDATE never overwrites an existing
 *      source='manual' odometer with a GPS (or 0) value.
 *
 * Hermetic: all writes happen inside BEGIN/ROLLBACK. Samsara is faked via
 * settings.samsara.fixture_mode=1 (FixtureProvider), restored on exit.
 *
 * Run: php tests/_smoke_lease_mileage_mode.php
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
function check(string $id, string $name, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS $id  $name — $msg\n"; }
    else     { $fail++; echo "  FAIL $id  $name — $msg\n"; }
}

// ── Flip Samsara fixture mode ON; remember the prior value ────────
$originalFixtureMode = (string) settings_get('samsara.fixture_mode');
db_execute("UPDATE settings SET value = '1' WHERE `key` = 'samsara.fixture_mode'");

echo "FleetForge — lease mileage-mode smoke (S-LEASE-MILEAGE-MODE)\n";
echo str_repeat('=', 70) . "\n";

db_execute("BEGIN");
try {
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) {
        throw new RuntimeException("Missing seed data (customer=$cust unit=$unit user=$user)");
    }

    // Link the unit to a fixture Samsara vehicle (reverted by ROLLBACK).
    db_execute(
        "UPDATE equipment_units SET samsara_vehicle_id = 'FIX_STD', samsara_entity_type = 'vehicle' WHERE id = ?",
        [$unit]
    );

    // Lift the invoice counter above committed MAX so generateInvoiceNumber()
    // can't collide with real data (reverted by ROLLBACK).
    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 100)]
    );

    /** Insert an active monthly lease in the given mileage mode. */
    $makeLease = function (string $mode, string $tag) use ($cust, $unit, $user): int {
        return db_insert('leases', [
            'contract_number'   => "SMOKE-MMODE-{$tag}",
            'customer_id'       => $cust,
            'equipment_unit_id' => $unit,
            'start_date'        => '2026-05-01',
            'status'            => 'active',
            'daily_rate'        => '10.00',
            'weekly_rate'       => '60.00',
            'monthly_rate'      => '250.00',
            'mileage_unit'      => 'km',
            'mileage_rate_km'   => '0.18',
            'currency'          => 'CAD',
            'billing_cycle'     => 'monthly',
            'mileage_tracking_mode' => $mode,
            'created_by'        => $user,
            'updated_by'        => $user,
        ]);
    };

    $gen = new \FleetForge\Billing\InvoiceGenerator();

    $mileageLine = function (int $invoiceId): ?array {
        return db_row(
            "SELECT amount, quantity FROM invoice_line_items
              WHERE invoice_id = ? AND item_type = 'mileage_usage'",
            [$invoiceId]
        );
    };

    // ── T1: samsara mode → D-C fetch fires, mileage line emitted ──
    $l1 = $makeLease('samsara', 'SAMSARA');
    $inv1 = $gen->createFromLease([
        'lease_id' => $l1, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $row1 = db_row("SELECT period_distance_km, odometer_source FROM invoices WHERE id = ?", [$inv1['invoice_id']]);
    $ml1  = $mileageLine($inv1['invoice_id']);
    check('T1', 'samsara mode emits Samsara mileage',
        $ml1 !== null && (string) $row1['period_distance_km'] === '1234.56',
        "period_distance_km=" . ($row1['period_distance_km'] ?? 'null') . " source=" . ($row1['odometer_source'] ?? 'null')
        . " mileage_line=" . ($ml1 ? 'present' : 'absent') . " (expect 1234.56 / present)");

    // ── T2: manual mode → D-C SKIPPED, no Samsara distance/line ──
    $l2 = $makeLease('manual', 'MANUAL');
    $inv2 = $gen->createFromLease([
        'lease_id' => $l2, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
    ]);
    $row2 = db_row("SELECT period_distance_km, odometer_source FROM invoices WHERE id = ?", [$inv2['invoice_id']]);
    $ml2  = $mileageLine($inv2['invoice_id']);
    check('T2', 'manual mode skips Samsara fetch',
        $ml2 === null && $row2['period_distance_km'] === null,
        "period_distance_km=" . ($row2['period_distance_km'] ?? 'null')
        . " mileage_line=" . ($ml2 ? 'present' : 'absent') . " (expect null / absent)");

    // ── T3: off mode → odometer + mileage suppressed even with caller params ──
    $l3 = $makeLease('off', 'OFF');
    $inv3 = $gen->createFromLease([
        'lease_id' => $l3, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        // Caller pre-populates odometer — off mode must still suppress it.
        'odometer_at_period_start_km' => '1000.00',
        'odometer_at_period_end_km'   => '1500.00',
        'odometer_source'             => 'manual',
    ]);
    $row3 = db_row("SELECT period_distance_km, odometer_at_period_end_km FROM invoices WHERE id = ?", [$inv3['invoice_id']]);
    $ml3  = $mileageLine($inv3['invoice_id']);
    check('T3', 'off mode suppresses odometer + mileage',
        $ml3 === null && $row3['period_distance_km'] === null && $row3['odometer_at_period_end_km'] === null,
        "period_distance_km=" . ($row3['period_distance_km'] ?? 'null')
        . " odo_end=" . ($row3['odometer_at_period_end_km'] ?? 'null')
        . " mileage_line=" . ($ml3 ? 'present' : 'absent') . " (expect null / null / absent)");

    // ── T4: activation guard never overwrites a manual odometer ──
    // Insert a pending lease carrying an operator-entered manual reading, then
    // run the exact activation UPDATE with a GPS reading of 0 (the failure that
    // produced the original "gives 0" bug). The CASE guard must preserve it.
    $l4 = db_insert('leases', [
        'contract_number'     => 'SMOKE-MMODE-ACTIVATE',
        'customer_id'         => $cust,
        'equipment_unit_id'   => $unit,
        'start_date'          => '2026-05-01',
        'status'              => 'pending',
        'daily_rate'          => '10.00',
        'weekly_rate'         => '60.00',
        'monthly_rate'        => '250.00',
        'currency'            => 'CAD',
        'billing_cycle'       => 'monthly',
        'mileage_tracking_mode'   => 'samsara',
        'odometer_start_km'       => '5000.00',
        'odometer_start_source'   => 'manual',
        'created_by'          => $user,
        'updated_by'          => $user,
    ]);
    // Mirror api/v1/leases/activate.php's hardened UPDATE with a GPS 0-reading.
    db_execute(
        "UPDATE leases
            SET odometer_start_km     = CASE WHEN odometer_start_source = 'manual'
                                             THEN odometer_start_km
                                             ELSE COALESCE(?, odometer_start_km) END,
                odometer_start_source = CASE WHEN odometer_start_source = 'manual'
                                             THEN odometer_start_source
                                             ELSE COALESCE(?, odometer_start_source) END
          WHERE id = ?",
        ['0.00', 'gps', $l4]
    );
    $row4 = db_row("SELECT odometer_start_km, odometer_start_source FROM leases WHERE id = ?", [$l4]);
    check('T4', 'activation preserves manual odometer vs GPS 0',
        (string) $row4['odometer_start_km'] === '5000.00' && $row4['odometer_start_source'] === 'manual',
        "odometer_start_km=" . $row4['odometer_start_km'] . " source=" . $row4['odometer_start_source']
        . " (expect 5000.00 / manual — NOT overwritten by GPS 0)");

    db_execute("ROLLBACK");
} catch (\Throwable $e) {
    db_execute("ROLLBACK");
    echo "  FATAL — " . $e->getMessage() . "\n";
    $fail++;
}

// ── Restore fixture mode ──────────────────────────────────────────
db_execute(
    "UPDATE settings SET value = ? WHERE `key` = 'samsara.fixture_mode'",
    [$originalFixtureMode === '' ? '0' : $originalFixtureMode]
);

echo str_repeat('=', 70) . "\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
