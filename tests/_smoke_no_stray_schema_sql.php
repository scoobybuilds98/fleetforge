<?php
declare(strict_types=1);

/**
 * tests/_smoke_no_stray_schema_sql.php
 *
 * D-GUARD-2 (S-SCHEMA-GUARD-1) — the PRIMARY net for the 2026-06-05 outage class.
 *
 * Fails the gate if EITHER:
 *   (i)  the deprecated database/migrations/ directory exists at all, OR
 *   (ii) any *.sql file containing CREATE TABLE / ALTER TABLE lives ANYWHERE in
 *        the repo other than db_migrations/ + FLEETFORGE_DATABASE_MASTER.sql.
 *
 * WHY: today's outage was a CREATE TABLE filed in the UNSCANNED database/
 * migrations/ dir — bin/migrate.php only scans db_migrations/, so the table was
 * never applied to a migrate-built DB and never reached existing prod via
 * `migrate --apply`. This guard makes that structurally impossible: every schema
 * change is FORCED to be a scanned db_migrations/ delta. Pure data seeds
 * (INSERT-only *.sql, e.g. database/seeds/) are allowed.
 *
 * ABSOLUTE — there is NO whitelist/exception path. A schema *.sql sitting outside
 * db_migrations/ is precisely the crack that caused the outage; a tolerated
 * "archive" location would reopen it. Deprecated historical artifacts are kept
 * on-disk but neutered to *.sql.txt (see scripts/archive/legacy_database_migrations/)
 * so they do not match the *.sql scan.
 *
 * Hermetic: filesystem-only, no DB / network.
 *
 * Final summary line is grep-able for CI:
 *   "NO-STRAY-SCHEMA OK — N sql files scanned, 0 stray schema files"
 *   "NO-STRAY-SCHEMA FAIL — N offender(s) (see list)"
 *
 * Exit code: 0 clean, 1 on any offender.
 *
 * @session  S-SCHEMA-GUARD-1
 * @decision D-GUARD-2
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/_smoke_schema_lib.php';

$root = rtrim(FF_ROOT, '/');

echo "S-SCHEMA-GUARD-1 — no stray schema SQL (D-GUARD-2, absolute)\n";
echo "  root: {$root}\n\n";

$offenders = [];

// ── (i) deprecated dir must not exist ──
$deprecatedDir = $root . '/database/migrations';
if (is_dir($deprecatedDir)) {
    $offenders[] = "DEPRECATED DIR EXISTS: database/migrations/ — the unscanned dir that caused the outage; it must not exist (move any migration into db_migrations/)";
}
echo "[1/2] database/migrations/ : " . (is_dir($deprecatedDir) ? "EXISTS ✗" : "absent ✓") . "\n";

// ── (ii) scan every *.sql for schema DDL outside the two sanctioned homes ──
// Directories that never hold project SQL — skip for speed/noise.
$skipDirs = ['/.git/', '/node_modules/', '/vendor/'];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$scanned = 0;
foreach ($rii as $fileInfo) {
    /** @var SplFileInfo $fileInfo */
    if (!$fileInfo->isFile()) {
        continue;
    }
    $path = $fileInfo->getPathname();
    if (substr($path, -4) !== '.sql') {
        continue;
    }
    $skip = false;
    foreach ($skipDirs as $sd) {
        if (strpos($path, $sd) !== false) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $rel = ltrim(substr($path, strlen($root)), '/');

    // Sanctioned homes: db_migrations/*.sql and the master file itself.
    if (strpos($rel, 'db_migrations/') === 0) {
        $scanned++;
        continue;
    }
    if ($rel === 'FLEETFORGE_DATABASE_MASTER.sql') {
        $scanned++;
        continue;
    }

    $scanned++;
    $sql = @file_get_contents($path);
    if ($sql === false) {
        continue;
    }
    if (ff_schema_sql_has_table_ddl($sql)) {
        $offenders[] = "STRAY SCHEMA SQL: {$rel} contains CREATE/ALTER TABLE outside db_migrations/ + master "
                     . "(make it a db_migrations/ delta, or neuter to *.sql.txt if it is dead historical prose)";
    }
}
echo "[2/2] scanned {$scanned} *.sql file(s) for stray schema DDL\n\n";

// ── Surface ──
if (empty($offenders)) {
    echo "NO-STRAY-SCHEMA OK — {$scanned} sql files scanned, 0 stray schema files\n";
    exit(0);
}

echo "NO-STRAY-SCHEMA FAIL — " . count($offenders) . " offender(s):\n\n";
foreach ($offenders as $o) {
    echo "  ✗ {$o}\n";
}
exit(1);
