<?php
declare(strict_types=1);

/**
 * tests/_smoke_inspection_notnull_update.php
 *
 * MEDIUM [04] — partial updates pushed NULL into NOT-NULL inspection columns.
 *
 * inspections/update.php (inspection_type, inspection_date) and
 * sections/update.php (condition) validated optional fields with isset()/truthy
 * but wrote them via array_key_exists() — a JSON null/'' skipped validation yet
 * still bound NULL to a NOT-NULL column → 1048 aborted the whole txn (500).
 * Fixed to reject a present-but-empty/invalid value with a clean 422.
 *
 * Asserts (real endpoints, super_admin):
 *   1. inspections/update with inspection_type='' → 422 (not 500).
 *   2. sections/update with condition='' → 422 (not 500).
 *   3. sections/update with a valid condition → succeeds (non-vacuous).
 *
 * PRE-FIX  : 1 and 2 return INTERNAL_ERROR (1048/500) → FAIL.
 * POST-FIX : VALIDATION_ERROR.
 *
 * Run:  php tests/_smoke_inspection_notnull_update.php   Exit 0 pass / 1 fail / 2 setup.
 *
 * @session MEDIUM-04-INSPECTION-NOTNULL
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_insp_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
class FfInspIn {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfInspIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfInspIn');
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

$inspId = null; $sectionId = null;
$cleanup = static function () use (&$inspId, &$sectionId) {
    if ($sectionId) { db_execute("DELETE FROM inspection_sections WHERE id = ?", [$sectionId]); }
    if ($inspId)    { db_execute("DELETE FROM inspection_sections WHERE inspection_id = ?", [$inspId]);
                      db_execute("DELETE FROM inspections WHERE id = ?", [$inspId]); }
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'Insp Smoke', 'role_slug' => 'super_admin'];
    $unit = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    if (!$unit) { echo "SETUP FAIL no equipment_unit\n"; exit(2); }

    $inspId = db_insert('inspections', [
        'inspection_type' => 'periodic', 'equipment_unit_id' => (int) $unit['id'],
        'inspection_date' => date('Y-m-d'), 'status' => 'draft',
    ]);
    $sectionId = db_insert('inspection_sections', [
        'inspection_id' => $inspId, 'section_name' => 'Tires', 'condition' => 'ok',
    ]);
    $upd = (string) db_row("SELECT updated_at FROM inspections WHERE id = ?", [$inspId])['updated_at'];

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [04] INSPECTION NOT-NULL UPDATE — inspection #{$inspId}, section #{$sectionId}\n";
    echo str_repeat('─', 72) . "\n";

    // 1. inspection_type=null → 422, not 500. JSON null slips the old isset()
    //    validation gate but array_key_exists() still writes it.
    $r = $post('api/v1/inspections/update.php', ['id' => $inspId, 'updated_at' => $upd, 'inspection_type' => null]);
    if (($r['error']['code'] ?? '') === 'VALIDATION_ERROR') {
        $pass("1 inspection_type null — clean 422 (not a 1048/500)");
    } else {
        $fail("1 inspection_type null — code=" . json_encode($r['error']['code'] ?? $r) . " (pre-fix INTERNAL_ERROR/1048)");
    }

    // 2. condition='' on a section → 422, not 500
    $r = $post('api/v1/inspections/sections/update.php', ['section_id' => $sectionId, 'condition' => '']);
    if (($r['error']['code'] ?? '') === 'VALIDATION_ERROR') {
        $pass("2 section condition empty — clean 422 (not a 1048/500)");
    } else {
        $fail("2 section condition empty — code=" . json_encode($r['error']['code'] ?? $r) . " (pre-fix INTERNAL_ERROR/1048)");
    }

    // 3. valid condition → succeeds (non-vacuous)
    $r = $post('api/v1/inspections/sections/update.php', ['section_id' => $sectionId, 'condition' => 'damaged']);
    $c = db_row("SELECT `condition` FROM inspection_sections WHERE id = ?", [$sectionId])['condition'] ?? '';
    if (($r['success'] ?? null) === true && $c === 'damaged') {
        $pass("3 valid condition — section updated to 'damaged' (non-vacuous)");
    } else {
        $fail("3 valid condition — failed: " . json_encode($r['error'] ?? $r) . " condition={$c}");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed inspection + section\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("INSPECTION NOT-NULL UPDATE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
