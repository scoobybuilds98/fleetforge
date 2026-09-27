<?php
/**
 * tests/_smoke_dashboard_viz_data.php
 *
 * S-DASHBOARD-VIZ — the new dashboard datasets the redesigned dashboard reads.
 *
 *   api/v1/dashboard/charts.php
 *     cash_flow          (MONEY) billed vs collected, rolling 12 months, CAD
 *     receivables        (MONEY) ArAging::asOf(ff_today()) total + 5 buckets w/ counts
 *     overdue_customers  (MONEY) top 6 customers by overdue balance
 *     fleet_mix          unit counts by status + per-category split
 *     lease_flow         leases opened / closed / on rent per month
 *     top_customers      + ytd_total (all customers, same filters)
 *     ?charts=a,b,c      subset mode (exact keys; money omitted for non-financial
 *                        roles; unknown/empty list → 422; ?chart= still wins)
 *   api/v1/dashboard/tables.php
 *     idle_units         up to 8 AVAILABLE units idle longest (no money)
 *
 * Checks:
 *   CF  cash_flow shape (12 labels = the rolling months), every month's billed
 *       and collected == an independent SQL sum, this_month/prev_month == the
 *       last two entries
 *   RV  receivables shape; total == Σ bucket amounts == ArAging grand total;
 *       every bucket == ArAging's; Σ counts == invoice_count == ArAging's
 *   OC  overdue_customers ≤6 rows, sorted desc, amounts > 0; total_overdue ≥
 *       Σ rows and == receivables' four late buckets == an independent
 *       per-invoice bcmath sum over the canonical overdue predicate
 *       (sent/partially_paid/overdue, balance_due > 0, due_date < ff_today());
 *       each row == that customer's independent figures, rows == top 6
 *   FM  fleet_mix total == Σ statuses == SQL fleet count; each status == SQL;
 *       per type on_lease+available+other == total; column sums tie; sorted
 *   LF  lease_flow 12-length non-negative int arrays; EVERY month's opened,
 *       closed and on_rent == an independent SQL count (current month on_rent
 *       as of ff_today())
 *   TC  top_customers ytd_total ≥ Σ series and == independent total
 *   IU  idle_units ≤8, every unit available + not deleted, sorted by
 *       idle_days desc, idle_days == today − idle_since, rows == an
 *       independent recompute from every lease of every available unit,
 *       no money-named key
 *   RG  role gate with REAL logins (auth_login, config/permissions.php):
 *       dispatcher combined payload omits the 3 new money charts and keeps
 *       fleet_mix/lease_flow; ?chart=<money> → 403 FORBIDDEN with no dataset;
 *       accountant gets all 5 (combined + single)
 *   CL  ?charts= subset: exact requested keys (super_admin, accountant);
 *       dispatcher gets the requested keys minus the money ones (money-only
 *       list → data:{}); unknown key and empty list → 422 VALIDATION_ERROR;
 *       ?chart= wins over ?charts=; no-param mode still returns all 17 keys
 *   MW  (S-PERF-3) the four month-bucketed charts: labels are the exact
 *       distinct consecutive months anchored on ff_today()'s month
 *       (revenue_forecast next 6, lease_expiry_calendar next 12,
 *       payment_speed + leases_trend rolling 12), and EVERY month's value ==
 *       an independent per-month SQL recompute (the pre-S-PERF-3 one-query-
 *       per-month form): forecast via the bcmath rate ladder, expiries by
 *       end_date, payment speed by paid_date (null for an empty month),
 *       leases opened/closed by created_at/updated_at
 *
 * HERMETIC: every endpoint call runs in a CLI subprocess that opens an outer
 * transaction FIRST, deletes the report_cache rows for the five new chart keys
 * and the four month-bucketed ones (so they build fresh), logs a real user in, runs the endpoint and ROLLS
 * BACK in a shutdown function — the cache writes the endpoint makes do not
 * persist. The parent process only reads.
 *
 * USAGE: php tests/_smoke_dashboard_viz_data.php
 * EXIT:  0 = all pass, 1 = any failure, 2 = setup error.
 *
 * @session S-DASHBOARD-VIZ, S-PERF-3
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

/** True for a JSON number (int or float). */
function is_num(mixed $v): bool
{
    return is_int($v) || is_float($v);
}

/** A JSON number as a 2dp string (for exact money comparison with SQL/bcmath). */
function m2(mixed $v): string
{
    return is_num($v) ? sprintf('%.2f', (float) $v) : 'NaN';
}

/** Keys of an array, sorted — for exact key-set comparison. */
function keyset(mixed $a): array
{
    $k = is_array($a) ? array_map('strval', array_keys($a)) : ['__not_array__'];
    sort($k);
    return $k;
}

/** Sorted copy of a list of strings. */
function sorted(array $a): array
{
    sort($a);
    return $a;
}

const NEW_KEYS    = ['cash_flow', 'receivables', 'overdue_customers', 'fleet_mix', 'lease_flow'];
const NEW_MONEY   = ['cash_flow', 'receivables', 'overdue_customers'];
// charts.php $moneyCharts — used to predict what a dispatcher's ?charts= subset keeps.
const ALL_MONEY   = ['revenue_trend', 'ar_aging', 'revenue_by_type', 'revenue_forecast', 'top_customers',
                     'weekly_heatmap', 'cash_flow', 'receivables', 'overdue_customers'];
const ALL_CHARTS  = ['revenue_trend', 'fleet_status', 'ar_aging', 'top_customers', 'leases_trend',
                     'utilization_trend', 'revenue_by_type', 'weekly_heatmap', 'revenue_forecast',
                     'lease_expiry_calendar', 'occupancy_by_type', 'payment_speed',
                     'cash_flow', 'receivables', 'overdue_customers', 'fleet_mix', 'lease_flow'];
// The exact list the redesigned dashboard requests.
const DASH_SUBSET = ['cash_flow', 'receivables', 'overdue_customers', 'revenue_forecast', 'payment_speed',
                     'lease_flow', 'lease_expiry_calendar', 'fleet_mix', 'utilization_trend',
                     'top_customers', 'revenue_by_type'];

// ── Harness: real login, outer txn, fresh new-key builds, rolled back ─────────
$harnessFile = sys_get_temp_dir() . '/_ff_dash_viz_harness_' . getmypid() . '.php';
file_put_contents($harnessFile, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
$root     = $argv[1];
$endpoint = $argv[2];                 // e.g. api/v1/dashboard/charts.php
$query    = $argv[3];                 // query string
$uid      = (int) $argv[4];           // real user id to log in as

ob_start();
require $root . '/config/app.php';
require_once FF_ROOT . '/includes/auth.php';

$pdo   = db_pdo();
$state = ['uid' => $uid];

// Outer transaction FIRST so neither the cache purge below nor the endpoint's
// own report_cache REPLACE survives (json_error() also rolls it back).
$pdo->beginTransaction();
register_shutdown_function(static function () use ($pdo, &$state) {
    $state['output'] = (string) ob_get_clean();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();                                        // hermetic
    }
    echo '@@STATE@@' . json_encode($state);
});

// Fresh builds for the S-DASHBOARD-VIZ keys and (S-PERF-3) the four
// month-bucketed charts the MW checks recompute (inside the rolled-back txn).
db_execute(
    "DELETE FROM report_cache WHERE report_type IN
        ('dashboard_chart_cash_flow','dashboard_chart_receivables','dashboard_chart_overdue_customers',
         'dashboard_chart_fleet_mix','dashboard_chart_lease_flow',
         'dashboard_chart_revenue_forecast','dashboard_chart_lease_expiry_calendar',
         'dashboard_chart_payment_speed','dashboard_chart_leases_trend')"
);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
parse_str($query, $_GET);
@session_start();
$u = db_row("SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = ?", [$uid]);
if (!$u) { $state['error'] = 'missing-user'; exit; }
@auth_login($u);
$state['fin']  = can_view_financials() ? 1 : 0;
$state['role'] = $u['role_slug'];
require $root . '/' . $endpoint;
PHP);

/**
 * Run one endpoint as one user through the harness.
 *
 * @param string $endpoint repo-relative endpoint path
 * @param string $query    query string
 * @param int    $uid      user id
 * @return array{fin:?int, role:?string, json:?array, output:string}
 */
function call_as(string $endpoint, string $query, int $uid): array
{
    global $harnessFile, $ROOT;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' '
        . escapeshellarg($ROOT) . ' ' . escapeshellarg($endpoint) . ' '
        . escapeshellarg($query) . ' ' . escapeshellarg((string) $uid) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@STATE@@');
    if ($pos === false) {
        fwrite(STDERR, "harness produced no state for {$endpoint}?{$query}:\n" . substr($out, 0, 800) . "\n");
        exit(2);
    }
    $state = json_decode(substr($out, $pos + 9), true) ?: [];
    $body  = (string) ($state['output'] ?? '');
    $s     = strpos($body, '{"');
    return [
        'fin'    => $state['fin'] ?? null,
        'role'   => $state['role'] ?? null,
        'json'   => $s !== false ? json_decode(substr($body, $s), true) : null,
        'output' => $body,
    ];
}

/**
 * Active user of a role, preferring the dedicated test fixture account.
 *
 * @param string $slug  role slug
 * @param string $email preferred fixture email
 * @return int|null
 */
function role_user(string $slug, string $email): ?int
{
    $r = db_row(
        "SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = ? AND u.deleted_at IS NULL AND u.status = 'active'
          ORDER BY (u.email = ?) DESC, u.id LIMIT 1",
        [$slug, $email]
    );
    return $r ? (int) $r['id'] : null;
}

/** USD → own frozen rate (missing / ≤0 → 1.0, the ArAging rule), bcmath scale 8. */
function cad8(string $amount, ?string $currency, ?string $rate): string
{
    $r = ($currency === 'USD' && $rate !== null && bccomp($rate, '0', 6) > 0) ? $rate : '1';
    return bcmul($amount, $r, 8);
}

echo "S-DASHBOARD-VIZ — new dashboard datasets (charts.php + tables.php)\n";
echo str_repeat('=', 78) . "\n";

try {
    $users = [
        'super_admin' => role_user('super_admin', 'test-superadmin@fleetforge.test'),
        'accountant'  => role_user('accountant',  'test-accountant@fleetforge.test'),
        'dispatcher'  => role_user('dispatcher',  'test-dispatcher@fleetforge.test'),
    ];
    foreach ($users as $slug => $uid) {
        if ($uid === null) {
            echo "SETUP FAIL — no active {$slug} user\n";
            exit(2);
        }
    }

    $today = ff_today();
    $year  = substr($today, 0, 4);
    // Independent rolling-12 months (oldest first), built differently from the
    // endpoint: step back from the current month's 1st with 'first day of'.
    $expMonths = [];
    for ($i = 11; $i >= 0; $i--) {
        $d = (new DateTimeImmutable($today))->modify('first day of this month')->modify("-{$i} months");
        $expMonths[] = ['label' => $d->format('M Y'), 'start' => $d->format('Y-m-d'), 'end' => $d->format('Y-m-t')];
    }
    $expLabels = array_column($expMonths, 'label');
    echo "  (company-local today = {$today}; window {$expMonths[0]['start']} … {$expMonths[11]['end']})\n\n";

    // ── Primary payload: super_admin, no params (all charts) ─────────────────
    $all = call_as('api/v1/dashboard/charts.php', '', $users['super_admin']);
    check('S.0 super_admin session has financial access (precondition)', $all['fin'] === 1, 'fin=' . var_export($all['fin'], true));
    $D = $all['json']['data'] ?? null;
    check('S.1 charts.php (no params) succeeds and still returns all 17 keys',
        ($all['json']['success'] ?? null) === true && keyset($D) === sorted(ALL_CHARTS),
        'keys=' . json_encode(keyset($D)) . ' body=' . substr($all['output'], 0, 300));
    if (!is_array($D)) {
        throw new RuntimeException('no charts payload — cannot continue');
    }

    // ── CF: cash_flow ────────────────────────────────────────────────────────
    echo "\nCF cash_flow\n";
    $cf = $D['cash_flow'] ?? null;
    $cfShape = is_array($cf)
        && keyset($cf) === sorted(['labels', 'billed', 'collected', 'this_month', 'prev_month'])
        && ($cf['labels'] ?? null) === $expLabels
        && is_array($cf['billed']) && count($cf['billed']) === 12 && array_is_list($cf['billed'])
        && is_array($cf['collected']) && count($cf['collected']) === 12 && array_is_list($cf['collected'])
        && count(array_filter($cf['billed'], 'is_num')) === 12
        && count(array_filter($cf['collected'], 'is_num')) === 12
        && keyset($cf['this_month']) === ['billed', 'collected'] && keyset($cf['prev_month']) === ['billed', 'collected']
        && is_num($cf['this_month']['billed']) && is_num($cf['this_month']['collected'])
        && is_num($cf['prev_month']['billed']) && is_num($cf['prev_month']['collected']);
    check('CF.1 shape: labels = the 12 rolling months (' . $expLabels[0] . ' … ' . $expLabels[11] . '), 12 numbers each, this/prev objects',
        $cfShape, 'got=' . substr(json_encode($cf), 0, 400));
    if ($cfShape) {
        $billedBad = $collBad = [];
        foreach ($expMonths as $i => $m) {
            $b = db_row(
                "SELECT ROUND(COALESCE(SUM(CASE WHEN currency = 'USD' AND exchange_rate_to_cad > 0
                                                THEN total_amount * exchange_rate_to_cad ELSE total_amount END), 0), 2) AS t
                   FROM invoices
                  WHERE deleted_at IS NULL AND status NOT IN ('draft','void','written_off')
                    AND invoice_date BETWEEN ? AND ?",
                [$m['start'], $m['end']]
            )['t'];
            if (m2($cf['billed'][$i]) !== sprintf('%.2f', (float) $b)) {
                $billedBad[] = "{$m['label']}: api=" . m2($cf['billed'][$i]) . " sql={$b}";
            }
            $c = db_row(
                "SELECT ROUND(COALESCE(SUM(CASE WHEN currency = 'USD' AND exchange_rate_to_cad > 0
                                                THEN amount * exchange_rate_to_cad ELSE amount END), 0), 2) AS t
                   FROM payments
                  WHERE deleted_at IS NULL
                    AND status IN ('cleared','pending')
                    AND payment_method <> 'account_credit'
                    AND payment_date BETWEEN ? AND ?",
                [$m['start'], $m['end']]
            )['t'];
            if (m2($cf['collected'][$i]) !== sprintf('%.2f', (float) $c)) {
                $collBad[] = "{$m['label']}: api=" . m2($cf['collected'][$i]) . " sql={$c}";
            }
        }
        check('CF.2 billed == independent SQL sum for every month (CAD, excl. draft/void/written_off)',
            $billedBad === [], implode('; ', $billedBad));
        check('CF.3 collected == independent SQL sum for every month (live cleared/pending cash, no account_credit)',
            $collBad === [], implode('; ', $collBad));
        check('CF.4 this_month == last entries, prev_month == second-to-last',
            m2($cf['this_month']['billed']) === m2($cf['billed'][11]) && m2($cf['this_month']['collected']) === m2($cf['collected'][11])
            && m2($cf['prev_month']['billed']) === m2($cf['billed'][10]) && m2($cf['prev_month']['collected']) === m2($cf['collected'][10]),
            json_encode(['this' => $cf['this_month'], 'prev' => $cf['prev_month']]));
        $zeroBilled = array_values(array_filter($expLabels, static fn($l, $i) => (float) $cf['billed'][$i] == 0.0, ARRAY_FILTER_USE_BOTH));
        echo "     (info) months with zero billed: " . count($zeroBilled) . ($zeroBilled ? ' — ' . implode(', ', $zeroBilled) : '') . "\n";
    }

    // ── RV: receivables vs ArAging ──────────────────────────────────────────
    echo "\nRV receivables\n";
    $rv    = $D['receivables'] ?? null;
    $aging = \FleetForge\Reports\ArAging::asOf($today);
    $expBuckets = [
        ['current', 'Not due yet', 'current'],
        ['d1_30',   '1–30 days late', 'days_1_30'],
        ['d31_60',  '31–60 days late', 'days_31_60'],
        ['d61_90',  '61–90 days late', 'days_61_90'],
        ['d90',     '90+ days late', 'days_90_plus'],
    ];
    $rvShape = is_array($rv)
        && keyset($rv) === sorted(['total', 'invoice_count', 'customer_count', 'buckets'])
        && is_num($rv['total']) && is_int($rv['invoice_count']) && is_int($rv['customer_count'])
        && is_array($rv['buckets']) && count($rv['buckets']) === 5;
    if ($rvShape) {
        foreach ($expBuckets as $i => [$k, $l]) {
            $b = $rv['buckets'][$i] ?? null;
            $rvShape = $rvShape && is_array($b) && keyset($b) === sorted(['key', 'label', 'amount', 'count'])
                && $b['key'] === $k && $b['label'] === $l && is_num($b['amount']) && is_int($b['count']);
        }
    }
    check('RV.1 shape: total/invoice_count/customer_count + 5 buckets (current, d1_30, d31_60, d61_90, d90) with labels',
        $rvShape, 'got=' . substr(json_encode($rv), 0, 500));
    if ($rvShape) {
        $sumAmt = '0.00';
        $sumCnt = 0;
        $bucketBad = [];
        $invCounts = array_fill_keys(\FleetForge\Reports\ArAging::BUCKETS, 0);
        foreach ($aging['invoices'] as $inv) {
            $invCounts[$inv['bucket']]++;
        }
        foreach ($expBuckets as $i => [$k, , $ak]) {
            $b = $rv['buckets'][$i];
            $sumAmt = bcadd($sumAmt, m2($b['amount']), 2);
            $sumCnt += $b['count'];
            if (m2($b['amount']) !== $aging['totals'][$ak] || $b['count'] !== $invCounts[$ak]) {
                $bucketBad[] = "{$k}: api=" . m2($b['amount']) . "/{$b['count']} aging={$aging['totals'][$ak]}/{$invCounts[$ak]}";
            }
        }
        check('RV.2 total == Σ bucket amounts', m2($rv['total']) === $sumAmt, 'total=' . m2($rv['total']) . " Σ={$sumAmt}");
        check("RV.3 total == ArAging::asOf(ff_today()) grand total ({$aging['totals']['total']}) and every bucket amount/count matches",
            m2($rv['total']) === $aging['totals']['total'] && $bucketBad === [],
            'total=' . m2($rv['total']) . ' ' . implode('; ', $bucketBad));
        check('RV.4 Σ bucket counts == invoice_count == ArAging invoice_count; customer_count == ArAging customer groups',
            $sumCnt === $rv['invoice_count'] && $rv['invoice_count'] === $aging['invoice_count']
            && $rv['customer_count'] === count($aging['customers']),
            "Σcount={$sumCnt} invoice_count={$rv['invoice_count']} aging={$aging['invoice_count']} customers={$rv['customer_count']}/" . count($aging['customers']));
    }

    // ── OC: overdue_customers ───────────────────────────────────────────────
    echo "\nOC overdue_customers\n";
    $oc = $D['overdue_customers'] ?? null;
    $ocShape = is_array($oc) && keyset($oc) === sorted(['rows', 'total_overdue'])
        && is_num($oc['total_overdue']) && is_array($oc['rows']) && array_is_list($oc['rows']);
    if ($ocShape) {
        foreach ($oc['rows'] as $r) {
            $ocShape = $ocShape && is_array($r)
                && keyset($r) === sorted(['customer_id', 'name', 'amount', 'invoice_count', 'oldest_days'])
                && is_int($r['customer_id']) && is_string($r['name']) && is_num($r['amount'])
                && is_int($r['invoice_count']) && is_int($r['oldest_days']);
        }
    }
    check('OC.1 shape: rows[{customer_id:int,name,amount,invoice_count:int,oldest_days:int}] + total_overdue',
        $ocShape, 'got=' . substr(json_encode($oc), 0, 500));
    if ($ocShape) {
        $rows   = $oc['rows'];
        $sorted = true;
        $sumRows = '0.00';
        $rowsOk = true;
        foreach ($rows as $i => $r) {
            $sumRows = bcadd($sumRows, m2($r['amount']), 2);
            $rowsOk  = $rowsOk && (float) $r['amount'] > 0 && $r['invoice_count'] >= 1 && $r['oldest_days'] >= 1;
            if ($i > 0 && (float) $rows[$i - 1]['amount'] < (float) $r['amount']) {
                $sorted = false;
            }
        }
        check('OC.2 ≤ 6 rows, sorted by amount desc, every amount > 0 / invoice_count ≥ 1 / oldest_days ≥ 1',
            count($rows) <= 6 && $sorted && $rowsOk, 'rows=' . json_encode($rows));

        $lateBuckets = bcsub($aging['totals']['total'], $aging['totals']['current'], 2);
        check("OC.3 total_overdue ≥ Σ rows ({$sumRows}) and == receivables' four late buckets ({$lateBuckets})",
            bccomp(m2($oc['total_overdue']), $sumRows, 2) >= 0 && m2($oc['total_overdue']) === $lateBuckets,
            'total_overdue=' . m2($oc['total_overdue']));

        // Independent: canonical overdue predicate, per-invoice CAD in bcmath.
        $ovRows = db_select(
            "SELECT i.id, i.customer_id, i.currency, i.exchange_rate_to_cad, i.balance_due, i.due_date
               FROM invoices i
              WHERE i.deleted_at IS NULL
                AND i.status IN ('sent','partially_paid','overdue')
                AND i.balance_due > 0
                AND i.due_date < ?",
            [$today]
        );
        $indTotal = '0.00';
        $indCust  = [];
        foreach ($ovRows as $r) {
            $cad = bcround(cad8((string) $r['balance_due'], $r['currency'], $r['exchange_rate_to_cad']), 2);
            $indTotal = bcadd($indTotal, $cad, 2);
            $ck = (int) ($r['customer_id'] ?? 0);
            $indCust[$ck] ??= ['amount' => '0.00', 'n' => 0, 'oldest' => 0];
            $indCust[$ck]['amount'] = bcadd($indCust[$ck]['amount'], $cad, 2);
            $indCust[$ck]['n']++;
            $indCust[$ck]['oldest'] = max($indCust[$ck]['oldest'],
                (int) (new DateTimeImmutable((string) $r['due_date']))->diff(new DateTimeImmutable($today))->days);
        }
        check("OC.4 total_overdue == independent canonical-predicate sum ({$indTotal}, " . count($ovRows) . ' invoices)',
            m2($oc['total_overdue']) === $indTotal, 'api=' . m2($oc['total_overdue']));

        $rowBad = [];
        foreach ($rows as $r) {
            $ind = $indCust[$r['customer_id']] ?? null;
            if ($ind === null || m2($r['amount']) !== $ind['amount'] || $r['invoice_count'] !== $ind['n'] || $r['oldest_days'] !== $ind['oldest']) {
                $rowBad[] = "cust {$r['customer_id']}: api=" . m2($r['amount']) . "/{$r['invoice_count']}/{$r['oldest_days']} ind=" . json_encode($ind);
            }
        }
        $indAmounts = array_column($indCust, 'amount');
        usort($indAmounts, static fn($a, $b) => bccomp($b, $a, 2));
        $apiAmounts = array_map('m2', array_column($rows, 'amount'));
        check('OC.5 every row == its customer\'s independent amount/invoice_count/oldest_days, and rows are the top 6 balances',
            $rowBad === [] && $apiAmounts === array_slice($indAmounts, 0, 6),
            implode('; ', $rowBad) . ' api=' . json_encode($apiAmounts) . ' ind_top6=' . json_encode(array_slice($indAmounts, 0, 6)));
    }

    // ── FM: fleet_mix ───────────────────────────────────────────────────────
    echo "\nFM fleet_mix\n";
    $fm = $D['fleet_mix'] ?? null;
    $expStatuses = [['on_lease', 'On lease'], ['available', 'Available'], ['reserved', 'Reserved'],
                    ['maintenance', 'In the shop'], ['inactive', 'Inactive']];
    $fmShape = is_array($fm) && keyset($fm) === sorted(['total', 'statuses', 'types'])
        && is_int($fm['total']) && is_array($fm['statuses']) && count($fm['statuses']) === 5
        && is_array($fm['types']) && array_is_list($fm['types']);
    if ($fmShape) {
        foreach ($expStatuses as $i => [$k, $l]) {
            $s = $fm['statuses'][$i] ?? null;
            $fmShape = $fmShape && is_array($s) && keyset($s) === sorted(['key', 'label', 'count'])
                && $s['key'] === $k && $s['label'] === $l && is_int($s['count']);
        }
        foreach ($fm['types'] as $t) {
            $fmShape = $fmShape && is_array($t)
                && keyset($t) === sorted(['label', 'total', 'on_lease', 'available', 'other', 'pct_on_lease'])
                && is_string($t['label']) && is_int($t['total']) && is_int($t['on_lease'])
                && is_int($t['available']) && is_int($t['other']) && is_num($t['pct_on_lease']);
        }
    }
    check('FM.1 shape: total + 5 statuses in order (on_lease … inactive, "In the shop") + types[{label,total,on_lease,available,other,pct_on_lease}]',
        $fmShape, 'got=' . substr(json_encode($fm), 0, 500));
    if ($fmShape) {
        $sqlByStatus = array_column(db_select(
            "SELECT status, COUNT(*) AS n FROM equipment_units
              WHERE deleted_at IS NULL AND status <> 'decommissioned' GROUP BY status"
        ), 'n', 'status');
        $fleet   = (int) array_sum($sqlByStatus);
        $stSum   = array_sum(array_column($fm['statuses'], 'count'));
        check("FM.2 total == Σ statuses == SQL fleet count (non-deleted, non-decommissioned) = {$fleet}",
            $fm['total'] === $stSum && $stSum === $fleet, "total={$fm['total']} Σstatuses={$stSum}");
        $stBad = [];
        foreach ($fm['statuses'] as $s) {
            if ($s['count'] !== (int) ($sqlByStatus[$s['key']] ?? 0)) {
                $stBad[] = "{$s['key']}: api={$s['count']} sql=" . (int) ($sqlByStatus[$s['key']] ?? 0);
            }
        }
        check('FM.3 every status count == SQL count', $stBad === [], implode('; ', $stBad));

        $typeBad = [];
        $sumT = $sumOn = $sumAv = 0;
        $typeSorted = true;
        foreach ($fm['types'] as $i => $t) {
            if ($t['on_lease'] + $t['available'] + $t['other'] !== $t['total']) {
                $typeBad[] = "{$t['label']}: {$t['on_lease']}+{$t['available']}+{$t['other']}≠{$t['total']}";
            }
            $expPct = $t['total'] > 0 ? round($t['on_lease'] / $t['total'] * 100, 1) : 0.0;
            if (abs((float) $t['pct_on_lease'] - $expPct) > 0.001 || $t['pct_on_lease'] < 0 || $t['pct_on_lease'] > 100) {
                $typeBad[] = "{$t['label']}: pct={$t['pct_on_lease']} expected={$expPct}";
            }
            if ($i > 0 && $fm['types'][$i - 1]['total'] < $t['total']) {
                $typeSorted = false;
            }
            $sumT += $t['total'];
            $sumOn += $t['on_lease'];
            $sumAv += $t['available'];
        }
        $stOn = $fm['statuses'][0]['count'];
        $stAv = $fm['statuses'][1]['count'];
        check('FM.4 each type on_lease+available+other == total, pct_on_lease = on_lease/total 1dp; Σ types ties to total/on_lease/available',
            $typeBad === [] && $sumT === $fm['total'] && $sumOn === $stOn && $sumAv === $stAv,
            implode('; ', $typeBad) . " Σtotal={$sumT}/{$fm['total']} Σon={$sumOn}/{$stOn} Σav={$sumAv}/{$stAv}");
        check('FM.5 types sorted by total desc', $typeSorted, json_encode(array_column($fm['types'], 'total', 'label')));
    }

    // ── LF: lease_flow ──────────────────────────────────────────────────────
    echo "\nLF lease_flow\n";
    $lf = $D['lease_flow'] ?? null;
    $intList = static fn($a): bool => is_array($a) && array_is_list($a) && count($a) === 12
        && count(array_filter($a, static fn($v) => is_int($v) && $v >= 0)) === 12;
    $lfShape = is_array($lf) && keyset($lf) === sorted(['labels', 'opened', 'closed', 'on_rent'])
        && ($lf['labels'] ?? null) === $expLabels
        && $intList($lf['opened']) && $intList($lf['closed']) && $intList($lf['on_rent']);
    check('LF.1 shape: 12 rolling labels; opened/closed/on_rent are 12 non-negative ints',
        $lfShape, 'got=' . substr(json_encode($lf), 0, 400));
    if ($lfShape) {
        $bad = ['opened' => [], 'closed' => [], 'on_rent' => []];
        foreach ($expMonths as $i => $m) {
            $upTo  = min($m['end'], $today);
            $asOf  = $i === 11 ? $today : $m['end'];
            $o = (int) db_row(
                "SELECT COUNT(*) AS n FROM leases
                  WHERE deleted_at IS NULL AND status IN ('active','completed')
                    AND start_date BETWEEN ? AND ?",
                [$m['start'], $upTo]
            )['n'];
            $c = (int) db_row(
                "SELECT COUNT(*) AS n FROM leases
                  WHERE deleted_at IS NULL AND status = 'completed'
                    AND IFNULL(actual_return_date, end_date) BETWEEN ? AND ?",
                [$m['start'], $upTo]
            )['n'];
            // Effective end spelled out case by case (not the endpoint's COALESCE).
            $r = (int) db_row(
                "SELECT COUNT(*) AS n FROM leases
                  WHERE deleted_at IS NULL AND status IN ('active','completed')
                    AND start_date <= ?
                    AND (   (actual_return_date IS NOT NULL AND actual_return_date >= ?)
                         OR (actual_return_date IS NULL AND end_date IS NOT NULL AND end_date >= ?)
                         OR (actual_return_date IS NULL AND end_date IS NULL AND status = 'active'))",
                [$asOf, $asOf, $asOf]
            )['n'];
            if ($lf['opened'][$i] !== $o)  { $bad['opened'][]  = "{$m['label']}: api={$lf['opened'][$i]} sql={$o}"; }
            if ($lf['closed'][$i] !== $c)  { $bad['closed'][]  = "{$m['label']}: api={$lf['closed'][$i]} sql={$c}"; }
            if ($lf['on_rent'][$i] !== $r) { $bad['on_rent'][] = "{$m['label']} (as of {$asOf}): api={$lf['on_rent'][$i]} sql={$r}"; }
        }
        check("LF.2 current-month on_rent ({$lf['on_rent'][11]}) == independent count as of ff_today(), and every past month-end matches",
            $bad['on_rent'] === [], implode('; ', $bad['on_rent']));
        check('LF.3 opened == independent count of active/completed starts per month (not after today)',
            $bad['opened'] === [], implode('; ', $bad['opened']));
        check('LF.4 closed == independent count of completed leases whose effective end is in the month (not after today)',
            $bad['closed'] === [], implode('; ', $bad['closed']));
    }

    // ── TC: top_customers.ytd_total ─────────────────────────────────────────
    echo "\nTC top_customers.ytd_total\n";
    $tc = $D['top_customers'] ?? null;
    $tcSeries = $tc['series'][0]['data'] ?? null;
    $tcShape  = is_array($tc) && keyset($tc) === sorted(['labels', 'series', 'ytd_total'])
        && is_num($tc['ytd_total']) && is_array($tcSeries) && ($tc['series'][0]['name'] ?? null) === 'Revenue'
        && count($tc['labels']) === count($tcSeries);
    check('TC.1 shape unchanged (labels + series[Revenue]) plus numeric ytd_total', $tcShape, 'got=' . substr(json_encode($tc), 0, 300));
    if ($tcShape) {
        $seriesSum = '0.00';
        foreach ($tcSeries as $v) {
            $seriesSum = bcadd($seriesSum, m2($v), 2);
        }
        $groups = db_select(
            "SELECT SUM(CASE WHEN currency = 'USD' THEN total_amount * COALESCE(exchange_rate_to_cad, 1) ELSE total_amount END) AS t
               FROM invoices
              WHERE deleted_at IS NULL AND status NOT IN ('draft','void','written_off') AND YEAR(invoice_date) = ?
              GROUP BY customer_id, company_name_snapshot, customer_name_snapshot",
            [$year]
        );
        $indYtd = '0.00';
        foreach ($groups as $g) {
            $indYtd = bcadd($indYtd, bcround((string) $g['t'], 2), 2);
        }
        check("TC.2 ytd_total (" . m2($tc['ytd_total']) . ") ≥ Σ top-5 series ({$seriesSum}) and == independent all-customer total ({$indYtd})",
            bccomp(m2($tc['ytd_total']), $seriesSum, 2) >= 0 && m2($tc['ytd_total']) === $indYtd);
    }

    // ── MW: month-bucketed charts (S-PERF-3) ────────────────────────────────
    // WHY: these four used strtotime("±N months") from today, which repeats /
    // skips a month on the 29th–31st, and ran one query per month. They now
    // share chart_months() (anchored on the 1st of ff_today()'s month) and one
    // GROUP BY / one fetch each. The expected months here are built a third
    // way ('first day of this month' ± i) and every value is recomputed with
    // the old per-month query shape, so both the bucketing and the batching
    // are pinned.
    echo "\nMW month-bucketed charts (S-PERF-3)\n";
    $fwdMonths = static function (int $n) use ($today): array {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $d = (new DateTimeImmutable($today))->modify('first day of this month')->modify("+{$i} months");
            $out[] = ['label' => $d->format('M Y'), 'start' => $d->format('Y-m-d'), 'end' => $d->format('Y-m-t'),
                      'y' => (int) $d->format('Y'), 'n' => (int) $d->format('n'), 'days' => (int) $d->format('t')];
        }
        return $out;
    };
    $pastMonths = array_map(static function (array $m): array {
        $d = new DateTimeImmutable($m['start']);
        return $m + ['y' => (int) $d->format('Y'), 'n' => (int) $d->format('n')];
    }, $expMonths);
    $curLabel = (new DateTimeImmutable($today))->format('M Y');

    // revenue_forecast — next 6 months, bcmath ladder per month.
    $rf     = $D['revenue_forecast'] ?? null;
    $rfData = $rf['series'][0]['data'] ?? null;
    $rfExp  = $fwdMonths(6);
    $rfShape = is_array($rf) && ($rf['labels'] ?? null) === array_column($rfExp, 'label')
        && ($rf['series'][0]['name'] ?? null) === 'Projected Revenue'
        && is_array($rfData) && array_is_list($rfData) && count(array_filter($rfData, 'is_num')) === 6;
    check("MW.1 revenue_forecast labels = 6 consecutive months from {$curLabel}, 6 numbers",
        $rfShape, 'got=' . substr(json_encode($rf), 0, 300));
    if ($rfShape) {
        $bad = [];
        foreach ($rfExp as $i => $m) {
            $exp = '0.00';
            foreach (db_select(
                "SELECT monthly_rate, weekly_rate, daily_rate FROM leases
                  WHERE status = 'active' AND deleted_at IS NULL
                    AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)",
                [$m['end'], $m['start']]
            ) as $l) {
                $exp = bcadd($exp, match (true) {
                    bccomp((string) $l['monthly_rate'], '0', 2) > 0 => (string) $l['monthly_rate'],
                    bccomp((string) $l['weekly_rate'], '0', 2) > 0  => bcmul((string) $l['weekly_rate'], '4.33', 2),
                    default                                          => bcmul((string) $l['daily_rate'], (string) $m['days'], 2),
                }, 2);
            }
            if (m2($rfData[$i]) !== $exp) {
                $bad[] = "{$m['label']}: api=" . m2($rfData[$i]) . " sql={$exp}";
            }
        }
        check('MW.2 revenue_forecast == independent per-month overlap query + bcmath rate ladder, every month',
            $bad === [], implode('; ', $bad));
    }

    // lease_expiry_calendar — next 12 months, count by end_date month.
    $le     = $D['lease_expiry_calendar'] ?? null;
    $leData = $le['series'][0]['data'] ?? null;
    $leExp  = $fwdMonths(12);
    $leShape = is_array($le) && ($le['labels'] ?? null) === array_column($leExp, 'label')
        && is_array($leData) && array_is_list($leData) && count($leData) === 12
        && count(array_filter($leData, static fn($v) => is_int($v) && $v >= 0)) === 12;
    check("MW.3 lease_expiry_calendar labels = 12 consecutive months from {$curLabel}, 12 non-negative ints",
        $leShape, 'got=' . substr(json_encode($le), 0, 300));
    if ($leShape) {
        $bad = [];
        foreach ($leExp as $i => $m) {
            $n = (int) db_row(
                "SELECT COUNT(*) AS n FROM leases
                  WHERE status = 'active' AND deleted_at IS NULL
                    AND YEAR(end_date) = ? AND MONTH(end_date) = ?",
                [$m['y'], $m['n']]
            )['n'];
            if ($leData[$i] !== $n) {
                $bad[] = "{$m['label']}: api={$leData[$i]} sql={$n}";
            }
        }
        check('MW.4 lease_expiry_calendar == independent per-month COUNT of active leases ending that month',
            $bad === [], implode('; ', $bad));
    }

    // payment_speed — rolling 12, AVG(paid_date − invoice_date) by paid_date month.
    $ps     = $D['payment_speed'] ?? null;
    $psData = $ps['series'][0]['data'] ?? null;
    $psShape = is_array($ps) && ($ps['labels'] ?? null) === $expLabels
        && ($ps['series'][0]['name'] ?? null) === 'Avg Days to Pay'
        && is_array($psData) && array_is_list($psData) && count($psData) === 12
        && count(array_filter($psData, static fn($v) => $v === null || is_num($v))) === 12;
    check("MW.5 payment_speed labels = the 12 rolling months ending {$curLabel}, 12 numbers-or-null",
        $psShape, 'got=' . substr(json_encode($ps), 0, 300));
    if ($psShape) {
        $bad = [];
        foreach ($pastMonths as $i => $m) {
            $avg = db_row(
                "SELECT AVG(DATEDIFF(paid_date, invoice_date)) AS a FROM invoices
                  WHERE status = 'paid' AND deleted_at IS NULL
                    AND YEAR(paid_date) = ? AND MONTH(paid_date) = ?",
                [$m['y'], $m['n']]
            )['a'];
            $exp = $avg === null ? null : round((float) $avg, 1);
            if ($psData[$i] !== $exp && !(is_num($psData[$i]) && $exp !== null && (float) $psData[$i] === $exp)) {
                $bad[] = "{$m['label']}: api=" . json_encode($psData[$i]) . ' sql=' . json_encode($exp);
            }
        }
        check('MW.6 payment_speed == independent per-month AVG by paid_date (null where no invoice was paid)',
            $bad === [], implode('; ', $bad));
    }

    // leases_trend — rolling 12, opened by created_at / closed by updated_at.
    $lt = $D['leases_trend'] ?? null;
    $ltShape = is_array($lt) && ($lt['labels'] ?? null) === $expLabels
        && ($lt['series'][0]['name'] ?? null) === 'Opened' && ($lt['series'][1]['name'] ?? null) === 'Closed'
        && $intList($lt['series'][0]['data'] ?? null) && $intList($lt['series'][1]['data'] ?? null);
    check("MW.7 leases_trend labels = the 12 rolling months ending {$curLabel}; Opened/Closed are 12 ints",
        $ltShape, 'got=' . substr(json_encode($lt), 0, 300));
    if ($ltShape) {
        $bad = [];
        foreach ($pastMonths as $i => $m) {
            $o = (int) db_row(
                "SELECT COUNT(*) AS n FROM leases
                  WHERE deleted_at IS NULL AND YEAR(created_at) = ? AND MONTH(created_at) = ?",
                [$m['y'], $m['n']]
            )['n'];
            $c = (int) db_row(
                "SELECT COUNT(*) AS n FROM leases
                  WHERE deleted_at IS NULL AND status IN ('completed','cancelled')
                    AND YEAR(updated_at) = ? AND MONTH(updated_at) = ?",
                [$m['y'], $m['n']]
            )['n'];
            if ($lt['series'][0]['data'][$i] !== $o || $lt['series'][1]['data'][$i] !== $c) {
                $bad[] = "{$m['label']}: api=" . $lt['series'][0]['data'][$i] . '/' . $lt['series'][1]['data'][$i] . " sql={$o}/{$c}";
            }
        }
        check('MW.8 leases_trend opened/closed == independent per-month COUNTs',
            $bad === [], implode('; ', $bad));
    }

    // ── IU: idle_units (tables.php) ─────────────────────────────────────────
    echo "\nIU idle_units\n";
    $tb = call_as('api/v1/dashboard/tables.php', '', $users['super_admin']);
    $iu = $tb['json']['data']['idle_units'] ?? null;
    $iuShape = ($tb['json']['success'] ?? null) === true && is_array($iu) && array_is_list($iu) && count($iu) <= 8;
    if ($iuShape) {
        foreach ($iu as $r) {
            $iuShape = $iuShape && is_array($r)
                && keyset($r) === sorted(['id', 'unit_number', 'type', 'category', 'yard', 'idle_since', 'idle_days'])
                && is_int($r['id']) && is_string($r['unit_number']) && is_string($r['type'])
                && ($r['category'] === null || is_string($r['category']))
                && ($r['yard'] === null || is_string($r['yard']))
                && ($r['idle_since'] === null || (is_string($r['idle_since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['idle_since'])))
                && ($r['idle_days'] === null || is_int($r['idle_days']));
        }
    }
    check('IU.1 shape: ≤ 8 rows of {id,unit_number,type,category,yard,idle_since,idle_days}',
        $iuShape, 'got=' . substr(json_encode($iu), 0, 500) . ' body=' . substr($tb['output'], 0, 200));
    if ($iuShape) {
        $statusBad = [];
        foreach ($iu as $r) {
            $u = db_row("SELECT status, deleted_at FROM equipment_units WHERE id = ?", [$r['id']]);
            if (!$u || $u['status'] !== 'available' || $u['deleted_at'] !== null) {
                $statusBad[] = "#{$r['id']} " . json_encode($u);
            }
        }
        check('IU.2 every listed unit is status available and not deleted', $statusBad === [], implode('; ', $statusBad));

        $orderOk = true;
        $mathBad = [];
        foreach ($iu as $i => $r) {
            if ($r['idle_since'] !== null) {
                $exp = max(0, (int) (new DateTimeImmutable($r['idle_since']))->diff(new DateTimeImmutable($today))->format('%r%a'));
                if ($r['idle_days'] !== $exp) {
                    $mathBad[] = "{$r['unit_number']}: idle_days={$r['idle_days']} expected={$exp}";
                }
            }
            if ($i > 0) {
                $p = $iu[$i - 1]['idle_days'];
                $c = $r['idle_days'];
                if ($p === null && $c !== null) { $orderOk = false; }
                if ($p !== null && $c !== null && $p < $c) { $orderOk = false; }
            }
        }
        check('IU.3 sorted by idle_days desc (nulls last) and idle_days == today − idle_since',
            $orderOk && $mathBad === [], implode('; ', $mathBad) . ' days=' . json_encode(array_column($iu, 'idle_days')));

        // Independent recompute from every lease of every available unit.
        $avail = db_select("SELECT id, unit_number, created_at FROM equipment_units WHERE status = 'available' AND deleted_at IS NULL");
        $leasesByUnit = [];
        foreach (db_select(
            "SELECT l.equipment_unit_id AS uid, l.start_date, l.actual_return_date, l.end_date
               FROM leases l JOIN equipment_units eu ON eu.id = l.equipment_unit_id
              WHERE eu.status = 'available' AND eu.deleted_at IS NULL
                AND l.deleted_at IS NULL AND l.status IN ('active','completed')"
        ) as $l) {
            $leasesByUnit[(int) $l['uid']][] = $l;
        }
        $expIdle = [];
        $drift   = [];
        foreach ($avail as $u) {
            $stillOut = false;
            $lastEnd  = null;
            foreach ($leasesByUnit[(int) $u['id']] ?? [] as $l) {
                $end = $l['actual_return_date'] ?? $l['end_date'];
                if ($l['start_date'] <= $today && ($end === null || $end > $today)) {
                    $stillOut = true;
                }
                if ($end !== null && $end <= $today && ($lastEnd === null || $end > $lastEnd)) {
                    $lastEnd = $end;
                }
            }
            if ($stillOut) {
                $drift[] = $u['unit_number'];
                continue;
            }
            $since = $lastEnd ?? ff_utc_to_local((string) $u['created_at']);
            $expIdle[] = [
                'id'         => (int) $u['id'],
                'unit'       => (string) $u['unit_number'],
                'since'      => $since,
                'days'       => max(0, (int) (new DateTimeImmutable($since))->diff(new DateTimeImmutable($today))->format('%r%a')),
            ];
        }
        usort($expIdle, static fn($a, $b) => ($b['days'] <=> $a['days']) ?: strnatcasecmp($a['unit'], $b['unit']));
        $expTop = array_map(static fn($e) => $e['id'] . '@' . $e['since'], array_slice($expIdle, 0, 8));
        $gotTop = array_map(static fn($r) => $r['id'] . '@' . $r['idle_since'], $iu);
        check('IU.4 rows == independent recompute (top 8 of ' . count($expIdle) . ' eligible available units; '
            . count($drift) . ' available-but-still-out skipped' . ($drift ? ': ' . implode(', ', $drift) : '') . ')',
            $gotTop === $expTop, 'api=' . json_encode($gotTop) . ' ind=' . json_encode($expTop));

        $moneyKeys = array_values(array_filter(array_keys($iu[0] ?? []), 'ff_is_money_field'));
        check('IU.5 no money-named key on idle_units rows', $moneyKeys === [], json_encode($moneyKeys));
    }

    // ── RG: role gate ───────────────────────────────────────────────────────
    echo "\nRG role gate (real logins)\n";
    $dAll = call_as('api/v1/dashboard/charts.php', '', $users['dispatcher']);
    check('RG.0 dispatcher session has NO financial access (precondition)', $dAll['fin'] === 0, 'fin=' . var_export($dAll['fin'], true));
    $dKeys = keyset($dAll['json']['data'] ?? null);
    $leaked = array_values(array_intersect(NEW_MONEY, $dKeys));
    check('RG.1 dispatcher combined payload: cash_flow/receivables/overdue_customers absent; fleet_mix + lease_flow present',
        ($dAll['json']['success'] ?? null) === true && $leaked === []
        && in_array('fleet_mix', $dKeys, true) && in_array('lease_flow', $dKeys, true),
        'leaked=' . json_encode($leaked) . ' keys=' . json_encode($dKeys));
    foreach (NEW_MONEY as $ck) {
        $one = call_as('api/v1/dashboard/charts.php', 'chart=' . $ck, $users['dispatcher'])['json'];
        check("RG.2 dispatcher ?chart={$ck} → 403 FORBIDDEN, no dataset",
            ($one['success'] ?? null) === false && ($one['error']['code'] ?? null) === 'FORBIDDEN' && !isset($one['data']),
            substr(json_encode($one), 0, 200));
    }
    $dFm = call_as('api/v1/dashboard/charts.php', 'chart=fleet_mix', $users['dispatcher'])['json'];
    check('RG.3 dispatcher ?chart=fleet_mix → 200 with the dataset (operational)',
        ($dFm['success'] ?? null) === true && isset($dFm['data']['statuses']), substr(json_encode($dFm), 0, 200));
    $dTb = call_as('api/v1/dashboard/tables.php', '', $users['dispatcher'])['json'];
    check('RG.4 dispatcher tables.php still carries idle_units (operational)',
        ($dTb['success'] ?? null) === true && is_array($dTb['data']['idle_units'] ?? null)
        && count($dTb['data']['idle_units']) === count($iu ?? []), 'got=' . substr(json_encode($dTb['data']['idle_units'] ?? null), 0, 200));

    $aAll = call_as('api/v1/dashboard/charts.php', '', $users['accountant']);
    check('RG.5 accountant session has financial access (precondition)', $aAll['fin'] === 1, 'fin=' . var_export($aAll['fin'], true));
    $aKeys   = keyset($aAll['json']['data'] ?? null);
    $missing = array_values(array_diff(NEW_KEYS, $aKeys));
    check('RG.6 accountant combined payload carries all 5 new datasets', $missing === [], 'missing=' . json_encode($missing));
    $probe = ['cash_flow' => 'billed', 'receivables' => 'buckets', 'overdue_customers' => 'rows'];
    foreach ($probe as $ck => $field) {
        $one = call_as('api/v1/dashboard/charts.php', 'chart=' . $ck, $users['accountant'])['json'];
        check("RG.7 accountant ?chart={$ck} → 200 with dataset",
            ($one['success'] ?? null) === true && is_array($one['data'][$field] ?? null), substr(json_encode($one), 0, 200));
    }

    // ── CL: ?charts= subset mode ────────────────────────────────────────────
    echo "\nCL ?charts= subset mode\n";
    $subsetQs = 'charts=' . implode(',', DASH_SUBSET);
    $sSub = call_as('api/v1/dashboard/charts.php', $subsetQs, $users['super_admin'])['json'];
    check('CL.1 super_admin ?charts=<dashboard 11> returns exactly those 11 keys',
        ($sSub['success'] ?? null) === true && keyset($sSub['data'] ?? null) === sorted(DASH_SUBSET),
        'keys=' . json_encode(keyset($sSub['data'] ?? null)));
    $diffKeys = [];
    foreach (DASH_SUBSET as $k) {
        if (json_encode($D[$k] ?? null) !== json_encode($sSub['data'][$k] ?? null)) {
            $diffKeys[] = $k;
        }
    }
    check('CL.2 every subset dataset is identical to the all-charts payload (same builders + cache)',
        is_array($sSub['data'] ?? null) && $diffKeys === [], 'differs: ' . json_encode($diffKeys));
    $aSub = call_as('api/v1/dashboard/charts.php', $subsetQs, $users['accountant'])['json'];
    check('CL.3 accountant ?charts=<dashboard 11> returns exactly those 11 keys',
        ($aSub['success'] ?? null) === true && keyset($aSub['data'] ?? null) === sorted(DASH_SUBSET),
        'keys=' . json_encode(keyset($aSub['data'] ?? null)));
    $dSub = call_as('api/v1/dashboard/charts.php', $subsetQs, $users['dispatcher'])['json'];
    $expDisp = sorted(array_values(array_diff(DASH_SUBSET, ALL_MONEY)));
    check('CL.4 dispatcher ?charts=<dashboard 11> → 200 with only the operational ones ' . json_encode($expDisp) . ' (money omitted, not an error)',
        ($dSub['success'] ?? null) === true && keyset($dSub['data'] ?? null) === $expDisp,
        'keys=' . json_encode(keyset($dSub['data'] ?? null)));
    $small = call_as('api/v1/dashboard/charts.php', 'charts=fleet_mix, lease_flow,fleet_mix', $users['dispatcher'])['json'];
    check('CL.5 whitespace + duplicate keys tolerated: ?charts=fleet_mix, lease_flow,fleet_mix → exactly {fleet_mix, lease_flow}',
        ($small['success'] ?? null) === true && keyset($small['data'] ?? null) === ['fleet_mix', 'lease_flow'],
        substr(json_encode($small), 0, 200));
    $unk = call_as('api/v1/dashboard/charts.php', 'charts=fleet_mix,bogus_chart', $users['super_admin'])['json'];
    check('CL.6 unknown key in ?charts= → 422 VALIDATION_ERROR, no data',
        ($unk['success'] ?? null) === false && ($unk['error']['code'] ?? null) === 'VALIDATION_ERROR' && !isset($unk['data']),
        substr(json_encode($unk), 0, 250));
    $empty = call_as('api/v1/dashboard/charts.php', 'charts=,,', $users['super_admin'])['json'];
    check('CL.7 empty list ?charts=,, → 422 VALIDATION_ERROR',
        ($empty['success'] ?? null) === false && ($empty['error']['code'] ?? null) === 'VALIDATION_ERROR',
        substr(json_encode($empty), 0, 250));
    $onlyMoney = call_as('api/v1/dashboard/charts.php', 'charts=cash_flow,receivables', $users['dispatcher']);
    check('CL.8 dispatcher ?charts=<money only> → 200 with an empty OBJECT (data:{}), no dataset leaked',
        ($onlyMoney['json']['success'] ?? null) === true && str_contains($onlyMoney['output'], '"data":{}'),
        substr($onlyMoney['output'], 0, 200));
    $both = call_as('api/v1/dashboard/charts.php', 'chart=fleet_mix&charts=cash_flow,receivables', $users['super_admin'])['json'];
    check('CL.9 ?chart= wins over ?charts= (single mode: the fleet_mix dataset directly under data)',
        ($both['success'] ?? null) === true && isset($both['data']['statuses']) && !isset($both['data']['cash_flow']),
        substr(json_encode($both), 0, 200));
} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $failures[] = 'exception';
} finally {
    @unlink($harnessFile);
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%s — %d passed, %d failed\n", $failures ? 'FAILURES' : 'ALL PASS', $passes, count($failures));
exit($failures ? 1 : 0);
