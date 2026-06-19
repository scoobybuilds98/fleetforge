<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CustomerPusher.php
 *
 * First FF → QBO push class (S-QBO-6). Handles customer create + update
 * sync into Intuit QBO. Called by `QboPusherDispatcher::dispatch()`
 * from `cron/qbo_sync_worker.php` when a queued acc_qbo_sync_queue row
 * with entity_type='customer' is picked up.
 *
 * Method-per-operation contract (D-QBO-3-2):
 *   create → pushCreate(int $entityId, ?array $payloadSnapshot = null): array
 *   update → pushUpdate(int $entityId, ?array $payloadSnapshot = null): array
 *
 * No pushVoid / pushDelete for customers per D-QBO-6-1 — FF soft-delete
 * does NOT enqueue a QBO delete (accountants don't appreciate auto-
 * deletion; QBO customer stays alive, mapping row stays mapped, future
 * drift cron S-QBO-24 may flag).
 *
 * Sync mode gating (D-QBO-6-4): per-call inspection of
 * `quickbooks.sync_mode.customer`:
 *   - 'sync' (default) | 'ff_to_qbo' → push allowed
 *   - 'qbo_to_ff' | 'disabled'       → returns ['status'=>'skipped_by_mode']
 * Master kill switch (`quickbooks.sync_enabled='0'` per D-CPA-5) is
 * enforced UPSTREAM by the worker (cron/qbo_sync_worker.php exits
 * silently when the master switch is off) AND by CustomerEnqueuer
 * (refuses to enqueue when master switch is off). So this class is
 * reached only when the worker decided to invoke it.
 *
 * Idempotency (D-QBO-6-2): pushCreate checks acc_qbo_customer_map for
 * an existing qbo_customer_id; if present, returns
 * ['status'=>'already_mapped'] without re-POSTing to QBO. Protects
 * against worker crash/retry cycles + accidental requeues from
 * misbehaving callers. The S-QBO-3 worker also dedupes by entity_id
 * at queue-claim time, but defense in depth.
 *
 * Field mapping per D-QBO-6-5 (FF customers → QBO Customer payload):
 *   customers.company_name → DisplayName + CompanyName
 *   customers.email        → PrimaryEmailAddr.Address
 *   customers.phone        → PrimaryPhone.FreeFormNumber
 *   customers.address      → BillAddr.Line1
 *   customers.city         → BillAddr.City
 *   customers.province     → BillAddr.CountrySubDivisionCode  (Canadian context)
 *   customers.postal_code  → BillAddr.PostalCode
 *   customers.country      → BillAddr.Country  (default 'CA')
 *   customers.deleted_at non-null → SKIP (returns skipped_soft_deleted)
 *
 * @session  S-QBO-6
 * @updated  S-QBO-FIXPACK-2 — always emit CurrencyRef on customer create
 *           payloads (D-QBO-FIXPACK-6 class-of-entity principle);
 *           idempotency-replay mismatch warning when QBO customer is
 *           currency-poisoned from a prior push (D-QBO-FIXPACK-10).
 * @updated  S-QBO-FIXPACK-3 — CurrencyRef emission gated on
 *           quickbooks.multi_currency_enabled (D-QBO-FIXPACK-12). When
 *           '0': omit CurrencyRef entirely (QBO error 6000 fires otherwise
 *           on single-currency companies). When '1': emit CurrencyRef as
 *           FIXPACK-2 specified. The throw-on-empty-currency guard is
 *           likewise gated — only relevant when multi-currency is on.
 * @updated  S-QBO-PUSHER-CONTRACT-PAYDOWN — standardized §6.8 5-key
 *           return shape via RESULT_BASE + union operator (MEDIUM-C6
 *           from Phase 2 contract audit). All return paths now guarantee
 *           presence of success/status/qbo_id/sync_token/error keys.
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.1 (customer sync rules),
 *           §6.7 (worker dispatch pattern)
 * @decision D-QBO-6-1 (no delete enqueue), D-QBO-6-2 (idempotency),
 *           D-QBO-6-4 (sync mode gating), D-QBO-6-5 (field mapping),
 *           D-QBO-FIXPACK-6 (class-of-entity CurrencyRef principle),
 *           D-QBO-FIXPACK-7 (customer CurrencyRef emit policy — create only),
 *           D-QBO-FIXPACK-10 (idempotency-replay mismatch warning)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class CustomerPusher
{
    /**
     * Canonical §6.8 Pusher return shape — ensures all 5 keys are
     * present on every return path. PHP + union operator fills absent
     * keys with null; left-hand values always win so method-specific
     * keys (error_code, mode, http_code) pass through unchanged.
     * @spec FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 "Return shape"
     */
    private const RESULT_BASE = [
        'success'    => null,
        'status'     => null,
        'qbo_id'     => null,
        'sync_token' => null,
        'error'      => null,
    ];

    /**
     * Push a new FF customer into QBO. Idempotent — if the customer
     * is already mapped (has a qbo_customer_id), returns
     * ['status'=>'already_mapped'] without re-POSTing.
     *
     * Invoked by QboPusherDispatcher with the (int $entityId, ?array
     * $payloadSnapshot = null) signature.
     */
    public static function pushCreate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'create', $payloadSnapshot);
    }

    /**
     * Update an existing QBO customer from FF state. Requires the
     * mapping row to already have qbo_customer_id + qbo_sync_token
     * (populated either by a prior pushCreate, by S-QBO-5's manual
     * mapping UI, or by the pull flow).
     */
    public static function pushUpdate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'update', $payloadSnapshot);
    }

    /**
     * Shared orchestration. The two public entry points delegate here
     * to keep the per-operation methods tiny + match the dispatcher's
     * OPERATION_METHODS contract (D-QBO-3-2).
     */
    private static function pushImpl(int $ffCustomerId, string $operation, ?array $payloadSnapshot): array
    {
        // 1. Sync mode gate (D-QBO-6-4) — per-call check so an operator
        //    flipping the mode mid-queue gets the new behavior on the
        //    next dispatch without restarting the worker.
        $mode = (string) settings_get('quickbooks.sync_mode.customer', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            // S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA: record the skip in
            // both map row (push_status='skipped_by_mode') + sync_log.
            // FK guard inside recordSkipped handles the case where
            // $ffCustomerId doesn't exist (gate fires before customer load).
            $ffForSkip = db_row("SELECT id FROM customers WHERE id = ?", [$ffCustomerId]) ?? ['id' => $ffCustomerId];
            self::recordSkipped($ffCustomerId, $ffForSkip, 'skipped_by_mode', $operation);
            return ['success' => true, 'status' => 'skipped_by_mode', 'outcome' => 'skipped', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load FF customer state. Selects only the columns this
        //    Pusher actually maps to QBO + soft-delete flag + currency
        //    (added S-QBO-FIXPACK-2: needed for CurrencyRef emission
        //    per D-QBO-FIXPACK-6/-7).
        $ff = db_row(
            "SELECT id, company_name, email, phone, address, city, province, postal_code, country, currency, deleted_at, updated_at
               FROM customers
              WHERE id = ?",
            [$ffCustomerId]
        );
        if ($ff === null) {
            return ['success' => false, 'status' => 'ff_not_found', 'outcome' => 'failed', 'error' => "FF customer {$ffCustomerId} not found"] + self::RESULT_BASE;
        }
        if ($ff['deleted_at'] !== null) {
            // Per D-QBO-6-1: FF soft-delete does NOT propagate to QBO.
            // Reaching here means the queue row was enqueued before
            // the customer was soft-deleted (or via an out-of-band path).
            //
            // S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA: record the skip in
            // both map row (push_status='skipped_soft_deleted') + sync_log
            // so the operator-facing admin view + audit trail surface
            // every dispatch attempt instead of silently no-op'ing.
            self::recordSkipped($ffCustomerId, $ff, 'skipped_soft_deleted', $operation);
            return ['success' => true, 'status' => 'skipped_soft_deleted', 'outcome' => 'skipped'] + self::RESULT_BASE;
        }

        // 3. Look up existing mapping. May be NULL (first push), or
        //    may exist from a prior pull (qbo_only), prior auto-match
        //    (mapped/manual), or a prior push (mapped/manual).
        $mapping = db_row(
            "SELECT id, qbo_customer_id, qbo_sync_token, mapping_status
               FROM acc_qbo_customer_map
              WHERE ff_customer_id = ?",
            [$ffCustomerId]
        );

        // 4. Idempotency on CREATE (D-QBO-6-2). If we already have a
        //    qbo_customer_id, we MUST NOT re-POST — that would create
        //    a duplicate QBO Customer with auto-discriminated name.
        //    Treat as no-op; the worker marks the queue row completed.
        //
        //    D-QBO-FIXPACK-10: Before returning 'already_mapped', probe
        //    the QBO customer's CurrencyRef and surface a warning when
        //    FF.currency ≠ QBO.CurrencyRef.value. This flags the
        //    "currency-poisoned mapping" case — a prior push that omitted
        //    CurrencyRef caused QBO to default to home currency (USD in
        //    Intuit US sandbox), which Intuit forbids changing after the
        //    fact. The check is best-effort: any network/auth failure is
        //    caught silently so it never blocks the idempotency no-op.
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_customer_id'])) {
            try {
                $client     = new QuickBooksClient();
                $qboResp    = $client->getEntity('customer', (string) $mapping['qbo_customer_id']);
                $qboCust    = $qboResp['Customer'] ?? null;
                if (is_array($qboCust)) {
                    $qboCurrency = strtoupper((string) ($qboCust['CurrencyRef']['value'] ?? ''));
                    $ffCurrency  = strtoupper((string) ($ff['currency'] ?? ''));
                    if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                        error_log(
                            "S-QBO-FIXPACK-10 WARNING: FF customer {$ff['id']} currency='{$ffCurrency}' " .
                            "but mapped QBO customer #{$mapping['qbo_customer_id']} is '{$qboCurrency}'. " .
                            "Mapping is currency-poisoned (Intuit forbids QBO customer currency change after create). " .
                            "Operator action: unlink FF customer from QBO (set mapping_status='ff_only'), " .
                            "mark QBO customer inactive in QBO, then re-push to create a new QBO customer."
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Silent fallthrough — currency-mismatch check is advisory only.
                // Don't block the idempotency no-op due to a transient QBO error.
                error_log("S-QBO-FIXPACK-10: currency-mismatch check failed for FF customer {$ff['id']}: " . $e->getMessage());
            }
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',  // replay no-op == created semantically
                'qbo_id'  => (string) $mapping['qbo_customer_id'],
            ] + self::RESULT_BASE;
        }

        // 5. UPDATE path requires an existing mapping with both the
        //    qbo_customer_id AND the SyncToken. Without them we can't
        //    address the QBO entity. Demote to a create instead —
        //    the dispatcher already invoked us for an update, but the
        //    semantics of "update an entity we've never pushed" is a
        //    first push.
        $effectiveOperation = $operation;
        if ($operation === 'update' && ($mapping === null || empty($mapping['qbo_customer_id']))) {
            $effectiveOperation = 'create';
        }

        // 6. Build payload from FF row.
        $qboPayload = self::buildQboPayload($ff);

        // D-QBO-FIXPACK-7: buildQboPayload always emits CurrencyRef (on
        // create, this is required per the class-of-entity principle
        // D-QBO-FIXPACK-6). On UPDATE, QBO rejects CurrencyRef because
        // customer currency is immutable after create per Intuit policy.
        // Strip it from update payloads here so the HTTP call succeeds.
        if ($effectiveOperation === 'update') {
            unset($qboPayload['CurrencyRef']);
        }

        // 7. HTTP call via QuickBooksClient. The client handles auth,
        //    retries (transient errors), Sentry capture, and sync_log
        //    writes — we just consume the parsed response.
        $client = new QuickBooksClient();
        try {
            if ($effectiveOperation === 'update') {
                $response = $client->updateEntity(
                    'customer',
                    (string) $mapping['qbo_customer_id'],
                    (string) ($mapping['qbo_sync_token'] ?? '0'),
                    $qboPayload
                );
            } else {
                $response = $client->createEntity('customer', $qboPayload);
            }
        } catch (QuickBooksException $e) {
            // S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA: record HTTP failure
            // in map row (push_status='failed' + push_error). HTTP-level
            // sync_log is written by QuickBooksClient itself on the failed
            // request, so no separate sync_log write needed here.
            // QuickBooksException exposes httpStatus / errorCode as public
            // readonly PROPERTIES, not methods — method_exists() is ALWAYS false
            // for them and silently dropped the real HTTP status + QBO fault code
            // (e.g. 6240 duplicate) that drive retry classification + Push State.
            $httpCode = $e->httpStatus ?? 0;
            self::recordPushFailure($ffCustomerId, $e->getMessage(), $httpCode);
            return [
                'success'    => false,
                'status'     => 'qbo_error',
                'outcome'    => 'failed',
                'error'      => $e->getMessage(),
                'error_code' => $e->errorCode ?? null,
            ] + self::RESULT_BASE;
        }

        // 8. QBO returns { "Customer": {...}, "time": "..." } for both
        //    create and update operations.
        $qboCustomer = $response['Customer'] ?? null;
        if (!is_array($qboCustomer) || empty($qboCustomer['Id'])) {
            self::recordPushFailure($ffCustomerId, 'QBO response missing Customer.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing Customer.Id',
            ] + self::RESULT_BASE;
        }

        // 9. Persist the mapping via recordSuccessfulPush helper.
        // S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA: extracted the inline
        // snapshot write into a dedicated helper that also stamps
        // push_status='pushed', push_error=null, pushed_at=NOW so the
        // operator-facing admin view + Push State queries surface the
        // success state. Mirrors InvoicePusher::recordSuccessfulPush().
        self::recordSuccessfulPush($ffCustomerId, $ff, $qboCustomer, $mapping);

        return [
            'success'    => true,
            'status'     => $effectiveOperation === 'update'
                              ? 'updated'
                              : ($effectiveOperation !== $operation ? 'created_from_update' : 'created'),
            'outcome'    => $effectiveOperation === 'update' ? 'updated' : 'created',
            'qbo_id'     => (string) $qboCustomer['Id'],
            'sync_token' => (string) ($qboCustomer['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Build the QBO Customer payload from an FF customers row.
     *
     * Defensive about empty / missing fields — QBO is lenient on
     * create (only DisplayName is strictly required) but the payload
     * must not contain empty objects (e.g. BillAddr with no Line1).
     * `array_filter()` drops null + empty-string entries before
     * deciding whether to attach the nested object.
     *
     * Emits CurrencyRef only when quickbooks.multi_currency_enabled='1'
     * (D-QBO-FIXPACK-12). Throws QuickBooksException on empty currency
     * in multi-currency mode. When multi-currency is disabled: omits
     * CurrencyRef entirely so single-currency QBO companies don't receive
     * error 6000 ("Multi Currency should be enabled").
     *
     * NOTE: pushImpl strips CurrencyRef from UPDATE payloads because
     * QBO rejects it on updates (customer currency is immutable after
     * create per Intuit policy). CurrencyRef is intentionally included
     * in buildQboPayload output (when enabled) so the offline smoke can
     * verify the emission logic by setting multi_currency_enabled='1'.
     *
     * Public-static so the offline smoke can exercise it without
     * going through the cURL boundary — matches the
     * QuickBooksClient::normalizeQueryResponse pattern from S-QBO-5-FIX-1.
     */
    public static function buildQboPayload(array $ff): array
    {
        $payload = [
            'DisplayName' => (string) ($ff['company_name'] ?? ''),
            'CompanyName' => (string) ($ff['company_name'] ?? ''),
        ];

        // D-QBO-FIXPACK-12: Gate CurrencyRef emission on multi-currency setting.
        // WHY: QBO error 6000 ("Multi Currency should be enabled to perform this
        // operation") fires when CurrencyRef is sent to a single-currency company.
        // FIXPACK-2 established "always emit CurrencyRef" (D-QBO-FIXPACK-6/-7),
        // but that assumed multi-currency is always enabled — operator discovered
        // live that the Intuit sandbox has multi-currency disabled by default.
        // When '1': emit CurrencyRef from customers.currency (throw on empty).
        // When '0': omit CurrencyRef entirely; QBO enforces home currency by
        // default, which is acceptable for single-currency deployments.
        // (pushImpl strips CurrencyRef for update ops regardless of this gate.)
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($ff['currency'] ?? ''));
            if ($currency === '') {
                throw new QuickBooksException(
                    "Customer " . ($ff['id'] ?? '?') . " has empty currency field; cannot push " .
                    "without explicit CurrencyRef (omitting causes silent QBO coercion to home " .
                    "currency). Verify customers.currency is populated (expected 'CAD' or 'USD')."
                );
            }
            $payload['CurrencyRef'] = ['value' => $currency];
        }
        // When multi_currency_enabled='0': omit CurrencyRef (QBO error 6000 otherwise).

        if (!empty($ff['email'])) {
            $payload['PrimaryEmailAddr'] = ['Address' => (string) $ff['email']];
        }
        if (!empty($ff['phone'])) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => (string) $ff['phone']];
        }

        // Build BillAddr only if at least one address field is populated.
        // Country defaults to 'CA' — the only FF deployment is Canadian
        // per spec §0, so this is a safe assumption rather than carrying
        // empty-string BillAddr.Country which QBO would reject.
        $addr = array_filter([
            'Line1'                  => $ff['address']     ?? null,
            'City'                   => $ff['city']        ?? null,
            'CountrySubDivisionCode' => $ff['province']    ?? null,
            'PostalCode'             => $ff['postal_code'] ?? null,
            'Country'                => $ff['country']     ?? 'CA',
        ], static fn($v) => $v !== null && $v !== '');
        // BillAddr is only meaningful with at least one address line
        // (Line1, City, or PostalCode). Country alone isn't enough.
        if (!empty($addr['Line1']) || !empty($addr['City']) || !empty($addr['PostalCode'])) {
            $payload['BillAddr'] = $addr;
        }

        return $payload;
    }

    // ============================================================
    // RECORD HELPERS (S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA)
    // Mirror InvoicePusher's 4-helper pattern for the customer push
    // path. Decisions D-CV-PUSH-STATE-COLUMN-PATH + D-CV-RECORD-HELPERS-
    // MIRROR + D-CV-ENUM-SCOPE. See db_migrations/202605270100_S-QBO-
    // CUSTOMER-VENDOR-PUSH-STATE-INFRA.sql for the schema additions.
    // ============================================================

    /**
     * Upsert mapping row with success state. Stamps push_status='pushed',
     * push_error=null, pushed_at=NOW alongside the QBO-side field snapshot.
     * Mirrors InvoicePusher::recordSuccessfulPush().
     */
    private static function recordSuccessfulPush(
        int $ffCustomerId,
        array $ff,
        array $qboCustomer,
        ?array $existingMapping
    ): void {
        $now = date('Y-m-d H:i:s');
        $snapshot = [
            'qbo_customer_id'  => (string) $qboCustomer['Id'],
            'qbo_sync_token'   => (string) ($qboCustomer['SyncToken']                ?? '0'),
            'qbo_display_name' => (string) ($qboCustomer['DisplayName']              ?? $ff['company_name'] ?? ''),
            'qbo_company_name' => (string) ($qboCustomer['CompanyName']              ?? ''),
            'qbo_email'        => (string) ($qboCustomer['PrimaryEmailAddr']['Address']    ?? ''),
            'qbo_phone'        => (string) ($qboCustomer['PrimaryPhone']['FreeFormNumber'] ?? ''),
            'qbo_active'       => !empty($qboCustomer['Active']) ? 1 : 0,
            'mapping_status'   => 'mapped',
            'push_status'      => 'pushed',
            'push_error'       => null,
            'pushed_at'        => $now,
            'last_synced_at'   => $now,
            'last_push_at'     => $now,
        ];

        if ($existingMapping === null) {
            // First time — INSERT with match_confidence='manual' (the
            // FF customer is operator-confirmed via the Create form).
            db_insert('acc_qbo_customer_map', $snapshot + [
                'ff_customer_id'   => $ffCustomerId,
                'match_confidence' => 'manual',
            ]);
        } else {
            // Existing mapping — UPDATE in place. match_confidence is
            // intentionally NOT touched (preserves operator overrides).
            $setSql = [];
            $params = [];
            foreach ($snapshot as $col => $val) {
                $setSql[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = (int) $existingMapping['id'];
            db_execute(
                "UPDATE acc_qbo_customer_map SET " . implode(', ', $setSql) . " WHERE id = ?",
                $params
            );
        }
    }

    /**
     * Record push failure (QBO HTTP error or malformed response). Creates
     * mapping row if absent so the operator can see the failure state in
     * the admin UI. Mirrors InvoicePusher::recordPushFailure().
     */
    private static function recordPushFailure(int $ffCustomerId, string $error, int $httpCode): void
    {
        // FK guard: customers.id is the FK target; ghost ids during smoke
        // (e.g. pushCreate(0) early-gate tests) would FK-violate.
        $exists = db_row("SELECT 1 AS x FROM customers WHERE id = ?", [$ffCustomerId]) !== null;
        if (!$exists) {
            return;
        }

        $existing = db_row(
            "SELECT id FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
            [$ffCustomerId]
        );
        $now = date('Y-m-d H:i:s');
        $errorWithCode = $httpCode > 0 ? "HTTP {$httpCode}: {$error}" : $error;

        if ($existing === null) {
            db_insert('acc_qbo_customer_map', [
                'ff_customer_id' => $ffCustomerId,
                'push_status'    => 'failed',
                'push_error'     => $errorWithCode,
                'last_synced_at' => $now,
            ]);
        } else {
            db_execute(
                "UPDATE acc_qbo_customer_map
                    SET push_status    = 'failed',
                        push_error     = ?,
                        last_synced_at = ?
                  WHERE id = ?",
                [$errorWithCode, $now, (int) $existing['id']]
            );
        }
    }

    /**
     * Record failed_preflight state. Forward-compatible per D-CV-ENUM-SCOPE
     * — CustomerPusher has no preflight gate today, but the ENUM admits
     * failed_preflight + failed_preflight_field_too_long; helper exists so
     * future preflight work can wire in without re-touching the recording
     * layer. Mirrors InvoicePusher::recordFailedPreflight().
     */
    private static function recordFailedPreflight(
        int    $ffCustomerId,
        string $reason,
        string $status = 'failed_preflight'
    ): void {
        $exists = db_row("SELECT 1 AS x FROM customers WHERE id = ?", [$ffCustomerId]) !== null;
        if (!$exists) {
            return;
        }

        $existing = db_row(
            "SELECT id FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
            [$ffCustomerId]
        );
        $now = date('Y-m-d H:i:s');

        if ($existing === null) {
            db_insert('acc_qbo_customer_map', [
                'ff_customer_id' => $ffCustomerId,
                'push_status'    => $status,
                'push_error'     => $reason,
                'last_synced_at' => $now,
            ]);
        } else {
            db_execute(
                "UPDATE acc_qbo_customer_map
                    SET push_status    = ?,
                        push_error     = ?,
                        last_synced_at = ?
                  WHERE id = ?",
                [$status, $reason, $now, (int) $existing['id']]
            );
        }
    }

    /**
     * Record skipped state. Writes both the map row push_status AND a
     * non-HTTP sync_log row so the operator-facing admin view + audit
     * trail surface every dispatch attempt. Mirrors
     * InvoicePusher::recordSkipped() including the FK guard.
     *
     * @param string $skippedStatus one of: skipped_by_mode | skipped_soft_deleted
     *                              (D-CV-ENUM-SCOPE — no skipped_voided)
     * @param string $operation     the queue operation ('create'|'update')
     */
    private static function recordSkipped(int $ffCustomerId, array $ff, string $skippedStatus, string $operation = 'create'): void
    {
        // FK guard — recordSkipped fires from the sync_mode gate before
        // the customer load, so $ffCustomerId may be a ghost id (smoke
        // tests use pushCreate(0); orphan queue rows pre-S-QBO-ENQUEUER-
        // ELIGIBILITY-GATE could point at hard-deleted customers).
        $exists = db_row("SELECT 1 AS x FROM customers WHERE id = ?", [$ffCustomerId]) !== null;
        if (!$exists) {
            return;
        }

        // ── Map row write ─────────────────────────────────────────
        $existing = db_row(
            "SELECT id FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
            [$ffCustomerId]
        );
        $now = date('Y-m-d H:i:s');

        if ($existing === null) {
            db_insert('acc_qbo_customer_map', [
                'ff_customer_id' => $ffCustomerId,
                'push_status'    => $skippedStatus,
                'last_synced_at' => $now,
            ]);
        } else {
            db_execute(
                "UPDATE acc_qbo_customer_map
                    SET push_status    = ?,
                        last_synced_at = ?
                  WHERE id = ?",
                [$skippedStatus, $now, (int) $existing['id']]
            );
        }

        // ── Sync log row (D-SYNC-LOG-NON-HTTP-INVOICE-4 carry-over) ─
        // Records the non-HTTP skip event for forensic visibility.
        // Best-effort: any error logged + swallowed so a sync_log
        // failure never blocks the map row write.
        try {
            $messages = [
                'skipped_by_mode'      => 'sync_mode.customer refuses FF→QBO direction; skip recorded without QBO push attempt.',
                'skipped_soft_deleted' => 'Customer soft-deleted in FF; skip recorded without QBO push attempt.',
            ];
            $msg = $messages[$skippedStatus] ?? "Skipped: {$skippedStatus}";

            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'customer',
                'entity_id'       => $ffCustomerId,
                'operation'       => $operation,
                'http_method'     => 'SKIP',
                'endpoint'        => '',
                'response_status' => null,
                'error_code'      => $skippedStatus,
                'error_message'   => $msg,
                'queue_id'        => QuickBooksClient::workerQueueId(),
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log(
                "CustomerPusher::recordSkipped sync_log insert failed for ff_customer_id={$ffCustomerId} "
                . "status={$skippedStatus}: " . $e->getMessage()
            );
        }
    }
}
