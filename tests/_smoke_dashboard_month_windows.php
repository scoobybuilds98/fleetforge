<?php
/**
 * tests/_smoke_dashboard_month_windows.php
 *
 * S-PERF-3 — dashboard month windows, batched month-bucketed charts, and
 * payment speed dated by paid_date.
 *
 * THE BUGS (api/v1/dashboard/charts.php, before S-PERF-3):
 *   1. Month drift. revenue_forecast / lease_expiry_calendar / payment_speed /
 *      leases_trend stepped months with strtotime("±N months") from TODAY. On
 *      the 29th–31st PHP overflows ("Oct 31 + 1 month" = Dec 1), so the charts
 *      showed a month twice and skipped another — the 6-month outlook total
 *      double-counted months, "Leases ending" drew "Dec" twice, and the payment
 *      speed "last 3 months" averaged the wrong months.
 *   2. payment_speed bucketed and measured paid invoices by updated_at (ON
 *      UPDATE CURRENT_TIMESTAMP): any later write to a paid invoice (e.g. the
 *      InvoicePdfGenerator pdf_path UPDATE) re-dated it into the current month
 *      with inflated days-to-pay.
 *   3. Cold path: one query per month per chart, one cache lookup and one
 *      autocommit REPLACE per chart.
 * THE FIX: one month builder, chart_months($from, $to), anchored on the 1st of
 * ff_today()'s month; one GROUP BY / one fetch per chart; payment_speed by
 * paid_date; one cache lookup + one multi-row REPLACE per request.
 *
 * Checks:
 *   H  (in-process, the REAL chart_months()/chart_rolling_months() bodies
 *      lifted out of charts.php with the tokenizer) for EVERY day
 *      2022-01-01 … 2028-12-31 with ff_today() pinned: each window is the
 *      right count of distinct consecutive months, the current month sits at
 *      the right end, start/end/days are that month's; the rolling wrapper ==
 *      chart_months(-11, 0); on every day ≤ 28 the months == the legacy
 *      strtotime list (the C1 byte-identity guarantee), and the legacy list IS
 *      broken on the 29th–31st (non-vacuous).
 *   E  the REAL endpoint (?charts= the four month-bucketed keys, cold) with the
 *      business date pinned to today and to the drift dates 2026-10-31,
 *      2026-03-31, 2026-12-30, 2027-01-29 and the leap day 2028-02-29: labels
 *      are the exact consecutive months; EVERY month's value == an independent
 *      per-month SQL recompute (the pre-S-PERF-3 query shape); the four cold
 *      builds are cached with exactly ONE REPLACE, and the whole request runs
 *      under 20 prepared statements (64 at HEAD in this harness).
 *   W  (in-process, the REAL chart_cache_write() lifted out of charts.php,
 *      inside a rolled-back transaction) a cache write that fails (a row whose
 *      generated_by breaks the users FK) is swallowed and logged, not thrown,
 *      and is not retried (it is not a deadlock); a valid batch of rows is
 *      written with one REPLACE.
 *   R  regression guard for bug 2: a fixture invoice paid 3 months back, whose
 *      pdf_path is then UPDATEd exactly as InvoicePdfGenerator does (bumping
 *      updated_at to now): it counts in its paid_date month (== an in-txn SQL
 *      recompute, and moves that month vs a no-fixture run) and does NOT move
 *      the month updated_at now points at.
 *
 * HERMETIC: every endpoint call runs in a CLI subprocess that pins ff_today()
 * (defined before includes/functions.php, whose definition is
 * function_exists-guarded), opens an outer transaction FIRST, deletes the four
 * charts' report_cache rows (so they build cold), inserts any fixture, logs a
 * real super_admin in, runs the endpoint and ROLLS BACK in a shutdown
 * function. The parent process only reads, except W, which writes inside its
 * own transaction and rolls it back.
 *
 * USAGE: php tests/_smoke_dashboard_month_windows.php
 * EXIT:  0 = all pass, 1 = any failure, 2 = setup error.
 *
 * @session S-PERF-3
 */

declare(strict_types=1);

// Pinnable business date for the in-process H sweep. WHY defined here: the
// app's ff_today() is function_exists-guarded, so a definition made before
// config/app.php wins. Unpinned it is exactly the app's own ff_today().
function ff_today(): string
{
    return $GLOBALS['__ff_pin'] ?? (new DateTimeImmutable('now', ff_business_timezone()))->format('Y-m-d');
}

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;

const MW_KEYS = ['revenue_forecast', 'lease_expiry_calendar', 'payment_speed', 'leases_trend'];

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

/**
 * Lift one top-level function's source out of a PHP file (tokenizer, brace
 * matched) and define it here — so the smoke executes the endpoint's REAL
 * helper without running the endpoint's request code.
 *
 * @param string $file PHP file
 * @param string $name function name
 * @return void
 */
function lift_function(string $file, string $name): void
{
    $tokens = token_get_all((string) file_get_contents($file));
    $n      = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) {
            continue;
        }
        $src   = '';
        $depth = 0;
        $open  = false;
        for ($k = $i; $k < $n; $k++) {
            $t    = $tokens[$k];
            $text = is_array($t) ? $t[1] : $t;
            $src .= $text;
            // Curly-open tokens ("{$x}" / "${") also close with a plain "}".
            if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $open = true;
            } elseif ($text === '}') {
                $depth--;
                if ($open && $depth === 0) {
                    eval($src);
                    return;
                }
            }
        }
    }
    throw new RuntimeException("function {$name} not found in {$file}");
}

/**
 * Independent month list relative to $pin's month, oldest first — built with
 * 'first day of this month' (never the endpoint's helper).
 *
 * @param string $pin  business date Y-m-d
 * @param int    $from first month offset
 * @param int    $to   last month offset (inclusive)
 * @return list<array{label:string, ym:string, start:string, end:string, y:int, n:int, days:int}>
 */
function exp_months(string $pin, int $from, int $to): array
{
    $out = [];
    for ($i = $from; $i <= $to; $i++) {
        $d = (new DateTimeImmutable($pin))->modify('first day of this month')->modify(($i >= 0 ? '+' : '') . $i . ' months');
        $out[] = ['label' => $d->format('M Y'), 'ym' => $d->format('Y-m'), 'start' => $d->format('Y-m-d'),
                  'end' => $d->format('Y-m-t'), 'y' => (int) $d->format('Y'), 'n' => (int) $d->format('n'),
                  'days' => (int) $d->format('t')];
    }
    return $out;
}

/**
 * The pre-S-PERF-3 month list: strtotime("±i months") from noon of $pin
 * (PHP default timezone), as 'Y-m'.
 *
 * @param string $pin  business date Y-m-d
 * @param int    $from first month offset
 * @param int    $to   last month offset (inclusive)
 * @return list<string>
 */
function legacy_yms(string $pin, int $from, int $to): array
{
    $base = strtotime($pin . ' 12:00:00');
    $out  = [];
    for ($i = $from; $i <= $to; $i++) {
        $out[] = date('Y-m', strtotime(($i >= 0 ? '+' : '') . $i . ' months', $base));
    }
    return $out;
}

/** 'Y-m' of a DATETIME/DATE string. */
function ym_of(string $d): string
{
    return substr($d, 0, 7);
}

// ── Harness: pinned business date, outer txn, cold builds, rolled back ─────────
$harnessFile = sys_get_temp_dir() . '/_ff_dash_month_harness_' . getmypid() . '.php';
// WHY a shutdown hook as well as the finally below: run_charts() exit(2)s on a
// harness failure, and exit() skips finally blocks.
register_shutdown_function(static function () use ($harnessFile): void {
    @unlink($harnessFile);
});
file_put_contents($harnessFile, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
$root  = $argv[1];
$pin   = $argv[2];                    // business date Y-m-d
$query = $argv[3];                    // query string
$fix   = $argv[4] === '1';            // insert the payment_speed fixture?

// Pin the company-local business date BEFORE includes/functions.php
// (its ff_today() is function_exists-guarded).
$GLOBALS['__ff_pin'] = $pin;
function ff_today(): string { return $GLOBALS['__ff_pin']; }

ob_start();
require $root . '/config/app.php';
require_once FF_ROOT . '/includes/auth.php';

$pdo   = db_pdo();
$state = ['pin' => $pin, 'today' => ff_today()];
$counters = static function () use ($pdo): array {
    return array_map('intval', $pdo->query(
        "SHOW SESSION STATUS WHERE Variable_name IN ('Com_replace','Com_stmt_execute')"
    )->fetchAll(PDO::FETCH_KEY_PAIR));
};

// Outer transaction FIRST so neither the purge, the fixture nor the
// endpoint's report_cache REPLACE survives.
$pdo->beginTransaction();
register_shutdown_function(static function () use ($pdo, &$state, $counters) {
    $state['output'] = (string) ob_get_clean();
    $state['c1']     = $counters();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();                                        // hermetic
    }
    echo '@@STATE@@' . json_encode($state);
});

db_execute(
    "DELETE FROM report_cache WHERE report_type IN
        ('dashboard_chart_revenue_forecast','dashboard_chart_lease_expiry_calendar',
         'dashboard_chart_payment_speed','dashboard_chart_leases_trend')"
);

if ($fix) {
    // Paid on the 15th three months back, 97 days after its invoice date.
    $paid = (new DateTimeImmutable(substr($pin, 0, 7) . '-15'))->modify('-3 months');
    $inv  = $paid->modify('-97 days');
    $id = db_insert('invoices', [
        'invoice_number' => 'SMOKE-MW-' . getmypid(), 'customer_id' => null, 'lease_id' => null,
        'billing_period_start' => $inv->format('Y-m-d'), 'billing_period_end' => $inv->format('Y-m-d'),
        'billing_period_days' => 1, 'billing_type' => 'single_period',
        'invoice_date' => $inv->format('Y-m-d'), 'due_date' => $inv->format('Y-m-d'),
        'status' => 'paid', 'paid_date' => $paid->format('Y-m-d'), 'currency' => 'CAD',
        'subtotal' => '10.00', 'total_amount' => '10.00', 'amount_paid' => '10.00',
        'credits_applied' => '0.00', 'balance_due' => '0.00',
        // Stamped as if last touched the day it was paid …
        'updated_at' => $paid->format('Y-m-d') . ' 12:00:00',
    ]);
    // … then the exact statement InvoicePdfGenerator::generate() runs, which
    // bumps updated_at (ON UPDATE CURRENT_TIMESTAMP) to now.
    db_execute(
        "UPDATE invoices SET pdf_path = ?, pdf_generated_at = ?, pdf_version = ? WHERE id = ?",
        ['smoke/month-windows.pdf', ff_now_utc(), 1, $id]
    );
    $row = db_row("SELECT paid_date, updated_at FROM invoices WHERE id = ?", [$id]);
    $state['fixture'] = ['id' => $id, 'paid_date' => $row['paid_date'], 'updated_at' => $row['updated_at']];
    // In-txn truth (fixture visible): AVG days by paid_date month, 12 rolling months.
    $state['sql_ps'] = [];
    $a = new DateTimeImmutable(substr($pin, 0, 7) . '-01');
    for ($i = -11; $i <= 0; $i++) {
        $m = $a->modify("{$i} months");
        $v = db_row(
            "SELECT AVG(DATEDIFF(paid_date, invoice_date)) AS a FROM invoices
              WHERE status = 'paid' AND deleted_at IS NULL AND YEAR(paid_date) = ? AND MONTH(paid_date) = ?",
            [(int) $m->format('Y'), (int) $m->format('n')]
        )['a'];
        $state['sql_ps'][] = $v === null ? null : round((float) $v, 1);
    }
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
parse_str($query, $_GET);
@session_start();
$u = db_row(
    "SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL
      ORDER BY (u.email = 'test-superadmin@fleetforge.test') DESC, u.id LIMIT 1"
);
if (!$u) { $state['error'] = 'missing-user'; exit; }
@auth_login($u);
$state['c0'] = $counters();
require $root . '/api/v1/dashboard/charts.php';
PHP);

/**
 * Run the charts endpoint (the four month keys) through the harness.
 *
 * @param string $pin     business date to pin
 * @param bool   $fixture insert the payment_speed fixture
 * @return array harness state + 'data' (decoded endpoint data) + 'stmts'/'replaces' deltas
 */
function run_charts(string $pin, bool $fixture = false): array
{
    global $harnessFile, $ROOT;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' '
        . escapeshellarg($ROOT) . ' ' . escapeshellarg($pin) . ' '
        . escapeshellarg('charts=' . implode(',', MW_KEYS)) . ' ' . ($fixture ? '1' : '0') . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@STATE@@');
    if ($pos === false) {
        fwrite(STDERR, "harness produced no state for {$pin}:\n" . substr($out, 0, 800) . "\n");
        exit(2);
    }
    $state = json_decode(substr($out, $pos + 9), true) ?: [];
    $body  = (string) ($state['output'] ?? '');
    $s     = strpos($body, '{"');
    $json  = $s !== false ? json_decode(substr($body, $s), true) : null;
    $state['ok']       = ($json['success'] ?? null) === true;
    $state['data']     = $json['data'] ?? null;
    $state['stmts']    = ($state['c1']['Com_stmt_execute'] ?? 0) - ($state['c0']['Com_stmt_execute'] ?? 0);
    $state['replaces'] = ($state['c1']['Com_replace'] ?? 0) - ($state['c0']['Com_replace'] ?? 0);
    return $state;
}

/**
 * Expected values for the four charts at $pin, recomputed per month with the
 * pre-S-PERF-3 query shape (one query per month).
 *
 * @param string $pin business date
 * @return array<string, mixed>
 */
function expected_values(string $pin): array
{
    $rf = [];
    foreach (exp_months($pin, 0, 5) as $m) {
        $t = '0.00';
        foreach (db_select(
            "SELECT monthly_rate, weekly_rate, daily_rate FROM leases
              WHERE status = 'active' AND deleted_at IS NULL
                AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)",
            [$m['end'], $m['start']]
        ) as $l) {
            $t = bcadd($t, match (true) {
                bccomp((string) $l['monthly_rate'], '0', 2) > 0 => (string) $l['monthly_rate'],
                bccomp((string) $l['weekly_rate'], '0', 2) > 0  => bcmul((string) $l['weekly_rate'], '4.33', 2),
                default                                          => bcmul((string) $l['daily_rate'], (string) $m['days'], 2),
            }, 2);
        }
        $rf[] = $t;
    }
    $le = [];
    foreach (exp_months($pin, 0, 11) as $m) {
        $le[] = (int) db_row(
            "SELECT COUNT(*) AS n FROM leases WHERE status = 'active' AND deleted_at IS NULL
                AND YEAR(end_date) = ? AND MONTH(end_date) = ?",
            [$m['y'], $m['n']]
        )['n'];
    }
    $ps = $op = $cl = [];
    foreach (exp_months($pin, -11, 0) as $m) {
        $a = db_row(
            "SELECT AVG(DATEDIFF(paid_date, invoice_date)) AS a FROM invoices
              WHERE status = 'paid' AND deleted_at IS NULL AND YEAR(paid_date) = ? AND MONTH(paid_date) = ?",
            [$m['y'], $m['n']]
        )['a'];
        $ps[] = $a === null ? null : round((float) $a, 1);
        $op[] = (int) db_row(
            "SELECT COUNT(*) AS n FROM leases WHERE deleted_at IS NULL
                AND YEAR(created_at) = ? AND MONTH(created_at) = ?",
            [$m['y'], $m['n']]
        )['n'];
        $cl[] = (int) db_row(
            "SELECT COUNT(*) AS n FROM leases WHERE deleted_at IS NULL AND status IN ('completed','cancelled')
                AND YEAR(updated_at) = ? AND MONTH(updated_at) = ?",
            [$m['y'], $m['n']]
        )['n'];
    }
    return ['rf' => $rf, 'le' => $le, 'ps' => $ps, 'op' => $op, 'cl' => $cl];
}

/** payment_speed value equality: null ≡ null, numbers compared as floats. */
function ps_eq(mixed $a, mixed $b): bool
{
    return ($a === null || $b === null) ? $a === $b : (float) $a === (float) $b;
}

echo "S-PERF-3 — dashboard month windows + batched month charts + payment speed by paid_date\n";
echo str_repeat('=', 78) . "\n";

try {
    // ── H: the real chart_months() for every day 2022–2028 ───────────────────
    echo "\nH chart_months() sweep (in-process, ff_today() pinned)\n";
    $chartsFile = $ROOT . '/api/v1/dashboard/charts.php';
    lift_function($chartsFile, 'chart_months');
    lift_function($chartsFile, 'chart_rolling_months');

    $windows  = ['past12' => [-11, 0], 'next6' => [0, 5], 'next12' => [0, 11], 'next1' => [1, 1]];
    $bad      = ['contract' => [], 'wrapper' => [], 'legacy_le28' => []];
    $legacyBroken = [];
    $days     = 0;
    $end      = new DateTimeImmutable('2028-12-31');
    for ($d = new DateTimeImmutable('2022-01-01'); $d <= $end; $d = $d->modify('+1 day')) {
        $days++;
        $pin = $d->format('Y-m-d');
        $GLOBALS['__ff_pin'] = $pin;
        foreach ($windows as $w => [$from, $to]) {
            $got = chart_months($from, $to);
            $exp = exp_months($pin, $from, $to);
            $ok  = count($got) === count($exp)
                && count(array_unique(array_column($got, 'ym'))) === count($exp);
            foreach ($exp as $i => $e) {
                $g  = $got[$i] ?? [];
                $ok = $ok && ($g['ym'] ?? '') === $e['ym'] && ($g['label'] ?? '') === $e['label']
                    && ($g['start'] ?? '') === $e['start'] && ($g['end'] ?? '') === $e['end']
                    && ($g['days'] ?? 0) === $e['days'];
            }
            if (!$ok && count($bad['contract']) < 5) {
                $bad['contract'][] = "{$pin} {$w}: " . implode(',', array_column($got, 'ym'));
            }
            $legacy = legacy_yms($pin, $from, $to);
            if ((int) $d->format('j') <= 28) {
                if (array_column($got, 'ym') !== $legacy && count($bad['legacy_le28']) < 5) {
                    $bad['legacy_le28'][] = "{$pin} {$w}";
                }
            } elseif ($legacy !== array_column($exp, 'ym')) {
                $legacyBroken[$pin] = true;
            }
        }
        if (chart_rolling_months() !== chart_months(-11, 0) && count($bad['wrapper']) < 5) {
            $bad['wrapper'][] = $pin;
        }
    }
    unset($GLOBALS['__ff_pin']);
    check("H.1 every day ({$days}): past-12 / next-6 / next-12 / next-1 windows are the exact distinct consecutive months (ym, label, start, end, days)",
        $bad['contract'] === [], implode(' | ', $bad['contract']));
    check('H.2 chart_rolling_months() == chart_months(-11, 0) on every day',
        $bad['wrapper'] === [], implode(', ', $bad['wrapper']));
    check('H.3 on every day ≤ 28 the months == the legacy strtotime("±N months") list (byte-identical there)',
        $bad['legacy_le28'] === [], implode(', ', $bad['legacy_le28']));
    $leg1031 = legacy_yms('2026-10-31', -11, 0);
    check('H.4 non-vacuous: the legacy list is broken on ' . count($legacyBroken) . ' of the 29th–31st days, e.g. 2026-10-31 past-12 has '
        . count(array_unique($leg1031)) . ' distinct months',
        count($legacyBroken) > 100 && count(array_unique($leg1031)) < 12, implode(',', $leg1031));

    // ── W: chart_cache_write() failure handling ──────────────────────────────
    // WHY: a cold charts response must never become a 500 because the cache
    // write failed (the cache is an optimisation). Exercised with the REAL
    // function; a FK-violating generated_by is a deterministic, non-deadlock
    // write error. In a transaction, a failed statement rolls back only
    // itself, so the valid write afterwards and the final rollback both work.
    echo "\nW chart_cache_write() (in-process, rolled back)\n";
    lift_function($chartsFile, 'chart_cache_write');
    $pdo     = db_pdo();
    $replace = static fn(): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_replace'")->fetch(PDO::FETCH_NUM)[1];
    $noUser  = (int) db_row("SELECT COALESCE(MAX(id), 0) + 1000000 AS n FROM users")['n'];
    $mkRow   = static fn(string $type, ?int $by): array => [
        $type, hash('sha256', $type . '|smoke-mw'), json_encode(['chart' => 'smoke']), '{"smoke":true}',
        ff_now_utc(), ff_now_utc('+15 minutes'), $by,
    ];
    $logFile = sys_get_temp_dir() . '/_ff_dash_month_errlog_' . getmypid() . '.log';
    $prevLog = ini_set('error_log', $logFile);
    $pdo->beginTransaction();
    try {
        $r0 = $replace();
        $threw = null;
        try {
            chart_cache_write([$mkRow('smoke_mw_b', null), $mkRow('smoke_mw_a', $noUser)]);
        } catch (\Throwable $t) {
            $threw = $t->getMessage();
        }
        $log = (string) @file_get_contents($logFile);
        check('W.1 a failing cache write (FK error) is swallowed, not thrown', $threw === null, (string) $threw);
        check('W.2 … and logged with the chart count', str_contains($log, 'report_cache write skipped (2 charts, attempt 1)'),
            substr($log, 0, 300));
        check('W.3 … and not retried (not a deadlock): exactly one REPLACE', $replace() - $r0 === 1,
            'replaces=' . ($replace() - $r0));
        check('W.4 … and nothing from the failed batch was written',
            (int) db_row("SELECT COUNT(*) AS n FROM report_cache WHERE report_type LIKE 'smoke\\_mw\\_%'")['n'] === 0);

        $r1 = $replace();
        chart_cache_write([$mkRow('smoke_mw_b', null), $mkRow('smoke_mw_a', null)]);
        check('W.5 a valid two-row batch is written with ONE REPLACE',
            $replace() - $r1 === 1
            && (int) db_row("SELECT COUNT(*) AS n FROM report_cache WHERE report_type LIKE 'smoke\\_mw\\_%'")['n'] === 2);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();                                            // hermetic
        }
        ini_set('error_log', $prevLog === false ? '' : $prevLog);
        @unlink($logFile);
    }

    // ── E: the real endpoint at pinned business dates ────────────────────────
    $realToday = ff_today();
    foreach ([$realToday, '2026-10-31', '2026-03-31', '2026-12-30', '2027-01-29', '2028-02-29'] as $pin) {
        echo "\nE endpoint, business date pinned to {$pin}\n";
        $r = run_charts($pin);
        $D = $r['data'];
        check("E.0 {$pin}: success, harness pinned ff_today() = {$pin}, the four keys returned",
            $r['ok'] && ($r['today'] ?? '') === $pin && is_array($D) && array_keys($D) === MW_KEYS,
            substr((string) ($r['output'] ?? ''), 0, 300));
        if (!is_array($D) || array_keys($D) !== MW_KEYS) {
            continue;
        }
        $past = array_column(exp_months($pin, -11, 0), 'label');
        check("E.1 {$pin}: labels — forecast next 6, expiry next 12 (from the pinned month), payment_speed + leases_trend rolling 12",
            ($D['revenue_forecast']['labels'] ?? null) === array_column(exp_months($pin, 0, 5), 'label')
            && ($D['lease_expiry_calendar']['labels'] ?? null) === array_column(exp_months($pin, 0, 11), 'label')
            && ($D['payment_speed']['labels'] ?? null) === $past
            && ($D['leases_trend']['labels'] ?? null) === $past,
            json_encode(array_map(static fn($c) => $c['labels'] ?? null, $D)));

        $e    = expected_values($pin);
        $rfV  = array_map(static fn($v) => sprintf('%.2f', (float) $v), $D['revenue_forecast']['series'][0]['data'] ?? []);
        $psV  = $D['payment_speed']['series'][0]['data'] ?? [];
        $psOk = count($psV) === 12;
        foreach ($e['ps'] as $i => $v) {
            $psOk = $psOk && array_key_exists($i, $psV) && ps_eq($psV[$i], $v);
        }
        check("E.2 {$pin}: revenue_forecast == per-month overlap query + bcmath ladder",
            $rfV === $e['rf'], 'api=' . json_encode($rfV) . ' sql=' . json_encode($e['rf']));
        check("E.3 {$pin}: lease_expiry_calendar == per-month COUNT by end_date",
            ($D['lease_expiry_calendar']['series'][0]['data'] ?? null) === $e['le'],
            'api=' . json_encode($D['lease_expiry_calendar']['series'][0]['data'] ?? null) . ' sql=' . json_encode($e['le']));
        check("E.4 {$pin}: payment_speed == per-month AVG(paid_date − invoice_date) by paid_date",
            $psOk, 'api=' . json_encode($psV) . ' sql=' . json_encode($e['ps']));
        check("E.5 {$pin}: leases_trend opened/closed == per-month COUNTs",
            ($D['leases_trend']['series'][0]['data'] ?? null) === $e['op'] && ($D['leases_trend']['series'][1]['data'] ?? null) === $e['cl'],
            'api=' . json_encode(array_column($D['leases_trend']['series'] ?? [], 'data')) . ' sql=' . json_encode([$e['op'], $e['cl']]));
        check("E.6 {$pin}: four cold builds cached with ONE multi-row REPLACE (was 4); {$r['stmts']} prepared statements (< 20; HEAD: 64)",
            $r['replaces'] === 1 && $r['stmts'] > 0 && $r['stmts'] < 20,
            "replaces={$r['replaces']} stmts={$r['stmts']}");
    }

    // ── R: payment_speed survives a later pdf_path write ────────────────────
    foreach ([$realToday, '2026-10-31'] as $pin) {
        echo "\nR payment_speed re-dating guard, business date {$pin}\n";
        $base = run_charts($pin);
        $fx   = run_charts($pin, true);
        $f    = $fx['fixture'] ?? null;
        $past = exp_months($pin, -11, 0);
        $yms  = array_column($past, 'ym');
        $fIdx = is_array($f) ? array_search(ym_of((string) $f['paid_date']), $yms, true) : false;
        $uIdx = is_array($f) ? array_search(ym_of((string) $f['updated_at']), $yms, true) : false;
        check("R.1 {$pin}: non-vacuous — the pdf_path UPDATE re-dated the fixture's updated_at out of its paid month ("
            . ($f['paid_date'] ?? '?') . ' → ' . ($f['updated_at'] ?? '?') . ')',
            is_array($f) && $fIdx !== false && ym_of((string) $f['updated_at']) !== ym_of((string) $f['paid_date']),
            json_encode($f));
        $bv = $base['data']['payment_speed']['series'][0]['data'] ?? [];
        $fv = $fx['data']['payment_speed']['series'][0]['data'] ?? [];
        $all = count($fv) === 12 && is_array($fx['sql_ps'] ?? null);
        foreach (($fx['sql_ps'] ?? []) as $i => $v) {
            $all = $all && array_key_exists($i, $fv) && ps_eq($fv[$i], $v);
        }
        check("R.2 {$pin}: with the fixture, every month == the in-transaction SQL truth by paid_date",
            $all, 'api=' . json_encode($fv) . ' sql=' . json_encode($fx['sql_ps'] ?? null));
        if ($fIdx !== false) {
            check("R.3 {$pin}: the fixture counts in its paid_date month ({$past[$fIdx]['label']}: "
                . json_encode($bv[$fIdx] ?? null) . ' → ' . json_encode($fv[$fIdx] ?? null) . ')',
                !ps_eq($bv[$fIdx] ?? null, $fv[$fIdx] ?? null));
        }
        if ($uIdx !== false) {
            check("R.4 {$pin}: the month updated_at now points at ({$past[$uIdx]['label']}) is unchanged by the fixture",
                ps_eq($bv[$uIdx] ?? null, $fv[$uIdx] ?? null),
                'base=' . json_encode($bv[$uIdx] ?? null) . ' fixture=' . json_encode($fv[$uIdx] ?? null));
        } else {
            check("R.4 {$pin}: updated_at's month lies outside the window, so it cannot leak (window "
                . $yms[0] . '…' . $yms[11] . ')', is_array($f));
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
