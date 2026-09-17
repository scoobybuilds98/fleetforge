<?php
/**
 * tests/_smoke_local_day_timestamps.php
 *
 * S-LOCAL-DAY-TS regression — "today / this month" figures over UTC DATETIME
 * columns start at LOCAL midnight, and PHP-computed timestamps written into
 * UTC DATETIME columns are UTC.
 *
 * THE BUGS: includes/db.php pins the PDO session to '+00:00', so created_at /
 * updated_at defaults and NOW() are UTC, while PHP date('Y-m-d H:i:s') is
 * Pacific wall time.
 *   - Tiles filtering "DATE(created_at) = CURDATE()" / ">= UTC_DATE()" reset at
 *     UTC midnight = 5pm PDT / 4pm PST and counted last evening as today.
 *   - AI daily budget enforcement (TokenTracker::canSpend) used the same UTC day.
 *   - app/auth/forgot_password.php stored expires_at = local now + 1h, which
 *     reset_password.php compares to UTC NOW(): every admin reset link was
 *     already ~6h expired when it was emailed.
 *
 * HOW THE CLOCK IS INJECTED: scenarios run in a CLI subprocess with an outer
 * transaction (rolled back — nothing persists) and the SQL clock pinned with
 * `SET TIMESTAMP` to <local today + 1 day> 01:30:00 UTC = the Pacific evening of
 * local today, after the UTC day has rolled over. Fixture rows sit 1 second
 * before local midnight (yesterday), 1 second after (today) and 20h after
 * (local evening = UTC tomorrow).
 *
 *   H  helpers: local-midnight UTC instants across both DST transitions,
 *      month start, UTC→local day, ff_now_utc() == SQL NOW().
 *   P  api/v1/payments/kpis.php recorded_today_cnt: fixtures add exactly 2
 *      (legacy DATE(created_at)=CURDATE() predicate counts only 1 — non-vacuous).
 *   N  api/v1/notifications/index.php?date_range=today lists exactly the 2
 *      today fixtures (legacy ">= UTC_DATE()" lists 1).
 *   T  TokenTracker::getTodayUsage() counts the 2 today rows and canSpend()
 *      enforces the SAME window.
 *   R  app/auth/forgot_password.php (real POST, mail in log mode) stores a token
 *      that reset_password.php's reader accepts now and at +59 min and rejects
 *      at +61 min. Skipped when AWS mail credentials are configured.
 *
 * USAGE: php tests/_smoke_local_day_timestamps.php
 * EXIT:  0 = all pass, 1 = any failure, 2 = setup error.
 *
 * @session S-LOCAL-DAY-TS
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

// ── Harness: one scenario per subprocess, pinned SQL clock, rolled back ──────
$harnessFile = sys_get_temp_dir() . '/_ff_local_day_ts_harness_' . getmypid() . '.php';
file_put_contents($harnessFile, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
$root     = $argv[1];
$scenario = $argv[2];
$withFix  = ($argv[3] ?? '0') === '1';

ob_start();
$_SERVER['REMOTE_ADDR'] = '10.77.' . random_int(1, 250) . '.' . random_int(1, 250);   // fresh rate-limit bucket
$_SERVER['HTTP_HOST']   = 'localhost';
require $root . '/config/app.php';
require_once FF_ROOT . '/includes/auth.php';

$pdo   = db_pdo();
$today = ff_today();
$start = ff_local_day_start_utc($today);                                   // local 00:00 today, as UTC
$pin   = ff_local_date_add($today, 1) . ' 01:30:00';                       // local evening, UTC tomorrow
$pdo->beginTransaction();
$pdo->exec('SET TIMESTAMP = UNIX_TIMESTAMP(' . $pdo->quote($pin) . ')');

$state = ['today' => $today, 'start_utc' => $start, 'pin' => $pin];
$at = static fn(int $secs): string => gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + $secs);
$fixtureTimes = ['yesterday' => $at(-1), 'today_early' => $at(1), 'today_evening' => $at(20 * 3600)];

$user = db_row(
    "SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1"
);

register_shutdown_function(static function () use ($pdo, &$state) {
    $state['output'] = (string) ob_get_clean();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();                                                  // hermetic
    }
    echo "@@STATE@@" . json_encode($state);
});

switch ($scenario) {
    case 'payments_kpis':
        if ($withFix) {
            foreach ($fixtureTimes as $k => $ts) {
                db_insert('payments', [
                    'payment_number' => 'SMOKE-LDTS-' . getmypid() . '-' . $k, 'amount' => '1.00',
                    'payment_date' => $today, 'payment_method' => 'other', 'created_at' => $ts,
                ]);
            }
            // The pre-fix predicate under the pinned clock (non-vacuity proof).
            $state['legacy_cnt'] = (int) db_count(
                "SELECT COUNT(*) FROM payments WHERE payment_number LIKE ? AND DATE(created_at) = CURDATE()",
                ['SMOKE-LDTS-' . getmypid() . '-%']
            );
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        auth_login($user);
        require $root . '/api/v1/payments/kpis.php';
        break;

    case 'notifications_today':
        auth_login($user);
        $tag = 'SMOKE-LDTS-' . getmypid();
        foreach ($fixtureTimes as $k => $ts) {
            db_insert('notifications', [
                'user_id' => (int) $user['id'], 'title' => $tag . ' ' . $k, 'message' => $tag,
                'category' => 'system', 'created_at' => $ts,
            ]);
        }
        $state['expected_titles'] = [$tag . ' today_early', $tag . ' today_evening'];
        $state['legacy_cnt'] = (int) db_count(
            "SELECT COUNT(*) FROM notifications WHERE message = ? AND created_at >= UTC_DATE()", [$tag]
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['date_range' => 'today', 'category' => 'system', 'per_page' => '100', 'search' => $tag];
        require $root . '/api/v1/notifications/index.php';
        break;

    case 'token_tracker':
        $base = \FleetForge\AI\TokenTracker::getTodayUsage();
        foreach (['yesterday' => 1000, 'today_early' => 20000, 'today_evening' => 300000] as $k => $tokens) {
            db_insert('ai_query_log', [
                'query_type' => 'smoke_ldts', 'total_tokens' => $tokens, 'created_at' => $fixtureTimes[$k],
            ]);
        }
        $after = \FleetForge\AI\TokenTracker::getTodayUsage();
        $state['delta_tokens'] = (int) $after['tokens'] - (int) $base['tokens'];
        $used = (int) $after['tokens'];
        db_execute("UPDATE settings SET value = ? WHERE `key` = 'ai.daily_token_limit'", [(string) $used]);
        settings_cache_flush();
        $state['can_spend_at_limit'] = \FleetForge\AI\TokenTracker::canSpend(null);
        db_execute("UPDATE settings SET value = ? WHERE `key` = 'ai.daily_token_limit'", [(string) ($used + 1)]);
        settings_cache_flush();
        $state['can_spend_under_limit'] = \FleetForge\AI\TokenTracker::canSpend(null);
        $state['legacy_delta'] = (int) db_count(
            "SELECT COALESCE(SUM(total_tokens),0) FROM ai_query_log WHERE query_type = 'smoke_ldts' AND DATE(created_at) = CURDATE()"
        );
        break;

    case 'password_reset':
        $pdo->exec('SET TIMESTAMP = DEFAULT');                              // real clock for this scenario
        $email = 'smoke-ldts-' . bin2hex(random_bytes(4)) . '@fleetforge.test';
        $roleId = (int) db_row("SELECT id FROM user_roles WHERE slug = 'super_admin' LIMIT 1")['id'];
        $uid = db_insert('users', ['name' => 'LDTS Probe', 'email' => $email, 'role_id' => $roleId, 'status' => 'active']);
        @session_start();
        $_SESSION['csrf_token']    = 'smoketoken';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf_token' => 'smoketoken', 'email' => $email];
        require $root . '/app/auth/forgot_password.php';
        $row = db_row(
            "SELECT id, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs_left, NOW() AS db_now
               FROM password_reset_tokens WHERE user_id = ?", [$uid]
        );
        $state['secs_left'] = $row ? (int) $row['secs_left'] : null;
        if ($row) {
            $plain = str_repeat('cd', 32);
            db_execute("UPDATE password_reset_tokens SET token_hash = ? WHERE id = ?", [hash('sha256', $plain), $row['id']]);
            $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = ['token' => $plain];
            ob_start();
            require $root . '/app/auth/reset_password.php';                 // defines find_valid_reset_token()
            ob_end_clean();
            $state['accept_now'] = (bool) find_valid_reset_token($plain);
            $pdo->exec("SET TIMESTAMP = UNIX_TIMESTAMP(DATE_ADD(" . $pdo->quote($row['db_now']) . ", INTERVAL 59 MINUTE))");
            $state['accept_59'] = (bool) find_valid_reset_token($plain);
            $pdo->exec("SET TIMESTAMP = UNIX_TIMESTAMP(DATE_ADD(" . $pdo->quote($row['db_now']) . ", INTERVAL 61 MINUTE))");
            $state['accept_61'] = (bool) find_valid_reset_token($plain);
        }
        break;
}
PHP);

/**
 * Run one scenario through the harness.
 *
 * @param string $scenario payments_kpis|notifications_today|token_tracker|password_reset
 * @param bool   $fixture  insert fixtures (payments_kpis only; others always do)
 * @return array decoded harness state (+ 'json' = decoded endpoint body)
 */
function run_scenario(string $scenario, bool $fixture = true): array
{
    global $harnessFile, $ROOT;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile) . ' '
        . escapeshellarg($ROOT) . ' ' . escapeshellarg($scenario) . ' ' . ($fixture ? '1' : '0') . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@STATE@@');
    if ($pos === false) {
        fwrite(STDERR, "harness produced no state for {$scenario}:\n{$out}\n");
        exit(2);
    }
    $state = json_decode(substr($out, $pos + 9), true) ?: [];
    $state['json'] = json_decode((string) ($state['output'] ?? ''), true);
    return $state;
}

echo "S-LOCAL-DAY-TS — local-day windows over UTC timestamps + UTC writes\n";
echo str_repeat('=', 78) . "\n\n";

try {
    // ── H: helpers ──────────────────────────────────────────────────────────
    $tzName = ff_business_timezone()->getName();
    if ($tzName === 'America/Vancouver') {
        check('H.1 PDT local midnight = 07:00 UTC', ff_local_day_start_utc('2026-09-16') === '2026-09-16 07:00:00', ff_local_day_start_utc('2026-09-16'));
        check('H.2 PST local midnight = 08:00 UTC', ff_local_day_start_utc('2026-01-15') === '2026-01-15 08:00:00', ff_local_day_start_utc('2026-01-15'));
        check('H.3 spring-forward day starts in PST, next day in PDT',
            ff_local_day_start_utc('2026-03-08') === '2026-03-08 08:00:00' && ff_local_day_start_utc('2026-03-09') === '2026-03-09 07:00:00');
        check('H.4 fall-back day starts in PDT, next day in PST',
            ff_local_day_start_utc('2026-11-01') === '2026-11-01 07:00:00' && ff_local_day_start_utc('2026-11-02') === '2026-11-02 08:00:00');
        check('H.5 month start of a mid-March date is Mar 1 local (PST) midnight', ff_local_month_start_utc('2026-03-20') === '2026-03-01 08:00:00');
        check('H.6 01:30 UTC on Sep 17 is local Sep 16', ff_utc_to_local('2026-09-17 01:30:00') === '2026-09-16');
    } else {
        echo "  (H.1-H.6 skipped: business timezone is {$tzName}, fixtures assume America/Vancouver)\n";
    }
    $sqlNow = (string) db_row('SELECT NOW() AS n')['n'];
    check('H.7 ff_now_utc() matches SQL NOW() (UTC session)', abs(strtotime(ff_now_utc() . ' UTC') - strtotime($sqlNow . ' UTC')) <= 2,
        'php=' . ff_now_utc() . ' sql=' . $sqlNow);

    // ── P: payments "Recorded today" ────────────────────────────────────────
    $pBase = run_scenario('payments_kpis', false);
    $pFix  = run_scenario('payments_kpis', true);
    $cb = $pBase['json']['data']['recorded_today_cnt'] ?? null;
    $cf = $pFix['json']['data']['recorded_today_cnt'] ?? null;
    check('P.1 payments kpis endpoint returned success', $cb !== null && $cf !== null, substr((string) ($pFix['output'] ?? ''), 0, 300));
    check('P.2 legacy DATE(created_at)=CURDATE() counts only 1 of the 2 today fixtures under this clock (non-vacuous)',
        ($pFix['legacy_cnt'] ?? null) === 1, 'legacy=' . json_encode($pFix['legacy_cnt'] ?? null));
    check('P.3 Recorded today gains exactly the 2 local-today payments (not yesterday 23:59:59)',
        $cb !== null && $cf !== null && ((int) $cf - (int) $cb) === 2, "recorded_today_cnt {$cb} → {$cf}");

    // ── N: notifications "Today" filter ─────────────────────────────────────
    $n = run_scenario('notifications_today');
    $items  = $n['json']['data']['items'] ?? $n['json']['data']['notifications'] ?? null;
    $titles = is_array($items) ? array_values(array_filter(array_map(static fn($r) => $r['title'] ?? '', $items),
        static fn($t) => str_starts_with((string) $t, 'SMOKE-LDTS-'))) : null;
    if (is_array($titles)) sort($titles);
    $expT = $n['expected_titles'] ?? [];
    sort($expT);
    check('N.1 legacy ">= UTC_DATE()" keeps only 1 of the 2 today notifications (non-vacuous)', ($n['legacy_cnt'] ?? null) === 1,
        'legacy=' . json_encode($n['legacy_cnt'] ?? null));
    check('N.2 date_range=today lists exactly the 2 local-today notifications', $titles === $expT,
        'got=' . json_encode($titles) . ' expected=' . json_encode($expT) . ' body=' . substr((string) ($n['output'] ?? ''), 0, 200));

    // ── T: AI token budget window ───────────────────────────────────────────
    $t = run_scenario('token_tracker');
    check('T.1 getTodayUsage counts the 2 local-today rows (20,000 + 300,000), not yesterday 23:59:59',
        ($t['delta_tokens'] ?? null) === 320000, 'delta=' . json_encode($t['delta_tokens'] ?? null));
    check('T.2 legacy UTC-day window would have counted 300,000 (non-vacuous)', ($t['legacy_delta'] ?? null) === 300000,
        'legacy=' . json_encode($t['legacy_delta'] ?? null));
    check('T.3 canSpend() enforces the same window: blocked at limit = today usage, allowed at +1',
        ($t['can_spend_at_limit'] ?? null) === false && ($t['can_spend_under_limit'] ?? null) === true,
        json_encode(['at' => $t['can_spend_at_limit'] ?? null, 'under' => $t['can_spend_under_limit'] ?? null]));

    // ── R: password reset token lifetime ────────────────────────────────────
    $awsKey = (string) (settings_get('aws.access_key_id') ?: env('AWS_ACCESS_KEY_ID', ''));
    if (APP_ENV === 'production' || $awsKey !== '') {
        echo "  (R.* skipped: mail is not in log mode — the forgot-password page would send a real email)\n";
    } else {
        $r = run_scenario('password_reset');
        check('R.1 forgot_password stores a token expiring ~1h after SQL NOW() (was ~-6h)',
            isset($r['secs_left']) && $r['secs_left'] >= 3500 && $r['secs_left'] <= 3700, 'secs_left=' . json_encode($r['secs_left'] ?? null));
        check('R.2 reset_password reader accepts it now and at +59 min, rejects at +61 min',
            ($r['accept_now'] ?? null) === true && ($r['accept_59'] ?? null) === true && ($r['accept_61'] ?? null) === false,
            json_encode(['now' => $r['accept_now'] ?? null, '59' => $r['accept_59'] ?? null, '61' => $r['accept_61'] ?? null]));
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
