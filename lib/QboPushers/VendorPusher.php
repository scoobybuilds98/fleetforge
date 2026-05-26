<?php
declare(strict_types=1);

/**
 * lib/QboPushers/VendorPusher.php
 *
 * Second FF → QBO push class (S-QBO-7). Handles vendor create +
 * update sync into Intuit QBO. Called by `QboPusherDispatcher::dispatch()`
 * from `cron/qbo_sync_worker.php` when a queued acc_qbo_sync_queue
 * row with entity_type='vendor' is picked up.
 *
 * Mirrors CustomerPusher (S-QBO-6) per §6.8 Pusher Integration
 * Contract locked in D-PUSHER-PATTERNS-DOCS-LOCK (2026-05-21).
 * Smaller domain than customer — no money math, no engine-version
 * dispatch.
 *
 * Method-per-operation contract (D-QBO-3-2 / §6.8 / K-22 Trap #64):
 *   create → pushCreate(int $entityId, ?array $payloadSnapshot = null): array
 *   update → pushUpdate(int $entityId, ?array $payloadSnapshot = null): array
 *
 * No pushVoid / pushDelete for vendors per D-QBO-6-1 carry-over:
 * FF soft-delete does NOT enqueue a QBO delete (accountants don't
 * appreciate auto-deletion; QBO vendor stays alive, mapping row
 * stays mapped, future drift cron S-QBO-24 may flag).
 *
 * Sync mode gating: per-call inspection of
 * `quickbooks.sync_mode.vendor`:
 *   - 'sync' (default) | 'ff_to_qbo' → push allowed
 *   - 'qbo_to_ff' | 'disabled'       → returns ['status'=>'skipped_by_mode']
 *
 * Idempotency (D-QBO-6-2 pattern): pushCreate checks acc_qbo_vendor_map
 * for an existing qbo_vendor_id; if present, returns
 * ['status'=>'already_mapped'] without re-POSTing.
 *
 * Demotion rule (D-PUSHER-DEMOTION-RULE / §6.8): pushUpdate invoked
 * against a mapping row with no qbo_vendor_id (e.g., an ff_only row
 * from auto-match that was never pushed) demotes to create. Returns
 * ['status'=>'created_from_update'] so the worker's audit trail
 * distinguishes the demoted case from a clean create.
 *
 * Field mapping per D-QBO-7-2/-3 (FF vendors → QBO Vendor payload):
 *   vendors.name              → DisplayName + CompanyName
 *   vendors.contact_name      → GivenName + FamilyName (split on first
 *                                space per D-QBO-7-3; no space = all to
 *                                GivenName, FamilyName empty)
 *   vendors.email             → PrimaryEmailAddr.Address
 *   vendors.phone             → PrimaryPhone.FreeFormNumber
 *   vendors.address           → BillAddr.Line1
 *   vendors.city              → BillAddr.City
 *   vendors.state             → BillAddr.CountrySubDivisionCode  (vendors use .state, not .province)
 *   BillAddr.Country = 'CA'   (default; vendors table has no country column today)
 *   vendors.vendor_type       → NOT mapped (D-QBO-7-2 — no clean QBO analog)
 *   is_1099                   → NOT mapped (D-QBO-7-1 — out of scope v1; column doesn't exist on disk anyway)
 *   vendors.deleted_at non-null → SKIP (returns skipped_soft_deleted)
 *
 * @session  S-QBO-7
 * @updated  S-QBO-FIXPACK-2 — always emit CurrencyRef on vendor create
 *           payloads (D-QBO-FIXPACK-6 class-of-entity principle);
 *           vendors table has no currency column so hardcoded 'CAD'
 *           per D-QBO-FIXPACK-8 Option A (Mainland is Canadian);
 *           idempotency-replay mismatch warning when QBO vendor is
 *           currency-poisoned from a prior push (D-QBO-FIXPACK-10).
 * @updated  S-QBO-FIXPACK-3 — CurrencyRef emission gated on
 *           quickbooks.multi_currency_enabled (D-QBO-FIXPACK-12). When
 *           '0': omit CurrencyRef entirely. When '1': emit hardcoded
 *           'CAD' per D-QBO-FIXPACK-8 Option A.
 * @updated  S-QBO-PUSHER-CONTRACT-PAYDOWN — added private RESULT_BASE +
 *           applied §6.8 canonical 5-key return shape on every path
 *           (MEDIUM-C6 fix).
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher Contract),
 *           §7.5 (vendor mapping table)
 * @decision D-QBO-7-1 (1099 out of scope v1),
 *           D-QBO-7-2 (vendor_type NOT mapped),
 *           D-QBO-7-3 (contact_name split on first space),
 *           D-QBO-7-4 (state-machine mapping pattern from D-QBO-5),
 *           D-PUSHER-DEMOTION-RULE (update→create demotion when no qbo_id),
 *           D-QBO-FIXPACK-6 (class-of-entity CurrencyRef principle),
 *           D-QBO-FIXPACK-8 (vendor CurrencyRef emit policy — hardcoded 'CAD'),
 *           D-QBO-FIXPACK-10 (idempotency-replay mismatch warning)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class VendorPusher
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
     * Push a new FF vendor into QBO. Idempotent — if the vendor is
     * already mapped (has a qbo_vendor_id), returns
     * ['status'=>'already_mapped'] without re-POSTing.
     */
    public static function pushCreate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'create', $payloadSnapshot);
    }

    /**
     * Update an existing QBO vendor from FF state. Requires the
     * mapping row to already have qbo_vendor_id + qbo_sync_token.
     * If neither is set the operation is demoted to create + the
     * return status flips to 'created_from_update'.
     */
    public static function pushUpdate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'update', $payloadSnapshot);
    }

    /**
     * Shared orchestration. Both public methods delegate here to keep
     * the per-operation methods tiny and match the dispatcher's
     * OPERATION_METHODS contract (K-22 Trap #64).
     */
    private static function pushImpl(int $ffVendorId, string $operation, ?array $payloadSnapshot): array
    {
        // 1. Sync mode gate — per-call check so an operator flipping the
        //    mode mid-queue gets the new behavior on the next dispatch.
        $mode = (string) settings_get('quickbooks.sync_mode.vendor', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            return ['success' => true, 'status' => 'skipped_by_mode', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load FF vendor state. Selects only the columns this Pusher
        //    actually maps to QBO + the soft-delete flag. vendors uses
        //    `state` (not `province` like customers) and has no
        //    postal_code or country column on disk.
        $ff = db_row(
            "SELECT id, name, contact_name, email, phone, address, city, state, deleted_at, updated_at
               FROM vendors
              WHERE id = ?",
            [$ffVendorId]
        );
        if ($ff === null) {
            return ['success' => false, 'status' => 'ff_not_found', 'error' => "FF vendor {$ffVendorId} not found"] + self::RESULT_BASE;
        }
        if ($ff['deleted_at'] !== null) {
            // D-QBO-6-1 carry-over: FF soft-delete does NOT propagate
            // to QBO. Reaching here means the queue row was enqueued
            // before the vendor was soft-deleted (or via an out-of-band
            // path).
            return ['success' => true, 'status' => 'skipped_soft_deleted'] + self::RESULT_BASE;
        }

        // 3. Look up existing mapping. May be NULL (first push), or
        //    may exist from a prior pull (qbo_only), prior auto-match
        //    (mapped/manual), or a prior push (mapped/manual).
        $mapping = db_row(
            "SELECT id, qbo_vendor_id, qbo_sync_token, mapping_status
               FROM acc_qbo_vendor_map
              WHERE ff_vendor_id = ?",
            [$ffVendorId]
        );

        // 4. Idempotency on CREATE (D-QBO-6-2). If we already have a
        //    qbo_vendor_id, MUST NOT re-POST — would create a duplicate
        //    QBO Vendor with auto-discriminated name. Treat as no-op.
        //
        //    D-QBO-FIXPACK-10: Before returning 'already_mapped', probe
        //    the QBO vendor's CurrencyRef and surface a warning when it
        //    differs from our expected 'CAD' (D-QBO-FIXPACK-8 hardcodes
        //    CAD for all vendors). A prior push that omitted CurrencyRef
        //    could have caused QBO to default to home currency (USD in
        //    Intuit US sandbox), poisoning the mapping permanently.
        //    Best-effort: any network/auth failure is caught silently.
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_vendor_id'])) {
            try {
                $client     = new QuickBooksClient();
                $qboResp    = $client->getEntity('vendor', (string) $mapping['qbo_vendor_id']);
                $qboVend    = $qboResp['Vendor'] ?? null;
                if (is_array($qboVend)) {
                    $qboCurrency = strtoupper((string) ($qboVend['CurrencyRef']['value'] ?? ''));
                    // D-QBO-FIXPACK-8: vendors are always CAD (hardcoded).
                    if ($qboCurrency !== '' && $qboCurrency !== 'CAD') {
                        error_log(
                            "S-QBO-FIXPACK-10 WARNING: FF vendor {$ff['id']} expected CurrencyRef='CAD' " .
                            "but mapped QBO vendor #{$mapping['qbo_vendor_id']} is '{$qboCurrency}'. " .
                            "Mapping is currency-poisoned (Intuit forbids QBO vendor currency change after create). " .
                            "Operator action: unlink FF vendor from QBO (set mapping_status='ff_only'), " .
                            "mark QBO vendor inactive in QBO, then re-push to create a new QBO vendor."
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Silent fallthrough — currency-mismatch check is advisory only.
                // Don't block the idempotency no-op due to a transient QBO error.
                error_log("S-QBO-FIXPACK-10: currency-mismatch check failed for FF vendor {$ff['id']}: " . $e->getMessage());
            }
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'qbo_id'  => (string) $mapping['qbo_vendor_id'],
            ] + self::RESULT_BASE;
        }

        // 5. UPDATE path requires an existing mapping with qbo_vendor_id
        //    AND qbo_sync_token. Without them we can't address the QBO
        //    entity — demote to create per D-PUSHER-DEMOTION-RULE.
        $effectiveOperation = $operation;
        if ($operation === 'update' && ($mapping === null || empty($mapping['qbo_vendor_id']))) {
            $effectiveOperation = 'create';
        }

        // 6. Build payload from FF row.
        $qboPayload = self::buildQboPayload($ff);

        // D-QBO-FIXPACK-8: buildQboPayload always emits CurrencyRef='CAD'
        // (vendors table has no currency column; hardcoded home currency per
        // D-QBO-FIXPACK-8 Option A). On UPDATE, QBO rejects CurrencyRef
        // because vendor currency is immutable after create per Intuit policy.
        // Strip it from update payloads here so the HTTP call succeeds.
        if ($effectiveOperation === 'update') {
            unset($qboPayload['CurrencyRef']);
        }

        // 7. HTTP call via QuickBooksClient. The client handles auth,
        //    retries (transient errors), Sentry capture, and sync_log
        //    writes per S-QBO-2 — Pusher consumes the parsed response.
        //    NEVER use per-entity wrappers like createVendor() — they
        //    don't exist (K-22 Trap #63). Use the typed entity API.
        $client = new QuickBooksClient();
        try {
            if ($effectiveOperation === 'update') {
                $response = $client->updateEntity(
                    'vendor',
                    (string) $mapping['qbo_vendor_id'],
                    (string) ($mapping['qbo_sync_token'] ?? '0'),
                    $qboPayload
                );
            } else {
                $response = $client->createEntity('vendor', $qboPayload);
            }
        } catch (QuickBooksException $e) {
            return [
                'success'    => false,
                'status'     => 'qbo_error',
                'error'      => $e->getMessage(),
                'error_code' => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : null,
            ] + self::RESULT_BASE;
        }

        // 8. QBO returns { "Vendor": {...}, "time": "..." } for both
        //    create and update operations.
        $qboVendor = $response['Vendor'] ?? null;
        if (!is_array($qboVendor) || empty($qboVendor['Id'])) {
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'error'   => 'QBO response missing Vendor.Id',
            ] + self::RESULT_BASE;
        }

        // 9. Persist the mapping. Snapshot QBO-side fields so drift
        //    detection (future S-QBO-24) has a baseline to compare
        //    against. last_push_at gets bumped on every successful
        //    round-trip.
        $now = date('Y-m-d H:i:s');
        $snapshot = [
            'qbo_vendor_id'    => (string) $qboVendor['Id'],
            'qbo_sync_token'   => (string) ($qboVendor['SyncToken']                ?? '0'),
            'qbo_display_name' => (string) ($qboVendor['DisplayName']              ?? $ff['name']),
            'qbo_company_name' => (string) ($qboVendor['CompanyName']              ?? ''),
            'qbo_given_name'   => (string) ($qboVendor['GivenName']                ?? ''),
            'qbo_family_name'  => (string) ($qboVendor['FamilyName']               ?? ''),
            'qbo_email'        => (string) ($qboVendor['PrimaryEmailAddr']['Address']    ?? ''),
            'qbo_phone'        => (string) ($qboVendor['PrimaryPhone']['FreeFormNumber'] ?? ''),
            'qbo_active'       => !empty($qboVendor['Active']) ? 1 : 0,
            'qbo_v4v_status'   => (string) ($qboVendor['V4VStatus']                ?? ''),
            'mapping_status'   => 'mapped',
            'last_synced_at'   => $now,
            'last_push_at'     => $now,
        ];

        if ($mapping === null) {
            // First time — INSERT with match_confidence='manual' (the
            // FF vendor is operator-confirmed via the Create form).
            db_insert('acc_qbo_vendor_map', $snapshot + [
                'ff_vendor_id'    => $ffVendorId,
                'match_confidence'=> 'manual',
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
            $params[] = (int) $mapping['id'];
            db_execute(
                "UPDATE acc_qbo_vendor_map SET " . implode(', ', $setSql) . " WHERE id = ?",
                $params
            );
        }

        return [
            'success'    => true,
            'status'     => $effectiveOperation === 'update'
                              ? 'updated'
                              : ($effectiveOperation !== $operation ? 'created_from_update' : 'created'),
            'qbo_id'     => (string) $qboVendor['Id'],
            'sync_token' => (string) ($qboVendor['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Build the QBO Vendor payload from an FF vendors row.
     *
     * Defensive about empty / missing fields — QBO is lenient on create
     * (DisplayName is the only strictly required field) but the payload
     * must not contain empty nested objects (e.g. BillAddr with no
     * Line1). `array_filter()` drops null + empty-string entries
     * before deciding whether to attach the nested object.
     *
     * contact_name → GivenName + FamilyName split per D-QBO-7-3:
     *   split on FIRST space; no space → all to GivenName,
     *   FamilyName empty. Captures "John Smith" → ('John', 'Smith')
     *   AND "John A. Smith Jr." → ('John', 'A. Smith Jr.')
     *   AND single names like "Cher" → ('Cher', '').
     *
     * Emits CurrencyRef='CAD' only when quickbooks.multi_currency_enabled='1'
     * (D-QBO-FIXPACK-12). The vendors table has no currency column; all FF
     * vendors are Canadian (hardcoded 'CAD' per D-QBO-FIXPACK-8 Option A).
     * When multi-currency is disabled: omits CurrencyRef so single-currency
     * QBO companies don't receive error 6000. Queue S-VENDOR-CURRENCY-COLUMN
     * if multi-currency vendor support is needed in the future.
     * NOTE: pushImpl strips CurrencyRef from UPDATE payloads because
     * QBO rejects it (vendor currency immutable after create).
     *
     * Public-static so the offline smoke can exercise it without
     * going through the cURL boundary.
     *
     * @param  array<string, mixed> $ff FF vendors row
     * @return array<string, mixed>      QBO Vendor payload
     */
    public static function buildQboPayload(array $ff): array
    {
        $payload = [
            'DisplayName' => (string) ($ff['name'] ?? ''),
            'CompanyName' => (string) ($ff['name'] ?? ''),
        ];

        // D-QBO-FIXPACK-12: Gate CurrencyRef emission on multi-currency setting.
        // WHY: QBO error 6000 fires when CurrencyRef is sent to a single-currency
        // company (D-QBO-FIXPACK-3 root cause discovery). Emit only when the
        // connected QBO company has multi-currency enabled (auto-detected at
        // connect/refresh time and cached in quickbooks.multi_currency_enabled).
        // When '1': emit CurrencyRef='CAD' per D-QBO-FIXPACK-8 Option A
        //   (vendors table has no currency column; Mainland is Canadian).
        // When '0': omit CurrencyRef entirely; QBO enforces home currency.
        // (pushImpl strips CurrencyRef for update ops regardless of this gate.)
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $payload['CurrencyRef'] = ['value' => 'CAD'];
        }
        // When multi_currency_enabled='0': omit CurrencyRef (QBO error 6000 otherwise).

        // D-QBO-7-3: split contact_name into GivenName + FamilyName.
        $contactName = trim((string) ($ff['contact_name'] ?? ''));
        if ($contactName !== '') {
            $spacePos = strpos($contactName, ' ');
            if ($spacePos === false) {
                $payload['GivenName']  = $contactName;
                $payload['FamilyName'] = '';
            } else {
                $payload['GivenName']  = substr($contactName, 0, $spacePos);
                $payload['FamilyName'] = trim(substr($contactName, $spacePos + 1));
            }
        }

        if (!empty($ff['email'])) {
            $payload['PrimaryEmailAddr'] = ['Address' => (string) $ff['email']];
        }
        if (!empty($ff['phone'])) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => (string) $ff['phone']];
        }

        // Build BillAddr only when at least one populated address field
        // exists. Country defaults to 'CA' — only FF deployment is
        // Canadian per spec §0; vendors table has no country column
        // today so the literal default is correct, not just a safe
        // assumption. vendors.state maps to QBO CountrySubDivisionCode
        // (vendors don't have separate province/state — single column).
        $addr = array_filter([
            'Line1'                  => $ff['address'] ?? null,
            'City'                   => $ff['city']    ?? null,
            'CountrySubDivisionCode' => $ff['state']   ?? null,
            'Country'                => 'CA',
        ], static fn($v) => $v !== null && $v !== '');
        // Only attach BillAddr when at least one address line is real
        // (Line1 or City). Country alone isn't enough.
        if (!empty($addr['Line1']) || !empty($addr['City'])) {
            $payload['BillAddr'] = $addr;
        }

        return $payload;
    }
}
