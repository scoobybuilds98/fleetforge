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
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.1 (customer sync rules),
 *           §6.7 (worker dispatch pattern)
 * @decision D-QBO-6-1 (no delete enqueue), D-QBO-6-2 (idempotency),
 *           D-QBO-6-4 (sync mode gating), D-QBO-6-5 (field mapping)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class CustomerPusher
{
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
            return ['success' => true, 'status' => 'skipped_by_mode', 'mode' => $mode];
        }

        // 2. Load FF customer state. Selects only the columns this
        //    Pusher actually maps to QBO + the soft-delete flag.
        $ff = db_row(
            "SELECT id, company_name, email, phone, address, city, province, postal_code, country, deleted_at, updated_at
               FROM customers
              WHERE id = ?",
            [$ffCustomerId]
        );
        if ($ff === null) {
            return ['success' => false, 'status' => 'ff_not_found', 'error' => "FF customer {$ffCustomerId} not found"];
        }
        if ($ff['deleted_at'] !== null) {
            // Per D-QBO-6-1: FF soft-delete does NOT propagate to QBO.
            // Reaching here means the queue row was enqueued before
            // the customer was soft-deleted (or via an out-of-band path).
            return ['success' => true, 'status' => 'skipped_soft_deleted'];
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
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_customer_id'])) {
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'qbo_id'  => (string) $mapping['qbo_customer_id'],
            ];
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
            return [
                'success'   => false,
                'status'    => 'qbo_error',
                'error'     => $e->getMessage(),
                'error_code' => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : null,
            ];
        }

        // 8. QBO returns { "Customer": {...}, "time": "..." } for both
        //    create and update operations.
        $qboCustomer = $response['Customer'] ?? null;
        if (!is_array($qboCustomer) || empty($qboCustomer['Id'])) {
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'error'   => 'QBO response missing Customer.Id',
            ];
        }

        // 9. Persist the mapping. Snapshot QBO-side fields so drift
        //    detection (S-QBO-24) has a baseline to compare against.
        //    last_push_at gets bumped on every successful round-trip.
        $now = date('Y-m-d H:i:s');
        $snapshot = [
            'qbo_customer_id'  => (string) $qboCustomer['Id'],
            'qbo_sync_token'   => (string) ($qboCustomer['SyncToken']                ?? '0'),
            'qbo_display_name' => (string) ($qboCustomer['DisplayName']              ?? $ff['company_name']),
            'qbo_company_name' => (string) ($qboCustomer['CompanyName']              ?? ''),
            'qbo_email'        => (string) ($qboCustomer['PrimaryEmailAddr']['Address']    ?? ''),
            'qbo_phone'        => (string) ($qboCustomer['PrimaryPhone']['FreeFormNumber'] ?? ''),
            'qbo_active'       => !empty($qboCustomer['Active']) ? 1 : 0,
            'mapping_status'   => 'mapped',
            'last_synced_at'   => $now,
            'last_push_at'     => $now,
        ];

        if ($mapping === null) {
            // First time — INSERT with match_confidence='manual' (the
            // FF customer is operator-confirmed via the Create form,
            // matches D-QBO-6-6-style reasoning from earlier sessions).
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
            $params[] = (int) $mapping['id'];
            db_execute(
                "UPDATE acc_qbo_customer_map SET " . implode(', ', $setSql) . " WHERE id = ?",
                $params
            );
        }

        return [
            'success'    => true,
            'status'     => $effectiveOperation === 'update'
                              ? 'updated'
                              : ($effectiveOperation !== $operation ? 'created_from_update' : 'created'),
            'qbo_id'     => (string) $qboCustomer['Id'],
            'sync_token' => (string) ($qboCustomer['SyncToken'] ?? '0'),
        ];
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
}
