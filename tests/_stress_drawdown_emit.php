<?php
declare(strict_types=1);

/**
 * tests/_stress_drawdown_emit.php
 *
 * S-MILEAGE-2B C3 stress test for the InvoiceGenerator drawdown emit
 * logic (D-B) — five cases covering both branches + Model B Lite.
 *
 * Locked D-B gate (per locked spec + K-16 mid-arc clarification):
 *   period_distance_km > 0
 *   AND mileage_rate_km > 0
 *   AND billing_type NOT IN ('mileage_only','adjustment','credit_note')
 *   AND (precharge_invoiced_at IS NOT NULL OR precharge_enabled = 0)
 *
 * Branch A: precharge_balance > 0 → mileage_usage + mileage_drawdown_credit
 * Branch B: precharge_balance == 0 or NULL → only mileage_usage
 *
 * K-16 convention: mileage_drawdown_credit emits with POSITIVE amount +
 * is_credit=1 to match InvoiceGenerator's signed-aggregator at lines
 * ~357-362 (bcsub on is_credit=1).
 *
 * Hermetic via BEGIN/ROLLBACK; no production drift. samsara skipped
 * via skip_samsara=1 param so the C3 D-C Samsara fallback doesn't fire.
 *
 * Decisions: D-B (drawdown shape + gate), D-C (Samsara opt-out),
 *            D135 (three-config matrix — Branch B for Model B Lite)
 * Spec ref:  S-MILEAGE-2B spec in FLEETFORGE_CURRENT_SESSIONS.md
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;

$results = [];

function recordCase(array &$results, string $case, bool $ok, string $msg): void {
    $results[] = ['case' => $case, 'ok' => $ok, 'msg' => $msg];
    $tag = $ok ? 'PASS' : 'FAIL';
    printf("  %s %s — %s\n", $tag, $case, $msg);
}

// Resolve FK parents
$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$unitId     = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

if ($customerId === 0 || $unitId === 0 || $userId === 0) {
    echo "FAIL — cannot resolve FK parents (customer=$customerId, unit=$unitId, user=$userId)\n";
    exit(1);
}

echo "S-MILEAGE-2B C3 — _stress_drawdown_emit.php\n";
echo str_repeat('═', 76), "\n";

$periodStart = '2026-04-01';
$periodEnd   = '2026-04-30';

$makeLease = function (string $suffix, int $enabled, ?string $amount, ?string $balance, ?string $invoicedAt) use ($customerId, $unitId, $userId): int {
    return db_insert('leases', [
        'contract_number'         => 'STRESS-2B-C3-' . $suffix,
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'start_date'              => '2026-01-01',
        'status'                  => 'active',
        'daily_rate'              => '50.00',
        'weekly_rate'             => '300.00',
        'monthly_rate'            => '1000.00',
        'mileage_rate_km'         => '0.18',
        'mileage_unit'            => 'km',
        'estimated_mileage_km'    => '0',
        'currency'                => 'CAD',
        'billing_cycle'           => 'monthly',
        'odometer_start_km'       => '0.00',
        'precharge_enabled'       => $enabled,
        'precharge_amount'        => $amount,
        'precharge_balance'       => $balance,
        'precharge_invoiced_at'   => $invoicedAt,
        'created_by'              => $userId,
        'updated_by'              => $userId,
    ]);
};

db_execute('BEGIN');
try {
    $gen = new InvoiceGenerator();

    // ─── Case (a): precharge_balance (500) > period_charge (18)
    $leaseA = $makeLease('a', 1, '500.00', '500.00', '2026-03-31 12:00:00');
    $resA = $gen->createFromLease([
        'lease_id'                    => $leaseA,
        'period_start'                => $periodStart,
        'period_end'                  => $periodEnd,
        'billing_type'                => 'full_month',
        'invoice_type'                => 'regular',
        'odometer_at_period_start_km' => '0.00',
        'odometer_at_period_end_km'   => '100.00',
        'skip_samsara'                => 1,
        'created_by'                  => $userId,
    ]);
    $usageA  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$resA['invoice_id']]);
    $creditA = db_row("SELECT amount, is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$resA['invoice_id']]);
    $leaseAPost = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$leaseA]);
    $okA = $usageA && bccomp($usageA['amount'], '18.00', 2) === 0
        && $creditA && bccomp($creditA['amount'], '18.00', 2) === 0 && (int) $creditA['is_credit'] === 1
        && $leaseAPost && bccomp($leaseAPost['precharge_balance'], '482.00', 2) === 0;
    recordCase($results, 'a balance(500) > charge(18)', $okA,
        sprintf('usage=%s credit=%s/is_credit=%s post=%s (expect 18/18/1/482)',
            $usageA['amount'] ?? 'null',
            $creditA['amount'] ?? 'null',
            $creditA['is_credit'] ?? 'null',
            $leaseAPost['precharge_balance'] ?? 'null'));

    // ─── Case (b): precharge_balance (18) == period_charge (18)
    $leaseB = $makeLease('b', 1, '18.00', '18.00', '2026-03-31 12:00:00');
    $resB = $gen->createFromLease([
        'lease_id'                    => $leaseB,
        'period_start'                => $periodStart,
        'period_end'                  => $periodEnd,
        'billing_type'                => 'full_month',
        'invoice_type'                => 'regular',
        'odometer_at_period_start_km' => '0.00',
        'odometer_at_period_end_km'   => '100.00',
        'skip_samsara'                => 1,
        'created_by'                  => $userId,
    ]);
    $creditB = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$resB['invoice_id']]);
    $leaseBPost = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$leaseB]);
    $okB = $creditB && bccomp($creditB['amount'], '18.00', 2) === 0
        && $leaseBPost && bccomp($leaseBPost['precharge_balance'], '0.00', 2) === 0;
    recordCase($results, 'b balance(18) == charge(18)', $okB,
        sprintf('credit=%s post=%s (expect 18/0)',
            $creditB['amount'] ?? 'null',
            $leaseBPost['precharge_balance'] ?? 'null'));

    // ─── Case (c): precharge_balance (10) < period_charge (18)
    $leaseC = $makeLease('c', 1, '10.00', '10.00', '2026-03-31 12:00:00');
    $resC = $gen->createFromLease([
        'lease_id'                    => $leaseC,
        'period_start'                => $periodStart,
        'period_end'                  => $periodEnd,
        'billing_type'                => 'full_month',
        'invoice_type'                => 'regular',
        'odometer_at_period_start_km' => '0.00',
        'odometer_at_period_end_km'   => '100.00',
        'skip_samsara'                => 1,
        'created_by'                  => $userId,
    ]);
    $usageC  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$resC['invoice_id']]);
    $creditC = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$resC['invoice_id']]);
    $leaseCPost = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$leaseC]);
    $okC = $usageC && bccomp($usageC['amount'], '18.00', 2) === 0
        && $creditC && bccomp($creditC['amount'], '10.00', 2) === 0
        && $leaseCPost && bccomp($leaseCPost['precharge_balance'], '0.00', 2) === 0;
    recordCase($results, 'c balance(10) < charge(18)', $okC,
        sprintf('usage=%s credit=%s post=%s (expect 18/10/0)',
            $usageC['amount'] ?? 'null',
            $creditC['amount'] ?? 'null',
            $leaseCPost['precharge_balance'] ?? 'null'));

    // ─── Case (d): precharge_balance == 0 → only usage line
    $leaseD = $makeLease('d', 1, '500.00', '0.00', '2026-03-31 12:00:00');
    $resD = $gen->createFromLease([
        'lease_id'                    => $leaseD,
        'period_start'                => $periodStart,
        'period_end'                  => $periodEnd,
        'billing_type'                => 'full_month',
        'invoice_type'                => 'regular',
        'odometer_at_period_start_km' => '0.00',
        'odometer_at_period_end_km'   => '100.00',
        'skip_samsara'                => 1,
        'created_by'                  => $userId,
    ]);
    $usageD  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$resD['invoice_id']]);
    $creditD = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$resD['invoice_id']]);
    $leaseDPost = db_row("SELECT precharge_balance FROM leases WHERE id=?", [$leaseD]);
    $okD = $usageD && bccomp($usageD['amount'], '18.00', 2) === 0
        && $creditD === null
        && $leaseDPost && bccomp($leaseDPost['precharge_balance'], '0.00', 2) === 0;
    recordCase($results, 'd balance==0 (no credit)', $okD,
        sprintf('usage=%s credit=%s post=%s (expect 18/null/0)',
            $usageD['amount'] ?? 'null',
            $creditD['amount'] ?? 'null',
            $leaseDPost['precharge_balance'] ?? 'null'));

    // ─── Case (e): Model B Lite (precharge_enabled=0) → only usage line
    $leaseE = $makeLease('e', 0, null, null, null);
    $resE = $gen->createFromLease([
        'lease_id'                    => $leaseE,
        'period_start'                => $periodStart,
        'period_end'                  => $periodEnd,
        'billing_type'                => 'full_month',
        'invoice_type'                => 'regular',
        'odometer_at_period_start_km' => '0.00',
        'odometer_at_period_end_km'   => '100.00',
        'skip_samsara'                => 1,
        'created_by'                  => $userId,
    ]);
    $usageE  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$resE['invoice_id']]);
    $creditE = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$resE['invoice_id']]);
    $okE = $usageE && bccomp($usageE['amount'], '18.00', 2) === 0
        && $creditE === null;
    recordCase($results, 'e Model B Lite (enabled=0)', $okE,
        sprintf('usage=%s credit=%s (expect 18/null)',
            $usageE['amount'] ?? 'null',
            $creditE['amount'] ?? 'null'));

} catch (\Throwable $e) {
    recordCase($results, 'EXCEPTION', false, $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
} finally {
    db_execute('ROLLBACK');
}

// Rollback cleanliness cross-check
$leakRow = db_row("SELECT COUNT(*) AS c FROM leases WHERE contract_number LIKE 'STRESS-2B-C3-%'", []);
recordCase($results, 'R rollback cleanliness', ((int) $leakRow['c']) === 0,
    sprintf('leaked leases=%d (expect 0)', (int) $leakRow['c']));

$passCount = count(array_filter($results, fn($r) => $r['ok']));
$total = count($results);
echo "\n" . sprintf("%d/%d passed%s\n", $passCount, $total, $passCount === $total ? '' : ' (FAILED)');
exit($passCount === $total ? 0 : 1);
