<?php
declare(strict_types=1);

/**
 * FleetForge — Demo Seed Script
 *
 * @file        scripts/seed_demo.php
 * @description Seeds realistic demo data:
 *              - 4 equipment templates (reefer, flatbed, container, chassis)
 *              - 12 equipment units spread across templates
 *              - 12 unique BC trucking customers
 *              - 12 leases (mix of active/completed/pending/cancelled)
 *              - 12 reservations + reservation_units (mix of statuses)
 *              - Respects one-unit-one-active-reservation rule
 *              - Sets equipment status correctly (on_lease, reserved, available)
 *
 * @usage       php scripts/seed_demo.php
 * @session     S018
 */

$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=fleetforge;charset=utf8mb4';
$pdo = new PDO($dsn, 'root', 'Qwerty09876!', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function ins(PDO $pdo, string $table, array $data): int {
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
    $vals = implode(', ', array_fill(0, count($data), '?'));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($vals)")
        ->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}

function exec_q(PDO $pdo, string $sql, array $p = []): void {
    $pdo->prepare($sql)->execute($p);
}

$now  = date('Y-m-d H:i:s');
$today = date('Y-m-d');

echo "FleetForge Demo Seed\n";
echo str_repeat('─', 50) . "\n";

// ══════════════════════════════════════════════════
// 1. EQUIPMENT TEMPLATES
// ══════════════════════════════════════════════════
echo "\n[1/5] Inserting equipment templates…\n";

$tplReefer = ins($pdo, 'equipment_templates', [
    'name'                => '48ft Reefer',
    'slug'                => '48ft-reefer',
    'description'         => 'Temperature-controlled 48ft refrigerated trailer for perishable freight.',
    'category'            => 'reefer',
    'brand'               => 'Utility',
    'model'               => '3000R',
    'default_length_ft'   => 48.00,
    'default_height_ft'   => 13.50,
    'default_width_ft'    => 8.50,
    'default_weight_capacity_lbs' => 44000,
    'default_axle_count'  => 2,
    'default_ownership_type' => 'owned',
    'default_daily_rate'  => 145.00,
    'default_weekly_rate' => 875.00,
    'default_monthly_rate'=> 2950.00,
    'default_currency'    => 'CAD',
    'default_mileage_unit'=> 'km',
    'is_active'           => 1,
    'sort_order'          => 10,
    'created_by'          => 1,
    'created_at'          => $now,
    'updated_at'          => $now,
]);
echo "  ✓ 48ft Reefer (id=$tplReefer)\n";

$tplFlatbed = ins($pdo, 'equipment_templates', [
    'name'                => '40ft Flatbed',
    'slug'                => '40ft-flatbed',
    'description'         => 'Open 40ft flatbed for oversized and heavy freight.',
    'category'            => 'flatbed',
    'brand'               => 'Wabash',
    'model'               => 'DuraPlate',
    'default_length_ft'   => 40.00,
    'default_height_ft'   => 5.00,
    'default_width_ft'    => 8.50,
    'default_weight_capacity_lbs' => 48000,
    'default_axle_count'  => 2,
    'default_ownership_type' => 'owned',
    'default_daily_rate'  => 120.00,
    'default_weekly_rate' => 720.00,
    'default_monthly_rate'=> 2400.00,
    'default_currency'    => 'CAD',
    'default_mileage_unit'=> 'km',
    'is_active'           => 1,
    'sort_order'          => 20,
    'created_by'          => 1,
    'created_at'          => $now,
    'updated_at'          => $now,
]);
echo "  ✓ 40ft Flatbed (id=$tplFlatbed)\n";

$tplContainer = ins($pdo, 'equipment_templates', [
    'name'                => '20ft Container',
    'slug'                => '20ft-container',
    'description'         => 'ISO 20ft shipping container for intermodal transport.',
    'category'            => 'container',
    'brand'               => 'CIMC',
    'model'               => 'Standard ISO',
    'default_length_ft'   => 20.00,
    'default_height_ft'   => 8.50,
    'default_width_ft'    => 8.00,
    'default_weight_capacity_lbs' => 52910,
    'default_axle_count'  => null,
    'default_ownership_type' => 'owned',
    'default_daily_rate'  => 95.00,
    'default_weekly_rate' => 565.00,
    'default_monthly_rate'=> 1850.00,
    'default_currency'    => 'CAD',
    'default_mileage_unit'=> 'km',
    'is_active'           => 1,
    'sort_order'          => 30,
    'created_by'          => 1,
    'created_at'          => $now,
    'updated_at'          => $now,
]);
echo "  ✓ 20ft Container (id=$tplContainer)\n";

$tplChassis = ins($pdo, 'equipment_templates', [
    'name'                => '53ft Chassis',
    'slug'                => '53ft-chassis',
    'description'         => 'Sliding tandem 53ft container chassis for port and rail operations.',
    'category'            => 'chassis',
    'brand'               => 'Stoughton',
    'model'               => 'SCTC-53',
    'default_length_ft'   => 53.00,
    'default_height_ft'   => 5.00,
    'default_width_ft'    => 8.50,
    'default_weight_capacity_lbs' => 67200,
    'default_axle_count'  => 2,
    'default_ownership_type' => 'owned',
    'default_daily_rate'  => 80.00,
    'default_weekly_rate' => 480.00,
    'default_monthly_rate'=> 1600.00,
    'default_currency'    => 'CAD',
    'default_mileage_unit'=> 'km',
    'is_active'           => 1,
    'sort_order'          => 40,
    'created_by'          => 1,
    'created_at'          => $now,
    'updated_at'          => $now,
]);
echo "  ✓ 53ft Chassis (id=$tplChassis)\n";

// ══════════════════════════════════════════════════
// 2. EQUIPMENT UNITS
// ══════════════════════════════════════════════════
echo "\n[2/5] Inserting equipment units…\n";

$unitDefs = [
    // [unit_number, template_id, year, length, yard_location, ownership_type, vin_suffix]
    ['RFR-001', $tplReefer,    2021, 48.00, 'Surrey Yard',      'owned',    'RF001BC2021'],
    ['RFR-002', $tplReefer,    2022, 48.00, 'Surrey Yard',      'owned',    'RF002BC2022'],
    ['RFR-003', $tplReefer,    2020, 48.00, 'Abbotsford Yard',  'owned',    'RF003BC2020'],
    ['FLT-001', $tplFlatbed,   2019, 40.00, 'Surrey Yard',      'owned',    'FL001BC2019'],
    ['FLT-002', $tplFlatbed,   2021, 40.00, 'Abbotsford Yard',  'owned',    'FL002BC2021'],
    ['FLT-003', $tplFlatbed,   2023, 40.00, 'Surrey Yard',      'owned',    'FL003BC2023'],
    ['CTR-001', $tplContainer, 2018, 20.00, 'Surrey Yard',      'owned',    'CT001BC2018'],
    ['CTR-002', $tplContainer, 2020, 20.00, 'Abbotsford Yard',  'owned',    'CT002BC2020'],
    ['CHS-001', $tplChassis,   2022, 53.00, 'Surrey Yard',      'owned',    'CH001BC2022'],
    ['CHS-002', $tplChassis,   2021, 53.00, 'Abbotsford Yard',  'owned',    'CH002BC2021'],
    ['DRY-002', 1,             2022, 53.00, 'Surrey Yard',      'owned',    'DR002BC2022'],
    ['DRY-003', 1,             2020, 53.00, 'Abbotsford Yard',  'owned',    'DR003BC2020'],
];

$unitIds = [];
foreach ($unitDefs as [$uNum, $tplId, $yr, $len, $yard, $own, $vinSfx]) {
    $uid = ins($pdo, 'equipment_units', [
        'template_id'       => $tplId,
        'unit_number'       => $uNum,
        'vin'               => 'FF' . $vinSfx,
        'year'              => $yr,
        'ownership_type'    => $own,
        'yard_location'     => $yard,
        'length_ft'         => $len,
        'height_ft'         => 13.50,
        'width_ft'          => 8.50,
        'axle_count'        => 2,
        'status'            => 'available',  // will update below as needed
        'tracking_provider' => 'none',
        'health_score'      => rand(72, 98),
        'cvi_expiry'        => date('Y-m-d', strtotime('+' . rand(60, 365) . ' days')),
        'registration_expiry'=> date('Y-m-d', strtotime('+' . rand(30, 365) . ' days')),
        'mvi_expiry'        => date('Y-m-d', strtotime('+' . rand(90, 365) . ' days')),
        'mileage'           => rand(50000, 450000),
        'acquired_date'     => date('Y-m-d', mktime(0,0,0,1,1,$yr)),
        'created_by'        => 1,
        'updated_by'        => 1,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);
    $unitIds[$uNum] = $uid;
    echo "  ✓ $uNum (id=$uid, tpl=$tplId)\n";
}

// ══════════════════════════════════════════════════
// 3. CUSTOMERS
// ══════════════════════════════════════════════════
echo "\n[3/5] Inserting customers…\n";

$customers = [
    ['Pacific Rim Carriers Ltd',     'Marcus Chen',      'mchen@pacificrimcarriers.ca',   '604-555-0101', 'Surrey',      'BC', 'V3W 1A1', 'low'],
    ['Coastal Freight Solutions',    'Sandra MacLeod',   'smacleod@coastalfreight.ca',    '604-555-0102', 'Richmond',    'BC', 'V6X 2B3', 'low'],
    ['Rocky Mountain Transport',     'Derek Olsen',      'dolsen@rockymtntrns.ca',        '250-555-0103', 'Kelowna',     'BC', 'V1Y 3C4', 'medium'],
    ['Cascade Logistics Group',      'Priya Sharma',     'psharma@cascadelogistics.ca',   '604-555-0104', 'Langley',     'BC', 'V2Y 4D5', 'low'],
    ['Northern Gateway Haulers',     'Robert Tremblay',  'rtremblay@northerngateway.ca',  '250-555-0105', 'Prince George','BC','V2L 5E6', 'medium'],
    ['Fraser Valley Flatbeds',       'Amanda Kowalski',  'akowalski@fvflatbeds.ca',       '604-555-0106', 'Abbotsford',  'BC', 'V2S 6F7', 'low'],
    ['Metro Vancouver Intermodal',   'Jason Park',       'jpark@mvintermodal.ca',         '604-555-0107', 'Burnaby',     'BC', 'V5G 7G8', 'low'],
    ['Island Pacific Trucking',      'Nicole Bouchard',  'nbouchard@islandpacific.ca',    '250-555-0108', 'Nanaimo',     'BC', 'V9R 8H9', 'medium'],
    ['Trans-Canada Cold Chain',      'William Fong',     'wfong@tccoldchain.ca',          '604-555-0109', 'Delta',       'BC', 'V4K 9I0', 'low'],
    ['Okanagan Express Lines',       'Heather Larson',   'hlarson@okanaganexpress.ca',    '250-555-0110', 'Penticton',   'BC', 'V2A 0J1', 'low'],
    ['Lower Mainland Bulk Carriers', 'Carlos Mendoza',   'cmendoza@lmbulk.ca',            '604-555-0111', 'Surrey',      'BC', 'V3S 1K2', 'high'],
    ['Pacific Coast Reefers Inc',    'Tamara Singh',     'tsingh@pcrreefers.ca',          '604-555-0112', 'Coquitlam',   'BC', 'V3E 2L3', 'low'],
];

$custIds = [];
foreach ($customers as $i => [$company, $contact, $email, $phone, $city, $prov, $postal, $risk]) {
    $cid = ins($pdo, 'customers', [
        'company_name'    => $company,
        'contact_name'    => $contact,
        'email'           => $email,
        'phone'           => $phone,
        'city'            => $city,
        'province'        => $prov,
        'postal_code'     => $postal,
        'country'         => 'Canada',
        'state'           => $prov,
        'currency'        => 'CAD',
        'mileage_unit'    => 'km',
        'status'          => 'active',
        'risk_score'      => $risk,
        'billing_cycle'   => 'monthly',
        'invoice_delivery'=> 'email',
        'payment_terms'   => 'Net 30',
        'discount_type'   => 'none',
        'discount_value'  => 0,
        'created_by'      => 1,
        'updated_by'      => 1,
        'created_at'      => date('Y-m-d H:i:s', strtotime("-$i months")),
        'updated_at'      => $now,
    ]);
    $custIds[] = $cid;
    echo "  ✓ $company (id=$cid)\n";
}

// ══════════════════════════════════════════════════
// 4. LEASES
// ══════════════════════════════════════════════════
echo "\n[4/5] Inserting leases…\n";

// Plan:
//   active  → RFR-001, RFR-002, FLT-001, DRY-002  (4 units set to on_lease)
//   completed → RFR-003, FLT-002, CTR-001           (3 units remain available)
//   pending   → CTR-002, CHS-001                    (2 units remain available, not yet started)
//   cancelled → CHS-002, DRY-003                    (2 units remain available)

$leaseDefs = [
    // [unit, cust_idx, contract, start, end, actual_ret, status, daily, monthly]
    ['RFR-001', 0,  'LC-2026-001', '2026-01-15', '2026-07-15', null,         'active',    145.00, 2950.00],
    ['RFR-002', 1,  'LC-2026-002', '2026-02-01', '2026-08-01', null,         'active',    140.00, 2800.00],
    ['FLT-001', 2,  'LC-2026-003', '2026-03-01', '2026-09-01', null,         'active',    120.00, 2400.00],
    ['DRY-002', 3,  'LC-2026-004', '2026-01-10', '2026-12-31', null,         'active',    110.00, 2200.00],
    ['RFR-003', 4,  'LC-2025-005', '2025-06-01', '2025-12-01', '2025-11-28', 'completed', 145.00, 2950.00],
    ['FLT-002', 5,  'LC-2025-006', '2025-08-15', '2026-02-15', '2026-02-10', 'completed', 120.00, 2400.00],
    ['CTR-001', 6,  'LC-2025-007', '2025-09-01', '2026-03-01', '2026-03-01', 'completed',  95.00, 1850.00],
    ['CTR-002', 7,  'LC-2026-008', '2026-05-01', '2026-11-01', null,         'pending',    95.00, 1850.00],
    ['CHS-001', 8,  'LC-2026-009', '2026-06-01', '2026-12-01', null,         'pending',    80.00, 1600.00],
    ['CHS-002', 9,  'LC-2025-010', '2025-10-01', '2026-04-01', null,         'cancelled', 80.00, 1600.00],
    ['DRY-003', 10, 'LC-2025-011', '2025-07-15', '2026-01-15', null,         'cancelled', 110.00, 2200.00],
    ['RFR-001', 11, 'LC-2024-012', '2024-03-01', '2024-09-01', '2024-08-25', 'completed', 135.00, 2700.00],
];

$leaseIds = [];
foreach ($leaseDefs as $i => [$uNum, $custIdx, $contract, $start, $end, $actualRet, $lstatus, $daily, $monthly]) {
    $unitId  = $unitIds[$uNum];
    $custId  = $custIds[$custIdx];
    $weekRate = round($daily * 6.5, 2);
    $months  = $lstatus === 'completed' ? round((strtotime($actualRet ?: $end) - strtotime($start)) / (30.44 * 86400), 1) : null;

    // Find template name for snapshot
    $row = $pdo->prepare("SELECT et.name FROM equipment_units eu JOIN equipment_templates et ON et.id=eu.template_id WHERE eu.id=?")->execute([$unitId]);
    $tplNameRow = $pdo->query("SELECT et.name FROM equipment_units eu JOIN equipment_templates et ON et.id=eu.template_id WHERE eu.id=$unitId")->fetch();
    $tplName = $tplNameRow ? $tplNameRow['name'] : '';

    $custRow = $pdo->query("SELECT contact_name, company_name FROM customers WHERE id=$custId")->fetch();

    $lid = ins($pdo, 'leases', [
        'contract_number'        => $contract,
        'customer_id'            => $custId,
        'equipment_unit_id'      => $unitId,
        'customer_name_snapshot' => $custRow['contact_name'],
        'company_name_snapshot'  => $custRow['company_name'],
        'unit_number_snapshot'   => $uNum,
        'template_name_snapshot' => $tplName,
        'start_date'             => $start,
        'end_date'               => $end,
        'actual_return_date'     => $actualRet,
        'status'                 => $lstatus,
        'daily_rate'             => $daily,
        'weekly_rate'            => $weekRate,
        'monthly_rate'           => $monthly,
        'currency'               => 'CAD',
        'mileage_unit'           => 'km',
        'mileage_rate'           => 0.18,
        'billing_cycle'          => 'monthly',
        'tax_rate_gst'           => 0.05,
        'tax_rate_pst'           => 0.07,
        'next_billing_date'      => $lstatus === 'active' ? date('Y-m-d', strtotime('first day of next month')) : null,
        'created_by'             => 1,
        'updated_by'             => 1,
        'created_at'             => date('Y-m-d H:i:s', strtotime($start . ' -2 days')),
        'updated_at'             => $now,
        'closed_at'              => in_array($lstatus, ['completed','cancelled']) ? date('Y-m-d H:i:s', strtotime(($actualRet ?: $end) . ' +1 day')) : null,
    ]);
    $leaseIds[] = $lid;
    echo "  ✓ $contract — $uNum / {$custRow['company_name']} [$lstatus] (id=$lid)\n";
}

// Update unit statuses for active leases
$activeLeaseUnits = ['RFR-001','RFR-002','FLT-001','DRY-002'];
foreach ($activeLeaseUnits as $uNum) {
    exec_q($pdo, "UPDATE equipment_units SET status='on_lease', updated_at=NOW() WHERE id=?", [$unitIds[$uNum]]);
    echo "  → $uNum set to on_lease\n";
}

// ══════════════════════════════════════════════════
// 5. RESERVATIONS
// ══════════════════════════════════════════════════
echo "\n[5/5] Inserting reservations…\n";

// Rule: only one active (pending/confirmed) reservation per unit
// Plan:
//   confirmed (4): RFR-003, FLT-002, CTR-001, DRY-003 → set unit status=reserved
//   pending   (4): FLT-003, CTR-002, CHS-001, CHS-002
//   completed (2): any units (historical)
//   cancelled (2): any units (historical)

// [cust_idx, unit_key|null, status, pickup_date, pickup_time, priority, company_name_override, quantity, yard]
$resDefs = [
    // Confirmed (units get reserved)
    [0, 'RFR-003', 'confirmed', '2026-04-10', '08:00:00', 'urgent',  null, 1, 'Surrey Yard'],
    [1, 'FLT-002', 'confirmed', '2026-04-18', '09:30:00', 'high',    null, 1, 'Abbotsford Yard'],
    [2, 'CTR-001', 'confirmed', '2026-04-25', '10:00:00', 'medium',  null, 1, 'Surrey Yard'],
    [3, 'DRY-003', 'confirmed', '2026-05-02', '07:00:00', 'high',    null, 1, 'Surrey Yard'],

    // Pending (units still show available but blocked in selector)
    [4, 'FLT-003', 'pending',   '2026-05-12', null,       'medium',  null, 1, 'Surrey Yard'],
    [5, 'CTR-002', 'pending',   '2026-05-20', '08:00:00', 'low',     null, 1, 'Abbotsford Yard'],
    [6, 'CHS-001', 'pending',   '2026-06-01', null,       'medium',  null, 1, 'Surrey Yard'],
    [7, 'CHS-002', 'pending',   '2026-06-15', '14:00:00', 'high',    null, 1, 'Abbotsford Yard'],

    // Completed (historical — units are free)
    [8, 'RFR-001', 'completed', '2025-11-10', '08:00:00', 'medium',  null, 1, 'Surrey Yard'],
    [9, 'FLT-001', 'completed', '2025-12-05', '09:00:00', 'low',     null, 1, 'Surrey Yard'],

    // Cancelled (historical — units are free)
    [10, 'RFR-002', 'cancelled', '2025-10-20', null,      'low',     null, 1, 'Surrey Yard'],
    [11, 'CTR-001', 'cancelled', '2026-01-08', '08:00:00','medium',  null, 1, 'Abbotsford Yard'],
];

// Units that get reserved status from confirmed reservations
$confirmedResUnits = ['RFR-003','FLT-002','CTR-001','DRY-003'];

foreach ($resDefs as $i => [$custIdx, $uKey, $rstatus, $pickDate, $pickTime, $priority, $compNameOvr, $qty, $yard]) {
    $custId  = $custIds[$custIdx];
    $custRow = $pdo->query("SELECT contact_name, company_name FROM customers WHERE id=$custId")->fetch();
    $companyName = $compNameOvr ?? $custRow['company_name'];
    $contactName = $custRow['contact_name'];

    $purposes = [
        'Freight transport — perishable goods',
        'Construction material haul',
        'Intermodal port pickup',
        'Long-haul dry goods',
        'Agricultural produce transport',
        'Manufacturing equipment move',
        'Retail distribution run',
        'Cold chain pharmaceutical',
        'Lumber & forestry products',
        'Automotive parts delivery',
        'Container repositioning',
        'Seasonal overflow capacity',
    ];

    $rid = ins($pdo, 'reservations', [
        'status'         => $rstatus,
        'customer_id'    => $custId,
        'contact_name'   => $contactName,
        'company_name'   => $companyName,
        'contact_phone'  => '604-555-0' . str_pad((string)(200 + $i), 3, '0', STR_PAD_LEFT),
        'contact_email'  => strtolower(str_replace(' ', '', $contactName)) . '@fleetforge.ca',
        'quantity'       => $qty,
        'pickup_date'    => $pickDate,
        'pickup_time'    => $pickTime,
        'yard_location'  => $yard,
        'purpose'        => $purposes[$i % count($purposes)],
        'priority'       => $priority,
        'notes'          => "Demo reservation {$i} — {$companyName}",
        'internal_notes' => null,
        'created_by'     => 1,
        'updated_by'     => 1,
        'created_at'     => date('Y-m-d H:i:s', strtotime($pickDate . ' -7 days')),
        'updated_at'     => $now,
    ]);

    // Reservation unit row
    $unitId       = $unitIds[$uKey];
    $unitRow      = $pdo->query("SELECT eu.unit_number, eu.status, et.name AS tname FROM equipment_units eu JOIN equipment_templates et ON et.id=eu.template_id WHERE eu.id=$unitId")->fetch();
    $statusAtRes  = $unitRow['status'];

    ins($pdo, 'reservation_units', [
        'reservation_id'         => $rid,
        'equipment_unit_id'      => $unitId,
        'unit_number_snapshot'   => $unitRow['unit_number'],
        'template_name_snapshot' => $unitRow['tname'],
        'status_at_reservation'  => $statusAtRes,
        'entry_type'             => 'system',
        'created_at'             => date('Y-m-d H:i:s', strtotime($pickDate . ' -7 days')),
    ]);

    $statusLabel = strtoupper($rstatus);
    echo "  ✓ Res#$rid [{$statusLabel}] {$companyName} — {$uKey} — {$pickDate}\n";
}

// Update units that now have CONFIRMED reservations
foreach ($confirmedResUnits as $uNum) {
    // Only mark reserved if unit isn't already on_lease
    $row = $pdo->query("SELECT status FROM equipment_units WHERE id={$unitIds[$uNum]}")->fetch();
    if ($row && $row['status'] !== 'on_lease') {
        exec_q($pdo, "UPDATE equipment_units SET status='reserved', updated_at=NOW() WHERE id=?", [$unitIds[$uNum]]);
        echo "  → $uNum set to reserved\n";
    }
}

// ══════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════
echo "\n" . str_repeat('─', 50) . "\n";
echo "✅ Seed complete!\n\n";

$counts = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM equipment_templates WHERE deleted_at IS NULL) AS templates,
        (SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL) AS units,
        (SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL) AS customers,
        (SELECT COUNT(*) FROM leases WHERE deleted_at IS NULL) AS leases,
        (SELECT COUNT(*) FROM reservations WHERE deleted_at IS NULL) AS reservations
")->fetch();

echo "  Templates : {$counts['templates']}\n";
echo "  Units     : {$counts['units']}\n";
echo "  Customers : {$counts['customers']}\n";
echo "  Leases    : {$counts['leases']}\n";
echo "  Reservations: {$counts['reservations']}\n\n";

// Unit status summary
echo "Unit status breakdown:\n";
$statRows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM equipment_units WHERE deleted_at IS NULL GROUP BY status ORDER BY status")->fetchAll();
foreach ($statRows as $sr) {
    echo "  {$sr['status']}: {$sr['cnt']}\n";
}
echo "\n";

// Active reservation → unit mapping
echo "Active reservations with unit assignments:\n";
$actRes = $pdo->query("
    SELECT r.id, r.status, r.company_name, r.pickup_date,
           ru.unit_number_snapshot, ru.template_name_snapshot
    FROM reservations r
    JOIN reservation_units ru ON ru.reservation_id = r.id
    WHERE r.status IN ('pending','confirmed') AND r.deleted_at IS NULL
    ORDER BY r.pickup_date
")->fetchAll();
foreach ($actRes as $ar) {
    echo "  Res#{$ar['id']} [{$ar['status']}] {$ar['unit_number_snapshot']} — {$ar['company_name']} — {$ar['pickup_date']}\n";
}
