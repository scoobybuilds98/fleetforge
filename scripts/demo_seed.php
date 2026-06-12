<?php
/**
 * FleetForge Demo Seed
 *
 * Creates a focused demo dataset for a live presentation:
 *   - 4 customers (Tonny Bhinder, Arsh Puar, Mike Lepore, Avi)
 *   - 2 bank accounts (CAD + USD)
 *   - 4 customer-specific rate cards (1 per customer; S-RATES-CONSOLIDATE — the
 *     card is the single source of customer pricing, no overrides seeded)
 *   - 20 leases (5 per customer) with varied complexity
 *   - ~40 invoices covering historical + current billing periods
 *   - ~30 payments (paid, partial, overdue mix)
 *   - 20 fixed assets linked 1:1 to the leased equipment units
 *   - Journal entries auto-posted for all invoice + payment activity
 *
 * Run /tmp/ff_demo_wipe.php first to start from a clean database.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/Billing/InvoiceGenerator.php';

$userId = 1;
$today  = new DateTime();

function daysAgo(int $n): string { return (new DateTime())->modify("-{$n} days")->format('Y-m-d'); }
function daysFromNow(int $n): string { return (new DateTime())->modify("+{$n} days")->format('Y-m-d'); }
function unique_code(): string { return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)); }

echo "=== FleetForge demo seed ===\n\n";

// ────────────────────────────────────────────────────────────
// Step 1 — Bank accounts (needed before any AR activity)
// ────────────────────────────────────────────────────────────
echo "Step 1 — Creating bank accounts...\n";

$cashCadId = db_row("SELECT id FROM acc_accounts WHERE code = '1010'", [])['id'];
$cashUsdId = db_row("SELECT id FROM acc_accounts WHERE code = '1020'", [])['id'];

$bankCadId = db_insert('acc_bank_accounts', [
    'name'                 => 'RBC — Operating Account',
    'account_number_last4' => '4821',
    'institution'          => 'Royal Bank of Canada',
    'account_type'         => 'checking',
    'currency'             => 'CAD',
    'gl_account_id'        => (int) $cashCadId,
    'opening_balance'      => '125000.00',
    'opening_balance_date' => daysAgo(180),
    'is_active'            => 1,
    'is_default'           => 1,
    'created_by'           => $userId,
]);
$bankUsdId = db_insert('acc_bank_accounts', [
    'name'                 => 'Wells Fargo — USD Account',
    'account_number_last4' => '7230',
    'institution'          => 'Wells Fargo',
    'account_type'         => 'checking',
    'currency'             => 'USD',
    'gl_account_id'        => (int) $cashUsdId,
    'opening_balance'      => '35000.00',
    'opening_balance_date' => daysAgo(180),
    'is_active'            => 1,
    'is_default'           => 0,
    'created_by'           => $userId,
]);
echo "  Bank id=$bankCadId (CAD), id=$bankUsdId (USD)\n";

// Seed a current USD→CAD exchange rate so USD invoices get a conversion snapshot.
// Idempotent: uses INSERT IGNORE so re-runs don't trip the unique key.
db_execute(
    "INSERT IGNORE INTO exchange_rates (from_currency, to_currency, rate, rate_date, source)
     VALUES ('USD', 'CAD', '1.375000', ?, 'manual')",
    [date('Y-m-d')]
);
echo "  FX rate seeded: 1 USD = 1.375 CAD\n";

// ────────────────────────────────────────────────────────────
// Step 2 — Customers
// ────────────────────────────────────────────────────────────
echo "\nStep 2 — Creating customers...\n";

$customerDefs = [
    'tonny' => [
        'contact_name'  => 'Tonny Bhinder',
        'company_name'  => 'LP Logistics Inc.',
        'email'         => 'tonny@lplogistics.ca',
        'phone'         => '604-555-0123',
        'address'       => '12345 104 Ave',
        'city'          => 'Surrey',
        'province'      => 'BC',
        'country'       => 'Canada',
        'postal_code'   => 'V3T 1V8',
        'currency'      => 'CAD',
        'mileage_unit'  => 'km',
        'tax_rate_id'   => 1,
        'gst_exempt'    => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'billing_cycle' => 'monthly',
        'payment_terms' => 'Net 30',
        'credit_limit'  => '50000.00',
        'risk_score'    => 'low',
        'status'        => 'active',
        'notes'         => 'Established carrier — 3+ years with us. Reliable, prefers GPS-tracked dry vans.',
    ],
    'arsh' => [
        'contact_name'  => 'Arsh Puar',
        'company_name'  => 'Puar Transport Ltd.',
        'email'         => 'arsh@puartransport.ca',
        'phone'         => '416-555-0456',
        'address'       => '789 Dixon Rd',
        'city'          => 'Toronto',
        'province'      => 'ON',
        'country'       => 'Canada',
        'postal_code'   => 'M9W 1J6',
        'currency'      => 'CAD',
        'mileage_unit'  => 'km',
        'tax_rate_id'   => 2,
        'gst_exempt'    => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'billing_cycle' => 'monthly',
        'payment_terms' => 'Net 15',
        'credit_limit'  => '25000.00',
        'risk_score'    => 'medium',
        'status'        => 'active',
        'notes'         => 'Growing carrier — added 3 trucks last quarter. Mixed equipment needs.',
    ],
    'mike' => [
        'contact_name'        => 'Mike Lepore',
        'company_name'        => 'Lepore Enterprise Trucking',
        'email'               => 'mike@leporetrucking.com',
        'phone'               => '780-555-0789',
        'address'             => '10025 103 Ave NW',
        'city'                => 'Edmonton',
        'province'            => 'AB',
        'country'             => 'Canada',
        'postal_code'         => 'T5J 0H6',
        'currency'            => 'USD',
        'mileage_unit'        => 'miles',
        'tax_rate_id'         => 3,
        'gst_exempt'          => 1, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'tax_exempt_number'   => 'GST-EXEMPT-12345',
        'billing_cycle'       => 'monthly',
        'payment_terms'       => 'Net 45',
        'credit_limit'        => '150000.00',
        'risk_score'          => 'low',
        'status'              => 'active',
        'notes'               => 'Enterprise account — cross-border USD billing. GST-exempt status on file.',
    ],
    'avi' => [
        'contact_name'  => 'Avi',
        'company_name'  => 'Avi\'s Trucking',
        'email'         => 'avi@avistrucking.ca',
        'phone'         => '604-555-0321',
        'address'       => '8888 King George Blvd',
        'city'          => 'Surrey',
        'province'      => 'BC',
        'country'       => 'Canada',
        'postal_code'   => 'V3W 5B2',
        'currency'      => 'CAD',
        'mileage_unit'  => 'km',
        'tax_rate_id'   => 1,
        'gst_exempt'    => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'billing_cycle' => 'monthly',
        'payment_terms' => 'Net 30',
        'credit_limit'  => '40000.00',
        'risk_score'    => 'low',
        'status'        => 'active',
        'notes'         => 'Owner-operator — runs diverse equipment types for regional hauls.',
    ],
];

$customerIds = [];
foreach ($customerDefs as $key => $def) {
    $def['created_by'] = $userId;
    $def['updated_by'] = $userId;
    $id = db_insert('customers', $def);
    $customerIds[$key] = $id;
    printf("  id=%d → %s (%s) %s\n", $id, $def['contact_name'], $def['company_name'], $def['currency']);
}

// ────────────────────────────────────────────────────────────
// Step 3 — Pick 20 equipment units
// ────────────────────────────────────────────────────────────
echo "\nStep 3 — Picking equipment units...\n";

$pickedIds = [];
function pickUnit(string $category, bool $needSamsara = false): array
{
    global $pickedIds;
    $where = "u.deleted_at IS NULL AND u.status = 'available' AND t.category = ?"
           . ($needSamsara ? " AND u.samsara_vehicle_id IS NOT NULL" : "");
    if (!empty($pickedIds)) {
        $where .= " AND u.id NOT IN (" . implode(',', array_map('intval', $pickedIds)) . ")";
    }
    $row = db_row(
        "SELECT u.id, u.unit_number, u.template_id, u.vin, u.year, u.length_ft, u.width_ft,
                u.height_ft, u.axle_count, u.weight_capacity_lbs, u.license_plate, u.license_state,
                u.tracking_provider, u.mileage, u.samsara_vehicle_id, u.samsara_odometer_km,
                t.name AS template_name, t.category, t.brand, t.model,
                t.default_daily_rate, t.default_weekly_rate, t.default_monthly_rate, t.default_mileage_rate
           FROM equipment_units u
           JOIN equipment_templates t ON t.id = u.template_id AND t.deleted_at IS NULL
          WHERE {$where}
          ORDER BY u.id ASC LIMIT 1",
        [$category]
    );
    if (!$row) throw new RuntimeException("No available {$category}" . ($needSamsara ? " with Samsara" : "") . " left.");
    $pickedIds[] = (int) $row['id'];
    return $row;
}

$units = [
    't1' => pickUnit('dry_van', true),
    't2' => pickUnit('reefer'),
    't3' => pickUnit('chassis'),
    't4' => pickUnit('flatbed'),
    't5' => pickUnit('container'),
    'a1' => pickUnit('dry_van', true),
    'a2' => pickUnit('step_deck'),
    'a3' => pickUnit('dry_van'),
    'a4' => pickUnit('reefer'),
    'a5' => pickUnit('lowboy'),
    'm1' => pickUnit('dry_van', true),
    'm2' => pickUnit('reefer'),
    'm3' => pickUnit('tanker'),
    'm4' => pickUnit('dry_van'),
    'm5' => pickUnit('flatbed'),
    'v1' => pickUnit('reefer'),
    'v2' => pickUnit('chassis'),
    'v3' => pickUnit('dry_van', true),
    'v4' => pickUnit('container'),
    'v5' => pickUnit('dump'),
];
foreach ($units as $k => $u) printf("  %s → id=%d %s (%s)\n", $k, $u['id'], $u['unit_number'], $u['category']);

// ────────────────────────────────────────────────────────────
// Step 4 — Rate cards + customer_equipment_rates
// ────────────────────────────────────────────────────────────
echo "\nStep 4 — Creating rate cards...\n";

$rateCardDefs = [
    // Each customer gets a dedicated rate card with their negotiated rates
    'tonny' => [
        'name'        => 'LP Logistics — Contracted Rates',
        'description' => 'Long-term customer rate card. 3-year renewable terms, net 30 payment.',
        'items'       => [
            ['equipment_type' => 'dry_van',   'daily' => '125.00', 'weekly' => '750.00', 'monthly' => '2200.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'reefer',    'daily' => '145.00', 'weekly' => '950.00', 'monthly' => '3200.00', 'mileage' => '0.1800', 'currency' => 'CAD'],
            ['equipment_type' => 'chassis',   'daily' => '80.00',  'weekly' => '480.00', 'monthly' => '1600.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'flatbed',   'daily' => '130.00', 'weekly' => '780.00', 'monthly' => '2470.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'container', 'daily' => '60.00',  'weekly' => '360.00', 'monthly' => '1200.00', 'mileage' => '0.1200', 'currency' => 'CAD'],
        ],
    ],
    'arsh' => [
        'name'        => 'Puar Transport — Flex Rate Card',
        'description' => 'Mixed-equipment rate card with premium reefer package. Net 15 terms.',
        'items'       => [
            ['equipment_type' => 'dry_van',   'daily' => '135.00', 'weekly' => '810.00', 'monthly' => '2400.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'step_deck', 'daily' => '140.00', 'weekly' => '840.00', 'monthly' => '2800.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'reefer',    'daily' => '160.00', 'weekly' => '1050.00','monthly' => '3600.00', 'mileage' => '0.2500', 'currency' => 'CAD'],
            ['equipment_type' => 'lowboy',    'daily' => '180.00', 'weekly' => '1080.00','monthly' => '3600.00', 'mileage' => '0.3500', 'currency' => 'CAD'],
        ],
    ],
    'mike' => [
        'name'        => 'Lepore Enterprise — USD Rate Card',
        'description' => 'Cross-border USD rate card with GST-exempt flag. Net 45 corporate terms.',
        'items'       => [
            ['equipment_type' => 'dry_van', 'daily' => '110.00', 'weekly' => '660.00',  'monthly' => '1950.00', 'mileage' => '0.0000', 'currency' => 'USD'],
            ['equipment_type' => 'reefer',  'daily' => '135.00', 'weekly' => '850.00',  'monthly' => '2850.00', 'mileage' => '0.1500', 'currency' => 'USD'],
            ['equipment_type' => 'tanker',  'daily' => '170.00', 'weekly' => '1020.00', 'monthly' => '3400.00', 'mileage' => '0.4000', 'currency' => 'USD'],
            ['equipment_type' => 'flatbed', 'daily' => '150.00', 'weekly' => '900.00',  'monthly' => '3000.00', 'mileage' => '0.0000', 'currency' => 'USD'],
        ],
    ],
    'avi' => [
        'name'        => 'Avi Trucking — Owner-Op Rates',
        'description' => 'Owner-operator package with varied equipment types for regional work.',
        'items'       => [
            ['equipment_type' => 'reefer',    'daily' => '145.00', 'weekly' => '950.00', 'monthly' => '3200.00', 'mileage' => '0.1800', 'currency' => 'CAD'],
            ['equipment_type' => 'dry_van',   'daily' => '125.00', 'weekly' => '750.00', 'monthly' => '2200.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'chassis',   'daily' => '80.00',  'weekly' => '480.00', 'monthly' => '1600.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'container', 'daily' => '60.00',  'weekly' => '360.00', 'monthly' => '1200.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
            ['equipment_type' => 'dump',      'daily' => '150.00', 'weekly' => '900.00', 'monthly' => '3000.00', 'mileage' => '0.0000', 'currency' => 'CAD'],
        ],
    ],
];

$rateCardIds = [];
foreach ($rateCardDefs as $key => $def) {
    $rcId = db_insert('rate_cards', [
        'name'           => $def['name'],
        'description'    => $def['description'],
        'is_default'     => 0,
        // S-RATES-CONSOLIDATE: bind the card to its customer so lookup_rates.php
        // prefers it per-customer (was previously a global card backed by overrides).
        'customer_id'    => $customerIds[$key],
        'effective_from' => daysAgo(180),
        'created_by'     => $userId,
    ]);
    $rateCardIds[$key] = $rcId;

    foreach ($def['items'] as $item) {
        db_insert('rate_card_items', [
            'rate_card_id'   => $rcId,
            'equipment_type' => $item['equipment_type'],
            'daily_rate'     => $item['daily'],
            'weekly_rate'    => $item['weekly'],
            'monthly_rate'   => $item['monthly'],
            'mileage_rate'   => $item['mileage'],
            'mileage_unit'   => $customerDefs[$key]['mileage_unit'],
            'currency'       => $item['currency'],
        ]);
    }
    printf("  Rate card id=%d for %s → %d items\n", $rcId, $key, count($def['items']));
    // S-RATES-CONSOLIDATE: the customer-specific rate card above is now the
    // single source of customer pricing — lookup_rates.php reads it directly,
    // so no separate customer_equipment_rates rows are seeded.
}

// ────────────────────────────────────────────────────────────
// Step 5 — Fixed assets (linked to every leased unit)
// ────────────────────────────────────────────────────────────
echo "\nStep 5 — Creating fixed assets...\n";

$feCostAcctId     = (int) db_row("SELECT id FROM acc_accounts WHERE code = '1210'", [])['id'];
$feAccumDeprId    = (int) db_row("SELECT id FROM acc_accounts WHERE code = '1220'", [])['id'];
$deprExpenseId    = (int) db_row("SELECT id FROM acc_accounts WHERE code = '5010'", [])['id'];

$assetIdByUnit = [];
$assetNum = 1;
foreach ($units as $key => $unit) {
    // Vary acquisition cost by category
    $cost = match ($unit['category']) {
        'dry_van'   => '58000.00',
        'reefer'    => '95000.00',
        'chassis'   => '25000.00',
        'flatbed'   => '68000.00',
        'container' => '8500.00',
        'step_deck' => '72000.00',
        'lowboy'    => '110000.00',
        'tanker'    => '120000.00',
        'dump'      => '75000.00',
        default     => '50000.00',
    };
    // Vary age — older units have more accumulated depreciation
    $ageMonths = random_int(18, 60);
    $usefulLifeYears = '10.00';
    $salvage = (string) round((float) $cost * 0.10, 2);
    $depreciable = (string) round((float) $cost - (float) $salvage, 2);
    $monthlyDepr = (float) $depreciable / (10 * 12);
    $accumDepr = (string) round($monthlyDepr * $ageMonths, 2);
    $nbv = (string) round((float) $cost - (float) $accumDepr, 2);

    $isFinanced = (int) ($ageMonths < 48);  // newer units more likely to be financed
    $monthlyPayment = $isFinanced ? (string) round((float) $cost / 60, 2) : '0.00';
    $remainingMonths = $isFinanced ? max(0, 60 - $ageMonths) : 0;

    $assetId = db_insert('acc_fixed_assets', [
        'asset_number'              => sprintf('FA-%s-%05d', date('Y'), $assetNum++),
        'name'                      => $unit['template_name'] . ' — ' . $unit['unit_number'],
        'description'               => 'Fleet asset, category: ' . $unit['category'],
        'asset_class'               => 'fleet_equipment',
        'cra_class'                 => '10',  // Class 10 = 30% DB
        'cra_cca_rate'              => '0.3000',
        'equipment_unit_id'         => (int) $unit['id'],
        'acquisition_date'          => daysAgo($ageMonths * 30),
        'acquisition_cost'          => $cost,
        'purchase_tax_gst'          => (string) round((float) $cost * 0.05, 2),
        'purchase_tax_pst'          => (string) round((float) $cost * 0.07, 2),
        'delivery_cost'             => '850.00',
        'setup_cost'                => '0.00',
        'is_financed'               => $isFinanced,
        'financing_monthly_payment' => $monthlyPayment,
        'financing_interest_rate'   => $isFinanced ? '0.0695' : '0.0000',
        'financing_remaining_months'=> $remainingMonths,
        'monthly_insurance_cost'    => '125.00',
        'monthly_licensing_cost'    => '85.00',
        'monthly_registration_cost' => '45.00',
        'depreciation_method'       => 'straight_line',
        'useful_life_years'         => $usefulLifeYears,
        'salvage_value'             => $salvage,
        'depreciable_cost'          => $depreciable,
        'accumulated_depreciation'  => $accumDepr,
        'net_book_value'            => $nbv,
        'depreciation_start_date'   => daysAgo($ageMonths * 30),
        'last_depreciation_date'    => daysAgo(1),
        'asset_account_id'          => $feCostAcctId,
        'accum_depr_account_id'     => $feAccumDeprId,
        'depr_expense_account_id'   => $deprExpenseId,
        'status'                    => 'active',
        'location'                  => 'Surrey Yard',
        'serial_number'             => $unit['vin'] ?? null,
        'created_by'                => $userId,
    ]);
    $assetIdByUnit[$unit['id']] = $assetId;
}
printf("  %d fixed assets created, linked 1:1 to equipment units\n", count($assetIdByUnit));

// ────────────────────────────────────────────────────────────
// Step 6 — Leases
// ────────────────────────────────────────────────────────────
echo "\nStep 6 — Creating leases...\n";

function buildEquipSnap(array $u): string
{
    return json_encode([
        'vin'               => $u['vin'],
        'year'              => $u['year'] ? (int) $u['year'] : null,
        'category'          => $u['category'],
        'width_ft'          => $u['width_ft'],
        'height_ft'         => $u['height_ft'],
        'length_ft'         => $u['length_ft'],
        'axle_count'        => $u['axle_count'] ? (int) $u['axle_count'] : null,
        'unit_number'       => $u['unit_number'],
        'license_plate'     => $u['license_plate'],
        'license_state'     => $u['license_state'],
        'template_name'     => $u['template_name'],
        'tracking_provider' => $u['tracking_provider'],
        'mileage_at_snapshot'=> (int) ($u['mileage'] ?? 0),
        'weight_capacity_lbs'=> $u['weight_capacity_lbs'] ? (int) $u['weight_capacity_lbs'] : null,
    ]);
}

function freezeTax(int $taxRateId, int $gstEx, int $pstEx, int $taxEx): array
{
    $row = db_row("SELECT gst_rate, pst_rate, hst_rate FROM tax_rates WHERE id = ?", [$taxRateId]);
    return [
        'gst' => $taxEx || $gstEx ? '0.0000' : $row['gst_rate'],
        'pst' => $taxEx || $pstEx ? '0.0000' : $row['pst_rate'],
        'hst' => $taxEx ? '0.0000' : $row['hst_rate'],
    ];
}

$leases = [
    // ── Tonny Bhinder (5) ──
    [
        'label' => 'T1 — Simple monthly dry van + Samsara GPS odometer',
        'customer' => 'tonny', 'unit' => 't1', 'status' => 'active',
        'start_date' => daysAgo(58), 'end_date' => daysFromNow(307),
        'daily_rate' => '125.00', 'weekly_rate' => '750.00', 'monthly_rate' => '2200.00', 'mileage_rate' => '0.0000',
        'estimated_mileage' => '8000.00',
        'notes' => 'Standard dry van rental — regional BC/AB routes.',
        'next_billing_date' => daysFromNow(2),
        'odometer_from_unit' => true,
    ],
    [
        'label' => 'T2 — Reefer w/ insurance + mileage charges',
        'customer' => 'tonny', 'unit' => 't2', 'status' => 'active',
        'start_date' => daysAgo(32), 'end_date' => daysFromNow(333),
        'daily_rate' => '145.00', 'weekly_rate' => '950.00', 'monthly_rate' => '3200.00', 'mileage_rate' => '0.1800',
        'estimated_mileage' => '6000.00',
        'insurance_opt_in' => 1, 'insurance_cost' => '185.00',
        'notes' => 'Refrigerated unit for produce runs. Insurance add-on included.',
        'next_billing_date' => daysFromNow(28),
        'odometer_start_km' => '42385.00',
    ],
    [
        'label' => 'T3 — Chassis weekly rental (COMPLETED)',
        'customer' => 'tonny', 'unit' => 't3', 'status' => 'completed',
        'start_date' => daysAgo(45), 'end_date' => daysAgo(17), 'actual_return_date' => daysAgo(15),
        'daily_rate' => '80.00', 'weekly_rate' => '480.00', 'monthly_rate' => '1600.00', 'mileage_rate' => '0.0000',
        'mileage_at_start' => 51200, 'mileage_at_end' => 52870, 'actual_mileage' => '1670.00',
        'odometer_start_km' => '51200.00',
        'notes' => 'Short-term chassis pull for container drayage. Successfully closed.',
    ],
    [
        'label' => 'T4 — Flatbed w/ 5% loyalty discount',
        'customer' => 'tonny', 'unit' => 't4', 'status' => 'active',
        'start_date' => daysAgo(14), 'end_date' => daysFromNow(351),
        'daily_rate' => '130.00', 'weekly_rate' => '780.00', 'monthly_rate' => '2470.00', 'mileage_rate' => '0.0000',
        'discount_type' => 'percentage', 'discount_value' => '5.00',
        'rate_notes' => 'Loyalty discount — 5% off monthly rate for repeat customer.',
        'next_billing_date' => daysFromNow(16),
    ],
    [
        'label' => 'T5 — Container w/ on-close billing + mileage',
        'customer' => 'tonny', 'unit' => 't5', 'status' => 'active',
        'start_date' => daysAgo(7), 'end_date' => null,
        'daily_rate' => '60.00', 'weekly_rate' => '360.00', 'monthly_rate' => '1200.00', 'mileage_rate' => '0.1200',
        'billing_cycle' => 'on_close_only',
        'estimated_mileage' => '2500.00',
        'notes' => 'Flexible rental — billed only on return. Mileage charged at close.',
    ],

    // ── Arsh Puar (5) ──
    [
        'label' => 'A1 — Dry van Samsara PENDING',
        'customer' => 'arsh', 'unit' => 'a1', 'status' => 'pending',
        'start_date' => daysFromNow(7), 'end_date' => daysFromNow(37),
        'daily_rate' => '135.00', 'weekly_rate' => '810.00', 'monthly_rate' => '2400.00', 'mileage_rate' => '0.0000',
        'notes' => 'New 1-month trial rental. Contract signed, awaiting pickup next week.',
    ],
    [
        'label' => 'A2 — Step deck w/ PO number',
        'customer' => 'arsh', 'unit' => 'a2', 'status' => 'active',
        'start_date' => daysAgo(20), 'end_date' => daysFromNow(40),
        'daily_rate' => '140.00', 'weekly_rate' => '840.00', 'monthly_rate' => '2800.00', 'mileage_rate' => '0.0000',
        'po_number' => 'PO-PUAR-2026-0042',
        'notes' => 'Oversize machinery hauling. Customer requires PO on all invoices.',
        'next_billing_date' => daysFromNow(10),
    ],
    [
        'label' => 'A3 — Dry van weekly billing',
        'customer' => 'arsh', 'unit' => 'a3', 'status' => 'active',
        'start_date' => daysAgo(9), 'end_date' => daysFromNow(21),
        'daily_rate' => '125.00', 'weekly_rate' => '750.00', 'monthly_rate' => '2200.00', 'mileage_rate' => '0.0000',
        'notes' => '30-day weekly-billed van for seasonal overflow capacity.',
        'next_billing_date' => daysFromNow(5),
    ],
    [
        'label' => 'A4 — Reefer w/ insurance + warranty + high mileage',
        'customer' => 'arsh', 'unit' => 'a4', 'status' => 'active',
        'start_date' => daysAgo(62), 'end_date' => daysFromNow(303),
        'daily_rate' => '160.00', 'weekly_rate' => '1050.00', 'monthly_rate' => '3600.00', 'mileage_rate' => '0.2500',
        'insurance_opt_in' => 1, 'insurance_cost' => '220.00',
        'warranty_opt_in' => 1, 'warranty_cost' => '140.00',
        'estimated_mileage' => '12000.00',
        'rate_notes' => 'Premium reefer package — full coverage + higher mileage allowance.',
        'next_billing_date' => daysFromNow(1),
    ],
    [
        'label' => 'A5 — Lowboy COMPLETED w/ mileage overage',
        'customer' => 'arsh', 'unit' => 'a5', 'status' => 'completed',
        'start_date' => daysAgo(90), 'end_date' => daysAgo(35), 'actual_return_date' => daysAgo(30),
        'daily_rate' => '180.00', 'weekly_rate' => '1080.00', 'monthly_rate' => '3600.00', 'mileage_rate' => '0.3500',
        'estimated_mileage' => '3000.00',
        'mileage_at_start' => 28400, 'mileage_at_end' => 32100, 'actual_mileage' => '3700.00',
        'odometer_start_km' => '28400.00',
        'notes' => 'Heavy equipment hauling contract. Returned 700km over estimate — overage charged on final invoice.',
    ],

    // ── Mike Lepore (5) ──
    [
        'label' => 'M1 — USD dry van w/ Samsara',
        'customer' => 'mike', 'unit' => 'm1', 'status' => 'active',
        'start_date' => daysAgo(40), 'end_date' => daysFromNow(325),
        'daily_rate' => '110.00', 'weekly_rate' => '660.00', 'monthly_rate' => '1950.00', 'mileage_rate' => '0.0000',
        'currency' => 'USD',
        'notes' => 'Cross-border USD contract. Standard dry van.',
        'next_billing_date' => daysFromNow(20),
        'odometer_from_unit' => true,
    ],
    [
        'label' => 'M2 — USD reefer w/ insurance + warranty',
        'customer' => 'mike', 'unit' => 'm2', 'status' => 'active',
        'start_date' => daysAgo(18), 'end_date' => daysFromNow(347),
        'daily_rate' => '135.00', 'weekly_rate' => '850.00', 'monthly_rate' => '2850.00', 'mileage_rate' => '0.1500',
        'currency' => 'USD',
        'insurance_opt_in' => 1, 'insurance_cost' => '165.00',
        'warranty_opt_in' => 1, 'warranty_cost' => '95.00',
        'estimated_mileage' => '10000.00',
        'rate_notes' => 'Full-coverage USD reefer with breakdown warranty.',
        'next_billing_date' => daysFromNow(12),
    ],
    [
        'label' => 'M3 — USD tanker w/ high mileage rate',
        'customer' => 'mike', 'unit' => 'm3', 'status' => 'active',
        'start_date' => daysAgo(25), 'end_date' => daysFromNow(340),
        'daily_rate' => '170.00', 'weekly_rate' => '1020.00', 'monthly_rate' => '3400.00', 'mileage_rate' => '0.4000',
        'currency' => 'USD',
        'estimated_mileage' => '15000.00',
        'notes' => 'Fuel tanker — long-haul petroleum transport. Mileage-heavy billing.',
        'next_billing_date' => daysFromNow(5),
    ],
    [
        'label' => 'M4 — CAD dry van weekly (cross-currency override)',
        'customer' => 'mike', 'unit' => 'm4', 'status' => 'active',
        'start_date' => daysAgo(5), 'end_date' => daysFromNow(25),
        'daily_rate' => '120.00', 'weekly_rate' => '720.00', 'monthly_rate' => '2100.00', 'mileage_rate' => '0.0000',
        'currency' => 'CAD',
        'rate_notes' => 'Canadian-domestic haul. Billed in CAD at customer request (override of USD default).',
        'next_billing_date' => daysFromNow(9),
    ],
    [
        'label' => 'M5 — USD flatbed PENDING',
        'customer' => 'mike', 'unit' => 'm5', 'status' => 'pending',
        'start_date' => daysFromNow(14), 'end_date' => daysFromNow(74),
        'daily_rate' => '150.00', 'weekly_rate' => '900.00', 'monthly_rate' => '3000.00', 'mileage_rate' => '0.0000',
        'currency' => 'USD',
        'notes' => 'Flatbed for steel coil delivery — awaiting unit inspection before pickup.',
    ],

    // ── Avi (5) ──
    [
        'label' => 'V1 — Premium reefer w/ ALL options',
        'customer' => 'avi', 'unit' => 'v1', 'status' => 'active',
        'start_date' => daysAgo(11), 'end_date' => daysFromNow(354),
        'daily_rate' => '145.00', 'weekly_rate' => '950.00', 'monthly_rate' => '3200.00', 'mileage_rate' => '0.1800',
        'insurance_opt_in' => 1, 'insurance_cost' => '195.00',
        'warranty_opt_in' => 1, 'warranty_cost' => '120.00',
        'discount_type' => 'flat', 'discount_value' => '250.00',
        'po_number' => 'PO-AVI-2026-001',
        'estimated_mileage' => '9000.00',
        'rate_notes' => 'Premium reefer — insurance, warranty, and $250/mo loyalty credit.',
        'notes' => 'Flagship contract — all premium options. Showcases every feature of the lease system.',
        'next_billing_date' => daysFromNow(19),
    ],
    [
        'label' => 'V2 — Chassis simple',
        'customer' => 'avi', 'unit' => 'v2', 'status' => 'active',
        'start_date' => daysAgo(22), 'end_date' => daysFromNow(68),
        'daily_rate' => '80.00', 'weekly_rate' => '480.00', 'monthly_rate' => '1600.00', 'mileage_rate' => '0.0000',
        'notes' => 'Container chassis for port drayage work.',
        'next_billing_date' => daysFromNow(8),
    ],
    [
        'label' => 'V3 — Dry van Samsara weekly',
        'customer' => 'avi', 'unit' => 'v3', 'status' => 'active',
        'start_date' => daysAgo(3), 'end_date' => daysFromNow(25),
        'daily_rate' => '125.00', 'weekly_rate' => '750.00', 'monthly_rate' => '2200.00', 'mileage_rate' => '0.0000',
        'notes' => '4-week weekly-billed GPS-tracked dry van.',
        'next_billing_date' => daysFromNow(4),
        'odometer_from_unit' => true,
    ],
    [
        'label' => 'V4 — Container COMPLETED',
        'customer' => 'avi', 'unit' => 'v4', 'status' => 'completed',
        'start_date' => daysAgo(75), 'end_date' => daysAgo(20), 'actual_return_date' => daysAgo(18),
        'daily_rate' => '60.00', 'weekly_rate' => '360.00', 'monthly_rate' => '1200.00', 'mileage_rate' => '0.0000',
        'notes' => 'Short-term container lease for port storage. Fully invoiced and paid.',
    ],
    [
        'label' => 'V5 — Dump w/ on-close billing',
        'customer' => 'avi', 'unit' => 'v5', 'status' => 'active',
        'start_date' => daysAgo(4), 'end_date' => null,
        'daily_rate' => '150.00', 'weekly_rate' => '900.00', 'monthly_rate' => '3000.00', 'mileage_rate' => '0.0000',
        'billing_cycle' => 'on_close_only',
        'notes' => 'Spot-haul dump trailer for construction project. Open-ended — billed on return.',
    ],
];

$leaseIdByKey = [];
foreach ($leases as $l) {
    $custKey   = $l['customer'];
    $customer  = $customerDefs[$custKey];
    $customerId= $customerIds[$custKey];
    $unit      = $units[$l['unit']];

    $currency    = $l['currency']    ?? $customer['currency'];
    $mileageUnit = $customer['mileage_unit'];
    $billingCycle= $l['billing_cycle'] ?? $customer['billing_cycle'];

    $tax = freezeTax($customer['tax_rate_id'], $customer['gst_exempt'], $customer['pst_exempt'], $customer['tax_exempt']);

    // Default numeric fields to '0.00' rather than null so NOT NULL columns pass
    $estMiles   = $l['estimated_mileage'] ?? '0.00';
    $actualMile = $l['actual_mileage']    ?? '0.00';

    $row = [
        'contract_number'         => 'CN-' . unique_code() . '-' . date('Y'),
        'customer_id'             => $customerId,
        'equipment_unit_id'       => (int) $unit['id'],
        'customer_name_snapshot'  => $customer['contact_name'],
        'company_name_snapshot'   => $customer['company_name'],
        'unit_number_snapshot'    => $unit['unit_number'],
        'template_name_snapshot'  => $unit['template_name'],
        'equipment_snapshot_json' => buildEquipSnap($unit),
        'status'                  => $l['status'],
        'start_date'              => $l['start_date'],
        'end_date'                => $l['end_date'] ?? null,
        'actual_return_date'      => $l['actual_return_date'] ?? null,
        'daily_rate'              => $l['daily_rate'],
        'weekly_rate'             => $l['weekly_rate'],
        'monthly_rate'            => $l['monthly_rate'],
        'mileage_rate'            => $l['mileage_rate'],
        'rate_notes'              => $l['rate_notes'] ?? null,
        'currency'                => $currency,
        'mileage_unit'            => $mileageUnit,
        'billing_cycle'           => $billingCycle,
        'estimated_mileage'       => $estMiles,
        'mileage_at_start'        => $l['mileage_at_start'] ?? null,
        'mileage_at_end'          => $l['mileage_at_end'] ?? null,
        'actual_mileage'          => $actualMile,
        'gst_exempt'              => $customer['gst_exempt'],
        'pst_exempt'              => $customer['pst_exempt'],
        'tax_exempt'              => $customer['tax_exempt'],
        'tax_rate_gst'            => $tax['gst'],
        'tax_rate_pst'            => $tax['pst'],
        'tax_rate_hst'            => $tax['hst'],
        'discount_type'           => $l['discount_type'] ?? 'none',
        'discount_value'          => $l['discount_value'] ?? '0.00',
        'insurance_opt_in'        => $l['insurance_opt_in'] ?? 0,
        'insurance_cost'          => $l['insurance_cost'] ?? '0.00',
        'warranty_opt_in'         => $l['warranty_opt_in'] ?? 0,
        'warranty_cost'           => $l['warranty_cost'] ?? '0.00',
        'po_number'               => $l['po_number'] ?? null,
        'next_billing_date'       => $l['next_billing_date'] ?? null,
        'last_billed_date'        => null,
        'notes'                   => $l['notes'] ?? null,
        'created_by'              => $userId,
        'updated_by'              => $userId,
    ];

    // Starting odometer (GPS vs manual)
    if (!empty($l['odometer_from_unit']) && !empty($unit['samsara_odometer_km'])) {
        $row['odometer_start_km']         = $unit['samsara_odometer_km'];
        $row['odometer_start_source']     = 'gps';
        $row['odometer_start_fetched_at'] = date('Y-m-d H:i:s');
    } elseif (!empty($l['odometer_start_km'])) {
        $row['odometer_start_km']     = $l['odometer_start_km'];
        $row['odometer_start_source'] = 'manual';
    }

    // Close metadata for completed leases
    if ($l['status'] === 'completed') {
        $row['closed_at']         = ($l['actual_return_date'] ?? $l['end_date']) . ' 16:00:00';
        $row['closed_by_user_id'] = $userId;
    }

    $leaseId = db_insert('leases', $row);
    $leaseIdByKey[$l['unit']] = $leaseId;

    // Sync unit status
    $newStatus = match ($l['status']) {
        'active'   => 'on_lease',
        'pending'  => 'reserved',
        'completed'=> 'available',
        default    => null,
    };
    if ($newStatus !== null) {
        db_execute("UPDATE equipment_units SET status = ?, updated_by = ? WHERE id = ?",
            [$newStatus, $userId, $unit['id']]);
    }

    db_insert('lease_status_log', [
        'lease_id'   => $leaseId,
        'old_status' => 'none',
        'new_status' => $l['status'],
        'notes'      => 'Demo seed: ' . $l['label'],
        'changed_by' => 'demo-seeder',
        'user_id'    => $userId,
    ]);

    printf("  id=%d %s %s → %s/mo %s\n", $leaseId, $row['contract_number'], str_pad($l['status'], 9), $row['monthly_rate'], $currency);
}
echo "  Total leases: " . count($leaseIdByKey) . "\n";

// ────────────────────────────────────────────────────────────
// Step 7 — Invoices via InvoiceGenerator
// ────────────────────────────────────────────────────────────
echo "\nStep 7 — Generating invoices...\n";

$gen = new \FleetForge\Billing\InvoiceGenerator();
$allInvoiceIds = [];

foreach ($leases as $l) {
    $leaseId = $leaseIdByKey[$l['unit']];
    $startTs = strtotime($l['start_date']);
    $custKey = $l['customer'];
    $billingCycle = $l['billing_cycle'] ?? $customerDefs[$custKey]['billing_cycle'];

    // Pending leases have no invoices
    if ($l['status'] === 'pending') continue;

    // on_close_only leases: only create an invoice when status is 'completed'
    if ($billingCycle === 'on_close_only' && $l['status'] !== 'completed') continue;

    // Determine the full billed range for this lease
    $endTs = $l['status'] === 'completed'
           ? strtotime($l['actual_return_date'] ?? $l['end_date'])
           : time();

    // Build a list of monthly periods [start, end] pairs. Always include at least
    // one period, even for very short leases (shows up as partial_start billing).
    $periods = [];
    $cursor = $startTs;
    while ($cursor < $endTs) {
        // Try a 30-day window from cursor OR the actual end, whichever is earlier
        $periodEnd = min(strtotime('+1 month -1 day', $cursor), $endTs);
        $periods[] = [
            date('Y-m-d', $cursor),
            date('Y-m-d', $periodEnd),
            // First period may be 'partial_start' for mid-month starts;
            // middle periods 'full_month'; last period 'partial_end' for completed leases
            'billing_type' => null, // filled in below
        ];
        $cursor = strtotime('+1 day', $periodEnd);
        if (count($periods) >= 4) break; // cap at 4 per lease to keep numbers sane
    }

    // Label billing types
    $n = count($periods);
    foreach ($periods as $i => &$p) {
        if ($n === 1)                  $p['billing_type'] = 'single_period';
        elseif ($i === 0)              $p['billing_type'] = 'partial_start';
        elseif ($i === $n - 1 && $l['status'] === 'completed') $p['billing_type'] = 'partial_end';
        else                           $p['billing_type'] = 'full_month';
    }
    unset($p);

    foreach ($periods as $p) {
        try {
            $result = $gen->createFromLease([
                'lease_id'          => $leaseId,
                'period_start'      => $p[0],
                'period_end'        => $p[1],
                'billing_type'      => $p['billing_type'],
                'invoice_type'      => ($l['status'] === 'completed' && $p === end($periods)) ? 'final' : 'regular',
                'generation_source' => 'cron',
                'auto_generated'    => 1,
                'created_by'        => $userId,
            ]);
            $allInvoiceIds[] = [
                'id'         => $result['invoice_id'],
                'lease_key'  => $l['unit'],
                'lease_id'   => $leaseId,
                'status'     => $l['status'],
                'period_end' => $p[1],
                'amount'     => $result['total_amount'],
                'balance'    => $result['balance_due'],
            ];
        } catch (\Throwable $e) {
            echo "  WARN: {$l['unit']} invoice {$p[0]}→{$p[1]} failed: " . $e->getMessage() . "\n";
        }
    }
}

printf("  %d invoices generated\n", count($allInvoiceIds));

// ────────────────────────────────────────────────────────────
// Step 8 — Payments (mix of fully-paid, partially-paid, overdue)
// ────────────────────────────────────────────────────────────
echo "\nStep 8 — Creating payments...\n";

$paymentCount = 0;
foreach ($allInvoiceIds as $idx => $inv) {
    // Pattern: oldest invoices fully paid, newer ones partially or unpaid
    $age = (int) floor((time() - strtotime($inv['period_end'])) / 86400);
    $total = (float) $inv['amount'];

    $payMode = 'unpaid';
    if ($inv['status'] === 'completed')    $payMode = 'paid';   // all completed leases fully paid
    elseif ($age > 45)                      $payMode = 'paid';
    elseif ($age > 15)                      $payMode = 'partial';
    elseif ($age > 0)                       $payMode = 'pending';
    else                                    $payMode = 'pending';  // current period

    if (in_array($payMode, ['pending', 'unpaid'], true)) continue;

    $amount = $payMode === 'paid' ? $total : round($total * 0.5, 2);

    // Look up currency + customer for the invoice
    $invRow = db_row("SELECT customer_id, currency, invoice_number FROM invoices WHERE id = ?", [$inv['id']]);
    $bankName = $invRow['currency'] === 'USD' ? 'Wells Fargo — USD Account' : 'RBC — Operating Account';
    $fx       = $invRow['currency'] === 'USD' ? '1.3750' : '1.0000';
    $amountInCad = $invRow['currency'] === 'USD'
        ? number_format($amount * 1.375, 2, '.', '')
        : number_format($amount, 2, '.', '');

    $paymentId = db_insert('payments', [
        'payment_number'       => 'PMT-' . date('Y') . '-' . str_pad((string)($paymentCount + 1), 5, '0', STR_PAD_LEFT),
        'customer_id'          => (int) $invRow['customer_id'],
        'amount'               => number_format($amount, 2, '.', ''),
        'currency'             => $invRow['currency'],
        'exchange_rate_to_cad' => $fx,
        'amount_in_cad'        => $amountInCad,
        'payment_method'       => 'ach',
        'reference_number'     => 'BT-' . date('Ymd') . '-' . str_pad((string)($paymentCount + 1), 4, '0', STR_PAD_LEFT),
        'bank_name'            => $bankName,
        'payment_date'         => date('Y-m-d', strtotime($inv['period_end'] . " +{$age} days")),
        'received_at'          => date('Y-m-d H:i:s', strtotime($inv['period_end'] . " +{$age} days")),
        'status'               => 'cleared',
        'notes'                => $payMode === 'paid' ? 'Full payment received' : 'Partial payment — balance outstanding',
        'recorded_by'          => $userId,
    ]);

    db_insert('payment_allocations', [
        'payment_id'      => $paymentId,
        'invoice_id'      => $inv['id'],
        'amount'          => number_format($amount, 2, '.', ''),
        'currency'        => $invRow['currency'],
        'allocation_type' => 'manual',
        'allocated_by'    => $userId,
    ]);

    // Update invoice paid/balance fields
    db_execute("UPDATE invoices SET amount_paid = ?, balance_due = total_amount - ?, status = ?, paid_date = CASE WHEN ? = total_amount THEN ? ELSE paid_date END WHERE id = ?",
        [
            number_format($amount, 2, '.', ''),
            number_format($amount, 2, '.', ''),
            $payMode === 'paid' ? 'paid' : 'partially_paid',
            number_format($amount, 2, '.', ''),
            date('Y-m-d'),
            $inv['id']
        ]
    );

    $paymentCount++;
}
printf("  %d payments created\n", $paymentCount);

// Mark overdue invoices
db_execute(
    "UPDATE invoices SET status = 'overdue'
      WHERE status IN ('sent', 'draft') AND due_date < ? AND balance_due > 0",
    [date('Y-m-d')]
);

// Update customer counters.
// S-FIX-2 Path B: outstanding_balance = SUM(balance_due) of invoices in
// status IN ('sent','partially_paid','overdue'). Drafts and voids do NOT
// contribute. Filtering on `balance_due > 0` was the legacy heuristic and
// included drafts; switch to status filter so seed data matches the canonical
// truth used by api/v1/invoices/send.php (where the OB increment now lives).
db_execute(
    "UPDATE customers c SET
        lease_count = (SELECT COUNT(*) FROM leases WHERE customer_id = c.id AND deleted_at IS NULL),
        active_lease_count = (SELECT COUNT(*) FROM leases WHERE customer_id = c.id AND status = 'active' AND deleted_at IS NULL),
        total_revenue = COALESCE((SELECT SUM(amount_paid) FROM invoices WHERE customer_id = c.id AND deleted_at IS NULL), 0),
        outstanding_balance = COALESCE((SELECT SUM(balance_due) FROM invoices WHERE customer_id = c.id AND deleted_at IS NULL AND status IN ('sent','partially_paid','overdue')), 0)
      WHERE deleted_at IS NULL",
    []
);

// Update equipment_units lease_count + total_revenue
db_execute(
    "UPDATE equipment_units eu SET
        lease_count = (SELECT COUNT(*) FROM leases WHERE equipment_unit_id = eu.id AND deleted_at IS NULL),
        total_revenue = COALESCE(
          (SELECT SUM(i.amount_paid) FROM invoices i
             JOIN leases l ON l.id = i.lease_id
            WHERE l.equipment_unit_id = eu.id AND i.deleted_at IS NULL), 0)
      WHERE deleted_at IS NULL",
    []
);

// ────────────────────────────────────────────────────────────
// Done
// ────────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
printf("  Customers:    %d\n", count($customerIds));
printf("  Rate cards:   %d\n", count($rateCardIds));
printf("  Leases:       %d\n", count($leaseIdByKey));
printf("  Invoices:     %d\n", count($allInvoiceIds));
printf("  Payments:     %d\n", $paymentCount);
printf("  Fixed assets: %d\n", count($assetIdByUnit));
printf("  Bank accts:   2 (CAD + USD)\n");

$outstanding = db_row("SELECT SUM(balance_due) as total FROM invoices WHERE deleted_at IS NULL", [])['total'];
$paidTotal   = db_row("SELECT SUM(amount_paid) as total FROM invoices WHERE deleted_at IS NULL", [])['total'];
printf("  Outstanding AR: \$%s\n", number_format((float) $outstanding, 2));
printf("  Collected:      \$%s\n", number_format((float) $paidTotal, 2));
