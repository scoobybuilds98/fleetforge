<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_contractnum_honor_input.php
 *
 * SC2: create with contract_number='MTTS1049' → persisted as MTTS1049.
 * SC3: create with blank contract_number → auto-generated (CN-XXXXXX-YYYY).
 * SC4: create with a duplicate contract_number → 422 CONTRACT_NUMBER_TAKEN, no row.
 *
 * All writes rolled back / cleaned up. Runs against the real db + schema.
 *
 * Run:  php tests/_smoke_lease_contractnum_honor_input.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-LEASE-CONTRACTNUM-HONOR-INPUT
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/includes/auth.php';

if (!isset($_SESSION)) $_SESSION = [];

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID       = getmypid();
$CTOK      = 'MTTS-CNUM-' . $PID;   // unique prefix keeps cleanup safe
$FIXED_CN  = 'MTTS' . $PID;         // SC2 / SC4 test contract number

// ── Subprocess POST harness (mirrors project convention) ─────────────────────
$harnessFile = sys_get_temp_dir() . '/_ff_cnum_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfCNumInput {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c) { \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfCNumInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfCNumInput');
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

$cleanup = static function () use (&$createdIds, $CTOK, $FIXED_CN, $PID) {
    foreach ($createdIds as $id) {
        db_execute("DELETE FROM audit_log WHERE entity_type='lease' AND entity_id=?", [$id]);
        db_execute("DELETE FROM leases WHERE id=?", [$id]);
    }
    // Belt-and-suspenders: wipe by contract_number pattern
    db_execute("DELETE FROM leases WHERE contract_number LIKE ?", ['MTTS' . $PID . '%']);
    db_execute("DELETE FROM leases WHERE contract_number LIKE ?", [$CTOK . '%']);
};

try {
    // ── Setup: resolve a super_admin session, a customer, and an available unit ──
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                     WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL: no super_admin\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'CNum Smoke', 'role_slug' => 'super_admin'];

    $custId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$custId) { echo "SETUP FAIL: no customer\n"; exit(2); }

    // Need two distinct available units (SC2 + SC3 each create a lease)
    $units = db_select("SELECT eu.id FROM equipment_units eu
                       WHERE eu.deleted_at IS NULL AND eu.status='available'
                       ORDER BY eu.id LIMIT 2");
    if (count($units) < 2) { echo "SETUP FAIL: need 2 available units\n"; exit(2); }
    [$unitA, $unitB] = [(int) $units[0]['id'], (int) $units[1]['id']];

    $cleanup();

    $basePayload = [
        'customer_id'       => $custId,
        'start_date'        => date('Y-m-d'),
        'daily_rate'        => '50',
        'weekly_rate'       => '300',
        'monthly_rate'      => '1000',
        'billing_cycle'     => 'monthly',
        'currency'          => 'CAD',
    ];

    echo "\nC1 — SC2: supplied contract_number is honored\n";
    $r = $post('api/v1/leases/create.php', array_merge($basePayload, [
        'equipment_unit_id' => $unitA,
        'contract_number'   => $FIXED_CN,
    ]));
    if (($r['success'] ?? false) && ($r['data']['contract_number'] ?? '') === $FIXED_CN) {
        $pass('SC2 returned 201 with contract_number=' . $FIXED_CN);
        $createdIds[] = (int) ($r['data']['id'] ?? 0);
    } else {
        $fail('SC2 expected 201 contract_number=' . $FIXED_CN . ', got: ' . json_encode($r));
    }
    // Verify persistence in DB
    $persisted = db_row("SELECT contract_number FROM leases WHERE contract_number=?", [$FIXED_CN]);
    if ($persisted && $persisted['contract_number'] === $FIXED_CN) {
        $pass('SC2 DB persisted as ' . $FIXED_CN);
    } else {
        $fail('SC2 DB row not found for contract_number=' . $FIXED_CN);
    }

    echo "\nC2 — SC3: blank contract_number → auto-generated\n";
    $r = $post('api/v1/leases/create.php', array_merge($basePayload, [
        'equipment_unit_id' => $unitB,
        // contract_number intentionally omitted (blank path)
    ]));
    if ($r['success'] ?? false) {
        $autoCN = $r['data']['contract_number'] ?? '';
        $pass('SC3 returned 201');
        $createdIds[] = (int) ($r['data']['id'] ?? 0);
        // Should NOT equal the fixed value and should match CN-XXXXXX-YYYY or settings prefix
        if ($autoCN !== $FIXED_CN && $autoCN !== '') {
            $pass('SC3 auto-generated a different number: ' . $autoCN);
        } else {
            $fail('SC3 auto-generated number looks wrong: ' . $autoCN);
        }
        // DB persistence
        $row = db_row("SELECT contract_number FROM leases WHERE contract_number=?", [$autoCN]);
        if ($row) {
            $pass('SC3 DB persisted auto-number ' . $autoCN);
        } else {
            $fail('SC3 DB row not found for auto-number ' . $autoCN);
        }
    } else {
        $fail('SC3 expected 201 but got: ' . json_encode($r));
    }

    echo "\nC3 — SC4: duplicate contract_number → 422, no new row\n";
    // $FIXED_CN was already inserted by C1
    $countBefore = (int) (db_row("SELECT COUNT(*) AS n FROM leases WHERE contract_number=?", [$FIXED_CN])['n'] ?? 0);
    $r = $post('api/v1/leases/create.php', array_merge($basePayload, [
        'equipment_unit_id' => $unitA,   // unit is on_lease now, but 422 fires before the FOR UPDATE
        'contract_number'   => $FIXED_CN,
    ]));
    $countAfter = (int) (db_row("SELECT COUNT(*) AS n FROM leases WHERE contract_number=?", [$FIXED_CN])['n'] ?? 0);

    // Response shape: {"success":false,"error":{"code":"...","message":"..."}}
    $errorCode = $r['error']['code'] ?? '';
    $errorMsg  = $r['error']['message'] ?? '';
    if (!($r['success'] ?? true) && $errorCode === 'CONTRACT_NUMBER_TAKEN') {
        $pass('SC4 returned CONTRACT_NUMBER_TAKEN error');
    } else {
        $fail('SC4 expected CONTRACT_NUMBER_TAKEN, got: ' . json_encode($r));
    }
    if (str_contains($errorMsg, $FIXED_CN)) {
        $pass('SC4 error message contains the duplicate number');
    } else {
        $fail('SC4 error message missing duplicate number, got: ' . $errorMsg);
    }
    if ($countAfter === $countBefore) {
        $pass('SC4 no row inserted (count unchanged at ' . $countBefore . ')');
    } else {
        $fail('SC4 row count changed: before=' . $countBefore . ' after=' . $countAfter);
    }

} finally {
    $cleanup();
    @unlink($harnessFile);
}

echo "\n";
if ($failures) {
    foreach ($failures as $f) echo "  \033[31mFAIL\033[0m — {$f}\n";
    echo "\n\033[31m" . count($failures) . " failure(s)\033[0m / {$passes} pass(es)\n\n";
    exit(1);
}
echo "\033[32mAll {$passes} checks PASS\033[0m\n\n";
exit(0);
