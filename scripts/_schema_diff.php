<?php
declare(strict_types=1);

/**
 * scripts/_schema_diff.php  (LOCAL diagnostic — never runs on prod)
 *
 * Parses FLEETFORGE_DATABASE_MASTER.sql for the authoritative column set of
 * every table, loads the read-only prod schema dump JSON, and reports drift:
 *
 *   - tables in master but missing on prod          (CREATE TABLE needed)
 *   - columns in master but missing on prod         (ADD COLUMN needed)
 *   - columns on prod but absent from master         (reverse drift, informational)
 *
 * For each missing column it emits an idempotent ADD COLUMN statement using
 * the EXACT definition from master plus an AFTER clause anchored to the
 * nearest preceding column that already exists on prod (so column ordering
 * matches master as closely as possible). Generates an apply-ready migration.
 *
 * Usage: php scripts/_schema_diff.php /tmp/ff_prod_schema.json
 */

$masterPath = __DIR__ . '/../FLEETFORGE_DATABASE_MASTER.sql';
$prodPath   = $argv[1] ?? '/tmp/ff_prod_schema.json';

$sql  = file_get_contents($masterPath);
$prod = json_decode(file_get_contents($prodPath), true);
if (!is_array($prod)) { fwrite(STDERR, "prod JSON parse failed\n"); exit(1); }

/**
 * Parse master into [table => ['cols' => [name => rawDef], 'order' => [names...]]].
 * rawDef = the column definition line as written in the DDL, minus the trailing
 * comma, suitable for "ALTER TABLE t ADD COLUMN <rawDef>".
 */
$master = [];
$lines = explode("\n", $sql);
$curTable = null;
foreach ($lines as $line) {
    $trim = trim($line);
    if (preg_match('/^CREATE TABLE `([^`]+)`/i', $trim, $m)) {
        $curTable = $m[1];
        $master[$curTable] = ['cols' => [], 'order' => []];
        continue;
    }
    if ($curTable === null) continue;
    if (preg_match('/^\)\s*ENGINE/i', $trim)) { $curTable = null; continue; }
    // Column line: starts with a backtick-quoted identifier.
    if (preg_match('/^`([^`]+)`\s+(.+?),?$/', $trim, $m)) {
        $colName = $m[1];
        // Skip index/key lines that happen to start with backtick (rare); a
        // real column has a type token after the name. KEY/PRIMARY/UNIQUE/
        // CONSTRAINT lines do NOT start with a backtick, so we are safe.
        $rawDef = rtrim($trim, ',');
        $master[$curTable]['cols'][$colName] = $rawDef;
        $master[$curTable]['order'][] = $colName;
    }
}

$missingTables   = [];
$missingColumns  = [];   // table => [ [name, rawDef, afterAnchor] ]
$reverseDrift    = [];   // table => [names...]

foreach ($master as $table => $info) {
    if (!isset($prod[$table])) {
        $missingTables[] = $table;
        continue;
    }
    $prodCols = $prod[$table];
    foreach ($info['order'] as $idx => $colName) {
        if (isset($prodCols[$colName])) continue;
        // Determine AFTER anchor: nearest preceding master column that exists on prod.
        $anchor = null;
        for ($j = $idx - 1; $j >= 0; $j--) {
            $prev = $info['order'][$j];
            if (isset($prodCols[$prev]) || _willAdd($missingColumns, $table, $prev)) {
                $anchor = $prev;
                break;
            }
        }
        $missingColumns[$table][] = [
            'name'   => $colName,
            'rawDef' => $info['cols'][$colName],
            'after'  => $anchor,  // null = FIRST
        ];
    }
    // Reverse drift: prod columns not in master.
    foreach (array_keys($prodCols) as $pc) {
        if (!isset($info['cols'][$pc])) {
            $reverseDrift[$table][] = $pc;
        }
    }
}

function _willAdd(array $missingColumns, string $table, string $col): bool {
    foreach ($missingColumns[$table] ?? [] as $mc) {
        if ($mc['name'] === $col) return true;
    }
    return false;
}

// ── Report ──────────────────────────────────────────────────────────────
echo "================ SCHEMA DRIFT REPORT ================\n";
echo "master tables: " . count($master) . " | prod tables: " . count($prod) . "\n\n";

echo "### TABLES IN MASTER MISSING ON PROD (" . count($missingTables) . ")\n";
foreach ($missingTables as $t) echo "  - {$t}\n";
if (!$missingTables) echo "  (none)\n";

echo "\n### COLUMNS IN MASTER MISSING ON PROD\n";
$totalMissingCols = 0;
foreach ($missingColumns as $t => $cols) {
    echo "  [{$t}] (" . count($cols) . ")\n";
    foreach ($cols as $c) {
        $totalMissingCols++;
        echo "      + {$c['name']}  AFTER " . ($c['after'] ?? '(FIRST)') . "\n";
    }
}
if (!$totalMissingCols) echo "  (none)\n";
echo "  TOTAL missing columns: {$totalMissingCols}\n";

echo "\n### REVERSE DRIFT — prod columns NOT in master (informational, NOT touched)\n";
$revCount = 0;
foreach ($reverseDrift as $t => $cols) {
    echo "  [{$t}]: " . implode(', ', $cols) . "\n";
    $revCount += count($cols);
}
if (!$revCount) echo "  (none)\n";

// ── Emit idempotent migration body ───────────────────────────────────────
echo "\n================ GENERATED MIGRATION (ADD COLUMN only) ================\n";
foreach ($missingColumns as $t => $cols) {
    foreach ($cols as $c) {
        $afterClause = $c['after'] !== null ? " AFTER `{$c['after']}`" : " FIRST";
        // Idempotent guard per column via information_schema + PREPARE.
        $guardVar = '@x_' . substr(md5($t . $c['name']), 0, 12);
        echo "SET {$guardVar} = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c['name']}');\n";
        $stmt = "ALTER TABLE `{$t}` ADD COLUMN {$c['rawDef']}{$afterClause}";
        // Escape single quotes for embedding inside the IF() string literal.
        $stmtEsc = str_replace("'", "''", $stmt);
        echo "SET {$guardVar}_sql = IF({$guardVar}=0, '{$stmtEsc}', 'SELECT 1');\n";
        echo "PREPARE _s FROM {$guardVar}_sql; EXECUTE _s; DEALLOCATE PREPARE _s;\n\n";
    }
}
echo "================ END ================\n";
