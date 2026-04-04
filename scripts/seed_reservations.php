<?php
declare(strict_types=1);
// Quick patch: insert the 12 demo reservations (templates/units/customers/leases already seeded)

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=fleetforge;charset=utf8mb4', 'root', 'Qwerty09876!', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function ins2(PDO $pdo, string $table, array $data): int {
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
    $vals = implode(', ', array_fill(0, count($data), '?'));
    $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($vals)")->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}

$now = date('Y-m-d H:i:s');

// Unit IDs from seed (RFR-001=6, RFR-002=7, RFR-003=8, FLT-001=9, FLT-002=10, FLT-003=11, CTR-001=12, CTR-002=13, CHS-001=14, CHS-002=15, DRY-002=16, DRY-003=17)
$unitIds = [
    'RFR-001'=>6,'RFR-002'=>7,'RFR-003'=>8,
    'FLT-001'=>9,'FLT-002'=>10,'FLT-003'=>11,
    'CTR-001'=>12,'CTR-002'=>13,
    'CHS-001'=>14,'CHS-002'=>15,
    'DRY-002'=>16,'DRY-003'=>17,
];

// Customers seeded: ids 4-15 for Pacific Rim (4) to Pacific Coast Reefers (15)
$custIds = [4,5,6,7,8,9,10,11,12,13,14,15];

$resDefs = [
    // [cust_idx(0=id4), unit_key, status, pickup_date, pickup_time|null, priority, qty, yard]
    [0,  'RFR-003', 'confirmed', '2026-04-10', '08:00:00', 'urgent', 1, 'Surrey Yard'],
    [1,  'FLT-002', 'confirmed', '2026-04-18', '09:30:00', 'high',   1, 'Abbotsford Yard'],
    [2,  'CTR-001', 'confirmed', '2026-04-25', '10:00:00', 'medium', 1, 'Surrey Yard'],
    [3,  'DRY-003', 'confirmed', '2026-05-02', '07:00:00', 'high',   1, 'Surrey Yard'],
    [4,  'FLT-003', 'pending',   '2026-05-12', null,       'medium', 1, 'Surrey Yard'],
    [5,  'CTR-002', 'pending',   '2026-05-20', '08:00:00', 'low',    1, 'Abbotsford Yard'],
    [6,  'CHS-001', 'pending',   '2026-06-01', null,       'medium', 1, 'Surrey Yard'],
    [7,  'CHS-002', 'pending',   '2026-06-15', '14:00:00', 'high',   1, 'Abbotsford Yard'],
    [8,  'RFR-001', 'completed', '2025-11-10', '08:00:00', 'medium', 1, 'Surrey Yard'],
    [9,  'FLT-001', 'completed', '2025-12-05', '09:00:00', 'low',    1, 'Surrey Yard'],
    [10, 'RFR-002', 'cancelled', '2025-10-20', null,       'low',    1, 'Surrey Yard'],
    [11, 'CTR-001', 'cancelled', '2026-01-08', '08:00:00', 'medium', 1, 'Abbotsford Yard'],
];

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

$confirmedUnits = ['RFR-003','FLT-002','CTR-001','DRY-003'];

echo "Inserting 12 demo reservations…\n";

foreach ($resDefs as $i => [$ci, $uKey, $rs, $pd, $pt, $pri, $qty, $yard]) {
    $custId  = $custIds[$ci];
    $custRow = $pdo->query("SELECT contact_name, company_name FROM customers WHERE id=$custId")->fetch();
    $unitId  = $unitIds[$uKey];
    $unitRow = $pdo->query("SELECT eu.unit_number, eu.status, et.name AS tname FROM equipment_units eu JOIN equipment_templates et ON et.id=eu.template_id WHERE eu.id=$unitId")->fetch();

    $phone = '604-555-' . str_pad((string)(200 + $i), 4, '0', STR_PAD_LEFT);

    $rid = ins2($pdo, 'reservations', [
        'status'         => $rs,
        'customer_id'    => $custId,
        'contact_name'   => $custRow['contact_name'],
        'company_name'   => $custRow['company_name'],
        'contact_phone'  => $phone,
        'contact_email'  => strtolower(str_replace([' ','.'], '', $custRow['contact_name'])) . '@fleet.ca',
        'quantity'       => $qty,
        'pickup_date'    => $pd,
        'pickup_time'    => $pt,
        'yard_location'  => $yard,
        'purpose'        => $purposes[$i],
        'priority'       => $pri,
        'notes'          => "Demo reservation — {$custRow['company_name']}",
        'internal_notes' => null,
        'created_by'     => 1,
        'updated_by'     => 1,
        'created_at'     => date('Y-m-d H:i:s', strtotime($pd . ' -7 days')),
        'updated_at'     => $now,
    ]);

    ins2($pdo, 'reservation_units', [
        'reservation_id'         => $rid,
        'equipment_unit_id'      => $unitId,
        'unit_number_snapshot'   => $unitRow['unit_number'],
        'template_name_snapshot' => $unitRow['tname'],
        'status_at_reservation'  => $unitRow['status'],
        'entry_type'             => 'system',
        'created_at'             => date('Y-m-d H:i:s', strtotime($pd . ' -7 days')),
    ]);

    echo "  ✓ Res#$rid [" . strtoupper($rs) . "] {$custRow['company_name']} — $uKey — $pd\n";
}

// Mark confirmed-reservation units as reserved
foreach ($confirmedUnits as $uNum) {
    $uid = $unitIds[$uNum];
    $row = $pdo->query("SELECT status FROM equipment_units WHERE id=$uid")->fetch();
    if ($row && $row['status'] !== 'on_lease') {
        $pdo->prepare("UPDATE equipment_units SET status='reserved', updated_at=NOW() WHERE id=?")->execute([$uid]);
        echo "  → $uNum status set to reserved\n";
    }
}

echo "\n─── Final Counts ───────────────────────────────\n";
$c = $pdo->query("SELECT
    (SELECT COUNT(*) FROM equipment_templates WHERE deleted_at IS NULL) AS tpl,
    (SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL) AS units,
    (SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL) AS cust,
    (SELECT COUNT(*) FROM leases WHERE deleted_at IS NULL) AS leases,
    (SELECT COUNT(*) FROM reservations WHERE deleted_at IS NULL) AS res")->fetch();
echo "  Templates   : {$c['tpl']}\n";
echo "  Units       : {$c['units']}\n";
echo "  Customers   : {$c['cust']}\n";
echo "  Leases      : {$c['leases']}\n";
echo "  Reservations: {$c['res']}\n\n";

echo "Unit statuses:\n";
foreach ($pdo->query("SELECT status, COUNT(*) n FROM equipment_units WHERE deleted_at IS NULL GROUP BY status ORDER BY status") as $r) {
    echo "  {$r['status']}: {$r['n']}\n";
}

echo "\nActive reservation assignments:\n";
$rows = $pdo->query("
    SELECT r.id, r.status, r.company_name, r.pickup_date, ru.unit_number_snapshot, ru.template_name_snapshot
    FROM reservations r JOIN reservation_units ru ON ru.reservation_id=r.id
    WHERE r.status IN ('pending','confirmed') AND r.deleted_at IS NULL
    ORDER BY r.pickup_date
")->fetchAll();
foreach ($rows as $r) {
    echo "  Res#{$r['id']} [{$r['status']}] {$r['unit_number_snapshot']} ({$r['template_name_snapshot']}) — {$r['company_name']} — {$r['pickup_date']}\n";
}
echo "\n✅ Done.\n";
