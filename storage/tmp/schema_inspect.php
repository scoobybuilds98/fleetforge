<?php
// Inspect actual schema names + relevant table columns we'll need
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

$pdo = db_pdo();

echo "All acc_* and journal/role tables:\n";
$rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($rows as $t) {
    if (strpos($t, 'acc_') === 0
        || strpos($t, 'journal') !== false
        || strpos($t, 'role')    !== false) {
        echo "  $t\n";
    }
}

$inspect = [
    'acc_fixed_assets',
    'acc_depreciation_runs',
    'acc_depreciation_run_lines',
    'acc_asset_disposals',
    'acc_asset_impairments',
    'acc_journal_entries',
    'acc_accounts',
    'acc_periods',
    'equipment_units',
    'mileage_logs',
    'users',
    'maintenance_work_orders',
];
foreach ($inspect as $t) {
    echo "\n=== $t ===\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM $t")->fetchAll();
        foreach ($cols as $c) {
            $extra = [];
            if ($c['Key'] === 'PRI') $extra[] = 'PK';
            if ($c['Null'] === 'NO' && $c['Default'] === null) $extra[] = 'NN';
            if ($c['Default'] !== null && $c['Default'] !== '') $extra[] = "def={$c['Default']}";
            $tag = $extra ? '  [' . implode(',', $extra) . ']' : '';
            echo "  {$c['Field']} : {$c['Type']}{$tag}\n";
        }
    } catch (Throwable $e) {
        echo "  ERR: {$e->getMessage()}\n";
    }
}

// Find the journal "lines" table whatever its name
echo "\n=== Looking for journal-line tables ===\n";
foreach (['acc_journal_lines','acc_journal_entry_lines','acc_journal_line_items'] as $t) {
    $present = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    echo "  $t: " . ($present ? 'YES' : 'no') . "\n";
}
