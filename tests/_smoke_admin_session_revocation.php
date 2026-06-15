<?php
declare(strict_types=1);

/**
 * tests/_smoke_admin_session_revocation.php
 *
 * WAVE 5 [Codex] — admin session never re-validates live status / deleted_at.
 *
 * require_auth() / require_auth_api() trust the ff_user session snapshot. The
 * per-request freshness hook (_ff_check_permission_freshness) only refreshes the
 * permission-OVERRIDE map — it never re-checks users.status / deleted_at, and it
 * early-returns for super_admin. So an admin who is suspended, locked, or
 * soft-deleted mid-session keeps full scope until the session times out. Login
 * requires status='active' AND deleted_at IS NULL — the live session must hold
 * to the same bar. Admin-side analogue of the portal [05] fix.
 *
 * Drives the REAL require_auth_api() guard via a subprocess harness with a
 * seeded ff_user session, toggling the user's DB row in try/finally:
 *   1. active + not deleted        → guard proceeds (reached:true).
 *   2. status='suspended'          → guard rejects (401 UNAUTHORIZED).
 *   3. status='locked'             → guard rejects (401).
 *   4. deleted_at set (soft-delete)→ guard rejects (401).
 *
 * PRE-FIX  : 2,3,4 reach the endpoint (reached:true) → FAIL (this is the repro).
 * POST-FIX : 2,3,4 are 401; 1 still proceeds.
 *
 * Run:  php tests/_smoke_admin_session_revocation.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-5-ADMIN-SESSION-REVOCATION
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

// Harness: seed ff_user, call require_auth_api(), echo a marker if it proceeds.
$harnessFile = sys_get_temp_dir() . '/_ff_admrev_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$sess = json_decode(base64_decode(\$argv[1] ?? ''), true);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/config/app.php';
require_once '{$ROOT}/includes/auth.php';
require_auth_api();            // exits with 401 JSON if the session is revoked
echo json_encode(['reached' => true]);
PHP);
$callGuard = static function (array $sess) use ($harnessFile): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"'); if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => substr((string) $out, 0, 160)];
};

$uid = null; $snapStatus = null; $snapDeleted = null;

$cleanup = static function () use (&$uid, &$snapStatus, &$snapDeleted, $harnessFile) {
    if ($uid !== null) {
        db_execute("UPDATE users SET status = ?, deleted_at = ? WHERE id = ?", [$snapStatus, $snapDeleted, $uid]);
    }
    if (file_exists($harnessFile)) @unlink($harnessFile);
};

try {
    $u = db_row("SELECT u.id, u.status, u.deleted_at, u.role_id, ur.slug
                   FROM users u JOIN user_roles ur ON ur.id = u.role_id
                  WHERE ur.slug <> 'super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$u) { echo "SETUP FAIL no non-super user\n"; exit(2); }
    $uid = (int) $u['id'];
    $snapStatus  = $u['status'];
    $snapDeleted = $u['deleted_at'];

    // A realistic session snapshot for this user (as login would store it).
    $sess = ['id' => $uid, 'name' => 'Rev Smoke', 'role_slug' => $u['slug'], 'role_id' => (int) $u['role_id'],
             'permissions' => [], 'permission_overrides' => [], 'role_permission_overrides' => []];

    $reached = static fn(array $r): bool => ($r['reached'] ?? null) === true;

    echo str_repeat('─', 72) . "\n";
    echo "WAVE 5 [Codex] ADMIN SESSION REVOCATION — live status re-check\n";
    echo str_repeat('─', 72) . "\n";

    // 1. active → proceeds
    db_execute("UPDATE users SET status='active', deleted_at=NULL WHERE id=?", [$uid]);
    if ($reached($callGuard($sess))) {
        $pass("1 active — guard proceeds for an active, non-deleted user");
    } else {
        $fail("1 active — guard blocked a valid active user (over-strict)");
    }

    // 2. suspended → 401
    db_execute("UPDATE users SET status='suspended' WHERE id=?", [$uid]);
    if (!$reached($callGuard($sess))) {
        $pass("2 suspended — guard rejects (was: session trusted → access kept)");
    } else {
        $fail("2 suspended — guard STILL proceeds (pre-fix leak: suspended admin keeps scope)");
    }

    // 3. locked → 401
    db_execute("UPDATE users SET status='locked' WHERE id=?", [$uid]);
    if (!$reached($callGuard($sess))) {
        $pass("3 locked — guard rejects");
    } else {
        $fail("3 locked — guard STILL proceeds (pre-fix leak)");
    }

    // 4. soft-deleted → 401
    db_execute("UPDATE users SET status='active', deleted_at=NOW() WHERE id=?", [$uid]);
    if (!$reached($callGuard($sess))) {
        $pass("4 soft-deleted — guard rejects");
    } else {
        $fail("4 soft-deleted — guard STILL proceeds (pre-fix leak: deleted admin keeps scope)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  restored user {$uid} (status={$snapStatus})\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("ADMIN SESSION REVOCATION — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
