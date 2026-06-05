<?php
declare(strict_types=1);

/**
 * tests/_smoke_migrations_reproduce_master.php
 *
 * D-GUARD-1 keystone (S-SCHEMA-GUARD-1) — "migrations ⊆ master".
 *
 * Asserts that EVERY table and column introduced by a db_migrations/ delta is
 * reflected in FLEETFORGE_DATABASE_MASTER.sql, the schema oracle (K-22). This is
 * the missing companion to the D127 master-vs-live parity smoke: D127 proves
 * master matches the DEV DB; this proves master matches the MIGRATIONS. The gap
 * between them is exactly what caused the 2026-06-05 outage —
 * `role_permission_overrides` was created by a migration (6cda088) but never
 * added to master, so a migrate-built DB and the oracle both diverged, and the
 * red D127 gate was tolerated rather than blocking.
 *
 * WHY static (not a from-zero `migrate` build): db_migrations/ holds incremental
 * deltas, not a full schema — 112 of master's 153 tables (the entire base:
 * users, customers, leases, invoices, all acc_*) are created by NO migration and
 * live only in master. A from-zero migrate reproduces 46 tables, not 153; and
 * re-applying migrations onto a master-loaded DB fails (40/90 are non-idempotent
 * bare CREATE/ADD). So the feasible, hermetic invariant is the STATIC subset
 * relation. See tests/_smoke_schema_lib.php for the parsing rationale and the
 * SESSION LOG for the full architectural finding.
 *
 * Scope + known limits (logged, never silent):
 *   - Create-then-drop tables/cols are netted out (subset relation). The only
 *     migration-created table absent from master today is lease_close_adjustments
 *     (net-dropped); it is logged. (Forensic capture-all backup tables such as
 *     *_backup_S_MILEAGE_* ARE kept in master, so they verify normally — there is
 *     deliberately NO carve-out that would let an unreflected table slip through.)
 *   - The residual sub-case "a table EDITED into master with NO db_migrations
 *     delta" is NOT covered here — only a 000_baseline migration closes that
 *     (queued as S-SCHEMA-GUARD-2). This guard closes the wrong-dir / unreflected
 *     -delta trigger; D-GUARD-2 (no stray schema sql) is the primary net.
 *
 * Final summary line is grep-able for CI:
 *   "MIGRATE-PARITY OK — migrations reproduce master (...)"
 *   "MIGRATE-PARITY FAIL — N drift line(s) (see ...)"
 *
 * Exit code: 0 on clean subset, 1 on drift.
 *
 * @session  S-SCHEMA-GUARD-1
 * @decision D-GUARD-1
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/_smoke_schema_lib.php';

$masterPath    = FF_ROOT . '/FLEETFORGE_DATABASE_MASTER.sql';
$migrationsDir = FF_ROOT . '/db_migrations';

echo "S-SCHEMA-GUARD-1 keystone — migrations ⊆ master (D-GUARD-1)\n";
echo "  master: {$masterPath}\n";
echo "  migrations: {$migrationsDir}\n\n";

$masterSql = @file_get_contents($masterPath);
if ($masterSql === false) {
    echo "MIGRATE-PARITY FAIL — cannot read master file {$masterPath}\n";
    exit(1);
}
$masterTables = ff_schema_parse_master_tables($masterSql);
echo "[1/3] Parsed master: " . count($masterTables) . " tables\n";

// ── Accumulate net claims across all migrations, tracking origin file ──
$createdTables = [];   // table => first originating file (basename)
$droppedTables = [];   // table => true
$addedCols     = [];   // "t.c" => [t, c, file]
$droppedCols   = [];   // "t.c" => true

$files = ff_schema_list_migration_files($migrationsDir);
foreach ($files as $path) {
    $base = basename($path);
    $sql  = @file_get_contents($path);
    if ($sql === false) {
        echo "  ⚠ unreadable migration skipped: {$base}\n";
        continue;
    }
    $claims = ff_schema_parse_migration_claims($sql);

    foreach ($claims['created_tables'] as $t => $_) {
        if (!isset($createdTables[$t])) {
            $createdTables[$t] = $base;
        }
    }
    foreach ($claims['dropped_tables'] as $t => $_) {
        $droppedTables[$t] = true;
    }
    foreach ($claims['added_cols'] as $key => $tc) {
        if (!isset($addedCols[$key])) {
            $addedCols[$key] = [$tc[0], $tc[1], $base];
        }
    }
    foreach ($claims['dropped_cols'] as $key => $_) {
        $droppedCols[$key] = true;
    }
}
echo "[2/3] Parsed " . count($files) . " migrations: "
   . count($createdTables) . " created tables, "
   . count($addedCols) . " column claims\n";

// ── Assert the subset relation ──
echo "[3/3] Checking subset relation against master\n\n";

$failures     = [];
$droppedSkips = [];

// Precedence: verify-if-present-in-master FIRST. A migration-created table that
// IS in master is reflected — pass (this correctly covers drop-then-recreate in
// one migration, e.g. acc_qbo_sync_log, and forensic capture-all backup tables
// such as *_backup_S_MILEAGE_*, which this repo DOES keep in master). Only tables
// genuinely ABSENT from master fall through; a net-dropped table is expected
// absent, anything else is real drift (the role_permission_overrides class).
$tablesVerified = 0;
foreach ($createdTables as $t => $file) {
    if (isset($masterTables[$t])) {
        $tablesVerified++;
        continue;
    }
    if (isset($droppedTables[$t])) {
        // created then net-dropped by migrations → master correctly lacks it.
        $droppedSkips[] = "table {$t} (created {$file}, later dropped)";
        continue;
    }
    $failures[] = "MISSING TABLE: `{$t}` is created by {$file} but absent from FLEETFORGE_DATABASE_MASTER.sql";
}

$colsVerified = 0;
foreach ($addedCols as $key => $tc) {
    [$t, $c, $file] = $tc;
    if (isset($droppedCols[$key])) {
        continue;   // added then dropped → netted out
    }
    if (isset($masterTables[$t])) {
        if (isset($masterTables[$t][$c])) {
            $colsVerified++;
        } else {
            $failures[] = "MISSING COLUMN: `{$t}`.`{$c}` added by {$file} but absent from master's `{$t}`";
        }
        continue;
    }
    // Owning table not in master: expected when net-dropped, or already reported
    // as a MISSING TABLE (created but unreflected). A column on a table that is
    // none of those is a true orphan.
    if (isset($droppedTables[$t]) || isset($createdTables[$t])) {
        continue;
    }
    $failures[] = "ORPHAN COLUMN: `{$t}`.`{$c}` added by {$file} but table `{$t}` is not in master";
}

// ── Surface ──
if (!empty($droppedSkips)) {
    echo "  Net-dropped (created then dropped by migrations — master correctly lacks):\n";
    foreach ($droppedSkips as $s) {
        echo "    · {$s}\n";
    }
}
echo "\n";
echo "  tables verified ⊆ master: {$tablesVerified}\n";
echo "  columns verified ⊆ master: {$colsVerified}\n";
echo "  net-dropped skipped: " . count($droppedSkips) . "\n\n";

if (empty($failures)) {
    echo "MIGRATE-PARITY OK — migrations reproduce master "
       . "({$tablesVerified} tables + {$colsVerified} columns reflected ⊆ master)\n";
    exit(0);
}

$diffPath = sys_get_temp_dir() . '/ff_migrate_parity_drift.txt';
@file_put_contents($diffPath, implode("\n", $failures) . "\n");

echo "MIGRATE-PARITY FAIL — " . count($failures) . " drift line(s) (see {$diffPath})\n\n";
echo "--- drift ---\n";
foreach ($failures as $f) {
    echo "  ✗ {$f}\n";
}
exit(1);
