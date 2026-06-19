<?php
/**
 * _smoke_lease_hourly_billing.php — S-LEASE-HOURLY-BILLING
 *
 * Proves engine/reefer-hours billing (manual entry) actually consumes
 * leases.hourly_rate: when an hourly rate is set and the caller supplies the
 * period's start/end engine hours, InvoiceGenerator emits an `hourly_usage`
 * line = (end - start) × hourly_rate and snapshots the hours on the invoice.
 *
 * Cases:
 *   T1 hourly_rate=2.50 + hours 100→140 → hourly_usage line $100.00, period_engine_hours=40.
 *   T2 hourly_rate=0/NULL + hours present → NO hourly line (rate gate).
 *   T3 hourly_rate set but start==end (0 hrs) → NO line (zero-hours gate).
 *   T4 negative manual delta (end<start) → clamped to 0 hrs, NO line.
 *
 * Hermetic: all writes inside BEGIN/ROLLBACK.
 * Run: php tests/_smoke_lease_hourly_billing.php  (exit 0 PASS / 1 FAIL)
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

echo "FleetForge — engine-hours billing smoke (S-LEASE-HOURLY-BILLING)\n";
echo str_repeat('=', 70) . "\n";

db_execute("BEGIN");
try {
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) {
        throw new RuntimeException("Missing seed data (customer=$cust unit=$unit user=$user)");
    }

    // Lift the invoice counter above committed MAX (reverted by ROLLBACK).
    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 100)]
    );

    $makeLease = function (?string $hourlyRate, string $tag) use ($cust, $unit, $user): int {
        return db_insert('leases', [
            'contract_number'   => "SMOKE-HRLY-{$tag}",
            'customer_id'       => $cust,
            'equipment_unit_id' => $unit,
            'start_date'        => '2026-05-01',
            'status'            => 'active',
            'daily_rate'        => '10.00',
            'weekly_rate'       => '60.00',
            'monthly_rate'      => '250.00',
            'currency'          => 'CAD',
            'billing_cycle'     => 'monthly',
            'hourly_rate'       => $hourlyRate,
            'engine_hours_at_start' => '100.00',
            'created_by'        => $user,
            'updated_by'        => $user,
        ]);
    };

    $gen = new \FleetForge\Billing\InvoiceGenerator();
    $hourlyLine = function (int $invoiceId): ?array {
        return db_row(
            "SELECT amount, quantity, unit FROM invoice_line_items
              WHERE invoice_id = ? AND item_type = 'hourly_usage'",
            [$invoiceId]
        );
    };
    $gen2 = function (int $leaseId, $hStart, $hEnd) use ($gen, $user) {
        return $gen->createFromLease([
            'lease_id' => $leaseId, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
            'engine_hours_at_period_start' => $hStart,
            'engine_hours_at_period_end'   => $hEnd,
        ]);
    };

    // ── T1: rate set + 40 hours → hourly_usage line $100.00 ──
    $l1  = $makeLease('2.50', 'T1');
    $iv1 = $gen2($l1, '100.00', '140.00');
    $r1  = db_row("SELECT period_engine_hours FROM invoices WHERE id = ?", [$iv1['invoice_id']]);
    $ln1 = $hourlyLine($iv1['invoice_id']);
    check('T1', 'hourly_usage line billed',
        $ln1 !== null && $ln1['amount'] === '100.00' && (string) $r1['period_engine_hours'] === '40.00' && $ln1['unit'] === 'hours',
        "line_amount=" . ($ln1['amount'] ?? 'none') . " period_engine_hours=" . ($r1['period_engine_hours'] ?? 'null') . " (expect 100.00 / 40.00)");

    // ── T2: no hourly rate → no line even with hours ──
    $l2  = $makeLease(null, 'T2');
    $iv2 = $gen2($l2, '100.00', '140.00');
    $ln2 = $hourlyLine($iv2['invoice_id']);
    check('T2', 'no rate → no hourly line', $ln2 === null,
        "hourly_line=" . ($ln2 ? 'present' : 'absent') . " (expect absent)");

    // ── T3: rate set but zero hours (start==end) → no line ──
    $l3  = $makeLease('2.50', 'T3');
    $iv3 = $gen2($l3, '140.00', '140.00');
    $ln3 = $hourlyLine($iv3['invoice_id']);
    $r3  = db_row("SELECT period_engine_hours FROM invoices WHERE id = ?", [$iv3['invoice_id']]);
    check('T3', 'zero hours → no line', $ln3 === null && (string) $r3['period_engine_hours'] === '0.00',
        "hourly_line=" . ($ln3 ? 'present' : 'absent') . " period_engine_hours=" . ($r3['period_engine_hours'] ?? 'null') . " (expect absent / 0.00)");

    // ── T4: negative delta → clamped to 0, no line ──
    $l4  = $makeLease('2.50', 'T4');
    $iv4 = $gen2($l4, '200.00', '150.00');
    $ln4 = $hourlyLine($iv4['invoice_id']);
    $r4  = db_row("SELECT period_engine_hours FROM invoices WHERE id = ?", [$iv4['invoice_id']]);
    check('T4', 'negative delta clamped, no line', $ln4 === null && (string) $r4['period_engine_hours'] === '0.00',
        "hourly_line=" . ($ln4 ? 'present' : 'absent') . " period_engine_hours=" . ($r4['period_engine_hours'] ?? 'null') . " (expect absent / 0.00)");

    db_execute("ROLLBACK");
} catch (\Throwable $e) {
    db_execute("ROLLBACK");
    echo "  FATAL — " . $e->getMessage() . "\n";
    $fail++;
}

echo str_repeat('=', 70) . "\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
