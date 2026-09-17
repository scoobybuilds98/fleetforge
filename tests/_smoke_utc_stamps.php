<?php
/**
 * tests/_smoke_utc_stamps.php
 *
 * S-UTC-STAMPS regression — the formerly "consistent-local" DATETIME columns now
 * store UTC, and the one-time repair (FleetForge\Support\LocalStampMigrator,
 * run by scripts/migrate_local_stamps_to_utc.php) shifts historical Pacific
 * wall-time values correctly.
 *
 *   M  migrator, value mode: values on both sides of both DST transitions shift by
 *      exactly 7h (PDT) or 8h (PST) — incl. the ambiguous fall-back hour and the
 *      spring-forward gap — values at/above the local cutover are untouched, a
 *      second run is a no-op (settings marker), ON UPDATE updated_at is pinned.
 *   F  migrator, row-filter mode (future-dated expiries): a pre-deploy row (UTC
 *      updated_at below the UTC cutover) converts even though its value is ABOVE
 *      the local cutover; a post-deploy row is left alone; a row-filter call with
 *      no predicate is refused.
 *   D  ff_now_utc('+N') is DST-safe (a UTC clock, not the Pacific wall clock).
 *   Q  QBO credit memo TxnDate = the company-local day of UTC created_at
 *      (a credit note created at 10pm Pacific is not pushed dated tomorrow).
 *
 * Everything runs inside one transaction that is ROLLED BACK — nothing persists
 * (the settings markers included).
 *
 * USAGE: php tests/_smoke_utc_stamps.php      EXIT: 0 pass / 1 fail
 *
 * @session S-UTC-STAMPS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Support\LocalStampMigrator as M;

$failures = [];
$passes   = 0;

/**
 * Record one assertion.
 *
 * @param string $label
 * @param bool   $ok
 * @param string $detail shown on failure
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

echo "S-UTC-STAMPS — UTC DATETIME writers + local→UTC repair\n";
echo str_repeat('=', 78) . "\n\n";

if (ff_business_timezone()->getName() !== 'America/Vancouver') {
    echo "  (skipped: fixtures assume America/Vancouver; business tz is " . ff_business_timezone()->getName() . ")\n";
    exit(0);
}

$pdo = db_pdo();
$pdo->beginTransaction();
try {
    $tag = 'SMOKE-UTCSTAMPS-' . getmypid();
    $cut = '2026-11-15 00:00:00';                              // local cutover wall time

    // A database the real repair already ran on carries completion markers for
    // registry columns (e.g. customer_credit_applications.token_expires_at) that
    // would turn the fixtures below into 'already_done'. Clear them — rolled back.
    db_execute("DELETE FROM settings WHERE `key` LIKE ?", [M::MARKER_PREFIX . '%']);

    // ── M: value mode on notifications.created_at + customers.created_at ────
    $uid   = (int) db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'];
    $cases = [
        '2026-01-15 10:00:00' => '2026-01-15 18:00:00',        // PST  +8
        '2026-07-01 23:30:00' => '2026-07-02 06:30:00',        // PDT  +7, crosses the date
        '2026-03-08 01:59:59' => '2026-03-08 09:59:59',        // last PST second
        '2026-03-08 02:30:00' => '2026-03-08 09:30:00',        // spring-forward gap → PDT
        '2026-11-01 01:30:00' => '2026-11-01 08:30:00',        // ambiguous hour → first (PDT)
        '2026-11-01 02:30:00' => '2026-11-01 10:30:00',        // after fall-back → PST
    ];
    $ids = [];
    foreach ($cases as $local => $utc) {
        $ids[$local] = db_insert('notifications', ['user_id' => $uid, 'title' => $tag, 'message' => $tag, 'category' => 'system', 'created_at' => $local]);
    }
    $above = db_insert('notifications', ['user_id' => $uid, 'title' => $tag, 'message' => $tag, 'category' => 'system', 'created_at' => '2026-12-01 12:00:00']);

    $dry = M::convert('notifications', 'created_at', $cut, 'message = ?', [$tag], false);
    check('M.1 dry run counts the 6 pre-cutover fixtures and writes nothing',
        $dry['status'] === 'would_convert' && $dry['rows'] === 6
        && db_row("SELECT created_at FROM notifications WHERE id = ?", [$ids['2026-01-15 10:00:00']])['created_at'] === '2026-01-15 10:00:00',
        json_encode($dry));
    $r = M::convert('notifications', 'created_at', $cut, 'message = ?', [$tag], true);
    $got = [];
    foreach ($cases as $local => $utc) {
        $got[$local] = db_row("SELECT created_at FROM notifications WHERE id = ?", [$ids[$local]])['created_at'];
    }
    check('M.2 each value shifted by its own PST/PDT offset (DST gap + ambiguous hour included)',
        $r['status'] === 'converted' && $r['rows'] === 6 && $got === array_combine(array_keys($cases), array_values($cases)),
        json_encode($got));
    check('M.3 a value at/above the local cutover is untouched',
        db_row("SELECT created_at FROM notifications WHERE id = ?", [$above])['created_at'] === '2026-12-01 12:00:00');
    check('M.4 second run is a no-op (settings marker)',
        M::convert('notifications', 'created_at', $cut, 'message = ?', [$tag], true)['status'] === 'already_done'
        && $got['2026-01-15 10:00:00'] === db_row("SELECT created_at FROM notifications WHERE id = ?", [$ids['2026-01-15 10:00:00']])['created_at']);

    $cid = db_insert('customers', ['company_name' => $tag, 'created_at' => '2026-02-01 09:00:00', 'updated_at' => '2020-01-01 00:00:00']);
    M::convert('customers', 'created_at', $cut, 'company_name = ?', [$tag], true);
    $crow = db_row("SELECT created_at, updated_at FROM customers WHERE id = ?", [$cid]);
    check('M.5 ON UPDATE updated_at is pinned while created_at converts',
        $crow['created_at'] === '2026-02-01 17:00:00' && $crow['updated_at'] === '2020-01-01 00:00:00', json_encode($crow));

    // ── F: row-filter mode for future-dated expiries ────────────────────────
    $cutUtc = '2026-11-15 08:00:00';                            // = $cut in PST
    $pre = db_insert('customer_credit_applications', [
        'customer_id' => (int) db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'],
        'token_hash' => hash('sha256', random_bytes(16)), 'status' => 'sent',
        'token_expires_at' => '2026-12-10 09:00:00',            // local, ABOVE the local cutover
        'updated_at' => '2026-11-10 12:00:00',                  // written pre-deploy
    ]);
    $post = db_insert('customer_credit_applications', [
        'customer_id' => (int) db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'],
        'token_hash' => hash('sha256', random_bytes(16)), 'status' => 'sent',
        'token_expires_at' => '2026-12-16 09:00:00',            // already UTC (post-deploy writer)
        'updated_at' => '2026-11-16 09:00:00',                  // written post-deploy
    ]);
    $rf = M::convert('customer_credit_applications', 'token_expires_at', $cut, 'updated_at < ? AND id IN (?, ?)', [$cutUtc, $pre, $post], true, false);
    $v = static fn(int $id): string => (string) db_row("SELECT token_expires_at FROM customer_credit_applications WHERE id = ?", [$id])['token_expires_at'];
    check('F.1 pre-deploy future-dated expiry converts (PST +8h) despite being above the local cutover',
        $rf['rows'] === 1 && $v($pre) === '2026-12-10 17:00:00', json_encode(['rows' => $rf['rows'], 'pre' => $v($pre)]));
    check('F.2 post-deploy (already UTC) row is left alone', $v($post) === '2026-12-16 09:00:00', $v($post));
    $refused = false;
    try {
        M::convert('customer_credit_applications', 'reviewed_at', $cut, null, [], false, false);
    } catch (\InvalidArgumentException) {
        $refused = true;
    }
    check('F.3 a row-filter conversion without a predicate is refused', $refused);

    // ── D: ff_now_utc offsets are DST-safe ──────────────────────────────────
    $a = new DateTimeImmutable(ff_now_utc(), new DateTimeZone('UTC'));
    $b = new DateTimeImmutable(ff_now_utc('+24 hours'), new DateTimeZone('UTC'));
    check('D.1 ff_now_utc(\'+24 hours\') is exactly 86,400s ahead of ff_now_utc()',
        abs(($b->getTimestamp() - $a->getTimestamp()) - 86400) <= 1);
    $nowSql = (string) db_row('SELECT UTC_TIMESTAMP() AS u')['u'];
    check('D.2 ff_now_utc() matches SQL UTC_TIMESTAMP()', abs(strtotime(ff_now_utc() . ' UTC') - strtotime($nowSql . ' UTC')) <= 2);

    // ── Q: QBO credit memo TxnDate is the local business day ────────────────
    $prevCode = settings_get('quickbooks.tax_override_code_id', '');
    if ($prevCode === '' || $prevCode === null) {
        db_execute("INSERT INTO settings (`key`, value, value_type, group_name) VALUES ('quickbooks.tax_override_code_id', 'NON', 'string', 'quickbooks') ON DUPLICATE KEY UPDATE value = 'NON'");
        settings_cache_flush();
    }
    $payload = \FleetForge\QboPushers\CreditMemoPusher::buildQboPayload(
        ['customer_id' => 1, 'amount' => '10.00', 'reason' => 'smoke', 'source' => 'manual',
         'credit_note_number' => 'CN-SMOKE', 'created_at' => '2026-09-17 05:00:00'],   // 10pm PDT on Sep 16
        ['qbo_customer_id' => '99'],
        '1'
    );
    check('Q.1 credit memo created 10pm Pacific pushes TxnDate = local day (2026-09-16), not UTC 09-17',
        ($payload['TxnDate'] ?? null) === '2026-09-16', json_encode($payload['TxnDate'] ?? null));
} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $failures[] = 'exception';
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    settings_cache_flush();
}

check('Z.1 rollback left no migration marker behind', !M::isDone('notifications', 'created_at'));

echo "\n" . str_repeat('=', 78) . "\n";
printf("%s — %d passed, %d failed\n", $failures ? 'FAILURES' : 'ALL PASS', $passes, count($failures));
exit($failures ? 1 : 0);
