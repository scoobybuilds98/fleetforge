<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_hourly_edit.php
 *
 * S-LEASE-EDIT-HOURLY — the hourly add-on rate is now operator-editable on the
 * Edit Lease form (the auto-billed daily/weekly/monthly tiers stay frozen), so
 * hours billing can be added/adjusted on an existing lease. This verifies the
 * supporting API change: api/v1/leases/update.php accepts, persists, and clears
 * `hourly_rate` (it previously ignored it).
 *
 *   T1  set hourly_rate on a lease that had none → persists.
 *   T2  clear it ('') → NULL (hours billing disabled).
 *   T3  negative → 422, value unchanged.
 *
 * Drives the REAL update endpoint (subprocess + auth + CSRF). Committed fixture
 * cleaned up in finally.
 *
 * Run:  php tests/_smoke_lease_hourly_edit.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-LEASE-EDIT-HOURLY
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Tests\Fixtures;

$ROOT = dirname(__DIR__);
$PID  = getmypid();

$passes = 0; $failures = [];
$pass = function (string $m) use (&$passes) { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = function (string $m) use (&$failures) { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$harnessFile = sys_get_temp_dir() . '/_ff_hourly_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$payload = base64_decode(\$argv[2] ?? '');
\$sess = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfHrInput {
    public \$context; private static string \$b=''; private int \$p=0;
    public static function set(string \$s):void{ self::\$b=\$s; }
    public function stream_open(\$a,\$b,\$c,&\$d):bool{ \$this->p=0; return true; }
    public function stream_read(\$n){ \$c=substr(self::\$b,\$this->p,\$n); \$this->p+=strlen(\$c); return \$c; }
    public function stream_eof():bool{ return \$this->p>=strlen(self::\$b); }
    public function stream_stat():array{ return []; }
    public function stream_seek(\$o,\$w):bool{ \$this->p=\$o; return true; }
    public function stream_tell():int{ return \$this->p; }
}
FfHrInput::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfHrInput');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_HOST']='localhost'; \$_SERVER['HTTP_USER_AGENT']='FF-HR/1.0';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$adminSession = null;
$post = function (array $payload) use ($harnessFile, &$adminSession): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg('api/v1/leases/update.php')
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    $start = is_string($out) ? strpos($out, '{"') : false;
    if ($start !== false) $out = substr($out, $start);
    $j = json_decode(trim((string) $out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};
$lockToken = fn (int $id): string => (string) (db_row("SELECT updated_at FROM leases WHERE id=?", [$id])['updated_at'] ?? '');
$hourlyOf  = fn (int $id) => db_row("SELECT hourly_rate FROM leases WHERE id=?", [$id])['hourly_rate'] ?? null;

$custId = $leaseId = 0;
$cleanup = function () use (&$custId, &$leaseId) {
    if ($leaseId) db_execute("DELETE FROM leases WHERE id=?", [$leaseId]);
    if ($custId)  { db_execute("DELETE FROM leases WHERE customer_id=?", [$custId]); db_execute("DELETE FROM customers WHERE id=?", [$custId]); }
};

try {
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "\033[31mSETUP FAIL\033[0m no super_admin.\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

    $custId  = Fixtures::createCustomer(['province' => 'BC']);
    $leaseId = Fixtures::createLease($custId, ['status' => 'active', 'daily_rate' => '100.00', 'weekly_rate' => '600.00', 'monthly_rate' => '2000.00']);
    db_execute("UPDATE leases SET hourly_rate = NULL WHERE id=?", [$leaseId]);

    echo str_repeat('─', 64) . "\nS-LEASE-EDIT-HOURLY — lease {$leaseId}\n" . str_repeat('─', 64) . "\n";

    // T1: set hourly on a lease that had none
    $r = $post(['id' => $leaseId, 'updated_at' => $lockToken($leaseId), 'hourly_rate' => '15.5000']);
    ($r['success'] ?? null) === true && bccomp((string) $hourlyOf($leaseId), '15.5000', 4) === 0
        ? $pass("T1 set hourly_rate=15.50 on a lease with none → persisted")
        : $fail("T1 set hourly — got " . json_encode($hourlyOf($leaseId)) . " resp=" . json_encode($r['error'] ?? $r));

    // T2: clear it
    $r = $post(['id' => $leaseId, 'updated_at' => $lockToken($leaseId), 'hourly_rate' => '']);
    ($r['success'] ?? null) === true && $hourlyOf($leaseId) === null
        ? $pass("T2 clear hourly_rate ('') → NULL (hours billing disabled)")
        : $fail("T2 clear hourly — got " . json_encode($hourlyOf($leaseId)) . " resp=" . json_encode($r['error'] ?? $r));

    // T3: negative rejected
    db_execute("UPDATE leases SET hourly_rate = '9.0000' WHERE id=?", [$leaseId]);
    $r = $post(['id' => $leaseId, 'updated_at' => $lockToken($leaseId), 'hourly_rate' => '-2']);
    ($r['error']['code'] ?? '') === 'VALIDATION_ERROR' && bccomp((string) $hourlyOf($leaseId), '9.0000', 4) === 0
        ? $pass("T3 negative hourly_rate → 422, value unchanged (9.00)")
        : $fail("T3 negative — expected 422 + unchanged, got " . json_encode($hourlyOf($leaseId)) . " resp=" . json_encode($r['error'] ?? $r));

} finally {
    $cleanup();
    @unlink($harnessFile);
}

echo str_repeat('─', 64) . "\n";
if ($failures) { echo "\033[31mRESULT: " . count($failures) . " FAIL / " . ($passes + count($failures)) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$passes} PASS — hourly_rate is editable + persisted on lease update\033[0m\n";
exit(0);
