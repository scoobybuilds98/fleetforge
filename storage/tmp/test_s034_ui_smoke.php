<?php
declare(strict_types=1);

// ============================================================
// S034 — UI SMOKE TEST
// Loads each S034 admin page in a forked child process with a
// pre-populated $_SESSION so require_auth() passes. We capture
// the rendered HTML and verify:
//   1. No PHP fatal errors
//   2. Expected page heading is in output
//   3. Key Alpine state variables/methods are present
// ============================================================

// We must do this in subprocess because each page calls require_auth()
// and uses session_start(), and PHP can only have one session per process.

$pages = [
    'fixed-assets' => [
        'file' => '/Users/avi/Documents/fleetforge/app/admin/accounting/fixed-assets/index.php',
        'must_contain' => ['Fixed Assets Register', 'Total Assets', 'New Asset'],
    ],
    'depreciation' => [
        'file' => '/Users/avi/Documents/fleetforge/app/admin/accounting/depreciation/index.php',
        'must_contain' => ['Depreciation', 'reverseRun', 'Reverse'],
    ],
    'capex' => [
        'file' => '/Users/avi/Documents/fleetforge/app/admin/accounting/capex/index.php',
        'must_contain' => ['CapEx', 'flaggedWorkOrders', 'Capitalize', 'Expense'],
    ],
];

echo str_repeat('=', 70) . PHP_EOL;
echo "S034 — UI SMOKE TEST" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

foreach ($pages as $name => $cfg) {
    echo "\n## $name → {$cfg['file']}\n";
    if (!is_file($cfg['file'])) {
        echo "  ❌ Page file missing\n";
        continue;
    }

    // Use a child PHP process so each page gets its own session
    $cmd = sprintf(
        '/Users/avi/Library/Application\\ Support/Herd/bin/php -d display_errors=1 -r %s 2>&1',
        escapeshellarg(<<<PHP
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            // Bypass auth: pre-populate the session before any code runs
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['REQUEST_URI']    = '/test';
            \$_SERVER['SCRIPT_NAME']    = '/test';
            \$_SERVER['HTTP_HOST']      = 'fleetforge.test';

            // We need to define FF_LOADED guard variables BEFORE auth.php loads.
            // But since this is a normal include, the simplest path is to start
            // the session ourselves with the right data, then let auth.php see it.
            session_id('testsmoke' . bin2hex(random_bytes(13)));
            \$_SESSION['ff_user'] = [
                'id' => 1,
                'name' => 'Avi',
                'email' => 'admin@fleetforge.test',
                'role_id' => 1,
                'role_slug' => 'super_admin',
                'permissions' => [],
                'theme' => 'dark',
            ];
            \$_SESSION['ff_last_activity'] = time();

            ob_start();
            try {
                require '{$cfg['file']}';
            } catch (Throwable \$e) {
                echo 'EXCEPTION: ' . \$e->getMessage() . ' at ' . \$e->getFile() . ':' . \$e->getLine();
            }
            \$html = ob_get_clean();
            echo \$html;
        PHP
        )
    );

    $output = shell_exec($cmd) ?? '';
    $size = strlen($output);
    echo "  Rendered: $size bytes\n";

    $hasErr = preg_match('/Fatal error|Parse error|Uncaught/', $output);
    echo $hasErr ? "  ❌ FATAL ERROR detected\n" : "  ✅ No fatal errors\n";
    if ($hasErr) {
        $lines = explode("\n", $output);
        foreach ($lines as $l) {
            if (preg_match('/error|exception/i', $l)) echo "    " . trim($l) . "\n";
        }
    }

    foreach ($cfg['must_contain'] as $needle) {
        $found = str_contains($output, $needle);
        echo "  " . ($found ? '✅' : '❌') . " contains '$needle'\n";
    }
}

echo "\n" . str_repeat('=', 70) . PHP_EOL;
echo "UI SMOKE TEST COMPLETE" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
