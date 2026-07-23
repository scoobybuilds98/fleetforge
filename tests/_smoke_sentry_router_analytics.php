<?php
declare(strict_types=1);

/**
 * tests/_smoke_sentry_router_analytics.php
 *
 * Regression for the two open production Sentry errors of 2026-07-24.
 *
 * BUG 1 — public/index.php:163, "str_starts_with(): Argument #1 ($haystack)
 * must be of type string, bool given" (5 hits, 2026-07-16 → 07-23).
 * parse_url() returns FALSE — not NULL — on a malformed URI such as
 * `///admin.php`, so the `?? '/'` fallback did not catch it and the base-path
 * guard fatalled on a bool haystack. Vulnerability scanners request these
 * paths constantly, so every scan turned a 404 into a 500.
 *
 * BUG 2 — api/v1/analytics/index.php:422, "min(): Argument #1 ($value) must
 * contain at least one element" (4 hits, ?view=seasonal_pattern).
 * "Worst month" ignores zero-revenue months via array_filter(), but when every
 * month is zero (no billable invoices at all) that yields an empty array and
 * min() throws. The sibling $minVal line already had the `?: [0]` guard; the
 * array_search() line did not.
 *
 * SC1: GET `///admin.php`      → 404 page, no fatal.   (was: 500)
 * SC2: GET `///wpxml.php`      → 404 page, no fatal.   (was: 500)
 * SC3: GET `http://:80`        → 404 page, no fatal.   (was: 500)
 * SC4: GET a valid app route   → still routes normally (no regression).
 * SC5: GET a valid API route   → still routes normally (no regression).
 * SC6: seasonal_pattern with the real dataset → 200 + 12 chart spokes.
 * SC7: seasonal_pattern with EVERY month zero → 200, no fatal. (was: 500)
 *
 * SC7 voids all invoices inside a transaction that is always rolled back, so
 * the dataset is unchanged. Runs the real scripts as subprocesses against the
 * real db + schema.
 *
 * Run:  php tests/_smoke_sentry_router_analytics.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session Sentry sweep 2026-07-24 (router bool-haystack + seasonal min())
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$ROOT     = dirname(__DIR__);
$PID      = getmypid();
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

// ── Router harness — boots the REAL front controller for a given REQUEST_URI ──
$routerFile = sys_get_temp_dir() . '/_ff_sentry_router_' . $PID . '.php';
file_put_contents($routerFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
\$_SERVER['REQUEST_URI']    = \$argv[1] ?? '/';
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
\$_SERVER['HTTP_HOST']      = 'localhost';
require '{$ROOT}/public/index.php';
PHP);

/** Run the front controller; return combined output (stdout+stderr). */
$route = static function (string $uri) use ($routerFile): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($routerFile)
         . ' ' . escapeshellarg($uri) . ' 2>&1';
    return (string) shell_exec($cmd);
};

// ── Analytics harness — boots the REAL endpoint, optionally with all months
// zeroed inside a transaction that is rolled back on shutdown. ───────────────
$analyticsFile = sys_get_temp_dir() . '/_ff_sentry_analytics_' . $PID . '.php';
file_put_contents($analyticsFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
\$zeroOut = (\$argv[1] ?? '') === 'zero';
\$sess    = json_decode(base64_decode(\$argv[2] ?? ''), true);

require_once '{$ROOT}/config/app.php';
require_once FF_ROOT . '/includes/db.php';

if (\$zeroOut) {
    // Void every invoice so the seasonal query returns no rows at all. The
    // endpoint exits via json_success(), so the rollback is registered as a
    // shutdown function — it runs on exit() too. Nothing is ever committed.
    \$pdo = db_pdo();
    \$pdo->beginTransaction();
    register_shutdown_function(static function () use (\$pdo) {
        if (\$pdo->inTransaction()) { \$pdo->rollBack(); }
    });
    db_execute("UPDATE invoices SET status='void' WHERE deleted_at IS NULL");
}

\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
\$_SERVER['HTTP_HOST']      = 'localhost';
\$_GET['view']              = 'seasonal_pattern';
@session_start();
\$_SESSION['ff_user'] = \$sess;
require '{$ROOT}/api/v1/analytics/index.php';
PHP);

$adminSession = null;
$analytics = static function (bool $zeroOut) use ($analyticsFile, &$adminSession): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($analyticsFile)
         . ' ' . escapeshellarg($zeroOut ? 'zero' : 'normal')
         . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $s   = strpos($out, '{"success"');
    if ($s === false) { $s = strpos($out, '{"'); }
    $json = $s !== false ? json_decode(trim(substr($out, $s)), true) : null;
    return ['raw' => $out, 'json' => is_array($json) ? $json : null];
};

/** True when the output carries a PHP fatal — the exact prod failure mode. */
$isFatal = static fn(string $out): bool =>
    stripos($out, 'Fatal error') !== false || stripos($out, 'Uncaught') !== false;

try {
    // ── Setup ────────────────────────────────────────────────────────────────
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id = u.role_id
                     WHERE ur.slug = 'super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL: no super_admin\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'Sentry Smoke', 'role_slug' => 'super_admin'];

    $invoiceCount = db_count("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL");
    if ($invoiceCount === 0) { echo "SETUP FAIL: no invoices — SC6 needs a real dataset\n"; exit(2); }

    // ── BUG 1: malformed URIs must 404, not fatal ────────────────────────────
    echo "\nC1 — malformed request paths (parse_url() returns false)\n";
    foreach ([['///admin.php', 'SC1'], ['///wpxml.php', 'SC2'], ['http://:80', 'SC3']] as [$uri, $sc]) {
        $out = $route($uri);
        if ($isFatal($out)) {
            $fail("{$sc}: GET {$uri} — PHP fatal (the prod 500 is back)");
        } elseif (stripos($out, 'not found') === false && stripos($out, '404') === false) {
            $fail("{$sc}: GET {$uri} — no fatal, but did not render the 404 page");
        } else {
            $pass("{$sc}: GET {$uri} → 404 page, no fatal");
        }
    }

    // ── No regression: well-formed routes still resolve ──────────────────────
    echo "\nC2 — well-formed routes still resolve\n";
    $out = $route(FF_BASE_PATH . '/api/v1/health');
    if ($isFatal($out)) {
        $fail('SC5: API health route fatalled');
    } elseif (strpos($out, '"success":true') === false) {
        $fail('SC5: API health route did not return success — ' . substr(trim($out), 0, 120));
    } else {
        $pass('SC5: ' . FF_BASE_PATH . '/api/v1/health → 200 success');
    }

    $out = $route(FF_BASE_PATH . '/');
    if ($isFatal($out)) {
        $fail('SC4: app root fatalled');
    } else {
        $pass('SC4: ' . FF_BASE_PATH . '/ → no fatal (routed normally)');
    }

    // ── BUG 2: seasonal_pattern with a real dataset, then with an empty one ───
    echo "\nC3 — analytics seasonal_pattern\n";
    $r = $analytics(false);
    if ($isFatal($r['raw'])) {
        $fail('SC6: seasonal_pattern fatalled on the real dataset');
    } elseif (($r['json']['success'] ?? false) !== true) {
        $fail('SC6: seasonal_pattern did not succeed — ' . substr(trim($r['raw']), 0, 160));
    } else {
        $spokes = count($r['json']['data']['chart_data']['series'][0]['data'] ?? []);
        $spokes === 12
            ? $pass("SC6: real dataset → 200, 12 chart spokes, best={$r['json']['data']['kpis']['best_month']}")
            : $fail("SC6: expected 12 chart spokes, got {$spokes}");
    }

    $before = db_count("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND status <> 'void'");
    $r = $analytics(true);
    if ($isFatal($r['raw'])) {
        $fail('SC7: all-zero dataset — PHP fatal (the prod 500 is back): '
            . substr(trim($r['raw']), 0, 200));
    } elseif (($r['json']['success'] ?? false) !== true) {
        $fail('SC7: all-zero dataset did not succeed — ' . substr(trim($r['raw']), 0, 200));
    } else {
        $k = $r['json']['data']['kpis'] ?? [];
        $pass("SC7: all-zero dataset → 200, no fatal (best={$k['best_month']}, "
            . "worst={$k['worst_month']}, variance={$k['seasonal_variance']})");
    }

    // The zeroing transaction must have rolled back — the dataset is untouched.
    $after = db_count("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND status <> 'void'");
    $before === $after
        ? $pass("SC7 cleanup: rollback verified — {$after} non-void invoices, unchanged")
        : $fail("SC7 cleanup: DATA LEAKED — non-void invoices {$before} → {$after}");

} finally {
    @unlink($routerFile);
    @unlink($analyticsFile);
}

echo "\n" . str_repeat('─', 60) . "\n";
if ($failures) {
    echo "\033[31m" . count($failures) . " FAILED\033[0m, {$passes} passed\n";
    foreach ($failures as $f) { echo "  • {$f}\n"; }
    exit(1);
}
echo "\033[32mALL {$passes} PASSED\033[0m\n";
exit(0);
