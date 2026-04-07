<?php declare(strict_types=1);

/**
 * scripts/seed_payoff_data.php
 *
 * Creates a fixed-asset row (acc_fixed_assets) for every active equipment
 * unit that doesn't already have one. Used to populate the Payoff
 * Calculator with realistic test data without manually clicking through
 * the New Asset modal 12 times.
 *
 * For each unit we synthesise:
 *   • acquisition_cost      — category-realistic sticker price
 *   • GST / PST              — 5% / 7% of sticker price (BC standard)
 *   • delivery_cost / setup  — small flat amounts
 *   • is_financed            — every other unit financed (so we get a mix)
 *   • financing_*            — 60-month term @ 7.5% APR if financed
 *   • monthly_insurance/lic/reg — flat per-category fixed costs
 *   • depreciation method   — straight_line, life by category
 *   • GL account links       — re-uses 12 / 13 / 50 (already set up
 *                              for the existing seeded asset #17)
 *
 * Idempotent: re-running the script does NOT create duplicate assets.
 * It only fills the gaps.
 *
 * Usage:
 *   php scripts/seed_payoff_data.php          # dry-run, prints plan
 *   php scripts/seed_payoff_data.php --apply  # actually inserts
 *   php scripts/seed_payoff_data.php --reset  # delete previously seeded
 *                                               assets first, then insert
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

$apply = in_array('--apply', $argv, true);
$reset = in_array('--reset', $argv, true);

// ── Per-category profile ────────────────────────────────────────
// All amounts are strings — we never use float math on dollar values
// per the project D16 rule.
//
// WHY the costs are tuned: revenue per unit in our seed data is
// ~$10-30k. The acquisition cost has to be in the same neighbourhood
// or the payoff calculator will only ever show negative progress.
// Monthly fixed costs are kept small so old acquisition_dates don't
// blow out the cumulative overhead column.
$profiles = [
    'reefer' => [
        'cost'         => '38000.00',
        'salvage'      => '6000.00',
        'life_years'   => '8',
        'monthly_ins'  => '180.00',
        'monthly_lic'  => '40.00',
        'monthly_reg'  => '25.00',
        'description'  => 'Refrigerated trailer — payoff seed',
    ],
    'dry_van' => [
        'cost'         => '24000.00',
        'salvage'      => '3500.00',
        'life_years'   => '10',
        'monthly_ins'  => '110.00',
        'monthly_lic'  => '35.00',
        'monthly_reg'  => '20.00',
        'description'  => 'Dry van trailer — payoff seed',
    ],
    'flatbed' => [
        'cost'         => '22000.00',
        'salvage'      => '3000.00',
        'life_years'   => '10',
        'monthly_ins'  => '95.00',
        'monthly_lic'  => '32.00',
        'monthly_reg'  => '18.00',
        'description'  => 'Flatbed trailer — payoff seed',
    ],
    'container' => [
        'cost'         => '6500.00',
        'salvage'      => '1000.00',
        'life_years'   => '12',
        'monthly_ins'  => '30.00',
        'monthly_lic'  => '0.00',
        'monthly_reg'  => '0.00',
        'description'  => 'Shipping container — payoff seed',
    ],
    'chassis' => [
        'cost'         => '14000.00',
        'salvage'      => '2000.00',
        'life_years'   => '12',
        'monthly_ins'  => '70.00',
        'monthly_lic'  => '25.00',
        'monthly_reg'  => '15.00',
        'description'  => 'Container chassis — payoff seed',
    ],
];

$defaultProfile = $profiles['dry_van']; // fallback for unknown categories

// ── Acquisition-date spread ─────────────────────────────────────
// We override the unit's acquired_date with one of these "months
// ago" values so the calculator gets a useful spread of payoff
// stories instead of "everything is broken because we acquired
// these in 2018". The values are picked deliberately to land on
// short / mid / long ownership periods.
//
// Index into $acqMonthsAgo by the seed loop counter ($idx % len).
$acqMonthsAgo = [3, 6, 9, 12, 15, 18, 24];

// ── BC tax rates (BC standard 5% GST + 7% PST) ──────────────────
$gstRate = '0.05';
$pstRate = '0.07';

// ── Pull every active unit + linked-asset state ─────────────────
// LEFT JOIN so we can see which units already have an asset.
$rows = db_select(
    "SELECT eu.id              AS unit_id,
            eu.unit_number,
            eu.acquired_date,
            eu.template_id,
            et.category,
            et.name             AS template_name,
            (SELECT a.id
               FROM acc_fixed_assets a
              WHERE a.equipment_unit_id = eu.id
                AND a.status != 'disposed'
              ORDER BY a.id DESC
              LIMIT 1)         AS existing_asset_id
       FROM equipment_units eu
       LEFT JOIN equipment_templates et ON et.id = eu.template_id
      WHERE eu.deleted_at IS NULL
      ORDER BY eu.id"
);

// ── Optional reset: drop previously-seeded assets ───────────────
// We tag everything we insert with description LIKE '%payoff seed%'
// so the reset path is unambiguous.
if ($reset) {
    $tagged = db_select(
        "SELECT id, asset_number, name FROM acc_fixed_assets
          WHERE description LIKE '%payoff seed%'"
    );
    if ($apply) {
        if (!empty($tagged)) {
            $ids = array_map(fn($r) => (int) $r['id'], $tagged);
            $in  = implode(',', $ids);
            db_pdo()->exec("DELETE FROM acc_fixed_assets WHERE id IN ($in)");
        }
        echo "[reset] deleted " . count($tagged) . " previously-seeded asset rows.\n";
    } else {
        echo "[reset DRY-RUN] would delete " . count($tagged) . " previously-seeded asset rows.\n";
        foreach ($tagged as $t) {
            echo "  - asset #{$t['id']} {$t['asset_number']} {$t['name']}\n";
        }
    }
    // Re-fetch row state after reset (so existing_asset_id reflects deletes).
    if ($apply) {
        $rows = db_select(
            "SELECT eu.id              AS unit_id,
                    eu.unit_number,
                    eu.acquired_date,
                    eu.template_id,
                    et.category,
                    et.name             AS template_name,
                    (SELECT a.id
                       FROM acc_fixed_assets a
                      WHERE a.equipment_unit_id = eu.id
                        AND a.status != 'disposed'
                      ORDER BY a.id DESC
                      LIMIT 1)         AS existing_asset_id
               FROM equipment_units eu
               LEFT JOIN equipment_templates et ON et.id = eu.template_id
              WHERE eu.deleted_at IS NULL
              ORDER BY eu.id"
        );
    }
}

// ── Plan / apply per unit ───────────────────────────────────────
$inserted = 0;
$skipped  = 0;
$idx      = 0;

// Find the next FA-YYYY-NNNNN slot to keep numbering predictable.
// We use the same year as the oldest "today" component and bump
// the suffix off the highest existing number.
$yearForNumbering = (int) date('Y');
$maxRow = db_row(
    "SELECT MAX(CAST(SUBSTRING_INDEX(asset_number, '-', -1) AS UNSIGNED)) AS max_n
       FROM acc_fixed_assets
      WHERE asset_number LIKE ?",
    ["FA-{$yearForNumbering}-%"]
);
$nextN = ((int) ($maxRow['max_n'] ?? 0)) + 1;

echo "\n=== seeder plan (yearForNumbering=$yearForNumbering, starting from FA-{$yearForNumbering}-" . str_pad((string)$nextN, 5, '0', STR_PAD_LEFT) . ") ===\n";

foreach ($rows as $row) {
    $unitId  = (int) $row['unit_id'];
    $unitNum = (string) $row['unit_number'];
    $cat     = (string) ($row['category'] ?? '');
    $tplName = (string) ($row['template_name'] ?? 'Unit');

    if (!empty($row['existing_asset_id'])) {
        echo sprintf("  [skip ] unit #%-3d %-10s already has asset #%d\n",
            $unitId, $unitNum, (int) $row['existing_asset_id']);
        $skipped++;
        continue;
    }

    $profile = $profiles[$cat] ?? $defaultProfile;

    // Compute taxes from sticker price.
    $cost     = $profile['cost'];
    $gst      = bcround_local(bcmul($cost, $gstRate, 6), 2);
    $pst      = bcround_local(bcmul($cost, $pstRate, 6), 2);
    $delivery = '450.00';
    $setup    = '250.00';

    // Financing — only every third unit so the dataset stays clean.
    // 60-month amortisation @ 6% APR (rough PMT factor 1.16 / 60).
    $isFinanced = ($idx % 3 === 0) ? 1 : 0;
    $finPayment = '0.00';
    $finRate    = null;
    $finMonths  = null;
    if ($isFinanced) {
        $totalFinanced = bcadd(bcadd(bcadd(bcadd($cost, $gst, 2), $pst, 2), $delivery, 2), $setup, 2);
        $monthly       = bcround_local(bcmul(bcdiv($totalFinanced, '60', 6), '1.16', 6), 2);
        $finPayment    = $monthly;
        $finRate       = '0.0600';
        $finMonths     = 60;
    }

    $assetNumber = sprintf('FA-%d-%05d', $yearForNumbering, $nextN++);
    $assetName   = sprintf('%s #%s', $tplName, $unitNum);

    // WHY override acquired_date: the unit's real acquired_date can be
    // from 2018-2023, which produces 60+ months of cumulative overhead
    // against modest revenue and makes every payoff scenario negative.
    // Spread the seeded assets across 3-24 months ago instead so the
    // calculator shows a useful mix of payoff stories.
    $monthsAgo = $acqMonthsAgo[$idx % count($acqMonthsAgo)];
    $acqDate   = date('Y-m-d', strtotime("first day of -{$monthsAgo} months"));

    $insert = [
        'asset_number'              => $assetNumber,
        'name'                      => $assetName,
        'description'               => $profile['description'],
        'asset_class'               => 'fleet_equipment',
        'equipment_unit_id'         => $unitId,
        'acquisition_date'          => $acqDate,
        'acquisition_cost'          => $cost,
        'purchase_tax_gst'          => $gst,
        'purchase_tax_pst'          => $pst,
        'delivery_cost'             => $delivery,
        'setup_cost'                => $setup,
        'is_financed'               => $isFinanced,
        'financing_monthly_payment' => $isFinanced ? $finPayment : null,
        'financing_interest_rate'   => $finRate,
        'financing_remaining_months'=> $finMonths,
        'monthly_insurance_cost'    => $profile['monthly_ins'],
        'monthly_licensing_cost'    => $profile['monthly_lic'],
        'monthly_registration_cost' => $profile['monthly_reg'],
        'depreciation_method'       => 'straight_line',
        'useful_life_years'         => $profile['life_years'],
        'salvage_value'             => $profile['salvage'],
        // Depreciable cost = acquisition - salvage. The reporting code
        // recomputes this on demand, but we set it here too for consistency.
        'depreciable_cost'          => bcsub($cost, $profile['salvage'], 2),
        'accumulated_depreciation'  => '0.00',
        'net_book_value'            => $cost,
        'depreciation_start_date'   => $acqDate,
        'asset_account_id'          => 12,  // Fleet Equipment — Cost
        'accum_depr_account_id'     => 13,  // Fleet Equipment — Accum Depr
        'depr_expense_account_id'   => 50,  // Depreciation — Rental Fleet
        'status'                    => 'active',
        'location'                  => 'Surrey Yard',
        'created_by'                => 1,
        'created_at'                => date('Y-m-d H:i:s'),
        'updated_at'                => date('Y-m-d H:i:s'),
    ];

    if ($apply) {
        try {
            $newId = db_insert('acc_fixed_assets', $insert);
            echo sprintf("  [INS ] unit #%-3d %-10s → asset #%-3d %s  cost=%-9s fin=%s\n",
                $unitId, $unitNum, $newId, $assetNumber, $cost, $isFinanced ? 'Y' : 'N');
            $inserted++;
        } catch (\Throwable $e) {
            echo sprintf("  [FAIL] unit #%-3d %-10s — %s\n", $unitId, $unitNum, $e->getMessage());
        }
    } else {
        echo sprintf("  [plan] unit #%-3d %-10s → %s  cost=%-9s gst=%-7s pst=%-7s fin=%s ins/mo=%s\n",
            $unitId, $unitNum, $assetNumber, $cost, $gst, $pst,
            $isFinanced ? 'Y' : 'N', $profile['monthly_ins']);
        $inserted++;
    }
    $idx++;
}

echo "\n=== summary ===\n";
echo "  units processed:  " . count($rows) . "\n";
echo "  already linked:   $skipped\n";
echo ($apply ? "  inserted:         $inserted\n"
             : "  would insert:     $inserted (dry-run; pass --apply to commit)\n");

// ── Tiny BC math rounding helper (matches functions.php::bcround) ──
function bcround_local(string $val, int $scale = 2): string
{
    if ($val === '' || !is_numeric($val)) return '0.00';
    $half = '0.' . str_repeat('0', $scale) . '5';
    return bcsub(bcadd($val, $half, $scale + 1), '0', $scale);
}
