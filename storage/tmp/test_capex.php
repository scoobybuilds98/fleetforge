<?php
// CapEx workflow test — "New Trailer Purchase $60,000"
// Walk through: create → approve → in_progress → complete (with linked asset)
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

use FleetForge\Accounting\FixedAssetService;

$ids = json_decode(file_get_contents(sys_get_temp_dir() . '/ff_test_assets.json'), true);
$userId = (int) $ids['user_id'];

function check(string $label, bool $pass, string $extra = ''): void {
    $marker = $pass ? '[PASS]' : '[FAIL]';
    echo "  $marker  $label" . ($extra ? "  ($extra)" : '') . "\n";
}
function header_print(string $t): void { echo "\n========== $t ==========\n"; }

// ============================================================
// Step 1: Create CapEx (status → pending_approval)
// ============================================================
header_print('Step 1: Create CapEx — New Trailer Purchase $60,000');
$capex = FixedAssetService::createCapex([
    'title'         => 'New Trailer Purchase',
    'description'   => 'Replace decommissioned trailer with newer model',
    'asset_class'   => 'fleet_equipment',
    'budget_amount' => '60000.00',
    'justification' => 'TEST: Increased capacity for Q3 contracts',
], $userId);
echo "  capex id={$capex['id']}  number={$capex['request_number']}  status={$capex['status']}\n";
echo "  budget={$capex['budget_amount']}  asset_class={$capex['asset_class']}\n";
check('capex created',                  !empty($capex['id']));
check('request_number assigned',        !empty($capex['request_number']));
check('budget = 60000.00',              $capex['budget_amount']  === '60000.00');
check('status = pending_approval',      $capex['status']         === 'pending_approval');
check('asset_class = fleet_equipment',  $capex['asset_class']    === 'fleet_equipment');
check('requested_by recorded',          (int) $capex['requested_by'] === $userId);

// ============================================================
// Step 2: Try to approve as accountant — should be blocked
// ============================================================
header_print('Step 2a: Accountant cannot approve CapEx');
try {
    FixedAssetService::approveCapex((int) $capex['id'], $userId, 'accountant');
    check('blocked accountant approval', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked accountant approval', true);
}

// ============================================================
// Step 2b: Approve as manager
// ============================================================
header_print('Step 2b: Manager approves CapEx');
$approved = FixedAssetService::approveCapex((int) $capex['id'], $userId, 'manager');
echo "  status={$approved['status']}  approved_by={$approved['approved_by']}  at={$approved['approved_at']}\n";
check('status = approved',          $approved['status']           === 'approved');
check('approved_by recorded',       (int) $approved['approved_by'] === $userId);
check('approved_at recorded',       !empty($approved['approved_at']));

// ============================================================
// Step 2c: Cannot approve twice
// ============================================================
header_print('Step 2c: Cannot approve twice');
try {
    FixedAssetService::approveCapex((int) $capex['id'], $userId, 'manager');
    check('blocked re-approval', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked re-approval', true);
}

// ============================================================
// Step 3: Move to in_progress
// ============================================================
header_print('Step 3: Move to in_progress');
$inProgress = FixedAssetService::startCapex((int) $capex['id'], $userId);
echo "  status={$inProgress['status']}\n";
check('status = in_progress', $inProgress['status'] === 'in_progress');

// Cannot start an already-started one
try {
    FixedAssetService::startCapex((int) $capex['id'], $userId);
    check('blocked re-start', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked re-start', true);
}

// ============================================================
// Step 4: Complete CapEx with linked asset creation
//
// User asked for: "When complete, generate fixed asset record automatically
// from CapEx with all values pre-filled"
// We test that pattern by passing asset_data to completeCapex().
// ============================================================
header_print('Step 4: Complete CapEx and auto-create linked asset');
$completed = FixedAssetService::completeCapex((int) $capex['id'], [
    'actual_amount' => '58500.00',  // came in $1,500 under budget
    'asset_data' => [
        'name'                    => 'TEST CapEx Trailer (auto)',
        'asset_class'             => 'fleet_equipment',
        'description'             => 'Auto-created from CapEx',
        'acquisition_date'        => '2026-04-01',
        'depreciation_start_date' => '2026-04-01',
        'acquisition_cost'        => '58500.00',
        'salvage_value'           => '5000.00',
        'useful_life_years'       => '10',
        'depreciation_method'     => 'straight_line',
        'asset_account_id'        => $ids['accounts']['fleet_cost'],
        'accum_depr_account_id'   => $ids['accounts']['fleet_accum'],
        'depr_expense_account_id' => $ids['accounts']['depr_fleet'],
    ],
], $userId);
echo "  status={$completed['status']}  actual_amount={$completed['actual_amount']}  asset_id={$completed['asset_id']}\n";
check('status = completed',           $completed['status']        === 'completed');
check('actual_amount = 58500.00',     $completed['actual_amount'] === '58500.00');
check('asset linked',                 !empty($completed['asset_id']));
check('completed_by recorded',        (int) $completed['completed_by'] === $userId);

// Verify the linked asset
$linked = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$completed['asset_id']]);
echo "  linked asset: #{$linked['id']} {$linked['asset_number']} {$linked['name']}\n";
echo "    cost={$linked['acquisition_cost']}  salvage={$linked['salvage_value']}  method={$linked['depreciation_method']}\n";
check('linked asset cost = 58500.00',     $linked['acquisition_cost']    === '58500.00');
check('linked asset salvage = 5000.00',   $linked['salvage_value']       === '5000.00');
check('linked asset method = straight_line', $linked['depreciation_method'] === 'straight_line');
check('linked asset depreciable = 53500.00', $linked['depreciable_cost']  === '53500.00');

// ============================================================
// Step 5: Cannot complete twice
// ============================================================
header_print('Step 5: Cannot complete twice');
try {
    FixedAssetService::completeCapex((int) $capex['id'], [
        'actual_amount' => '99999.00',
    ], $userId);
    check('blocked re-completion', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked re-completion', true);
}

// ============================================================
// Step 6: Variance analysis (budget vs actual)
// ============================================================
header_print('Step 6: Budget vs actual variance');
$variance  = bcsub($completed['budget_amount'], $completed['actual_amount'], 2);
$variancePct = bcmul(bcdiv($variance, $completed['budget_amount'], 6), '100', 2);
echo "  budget=60000.00  actual={$completed['actual_amount']}  variance=$variance ($variancePct%)\n";
check('variance = 1500.00 (under budget)', $variance === '1500.00');

echo "\n=== CAPEX TESTS COMPLETE ===\n";
