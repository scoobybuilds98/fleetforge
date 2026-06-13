<?php
declare(strict_types=1);

/**
 * tests/_smoke_portal_own_status.php
 *
 * MEDIUM [10] — require_portal_auth() re-checks the customer status but not the
 * portal user's OWN status. A deactivated portal login kept access (until
 * session expiry) while the customer stayed active. Fixed: re-check
 * portal_users.status === 'active' on every guarded request.
 *
 * Drives the REAL require_portal_auth() in a subprocess (it header()+exit()s on
 * block) with a preset portal session, and checks whether it falls through.
 *
 * Asserts:
 *   1. active portal user (active customer) → falls through (PASSED_AUTH).
 *   2. inactive portal user (active customer) → blocked (no PASSED_AUTH).
 *
 * PRE-FIX  : case 2 falls through → FAIL.
 * POST-FIX : case 2 blocked.
 *
 * Run:  php tests/_smoke_portal_own_status.php   Exit 0 pass / 1 fail / 2 setup.
 *
 * @session MEDIUM-10-PORTAL-OWN-STATUS
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

// Harness: set a portal session, call require_portal_auth(), echo a sentinel
// only if it falls through (not redirected/exited).
$harnessFile = sys_get_temp_dir() . '/_ff_portal_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$puId=(int)(\$argv[1]??0); \$custId=(int)(\$argv[2]??0);
require '{$ROOT}/config/app.php';
require '{$ROOT}/app/portal/includes/auth.php';
@session_start();
\$_SESSION['ff_portal_user'] = ['id'=>\$puId,'customer_id'=>\$custId,'email'=>'p@smoke','name'=>'P'];
require_portal_auth();
echo 'PASSED_AUTH';
PHP);
$invoke = static function (int $puId, int $custId) use ($harnessFile): string {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg((string) $puId) . ' ' . escapeshellarg((string) $custId) . ' 2>/dev/null');
    return is_string($out) ? $out : '';
};

$custId = null; $puId = null;
$cleanup = static function () use (&$custId, &$puId) {
    if ($puId)   { db_execute("DELETE FROM portal_users WHERE id = ?", [$puId]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
};

try {
    $custId = db_insert('customers', ['company_name' => "Portal Co {$PID}", 'currency' => 'CAD', 'status' => 'active', 'outstanding_balance' => '0.00']);
    $puId   = db_insert('portal_users', ['customer_id' => $custId, 'name' => 'Portal P', 'email' => "portal_own_{$PID}@smoke.local", 'status' => 'active', 'is_primary' => 1]);

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [10] PORTAL OWN-STATUS — portal user #{$puId}, customer #{$custId}\n";
    echo str_repeat('─', 72) . "\n";

    // 1. active portal user → falls through
    $out = $invoke($puId, $custId);
    if (str_contains($out, 'PASSED_AUTH')) {
        $pass("1 active portal user — passes the guard (non-vacuous)");
    } else {
        $fail("1 active portal user — unexpectedly blocked: " . trim($out));
    }

    // 2. deactivate the portal user (customer stays active) → blocked
    db_execute("UPDATE portal_users SET status = 'inactive' WHERE id = ?", [$puId]);
    $out = $invoke($puId, $custId);
    if (!str_contains($out, 'PASSED_AUTH')) {
        $pass("2 deactivated portal user — blocked despite active customer");
    } else {
        $fail("2 deactivated portal user — STILL passed the guard (pre-fix: own status not re-checked)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed portal user + customer\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("PORTAL OWN-STATUS — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
