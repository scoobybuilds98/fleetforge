<?php
declare(strict_types=1);

/**
 * tests/_smoke_cron_toggles.php
 *
 * S-CRON-TOGGLES — guards the per-cron on/off feature (Settings → Intelligence →
 * Scheduled Jobs). Verifies the THREE surfaces that must agree:
 *   (1) config/cron_jobs.php registry,
 *   (2) the seeded cron.<name>_enabled settings rows (boolean + labeled),
 *   (3) the cron_enabled() gate actually wired into each cron script.
 * Plus the helper's behavior and that the monthly invoice cron ships OFF.
 *
 * Setting writes are snapshotted + restored (hermetic).
 *
 * @session S-CRON-TOGGLES
 */

require_once dirname(__DIR__) . '/config/app.php';

$pass = 0; $fail = 0; $fails = [];
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fails[] = $label; }
}

$registry = require dirname(__DIR__) . '/config/cron_jobs.php';
ok('registry is a non-empty array', is_array($registry) && count($registry) > 0);

echo "\n# registry ↔ settings rows ↔ cron script gate\n";
foreach ($registry as $name => $meta) {
    $key = "cron.{$name}_enabled";
    $row = db_row("SELECT value_type, label, group_name FROM settings WHERE `key` = ?", [$key]);
    ok("'{$key}' seeded as boolean+labeled (group=cron)",
        $row !== null && $row['value_type'] === 'boolean' && $row['label'] !== null && $row['group_name'] === 'cron',
        json_encode($row));

    // The cron script must actually call cron_enabled('<name>') — else the toggle
    // is a no-op. (Structural: the gate line is present in the file.)
    $src = @file_get_contents(dirname(__DIR__) . "/cron/{$name}.php");
    ok("cron/{$name}.php calls cron_enabled('{$name}')",
        is_string($src) && str_contains($src, "cron_enabled('{$name}')"));
}

echo "\n# helper behavior (hermetic toggle)\n";
$probe = 'invoice_overdue';
$pk = "cron.{$probe}_enabled";
$orig = db_row("SELECT value FROM settings WHERE `key`=?", [$pk])['value'] ?? null;
try {
    db_execute("UPDATE settings SET value='1' WHERE `key`=?", [$pk]);
    ok("cron_enabled('{$probe}') true when setting=1", cron_enabled($probe) === true);
    db_execute("UPDATE settings SET value='0' WHERE `key`=?", [$pk]);
    // cron_enabled caches the registry, not the setting, so a fresh read reflects DB.
    ok("cron_enabled('{$probe}') false when setting=0", cron_enabled($probe) === false);
} finally {
    if ($orig !== null) { db_execute("UPDATE settings SET value=? WHERE `key`=?", [$orig, $pk]); }
}

echo "\n# infra crons are never gated\n";
ok("cron_enabled('backup_db') === true (not in registry)", cron_enabled('backup_db') === true);
ok("cron_enabled('qbo_token_refresh') === true (not in registry)", cron_enabled('qbo_token_refresh') === true);

echo "\n# monthly invoice cron is currently OFF (operator request)\n";
ok('cron.invoice_generate_monthly_enabled == 0', (string) settings_get('cron.invoice_generate_monthly_enabled', '1') === '0');
ok("cron_enabled('invoice_generate_monthly') === false", cron_enabled('invoice_generate_monthly') === false);

echo "\n" . str_repeat('─', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
if ($fail) { echo "FAILURES: " . implode('; ', $fails) . "\n"; }
exit($fail ? 1 : 0);
