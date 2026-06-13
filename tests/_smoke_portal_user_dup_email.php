<?php
declare(strict_types=1);

/**
 * tests/_smoke_portal_user_dup_email.php
 *
 * MEDIUM [05/10] — portal_users create dup-email handling.
 *
 * portal_users is hard-deleted (no deleted_at), UNIQUE on email. create.php
 * 409'd on ANY existing email without distinguishing a DEACTIVATED account, and
 * the INSERT had no 23000 catch (a TOCTOU race → raw 1062 → 500). Fixed:
 *   - inactive existing row → 409 with a "reactivate" hint + portal_user_id;
 *   - INSERT wrapped in a 23000 → 409 belt.
 *
 * Asserts (real endpoint, super_admin):
 *   1. duplicate of an INACTIVE portal user → 409 with the reactivation hint.
 *   2. brand-new email → 201 (non-vacuous).
 *
 * PRE-FIX  : case 1 returns the generic 409 (no reactivation hint) → FAIL.
 * POST-FIX : case 1 carries the inactive hint.
 *
 * Run:  php tests/_smoke_portal_user_dup_email.php   Exit 0 pass / 1 fail / 2 setup.
 *
 * @session MEDIUM-05-PORTAL-USER-DUP
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_pu_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
class FfPuIn {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfPuIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfPuIn');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$sess = null;
$post = static function (string $endpoint, array $payload) use ($harnessFile, &$sess): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"'); if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$custId = null; $puIds = []; $emails = [];
$cleanup = static function () use (&$custId, &$puIds, &$emails) {
    foreach ($emails as $e) { db_execute("DELETE FROM portal_users WHERE email = ?", [$e]); }
    foreach ($puIds as $p) { db_execute("DELETE FROM portal_users WHERE id = ?", [$p]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'PU Smoke', 'role_slug' => 'super_admin'];

    $custId = db_insert('customers', ['company_name' => "PU Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '0.00']);
    $inactiveEmail = "pu_inactive_{$PID}@smoke.local";
    $freshEmail    = "pu_fresh_{$PID}@smoke.local";
    $emails = [$inactiveEmail, $freshEmail];

    // Pre-seed a DEACTIVATED portal user.
    $puIds[] = db_insert('portal_users', [
        'customer_id' => $custId, 'name' => 'Inactive PU', 'email' => $inactiveEmail, 'status' => 'inactive', 'is_primary' => 0,
    ]);

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [05] PORTAL USER DUP EMAIL — customer #{$custId}\n";
    echo str_repeat('─', 72) . "\n";

    // 1. dup of inactive → 409 with reactivation hint
    $r = $post('api/v1/portal_users/create.php', ['customer_id' => $custId, 'name' => 'X', 'email' => $inactiveEmail]);
    $msg = ($r['error']['message'] ?? '') . ' ' . json_encode($r['error']['details'] ?? $r['error'] ?? []);
    if (($r['success'] ?? null) === false && stripos($msg, 'reactivat') !== false) {
        $pass("1 inactive dup — 409 with reactivation hint");
    } else {
        $fail("1 inactive dup — no reactivation hint: " . json_encode($r['error'] ?? $r) . " (pre-fix: generic 409)");
    }

    // 2. fresh email → 201
    $r = $post('api/v1/portal_users/create.php', ['customer_id' => $custId, 'name' => 'Fresh PU', 'email' => $freshEmail]);
    if (($r['success'] ?? null) === true) {
        $pass("2 fresh email — created (non-vacuous)");
    } else {
        $fail("2 fresh email — failed: " . json_encode($r['error'] ?? $r));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed portal users + customer\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("PORTAL USER DUP EMAIL — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
