<?php
declare(strict_types=1);

/**
 * tests/_smoke_archive_ddl_lockstep.php
 *
 * WAVE 4 [07] — archive_old_data.php inline CREATE TABLE DDL drift.
 *
 * cron/archive_old_data.php carried its own `CREATE TABLE IF NOT EXISTS
 * notification_log_archive` whose channel/status enums were a stale copy of the
 * schema. When migration f4e438c (S-CRON-FIX-REMAINING) added `slack` to
 * notification_log.channel and `skipped` to .status, the inline DDL was NOT
 * updated. Harmless today (the table already exists, migration-owned), but if
 * the archive table is ever absent at cron time the cron would re-create it
 * from its stale DDL, then `INSERT … SELECT` would coerce/truncate `slack` /
 * `skipped` rows under STRICT mode on archival — silent data corruption of
 * exactly the values that fix added.
 *
 * notification_log_archive is now owned by db_migrations/000_baseline.sql with
 * the correct (superset) enums, so the remediation is to DELETE the redundant
 * inline create from the cron — removing the drift source entirely rather than
 * keeping a second copy in perpetual lockstep.
 *
 * Invariants asserted (schema-real — live enums + cron source):
 *   1. live notification_log_archive enums ⊇ notification_log enums (archive can
 *      hold every value the source can) — the actual data-safety guarantee.
 *   2. the cron carries NO inline notification_log_archive CREATE TABLE; OR, if
 *      one is ever re-introduced, its enum members must be a superset of the
 *      live source enums. (Covers both remediation paths; the stale copy fails.)
 *
 * PRE-FIX  : check 2 fails — inline DDL present, channel omits 'slack', status omits 'skipped'.
 * POST-FIX : both pass — inline create removed; migration owns the table.
 *
 * Run:  php tests/_smoke_archive_ddl_lockstep.php   Exit 0/1.
 *
 * @session WAVE-4-ARCHIVE-DDL-LOCKSTEP
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "WAVE 4 [07] ARCHIVE DDL LOCKSTEP — notification_log_archive ⊇ source\n";
echo str_repeat('─', 72) . "\n";

/** Pull the members out of an `enum('a','b',…)` literal. */
$enumMembers = static function (string $enumLiteral): array {
    if (preg_match('/enum\s*\((.*?)\)/is', $enumLiteral, $m)) {
        if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $mm)) {
            return array_map(static fn($s) => strtolower($s), $mm[1]);
        }
    }
    return [];
};

// Live enums from information_schema (COLUMN_TYPE is e.g. "enum('email',…)").
$liveType = [];
foreach (
    db_select(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('notification_log','notification_log_archive')
            AND COLUMN_NAME IN ('channel','status')",
        []
    ) as $r
) {
    $liveType[$r['TABLE_NAME']][$r['COLUMN_NAME']] = $r['COLUMN_TYPE'];
}

// ── CHECK 1: live archive enums ⊇ live source enums ─────────────────────────
$missingInArchive = [];
foreach (['channel', 'status'] as $col) {
    $src = $enumMembers($liveType['notification_log'][$col] ?? '');
    $arc = $enumMembers($liveType['notification_log_archive'][$col] ?? '');
    foreach ($src as $member) {
        if (!in_array($member, $arc, true)) $missingInArchive[] = "{$col}:'{$member}'";
    }
}
if (!$missingInArchive) {
    $pass("1 live schema — notification_log_archive enums cover every source member");
} else {
    $fail("1 live schema — archive enum missing: " . implode(', ', $missingInArchive));
}

// ── CHECK 2: no stale inline CREATE TABLE in the cron ───────────────────────
$src = (string) file_get_contents($ROOT . '/cron/archive_old_data.php');
// Isolate an inline CREATE TABLE … notification_log_archive ( … ) block, if any.
$hasInline = preg_match(
    '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?notification_log_archive`?\s*\((.*?)\)\s*ENGINE/is',
    $src,
    $block
);
if (!$hasInline) {
    $pass("2 no drift — cron carries no inline notification_log_archive DDL (migration-owned)");
} else {
    // Inline DDL still present → it MUST be a superset of the live source enums.
    $stale = [];
    foreach (['channel', 'status'] as $col) {
        $srcMembers = $enumMembers($liveType['notification_log'][$col] ?? '');
        // grep the column's enum literal out of the inline block
        if (preg_match('/`' . $col . '`\s+(enum\s*\([^)]*\))/is', $block[1], $cm)) {
            $inlineMembers = $enumMembers($cm[1]);
            foreach ($srcMembers as $member) {
                if (!in_array($member, $inlineMembers, true)) $stale[] = "{$col}:'{$member}'";
            }
        }
    }
    if (!$stale) {
        $pass("2 lockstep — inline DDL present but covers every source enum member");
    } else {
        $fail("2 drift — inline cron DDL is stale, missing: " . implode(', ', $stale));
    }
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("ARCHIVE DDL LOCKSTEP — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
