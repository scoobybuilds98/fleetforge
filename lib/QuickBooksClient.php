<?php
declare(strict_types=1);

/**
 * lib/QuickBooksClient.php
 *
 * Thin wrapper around the QuickBooks Online API. This class is the
 * SOLE outbound surface to QBO — every other piece of FF code that
 * talks to QBO must go through it. The wrapper centralises:
 *   - OAuth token management (auto-refresh + rotation)
 *   - Base URL switching (sandbox vs production)
 *   - Error classification + typed exceptions
 *   - Retry orchestration (exponential backoff, Retry-After honoring)
 *   - Rate-limit awareness (X-RateLimit-Remaining throttling)
 *   - Sentry instrumentation on every failure
 *   - acc_qbo_sync_log writes for every call
 *
 * S-QBO-1 SCOPE — Token management (SHIPPED):
 *   - ensureValidToken(), refreshAccessToken(), settings_write_qbo()
 *
 * S-QBO-2 SCOPE — HTTP boundary (SHIPPED THIS SESSION):
 *   - get / post / put + query + getCompanyInfo
 *   - getEntity / createEntity / updateEntity
 *   - executeRequest (single attempt — used by worker for next_retry_at)
 *   - executeWithRetry (in-process retry — used by ad-hoc callers)
 *   - classifyError + writeSyncLog + captureSentry + throttle helpers
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.1, §5.2, §5.3, §6.5, §6.6,
 *           §13 (error handling), §14 (rate limits)
 * Session:  S-QBO-1 (token management) → S-QBO-2 (HTTP boundary)
 */

namespace FleetForge;

use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\QuickBooksAuthExpiredException;
use FleetForge\Exceptions\QuickBooksStaleObjectException;
use FleetForge\Exceptions\QuickBooksDuplicateNameException;
use FleetForge\Exceptions\QuickBooksValidationException;
use FleetForge\Exceptions\QuickBooksForbiddenException;
use FleetForge\Exceptions\QuickBooksNotFoundException;
use FleetForge\Exceptions\QuickBooksTransientException;
use FleetForge\Exceptions\QuickBooksRateLimitException;
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

    /** X-RateLimit-Remaining from the most recent response (null until first call). */
    private ?int $rateLimitRemaining = null;

    /** X-RateLimit-Reset (epoch seconds) from the most recent response. */
    private ?int $rateLimitReset = null;

    /** Retry-After hint (seconds) carried from a 429 response into the retry orchestrator. */
    private ?int $retryAfter = null;

    /**
     * Tracks whether the current logical operation has already
     * attempted a token refresh after a 401. Prevents an infinite
     * refresh loop when the freshly-refreshed token is also rejected.
     * Reset to false at the top of every executeWithRetry call.
     */
    private bool $authRetryDone = false;

    // ── D-QBO-FIXPACK-15: Static worker context ────────────────────
    // The sync worker (cron/qbo_sync_worker.php) sets these via
    // setWorkerContext() before dispatching each queue row. writeSyncLog()
    // falls back to these static values when $opts['queue_id'] / $opts['entity_id']
    // are absent — which they always are when a Pusher calls createEntity/
    // updateEntity without carrying the queue context (Pushers don't receive
    // the queue row; they only get $entityId + optional $payloadSnapshot).
    //
    // Static scope is intentional: a PHP CLI worker is single-threaded;
    // the worker clears context (null, null, null) after each queue row
    // completes so no row's context bleeds into the next.
    /** @var int|null Queue row ID from acc_qbo_sync_queue, set by worker */
    private static ?int $workerQueueId = null;
    /** @var string|null Entity type from queue row (e.g. 'customer') */
    private static ?string $workerEntityType = null;
    /** @var int|null FF entity ID from queue row */
    private static ?int $workerEntityId = null;

    /**
     * QBO Online minorversion. Locked as D-QBO-2-2 (see PROGRESS.md
     * DECISIONS). Bumping requires verifying the entity payload
     * shapes against Intuit's minorversion changelog.
     */
    private const QBO_MINORVERSION = '70';

    /** Maximum bytes persisted into acc_qbo_sync_log.response_payload (truncate beyond). */
    private const SYNC_LOG_PAYLOAD_LIMIT_BYTES = 65536; // 64 KB

    /** Maximum bytes attached to Sentry extra.response_body (truncate beyond). */
    private const SENTRY_RESPONSE_LIMIT_BYTES = 16384; // 16 KB

    /** cURL per-request timeout. QBO production p99 is ~3s; 30s is operator-friendly headroom. */
    private const HTTP_TIMEOUT_SECONDS = 30;

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
     * Set (or clear) worker context for sync_log enrichment.
     *
     * Called by cron/qbo_sync_worker.php before dispatching each queue row
     * so that sync_log entries written by Pushers (which don't carry queue
     * context themselves) are annotated with the correct queue_id + entity_id.
     *
     * Pass (null, null, null) to clear context between queue rows.
     *
     * Static so callers don't need a QuickBooksClient instance — the worker
     * can call this before constructing the client (or before dispatch()).
     *
     * @param int|null    $queueId    acc_qbo_sync_queue.id of the current row
     * @param string|null $entityType entity_type column value (e.g. 'customer')
     * @param int|null    $entityId   FF entity ID from queue row
     *
     * @session  S-QBO-FIXPACK-3 (D-QBO-FIXPACK-15 — Bug C fix)
     */
    public static function setWorkerContext(?int $queueId, ?string $entityType, ?int $entityId): void
    {
        self::$workerQueueId    = $queueId;
        self::$workerEntityType = $entityType;
        self::$workerEntityId   = $entityId;
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
    // PUBLIC HTTP API
    // ============================================================

    /**
     * GET an arbitrary QBO endpoint (relative to /v3/company/{realmId}/).
     *
     * @param string $endpoint Relative endpoint, e.g. 'customer/42'
     * @param array  $params   Query string params (minorversion is auto-appended)
     * @param array  $opts     Caller hints — supports 'no_retry' (bool),
     *                         'entity_type' (string), 'entity_id' (int),
     *                         'operation' (string), 'queue_id' (int)
     * @throws QuickBooksException family
     */
    public function get(string $endpoint, array $params = [], array $opts = []): array
    {
        return $this->dispatch('GET', $endpoint, ['query' => $params] + $opts);
    }

    /**
     * POST an entity body to a QBO endpoint.
     */
    public function post(string $endpoint, array $payload, array $opts = []): array
    {
        return $this->dispatch('POST', $endpoint, ['json' => $payload] + $opts);
    }

    /**
     * PUT an entity body to a QBO endpoint (QBO uses POST for most
     * updates — included for completeness).
     */
    public function put(string $endpoint, array $payload, array $opts = []): array
    {
        return $this->dispatch('PUT', $endpoint, ['json' => $payload] + $opts);
    }

    /**
     * Run a QBO Query SQL statement (e.g. SELECT * FROM Customer
     * WHERE Active = true). Returns the parsed QueryResponse block.
     *
     * Important: $sql is sent verbatim — caller is responsible for
     * any escaping needed by the QBO query language.
     *
     * Response normalization (S-QBO-5-FIX-1, K-22 Trap #60):
     * QBO returns entity collections under QueryResponse as: missing
     * (0 rows), bare object (1 row), array of objects (N>1 rows).
     * This method normalizes the 1-row case in-place — every
     * uppercase-keyed field under QueryResponse is guaranteed to be
     * an array after the call returns. Pusher / Puller authors can
     * iterate every entity collection without defensive wrapping.
     */
    public function query(string $sql, array $opts = []): array
    {
        $opts['operation']   = $opts['operation']   ?? 'query';
        $opts['entity_type'] = $opts['entity_type'] ?? 'query';
        $response = $this->dispatch('GET', 'query', ['query' => ['query' => $sql]] + $opts);
        return self::normalizeQueryResponse($response);
    }

    /**
     * Coerce single-object entity collections under QueryResponse
     * to single-element arrays. Public-static so the offline smoke
     * (tests/_smoke_qbo_client.php) can exercise it without going
     * through the cURL boundary — pattern mirrors the existing
     * _testClassify accessor used by the classifyError smoke.
     *
     * Heuristic: entity collections are uppercase-PascalCase
     * (Customer, Vendor, Invoice, CreditMemo, …). Metadata fields
     * are camelCase (startPosition, maxResults, totalCount) — keyed
     * with a lowercase first character. We walk QueryResponse,
     * skip metadata, and wrap any uppercase-keyed bare-object value
     * into a [value]-shaped array. Empty + already-arrayed values
     * pass through untouched. Top-level envelope keys (`time`, etc.)
     * are likewise untouched.
     *
     * @see K-22 Trap #60 in docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md
     */
    public static function normalizeQueryResponse(array $response): array
    {
        if (!isset($response['QueryResponse']) || !is_array($response['QueryResponse'])) {
            return $response;
        }
        foreach ($response['QueryResponse'] as $key => $value) {
            // Entity collections are uppercase-PascalCase. Metadata
            // fields (startPosition, maxResults, totalCount) start
            // lowercase — skip those without inspecting them.
            if ($key === '' || !ctype_upper($key[0])) {
                continue;
            }
            // Bare object (1 row): assoc array with no integer 0 key.
            // Empty arrays + already-indexed arrays pass through.
            if (is_array($value) && !array_key_exists(0, $value) && !empty($value)) {
                $response['QueryResponse'][$key] = [$value];
            }
        }
        return $response;
    }

    /**
     * Fetch a single entity by type + ID. QBO entity-type endpoint
     * paths are lowercase (customer, invoice, vendor, etc.) — we
     * lowercase the type defensively in case a caller passes the
     * pascal-case form from the spec (Customer, Invoice).
     */
    public function getEntity(string $type, string $id, array $opts = []): array
    {
        $opts['entity_type'] = $opts['entity_type'] ?? $type;
        $opts['entity_id']   = $opts['entity_id']   ?? (ctype_digit($id) ? (int) $id : null);
        $opts['operation']   = $opts['operation']   ?? 'get';
        return $this->get(strtolower($type) . '/' . urlencode($id), [], $opts);
    }

    /**
     * Create a new QBO entity. QBO returns the created entity with
     * its assigned Id + SyncToken — the caller is responsible for
     * persisting both into the acc_qbo_*_map row.
     */
    public function createEntity(string $type, array $data, array $opts = []): array
    {
        $opts['entity_type'] = $opts['entity_type'] ?? $type;
        $opts['operation']   = $opts['operation']   ?? 'create';
        return $this->post(strtolower($type), $data, $opts);
    }

    /**
     * Update an existing QBO entity. SyncToken must be the current
     * value from QBO — passing a stale token raises
     * QuickBooksStaleObjectException (code 5010) and the caller must
     * re-pull the entity, update its map row, and retry.
     *
     * Uses sparse=false (full replacement) — sparse=true is opt-in
     * via $opts['sparse'] = true for callers that need partial
     * updates.
     */
    public function updateEntity(string $type, string $id, string $syncToken, array $data, array $opts = []): array
    {
        $sparse = $opts['sparse'] ?? false;
        $merged = array_merge($data, [
            'Id'        => $id,
            'SyncToken' => $syncToken,
            'sparse'    => $sparse ? true : false,
        ]);

        $opts['entity_type'] = $opts['entity_type'] ?? $type;
        $opts['entity_id']   = $opts['entity_id']   ?? (ctype_digit($id) ? (int) $id : null);
        $opts['operation']   = $opts['operation']   ?? 'update';

        // QBO update endpoint pattern: POST /v3/company/{realmId}/{type}?operation=update
        return $this->dispatch('POST', strtolower($type) . '?operation=update', ['json' => $merged] + $opts);
    }

    /**
     * Fetch the realm's CompanyInfo entity. Used by
     * api/v1/quickbooks/test_connection.php to confirm the OAuth +
     * credentials are working end-to-end.
     *
     * QBO endpoint pattern: GET /v3/company/{realmId}/companyinfo/{realmId}
     * — the realm ID appears in both the URL prefix and the resource
     * path (QBO's API design quirk for this entity).
     *
     * @return array{success: bool, company_name: string, realm_id: string}
     */
    public function getCompanyInfo(): array
    {
        $response = $this->get(
            'companyinfo/' . $this->realmId,
            [],
            ['entity_type' => 'companyinfo', 'operation' => 'get']
        );

        $companyName = $response['CompanyInfo']['CompanyName']
            ?? $response['CompanyInfo']['LegalName']
            ?? '(unknown)';

        return [
            'success'      => true,
            'company_name' => (string) $companyName,
            'realm_id'     => $this->realmId,
        ];
    }

    // ============================================================
    // PRIVATE — REQUEST ORCHESTRATION
    // ============================================================

    /**
     * Dispatch a request. Routes to executeWithRetry by default;
     * when $opts['no_retry'] is true (queue worker context) executes
     * a single attempt and lets the caller schedule next_retry_at.
     */
    private function dispatch(string $method, string $endpoint, array $opts = []): array
    {
        if (!empty($opts['no_retry'])) {
            return $this->executeRequest($method, $endpoint, $opts);
        }
        return $this->executeWithRetry($method, $endpoint, $opts);
    }

    /**
     * In-process retry orchestration per spec §13.2. Used for
     * synchronous ad-hoc calls (test_connection, operator-initiated
     * pushes). The queue worker (S-QBO-3) bypasses this via
     * $opts['no_retry'] and schedules next_retry_at on the queue row
     * instead.
     *
     * Auth-expired (401) gets ONE token refresh + retry, NOT counted
     * against the retry budget. Subsequent 401s propagate as
     * QuickBooksAuthExpiredException so the operator can re-authorize.
     */
    private function executeWithRetry(string $method, string $endpoint, array $opts = []): array
    {
        $maxAttempts = (int) settings_get('quickbooks.retry.max_attempts', '5');
        $backoffBase = (int) settings_get('quickbooks.retry.backoff_base_seconds', '60');

        // Reset the auth-retry flag per logical operation.
        $this->authRetryDone = false;

        $attempt        = 0;
        $lastTransient  = null;
        $totalAttempts  = max(1, $maxAttempts + 1); // initial + N retries

        while ($attempt < $totalAttempts) {
            try {
                return $this->executeRequest($method, $endpoint, $opts);
            } catch (QuickBooksAuthExpiredException $e) {
                if ($this->authRetryDone) {
                    // Already refreshed once this op and still 401 —
                    // surface to caller so they can re-prompt OAuth.
                    $this->captureSentry($e, $opts, $attempt);
                    throw $e;
                }
                // Silent refresh + immediate retry. Does NOT count
                // against the retry budget (auth refresh is not a
                // failure mode the retry policy is meant to handle).
                $this->authRetryDone = true;
                try {
                    $this->refreshAccessToken();
                } catch (Throwable $refreshErr) {
                    // Refresh itself blew up — bubble the original
                    // 401 with the refresh error chained so the
                    // operator sees both layers in Sentry.
                    $wrapped = new QuickBooksAuthExpiredException(
                        $e->getMessage() . ' (refresh attempt also failed: ' . $refreshErr->getMessage() . ')',
                        $e->errorCode,
                        $e->httpStatus,
                        $e->faultDetail,
                        $refreshErr
                    );
                    $this->captureSentry($wrapped, $opts, $attempt);
                    throw $wrapped;
                }
                continue; // retry with fresh token, no budget increment
            } catch (QuickBooksRateLimitException $e) {
                // 429 internal flavor — convert to Transient after we
                // exhaust budget. Sleep per Retry-After if budget allows.
                $lastTransient = $e;
                if ($attempt < $maxAttempts) {
                    $sleep = $e->retryAfterSeconds ?? ($backoffBase * (2 ** $attempt));
                    sleep(max(0, $sleep));
                    $attempt++;
                    continue;
                }
                $transient = new QuickBooksTransientException(
                    'Rate limit exhausted after ' . $totalAttempts . ' attempts: ' . $e->getMessage(),
                    $e->errorCode,
                    $e->httpStatus,
                    $e->faultDetail,
                    $e
                );
                $this->captureSentry($transient, $opts, $attempt);
                throw $transient;
            } catch (QuickBooksTransientException $e) {
                $lastTransient = $e;
                if ($attempt < $maxAttempts) {
                    // Honor Retry-After if it was set by classifyError
                    // before throwing (rare path — most transient
                    // failures don't carry the header).
                    $sleep = $this->retryAfter ?? ($backoffBase * (2 ** $attempt));
                    $this->retryAfter = null;
                    sleep(max(0, $sleep));
                    $attempt++;
                    continue;
                }
                $this->captureSentry($e, $opts, $attempt);
                throw $e;
            } catch (QuickBooksException $e) {
                // Non-retryable category (validation, stale_object,
                // duplicate_name, forbidden, not_found, etc.) — fail
                // fast and let the caller decide what to do.
                $this->captureSentry($e, $opts, $attempt);
                throw $e;
            }
        }

        // Defensive — control should not reach here. If it does,
        // surface the last transient so the caller sees something.
        $err = $lastTransient ?? new QuickBooksException('Retry loop terminated without success or final throw.');
        $this->captureSentry($err, $opts, $attempt);
        throw $err;
    }

    /**
     * Single-attempt request execution. The worker (S-QBO-3) uses
     * this directly via $opts['no_retry'] and handles its own
     * retry timing via next_retry_at scheduling.
     *
     * Responsibilities (in order):
     *   1. Pre-throttle if close to rate limit
     *   2. Ensure valid token
     *   3. Build URL + headers + body
     *   4. cURL execute, time the call
     *   5. Update rate-limit instance state from response headers
     *   6. Write sync log row (guarded — table presence check)
     *   7. Classify and throw on non-2xx
     *   8. Return parsed JSON on 2xx
     */
    private function executeRequest(string $method, string $endpoint, array $opts = []): array
    {
        // ── Pre-throttle ───────────────────────────────────────
        $this->maybeThrottle($opts);

        $this->ensureValidToken();

        // ── Build URL + query ──────────────────────────────────
        $url = $this->baseUrl . '/v3/company/' . $this->realmId . '/' . ltrim($endpoint, '/');

        // Auto-append minorversion to every QBO call unless the
        // caller has explicitly set one in $opts['query'].
        $queryParams = $opts['query'] ?? [];
        if (!isset($queryParams['minorversion'])) {
            $queryParams['minorversion'] = self::QBO_MINORVERSION;
        }
        if (!empty($queryParams)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($queryParams);
        }

        // ── Build headers ──────────────────────────────────────
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json',
        ];
        $bodyJson = null;
        if (isset($opts['json'])) {
            $bodyJson = json_encode($opts['json']);
            $headers[] = 'Content-Type: application/json';
        }

        // ── Execute ────────────────────────────────────────────
        $startMs = microtime(true);
        $ch      = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true, // include headers in response body so we can parse
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($bodyJson !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        }

        $rawResponse = curl_exec($ch);
        $httpStatus  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr     = curl_error($ch);
        curl_close($ch);

        $durationMs = (int) round((microtime(true) - $startMs) * 1000);

        // ── Transport-level failure (cURL couldn't even connect) ──
        if ($rawResponse === false) {
            $msg = "QBO HTTP transport failure: {$curlErr}";
            $this->writeSyncLog([
                'direction'        => $this->inferDirection($opts),
                'entity_type'      => (string) ($opts['entity_type'] ?? 'unknown'),
                'entity_id'        => $opts['entity_id'] ?? null,
                'operation'        => (string) ($opts['operation'] ?? strtolower($method)),
                'http_method'      => $method,
                'endpoint'         => $endpoint,
                'request_payload'  => $bodyJson,
                'response_status'  => null,
                'response_payload' => null,
                'duration_ms'      => $durationMs,
                'error_code'       => 'TRANSPORT',
                'error_message'    => $msg,
                'queue_id'         => $opts['queue_id'] ?? null,
            ]);
            // Treat as transient — retry orchestrator will back off.
            throw new QuickBooksTransientException($msg, 'TRANSPORT', null);
        }

        // ── Split headers + body ───────────────────────────────
        $rawHeaders = substr((string) $rawResponse, 0, $headerSize);
        $body       = substr((string) $rawResponse, $headerSize);
        $parsedHdrs = $this->parseHeaders($rawHeaders);

        // ── Update rate-limit state ────────────────────────────
        $this->captureRateLimit($parsedHdrs);

        // ── Parse JSON body (QBO always returns JSON) ──────────
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        // ── Write sync log (guarded — drops silently if table absent) ──
        $logRow = [
            'direction'        => $this->inferDirection($opts),
            'entity_type'      => (string) ($opts['entity_type'] ?? 'unknown'),
            'entity_id'        => $opts['entity_id'] ?? null,
            'qbo_entity_id'    => $this->extractQboEntityId($decoded, (string) ($opts['entity_type'] ?? '')),
            'operation'        => (string) ($opts['operation'] ?? strtolower($method)),
            'http_method'      => $method,
            'endpoint'         => $endpoint,
            'request_payload'  => $this->scrubRequestForLog($bodyJson, $queryParams),
            'response_status'  => $httpStatus,
            'response_payload' => $this->truncateForLog($body, self::SYNC_LOG_PAYLOAD_LIMIT_BYTES),
            'duration_ms'      => $durationMs,
            'queue_id'         => $opts['queue_id'] ?? null,
        ];

        // ── Non-2xx → classify and throw ───────────────────────
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $classified = $this->classifyError($httpStatus, $decoded, $parsedHdrs);
            $logRow['error_code']    = $classified['code'] ?? (string) $classified['category'];
            $logRow['error_message'] = $classified['message'];
            $this->writeSyncLog($logRow);
            throw $this->buildException($classified, $httpStatus, $decoded);
        }

        // ── Success ────────────────────────────────────────────
        $this->writeSyncLog($logRow);
        return $decoded;
    }

    // ============================================================
    // PRIVATE — RATE LIMIT + THROTTLE
    // ============================================================

    /**
     * Stash rate-limit headers for the throttle helper to consult
     * before the NEXT request. Header capitalisation varies — we
     * normalise to lowercase keys when parseHeaders runs.
     */
    private function captureRateLimit(array $parsedHeaders): void
    {
        $remaining = $parsedHeaders['x-ratelimit-remaining'] ?? null;
        $reset     = $parsedHeaders['x-ratelimit-reset']     ?? null;
        $this->rateLimitRemaining = ($remaining !== null && $remaining !== '') ? (int) $remaining : null;
        $this->rateLimitReset     = ($reset     !== null && $reset     !== '') ? (int) $reset     : null;
    }

    /**
     * Sleep up to throttle_seconds if the previous response indicated
     * we're nearing the rate-limit threshold. Beyond that, just log
     * a Sentry breadcrumb and proceed — the worker (S-QBO-3) can
     * handle the inevitable 429 with proper next_retry_at scheduling.
     */
    private function maybeThrottle(array $opts): void
    {
        if ($this->rateLimitRemaining === null || $this->rateLimitReset === null) {
            return; // never seen a response yet, or headers missing
        }

        $threshold  = (int) settings_get('quickbooks.rate_limit.throttle_threshold', '10');
        $maxSleep   = (int) settings_get('quickbooks.rate_limit.throttle_seconds',   '30');

        if ($this->rateLimitRemaining >= $threshold) {
            return;
        }

        $sleepNeeded = max(0, $this->rateLimitReset - time());

        if ($sleepNeeded === 0) {
            return; // window already reset; budget refresh imminent
        }

        if ($sleepNeeded <= $maxSleep) {
            sleep($sleepNeeded);
            return;
        }

        // Reset window is too far away — log + proceed. The next
        // call will likely get a 429 which we'll handle properly.
        $this->logBreadcrumb('rate_limit_near_exhaustion', [
            'remaining' => $this->rateLimitRemaining,
            'reset_in'  => $sleepNeeded,
            'max_sleep' => $maxSleep,
        ]);
    }

    // ============================================================
    // PRIVATE — ERROR CLASSIFICATION
    // ============================================================

    /**
     * Map a non-2xx response into a category + concrete exception
     * shape per spec §13.1 + §13.3. Returns:
     *   [
     *     'category'    => string  (e.g. 'stale_object', 'auth_expired')
     *     'code'        => ?string (QBO Fault.Error[0].code if present)
     *     'message'     => string  (humane message for surfacing)
     *     'detail'      => ?string (QBO Fault.Error[0].Detail if present)
     *     'fault_block' => ?array  (raw Fault.Error[0] for downstream)
     *     'retry_after' => ?int    (seconds — for 429)
     *   ]
     */
    private function classifyError(int $httpStatus, array $faultResponse, array $headers = []): array
    {
        $error  = $faultResponse['Fault']['Error'][0] ?? null;
        $code   = $error['code']         ?? null;
        $type   = $faultResponse['Fault']['type'] ?? null;
        $msg    = $error['Message']      ?? null;
        $detail = $error['Detail']       ?? null;

        // Default category guess from HTTP status before walking the
        // QBO Fault block — many non-business failures don't carry a
        // Fault at all (transport hiccups, gateway 502s, etc.).
        $category = match (true) {
            $httpStatus === 401                                            => 'auth_expired',
            $httpStatus === 403                                            => 'forbidden',
            $httpStatus === 404                                            => 'not_found',
            $httpStatus === 408                                            => 'transient_5xx',
            $httpStatus === 429                                            => 'rate_limited',
            $httpStatus >= 500 && $httpStatus < 600                        => 'transient_5xx',
            default                                                        => 'unknown',
        };

        // QBO Fault block refinements (override HTTP-based guess
        // when the Fault provides more specific info).
        if ($code === '5010')                              { $category = 'stale_object'; }
        elseif ($code === '6240')                          { $category = 'duplicate_name'; }
        elseif ($code === '2500')                          { $category = 'invalid_reference'; }
        elseif ($code === '610')                           { $category = 'invalid_object'; }
        elseif ($type === 'AuthenticationFault')           { $category = 'auth_expired'; }
        elseif ($type === 'AuthorizationFault')            { $category = 'forbidden'; }
        elseif ($type === 'ValidationFault' && $category === 'unknown') { $category = 'validation_failed'; }
        elseif ($type === 'SystemFault')                   { $category = 'transient_5xx'; }

        // Retry-After header capture for 429.
        $retryAfter = null;
        if ($httpStatus === 429) {
            $rawRetry = $headers['retry-after'] ?? null;
            if ($rawRetry !== null && $rawRetry !== '') {
                $retryAfter = (int) $rawRetry;
                $this->retryAfter = $retryAfter; // also expose to retry orchestrator
            }
        }

        return [
            'category'    => $category,
            'code'        => $code !== null ? (string) $code : null,
            'message'     => $msg ?? "QBO returned HTTP {$httpStatus}",
            'detail'      => $detail,
            'fault_block' => $error,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Concrete exception factory based on classify() output. Kept
     * separate so the smoke can call classifyError() directly and
     * verify categorisation without going through cURL.
     */
    private function buildException(array $classified, int $httpStatus, array $faultResponse): QuickBooksException
    {
        $msg     = $classified['message'];
        $code    = $classified['code'];
        $fault   = $classified['fault_block'];

        return match ($classified['category']) {
            'stale_object'      => new QuickBooksStaleObjectException($msg, $code, $httpStatus, $fault),
            'duplicate_name'    => new QuickBooksDuplicateNameException($msg, $code, $httpStatus, $fault),
            'auth_expired'      => new QuickBooksAuthExpiredException($msg, $code, $httpStatus, $fault),
            'forbidden'         => new QuickBooksForbiddenException($msg, $code, $httpStatus, $fault),
            'not_found'         => new QuickBooksNotFoundException($msg, $code, $httpStatus, $fault),
            'validation_failed',
            'invalid_reference',
            'invalid_object'    => new QuickBooksValidationException($msg, $code, $httpStatus, $fault),
            'rate_limited'      => new QuickBooksRateLimitException($msg, $classified['retry_after'], $code, $httpStatus, $fault),
            'transient_5xx'     => new QuickBooksTransientException($msg, $code, $httpStatus, $fault),
            default             => new QuickBooksException($msg, $code, $httpStatus, $fault),
        };
    }

    // ============================================================
    // PRIVATE — SYNC LOG + SENTRY
    // ============================================================

    /**
     * Persist a row into acc_qbo_sync_log if the table exists. The
     * guard is intentional — the client can be loaded before the
     * sync log table is migrated, and a missing-table exception
     * here would mask the real API call result. Logging is
     * advisory; the API result is the source of truth.
     *
     * @param array{
     *   direction:string,
     *   entity_type:string,
     *   entity_id?:?int,
     *   qbo_entity_id?:?string,
     *   operation:string,
     *   http_method:string,
     *   endpoint:string,
     *   request_payload?:?string,
     *   response_status?:?int,
     *   response_payload?:?string,
     *   duration_ms?:?int,
     *   error_code?:?string,
     *   error_message?:?string,
     *   queue_id?:?int,
     * } $row
     */
    private function writeSyncLog(array $row): void
    {
        try {
            // Guard: schema check via SHOW TABLES. Cached per-process
            // so a worker burning through 100 queue items doesn't
            // re-check 100 times.
            static $tableExists = null;
            if ($tableExists === null) {
                $check = db_select("SHOW TABLES LIKE 'acc_qbo_sync_log'", []);
                $tableExists = !empty($check);
            }
            if (!$tableExists) {
                return;
            }

            // Verify the table has the canonical §6.5 shape. If a
            // legacy mismatched shape ever resurfaces (pre-S-QBO-2
            // schema), no-op rather than blowing up.
            static $shapeOk = null;
            if ($shapeOk === null) {
                $cols = db_select("SHOW COLUMNS FROM acc_qbo_sync_log LIKE 'http_method'", []);
                $shapeOk = !empty($cols);
            }
            if (!$shapeOk) {
                return;
            }

            $user = function_exists('current_user') ? (current_user() ?? null) : null;

            // D-QBO-FIXPACK-15 (Bug C): Fall back to static worker context when
            // $row['queue_id'] / $row['entity_id'] are absent. Pushers call
            // createEntity/updateEntity without carrying queue context, so
            // sync_log rows written during a worker dispatch would have NULL
            // queue_id + entity_id without this fallback.
            db_insert('acc_qbo_sync_log', [
                'direction'        => $row['direction']        ?? 'push',
                'entity_type'      => $row['entity_type']      ?? self::$workerEntityType ?? 'unknown',
                'entity_id'        => $row['entity_id']        ?? self::$workerEntityId,
                'qbo_entity_id'    => $row['qbo_entity_id']    ?? null,
                'operation'        => $row['operation']        ?? 'unknown',
                'http_method'      => $row['http_method']      ?? 'GET',
                'endpoint'         => $row['endpoint']         ?? '',
                'request_payload'  => $row['request_payload']  ?? null,
                'response_status'  => $row['response_status']  ?? null,
                'response_payload' => $row['response_payload'] ?? null,
                'duration_ms'      => $row['duration_ms']      ?? null,
                'error_code'       => $row['error_code']       ?? null,
                'error_message'    => $row['error_message']    ?? null,
                'user_id'          => ($user['id'] ?? null) ?: null,
                'queue_id'         => $row['queue_id']         ?? self::$workerQueueId,
                'realm_id'         => $this->realmId,
                'environment'      => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (Throwable $logErr) {
            // Sync log failures must NEVER break the API call.
            // Log to PHP error log and move on.
            error_log('[QuickBooksClient.writeSyncLog] persist failed: ' . $logErr->getMessage());
        }
    }

    /**
     * Forward an exception to Sentry with the structured tags + extra
     * payload per spec §13.5. Guarded — if Sentry SDK isn't loaded
     * (some test/dev environments) or never initialized, silently
     * no-ops. The wrapper at lib/Observability/Sentry::captureException
     * itself short-circuits when no DSN is configured.
     */
    private function captureSentry(Throwable $e, array $opts, int $retryCount): void
    {
        // Phase 1 — always forward to the FF Sentry wrapper which
        // handles its own init guard + DSN check.
        if (class_exists('\FleetForge\Observability\Sentry')) {
            try {
                \FleetForge\Observability\Sentry::captureException($e);
            } catch (Throwable $sentryErr) {
                error_log('[QuickBooksClient.captureSentry] wrapper failed: ' . $sentryErr->getMessage());
            }
        }

        // Phase 2 — if the raw Sentry SDK is loaded AND initialized,
        // also push the structured §13.5 tags + extra via withScope.
        // This is supplementary; the wrapper above already captured
        // the bare exception.
        if (!function_exists('\Sentry\withScope') || !function_exists('\Sentry\captureException')) {
            return;
        }

        try {
            $errorCode = ($e instanceof QuickBooksException) ? ($e->errorCode ?? 'unknown') : 'unknown';
            $tags = array_filter([
                'integration' => 'quickbooks',
                'entity_type' => $opts['entity_type'] ?? null,
                'operation'   => $opts['operation']   ?? null,
                'error_code'  => $errorCode,
                'realm_id'    => $this->realmId,
                'environment' => (string) settings_get('quickbooks.environment', 'sandbox'),
            ], static fn ($v) => $v !== null && $v !== '');

            $extra = array_filter([
                'entity_id'       => $opts['entity_id'] ?? null,
                'request_payload' => $this->scrubExtraPayload($opts),
                'response_status' => ($e instanceof QuickBooksException) ? $e->httpStatus : null,
                'response_body'   => $this->truncateForLog((string) ($opts['_response_body'] ?? ''), self::SENTRY_RESPONSE_LIMIT_BYTES),
                'retry_count'     => $retryCount,
            ], static fn ($v) => $v !== null && $v !== '');

            \Sentry\withScope(function ($scope) use ($tags, $extra, $e): void {
                foreach ($tags as $k => $v) {
                    $scope->setTag((string) $k, (string) $v);
                }
                foreach ($extra as $k => $v) {
                    $scope->setExtra((string) $k, $v);
                }
                \Sentry\captureException($e);
            });
        } catch (Throwable $sentryErr) {
            error_log('[QuickBooksClient.captureSentry] structured forward failed: ' . $sentryErr->getMessage());
        }
    }

    /**
     * Drop a non-exception breadcrumb to Sentry (e.g. throttle
     * warnings, rate-limit near-exhaustion). Best-effort.
     */
    private function logBreadcrumb(string $category, array $data): void
    {
        if (!function_exists('\Sentry\addBreadcrumb')) {
            return;
        }
        try {
            \Sentry\addBreadcrumb(new \Sentry\Breadcrumb(
                \Sentry\Breadcrumb::LEVEL_INFO,
                \Sentry\Breadcrumb::TYPE_DEFAULT,
                'quickbooks',
                $category,
                $data
            ));
        } catch (Throwable) {
            // breadcrumbs are advisory — silently ignore SDK quirks
        }
    }

    // ============================================================
    // PRIVATE — HELPERS
    // ============================================================

    /**
     * Parse raw HTTP response headers (multi-line string with \r\n
     * separators) into a lowercase-keyed associative array. Multiple
     * values for the same header are joined with commas (per RFC 7230).
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name  = strtolower(trim($name));
            $value = trim($value);
            if ($name === '') {
                continue;
            }
            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /**
     * Best-effort guess at sync_log.direction from the opts hash.
     * Operation names from this session's call surface map cleanly;
     * fallback is 'push' (every FF-initiated mutation is a push by
     * definition — pulls only happen from webhooks + CDC + S-QBO-27).
     */
    private function inferDirection(array $opts): string
    {
        $op = strtolower((string) ($opts['operation'] ?? ''));
        if (in_array($op, ['get', 'query', 'pull', 'getentity', 'companyinfo'], true)) {
            return 'pull';
        }
        return 'push';
    }

    /**
     * Reach into the parsed response body and try to extract the
     * QBO entity Id for sync_log.qbo_entity_id. Different endpoints
     * shape the response differently — best-effort, returns null on
     * mismatch.
     */
    private function extractQboEntityId(array $decoded, string $entityType): ?string
    {
        // Common shape: { "Customer": { "Id": "42", ... } }
        $pascalType = ucfirst(strtolower($entityType));
        if (isset($decoded[$pascalType]['Id'])) {
            return (string) $decoded[$pascalType]['Id'];
        }
        // QueryResponse shape: { "QueryResponse": { "Customer": [{ "Id": "42" }, ...] } }
        if (isset($decoded['QueryResponse'][$pascalType][0]['Id'])) {
            return (string) $decoded['QueryResponse'][$pascalType][0]['Id'];
        }
        return null;
    }

    /**
     * Strip the Authorization header value before persisting the
     * request payload into sync_log. We never sent Authorization
     * in the JSON body, but defense-in-depth covers a future caller
     * that might include sensitive fields.
     */
    private function scrubRequestForLog(?string $bodyJson, array $queryParams): ?string
    {
        $payload = [
            'query' => $queryParams,
            'body'  => $bodyJson !== null ? json_decode($bodyJson, true) : null,
        ];

        // Walk the body once and redact any Authorization-ish keys.
        if (is_array($payload['body'])) {
            $payload['body'] = $this->redactAuthKeys($payload['body']);
        }

        return $this->truncateForLog((string) json_encode($payload), self::SYNC_LOG_PAYLOAD_LIMIT_BYTES);
    }

    /**
     * For Sentry extra.request_payload — same scrubbing but smaller
     * limit since Sentry events are throttled by size budget.
     */
    private function scrubExtraPayload(array $opts): ?string
    {
        $payload = [
            'query' => $opts['query'] ?? null,
            'body'  => $opts['json']  ?? null,
        ];
        if (is_array($payload['body'])) {
            $payload['body'] = $this->redactAuthKeys($payload['body']);
        }
        return $this->truncateForLog((string) json_encode($payload), self::SENTRY_RESPONSE_LIMIT_BYTES);
    }

    /**
     * Recursive walker that redacts any key matching Authorization /
     * password / secret / token (case-insensitive). The QBO API
     * doesn't currently emit these in entity payloads, but defense
     * in depth keeps the sync log safe from future surprises.
     */
    private function redactAuthKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/authorization|password|secret|access_token|refresh_token/i', (string) $key) === 1) {
                $data[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->redactAuthKeys($value);
            }
        }
        return $data;
    }

    /**
     * Clip a string to $maxBytes, appending a "...[truncated]" marker
     * so the next reader doesn't mistake it for the full payload.
     */
    private function truncateForLog(string $s, int $maxBytes): string
    {
        if (strlen($s) <= $maxBytes) {
            return $s;
        }
        $marker = '...[truncated]';
        return substr($s, 0, max(0, $maxBytes - strlen($marker))) . $marker;
    }

    /**
     * Test-only accessor for the smoke. Returns the classifyError
     * output for a synthetic (HTTP status + fault block + headers)
     * triple without going through cURL. Not part of the public
     * API — production callers must not depend on this.
     */
    public function _testClassify(int $httpStatus, array $faultResponse, array $headers = []): array
    {
        return $this->classifyError($httpStatus, $faultResponse, $headers);
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
