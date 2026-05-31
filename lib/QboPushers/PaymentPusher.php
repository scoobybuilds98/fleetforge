<?php
declare(strict_types=1);

/**
 * lib/QboPushers/PaymentPusher.php
 *
 * Phase QBO-6 / 2 of 3 (S-QBO-14) — paired with S-QBO-13 webhook puller.
 *
 * FF→QBO payment push for FF-originated cash applications. The S-QBO-13
 * pull side (PaymentWebhookHandler) handles QBO Payments-originated cash;
 * S-QBO-14 fills in the push side for payments operators record in FF
 * directly (cheque, ACH, EFT, etc.). Both halves share acc_qbo_payment_map
 * (bidirectional schema shipped in S-QBO-13; no new migration here).
 *
 * Critical invariant — bidirectional dedup (D-QBO-14-1, closing D-QBO-13-1/2
 * at the push layer):
 *   - PaymentEnqueuer gate-0 rejects payment.origin != 'ff_native'
 *   - PaymentPusher::pushImpl ALSO rejects payment.origin != 'ff_native'
 *     (defense-in-depth: orphan queue rows from CLI invocations, race
 *      between enqueue and dispatch, manual operator queue inserts)
 *   - Together, these guarantee a webhook-originated payment can never
 *     be pushed back to QBO (the map row carries push_status='pulled_from_qbo'
 *     as terminal state).
 *
 * Differences from BillPusher template:
 *   - Trigger: api/v1/payments/create.php fires enqueue after db_transaction
 *     commits (status='cleared' is the default for FF payments).
 *   - CustomerRef (not VendorRef) lookup via acc_qbo_customer_map.
 *   - DepositToAccountRef = QBO UndepositedFunds account (resolved via
 *     acc_qbo_account_map where critical_category='undeposited_funds';
 *     AccountValidator::assertReadyForPaymentPush gates this pre-push).
 *   - Line[].LinkedTxn[] allocates to QBO Invoice(s) — for each FF
 *     payment_allocations row, look up qbo_invoice_id via
 *     acc_qbo_invoice_map and emit LinkedTxn{TxnId, TxnType='Invoice'}.
 *   - PaymentRefNum = payment.reference_number (21-char limit per
 *     D-QBO-14-6 via QboFieldLimits::PAYMENT_REF_NUM_MAX).
 *   - No TxnTaxDetail (payments don't carry tax — tax is invoice-level).
 *   - No DetailType (QBO Payment Lines just have LinkedTxn arrays — there's
 *     no AccountBasedExpenseLineDetail or SalesItemLineDetail at line level).
 *   - Currency-mismatch gate (D-QBO-14-7, mirrors D-QBO-BILL-GOTCHAS-1
 *     for bills) — checks payment.currency against QBO customer.CurrencyRef
 *     when multi_currency_enabled='1'.
 *   - pushUpdate IMPLEMENTED in S-QBO-PAYMENT-UPDATE (closes the D-QBO-14-5
 *     stub): routes through pushImpl with operation='update' — full-payload
 *     re-send via QuickBooksClient::updateEntity + SyncToken round-trip
 *     (mirrors InvoicePusher D-QBO-12-1 + BillPusher D-QBO-BILL-UPDATE-1),
 *     demote-to-create when unmapped. Origin/dedup guards (steps 5+6) run
 *     BEFORE the operation branch so an 'update' of a webhook-originated
 *     payment is still rejected as skipped_non_ff_origin — the D-QBO-14-1
 *     invariant covers both verbs without extra code (verified by smoke
 *     C25 in S-QBO-PAYMENT-UPDATE).
 *   - Status gate: payment.status='cleared' required (D-QBO-14-2);
 *     pending/failed/voided/refunded/returned payments are skipped.
 *
 * @session  S-QBO-14
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher Contract),
 *           §8.5 (Payment payload), §7.7 acc_qbo_payment_map (shipped
 *           S-QBO-13)
 * @decision D-QBO-14-1 (origin='ff_native' filter; closes D-QBO-13-1/2
 *               invariant at push layer),
 *           D-QBO-14-2 (payment.status='cleared' eligibility),
 *           D-QBO-14-3 (LinkedTxn.TxnType='Invoice' only in v1),
 *           D-QBO-14-4 (DepositToAccountRef from critical_category=
 *               'undeposited_funds' lookup),
 *           D-QBO-14-5 (pushUpdate stub — CLOSED by S-QBO-PAYMENT-UPDATE /
 *               D-QBO-PAYMENT-UPDATE-1),
 *           D-PUSHER-DEMOTION-RULE (update→create demotion — ACTIVE since
 *               S-QBO-PAYMENT-UPDATE: pushImpl step 6b demotes an 'update' of
 *               an unmapped payment to 'create'),
 *           D-QBO-14-6 (PaymentRefNum 21-char field-length gate via
 *               QboFieldLimits::PAYMENT_REF_NUM_MAX),
 *           D-QBO-14-7 (currency-mismatch gate mirroring D-QBO-BILL-GOTCHAS-1),
 *           D-QBO-13-1 (origin column — already locked S-QBO-13),
 *           D-QBO-13-2 (push_status='pulled_from_qbo' terminal state —
 *               already locked S-QBO-13),
 *           D-QBO-FIXPACK-6 (class-of-entity CurrencyRef principle),
 *           D-QBO-FIXPACK-12 (CurrencyRef emission gated on
 *               multi_currency_enabled),
 *           D-QBO-FIXPACK-10 (idempotency-replay mismatch warning),
 *           D-PUSHER-OUTCOME-FIELD-UNIVERSAL-2 (outcome field on every
 *               return path)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class PaymentPusher
{
    /**
     * §6.8 canonical return shape — merged onto every return path so
     * downstream consumers (worker tally, smoke assertions) get a
     * predictable key set. Mirrors BillPusher / InvoicePusher RESULT_BASE.
     */
    private const RESULT_BASE = [
        'success'    => false,
        'status'     => null,
        'outcome'    => null,
        'qbo_id'     => null,
        'sync_token' => null,
        'error'      => null,
    ];

    /**
     * Push a new FF payment into QBO. Idempotent — if the payment is
     * already mapped (has a qbo_payment_id), returns ['status' =>
     * 'already_mapped'] without re-POSTing.
     */
    public static function pushCreate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'create', $payloadSnapshot);
    }

    /**
     * Push an UPDATE to an existing QBO payment (S-QBO-PAYMENT-UPDATE,
     * closing the D-QBO-14-5 stub). Full-payload re-send via
     * QuickBooksClient::updateEntity — not a sparse diff (mirrors
     * InvoicePusher D-QBO-12-1 + BillPusher D-QBO-BILL-UPDATE-1). FF is
     * canonical (D-QBO-CORE-1); QBO accepts the complete payload + current
     * SyncToken and replaces.
     *
     * Demotion (D-PUSHER-DEMOTION-RULE): if the payment was never pushed
     * (no map row / null qbo_payment_id), pushImpl auto-demotes to 'create'
     * and re-POSTs — an update of an unmapped entity is just a first push.
     *
     * Bidirectional dedup (D-QBO-14-1): pushImpl steps 5+6 reject any
     * non-ff_native payment (or any map row with push_status='pulled_from_qbo')
     * BEFORE the operation branch — so an update of a webhook-originated
     * payment never reaches the HTTP call (smoke C25).
     *
     * @return array  §6.8 5-key return shape; status='pushed' on a
     *                successful update (acc_qbo_payment_map.push_status
     *                ENUM has no 'updated' value, so we reuse 'pushed';
     *                no migration this session), outcome='updated'.
     *                Demoted-to-create returns outcome='created'. Plus the
     *                skip / failure status codes inherited from pushImpl.
     */
    public static function pushUpdate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'update', $payloadSnapshot);
    }

    /**
     * Push a VOID to an existing QBO payment (S-QBO-PUSHVOID-TRIO, closing
     * the F7 pushVoid follow-up for payments). Separate pipeline from
     * pushImpl — mirrors InvoicePusher::pushVoidImpl (D-QBO-12-3/4/5):
     *
     *   - Void trigger (D-QBO-PUSHVOID-TRIO-1): payments are soft-deleted
     *     (NOT status='void' — payments.status ENUM has no 'void' value).
     *     The canonical reversal path is api/v1/payments/delete.php which
     *     sets deleted_at + reverses the JE/counters. So pushVoid keys on
     *     deleted_at IS NOT NULL. (refunded / partially_refunded map to a
     *     QBO RefundReceipt — a different entity, deferred — and bounced is
     *     a separate reversal; neither triggers a Payment void here.)
     *   - Idempotent on push_status='voided' (already_voided no-op).
     *   - No mapping / null qbo_payment_id → skipped_unmapped_void (FF
     *     payment soft-deleted before it was ever pushed — nothing in QBO).
     *   - origin guard: a webhook-originated / pulled_from_qbo map row is
     *     never voided by the push side (D-QBO-14-1 — that QBO Payment is
     *     QBO-canonical; FF must not void it via our push pipeline).
     *   - voidEntity('payment') HTTP call.
     *
     * @return array §6.8 5-key shape; status='voided' on success,
     *               'already_voided' on idempotent replay,
     *               'skipped_unmapped_void' for never-pushed payments.
     */
    public static function pushVoid(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushVoidImpl($entityId);
    }

    /**
     * Separate void pipeline (mirrors InvoicePusher::pushVoidImpl). Keeps
     * pushImpl untouched — the void invariant (deleted_at IS NOT NULL)
     * inverts pushImpl's soft-delete skip, so a shared pipeline would fight
     * itself.
     */
    private static function pushVoidImpl(int $ffPaymentId): array
    {
        // 1. Sync mode gate.
        $mode = (string) settings_get('quickbooks.sync_mode.payment', 'queue');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $ffForSkip = db_row("SELECT id, status, origin FROM payments WHERE id = ?", [$ffPaymentId])
                ?? ['id' => $ffPaymentId, 'status' => null, 'origin' => null];
            self::recordSkipped($ffPaymentId, $ffForSkip, 'skipped_by_mode', 'void');
            return [
                'success' => true,
                'status'  => 'skipped_by_mode',
                'outcome' => 'skipped',
                'mode'    => $mode,
            ] + self::RESULT_BASE;
        }

        // 2. Load FF payment (include deleted rows — the void trigger IS the
        //    soft-delete, so a normal "WHERE deleted_at IS NULL" would hide it).
        $ff = db_row(
            "SELECT id, payment_number, status, origin, deleted_at FROM payments WHERE id = ?",
            [$ffPaymentId]
        );
        if ($ff === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF payment {$ffPaymentId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. INVERTED invariant (D-QBO-PUSHVOID-TRIO-1): payment must be
        //    soft-deleted. The canonical void trigger is payments/delete.php
        //    post-commit, where deleted_at is guaranteed set. This catches
        //    mis-dispatch (manual CLI / stale queue row for a live payment).
        if ($ff['deleted_at'] === null) {
            return [
                'success' => false,
                'status'  => 'void_status_mismatch',
                'outcome' => 'failed',
                'error'   => "Cannot push void for FF payment {$ffPaymentId}: deleted_at IS NULL (payment not voided/soft-deleted)",
            ] + self::RESULT_BASE;
        }

        // 4. Mapping lookup. Void requires an existing QBO entity.
        $mapping = db_row(
            "SELECT id, qbo_payment_id, qbo_sync_token, push_status
               FROM acc_qbo_payment_map
              WHERE ff_payment_id = ?",
            [$ffPaymentId]
        );

        // 4a. No mapping → FF payment soft-deleted before ever pushing.
        //     Nothing in QBO to void (D-QBO-12-5 pattern). No row written.
        if ($mapping === null || empty($mapping['qbo_payment_id'])) {
            return [
                'success' => true,
                'status'  => 'skipped_unmapped_void',
                'outcome' => 'skipped',
            ] + self::RESULT_BASE;
        }

        // 4b. Origin guard (D-QBO-14-1): a webhook-originated payment carries
        //     push_status='pulled_from_qbo' — that QBO Payment is QBO-canonical
        //     and must NOT be voided via our push side. Skip without touching
        //     the terminal map row.
        if ($mapping['push_status'] === 'pulled_from_qbo') {
            self::writeNonHttpSyncLog(
                $ffPaymentId,
                'void',
                'skipped_non_ff_origin',
                "payment {$ff['payment_number']} map row has push_status='pulled_from_qbo'; webhook-originated payments are not voided via push (D-QBO-14-1)"
            );
            return [
                'success' => true,
                'status'  => 'skipped_non_ff_origin',
                'outcome' => 'skipped',
                'qbo_id'  => (string) $mapping['qbo_payment_id'],
            ] + self::RESULT_BASE;
        }

        // 4c. Already voided → idempotent no-op (D-QBO-12-4).
        if ($mapping['push_status'] === 'voided') {
            return [
                'success'    => true,
                'status'     => 'already_voided',
                'outcome'    => 'voided',
                'qbo_id'     => (string) $mapping['qbo_payment_id'],
                'sync_token' => (string) ($mapping['qbo_sync_token'] ?? '0'),
            ] + self::RESULT_BASE;
        }

        // 5. HTTP void via QuickBooksClient::voidEntity.
        $client = new QuickBooksClient();
        try {
            $response = $client->voidEntity(
                'payment',
                (string) $mapping['qbo_payment_id'],
                (string) ($mapping['qbo_sync_token'] ?? '0')
            );
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffPaymentId, 'Void failed: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'qbo_error',
                'outcome' => 'failed',
                'error'   => 'Void failed: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        $qboPayment = $response['Payment'] ?? null;
        if (!is_array($qboPayment) || empty($qboPayment['Id'])) {
            self::recordPushFailure($ffPaymentId, 'QBO void response missing Payment.Id');
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO void response missing Payment.Id',
            ] + self::RESULT_BASE;
        }

        // 6. Persist voided state on the existing map row.
        self::upsertMappingRow($ffPaymentId, [
            'qbo_sync_token' => (string) ($qboPayment['SyncToken'] ?? '0'),
            'push_status'    => 'voided',
            'push_error'     => null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'success'    => true,
            'status'     => 'voided',
            'outcome'    => 'voided',
            'qbo_id'     => (string) $qboPayment['Id'],
            'sync_token' => (string) ($qboPayment['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Shared orchestration. Both public methods delegate here to keep
     * the per-operation methods tiny and match the dispatcher's
     * OPERATION_METHODS contract.
     */
    private static function pushImpl(int $ffPaymentId, string $operation, ?array $payloadSnapshot): array
    {
        // 1. Sync mode gate — per-call check so an operator flipping the
        //    mode mid-queue gets the new behavior on the next dispatch.
        $mode = (string) settings_get('quickbooks.sync_mode.payment', 'queue');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $ffForSkip = db_row("SELECT id, status, origin FROM payments WHERE id = ?", [$ffPaymentId]) ?? ['id' => $ffPaymentId, 'status' => null, 'origin' => null];
            self::recordSkipped($ffPaymentId, $ffForSkip, 'skipped_by_mode', $operation);
            return [
                'success' => true,
                'status'  => 'skipped_by_mode',
                'outcome' => 'skipped',
                'error'   => "sync_mode.payment={$mode}",
            ] + self::RESULT_BASE;
        }

        // 2. Load FF payment state. Selects columns this Pusher actually
        //    maps to QBO. payments use status='cleared' for the standard
        //    pushable state (D-QBO-14-2); other states (pending/failed/
        //    voided/refunded/returned) skip pre-flight.
        $ff = db_row(
            "SELECT id, payment_number, customer_id, amount, currency, payment_method,
                    reference_number, payment_date, status, origin,
                    exchange_rate_to_cad, updated_at, deleted_at
               FROM payments
              WHERE id = ?",
            [$ffPaymentId]
        );
        if ($ff === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF payment {$ffPaymentId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Look up existing mapping. May be NULL (first push), or may
        //    exist from a prior push, or may exist with push_status=
        //    'pulled_from_qbo' (webhook-originated; must skip — see step 5).
        $mapping = db_row(
            "SELECT id, qbo_payment_id, qbo_sync_token, qbo_currency, push_status, origin AS map_origin
               FROM acc_qbo_payment_map
              WHERE ff_payment_id = ?",
            [$ffPaymentId]
        );

        // 4. Soft-deleted payment skip — mirror invoice's skipped_soft_deleted
        //    state. FF payments are soft-deleted (deleted_at non-null); the
        //    QBO-side state isn't auto-reconciled in v1.
        if ($ff['deleted_at'] !== null) {
            self::recordSkipped($ffPaymentId, $ff, 'skipped_by_mode', $operation);
            return [
                'success' => true,
                'status'  => 'skipped_by_mode',
                'outcome' => 'skipped',
                'error'   => "payment {$ff['payment_number']} is soft-deleted (deleted_at non-null)",
            ] + self::RESULT_BASE;
        }

        // 5. CRITICAL — bidirectional dedup invariant per D-QBO-14-1.
        //    PaymentEnqueuer gate-0 rejects origin != 'ff_native' but
        //    payment can land in dispatch via CLI invocation, race, or
        //    manual queue insert. Pusher defends with the same check.
        //
        //    Two sub-cases:
        //    (a) origin = 'qbo_payments_webhook' (already in QBO via webhook).
        //        Map row exists with push_status='pulled_from_qbo' as
        //        terminal state. Re-pushing would create a DUPLICATE QBO
        //        Payment with auto-discriminated id. NEVER push.
        //    (b) origin = 'qbo_other' (accountant created in QBO directly,
        //        pulled by some future S-QBO-26 path). Same skip semantics.
        //
        //    Recording strategy mirrors BillPusher::skipped_unmapped_void
        //    (D-QBO-18-4): do NOT overwrite the map row. If a map row
        //    exists (from S-QBO-13 pull), its push_status='pulled_from_qbo'
        //    is the canonical terminal state and must be preserved. If no
        //    map row exists yet (edge case — operator inserted a non-
        //    ff_native payment without a webhook event), don't write one;
        //    the sync_log SKIP sentinel captures the diagnostic. This
        //    avoids the need for a 'skipped_non_ff_origin' ENUM value on
        //    push_status (no migration in this session per PART B).
        if ($ff['origin'] !== 'ff_native') {
            self::writeNonHttpSyncLog(
                $ffPaymentId,
                $operation,
                'skipped_non_ff_origin',
                "payment {$ff['payment_number']} origin='{$ff['origin']}' (D-QBO-14-1 dedup)"
            );
            return [
                'success' => true,
                'status'  => 'skipped_non_ff_origin',
                'outcome' => 'skipped',
                'error'   => "payment {$ff['payment_number']} has origin='{$ff['origin']}'; only ff_native payments are pushed (D-QBO-14-1; bidirectional dedup with D-QBO-13)",
                'qbo_id'  => ($mapping !== null && !empty($mapping['qbo_payment_id'])) ? (string) $mapping['qbo_payment_id'] : null,
            ] + self::RESULT_BASE;
        }

        // 6. Defense-in-depth: if map row already exists with push_status=
        //    'pulled_from_qbo', that's a strong signal the payment came
        //    from a webhook and origin got mis-tagged or migrated. Skip
        //    without touching the map row (preserves terminal state).
        if ($mapping !== null && $mapping['push_status'] === 'pulled_from_qbo') {
            self::writeNonHttpSyncLog(
                $ffPaymentId,
                $operation,
                'skipped_non_ff_origin',
                "payment {$ff['payment_number']} map row has push_status='pulled_from_qbo'"
            );
            return [
                'success' => true,
                'status'  => 'skipped_non_ff_origin',
                'outcome' => 'skipped',
                'error'   => "map row for payment {$ff['payment_number']} has push_status='pulled_from_qbo'; webhook-originated payments are not re-pushed",
                'qbo_id'  => (string) ($mapping['qbo_payment_id'] ?? ''),
            ] + self::RESULT_BASE;
        }

        // 7. Idempotency on CREATE (mirror of D-QBO-6-2). If we already
        //    have a qbo_payment_id, MUST NOT re-POST. Treat as no-op.
        //    D-QBO-FIXPACK-10: surface mismatch warning on already_mapped.
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_payment_id'])) {
            $expectedCurrency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            $snapshotCurrency = strtoupper((string) ($mapping['qbo_currency'] ?? ''));
            if ($snapshotCurrency !== '' && $snapshotCurrency !== $expectedCurrency) {
                error_log(
                    "S-QBO-FIXPACK-10 WARNING: FF payment {$ff['id']} expected CurrencyRef='{$expectedCurrency}' " .
                    "but mapped QBO payment #{$mapping['qbo_payment_id']} snapshot is '{$snapshotCurrency}'. " .
                    "Mapping is currency-poisoned (Intuit forbids QBO payment currency change after create). " .
                    "Operator action: investigate the payment mapping; may need unlink + re-push."
                );
            }
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',
                'qbo_id'  => (string) $mapping['qbo_payment_id'],
            ] + self::RESULT_BASE;
        }

        // 7b. Update demotion (D-PUSHER-DEMOTION-RULE, mirrors InvoicePusher
        //     D-QBO-12-1 + BillPusher S-QBO-BILL-UPDATE step 5b). An
        //     'update' of a payment that was never pushed (no map row OR
        //     null qbo_payment_id) is just a first push — demote to
        //     'create' so the HTTP dispatch in step 11 calls createEntity.
        //     Steps 5+6 already rejected non-ff_native and pulled_from_qbo
        //     map rows; here we only see legitimate ff_native unmapped rows.
        if ($operation === 'update' && ($mapping === null || empty($mapping['qbo_payment_id']))) {
            $operation = 'create';
        }

        // 8. Status must be 'cleared' to push (D-QBO-14-2). Payments in
        //    other states (pending/failed/voided/refunded/returned) skip
        //    — operator either hasn't confirmed receipt yet (pending) or
        //    the payment was reversed (refunded/returned/void). Pushing
        //    these would create incorrect QBO state.
        if ($ff['status'] !== 'cleared') {
            self::recordFailedPreflight(
                $ffPaymentId,
                "Payment {$ff['payment_number']} status='{$ff['status']}'; push requires status='cleared' (D-QBO-14-2)."
            );
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => "payment status='{$ff['status']}' — push requires 'cleared'",
            ] + self::RESULT_BASE;
        }

        // 9. Pre-flight gates — customer mapping + chart-of-accounts +
        //    per-allocation invoice mapping + UndepositedFunds resolution +
        //    tax-override target + connection status + field length +
        //    currency-mismatch.
        $preflightError = self::runPreflight($ff);
        if ($preflightError !== null) {
            if (is_array($preflightError)) {
                $reason     = (string) ($preflightError['reason'] ?? 'preflight failed');
                $statusCode = (string) ($preflightError['status_code'] ?? 'failed_preflight');
            } else {
                $reason     = (string) $preflightError;
                $statusCode = 'failed_preflight';
            }
            if ($statusCode !== 'failed_preflight') {
                self::recordTypedPreflight($ffPaymentId, $statusCode, $reason);
            } else {
                self::recordFailedPreflight($ffPaymentId, $reason);
            }
            return [
                'success' => false,
                'status'  => $statusCode,
                'outcome' => 'failed',
                'error'   => $reason,
            ] + self::RESULT_BASE;
        }

        // 10. Build payload from FF row + allocations.
        try {
            $qboPayload = self::buildQboPayload($ff);
        } catch (QuickBooksException $e) {
            self::recordFailedPreflight($ffPaymentId, 'Payload build failed: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build failed: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        } catch (\Throwable $e) {
            self::recordFailedPreflight($ffPaymentId, 'Payload build unexpected error: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build unexpected error: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 11. HTTP call via QuickBooksClient. createEntity for a new payment,
        //     updateEntity for an existing one (full-payload re-send per
        //     D-QBO-12-1; updateEntity merges Id + SyncToken into the
        //     payload). SyncToken comes from the mapping row (refreshed on
        //     every push). Demotion in step 7b guarantees $mapping has a
        //     qbo_payment_id whenever $operation is still 'update' here.
        $client = new QuickBooksClient();
        try {
            if ($operation === 'update') {
                $response = $client->updateEntity(
                    'payment',
                    (string) $mapping['qbo_payment_id'],
                    (string) ($mapping['qbo_sync_token'] ?? '0'),
                    $qboPayload
                );
            } else {
                $response = $client->createEntity('payment', $qboPayload);
            }
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffPaymentId, $e->getMessage());
            return [
                'success' => false,
                'status'  => 'qbo_error',
                'outcome' => 'failed',
                'error'   => $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 12. Parse response + persist mapping. QBO returns the created
        //     Payment entity under 'Payment' key.
        $qboPayment = $response['Payment'] ?? null;
        if (!is_array($qboPayment) || empty($qboPayment['Id'])) {
            self::recordPushFailure($ffPaymentId, 'QBO response missing Payment.Id');
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing Payment.Id',
            ] + self::RESULT_BASE;
        }

        self::recordSuccessfulPush($ffPaymentId, $ff, $qboPayment);

        return [
            'success'    => true,
            'status'     => 'pushed',
            // acc_qbo_payment_map.push_status ENUM has no 'updated' value —
            // the map row stays 'pushed' for both create + update. The
            // transient outcome field distinguishes them for the worker
            // tally / smoke assertions.
            'outcome'    => $operation === 'update' ? 'updated' : 'created',
            'qbo_id'     => (string) $qboPayment['Id'],
            'sync_token' => (string) ($qboPayment['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    // ────────────────────────────────────────────────────────────────────
    // Pre-flight gates
    // ────────────────────────────────────────────────────────────────────

    /**
     * Inline pre-flight gates. Returns NULL on pass; legacy string on
     * generic-failed_preflight; array{reason,status_code} on typed
     * preflight failures (gates 7-8 currently — field-too-long +
     * currency-mismatch).
     *
     * Gates:
     *   1. Customer mapping — acc_qbo_customer_map.mapping_status='mapped'
     *      AND qbo_customer_id non-null for ff.customer_id.
     *   2. AccountValidator::assertReadyForPaymentPush — requires
     *      ar_clearing + undeposited_funds categories mapped.
     *   3. Per-allocation invoice mapping — every payment_allocations
     *      row must point to an FF invoice with acc_qbo_invoice_map
     *      row + qbo_invoice_id non-null.
     *   4. UndepositedFunds account resolution — find acc_qbo_account_map
     *      row where critical_category='undeposited_funds' AND
     *      mapping_status='mapped'. (Validator gate 2 confirms one
     *      exists; this gate fetches the qbo_account_id for payload.)
     *   5. Tax-override target set (system-readiness signal — payments
     *      don't use tax-override directly, but a missing target signals
     *      incomplete QBO setup).
     *   6. Connection healthy.
     *   7. Field-length: PaymentRefNum ≤21 chars per D-QBO-14-6.
     *   8. Currency-mismatch (when multi-currency enabled).
     *
     * @return null|string|array{reason: string, status_code: string}
     */
    private static function runPreflight(array $ff)
    {
        // Gate 1: Customer mapping.
        $customerMap = db_row(
            "SELECT qbo_customer_id, mapping_status FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
            [(int) $ff['customer_id']]
        );
        if ($customerMap === null || empty($customerMap['qbo_customer_id']) || $customerMap['mapping_status'] !== 'mapped') {
            return "Customer #{$ff['customer_id']} is not mapped to QBO. Map via /quickbooks/customers before pushing the payment.";
        }

        // Gate 2: AccountValidator — ar_clearing + undeposited_funds.
        try {
            AccountValidator::assertReadyForPaymentPush();
        } catch (ChartOfAccountsIncompleteException $e) {
            return 'AccountValidator: ' . $e->getMessage();
        }

        // Gate 3: Per-allocation invoice mapping. Payments without
        // allocations are unallocated deposits (deferred to a follow-up;
        // v1 only handles invoice-allocated payments per D-QBO-14-3).
        $allocations = db_select(
            "SELECT id, invoice_id, amount
               FROM payment_allocations
              WHERE payment_id = ?
              ORDER BY id",
            [(int) $ff['id']]
        );
        if (empty($allocations)) {
            return "Payment {$ff['payment_number']} has no invoice allocations. v1 only pushes invoice-allocated payments (D-QBO-14-3). Standalone deposits + account_credit allocations deferred.";
        }
        foreach ($allocations as $alloc) {
            $invMap = db_row(
                "SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
                [(int) $alloc['invoice_id']]
            );
            if ($invMap === null || empty($invMap['qbo_invoice_id'])) {
                return "Payment {$ff['payment_number']} allocates to FF invoice #{$alloc['invoice_id']} which has no QBO mapping. Push the invoice (S-QBO-11) before pushing the payment.";
            }
        }

        // Gate 4: UndepositedFunds account resolution. Validator gate 2
        // confirmed a mapped row exists; here we just verify we can
        // fetch the qbo_account_id (used at payload-build time).
        $ufRow = db_row(
            "SELECT qbo_account_id
               FROM acc_qbo_account_map
              WHERE critical_category = 'undeposited_funds'
                AND mapping_status = 'mapped'
                AND qbo_account_id IS NOT NULL
              LIMIT 1"
        );
        if ($ufRow === null || empty($ufRow['qbo_account_id'])) {
            // Should be impossible after Validator gate 2; defensive.
            return "QBO UndepositedFunds account not resolved. Map an FF account with critical_category='undeposited_funds' via /quickbooks/accounts.";
        }

        // Gate 5: Tax-override target.
        $overrideId = (string) settings_get('quickbooks.tax_override_code_id', '');
        if ($overrideId === '') {
            return "QBO tax-override target ('NON') not configured. Run a Pull on /quickbooks/tax_codes to auto-wire it (D-QBO-9-2).";
        }

        // Gate 6: Connection status.
        $connStatus = (string) settings_get('quickbooks.connection_status', '');
        if ($connStatus !== 'connected') {
            return "QBO connection is not connected (status={$connStatus}). Connect via /quickbooks/settings.";
        }

        // Gate 7: Field-length — PaymentRefNum per D-QBO-14-6.
        // PaymentRefNum is optional but when set must satisfy the 21-char
        // limit (matches Invoice/Bill DocNumber per Intuit docs). Returns
        // typed status code so operator can filter retries in the admin UI.
        $refNum = trim((string) ($ff['reference_number'] ?? ''));
        if ($refNum !== '' && strlen($refNum) > QboFieldLimits::PAYMENT_REF_NUM_MAX) {
            $len = strlen($refNum);
            $max = QboFieldLimits::PAYMENT_REF_NUM_MAX;
            return [
                'reason'      => "PaymentRefNum '{$refNum}' exceeds QBO Payment.PaymentRefNum limit of {$max} characters (actual: {$len}). Shorten the payment reference_number.",
                'status_code' => 'failed_preflight_field_too_long',
            ];
        }

        // Gate 8: Currency-mismatch (D-QBO-14-7, mirrors D-QBO-BILL-GOTCHAS-1
        // for bills). When multi-currency is enabled and FF payment currency
        // differs from the mapped QBO customer's CurrencyRef, the push would
        // create a corrupted AR entry — catch pre-flight.
        //
        // SKIP when multi-currency disabled: PaymentPusher omits CurrencyRef
        // entirely in single-currency mode (D-QBO-FIXPACK-12).
        //
        // FALLTHROUGH: any QBO API transient error swallowed — pre-flight
        // should never block a push on a read-only connectivity issue.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            try {
                $client = new QuickBooksClient();
                $qboCustomerResp = $client->getEntity('customer', (string) $customerMap['qbo_customer_id']);
                $qboCustomer = $qboCustomerResp['Customer'] ?? null;

                if ($qboCustomer === null) {
                    return [
                        'reason'      => "QBO customer (id={$customerMap['qbo_customer_id']}) not found in sandbox/production; "
                                       . "the mapping may be stale. Re-pull customers via /quickbooks/customers to refresh.",
                        'status_code' => 'failed_preflight',
                    ];
                }

                $qboCurrency = strtoupper((string) ($qboCustomer['CurrencyRef']['value'] ?? ''));
                $ffCurrency  = strtoupper((string) ($ff['currency'] ?? ''));

                if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                    $qboName = (string) ($qboCustomer['DisplayName'] ?? '(unnamed)');
                    return [
                        'reason'      => "Currency mismatch: FF payment is {$ffCurrency} but QBO customer "
                                       . "(id={$customerMap['qbo_customer_id']}, name='{$qboName}') is {$qboCurrency}. "
                                       . "Either re-map FF customer {$ff['customer_id']} to a QBO customer with "
                                       . "matching currency, or change FF payment.currency to match. "
                                       . "(QBO forbids customer currency change after create; you may need a new QBO customer.)",
                        'status_code' => 'failed_preflight_currency_mismatch',
                    ];
                }
            } catch (\Throwable $e) {
                error_log("PaymentPusher gate 8 (currency-mismatch) probe failed for ff_payment={$ff['id']}: " . $e->getMessage());
            }
        }

        return null;
    }

    // ────────────────────────────────────────────────────────────────────
    // Payload builder (public-static for offline smoke testability)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Emit QBO Payment payload from FF payment row + payment_allocations.
     * Pure function — no DB writes; reads from FF for allocations + per-
     * allocation invoice mapping + customer mapping + UndepositedFunds
     * account mapping. Throws QuickBooksException on missing references
     * (caller catches + records as failed_preflight).
     *
     * Per D-QBO-14-3: each LinkedTxn carries TxnType='Invoice' (only
     * invoice allocations in v1).
     * Per D-QBO-14-4: DepositToAccountRef from acc_qbo_account_map where
     * critical_category='undeposited_funds'.
     * Per D-QBO-FIXPACK-12: CurrencyRef emission gated on
     * multi_currency_enabled.
     * Per D-QBO-14-6: PaymentRefNum from payment.reference_number.
     *
     * @param  array<string, mixed> $ff FF payments row (must include
     *         id, customer_id, amount, currency, payment_date,
     *         reference_number, exchange_rate_to_cad)
     * @return array<string, mixed>      QBO Payment payload
     * @throws QuickBooksException       on missing customer/invoice/UF mapping
     */
    public static function buildQboPayload(array $ff): array
    {
        // 1. Customer mapping (defensive — caller should have validated).
        $customerMap = db_row(
            "SELECT qbo_customer_id FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
            [(int) $ff['customer_id']]
        );
        if ($customerMap === null || empty($customerMap['qbo_customer_id'])) {
            throw new QuickBooksException(
                "Cannot build QBO Payment payload: FF customer {$ff['customer_id']} has no QBO mapping."
            );
        }

        // 2. UndepositedFunds account resolution per D-QBO-14-4.
        $ufRow = db_row(
            "SELECT qbo_account_id
               FROM acc_qbo_account_map
              WHERE critical_category = 'undeposited_funds'
                AND mapping_status = 'mapped'
                AND qbo_account_id IS NOT NULL
              LIMIT 1"
        );
        if ($ufRow === null || empty($ufRow['qbo_account_id'])) {
            throw new QuickBooksException(
                "Cannot build QBO Payment payload: no FF account mapped with critical_category='undeposited_funds'. "
                . "Operator must tag + map an UF account via /quickbooks/accounts before payment push works (D-QBO-14-4)."
            );
        }

        $payload = [
            'CustomerRef' => ['value' => (string) $customerMap['qbo_customer_id']],
            'TotalAmt'    => (float) $ff['amount'],
            'TxnDate'     => (string) $ff['payment_date'],
            'DepositToAccountRef' => ['value' => (string) $ufRow['qbo_account_id']],
        ];

        // 3. PaymentRefNum per D-QBO-14-6. Optional — only emit when set.
        $refNum = trim((string) ($ff['reference_number'] ?? ''));
        if ($refNum !== '') {
            $payload['PaymentRefNum'] = $refNum;
        }

        // 4. CurrencyRef emission gated on multi-currency setting per
        //    D-QBO-FIXPACK-12. Reads payment.currency (authoritative push value).
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';
            }
            $payload['CurrencyRef'] = ['value' => $currency];
            // ExchangeRate emit policy per D-QBO-FIXPACK-1/-2: CAD → '1.0';
            // non-CAD → payment.exchange_rate_to_cad (throw on missing).
            if ($currency === 'CAD') {
                $payload['ExchangeRate'] = '1.0';
            } else {
                $rate = (string) ($ff['exchange_rate_to_cad'] ?? '');
                if ($rate === '' || bccomp($rate, '0', 6) <= 0) {
                    throw new QuickBooksException(
                        "Cannot build QBO Payment payload: non-CAD payment #{$ff['id']} has missing/zero exchange_rate_to_cad. Set the FX rate before push."
                    );
                }
                $payload['ExchangeRate'] = $rate;
            }
        }

        // 5. Build Lines from payment_allocations. Each allocation emits
        //    as Line with LinkedTxn{TxnId, TxnType='Invoice'} per
        //    D-QBO-14-3. Unlike Bill/Invoice lines, QBO Payment lines do
        //    NOT carry DetailType — the LinkedTxn array is the line shape.
        $allocations = db_select(
            "SELECT id, invoice_id, amount
               FROM payment_allocations
              WHERE payment_id = ?
              ORDER BY id",
            [(int) $ff['id']]
        );
        if (empty($allocations)) {
            throw new QuickBooksException(
                "Cannot build QBO Payment payload: payment #{$ff['id']} has no invoice allocations (D-QBO-14-3 v1 scope)."
            );
        }

        $payloadLines = [];
        foreach ($allocations as $alloc) {
            $invMap = db_row(
                "SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
                [(int) $alloc['invoice_id']]
            );
            if ($invMap === null || empty($invMap['qbo_invoice_id'])) {
                throw new QuickBooksException(
                    "Cannot build QBO Payment payload: allocation #{$alloc['id']} targets FF invoice #{$alloc['invoice_id']} which has no QBO mapping."
                );
            }
            $payloadLines[] = [
                'Amount'    => (float) $alloc['amount'],
                'LinkedTxn' => [
                    [
                        'TxnId'   => (string) $invMap['qbo_invoice_id'],
                        'TxnType' => 'Invoice',
                    ],
                ],
            ];
        }
        $payload['Line'] = $payloadLines;

        // 6. PrivateNote — JSON audit trail for QBO-side review per
        //    D-QBO-11-6 pattern (mirror for payments).
        $payload['PrivateNote'] = self::buildPrivateNoteJson($ff);

        return $payload;
    }

    /**
     * Build PrivateNote JSON audit trail. Operator/accountant can drill
     * down from QBO into FF state via this audit blob.
     */
    public static function buildPrivateNoteJson(array $ff): string
    {
        $audit = [
            'ff_payment_id'     => (int) $ff['id'],
            'ff_payment_number' => (string) $ff['payment_number'],
            'ff_payment_method' => (string) ($ff['payment_method'] ?? ''),
            'ff_origin'         => (string) ($ff['origin'] ?? 'ff_native'),
            'pushed_at'         => date('c'),
        ];
        return json_encode($audit, JSON_UNESCAPED_SLASHES);
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers (mirror BillPusher / InvoicePusher)
    //
    // All helpers FK-guard via early-return when the FF payment no longer
    // exists. Smokes use sentinel IDs (999990-999999) for ghost rows.
    // ────────────────────────────────────────────────────────────────────

    /**
     * Record a skip event in BOTH the map row AND a non-HTTP sync_log
     * row. Mirror of BillPusher::recordSkipped per D-PUSHER-SKIP-RECORD-
     * INVOICE-1 (carry-over).
     */
    private static function recordSkipped(int $ffPaymentId, array $ff, string $statusCode, string $operation): void
    {
        if (!self::ffPaymentExists($ffPaymentId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $payNum = (string) ($ff['payment_number'] ?? '');
        $errorMsg = "skipped: {$statusCode}" . ($payNum !== '' ? " (payment {$payNum})" : '');

        self::upsertMappingRow($ffPaymentId, [
            'push_status'    => $statusCode,
            'push_error'     => $errorMsg,
            'last_synced_at' => $now,
        ]);
        self::writeNonHttpSyncLog($ffPaymentId, $operation, $statusCode, $errorMsg);
    }

    /**
     * Record a successful push: write/update the map row with QBO
     * identifiers + snapshot fields. Mirror of BillPusher pattern.
     */
    private static function recordSuccessfulPush(int $ffPaymentId, array $ff, array $qboPayment): void
    {
        if (!self::ffPaymentExists($ffPaymentId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        // Find the first LinkedTxn from the response (the QBO invoice this
        // payment allocates to). Used for snapshot field qbo_linked_invoice_id.
        $linkedInvoiceId = null;
        if (isset($qboPayment['Line'][0]['LinkedTxn'][0]['TxnId'])) {
            $linkedInvoiceId = (string) $qboPayment['Line'][0]['LinkedTxn'][0]['TxnId'];
        }

        self::upsertMappingRow($ffPaymentId, [
            'qbo_payment_id'        => (string) $qboPayment['Id'],
            'qbo_sync_token'        => (string) ($qboPayment['SyncToken'] ?? '0'),
            'qbo_total_amt'         => isset($qboPayment['TotalAmt']) ? (string) $qboPayment['TotalAmt'] : null,
            'qbo_currency'          => isset($qboPayment['CurrencyRef']['value']) ? (string) $qboPayment['CurrencyRef']['value'] : null,
            'qbo_txn_date'          => isset($qboPayment['TxnDate']) ? (string) $qboPayment['TxnDate'] : null,
            'qbo_linked_invoice_id' => $linkedInvoiceId,
            'origin'                => 'ff_native',
            'realm_id'              => (string) settings_get('quickbooks.realm_id', null),
            'push_status'           => 'pushed',
            'push_error'            => null,
            'pushed_at'             => $now,
            'last_synced_at'        => $now,
        ]);
    }

    /**
     * Record a push failure (HTTP error from QBO).
     */
    private static function recordPushFailure(int $ffPaymentId, string $errorMessage): void
    {
        if (!self::ffPaymentExists($ffPaymentId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffPaymentId, [
            'push_status'    => 'failed',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    /**
     * Record a preflight failure (generic).
     */
    private static function recordFailedPreflight(int $ffPaymentId, string $errorMessage): void
    {
        if (!self::ffPaymentExists($ffPaymentId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffPaymentId, [
            'push_status'    => 'failed_preflight',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    /**
     * Record a typed pre-flight failure. For payment-push, the map row's
     * push_status ENUM does NOT yet include failed_preflight_currency_
     * mismatch or failed_preflight_field_too_long sub-states (S-QBO-13
     * shipped the canonical 8-state ENUM without these typed sub-states
     * because S-QBO-13 was pull-only). Until a follow-up migration adds
     * them, we fall back to 'failed_preflight' for persistence — the
     * typed status code is still returned to the caller for routing,
     * just not persisted at finer granularity.
     */
    private static function recordTypedPreflight(int $ffPaymentId, string $statusCode, string $errorMessage): void
    {
        if (!self::ffPaymentExists($ffPaymentId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffPaymentId, [
            'push_status'    => 'failed_preflight',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────────

    private static function ffPaymentExists(int $ffPaymentId): bool
    {
        $exists = db_row("SELECT id FROM payments WHERE id = ?", [$ffPaymentId]);
        return $exists !== null;
    }

    /**
     * INSERT-or-UPDATE the mapping row. Uses uq_ff_payment UNIQUE index.
     * Ensures origin='ff_native' is set on insert (push-originated rows).
     */
    private static function upsertMappingRow(int $ffPaymentId, array $fields): void
    {
        $existing = db_row("SELECT id, origin FROM acc_qbo_payment_map WHERE ff_payment_id = ?", [$ffPaymentId]);
        if ($existing === null) {
            // First-write defaults: origin='ff_native' (this Pusher only
            // handles FF-originated push; webhook rows carry origin=
            // 'qbo_payments_webhook' and are written by S-QBO-13 handler).
            db_insert('acc_qbo_payment_map', array_merge(
                ['ff_payment_id' => $ffPaymentId, 'origin' => 'ff_native'],
                $fields
            ));
        } else {
            db_update('acc_qbo_payment_map', $fields, 'ff_payment_id = ?', [$ffPaymentId]);
        }
    }

    /**
     * Write a non-HTTP sync_log row for skip events. http_method='SKIP'
     * sentinel + response_status=NULL distinguishes from real HTTP traffic.
     */
    private static function writeNonHttpSyncLog(int $ffPaymentId, string $operation, string $statusCode, string $errorMessage): void
    {
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'payment',
                'entity_id'       => $ffPaymentId,
                'operation'       => $operation,
                'http_method'     => 'SKIP',
                'endpoint'        => '',
                'response_status' => null,
                'error_code'      => substr($statusCode, 0, 50),
                'error_message'   => substr($errorMessage, 0, 500),
                'queue_id'        => QuickBooksClient::workerQueueId(),
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log("PaymentPusher: writeNonHttpSyncLog failed for ff_payment_id={$ffPaymentId}: " . $e->getMessage());
        }
    }
}
