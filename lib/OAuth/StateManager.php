<?php
declare(strict_types=1);

/**
 * lib/OAuth/StateManager.php
 *
 * DB-backed OAuth state-token manager. Replaces session-based state
 * storage (the $_SESSION['*_oauth_state'] pattern from S-QBO-1) with
 * a persistent acc_oauth_states table. Solves K-22 Trap #59 (OAuth
 * callbacks under ngrok / any cross-origin redirect lose the session
 * cookie, so session-backed state verification always fails).
 *
 * Constitutive principle (D-QBO-OAUTH-FIX-2): OAuth callbacks are
 * public endpoints; the state token IS the authentication proof,
 * NOT the user session. This class is the only correct way to mint
 * and verify those tokens for FleetForge's OAuth flows.
 *
 * Lifecycle per token:
 *   1. init.php calls generate() → INSERTs row with 10-min TTL +
 *      captures the initiating user_id for downstream audit.
 *   2. User redirected to provider (Intuit, future providers); state
 *      flows through provider's authorize URL as the ?state= param.
 *   3. Callback receives ?state=token; calls verifyAndConsume()
 *      which atomically marks the row used (UPDATE … WHERE used_at
 *      IS NULL — single-use enforcement via affected_rows).
 *   4. Caller uses returned initiated_by_user_id for audit attribution.
 *
 * Cleanup (D-QBO-OAUTH-FIX-3): generate() opportunistically deletes
 * rows where expires_at < NOW() - 24h. The 24h buffer keeps recent
 * expired rows for forensic audit ("operator X attempted to connect
 * but never came back — was that them?"). No dedicated cron needed.
 *
 * @session S-QBO-OAUTH-FIX
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §5.1 (OAuth flow)
 * @companion K-22 Trap #59 in FLEETFORGE_CLAUDE_CODE_REFERENCE.md §11
 */

namespace FleetForge\OAuth;

use RuntimeException;
use Throwable;

class StateManager
{
    /** Default token lifetime — 10 minutes is generous for slow OAuth
     *  flows (Intuit MFA, slow tabs) but narrows the attack window
     *  for any token that leaks. */
    public const DEFAULT_TTL_SECONDS = 600;

    /** Forensic buffer — expired rows are kept this long after expiry
     *  before cleanup deletes them. Lets operators audit recent
     *  failed/abandoned attempts. */
    public const CLEANUP_BUFFER_HOURS = 24;

    /**
     * Generate a new state token, persist to acc_oauth_states with
     * the configured TTL, and return the token for inclusion in the
     * provider's authorize URL.
     *
     * Side effect: opportunistically deletes acc_oauth_states rows
     * older than the CLEANUP_BUFFER_HOURS window before insert.
     * Cheap on a UNIQUE-indexed table.
     *
     * Token format: 64 hex chars = 32 bytes of entropy = 2^256
     * possible values. Brute-force search is infeasible within the
     * 10-minute TTL even on adversarial hardware.
     *
     * @param  string  $provider          One of the acc_oauth_states.provider ENUM values
     * @param  int     $ttlSeconds        Time-to-live in seconds (default 600)
     * @param  ?int    $initiatedByUserId User initiating the OAuth flow (captured for audit; null when no session)
     * @param  ?string $initiatedIp       Source IP at init time (captured for audit)
     * @return string  The state token to include in the authorize URL
     *
     * @throws RuntimeException when random_bytes or DB write fails
     */
    public static function generate(
        string $provider,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?int $initiatedByUserId = null,
        ?string $initiatedIp = null
    ): string {
        // Opportunistic cleanup of stale rows (D-QBO-OAUTH-FIX-3 — 24h
        // forensic buffer). Wrapped in try/catch — cleanup failure must
        // never block a new OAuth flow.
        try {
            db_execute(
                "DELETE FROM acc_oauth_states
                  WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
                [self::CLEANUP_BUFFER_HOURS]
            );
        } catch (Throwable $cleanupErr) {
            error_log('[StateManager.generate] cleanup failed (non-fatal): ' . $cleanupErr->getMessage());
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $randomErr) {
            throw new RuntimeException(
                'Failed to generate OAuth state token: ' . $randomErr->getMessage(),
                0,
                $randomErr
            );
        }

        // Use raw INSERT (not db_insert) so expires_at can be computed
        // by MySQL's clock via DATE_ADD(NOW(), …). Mixing PHP date() —
        // which respects php.ini timezone — with MySQL NOW() in the
        // verify-time predicate would put the two values on different
        // clocks and silently invalidate every token whenever the
        // process timezone disagreed with the DB timezone (Herd's
        // default PHP is America/Chicago; MySQL is UTC → 6h offset).
        db_execute(
            "INSERT INTO acc_oauth_states
                (state_token, provider, initiated_by_user_id, initiated_ip, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))",
            [$token, $provider, $initiatedByUserId, $initiatedIp, $ttlSeconds]
        );

        return $token;
    }

    /**
     * Atomically verify + consume a state token. Single-use semantics
     * enforced by UPDATE-with-condition — only the first call where
     * the row exists, hasn't expired, and hasn't been used wins. Any
     * subsequent call (or any call against a missing / expired /
     * tampered / wrong-provider token) returns null without throwing.
     *
     * Returns the originally-captured initiated_by_user_id so callers
     * can attribute the post-callback audit_log row to the operator
     * who started the flow — preserving audit attribution even though
     * the callback runs without an active session (D-QBO-OAUTH-FIX-4).
     *
     * @param  string  $token       The state token from the callback ?state= param
     * @param  string  $provider    Expected provider — must match what was stored at generation
     * @param  ?string $consumedIp  Source IP at consumption time (captured for audit)
     * @return array|null           ['initiated_by_user_id' => ?int, 'initiated_at' => string]
     *                              on success; null on ANY verification failure (missing,
     *                              expired, already used, wrong provider, tampered).
     */
    public static function verifyAndConsume(
        string $token,
        string $provider,
        ?string $consumedIp = null
    ): ?array {
        if ($token === '') {
            return null;
        }

        // Atomic single-use consumption: only succeeds when the row
        // exists AND not yet expired AND not yet used AND provider
        // matches. db_execute returns affected_rows so we can detect
        // race conditions without FOR UPDATE.
        $rows = db_execute(
            "UPDATE acc_oauth_states
                SET used_at = NOW(),
                    consumed_ip = ?
              WHERE state_token = ?
                AND provider = ?
                AND expires_at > NOW()
                AND used_at IS NULL",
            [$consumedIp, $token, $provider]
        );

        if ($rows === 0) {
            // One of: token doesn't exist, wrong provider, expired,
            // already used, or tampered. All treated as invalid — we
            // don't differentiate to avoid leaking which-failed
            // information to a potential attacker.
            return null;
        }

        // Successfully consumed; fetch the initiated context for audit.
        $row = db_row(
            "SELECT initiated_by_user_id, initiated_at
               FROM acc_oauth_states
              WHERE state_token = ? AND provider = ?",
            [$token, $provider]
        );

        // Normalise types: initiated_by_user_id may come back as
        // string from PDO; cast to int|null so callers don't have to.
        if ($row !== null && $row['initiated_by_user_id'] !== null) {
            $row['initiated_by_user_id'] = (int) $row['initiated_by_user_id'];
        }

        return $row;
    }

    /**
     * Manual cleanup — for scripts / crons / tests that need to
     * trigger the same opportunistic-cleanup logic generate() does.
     * Returns row count deleted so callers can log it.
     *
     * @param  int $bufferHours Keep expired-within-this-many-hours rows
     * @return int              Rows deleted
     */
    public static function cleanup(int $bufferHours = self::CLEANUP_BUFFER_HOURS): int
    {
        return (int) db_execute(
            "DELETE FROM acc_oauth_states
              WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$bufferHours]
        );
    }
}
