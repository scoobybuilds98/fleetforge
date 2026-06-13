<?php
declare(strict_types=1);

/**
 * tests/_smoke_settings_formkeys_label_guard.php
 *
 * WAVE 4 [09] LOW — settings _form_keys[] save path missing the render-scope guard.
 *
 * app/admin/settings/index.php has two save paths. The backward-compat path
 * filters `label IS NOT NULL` so a save only touches rows the form could render
 * (the fix that stopped the Security card zeroing the 13 hidden
 * security.rate_limit.* rows). The newer per-form `_form_keys[]` opt-in path
 * selected `WHERE key IN (...)` with NO such filter — so a crafted
 * `_form_keys[]=security.rate_limit.login_ip_threshold` (a deliberately-hidden
 * NULL-label row) could address a setting the form never shows. Defense-in-depth
 * (needs super_admin + a hand-crafted POST; can't clobber-to-default), but both
 * paths should share the same render-scope guarantee. Fixed: added
 * `AND label IS NOT NULL` to the _form_keys[] query.
 *
 * Schema-real check against the live settings table + a source guard:
 *   1. The exact post-fix _form_keys[] query, given a hidden NULL-label
 *      rate_limit key AND a visible labeled security key, returns ONLY the
 *      visible one — the hidden row is unreachable.
 *   2. BOTH save-path queries in index.php carry `label IS NOT NULL`.
 *
 * PRE-FIX  : check 2 fails (the _form_keys[] IN(...) query had no label filter).
 * POST-FIX : both pass.
 *
 * Run:  php tests/_smoke_settings_formkeys_label_guard.php   Exit 0/1.
 *
 * @session WAVE-4-SETTINGS-FORMKEYS-LABEL
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "WAVE 4 [09] SETTINGS _form_keys[] LABEL GUARD\n";
echo str_repeat('─', 72) . "\n";

// Pick a real hidden (NULL-label) rate_limit key + a real visible security key.
$hidden  = db_row("SELECT `key` FROM settings WHERE `key` LIKE 'security.rate_limit.%' AND label IS NULL LIMIT 1")['key'] ?? null;
$visible = db_row("SELECT `key` FROM settings WHERE group_name='security' AND label IS NOT NULL LIMIT 1")['key'] ?? null;

if ($hidden === null || $visible === null) {
    echo "SETUP FAIL — need one NULL-label rate_limit key and one labeled security key (hidden="
        . var_export($hidden, true) . " visible=" . var_export($visible, true) . ")\n";
    exit(2);
}

// ── CHECK 1: the post-fix query filters the hidden row out ──────────────────
$keys = [$hidden, $visible];
$ph   = implode(',', array_fill(0, count($keys), '?'));
$rows = db_select("SELECT `key`, value_type FROM settings WHERE `key` IN ($ph) AND label IS NOT NULL", $keys);
$returned = array_column($rows, 'key');
if (in_array($visible, $returned, true) && !in_array($hidden, $returned, true)) {
    $pass("1 query — '{$visible}' addressable; hidden '{$hidden}' filtered out");
} else {
    $fail("1 query — returned " . json_encode($returned) . " (expected only the visible key)");
}

// ── CHECK 2: both save-path queries carry `label IS NOT NULL` ───────────────
$src = (string) file_get_contents($ROOT . '/app/admin/settings/index.php');
// The two SELECTs from the `settings` table inside the save handler.
preg_match_all('/SELECT\s+`?key`?,\s*value_type\s+FROM\s+settings\s+WHERE(.+?)(?:,|\n|$)/is', $src, $mm);
$queries = $mm[1] ?? [];
$withGuard = 0;
foreach ($queries as $q) {
    if (stripos($q, 'label IS NOT NULL') !== false) $withGuard++;
}
if (count($queries) >= 2 && $withGuard === count($queries)) {
    $pass("2 source — all {$withGuard}/" . count($queries) . " settings save-path queries filter label IS NOT NULL");
} else {
    $fail("2 source — only {$withGuard}/" . count($queries) . " save-path queries carry the label guard");
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("SETTINGS _form_keys[] LABEL GUARD — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
