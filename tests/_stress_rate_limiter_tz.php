<?php declare(strict_types=1);

/**
 * tests/_stress_rate_limiter_tz.php
 *
 * S-RATELIMITER-TZ-FIX — regression test for the RateLimiter clock-skew bug.
 *
 * The bug: window_start / blocked_until were stored under MySQL session
 * timezone +00:00 (UTC) but compared against PHP time() via strtotime() which
 * parses bare 'Y-m-d H:i:s' strings in the script's default timezone
 * (APP_TIMEZONE=America/Vancouver). That made window_start appear ~7h in the
 * future, so the "window expired" branch never fired and every attempt past
 * the first one tripped the threshold check.
 *
 * Tests (run in order; each verifies a specific state transition):
 *   T1 — first attempt creates row, allowed=true, count=1
 *   T2 — second attempt within window increments, allowed=true, count=2
 *   T3 — attempt crossing threshold blocks, allowed=false, retry_after > 0
 *   T4 — blocked_until honored on subsequent request (allowed=false)
 *   T5 — backdated window_start triggers reset (allowed=true, count=1)
 *        — this is the assertion the timezone bug was killing pre-fix
 *   T6 — block expiration: backdated blocked_until allows new attempt
 *   T7 — reset() clears the bucket entirely
 *
 * Pre-fix expected failures: T5 + T6 would have returned allowed=false
 * because strtotime() parsed the UTC string as Vancouver-local, shifting it
 * 7h forward and making the comparison `now - windowStart >= windowSecs`
 * always false.
 *
 * Usage:
 *   php tests/_stress_rate_limiter_tz.php
 *
 * Spec: S-RATELIMITER-TZ-FIX (2026-05-17)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/Security/RateLimiter.php';

use FleetForge\Security\RateLimiter;

// Use a synthetic bucket key so we never collide with real auth state.
$bucket = 'test:tz_fix:' . bin2hex(random_bytes(4));

// WHY: clean the row before AND after so a failed prior run can't poison
// this run, and so we don't leave test artifacts in the table.
function cleanup(string $bucket): void
{
    db_execute("DELETE FROM rate_limit_attempts WHERE bucket_key = ?", [$bucket]);
}
cleanup($bucket);

$failures = [];
$tests    = 0;

function assert_eq(string $label, mixed $expected, mixed $actual, array &$failures, int &$tests): void
{
    $tests++;
    if ($expected !== $actual) {
        $failures[] = sprintf("%s — expected %s, got %s",
            $label, var_export($expected, true), var_export($actual, true));
    }
}

function assert_true(string $label, bool $cond, array &$failures, int &$tests): void
{
    $tests++;
    if (!$cond) $failures[] = $label;
}

try {
    // ── T1: first attempt creates row ─────────────────────────────────────
    $r = RateLimiter::check($bucket, threshold: 3, windowMinutes: 5, blockMinutes: 10);
    assert_eq('T1 allowed',   true, $r['allowed'],   $failures, $tests);
    assert_eq('T1 remaining', 2,    $r['remaining'], $failures, $tests);
    $row = db_row("SELECT attempt_count FROM rate_limit_attempts WHERE bucket_key = ?", [$bucket]);
    assert_eq('T1 row count', 1, (int) $row['attempt_count'], $failures, $tests);

    // ── T2: increment within window ───────────────────────────────────────
    $r = RateLimiter::check($bucket, threshold: 3, windowMinutes: 5, blockMinutes: 10);
    assert_eq('T2 allowed',   true, $r['allowed'],   $failures, $tests);
    assert_eq('T2 remaining', 1,    $r['remaining'], $failures, $tests);

    // ── T3: cross threshold → block ───────────────────────────────────────
    $r = RateLimiter::check($bucket, threshold: 3, windowMinutes: 5, blockMinutes: 10);
    assert_eq('T3 allowed-3',  true, $r['allowed'],   $failures, $tests); // attempt 3 — still allowed
    $r = RateLimiter::check($bucket, threshold: 3, windowMinutes: 5, blockMinutes: 10);
    assert_eq('T3 allowed-4',     false, $r['allowed'],          $failures, $tests); // attempt 4 — blocked
    assert_true('T3 retry_after > 0', $r['retry_after_seconds'] > 0, $failures, $tests);

    // ── T4: blocked_until honored ─────────────────────────────────────────
    $r = RateLimiter::check($bucket, threshold: 3, windowMinutes: 5, blockMinutes: 10);
    assert_eq('T4 blocked again', false, $r['allowed'], $failures, $tests);

    // ── T5: backdate window_start so the "expired window" branch fires.
    //        Use UTC_TIMESTAMP - INTERVAL — db.php sets SET time_zone='+00:00'
    //        so UTC_TIMESTAMP() and NOW() are equal in our session, and any
    //        backdating done via SQL stays consistent with how the column
    //        was written. Also clear blocked_until so T5 is purely a
    //        window-expiry check, not a block-expiry check.
    //        Pre-fix this assertion failed: PHP-side strtotime() read the
    //        UTC string as Vancouver-local and made the row appear in the
    //        future, so ($now - $windowStart) was negative and the
    //        "expired" branch never fired.
    db_execute(
        "UPDATE rate_limit_attempts
         SET window_start  = DATE_SUB(NOW(), INTERVAL 1 HOUR),
             blocked_until = NULL,
             attempt_count = 3
         WHERE bucket_key = ?",
        [$bucket]
    );
    $r = RateLimiter::check($bucket, threshold: 3, windowMinutes: 5, blockMinutes: 10);
    assert_eq('T5 window reset → allowed', true, $r['allowed'],   $failures, $tests);
    assert_eq('T5 remaining after reset',  2,    $r['remaining'], $failures, $tests);
    $row = db_row("SELECT attempt_count FROM rate_limit_attempts WHERE bucket_key = ?", [$bucket]);
    assert_eq('T5 row count post-reset', 1, (int) $row['attempt_count'], $failures, $tests);

    // ── T6: backdate blocked_until — should be ignored as expired ─────────
    db_execute(
        "UPDATE rate_limit_attempts
         SET blocked_until = DATE_SUB(NOW(), INTERVAL 1 HOUR),
             window_start  = NOW(),
             attempt_count = 1
         WHERE bucket_key = ?",
        [$bucket]
    );
    $r = RateLimiter::check($bucket, threshold: 3, windowMinutes: 5, blockMinutes: 10);
    assert_eq('T6 expired block → allowed', true, $r['allowed'], $failures, $tests);

    // ── T7: reset() clears the row entirely ───────────────────────────────
    RateLimiter::reset($bucket);
    $row = db_row("SELECT id FROM rate_limit_attempts WHERE bucket_key = ?", [$bucket]);
    assert_eq('T7 row deleted', null, $row, $failures, $tests);

} finally {
    cleanup($bucket);
}

// ── Report ────────────────────────────────────────────────────────────────
$passed = $tests - count($failures);
echo "_stress_rate_limiter_tz: {$passed}/{$tests} PASS\n";
if ($failures) {
    foreach ($failures as $f) echo "  FAIL: {$f}\n";
    exit(1);
}
exit(0);
