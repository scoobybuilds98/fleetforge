<?php declare(strict_types=1);

/**
 * FleetForge — Smoke test harness for PAYOFF-1 design polish.
 *
 * Renders each modified page through PHP CLI with a fake super_admin
 * session, captures the HTML, writes it to /tmp for grep verification,
 * and prints a pass/fail summary for the markers we care about.
 *
 * Each page runs in a SEPARATE child PHP process to avoid duplicate
 * function/declaration collisions across page includes.
 *
 * Usage:
 *   php tests/_smoke_payoff_design.php          # parent — runs all 4 cases
 *   php tests/_smoke_payoff_design.php <case>   # child  — renders one page
 */

// Each test case is: [label, relPath, expectedMarkers, $_GET overrides]
$cases = [
    'fixed_assets_index' => [
        'path'    => 'app/admin/accounting/fixed-assets/index.php',
        'get'     => [],
        'markers' => [
            'Fixed Assets Register',
            'Track depreciable assets',
            'class="section-header"',
            '<h3 class="section-title">Core Details</h3>',
            '<h3 class="section-title">Depreciation</h3>',
            '<h3 class="section-title">GL Accounts</h3>',
            '<h3 class="section-title">Identification</h3>',
            '<h3 class="section-title">Acquisition Details</h3>',
            '<h3 class="section-title">Financing</h3>',
            '<h3 class="section-title">Monthly Fixed Costs</h3>',
            'New Fixed Asset',
            'Edit Fixed Asset',
        ],
    ],
    'payoff_report' => [
        'path'    => 'app/admin/accounting/fixed-assets/payoff-report.php',
        'get'     => [],
        'markers' => [
            'Fleet Payoff Report',
            'See how every linked fixed asset is recovering its investment',
            'Back to Assets',
            "\$dispatch('payoff-export-csv')",
            '@payoff-export-csv.window="exportCsv()"',
            'stat-card stat-card--blue',
            'stat-icon--blue',
            'class="card" style="margin-bottom:20px;padding:14px 16px;"',
            'class="data-table"',
            "toggleSort('asset_number')",
        ],
    ],
    'equipment_show_linked' => [
        'path'    => 'app/admin/equipment/show.php',
        'get'     => ['id' => 6],
        // The PHP injects linkedAssetId via PHP_EOL-aligned formatting,
        // so we match it via regex (any amount of whitespace, then 17).
        'markers' => [
            'class="tab-bar"',
            'Payoff Analysis',
            'FF_UnitDetail',
        ],
        'regex' => [
            '/linkedAssetId:\s*17\b/',
        ],
    ],
    'equipment_show_unlinked' => [
        'path'    => 'app/admin/equipment/show.php',
        'get'     => ['id' => 7],
        'markers' => [
            'class="tab-bar"',
            'Payoff Analysis',
            'FF_UnitDetail',
        ],
        'regex' => [
            '/linkedAssetId:\s*0\b/',
        ],
    ],
];

// ── CHILD MODE ──────────────────────────────────────────────
// Invoked as: php _smoke_payoff_design.php <case-key>
// Renders one page and writes the captured HTML to /tmp.
if (isset($argv[1])) {
    $key = $argv[1];
    if (!isset($cases[$key])) {
        fwrite(STDERR, "unknown case: $key\n");
        exit(2);
    }
    $case = $cases[$key];

    // Pretend to be a real HTTPS web request.
    $_SERVER['HTTPS']           = 'on';
    $_SERVER['HTTP_HOST']       = 'fleetforge.test';
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REQUEST_URI']     = '/';
    $_SERVER['SCRIPT_NAME']     = '/index.php';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'ff-smoke-cli';
    $_GET                       = $case['get'];

    // Bootstrap the app and stub a super_admin session.
    require_once __DIR__ . '/../config/app.php';
    require_once FF_ROOT . '/includes/auth.php';
    $_SESSION['ff_user'] = [
        'id'          => 1,
        'name'        => 'Smoke Admin',
        'email'       => 'smoke@fleetforge.test',
        'role_id'     => 1,
        'role_slug'   => 'super_admin',
        'permissions' => [],
        'theme'       => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();

    $absPath = FF_ROOT . '/' . ltrim($case['path'], '/');
    if (!is_file($absPath)) {
        fwrite(STDERR, "missing file: $absPath\n");
        exit(3);
    }

    ob_start();
    try {
        require $absPath;
    } catch (\Throwable $e) {
        ob_end_clean();
        fwrite(STDERR, "exception: " . $e->getMessage() . "\n");
        exit(4);
    }
    $html = ob_get_clean();

    $outFile = '/tmp/ff_smoke_' . $key . '.html';
    file_put_contents($outFile, $html);
    echo $outFile . "\n" . strlen($html) . "\n";
    exit(0);
}

// ── PARENT MODE ─────────────────────────────────────────────
// Re-invoke this script for each case in a child process.
$self = __FILE__;
$php  = PHP_BINARY;

echo "\n=== PAYOFF-1 design smoke test ===\n";
$totalPass = 0;
foreach ($cases as $key => $case) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' ' . escapeshellarg($key) . ' 2>&1';
    $out = [];
    $exitCode = 0;
    exec($cmd, $out, $exitCode);

    if ($exitCode !== 0) {
        echo sprintf("[FAIL] %-26s child exit=%d\n", $key, $exitCode);
        foreach ($out as $line) echo "        $line\n";
        continue;
    }

    $outFile = $out[0] ?? '';
    $bytes   = (int) ($out[1] ?? 0);
    $html    = is_file($outFile) ? (string) file_get_contents($outFile) : '';

    $missing = [];
    foreach ($case['markers'] as $marker) {
        if (strpos($html, $marker) === false) {
            $missing[] = $marker;
        }
    }
    foreach (($case['regex'] ?? []) as $rx) {
        if (!preg_match($rx, $html)) {
            $missing[] = 'regex ' . $rx;
        }
    }

    $tag = empty($missing) ? 'PASS' : 'FAIL';
    echo sprintf("[%s] %-26s %7d bytes  %s\n", $tag, $key, $bytes, $outFile);
    if (empty($missing)) {
        $totalPass++;
    } else {
        echo "       missing markers:\n";
        foreach ($missing as $m) {
            echo "         - " . $m . "\n";
        }
    }
}

echo sprintf("\n%d / %d pages passed\n", $totalPass, count($cases));
exit($totalPass === count($cases) ? 0 : 1);
