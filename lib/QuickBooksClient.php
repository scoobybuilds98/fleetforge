<?php
declare(strict_types=1);

/**
 * lib/QuickBooksClient.php
 *
 * Thin wrapper around the QuickBooks Online API. This class is the
 * SOLE outbound surface to QBO — every other piece of FF code that
 * talks to QBO must go through it. The wrapper exists so that we
 * can centralise: OAuth token management, base-URL switching
 * (sandbox vs production), error classification, Sentry
 * instrumentation, rate-limit throttling, and the tax-override
 * pattern (D-QBO-CORE-6).
 *
 * S-QBO-1 SCOPE — TOKEN MANAGEMENT ONLY:
 *   - ensureValidToken()          — fully implemented
 *   - refreshAccessToken()        — fully implemented
 *   - settings_write_qbo()        — private writer helper
 *
 * S-QBO-2 SCOPE — HTTP BOUNDARY (stubs in this session):
 *   - get / post / put            — HTTP verbs
 *   - query                       — SQL-over-API (QBO's SELECT idiom)
 *   - getEntity / createEntity / updateEntity — typed wrappers
 *   - getCompanyInfo              — minimal stub so Test Connection
 *                                   in S-QBO-1 doesn't fatal
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.1, §5.2, §5.3, §6.6
 * Session:  S-QBO-1 (token management) → S-QBO-2 (HTTP boundary)
 */

namespace FleetForge;

use RuntimeException;
use Throwable;

class QuickBooksClient
{
    /** Intuit OAuth + API endpoint hosts (constant across realms). */
    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    /** Refresh tokens that expire within this many seconds trigger an eager refresh. */
    private const ACCESS_TOKEN_REFRESH_WINDOW_SECONDS = 300; // 5 minutes

    /** API base URL — set per environment in __construct(). */
    private string $baseUrl;

    /** Active QBO company-file ID (the realm). Set per __construct(). */
    private string $realmId;

    /** Active bearer access token (loaded by ensureValidToken). */
    private string $accessToken = '';

    /**
     * Bootstrap from settings. Reads environment + realm_id; the
     * access_token is loaded on demand in ensureValidToken().
     */
    public function __construct()
    {
        $environment = (string) settings_get('quickbooks.environment', 'sandbox');
        $this->baseUrl = $environment === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';

        $this->realmId = (string) settings_get('quickbooks.realm_id', '');
    }

    /**
     * Read the active realm ID (for downstream callers that need to
     * build URLs themselves — discouraged; prefer this class's
     * verb methods).
     */
    public function getRealmId(): string
    {
        return $this->realmId;
    }

    /**
     * Read the active environment ('sandbox' or 'production').
     */
    public function getEnvironment(): string
    {
        return (string) settings_get('quickbooks.environment', 'sandbox');
    }

    /**
     * Ensure $this->accessToken holds a valid token, refreshing if
     * the stored access token expires within 5 minutes. Called by
     * every HTTP-issuing method before the request goes out.
     *
     * @throws RuntimeException when refreshAccessToken fails.
     */
    public function ensureValidToken(): void
    {
        $accessToken = (string) settings_get('quickbooks.access_token', '');
        $expiresAt   = (string) settings_get('quickbooks.access_token_expires_at', '');

        // No token at all — connection has never been established.
        // Refresh would fail too (no refresh_token), so surface the
        // condition clearly here rather than upstream.
        if ($accessToken === '' || $expiresAt === '') {
            throw new RuntimeException('QBO not connected — complete OAuth at Settings → QuickBooks before issuing API calls.');
        }

        $expiresTs = strtotime($expiresAt);
        if ($expiresTs === false) {
            // Malformed timestamp — be safe and force a refresh.
            $this->refreshAccessToken();
            $accessToken = (string) settings_get('quickbooks.access_token', '');
        } elseif (($expiresTs - time()) <= self::ACCESS_TOKEN_REFRESH_WINDOW_SECONDS) {
            // Inside the 5-minute window — refresh proactively.
            $this->refreshAccessToken();
            $accessToken = (string) settings_get('quickbooks.access_token', '');
        }

        $this->accessToken = $accessToken;
    }

    /**
     * Exchange the stored refresh_token for a new access_token +
     * rotated refresh_token. Updates the settings rows and the
     * connection_status flag.
     *
     * On failure, sets connection_status='error' (or 'expired' for
     * confirmed-expired refresh tokens) and throws RuntimeException
     * so the caller can decide on retry / notify behaviour.
     */
    public function refreshAccessToken(): void
    {
        $refreshToken = (string) settings_get('quickbooks.refresh_token', '');
        $clientId     = (string) settings_get('quickbooks.client_id', '');
        $clientSecret = (string) settings_get('quickbooks.client_secret', '');

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            $msg = 'QBO refresh failed — missing refresh_token, client_id, or client_secret in settings.';
            self::settings_write_qbo('connection_status', 'error');
            self::settings_write_qbo('connection_error',  $msg);
            throw new RuntimeException($msg);
        }

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            $errSummary = $curlErr !== '' ? $curlErr : (string) $body;
            // Intuit returns HTTP 400 with body {"error":"invalid_grant"}
            // once a refresh token is past its expiry — flip status to
            // 'expired' so the UI shows the "re-authorize required"
            // banner instead of the generic error.
            $isExpired = $httpCode === 400 && str_contains((string) $body, 'invalid_grant');
            self::settings_write_qbo('connection_status', $isExpired ? 'expired' : 'error');
            self::settings_write_qbo('connection_error',  self::truncateError("QBO token refresh HTTP {$httpCode}: {$errSummary}"));
            throw new RuntimeException("QBO token refresh failed (HTTP {$httpCode}): {$errSummary}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || empty($decoded['access_token']) || empty($decoded['refresh_token'])) {
            self::settings_write_qbo('connection_status', 'error');
            self::settings_write_qbo('connection_error',  'QBO token refresh returned malformed body.');
            throw new RuntimeException('QBO token refresh returned malformed body: ' . (string) $body);
        }

        // ── Persist the new token set ─────────────────────────
        // expires_in is seconds-until-access-expiry;
        // x_refresh_token_expires_in is seconds-until-refresh-expiry.
        $now           = time();
        $accessExpiry  = $now + (int) ($decoded['expires_in'] ?? 3600);
        $refreshExpiry = $now + (int) ($decoded['x_refresh_token_expires_in'] ?? 8726400); // 101 days default

        self::settings_write_qbo('access_token',              (string) $decoded['access_token']);
        self::settings_write_qbo('refresh_token',             (string) $decoded['refresh_token']);
        self::settings_write_qbo('access_token_expires_at',   date('Y-m-d H:i:s', $accessExpiry));
        self::settings_write_qbo('refresh_token_expires_at',  date('Y-m-d H:i:s', $refreshExpiry));
        self::settings_write_qbo('last_token_refresh_at',     date('Y-m-d H:i:s', $now));
        self::settings_write_qbo('connection_status',         'connected');
        self::settings_write_qbo('connection_error',          '');
    }

    // ============================================================
    // STUBS — S-QBO-2 will implement the HTTP boundary in full.
    // For S-QBO-1, the stubs exist so that test_connection.php and
    // the rest of the call surface load without fatal errors.
    // ============================================================

    /**
     * Stub for S-QBO-2. Returns minimal company info for the Test
     * Connection feature in S-QBO-1 so the operator can verify their
     * OAuth + credentials before any real sync work begins.
     *
     * Once S-QBO-2 lands, this method will actually call
     * GET /v3/company/{realmId}/companyinfo/{realmId} and return the
     * full CompanyInfo entity. For now, returning a minimal stub
     * with the realm ID confirms the token loaded successfully.
     *
     * @return array{success: bool, company_name: string, realm_id: string}
     */
    public function getCompanyInfo(): array
    {
        $this->ensureValidToken(); // raises if no valid token

        // TODO(S-QBO-2): real GET /v3/company/{realmId}/companyinfo/{realmId}
        return [
            'success'      => true,
            'company_name' => '(pending S-QBO-2 — real CompanyInfo fetch)',
            'realm_id'     => $this->realmId,
        ];
    }

    /** Stub — S-QBO-2 implements actual HTTP GET against $this->baseUrl. */
    public function get(string $path, array $query = []): array
    {
        $this->ensureValidToken();
        // TODO(S-QBO-2)
        return [];
    }

    /** Stub — S-QBO-2 implements actual HTTP POST against $this->baseUrl. */
    public function post(string $path, array $body): array
    {
        $this->ensureValidToken();
        // TODO(S-QBO-2)
        return [];
    }

    /** Stub — S-QBO-2 implements actual HTTP PUT against $this->baseUrl. */
    public function put(string $path, array $body): array
    {
        $this->ensureValidToken();
        // TODO(S-QBO-2)
        return [];
    }

    /** Stub — S-QBO-2 implements QBO SQL-over-API ("SELECT ..."). */
    public function query(string $qboSql): array
    {
        $this->ensureValidToken();
        // TODO(S-QBO-2)
        return [];
    }

    /** Stub — S-QBO-2 implements typed entity fetch. */
    public function getEntity(string $entityType, string $entityId): array
    {
        $this->ensureValidToken();
        // TODO(S-QBO-2)
        return [];
    }

    /** Stub — S-QBO-2 implements typed entity create. */
    public function createEntity(string $entityType, array $payload): array
    {
        $this->ensureValidToken();
        // TODO(S-QBO-2)
        return [];
    }

    /** Stub — S-QBO-2 implements typed entity update with SyncToken refresh. */
    public function updateEntity(string $entityType, string $entityId, array $payload): array
    {
        $this->ensureValidToken();
        // TODO(S-QBO-2)
        return [];
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    /**
     * settings_write_qbo — INSERT … ON DUPLICATE KEY UPDATE for a
     * single quickbooks.* setting. Static so refreshAccessToken()
     * can write without instantiating, and so the helper is callable
     * from outside (e.g. the OAuth callback) via reflection-free
     * `QuickBooksClient::settings_write_qbo(...)`.
     *
     * Idempotent: existing row → UPDATE value; missing row → INSERT
     * with sensible defaults (matches the brand.php canonical write
     * pattern). NEVER logs the value (audit-log discipline for
     * is_sensitive=1 rows).
     */
    public static function settings_write_qbo(string $shortKey, string $value): void
    {
        $key = 'quickbooks.' . $shortKey;
        db_execute(
            "INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `updated_at`)
             VALUES (?, ?, 'string', 'quickbooks', NOW())
             ON DUPLICATE KEY UPDATE
                `value`      = VALUES(`value`),
                `updated_at` = NOW()",
            [$key, $value]
        );
    }

    /**
     * truncateError — clamp an error message to 500 chars so the
     * connection_error setting doesn't blow up beyond reasonable UI
     * display. Used by refreshAccessToken + callback.php.
     */
    public static function truncateError(string $msg): string
    {
        return strlen($msg) > 500 ? (substr($msg, 0, 497) . '...') : $msg;
    }
}
