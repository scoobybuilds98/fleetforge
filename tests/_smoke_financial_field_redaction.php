<?php
declare(strict_types=1);

/**
 * tests/_smoke_financial_field_redaction.php
 *
 * WAVE 5 — financial-field redaction predicate across customer/equipment reads.
 *
 * The dashboard authz fix introduced can_view_financials() (= payments:view) as
 * the shared "can see money" predicate. The audit flagged the same leak on the
 * customer/equipment surfaces: a dispatcher (payments=NONE) receives customer
 * outstanding_balance/revenue/credit and unit cost/revenue via the list/show
 * APIs. This applies the same serve-time redaction there.
 *
 * Drives the REAL endpoints via the subprocess harness with dispatcher vs
 * super_admin sessions and asserts the money KEYS are absent for the dispatcher
 * and present for super_admin (value irrelevant — redaction unsets the key):
 *   - customers/index.php       outstanding_balance / total_revenue
 *   - customers/show.php        outstanding_balance / credit_limit
 *   - customers/kpis.php        overdue_balance
 *   - equipment/units/index.php total_revenue
 *   - equipment/units/show.php  acquisition_cost / total_maintenance_cost
 *
 * PRE-FIX  : dispatcher sees the money keys → FAIL.
 * POST-FIX : dispatcher keys stripped; super_admin keeps them.
 *
 * Run:  php tests/_smoke_financial_field_redaction.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-5-FINANCIAL-FIELD-REDACTION
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_finredact_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$qs=\$argv[2]??''; \$sess=json_decode(base64_decode(\$argv[3]??''), true);
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['ff_user']=\$sess;
require '{$ROOT}/' . \$endpoint;
PHP);
$get = static function (string $endpoint, string $qs, array $sess) use ($harnessFile): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"'); if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => substr((string) $out, 0, 160)];
};

// True if $keys appear anywhere in the (possibly nested) response data.
$hasAnyKey = static function (array $resp, array $keys): bool {
    $json = json_encode($resp['data'] ?? $resp);
    foreach ($keys as $k) {
        if (str_contains((string) $json, "\"{$k}\"")) return true;
    }
    return false;
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $super = ['id' => (int) $admin['id'], 'name' => 'Fin Admin', 'role_slug' => 'super_admin'];

    // Real user id (FK-safe) + dispatcher perms (payments NONE, customers/equipment view).
    $ru = db_row("SELECT id FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$ru) { echo "SETUP FAIL no active user\n"; exit(2); }
    $disp = ['id' => (int) $ru['id'], 'name' => 'Fin Disp', 'role_slug' => 'dispatcher', 'role_id' => null,
             'permissions' => ['payments' => ['view' => 0], 'customers' => ['view' => 1], 'equipment' => ['view' => 1]],
             'permission_overrides' => [], 'role_permission_overrides' => []];

    $cust = db_row("SELECT id FROM customers WHERE deleted_at IS NULL LIMIT 1");
    $unit = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    if (!$cust || !$unit) { echo "SETUP FAIL need a customer + a unit\n"; exit(2); }
    $cid = (int) $cust['id']; $uid = (int) $unit['id'];

    echo str_repeat('─', 72) . "\n";
    echo "WAVE 5 FINANCIAL-FIELD REDACTION — customers + equipment reads\n";
    echo str_repeat('─', 72) . "\n";

    $cases = [
        ['customers/index',      'api/v1/customers/index.php',       '',                ['outstanding_balance', 'total_revenue']],
        ['customers/show',       'api/v1/customers/show.php',        "id={$cid}",       ['outstanding_balance', 'credit_limit']],
        ['customers/kpis',       'api/v1/customers/kpis.php',        '',                ['overdue_balance']],
        ['equipment/units/index','api/v1/equipment/units/index.php', '',                ['total_revenue']],
        ['equipment/units/show', 'api/v1/equipment/units/show.php',  "id={$uid}",       ['acquisition_cost', 'total_maintenance_cost']],
    ];

    foreach ($cases as [$label, $endpoint, $qs, $keys]) {
        $d = $get($endpoint, $qs, $disp);
        $s = $get($endpoint, $qs, $super);
        $dispLeaks  = $hasAnyKey($d, $keys);
        $superShows = $hasAnyKey($s, $keys);
        if (!$dispLeaks && $superShows) {
            $pass("{$label} — money keys [" . implode(',', $keys) . "] stripped for dispatcher, present for super_admin");
        } else {
            $fail("{$label} — dispatcher_has_money=" . ($dispLeaks ? 'YES(leak)' : 'no')
                . " super_has_money=" . ($superShows ? 'yes' : 'NO')
                . (isset($d['_raw']) ? " disp_raw={$d['_raw']}" : '')
                . (isset($s['_raw']) ? " super_raw={$s['_raw']}" : ''));
        }
    }

} finally {
    if (file_exists($harnessFile)) @unlink($harnessFile);
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("FINANCIAL-FIELD REDACTION — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
