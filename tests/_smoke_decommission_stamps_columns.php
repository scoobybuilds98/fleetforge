<?php
declare(strict_types=1);

/**
 * tests/_smoke_decommission_stamps_columns.php
 *
 * WAVE 4 [04] LOW — decommission via status change left the dedicated columns unset.
 *
 * api/v1/equipment/units/update_status.php wrote only `status` when transitioning
 * a unit to 'decommissioned', leaving equipment_units.decommissioned_date and
 * .decommission_reason NULL — so any report keyed on decommissioned_date
 * ("units decommissioned this year") silently missed the unit. Fixed to stamp
 * both columns on the decommissioned transition (business date in app tz).
 *
 * Drives the REAL endpoint via the subprocess HTTP harness (faked session +
 * CSRF), inside a try/finally that restores the unit:
 *   1. maintenance → decommissioned with a reason → 200, status flips,
 *      decommissioned_date = today AND decommission_reason = the reason.
 *   2. control: a non-decommission transition (available → maintenance) leaves
 *      both columns untouched (no accidental stamping).
 *
 * PRE-FIX  : case 1's decommissioned_date / decommission_reason stay NULL → FAIL.
 * POST-FIX : both set; control case clean.
 *
 * Run:  php tests/_smoke_decommission_stamps_columns.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-4-DECOMMISSION-COLUMNS
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

// ── Subprocess HTTP harness (same shape as _smoke_lease_cancel_role.php) ────
$harnessFile = sys_get_temp_dir() . '/_ff_decom_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??'');
\$sess=json_decode(base64_decode(\$argv[3]??''), true);
class FfDecIn {
    private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfDecIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfDecIn');
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

$unitId = null;
$snap   = null;   // original row snapshot for restore

$cleanup = static function () use (&$unitId, &$snap) {
    if ($unitId && $snap !== null) {
        db_execute(
            "UPDATE equipment_units SET status = ?, decommissioned_date = ?, decommission_reason = ? WHERE id = ?",
            [$snap['status'], $snap['decommissioned_date'], $snap['decommission_reason'], $unitId]
        );
    }
};

try {
    $admin = db_row("SELECT u.id,u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'Decom Admin', 'role_slug' => 'super_admin'];

    $unit = db_row("SELECT id, status, decommissioned_date, decommission_reason
                      FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    if (!$unit) { echo "SETUP FAIL no equipment_unit\n"; exit(2); }
    $unitId = (int) $unit['id'];
    $snap   = $unit;

    echo str_repeat('─', 72) . "\n";
    echo "WAVE 4 [04] DECOMMISSION — stamps decommissioned_date + reason\n";
    echo str_repeat('─', 72) . "\n";

    $today  = date('Y-m-d');
    $reason = "smoke decommission {$PID}";

    // ── CASE 1: maintenance → decommissioned stamps both columns ────────────
    // Put the unit into 'maintenance' (a state from which decommission is legal).
    db_execute("UPDATE equipment_units SET status='maintenance', decommissioned_date=NULL, decommission_reason=NULL WHERE id=?", [$unitId]);
    $r   = $post('api/v1/equipment/units/update_status.php',
                 ['id' => $unitId, 'new_status' => 'decommissioned', 'reason' => $reason], $sess);
    $row = db_row("SELECT status, decommissioned_date, decommission_reason FROM equipment_units WHERE id=?", [$unitId]);

    if (($r['success'] ?? null) === true
        && ($row['status'] ?? '') === 'decommissioned'
        && ($row['decommissioned_date'] ?? null) === $today
        && ($row['decommission_reason'] ?? null) === $reason) {
        $pass("1 decommission — date={$row['decommissioned_date']} reason set; status=decommissioned");
    } else {
        $fail("1 decommission — resp=" . json_encode($r['error'] ?? $r)
            . " date=" . var_export($row['decommissioned_date'] ?? null, true)
            . " reason=" . var_export($row['decommission_reason'] ?? null, true)
            . " (pre-fix: both NULL)");
    }

    // ── CASE 2: control — non-decommission transition doesn't stamp ─────────
    // From decommissioned we can't transition out (terminal), so reset to
    // 'available' directly, then do available → maintenance via the endpoint.
    db_execute("UPDATE equipment_units SET status='available', decommissioned_date=NULL, decommission_reason=NULL WHERE id=?", [$unitId]);
    $r   = $post('api/v1/equipment/units/update_status.php',
                 ['id' => $unitId, 'new_status' => 'maintenance', 'reason' => 'control'], $sess);
    $row = db_row("SELECT status, decommissioned_date, decommission_reason FROM equipment_units WHERE id=?", [$unitId]);
    if (($r['success'] ?? null) === true
        && ($row['status'] ?? '') === 'maintenance'
        && ($row['decommissioned_date'] ?? null) === null
        && ($row['decommission_reason'] ?? null) === null) {
        $pass("2 control — available→maintenance leaves decommission columns NULL");
    } else {
        $fail("2 control — unexpected stamping: " . json_encode($row) . " resp=" . json_encode($r['error'] ?? $r));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (file_exists($harnessFile)) @unlink($harnessFile);
    echo "  restored unit {$unitId} to original status/columns\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("DECOMMISSION COLUMNS — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
