<?php
declare(strict_types=1);

/**
 * tests/runner.php
 *
 * S-BILLING-TESTS-ADVERSARIAL main runner. Discovers test files,
 * dispatches to tier subsets, reports pass/fail per tier.
 *
 * Each test file under tests/_unit/, tests/_integration/, tests/_interaction/,
 * tests/_regression/ defines functions named test_XX_NNN($ctx) — the runner
 * scans included files for functions matching that pattern and invokes them.
 *
 * Per spec §4.1 the test database setup is deferred to a future session —
 * existing repo convention is transactional rollback against the main
 * `fleetforge` DB via DbState::inTransaction. Documented in DbState.php.
 *
 * Usage:
 *   php tests/runner.php                  # run everything
 *   php tests/runner.php --tier=1         # run tier 1 only
 *   php tests/runner.php --tier=1,2       # run tiers 1+2
 *   php tests/runner.php --test=RR-001    # run just one test
 *   php tests/runner.php --fast           # skip mutation suite (slow)
 *   php tests/runner.php --verbose        # detailed output
 *   php tests/runner.php --bail           # stop on first failure
 *   php tests/runner.php --report=html    # generate HTML report
 *
 * Exit codes:
 *   0  all pass
 *   1  at least one test failed
 *   3  mutation kill rate below 95%
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 * @spec    FleetForge_Holistic_Billing_Engine_Test_Spec.docx §36, §40
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "tests/runner.php must run from the command line.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';

require_once __DIR__ . '/helpers/Assert.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Tests\Assert;
use FleetForge\Tests\AssertionFailed;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

// ── CLI parsing ─────────────────────────────────────────────
$args     = array_slice($argv, 1);
$tiers    = [];     // empty = run all
$onlyTest = null;
$fast     = false;
$verbose  = false;
$bail     = false;
$htmlOut  = null;

foreach ($args as $a) {
    if (str_starts_with($a, '--tier=')) {
        foreach (explode(',', substr($a, 7)) as $t) {
            $tiers[] = (int)$t;
        }
    } elseif (str_starts_with($a, '--test=')) {
        $onlyTest = substr($a, 7);
    } elseif ($a === '--fast') {
        $fast = true;
    } elseif ($a === '--verbose' || $a === '-v') {
        $verbose = true;
    } elseif ($a === '--bail') {
        $bail = true;
    } elseif (str_starts_with($a, '--report=')) {
        $htmlOut = substr($a, 9);
    } elseif ($a === '-h' || $a === '--help') {
        echo file_get_contents(__FILE__, false, null, 0, 2000);
        exit(0);
    } else {
        fwrite(STDERR, "Unknown arg: $a\n");
        exit(2);
    }
}

// ── Tier registry ───────────────────────────────────────────
// Maps tier number → list of test files. Each file defines functions
// named test_<PREFIX>_<NNN>($ctx) which the runner discovers via
// get_defined_functions() before and after the include.
$tierFiles = [
    1 => [
        ['Day Counting',         '[DC]',  __DIR__ . '/_unit/tier1_day_counting.php',   40],
        ['Tier Formula',         '[TF]',  __DIR__ . '/_unit/tier1_tier_formula.php',   75],
        ['WhicheverPaysMore',    '[WPM]', __DIR__ . '/_unit/tier1_wpm.php',            30],
        ['Running Reconcile',    '[RR]',  __DIR__ . '/_unit/tier1_reconciliation.php', 45],
    ],
    2 => [
        ['Boundary Values',      '[BV]', __DIR__ . '/_unit/tier2_boundary.php',       50],
        ['Malformed Inputs',     '[MI]', __DIR__ . '/_unit/tier2_malformed.php',      35],
        ['Precision/Rounding',   '[PR]', __DIR__ . '/_unit/tier2_precision.php',      40],
        ['Negative/Zero',        '[NZ]', __DIR__ . '/_unit/tier2_negative_zero.php',  25],
    ],
    4 => [
        ['Multi-invoice',        '[ML]', __DIR__ . '/_integration/tier4_lifecycle.php',    30],
        ['DB State',             '[DB]', __DIR__ . '/_integration/tier4_db_state.php',     25],
        ['Transactions',         '[TR]', __DIR__ . '/_integration/tier4_transactions.php', 20],
        ['Audit Trail',          '[AT]', __DIR__ . '/_integration/tier4_audit.php',        15],
    ],
    6 => [
        ['Mileage',              '[MX]', __DIR__ . '/_interaction/tier6_mileage.php',  20],
        ['Tax',                  '[TX]', __DIR__ . '/_interaction/tier6_tax.php',      15],
        ['Discount',             '[DI]', __DIR__ . '/_interaction/tier6_discount.php', 12],
        ['Add-ons',              '[AO]', __DIR__ . '/_interaction/tier6_addons.php',   10],
        ['Currency',             '[FX]', __DIR__ . '/_interaction/tier6_currency.php', 8],
    ],
    7 => [
        ['Historical regression','[RG]', __DIR__ . '/_regression/tier7_historical.php',  15],
        ['Mutations',            '[MT]', __DIR__ . '/_regression/tier7_mutations.php',   25],
        ['Spec conformance',     '[SC]', __DIR__ . '/_regression/tier7_conformance.php', 20],
    ],
];

$activeTiers = empty($tiers) ? array_keys($tierFiles) : $tiers;

// ── Discovery: include each tier file and snapshot newly-defined functions
$discovered = []; // tier => [ [groupName, tag, expected, tests=[name=>fn]], ... ]
foreach ($activeTiers as $tier) {
    if (!isset($tierFiles[$tier])) continue;
    $discovered[$tier] = [];

    foreach ($tierFiles[$tier] as [$groupName, $tag, $path, $expected]) {
        if (!is_readable($path)) {
            $discovered[$tier][] = [$groupName, $tag, $expected, [], "MISSING FILE: $path"];
            continue;
        }
        $before = get_defined_functions()['user'];
        require_once $path;
        $after  = get_defined_functions()['user'];
        $new    = array_diff($after, $before);

        // Only keep functions matching test_<PREFIX>_<NNN> for this group's tag.
        $tagClean = trim($tag, '[]');
        $pattern  = "/^test_{$tagClean}_(\\d+|[a-z0-9_]+)$/i";
        $tests    = [];
        foreach ($new as $fn) {
            if (preg_match($pattern, $fn, $m)) {
                $tests[$fn] = $fn;
            }
        }
        $discovered[$tier][] = [$groupName, $tag, $expected, $tests, null];
    }
}

// ── Run ─────────────────────────────────────────────────────
$totalPass = 0;
$totalFail = 0;
$failures  = [];   // [tier, group, testId, msg]
$t0        = microtime(true);

echo "FleetForge Holistic Billing Engine — Adversarial Test Suite\n";
echo str_repeat('=', 70) . "\n\n";

foreach ($discovered as $tier => $groups) {
    $tierName = [
        1 => 'Foundational Correctness',
        2 => 'Adversarial Inputs',
        4 => 'Integration & State',
        6 => 'Interaction Tests',
        7 => 'Regression & Mutation',
    ][$tier] ?? "Tier $tier";

    echo "[Tier $tier] $tierName\n";

    $tierPass = 0;
    $tierFail = 0;

    foreach ($groups as [$groupName, $tag, $expected, $tests, $err]) {
        if ($err !== null) {
            echo "  $tag $groupName ... $err\n";
            $tierFail += $expected;
            continue;
        }

        $t1 = microtime(true);
        $gp = 0; $gf = 0;
        $tagClean = trim($tag, '[]');

        foreach ($tests as $fn) {
            // Resolve test ID from function name for --test filtering.
            preg_match("/^test_{$tagClean}_(.+)$/i", $fn, $m);
            $testId = strtoupper($tagClean) . '-' . str_pad($m[1] ?? '?', 3, '0', STR_PAD_LEFT);

            if ($onlyTest !== null && $testId !== $onlyTest) continue;

            try {
                $fn();
                $gp++;
                if ($verbose) echo "    PASS $testId\n";
            } catch (AssertionFailed $e) {
                $gf++;
                $failures[] = [$tier, $groupName, $testId, $e->getMessage()];
                echo "    FAIL $testId — " . $e->getMessage() . "\n";
                if ($bail) break 3;
            } catch (\Throwable $e) {
                $gf++;
                $failures[] = [$tier, $groupName, $testId, 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage()];
                echo "    FAIL $testId — uncaught " . get_class($e) . ': ' . $e->getMessage() . "\n";
                if ($bail) break 3;
            }
        }

        $dt   = number_format(microtime(true) - $t1, 2);
        $mark = $gf === 0 ? '✓' : '✗';
        $count = "$gp/" . ($gp + $gf);
        $expectStr = $expected > 0 ? " (expected $expected)" : '';
        $countDisplay = ($gp + $gf) !== $expected ? "$count$expectStr" : $count;
        echo "  $tag $groupName  $countDisplay $mark ({$dt}s)\n";

        $tierPass += $gp;
        $tierFail += $gf;
    }

    $totalPass += $tierPass;
    $totalFail += $tierFail;
    echo "\n";
}

// ── Summary ─────────────────────────────────────────────────
$dt = number_format(microtime(true) - $t0, 2);
echo str_repeat('=', 70) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 70) . "\n";
echo "Total tests:    " . ($totalPass + $totalFail) . "\n";
echo "Passed:         $totalPass\n";
echo "Failed:         $totalFail\n";
echo "Duration:       {$dt}s\n";

if (!empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as [$t, $g, $id, $m]) {
        echo "  T$t $g $id\n    $m\n";
    }
}

// HTML report (minimal — for CI integration)
if ($htmlOut !== null) {
    $html  = "<!DOCTYPE html><html><head><meta charset='utf-8'><title>FleetForge Test Report</title></head><body>";
    $html .= "<h1>FleetForge Adversarial Test Suite</h1>";
    $html .= "<p>Pass: $totalPass / " . ($totalPass + $totalFail) . " — Duration: {$dt}s</p>";
    if (!empty($failures)) {
        $html .= "<h2>Failures</h2><ul>";
        foreach ($failures as [$t, $g, $id, $m]) {
            $html .= "<li><strong>T$t $g $id</strong> — " . htmlspecialchars($m) . "</li>";
        }
        $html .= "</ul>";
    }
    $html .= "</body></html>";
    file_put_contents($htmlOut, $html);
    echo "\nHTML report: $htmlOut\n";
}

exit($totalFail > 0 ? 1 : 0);
