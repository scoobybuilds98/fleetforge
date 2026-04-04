<?php
declare(strict_types=1);

/**
 * FleetForge — Rate Card Seed Script
 *
 * @file        scripts/seed_rate_cards.php
 * @description Seeds 11 realistic rate cards with rate items for a
 *              Canadian truck/trailer leasing company.
 *
 *              Idempotent: clears all rate_card_items, soft-deletes any
 *              non-deleted rate_cards, then inserts fresh data.
 *
 * @usage       php scripts/seed_rate_cards.php
 */

require_once __DIR__ . '/../config/app.php';

// ============================================================
// Constants
// ============================================================
const CREATED_BY = 1;

// ============================================================
// Equipment type labels — these must match what exists in
// rate_card_items.equipment_type (varchar 255, free text keyed
// to template name).
// ============================================================
const EQUIP_DRY_VAN   = '53ft Dry Van';
const EQUIP_REEFER    = '48ft Reefer';
const EQUIP_FLATBED   = '40ft Flatbed';
const EQUIP_CONTAINER = '20ft Container';
const EQUIP_CHASSIS   = '53ft Chassis';

// ============================================================
// Base reference rates (Standard 2025, CAD)
// All other cards derive from these via multipliers.
// ============================================================
const BASE_RATES = [
    EQUIP_DRY_VAN   => ['daily' => 125.00, 'weekly' => 800.00,  'monthly' => 2200.00, 'mileage' => 0.1800],
    EQUIP_REEFER    => ['daily' => 145.00, 'weekly' => 950.00,  'monthly' => 3200.00, 'mileage' => 0.2200],
    EQUIP_FLATBED   => ['daily' => 120.00, 'weekly' => 780.00,  'monthly' => 2100.00, 'mileage' => 0.1700],
    EQUIP_CONTAINER => ['daily' =>  95.00, 'weekly' => 620.00,  'monthly' => 1700.00, 'mileage' => 0.1500],
    EQUIP_CHASSIS   => ['daily' =>  80.00, 'weekly' => 520.00,  'monthly' => 1400.00, 'mileage' => 0.1300],
];

// ============================================================
// Helpers
// ============================================================

/**
 * Round a rate to 2 decimal places.
 * Mileage rates are kept to 4 decimal places to preserve fractions.
 */
function apply_multiplier(float $rate, float $multiplier, int $decimals = 2): float
{
    return round($rate * $multiplier, $decimals);
}

/**
 * Build an array of rate_card_items rows for a given set of
 * equipment types, a rate card ID, an optional multiplier, and
 * an optional currency.
 *
 * @param int      $cardId      FK to rate_cards.id
 * @param string[] $types       Equipment type labels to include
 * @param float    $multiplier  Applied to all base rates
 * @param string   $currency    'CAD' or 'USD'
 * @param string   $notes       Optional notes column value
 * @return array[]              Ready to pass to db_insert()
 */
function build_items(
    int $cardId,
    array $types,
    float $multiplier = 1.0,
    string $currency = 'CAD',
    string $notes = ''
): array {
    $items = [];

    foreach ($types as $type) {
        $base = BASE_RATES[$type];

        $items[] = [
            'rate_card_id'   => $cardId,
            'equipment_type' => $type,
            'daily_rate'     => apply_multiplier($base['daily'],   $multiplier),
            'weekly_rate'    => apply_multiplier($base['weekly'],  $multiplier),
            'monthly_rate'   => apply_multiplier($base['monthly'], $multiplier),
            'mileage_rate'   => apply_multiplier($base['mileage'], $multiplier, 4),
            'mileage_unit'   => 'km',
            'currency'       => $currency,
            'notes'          => $notes,
        ];
    }

    return $items;
}

// ============================================================
// All 5 equipment types (used for most cards)
// ============================================================
const ALL_TYPES = [
    EQUIP_DRY_VAN,
    EQUIP_REEFER,
    EQUIP_FLATBED,
    EQUIP_CONTAINER,
    EQUIP_CHASSIS,
];

// ============================================================
// Rate card definitions
// Each entry: [name, effective_from, effective_to|null, is_default, description]
// plus 'items_fn' — a closure that receives $cardId and returns items[].
// ============================================================
$cardDefinitions = [

    // 1. Standard 2024 Rates — ~6% below 2025 (midpoint of 5-8%)
    [
        'name'           => 'Standard 2024 Rates',
        'effective_from' => '2024-01-01',
        'effective_to'   => '2024-12-31',
        'is_default'     => 0,
        'description'    => 'Archive — superseded by 2025 rates',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 0.94, 'CAD',
            'Historical 2024 standard rates (~6% below 2025 base)'
        ),
    ],

    // 2. Standard 2025 Rates — base rates, current default
    [
        'name'           => 'Standard 2025 Rates',
        'effective_from' => '2025-01-01',
        'effective_to'   => null,
        'is_default'     => 1,
        'description'    => 'Current standard fleet rates — all equipment types',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 1.0, 'CAD',
            'Standard 2025 base rates'
        ),
    ],

    // 3. Premium 2025 Rates — ~22% above standard (midpoint of 20-25%)
    [
        'name'           => 'Premium 2025 Rates',
        'effective_from' => '2025-01-01',
        'effective_to'   => null,
        'is_default'     => 0,
        'description'    => 'Premium tier for long-term contracts 12+ months',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 1.22, 'CAD',
            'Premium tier — long-term contract pricing (~22% above standard)'
        ),
    ],

    // 4. Seasonal Winter 2024-25 — ~10% above standard (midpoint of 8-12%)
    [
        'name'           => 'Seasonal Winter 2024-25',
        'effective_from' => '2024-11-01',
        'effective_to'   => '2025-03-31',
        'is_default'     => 0,
        'description'    => 'Adjusted rates for winter season demand',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 1.10, 'CAD',
            'Winter 2024-25 seasonal premium (~10% above standard)'
        ),
    ],

    // 5. Seasonal Winter 2025-26 — ~10% above standard
    [
        'name'           => 'Seasonal Winter 2025-26',
        'effective_from' => '2025-11-01',
        'effective_to'   => '2026-03-31',
        'is_default'     => 0,
        'description'    => 'Winter 2025-26 seasonal rates',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 1.10, 'CAD',
            'Winter 2025-26 seasonal premium (~10% above standard)'
        ),
    ],

    // 6. Promotional Q1 2025 — 10% below standard
    [
        'name'           => 'Promotional Q1 2025',
        'effective_from' => '2025-01-01',
        'effective_to'   => '2025-03-31',
        'is_default'     => 0,
        'description'    => 'Q1 promotion — 10% off standard',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 0.90, 'CAD',
            'Q1 2025 promotional discount — 10% off standard rates'
        ),
    ],

    // 7. USD Export Rates 2025 — CAD base × 0.73 → USD
    [
        'name'           => 'USD Export Rates 2025',
        'effective_from' => '2025-01-01',
        'effective_to'   => null,
        'is_default'     => 0,
        'description'    => 'USD-denominated for US-bound shipments',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 0.73, 'USD',
            'USD rates — approx CAD/USD 0.73 conversion; for US-bound cross-border moves'
        ),
    ],

    // 8. Short-Term Surge 2025 — ~32% above standard (midpoint of 30-35%)
    [
        'name'           => 'Short-Term Surge 2025',
        'effective_from' => '2025-04-01',
        'effective_to'   => null,
        'is_default'     => 0,
        'description'    => 'Short-term/spot rental rates (premium)',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 1.32, 'CAD',
            'Short-term / spot market premium (~32% above standard)'
        ),
    ],

    // 9. Contract Fleet 2025 — ~13% below standard (midpoint of 12-15%)
    [
        'name'           => 'Contract Fleet 2025',
        'effective_from' => '2025-01-01',
        'effective_to'   => null,
        'is_default'     => 0,
        'description'    => 'Negotiated rates for dedicated fleet customers 5+ units',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 0.87, 'CAD',
            'Volume discount for fleet contracts — 5+ units (~13% below standard)'
        ),
    ],

    // 10. Legacy 2023 Rates — ~13% below standard (midpoint of 12-15%)
    [
        'name'           => 'Legacy 2023 Rates',
        'effective_from' => '2023-01-01',
        'effective_to'   => '2023-12-31',
        'is_default'     => 0,
        'description'    => 'Historical archive 2023',
        'items_fn'       => fn(int $id) => build_items(
            $id, ALL_TYPES, 0.87, 'CAD',
            'Historical 2023 rates — read-only archive (~13% below 2025 base)'
        ),
    ],

    // 11. Reefer Specialist 2025 — only 48ft Reefer, ~35% above standard
    [
        'name'           => 'Reefer Specialist 2025',
        'effective_from' => '2025-01-01',
        'effective_to'   => null,
        'is_default'     => 0,
        'description'    => 'Reefer-only card with cold-chain premium rates',
        'items_fn'       => fn(int $id) => build_items(
            $id, [EQUIP_REEFER], 1.35, 'CAD',
            'Cold-chain specialist premium — 48ft Reefer only (+35% above standard)'
        ),
    ],
];

// ============================================================
// Main execution — wrapped in a single transaction
// ============================================================

echo PHP_EOL;
echo '============================================================' . PHP_EOL;
echo ' FleetForge — Rate Card Seed' . PHP_EOL;
echo '============================================================' . PHP_EOL . PHP_EOL;

db_transaction(function () use ($cardDefinitions): void {

    // ----------------------------------------------------------
    // Step 1: Clear existing rate_card_items (hard delete — seed data)
    // ----------------------------------------------------------
    echo '[1/3] Clearing rate_card_items... ';
    $deleted = db_execute('DELETE FROM rate_card_items');
    echo "done. ({$deleted} rows removed)" . PHP_EOL;

    // ----------------------------------------------------------
    // Step 2: Soft-delete all non-deleted rate_cards
    // ----------------------------------------------------------
    echo '[2/3] Soft-deleting existing rate_cards... ';
    $archived = db_execute(
        'UPDATE rate_cards SET deleted_at = NOW() WHERE deleted_at IS NULL'
    );
    echo "done. ({$archived} cards archived)" . PHP_EOL;

    // ----------------------------------------------------------
    // Step 3: Insert 11 rate cards + their items
    // ----------------------------------------------------------
    echo '[3/3] Inserting rate cards and items...' . PHP_EOL . PHP_EOL;

    $cardNum      = 0;
    $totalItems   = 0;

    foreach ($cardDefinitions as $def) {
        $cardNum++;

        // Build the rate_cards row — effective_to may be null (open-ended)
        $cardRow = [
            'name'           => $def['name'],
            'description'    => $def['description'],
            'is_default'     => $def['is_default'],
            'effective_from' => $def['effective_from'],
            'created_by'     => CREATED_BY,
        ];

        // Only include effective_to when it is not null
        // (the column is nullable; omitting it leaves it as NULL)
        if ($def['effective_to'] !== null) {
            $cardRow['effective_to'] = $def['effective_to'];
        }

        $cardId = db_insert('rate_cards', $cardRow);

        // Generate items via the card-specific closure
        $items = ($def['items_fn'])($cardId);

        foreach ($items as $item) {
            db_insert('rate_card_items', $item);
        }

        $itemCount    = count($items);
        $totalItems  += $itemCount;
        $defaultLabel = $def['is_default'] ? ' [DEFAULT]' : '';
        $effectiveTo  = $def['effective_to'] ?? 'open';

        printf(
            "  %2d. %-30s  id=%d  items=%d  %s → %s%s%s",
            $cardNum,
            $def['name'],
            $cardId,
            $itemCount,
            $def['effective_from'],
            $effectiveTo,
            $defaultLabel,
            PHP_EOL
        );
    }

    echo PHP_EOL;
    echo '------------------------------------------------------------' . PHP_EOL;
    printf(
        "  Done. %d rate cards created, %d rate items inserted.%s",
        $cardNum,
        $totalItems,
        PHP_EOL
    );
    echo '============================================================' . PHP_EOL . PHP_EOL;
});
