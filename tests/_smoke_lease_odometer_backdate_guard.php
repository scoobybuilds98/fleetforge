<?php
/**
 * _smoke_lease_odometer_backdate_guard.php — S-ODO-VALIDATION
 *
 * Locks the fix for the "starting odometer > latest recorded" bug (prod MTTS82,
 * lease 72): a back-dated lease captured the CURRENT GPS odometer (17,112.59,
 * Jun-2026) as its STARTING reading, then a manually-entered closing reading
 * (12,198.00, the real Jan-2026 return) came in below it — and the engine
 * clamped the impossible negative distance to 0.00, silently saving start>end.
 *
 *   T1 — activation back-dating guard: a lease activated > 7 days after its
 *        start_date is "back-dated" → skip GPS start-capture (leave it manual);
 *        a lease activated on its start_date is not.
 *   T2 — close guard: a closing odometer below the starting odometer is rejected
 *        (bccomp(close, start) < 0); a closing >= start passes.
 *   T3 — detector: a reusable query that flags any non-void invoice whose
 *        period-end odometer sits below the lease's starting odometer (so the
 *        operator can find every affected record — there should be 0 in dev).
 *
 * Hermetic: all writes inside BEGIN/ROLLBACK.
 * Run: php tests/_smoke_lease_odometer_backdate_guard.php  (exit 0 PASS / 1 FAIL)
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

echo "FleetForge — odometer back-date / close guard smoke (S-ODO-VALIDATION)\n";
echo str_repeat('=', 70) . "\n";

// ── T1: activation back-dating guard (mirrors api/v1/leases/activate.php) ──
$graceDays = 7;
$isBackdated = function (string $startDate) use ($graceDays): bool {
    $ts = strtotime($startDate);
    return $ts !== false && (time() - $ts) > ($graceDays * 86400);
};
$yearAgo  = date('Y-m-d', strtotime('-327 days'));   // ≈ MTTS82's start→activation gap
$today    = date('Y-m-d');
$threeAgo = date('Y-m-d', strtotime('-3 days'));      // within grace (normal late activation)
check('T1', 'back-dated activation skips GPS capture',
    $isBackdated($yearAgo) === true && $isBackdated($today) === false && $isBackdated($threeAgo) === false,
    "yearAgo=" . ($isBackdated($yearAgo) ? 'skip' : 'capture')
    . " today=" . ($isBackdated($today) ? 'skip' : 'capture')
    . " 3d=" . ($isBackdated($threeAgo) ? 'skip' : 'capture') . " (expect skip/capture/capture)");

// ── T2: close guard (mirrors api/v1/leases/close.php S-ODO-VALIDATION) ──
$rejects = fn(string $close, string $start): bool => bccomp($close, $start, 2) < 0;
check('T2', 'close rejects closing < starting',
    $rejects('12198.00', '17112.59') === true && $rejects('17200.00', '17112.59') === false
        && $rejects('17112.59', '17112.59') === false,
    "12198<17112=" . ($rejects('12198.00','17112.59') ? 'reject' : 'allow')
    . " 17200=" . ($rejects('17200.00','17112.59') ? 'reject' : 'allow')
    . " equal=" . ($rejects('17112.59','17112.59') ? 'reject' : 'allow') . " (expect reject/allow/allow)");

// ── T3: detector query for existing bad records (hermetic) ──
db_execute("BEGIN");
try {
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) throw new RuntimeException("Missing seed data");

    // Bump the invoice counter above committed MAX (reverted by ROLLBACK).
    $yr = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 100)]);

    $lid = db_insert('leases', [
        'contract_number' => 'SMOKE-ODO-GUARD', 'customer_id' => $cust, 'equipment_unit_id' => $unit,
        'start_date' => '2025-07-26', 'status' => 'active',
        'daily_rate' => '10.00', 'weekly_rate' => '60.00', 'monthly_rate' => '250.00',
        'currency' => 'CAD', 'billing_cycle' => 'monthly',
        'mileage_tracking_mode' => 'manual',  // store the odometer snapshot (off mode would null it)
        'odometer_start_km' => '17112.59', 'odometer_start_source' => 'gps',
        'created_by' => $user, 'updated_by' => $user,
    ]);
    $gen = new \FleetForge\Billing\InvoiceGenerator();
    // BAD invoice: period-end odometer (12,198) BELOW the lease start (17,112) —
    // the engine clamps period/cumulative distance to 0 but stores the raw end.
    $badInv = $gen->createFromLease([
        'lease_id' => $lid, 'period_start' => '2025-08-01', 'period_end' => '2026-01-28',
        'billing_type' => 'partial_end', 'invoice_type' => 'final', 'created_by' => $user,
        'odometer_at_period_start_km' => '17112.59', 'odometer_at_period_end_km' => '12198.00',
        'odometer_source' => 'manual',
    ]);
    db_execute("UPDATE invoices SET invoice_number = 'SMOKE-ODO-BAD' WHERE id = ?", [$badInv['invoice_id']]);
    // OK invoice: period-end (17,200) above the lease start.
    $okInv = $gen->createFromLease([
        'lease_id' => $lid, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        'odometer_at_period_start_km' => '17112.59', 'odometer_at_period_end_km' => '17200.00',
        'odometer_source' => 'manual',
    ]);
    db_execute("UPDATE invoices SET invoice_number = 'SMOKE-ODO-OK' WHERE id = ?", [$okInv['invoice_id']]);

    $flagged = db_select(
        "SELECT i.invoice_number
           FROM invoices i JOIN leases l ON l.id = i.lease_id
          WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
            AND i.odometer_at_period_end_km IS NOT NULL AND l.odometer_start_km IS NOT NULL
            AND i.odometer_at_period_end_km < l.odometer_start_km",
        [$lid]
    );
    $nums = array_column($flagged, 'invoice_number');
    check('T3', 'detector flags only the impossible invoice',
        in_array('SMOKE-ODO-BAD', $nums, true) && !in_array('SMOKE-ODO-OK', $nums, true) && count($nums) === 1,
        "flagged=[" . implode(',', $nums) . "] (expect only SMOKE-ODO-BAD)");

    db_execute("ROLLBACK");
} catch (\Throwable $e) {
    db_execute("ROLLBACK");
    echo "  FATAL — " . $e->getMessage() . "\n";
    $fail++;
}

echo str_repeat('=', 70) . "\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
