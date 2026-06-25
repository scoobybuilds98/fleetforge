<?php
declare(strict_types=1);

/**
 * tests/_smoke_unit_dupkey_guard.php
 *
 * Regression for Sentry FLEETFORGE-M (duplicate equipment_units.vin → HTTP 500)
 * and the sibling unit_number blind spot. equipment_units.vin and
 * equipment_units.unit_number both carry GLOBAL UNIQUE indexes that span
 * soft-deleted rows, while the create/update pre-checks were soft-delete-blind
 * (or absent). Reused values therefore passed the pre-check and then 1062'd on
 * INSERT/UPDATE → uncaught PDOException → 500.
 *
 * SC1: create unit with unique unit_number + vin → 201.
 * SC2: create another unit with the SAME LIVE unit_number → 409 ALREADY_EXISTS.
 * SC3: create another unit with the SAME LIVE vin → 409 ALREADY_EXISTS.
 * SC4: soft-delete a unit, reuse its unit_number on create → 409, NOT 500.
 * SC5: soft-delete a unit, reuse its vin on create → 409, NOT 500.
 * SC6: update — rename a unit's unit_number to a live peer's → 422, NOT 500.
 * SC7: update — change a unit's vin to a live peer's → 422, NOT 500.
 *
 * All writes cleaned up. Runs against the real db + schema.
 *
 * Run:  php tests/_smoke_unit_dupkey_guard.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session FLEETFORGE-M (equipment_units dup-key guard)
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

$PID  = getmypid();
$UTOK = 'ZZUDK' . $PID;          // unit_number prefix — unique, easy to clean up
$VTOK = 'ZZVIN' . $PID;          // vin prefix

// ── Subprocess POST harness (mirrors project convention) ─────────────────────
$harnessFile = sys_get_temp_dir() . '/_ff_unit_dupkey_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfUnitInput {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c) { \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfUnitInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfUnitInput');
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

$cleanup = static function () use (&$createdIds, $UTOK, $VTOK) {
    // Resolve any rows by token first so we also clean ids we never captured.
    $rows = db_select(
        "SELECT id FROM equipment_units WHERE unit_number LIKE ? OR vin LIKE ?",
        [$UTOK . '%', $VTOK . '%']
    );
    $ids = array_unique(array_merge($createdIds, array_map(fn($r) => (int) $r['id'], $rows)));
    foreach ($ids as $id) {
        if (!$id) continue;
        db_execute("DELETE FROM audit_log WHERE entity_type='equipment_unit' AND entity_id=?", [$id]);
        db_execute("DELETE FROM equipment_status_log WHERE equipment_unit_id=?", [$id]);
        db_execute("DELETE FROM equipment_units WHERE id=?", [$id]);
    }
};

// Create a unit via the API and return its id (or 0). Tracks for cleanup.
$createUnit = static function (string $unitNumber, ?string $vin) use ($post, &$createdIds, &$templateId): array {
    $payload = [
        'template_id'    => $templateId,
        'unit_number'    => $unitNumber,
        'ownership_type' => 'owned',
    ];
    if ($vin !== null) $payload['vin'] = $vin;
    $r = $post('api/v1/equipment/units/create.php', $payload);
    $id = (int) ($r['data']['id'] ?? 0);
    if ($id) $createdIds[] = $id;
    return [$r, $id];
};

try {
    // ── Setup ──────────────────────────────────────────────────────────────
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id
                     WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL: no super_admin\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'Unit DupKey Smoke', 'role_slug' => 'super_admin'];

    $templateId = (int) (db_row(
        "SELECT id FROM equipment_templates WHERE deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT 1"
    )['id'] ?? 0);
    if (!$templateId) { echo "SETUP FAIL: no active equipment_template\n"; exit(2); }

    $cleanup();

    echo "\nC1 — SC1: create unit with unique unit_number + vin\n";
    [$r1, $id1] = $createUnit($UTOK . 'A', $VTOK . 'A');
    if (($r1['success'] ?? false) && $id1 > 0) {
        $pass('SC1 created unit ' . $UTOK . 'A (id=' . $id1 . ')');
    } else {
        $fail('SC1 expected 201, got: ' . json_encode($r1));
    }

    echo "\nC2 — SC2: duplicate LIVE unit_number → 409, no 500\n";
    [$r2] = $createUnit($UTOK . 'A', $VTOK . 'B');
    if (!($r2['success'] ?? true) && ($r2['error']['code'] ?? '') === 'ALREADY_EXISTS') {
        $pass('SC2 duplicate live unit_number → ALREADY_EXISTS');
    } else {
        $fail('SC2 expected ALREADY_EXISTS, got: ' . json_encode($r2));
    }

    echo "\nC3 — SC3: duplicate LIVE vin → 409, no 500\n";
    [$r3] = $createUnit($UTOK . 'C', $VTOK . 'A');
    if (!($r3['success'] ?? true) && ($r3['error']['code'] ?? '') === 'ALREADY_EXISTS') {
        $pass('SC3 duplicate live vin → ALREADY_EXISTS');
    } else {
        $fail('SC3 expected ALREADY_EXISTS, got: ' . json_encode($r3));
    }
    // S-UNIT-VIN-CONFLICT-NAME: the error must NAME the live unit holding the vin.
    if (strpos($r3['error']['message'] ?? '', $UTOK . 'A') !== false) {
        $pass('SC3b vin-conflict message names the live holder (' . $UTOK . 'A)');
    } else {
        $fail('SC3b expected message to name unit ' . $UTOK . 'A, got: ' . ($r3['error']['message'] ?? ''));
    }

    echo "\nC4 — SC4: reuse unit_number held by a SOFT-DELETED unit → 409, not 500\n";
    [$rv4, $victim4] = $createUnit($UTOK . 'D', $VTOK . 'D');
    if ($victim4 > 0) {
        db_execute("UPDATE equipment_units SET deleted_at = NOW() WHERE id = ?", [$victim4]);
        $pass('SC4 setup: victim ' . $UTOK . 'D created and soft-deleted');
        [$r4] = $createUnit($UTOK . 'D', $VTOK . 'E');   // reuse unit_number, fresh vin
        if (!($r4['success'] ?? true) && ($r4['error']['code'] ?? '') === 'ALREADY_EXISTS') {
            $pass('SC4 reuse of soft-deleted unit_number → ALREADY_EXISTS (no PDO 500)');
        } else {
            $fail('SC4 expected ALREADY_EXISTS, got: ' . json_encode($r4));
        }
    } else {
        $fail('SC4 setup failed: ' . json_encode($rv4));
    }

    echo "\nC5 — SC5: reuse vin held by a SOFT-DELETED unit → 409, not 500\n";
    [$rv5, $victim5] = $createUnit($UTOK . 'F', $VTOK . 'F');
    if ($victim5 > 0) {
        db_execute("UPDATE equipment_units SET deleted_at = NOW() WHERE id = ?", [$victim5]);
        $pass('SC5 setup: victim vin ' . $VTOK . 'F created and soft-deleted');
        [$r5] = $createUnit($UTOK . 'G', $VTOK . 'F');   // fresh unit_number, reuse vin
        if (!($r5['success'] ?? true) && ($r5['error']['code'] ?? '') === 'ALREADY_EXISTS') {
            $pass('SC5 reuse of soft-deleted vin → ALREADY_EXISTS (no PDO 500)');
        } else {
            $fail('SC5 expected ALREADY_EXISTS, got: ' . json_encode($r5));
        }
        // S-UNIT-VIN-CONFLICT-NAME: a soft-deleted holder must be flagged as such.
        if (stripos($r5['error']['message'] ?? '', 'deleted') !== false) {
            $pass('SC5b vin-conflict message flags the soft-deleted holder');
        } else {
            $fail('SC5b expected message to flag a deleted holder, got: ' . ($r5['error']['message'] ?? ''));
        }
    } else {
        $fail('SC5 setup failed: ' . json_encode($rv5));
    }

    echo "\nC6 — SC6/SC7: update collisions on a live peer → 422, not 500\n";
    // Two distinct live units; then try to rename / re-vin one onto the other.
    [$ra, $idA] = $createUnit($UTOK . 'H', $VTOK . 'H');
    [$rb, $idB] = $createUnit($UTOK . 'I', $VTOK . 'I');
    if ($idA > 0 && $idB > 0) {
        $uaUpdatedAt = db_row("SELECT updated_at FROM equipment_units WHERE id = ?", [$idB])['updated_at'] ?? '';

        $r6 = $post('api/v1/equipment/units/update.php', [
            'id'          => $idB,
            'updated_at'  => $uaUpdatedAt,
            'unit_number' => $UTOK . 'H',   // collide with unit A's number
        ]);
        if (!($r6['success'] ?? true) && ($r6['error']['code'] ?? '') === 'VALIDATION_ERROR'
            && isset($r6['error']['fields']['unit_number'])) {
            $pass('SC6 rename onto a live unit_number → 422 field error (no 500)');
        } else {
            $fail('SC6 expected unit_number 422, got: ' . json_encode($r6));
        }

        $uaUpdatedAt2 = db_row("SELECT updated_at FROM equipment_units WHERE id = ?", [$idB])['updated_at'] ?? '';
        $r7 = $post('api/v1/equipment/units/update.php', [
            'id'         => $idB,
            'updated_at' => $uaUpdatedAt2,
            'vin'        => $VTOK . 'H',   // collide with unit A's vin
        ]);
        if (!($r7['success'] ?? true) && ($r7['error']['code'] ?? '') === 'VALIDATION_ERROR'
            && isset($r7['error']['fields']['vin'])) {
            $pass('SC7 re-vin onto a live vin → 422 field error (no 500)');
        } else {
            $fail('SC7 expected vin 422, got: ' . json_encode($r7));
        }
        // S-UNIT-VIN-CONFLICT-NAME: the field error names the live peer.
        if (strpos($r7['error']['fields']['vin'] ?? '', $UTOK . 'H') !== false) {
            $pass('SC7b re-vin field error names the live peer (' . $UTOK . 'H)');
        } else {
            $fail('SC7b expected vin field error to name unit ' . $UTOK . 'H, got: ' . ($r7['error']['fields']['vin'] ?? ''));
        }
    } else {
        $fail('SC6/SC7 setup failed: A=' . json_encode($ra) . ' B=' . json_encode($rb));
    }

    echo "\nC8 — SC8: move_vin atomically reassigns a VIN between units (S-UNIT-VIN-MOVE)\n";
    // Unit J holds a VIN; unit K has none. Move J's VIN onto K → J cleared, K set.
    [$rj, $idJ] = $createUnit($UTOK . 'J', $VTOK . 'J');
    [$rk, $idK] = $createUnit($UTOK . 'K', null);
    if ($idJ > 0 && $idK > 0) {
        $rm = $post('api/v1/equipment/units/move_vin.php', ['vin' => $VTOK . 'J', 'to_unit_id' => $idK]);
        // Read vin without coalescing — a cleared vin is genuinely NULL.
        $jVin = (db_row("SELECT vin FROM equipment_units WHERE id = ?", [$idJ]) ?? ['vin' => '!NOROW'])['vin'];
        $kVin = (db_row("SELECT vin FROM equipment_units WHERE id = ?", [$idK]) ?? ['vin' => '!NOROW'])['vin'];
        if (($rm['success'] ?? false)
            && ($rm['data']['moved_from_unit_number'] ?? '') === $UTOK . 'J'
            && $jVin === null && $kVin === $VTOK . 'J') {
            $pass('SC8 move_vin: VIN moved ' . $UTOK . 'J → ' . $UTOK . 'K (old holder cleared, no collision)');
        } else {
            $fail('SC8 expected clean move; got resp=' . json_encode($rm) . " J.vin=" . var_export($jVin, true) . " K.vin=" . var_export($kVin, true));
        }
        // SC8b: re-running the move is idempotent (VIN already on K → still succeeds, K keeps it).
        $rm2 = $post('api/v1/equipment/units/move_vin.php', ['vin' => $VTOK . 'J', 'to_unit_id' => $idK]);
        $kVin2 = db_row("SELECT vin FROM equipment_units WHERE id = ?", [$idK])['vin'] ?? '!';
        if (($rm2['success'] ?? false) && $kVin2 === $VTOK . 'J') {
            $pass('SC8b move_vin is idempotent (VIN already on target → still success)');
        } else {
            $fail('SC8b expected idempotent success, got: ' . json_encode($rm2) . " K.vin=" . var_export($kVin2, true));
        }
    } else {
        $fail('SC8 setup failed: J=' . json_encode($rj) . ' K=' . json_encode($rk));
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
