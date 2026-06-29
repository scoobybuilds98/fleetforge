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
// S-CLOSE-MANUAL-MILEAGE-BRIDGE: the close-time manual-mileage bridge helper.
require_once FF_ROOT . '/api/v1/leases/_close_reconciliation.php';

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

    // ── T5: off mode + configured mileage rate → billing WARNING emitted ──
    // S-MILEAGE-MODE-OFF-WARN: l3 (off) carries mileage_rate_km=0.18, so the
    // generator must write a non-blocking [FLEETFORGE_BILLING_WARNING] audit_log
    // row flagging the silent rate>0 + mode='off' trap (the MTTS68 /
    // INV-2026-00150 incident). l2 (manual, same rate) must NOT — manual mode
    // bills mileage normally and is not a misconfiguration.
    $warnRow = function (int $leaseId): ?array {
        return db_row(
            "SELECT id FROM audit_log
              WHERE entity_type = 'lease' AND entity_id = ? AND module = 'billing'
                AND notes LIKE '[FLEETFORGE_BILLING_WARNING]%mileage_tracking_mode is OFF%'
              ORDER BY id DESC LIMIT 1",
            [$leaseId]
        );
    };
    $w3 = $warnRow($l3);
    $w2 = $warnRow($l2);
    check('T5', 'off+rate emits billing warning; manual does not',
        $w3 !== null && $w2 === null,
        "off_lease_warn=" . ($w3 ? 'present' : 'absent')
        . " manual_lease_warn=" . ($w2 ? 'present' : 'absent') . " (expect present / absent)");

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

    // ── T6: manual-mileage bridge helper gating (pure, no DB) ──
    // S-CLOSE-MANUAL-MILEAGE-BRIDGE: the close "Actual Mileage (for billing)"
    // field must bill on a manual lease (the MTTS68 175 km × $0.06 = $10.50 fix)
    // but never double-bill the legacy overage / modern odometer / per-period
    // paths, and must convert a miles-unit lease to km.
    $hbase = ['mileage_tracking_mode' => 'manual', 'mileage_rate_km' => '0.0600',
              'mileage_at_start' => null, 'mileage_unit' => 'km', 'km_to_miles_conversion' => '0.621371'];
    $h1 = ff_close_manual_mileage_bridge_line($hbase, 175, null, false);                       // bills
    $h2 = ff_close_manual_mileage_bridge_line(['mileage_tracking_mode' => 'off'] + $hbase, 175, null, false); // off
    $h3 = ff_close_manual_mileage_bridge_line($hbase, 175, '5000.00', false);                  // odometer path owns it
    $h4 = ff_close_manual_mileage_bridge_line(['mileage_at_start' => 100] + $hbase, 175, null, false); // legacy owns it
    $h5 = ff_close_manual_mileage_bridge_line($hbase, 175, null, true);                        // prior mileage billed
    $h6 = ff_close_manual_mileage_bridge_line(['mileage_unit' => 'miles'] + $hbase, 175, null, false); // miles → km
    check('T6', 'manual mileage bridge gating + unit conversion',
        // S-MILEAGE-RATE-CONVERT-FIX: a miles lease now bills the per-mile-canonical
        // amount (175 mi × $0.0966/mi = $16.91), which is qty×price-consistent on the
        // line (the QBO invariant), vs the old km-canonical 281.65 km × $0.06 = $16.90.
        $h1 !== null && $h1['amount'] === '10.50' && $h1['item_type'] === 'mileage_usage'
        && $h2 === null && $h3 === null && $h4 === null && $h5 === null
        && $h6 !== null && $h6['amount'] === '16.91' && ($h6['unit'] ?? '') === 'miles',
        "manual/175=" . ($h1['amount'] ?? 'null') . " off=" . ($h2 === null ? 'null' : 'LEAK')
        . " odo=" . ($h3 === null ? 'null' : 'LEAK') . " legacy=" . ($h4 === null ? 'null' : 'LEAK')
        . " prior=" . ($h5 === null ? 'null' : 'LEAK') . " miles=" . ($h6['amount'] ?? 'null') . "/" . ($h6['unit'] ?? '?')
        . " (expect 10.50/null/null/null/null/16.91/miles)");

    // ── T7: bridged line bills through createFromLease (the carrier) ──
    // close.php appends the helper's line to $extraLines on an 'adjustment'
    // invoice (the guaranteed fallback carrier); assert the mileage_usage line
    // materializes with the right amount and no auto-drawdown double.
    $l6  = $makeLease('manual', 'BRIDGE');  // mileage_rate_km = 0.18
    $brg = ff_close_manual_mileage_bridge_line(
        array_merge(
            ['mileage_at_start' => null, 'mileage_unit' => 'km', 'km_to_miles_conversion' => '0.621371'],
            db_row("SELECT mileage_tracking_mode, mileage_rate_km FROM leases WHERE id = ?", [$l6])
        ),
        200, null, false
    );
    $inv6 = $gen->createFromLease([
        'lease_id' => $l6, 'period_start' => '2026-05-25', 'period_end' => '2026-05-25',
        'billing_type' => 'adjustment', 'invoice_type' => 'final', 'created_by' => $user,
        'extra_lines' => [$brg],
    ]);
    $ml6 = $mileageLine($inv6['invoice_id']);
    // 200 km × $0.18/km = $36.00; exactly one mileage_usage line (no auto double).
    $ml6count = (int) (db_row(
        "SELECT COUNT(*) c FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_usage'",
        [$inv6['invoice_id']]
    )['c'] ?? 0);
    check('T7', 'bridged mileage line bills on adjustment invoice (no double)',
        $ml6 !== null && (string) $ml6['amount'] === '36.00' && $ml6count === 1,
        "mileage_line=" . ($ml6 ? ('present amt=' . $ml6['amount']) : 'absent')
        . " count=" . $ml6count . " (expect present 36.00 count=1)");

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
