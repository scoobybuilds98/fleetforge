<?php
declare(strict_types=1);

/**
 * lib/QboFixture.php
 *
 * Deterministic in-code QBO response generator. Acts as the fake-Intuit
 * responder when `quickbooks.fixture_mode='1'` is active (S-QBO-OFFLINE-TESTBED).
 * QuickBooksClient::executeRequest short-circuits to QboFixture::respond()
 * after building the real URL + body but before the cURL exec — so the
 * Pushers/Pullers exercise the genuine request-build path AND the genuine
 * downstream parse/sync_log/classify path; only the wire round-trip is
 * replaced with a synthetic response.
 *
 * Design (D-QBO-FIXTURE-3 — "deterministic in-code generator over
 * recorded JSON"):
 *   • Response shapes are computed from (entity_type, operation, payload)
 *     — no on-disk fixtures to drift apart from the live contract.
 *   • Create echoes sent fields, attaches a synthetic Id ('QBO-FIX-<entity>-<n>')
 *     and SyncToken='0'.
 *   • Update echoes sent fields, bumps the SyncToken.
 *   • Void returns the canonical {Id, SyncToken, status:'Voided'} shape.
 *   • Query returns a small canned collection PER ENTITY type, honoring
 *     K-22 Trap #60: bare object when exactly 1 row, array when ≥2.
 *     (QuickBooksClient::normalizeQueryResponse coerces the 1-row case
 *     to an array; the fixture exercises both branches by returning
 *     2-3 rows for the reference-data entity types.)
 *   • Get returns the canonical {<Pascal>: {...}} shape with a fixture-
 *     tagged Id echoed from the URL.
 *
 * Error injection — `injectError($key, $status, $body)` queues a single
 * synthetic error to fire on the NEXT matching call. The fixture pipeline
 * smoke uses this to manufacture failed/skipped/permanent states without
 * mocking the higher-level Pushers. Keys: '<entity_type>:<operation>'
 * (e.g. 'customer:create') OR '<entity_type>:<operation>:<id>' (e.g.
 * 'invoice:update:7') for a precise match.
 *
 * Sentinel realm — `QboFixture::REALM_SENTINEL = 'FIXTURE-DEMO'` is the
 * realm_id setting that QboDemoSeed flips to (alongside fixture_mode='1')
 * when loading the offline demo data. Wipe queries delete rows tagged
 * with this realm, so a load → browse → wipe cycle never touches real data.
 *
 * @session  S-QBO-OFFLINE-TESTBED
 * @decision D-QBO-FIXTURE-1 (intercept at dispatch() chokepoint),
 *           D-QBO-FIXTURE-3 (deterministic in-code generator over recorded JSON)
 */

namespace FleetForge;

class QboFixture
{
    /** Realm sentinel used by QboDemoSeed + wipe queries to tag fixture-originated rows. */
    public const REALM_SENTINEL = 'FIXTURE-DEMO';

    /** Synthetic-Id prefix written into acc_qbo_*_map.qbo_<entity>_id. */
    public const ID_PREFIX = 'QBO-FIX-';

    /**
     * Per-process counter for synthetic Ids — deterministic across a single
     * request, monotonic so the same Pusher invoked twice gets distinct Ids
     * (avoids "create_a returns Id=1001, create_b also returns Id=1001 →
     * duplicate map row" surprises).
     *
     * @var array<string,int>
     */
    private static array $idCounter = [];

    /**
     * Queued error injections. Key shape: '<entity_type>:<operation>' OR
     * '<entity_type>:<operation>:<qbo_id>'. Each entry: ['status'=>int, 'body'=>array].
     * Pops on first match — single-shot so successive calls go back to the
     * happy path.
     *
     * @var array<string, array{status:int, body:array}>
     */
    private static array $errorInjections = [];

    /**
     * Queue a single-shot synthetic error response. Pops on first match.
     * Used by the offline pipeline smoke + QboDemoSeed to manufacture a
     * failed-push state for an entity without needing real QBO errors.
     *
     * @param string $key      '<entity_type>:<operation>' or '<entity_type>:<operation>:<id>'
     * @param int    $status   HTTP status to return (e.g. 400, 401, 429, 500)
     * @param array  $body     Decoded body — typically a {Fault: {Error: [{...}]}} block
     */
    public static function injectError(string $key, int $status, array $body): void
    {
        self::$errorInjections[$key] = ['status' => $status, 'body' => $body];
    }

    /** Reset all queued injections + counters. Useful between smoke runs. */
    public static function reset(): void
    {
        self::$idCounter      = [];
        self::$errorInjections = [];
    }

    /**
     * Synthesize a QBO HTTP response for (method, endpoint, opts). Returns
     * a {status, body} pair shaped exactly like what cURL would yield —
     * the caller (QuickBooksClient::executeFixture) then runs the normal
     * downstream parse/sync_log/classify pipeline.
     *
     * @param  string $method  Uppercase HTTP verb (GET/POST/PUT/DELETE)
     * @param  string $endpoint Relative endpoint (e.g. 'customer', 'customer/7', 'query')
     * @param  array  $opts     Same opts shape QuickBooksClient passes through
     * @return array{status:int, body:string}
     */
    public static function respond(string $method, string $endpoint, array $opts): array
    {
        $entityType = (string) ($opts['entity_type'] ?? '');
        $operation  = (string) ($opts['operation']   ?? strtolower($method));
        $payload    = $opts['json'] ?? [];
        $qboId      = is_array($payload) ? (string) ($payload['Id'] ?? '') : '';

        // ── Error injection first (highest precedence) ──────────────
        // Match the most-specific key first ('customer:update:7'), then
        // the operation-level key ('customer:update'), so callers can
        // pin a fault to a single row without poisoning sibling rows.
        $candidates = [
            $entityType . ':' . $operation . ($qboId !== '' ? ':' . $qboId : ''),
            $entityType . ':' . $operation,
        ];
        foreach ($candidates as $k) {
            if (isset(self::$errorInjections[$k])) {
                $err = self::$errorInjections[$k];
                unset(self::$errorInjections[$k]); // single-shot
                return ['status' => $err['status'], 'body' => (string) json_encode($err['body'])];
            }
        }

        // ── Route by operation ──────────────────────────────────────
        if ($operation === 'query' || $endpoint === 'query') {
            return self::respondQuery($opts);
        }
        if ($operation === 'void') {
            return self::respondVoid($entityType, is_array($payload) ? $payload : []);
        }
        if ($operation === 'update') {
            return self::respondUpdate($entityType, is_array($payload) ? $payload : []);
        }
        if ($operation === 'create' || ($method === 'POST' && empty($qboId))) {
            return self::respondCreate($entityType, is_array($payload) ? $payload : []);
        }
        if ($operation === 'get' || $method === 'GET') {
            return self::respondGet($entityType, $endpoint);
        }
        if ($operation === 'company_info') {
            return self::respondCompanyInfo();
        }

        // Fallback: empty 200 (caller still gets a valid array)
        return ['status' => 200, 'body' => (string) json_encode(['time' => self::nowIso()])];
    }

    // ============================================================
    // PER-OPERATION RESPONDERS
    // ============================================================

    private static function respondCreate(string $entityType, array $payload): array
    {
        $pascal = self::pascalEntity($entityType);
        $id     = self::nextId($entityType);
        $entity = self::echoFields($payload, $id, '0');
        return ['status' => 200, 'body' => (string) json_encode([
            $pascal => $entity,
            'time'  => self::nowIso(),
        ])];
    }

    private static function respondUpdate(string $entityType, array $payload): array
    {
        $pascal   = self::pascalEntity($entityType);
        $id       = (string) ($payload['Id'] ?? self::nextId($entityType));
        $oldToken = (string) ($payload['SyncToken'] ?? '0');
        $newToken = (string) ((int) $oldToken + 1);
        $entity   = self::echoFields($payload, $id, $newToken);
        return ['status' => 200, 'body' => (string) json_encode([
            $pascal => $entity,
            'time'  => self::nowIso(),
        ])];
    }

    private static function respondVoid(string $entityType, array $payload): array
    {
        $pascal = self::pascalEntity($entityType);
        $id     = (string) ($payload['Id'] ?? '0');
        return ['status' => 200, 'body' => (string) json_encode([
            $pascal => [
                'Id'        => $id,
                'SyncToken' => '1',
                'status'    => 'Voided',
                'Voided'    => true,
                'Balance'   => 0,
                'TotalAmt'  => 0,
            ],
            'time' => self::nowIso(),
        ])];
    }

    private static function respondGet(string $entityType, string $endpoint): array
    {
        // Endpoint like 'account/123' — pull the id off the back of the path.
        $parts = explode('/', $endpoint, 2);
        $id    = isset($parts[1]) ? urldecode($parts[1]) : '0';
        $pascal = self::pascalEntity($entityType);
        $entity = self::echoFields(self::echoableDefaults($entityType, $id), $id, '0');
        return ['status' => 200, 'body' => (string) json_encode([
            $pascal => $entity,
            'time'  => self::nowIso(),
        ])];
    }

    private static function respondQuery(array $opts): array
    {
        $sql = (string) ($opts['query']['query'] ?? '');
        if (preg_match('/FROM\s+([A-Za-z_]+)/i', $sql, $m)) {
            $pascal     = $m[1];
            $entityType = self::snakeFromPascal($pascal);
            $items      = self::cannedCollection($entityType, $pascal);

            // K-22 Trap #60: when there is exactly 1 row, QBO returns it
            // as a bare object (not a 1-element array). The fixture honors
            // that contract for the 1-row case so normalizeQueryResponse
            // gets exercised both branches across the suite.
            $collection = (count($items) === 1) ? $items[0] : array_values($items);

            return ['status' => 200, 'body' => (string) json_encode([
                'QueryResponse' => [
                    $pascal           => $collection,
                    'startPosition'   => 1,
                    'maxResults'      => count($items),
                    'totalCount'      => count($items),
                ],
                'time' => self::nowIso(),
            ])];
        }
        return ['status' => 200, 'body' => (string) json_encode([
            'QueryResponse' => [],
            'time'          => self::nowIso(),
        ])];
    }

    private static function respondCompanyInfo(): array
    {
        return ['status' => 200, 'body' => (string) json_encode([
            'CompanyInfo' => [
                'Id'            => '1',
                'CompanyName'   => 'FleetForge Fixture Sandbox Co.',
                'LegalName'     => 'FleetForge Fixture Sandbox Co.',
                'Country'       => 'CA',
                'SupportedLanguages' => 'en',
            ],
            'time' => self::nowIso(),
        ])];
    }

    // ============================================================
    // CANNED COLLECTIONS — per-entity QueryResponse seed data
    // ============================================================

    /**
     * Tiny realistic per-entity collection. Pull-capable types (customer,
     * vendor, account, item, tax_code) need ≥2 items so the pulls populate
     * the operator-facing admin pages with browseable rows. Push-only
     * entity-types (Invoice, Bill, etc.) generally aren't queried via
     * DriftChecker.checkLive, but a small list is returned anyway so the
     * live drift path can be exercised end-to-end.
     */
    private static function cannedCollection(string $entityType, string $pascal): array
    {
        switch ($entityType) {
            case 'customer':
                return [
                    self::echoFields(['DisplayName' => 'FIXTURE-DEMO Acme Trucking Ltd.', 'CompanyName' => 'FIXTURE-DEMO Acme Trucking Ltd.', 'PrimaryEmailAddr' => ['Address' => 'ops@acme.fixture'], 'CurrencyRef' => ['value' => 'CAD'], 'Active' => true, 'Balance' => 1250.00], self::ID_PREFIX . 'customer-1', '0'),
                    self::echoFields(['DisplayName' => 'FIXTURE-DEMO Beaver Haulage Inc.', 'CompanyName' => 'FIXTURE-DEMO Beaver Haulage Inc.', 'PrimaryEmailAddr' => ['Address' => 'ap@beaver.fixture'], 'CurrencyRef' => ['value' => 'CAD'], 'Active' => true, 'Balance' => 0.00],     self::ID_PREFIX . 'customer-2', '0'),
                ];
            case 'vendor':
                return [
                    self::echoFields(['DisplayName' => 'FIXTURE-DEMO Petro-Can Cards', 'CompanyName' => 'FIXTURE-DEMO Petro-Can Cards', 'CurrencyRef' => ['value' => 'CAD'], 'Active' => true, 'Balance' => 0.00], self::ID_PREFIX . 'vendor-1', '0'),
                    self::echoFields(['DisplayName' => 'FIXTURE-DEMO Coastal Tires',   'CompanyName' => 'FIXTURE-DEMO Coastal Tires',   'CurrencyRef' => ['value' => 'CAD'], 'Active' => true, 'Balance' => 487.50], self::ID_PREFIX . 'vendor-2', '0'),
                ];
            case 'account':
                return [
                    self::echoFields(['Name' => 'FIXTURE-DEMO Accounts Receivable', 'AccountType' => 'Accounts Receivable', 'AccountSubType' => 'AccountsReceivable', 'Classification' => 'Asset',     'CurrentBalance' => 12500.00, 'Active' => true], self::ID_PREFIX . 'account-1', '0'),
                    self::echoFields(['Name' => 'FIXTURE-DEMO Cash',                'AccountType' => 'Bank',                'AccountSubType' => 'Checking',           'Classification' => 'Asset',     'CurrentBalance' => 50000.00, 'Active' => true], self::ID_PREFIX . 'account-2', '0'),
                    self::echoFields(['Name' => 'FIXTURE-DEMO Sales Revenue',       'AccountType' => 'Income',              'AccountSubType' => 'SalesOfProductIncome', 'Classification' => 'Revenue', 'CurrentBalance' => 0.00,     'Active' => true], self::ID_PREFIX . 'account-3', '0'),
                ];
            case 'item':
                return [
                    self::echoFields(['Name' => 'FIXTURE-DEMO Hauling Service', 'Type' => 'Service', 'UnitPrice' => 150.00, 'Active' => true], self::ID_PREFIX . 'item-1', '0'),
                    self::echoFields(['Name' => 'FIXTURE-DEMO Fuel Surcharge',  'Type' => 'Service', 'UnitPrice' => 25.00,  'Active' => true], self::ID_PREFIX . 'item-2', '0'),
                ];
            case 'tax_code':
                return [
                    self::echoFields(['Name' => 'FIXTURE-DEMO GST/HST 5%', 'Active' => true, 'TaxGroup' => false], self::ID_PREFIX . 'tax_code-1', '0'),
                    self::echoFields(['Name' => 'FIXTURE-DEMO Exempt',     'Active' => true, 'TaxGroup' => false], self::ID_PREFIX . 'tax_code-2', '0'),
                ];
            case 'invoice':
                return [
                    self::echoFields(['DocNumber' => 'FIX-INV-1', 'TotalAmt' => 1250.00, 'Balance' => 1250.00, 'CustomerRef' => ['value' => self::ID_PREFIX . 'customer-1']], self::ID_PREFIX . 'invoice-1', '0'),
                ];
            case 'bill':
                return [
                    self::echoFields(['DocNumber' => 'FIX-BILL-1', 'TotalAmt' => 487.50, 'Balance' => 487.50, 'VendorRef' => ['value' => self::ID_PREFIX . 'vendor-2']], self::ID_PREFIX . 'bill-1', '0'),
                ];
            default:
                return [];
        }
    }

    private static function echoableDefaults(string $entityType, string $id): array
    {
        // Default field set for getEntity responses. Some Pushers/Pullers
        // probe specific shapes (e.g. CustomerPusher::pushImpl reads
        // CurrencyRef.value on already_mapped probe). Provide realistic
        // defaults keyed on entity type.
        switch ($entityType) {
            case 'customer':
                return ['DisplayName' => 'FIXTURE-DEMO Customer ' . $id, 'CompanyName' => 'FIXTURE-DEMO Customer ' . $id, 'CurrencyRef' => ['value' => 'CAD'], 'Active' => true];
            case 'vendor':
                return ['DisplayName' => 'FIXTURE-DEMO Vendor ' . $id, 'CurrencyRef' => ['value' => 'CAD'], 'Active' => true];
            case 'account':
                return ['Name' => 'FIXTURE-DEMO Account ' . $id, 'AccountType' => 'Bank', 'CurrentBalance' => 0.00, 'Active' => true];
            default:
                return ['Name' => 'FIXTURE-DEMO ' . $entityType . ' ' . $id];
        }
    }

    // ============================================================
    // INTERNAL HELPERS
    // ============================================================

    private static function echoFields(array $payload, string $id, string $syncToken): array
    {
        // Reflect the operator-sent payload (so the Pusher's mapping logic
        // sees its own request shape coming back) and stamp the QBO-side
        // bookkeeping fields the response shape mandates. array_merge —
        // NOT the `+` union operator — so the synthetic Id/SyncToken
        // ALWAYS overwrite any incoming values (update payloads carry
        // the old SyncToken; the fixture must bump it).
        return array_merge($payload, [
            'Id'         => $id,
            'SyncToken'  => $syncToken,
            'sparse'     => false,
            'MetaData'   => [
                'CreateTime'      => self::nowIso(),
                'LastUpdatedTime' => self::nowIso(),
            ],
        ]);
    }

    private static function pascalEntity(string $entityType): string
    {
        // 'credit_memo' → 'CreditMemo', 'customer' → 'Customer'
        $parts = explode('_', $entityType);
        return implode('', array_map('ucfirst', $parts));
    }

    private static function snakeFromPascal(string $pascal): string
    {
        // 'CreditMemo' → 'credit_memo' (used to route query() responses)
        $snake = preg_replace('/(?<!^)([A-Z])/', '_$1', $pascal);
        return strtolower((string) $snake);
    }

    private static function nextId(string $entityType): string
    {
        // Start at 1001 — three digits so the synthetic ids look like
        // real QBO ids (which are short integers as strings) wrapped in
        // the QBO-FIX- prefix for unmistakable origin.
        if (!isset(self::$idCounter[$entityType])) {
            self::$idCounter[$entityType] = 1000;
        }
        $n = ++self::$idCounter[$entityType];
        return self::ID_PREFIX . $entityType . '-' . $n;
    }

    private static function nowIso(): string
    {
        return date('c');
    }
}
