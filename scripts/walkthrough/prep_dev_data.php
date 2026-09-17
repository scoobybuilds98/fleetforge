<?php
declare(strict_types=1);

// ============================================================
// scripts/walkthrough/prep_dev_data.php
//
// Makes the DEV presentation dataset camera-ready for the training videos,
// reversibly:
//   --apply   soft-deletes automated-test debris (SMOKE-OVS-* / "Test Co*"
//             customers and every soft-deletable row that hangs off them) and
//             renames the recorder user to "Training Admin". Every touched id
//             and prior value is written to training-videos/.cache/prep_state.json.
//   --revert  restores exactly those rows/values from the state file.
//
// WHY soft-delete instead of wiping: smokes re-create these rows constantly and
// other sessions rely on some of them; reverting keeps the dev DB as we found it.
// DEV ONLY — refuses to run unless APP_ENV=development.
// ============================================================

require_once __DIR__ . '/../../config/app.php';
require_once FF_ROOT . '/includes/db.php';

if (APP_ENV !== 'development') {
    fwrite(STDERR, "prep_dev_data.php refuses to run outside APP_ENV=development\n");
    exit(1);
}

$mode      = $argv[1] ?? '';
$stateFile = FF_ROOT . '/training-videos/.cache/prep_state.json';
$userId    = 19;

if ($mode === '--apply') {
    if (is_file($stateFile)) {
        fwrite(STDERR, "state file exists — run --revert first\n");
        exit(1);
    }
    $stamp = date('Y-m-d H:i:s');
    $state = ['applied_at' => $stamp, 'rows' => [], 'user' => null];

    $customerIds = array_map('intval', array_column(db_select(
        "SELECT id FROM customers
         WHERE deleted_at IS NULL AND (company_name LIKE 'SMOKE-%' OR company_name LIKE 'Test Co%')"
    ), 'id'));

    if ($customerIds) {
        $in = implode(',', $customerIds);
        // Every table with both customer_id and deleted_at — found live so new modules are covered.
        $tables = array_column(db_select(
            "SELECT c1.TABLE_NAME t FROM information_schema.COLUMNS c1
             JOIN information_schema.COLUMNS c2 ON c2.TABLE_SCHEMA = c1.TABLE_SCHEMA AND c2.TABLE_NAME = c1.TABLE_NAME AND c2.COLUMN_NAME = 'deleted_at'
             WHERE c1.TABLE_SCHEMA = DATABASE() AND c1.COLUMN_NAME = 'customer_id'"
        ), 't');
        foreach ($tables as $t) {
            $ids = array_map('intval', array_column(db_select("SELECT id FROM `{$t}` WHERE deleted_at IS NULL AND customer_id IN ({$in})"), 'id'));
            if (!$ids) continue;
            db_execute("UPDATE `{$t}` SET deleted_at = ? WHERE id IN (" . implode(',', $ids) . ')', [$stamp]);
            $state['rows'][$t] = $ids;
        }
        db_execute("UPDATE customers SET deleted_at = ? WHERE id IN ({$in})", [$stamp]);
        $state['rows']['customers'] = $customerIds;
    }

    $u = db_row('SELECT name FROM users WHERE id = ?', [$userId]);
    $state['user'] = ['id' => $userId, 'name' => $u['name'] ?? null];
    db_execute("UPDATE users SET name = 'Training Admin' WHERE id = ?", [$userId]);

    @mkdir(dirname($stateFile), 0775, true);
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    foreach ($state['rows'] as $t => $ids) echo "hid {$t}: " . count($ids) . "\n";
    echo "renamed user {$userId} -> Training Admin\n";
    exit(0);
}

if ($mode === '--revert') {
    if (!is_file($stateFile)) {
        fwrite(STDERR, "no state file — nothing to revert\n");
        exit(1);
    }
    $state = json_decode((string) file_get_contents($stateFile), true);
    foreach ($state['rows'] as $t => $ids) {
        $t = preg_replace('/[^a-z0-9_]/', '', $t);
        // Only un-delete rows still carrying OUR timestamp, so a later genuine delete is kept.
        db_execute("UPDATE `{$t}` SET deleted_at = NULL WHERE deleted_at = ? AND id IN (" . implode(',', array_map('intval', $ids)) . ')', [$state['applied_at']]);
        echo "restored {$t}: " . count($ids) . "\n";
    }
    if ($state['user']['name'] !== null) {
        db_execute('UPDATE users SET name = ? WHERE id = ?', [$state['user']['name'], (int) $state['user']['id']]);
    }
    unlink($stateFile);
    echo "reverted\n";
    exit(0);
}

fwrite(STDERR, "usage: php scripts/walkthrough/prep_dev_data.php --apply|--revert\n");
exit(1);
