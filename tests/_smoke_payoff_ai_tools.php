<?php declare(strict_types=1);

/**
 * tests/_smoke_payoff_ai_tools.php
 *
 * Sanity check for the two new AI tools added in PAYOFF-1:
 *   - get_fixed_asset_details   — by id, asset_number, and unit_number
 *   - get_payoff_analysis       — by unit_number (the user's exact failure mode)
 *
 * Both tools route through ToolRegistry::execute() so we exercise the
 * full dispatcher → handler → JSON-encode pipeline that the chat
 * endpoint hits at runtime.
 *
 * Usage:
 *   php tests/_smoke_payoff_ai_tools.php
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/auth.php';

// Stub a super-admin session so canViewFinancials() returns true.
$_SESSION['ff_user'] = [
    'id'          => 1,
    'name'        => 'Smoke Admin',
    'email'       => 'smoke@fleetforge.test',
    'role_id'     => 1,
    'role_slug'   => 'super_admin',
    'permissions' => [],
    'theme'       => 'dark',
];
$_SESSION['ff_last_activity'] = time();

// ── Pick a unit that has a linked asset (the seeder ran 12 of them) ──
$pick = db_row(
    "SELECT eu.unit_number, a.id AS asset_id, a.asset_number
       FROM acc_fixed_assets a
       JOIN equipment_units eu ON eu.id = a.equipment_unit_id
      WHERE a.equipment_unit_id IS NOT NULL
        AND a.status != 'disposed'
        AND eu.deleted_at IS NULL
      ORDER BY eu.id ASC
      LIMIT 1"
);

if (!$pick) {
    fwrite(STDERR, "no linked assets found — run scripts/seed_payoff_data.php --apply first\n");
    exit(1);
}

// Also pick a CHS-* unit if we can — that's the user's literal example.
$chs = db_row(
    "SELECT eu.unit_number, a.id AS asset_id, a.asset_number
       FROM acc_fixed_assets a
       JOIN equipment_units eu ON eu.id = a.equipment_unit_id
      WHERE a.equipment_unit_id IS NOT NULL
        AND a.status != 'disposed'
        AND eu.deleted_at IS NULL
        AND eu.unit_number LIKE 'CHS-%'
      ORDER BY eu.id ASC
      LIMIT 1"
);

$cases = [
    [
        'label' => 'get_fixed_asset_details by asset_id',
        'tool'  => 'get_fixed_asset_details',
        'input' => ['asset_id' => (int) $pick['asset_id']],
        'expect_keys' => ['id', 'asset_number', 'name', 'equipment_unit_number', 'acquisition_cost'],
    ],
    [
        'label' => 'get_fixed_asset_details by asset_number',
        'tool'  => 'get_fixed_asset_details',
        'input' => ['asset_number' => $pick['asset_number']],
        'expect_keys' => ['id', 'asset_number', 'equipment_unit_number'],
    ],
    [
        'label' => 'get_fixed_asset_details by unit_number',
        'tool'  => 'get_fixed_asset_details',
        'input' => ['unit_number' => $pick['unit_number']],
        'expect_keys' => ['id', 'asset_number', 'equipment_unit_number'],
    ],
    [
        'label' => 'get_payoff_analysis by unit_number',
        'tool'  => 'get_payoff_analysis',
        'input' => ['unit_number' => $pick['unit_number'], 'period' => 6],
        'expect_keys' => ['asset', 'invested', 'totals', 'scenarios', 'projection'],
    ],
    [
        'label' => 'get_payoff_analysis by asset_id (period=12)',
        'tool'  => 'get_payoff_analysis',
        'input' => ['asset_id' => (int) $pick['asset_id'], 'period' => 12],
        'expect_keys' => ['asset', 'invested', 'totals', 'scenarios', 'projection'],
    ],
    [
        'label' => 'get_payoff_analysis with bogus unit_number (expect helpful error)',
        'tool'  => 'get_payoff_analysis',
        'input' => ['unit_number' => 'NOPE-999'],
        'expect_string_contains' => 'No equipment unit found',
    ],
];

if ($chs) {
    array_splice($cases, 4, 0, [[
        'label' => "get_payoff_analysis by unit_number={$chs['unit_number']} (user's exact case)",
        'tool'  => 'get_payoff_analysis',
        'input' => ['unit_number' => $chs['unit_number']],
        'expect_keys' => ['asset', 'invested', 'totals', 'scenarios', 'projection'],
    ]]);
}

echo "\n=== payoff AI-tool smoke test ===\n";
echo "seed pick: unit={$pick['unit_number']}  asset={$pick['asset_number']} (id={$pick['asset_id']})\n";
if ($chs) echo "CHS pick:  unit={$chs['unit_number']} asset={$chs['asset_number']} (id={$chs['asset_id']})\n";
echo str_repeat('-', 100) . "\n";

$pass = 0;
foreach ($cases as $case) {
    $result = \FleetForge\AI\ToolRegistry::execute(
        $case['tool'],
        $case['input'],
        1
    );

    // Check for substring expectation (error path).
    if (isset($case['expect_string_contains'])) {
        if (str_contains($result, $case['expect_string_contains'])) {
            echo sprintf("[PASS] %s\n", $case['label']);
            $pass++;
        } else {
            echo sprintf("[FAIL] %s\n        expected substring: %s\n        got: %s\n",
                $case['label'], $case['expect_string_contains'], substr($result, 0, 200));
        }
        continue;
    }

    // Otherwise the result must be a JSON object containing every expected key.
    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        echo sprintf("[FAIL] %s\n        result was not JSON: %s\n",
            $case['label'], substr($result, 0, 200));
        continue;
    }

    $missing = [];
    foreach ($case['expect_keys'] as $k) {
        if (!array_key_exists($k, $decoded)) $missing[] = $k;
    }
    if ($missing) {
        echo sprintf("[FAIL] %s\n        missing keys: %s\n        got keys: %s\n",
            $case['label'], implode(',', $missing), implode(',', array_keys($decoded)));
        continue;
    }

    // Friendly summary line for the payoff cases.
    if ($case['tool'] === 'get_payoff_analysis') {
        $a = $decoded['asset']      ?? [];
        $i = $decoded['invested']   ?? [];
        $t = $decoded['totals']     ?? [];
        $p = $decoded['projection'] ?? [];
        echo sprintf("[PASS] %s\n        unit=%s invested=\$%s net=\$%s remaining=\$%s prog=%s%% proj=%s mo (%s)\n",
            $case['label'],
            $a['equipment_unit_number'] ?? '?',
            $i['total_invested']        ?? '?',
            $t['net_revenue_to_date']   ?? '?',
            $t['still_to_recover']      ?? '?',
            $t['progress_pct']          ?? '?',
            $p['projected_months']      ?? '—',
            $p['projected_date']        ?? '—'
        );
    } else {
        echo sprintf("[PASS] %s  (asset_number=%s unit=%s)\n",
            $case['label'],
            $decoded['asset_number']         ?? '?',
            $decoded['equipment_unit_number']?? '?'
        );
    }
    $pass++;
}

echo str_repeat('-', 100) . "\n";
echo "$pass / " . count($cases) . " cases passed\n";
exit($pass === count($cases) ? 0 : 1);
