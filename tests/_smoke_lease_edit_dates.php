<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_edit_dates.php
 *
 * S-LEASE-EDIT-DATES — the pickup date + time are now editable on the Edit Lease
 * form WHILE THE LEASE IS PENDING (operator: "lease isn't activated, should be
 * allowed to alter the date/time"). Once activated they freeze (billing + the
 * activation invoice key off them). The expected-return end_time is editable on
 * any editable status. This drives the REAL update endpoint.
 *
 *   T1  pending: set start_date + start_time → both persist.
 *   T2  pending: set end_time → persists; clear ('') → NULL.
 *   T3  ACTIVE: attempt start_date change → 422, value unchanged (frozen).
 *   T4  ACTIVE: attempt start_time change → 422, value unchanged (frozen).
 *   T5  pending: implausible year (0001) → 422, value unchanged (date-sanity).
 *   T6  pending: end_date before the NEW start_date → 422 (cross-field).
 *
 * Run:  php tests/_smoke_lease_edit_dates.php
 * Exit: 0 all pass, 1 failure, 2 setup error.
 *
 * @session S-LEASE-EDIT-DATES
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

$harnessFile = sys_get_temp_dir() . '/_ff_editdates_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$payload = base64_decode(\$argv[2] ?? '');
\$sess = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfEdInput {
    public \$context; private static string \$b=''; private int \$p=0;
    public static function set(string \$s):void{ self::\$b=\$s; }
    public function stream_open(\$a,\$b,\$c,&\$d):bool{ \$this->p=0; return true; }
    public function stream_read(\$n){ \$c=substr(self::\$b,\$this->p,\$n); \$this->p+=strlen(\$c); return \$c; }
    public function stream_eof():bool{ return \$this->p>=strlen(self::\$b); }
    public function stream_stat():array{ return []; }
    public function stream_seek(\$o,\$w):bool{ \$this->p=\$o; return true; }
    public function stream_tell():int{ return \$this->p; }
}
FfEdInput::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfEdInput');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_HOST']='localhost'; \$_SERVER['HTTP_USER_AGENT']='FF-ED/1.0';
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
$lockTok = fn (int $id): string => (string) (db_row("SELECT updated_at FROM leases WHERE id=?", [$id])['updated_at'] ?? '');
$col     = fn (int $id, string $c) => db_row("SELECT {$c} AS v FROM leases WHERE id=?", [$id])['v'] ?? null;

$custId = 0; $pendId = 0; $actId = 0;
$cleanup = function () use (&$custId, &$pendId, &$actId) {
    foreach ([$pendId, $actId] as $lid) if ($lid) db_execute("DELETE FROM leases WHERE id=?", [$lid]);
    if ($custId) { db_execute("DELETE FROM leases WHERE customer_id=?", [$custId]); db_execute("DELETE FROM customers WHERE id=?", [$custId]); }
};

try {
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "\033[31mSETUP FAIL\033[0m no super_admin.\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

    $custId = Fixtures::createCustomer(['province' => 'BC']);
    $pendId = Fixtures::createLease($custId, ['status' => 'pending', 'start_date' => '2026-04-24', 'start_time' => '11:30:00']);
    $actId  = Fixtures::createLease($custId, ['status' => 'active',  'start_date' => '2026-04-24', 'start_time' => '11:30:00']);

    echo str_repeat('─', 64) . "\nS-LEASE-EDIT-DATES — pending {$pendId} / active {$actId}\n" . str_repeat('─', 64) . "\n";

    // T1: pending — set start_date + start_time
    $r = $post(['id' => $pendId, 'updated_at' => $lockTok($pendId), 'start_date' => '2026-05-02', 'start_time' => '08:15']);
    ($r['success'] ?? null) === true
        && (string) $col($pendId, 'start_date') === '2026-05-02'
        && substr((string) $col($pendId, 'start_time'), 0, 5) === '08:15'
        ? $pass("T1 pending: start_date→2026-05-02 + start_time→08:15 persisted")
        : $fail("T1 — start=" . json_encode($col($pendId, 'start_date')) . " time=" . json_encode($col($pendId, 'start_time')) . " resp=" . json_encode($r['error'] ?? $r));

    // T2: pending — set then clear end_time
    $r = $post(['id' => $pendId, 'updated_at' => $lockTok($pendId), 'end_date' => '2026-06-01', 'end_time' => '16:45']);
    $set = ($r['success'] ?? null) === true && substr((string) $col($pendId, 'end_time'), 0, 5) === '16:45';
    $r = $post(['id' => $pendId, 'updated_at' => $lockTok($pendId), 'end_time' => '']);
    $cleared = ($r['success'] ?? null) === true && $col($pendId, 'end_time') === null;
    $set && $cleared
        ? $pass("T2 pending: end_time set→16:45 then cleared→NULL")
        : $fail("T2 — set={$set} cleared={$cleared} end_time=" . json_encode($col($pendId, 'end_time')));

    // T3: ACTIVE — start_date change rejected, value unchanged
    $before = (string) $col($actId, 'start_date');
    $r = $post(['id' => $actId, 'updated_at' => $lockTok($actId), 'start_date' => '2026-05-10']);
    ($r['error']['code'] ?? '') === 'VALIDATION_ERROR'
        && (string) $col($actId, 'start_date') === $before
        ? $pass("T3 active: start_date change → 422, unchanged ({$before})")
        : $fail("T3 — got " . json_encode($col($actId, 'start_date')) . " resp=" . json_encode($r['error'] ?? $r));

    // T4: ACTIVE — start_time change rejected
    $beforeT = (string) $col($actId, 'start_time');
    $r = $post(['id' => $actId, 'updated_at' => $lockTok($actId), 'start_time' => '06:00']);
    ($r['error']['code'] ?? '') === 'VALIDATION_ERROR'
        && (string) $col($actId, 'start_time') === $beforeT
        ? $pass("T4 active: start_time change → 422, unchanged")
        : $fail("T4 — got " . json_encode($col($actId, 'start_time')) . " resp=" . json_encode($r['error'] ?? $r));

    // T5: pending — implausible year rejected, unchanged
    $beforeD = (string) $col($pendId, 'start_date');
    $r = $post(['id' => $pendId, 'updated_at' => $lockTok($pendId), 'start_date' => '0001-05-02']);
    ($r['error']['code'] ?? '') === 'VALIDATION_ERROR'
        && (string) $col($pendId, 'start_date') === $beforeD
        ? $pass("T5 pending: year-0001 start_date → 422, unchanged ({$beforeD})")
        : $fail("T5 — got " . json_encode($col($pendId, 'start_date')) . " resp=" . json_encode($r['error'] ?? $r));

    // T6: pending — end_date before the NEW start_date rejected
    $r = $post(['id' => $pendId, 'updated_at' => $lockTok($pendId), 'start_date' => '2026-07-01', 'end_date' => '2026-06-15']);
    ($r['error']['code'] ?? '') === 'VALIDATION_ERROR'
        ? $pass("T6 pending: end_date before new start_date → 422 (cross-field)")
        : $fail("T6 — expected 422, resp=" . json_encode($r['error'] ?? $r));

} finally {
    $cleanup();
    @unlink($harnessFile);
}

echo str_repeat('─', 64) . "\n";
if ($failures) { echo "\033[31mRESULT: " . count($failures) . " FAIL / " . ($passes + count($failures)) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$passes} PASS — pickup date/time editable on pending, frozen once active\033[0m\n";
exit(0);
