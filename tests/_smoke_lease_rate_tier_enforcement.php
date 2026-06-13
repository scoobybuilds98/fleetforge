<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_rate_tier_enforcement.php
 *
 * D132 — rate-tier completeness must be enforced at EVERY rate-write API, not
 * just lease create. A monthly lease (or a template that seeds one) must never
 * carry a rate-tier hole: when any of daily/weekly/monthly is > 0, ALL must be
 * > 0. The holistic engine prorates partial periods off the daily/weekly tiers,
 * so a zero tier silently yields $0 base_rental + a bogus reconciliation credit
 * (the exact data corruption remediated by
 * scripts/billing_rate_tier_backfill_2026_06_13.php).
 *
 * Coverage map (verified against source):
 *   - leases/create.php  — ALREADY enforced D132 (regression check here).
 *   - leases/update.php  — BLOCKS all rate fields (routes to amend_rate); safe.
 *   - leases/amend_rate.php       — was the live gap (FIXED): now checks the
 *     EFFECTIVE merged tiers.
 *   - equipment/templates/create.php + update.php — were gaps (FIXED): I3.
 *
 * Drives the REAL endpoints via the subprocess POST harness (real db + schema +
 * CSRF + super_admin session). Real writes are removed in finally.
 *
 * PRE-FIX  : amend to a zero tier SUCCEEDS; template create/update with a hole
 *            SUCCEEDS → FAIL.
 * POST-FIX : all rejected 422; complete writes still succeed (non-vacuous).
 *
 * Run:  php tests/_smoke_lease_rate_tier_enforcement.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session D132-RATE-TIER-ENFORCEMENT
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
$skip = static function (string $m): void { echo "  \033[33mSKIP\033[0m — {$m}\n"; };

$PID   = getmypid();
$CTOK  = '_SMOKE_RTIER_' . $PID;          // lease contract token
$TTOK  = '_smoke_rtier_' . $PID;          // template name token

// ── POST harness ─────────────────────────────────────────────────────────────
$harnessFile = sys_get_temp_dir() . '/_ff_rtier_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? '';
\$payload  = base64_decode(\$argv[2] ?? '');
\$sess     = json_decode(base64_decode(\$argv[3] ?? ''), true);
class FfRTInput {
    public \$context; private static string \$buf=''; private int \$pos=0;
    public static function set(string \$s): void { self::\$buf=\$s; }
    public function stream_open(\$p,\$m,\$o,&\$x): bool { \$this->pos=0; return true; }
    public function stream_read(\$c) { \$ch=substr(self::\$buf,\$this->pos,\$c); \$this->pos+=strlen(\$ch); return \$ch; }
    public function stream_eof(): bool { return \$this->pos>=strlen(self::\$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek(\$o,\$w): bool { \$this->pos=\$o; return true; }
    public function stream_tell(): int { return \$this->pos; }
}
FfRTInput::set(\$payload);
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfRTInput');
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

$leaseId = null;
$tplUpdId = null;

$cleanup = static function () use (&$leaseId, &$tplUpdId, $CTOK, $TTOK) {
    if ($leaseId) {
        db_execute("DELETE FROM lease_amendments WHERE lease_id = ?", [$leaseId]);
        db_execute("DELETE FROM audit_log WHERE entity_type IN ('lease','lease_rate_amendment') AND entity_id = ?", [$leaseId]);
        db_execute("DELETE FROM leases WHERE id = ?", [$leaseId]);
    }
    db_execute("DELETE FROM leases WHERE contract_number LIKE ?", [$CTOK . '%']);
    db_execute("DELETE FROM equipment_templates WHERE name LIKE ?", [$TTOK . '%']);
};

try {
    $admin = db_row("SELECT u.id,u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $adminSession = ['id' => (int) $admin['id'], 'name' => 'RTier Smoke', 'role_slug' => 'super_admin'];

    $custId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unitId = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1")['id'] ?? 0);

    $cleanup();

    // Seeded ACTIVE lease with COMPLETE tiers for the amend tests.
    $leaseId = db_insert('leases', [
        'contract_number'        => $CTOK . '-1',
        'customer_id'            => $custId,
        'equipment_unit_id'      => $unitId,
        'unit_number_snapshot'   => 'RTIER',
        'company_name_snapshot'  => 'RTier Co',
        'customer_name_snapshot' => 'RTier',
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

    echo str_repeat('─', 72) . "\n";
    echo "D132 RATE-TIER ENFORCEMENT — lease #{$leaseId}, pid={$PID}\n";
    echo str_repeat('─', 72) . "\n";

    // ── 1. leases/amend_rate.php — amend a tier to 0 (the live gap) ──────────
    $upd = (string) db_row("SELECT updated_at FROM leases WHERE id=?", [$leaseId])['updated_at'];
    $r = $post('api/v1/leases/amend_rate.php', [
        'lease_id'       => $leaseId,
        'updated_at'     => $upd,
        'new_daily_rate' => '0',         // would zero the daily tier → hole
        'reason'         => 'rtier smoke',
    ]);
    $afterDaily = (string) db_row("SELECT daily_rate FROM leases WHERE id=?", [$leaseId])['daily_rate'];
    if (($r['success'] ?? null) === false && bccomp($afterDaily, '100.00', 2) === 0) {
        $pass("1 amend_rate — zeroing daily tier REJECTED (code=" . ($r['error']['code'] ?? '?') . "); lease unchanged");
    } else {
        $fail("1 amend_rate — HOLE OPENED: success=" . json_encode($r['success'] ?? null)
            . " daily_rate now {$afterDaily} (expected 100.00)");
    }

    // ── 2. leases/amend_rate.php non-vacuous — all tiers stay positive ───────
    $upd = (string) db_row("SELECT updated_at FROM leases WHERE id=?", [$leaseId])['updated_at'];
    $r = $post('api/v1/leases/amend_rate.php', [
        'lease_id'         => $leaseId,
        'updated_at'       => $upd,
        'new_monthly_rate' => '2500',
        'reason'           => 'rtier smoke ok',
    ]);
    $afterMonthly = (string) db_row("SELECT monthly_rate FROM leases WHERE id=?", [$leaseId])['monthly_rate'];
    if (($r['success'] ?? null) === true && bccomp($afterMonthly, '2500.00', 2) === 0) {
        $pass("2 amend_rate — complete amendment (all tiers > 0) succeeds; monthly→2500 (non-vacuous)");
    } else {
        $fail("2 amend_rate — complete amendment failed: success=" . json_encode($r['success'] ?? null)
            . " err=" . json_encode($r['error'] ?? null) . " monthly={$afterMonthly}");
    }

    // ── 3. templates/create.php — create with a hole (the gap) ───────────────
    $r = $post('api/v1/equipment/templates/create.php', [
        'name'                 => $TTOK . '-hole',
        'category'             => 'dry_van',
        'default_daily_rate'   => '0',
        'default_weekly_rate'  => '0',
        'default_monthly_rate' => '1000',
        'default_mileage_rate' => '0',
    ]);
    $created = (int) db_row("SELECT COUNT(*) c FROM equipment_templates WHERE name=?", [$TTOK . '-hole'])['c'];
    if (($r['success'] ?? null) === false && $created === 0) {
        $pass("3 template create — rate-tier hole REJECTED (code=" . ($r['error']['code'] ?? '?') . "); nothing created");
    } else {
        $fail("3 template create — HOLE CREATED: success=" . json_encode($r['success'] ?? null) . " rows={$created}");
    }

    // ── 4. templates/create.php non-vacuous — complete tiers ─────────────────
    $r = $post('api/v1/equipment/templates/create.php', [
        'name'                 => $TTOK . '-ok',
        'category'             => 'dry_van',
        'default_daily_rate'   => '120',
        'default_weekly_rate'  => '720',
        'default_monthly_rate' => '2400',
        'default_mileage_rate' => '0',
    ]);
    if (($r['success'] ?? null) === true) {
        $pass("4 template create — complete tiers succeed (non-vacuous)");
    } else {
        $fail("4 template create — complete create failed: " . json_encode($r['error'] ?? $r));
    }

    // ── 5. templates/update.php — edit a complete template into a hole ───────
    $tplUpdId = db_insert('equipment_templates', [
        'name'                 => $TTOK . '-upd',
        'slug'                 => $TTOK . '-upd',
        'category'             => 'dry_van',
        'default_daily_rate'   => '120.00',
        'default_weekly_rate'  => '720.00',
        'default_monthly_rate' => '2400.00',
    ]);
    $upd = (string) db_row("SELECT updated_at FROM equipment_templates WHERE id=?", [$tplUpdId])['updated_at'];
    $r = $post('api/v1/equipment/templates/update.php', [
        'id'                  => $tplUpdId,
        'updated_at'          => $upd,
        'default_weekly_rate' => '0',     // would open a hole (daily+monthly still > 0)
    ]);
    $afterWeekly = (string) db_row("SELECT default_weekly_rate FROM equipment_templates WHERE id=?", [$tplUpdId])['default_weekly_rate'];
    if (($r['success'] ?? null) === false && bccomp($afterWeekly, '720.00', 2) === 0) {
        $pass("5 template update — edit into a hole REJECTED (code=" . ($r['error']['code'] ?? '?') . "); unchanged");
    } else {
        $fail("5 template update — HOLE OPENED: success=" . json_encode($r['success'] ?? null)
            . " weekly now {$afterWeekly} (expected 720.00)");
    }

    // ── 4b. templates/create.php — default_mileage_rate omitted must not 500 ─
    // default_mileage_rate is NOT NULL DEFAULT '0.0000'; omitting it used to
    // insert explicit NULL → 1048 → HTTP 500. Complete tiers, no mileage key.
    $r = $post('api/v1/equipment/templates/create.php', [
        'name'                 => $TTOK . '-nomileage',
        'category'             => 'dry_van',
        'default_daily_rate'   => '120',
        'default_weekly_rate'  => '720',
        'default_monthly_rate' => '2400',
        // default_mileage_rate intentionally omitted
    ]);
    if (($r['success'] ?? null) === true) {
        $pass("4b template create — omitted default_mileage_rate succeeds (no 500; defaults to 0)");
    } else {
        $fail("4b template create — omitted default_mileage_rate failed: " . json_encode($r['error'] ?? $r));
    }

    // ── 6. leases/create.php — regression: existing D132 guard intact ────────
    if ($custId && $unitId) {
        $r = $post('api/v1/leases/create.php', [
            'customer_id'       => $custId,
            'equipment_unit_id' => $unitId,
            'start_date'        => date('Y-m-d'),
            'billing_cycle'     => 'monthly',
            'daily_rate'        => '0',
            'weekly_rate'       => '0',
            'monthly_rate'      => '1000',
        ]);
        $f = $r['error']['fields'] ?? [];
        $tierFlagged = isset($f['daily_rate']) || isset($f['weekly_rate']) || isset($f['monthly_rate']);
        if (($r['success'] ?? null) === false && $tierFlagged) {
            $pass("6 lease create — pre-existing D132 guard intact (rate-tier hole rejected)");
        } else {
            $fail("6 lease create — expected 422 with tier field error; got success="
                . json_encode($r['success'] ?? null) . " fields=" . json_encode(array_keys($f)));
        }
    } else {
        $skip("6 lease create — no customer/unit available to attempt create");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $_SESSION = [];
    if ($tplUpdId) { db_execute("DELETE FROM equipment_templates WHERE id = ?", [$tplUpdId]); }
    $cleanup();
    if (isset($harnessFile) && file_exists($harnessFile)) @unlink($harnessFile);
    echo "  cleaned lease + template smoke rows\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("D132 RATE-TIER ENFORCEMENT — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
