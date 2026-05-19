<?php
/**
 * FleetForge — Schema Quick-Reference Generator
 *
 * Regenerates docs/FLEETFORGE_SCHEMA_QUICK_REF.md from the live database's
 * information_schema. The quick-ref file is the authoritative source of
 * on-disk column names for session prompts and code reviews — spec files
 * use idealized column names that have repeatedly drifted from reality
 * (K-22 catches across Phase B and C).
 *
 * Usage:
 *   php scripts/generate_schema_ref.php
 *
 * Run after every migration that adds, drops, or renames columns so that
 * the quick-ref stays in sync with reality. Listed as F-SCHEMA-REF-1 in
 * FLEETFORGE_PREDEPLOY_CHECKLIST.md.
 *
 * Output: docs/FLEETFORGE_SCHEMA_QUICK_REF.md (overwritten on each run).
 *
 * Grouping order (per S-SCHEMA-QUICK-REF):
 *   1. Core tables — no underscore in the table name, alphabetical
 *   2. acc_* tables — accounting domain, alphabetical
 *   3. All other tables — alphabetical
 */

declare(strict_types=1);

require __DIR__ . '/../config/app.php';

// ────────────────────────────────────────────────────────────
// Step 1 — Pull every column from the current schema in
//   ordinal-position order so the rendered tables match the
//   physical layout exactly. Filter to the bound DATABASE() so
//   the script is portable across environments (dev / Lightsail).
// ────────────────────────────────────────────────────────────
$rows = db_select("
    SELECT TABLE_NAME,
           COLUMN_NAME,
           COLUMN_TYPE,
           IS_NULLABLE,
           COLUMN_KEY,
           COLUMN_DEFAULT,
           EXTRA
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME, ORDINAL_POSITION
");

// ────────────────────────────────────────────────────────────
// Step 2 — Bucket columns by table. Preserves the SQL ORDER BY
//   ordinal_position by appending to per-table arrays in order.
// ────────────────────────────────────────────────────────────
$byTable = [];
foreach ($rows as $r) {
    $byTable[$r['TABLE_NAME']][] = $r;
}

// ────────────────────────────────────────────────────────────
// Step 3 — Partition table names into the three rendering
//   groups. "Core" = no underscore in the name (head-tables
//   like customers, invoices, leases). "acc_" = accounting
//   domain. "Other" = everything else (ai_*, chat_*, audit_*,
//   prefixed multi-word tables, etc.).
// ────────────────────────────────────────────────────────────
$core  = [];
$acc   = [];
$other = [];
foreach (array_keys($byTable) as $name) {
    if (strpos($name, 'acc_') === 0) {
        $acc[] = $name;
    } elseif (strpos($name, '_') === false) {
        $core[] = $name;
    } else {
        $other[] = $name;
    }
}
sort($core);
sort($acc);
sort($other);

$tableCount = count($byTable);
$colCount   = count($rows);
$today      = date('Y-m-d');

// ────────────────────────────────────────────────────────────
// Step 4 — Render. Each table becomes a markdown H2 plus a
//   four-column table (Column | Type | Key | Nullable). Pipes
//   and backslashes in COLUMN_TYPE are escaped so things like
//   enum('a','b') and varbinary types render correctly.
// ────────────────────────────────────────────────────────────
$out  = "# FleetForge — Schema Quick Reference\n";
$out .= "**Auto-generated from live database. Do NOT edit manually.**\n";
$out .= "**Regenerate:** `php scripts/generate_schema_ref.php`\n";
$out .= "**Generated:** {$today}\n";
$out .= "**Tables:** {$tableCount} total · **Columns:** {$colCount}\n\n";
$out .= "> This file is the authoritative source for on-disk column names.\n";
$out .= "> Use it instead of spec files when writing column references in\n";
$out .= "> session prompts. Spec column names are idealized and often\n";
$out .= "> differ from on-disk reality (see K-22 traps in\n";
$out .= "> `FLEETFORGE_CLAUDE_CODE_REFERENCE.md`).\n\n";
$out .= "**Grouping:** core tables → `acc_` tables → all others. Each group alphabetical.\n\n";
$out .= "---\n\n";

$out .= render_group('Core tables', $core, $byTable);
$out .= render_group('Accounting (`acc_*`) tables', $acc, $byTable);
$out .= render_group('Other tables', $other, $byTable);

/**
 * Render one named group of tables to a markdown string.
 *
 * @param string                       $heading  Section heading shown as H2.
 * @param array<int,string>            $tables   Table names in render order.
 * @param array<string,array<int,array>> $byTable Map of table-name → column rows.
 */
function render_group(string $heading, array $tables, array $byTable): string
{
    if (empty($tables)) {
        return '';
    }

    $md  = "# {$heading}\n\n";
    $md .= '_' . count($tables) . " table" . (count($tables) === 1 ? '' : 's') . "._\n\n";

    foreach ($tables as $tableName) {
        $md .= render_table($tableName, $byTable[$tableName]);
    }
    return $md;
}

/**
 * Render a single table's columns as a markdown table.
 *
 * @param string             $tableName  Table identifier.
 * @param array<int,array>   $columns    information_schema rows for the table.
 */
function render_table(string $tableName, array $columns): string
{
    $md  = "## `{$tableName}`\n\n";
    $md .= "| Column | Type | Key | Nullable |\n";
    $md .= "|--------|------|-----|----------|\n";

    foreach ($columns as $c) {
        // Pipes and backslashes break markdown table rendering; escape them.
        $type = str_replace(['\\', '|'], ['\\\\', '\\|'], $c['COLUMN_TYPE']);

        // EXTRA is appended (e.g. "auto_increment", "on update CURRENT_TIMESTAMP")
        // because it disambiguates surrogate keys and timestamp columns.
        if (!empty($c['EXTRA'])) {
            $type .= ' _(' . $c['EXTRA'] . ')_';
        }

        $key      = $c['COLUMN_KEY'] !== '' ? $c['COLUMN_KEY'] : '';
        $nullable = $c['IS_NULLABLE'] === 'YES' ? 'YES' : 'NO';

        $md .= "| `{$c['COLUMN_NAME']}` | {$type} | {$key} | {$nullable} |\n";
    }

    return $md . "\n";
}

// ────────────────────────────────────────────────────────────
// Step 5 — Write the file. Path is computed from __DIR__ so the
//   script works from any cwd (cron, deploy hook, manual run).
// ────────────────────────────────────────────────────────────
$outPath = __DIR__ . '/../docs/FLEETFORGE_SCHEMA_QUICK_REF.md';
$bytes   = file_put_contents($outPath, $out);

if ($bytes === false) {
    fwrite(STDERR, "ERROR: failed to write {$outPath}\n");
    exit(1);
}

echo "Schema quick-ref written to docs/FLEETFORGE_SCHEMA_QUICK_REF.md\n";
echo "Tables: {$tableCount} (core: " . count($core) . ", acc_: " . count($acc) . ", other: " . count($other) . ")\n";
echo "Columns: {$colCount}\n";
echo "Bytes: {$bytes}\n";
