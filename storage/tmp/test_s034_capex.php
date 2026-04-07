<?php
declare(strict_types=1);
require __DIR__ . '/../../config/app.php';

use FleetForge\Accounting\FixedAssetService;
use FleetForge\Accounting\AccountingService;

// ============================================================
// S034 — CAPEX REVIEW TESTS
// Verifies the work-order capitalization workflow:
//   1. Threshold detection (above/below)
//   2. Capitalize flagged WO → fixed asset + capex_requests row
//   3. Expense flagged WO → capex_requests row 'rejected'
//   4. Double-review prevention
//   5. Role gating
// ============================================================

function pass_c(string $label, string $expected, string $actual): bool
{
    if (is_numeric($expected) && is_numeric($actual)) {
        $ok = bccomp($expected, $actual, 2) === 0;
    } else {
        $ok = ($expected === $actual);
    }
    printf("  %s %s = %s %s %s\n",
        $ok ? '✅' : '❌', $label, $actual,
        $ok ? '==' : '!=', $expected);
    return $ok;
}

echo str_repeat('=', 70) . PHP_EOL;
echo "S034 — CAPEX REVIEW TESTS" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

// ── Pre-test: capex threshold setting ────────────────────────
$threshold = (string) AccountingService::setting('accounting.capex_threshold_cad', '2500.00');
echo "\nCapEx threshold (CAD): \$$threshold\n";

// ── Capture baseline flagged count BEFORE creating test WOs ──
$baselineFlagged = count(FixedAssetService::listFlaggedWorkOrders());
echo "Baseline flagged WO count (existing): $baselineFlagged\n";

// ── Create two test WOs: one above, one below threshold ──
echo "\n## Creating two test work orders\n\n";

// Above threshold ($8,500 — should flag)
$woAboveId = db_insert('maintenance_work_orders', [
    'work_order_number' => 'WO-CAPEX-TEST-A',
    'equipment_unit_id' => 5,           // existing unit
    'vendor_id'         => 1,
    'work_type'         => 'repair',
    'priority'          => 'high',
    'status'            => 'completed',
    'requested_date'    => '2026-03-10',
    'title'             => 'Engine rebuild — major overhaul',
    'description'       => 'Complete engine rebuild with new bearings, gaskets, machining',
    'completed_date'    => '2026-03-15',
    'labor_cost'        => '3500.00',
    'parts_cost'        => '5000.00',
    'total_cost'        => '8500.00',
    'created_by'        => 1,
    'completed_by'      => 1,
]);
echo "  Created WO #$woAboveId (above threshold) — total \$8,500.00\n";

// Below threshold ($1,800 — should NOT flag)
$woBelowId = db_insert('maintenance_work_orders', [
    'work_order_number' => 'WO-CAPEX-TEST-B',
    'equipment_unit_id' => 5,
    'vendor_id'         => 1,
    'work_type'         => 'repair',
    'priority'          => 'medium',
    'status'            => 'completed',
    'requested_date'    => '2026-03-10',
    'title'             => 'Brake pad replacement',
    'description'       => 'Routine brake pad replacement, all wheels',
    'completed_date'    => '2026-03-15',
    'labor_cost'        => '600.00',
    'parts_cost'        => '1200.00',
    'total_cost'        => '1800.00',
    'created_by'        => 1,
    'completed_by'      => 1,
]);
echo "  Created WO #$woBelowId (below threshold) — total \$1,800.00\n";

// ── Create a SECOND above-threshold WO so we have one to expense ──
$woAbove2Id = db_insert('maintenance_work_orders', [
    'work_order_number' => 'WO-CAPEX-TEST-C',
    'equipment_unit_id' => 5,
    'vendor_id'         => 1,
    'work_type'         => 'repair',
    'priority'          => 'medium',
    'status'            => 'completed',
    'requested_date'    => '2026-03-10',
    'title'             => 'Diagnostic + minor repairs',
    'description'       => 'Computer diagnostic, ECU update, minor sensor swaps',
    'completed_date'    => '2026-03-15',
    'labor_cost'        => '1500.00',
    'parts_cost'        => '1500.00',
    'total_cost'        => '3000.00',
    'created_by'        => 1,
    'completed_by'      => 1,
]);
echo "  Created WO #$woAbove2Id (above threshold) — total \$3,000.00\n";

// ============================================================
// TEST 1 — listFlaggedWorkOrders detects above-threshold WOs
// ============================================================
echo "\n## TEST 1 — listFlaggedWorkOrders correctly identifies above-threshold WOs\n\n";

$flagged = FixedAssetService::listFlaggedWorkOrders();
$delta = count($flagged) - $baselineFlagged;
echo "  Baseline: $baselineFlagged | Now: " . count($flagged) . " | Delta: +$delta\n";

$flaggedIds = array_column($flagged, 'id');
pass_c("Above-threshold WO #{$woAboveId} appears in flagged list",
    "1", in_array($woAboveId, $flaggedIds) ? "1" : "0");
pass_c("Above-threshold WO #{$woAbove2Id} appears in flagged list",
    "1", in_array($woAbove2Id, $flaggedIds) ? "1" : "0");
pass_c("Below-threshold WO #{$woBelowId} does NOT appear in flagged list",
    "1", !in_array($woBelowId, $flaggedIds) ? "1" : "0");

// Show the row details
foreach ($flagged as $f) {
    if (in_array((int) $f['id'], [$woAboveId, $woAbove2Id], true)) {
        printf("    Flagged: WO #%d %s | %s | unit %s | vendor %s | \$%s\n",
            $f['id'], $f['work_order_number'], $f['title'],
            $f['unit_number'] ?? '?', $f['vendor_name'] ?? '?', $f['total_cost']);
    }
}

// ============================================================
// TEST 2 — Capitalize WO → creates fixed asset + capex_requests
// ============================================================
echo "\n## TEST 2 — Capitalize flagged WO #{$woAboveId} into a fixed asset\n\n";

$capResult = FixedAssetService::capitalizeFlaggedWorkOrder(
    $woAboveId,
    [
        'name'                    => 'Engine Rebuild — Unit 1',
        'asset_class'             => 'fleet_equipment',
        'depreciation_method'     => 'straight_line',
        'useful_life_years'       => 7,
        'salvage_value'           => '0.00',
        'depreciation_start_date' => '2026-04-01',
        'asset_account_id'        => 12,
        'accum_depr_account_id'   => 13,
        'depr_expense_account_id' => 50,
    ],
    1,
    'super_admin'
);

printf("  Created CapEx request #%d, asset #%d (%s)\n",
    $capResult['capex_request']['id'],
    $capResult['asset']['id'],
    $capResult['asset']['asset_number']);

pass_c("Capex request status", "completed", $capResult['capex_request']['status']);
pass_c("Capex request budget = WO total", "8500.00", $capResult['capex_request']['budget_amount']);
pass_c("Capex request linked to WO", (string) $woAboveId, (string) $capResult['capex_request']['work_order_id']);
pass_c("Linked asset cost = WO total", "8500.00", $capResult['asset']['acquisition_cost']);
pass_c("Linked asset method", "straight_line", $capResult['asset']['depreciation_method']);
pass_c("Linked asset depreciation_start_date", "2026-04-01", $capResult['asset']['depreciation_start_date']);

// Verify the WO is no longer in the flagged list
$flagged2 = FixedAssetService::listFlaggedWorkOrders();
$flagged2Ids = array_column($flagged2, 'id');
pass_c("Capitalized WO removed from flagged list",
    "1", !in_array($woAboveId, $flagged2Ids) ? "1" : "0");

// ============================================================
// TEST 3 — Expense the second flagged WO
// ============================================================
echo "\n## TEST 3 — Expense flagged WO #{$woAbove2Id} (does not capitalize)\n\n";

$expResult = FixedAssetService::expenseFlaggedWorkOrder(
    $woAbove2Id,
    'Routine diagnostic and minor sensor work — does not extend useful life or add significant capability.',
    1,
    'super_admin'
);

printf("  Created CapEx request #%d (status=%s)\n", $expResult['id'], $expResult['status']);
pass_c("Expensed capex status", "rejected", $expResult['status']);
pass_c("Linked to WO", (string) $woAbove2Id, (string) $expResult['work_order_id']);
pass_c("Reason captured",
    "1",
    str_contains((string) $expResult['rejected_reason'], 'sensor') ? "1" : "0");
pass_c("No asset created (asset_id null)",
    "1",
    $expResult['asset_id'] === null ? "1" : "0");

// Verify also removed from flagged
$flagged3 = FixedAssetService::listFlaggedWorkOrders();
$flagged3Ids = array_column($flagged3, 'id');
pass_c("Expensed WO removed from flagged list",
    "1", !in_array($woAbove2Id, $flagged3Ids) ? "1" : "0");

// ============================================================
// TEST 4 — Double-review prevention
// ============================================================
echo "\n## TEST 4 — Cannot review the same WO twice\n\n";
try {
    FixedAssetService::capitalizeFlaggedWorkOrder($woAboveId, [
        'name'                    => 'Should fail',
        'asset_account_id'        => 12,
        'accum_depr_account_id'   => 13,
        'depr_expense_account_id' => 50,
    ], 1, 'super_admin');
    echo "  ❌ Should have thrown but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
}

try {
    FixedAssetService::expenseFlaggedWorkOrder($woAbove2Id, 'Already reviewed', 1, 'super_admin');
    echo "  ❌ Should have thrown but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
}

// ============================================================
// TEST 5 — Role gating
// ============================================================
echo "\n## TEST 5 — Driver role cannot review CapEx flags\n\n";
try {
    FixedAssetService::capitalizeFlaggedWorkOrder($woBelowId, [], 1, 'driver');
    echo "  ❌ Should have thrown role error but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Capitalize correctly blocked: " . $e->getMessage() . "\n";
}
try {
    FixedAssetService::expenseFlaggedWorkOrder($woBelowId, 'test', 1, 'driver');
    echo "  ❌ Should have thrown role error but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Expense correctly blocked: " . $e->getMessage() . "\n";
}

// ============================================================
// TEST 6 — Empty reason rejected on expense
// ============================================================
echo "\n## TEST 6 — Empty reason rejected on expense\n\n";
try {
    FixedAssetService::expenseFlaggedWorkOrder($woBelowId, '', 1, 'super_admin');
    echo "  ❌ Should have thrown but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
}

// ============================================================
// TEST 7 — Audit log captured
// ============================================================
echo "\n## TEST 7 — Audit trail for CapEx events\n\n";
$auditCount = db_count(
    "SELECT COUNT(*) FROM audit_log
     WHERE entity_type = 'capex_request'
     AND created_at >= NOW() - INTERVAL 5 MINUTE"
);
echo "  CapEx audit entries (last 5 min): $auditCount\n";
pass_c("Audit count >= 2 (capitalize + expense)",
    "1", $auditCount >= 2 ? "1" : "0");

// Save IDs for cleanup
file_put_contents(__DIR__ . '/test_s034_capex_ids.json', json_encode([
    'wo_above_id'    => $woAboveId,
    'wo_below_id'    => $woBelowId,
    'wo_above_2_id'  => $woAbove2Id,
    'capex_id'       => $capResult['capex_request']['id'],
    'expensed_id'    => $expResult['id'],
    'asset_id'       => $capResult['asset']['id'],
]));

echo "\n" . str_repeat('=', 70) . PHP_EOL;
echo "CAPEX REVIEW TESTS COMPLETE" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
