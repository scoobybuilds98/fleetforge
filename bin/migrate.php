#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/migrate.php — FleetForge schema migration CLI.
 *
 * Usage:
 *   php bin/migrate.php                  # dry-run (default, mutates nothing)
 *   php bin/migrate.php --dry-run        # explicit dry-run
 *   php bin/migrate.php --apply          # actually apply pending migrations
 *   php bin/migrate.php --backfill       # one-time bootstrap of 5 historical files
 *   php bin/migrate.php --verify         # recompute checksums of every applied file
 *   php bin/migrate.php --status         # short status (counts only, exit 0)
 *   php bin/migrate.php --help
 *
 * Exit codes:
 *   0  success / clean
 *   1  generic error / SQL failure
 *   2  bad CLI args
 *   3  another runner is in flight (lock held)
 *   4  checksum drift detected (--dry-run, --apply, or --verify)
 *
 * @session  S-MIGRATIONS-RUNNER
 * @decision D-H (dry-run default), D-G (GET_LOCK ff_migrations 0)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/migrate.php must run from the command line.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

\FleetForge\Observability\Sentry::init();

use FleetForge\Migrations\Runner;
use FleetForge\Observability\Sentry;

// ── CLI parsing ────────────────────────────────────────────────

$args = array_slice($argv, 1);
$mode = 'dry-run';
$showHelp = false;

foreach ($args as $a) {
    switch ($a) {
        case '--dry-run':  $mode = 'dry-run';  break;
        case '--apply':    $mode = 'apply';    break;
        case '--backfill': $mode = 'backfill'; break;
        case '--verify':   $mode = 'verify';   break;
        case '--status':   $mode = 'status';   break;
        case '-h': case '--help': $showHelp = true; break;
        default:
            fwrite(STDERR, "Unknown argument: {$a}\nRun with --help for usage.\n");
            exit(2);
    }
}

if ($showHelp) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500) ?: '');
    exit(0);
}

// ── Header ─────────────────────────────────────────────────────

$now    = date('Y-m-d H:i:s');
$cliUser = trim((string) shell_exec('whoami 2>/dev/null') ?? '') ?: 'cli';
$banner = "═══ FleetForge migrations — {$now} — mode: {$mode} ═══";
echo "\n{$banner}\n\n";

// ── Run ────────────────────────────────────────────────────────

$exitCode  = 0;
$runner    = new Runner();
$lockHeld  = false;

try {
    if ($mode !== 'verify' && $mode !== 'status') {
        $runner->acquireLock();
        $lockHeld = true;
    }

    switch ($mode) {
        case 'dry-run':  $exitCode = cmd_dry_run($runner); break;
        case 'apply':    $exitCode = cmd_apply($runner, $cliUser); break;
        case 'backfill': $exitCode = cmd_backfill($runner, $cliUser); break;
        case 'verify':   $exitCode = cmd_verify($runner); break;
        case 'status':   $exitCode = cmd_status($runner); break;
    }
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Another migration run is in flight')) {
        echo "✗ {$msg}\n";
        $exitCode = 3;
    } else {
        echo "✗ ERROR: {$msg}\n";
        Sentry::captureException($e);
        $exitCode = 1;
    }
} finally {
    if ($lockHeld) {
        $runner->releaseLock();
    }
}

echo "\n";
exit($exitCode);

// ── Subcommands ────────────────────────────────────────────────

/**
 * --dry-run: list pending migrations without applying.
 */
function cmd_dry_run(Runner $runner): int
{
    $plan = $runner->plan();
    $pending = $plan['to_apply'];
    $drift   = $plan['drift'];
    $already = $plan['already'];

    echo "Migrations directory: " . $runner->getMigrationsDir() . "\n";
    echo "Already applied:      {$already}\n";
    echo "Pending:              " . count($pending) . "\n";
    echo "Checksum drift:       " . count($drift) . "\n\n";

    if (count($drift) > 0) {
        echo "⚠ CHECKSUM DRIFT — these files were edited after being applied:\n";
        foreach ($drift as $d) {
            echo "  - {$d['filename']}\n";
            echo "      stored  {$d['stored']}\n";
            echo "      current {$d['current']}\n";
        }
        echo "\nRefusing to apply until drift is resolved. Either:\n";
        echo "  • Revert the file edit so checksum matches stored, OR\n";
        echo "  • Manually update schema_migrations.checksum to match if the\n";
        echo "    edit was intentional and the DB already reflects it.\n";
        return 4;
    }

    if (count($pending) === 0) {
        echo "✓ 0 migrations to apply.\n";
        return 0;
    }

    echo "Would apply (in order):\n";
    foreach ($pending as $p) {
        echo "  - {$p['filename']}\n";
        echo "      sha256: {$p['checksum']}\n";
    }
    echo "\nRun with --apply to execute.\n";
    return 0;
}

/**
 * --apply: actually run pending migrations.
 */
function cmd_apply(Runner $runner, string $cliUser): int
{
    $plan = $runner->plan();
    $pending = $plan['to_apply'];
    $drift   = $plan['drift'];

    if (count($drift) > 0) {
        echo "⚠ Refusing to apply — checksum drift detected. Run --dry-run for details.\n";
        return 4;
    }

    if (count($pending) === 0) {
        echo "✓ 0 migrations to apply (everything is up to date).\n";
        return 0;
    }

    echo "Applying " . count($pending) . " migration(s)...\n\n";

    foreach ($pending as $p) {
        $filename = $p['filename'];
        echo "→ {$filename}\n";
        try {
            $ms = $runner->applyFile($filename);
            $runner->recordApplied(
                $filename,
                $p['checksum'],
                $ms,
                $cliUser
            );
            echo "  ✓ applied in {$ms}ms (sha256: {$p['checksum']})\n";
        } catch (\Throwable $e) {
            echo "  ✗ FAILED\n";
            echo $e->getMessage() . "\n";
            return 1;
        }
    }

    echo "\n✓ All migrations applied.\n";
    return 0;
}

/**
 * --backfill: one-time bootstrap of historical files. Refuses if
 * schema_migrations is non-empty.
 */
function cmd_backfill(Runner $runner, string $cliUser): int
{
    echo "Backfilling " . count(Runner::HISTORICAL_FILES) . " historical migrations...\n\n";

    $results = $runner->backfill('backfill:' . $cliUser);

    foreach ($results as $r) {
        echo "  ✓ {$r['filename']}\n";
        echo "      sha256:     {$r['checksum']}\n";
        echo "      applied_at: {$r['applied_at']} (file mtime)\n";
    }
    echo "\n✓ Backfill complete: " . count($results) . " rows recorded.\n";
    return 0;
}

/**
 * --verify: recompute every stored checksum against current files.
 * Reports drift and missing files but applies nothing.
 */
function cmd_verify(Runner $runner): int
{
    $r = $runner->verify();
    $okN     = count($r['ok']);
    $driftN  = count($r['drift']);
    $missN   = count($r['missing_file']);
    $total   = $okN + $driftN + $missN;

    echo "Verifying {$total} applied migration(s)...\n\n";

    foreach ($r['ok'] as $f) {
        echo "  ✓ {$f}\n";
    }
    if ($driftN > 0) {
        echo "\n⚠ DRIFT ({$driftN}):\n";
        foreach ($r['drift'] as $d) {
            echo "  ✗ {$d['filename']}\n";
            echo "      stored  {$d['stored']}\n";
            echo "      current {$d['current']}\n";
        }
    }
    if ($missN > 0) {
        echo "\n⚠ MISSING FILES ({$missN}) — recorded as applied but not on disk:\n";
        foreach ($r['missing_file'] as $f) {
            echo "  ✗ {$f}\n";
        }
    }

    echo "\nResult: {$okN} ok / {$driftN} drift / {$missN} missing\n";
    return ($driftN === 0 && $missN === 0) ? 0 : 4;
}

/**
 * --status: terse one-screen summary, always exit 0.
 */
function cmd_status(Runner $runner): int
{
    $plan = $runner->plan();
    echo "applied:   {$plan['already']}\n";
    echo "pending:   " . count($plan['to_apply']) . "\n";
    echo "drift:     " . count($plan['drift']) . "\n";
    return 0;
}
