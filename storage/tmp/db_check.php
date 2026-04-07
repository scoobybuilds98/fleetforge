<?php
// Quick DB connectivity + table existence check.
// Run with:  php storage/tmp/db_check.php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

$want = [
    'acc_fixed_assets',
    'acc_depreciation_runs',
    'acc_depreciation_run_lines',
    'acc_asset_disposals',
    'acc_asset_impairments',
    'acc_capex_requests',
    'acc_journal_entries',
    'acc_journal_lines',
    'acc_accounts',
    'acc_periods',
    'equipment_units',
    'mileage_logs',
    'users',
    'roles',
    'maintenance_work_orders',
];

echo "DB connected: " . FF_DB_NAME . "\n\n";
echo "Tables present:\n";
foreach ($want as $t) {
    $present = (bool) db_pdo()->query("SHOW TABLES LIKE " . db_pdo()->quote($t))->fetchColumn();
    printf("  [%s] %s\n", $present ? 'X' : ' ', $t);
}

echo "\nBaseline counts:\n";
$counts = [
    'users'              => 'SELECT COUNT(*) FROM users',
    'equipment_units'    => 'SELECT COUNT(*) FROM equipment_units',
    'mileage_logs'       => 'SELECT COUNT(*) FROM mileage_logs',
    'acc_accounts'       => 'SELECT COUNT(*) FROM acc_accounts',
    'acc_periods (open)' => "SELECT COUNT(*) FROM acc_periods WHERE status='open'",
    'maint_work_orders'  => 'SELECT COUNT(*) FROM maintenance_work_orders',
];
foreach ($counts as $label => $sql) {
    try {
        $n = (int) db_pdo()->query($sql)->fetchColumn();
        echo "  $label: $n\n";
    } catch (Throwable $e) {
        echo "  $label: ERR ({$e->getMessage()})\n";
    }
}
