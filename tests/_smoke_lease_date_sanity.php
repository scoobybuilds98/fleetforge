<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_date_sanity.php
 *
 * S-LEASE-DATE-SANITY — guards against the MTTS286 prod incident (2026-06-26): a
 * backfill typo `start_date = 0001-03-02` passed clean_date() (valid calendar
 * date), then close computed a ~739k-day span that overflowed
 * invoices.billing_period_days (smallint unsigned) → SQLSTATE 22003/1264 → a
 * cryptic "unexpected error".
 *
 *   T1  create with start_date 0001-03-02  → 422, start_date "looks invalid".
 *   T2  create with a VALID start_date     → the year-guard does NOT false-fire.
 *   T3  close (real endpoint) an active lease whose start_date is 0001-03-02 →
 *       422 INVALID_LEASE_DATES (a clean error, NOT INTERNAL_ERROR / 500).
 *
 * Drives the REAL create + close endpoints (subprocess + auth + CSRF). Committed
 * fixtures cleaned up in finally.
 *
 * Run:  php tests/_smoke_lease_date_sanity.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-LEASE-DATE-SANITY
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Tests\Fixtures;

$ROOT = dirname(__DIR__);
$PID  = getmypid();
$TOKEN = '__date_sanity_' . $PID . '__';

$passes = 0; $failures = [];
$pass = function (string $m) use (&$passes) { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = function (string $m) use (&$failures) { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

// POST harness (real endpoint subprocess with a super_admin session + CSRF).
$harnessFile = sys_get_temp_dir() . '/_ff_datesanity_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfDSInput {
    public \$context; private static string \$b=''; private int \$p=0;
    public static function set(string \$s):void{ self::\$b=\$s; }
    public function stream_open(\$a,\$b,\$c,&\$d):bool{ \$this->p=0; return true; }
    public function stream_read(\$n){ \$c=substr(self::\$b,\$this->p,\$n); \$this->p+=strlen(\$c); return \$c; }
    public function stream_eof():bool{ return \$this->p>=strlen(self::\$b); }
    public function stream_stat():array{ return []; }
    public function stream_seek(\$o,\$w):bool{ \$this->p=\$o; return true; }
    public function stream_tell():int{ return \$this->p; }
}
FfDSInput::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfDSInput');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_HOST']='localhost'; \$_SERVER['HTTP_USER_AGENT']='FF-DS/1.0';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$adminSession = null;
$post = function (string $endpoint, array $payload) use ($harnessFile, &$adminSession): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    $start = is_string($out) ? strpos($out, '{"') : false;
    if ($start !== false) $out = substr($out, $start);
    $j = json_decode(trim((string) $out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$custId = $leaseId = 0;
$cleanup = function () use (&$custId, &$leaseId) {
    if ($leaseId) {
        foreach (array_column(db_select("SELECT id FROM invoices WHERE lease_id=?", [$leaseId]), 'id') as $iv) {
            db_execute("DELETE FROM invoice_line_items WHERE invoice_id=?", [(int)$iv]);
            db_execute("DELETE FROM invoices WHERE id=?", [(int)$iv]);
        }
        db_execute("DELETE FROM leases WHERE id=?", [$leaseId]);
    }
    if ($custId) { db_execute("DELETE FROM leases WHERE customer_id=?", [$custId]); db_execute("DELETE FROM customers WHERE id=?", [$custId]); }
};

try {
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "\033[31mSETUP FAIL\033[0m no super_admin.\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'role_slug' => 'super_admin'];

    echo str_repeat('─', 72) . "\nS-LEASE-DATE-SANITY — token {$TOKEN}\n" . str_repeat('─', 72) . "\n";

    // ── T1: create with year-0001 start_date → rejected ──
    $r = $post('api/v1/leases/create.php', ['start_date' => '0001-03-02', 'start_time' => '12:00']);
    $msg = $r['error']['fields']['start_date'] ?? '';
    (($r['error']['code'] ?? '') === 'VALIDATION_ERROR') && stripos($msg, 'looks invalid') !== false
        ? $pass("T1 create start_date=0001-03-02 → rejected ({$msg})")
        : $fail("T1 create year-0001 — expected 'looks invalid', got " . json_encode($r['error'] ?? $r));

    // ── T2: create with a VALID start_date → the year-guard does NOT fire ──
    $r = $post('api/v1/leases/create.php', ['start_date' => '2026-03-02', 'start_time' => '12:00']);
    $msg = $r['error']['fields']['start_date'] ?? '';
    stripos($msg, 'looks invalid') === false
        ? $pass("T2 create start_date=2026-03-02 → year-guard does not false-fire" . ($msg ? " (start_date msg: '{$msg}')" : ''))
        : $fail("T2 valid date tripped the year-guard: {$msg}");

    // ── T3: close an active lease with a corrupt start_date → graceful 422 ──
    $custId  = Fixtures::createCustomer(['province' => 'BC']);
    $leaseId = Fixtures::createLease($custId, ['status' => 'active', 'engine_version' => 'holistic', 'billing_cycle' => 'monthly', 'daily_rate' => '100.00', 'weekly_rate' => '600.00', 'monthly_rate' => '2000.00']);
    db_execute("UPDATE leases SET start_date='0001-03-02' WHERE id=?", [$leaseId]);
    $r = $post('api/v1/leases/close.php', ['id' => $leaseId, 'actual_return_date' => '2026-03-15']);
    ($r['error']['code'] ?? '') === 'INVALID_LEASE_DATES'
        ? $pass("T3 close year-0001 lease → INVALID_LEASE_DATES (clean error, not a 1264/500)")
        : $fail("T3 close year-0001 — expected INVALID_LEASE_DATES, got " . json_encode($r['error'] ?? $r));

} finally {
    $cleanup();
    @unlink($harnessFile);
}

echo str_repeat('─', 72) . "\n";
if ($failures) { echo "\033[31mRESULT: " . count($failures) . " FAIL / " . ($passes + count($failures)) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$passes} PASS — invalid lease start dates are rejected at create + close\033[0m\n";
exit(0);
