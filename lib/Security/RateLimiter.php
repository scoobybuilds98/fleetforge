<?php
declare(strict_types=1);

namespace FleetForge\Security;

/**
 * lib/Security/RateLimiter.php
 *
 * Fixed-window rate limiter backed by the rate_limit_attempts table.
 * One row per bucket_key; window resets when window_start ages past
 * the configured window.
 *
 * Bucket key conventions:
 *   'login:ip:{ip}'                    — IP-level login throttle
 *   'forgot_password:ip:{ip}'          — IP-level forgot-password throttle
 *   'mfa:user:{user_id}'               — per-user MFA challenge attempts
 *   'mfa:ip:{ip}'                      — IP-level MFA attempts (cross-user)
 *   'ai:user:{user_id}'                — AI endpoint requests per user
 *
 * D-F: IP detection reads CF-Connecting-IP (Cloudflare header) first,
 *      then falls back to REMOTE_ADDR. In dev REMOTE_ADDR is used.
 *
 * @session S-PROD-1A
 */
class RateLimiter
{
    /**
     * Check the bucket and increment the counter.
     *
     * Returns:
     *   ['allowed' => true,  'remaining' => N, 'retry_after_seconds' => 0]
     *   ['allowed' => false, 'remaining' => 0, 'retry_after_seconds' => N]
     *
     * If $blockMinutes > 0 and the threshold is exceeded, sets blocked_until.
     * The CURRENT request is counted; the threshold is the inclusive limit.
     * (attempts 1..threshold are allowed; attempt threshold+1 is blocked)
     */
    public static function check(
        string $bucketKey,
        int    $threshold,
        int    $windowMinutes,
        int    $blockMinutes = 0
    ): array {
        $now        = time();
        $windowSecs = $windowMinutes * 60;

        // S-RATELIMITER-TZ-FIX: compare timestamps as Unix integers, not parsed
        // datetime strings. window_start/blocked_until are stored by MySQL NOW()
        // under a UTC session timezone (db.php:65 SET time_zone='+00:00'), but
        // PHP's strtotime() parses bare 'Y-m-d H:i:s' strings in the script's
        // default timezone (APP_TIMEZONE=America/Vancouver). Same incident on
        // the write side — date('Y-m-d H:i:s', ...) emitted Vancouver-local
        // strings that MySQL then stored verbatim under +00:00. Net effect was
        // a ~7h skew that made window_start always look "in the future" and
        // blocked_until appear to last 7h longer than configured. Using
        // UNIX_TIMESTAMP() on read and FROM_UNIXTIME() on write keeps both
        // sides in epoch-int space — timezone-agnostic by construction.
        $row = db_row(
            "SELECT id, attempt_count,
                    UNIX_TIMESTAMP(window_start)  AS window_start_ts,
                    UNIX_TIMESTAMP(blocked_until) AS blocked_until_ts
             FROM rate_limit_attempts WHERE bucket_key = ?",
            [$bucketKey]
        );

        if ($row) {
            // ── Check active block ────────────────────────────────────────
            // UNIX_TIMESTAMP() returns NULL for a NULL column → no parse needed.
            if ($row['blocked_until_ts'] !== null) {
                $blockedUntilTs = (int) $row['blocked_until_ts'];
                if ($blockedUntilTs > $now) {
                    return [
                        'allowed'              => false,
                        'remaining'            => 0,
                        'retry_after_seconds'  => $blockedUntilTs - $now,
                    ];
                }
            }

            // ── Check if window has expired ───────────────────────────────
            $windowStartTs = (int) $row['window_start_ts'];
            if (($now - $windowStartTs) >= $windowSecs) {
                // Start a fresh window for this request
                db_execute(
                    "UPDATE rate_limit_attempts
                     SET attempt_count = 1, window_start = NOW(), last_attempt = NOW(),
                         blocked_until = NULL
                     WHERE bucket_key = ?",
                    [$bucketKey]
                );
                return [
                    'allowed'             => true,
                    'remaining'           => $threshold - 1,
                    'retry_after_seconds' => 0,
                ];
            }

            // ── Increment within the active window ────────────────────────
            $newCount = (int) $row['attempt_count'] + 1;

            if ($newCount > $threshold) {
                // S-RATELIMITER-TZ-FIX: pass the absolute Unix timestamp and
                // let MySQL convert via FROM_UNIXTIME — no PHP-side date()
                // formatting in any local timezone. FROM_UNIXTIME(NULL) → NULL,
                // which preserves the "no block window configured" branch.
                $blockedUntilTs = $blockMinutes > 0
                    ? $now + $blockMinutes * 60
                    : null;

                db_execute(
                    "UPDATE rate_limit_attempts
                     SET attempt_count = ?, last_attempt = NOW(),
                         blocked_until = FROM_UNIXTIME(?)
                     WHERE bucket_key = ?",
                    [$newCount, $blockedUntilTs, $bucketKey]
                );

                $retryAfter = $blockMinutes > 0
                    ? $blockMinutes * 60
                    : (int) ($windowSecs - ($now - $windowStartTs));

                return [
                    'allowed'             => false,
                    'remaining'           => 0,
                    'retry_after_seconds' => $retryAfter,
                ];
            }

            db_execute(
                "UPDATE rate_limit_attempts
                 SET attempt_count = ?, last_attempt = NOW()
                 WHERE bucket_key = ?",
                [$newCount, $bucketKey]
            );

            return [
                'allowed'             => true,
                'remaining'           => $threshold - $newCount,
                'retry_after_seconds' => 0,
            ];
        }

        // ── First attempt for this bucket ─────────────────────────────────
        db_execute(
            "INSERT INTO rate_limit_attempts (bucket_key, attempt_count, window_start, last_attempt)
             VALUES (?, 1, NOW(), NOW())",
            [$bucketKey]
        );

        return [
            'allowed'             => true,
            'remaining'           => $threshold - 1,
            'retry_after_seconds' => 0,
        ];
    }

    /**
     * Reset the bucket — call after a successful auth to forgive prior failures.
     * Also clears any active block so a legitimate user isn't locked after success.
     */
    public static function reset(string $bucketKey): void
    {
        db_execute(
            "DELETE FROM rate_limit_attempts WHERE bucket_key = ?",
            [$bucketKey]
        );
    }

    /**
     * Returns the client's real IP address.
     *
     * D-F: The app sits behind Cloudflare in production. Cloudflare sets
     * HTTP_CF_CONNECTING_IP to the visitor's real IP. Only trust this header
     * when it is present — it is not user-settable through Cloudflare's layer.
     * In dev (direct connection), REMOTE_ADDR is the correct value.
     *
     * X-Forwarded-For is intentionally ignored here: without a known trusted
     * proxy chain, XFF is trivially spoofable and cannot be trusted.
     */
    public static function getClientIp(): string
    {
        // Cloudflare sets this in production — safe to trust
        $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
