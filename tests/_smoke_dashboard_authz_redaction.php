<?php
declare(strict_types=1);

/**
 * tests/_smoke_dashboard_authz_redaction.php
 *
 * WAVE 5 [Codex CRITICAL] — dashboard APIs bypass role-level data restrictions.
 *
 * api/v1/dashboard/{kpis,charts,tables,activity_feed}.php gate ONLY on
 * require_auth_api() — no per-dataset permission gating. config/permissions.php
 * gives a dispatcher payments=NONE, audit=NONE, and invoices=view documented as
 * "status + dates only — no amounts (enforced in API)". But the dashboard API
 * does no such stripping, so a dispatcher retrieves:
 *   - kpis.active_revenue / monthly_collections / overdue_invoices.total  (money)
 *   - tables.invoices[].total_amount / balance_due                        (money)
 *   - charts revenue datasets                                            (money)
 *   - activity_feed.items  (audit_log rows — dispatcher audit=NONE)      (audit)
 *
 * Proposed contract (documented intent + finding fix sketch):
 *   - money fields require can('payments','view'); stripped/withheld otherwise,
 *     non-sensitive operational cards still returned (endpoint stays 200).
 *   - activity_feed requires can('audit','view'); empty items otherwise.
 *
 * Drives the REAL endpoints via the subprocess HTTP harness with two sessions:
 *   dispatcher (payments=NONE, audit=NONE) vs super_admin (sees everything).
 *
 * PRE-FIX  : dispatcher receives money + audit fields → every dispatcher-side
 *            assertion FAILS (this is the Gate A repro).
 * POST-FIX : dispatcher money/audit stripped; super_admin still sees them.
 *
 * Run:  php tests/_smoke_dashboard_authz_redaction.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-5-DASHBOARD-AUTHZ
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();

// ── Subprocess GET harness with an admin (ff_user) session ──────────────────
$harnessFile = sys_get_temp_dir() . '/_ff_dash_authz_' . $PID . '.php';
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
    // dashboard_kpis (and friends) cache with a ROLE-BLIND key, so clear the
    // dashboard cache before each call to read a freshly-computed payload for
    // THIS role — otherwise a warm cross-role cache masks the per-role result.
    db_execute("DELETE FROM report_cache WHERE report_type LIKE 'dashboard%'");
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg(base64_encode(json_encode($sess))) . ' 2>/dev/null');
    if (!is_string($out)) return ['_raw' => ''];
    $s = strpos($out, '{"'); if ($s !== false) $out = substr($out, $s);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => substr((string) $out, 0, 200)];
};

try {
    $admin = db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id
                      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1");
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }
    $super = ['id' => (int) $admin['id'], 'name' => 'Dash Admin', 'role_slug' => 'super_admin'];

    // Need a REAL user id for the session — dashboard endpoints write
    // report_cache.generated_by (FK → users.id), so an id=0 synthetic session
    // would FK-fail the cache write before json_success. can() reads the
    // *session* permissions, not the DB role, so we attach dispatcher's factory
    // perms (payments/audit NONE) to a valid user id.
    $realUser = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$realUser) { echo "SETUP FAIL no users\n"; exit(2); }
    $disp = [
        'id' => (int) $realUser['id'], 'name' => 'Dash Disp', 'role_slug' => 'dispatcher',
        'permissions' => [
            'payments' => ['view' => 0], 'audit' => ['view' => 0],
            'invoices' => ['view' => 1], 'leases' => ['view' => 1], 'equipment' => ['view' => 1],
        ],
        'permission_overrides' => [], 'role_permission_overrides' => [],
    ];

    echo str_repeat('─', 72) . "\n";
    echo "WAVE 5 [Codex] DASHBOARD AUTHZ — dispatcher must not see money/audit\n";
    echo str_repeat('─', 72) . "\n";

    $data = static fn(array $r): array => $r['data'] ?? [];

    // ── KPIS: active_revenue is a money field (payments:view) ───────────────
    $dK = $data($get('api/v1/dashboard/kpis.php', '', $disp));
    $sK = $data($get('api/v1/dashboard/kpis.php', '', $super));
    $dispHasRev  = array_key_exists('active_revenue', $dK) && $dK['active_revenue'] !== null;
    $superHasRev = array_key_exists('active_revenue', $sK) && $sK['active_revenue'] !== null;
    if (!$dispHasRev && $superHasRev) {
        $pass("kpis — active_revenue withheld from dispatcher, present for super_admin");
    } else {
        $fail("kpis — dispatcher active_revenue=" . var_export($dK['active_revenue'] ?? '(absent)', true)
            . " super=" . var_export($sK['active_revenue'] ?? '(absent)', true) . " (pre-fix: dispatcher leaks it)");
    }

    // ── TABLES: invoices[].total_amount / balance_due are money ─────────────
    $dT = $data($get('api/v1/dashboard/tables.php', '', $disp));
    $sT = $data($get('api/v1/dashboard/tables.php', '', $super));
    $dispInvMoney  = false;
    foreach (($dT['invoices'] ?? []) as $row) {
        if (array_key_exists('total_amount', $row) || array_key_exists('balance_due', $row)) { $dispInvMoney = true; break; }
    }
    $superInvMoney = false;
    foreach (($sT['invoices'] ?? []) as $row) {
        if (array_key_exists('total_amount', $row) || array_key_exists('balance_due', $row)) { $superInvMoney = true; break; }
    }
    if (!$dispInvMoney && $superInvMoney) {
        $pass("tables — invoice total_amount/balance_due stripped for dispatcher, present for super_admin");
    } else {
        $fail("tables — dispatcher_invoice_money=" . ($dispInvMoney ? 'PRESENT' : 'none')
            . " super_invoice_money=" . ($superInvMoney ? 'present' : 'NONE')
            . " (pre-fix: dispatcher leaks invoice amounts; needs seed invoices w/ balance_due>0)");
    }

    // ── ACTIVITY_FEED: audit_log items (audit:view) ─────────────────────────
    $dA = $data($get('api/v1/dashboard/activity_feed.php', '', $disp));
    $sA = $data($get('api/v1/dashboard/activity_feed.php', '', $super));
    $dispItems  = count($dA['items'] ?? []);
    $superItems = count($sA['items'] ?? []);
    if ($dispItems === 0 && $superItems > 0) {
        $pass("activity_feed — withheld from dispatcher (audit=NONE), {$superItems} items for super_admin");
    } else {
        $fail("activity_feed — dispatcher_items={$dispItems} super_items={$superItems} (pre-fix: dispatcher leaks audit feed)");
    }

    // ── CHARTS: revenue dataset is money (payments:view) ────────────────────
    $dC = $data($get('api/v1/dashboard/charts.php', 'chart=revenue_trend', $disp));
    $sC = $data($get('api/v1/dashboard/charts.php', 'chart=revenue_trend', $super));
    $dispHasChart  = !empty($dC) && ($dC['_raw'] ?? null) === null;
    $superHasChart = !empty($sC) && ($sC['_raw'] ?? null) === null;
    if (!$dispHasChart && $superHasChart) {
        $pass("charts — revenue_trend withheld from dispatcher, present for super_admin");
    } else {
        $fail("charts — dispatcher_revenue_chart=" . ($dispHasChart ? 'PRESENT' : 'none')
            . " super=" . ($superHasChart ? 'present' : 'NONE') . " (pre-fix: dispatcher leaks revenue chart)");
    }

} finally {
    if (file_exists($harnessFile)) @unlink($harnessFile);
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("DASHBOARD AUTHZ — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
