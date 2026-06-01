<?php
declare(strict_types=1);

/**
 * lib/QboPushers/RefundReceiptPusher.php
 *
 * FF → QBO cash refund-receipt push (S-QBO-17, Phase QBO-7 / 2 of 2 — CLOSES
 * Phase QBO-7). The customer-side cash-refund counterpart to S-QBO-16 credit
 * memos. Spec §8.7.
 *
 * SCOPE (D-QBO-17-2): CASH refunds only. Credit-method refunds already create
 * a credit_notes row at lease close (S-MILEAGE-3 D-C) and push via the
 * CreditMemo path (S-QBO-16) + apply→LinkedTxn (S-QBO-CREDIT-MEMO-APPLY) — they
 * are NOT re-pushed as RefundReceipts.
 *
 * KEYING (D-QBO-17-5): a precharge refund has NO dedicated row — it lives on
 * `leases` (precharge_refund_method='cash', precharge_balance = amount,
 * precharge_refund_settled_at = disbursement timestamp). One cash refund per
 * lease → keyed on lease_id; naturally idempotent.
 *
 * Method-per-operation contract (D-QBO-3-2 + D-PUSHER-CONTRACT):
 *   create → pushCreate(int $ffLeaseId): array
 *   update → unsupported (a refund is immutable once disbursed)
 *   void   → unsupported in v1 (a reversed refund is a fresh forward event;
 *            tracked as a future follow-up alongside F27 un-apply)
 *
 * pushImpl pipeline (create-only; mirrors CreditMemoPusher):
 *   1. Sync mode gate (quickbooks.sync_mode.refund_receipt; 'sync' default).
 *   2. Load FF leases row (404 → ff_not_found).
 *   3. Eligibility: status='completed' + precharge_refund_method='cash' +
 *      precharge_refund_settled_at non-null (else skipped_not_eligible — the
 *      canonical trigger is mark_refund_settled.php, but force-resync could
 *      reach an un-settled lease).
 *   4. Idempotency (existing qbo_refund_receipt_id → already_mapped).
 *   5. Pre-flight gates (runPreflight — 6 sub-checks; typed statuses):
 *        customer mapped, item (mileage_credit) mapped, tax override set,
 *        deposit account set, payment method set, DocNumber length.
 *   6. Build payload (zero-tax RefundReceipt; §8.7).
 *   7. HTTP createEntity('refundreceipt', payload).
 *   8. Persist mapping.
 *
 * TaxCodeRef=NON (D-QBO-17-3) is a DOCUMENTED ASSUMPTION pending accountant
 * confirmation of refund tax treatment → live-verify follow-up F28 (F16/F19
 * pattern). Safe because sync_enabled='0' until S-QBO-30 cutover.
 *
 * @session  S-QBO-17
 * @closes   Phase QBO-7 (2/2)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.7 (Refund Receipt), §9 (tax-override)
 * @decision D-QBO-17-1/-2/-3/-4/-5/-6
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class RefundReceiptPusher
{
    /** Canonical §6.8 5-key return shape spread onto every return path. */
    private const RESULT_BASE = [
        'success'    => false,
        'status'     => 'unknown',
        'outcome'    => 'failed',
        'qbo_id'     => null,
        'sync_token' => null,
    ];

    /**
     * The FF item_type the refund line maps to (D-QBO-17-3). A mileage
     * precharge refund returns unused prepaid mileage → mileage_credit, the
     * same item credit memos use for mileage_overpayment.
     */
    public const REFUND_ITEM_TYPE = 'mileage_credit';

    /**
     * Push a cash refund as a QBO RefundReceipt. Idempotent — if the lease is
     * already mapped (qbo_refund_receipt_id set), returns 'already_mapped'.
     *
     * @param  int        $ffLeaseId       leases.id
     * @param  array|null $payloadSnapshot unused; signature matches D-PUSHER-CONTRACT
     */
    public static function pushCreate(int $ffLeaseId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($ffLeaseId, 'create');
    }

    private static function pushImpl(int $ffLeaseId, string $operation): array
    {
        // 1. Sync mode gate.
        $mode = (string) settings_get('quickbooks.sync_mode.refund_receipt', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $leaseForSkip = db_row("SELECT * FROM leases WHERE id = ?", [$ffLeaseId]) ?? ['id' => $ffLeaseId];
            self::recordSkipped($ffLeaseId, $leaseForSkip, 'skipped_by_mode', $operation);
            return ['success' => true, 'status' => 'skipped_by_mode', 'outcome' => 'skipped', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load FF lease.
        $lease = db_row("SELECT * FROM leases WHERE id = ?", [$ffLeaseId]);
        if ($lease === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF lease {$ffLeaseId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Eligibility — cash refund that has actually settled.
        if (($lease['deleted_at'] ?? null) !== null) {
            self::recordSkipped($ffLeaseId, $lease, 'skipped_not_eligible', $operation);
            return ['success' => true, 'status' => 'skipped_not_eligible', 'outcome' => 'skipped',
                'reason' => 'lease soft-deleted'] + self::RESULT_BASE;
        }
        if (($lease['precharge_refund_method'] ?? null) !== 'cash'
            || ($lease['precharge_refund_settled_at'] ?? null) === null) {
            self::recordSkipped($ffLeaseId, $lease, 'skipped_not_eligible', $operation);
            return ['success' => true, 'status' => 'skipped_not_eligible', 'outcome' => 'skipped',
                'reason' => "refund_method=" . ($lease['precharge_refund_method'] ?? 'none')
                    . " settled_at=" . ($lease['precharge_refund_settled_at'] ?? 'null')] + self::RESULT_BASE;
        }

        // 4. Idempotency on CREATE.
        $mapping = db_row(
            "SELECT id, qbo_refund_receipt_id FROM acc_qbo_refund_receipt_map WHERE ff_lease_id = ?",
            [$ffLeaseId]
        );
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_refund_receipt_id'])) {
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',
                'qbo_id'  => (string) $mapping['qbo_refund_receipt_id'],
            ] + self::RESULT_BASE;
        }

        // 5. Pre-flight gates.
        $gate = self::runPreflight($ffLeaseId, $lease);
        if (!$gate['ok']) {
            $typedStatus = (string) ($gate['status_code'] ?? 'failed_preflight');
            self::recordFailedPreflight($ffLeaseId, $lease, (string) $gate['reason'], $typedStatus);
            return [
                'success' => false,
                'status'  => $typedStatus,
                'outcome' => 'failed',
                'error'   => $gate['reason'],
            ] + self::RESULT_BASE;
        }

        // 6. Build payload (refs already verified by runPreflight).
        try {
            $payload = self::buildQboPayload(
                $lease,
                (string) $gate['qbo_customer_id'],
                (string) $gate['qbo_item_id'],
                (string) $gate['qbo_deposit_account_id'],
                (string) $gate['qbo_payment_method_id'],
                (string) $gate['qbo_tax_code_id']
            );
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffLeaseId, $lease, "Payload build failed: " . $e->getMessage(), 0);
            return [
                'success' => false,
                'status'  => 'payload_build_failed',
                'outcome' => 'failed',
                'error'   => "Payload build failed: " . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 7. HTTP create.
        $client = new QuickBooksClient();
        try {
            $response = $client->createEntity('refundreceipt', $payload);
        } catch (QuickBooksException $e) {
            $httpCode = method_exists($e, 'getHttpStatus') ? (int) $e->getHttpStatus() : 0;
            self::recordPushFailure($ffLeaseId, $lease, $e->getMessage(), $httpCode);
            return [
                'success'   => false,
                'status'    => 'qbo_error',
                'outcome'   => 'failed',
                'error'     => $e->getMessage(),
                'http_code' => $httpCode,
            ] + self::RESULT_BASE;
        }

        $qboRr = $response['RefundReceipt'] ?? null;
        if (!is_array($qboRr) || empty($qboRr['Id'])) {
            self::recordPushFailure($ffLeaseId, $lease, 'QBO response missing RefundReceipt.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing RefundReceipt.Id',
            ] + self::RESULT_BASE;
        }

        // 8. Persist mapping.
        self::recordSuccessfulPush($ffLeaseId, $lease, $qboRr, $gate);

        return [
            'success'    => true,
            'status'     => 'created',
            'outcome'    => 'created',
            'qbo_id'     => (string) $qboRr['Id'],
            'sync_token' => (string) ($qboRr['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Synthesized QBO DocNumber for a refund (FF has no refund_number). Compact
     * lease-keyed form 'RFD-<lease_id>' is always well under QBO's 21-char max.
     */
    public static function docNumber(int $ffLeaseId): string
    {
        return 'RFD-' . $ffLeaseId;
    }

    /**
     * Pre-flight gates. Returns ['ok'=>bool, 'reason'=>?string, 'status_code'=>?string,
     * + resolved refs on success].
     *
     * Gates:
     *   1. Customer mapped (acc_qbo_customer_map → qbo_customer_id).
     *   2. Refund line item mapped (REFUND_ITEM_TYPE → acc_qbo_item_map).
     *   3. Tax override configured (settings.quickbooks.tax_override_code_id).
     *   4. Deposit account configured (settings.quickbooks.refund.deposit_account_id).
     *   5. Payment method configured (settings.quickbooks.refund.payment_method_id).
     *   6. DocNumber length (synthesized RFD-<id> ≤ 21 — defensive; never trips).
     */
    public static function runPreflight(int $ffLeaseId, array $lease): array
    {
        // Gate 1: customer mapping.
        $customerId = (int) ($lease['customer_id'] ?? 0);
        if ($customerId === 0) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "Lease {$ffLeaseId} has no customer_id — cannot build a RefundReceipt CustomerRef."];
        }
        $customerMap = db_row(
            "SELECT qbo_customer_id FROM acc_qbo_customer_map
              WHERE ff_customer_id = ? AND mapping_status = 'mapped'",
            [$customerId]
        );
        if (!$customerMap || empty($customerMap['qbo_customer_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "FF customer {$customerId} has no mapped QBO customer (acc_qbo_customer_map). Map it via /quickbooks/customers first."];
        }

        // Gate 2: refund line item mapping.
        $itemMap = db_row(
            "SELECT qbo_item_id FROM acc_qbo_item_map
              WHERE ff_item_type = ? AND mapping_status = 'mapped'
              ORDER BY id ASC LIMIT 1",
            [self::REFUND_ITEM_TYPE]
        );
        if (!$itemMap || empty($itemMap['qbo_item_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "Refund line item_type '" . self::REFUND_ITEM_TYPE . "' has no mapped QBO item (acc_qbo_item_map). Map it via /quickbooks/items first."];
        }

        // Gate 3: tax override.
        $taxCodeId = (string) settings_get('quickbooks.tax_override_code_id', '');
        if ($taxCodeId === '') {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => 'Tax override code not configured (settings.quickbooks.tax_override_code_id empty). Run /quickbooks/tax_codes → Pull first (D-QBO-9-2).'];
        }

        // Gate 4: deposit account (D-QBO-17-6).
        $depositAccountId = (string) settings_get('quickbooks.refund.deposit_account_id', '');
        if ($depositAccountId === '') {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => 'Refund deposit account not configured (settings.quickbooks.refund.deposit_account_id empty). Set the QBO bank/cash Account.Id the refund cash leaves from on /quickbooks/settings (D-QBO-17-6).'];
        }

        // Gate 5: payment method (D-QBO-17-6).
        $paymentMethodId = (string) settings_get('quickbooks.refund.payment_method_id', '');
        if ($paymentMethodId === '') {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => 'Refund payment method not configured (settings.quickbooks.refund.payment_method_id empty). Set the QBO PaymentMethod.Id (cheque) on /quickbooks/settings (D-QBO-17-6).'];
        }

        // Gate 6: DocNumber length (defensive — RFD-<id> is always short).
        $docNumber = self::docNumber($ffLeaseId);
        if (strlen($docNumber) > QboFieldLimits::INVOICE_DOC_NUMBER_MAX) {
            return ['ok' => false, 'status_code' => 'failed_preflight_field_too_long',
                'reason' => "Synthesized DocNumber '{$docNumber}' exceeds QBO max " . QboFieldLimits::INVOICE_DOC_NUMBER_MAX . "."];
        }

        return [
            'ok'                     => true,
            'reason'                 => null,
            'status_code'            => null,
            'qbo_customer_id'        => (string) $customerMap['qbo_customer_id'],
            'qbo_item_id'            => (string) $itemMap['qbo_item_id'],
            'qbo_tax_code_id'        => $taxCodeId,
            'qbo_deposit_account_id' => $depositAccountId,
            'qbo_payment_method_id'  => $paymentMethodId,
        ];
    }

    /**
     * Build the QBO RefundReceipt payload (spec §8.7). Public-static so the
     * offline smoke exercises it without cURL.
     *
     * TotalAmt = precharge_balance (the refunded amount, preserved post-refund
     * per S-MILEAGE-3 D182). Single SalesItemLineDetail line. Tax-override per
     * D-QBO-CORE-6: TaxCodeRef = override 'NON' code; TxnTaxDetail.TotalTax=0
     * (D-QBO-17-3 assumption pending F28 live-verify). PaymentMethodRef +
     * DepositToAccountRef from settings (D-QBO-17-6).
     *
     * @throws QuickBooksException on missing refs (defense-in-depth; caller
     *         verified via runPreflight).
     */
    public static function buildQboPayload(
        array $lease,
        string $qboCustomerId,
        string $qboItemId,
        string $qboDepositAccountId,
        string $qboPaymentMethodId,
        string $qboTaxCodeId
    ): array {
        if ($qboCustomerId === '')      throw new QuickBooksException("RefundReceipt payload: missing QBO customer mapping.");
        if ($qboItemId === '')          throw new QuickBooksException("RefundReceipt payload: missing QBO item mapping for '" . self::REFUND_ITEM_TYPE . "'.");
        if ($qboDepositAccountId === '') throw new QuickBooksException("RefundReceipt payload: missing deposit account (settings.quickbooks.refund.deposit_account_id).");
        if ($qboPaymentMethodId === '') throw new QuickBooksException("RefundReceipt payload: missing payment method (settings.quickbooks.refund.payment_method_id).");
        if ($qboTaxCodeId === '')       throw new QuickBooksException("RefundReceipt payload: missing tax override code (settings.quickbooks.tax_override_code_id).");

        $amount = bcadd((string) ($lease['precharge_balance'] ?? '0'), '0', 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new QuickBooksException("RefundReceipt payload: precharge_balance must be positive, got '{$amount}'.");
        }

        $ffLeaseId = (int) ($lease['id'] ?? 0);
        $contract  = (string) ($lease['contract_number'] ?? '');
        $desc      = 'Mileage prepayment refund on close' . ($contract !== '' ? " ({$contract})" : '');

        // TxnDate from the settle timestamp (when money actually moved).
        $txnDate = substr((string) ($lease['precharge_refund_settled_at'] ?? date('Y-m-d')), 0, 10);

        $payload = [
            'CustomerRef'         => ['value' => $qboCustomerId],
            'TxnDate'             => $txnDate,
            'DocNumber'           => self::docNumber($ffLeaseId),
            'PaymentMethodRef'    => ['value' => $qboPaymentMethodId],
            'DepositToAccountRef' => ['value' => $qboDepositAccountId],
            'Line'                => [
                [
                    'Description'         => $desc,
                    'Amount'              => (float) $amount,
                    'DetailType'          => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => [
                        'ItemRef'    => ['value' => $qboItemId],
                        'Qty'        => 1,
                        'UnitPrice'  => (float) $amount,
                        'TaxCodeRef' => ['value' => $qboTaxCodeId],
                    ],
                ],
            ],
            // Tax-override: refund carries no tax → TotalTax=0 (D-QBO-CORE-6 /
            // D-QBO-17-3 assumption → F28 live-verify).
            'TxnTaxDetail' => [
                'TxnTaxCodeRef' => ['value' => $qboTaxCodeId],
                'TotalTax'      => '0.00',
            ],
            'PrivateNote' => self::buildPrivateNoteJson($lease),
        ];

        // CurrencyRef + ExchangeRate gated on multi_currency (D-QBO-FIXPACK-12).
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($lease['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';
            }
            $payload['CurrencyRef'] = ['value' => $currency];
            $payload['ExchangeRate'] = '1.0';
        }

        return $payload;
    }

    /** PrivateNote JSON for QBO-side audit drill-down. */
    public static function buildPrivateNoteJson(array $lease): string
    {
        $note = [
            'ff_lease_id'         => (int) ($lease['id'] ?? 0),
            'ff_contract_number'  => (string) ($lease['contract_number'] ?? ''),
            'refund_method'       => 'cash',
            'refund_amount'       => (string) ($lease['precharge_balance'] ?? '0.00'),
            'settled_at'          => (string) ($lease['precharge_refund_settled_at'] ?? ''),
            'pushed_at'           => date('c'),
        ];
        return json_encode($note, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers (mirror CreditMemoPusher; FK-guard via early return).
    // ────────────────────────────────────────────────────────────────────

    private static function ffLeaseExists(int $id): bool
    {
        return db_row("SELECT id FROM leases WHERE id = ?", [$id]) !== null;
    }

    private static function recordSuccessfulPush(int $ffLeaseId, array $lease, array $qboRr, array $gate): void
    {
        if (!self::ffLeaseExists($ffLeaseId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $txnDate = substr((string) ($lease['precharge_refund_settled_at'] ?? date('Y-m-d')), 0, 10);
        self::upsertMappingRow($ffLeaseId, [
            'ff_customer_id_snapshot'     => (int) ($lease['customer_id'] ?? 0),
            'ff_contract_number_snapshot' => (string) ($lease['contract_number'] ?? ''),
            'qbo_refund_receipt_id'       => (string) $qboRr['Id'],
            'qbo_sync_token'              => (string) ($qboRr['SyncToken'] ?? '0'),
            'qbo_doc_number'              => isset($qboRr['DocNumber']) ? (string) $qboRr['DocNumber'] : self::docNumber($ffLeaseId),
            'qbo_total_amt'               => isset($qboRr['TotalAmt']) ? (string) $qboRr['TotalAmt'] : (string) ($lease['precharge_balance'] ?? '0.00'),
            'qbo_currency'                => isset($qboRr['CurrencyRef']['value']) ? (string) $qboRr['CurrencyRef']['value'] : (string) ($lease['currency'] ?? null),
            'qbo_exchange_rate'           => isset($qboRr['ExchangeRate']) ? (string) $qboRr['ExchangeRate'] : null,
            'qbo_txn_date'                => $txnDate,
            'qbo_deposit_account_id'      => (string) ($gate['qbo_deposit_account_id'] ?? ''),
            'qbo_payment_method_id'       => (string) ($gate['qbo_payment_method_id'] ?? ''),
            'ff_refund_amount_snapshot'   => (string) ($lease['precharge_balance'] ?? '0.00'),
            'push_status'                 => 'pushed',
            'push_error'                  => null,
            'pushed_at'                   => $now,
            'last_synced_at'              => $now,
        ]);
        self::writeSyncLog($ffLeaseId, 'create', 'pushed', "QBO RefundReceipt #{$qboRr['Id']} created");
    }

    private static function recordPushFailure(int $ffLeaseId, array $lease, string $error, int $httpCode): void
    {
        if (!self::ffLeaseExists($ffLeaseId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffLeaseId, [
            'ff_customer_id_snapshot'     => (int) ($lease['customer_id'] ?? 0),
            'ff_contract_number_snapshot' => (string) ($lease['contract_number'] ?? ''),
            'push_status'                 => 'failed',
            'push_error'                  => substr($error, 0, 2000),
            'last_synced_at'              => $now,
        ]);
        self::writeSyncLog($ffLeaseId, 'create', 'failed', $error);
    }

    private static function recordFailedPreflight(int $ffLeaseId, array $lease, string $reason, string $status): void
    {
        if (!self::ffLeaseExists($ffLeaseId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffLeaseId, [
            'ff_customer_id_snapshot'     => (int) ($lease['customer_id'] ?? 0),
            'ff_contract_number_snapshot' => (string) ($lease['contract_number'] ?? ''),
            'push_status'                 => $status,
            'push_error'                  => substr($reason, 0, 2000),
            'last_synced_at'              => $now,
        ]);
        self::writeSyncLog($ffLeaseId, 'create', $status, $reason);
    }

    private static function recordSkipped(int $ffLeaseId, array $lease, string $skippedStatus, string $operation): void
    {
        if (!self::ffLeaseExists($ffLeaseId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $contract = (string) ($lease['contract_number'] ?? '');
        $msg = "skipped: {$skippedStatus}" . ($contract !== '' ? " ({$contract})" : '');
        self::upsertMappingRow($ffLeaseId, [
            'ff_customer_id_snapshot'     => (int) ($lease['customer_id'] ?? 0),
            'ff_contract_number_snapshot' => $contract,
            'push_status'                 => $skippedStatus === 'skipped_not_eligible' ? 'failed_preflight' : $skippedStatus,
            'push_error'                  => $msg,
            'last_synced_at'              => $now,
        ]);
        self::writeSyncLog($ffLeaseId, $operation, $skippedStatus, $msg);
    }

    /** INSERT-or-UPDATE the map row keyed on ff_lease_id (uq_ff_lease). */
    private static function upsertMappingRow(int $ffLeaseId, array $fields): void
    {
        $existing = db_row("SELECT id FROM acc_qbo_refund_receipt_map WHERE ff_lease_id = ?", [$ffLeaseId]);
        if ($existing) {
            db_update('acc_qbo_refund_receipt_map', $fields, 'ff_lease_id = ?', [$ffLeaseId]);
        } else {
            db_insert('acc_qbo_refund_receipt_map', ['ff_lease_id' => $ffLeaseId] + $fields);
        }
    }

    /** Best-effort sync_log write (never throws). Mirrors CreditMemoPusher. */
    private static function writeSyncLog(int $ffLeaseId, string $operation, string $status, string $message): void
    {
        $isSuccess = ($status === 'pushed');
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'refund_receipt',
                'entity_id'       => $ffLeaseId,
                'operation'       => $operation,
                'http_method'     => $isSuccess ? 'POST' : 'SKIP',
                'endpoint'        => $isSuccess ? 'refundreceipt' : '',
                'response_status' => null,
                'error_code'      => $isSuccess ? null : substr($status, 0, 50),
                'error_message'   => substr($message, 0, 500),
                'queue_id'        => QuickBooksClient::workerQueueId(),
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log("[RefundReceiptPusher] writeSyncLog failed for lease {$ffLeaseId}: " . $e->getMessage());
        }
    }
}
