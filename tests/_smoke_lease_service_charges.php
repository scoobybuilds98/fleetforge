<?php
/**
 * _smoke_lease_service_charges.php — S-LEASE-SERVICE-CHARGES
 *
 * Proves the one-time lease service charges:
 *   - Cartage (entered at creation) bills exactly ONCE, on the first invoice,
 *     and is stamped cartage_billed_at so a second invoice doesn't re-bill it.
 *   - Sweep / Wash / Fuel closeout charges become invoice line items
 *     (sweep flat, wash flat, fuel = gallons × per-gallon rate).
 *
 * Cartage is exercised directly through InvoiceGenerator; closeout charges are
 * built the same way close.php builds them (extra_lines) and billed via an
 * 'adjustment' (extra-lines-only) invoice — the close.php post-pass guarantee.
 *
 * Hermetic: all writes inside BEGIN/ROLLBACK.
 * Run: php tests/_smoke_lease_service_charges.php  (exit 0 PASS / 1 FAIL)
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

$pass = 0; $fail = 0;
function check(string $id, string $name, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS $id  $name — $msg\n"; }
    else     { $fail++; echo "  FAIL $id  $name — $msg\n"; }
}

echo "FleetForge — lease service-charges smoke (S-LEASE-SERVICE-CHARGES)\n";
echo str_repeat('=', 70) . "\n";

db_execute("BEGIN");
try {
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) throw new RuntimeException("Missing seed data");

    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 100)]);

    $gen = new \FleetForge\Billing\InvoiceGenerator();
    $lineOf = function (int $invoiceId, string $type): ?array {
        return db_row("SELECT amount, quantity, unit FROM invoice_line_items WHERE invoice_id = ? AND item_type = ?", [$invoiceId, $type]);
    };

    // ── T1+T2: cartage bills once on the first invoice, not on the second ──
    $lc = db_insert('leases', [
        'contract_number' => 'SMOKE-SVC-CARTAGE', 'customer_id' => $cust, 'equipment_unit_id' => $unit,
        'start_date' => '2026-05-01', 'status' => 'active',
        'daily_rate' => '10.00', 'weekly_rate' => '60.00', 'monthly_rate' => '250.00',
        'currency' => 'CAD', 'billing_cycle' => 'monthly',
        'cartage_amount' => '175.00',
        'created_by' => $user, 'updated_by' => $user,
    ]);
    $inv1 = $gen->createFromLease(['lease_id'=>$lc,'period_start'=>'2026-05-01','period_end'=>'2026-05-31',
        'billing_type'=>'full_month','invoice_type'=>'regular','created_by'=>$user]);
    $c1 = $lineOf($inv1['invoice_id'], 'cartage');
    $billedAt = db_row("SELECT cartage_billed_at FROM leases WHERE id = ?", [$lc])['cartage_billed_at'];
    check('T1', 'cartage on first invoice + stamped',
        $c1 !== null && $c1['amount'] === '175.00' && $billedAt !== null,
        "cartage_line=" . ($c1['amount'] ?? 'none') . " billed_at=" . ($billedAt ?? 'null') . " (expect 175.00 / set)");

    $inv2 = $gen->createFromLease(['lease_id'=>$lc,'period_start'=>'2026-06-01','period_end'=>'2026-06-30',
        'billing_type'=>'full_month','invoice_type'=>'regular','created_by'=>$user]);
    $c2 = $lineOf($inv2['invoice_id'], 'cartage');
    check('T2', 'cartage NOT re-billed while a live cartage line exists', $c2 === null,
        "second_invoice_cartage=" . ($c2 ? 'present' : 'absent') . " (expect absent)");

    // ── T2b: cartage RE-bills after its carrier invoice is VOIDED (live-aware guard) ──
    // Reproduces the close overshoot reconciliation (void the first invoice + reissue):
    // with the only cartage line now on a void invoice, the next invoice must re-emit it
    // (the old cartage_billed_at flag left it stranded → customer silently undercharged).
    db_execute("UPDATE invoices SET status='void' WHERE id = ?", [$inv1['invoice_id']]);
    $inv3 = $gen->createFromLease(['lease_id'=>$lc,'period_start'=>'2026-07-01','period_end'=>'2026-07-31',
        'billing_type'=>'full_month','invoice_type'=>'regular','created_by'=>$user]);
    $c3 = $lineOf($inv3['invoice_id'], 'cartage');
    check('T2b', 'cartage re-bills after its carrier is voided', $c3 !== null && $c3['amount'] === '175.00',
        "reissue_cartage=" . ($c3['amount'] ?? 'none') . " (expect 175.00 — re-billed onto a live invoice)");

    // ── T3: sweep+wash+fuel closeout lines on an adjustment invoice ──
    // Mirror close.php's builder: sweep flat, wash flat, fuel = gallons × rate.
    $fuelRate = (string) settings_get('lease.fuel_rate_per_gallon', '13.00');
    $gallons  = '10.00';
    $fuelAmt  = bcround(bcmul($gallons, $fuelRate, 6), 2);
    $closeout = [
        ['item_type'=>'sweep','description'=>'Sweep out','quantity'=>'1.0000','unit_price'=>'30.00','amount'=>'30.00','taxable'=>1],
        ['item_type'=>'wash', 'description'=>'Wash out', 'quantity'=>'1.0000','unit_price'=>'120.00','amount'=>'120.00','taxable'=>1],
        ['item_type'=>'fuel', 'description'=>'Fuel charge','quantity'=>$gallons,'unit'=>'gallons','unit_price'=>$fuelRate,'amount'=>$fuelAmt,'taxable'=>1],
    ];
    $ls = db_insert('leases', [
        'contract_number' => 'SMOKE-SVC-CLOSEOUT', 'customer_id' => $cust, 'equipment_unit_id' => $unit,
        'start_date' => '2026-05-01', 'status' => 'active',
        'daily_rate' => '10.00', 'weekly_rate' => '60.00', 'monthly_rate' => '250.00',
        'currency' => 'CAD', 'billing_cycle' => 'monthly', 'created_by' => $user, 'updated_by' => $user,
    ]);
    $adj = $gen->createFromLease(['lease_id'=>$ls,'period_start'=>'2026-05-31','period_end'=>'2026-05-31',
        'billing_type'=>'adjustment','invoice_type'=>'final','created_by'=>$user,'extra_lines'=>$closeout]);
    $sw = $lineOf($adj['invoice_id'], 'sweep');
    $wa = $lineOf($adj['invoice_id'], 'wash');
    $fu = $lineOf($adj['invoice_id'], 'fuel');
    $base = $lineOf($adj['invoice_id'], 'base_rental');
    check('T3', 'sweep/wash/fuel lines on adjustment invoice (no base rental)',
        $sw && $sw['amount'] === '30.00' && $wa && $wa['amount'] === '120.00'
        && $fu && $fu['amount'] === '130.00' && $fu['unit'] === 'gallons' && $base === null,
        "sweep=" . ($sw['amount'] ?? 'none') . " wash=" . ($wa['amount'] ?? 'none')
        . " fuel=" . ($fu['amount'] ?? 'none') . " base=" . ($base ? 'present' : 'absent')
        . " (expect 30/120/130 / no base)");

    // ── T4: show-API closeout aggregation returns the billed amounts ──
    // Mirrors the query in api/v1/leases/show.php that feeds lease.closeout_charges.
    $agg = [];
    foreach (db_select(
        "SELECT li.item_type, SUM(li.amount) AS total
           FROM invoice_line_items li
           JOIN invoices i ON i.id = li.invoice_id
          WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
            AND li.item_type IN ('sweep','wash','fuel','cartage')
          GROUP BY li.item_type", [$ls]) as $r) {
        $agg[$r['item_type']] = (string) $r['total'];
    }
    check('T4', 'show-API closeout aggregation',
        ($agg['sweep'] ?? null) === '30.00' && ($agg['wash'] ?? null) === '120.00' && ($agg['fuel'] ?? null) === '130.00',
        "agg sweep=" . ($agg['sweep'] ?? 'null') . " wash=" . ($agg['wash'] ?? 'null') . " fuel=" . ($agg['fuel'] ?? 'null') . " (expect 30/120/130)");

    db_execute("ROLLBACK");
} catch (\Throwable $e) {
    db_execute("ROLLBACK");
    echo "  FATAL — " . $e->getMessage() . "\n";
    $fail++;
}

echo str_repeat('=', 70) . "\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
