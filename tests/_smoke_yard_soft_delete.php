<?php
declare(strict_types=1);

/**
 * tests/_smoke_yard_soft_delete.php
 *
 * MEDIUM [09] — yard soft-delete correctness (two findings, one domain):
 *   - create.php: re-creating a soft-deleted yard name now REVIVES the tombstone
 *     (yards has a GLOBAL unique key on name + soft delete → previously a
 *     lockout / 1062). Mirrors the C2 customer revive.
 *   - destroy.php: deleting a yard with equipment units parked at it (denormalized
 *     yard_location name string, no FK) is now REJECTED — previously it orphaned
 *     those units.
 *
 * Drives the real endpoints (POST harness, super_admin). All writes cleaned up;
 * the borrowed equipment unit's yard_location is snapshotted + restored.
 *
 * Run:  php tests/_smoke_yard_soft_delete.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session MEDIUM-09-YARD-SOFT-DELETE
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

$harnessFile = sys_get_temp_dir() . '/_ff_yard_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$payload=base64_decode(\$argv[2]??''); \$sess=json_decode(base64_decode(\$argv[3]??''),true);
class FfYardIn {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c){ \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfYardIn::set(\$payload);
stream_wrapper_unregister('php'); stream_wrapper_register('php','FfYardIn');
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

$nameR = "ZZ Yard Revive {$PID}";
$nameP = "ZZ Yard Parked {$PID}";
$unitId = null; $unitYard0 = null;

$cleanup = static function () use ($nameR, $nameP, &$unitId, &$unitYard0) {
    db_execute("DELETE FROM yards WHERE name IN (?, ?)", [$nameR, $nameP]);
    if ($unitId && $unitYard0 !== null) {
        db_execute("UPDATE equipment_units SET yard_location = ? WHERE id = ?", [$unitYard0, $unitId]);
    }
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $sess = ['id' => (int) $admin['id'], 'name' => 'Yard Smoke', 'role_slug' => 'super_admin'];
    $cleanup();

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [09] YARD SOFT-DELETE — revive + orphan guard\n";
    echo str_repeat('─', 72) . "\n";

    // ── REVIVE: re-create a soft-deleted yard name ───────────────────────────
    $r = $post('api/v1/yards/create.php', ['name' => $nameR]);
    $id1 = (int) ($r['data']['id'] ?? 0);
    db_execute("UPDATE yards SET deleted_at = NOW() WHERE id = ?", [$id1]);
    $r = $post('api/v1/yards/create.php', ['name' => $nameR]);
    $rows = db_select("SELECT id, deleted_at FROM yards WHERE name = ?", [$nameR]);
    if (($r['success'] ?? null) === true && count($rows) === 1 && $rows[0]['deleted_at'] === null && (int) $rows[0]['id'] === $id1) {
        $pass("revive — re-creating a soft-deleted yard name revived it (same id={$id1}, no duplicate)");
    } else {
        $fail("revive — success=" . json_encode($r['success'] ?? null) . " rows=" . count($rows)
            . " id_match=" . (isset($rows[0]) && (int) $rows[0]['id'] === $id1 ? 'Y' : 'N') . " err=" . json_encode($r['error'] ?? null));
    }

    // ── ORPHAN GUARD: can't delete a yard with units parked at it ────────────
    $r = $post('api/v1/yards/create.php', ['name' => $nameP]);
    $id2 = (int) ($r['data']['id'] ?? 0);
    $u = db_row("SELECT id, yard_location FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    $unitId = (int) $u['id']; $unitYard0 = $u['yard_location'];
    db_execute("UPDATE equipment_units SET yard_location = ? WHERE id = ?", [$nameP, $unitId]);

    $r = $post('api/v1/yards/destroy.php', ['id' => $id2]);
    $del = db_row("SELECT deleted_at FROM yards WHERE id = ?", [$id2])['deleted_at'] ?? null;
    if (($r['success'] ?? null) === false && ($r['error']['code'] ?? '') === 'HAS_PARKED_UNITS' && $del === null) {
        $pass("orphan guard — delete blocked while a unit is parked at the yard (not orphaned)");
    } else {
        $fail("orphan guard — resp=" . json_encode($r) . " deleted_at=" . json_encode($del));
    }

    // non-vacuous: move the unit away, delete now succeeds
    db_execute("UPDATE equipment_units SET yard_location = ? WHERE id = ?", [$unitYard0, $unitId]);
    $r = $post('api/v1/yards/destroy.php', ['id' => $id2]);
    if (($r['success'] ?? null) === true) {
        $pass("non-vacuous — delete succeeds once no units are parked");
    } else {
        $fail("non-vacuous — delete failed: " . json_encode($r['error'] ?? $r));
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  removed yards; restored unit yard_location\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("YARD SOFT-DELETE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
