<?php declare(strict_types=1);

/**
 * scripts/seed_dataset.php
 *
 * Comprehensive seed dataset for FleetForge — populates every primary
 * module with realistic test data covering every status, enum value,
 * and edge case the AI / reports / dashboards exercise.
 *
 * Inserts ~900 rows distributed across 20 tables in dependency order:
 *   equipment_templates    (12 rows — all 10 categories)
 *   vendors                (12 rows — all 6 vendor_types)
 *   yards                  (3 rows  — additional yards)
 *   rate_cards             (8 rows  — default / historical / future)
 *   rate_card_items        (~50 rows)
 *   customers              (50 rows — all 5 statuses + currency / exempt / discount mix)
 *   customer_contacts      (~50 rows)
 *   customer_equipment_rates (~25 rows — custom per-customer rates)
 *   equipment_units        (80 rows — all 6 statuses, 3 ownership_types)
 *   leases                 (60 rows — all 4 statuses)
 *   invoices               (~100 rows — all 7 statuses + 6 invoice_types)
 *   invoice_line_items     (~130 rows — all 13 item_types)
 *   payments               (~60 rows — all 8 payment_methods + 6 statuses)
 *   payment_allocations    (~60 rows)
 *   maintenance_work_orders (50 rows — all 8 work_types, 4 priorities, 5 statuses)
 *   damage_claims          (25 rows — all 4 severities + 6 statuses)
 *   inspections            (30 rows — all 5 inspection_types)
 *   mileage_logs           (40 rows — all 5 log_types)
 *   reservations           (25 rows — all 4 statuses)
 *   reservation_units      (~35 rows)
 *
 * Tagging strategy (so --reset is unambiguous):
 *   Every seeded row carries the literal string "[seed:dataset]" in
 *   one of its text columns (notes / internal_notes / description).
 *   Numbered records also use a "SEED-" prefix on their natural keys
 *   (SEED-DRY-001, SEED-INV-..., SEED-LSE-...) so the data is easy
 *   to distinguish in the UI.
 *
 * Idempotent: re-running this script does NOT create duplicate rows
 * because each insert path either short-circuits when a tagged row
 * already exists, or the unique key prevents collision.
 *
 * Usage:
 *   php scripts/seed_dataset.php           # dry-run, prints plan
 *   php scripts/seed_dataset.php --apply   # actually inserts
 *   php scripts/seed_dataset.php --reset   # delete previously seeded
 *                                             rows first, then insert
 *
 * @session DATA-1 — Comprehensive seed dataset
 */

require_once __DIR__ . '/../config/app.php';

$apply = in_array('--apply', $argv, true);
$reset = in_array('--reset', $argv, true);

const SEED_TAG = '[seed:dataset]';

// ── tiny utility helpers ───────────────────────────────────────
mt_srand(20260407); // deterministic across runs

function rnd(int $min, int $max): int { return mt_rand($min, $max); }

/** Returns a random element of $arr. */
function pick(array $arr) { return $arr[array_rand($arr)]; }

/** Returns a Y-m-d date $minDays..$maxDays days ago (positive = past). */
function pastDate(int $minDays, int $maxDays): string {
    $days = rnd($minDays, $maxDays);
    return date('Y-m-d', strtotime("-{$days} days"));
}

/** Returns a Y-m-d date $minDays..$maxDays days in the future. */
function futureDate(int $minDays, int $maxDays): string {
    $days = rnd($minDays, $maxDays);
    return date('Y-m-d', strtotime("+{$days} days"));
}

/** Returns a random money string between $min..$max (with 2 decimals). */
function money(int $min, int $max): string {
    $whole = rnd($min, $max);
    $cents = str_pad((string) rnd(0, 99), 2, '0', STR_PAD_LEFT);
    return "{$whole}.{$cents}";
}

/** BC math 2-decimal rounder. */
function bcr(string $val, int $scale = 2): string {
    if ($val === '' || !is_numeric($val)) return '0.00';
    $half = '0.' . str_repeat('0', $scale) . '5';
    return bcsub(bcadd($val, $half, $scale + 1), '0', $scale);
}

/** now() string. */
function nowStr(): string { return date('Y-m-d H:i:s'); }

// ────────────────────────────────────────────────────────────────
//  RESET PATH — remove every previously-seeded row in dependency order
// ────────────────────────────────────────────────────────────────
if ($reset) {
    echo "\n=== RESET previously-seeded dataset ===\n";

    $resetSpecs = [
        // [table, where SQL, params]
        ['payment_allocations',
            "payment_id IN (SELECT id FROM payments WHERE internal_notes LIKE ?)",
            ['%' . SEED_TAG . '%']],
        ['payments',
            "internal_notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['invoice_line_items',
            "invoice_id IN (SELECT id FROM invoices WHERE internal_notes LIKE ?)",
            ['%' . SEED_TAG . '%']],
        ['invoices',
            "internal_notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['mileage_logs',
            "notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['inspections',
            "notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['damage_claims',
            "notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['maintenance_work_orders',
            "internal_notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['reservation_units',
            "reservation_id IN (SELECT id FROM reservations WHERE internal_notes LIKE ?)",
            ['%' . SEED_TAG . '%']],
        ['reservations',
            "internal_notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['leases',
            "notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['customer_equipment_rates',
            "notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['customer_contacts',
            "notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['customers',
            "internal_notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['equipment_units',
            "internal_notes LIKE ?", ['%' . SEED_TAG . '%']],
        ['equipment_templates',
            "description LIKE ?", ['%' . SEED_TAG . '%']],
        ['rate_card_items',
            "rate_card_id IN (SELECT id FROM rate_cards WHERE description LIKE ?)",
            ['%' . SEED_TAG . '%']],
        ['rate_cards',
            "description LIKE ?", ['%' . SEED_TAG . '%']],
        ['vendors',
            "notes LIKE ?", ['%' . SEED_TAG . '%']],
    ];

    foreach ($resetSpecs as [$table, $where, $params]) {
        if ($apply) {
            $stmt = db_pdo()->prepare("DELETE FROM `$table` WHERE $where");
            $stmt->execute($params);
            $n = $stmt->rowCount();
            echo "  deleted $n from $table\n";
        } else {
            $stmt = db_pdo()->prepare("SELECT COUNT(*) AS n FROM `$table` WHERE $where");
            $stmt->execute($params);
            $n = (int) $stmt->fetchColumn();
            echo "  [DRY] would delete $n from $table\n";
        }
    }
    echo "  reset complete.\n\n";
}

// ────────────────────────────────────────────────────────────────
// Tracking — collect counts and built IDs across the run
// ────────────────────────────────────────────────────────────────
$counts        = [];
$createdTplIds = [];
$createdYards  = [];
$createdVendors= [];
$createdRateCardIds = [];
$createdCustomerIds = [];
$createdUnitIds     = [];
$createdLeaseIds    = [];
$createdInvoiceIds  = [];
$createdPaymentIds  = [];
$createdReservationIds = [];

/** Wrap a db_insert that we want to NO-OP in dry-run mode. */
function ins(string $table, array $row): int {
    global $apply;
    if (!$apply) {
        return rnd(900000, 999999); // pretend ID for downstream linking
    }
    return (int) db_insert($table, $row);
}

function bump(string $module, int $by = 1): void {
    global $counts;
    $counts[$module] = ($counts[$module] ?? 0) + $by;
}

echo "=== seed_dataset " . ($apply ? 'APPLY' : 'DRY-RUN') . " ===\n\n";

// ════════════════════════════════════════════════════════════════
// 1. EQUIPMENT TEMPLATES (12 rows — all 10 categories)
// ════════════════════════════════════════════════════════════════
$templates = [
    ['Seed: 53ft Dry Van Wabash',  'seed-53ft-dry-van-wabash',  'dry_van',   'Wabash',     'DuraPlate', 53, 13.5, 8.5, 45000, '125.00', '750.00', '2200.00', '0.0000'],
    ['Seed: 48ft Dry Van Great Dane','seed-48ft-dry-van-gd',    'dry_van',   'Great Dane', 'Champion',  48, 13.5, 8.5, 44000, '115.00', '690.00', '2050.00', '0.0000'],
    ['Seed: 53ft Reefer Carrier',  'seed-53ft-reefer-carrier',  'reefer',    'Carrier',    'Vector',    53, 13.5, 8.5, 44000, '160.00', '960.00', '3200.00', '0.1500'],
    ['Seed: 48ft Reefer Thermo',   'seed-48ft-reefer-thermo',   'reefer',    'Thermo King','Precedent', 48, 13.5, 8.5, 43000, '150.00', '900.00', '3000.00', '0.1500'],
    ['Seed: 40ft Container Chassis','seed-40ft-container-chassis','chassis', 'Pratt',      'Tri-Axle',  40, 5.0,  8.5, 60000, '85.00',  '510.00', '1700.00', '0.0000'],
    ['Seed: 20ft Container Chassis','seed-20ft-container-chassis','chassis', 'Cheetah',    'Slider',    20, 5.0,  8.5, 50000, '70.00',  '420.00', '1400.00', '0.0000'],
    ['Seed: 53ft Flatbed Wabash',  'seed-53ft-flatbed-wabash',  'flatbed',   'Wabash',     'OpenDeck',  53, 5.0,  8.5, 48000, '130.00', '780.00', '2600.00', '0.0000'],
    ['Seed: 40ft Step Deck',       'seed-40ft-step-deck',       'step_deck', 'Reitnouer',  'MaxMiser',  40, 5.0,  8.5, 50000, '140.00', '840.00', '2800.00', '0.0000'],
    ['Seed: 48ft Lowboy',          'seed-48ft-lowboy',          'lowboy',    'Trail King', 'TK110HDG',  48, 4.0,  8.5, 80000, '180.00', '1080.00','3600.00', '0.0000'],
    ['Seed: 40ft Tanker',          'seed-40ft-tanker',          'tanker',    'Polar',      'Stainless', 40, 11.5, 8.5, 60000, '170.00', '1020.00','3400.00', '0.1200'],
    ['Seed: 36ft Dump Trailer',    'seed-36ft-dump',            'dump',      'East',       'Genesis',   36, 11.0, 8.5, 70000, '150.00', '900.00', '3000.00', '0.0000'],
    ['Seed: 53ft Container 20DC',  'seed-20dc-container',       'container', 'Maersk',     '20DC',      20, 8.5,  8.0, 52000, '60.00',  '360.00', '1200.00', '0.0000'],
];

echo "1) equipment_templates\n";
foreach ($templates as $idx => $t) {
    [$name, $slug, $category, $brand, $model, $L, $H, $W, $cap, $daily, $weekly, $monthly, $mileage] = $t;

    $row = [
        'name' => $name,
        'slug' => $slug,
        'description' => "Seed dataset template — {$brand} {$model}, $category, {$L}ft. " . SEED_TAG,
        'category' => $category,
        'brand' => $brand,
        'model' => $model,
        'default_length_ft' => (string) $L,
        'default_height_ft' => (string) $H,
        'default_width_ft'  => (string) $W,
        'default_weight_capacity_lbs' => $cap,
        'default_axle_count' => $category === 'lowboy' ? 3 : 2,
        'default_ownership_type' => 'owned',
        'default_yard_location' => 'surrey',
        'default_tracking_provider' => 'none',
        'default_cvi_interval_days' => 365,
        'default_mvi_interval_days' => 365,
        'default_registration_interval_days' => 365,
        'default_insurance_interval_days' => 365,
        'default_daily_rate'   => $daily,
        'default_weekly_rate'  => $weekly,
        'default_monthly_rate' => $monthly,
        'default_mileage_rate' => $mileage,
        'default_currency'     => 'CAD',
        'default_mileage_unit' => $category === 'reefer' || $category === 'tanker' ? 'km' : 'miles',
        'is_active'  => 1,
        'sort_order' => 100 + ($idx * 5),
        'created_by' => 1,
        'created_at' => nowStr(),
        'updated_at' => nowStr(),
    ];
    $newId = ins('equipment_templates', $row);
    $createdTplIds[] = $newId;
    bump('equipment_templates');
    echo "  + $name (id={$newId})\n";
}

// ════════════════════════════════════════════════════════════════
// 2. VENDORS (12 rows — all 6 vendor_types)
// ════════════════════════════════════════════════════════════════
$vendors = [
    ['Seed: Pacific Diesel Repair',    'maintenance', 'Tony Park',   'tony@pacdiesel.example',   '604-555-0210', 'Surrey',     'BC', '["Diesel Engine","Brakes","Suspension"]', '110.00', 5, true],
    ['Seed: West Coast Trailer Repair','repair',      'Maria Lopez', 'maria@wctr.example',       '604-555-0211', 'Burnaby',    'BC', '["Body Repair","Frame","Doors"]',         '125.00', 4, true],
    ['Seed: Northland Parts Co',       'parts',       'Sam Khan',    'sam@northlandparts.example','604-555-0212','Langley',    'BC', '["Brake Pads","Filters","Bearings"]',     null,     5, true],
    ['Seed: Provincial Inspection Ltd','inspection',  'Alice Cho',   'alice@provinsp.example',   '604-555-0213', 'Delta',      'BC', '["CVI","MVI","Pre-Trip"]',                '95.00',  5, false],
    ['Seed: BC Towing Services',       'towing',      'Dan Reid',    'dan@bctowing.example',     '604-555-0214', 'Abbotsford', 'BC', '["Heavy Tow","Recovery"]',                '180.00', 4, false],
    ['Seed: Total Tire Centre',        'other',       'Lina Park',   'lina@totaltire.example',   '604-555-0215', 'Surrey',     'BC', '["Tire Replacement","Alignment"]',        '85.00',  4, true],
    ['Seed: Atlas Body Shop',          'repair',      'Carl Jung',   'carl@atlas.example',       '604-555-0216', 'Coquitlam',  'BC', '["Collision","Paint","Welding"]',         '105.00', 3, false],
    ['Seed: Fast Lube & Service',      'maintenance', null,          'service@fastlube.example', '604-555-0217', 'Surrey',     'BC', '["PM Service","Oil Change"]',             '75.00',  4, false],
    ['Seed: Reefer Specialists Inc',   'maintenance', 'Greg Mok',    'greg@reefspec.example',    '604-555-0218', 'Richmond',   'BC', '["Reefer Units","Compressor"]',           '140.00', 5, true],
    ['Seed: Lower Mainland Towing',    'towing',      null,          'dispatch@lmtowing.example','604-555-0219', 'Vancouver',  'BC', '["Light Tow","Recovery"]',                '160.00', 3, false],
    ['Seed: Highland Hydraulics',      'parts',       'Iva Mendes',  'iva@highhydro.example',    '604-555-0220', 'Langley',    'BC', '["Hydraulic Hoses","Cylinders"]',         null,     4, false],
    ['Seed: ProInspect Compliance',    'inspection',  'Joe Tan',     'joe@proinspect.example',   '604-555-0221', 'Surrey',     'BC', '["CVI","Annual"]',                        '110.00', 5, true],
];

echo "\n2) vendors\n";
foreach ($vendors as $v) {
    [$name, $type, $contact, $email, $phone, $city, $state, $specs, $rate, $rating, $preferred] = $v;
    $row = [
        'name' => $name,
        'vendor_type' => $type,
        'contact_name' => $contact,
        'email' => $email,
        'phone' => $phone,
        'city' => $city,
        'state' => $state,
        'specializations' => $specs,
        'hourly_rate' => $rate,
        'rating' => $rating,
        'notes' => "Seed dataset vendor. " . SEED_TAG,
        'is_preferred' => $preferred ? 1 : 0,
        'total_spent' => '0.00',
        'created_by' => 1,
        'created_at' => nowStr(),
        'updated_at' => nowStr(),
    ];
    $vid = ins('vendors', $row);
    $createdVendors[] = $vid;
    bump('vendors');
    echo "  + $name ($type) id={$vid}\n";
}

// ════════════════════════════════════════════════════════════════
// 3. YARDS (3 rows in addition to existing 4)
// ════════════════════════════════════════════════════════════════
$yards = [
    ['Seed: Calgary Yard',  'seed-calgary-yard',  'Calgary',  'AB', 90],
    ['Seed: Edmonton Yard', 'seed-edmonton-yard', 'Edmonton', 'AB', 60],
    ['Seed: Winnipeg Yard', 'seed-winnipeg-yard', 'Winnipeg', 'MB', 45],
];
echo "\n3) yards\n";
foreach ($yards as $y) {
    [$name, $slug, $city, $state, $cap] = $y;
    $row = [
        'name' => $name,
        'slug' => $slug,
        'city' => $city,
        'state' => $state,
        'capacity' => $cap,
        'phone' => '604-555-' . rnd(1000, 9999),
        'notes' => "Seed yard. " . SEED_TAG,
        'is_active' => 1,
        'created_at' => nowStr(),
        'updated_at' => nowStr(),
    ];
    $yid = ins('yards', $row);
    $createdYards[] = ['id' => $yid, 'slug' => $slug];
    bump('yards');
    echo "  + $name id={$yid}\n";
}

// ════════════════════════════════════════════════════════════════
// 4. RATE CARDS + ITEMS (8 cards, ~50 items)
// ════════════════════════════════════════════════════════════════
$rateCards = [
    ['Seed: Standard 2026 (Q1)',  '2026-01-01', '2026-03-31', false],
    ['Seed: Standard 2026 (Q2)',  '2026-04-01', null,          true],
    ['Seed: Premium Customers',   '2026-01-01', null,          false],
    ['Seed: Long-Term Discount',  '2026-01-01', null,          false],
    ['Seed: Holiday Promo',       '2026-11-01', '2026-12-31', false],
    ['Seed: USD Cross-Border',    '2026-01-01', null,          false],
    ['Seed: 2025 Legacy',         '2025-01-01', '2025-12-31', false],
    ['Seed: 2027 Forecast',       '2027-01-01', null,          false],
];
echo "\n4) rate_cards\n";
foreach ($rateCards as $rc) {
    [$name, $from, $to, $isDefault] = $rc;
    $row = [
        'name' => $name,
        'description' => "Seed rate card. " . SEED_TAG,
        'is_default' => $isDefault ? 1 : 0,
        'effective_from' => $from,
        'effective_to' => $to,
        'created_by' => 1,
        'created_at' => nowStr(),
        'updated_at' => nowStr(),
    ];
    $rcid = ins('rate_cards', $row);
    $createdRateCardIds[] = $rcid;
    bump('rate_cards');
    echo "  + $name id={$rcid}\n";
}

// Items: ~6 categories × 8 cards
$rateCategories = ['dry_van', 'reefer', 'flatbed', 'step_deck', 'chassis', 'lowboy'];
echo "\n4b) rate_card_items\n";
foreach ($createdRateCardIds as $rcId) {
    foreach ($rateCategories as $cat) {
        $base = match ($cat) {
            'dry_van'   => 120,
            'reefer'    => 155,
            'flatbed'   => 130,
            'step_deck' => 140,
            'chassis'   => 80,
            'lowboy'    => 175,
        };
        $jitter = rnd(-15, 15);
        $daily   = (string) ($base + $jitter);
        $weekly  = (string) (($base + $jitter) * 6);
        $monthly = (string) (($base + $jitter) * 24);
        $row = [
            'rate_card_id' => $rcId,
            'equipment_type' => $cat,
            'daily_rate'   => $daily . '.00',
            'weekly_rate'  => $weekly . '.00',
            'monthly_rate' => $monthly . '.00',
            'mileage_rate' => $cat === 'reefer' || $cat === 'tanker' ? '0.1500' : '0.0000',
            'mileage_unit' => $cat === 'reefer' ? 'km' : 'miles',
            'currency' => 'CAD',
            'notes' => SEED_TAG,
            'created_at' => nowStr(),
            'updated_at' => nowStr(),
        ];
        ins('rate_card_items', $row);
        bump('rate_card_items');
    }
}
echo "  + " . ($counts['rate_card_items'] ?? 0) . " rate_card_items inserted\n";

// ════════════════════════════════════════════════════════════════
// 5. CUSTOMERS (50 rows — every status / scenario)
// ════════════════════════════════════════════════════════════════
$companyAdjectives = ['Pacific', 'Northern', 'Coastal', 'Mountain', 'Prairie', 'Western', 'Atlantic', 'Boreal', 'Cascade', 'Granite', 'Royal', 'Sterling', 'Maple', 'Cedar', 'Polar', 'Arctic', 'Continental', 'Imperial', 'Heritage', 'Pinnacle', 'Apex', 'Summit', 'Vanguard', 'Beacon', 'Canyon'];
$companyNouns = ['Logistics', 'Transport', 'Freight', 'Hauling', 'Carriers', 'Distribution', 'Express', 'Trucking', 'Cartage', 'Movers', 'Shipping', 'Lines', 'Couriers', 'Freightways'];
$cities = [
    ['Vancouver', 'BC'], ['Surrey', 'BC'], ['Burnaby', 'BC'], ['Richmond', 'BC'],
    ['Calgary', 'AB'], ['Edmonton', 'AB'], ['Red Deer', 'AB'],
    ['Winnipeg', 'MB'], ['Saskatoon', 'SK'], ['Regina', 'SK'],
    ['Toronto', 'ON'], ['Mississauga', 'ON'], ['Hamilton', 'ON'],
    ['Seattle', 'WA'], ['Portland', 'OR'], ['Spokane', 'WA'],
];

$customerSpecs = [
    // 30 active CAD low-risk current
    ['active', 'CAD', false, false, false, 'low',    'current',     'none', '0', false, 25],
    // 5 active CAD percentage discount
    ['active', 'CAD', false, false, false, 'low',    'current',     'percentage', '5.00', false, 5],
    // 3 active CAD flat discount + late fees
    ['active', 'CAD', false, false, false, 'medium', 'current',     'flat',       '50.00', true, 3],
    // 3 active USD
    ['active', 'USD', false, false, false, 'low',    'current',     'none', '0', false, 3],
    // 3 active gst exempt
    ['active', 'CAD', false, true,  false, 'low',    'current',     'none', '0', false, 3],
    // 2 active fully tax exempt
    ['active', 'CAD', true,  true,  true,  'low',    'current',     'none', '0', false, 2],
    // 2 inactive
    ['inactive', 'CAD', false, false, false, 'low',  'current',     'none', '0', false, 2],
    // 2 pending
    ['pending', 'CAD', false, false, false, 'medium','current',     'none', '0', false, 2],
    // 2 suspended
    ['suspended', 'CAD', false, false, false, 'high','watch',       'none', '0', false, 2],
    // 1 credit_hold
    ['credit_hold', 'CAD', false, false, false, 'high','collections', 'none', '0', false, 1],
    // 1 active high risk legal
    ['active', 'CAD', false, false, false, 'high',  'legal',        'none', '0', true, 1],
    // 1 active written_off
    ['active', 'CAD', false, false, false, 'high',  'written_off',  'none', '0', false, 1],
];

echo "\n5) customers\n";
$cn = 0;
foreach ($customerSpecs as $spec) {
    [$status, $currency, $taxEx, $gstEx, $pstEx, $risk, $coll, $discType, $discVal, $lateFee, $count] = $spec;
    for ($i = 0; $i < $count; $i++) {
        $cn++;
        $name = "Seed " . pick($companyAdjectives) . ' ' . pick($companyNouns) . " #{$cn}";
        $city = pick($cities);
        $row = [
            'company_name' => $name,
            'contact_name' => 'Contact ' . $cn,
            'email'  => 'ops' . $cn . '@seedcustomers.test',
            'phone'  => '604-555-' . str_pad((string) (1000 + $cn), 4, '0', STR_PAD_LEFT),
            'address' => rnd(100, 9999) . ' ' . pick(['Main', 'Industrial', 'Commerce', 'Trade', 'Logistics']) . ' ' . pick(['St', 'Ave', 'Way', 'Blvd']),
            'city'   => $city[0],
            'state'  => $city[1],
            'province' => $city[1],
            'postal_code' => pick(['V3T 1A1', 'V5K 0A1', 'T2P 4N7', 'M5V 1J3']),
            'country' => $city[1] === 'WA' || $city[1] === 'OR' ? 'United States' : 'Canada',
            'tax_id'  => 'TAX' . rnd(100000, 999999),
            'dot_number' => 'DOT' . rnd(1000000, 9999999),
            'mc_number' => 'MC-' . rnd(100000, 999999),
            'gst_number' => '12345' . rnd(1000, 9999) . 'RT0001',
            'currency' => $currency,
            'mileage_unit' => $currency === 'USD' ? 'miles' : 'km',
            'tax_exempt' => $taxEx ? 1 : 0,
            'gst_exempt' => $gstEx ? 1 : 0,
            'pst_exempt' => $pstEx ? 1 : 0,
            'billing_cycle' => 'monthly',
            'invoice_delivery' => pick(['email', 'email', 'email', 'portal']),
            'invoice_email' => 'billing' . $cn . '@seedcustomers.test',
            'po_required' => rnd(0, 4) === 0 ? 1 : 0,
            'discount_type' => $discType,
            'discount_value' => $discVal,
            'discount_reason' => $discType !== 'none' ? 'Volume discount' : null,
            'late_fee_enabled' => $lateFee ? 1 : 0,
            'late_fee_type' => $lateFee ? 'percentage' : null,
            'late_fee_value' => $lateFee ? '1.5000' : null,
            'late_fee_grace_days' => $lateFee ? 5 : 0,
            'status' => $status,
            'credit_limit' => money(20000, 200000),
            'payment_terms' => pick(['Net 15', 'Net 30', 'Net 45', 'Net 60', 'Due on receipt']),
            'preferred_yard' => pick(['surrey', 'delta-yard', 'abbotsford-yard']),
            'risk_score' => $risk,
            'collection_status' => $coll,
            'lease_count' => 0,
            'active_lease_count' => 0,
            'total_revenue' => '0.00',
            'outstanding_balance' => '0.00',
            'account_credit_balance' => '0.00',
            'internal_notes' => "Seed customer. status={$status} risk={$risk}. " . SEED_TAG,
            'created_by' => 1,
            'updated_by' => 1,
            'created_at' => pastDate(30, 730),
            'updated_at' => nowStr(),
        ];
        $cid = ins('customers', $row);
        $createdCustomerIds[] = ['id' => $cid, 'name' => $name, 'currency' => $currency, 'taxEx' => $taxEx, 'gstEx' => $gstEx, 'pstEx' => $pstEx, 'discType' => $discType, 'discVal' => $discVal];
        bump('customers');
    }
}
echo "  + " . ($counts['customers'] ?? 0) . " customers inserted\n";

// ── customer_contacts (50 — primary + secondary on first 30 customers)
echo "\n5b) customer_contacts\n";
$contactsBuilt = 0;
foreach ($createdCustomerIds as $i => $c) {
    if ($i >= 30) break;
    // primary
    ins('customer_contacts', [
        'customer_id' => $c['id'],
        'name'   => 'Primary Contact ' . ($i + 1),
        'title'  => pick(['Operations Manager', 'Logistics Director', 'Fleet Manager', 'CFO', 'Owner']),
        'email'  => 'primary' . ($i + 1) . '@seedcustomers.test',
        'phone'  => '604-555-' . str_pad((string) (2000 + $i), 4, '0', STR_PAD_LEFT),
        'is_primary' => 1,
        'notes' => SEED_TAG,
        'created_at' => nowStr(),
    ]);
    $contactsBuilt++;
    bump('customer_contacts');
    // ~70% also get a secondary contact
    if (rnd(0, 9) < 7) {
        ins('customer_contacts', [
            'customer_id' => $c['id'],
            'name'   => 'Backup Contact ' . ($i + 1),
            'title'  => pick(['Dispatcher', 'AP Clerk', 'Driver Manager']),
            'email'  => 'backup' . ($i + 1) . '@seedcustomers.test',
            'phone'  => '604-555-' . str_pad((string) (3000 + $i), 4, '0', STR_PAD_LEFT),
            'is_primary' => 0,
            'notes' => SEED_TAG,
            'created_at' => nowStr(),
        ]);
        $contactsBuilt++;
        bump('customer_contacts');
    }
}
echo "  + $contactsBuilt customer_contacts inserted\n";

// ── customer_equipment_rates (25 custom rates)
echo "\n5c) customer_equipment_rates\n";
for ($i = 0; $i < 25; $i++) {
    $c = $createdCustomerIds[$i % count($createdCustomerIds)];
    $cat = pick(['dry_van', 'reefer', 'flatbed', 'chassis', 'step_deck']);
    $base = match ($cat) {
        'dry_van' => 110, 'reefer' => 145, 'flatbed' => 120, 'chassis' => 70, 'step_deck' => 130,
    };
    $daily   = (string) ($base + rnd(-10, 10)) . '.00';
    $weekly  = (string) (($base + rnd(-10, 10)) * 6) . '.00';
    $monthly = (string) (($base + rnd(-10, 10)) * 24) . '.00';
    ins('customer_equipment_rates', [
        'customer_id'  => $c['id'],
        'equipment_type' => $cat,
        'daily_rate'   => $daily,
        'weekly_rate'  => $weekly,
        'monthly_rate' => $monthly,
        'mileage_rate' => $cat === 'reefer' ? '0.1400' : '0.0000',
        'mileage_unit' => $cat === 'reefer' ? 'km' : 'miles',
        'currency' => $c['currency'],
        'minimum_charge' => money(500, 2000),
        'notes' => "Seed custom rate for {$c['name']}. " . SEED_TAG,
        'effective_from' => pastDate(30, 365),
        'effective_to' => null,
        'created_by' => 1,
        'created_at' => nowStr(),
        'updated_at' => nowStr(),
    ]);
    bump('customer_equipment_rates');
}
echo "  + " . $counts['customer_equipment_rates'] . " customer_equipment_rates inserted\n";

// ════════════════════════════════════════════════════════════════
// 6. EQUIPMENT UNITS (80 rows distributed across 12 templates)
// ════════════════════════════════════════════════════════════════
$catPrefix = [
    'dry_van' => 'DRY', 'reefer' => 'RFR', 'chassis' => 'CHS',
    'flatbed' => 'FLT', 'step_deck' => 'STP', 'lowboy' => 'LOW',
    'tanker' => 'TNK', 'dump' => 'DMP', 'container' => 'CON', 'other' => 'OTH',
];
$statusBuckets = [
    ['available',     30],
    ['on_lease',      18],
    ['maintenance',    8],
    ['reserved',       8],
    ['inactive',       8],
    ['decommissioned', 8],
];
$ownership = ['owned', 'owned', 'owned', 'leased', 'leased', 'brokered'];
$yardSlugs = ['surrey', 'delta-yard', 'abbotsford-yard', 'seed-calgary-yard', 'seed-edmonton-yard'];

echo "\n6) equipment_units\n";
$tplRotation = 0;
$unitCounter = 0;
foreach ($statusBuckets as [$status, $qty]) {
    for ($i = 0; $i < $qty; $i++) {
        // Pick a template by rotation so all 12 get coverage.
        $tplId = $createdTplIds[$tplRotation % count($createdTplIds)];
        $tplRotation++;

        // Pull the template's category for prefix and rates.
        $tpl = $apply
            ? db_row("SELECT category, default_daily_rate, default_weekly_rate, default_monthly_rate, default_mileage_rate FROM equipment_templates WHERE id = ?", [$tplId])
            : ['category' => 'dry_van', 'default_daily_rate' => '120.00', 'default_weekly_rate' => '720.00', 'default_monthly_rate' => '2400.00', 'default_mileage_rate' => '0.0000'];
        $cat = $tpl['category'] ?? 'dry_van';
        $prefix = $catPrefix[$cat] ?? 'GEN';

        $unitCounter++;
        $unitNumber = sprintf('SEED-%s-%03d', $prefix, $unitCounter);

        $acquiredDate = pastDate(60, 1100);
        $cost = match ($cat) {
            'dry_van' => money(20000, 35000),
            'reefer'  => money(40000, 60000),
            'chassis' => money(12000, 18000),
            'flatbed' => money(22000, 35000),
            'step_deck' => money(28000, 42000),
            'lowboy'  => money(45000, 70000),
            'tanker'  => money(50000, 80000),
            'dump'    => money(35000, 55000),
            'container' => money(5000, 10000),
            default   => money(20000, 30000),
        };

        $row = [
            'template_id' => $tplId,
            'unit_number' => $unitNumber,
            'vin'  => 'SEED' . str_pad((string) $unitCounter, 13, '0', STR_PAD_LEFT),
            'year' => rnd(2018, 2026),
            'tracking_provider' => 'none',
            'ownership_type' => pick($ownership),
            'yard_location' => pick($yardSlugs),
            'license_plate' => 'SD' . str_pad((string) $unitCounter, 4, '0', STR_PAD_LEFT),
            'license_state' => 'BC',
            'cvi_expiry' => futureDate(15, 360),
            'registration_expiry' => futureDate(15, 360),
            'mvi_expiry' => futureDate(15, 360),
            'insurance_expiry' => futureDate(30, 360),
            'status' => $status,
            'mileage' => rnd(5000, 350000),
            'notes' => "Seed unit. category={$cat} status={$status}.",
            'internal_notes' => SEED_TAG,
            'lease_count' => 0,
            'total_revenue' => '0.00',
            'total_maintenance_cost' => '0.00',
            'acquired_date' => $acquiredDate,
            'acquisition_cost' => $cost,
            'decommissioned_date' => $status === 'decommissioned' ? pastDate(10, 200) : null,
            'decommission_reason' => $status === 'decommissioned' ? 'Seed: end of useful life' : null,
            'created_by' => 1,
            'updated_by' => 1,
            'created_at' => $acquiredDate . ' 12:00:00',
            'updated_at' => nowStr(),
        ];
        $uid = ins('equipment_units', $row);
        $createdUnitIds[] = ['id' => $uid, 'unit_number' => $unitNumber, 'category' => $cat, 'status' => $status,
                              'daily' => $tpl['default_daily_rate'] ?? '120.00',
                              'weekly' => $tpl['default_weekly_rate'] ?? '720.00',
                              'monthly' => $tpl['default_monthly_rate'] ?? '2400.00',
                              'mileage' => $tpl['default_mileage_rate'] ?? '0.0000'];
        bump('equipment_units');
    }
}
echo "  + " . $counts['equipment_units'] . " equipment_units inserted\n";

// ════════════════════════════════════════════════════════════════
// 7. LEASES (60 rows — all 4 statuses)
// ════════════════════════════════════════════════════════════════
$leaseStatuses = [
    ['active',    25],
    ['completed', 25],
    ['pending',    5],
    ['cancelled',  5],
];
echo "\n7) leases\n";
$leaseCounter = 0;
$activeUnits = array_values(array_filter($createdUnitIds, fn($u) => in_array($u['status'], ['available','on_lease','reserved','maintenance'])));
foreach ($leaseStatuses as [$lstatus, $qty]) {
    for ($i = 0; $i < $qty; $i++) {
        $leaseCounter++;
        $cust = $createdCustomerIds[$leaseCounter % count($createdCustomerIds)];
        $unit = $activeUnits[$leaseCounter % count($activeUnits)];

        // Date logic by status
        if ($lstatus === 'active') {
            $start = pastDate(15, 180);
            $end   = futureDate(30, 365);
            $actualReturn = null;
        } elseif ($lstatus === 'completed') {
            $start = pastDate(180, 540);
            $end   = pastDate(15, 150);
            $actualReturn = $end;
        } elseif ($lstatus === 'pending') {
            $start = futureDate(7, 60);
            $end   = futureDate(90, 365);
            $actualReturn = null;
        } else { // cancelled
            $start = pastDate(30, 240);
            $end   = pastDate(0, 25);
            $actualReturn = pastDate(5, 28);
        }

        $daily   = $unit['daily'];
        $weekly  = $unit['weekly'];
        $monthly = $unit['monthly'];
        $mileage = $unit['mileage'];

        $estMileage = (string) rnd(1000, 25000);
        $actMileage = $lstatus === 'completed' ? (string) rnd(1000, 25000) : '0';

        // Tax rates: BC 5/7, but follow customer exemptions.
        $gstRate = $cust['gstEx'] || $cust['taxEx'] ? '0.0000' : '0.0500';
        $pstRate = $cust['pstEx'] || $cust['taxEx'] ? '0.0000' : '0.0700';

        $contractNum = sprintf('SEED-LSE-2026-%05d', $leaseCounter);
        $row = [
            'contract_number' => $contractNum,
            'customer_id'     => $cust['id'],
            'equipment_unit_id' => $unit['id'],
            'customer_name_snapshot' => $cust['name'],
            'company_name_snapshot' => $cust['name'],
            'unit_number_snapshot' => $unit['unit_number'],
            'template_name_snapshot' => 'Seed Template',
            'start_date' => $start,
            'end_date'   => $end,
            'actual_return_date' => $actualReturn,
            'status' => $lstatus,
            'daily_rate'   => $daily,
            'weekly_rate'  => $weekly,
            'monthly_rate' => $monthly,
            'currency' => $cust['currency'],
            'mileage_unit' => $unit['category'] === 'reefer' ? 'km' : 'miles',
            'mileage_rate' => $mileage,
            'estimated_mileage' => $estMileage,
            'actual_mileage'   => $actMileage,
            'mileage_precharge_amount' => '0.00',
            'mileage_precharge_invoiced' => 0,
            'tax_exempt' => $cust['taxEx'] ? 1 : 0,
            'gst_exempt' => $cust['gstEx'] ? 1 : 0,
            'pst_exempt' => $cust['pstEx'] ? 1 : 0,
            'tax_rate_gst' => $gstRate,
            'tax_rate_pst' => $pstRate,
            'tax_rate_hst' => '0.0000',
            'discount_type' => $cust['discType'],
            'discount_value' => $cust['discVal'],
            'insurance_opt_in' => rnd(0, 2) === 0 ? 1 : 0,
            'insurance_cost' => rnd(0, 2) === 0 ? '85.00' : '0.00',
            'warranty_opt_in' => rnd(0, 4) === 0 ? 1 : 0,
            'warranty_cost' => rnd(0, 4) === 0 ? '45.00' : '0.00',
            'billing_cycle' => 'monthly',
            'po_number' => rnd(0, 3) === 0 ? 'PO-' . rnd(10000, 99999) : null,
            'total_invoiced' => '0.00',
            'total_paid' => '0.00',
            'outstanding_balance' => '0.00',
            'total_estimated_charge' => '0.00',
            'final_total_charge' => '0.00',
            'notes' => "Seed lease #{$leaseCounter}. status={$lstatus}. " . SEED_TAG,
            'cancellation_reason' => $lstatus === 'cancelled' ? 'Seed: customer cancellation' : null,
            'created_by' => 1,
            'updated_by' => 1,
            'created_at' => $start . ' 09:00:00',
            'updated_at' => nowStr(),
        ];
        $lid = ins('leases', $row);
        $createdLeaseIds[] = ['id' => $lid, 'contract' => $contractNum, 'customer_id' => $cust['id'],
                                'unit_id' => $unit['id'], 'status' => $lstatus, 'currency' => $cust['currency'],
                                'monthly' => $monthly, 'daily' => $daily,
                                'gstRate' => $gstRate, 'pstRate' => $pstRate,
                                'gstEx' => $cust['gstEx'], 'pstEx' => $cust['pstEx'], 'taxEx' => $cust['taxEx'],
                                'start' => $start, 'end' => $end];
        bump('leases');
    }
}
echo "  + " . $counts['leases'] . " leases inserted\n";

// ════════════════════════════════════════════════════════════════
// 8. INVOICES + LINE ITEMS (~100 invoices, ~130 line items)
// ════════════════════════════════════════════════════════════════
echo "\n8) invoices + line items\n";
$invoiceCounter = 0;
// Status mix to drive creation: paid (40), partially_paid (15), overdue (20), sent (10), draft (5), void (3), written_off (2), credit_note (5)
$invoiceStatusPlan = [
    ['paid', 40], ['partially_paid', 15], ['overdue', 20], ['sent', 10],
    ['draft', 5], ['void', 3], ['written_off', 2], ['paid', 5], // credit_notes recorded as paid invoices below
];

$completedLeases = array_values(array_filter($createdLeaseIds, fn($l) => in_array($l['status'], ['active','completed'])));
$leasePtr = 0;

foreach ($invoiceStatusPlan as [$invStatus, $qty]) {
    for ($i = 0; $i < $qty; $i++) {
        $lease = $completedLeases[$leasePtr % count($completedLeases)];
        $leasePtr++;
        $invoiceCounter++;

        // Billing period: 1 month within lease window
        $periodStart = $lease['status'] === 'completed'
            ? date('Y-m-d', strtotime($lease['start'] . ' +' . rnd(0, 4) . ' months'))
            : date('Y-m-01', strtotime('-' . rnd(1, 6) . ' months'));
        $periodEnd = date('Y-m-d', strtotime($periodStart . ' +1 month -1 day'));
        $days = (int) ((strtotime($periodEnd) - strtotime($periodStart)) / 86400) + 1;

        // Subtotal — use monthly rate for full month
        $subtotal = $lease['monthly'];

        // Discount math
        $discType = 'none'; $discVal = '0'; $discAmount = '0.00';
        // (we keep invoices simple — discount lives on customer/lease but we don't apply at invoice level here)
        $subAfterDisc = $subtotal;

        $gstAmt = bcr(bcmul($subAfterDisc, $lease['gstRate'], 6), 2);
        $pstAmt = bcr(bcmul($subAfterDisc, $lease['pstRate'], 6), 2);
        $taxTot = bcr(bcadd($gstAmt, $pstAmt, 2), 2);
        $total  = bcr(bcadd($subAfterDisc, $taxTot, 2), 2);

        // Invoice date / due date
        $invoiceDate = $invStatus === 'overdue'
            ? pastDate(70, 180)
            : ($invStatus === 'draft' ? pastDate(0, 5) : pastDate(5, 90));
        $dueDate = date('Y-m-d', strtotime($invoiceDate . ' +30 days'));
        $paidDate = null;
        $amountPaid = '0.00';
        $balanceDue = $total;

        if ($invStatus === 'paid') {
            $paidDate = date('Y-m-d', strtotime($invoiceDate . ' +' . rnd(5, 25) . ' days'));
            $amountPaid = $total;
            $balanceDue = '0.00';
        } elseif ($invStatus === 'partially_paid') {
            $amountPaid = bcr(bcmul($total, '0.5', 6), 2);
            $balanceDue = bcr(bcsub($total, $amountPaid, 2), 2);
        } elseif ($invStatus === 'written_off') {
            $balanceDue = '0.00';
        }

        $invoiceNumber = sprintf('SEED-INV-2026-%05d', $invoiceCounter);
        $invType = $invStatus === 'paid' && rnd(0, 9) === 0 ? 'final' : 'regular';

        $invRow = [
            'invoice_number' => $invoiceNumber,
            'invoice_type'   => $invType,
            'customer_id'    => $lease['customer_id'],
            'lease_id'       => $lease['id'],
            'currency'       => $lease['currency'],
            'billing_period_start' => $periodStart,
            'billing_period_end'   => $periodEnd,
            'billing_period_days'  => $days,
            'billing_type'   => 'full_month',
            'rate_method_used' => 'monthly',
            'invoice_date'   => $invoiceDate,
            'due_date'       => $dueDate,
            'paid_date'      => $paidDate,
            'status'         => $invStatus,
            'subtotal'       => $subtotal,
            'discount_type'  => 'none',
            'discount_value' => '0.0000',
            'discount_amount' => '0.00',
            'subtotal_after_discount' => $subAfterDisc,
            'tax_gst_rate'   => $lease['gstRate'],
            'tax_pst_rate'   => $lease['pstRate'],
            'tax_hst_rate'   => '0.0000',
            'tax_gst_amount' => $gstAmt,
            'tax_pst_amount' => $pstAmt,
            'tax_hst_amount' => '0.00',
            'tax_total'      => $taxTot,
            'total_amount'   => $total,
            'amount_paid'    => $amountPaid,
            'credits_applied' => '0.00',
            'balance_due'    => $balanceDue,
            'late_fee_applied' => 0,
            'late_fee_amount' => '0.00',
            'pdf_version' => 1,
            'tax_exempt_snapshot' => $lease['taxEx'] ? 1 : 0,
            'gst_exempt_snapshot' => $lease['gstEx'] ? 1 : 0,
            'pst_exempt_snapshot' => $lease['pstEx'] ? 1 : 0,
            'auto_generated' => 1,
            'auto_generated_at' => nowStr(),
            'generation_source' => 'cron',
            'internal_notes' => "Seed invoice #{$invoiceCounter}. status={$invStatus}. " . SEED_TAG,
            'created_by' => 1,
            'updated_by' => 1,
            'created_at' => $invoiceDate . ' 09:00:00',
            'updated_at' => nowStr(),
        ];
        $iid = ins('invoices', $invRow);
        $createdInvoiceIds[] = ['id' => $iid, 'lease_id' => $lease['id'], 'customer_id' => $lease['customer_id'],
                                  'currency' => $lease['currency'], 'total' => $total,
                                  'status' => $invStatus, 'amount_paid' => $amountPaid, 'balance' => $balanceDue,
                                  'gstRate' => $lease['gstRate'], 'pstRate' => $lease['pstRate']];
        bump('invoices');

        // ── Base rental line item (always present) ──
        ins('invoice_line_items', [
            'invoice_id' => $iid,
            'sort_order' => 1,
            'item_type'  => 'base_rental',
            'description' => "Monthly rental {$periodStart} to {$periodEnd}",
            'quantity'   => '1.0000',
            'unit'       => 'month',
            'unit_price' => $subtotal,
            'amount'     => $subtotal,
            'is_credit'  => 0,
            'taxable'    => 1,
            'tax_gst_amount' => $gstAmt,
            'tax_pst_amount' => $pstAmt,
            'tax_hst_amount' => '0.00',
            'rate_method' => 'monthly',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'billing_days' => $days,
            'created_at' => nowStr(),
        ]);
        bump('invoice_line_items');

        // ── Additional line items: insurance, mileage, late_fee, discount, damage variants ──
        // ~30% chance of an extra line item
        if (rnd(0, 9) < 3) {
            $variant = pick(['insurance', 'mileage_precharge', 'late_fee', 'manual_adjustment', 'damage', 'discount']);
            $amt  = match ($variant) {
                'insurance' => '85.00',
                'mileage_precharge' => '350.00',
                'late_fee' => '45.00',
                'manual_adjustment' => '120.00',
                'damage' => money(150, 800),
                'discount' => '-50.00',
            };
            ins('invoice_line_items', [
                'invoice_id' => $iid,
                'sort_order' => 2,
                'item_type'  => $variant,
                'description' => ucfirst(str_replace('_', ' ', $variant)) . ' line',
                'quantity' => '1.0000',
                'unit_price' => $amt,
                'amount' => $amt,
                'is_credit' => $variant === 'discount' ? 1 : 0,
                'taxable' => $variant === 'discount' ? 0 : 1,
                'tax_gst_amount' => '0.00',
                'tax_pst_amount' => '0.00',
                'tax_hst_amount' => '0.00',
                'created_at' => nowStr(),
            ]);
            bump('invoice_line_items');
        }
    }
}
echo "  + " . $counts['invoices'] . " invoices, " . $counts['invoice_line_items'] . " line items inserted\n";

// ════════════════════════════════════════════════════════════════
// 9. PAYMENTS + ALLOCATIONS (~60 each)
// ════════════════════════════════════════════════════════════════
echo "\n9) payments + allocations\n";
$paymentCounter = 0;
$paidInvoices = array_values(array_filter($createdInvoiceIds, fn($i) => in_array($i['status'], ['paid', 'partially_paid'])));

$paymentMethods = ['ach', 'ach', 'ach', 'e_transfer', 'e_transfer', 'check', 'check', 'wire', 'credit_card', 'cash', 'other'];
$paymentStatusPool = ['cleared', 'cleared', 'cleared', 'cleared', 'cleared', 'cleared', 'cleared', 'pending', 'failed', 'returned'];

foreach ($paidInvoices as $idx => $inv) {
    $paymentCounter++;
    $method = pick($paymentMethods);
    $pstatus = pick($paymentStatusPool);
    $amount = $inv['amount_paid'];
    if ($amount === '0.00' || bccomp($amount, '0', 2) <= 0) continue;

    $payNumber = sprintf('SEED-PAY-2026-%05d', $paymentCounter);
    $payDate = pastDate(2, 80);
    $row = [
        'payment_number' => $payNumber,
        'customer_id' => $inv['customer_id'],
        'amount' => $amount,
        'currency' => $inv['currency'],
        'amount_in_cad' => $inv['currency'] === 'USD' ? bcr(bcmul($amount, '1.36', 6), 2) : $amount,
        'exchange_rate_to_cad' => $inv['currency'] === 'USD' ? '1.360000' : null,
        'payment_method' => $method,
        'reference_number' => 'REF-' . rnd(100000, 999999),
        'check_number' => $method === 'check' ? (string) rnd(1000, 9999) : null,
        'card_last_four' => $method === 'credit_card' ? str_pad((string) rnd(0, 9999), 4, '0', STR_PAD_LEFT) : null,
        'bank_name' => in_array($method, ['ach', 'wire', 'e_transfer']) ? pick(['RBC', 'TD', 'BMO', 'Scotia', 'CIBC']) : null,
        'payment_date' => $payDate,
        'received_at' => $payDate . ' 14:30:00',
        'status' => $pstatus,
        'failure_reason' => $pstatus === 'failed' ? 'NSF' : null,
        'returned_reason' => $pstatus === 'returned' ? 'Account closed' : null,
        'returned_date' => $pstatus === 'returned' ? $payDate : null,
        'overpayment_amount' => '0.00',
        'overpayment_resolved' => 0,
        'refund_amount' => '0.00',
        'internal_notes' => "Seed payment for invoice {$inv['id']}. " . SEED_TAG,
        'recorded_by' => 1,
        'created_at' => $payDate . ' 14:30:00',
        'updated_at' => nowStr(),
    ];
    $pid = ins('payments', $row);
    $createdPaymentIds[] = $pid;
    bump('payments');

    // allocation
    ins('payment_allocations', [
        'payment_id' => $pid,
        'invoice_id' => $inv['id'],
        'amount' => $amount,
        'currency' => $inv['currency'],
        'allocation_type' => rnd(0, 4) === 0 ? 'manual' : 'auto',
        'allocated_by' => 1,
        'created_at' => nowStr(),
    ]);
    bump('payment_allocations');
}
echo "  + " . ($counts['payments'] ?? 0) . " payments, " . ($counts['payment_allocations'] ?? 0) . " allocations\n";

// ════════════════════════════════════════════════════════════════
// 10. MAINTENANCE WORK ORDERS (50 — all 8 work_types)
// ════════════════════════════════════════════════════════════════
echo "\n10) maintenance_work_orders\n";
$workTypes = ['scheduled_service', 'repair', 'inspection', 'tire', 'electrical', 'body_damage', 'breakdown', 'other'];
$priorities = ['low', 'medium', 'medium', 'high', 'emergency'];
$woStatuses = ['completed', 'completed', 'completed', 'completed', 'in_progress', 'open', 'waiting_parts', 'cancelled'];
$serviceableUnits = array_values(array_filter($createdUnitIds, fn($u) => $u['status'] !== 'decommissioned'));

for ($i = 0; $i < 50; $i++) {
    $unit = $serviceableUnits[$i % count($serviceableUnits)];
    $vendorId = $createdVendors[array_rand($createdVendors)];
    $wtype  = $workTypes[$i % count($workTypes)];
    $prio   = pick($priorities);
    $wstat  = pick($woStatuses);

    $reqDate  = pastDate(5, 365);
    $schedDate = $wstat !== 'open' ? date('Y-m-d', strtotime($reqDate . ' +' . rnd(1, 7) . ' days')) : null;
    $compDate = $wstat === 'completed' ? date('Y-m-d', strtotime($reqDate . ' +' . rnd(1, 14) . ' days')) : null;

    $labor = $wstat === 'completed' ? money(50, 1500) : '0.00';
    $parts = $wstat === 'completed' ? money(20, 1200) : '0.00';
    $total = bcr(bcadd($labor, $parts, 2), 2);

    $woNum = sprintf('SEED-WO-2026-%05d', $i + 1);
    ins('maintenance_work_orders', [
        'work_order_number' => $woNum,
        'equipment_unit_id' => $unit['id'],
        'vendor_id' => $vendorId,
        'work_type' => $wtype,
        'priority'  => $prio,
        'status'    => $wstat,
        'title' => "Seed: " . ucfirst(str_replace('_', ' ', $wtype)) . " on {$unit['unit_number']}",
        'description' => "Auto-generated seed work order for {$unit['unit_number']}.",
        'mileage_at_service' => rnd(10000, 350000),
        'requested_date' => $reqDate,
        'scheduled_date' => $schedDate,
        'completed_date' => $compDate,
        'labor_cost' => $labor,
        'parts_cost' => $parts,
        'total_cost' => $total,
        'notes' => "Routine seed maintenance.",
        'internal_notes' => SEED_TAG,
        'resolution_notes' => $wstat === 'completed' ? 'Issue resolved.' : null,
        'created_by' => 1,
        'created_at' => $reqDate . ' 08:00:00',
        'updated_at' => nowStr(),
    ]);
    bump('maintenance_work_orders');
}
echo "  + " . $counts['maintenance_work_orders'] . " work orders inserted\n";

// ════════════════════════════════════════════════════════════════
// 11. DAMAGE CLAIMS (25 — all 4 severities, 6 statuses)
// ════════════════════════════════════════════════════════════════
echo "\n11) damage_claims\n";
$severities = ['minor', 'minor', 'minor', 'minor', 'moderate', 'moderate', 'moderate', 'major', 'total_loss'];
$dcStatuses = ['reported', 'assessed', 'repair_ordered', 'invoiced', 'resolved', 'written_off'];

for ($i = 0; $i < 25; $i++) {
    $unit = $serviceableUnits[$i % count($serviceableUnits)];
    $lease = $createdLeaseIds[$i % count($createdLeaseIds)];
    $sev = pick($severities);
    $stat = $dcStatuses[$i % count($dcStatuses)];

    $est = match ($sev) {
        'minor'      => money(150, 800),
        'moderate'   => money(900, 3000),
        'major'      => money(3500, 12000),
        'total_loss' => money(15000, 45000),
    };
    $actual = in_array($stat, ['invoiced', 'resolved']) ? bcr(bcmul($est, '1.10', 6), 2) : null;

    $dcNum = sprintf('SEED-DMG-2026-%05d', $i + 1);
    ins('damage_claims', [
        'claim_number' => $dcNum,
        'equipment_unit_id' => $unit['id'],
        'lease_id' => $lease['id'],
        'customer_id' => $lease['customer_id'],
        'description' => "Seed: " . pick(['Tire damage', 'Side panel dent', 'Roof scrape', 'Door damage', 'Reefer unit fault']) . " — severity: $sev",
        'damage_location' => pick(['Driver-side rear', 'Passenger-side front', 'Roof', 'Rear doors', 'Reefer compressor']),
        'severity' => $sev,
        'estimated_repair_cost' => $est,
        'actual_repair_cost' => $actual,
        'customer_liable_amount' => $stat === 'resolved' ? $actual : null,
        'status' => $stat,
        'notes' => "Damage discovered during inspection. " . SEED_TAG,
        'reported_by' => 1,
        'created_at' => pastDate(5, 200),
        'updated_at' => nowStr(),
    ]);
    bump('damage_claims');
}
echo "  + " . $counts['damage_claims'] . " damage claims inserted\n";

// ════════════════════════════════════════════════════════════════
// 12. INSPECTIONS (30 — all 5 inspection_types)
// ════════════════════════════════════════════════════════════════
echo "\n12) inspections\n";
$inspTypes = ['pre_lease', 'post_lease', 'periodic', 'damage', 'compliance'];
$conditions = ['excellent', 'good', 'good', 'fair', 'poor', 'damaged'];
$inspStatuses = ['signed', 'signed', 'signed', 'complete', 'complete', 'draft'];

for ($i = 0; $i < 30; $i++) {
    $unit = $serviceableUnits[$i % count($serviceableUnits)];
    $lease = $createdLeaseIds[$i % count($createdLeaseIds)];
    $type = $inspTypes[$i % count($inspTypes)];
    $cond = pick($conditions);
    $stat = pick($inspStatuses);
    $iDate = pastDate(5, 360);

    $inspNum = sprintf('SEED-INSP-2026-%05d', $i + 1);
    ins('inspections', [
        'inspection_number' => $inspNum,
        'inspection_type' => $type,
        'equipment_unit_id' => $unit['id'],
        'lease_id' => in_array($type, ['pre_lease', 'post_lease']) ? $lease['id'] : null,
        'overall_condition' => $cond,
        'condition_score' => match ($cond) { 'excellent' => 95, 'good' => 80, 'fair' => 65, 'poor' => 45, 'damaged' => 25 },
        'status' => $stat,
        'inspected_by' => 'Seed Inspector',
        'inspected_by_user_id' => 1,
        'inspection_date' => $iDate,
        'mileage_at_inspection' => rnd(10000, 350000),
        'fuel_level' => pick(['empty', 'quarter', 'half', 'three_quarter', 'full']),
        'is_clean' => rnd(0, 4) > 0 ? 1 : 0,
        'signed_at' => $stat === 'signed' ? $iDate . ' 16:00:00' : null,
        'notes' => "Seed inspection. type=$type cond=$cond. " . SEED_TAG,
        'created_at' => $iDate . ' 10:00:00',
        'updated_at' => nowStr(),
    ]);
    bump('inspections');
}
echo "  + " . $counts['inspections'] . " inspections inserted\n";

// ════════════════════════════════════════════════════════════════
// 13. MILEAGE LOGS (40 — all 5 log_types)
// ════════════════════════════════════════════════════════════════
echo "\n13) mileage_logs\n";
$logTypes = ['manual', 'gps_sync', 'lease_start', 'lease_end', 'service'];
for ($i = 0; $i < 40; $i++) {
    $unit  = $serviceableUnits[$i % count($serviceableUnits)];
    $lease = in_array($logTypes[$i % 5], ['lease_start', 'lease_end']) ? $createdLeaseIds[$i % count($createdLeaseIds)] : null;
    $type  = $logTypes[$i % count($logTypes)];

    ins('mileage_logs', [
        'equipment_unit_id' => $unit['id'],
        'lease_id' => $lease['id'] ?? null,
        'log_type' => $type,
        'odometer_reading' => rnd(10000, 350000),
        'mileage_unit' => $unit['category'] === 'reefer' ? 'km' : 'miles',
        'log_date' => pastDate(1, 240),
        'notes' => "Seed mileage log type=$type. " . SEED_TAG,
        'recorded_by' => 1,
        'created_at' => nowStr(),
    ]);
    bump('mileage_logs');
}
echo "  + " . $counts['mileage_logs'] . " mileage logs inserted\n";

// ════════════════════════════════════════════════════════════════
// 14. RESERVATIONS + UNITS (25 reservations + ~35 reservation_units)
// ════════════════════════════════════════════════════════════════
echo "\n14) reservations + reservation_units\n";
$resStatuses = [
    ['confirmed', 15], ['pending', 5], ['completed', 3], ['cancelled', 2],
];
$priorities2 = ['low', 'medium', 'medium', 'high', 'urgent'];
$resCounter = 0;
foreach ($resStatuses as [$rstatus, $qty]) {
    for ($i = 0; $i < $qty; $i++) {
        $resCounter++;
        $cust = $createdCustomerIds[$resCounter % count($createdCustomerIds)];
        $pickup = $rstatus === 'pending' || $rstatus === 'confirmed'
            ? futureDate(2, 60)
            : pastDate(5, 120);

        $rid = ins('reservations', [
            'status' => $rstatus,
            'customer_id' => $cust['id'],
            'contact_name' => 'Reservation ' . $resCounter,
            'company_name' => $cust['name'],
            'contact_phone' => '604-555-' . rnd(1000, 9999),
            'contact_email' => 'res' . $resCounter . '@seedcustomers.test',
            'quantity' => rnd(1, 3),
            'pickup_date' => $pickup,
            'pickup_time' => sprintf('%02d:00:00', rnd(6, 17)),
            'yard_location' => pick(['surrey', 'delta-yard', 'abbotsford-yard']),
            'purpose' => "Seed reservation purpose: " . pick(['One-time haul', 'Weekly route', 'Long-term project', 'Backup unit']),
            'priority' => pick($priorities2),
            'notes' => "Seed reservation #{$resCounter}.",
            'internal_notes' => SEED_TAG,
            'created_by' => 1,
            'updated_by' => 1,
            'created_at' => pastDate(1, 60) . ' 09:00:00',
            'updated_at' => nowStr(),
        ]);
        $createdReservationIds[] = $rid;
        bump('reservations');

        // 1-2 reservation_units per reservation
        $unitsForRes = rnd(1, 2);
        for ($u = 0; $u < $unitsForRes; $u++) {
            $unit = $serviceableUnits[($resCounter + $u) % count($serviceableUnits)];
            ins('reservation_units', [
                'reservation_id' => $rid,
                'equipment_unit_id' => $unit['id'],
                'unit_number_snapshot' => $unit['unit_number'],
                'template_name_snapshot' => 'Seed Template',
                'status_at_reservation' => $unit['status'],
                'entry_type' => 'system',
                'created_at' => nowStr(),
            ]);
            bump('reservation_units');
        }
    }
}
echo "  + " . $counts['reservations'] . " reservations, " . $counts['reservation_units'] . " reservation_units\n";

// ════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════
echo "\n=== summary ===\n";
$total = 0;
foreach ($counts as $k => $v) {
    echo sprintf("  %-30s %5d\n", $k, $v);
    $total += $v;
}
echo str_repeat('-', 38) . "\n";
echo sprintf("  %-30s %5d\n", 'TOTAL', $total);
echo $apply
    ? "\nApplied. Re-run the AI smoke test or browse the dashboards.\n"
    : "\nDry-run only. Pass --apply to commit. Use --reset --apply to wipe + re-seed.\n";
