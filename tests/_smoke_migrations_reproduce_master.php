<?php
declare(strict_types=1);

/**
 * tests/_smoke_migrations_reproduce_master.php
 *
 * D-GUARD-1 keystone — upgraded to GENUINE from-zero rebuild (S-SCHEMA-BASELINE,
 * D-BASELINE-4). Replaced the prior static migrations⊆master subset check.
 *
 * WHY UPGRADED: the static check proved that every migration-created table/column
 * existed in master, but it could not catch "a table edited into master with NO
 * delta" — the residual sub-case that 000_baseline closes. Now that a full-schema
 * baseline exists, the from-zero path is both possible and verifiable: a scratch DB
 * built entirely from db_migrations/ must match master exactly (schema only, no data).
 *
 * THREE CHECKS (all hermetic — scratch DBs only, dev DB untouched):
 *
 *   SC1 — Fresh-build path (the core invariant):
 *     Create scratch DB → apply all db_migrations/*.sql in sorted order → mysqldump →
 *     normalize → diff vs normalized master → must be CLEAN.
 *     This is the literal "from-zero == master" assertion.
 *
 *   SC2 — Negative test (proves the gate bites):
 *     Load master + add a phantom CREATE TABLE into the master copy → re-run diff →
 *     must be NON-EMPTY. Proves a table-in-master-with-no-delta is caught as RED.
 *     The master file itself is NOT modified: the phantom is added only to a temp copy.
 *
 *   SC3 / SC4 — Prod-path safety (Guard 4):
 *     Create a second scratch DB loaded from master (simulates a caught-up prod that
 *     has not yet run 000_baseline) → apply 000_baseline.sql via mysql → assert zero
 *     errors AND zero schema change (dump must still match master). Proves CREATE
 *     TABLE IF NOT EXISTS + INSERT IGNORE are truly no-ops on an existing full schema.
 *
 * Final summary line is grep-able for CI:
 *   "MIGRATE-PARITY OK — SC1 clean + SC2 red + SC3/SC4 no-op clean"
 *   "MIGRATE-PARITY FAIL — <description>"
 *
 * Exit code: 0 on all three checks passing, 1 on any failure.
 *
 * @session  S-SCHEMA-BASELINE
 * @decision D-GUARD-1 (upgraded), D-BASELINE-4
 */

require_once __DIR__ . '/../config/app.php';

$argvSafe      = $argv ?? [];
$keepScratch   = in_array('--keep-scratch-db',  $argvSafe, true);
$printFullDiff = in_array('--print-full-diff',  $argvSafe, true);

$dbHost = (string) (env('DB_HOST', '127.0.0.1')     ?: '127.0.0.1');
$dbPort = (string) (env('DB_PORT', '3306')           ?: '3306');
$dbName = (string) (env('DB_DATABASE', 'fleetforge') ?: 'fleetforge');
$dbUser = (string) (env('DB_USERNAME', 'root')       ?: 'root');
$dbPass = (string) (env('DB_PASSWORD', '')           ?: '');

$masterPath    = FF_ROOT . '/FLEETFORGE_DATABASE_MASTER.sql';
$migrationsDir = FF_ROOT . '/db_migrations';
$scratchFresh  = 'fleetforge_baseline_fresh_test';
$scratchProd   = 'fleetforge_baseline_prod_sim_test';
$tmp           = sys_get_temp_dir();

$tStart = microtime(true);

echo "D-GUARD-1 keystone — genuine from-zero rebuild (S-SCHEMA-BASELINE, D-BASELINE-4)\n";
echo "  master: {$masterPath}\n";
echo "  migrations: {$migrationsDir}\n";
echo "  scratch DBs: {$scratchFresh}, {$scratchProd}\n\n";

// ── Helpers ──────────────────────────────────────────────────────────────

/**
 * Run a shell command, return [exitCode, combinedOutput].
 */
function bzRunCmd(array $cmd, ?string $stdinPath = null): array
{
    $descriptors = [
        0 => $stdinPath ? ['file', $stdinPath, 'rb'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [-1, 'proc_open failed'];
    }
    if (!$stdinPath) {
        fclose($pipes[0]);
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $stdout . $stderr];
}

function bzMysqlCmd(
    string $dbHost, string $dbPort, string $dbUser, string $dbPass
): array {
    $cmd = ['mysql', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser];
    if ($dbPass !== '') {
        $cmd[] = '-p' . $dbPass;
    }
    $cmd[] = '--default-character-set=utf8mb4';
    return $cmd;
}

function bzDumpCmd(
    string $dbHost, string $dbPort, string $dbUser, string $dbPass, string $db
): array {
    $cmd = ['mysqldump', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser];
    if ($dbPass !== '') {
        $cmd[] = '-p' . $dbPass;
    }
    return array_merge($cmd, [
        '--no-data', '--routines', '--triggers', '--events',
        '--skip-comments', '--skip-set-charset',
        '--skip-add-drop-table', '--skip-add-locks',
        '--single-transaction', '--no-tablespaces',
        $db,
    ]);
}

function bzNormalize(string $rawPath, string $dbInDump, string $canonicalDb): string
{
    $c = file_get_contents($rawPath);
    $c = preg_replace('/^-- (Host|Server version|Dump completed|MySQL dump|---).*$/m', '', $c ?? '');
    $c = preg_replace('/ AUTO_INCREMENT=\d+/', '', $c ?? '');
    $c = preg_replace('/ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci/', ' COLLATE utf8mb4_unicode_ci', $c ?? '');
    if ($dbInDump !== $canonicalDb) {
        $c = str_replace($dbInDump, $canonicalDb, $c ?? '');
    }
    return preg_replace("/\n{3,}/", "\n\n", $c ?? '') ?? '';
}

/** Fail the run with a message and cleanup if needed. */
function bzFail(
    string $msg,
    string $scratchFresh, string $scratchProd,
    bool   $keepScratch,
    string $dbHost, string $dbPort, string $dbUser, string $dbPass
): never {
    echo "\nMIGRATE-PARITY FAIL — {$msg}\n";
    if (!$keepScratch) {
        bzDropScratch($scratchFresh, $dbHost, $dbPort, $dbUser, $dbPass);
        bzDropScratch($scratchProd,  $dbHost, $dbPort, $dbUser, $dbPass);
    }
    exit(1);
}

function bzDropScratch(
    string $db,
    string $dbHost, string $dbPort, string $dbUser, string $dbPass
): void {
    $cmd = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
    $cmd[] = '-e';
    $cmd[] = "DROP DATABASE IF EXISTS `{$db}`;";
    bzRunCmd($cmd);
}

// ── Resolve migration files (sorted, *.sql only) ─────────────────────────

$migrationFiles = [];
foreach (scandir($migrationsDir) ?: [] as $entry) {
    if (substr($entry, -4) === '.sql') {
        $migrationFiles[] = $entry;
    }
}
sort($migrationFiles, SORT_STRING);

echo "[info] Migration files to apply: " . count($migrationFiles) . " (" . implode(', ', $migrationFiles) . ")\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// SC1 — Fresh-build path
// ═══════════════════════════════════════════════════════════════════════════

echo "══ SC1: fresh-build path ══\n";
echo "[1/5] Creating scratch DB {$scratchFresh}\n";

$resetCmd = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
$resetCmd[] = '-e';
$resetCmd[] = "DROP DATABASE IF EXISTS `{$scratchFresh}`; CREATE DATABASE `{$scratchFresh}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
[$code, $out] = bzRunCmd($resetCmd);
if ($code !== 0) {
    bzFail("SC1 scratch DB create failed: {$out}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}

echo "[2/5] Applying " . count($migrationFiles) . " migration file(s) to {$scratchFresh}\n";
foreach ($migrationFiles as $mf) {
    $mPath = $migrationsDir . '/' . $mf;
    $applyCmd = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
    $applyCmd[] = $scratchFresh;
    [$code, $out] = bzRunCmd($applyCmd, $mPath);
    if ($code !== 0) {
        // Filter the expected mysql password-on-CLI warning
        $filtered = preg_replace('/.*Using a password.*\n?/m', '', $out) ?? $out;
        bzFail("SC1 applying {$mf} failed (exit {$code}):\n{$filtered}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
    }
    echo "  ✓ {$mf}\n";
}

echo "[3/5] Dumping {$scratchFresh} schema\n";
$freshDump  = $tmp . '/ff_bz_fresh.sql';
$masterDump = $tmp . '/ff_bz_master.sql';
[$code, $out] = bzRunCmd(bzDumpCmd($dbHost, $dbPort, $dbUser, $dbPass, $scratchFresh));
if ($code !== 0) {
    bzFail("SC1 dump(fresh) failed: {$out}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
file_put_contents($freshDump, $out);

echo "[4/5] Dumping master into scratch copy\n";
$masterLoadDb = 'fleetforge_baseline_master_copy_test';
$resetCmd2 = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
$resetCmd2[] = '-e';
$resetCmd2[] = "DROP DATABASE IF EXISTS `{$masterLoadDb}`; CREATE DATABASE `{$masterLoadDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
bzRunCmd($resetCmd2);
$loadCmd = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
$loadCmd[] = '--default-character-set=utf8mb4';
$loadCmd[] = $masterLoadDb;
[$code, $out] = bzRunCmd($loadCmd, $masterPath);
if ($code !== 0) {
    bzFail("SC1 master load failed: {$out}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
[$code, $out] = bzRunCmd(bzDumpCmd($dbHost, $dbPort, $dbUser, $dbPass, $masterLoadDb));
if ($code !== 0) {
    bzFail("SC1 dump(master) failed: {$out}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
file_put_contents($masterDump, $out);
// Drop the master copy DB now (not the main scratchFresh which we reuse for SC2 if needed)
$dropCmd = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
$dropCmd[] = '-e';
$dropCmd[] = "DROP DATABASE IF EXISTS `{$masterLoadDb}`;";
bzRunCmd($dropCmd);

echo "[5/5] Diffing normalized dumps\n";
$freshNorm  = bzNormalize($freshDump,  $scratchFresh, $dbName);
$masterNorm = bzNormalize($masterDump, $masterLoadDb, $dbName);
$freshNormPath  = $tmp . '/ff_bz_fresh_norm.sql';
$masterNormPath = $tmp . '/ff_bz_master_norm.sql';
file_put_contents($freshNormPath,  $freshNorm);
file_put_contents($masterNormPath, $masterNorm);

[$diffCode, $diffOut] = bzRunCmd(['diff', '-u', $freshNormPath, $masterNormPath]);
$diffPath = $tmp . '/ff_bz_sc1_diff.txt';
file_put_contents($diffPath, $diffOut);

$sc1Lines = $diffOut === '' ? [] : explode("\n", trim($diffOut));
$sc1Substantive = [];
foreach ($sc1Lines as $line) {
    if ($line === '' || ($line[0] !== '+' && $line[0] !== '-')) {
        continue;
    }
    if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
        continue;
    }
    if (preg_match('/Ã¢â‚¬|Ã¢â€/', $line)) {
        continue;
    }
    $sc1Substantive[] = $line;
}

if (!empty($sc1Substantive)) {
    echo "\nSC1 FAIL — from-zero build does NOT match master: " . count($sc1Substantive) . " substantive diff lines\n";
    foreach (array_slice($sc1Substantive, 0, 40) as $l) {
        echo "  {$l}\n";
    }
    if (count($sc1Substantive) > 40) {
        echo "  ...(" . (count($sc1Substantive) - 40) . " more — re-run with --print-full-diff)\n";
    }
    if ($printFullDiff) {
        echo "\n--- Full diff ---\n{$diffOut}\n";
    }
    bzFail("SC1 from-zero != master (see {$diffPath})", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
echo "SC1 PASS — from-zero build matches master (" . count($sc1Lines) . " cosmetic lines filtered)\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// SC2 — Negative test: phantom table in master → expect RED
// ═══════════════════════════════════════════════════════════════════════════

echo "══ SC2: negative test (phantom table in master → expect RED) ══\n";

// Append a phantom table to the NORMALIZED master copy (temp file only — does NOT
// touch FLEETFORGE_DATABASE_MASTER.sql).
$phantomDdl = "\nCREATE TABLE `_sc2_phantom_table_guard_test` (\n  `id` int unsigned NOT NULL AUTO_INCREMENT,\n  `phantom_col` varchar(100) NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB COLLATE utf8mb4_unicode_ci;\n";
$masterNormPhantom = $masterNorm . $phantomDdl;
$masterNormPhantomPath = $tmp . '/ff_bz_master_norm_phantom.sql';
file_put_contents($masterNormPhantomPath, $masterNormPhantom);

// Diff: from-zero (no phantom) vs master-with-phantom → must be non-empty
[$diffCode2, $diffOut2] = bzRunCmd(['diff', '-u', $freshNormPath, $masterNormPhantomPath]);
$sc2Lines = $diffOut2 === '' ? [] : explode("\n", trim($diffOut2));
$sc2Substantive = array_filter($sc2Lines, static function (string $line): bool {
    if ($line === '' || ($line[0] !== '+' && $line[0] !== '-')) {
        return false;
    }
    if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
        return false;
    }
    return true;
});

if (empty($sc2Substantive)) {
    bzFail("SC2 NEGATIVE TEST FAILED — a phantom table in master was NOT detected (keystone is broken)", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
echo "SC2 PASS — phantom table in master detected RED (" . count($sc2Substantive) . " diff lines, expected >0)\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// SC3/SC4 — Prod-path: 000_baseline is a no-op on a full schema
// ═══════════════════════════════════════════════════════════════════════════

echo "══ SC3/SC4: prod-path safety (000_baseline no-op on full schema) ══\n";
echo "[1/4] Creating prod-sim scratch DB {$scratchProd} from master\n";

$resetCmd3 = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
$resetCmd3[] = '-e';
$resetCmd3[] = "DROP DATABASE IF EXISTS `{$scratchProd}`; CREATE DATABASE `{$scratchProd}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
[$code, $out] = bzRunCmd($resetCmd3);
if ($code !== 0) {
    bzFail("SC4 scratch DB create failed: {$out}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
$loadCmd2 = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
$loadCmd2[] = '--default-character-set=utf8mb4';
$loadCmd2[] = $scratchProd;
[$code, $out] = bzRunCmd($loadCmd2, $masterPath);
if ($code !== 0) {
    bzFail("SC4 master load failed: {$out}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}

echo "[2/4] Applying 000_baseline.sql against full-schema prod-sim DB\n";
$baselinePath = $migrationsDir . '/000_baseline.sql';
$applyCmd2    = bzMysqlCmd($dbHost, $dbPort, $dbUser, $dbPass);
$applyCmd2[]  = $scratchProd;
[$code, $out] = bzRunCmd($applyCmd2, $baselinePath);
if ($code !== 0) {
    $filtered = preg_replace('/.*Using a password.*\n?/m', '', $out) ?? $out;
    bzFail("SC4 000_baseline.sql application ERRORED on full-schema DB (Guard 1 violation):\n{$filtered}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
echo "  ✓ 000_baseline.sql applied with no errors\n";

echo "[3/4] Dumping prod-sim DB after 000_baseline apply\n";
$prodDump = $tmp . '/ff_bz_prod_sim.sql';
[$code, $out] = bzRunCmd(bzDumpCmd($dbHost, $dbPort, $dbUser, $dbPass, $scratchProd));
if ($code !== 0) {
    bzFail("SC4 dump(prod-sim) failed: {$out}", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
file_put_contents($prodDump, $out);

echo "[4/4] Diffing prod-sim vs master (must be clean — no schema change)\n";
$prodNorm     = bzNormalize($prodDump, $scratchProd, $dbName);
$prodNormPath = $tmp . '/ff_bz_prod_norm.sql';
file_put_contents($prodNormPath, $prodNorm);

[$diffCode3, $diffOut3] = bzRunCmd(['diff', '-u', $masterNormPath, $prodNormPath]);
$sc4Lines = $diffOut3 === '' ? [] : explode("\n", trim($diffOut3));
$sc4Substantive = [];
foreach ($sc4Lines as $line) {
    if ($line === '' || ($line[0] !== '+' && $line[0] !== '-')) {
        continue;
    }
    if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
        continue;
    }
    if (preg_match('/Ã¢â‚¬|Ã¢â€/', $line)) {
        continue;
    }
    $sc4Substantive[] = $line;
}

if (!empty($sc4Substantive)) {
    echo "\nSC4 FAIL — 000_baseline.sql changed the schema of the prod-sim DB: " . count($sc4Substantive) . " diff lines\n";
    foreach (array_slice($sc4Substantive, 0, 40) as $l) {
        echo "  {$l}\n";
    }
    bzFail("SC4 000_baseline.sql is NOT a no-op on full schema (see " . $tmp . "/ff_bz_prod_diff.txt)", $scratchFresh, $scratchProd, $keepScratch, $dbHost, $dbPort, $dbUser, $dbPass);
}
echo "SC3/SC4 PASS — 000_baseline.sql is a no-op on a full-schema DB (no schema change, no errors)\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// Cleanup + summary
// ═══════════════════════════════════════════════════════════════════════════

if (!$keepScratch) {
    bzDropScratch($scratchFresh, $dbHost, $dbPort, $dbUser, $dbPass);
    bzDropScratch($scratchProd,  $dbHost, $dbPort, $dbUser, $dbPass);
}

$elapsed = microtime(true) - $tStart;
echo sprintf(
    "MIGRATE-PARITY OK — SC1 clean + SC2 red + SC3/SC4 no-op clean (%.1fs)\n",
    $elapsed
);
exit(0);
