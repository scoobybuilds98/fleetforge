<?php
declare(strict_types=1);

/**
 * cron/qbo_token_refresh.php
 *
 * Daily cron — 02:00 server time. Watches the QBO refresh token's
 * expiry timestamp and forces a refresh when within 14 days of
 * expiring. The 14-day buffer means a failed rotation gives the
 * operator two weeks to manually re-authorize before the connection
 * dies completely (Intuit's hard expiry is 101 days of inactivity).
 *
 * On rotation success: log success + audit_log + exit 0.
 * On rotation failure: flip connection_status='expired',
 *   dispatch critical notification to ALL super_admins,
 *   log to audit_log + stderr, exit 0 (no retry within the same
 *   day — next day's cron tick will try again).
 *
 * Crontab: 0 2 * * * php /var/www/fleetforge/cron/qbo_token_refresh.php
 *   (Do NOT install this crontab in S-QBO-1 — documented only.
 *    Operator wires it during S-QBO-29 alongside the other QBO crons.)
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.3
 * Session:  S-QBO-1
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QuickBooksClient;
use FleetForge\Notifications\NotificationService;
use FleetForge\QboPushers\CompanyInfoSync;

// ── D21 advisory lock ──────────────────────────────────────────
// GET_LOCK with timeout=0 means: acquire-or-fail-immediately.
// If a prior tick is still running (shouldn't happen daily but
// defensive against an operator manually invoking) we exit cleanly.
$lock = db_row("SELECT GET_LOCK('ff_qbo_token_refresh', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/qbo_token_refresh: another instance is running — exiting.');
    exit(0);
}

try {
    $refreshExpiresAtRaw = (string) settings_get('quickbooks.refresh_token_expires_at', '');

    if ($refreshExpiresAtRaw === '') {
        // Never connected (or freshly disconnected). Nothing to do.
        echo "QBO token refresh: no refresh_token on file — skipping.\n";
        exit(0);
    }

    $expiresTs = strtotime($refreshExpiresAtRaw);
    if ($expiresTs === false) {
        error_log("cron/qbo_token_refresh: malformed refresh_token_expires_at='{$refreshExpiresAtRaw}' — skipping.");
        exit(0);
    }

    $secondsRemaining = $expiresTs - time();
    $daysRemaining    = (int) floor($secondsRemaining / 86400);

    // ── Healthy: > 14 days to refresh expiry ──────────────────
    // Bail early — no API call needed. Logged so the operator can
    // confirm the cron is firing.
    if ($secondsRemaining > 14 * 86400) {
        echo "QBO token refresh: healthy, {$daysRemaining} days to refresh expiry, no action needed.\n";
        exit(0);
    }

    // ── Within 14-day window: refresh now ─────────────────────
    echo "QBO token refresh: {$daysRemaining} days to refresh expiry — refreshing now.\n";

    try {
        $client = new QuickBooksClient();
        $client->refreshAccessToken();

        // Success branch — log to audit_log so the operator can
        // confirm refreshes are happening on schedule.
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'quickbooks',
            'entity_type' => 'qbo_oauth_connection',
            'notes'       => "Token refresh successful via pinger cron (was {$daysRemaining} days from expiry).",
            'ip_address'  => '127.0.0.1',
        ]);

        // D-QBO-FIXPACK-11: Re-sync CompanyInfo on each token refresh so
        // quickbooks.multi_currency_enabled stays current if the operator
        // ever toggles multi-currency in their QBO account settings.
        // Non-fatal: a failed CompanyInfo sync doesn't abort the token rotation.
        try {
            CompanyInfoSync::syncFromQbo($client);
        } catch (\Throwable $companyInfoErr) {
            error_log('cron/qbo_token_refresh: CompanyInfo sync failed after rotation: ' . $companyInfoErr->getMessage());
        }

        echo "QBO token refresh: rotation OK.\n";
    } catch (\Throwable $e) {
        // Failure branch — refreshAccessToken already wrote
        // connection_status='error'/'expired' + connection_error.
        // Capture in Sentry so the team sees rotation failures even
        // when the operator hasn't checked the in-app notification bell.
        \FleetForge\Observability\Sentry::captureException($e);
        // We additionally:
        //   1. Flip explicitly to 'expired' (override 'error' if
        //      refreshAccessToken set that). Once we get into the
        //      14-day window and rotation fails, the operator
        //      mental model is "expired", not "transient error".
        //   2. Notify all super_admins via the in-app notification
        //      bell + a critical severity flag.
        QuickBooksClient::settings_write_qbo('connection_status', 'expired');

        $superAdminIds = array_column(
            db_select(
                "SELECT u.id
                   FROM users u
                   JOIN user_roles r ON r.id = u.role_id
                  WHERE r.slug = 'super_admin'
                    AND u.status = 'active'
                    AND u.deleted_at IS NULL",
                []
            ),
            'id'
        );
        $superAdminIds = array_map('intval', $superAdminIds);

        if ($superAdminIds !== []) {
            NotificationService::notify(
                'qbo_token_expiry_warning',
                'QBO connection at risk',
                'QBO refresh token rotation failed. Re-authorize at Settings → QuickBooks before the existing token expires.',
                'qbo_oauth_connection',
                null,
                '/quickbooks/settings',
                $superAdminIds,
                'critical'
            );
        }

        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'quickbooks',
            'entity_type' => 'qbo_oauth_connection',
            'notes'       => 'Token refresh FAILED in pinger cron ('
                . $daysRemaining . ' days to expiry). Error: '
                . QuickBooksClient::truncateError($e->getMessage())
                . '. Super_admin notified.',
            'ip_address'  => '127.0.0.1',
        ]);

        fwrite(STDERR, "QBO token refresh FAILED: " . $e->getMessage() . "\n");
        // Exit 0 — the failure has been recorded + operators notified.
        // Exiting non-zero would mostly just spam the cron log.
    }
} catch (\Throwable $e) {
    // Unexpected outer error (e.g. DB down, settings_get failure).
    error_log('cron/qbo_token_refresh: unexpected error: ' . $e->getMessage());
    \FleetForge\Observability\Sentry::captureException($e);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_qbo_token_refresh')", []);
}
