<?php
/**
 * tests/_smoke_local_date_overdue.php
 *
 * S-SQL-LOCAL-DATE regression — an invoice due TODAY (company-local, Pacific)
 * must not be counted overdue in the evening, when MySQL's UTC day has already
 * rolled over to tomorrow.
 *
 * THE BUG: includes/db.php pins the PDO session to time_zone '+00:00', so SQL
 * CURDATE() is the UTC calendar day. From 5pm Pacific (4pm in winter) CURDATE()
 * is TOMORROW, and every "due_date < CURDATE()" predicate treated invoices due
 * today as one day past due: the Current tile lost them, the 1–30 tile gained
 * them, and the aging drill-down listed them as overdue.
 * THE FIX: business DATE comparisons bind ff_today() (PHP date('Y-m-d') in
 * APP_TIMEZONE) instead of calling CURDATE().
 *
 * HOW THE DATE IS INJECTED: each scenario runs the REAL endpoint in a CLI
 * subprocess whose PDO connection (a) opens an outer transaction and (b) pins
 * the SQL clock with `SET TIMESTAMP` to <today + 1 day> 01:30:00 UTC — i.e.
 * 6:30pm Pacific (5:30pm PST) on the same local day. PHP's own date stays the
 * real local today. Fixture customer + invoices are inserted inside that
 * transaction, the endpoint's JSON is captured, and everything ROLLS BACK —
 * nothing persists in the dev DB, and the result is independent of the wall
 * clock the smoke runs at.
 *
 * Fixture (sent invoices, one customer): DUE_TODAY (due D, $100),
 * DUE_YESTERDAY (due D-1, $200), DUE_IN_5 (due D+5, $400).
 *
 *   C  clock injection is real: SQL CURDATE() = D+1 while PHP ff_today() = D, and
 *      the LEGACY predicate "due_date < CURDATE()" flags 2 fixture invoices
 *      (DUE_TODAY + DUE_YESTERDAY) — proves the smoke is not vacuous.
 *   K  api/v1/invoices/kpis.php (fixture vs no-fixture delta):
 *        current +2 invoices / +$500.00, ar30 +1 / +$200.00.
 *   L  api/v1/invoices/index.php?customer_id=…&aging=current → DUE_TODAY + DUE_IN_5;
 *      &aging=ar30 → DUE_YESTERDAY only.
 *   P  tile ↔ drill-down parity (S-SQL-LOCAL-DATE-FOLLOWUPS). The list's aging filter used
 *      status 'sent' (current) / 'sent','overdue' (ar30/60/90) while the tiles count
 *      'partially_paid' too, so a clicked tile listed fewer invoices than it showed.
 *      The extended fixture adds PART_TODAY (partially_paid, due D, $200 balance),
 *      PART_YESTERDAY (partially_paid, due D-1, $50), OVERDUE_2 (overdue, due D-2,
 *      $25), PART_45 (partially_paid, due D-45, $10), PART_100 (partially_paid,
 *      due D-100, $5). For EVERY bucket: list ids == expected set, list count ==
 *      tile count delta, and list balance_due sum == tile total delta.
 *
 * USAGE: php tests/_smoke_local_date_overdue.php
 * EXIT:  0 = all pass, 1 = any failure, 2 = setup error.
 *
 * @session S-SQL-LOCAL-DATE
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

// ── Harness: runs one GET endpoint with a pinned SQL clock, rolls back ────────
$harnessFile = sys_get_temp_dir() . '/_ff_local_date_harness_' . getmypid() . '.php';
file_put_contents($harnessFile, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
$root      = $argv[1];
$endpoint  = $argv[2];                       // e.g. api/v1/invoices/kpis.php
$query     = $argv[3];                       // query string; {cust} → fixture customer id
$fixMode   = $argv[4];                       // '0' none | '1' base fixture | '2' base + partially_paid/overdue
$withFix   = $fixMode !== '0';

ob_start();
require $root . '/config/app.php';
require_once FF_ROOT . '/includes/auth.php';

$pdo   = db_pdo();
$today = ff_today();                                            // company-local D
$pin   = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d') . ' 01:30:00';

// Outer transaction FIRST so nothing the endpoint (or the fixture) writes survives.
$pdo->beginTransaction();
// Pin NOW()/CURDATE() for this session to D+1 01:30 UTC = the evening of D in Pacific.
$pdo->exec('SET TIMESTAMP = UNIX_TIMESTAMP(' . $pdo->quote($pin) . ')');

$state = ['today' => $today, 'pin' => $pin];
$state['sql_curdate'] = db_row('SELECT CURDATE() AS d')['d'];

$custId = 0;
if ($withFix) {
    $tag    = 'SMOKE-LOCALDATE-' . getmypid();
    $custId = db_insert('customers', [
        'company_name' => $tag . ' Co', 'contact_name' => 'Local Date Smoke',
        'province' => 'BC', 'currency' => 'CAD', 'outstanding_balance' => '700.00',
    ]);
    $mk = static function (string $suffix, string $due, string $amt, string $status = 'sent', string $paid = '0.00') use ($custId, $tag, $today): int {
        return db_insert('invoices', [
            'invoice_number' => $tag . '-' . $suffix, 'customer_id' => $custId, 'lease_id' => null,
            'company_name_snapshot' => $tag . ' Co',
            'billing_period_start' => $today, 'billing_period_end' => $today, 'billing_period_days' => 1,
            'billing_type' => 'single_period', 'invoice_date' => $today, 'due_date' => $due,
            'sent_date' => $today, 'status' => $status, 'currency' => 'CAD',
            'subtotal' => $amt, 'total_amount' => $amt, 'amount_paid' => $paid,
            'credits_applied' => '0.00', 'balance_due' => bcsub($amt, $paid, 2),
        ]);
    };
    $d = new DateTimeImmutable($today);
    $state['ids'] = [
        'due_today'     => $mk('TODAY', $today, '100.00'),
        'due_yesterday' => $mk('YESTERDAY', $d->modify('-1 day')->format('Y-m-d'), '200.00'),
        'due_in_5'      => $mk('IN5', $d->modify('+5 days')->format('Y-m-d'), '400.00'),
    ];
    if ($fixMode === '2') {
        // Outstanding invoices the tiles count but the old drill-down dropped.
        $state['ids'] += [
            'part_today'     => $mk('PART-TODAY', $today, '300.00', 'partially_paid', '100.00'),
            'part_yesterday' => $mk('PART-YESTERDAY', $d->modify('-1 day')->format('Y-m-d'), '500.00', 'partially_paid', '450.00'),
            'overdue_2'      => $mk('OVERDUE-2', $d->modify('-2 days')->format('Y-m-d'), '25.00', 'overdue'),
            'part_45'        => $mk('PART-45', $d->modify('-45 days')->format('Y-m-d'), '30.00', 'partially_paid', '20.00'),
            'part_100'       => $mk('PART-100', $d->modify('-100 days')->format('Y-m-d'), '15.00', 'partially_paid', '10.00'),
        ];
    }
    // The pre-fix predicate, evaluated under the pinned clock (non-vacuity proof).
    // Scoped to the three 'sent' base invoices so the extended fixture doesn't shift it.
    $state['legacy_overdue_ids'] = array_map('intval', array_column(db_select(
        "SELECT id FROM invoices WHERE customer_id = ? AND status = 'sent' AND due_date < CURDATE() ORDER BY id", [$custId]
    ), 'id'));
}

register_shutdown_function(static function () use ($pdo, &$state) {
    $state['output'] = (string) ob_get_clean();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();                                        // hermetic
    }
    echo "@@STATE@@" . json_encode($state);
});

// Request context + a real super_admin session (inside the rolled-back txn).
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
parse_str(str_replace('{cust}', (string) $custId, $query), $_GET);
$user = db_row(
    "SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1"
);
auth_login($user);
require $root . '/' . $endpoint;
PHP);

/**
 * Run one endpoint scenario through the harness.
 *
 * @param string $endpoint repo-relative endpoint path
 * @param string $query    query string ({cust} is replaced with the fixture customer id)
 * @param bool|string $fixture false/true = none/base fixture; '2' = base + partially_paid/overdue
 * @return array decoded harness state (+ 'json' = decoded endpoint body)
 */
function run_scenario(string $endpoint, string $query, bool|string $fixture): array
{
    global $harnessFile, $ROOT;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' '
        . escapeshellarg($ROOT) . ' ' . escapeshellarg($endpoint) . ' '
        . escapeshellarg($query) . ' ' . (is_string($fixture) ? $fixture : ($fixture ? '1' : '0')) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@STATE@@');
    if ($pos === false) {
        fwrite(STDERR, "harness produced no state for {$endpoint}?{$query}:\n{$out}\n");
        exit(2);
    }
    $state = json_decode(substr($out, $pos + 9), true) ?: [];
    $state['json'] = json_decode((string) ($state['output'] ?? ''), true);
    return $state;
}

echo "S-SQL-LOCAL-DATE — invoices due today stay current after the UTC day rolls over\n";
echo str_repeat('=', 78) . "\n\n";

try {
    // ── C + K: KPI tiles, fixture vs no fixture ──────────────────────────────
    $base = run_scenario('api/v1/invoices/kpis.php', '', false);
    $fix  = run_scenario('api/v1/invoices/kpis.php', '', true);

    $d1 = (new DateTimeImmutable($fix['today']))->modify('+1 day')->format('Y-m-d');
    check('C.1 SQL clock pinned past the UTC rollover (CURDATE() = local today + 1)',
        ($fix['sql_curdate'] ?? '') === $d1, "sql_curdate={$fix['sql_curdate']} today={$fix['today']}");
    $ids = $fix['ids'] ?? [];
    $legacy = $fix['legacy_overdue_ids'] ?? [];
    sort($legacy);
    $expectLegacy = [(int) ($ids['due_today'] ?? 0), (int) ($ids['due_yesterday'] ?? 0)];
    sort($expectLegacy);
    check('C.2 legacy "due_date < CURDATE()" flags the due-TODAY invoice as overdue under this clock (non-vacuous)',
        $legacy === $expectLegacy, 'legacy=' . json_encode($legacy) . ' expected=' . json_encode($expectLegacy));

    $kb = $base['json']['data'] ?? null;
    $kf = $fix['json']['data'] ?? null;
    check('K.0 kpis endpoint returned success both runs', is_array($kb) && is_array($kf),
        substr((string) ($fix['output'] ?? ''), 0, 300));
    if (is_array($kb) && is_array($kf)) {
        $delta = static fn(string $k): string => bcsub((string) $kf[$k], (string) $kb[$k], 2);
        check('K.1 Current tile gains BOTH not-yet-due invoices (due today + due in 5): +2',
            ((int) $kf['current_cnt'] - (int) $kb['current_cnt']) === 2,
            "current_cnt {$kb['current_cnt']} → {$kf['current_cnt']}");
        check('K.2 Current total +$500.00', bccomp($delta('current_total'), '500.00', 2) === 0, 'delta=' . $delta('current_total'));
        check('K.3 1–30 days tile gains ONLY the invoice due yesterday: +1',
            ((int) $kf['ar30_cnt'] - (int) $kb['ar30_cnt']) === 1,
            "ar30_cnt {$kb['ar30_cnt']} → {$kf['ar30_cnt']}");
        check('K.4 1–30 days total +$200.00', bccomp($delta('ar30_total'), '200.00', 2) === 0, 'delta=' . $delta('ar30_total'));
    }

    // ── L: aging drill-down list, scoped to the fixture customer ─────────────
    $listIds = static function (array $state): array {
        $items = $state['json']['data']['items'] ?? null;
        if (!is_array($items)) return ['__error__'];
        $ids = array_map(static fn($r) => (int) $r['id'], $items);
        sort($ids);
        return $ids;
    };
    $cur = run_scenario('api/v1/invoices/index.php', 'customer_id={cust}&aging=current&per_page=100', true);
    $expCur = [(int) $cur['ids']['due_today'], (int) $cur['ids']['due_in_5']];
    sort($expCur);
    check('L.1 aging=current lists the due-today and due-in-5 invoices',
        $listIds($cur) === $expCur, 'got=' . json_encode($listIds($cur)) . ' expected=' . json_encode($expCur)
        . ' body=' . substr((string) ($cur['output'] ?? ''), 0, 200));

    $a30 = run_scenario('api/v1/invoices/index.php', 'customer_id={cust}&aging=ar30&per_page=100', true);
    check('L.2 aging=ar30 lists ONLY the invoice due yesterday',
        $listIds($a30) === [(int) $a30['ids']['due_yesterday']],
        'got=' . json_encode($listIds($a30)) . ' expected=' . json_encode([(int) $a30['ids']['due_yesterday']]));

    // ── P: tile ↔ drill-down parity incl. partially_paid + overdue ───────────
    // One kpis run with the extended fixture; its delta over $base (no fixture) is
    // what the tiles show for this customer. Each bucket's list must show exactly
    // those invoices. Fixture ids differ per subprocess, so sets are compared by
    // invoice-number suffix.
    $kx = run_scenario('api/v1/invoices/kpis.php', '', '2')['json']['data'] ?? null;
    check('P.0 kpis endpoint returned success with the extended fixture', is_array($kx) && is_array($kb));
    $expected = [
        'current' => ['IN5', 'PART-TODAY', 'TODAY'],
        'ar30'    => ['OVERDUE-2', 'PART-YESTERDAY', 'YESTERDAY'],
        'ar60'    => ['PART-45'],
        'ar90'    => ['PART-100'],
    ];
    foreach ($expected as $bucket => $suffixes) {
        $run   = run_scenario('api/v1/invoices/index.php', "customer_id={cust}&aging={$bucket}&per_page=100", '2');
        $items = $run['json']['data']['items'] ?? null;
        $got   = [];
        $sum   = '0.00';
        foreach (is_array($items) ? $items : [] as $r) {
            $got[] = preg_replace('/^SMOKE-LOCALDATE-\d+-/', '', (string) $r['invoice_number']);
            $sum   = bcadd($sum, (string) $r['balance_due'], 2);
        }
        sort($got);
        check("P.{$bucket}.1 aging={$bucket} lists " . implode(' + ', $suffixes),
            $got === $suffixes, 'got=' . json_encode($got) . ' body=' . substr((string) ($run['output'] ?? ''), 0, 200));
        if (is_array($kx) && is_array($kb)) {
            $cntDelta = (int) $kx["{$bucket}_cnt"] - (int) $kb["{$bucket}_cnt"];
            $totDelta = bcsub((string) $kx["{$bucket}_total"], (string) $kb["{$bucket}_total"], 2);
            check("P.{$bucket}.2 list count == tile count delta ({$cntDelta})",
                count($got) === $cntDelta, 'list=' . count($got) . " tile_delta={$cntDelta}");
            check("P.{$bucket}.3 list balance_due sum == tile total delta ({$totDelta})",
                bccomp($sum, $totDelta, 2) === 0, "list_sum={$sum} tile_delta={$totDelta}");
        }
    }
} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $failures[] = 'exception';
} finally {
    @unlink($harnessFile);
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%s — %d passed, %d failed\n", $failures ? 'FAILURES' : 'ALL PASS', $passes, count($failures));
exit($failures ? 1 : 0);
