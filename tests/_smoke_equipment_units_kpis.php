<?php
/**
 * tests/_smoke_equipment_units_kpis.php
 *
 * S-PERF-3 — the equipment list's four tiles now come from ONE endpoint
 * (api/v1/equipment/units/kpis.php) instead of four
 * `api/v1/equipment/units?status=…&per_page=1` calls. The tiles must show
 * EXACTLY the numbers the old calls produced, for every role, including the
 * edge cases the old calls handled implicitly:
 *   - Total counts EVERY non-deleted unit (reserved / inactive / decommissioned
 *     too) — it was units?per_page=1 with no status filter, and the
 *     utilization % divides by it.
 *   - Soft-deleted units and units on a soft-deleted template are excluded
 *     (units/index.php base WHERE).
 *   - A status with no units comes back as 0 (prod has no maintenance units).
 *
 * HOW: each scenario runs a REAL endpoint in a CLI subprocess that logs a REAL
 * user in via auth_login() (role permissions load exactly as at login) inside
 * an outer transaction that is ROLLED BACK at shutdown — nothing persists in
 * the shared dev DB. The fixture (mode 1) is applied inside that transaction,
 * identically for the old and the new endpoint, so both see the same data.
 *
 *   P  parity, live data: users 54–58 — kpis.{available,on_lease,maintenance,
 *      total} == the four old per_page=1 pagination.total values.
 *   F  parity, fixture (user 55): 2 units → maintenance, 1 → inactive,
 *      1 → reserved, 1 → decommissioned, 1 unit soft-deleted, and one live
 *      template (with its units) soft-deleted. Non-vacuity: the fixture must
 *      actually move maintenance above 0 and change total vs live data.
 *   Z  a status with no units is present as integer 0 (live data has no
 *      maintenance units; asserted when that holds, and in the fixture's
 *      "empty" mode that moves every maintenance unit away).
 *   S  shape: exactly the four integer keys — no money (total_revenue) leaks.
 *   A  auth: no session → 401; Read Only with an equipment.view=false
 *      per-user override → 403 FORBIDDEN; the same user without it → 200.
 *   U  page wiring (static): app/admin/equipment/index.php calls
 *      units/kpis, no longer issues per_page=1 count calls, calls load()
 *      before loadKpis()/loadTemplates() with no await in init(), refreshes
 *      the tiles after both bulk actions, and never adds x-init="init()".
 *
 * USAGE: php tests/_smoke_equipment_units_kpis.php
 * EXIT:  0 = all pass, 1 = any failure, 2 = setup error.
 *
 * @session S-PERF-3
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;

/**
 * Record one assertion.
 *
 * @param string $label  what is being checked
 * @param bool   $ok     outcome
 * @param string $detail shown on failure only
 * @return void
 */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "  \033[32mPASS\033[0m — {$label}\n";
    } else {
        $failures[] = $label;
        echo "  \033[31mFAIL\033[0m — {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

// ── Harness: one GET endpoint as a real user, inside a rolled-back txn ───────
$harnessFile = sys_get_temp_dir() . '/_ff_equip_kpis_harness_' . getmypid() . '.php';
file_put_contents($harnessFile, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
$root     = $argv[1];
$endpoint = $argv[2];                 // e.g. api/v1/equipment/units/kpis.php
$query    = $argv[3];                 // query string
$uid      = (int) $argv[4];           // 0 = no session
$fixMode  = $argv[5];                 // '0' live | '1' mixed fixture | 'empty' no maintenance units
$denyView = $argv[6] === '1';         // per-user override equipment.view = false

ob_start();
require $root . '/config/app.php';
require_once FF_ROOT . '/includes/auth.php';

$pdo = db_pdo();
// Outer transaction FIRST: the fixture and anything login writes roll back.
$pdo->beginTransaction();
$state = ['fixture' => []];

if ($fixMode === '1') {
    // Live units on live templates, deterministic order, so the old and the
    // new endpoint runs mutate exactly the same rows.
    $live = array_map('intval', array_column(db_select(
        "SELECT u.id FROM equipment_units u JOIN equipment_templates t ON t.id = u.template_id
          WHERE u.deleted_at IS NULL AND t.deleted_at IS NULL ORDER BY u.id LIMIT 6"
    ), 'id'));
    $set = static function (int $id, string $status): void {
        db_execute("UPDATE equipment_units SET status = ? WHERE id = ?", [$status, $id]);
    };
    $set($live[0], 'maintenance');
    $set($live[1], 'maintenance');
    $set($live[2], 'inactive');
    $set($live[3], 'reserved');
    $set($live[4], 'decommissioned');
    db_execute("UPDATE equipment_units SET deleted_at = NOW() WHERE id = ?", [$live[5]]);
    // Soft-delete the live template with the FEWEST live units (>0), none of
    // whose units were touched above — so its units must drop from every tile.
    $tpl = db_row(
        "SELECT t.id, COUNT(*) AS n FROM equipment_templates t
           JOIN equipment_units u ON u.template_id = t.id AND u.deleted_at IS NULL
          WHERE t.deleted_at IS NULL
            AND t.id NOT IN (SELECT template_id FROM equipment_units WHERE id IN (" . implode(',', $live) . "))
          GROUP BY t.id ORDER BY n ASC, t.id ASC LIMIT 1"
    );
    if ($tpl) {
        db_execute("UPDATE equipment_templates SET deleted_at = NOW() WHERE id = ?", [(int) $tpl['id']]);
    }
    $state['fixture'] = ['units' => $live, 'template' => $tpl ? (int) $tpl['id'] : null,
                         'template_units' => $tpl ? (int) $tpl['n'] : 0];
} elseif ($fixMode === 'empty') {
    db_execute("UPDATE equipment_units SET status = 'available' WHERE status = 'maintenance'");
}

register_shutdown_function(static function () use ($pdo, &$state) {
    $state['output'] = (string) ob_get_clean();
    $state['http']   = http_response_code();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();                                        // hermetic
    }
    echo "@@STATE@@" . json_encode($state);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['HTTP_ACCEPT']    = 'application/json';
parse_str($query, $_GET);
@session_start();
if ($uid > 0) {
    $user = db_row(
        "SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE u.id = ? AND u.deleted_at IS NULL",
        [$uid]
    );
    if (!$user) { $state['error'] = "user {$uid} missing"; exit; }
    @auth_login($user);
    if ($denyView) {
        // Highest-precedence (per-user) override, the way an admin revokes it.
        $_SESSION['ff_user']['permission_overrides']['equipment']['view'] = false;
    }
}
require $root . '/' . $endpoint;
PHP);
// WHY a shutdown hook as well as the `finally` below: run_endpoint() exit(2)s
// on a harness setup error, and exit() skips `finally` blocks.
register_shutdown_function(static fn () => @unlink($harnessFile));

/**
 * Run one endpoint through the harness.
 *
 * @param string $endpoint repo-relative endpoint path
 * @param string $query    query string
 * @param int    $uid      user to log in as (0 = anonymous)
 * @param string $fix      fixture mode: '0' | '1' | 'empty'
 * @param bool   $deny     apply the equipment.view=false override
 * @return array harness state + 'json' (decoded endpoint body)
 */
function run_endpoint(string $endpoint, string $query, int $uid, string $fix = '0', bool $deny = false): array
{
    global $harnessFile, $ROOT;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' '
        . escapeshellarg($ROOT) . ' ' . escapeshellarg($endpoint) . ' ' . escapeshellarg($query) . ' '
        . (string) $uid . ' ' . escapeshellarg($fix) . ' ' . ($deny ? '1' : '0') . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@STATE@@');
    if ($pos === false) {
        fwrite(STDERR, "harness produced no state for {$endpoint}?{$query} (uid {$uid}):\n{$out}\n");
        exit(2);
    }
    $state = json_decode(substr($out, $pos + 9), true) ?: [];
    if (!empty($state['error'])) {
        fwrite(STDERR, "harness setup error: {$state['error']}\n");
        exit(2);
    }
    $out2 = (string) ($state['output'] ?? '');
    $s    = strpos($out2, '{"');
    $state['json'] = $s !== false ? json_decode(substr($out2, $s), true) : null;
    return $state;
}

/**
 * The four tile numbers exactly as the OLD page computed them: four
 * units?…&per_page=1 calls, pagination.total each, 0 when a call failed.
 *
 * @param int    $uid user id
 * @param string $fix fixture mode
 * @return array{available:int,on_lease:int,maintenance:int,total:int}
 */
function old_tiles(int $uid, string $fix): array
{
    $t = static function (string $q) use ($uid, $fix): int {
        $j = run_endpoint('api/v1/equipment/units/index.php', $q, $uid, $fix)['json'];
        return ($j['success'] ?? false) ? (int) $j['data']['pagination']['total'] : 0;
    };
    return [
        'available'   => $t('status=available&per_page=1'),
        'on_lease'    => $t('status=on_lease&per_page=1'),
        'maintenance' => $t('status=maintenance&per_page=1'),
        'total'       => $t('per_page=1'),
    ];
}

/**
 * The four tile numbers from the NEW endpoint (null when it did not succeed).
 *
 * @param int    $uid user id
 * @param string $fix fixture mode
 * @return array|null decoded data payload
 */
function new_tiles(int $uid, string $fix): ?array
{
    $j = run_endpoint('api/v1/equipment/units/kpis.php', '', $uid, $fix)['json'];
    return ($j['success'] ?? false) ? $j['data'] : null;
}

echo "S-PERF-3 — equipment list tiles: one kpis call == the four old per_page=1 totals\n";
echo str_repeat('=', 78) . "\n\n";

try {
    // ── P: live-data parity for every role ───────────────────────────────────
    echo "P  parity on live data (users 54–58)\n";
    $live = null;
    foreach ([54, 55, 56, 57, 58] as $uid) {
        $old = old_tiles($uid, '0');
        $new = new_tiles($uid, '0');
        check("P.{$uid} kpis == old per_page=1 totals", $new !== null && $new === $old,
            'old=' . json_encode($old) . ' new=' . json_encode($new));
        $live ??= $new;
    }

    // ── S: shape — four ints, nothing else (no money) ────────────────────────
    echo "\nS  payload shape\n";
    check('S.1 exactly available/on_lease/maintenance/total',
        is_array($live) && array_keys($live) === ['available', 'on_lease', 'maintenance', 'total'],
        json_encode($live));
    check('S.2 every value is an integer',
        is_array($live) && count(array_filter($live, 'is_int')) === 4, json_encode($live));
    check('S.3 total >= sum of the three tiled statuses (other statuses counted too)',
        is_array($live) && $live['total'] >= $live['available'] + $live['on_lease'] + $live['maintenance']);

    // ── F: fixture parity (inactive / reserved / decommissioned / deletes) ──
    echo "\nF  parity with a mixed fixture (rolled back)\n";
    $fixState = run_endpoint('api/v1/equipment/units/kpis.php', '', 55, '1');
    $old = old_tiles(55, '1');
    $new = ($fixState['json']['success'] ?? false) ? $fixState['json']['data'] : null;
    check('F.1 kpis == old per_page=1 totals under the fixture', $new !== null && $new === $old,
        'old=' . json_encode($old) . ' new=' . json_encode($new));
    check('F.2 fixture is not vacuous: maintenance > 0', is_array($new) && $new['maintenance'] >= 2,
        json_encode($new));
    $tplUnits = (int) ($fixState['fixture']['template_units'] ?? 0);
    check('F.3 fixture soft-deleted a template that had live units', $tplUnits > 0,
        json_encode($fixState['fixture'] ?? null));
    // Total drops by exactly the soft-deleted unit + the deleted template's units;
    // status moves (maintenance/inactive/reserved/decommissioned) keep them in Total.
    check('F.4 total excludes the soft-deleted unit and the deleted template\'s units',
        is_array($new) && is_array($live) && $new['total'] === $live['total'] - 1 - $tplUnits,
        "live total={$live['total']} fixture total=" . ($new['total'] ?? 'null') . " tpl units={$tplUnits}");

    // ── Z: a status with no units is 0, not missing ──────────────────────────
    echo "\nZ  empty status bucket\n";
    $emptyOld = old_tiles(55, 'empty');
    $emptyNew = new_tiles(55, 'empty');
    check('Z.1 no maintenance units → maintenance is int 0',
        is_array($emptyNew) && array_key_exists('maintenance', $emptyNew) && $emptyNew['maintenance'] === 0,
        json_encode($emptyNew));
    check('Z.2 parity holds with the empty bucket', $emptyNew === $emptyOld,
        'old=' . json_encode($emptyOld) . ' new=' . json_encode($emptyNew));

    // ── A: auth / permission ─────────────────────────────────────────────────
    echo "\nA  auth + permission\n";
    $anon = run_endpoint('api/v1/equipment/units/kpis.php', '', 0);
    check('A.1 no session → 401', ($anon['http'] ?? 0) === 401 && ($anon['json']['success'] ?? true) === false,
        'http=' . ($anon['http'] ?? '?') . ' body=' . substr((string) ($anon['output'] ?? ''), 0, 160));
    $denied = run_endpoint('api/v1/equipment/units/kpis.php', '', 58, '0', true);
    check('A.2 Read Only with equipment.view revoked → 403 FORBIDDEN',
        ($denied['http'] ?? 0) === 403 && ($denied['json']['error']['code'] ?? '') === 'FORBIDDEN',
        'http=' . ($denied['http'] ?? '?') . ' body=' . substr((string) ($denied['output'] ?? ''), 0, 160));
    $deniedOld = run_endpoint('api/v1/equipment/units/index.php', 'per_page=1', 58, '0', true);
    check('A.3 same gate as units/index.php (also 403 for that user)', ($deniedOld['http'] ?? 0) === 403);
    $ro = run_endpoint('api/v1/equipment/units/kpis.php', '', 58);
    check('A.4 Read Only (58) without the override → 200', ($ro['http'] ?? 0) === 200 && ($ro['json']['success'] ?? false) === true);

    // ── U: page wiring (static) ──────────────────────────────────────────────
    echo "\nU  app/admin/equipment/index.php wiring\n";
    $page = (string) file_get_contents($ROOT . '/app/admin/equipment/index.php');
    check('U.1 tiles read api/v1/equipment/units/kpis', str_contains($page, "base_url('api/v1/equipment/units/kpis')"));
    check('U.2 no per_page=1 count calls left', !preg_match("/per_page=1['&]/", $page));
    $initBody = preg_match('/\n\s{8}init\(\)\s*\{(.*?)\n\s{8}\},/s', $page, $m) ? $m[1] : '';
    $pLoad = strpos($initBody, 'this.load()');
    $pKpis = strpos($initBody, 'this.loadKpis()');
    $pTpl  = strpos($initBody, 'this.loadTemplates()');
    check('U.3 init() fires load() first, then loadKpis() and loadTemplates()',
        $initBody !== '' && $pLoad !== false && $pKpis !== false && $pTpl !== false && $pLoad < $pKpis && $pLoad < $pTpl);
    check('U.4 init() has no await (nothing gates the table)', $initBody !== '' && !preg_match('/\\bawait\\s+this\\./', $initBody));
    check('U.5 both bulk actions refresh the tiles',
        substr_count($page, 'await Promise.all([this.load(), this.loadKpis()]);') === 2);
    check('U.6 no x-init="init()" (Alpine auto-init; double-init trap)', !str_contains($page, 'x-init="init()"'));
    check('U.7 loadKpis() catches failures and always leaves the skeleton',
        (bool) preg_match('/async loadKpis\(\)\s*\{\s*try\s*\{.*?\}\s*catch\s*\(e\)\s*\{[^}]*\}\s*this\.kpisLoaded = true;/s', $page));
} finally {
    @unlink($harnessFile);
}

echo "\n" . str_repeat('=', 78) . "\n";
if ($failures) {
    echo "\033[31m" . count($failures) . " FAILED\033[0m, {$passes} passed\n";
    exit(1);
}
echo "\033[32mALL {$passes} PASSED\033[0m\n";
exit(0);
