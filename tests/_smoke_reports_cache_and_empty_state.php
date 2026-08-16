<?php
declare(strict_types=1);

/**
 * tests/_smoke_reports_cache_and_empty_state.php
 *
 * S-REPORTS-STALE — the Reports module went stale and looked broken.
 *
 * Two independent defects, both locked down here:
 *
 *  1. CACHE INVALIDATION. json_success() invalidated report_cache on every write,
 *     but only rows matching 'dashboard_%'. The Reports module caches under
 *     revenue_/fleet_/customer_/compliance_ prefixes, so those rows survived
 *     every write and Reports served a snapshot up to 15 minutes stale while the
 *     Dashboard refreshed instantly.
 *
 *     The fix is an ALLOWLIST, not a blanket wipe, because report_cache also
 *     holds 'ai_fleet_brief' — the product of a paid Claude API call, read back
 *     by MorningBriefingRenderer. A blanket DELETE would silently break the
 *     morning brief and cost money to regenerate. The escaping matters too:
 *     `_` is a LIKE wildcard, so an unescaped 'fleet_%' also matches
 *     'fleetXanything'.
 *
 *  2. EMPTY-STATE CENSUS. Revenue views (Reports) AND six of the eight Analytics
 *     views exclude draft/void/written_off per the
 *     reporting policy, so a range holding nothing but drafts rendered a silent
 *     grid of $0.00 — indistinguishable from "no business activity". The APIs
 *     now return an `excluded` block so the UI can name the cause.
 *
 * PRE-FIX  : analytics rows survive a write; no `excluded` key in the response.
 * POST-FIX : all five prefixes purged, ai_fleet_brief intact, `excluded` present
 *            and counting the real draft/void rows in range.
 *
 * Hermetic: seeds inside a transaction and rolls back. The endpoint assertions
 * run against whatever the dev DB holds, asserting SHAPE + internal consistency
 * against a direct SQL count rather than hard-coded totals.
 *
 * Run:  php tests/_smoke_reports_cache_and_empty_state.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-REPORTS-STALE
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo "────────────────────────────────────────────────────────────────────────\n";
echo "S-REPORTS-STALE — analytics cache invalidation + empty-state census\n";
echo "────────────────────────────────────────────────────────────────────────\n";

// ═════════════════════════════════════════════════════════════════════════════
// PART 1 — invalidate_analytics_cache() allowlist
// ═════════════════════════════════════════════════════════════════════════════

if (!function_exists('invalidate_analytics_cache')) {
    echo "  \033[31mSETUP\033[0m — invalidate_analytics_cache() is not defined\n";
    exit(2);
}

// Decoys probe the two ways this can regress: dropping the AI brief (blanket
// wipe) and eating prefix look-alikes (unescaped LIKE underscore).
$mustDie = [
    'revenue_period', 'revenue_ar_aging', 'fleet_utilization',
    'customer_ltv', 'compliance_timeline', 'dashboard_kpis', 'dashboard_chart_ar_aging',
];
$mustLive = ['ai_fleet_brief', 'fleetXbogus', 'dashboardZbogus', 'customerXbogus'];

db_pdo()->beginTransaction();
try {
    foreach (array_merge($mustDie, $mustLive) as $i => $type) {
        db_execute(
            "INSERT INTO report_cache (report_type, parameters_hash, parameters, result_data, generated_at, expires_at)
             VALUES (?, ?, '{}', '{}', NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)",
            [$type, 'sREPORTSSTALE' . $i]
        );
    }

    invalidate_analytics_cache();

    $after = array_column(db_select("SELECT DISTINCT report_type FROM report_cache"), 'report_type');

    $survivors = array_values(array_intersect($mustDie, $after));
    if ($survivors === []) {
        $pass('all 5 analytics prefixes purged (' . count($mustDie) . ' decoy rows)');
    } else {
        $fail('analytics rows survived invalidation: ' . implode(', ', $survivors));
    }

    // The expensive one. A blanket "DELETE FROM report_cache" passes every other
    // assertion in this file and fails only here.
    if (in_array('ai_fleet_brief', $after, true)) {
        $pass('ai_fleet_brief PRESERVED — invalidation is an allowlist, not a wipe');
    } else {
        $fail('ai_fleet_brief was deleted — a paid Claude call and the morning brief are gone');
    }

    $eaten = array_values(array_diff(['fleetXbogus', 'dashboardZbogus', 'customerXbogus'], $after));
    if ($eaten === []) {
        $pass('LIKE underscore escaped — prefix look-alikes untouched');
    } else {
        $fail('unescaped LIKE `_` wildcard ate: ' . implode(', ', $eaten));
    }
} finally {
    db_rollback_if_active();
}

// Rollback must have restored the pre-test cache contents.
$leaked = db_count("SELECT COUNT(*) FROM report_cache WHERE parameters_hash LIKE 'sREPORTSSTALE%'");
if ($leaked === 0) {
    $pass('hermetic — no seeded rows leaked into the dev DB');
} else {
    $fail("{$leaked} seeded row(s) leaked — transaction did not roll back");
}

// ═════════════════════════════════════════════════════════════════════════════
// PART 2 — `excluded` census on the reports endpoints
// ═════════════════════════════════════════════════════════════════════════════

$PID         = getmypid();
$harnessFile = sys_get_temp_dir() . '/_ff_rptstale_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$qs = \$argv[2] ?? '';
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REQUEST_URI']='/'.\$endpoint.'?'.\$qs;
\$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['ff_user'] = ['id'=>1,'role_slug'=>'super_admin'];
require '{$ROOT}/' . \$endpoint;
PHP);

$invoke = static function (string $endpoint, string $qs) use ($harnessFile): ?array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($qs) . ' 2>/dev/null');
    if (!is_string($out)) return null;
    $s = strpos($out, '{"success"');
    if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : null;
};

// Pick a window that actually contains excluded invoices, so the assertion is
// non-vacuous on any seeded dev DB rather than only on today's calendar month.
$window = db_row(
    "SELECT MIN(invoice_date) AS df, MAX(invoice_date) AS dt
     FROM invoices
     WHERE deleted_at IS NULL AND status IN ('draft','void','written_off')"
);
$dateFrom = $window['df'] ?? null;
$dateTo   = $window['dt'] ?? null;

if (!$dateFrom || !$dateTo) {
    echo "  \033[33mSKIP\033[0m — no draft/void/written_off invoices in this DB; census assertions need one\n";
} else {
    $expected = db_row(
        "SELECT
            COUNT(CASE WHEN status='draft'       THEN 1 END) AS draft_count,
            COUNT(CASE WHEN status='void'        THEN 1 END) AS void_count,
            COUNT(CASE WHEN status='written_off' THEN 1 END) AS written_off_count
         FROM invoices
         WHERE deleted_at IS NULL
           AND status IN ('draft','void','written_off')
           AND invoice_date BETWEEN ? AND ?",
        [$dateFrom, $dateTo]
    );

    // Bust the 15-min cache so the endpoints recompute rather than replaying a
    // pre-fix row that predates the `excluded` key.
    invalidate_analytics_cache();

    $qs = http_build_query(['preset' => 'custom', 'date_from' => $dateFrom, 'date_to' => $dateTo]);

    foreach ([
        'api/v1/reports/revenue.php'  => 'period',
        'api/v1/reports/customer.php' => 'ltv',
    ] as $endpoint => $view) {
        $short = basename($endpoint);
        $resp  = $invoke($endpoint, $qs . '&view=' . $view);

        if (!$resp || empty($resp['success'])) {
            $fail("{$short} — endpoint did not return success");
            continue;
        }
        $ex = $resp['data']['excluded'] ?? null;
        if (!is_array($ex)) {
            $fail("{$short} — response has no `excluded` block; UI cannot explain a zero");
            continue;
        }
        $mismatch = [];
        foreach (['draft_count', 'void_count', 'written_off_count'] as $k) {
            if ((int) ($ex[$k] ?? -1) !== (int) $expected[$k]) {
                $mismatch[] = sprintf('%s api=%s sql=%s', $k, $ex[$k] ?? 'missing', $expected[$k]);
            }
        }
        if ($mismatch === []) {
            $pass(sprintf('%s — excluded census matches SQL (draft=%d, void=%d, written_off=%d)',
                $short, $expected['draft_count'], $expected['void_count'], $expected['written_off_count']));
        } else {
            $fail("{$short} — census mismatch: " . implode('; ', $mismatch));
        }

        // draft_total is CAD-canonical, so it must be >= 0 and present whenever
        // drafts exist (guards a silently-dropped SUM).
        if ((int) $expected['draft_count'] > 0) {
            $dt = $ex['draft_total'] ?? null;
            if (is_string($dt) && bccomp($dt, '0', 2) > 0) {
                $pass("{$short} — draft_total present and positive ({$dt})");
            } else {
                $fail("{$short} — draft_total missing or zero despite {$expected['draft_count']} drafts");
            }
        }
    }

    // Leave no post-test cache rows built from the smoke's custom window.
    invalidate_analytics_cache();
}

// ═════════════════════════════════════════════════════════════════════════════
// PART 3 — Analytics module census (same policy, different module)
// ═════════════════════════════════════════════════════════════════════════════
//
// Analytics does NOT cache (fast-path queries), so Part 1 does not apply to it.
// But six of its eight views apply the same draft/void/written_off exclusion,
// so they need the same explanation — and each view uses a DIFFERENT window,
// which is the part most likely to regress into a single global range.

$ANALYTICS_EXPECT = [
    // view                 => expects a census?
    'revenue_forecast'      => true,
    'utilization_matrix'    => true,
    'concentration_risk'    => true,
    'seasonal_pattern'      => true,   // all-time: window_from/to are null
    'cohort_revenue'        => true,
    'avg_lease_value'       => true,
    'fleet_optimizer'       => false,  // lease-driven — must NOT claim a census
    'lead_time'             => false,  // lease-driven
];

foreach ($ANALYTICS_EXPECT as $view => $wantsCensus) {
    $resp = $invoke('api/v1/analytics/index.php', 'view=' . $view);
    if (!$resp || empty($resp['success'])) {
        $fail("analytics/{$view} — endpoint did not return success");
        continue;
    }
    $ex = $resp['data']['excluded'] ?? null;

    if (!$wantsCensus) {
        if ($ex === null) {
            $pass("analytics/{$view} — lease-driven, correctly carries no census");
        } else {
            $fail("analytics/{$view} — lease-driven but returned an `excluded` block");
        }
        continue;
    }
    if (!is_array($ex)) {
        $fail("analytics/{$view} — money view with no `excluded` block; UI cannot explain a flat chart");
        continue;
    }
    foreach (['draft_count', 'void_count', 'written_off_count', 'billable_count', 'draft_total'] as $k) {
        if (!array_key_exists($k, $ex)) {
            $fail("analytics/{$view} — census missing `{$k}`");
            continue 2;
        }
    }

    // The census must agree with a direct count over the SAME window it reports,
    // which is what catches a view being handed the wrong range.
    $w  = 'i.deleted_at IS NULL';
    $pr = [];
    if (($ex['window_from'] ?? null) !== null && ($ex['window_to'] ?? null) !== null) {
        $w   .= ' AND i.invoice_date BETWEEN ? AND ?';
        $pr[] = $ex['window_from'];
        $pr[] = $ex['window_to'];
    }
    $sql = db_row(
        "SELECT COUNT(CASE WHEN i.status='draft' THEN 1 END) AS d,
                COUNT(CASE WHEN i.status NOT IN ('draft','void','written_off') THEN 1 END) AS b
         FROM invoices i WHERE {$w}",
        $pr
    );
    if ((int) $ex['draft_count'] === (int) $sql['d'] && (int) $ex['billable_count'] === (int) $sql['b']) {
        $win = ($ex['window_from'] ?? null) === null ? 'all-time' : $ex['window_from'] . '..' . $ex['window_to'];
        $pass(sprintf('analytics/%s — census agrees with SQL over its own window (%s; draft=%d, billable=%d)',
            $view, $win, $ex['draft_count'], $ex['billable_count']));
    } else {
        $fail(sprintf('analytics/%s — census disagrees with SQL over window %s..%s: api draft=%s/billable=%s vs sql draft=%s/billable=%s',
            $view, $ex['window_from'] ?? 'null', $ex['window_to'] ?? 'null',
            $ex['draft_count'], $ex['billable_count'], $sql['d'], $sql['b']));
    }
}

// The windows must not all collapse to one range — that is the regression that
// would make every census silently contradict its own chart.
$windows = [];
foreach (['revenue_forecast', 'concentration_risk', 'seasonal_pattern'] as $view) {
    $r = $invoke('api/v1/analytics/index.php', 'view=' . $view);
    $e = $r['data']['excluded'] ?? [];
    $windows[$view] = ($e['window_from'] ?? 'null') . '..' . ($e['window_to'] ?? 'null');
}
if (count(array_unique($windows)) > 1 && $windows['seasonal_pattern'] === 'null..null') {
    $pass('analytics — per-view windows are distinct and seasonal_pattern is all-time (not collapsed to one range)');
} else {
    $fail('analytics — windows collapsed: ' . json_encode($windows));
}

@unlink($harnessFile);

echo "\n────────────────────────────────────────────────────────────────────────\n";
printf("S-REPORTS-STALE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) {
    echo "\033[31m✗ FAILURES\033[0m\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    echo "────────────────────────────────────────────────────────────────────────\n";
    exit(1);
}
echo "\033[32m✓ ALL PASSED\033[0m\n";
echo "────────────────────────────────────────────────────────────────────────\n";
exit(0);
