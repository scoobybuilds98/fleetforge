<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_contractnum_honor_input.php
 *
 * SC2: create with contract_number='MTTS<pid>' → persisted verbatim.
 * SC3: create with blank contract_number → auto-generated (CN-XXXXXX-YYYY).
 * SC4: create with a duplicate (live) contract_number → 422 CONTRACT_NUMBER_TAKEN, no row.
 * SC5: create with a contract_number held by a SOFT-DELETED lease → 422
 *      CONTRACT_NUMBER_TAKEN (not an HTTP 500). Regression for Sentry FLEETFORGE-P:
 *      the contract_number UNIQUE index is global, so db_exists() (which filters
 *      deleted_at IS NULL) was blind to the collision and let it 1062 on INSERT.
 *
 * All writes cleaned up; reserved units released. Runs against the real db + schema.
 *
 * Run:  php tests/_smoke_lease_contractnum_honor_input.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session S-LEASE-CONTRACTNUM-HONOR-INPUT, FLEETFORGE-P (soft-delete dup guard)
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

$PID        = getmypid();
$CTOK       = 'MTTS-CNUM-' . $PID;  // unique prefix keeps cleanup safe
$FIXED_CN   = 'MTTS' . $PID;        // SC2 / SC4 test contract number
$SOFTDEL_CN = 'MTTS' . $PID . 'D';  // SC5: number held by a soft-deleted lease

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

$createdIds    = [];
$reservedUnits = [];

$cleanup = static function () use (&$createdIds, &$reservedUnits, $CTOK, $PID) {
    foreach ($createdIds as $id) {
        db_execute("DELETE FROM audit_log WHERE entity_type='lease' AND entity_id=?", [$id]);
        db_execute("DELETE FROM leases WHERE id=?", [$id]);
    }
    // Belt-and-suspenders: wipe by contract_number pattern (covers soft-deleted rows too)
    db_execute("DELETE FROM leases WHERE contract_number LIKE ?", ['MTTS' . $PID . '%']);
    db_execute("DELETE FROM leases WHERE contract_number LIKE ?", [$CTOK . '%']);
    // Release the units this test reserved so the dev DB stays re-runnable.
    foreach ($reservedUnits as $uid) {
        db_execute("UPDATE equipment_units SET status='available' WHERE id=? AND status IN ('reserved','on_lease')", [$uid]);
    }
};

try {
    // ── Setup: resolve a super_admin session, a customer, and an available unit ──
    $admin = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                     WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL: no super_admin\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'CNum Smoke', 'role_slug' => 'super_admin'];

    $custId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$custId) { echo "SETUP FAIL: no customer\n"; exit(2); }

    // Need three distinct available units (SC2 + SC3 + SC5 each create a lease)
    $units = db_select("SELECT eu.id FROM equipment_units eu
                       WHERE eu.deleted_at IS NULL AND eu.status='available'
                       ORDER BY eu.id LIMIT 3");
    if (count($units) < 3) { echo "SETUP FAIL: need 3 available units\n"; exit(2); }
    [$unitA, $unitB, $unitC] = [(int) $units[0]['id'], (int) $units[1]['id'], (int) $units[2]['id']];
    $reservedUnits = [$unitA, $unitB, $unitC];

    $cleanup();

    $basePayload = [
        'customer_id'       => $custId,
        'start_date'        => date('Y-m-d'),
        'start_time'        => '09:00',   // S-LEASE-RENTAL-DAY-TIME: now mandatory
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

    echo "\nC4 — SC5: number held by a SOFT-DELETED lease → 422, not 500 (FLEETFORGE-P)\n";
    // Root cause: leases.contract_number is a GLOBAL unique index (spans soft-deleted
    // rows), but the old pre-check used db_exists() which filters deleted_at IS NULL —
    // so a reused number passed the pre-check, then 1062'd on INSERT → HTTP 500.
    // Reproduce deterministically: create a lease, soft-delete it, reuse the number.
    $rVictim = $post('api/v1/leases/create.php', array_merge($basePayload, [
        'equipment_unit_id' => $unitC,
        'contract_number'   => $SOFTDEL_CN,
    ]));
    $victimId = (int) ($rVictim['data']['id'] ?? 0);
    if (($rVictim['success'] ?? false) && $victimId > 0) {
        $createdIds[] = $victimId;
        // Soft-delete the lease — the contract_number stays live in the unique index.
        db_execute("UPDATE leases SET deleted_at = NOW() WHERE id = ?", [$victimId]);
        // Free the unit too, so the reuse attempt reaches the leases INSERT rather
        // than tripping the unit FOR UPDATE gate first. This faithfully reproduces
        // the production flow (number reused on an AVAILABLE unit): with the fix the
        // pre-check 422s before the txn; reverted, it 1062s on INSERT → 500.
        db_execute("UPDATE equipment_units SET status='available' WHERE id = ?", [$unitC]);
        $pass('SC5 setup: victim lease ' . $SOFTDEL_CN . ' created, soft-deleted, unit freed');

        $rReuse = $post('api/v1/leases/create.php', array_merge($basePayload, [
            'equipment_unit_id' => $unitC,   // available again → exercises the INSERT path
            'contract_number'   => $SOFTDEL_CN,
        ]));
        $reuseCode = $rReuse['error']['code'] ?? '';
        if (!($rReuse['success'] ?? true) && $reuseCode === 'CONTRACT_NUMBER_TAKEN') {
            $pass('SC5 reuse of soft-deleted number → 422 CONTRACT_NUMBER_TAKEN (no PDO 500)');
        } else {
            $fail('SC5 expected CONTRACT_NUMBER_TAKEN, got: ' . json_encode($rReuse));
        }
        // No NEW (live) lease should exist for the number.
        $liveCount = (int) (db_row(
            "SELECT COUNT(*) AS n FROM leases WHERE contract_number=? AND deleted_at IS NULL",
            [$SOFTDEL_CN])['n'] ?? 0);
        if ($liveCount === 0) {
            $pass('SC5 no live lease created for the soft-deleted number');
        } else {
            $fail('SC5 a live lease was created for ' . $SOFTDEL_CN . ' (count=' . $liveCount . ')');
        }
    } else {
        $fail('SC5 setup failed — could not create victim lease: ' . json_encode($rVictim));
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
