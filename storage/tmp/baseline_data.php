<?php
// Find baseline data needed for fixed-asset module testing.
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

$pdo = db_pdo();

echo "=== Users (for created_by) ===\n";
$users = db_select("SELECT id, name, email, role_id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 10");
foreach ($users as $u) printf("  #%d  %s  <%s>  role_id=%d\n", $u['id'], $u['name'], $u['email'], $u['role_id']);

echo "\n=== Roles ===\n";
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id");
foreach ($roles as $r) printf("  #%d  %s  (%s)\n", $r['id'], $r['name'], $r['slug']);

echo "\n=== Equipment units (will link some to assets) ===\n";
$units = db_select("SELECT id, unit_number, ownership_type, mileage, status FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 20");
foreach ($units as $u) printf("  #%-3d %-20s %-10s mi=%-8d %s\n", $u['id'], $u['unit_number'], $u['ownership_type'], $u['mileage'], $u['status']);

echo "\n=== Mileage logs (last 10) ===\n";
$logs = db_select("SELECT id, equipment_unit_id, log_date, odometer_reading, mileage_unit FROM mileage_logs ORDER BY id DESC LIMIT 10");
foreach ($logs as $l) printf("  #%d unit=%d date=%s odo=%d %s\n", $l['id'], $l['equipment_unit_id'], $l['log_date'], $l['odometer_reading'], $l['mileage_unit']);

echo "\n=== Open accounting periods ===\n";
$periods = db_select("SELECT id, name, year, month, start_date, end_date, status FROM acc_periods WHERE status='open' ORDER BY year, month");
foreach ($periods as $p) printf("  #%d %s (%s..%s) %s\n", $p['id'], $p['name'], $p['start_date'], $p['end_date'], $p['status']);

echo "\n=== Accounts of interest (asset / depreciation / disposal / impairment) ===\n";
$keywords = [
    'fleet', 'equipment cost', 'office equipment', 'building', 'land', 'vehicle',
    'accumulated depreciation', 'depreciation',
    'gain on disposal', 'loss on disposal',
    'impairment', 'cash',
];
foreach ($keywords as $k) {
    $rows = db_select(
        "SELECT id, code, name, account_type, normal_balance, is_header
         FROM acc_accounts
         WHERE LOWER(name) LIKE ?
           AND is_active = 1
           AND is_header = 0
         ORDER BY code",
        ['%'.$k.'%']
    );
    foreach ($rows as $r) {
        printf("  [%s] %s  %s  (%s, %s)\n",
            $r['code'], str_pad($r['name'], 38), $r['account_type'], $r['normal_balance'], '#'.$r['id']);
    }
}

echo "\n=== Settings (accounting.*) ===\n";
$st = db_select("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'accounting.%' ORDER BY `key`");
foreach ($st as $s) printf("  %-50s = %s\n", $s['key'], substr($s['value'], 0, 80));
