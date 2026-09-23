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
 * S-DASH-CHART-REDACT: top_customers (YTD revenue per customer) and
 * weekly_heatmap (daily revenue totals) are dollar datasets that were missing
 * from charts.php's $moneyCharts. The chart section now covers EVERY money
 * chart, for REAL users logged in through the app's own auth_login() (so
 * config/permissions.php is exercised, not a hand-built permission map):
 *   - dispatcher   combined payload omits all 6 money charts, keeps the 6
 *                  operational ones; ?chart=top_customers / weekly_heatmap → 403
 *   - accountant   combined payload carries all 12; both single charts → 200
 *   - super_admin  same as accountant
 * super_admin runs FIRST against a cleared cache so the dispatcher is served
 * from the cache it warmed — proves the redaction is serve-time, not a
 * side-effect of a per-role rebuild.
 *
 * S-DASHBOARD-VIZ: three more money charts (cash_flow, receivables,
 * overdue_customers) and two operational ones (fleet_mix, lease_flow). The
 * lists below mirror charts.php again (9 money / 8 operational / 17 total),
 * and the single-chart 403/200 probes cover the three new money charts too.
 * Their datasets are not ApexCharts-shaped, so the 200 probe checks each
 * chart's own top-level field instead of `series`.
 *
 * Run:  php tests/_smoke_dashboard_authz_redaction.php   Exit 0/1 (2 setup).
 *
 * @session WAVE-5-DASHBOARD-AUTHZ, S-DASH-CHART-REDACT, S-DASHBOARD-VIZ
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

// ── Real-login GET harness (S-DASH-CHART-REDACT) ────────────────────────────
// Logs a REAL user in via auth_login() (role perms + DB overrides load exactly
// as at login; no DB writes without remember-me), then drives the endpoint.
// The first output line reports can_view_financials() so the caller asserts
// the role precondition before trusting any redaction result. Does NOT clear
// report_cache — the caller controls cache warmth on purpose.
$loginHarnessFile = sys_get_temp_dir() . '/_ff_dash_authz_login_' . $PID . '.php';
file_put_contents($loginHarnessFile, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$qs=\$argv[2]??''; \$uid=(int)(\$argv[3]??0);
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
require '{$ROOT}/config/app.php';
require_once FF_ROOT . '/includes/auth.php';
@session_start();
\$u = db_row("SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = ?", [\$uid]);
if (!\$u) { echo "FFSMOKE-FIN=missing-user\n"; exit; }
@auth_login(\$u);
echo 'FFSMOKE-FIN=' . (can_view_financials() ? '1' : '0') . "\n";
require '{$ROOT}/' . \$endpoint;
PHP);
$getAs = static function (string $endpoint, string $qs, int $uid) use ($loginHarnessFile): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($loginHarnessFile) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg((string) $uid) . ' 2>/dev/null');
    $fin = preg_match('/^FFSMOKE-FIN=(\S+)/m', $out, $m) ? $m[1] : 'none';
    $s = strpos($out, '{"');
    $j = $s !== false ? json_decode(trim(substr($out, $s)), true) : null;
    return ['fin' => $fin, 'resp' => is_array($j) ? $j : ['_raw' => substr($out, 0, 200)]];
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

    // ── CHARTS, every money chart, real logins (S-DASH-CHART-REDACT) ────────
    // Mirrors charts.php's $moneyCharts; the operational list is the rest of
    // its $allowedCharts. top_customers / weekly_heatmap were added by
    // S-DASH-CHART-REDACT; cash_flow / receivables / overdue_customers (money)
    // and fleet_mix / lease_flow (operational) by S-DASHBOARD-VIZ.
    $moneyCharts = ['revenue_trend', 'ar_aging', 'revenue_by_type', 'revenue_forecast', 'top_customers', 'weekly_heatmap',
                    'cash_flow', 'receivables', 'overdue_customers'];
    $opsCharts   = ['fleet_status', 'leases_trend', 'utilization_trend', 'lease_expiry_calendar', 'occupancy_by_type', 'payment_speed',
                    'fleet_mix', 'lease_flow'];
    // Single-chart probes: chart key => the top-level field a served dataset must carry.
    $newCharts   = ['top_customers' => 'series', 'weekly_heatmap' => 'series',
                    'cash_flow' => 'billed', 'receivables' => 'buckets', 'overdue_customers' => 'rows'];
    $nMoney = count($moneyCharts);
    $nOps   = count($opsCharts);

    // Prefer the dedicated test fixture per role (test-dispatcher, not the
    // override-grant dispatcher); the FIN precondition catches a mis-pick.
    $roleUser = static function (string $slug, string $email): ?int {
        $r = db_row("SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
                      WHERE r.slug = ? AND u.deleted_at IS NULL AND u.status = 'active'
                      ORDER BY (u.email = ?) DESC, u.id LIMIT 1", [$slug, $email]);
        return $r ? (int) $r['id'] : null;
    };
    // super_admin FIRST: it warms the role-blind cache the dispatcher then reads.
    $roles = [
        'super_admin' => ['uid' => $roleUser('super_admin', 'test-superadmin@fleetforge.test'), 'fin' => '1'],
        'dispatcher'  => ['uid' => $roleUser('dispatcher',  'test-dispatcher@fleetforge.test'), 'fin' => '0'],
        'accountant'  => ['uid' => $roleUser('accountant',  'test-accountant@fleetforge.test'), 'fin' => '1'],
    ];
    foreach ($roles as $slug => $r) {
        if ($r['uid'] === null) { echo "SETUP FAIL no active {$slug} user\n"; exit(2); }
    }

    db_execute("DELETE FROM report_cache WHERE report_type LIKE 'dashboard_chart_%'");

    foreach ($roles as $slug => $r) {
        $uid = $r['uid'];
        $all = $getAs('api/v1/dashboard/charts.php', '', $uid);
        if ($all['fin'] !== $r['fin']) {
            $fail("charts[{$slug} #{$uid}] — precondition: can_view_financials()={$all['fin']}, expected {$r['fin']}; redaction result untrustworthy");
            continue;
        }
        $keys = array_keys(is_array($all['resp']['data'] ?? null) ? $all['resp']['data'] : []);

        if ($r['fin'] === '0') {
            // Combined payload: every money chart gone, operational charts intact (still 200).
            $leaked  = array_values(array_intersect($moneyCharts, $keys));
            $lostOps = array_values(array_diff($opsCharts, $keys));
            if (($all['resp']['success'] ?? false) === true && !$leaked && !$lostOps) {
                $pass("charts[{$slug}] combined — all {$nMoney} money charts omitted (incl. top_customers, weekly_heatmap, cash_flow, receivables, overdue_customers), {$nOps} operational charts served");
            } else {
                $fail("charts[{$slug}] combined — leaked=[" . implode(',', $leaked) . "] missing_ops=[" . implode(',', $lostOps) . "]"
                    . " success=" . var_export($all['resp']['success'] ?? null, true));
            }
            // Single-chart request: 403 FORBIDDEN, no dataset in the body.
            foreach (array_keys($newCharts) as $ck) {
                $one  = $getAs('api/v1/dashboard/charts.php', 'chart=' . $ck, $uid)['resp'];
                $code = $one['error']['code'] ?? null;
                if (($one['success'] ?? null) === false && $code === 'FORBIDDEN' && !isset($one['data'])) {
                    $pass("charts[{$slug}] ?chart={$ck} — 403 FORBIDDEN, no dataset");
                } else {
                    $fail("charts[{$slug}] ?chart={$ck} — expected 403 FORBIDDEN, got success=" . var_export($one['success'] ?? null, true)
                        . " code=" . var_export($code, true) . (isset($one['data']) ? ' WITH dataset (leak)' : ''));
                }
            }
        } else {
            $missing = array_values(array_diff(array_merge($moneyCharts, $opsCharts), $keys));
            if (($all['resp']['success'] ?? false) === true && !$missing) {
                $d = $all['resp']['data'];
                $pass("charts[{$slug}] combined — all " . ($nMoney + $nOps) . " charts served (top_customers " . count($d['top_customers']['labels'] ?? [])
                    . " customers, weekly_heatmap " . count($d['weekly_heatmap']['series'] ?? []) . " weeks, receivables "
                    . count($d['receivables']['buckets'] ?? []) . " buckets)");
            } else {
                $fail("charts[{$slug}] combined — missing=[" . implode(',', $missing) . "] success="
                    . var_export($all['resp']['success'] ?? null, true));
            }
            foreach ($newCharts as $ck => $field) {
                $one = $getAs('api/v1/dashboard/charts.php', 'chart=' . $ck, $uid)['resp'];
                if (($one['success'] ?? null) === true && is_array($one['data'][$field] ?? null)) {
                    $pass("charts[{$slug}] ?chart={$ck} — 200 with dataset");
                } else {
                    $fail("charts[{$slug}] ?chart={$ck} — expected 200 dataset, got success=" . var_export($one['success'] ?? null, true)
                        . " code=" . var_export($one['error']['code'] ?? null, true));
                }
            }
        }
    }

} finally {
    if (file_exists($harnessFile)) @unlink($harnessFile);
    if (file_exists($loginHarnessFile)) @unlink($loginHarnessFile);
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("DASHBOARD AUTHZ — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
