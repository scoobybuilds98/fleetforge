<?php
declare(strict_types=1);

/**
 * tests/_stress_activate_precharge_init.php
 *
 * S-MILEAGE-2A C2 — stress test for the precharge_balance init UPDATE
 * guard in api/v1/leases/activate.php. The activate endpoint's state
 * machine already prevents real re-activation (status='pending' check),
 * so this test exercises the inner guard as defense-in-depth: even if
 * the inner UPDATE re-runs on already-init'd / non-precharge / opted-out
 * leases, the WHERE clause MUST filter them out.
 *
 * 5 cases, hermetic via single transaction + ROLLBACK at end:
 *   A — precharge_enabled=1, amount=$500, balance=NULL  → balance becomes $500.00
 *   B — precharge_enabled=1, amount=$500, balance=$200  → balance stays $200.00
 *   C — precharge_enabled=0, amount=NULL, balance=NULL  → balance stays NULL
 *   D — precharge_enabled=1, amount=$750, balance=NULL  → balance becomes $750.00
 *   E — precharge_enabled=1, amount=$500, balance=$500  → balance stays $500.00
 *                                                       (idempotent re-run shape)
 *
 * Exit code: 0 on all-pass, 1 on any-fail.
 *
 * Usage: php tests/_stress_activate_precharge_init.php
 *
 * Decisions: D-A (precharge_balance init at activation), D20 (FOR UPDATE)
 * Spec ref:  S-MILEAGE-2A spec in FLEETFORGE_CURRENT_SESSIONS.md
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$tStart = microtime(true);
$results = []; // [caseId => [passed: bool, msg: string]]

/**
 * Record a case result + render a line.
 */
function recordCase(array &$results, string $id, string $name, bool $passed, string $msg): void
{
    $results[$id] = ['name' => $name, 'passed' => $passed, 'msg' => $msg];
    $tag = $passed ? 'PASS' : 'FAIL';
    printf("  %s %s %-40s — %s\n", $tag, $id, $name, $msg);
}

// ── Pick a valid customer + unit + user for FK references ───
$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$unitId     = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

if ($customerId === 0 || $unitId === 0 || $userId === 0) {
    echo "FAIL — cannot resolve FK parents (customer=$customerId, unit=$unitId, user=$userId)\n";
    exit(1);
}

echo "S-MILEAGE-2A C2 — precharge_balance init guard stress test\n";
echo "  FK refs: customer_id=$customerId, unit_id=$unitId, user_id=$userId\n";
echo str_repeat('═', 64), "\n";

// ── BEGIN transaction; insert + test + ROLLBACK at end ──────
db_execute("BEGIN");

try {
    /**
     * Insert a synthetic pending lease with the given precharge tuple
     * and return its new id.
     */
    $makeLease = function (int $customerId, int $unitId, int $userId, int $enabled, ?string $amount, ?string $balance, string $contractSuffix): int {
        return db_insert('leases', [
            'contract_number'     => 'STRESS-2A-' . $contractSuffix,
            'customer_id'         => $customerId,
            'equipment_unit_id'   => $unitId,
            'start_date'          => date('Y-m-d'),
            'status'              => 'pending',
            'daily_rate'          => '10.00',
            'weekly_rate'         => '60.00',
            'monthly_rate'        => '250.00',
            'currency'            => 'CAD',
            'billing_cycle'       => 'monthly',
            'precharge_enabled'   => $enabled,
            'precharge_amount'    => $amount,
            'precharge_balance'   => $balance,
            'created_by'          => $userId,
            'updated_by'          => $userId,
        ]);
    };

    /**
     * Run the same precharge_balance init UPDATE that
     * api/v1/leases/activate.php executes, and return rows-affected.
     */
    $runInitGuard = function (int $leaseId, int $userId): int {
        return db_execute(
            "UPDATE leases
                SET precharge_balance = precharge_amount,
                    updated_at = NOW(),
                    updated_by = ?
              WHERE id = ?
                AND precharge_enabled = 1
                AND precharge_amount IS NOT NULL
                AND precharge_balance IS NULL",
            [$userId, $leaseId]
        );
    };

    /** Read precharge_balance back as ?string (NULL preserved). */
    $readBalance = function (int $leaseId): ?string {
        $r = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);
        return $r['precharge_balance'] !== null ? (string) $r['precharge_balance'] : null;
    };

    // === Case A — NULL balance → init fires ===
    $a = $makeLease($customerId, $unitId, $userId, 1, '500.00', null, 'A');
    $aRows = $runInitGuard($a, $userId);
    $aBal  = $readBalance($a);
    recordCase($results, 'A', 'enabled=1, amt=500, bal=NULL',
        ($aRows === 1 && $aBal === '500.00'),
        sprintf("rows=%d balance=%s (expected rows=1 balance=500.00)", $aRows, $aBal ?? 'NULL'));

    // === Case B — non-NULL balance → guard blocks ===
    $b = $makeLease($customerId, $unitId, $userId, 1, '500.00', '200.00', 'B');
    $bRows = $runInitGuard($b, $userId);
    $bBal  = $readBalance($b);
    recordCase($results, 'B', 'enabled=1, amt=500, bal=200',
        ($bRows === 0 && $bBal === '200.00'),
        sprintf("rows=%d balance=%s (expected rows=0 balance=200.00)", $bRows, $bBal ?? 'NULL'));

    // === Case C — precharge_enabled=0 → guard blocks ===
    $c = $makeLease($customerId, $unitId, $userId, 0, null, null, 'C');
    $cRows = $runInitGuard($c, $userId);
    $cBal  = $readBalance($c);
    recordCase($results, 'C', 'enabled=0, amt=NULL, bal=NULL',
        ($cRows === 0 && $cBal === null),
        sprintf("rows=%d balance=%s (expected rows=0 balance=NULL)", $cRows, $cBal ?? 'NULL'));

    // === Case D — another NULL → init fires (different amount) ===
    $d = $makeLease($customerId, $unitId, $userId, 1, '750.00', null, 'D');
    $dRows = $runInitGuard($d, $userId);
    $dBal  = $readBalance($d);
    recordCase($results, 'D', 'enabled=1, amt=750, bal=NULL',
        ($dRows === 1 && $dBal === '750.00'),
        sprintf("rows=%d balance=%s (expected rows=1 balance=750.00)", $dRows, $dBal ?? 'NULL'));

    // === Case E — idempotent re-run: balance already = amount ===
    $e = $makeLease($customerId, $unitId, $userId, 1, '500.00', '500.00', 'E');
    $eRows = $runInitGuard($e, $userId);
    $eBal  = $readBalance($e);
    recordCase($results, 'E', 'enabled=1, amt=500, bal=500 (idempotent)',
        ($eRows === 0 && $eBal === '500.00'),
        sprintf("rows=%d balance=%s (expected rows=0 balance=500.00)", $eRows, $eBal ?? 'NULL'));

    // ── ROLLBACK to leave DB unchanged ─────────────────
    db_execute("ROLLBACK");
} catch (\Throwable $ex) {
    db_execute("ROLLBACK");
    echo "EXCEPTION — " . $ex->getMessage() . "\n";
    exit(1);
}

// ── Confirm rollback erased the test rows ──────────
$leakCount = (int) (db_row(
    "SELECT COUNT(*) AS n FROM leases WHERE contract_number LIKE 'STRESS-2A-%'"
)['n'] ?? -1);
$leakOk = ($leakCount === 0);
recordCase($results, 'R', 'rollback-leak-check (STRESS-2A-% rows gone)',
    $leakOk,
    sprintf("residual_count=%d (expected 0)", $leakCount));

// ── Summary ────────────────────────────────────────
$elapsed = microtime(true) - $tStart;
$pass    = array_sum(array_map(fn($r) => $r['passed'] ? 1 : 0, $results));
$total   = count($results);
$failed  = array_filter($results, fn($r) => !$r['passed']);

echo "\n";
if ($pass === $total) {
    printf("%d/%d passed in %.1fs\n", $pass, $total, $elapsed);
    exit(0);
}

$failList = [];
foreach ($failed as $id => $row) {
    $failList[] = "{$id} {$row['name']}";
}
printf("%d/%d passed (FAILED: %s) in %.1fs\n", $pass, $total, implode(', ', $failList), $elapsed);
exit(1);
