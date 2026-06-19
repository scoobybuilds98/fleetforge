<?php
declare(strict_types=1);
/**
 * tests/_smoke_lease_create_endpoint.php — S-LEASE-CREATE-ENDPOINT
 *
 * Regression guard for the use()-closure capture trap that 500'd every lease
 * create on prod (FLEETFORGE-W; fixed a6115d3): the lease db_insert lives inside
 * the $createLease = function () use (...) closure in api/v1/leases/create.php,
 * and any column whose $variable is referenced in the insert but MISSING from
 * use() resolves to NULL — fatal for NOT NULL columns (mileage_tracking_mode),
 * silent data-loss for nullable ones (cartage_amount, engine_hours_at_start).
 *
 * The existing rate-tier smoke only exercises the REJECTION path (incomplete
 * tiers → 422), so it never reaches the insert. This drives a FULLY VALID create
 * through the REAL endpoint (subprocess + php://input + auth session, per
 * feedback_schema_real_smoke_coverage) so the insert closure actually runs:
 *
 *   T1 — valid create with the new columns set (mileage_tracking_mode=samsara,
 *        cartage_amount, engine_hours_at_start) → success AND every value is
 *        persisted (any var dropped from use() would be NULL/default here, or a
 *        500 for the NOT NULL mileage_tracking_mode).
 *   T2 — minimal valid create (no new fields) → success + mileage_tracking_mode
 *        defaults to 'off'.
 *
 * NOT hermetic (the endpoint commits its own transaction); cleans up by deleting
 * the created leases by contract token + resetting the reserved unit.
 *
 * Run: php tests/_smoke_lease_create_endpoint.php   (exit 0 PASS / 1 FAIL)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';
if (!isset($_SESSION)) $_SESSION = [];

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS — {$m}\n"; }
function no(string $m): void  { global $fail; $fail++; echo "  FAIL — {$m}\n"; }

$PID  = getmypid();
$CTOK = '_SMOKE_CREATE_' . $PID;

// ── POST harness: subprocess that feeds php://input + an auth session and
//    requires the real endpoint (mirrors _smoke_lease_rate_tier_enforcement). ──
$harnessFile = sys_get_temp_dir() . '/_ff_create_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfCreateInput {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c) { \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfCreateInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfCreateInput');
\$_SERVER['REQUEST_METHOD']    = 'POST';
\$_SERVER['CONTENT_TYPE']      = 'application/json';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'smoketoken';
\$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
\$_SERVER['HTTP_HOST']         = 'localhost';
@session_start();
\$_SESSION['csrf_token'] = 'smoketoken';
\$_SESSION['ff_user']    = \$sess;
require '{$ROOT}/' . \$endpoint;
PHP);

$adminSession = null;
$post = static function (string $endpoint, array $payload) use ($harnessFile, &$adminSession): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"success"');
    if ($s === false) $s = strpos($out, '{"');
    if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
};

$createdIds = [];
$unitId = 0;
$unitPriorStatus = null;
$cleanup = static function () use (&$createdIds, &$unitId, &$unitPriorStatus, $CTOK) {
    foreach ($createdIds as $lid) {
        db_execute("DELETE FROM audit_log WHERE entity_type='lease' AND entity_id=?", [$lid]);
        db_execute("DELETE FROM leases WHERE id=?", [$lid]);
    }
    db_execute("DELETE FROM leases WHERE contract_number LIKE ?", [$CTOK . '%']);
    if ($unitId && $unitPriorStatus !== null) {
        db_execute("UPDATE equipment_units SET status=? WHERE id=?", [$unitPriorStatus, $unitId]);
    }
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL: no super_admin\n"; @unlink($harnessFile); exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'Create Smoke', 'role_slug' => 'super_admin'];

    $custId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit   = db_row("SELECT id, status FROM equipment_units WHERE status='available' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$custId || !$unit) { echo "SKIP: need a customer + an available unit\n"; @unlink($harnessFile); exit(0); }
    $unitId = (int) $unit['id'];
    $unitPriorStatus = $unit['status'];

    echo str_repeat('-', 72) . "\n";
    echo "LEASE CREATE ENDPOINT — customer #{$custId}, unit #{$unitId}, pid={$PID}\n";
    echo str_repeat('-', 72) . "\n";

    $basePayload = [
        'customer_id'       => $custId,
        'equipment_unit_id' => $unitId,
        'start_date'        => date('Y-m-d'),
        'start_time'        => '08:00',
        'billing_cycle'     => 'monthly',
        'daily_rate'        => '100',
        'weekly_rate'       => '600',
        'monthly_rate'      => '2000',
        'mileage_unit'      => 'km',
        'mileage_rate'      => '0',
        'currency'          => 'CAD',
    ];

    // ── T1: valid create WITH the new columns set → reaches the insert closure ──
    $r = $post('api/v1/leases/create.php', $basePayload + [
        'contract_number'       => $CTOK . '-1',
        'mileage_tracking_mode' => 'samsara',
        'engine_hours_at_start' => '100.50',
        'cartage_amount'        => '175.00',
    ]);
    if (($r['success'] ?? null) === true && !empty($r['data']['id'])) {
        $lid = (int) $r['data']['id'];
        $createdIds[] = $lid;
        $row = db_row("SELECT mileage_tracking_mode, engine_hours_at_start, cartage_amount FROM leases WHERE id=?", [$lid]);
        $modeOk  = ($row['mileage_tracking_mode'] ?? null) === 'samsara';
        $hoursOk = $row && $row['engine_hours_at_start'] !== null && bccomp((string) $row['engine_hours_at_start'], '100.50', 2) === 0;
        $cartOk  = $row && $row['cartage_amount'] !== null && bccomp((string) $row['cartage_amount'], '175.00', 2) === 0;
        if ($modeOk && $hoursOk && $cartOk) {
            ok("T1 valid create persists all closure-captured columns (mode=samsara, hours=100.50, cartage=175.00)");
        } else {
            no("T1 created but a closure-captured column was dropped to NULL/default — "
               . "mode=" . json_encode($row['mileage_tracking_mode'] ?? null)
               . " hours=" . json_encode($row['engine_hours_at_start'] ?? null)
               . " cartage=" . json_encode($row['cartage_amount'] ?? null)
               . " (a missing use() capture). ");
        }
    } else {
        // The exact prod failure mode: 500 from mileage_tracking_mode NOT NULL, or any validation error.
        no("T1 valid create did NOT succeed — success=" . json_encode($r['success'] ?? null)
           . " err=" . json_encode($r['error'] ?? ($r['_raw'] ?? null)));
    }

    // Free the unit before T2 (each successful create reserves it): delete T1's
    // lease + reset the unit to available so T2 can reuse the same single unit.
    foreach ($createdIds as $lid) {
        db_execute("DELETE FROM audit_log WHERE entity_type='lease' AND entity_id=?", [$lid]);
        db_execute("DELETE FROM leases WHERE id=?", [$lid]);
    }
    $createdIds = [];
    db_execute("UPDATE equipment_units SET status='available' WHERE id=?", [$unitId]);

    // ── T2: minimal valid create (no new fields) → success + mode defaults 'off' ──
    $r2 = $post('api/v1/leases/create.php', $basePayload + ['contract_number' => $CTOK . '-2']);
    if (($r2['success'] ?? null) === true && !empty($r2['data']['id'])) {
        $lid2 = (int) $r2['data']['id'];
        $createdIds[] = $lid2;
        $mode2 = db_row("SELECT mileage_tracking_mode FROM leases WHERE id=?", [$lid2])['mileage_tracking_mode'] ?? null;
        if ($mode2 === 'off') ok("T2 minimal valid create succeeds; mileage_tracking_mode defaults to 'off'");
        else no("T2 created but mileage_tracking_mode default wrong: " . json_encode($mode2) . " (expect 'off')");
    } else {
        no("T2 minimal valid create did NOT succeed — success=" . json_encode($r2['success'] ?? null)
           . " err=" . json_encode($r2['error'] ?? ($r2['_raw'] ?? null)));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $_SESSION = [];
    $cleanup();
    if (file_exists($harnessFile)) @unlink($harnessFile);
    echo "  cleaned " . count($createdIds) . " lease(s); unit #{$unitId} reset to '{$unitPriorStatus}'\n";
}

echo "\n" . str_repeat('-', 72) . "\n";
echo "LEASE CREATE ENDPOINT — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
