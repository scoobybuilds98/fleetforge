<?php
declare(strict_types=1);

/**
 * tests/_smoke_db_connect_retry.php — S-DB-CONNECT-RETRY
 *
 * Proves the DB-connection resilience that fixes the recurring prod Sentry issue
 * `PDOException: SQLSTATE[HY000] [2002] Connection refused` (includes/db.php:61).
 *
 * ROOT CAUSE (prod 2026-06-23 06:11, also 2026-06-03): unattended-upgrades applied
 * a mysql-server security update and restarted mysqld for ~10s; db_pdo() made a
 * SINGLE connect attempt, so every request in the window (often the high-frequency
 * unread-count pollers) threw an unhandled PDOException → 500 + Sentry event.
 *
 * FIX UNDER TEST: db_connect_with_retry() retries TRANSIENT connect failures with
 * bounded exponential backoff; db_is_transient_connect_error() classifies which
 * failures are worth retrying (server-unreachable) vs. fail-fast (bad creds / db).
 *
 * Coverage:
 *   T1 classifier — refused-port [2002] + crafted [2006]/[2013]/gone-away ⇒ transient;
 *      access-denied [1045] + crafted [1049] ⇒ NOT transient.
 *   T2 retry timing — connect to a refused port retries (elapsed ≥ backoff sum) then throws.
 *   T3 fail-fast — a non-transient error (bad password) throws on the first attempt (no backoff).
 *   T4 happy path — db_pdo() still returns a working connection (SELECT 1).
 *
 * Read-only: opens failed connections to a dead local port + a bad-credential auth
 * attempt against the real local DB. No writes, no prod access.
 *
 * Run: php tests/_smoke_db_connect_retry.php
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

$pass = 0;
$fail = 0;
function check(string $id, string $name, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS $id  $name — $msg\n"; }
    else     { $fail++; echo "  FAIL $id  $name — $msg\n"; }
}

echo "FleetForge — DB connect-retry smoke (S-DB-CONNECT-RETRY)\n";
echo str_repeat('=', 70) . "\n";

// A local TCP port with nothing listening → immediate RST → [2002] refused.
$deadPort = 59321;
$deadDsn  = sprintf('mysql:host=127.0.0.1;port=%d;dbname=%s;charset=utf8mb4', $deadPort, FF_DB_NAME);
$opts     = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

// ── T1: classifier ──────────────────────────────────────────────
// Real [2002] from the dead port.
$refused = null;
try { new PDO($deadDsn, FF_DB_USER, FF_DB_PASS, $opts); }
catch (\PDOException $e) { $refused = $e; }

// Real non-transient [1045] from the live host with a wrong password.
$badPass = null;
$realDsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', FF_DB_HOST, FF_DB_PORT, FF_DB_NAME);
try { new PDO($realDsn, FF_DB_USER, FF_DB_PASS . '_WRONG_' . bin2hex(random_bytes(3)), $opts); }
catch (\PDOException $e) { $badPass = $e; }

// Crafted message-only cases (no live server needed for these classes).
$goneAway = db_is_transient_connect_error(new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away'));
$lost     = db_is_transient_connect_error(new \PDOException('SQLSTATE[HY000] [2013] Lost connection to MySQL server during query'));
$unknown  = db_is_transient_connect_error(new \PDOException('SQLSTATE[HY000] [1049] Unknown database \'nope\''));

check('T1', 'transient vs permanent classification',
    $refused !== null && db_is_transient_connect_error($refused) === true
    && $badPass !== null && db_is_transient_connect_error($badPass) === false
    && $goneAway === true && $lost === true && $unknown === false,
    "refused([2002])=" . ($refused ? (db_is_transient_connect_error($refused) ? 'transient' : 'PERMANENT?') : 'no-exc')
    . " badpass([1045])=" . ($badPass ? (db_is_transient_connect_error($badPass) ? 'TRANSIENT?' : 'permanent') : 'no-exc')
    . " goneaway=" . ($goneAway ? 'transient' : 'no') . " lost=" . ($lost ? 'transient' : 'no')
    . " unknowndb=" . ($unknown ? 'TRANSIENT?' : 'permanent')
    . " (expect transient/permanent/transient/transient/permanent)");

// ── T2: transient error RETRIES then throws ─────────────────────
// maxRetries=2, base=50ms → sleeps 50+100 = 150ms before the 3rd attempt throws.
// A single attempt to a refused local port is ~sub-millisecond, so elapsed ≥ ~140ms
// proves the backoff loop actually ran.
$t0 = microtime(true);
$threw = false;
$transientThrown = false;
try {
    db_connect_with_retry($deadDsn, FF_DB_USER, FF_DB_PASS, $opts, 2, 50);
} catch (\PDOException $e) {
    $threw = true;
    $transientThrown = db_is_transient_connect_error($e);
}
$elapsedMs = (microtime(true) - $t0) * 1000;
check('T2', 'transient connect retries with backoff then throws',
    $threw && $transientThrown && $elapsedMs >= 140 && $elapsedMs < 3000,
    "threw=" . ($threw ? 'yes' : 'no') . " elapsed=" . round($elapsedMs) . "ms (expect threw + >=140ms backoff)");

// ── T3: non-transient error FAILS FAST (no retry/backoff) ───────
$t0 = microtime(true);
$threwFast = false;
try {
    db_connect_with_retry($realDsn, FF_DB_USER, FF_DB_PASS . '_WRONG_' . bin2hex(random_bytes(3)), $opts, 2, 50);
} catch (\PDOException $e) {
    $threwFast = true;
}
$fastMs = (microtime(true) - $t0) * 1000;
// A genuine bad-credential auth round-trip is fast and must NOT incur the 150ms
// backoff (it would if the loop wrongly treated [1045] as transient).
check('T3', 'permanent error fails fast (no backoff)',
    $threwFast && $fastMs < 140,
    "threw=" . ($threwFast ? 'yes' : 'no') . " elapsed=" . round($fastMs) . "ms (expect threw + <140ms, no retry)");

// ── T4: happy path — real connection still works ────────────────
$one = null;
try {
    $one = (int) (db_row("SELECT 1 AS one")['one'] ?? 0);
} catch (\Throwable $e) {
    $one = -1;
}
check('T4', 'db_pdo happy path still connects',
    $one === 1,
    "SELECT 1 => " . $one . " (expect 1)");

echo str_repeat('=', 70) . "\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
