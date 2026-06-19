<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CreditApplicationPusher.php
 *
 * FF→QBO credit-APPLICATION push (S-QBO-CREDIT-MEMO-APPLY, closing F25 — the
 * last open QBO update-debt item, carved out of S-QBO-CREDIT-MEMO-UPDATE per
 * D-QBO-CREDIT-MEMO-UPDATE-1).
 *
 * QBO does NOT model "apply a credit to an invoice" as a CreditMemo update.
 * Instead, applying a credit is a ZERO-DOLLAR Payment entity carrying TWO
 * LinkedTxns on a single Line: the source CreditMemo + the target Invoice.
 * The line Amount equals the amount_applied; the header TotalAmt is 0.
 *
 * Method-per-operation contract (D-QBO-3-2 + D-PUSHER-CONTRACT):
 *   create → pushCreate(int $ffApplicationId): array
 *   update → unsupported (applications are immutable in QBO; an apply is
 *            re-created by deleting + re-posting the Payment — out of v1
 *            scope per D-QBO-CREDIT-MEMO-APPLY-4)
 *   void   → unsupported in v1 (un-apply requires DELETE on QBO Payment +
 *            reversal of credit_note_applications rows; no FF endpoint
 *            un-applies today — credit_notes/void.php voids the parent
 *            credit, not individual applications). Tracked as new
 *            OPERATOR_FOLLOWUPS item.
 *
 * pushImpl pipeline (create-only, mirrors CreditMemoPusher structure):
 *   1. Sync mode gate (quickbooks.sync_mode.credit_application; 'sync' default).
 *      Separate from sync_mode.credit_memo so an operator can pause apply
 *      propagation without disabling credit-memo creation.
 *   2. Load FF credit_note_applications row (404 → ff_not_found).
 *   3. Idempotency (existing map row with qbo_payment_id → already_mapped).
 *   4. Pre-flight gates (runPreflight — 4 sub-checks; typed statuses):
 *        4a. Parent credit memo MAPPED (acc_qbo_credit_memo_map →
 *            qbo_credit_memo_id non-null). Apply cannot pre-date the
 *            create — the QBO CreditMemo MUST exist first.
 *        4b. Target invoice MAPPED (acc_qbo_invoice_map → qbo_invoice_id).
 *        4c. Customer MAPPED (acc_qbo_customer_map → qbo_customer_id;
 *            both parent credit and invoice belong to this customer per
 *            apply.php pre-flight).
 *        4d. Auto-apply pre-req documented as operator follow-up
 *            (D-QBO-CREDIT-MEMO-APPLY-3): NOT runtime-probed; matches
 *            tax_override / sync_enabled pre-flight-as-doc pattern.
 *   5. Build zero-dollar Payment payload (TotalAmt=0, CustomerRef,
 *      single Line with Amount=amount_applied + 2 LinkedTxns).
 *   6. HTTP createEntity('payment', payload).
 *   7. Persist mapping with success state.
 *
 * @session  S-QBO-CREDIT-MEMO-APPLY
 * @closes   F25
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.6 (credit application → LinkedTxn)
 * @decision D-QBO-CREDIT-MEMO-APPLY-1 (new entity_type credit_application,
 *               keyed on credit_note_applications.id; rejects 'reuse credit_memo+op=apply'
 *               because 1 credit → N applications),
 *           D-QBO-CREDIT-MEMO-APPLY-2 (new acc_qbo_credit_application_map),
 *           D-QBO-CREDIT-MEMO-APPLY-3 (auto-apply pre-req documented, not probed),
 *           D-QBO-CREDIT-MEMO-APPLY-4 (v1 forward-apply only; un-apply→follow-up),
 *           D-QBO-CREDIT-MEMO-APPLY-5 (UI shares credit_memos.php via CLASS 12 allowlist)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class CreditApplicationPusher
{
    /**
     * Canonical §6.8 5-key return shape spread onto every return path.
     */
    private const RESULT_BASE = [
        'success'    => false,
        'status'     => 'unknown',
        'outcome'    => 'failed',
        'qbo_id'     => null,
        'sync_token' => null,
    ];

    /**
     * Push a new FF credit application as a zero-dollar QBO Payment carrying
     * 2 LinkedTxns (CreditMemo + Invoice). Idempotent — if the application
     * is already mapped (qbo_payment_id set), returns 'already_mapped' with
     * no second POST.
     *
     * @param  int        $ffApplicationId  credit_note_applications.id
     * @param  array|null $payloadSnapshot  unused; signature matches D-PUSHER-CONTRACT
     */
    public static function pushCreate(int $ffApplicationId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($ffApplicationId, 'create');
    }

    /**
     * Void the QBO apply-Payment when an FF credit application is un-applied
     * (F27 / api/v1/credit_notes/unapply.php). Deletes the zero-dollar Payment
     * that carried the CreditMemo+Invoice LinkedTxns — QBO then restores the
     * credit + invoice balances on its side. Mirrors the pushVoid trio
     * (D-QBO-PUSHVOID-TRIO-1): separate pipeline, idempotent on
     * push_status='voided', skipped_unmapped_void when never pushed.
     *
     * @return array §6.8 5-key shape; status='voided' on success,
     *               'already_voided' on replay, 'skipped_unmapped_void' for
     *               applications that never pushed to QBO.
     */
    public static function pushVoid(int $ffApplicationId, ?array $payloadSnapshot = null): array
    {
        // 1. Sync mode gate.
        $mode = (string) settings_get('quickbooks.sync_mode.credit_application', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            return ['success' => true, 'status' => 'skipped_by_mode', 'outcome' => 'skipped', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Mapping lookup.
        $mapping = db_row(
            "SELECT id, qbo_payment_id, qbo_sync_token, push_status
               FROM acc_qbo_credit_application_map
              WHERE ff_credit_application_id = ?",
            [$ffApplicationId]
        );

        // 2a. Never pushed → nothing in QBO to void.
        if ($mapping === null || empty($mapping['qbo_payment_id'])) {
            return ['success' => true, 'status' => 'skipped_unmapped_void', 'outcome' => 'skipped'] + self::RESULT_BASE;
        }

        // 2b. Already voided → idempotent no-op.
        if (($mapping['push_status'] ?? '') === 'voided') {
            return [
                'success'    => true,
                'status'     => 'already_voided',
                'outcome'    => 'voided',
                'qbo_id'     => (string) $mapping['qbo_payment_id'],
                'sync_token' => (string) ($mapping['qbo_sync_token'] ?? '0'),
            ] + self::RESULT_BASE;
        }

        // 3. HTTP void of the apply Payment.
        $client = new QuickBooksClient();
        try {
            $response = $client->voidEntity(
                'payment',
                (string) $mapping['qbo_payment_id'],
                (string) ($mapping['qbo_sync_token'] ?? '0')
            );
        } catch (QuickBooksException $e) {
            // readonly PROPERTY, not a method — method_exists() is always false.
            $httpCode = $e->httpStatus ?? 0;
            self::recordPushFailure($ffApplicationId, ['credit_note_id' => 0, 'invoice_id' => 0], 'Void failed: ' . $e->getMessage(), $httpCode);
            return ['success' => false, 'status' => 'qbo_error', 'outcome' => 'failed', 'error' => 'Void failed: ' . $e->getMessage()] + self::RESULT_BASE;
        }

        $qboPay = $response['Payment'] ?? null;
        if (!is_array($qboPay) || empty($qboPay['Id'])) {
            self::recordPushFailure($ffApplicationId, ['credit_note_id' => 0, 'invoice_id' => 0], 'QBO void response missing Payment.Id', 0);
            return ['success' => false, 'status' => 'qbo_malformed_response', 'outcome' => 'failed', 'error' => 'QBO void response missing Payment.Id'] + self::RESULT_BASE;
        }

        // 4. Persist voided state.
        self::upsertMappingRow($ffApplicationId, [
            'qbo_sync_token' => (string) ($qboPay['SyncToken'] ?? '0'),
            'push_status'    => 'voided',
            'push_error'     => null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
        self::writeSyncLog($ffApplicationId, 'void', 'voided', "QBO apply Payment #{$qboPay['Id']} voided (un-applied)");

        return [
            'success'    => true,
            'status'     => 'voided',
            'outcome'    => 'voided',
            'qbo_id'     => (string) $qboPay['Id'],
            'sync_token' => (string) ($qboPay['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Create-only pipeline. Mirrors CreditMemoPusher::pushImpl structure but
     * SLIMMER: no soft-delete gate (credit_note_applications has no
     * deleted_at column — applications are append-only), no voided gate
     * (the parent credit_notes.status='void' check is done by surfacing
     * 'failed_preflight_parent_unmapped' if the credit was never pushed),
     * no field-length gate (apply has no DocNumber), no currency probe
     * (apply.php D18 CURRENCY_MISMATCH gate already guarantees the parent
     * credit + invoice currencies match).
     */
    private static function pushImpl(int $ffApplicationId, string $operation): array
    {
        // 1. Sync mode gate. Per-call so a mid-queue mode flip takes effect.
        $mode = (string) settings_get('quickbooks.sync_mode.credit_application', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $appForSkip = db_row("SELECT * FROM credit_note_applications WHERE id = ?", [$ffApplicationId]) ?? ['id' => $ffApplicationId];
            self::recordSkipped($ffApplicationId, $appForSkip, 'skipped_by_mode', $operation);
            return ['success' => true, 'status' => 'skipped_by_mode', 'outcome' => 'skipped', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load FF credit_note_applications row.
        $app = db_row("SELECT * FROM credit_note_applications WHERE id = ?", [$ffApplicationId]);
        if ($app === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF credit application {$ffApplicationId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Idempotency on CREATE.
        $mapping = db_row(
            "SELECT id, qbo_payment_id, qbo_sync_token
               FROM acc_qbo_credit_application_map
              WHERE ff_credit_application_id = ?",
            [$ffApplicationId]
        );
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_payment_id'])) {
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',
                'qbo_id'  => (string) $mapping['qbo_payment_id'],
            ] + self::RESULT_BASE;
        }

        // 4. Pre-flight gates.
        $gate = self::runPreflight($ffApplicationId, $app);
        if (!$gate['ok']) {
            $typedStatus = (string) ($gate['status_code'] ?? 'failed_preflight');
            self::recordFailedPreflight($ffApplicationId, $app, (string) $gate['reason'], $typedStatus);
            return [
                'success' => false,
                'status'  => $typedStatus,
                'outcome' => 'failed',
                'error'   => $gate['reason'],
            ] + self::RESULT_BASE;
        }

        // 5. Build payload (uses values already verified by runPreflight).
        try {
            $payload = self::buildQboPayload(
                $app,
                (string) $gate['qbo_customer_id'],
                (string) $gate['qbo_credit_memo_id'],
                (string) $gate['qbo_invoice_id']
            );
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffApplicationId, $app, "Payload build failed: " . $e->getMessage(), 0);
            return [
                'success' => false,
                'status'  => 'payload_build_failed',
                'outcome' => 'failed',
                'error'   => "Payload build failed: " . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 6. HTTP create. QBO apply-a-credit is a zero-dollar Payment create
        //    — there is no special endpoint.
        $client = new QuickBooksClient();
        try {
            $response = $client->createEntity('payment', $payload);
        } catch (QuickBooksException $e) {
            // readonly PROPERTY, not a method — method_exists() is always false.
            $httpCode = $e->httpStatus ?? 0;
            self::recordPushFailure($ffApplicationId, $app, $e->getMessage(), $httpCode);
            return [
                'success'   => false,
                'status'    => 'qbo_error',
                'outcome'   => 'failed',
                'error'     => $e->getMessage(),
                'http_code' => $httpCode,
            ] + self::RESULT_BASE;
        }

        $qboPay = $response['Payment'] ?? null;
        if (!is_array($qboPay) || empty($qboPay['Id'])) {
            self::recordPushFailure($ffApplicationId, $app, 'QBO response missing Payment.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing Payment.Id',
            ] + self::RESULT_BASE;
        }

        // 7. Persist mapping.
        self::recordSuccessfulPush(
            $ffApplicationId,
            $app,
            $qboPay,
            (string) $gate['qbo_credit_memo_id'],
            (string) $gate['qbo_invoice_id']
        );

        return [
            'success'    => true,
            'status'     => 'created',
            'outcome'    => 'created',
            'qbo_id'     => (string) $qboPay['Id'],
            'sync_token' => (string) ($qboPay['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Pre-flight gates. Returns ['ok'=>bool, 'reason'=>?string, 'status_code'=>?string,
     * 'qbo_customer_id'=>?string, 'qbo_credit_memo_id'=>?string, 'qbo_invoice_id'=>?string].
     *
     * On success the resolved QBO IDs are returned so pushImpl/buildQboPayload
     * don't re-query.
     *
     * Gates:
     *   1. Parent credit memo MAPPED — acc_qbo_credit_memo_map.qbo_credit_memo_id
     *      non-null. Apply cannot pre-date the parent's create push.
     *   2. Target invoice MAPPED — acc_qbo_invoice_map.qbo_invoice_id non-null.
     *   3. Customer MAPPED — acc_qbo_customer_map.qbo_customer_id non-null.
     *
     * Auto-apply company-setting risk (D-QBO-CREDIT-MEMO-APPLY-3) is NOT
     * runtime-probed; it is an operator pre-cutover follow-up item.
     */
    public static function runPreflight(int $ffApplicationId, array $app): array
    {
        $creditNoteId = (int) ($app['credit_note_id'] ?? 0);
        $invoiceId    = (int) ($app['invoice_id'] ?? 0);

        if ($creditNoteId === 0 || $invoiceId === 0) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "FF credit application {$ffApplicationId} has invalid credit_note_id or invoice_id."];
        }

        // Gate 1: parent credit memo mapped (must be pushed FIRST).
        $cmMap = db_row(
            "SELECT qbo_credit_memo_id FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id = ?",
            [$creditNoteId]
        );
        if ($cmMap === null || empty($cmMap['qbo_credit_memo_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "Parent credit note {$creditNoteId} has no QBO CreditMemo mapping (acc_qbo_credit_memo_map.qbo_credit_memo_id is NULL). The credit memo must be pushed before any of its applications can propagate."];
        }

        // Gate 2: target invoice mapped.
        $invMap = db_row(
            "SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
            [$invoiceId]
        );
        if ($invMap === null || empty($invMap['qbo_invoice_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "Target invoice {$invoiceId} has no QBO Invoice mapping (acc_qbo_invoice_map.qbo_invoice_id is NULL). Invoice must be pushed before credit can be applied to it."];
        }

        // Gate 3: customer mapped. Read customer_id off the credit_notes row
        //         (apply.php pre-flight guarantees credit + invoice share it).
        $cn = db_row("SELECT customer_id FROM credit_notes WHERE id = ?", [$creditNoteId]);
        if ($cn === null || empty($cn['customer_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "Cannot resolve customer for parent credit note {$creditNoteId}."];
        }
        $custMap = db_row(
            "SELECT qbo_customer_id FROM acc_qbo_customer_map
              WHERE ff_customer_id = ? AND mapping_status = 'mapped'",
            [(int) $cn['customer_id']]
        );
        if ($custMap === null || empty($custMap['qbo_customer_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "FF customer {$cn['customer_id']} has no mapped QBO customer (acc_qbo_customer_map)."];
        }

        return [
            'ok'                 => true,
            'reason'             => null,
            'status_code'        => null,
            'qbo_customer_id'    => (string) $custMap['qbo_customer_id'],
            'qbo_credit_memo_id' => (string) $cmMap['qbo_credit_memo_id'],
            'qbo_invoice_id'     => (string) $invMap['qbo_invoice_id'],
        ];
    }

    /**
     * Build the zero-dollar QBO Payment payload that carries the apply.
     * Public-static so the offline smoke exercises it without cURL.
     *
     * Shape (per QBO Payment API):
     *   TotalAmt: 0     (header is zero — the LINE Amount carries amount_applied)
     *   CustomerRef     (required)
     *   TxnDate         (applied_at)
     *   PrivateNote     (audit JSON)
     *   Line: [
     *     { Amount: <amount_applied>,
     *       LinkedTxn: [
     *         { TxnId: <qbo_credit_memo_id>, TxnType: 'CreditMemo' },
     *         { TxnId: <qbo_invoice_id>,     TxnType: 'Invoice'   } ] } ]
     *
     * CurrencyRef + ExchangeRate gated on multi_currency setting per
     * D-QBO-FIXPACK-12. Currency itself is read off the parent credit
     * note (apply.php D18 guarantees parent credit + invoice currencies
     * match).
     *
     * @throws QuickBooksException on missing mappings (caller already
     *         verified via runPreflight; this is a defense-in-depth guard).
     */
    public static function buildQboPayload(
        array $app,
        string $qboCustomerId,
        string $qboCreditMemoId,
        string $qboInvoiceId
    ): array {
        if ($qboCustomerId === '') {
            throw new QuickBooksException("CreditApplication payload: missing QBO customer mapping.");
        }
        if ($qboCreditMemoId === '') {
            throw new QuickBooksException("CreditApplication payload: missing QBO CreditMemo mapping for parent credit_note_id={$app['credit_note_id']}.");
        }
        if ($qboInvoiceId === '') {
            throw new QuickBooksException("CreditApplication payload: missing QBO Invoice mapping for invoice_id={$app['invoice_id']}.");
        }

        $amountApplied = bcadd((string) ($app['amount_applied'] ?? '0'), '0', 2);
        if (bccomp($amountApplied, '0', 2) <= 0) {
            throw new QuickBooksException("CreditApplication payload: amount_applied must be positive, got '{$amountApplied}'.");
        }

        $txnDate = substr((string) ($app['applied_at'] ?? date('Y-m-d')), 0, 10);

        $payload = [
            'CustomerRef' => ['value' => $qboCustomerId],
            'TotalAmt'    => 0.0,
            'TxnDate'     => $txnDate,
            'Line'        => [
                [
                    'Amount'    => (float) $amountApplied,
                    'LinkedTxn' => [
                        ['TxnId' => $qboCreditMemoId, 'TxnType' => 'CreditMemo'],
                        ['TxnId' => $qboInvoiceId,    'TxnType' => 'Invoice'],
                    ],
                ],
            ],
            'PrivateNote' => self::buildPrivateNoteJson($app),
        ];

        // Multi-currency: read the parent credit note's currency
        // (apply.php D18 guarantees parent credit + invoice match).
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $cn = db_row("SELECT currency FROM credit_notes WHERE id = ?", [(int) $app['credit_note_id']]);
            $currency = strtoupper((string) ($cn['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';
            }
            $payload['CurrencyRef'] = ['value' => $currency];
            // Apply rows have no FX column; CAD=1.0; non-CAD=1.0 best-effort
            // (FX revaluation of credit-application mirror rows deferred — see F15).
            $payload['ExchangeRate'] = '1.0';
        }

        return $payload;
    }

    /**
     * PrivateNote JSON for QBO-side audit drill-down (accountant-only).
     */
    public static function buildPrivateNoteJson(array $app): string
    {
        $note = [
            'ff_credit_application_id' => (int) ($app['id'] ?? 0),
            'ff_credit_note_id'        => (int) ($app['credit_note_id'] ?? 0),
            'ff_invoice_id'            => (int) ($app['invoice_id'] ?? 0),
            'amount_applied'           => (string) ($app['amount_applied'] ?? '0.00'),
            'pushed_at'                => date('c'),
        ];
        return json_encode($note, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers (mirror CreditMemoPusher)
    //
    // All helpers FK-guard via early-return when the FF application no
    // longer exists. Smokes use sentinel IDs (999990–999999) for ghost rows.
    // ────────────────────────────────────────────────────────────────────

    private static function ffApplicationExists(int $id): bool
    {
        return db_row("SELECT id FROM credit_note_applications WHERE id = ?", [$id]) !== null;
    }

    private static function recordSuccessfulPush(int $ffAppId, array $app, array $qboPay, string $qboCreditMemoId, string $qboInvoiceId): void
    {
        if (!self::ffApplicationExists($ffAppId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $txnDate = substr((string) ($app['applied_at'] ?? date('Y-m-d')), 0, 10);
        self::upsertMappingRow($ffAppId, [
            'ff_credit_note_id_snapshot' => (int) $app['credit_note_id'],
            'ff_invoice_id_snapshot'     => (int) $app['invoice_id'],
            'qbo_payment_id'             => (string) $qboPay['Id'],
            'qbo_sync_token'             => (string) ($qboPay['SyncToken'] ?? '0'),
            'qbo_credit_memo_id_ref'     => $qboCreditMemoId,
            'qbo_invoice_id_ref'         => $qboInvoiceId,
            'qbo_total_amt'              => isset($qboPay['TotalAmt']) ? (string) $qboPay['TotalAmt'] : '0.00',
            'qbo_currency'               => isset($qboPay['CurrencyRef']['value']) ? (string) $qboPay['CurrencyRef']['value'] : null,
            'qbo_exchange_rate'          => isset($qboPay['ExchangeRate']) ? (string) $qboPay['ExchangeRate'] : null,
            'qbo_txn_date'               => $txnDate,
            'amount_applied_snapshot'    => (string) ($app['amount_applied'] ?? '0.00'),
            'push_status'                => 'pushed',
            'push_error'                 => null,
            'pushed_at'                  => $now,
            'last_synced_at'             => $now,
        ]);
        self::writeSyncLog($ffAppId, 'create', 'pushed', "QBO Payment #{$qboPay['Id']} (apply) created");
    }

    private static function recordPushFailure(int $ffAppId, array $app, string $error, int $httpCode): void
    {
        if (!self::ffApplicationExists($ffAppId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffAppId, [
            'ff_credit_note_id_snapshot' => (int) ($app['credit_note_id'] ?? 0),
            'ff_invoice_id_snapshot'     => (int) ($app['invoice_id'] ?? 0),
            'push_status'                => 'failed',
            'push_error'                 => substr($error, 0, 2000),
            'last_synced_at'             => $now,
        ]);
        self::writeSyncLog($ffAppId, 'create', 'failed', $error);
    }

    private static function recordFailedPreflight(int $ffAppId, array $app, string $reason, string $status): void
    {
        if (!self::ffApplicationExists($ffAppId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffAppId, [
            'ff_credit_note_id_snapshot' => (int) ($app['credit_note_id'] ?? 0),
            'ff_invoice_id_snapshot'     => (int) ($app['invoice_id'] ?? 0),
            'push_status'                => $status,
            'push_error'                 => substr($reason, 0, 2000),
            'last_synced_at'             => $now,
        ]);
        self::writeSyncLog($ffAppId, 'create', $status, $reason);
    }

    private static function recordSkipped(int $ffAppId, array $app, string $skippedStatus, string $operation): void
    {
        if (!self::ffApplicationExists($ffAppId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $msg = "skipped: {$skippedStatus} (application {$ffAppId})";
        self::upsertMappingRow($ffAppId, [
            'ff_credit_note_id_snapshot' => (int) ($app['credit_note_id'] ?? 0),
            'ff_invoice_id_snapshot'     => (int) ($app['invoice_id'] ?? 0),
            'push_status'                => $skippedStatus,
            'push_error'                 => $msg,
            'last_synced_at'             => $now,
        ]);
        self::writeSyncLog($ffAppId, $operation, $skippedStatus, $msg);
    }

    /**
     * INSERT-or-UPDATE the acc_qbo_credit_application_map row keyed on
     * ff_credit_application_id (uq_ff_credit_application).
     */
    private static function upsertMappingRow(int $ffAppId, array $fields): void
    {
        $existing = db_row(
            "SELECT id FROM acc_qbo_credit_application_map WHERE ff_credit_application_id = ?",
            [$ffAppId]
        );
        if ($existing) {
            db_update('acc_qbo_credit_application_map', $fields, 'ff_credit_application_id = ?', [$ffAppId]);
        } else {
            db_insert('acc_qbo_credit_application_map', ['ff_credit_application_id' => $ffAppId] + $fields);
        }
    }

    /**
     * Best-effort sync_log write (never throws). Mirrors
     * CreditMemoPusher::writeSyncLog.
     */
    private static function writeSyncLog(int $ffAppId, string $operation, string $status, string $message): void
    {
        $isSuccess = in_array($status, ['pushed', 'voided'], true);
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'credit_application',
                'entity_id'       => $ffAppId,
                'operation'       => $operation,
                'http_method'     => $isSuccess ? 'POST' : 'SKIP',
                'endpoint'        => $isSuccess ? 'payment' : '',
                'response_status' => null,
                'error_code'      => $isSuccess ? null : substr($status, 0, 50),
                'error_message'   => substr($message, 0, 500),
                'queue_id'        => QuickBooksClient::workerQueueId(),
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log("[CreditApplicationPusher] writeSyncLog failed for application {$ffAppId}: " . $e->getMessage());
        }
    }
}
