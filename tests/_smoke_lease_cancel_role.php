<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_cancel_role.php
 *
 * H6 (Wave 3) — role gate read the wrong session key.
 *
 * leases/cancel.php and customers/reenable_email.php gated on $user['role'],
 * but auth_login() stores the role under 'role_slug' — so the lookup yielded ''
 * for everyone and the gate 403'd EVERY role (including super_admin) on an
 * active-lease cancel / email re-enable. Fixed to read 'role_slug'.
 *
 * Asserts (via the real endpoints):
 *   1. cancel.php — super_admin CAN cancel an active lease (status→cancelled).
 *   2. cancel.php — a dispatcher (has leases.edit but is not manager) is still
 *      403 on an active-lease cancel (the gate still discriminates).
 *   3. reenable_email.php — super_admin is NOT 403 (reaches business logic).
 *
 * PRE-FIX  : 1 and 3 return 403 → FAIL.
 * POST-FIX : 1 succeeds, 2 still 403, 3 succeeds.
 *
 * Run:  php tests/_smoke_lease_cancel_role.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session H6-ROLE-SLUG
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_h6_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??'');
\$sess=json_decode(base64_decode(\$argv[3]??''), true);
class FfH6In {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfH6In::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfH6In');
\$_SERVER['REQUEST_METHOD']='POST'; \$_SERVER['CONTENT_TYPE']='application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN']='smoketoken'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['csrf_token']='smoketoken'; \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$post = static function (string $endpoint, array $payload, array $sess) use ($harnessFile): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"'); if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$leaseIds = [];
$custId   = null;
$unitId   = null;
$unitStatus0 = null;

$cleanup = static function () use (&$leaseIds, &$custId, &$unitId, &$unitStatus0) {
    foreach ($leaseIds as $lid) { db_execute("DELETE FROM leases WHERE id = ?", [$lid]); }
    if ($custId) { db_execute("DELETE FROM customers WHERE id = ?", [$custId]); }
    if ($unitId && $unitStatus0 !== null) {
        db_execute("UPDATE equipment_units SET status = ? WHERE id = ?", [$unitStatus0, $unitId]);
    }
};

$mkLease = static function (int $cust, int $unit, string $tag) use ($PID): int {
    return db_insert('leases', [
        'contract_number'        => "H6-{$PID}-{$tag}",
        'customer_id'            => $cust,
        'equipment_unit_id'      => $unit,
        'unit_number_snapshot'   => 'H6',
        'company_name_snapshot'  => 'H6 Co',
        'customer_name_snapshot' => 'H6',
        'status'                 => 'active',
        'start_date'             => date('Y-m-d'),
        'daily_rate'             => '100.00',
        'weekly_rate'            => '600.00',
        'monthly_rate'           => '2000.00',
        'currency'               => 'CAD',
        'billing_cycle'          => 'monthly',
        'discount_type'          => 'none',
        'discount_value'         => '0.0000',
        'mileage_rate'           => '0.0000',
        'mileage_unit'           => 'km',
        'total_invoiced'         => '0.00',
        'total_paid'             => '0.00',
        'outstanding_balance'    => '0.00',
    ]);
};

try {
    $admin = db_row("SELECT u.id,u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $superSess = ['id' => (int) $admin['id'], 'name' => 'H6 Admin', 'role_slug' => 'super_admin'];
    // Synthetic dispatcher: has leases.edit (passes require_permission) but is not
    // manager. Needs a REAL active user id — require_auth_api() now re-checks live
    // users.status/deleted_at, so an id=0 session is revoked (401) before the gate.
    // can() reads the session 'permissions', not the DB role, so dispatcher perms
    // are attached to a valid user id.
    $realUser = db_row("SELECT id FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$realUser) { echo "SETUP FAIL no active user\n"; exit(2); }
    $dispSess  = ['id' => (int) $realUser['id'], 'name' => 'H6 Disp', 'role_slug' => 'dispatcher',
                  'permissions' => ['leases' => ['edit' => 1, 'view' => 1], 'customers' => ['edit' => 1]],
                  'permission_overrides' => [], 'role_permission_overrides' => []];

    $custId = db_insert('customers', ['company_name' => "H6 Co {$PID}", 'currency' => 'CAD', 'outstanding_balance' => '0.00']);
    $unit   = db_row("SELECT id, status FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    if (!$unit) { echo "SETUP FAIL no equipment_unit\n"; exit(2); }
    $unitId = (int) $unit['id']; $unitStatus0 = $unit['status'];

    $leaseSuper = $mkLease($custId, $unitId, 'super'); $leaseIds[] = $leaseSuper;
    $leaseDisp  = $mkLease($custId, $unitId, 'disp');  $leaseIds[] = $leaseDisp;

    echo str_repeat('─', 72) . "\n";
    echo "H6 ROLE GATE — cancel.php + reenable_email.php (role_slug)\n";
    echo str_repeat('─', 72) . "\n";

    // 1. super_admin cancels an active lease
    $r = $post('api/v1/leases/cancel.php', ['id' => $leaseSuper, 'cancel_reason' => 'h6 smoke'], $superSess);
    $st = db_row("SELECT status FROM leases WHERE id = ?", [$leaseSuper])['status'] ?? '';
    if (($r['success'] ?? null) === true && $st === 'cancelled') {
        $pass("1 cancel/super_admin — active lease cancelled (status=cancelled)");
    } else {
        $fail("1 cancel/super_admin — blocked: resp=" . json_encode($r['error'] ?? $r) . " status={$st} (pre-fix: 403 for everyone)");
    }

    // 2. dispatcher still blocked (gate discriminates)
    $r = $post('api/v1/leases/cancel.php', ['id' => $leaseDisp, 'cancel_reason' => 'h6 smoke'], $dispSess);
    $st = db_row("SELECT status FROM leases WHERE id = ?", [$leaseDisp])['status'] ?? '';
    if (($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'FORBIDDEN' && $st === 'active') {
        $pass("2 cancel/dispatcher — still 403 (manager gate intact; lease unchanged)");
    } else {
        $fail("2 cancel/dispatcher — gate broke: resp=" . json_encode($r) . " status={$st}");
    }

    // 3. reenable_email as super_admin reaches business logic (not 403)
    $r = $post('api/v1/customers/reenable_email.php', ['id' => $custId], $superSess);
    if (($r['success'] ?? null) === true) {
        $pass("3 reenable_email/super_admin — allowed (idempotent success on a non-disabled customer)");
    } else {
        $fail("3 reenable_email/super_admin — blocked: resp=" . json_encode($r['error'] ?? $r) . " (pre-fix: 403)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed leases + customer; restored unit status\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("H6 ROLE GATE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
