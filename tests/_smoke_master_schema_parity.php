<?php
declare(strict_types=1);

/**
 * tests/_smoke_master_schema_parity.php
 *
 * S-DATABASE-MASTER-RECONCILE-2 parity smoke test.
 *
 * Confirms FLEETFORGE_DATABASE_MASTER.sql is byte-substantively
 * identical to the live dev DB schema. Catches drift introduced
 * by sessions that mutate the schema but mis-edit the master file
 * (e.g. in-place str_replace on a dropped column's slot rather
 * than appending the new column at the end of the table).
 *
 * Method:
 *   1. mysqldump live DB schema (no data) with the agreed flags.
 *   2. Drop+create scratch DB, load the master, dump it the same way.
 *   3. Normalize away cosmetic mysqldump round-trip noise:
 *        - AUTO_INCREMENT=N counters
 *        - dump-header lines (Host, Server version, etc.)
 *        - redundant `CHARACTER SET utf8mb4` on text columns
 *          (mysqldump emits this on every column when re-dumping
 *          a re-loaded DB, even though it matches the table default
 *          and is omitted from the original master file).
 *   4. Diff. Substantive lines (lines starting with + or - that
 *      survive the noise filters) → drift detected.
 *
 * Usage:
 *   php tests/_smoke_master_schema_parity.php
 *   php tests/_smoke_master_schema_parity.php --keep-scratch-db
 *     (skips drop of fleetforge_master_validate_2 for inspection)
 *   php tests/_smoke_master_schema_parity.php --print-full-diff
 *     (prints the full normalized diff, not just the substantive lines)
 *
 * Exit code: 0 on clean parity, 1 on drift.
 *
 * Final summary line is grep-able for CI:
 *   "PARITY OK — master matches live DB (N lines of cosmetic noise filtered)"
 *   or
 *   "PARITY FAIL — N substantive drift lines (see /tmp/...)"
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$argvSafe       = $argv ?? [];
$keepScratch    = in_array('--keep-scratch-db',  $argvSafe, true);
$printFullDiff  = in_array('--print-full-diff', $argvSafe, true);

// ── Resolve mysql/mysqldump credentials from .env ─────────────
$dbHost = (string) (env('DB_HOST', '127.0.0.1') ?: '127.0.0.1');
$dbPort = (string) (env('DB_PORT', '3306')      ?: '3306');
$dbName = (string) (env('DB_DATABASE', 'fleetforge') ?: 'fleetforge');
$dbUser = (string) (env('DB_USERNAME', 'root')  ?: 'root');
$dbPass = (string) (env('DB_PASSWORD', '')      ?: '');
$scratchDb = 'fleetforge_master_validate_2';

$tmp        = sys_get_temp_dir();
$liveDump   = $tmp . '/ff_parity_live.sql';
$masterDump = $tmp . '/ff_parity_master.sql';
$diffPath   = $tmp . '/ff_parity_drift.diff';

$tStart = microtime(true);

echo "S-DATABASE-MASTER-RECONCILE-2 parity smoke test\n";
echo "  master: " . FF_ROOT . "/FLEETFORGE_DATABASE_MASTER.sql\n";
echo "  live:   {$dbName} @ {$dbHost}:{$dbPort}\n\n";

// ── Helpers ───────────────────────────────────────────────────

/**
 * Run a shell command, capture combined output, return [code, output].
 * Uses proc_open with array-form arguments (no shell, no injection).
 */
function runCmd(array $cmd, ?string $stdinPath = null): array
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
    if (!$stdinPath) fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, ($stdout ?? '') . ($stderr ?? '')];
}

function dumpFlags(string $dbName): array
{
    return [
        '--no-data',
        '--routines',
        '--triggers',
        '--events',
        '--skip-comments',
        '--skip-set-charset',
        '--skip-add-drop-table',
        '--skip-add-locks',
        '--single-transaction',
        '--no-tablespaces',
        $dbName,
    ];
}

// ── Step 1: dump live DB schema ────────────────────────────────
echo "[1/4] Dumping live DB schema → {$liveDump}\n";
$cmd = array_merge(
    ['mysqldump', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser],
    $dbPass !== '' ? ['-p' . $dbPass] : [],
    dumpFlags($dbName)
);
[$code, $out] = runCmd($cmd);
if ($code !== 0) {
    echo "  ✗ mysqldump (live) exit $code\n  $out\n";
    exit(1);
}
file_put_contents($liveDump, $out);

// ── Step 2: drop+create scratch DB, load master, dump scratch ──
echo "[2/4] Loading master into scratch DB {$scratchDb}\n";
$resetSql = "DROP DATABASE IF EXISTS `{$scratchDb}`; CREATE DATABASE `{$scratchDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
$cmd = array_merge(
    ['mysql', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser],
    $dbPass !== '' ? ['-p' . $dbPass] : [],
    ['--default-character-set=utf8mb4', '-e', $resetSql]
);
[$code, $out] = runCmd($cmd);
if ($code !== 0) {
    echo "  ✗ scratch DB reset failed: $out\n";
    exit(1);
}

$masterSqlPath = FF_ROOT . '/FLEETFORGE_DATABASE_MASTER.sql';
$cmd = array_merge(
    ['mysql', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser],
    $dbPass !== '' ? ['-p' . $dbPass] : [],
    ['--default-character-set=utf8mb4', $scratchDb]
);
[$code, $out] = runCmd($cmd, $masterSqlPath);
if ($code !== 0) {
    echo "  ✗ master load failed: $out\n";
    exit(1);
}

echo "[3/4] Dumping scratch DB schema → {$masterDump}\n";
$cmd = array_merge(
    ['mysqldump', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser],
    $dbPass !== '' ? ['-p' . $dbPass] : [],
    dumpFlags($scratchDb)
);
[$code, $out] = runCmd($cmd);
if ($code !== 0) {
    echo "  ✗ mysqldump (scratch) exit $code\n  $out\n";
    exit(1);
}
file_put_contents($masterDump, $out);

// ── Step 3: normalize both dumps ───────────────────────────────
$normalize = function (string $rawPath, string $dbInDump) use ($dbName): string {
    $contents = file_get_contents($rawPath);
    // Strip per-table mysqldump SET-clutter and dump headers
    $contents = preg_replace('/^-- (Host|Server version|Dump completed|MySQL dump|---).*$/m', '', $contents);
    // Strip AUTO_INCREMENT counters (environment-specific noise)
    $contents = preg_replace('/ AUTO_INCREMENT=\d+/', '', $contents);
    // Collapse the redundant CHARACTER SET utf8mb4 that mysqldump emits
    // on every text column when re-dumping a re-loaded DB. The original
    // master file omits this because it matches the table-level default.
    $contents = preg_replace(
        '/ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci/',
        ' COLLATE utf8mb4_unicode_ci',
        $contents
    );
    // Rename the scratch DB references back to the live DB name so the
    // diff isn't littered with cosmetic differences. The dump format
    // uses the DB name in routine/trigger DEFINER and table-options
    // contexts on some MySQL versions.
    if ($dbInDump !== $dbName) {
        $contents = str_replace($dbInDump, $dbName, $contents);
    }
    // Collapse multiple blank lines
    $contents = preg_replace("/\n{3,}/", "\n\n", $contents);
    return $contents;
};

$liveNorm   = $normalize($liveDump,   $dbName);
$masterNorm = $normalize($masterDump, $scratchDb);

$liveNormPath   = $tmp . '/ff_parity_live_norm.sql';
$masterNormPath = $tmp . '/ff_parity_master_norm.sql';
file_put_contents($liveNormPath,   $liveNorm);
file_put_contents($masterNormPath, $masterNorm);

// ── Step 4: diff ──────────────────────────────────────────────
echo "[4/4] Diffing normalized dumps → {$diffPath}\n";
[$diffCode, $diffOut] = runCmd(['diff', '-u', $liveNormPath, $masterNormPath]);
file_put_contents($diffPath, $diffOut);

// Cleanup scratch DB unless --keep-scratch-db
if (!$keepScratch) {
    $cmd = array_merge(
        ['mysql', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser],
        $dbPass !== '' ? ['-p' . $dbPass] : [],
        ['-e', "DROP DATABASE IF EXISTS `{$scratchDb}`;"]
    );
    runCmd($cmd);
}

// ── Surface results ───────────────────────────────────────────
$diffLines     = $diffOut === '' ? [] : explode("\n", trim($diffOut));
$totalLines    = count($diffLines);

// "Substantive" lines = +/- lines that survive the noise filters.
// Lines starting with +++ / --- are diff headers, not content.
$substantive = [];
foreach ($diffLines as $line) {
    if ($line === '' || $line[0] !== '+' && $line[0] !== '-') continue;
    if (str_starts_with($line, '+++') || str_starts_with($line, '---')) continue;
    // Filter out any residual encoding-noise lines: comments containing
    // mojibake byte sequences for the em-dash / arrow characters.
    if (preg_match('/Ã¢â‚¬|Ã¢â€/', $line)) continue;
    $substantive[] = $line;
}
$substantiveCount = count($substantive);

$elapsed = microtime(true) - $tStart;

echo "\n";
if ($substantiveCount === 0) {
    echo "PARITY OK — master matches live DB ({$totalLines} lines of cosmetic noise filtered) in "
       . sprintf('%.1fs', $elapsed) . "\n";
    exit(0);
}

echo "PARITY FAIL — {$substantiveCount} substantive drift lines (see {$diffPath}) in "
   . sprintf('%.1fs', $elapsed) . "\n";

if ($printFullDiff) {
    echo "\n--- Full normalized diff ---\n";
    echo $diffOut . "\n";
} else {
    echo "\n--- Substantive drift (first 60 lines) ---\n";
    echo implode("\n", array_slice($substantive, 0, 60)) . "\n";
    if ($substantiveCount > 60) {
        echo "...(" . ($substantiveCount - 60) . " more — re-run with --print-full-diff)\n";
    }
}
exit(1);
