<?php
declare(strict_types=1);

/**
 * lib/QboPushers/QboWebhookSignature.php
 *
 * HMAC-SHA256 webhook signature verifier — Intuit Webhooks spec
 * (QBO_SPEC §12.2). Used by api/v1/webhooks/qbo_payment_notifications.php
 * before processing any webhook event.
 *
 * Pattern: every Intuit webhook delivery carries an `intuit-signature`
 * header containing base64(hmac_sha256(raw_body, verifier_token)).
 * FF re-computes the same hash with its stored verifier_token and
 * compares via constant-time `hash_equals` to defend against timing
 * side-channels.
 *
 * The verifier_token is stored in settings as
 * `quickbooks.webhook_verifier_token` (is_sensitive=1 per D-QBO-1-1).
 * Operator obtains it from Intuit Developer dashboard when registering
 * the webhook URL.
 *
 * @session  S-QBO-13
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §12.2 (signature verification)
 * @decision D-QBO-13-3 (HMAC-SHA256 base64; constant-time compare;
 *           empty-token fail-closed)
 */

namespace FleetForge\QboPushers;

class QboWebhookSignature
{
    /**
     * Verify an Intuit webhook signature.
     *
     * Returns true ONLY when ALL of:
     *   - $verifierToken is non-empty (fail-closed when not configured —
     *     prevents accepting any webhook if operator forgot to set it).
     *   - $receivedSignature is non-empty.
     *   - HMAC-SHA256(rawBody, verifierToken) base64-encoded matches
     *     receivedSignature via constant-time compare.
     *
     * Returns false on any failure mode. Caller (the webhook endpoint)
     * MUST translate false → HTTP 403 + Sentry capture + early exit.
     *
     * @param  string $rawBody            Raw request body (file_get_contents('php://input'))
     * @param  string $verifierToken      The shared secret from settings
     * @param  string $receivedSignature  Value of HTTP_INTUIT_SIGNATURE header
     * @return bool                       true = valid, false = reject
     */
    public static function verify(string $rawBody, string $verifierToken, string $receivedSignature): bool
    {
        // Fail-closed on missing config — never accept webhooks when
        // verifier_token is empty (otherwise hash_hmac with '' key
        // produces a deterministic hash anyone could compute).
        if ($verifierToken === '') {
            error_log('[QboWebhookSignature] FAIL: quickbooks.webhook_verifier_token not configured');
            return false;
        }
        if ($receivedSignature === '') {
            return false;  // missing header — definitely not Intuit
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $verifierToken, true));

        // hash_equals is constant-time — protects against timing-based
        // signature-guessing attacks. NEVER use === for HMAC compares.
        return hash_equals($expected, $receivedSignature);
    }

    /**
     * Compute the expected signature for a given body + token. Useful
     * for the smoke test (which needs to forge a valid signature to
     * exercise the happy path).
     *
     * @param  string $rawBody
     * @param  string $verifierToken
     * @return string base64-encoded HMAC-SHA256
     */
    public static function compute(string $rawBody, string $verifierToken): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $verifierToken, true));
    }
}
