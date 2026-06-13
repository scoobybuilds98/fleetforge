<?php
declare(strict_types=1);

/**
 * tests/_smoke_portal_api_suspended_lockout.php
 *
 * WAVE 4 [05] LOW — portal JSON APIs skipped the mid-session suspension re-check.
 *
 * The portal *page* guard require_portal_auth() re-queries customers.status (and
 * portal_users.status) every request and logs out a revoked account. But the
 * three portal API bootstraps (messenger / chat / notifications) gated on
 * portal_user_id()/portal_customer_id() from the SESSION only — so a customer
 * suspended mid-session kept hitting the JSON polling endpoints until the
 * session expired. Own-data only (no cross-tenant leak), defense-in-depth.
 *
 * Fix: extracted the status re-check into a shared portal_status_revoked()
 * helper; require_portal_auth() (page → redirect) and all three API bootstraps
 * (→ 401 JSON) now call it.
 *
 * Checks (real helper + real endpoint + wiring):
 *   1. portal_status_revoked() — active customer + active portal user → null.
 *   2. suspended customer → returns the suspension message.
 *   3. deactivated portal user (customer active) → returns the deactivation msg.
 *   4. end-to-end: notifications/count.php (real endpoint via subprocess) →
 *      401 UNAUTHORIZED when the customer is suspended; 200 success when active.
 *   5. wiring: all three API bootstraps call portal_status_revoked().
 *
 * PRE-FIX  : portal_status_revoked() does not exist (checks 1-3 fatal) and the
 *            bootstraps don't reference it (check 5 fails) — endpoint still 200s
 *            while suspended (check 4 fails).
 * POST-FIX : all pass.
 *
 * Run:  php tests/_smoke_portal_api_suspended_lockout.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-4-PORTAL-API-SUSPENDED-LOCKOUT
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/app/portal/includes/auth.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

// ── Subprocess harness: run a real portal GET endpoint with a portal session ─
$harnessFile = sys_get_temp_dir() . '/_ff_portal_lockout_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$sess=json_decode(base64_decode(\$argv[2]??''), true);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['ff_portal_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$getEndpoint = static function (string $endpoint, array $sess) use ($harnessFile): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"'); if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

// Snapshot the single seeded portal user + its customer so we can restore.
$pu = db_row("SELECT id, customer_id, status FROM portal_users LIMIT 1");
if (!$pu) { echo "SETUP FAIL no portal_users row\n"; exit(2); }
$puId   = (int) $pu['id'];
$custId = (int) $pu['customer_id'];
$cust   = db_row("SELECT id, status FROM customers WHERE id = ?", [$custId]);
if (!$cust) { echo "SETUP FAIL portal user has no customer\n"; exit(2); }

$puStatus0   = $pu['status'];
$custStatus0 = $cust['status'];

$sess = ['id' => $puId, 'customer_id' => $custId, 'is_primary' => 1, 'name' => 'Lockout Smoke', 'email' => 'x@example.com'];
$_SESSION['ff_portal_user'] = $sess;   // for the in-process helper calls

$setCust = static fn(string $s) => db_execute("UPDATE customers SET status = ? WHERE id = ?", [$s, $custId]);
$setPu   = static fn(string $s) => db_execute("UPDATE portal_users SET status = ? WHERE id = ?", [$s, $puId]);

$cleanup = static function () use ($setCust, $setPu, $custStatus0, $puStatus0, $harnessFile) {
    $setCust($custStatus0); $setPu($puStatus0);
    if (file_exists($harnessFile)) @unlink($harnessFile);
};

try {
    echo str_repeat('─', 72) . "\n";
    echo "WAVE 4 [05] PORTAL API SUSPENDED LOCKOUT\n";
    echo str_repeat('─', 72) . "\n";

    // The shared helper IS the fix; guard so a pre-fix checkout fails cleanly
    // (and the finally-cleanup still runs) instead of fataling on undefined fn.
    $haveHelper = function_exists('portal_status_revoked');

    // ── CHECK 1: active + active → not revoked ──────────────────────────────
    $setCust('active'); $setPu('active');
    if ($haveHelper && portal_status_revoked() === null) {
        $pass("1 helper — active customer + active portal user → access allowed");
    } else {
        $fail("1 helper — " . ($haveHelper ? "unexpectedly revoked: " . var_export(portal_status_revoked(), true)
                                            : "portal_status_revoked() not defined (pre-fix)"));
    }

    // ── CHECK 2: suspended customer → revoked ───────────────────────────────
    $setCust('suspended');
    $r = $haveHelper ? portal_status_revoked() : null;
    if (is_string($r) && stripos($r, 'suspended') !== false) {
        $pass("2 helper — suspended customer → revoked (\"{$r}\")");
    } else {
        $fail("2 helper — suspended customer not revoked: " . var_export($r, true));
    }

    // ── CHECK 3: deactivated portal user (customer active) → revoked ────────
    $setCust('active'); $setPu('inactive');
    $r = $haveHelper ? portal_status_revoked() : null;
    if (is_string($r) && stripos($r, 'deactivated') !== false) {
        $pass("3 helper — deactivated portal user → revoked (\"{$r}\")");
    } else {
        $fail("3 helper — deactivated portal user not revoked: " . var_export($r, true));
    }

    // ── CHECK 4: end-to-end via the real notifications/count.php endpoint ───
    $setCust('suspended'); $setPu('active');
    $r = $getEndpoint('app/portal/api/notifications/count.php', $sess);
    $blocked = ($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'UNAUTHORIZED';

    $setCust('active');
    $r2 = $getEndpoint('app/portal/api/notifications/count.php', $sess);
    $allowed = ($r2['success'] ?? null) === true && array_key_exists('unread_count', $r2['data'] ?? []);

    if ($blocked && $allowed) {
        $pass("4 endpoint — count.php 401s while suspended, 200s once active again");
    } else {
        $fail("4 endpoint — suspended=" . json_encode($r) . " active=" . json_encode($r2));
    }

    // ── CHECK 5: all three API bootstraps call the shared helper ────────────
    $bootstraps = [
        'app/portal/api/messenger/_bootstrap.php',
        'app/portal/api/chat/_bootstrap.php',
        'app/portal/api/notifications/_bootstrap.php',
    ];
    $missing = [];
    foreach ($bootstraps as $b) {
        if (!str_contains((string) file_get_contents($ROOT . '/' . $b), 'portal_status_revoked(')) {
            $missing[] = $b;
        }
    }
    if (!$missing) {
        $pass("5 wiring — messenger + chat + notifications bootstraps all re-check status");
    } else {
        $fail("5 wiring — bootstraps NOT calling portal_status_revoked(): " . implode(', ', $missing));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  restored customer {$custId} (status={$custStatus0}) + portal_user {$puId} (status={$puStatus0})\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("PORTAL API SUSPENDED LOCKOUT — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
