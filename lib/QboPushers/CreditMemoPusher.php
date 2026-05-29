<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CreditMemoPusher.php
 *
 * FF → QBO credit memo push (S-QBO-16, Phase QBO-7 / 1 of 2). Sibling of
 * InvoicePusher (S-QBO-11). FF `credit_notes` rows push to QBO as the
 * native CreditMemo entity; QBO derives the credit JE from it (which is
 * why source_type='credit_note' is in JournalEntryPusher's
 * BRIDGE_DERIVED_SOURCE_TYPES — D-QBO-21-1 — so the FF two-step credit JE
 * never double-pushes as a raw JE).
 *
 * Method-per-operation contract (D-QBO-3-2 + D-PUSHER-CONTRACT):
 *   create → pushCreate(int $ffCreditNoteId): array
 *   update → pushUpdate(int): array   (STUB → S-QBO-16-UPDATE-FOLLOWUP / F20)
 *   void   → pushVoid(int): array     (STUB → S-QBO-16-UPDATE-FOLLOWUP / F20)
 *
 * pushImpl pipeline (create only in v1):
 *   1. Sync mode gate (quickbooks.sync_mode.credit_memo; 'sync' default)
 *   2. Load FF credit_notes row (404 → ff_not_found)
 *   3. Voided check (status='void' → skipped_voided)
 *   4. Soft-deleted check (deleted_at non-null → skipped_soft_deleted)
 *   5. Idempotency (existing qbo_credit_memo_id → already_mapped)
 *   6. Pre-flight gates (runPreflight — 5 sub-checks; typed statuses)
 *   7. Load customer + customer mapping + resolve item mapping
 *   8. Build QBO CreditMemo payload
 *   9. HTTP createEntity('creditmemo', payload)
 *  10. Persist mapping with success state
 *
 * K-22 catches (S-QBO-16 pre-flight):
 *   - credit_notes is HEADER-ONLY (no credit_note_lines table) → single
 *     QBO CreditMemo Line with SalesItemLineDetail (D-QBO-16-1).
 *   - credit_notes.source DB ENUM has 9 values; api/v1/credit_notes/
 *     create.php validates only 6 (3 are internal-flow-created). The
 *     SOURCE_TO_ITEM_TYPE map below covers all 9.
 *   - status flow is active/partially_used/fully_used/expired/void (NOT
 *     draft/issued). Push trigger = creation (status='active').
 *
 * @session  S-QBO-16
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.6 (Credit Memo), §9 (tax-override)
 * @decision D-QBO-16-1 (SOURCE_TO_ITEM_TYPE map → acc_qbo_item_map lookup
 *                       for the single line ItemRef),
 *           D-QBO-16-2 (pushCreate only; apply→LinkedTxn + void stubbed → F20),
 *           D-QBO-16-3 (acc_qbo_credit_memo_map mirrors invoice map shape),
 *           D-QBO-CORE-6 (tax-override: TotalTax=0 — credit notes carry no
 *                       tax; line TaxCodeRef = tax_override_code_id)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class CreditMemoPusher
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
     * credit_notes.source (9 DB ENUM values) → acc_qbo_item_map.ff_item_type
     * for the single CreditMemo line's ItemRef (D-QBO-16-1). All target
     * item_types are mappable via the S-QBO-10 item map. Spec §8.6 implies
     * this (shows a mileage_credit item for a mileage_overpayment credit).
     *
     * Covers ALL 9 DB ENUM values even though create.php only accepts 6 —
     * the other 3 (overpayment / precharge_refund /
     * base_rental_reconciliation_overflow) are created by internal flows
     * (InvoiceGenerator auto-create + base-rental reconciliation) and also
     * need to push.
     *
     * @var array<string,string>
     */
    public const SOURCE_TO_ITEM_TYPE = [
        'mileage_overpayment'                => 'mileage_credit',
        'precharge_refund'                   => 'mileage_drawdown_credit',
        'base_rental_reconciliation_overflow'=> 'base_rental_reconciliation_credit',
        'invoice_adjustment'                 => 'manual_adjustment',
        'damage_resolution'                  => 'damage',
        'goodwill'                           => 'discount',
        'overpayment'                        => 'account_credit_applied',
        'payment_returned'                   => 'other',
        'other'                              => 'other',
    ];

    /**
     * Resolve the FF item_type for a credit_notes.source value. Unknown
     * sources fall back to 'other' (defensive — every QBO CreditMemo line
     * needs an ItemRef; 'other' is always mappable).
     */
    public static function resolveItemType(string $source): string
    {
        return self::SOURCE_TO_ITEM_TYPE[$source] ?? 'other';
    }

    /**
     * Push a new FF credit note into QBO as a CreditMemo. Idempotent — if
     * the credit note is already mapped (qbo_credit_memo_id set), returns
     * 'already_mapped' without a second POST.
     */
    public static function pushCreate(int $ffCreditNoteId): array
    {
        return self::pushImpl($ffCreditNoteId, 'create');
    }

    /**
     * UPDATE stub per D-QBO-16-2. The apply→LinkedTxn flow (linking the
     * QBO CreditMemo to a QBO Invoice when an FF credit is applied) is
     * deferred to S-QBO-16-UPDATE-FOLLOWUP (F20). Matches the stub-then-
     * implement pattern of S-QBO-11/14/18/19/21.
     */
    public static function pushUpdate(int $ffCreditNoteId): array
    {
        return [
            'success' => false,
            'status'  => 'unsupported_in_session',
            'outcome' => 'skipped',
            'error'   => 'CreditMemoPusher::pushUpdate (apply→LinkedTxn) is stubbed in S-QBO-16 v1; deferred to S-QBO-16-UPDATE-FOLLOWUP (F20) per D-QBO-16-2.',
        ] + self::RESULT_BASE;
    }

    /**
     * VOID stub per D-QBO-16-2. Deferred to S-QBO-16-UPDATE-FOLLOWUP (F20).
     */
    public static function pushVoid(int $ffCreditNoteId): array
    {
        return [
            'success' => false,
            'status'  => 'unsupported_in_session',
            'outcome' => 'skipped',
            'error'   => 'CreditMemoPusher::pushVoid is stubbed in S-QBO-16 v1; deferred to S-QBO-16-UPDATE-FOLLOWUP (F20) per D-QBO-16-2.',
        ] + self::RESULT_BASE;
    }

    /**
     * Create pipeline (10 steps; see class docblock).
     */
    private static function pushImpl(int $ffCreditNoteId, string $operation): array
    {
        // 1. Sync mode gate. Per-call so a mid-queue mode flip takes effect.
        $mode = (string) settings_get('quickbooks.sync_mode.credit_memo', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $cnForSkip = db_row("SELECT * FROM credit_notes WHERE id = ?", [$ffCreditNoteId]) ?? ['id' => $ffCreditNoteId];
            self::recordSkipped($ffCreditNoteId, $cnForSkip, 'skipped_by_mode', $operation);
            return ['success' => true, 'status' => 'skipped_by_mode', 'outcome' => 'skipped', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load FF credit note.
        $cn = db_row("SELECT * FROM credit_notes WHERE id = ?", [$ffCreditNoteId]);
        if ($cn === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF credit note {$ffCreditNoteId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Voided check. credit_notes.status ENUM includes 'void'.
        if ($cn['status'] === 'void') {
            self::recordSkipped($ffCreditNoteId, $cn, 'skipped_voided', $operation);
            return ['success' => true, 'status' => 'skipped_voided', 'outcome' => 'skipped'] + self::RESULT_BASE;
        }

        // 4. Soft-deleted check.
        if (($cn['deleted_at'] ?? null) !== null) {
            self::recordSkipped($ffCreditNoteId, $cn, 'skipped_soft_deleted', $operation);
            return ['success' => true, 'status' => 'skipped_soft_deleted', 'outcome' => 'skipped'] + self::RESULT_BASE;
        }

        // 5. Idempotency (existing mapping with qbo_credit_memo_id → no-op).
        $mapping = db_row(
            "SELECT id, qbo_credit_memo_id, qbo_currency
               FROM acc_qbo_credit_memo_map
              WHERE ff_credit_note_id = ?",
            [$ffCreditNoteId]
        );
        if ($mapping !== null && !empty($mapping['qbo_credit_memo_id'])) {
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',
                'qbo_id'  => (string) $mapping['qbo_credit_memo_id'],
            ] + self::RESULT_BASE;
        }

        // 6. Pre-flight gates.
        $gate = self::runPreflight($ffCreditNoteId, $cn);
        if (!$gate['ok']) {
            $typedStatus = (string) ($gate['status_code'] ?? 'failed_preflight');
            self::recordFailedPreflight($ffCreditNoteId, $cn, (string) $gate['reason'], $typedStatus);
            return [
                'success' => false,
                'status'  => $typedStatus,
                'outcome' => 'failed',
                'error'   => $gate['reason'],
            ] + self::RESULT_BASE;
        }

        // 7. Load customer mapping + resolve item mapping (gate already verified both).
        $customerMap = db_row(
            "SELECT qbo_customer_id FROM acc_qbo_customer_map
              WHERE ff_customer_id = ? AND mapping_status = 'mapped'",
            [(int) $cn['customer_id']]
        );
        $itemType = self::resolveItemType((string) $cn['source']);
        $itemMap = db_row(
            "SELECT qbo_item_id FROM acc_qbo_item_map
              WHERE ff_item_type = ? AND mapping_status = 'mapped'
              ORDER BY id ASC LIMIT 1",
            [$itemType]
        );

        // 8. Build payload.
        try {
            $payload = self::buildQboPayload($cn, $customerMap ?: [], (string) ($itemMap['qbo_item_id'] ?? ''));
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffCreditNoteId, $cn, "Payload build failed: " . $e->getMessage(), 0);
            return [
                'success' => false,
                'status'  => 'payload_build_failed',
                'outcome' => 'failed',
                'error'   => "Payload build failed: " . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 9. HTTP create.
        $client = new QuickBooksClient();
        try {
            $response = $client->createEntity('creditmemo', $payload);
        } catch (QuickBooksException $e) {
            $httpCode = method_exists($e, 'getHttpStatus') ? (int) $e->getHttpStatus() : 0;
            self::recordPushFailure($ffCreditNoteId, $cn, $e->getMessage(), $httpCode);
            return [
                'success'   => false,
                'status'    => 'qbo_error',
                'outcome'   => 'failed',
                'error'     => $e->getMessage(),
                'http_code' => $httpCode,
            ] + self::RESULT_BASE;
        }

        $qboCm = $response['CreditMemo'] ?? null;
        if (!is_array($qboCm) || empty($qboCm['Id'])) {
            self::recordPushFailure($ffCreditNoteId, $cn, 'QBO response missing CreditMemo.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing CreditMemo.Id',
            ] + self::RESULT_BASE;
        }

        // 10. Persist mapping.
        self::recordSuccessfulPush($ffCreditNoteId, $cn, $qboCm, $itemType);

        return [
            'success'    => true,
            'status'     => 'created',
            'outcome'    => 'created',
            'qbo_id'     => (string) $qboCm['Id'],
            'sync_token' => (string) ($qboCm['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Pre-flight gates. Returns ['ok'=>bool, 'reason'=>?string, 'status_code'=>?string].
     *
     * Gates:
     *   1. Tax override configured (tax_override_code_id) — also the
     *      connection-readiness proxy.
     *   2. Customer mapped (acc_qbo_customer_map mapped → qbo_customer_id).
     *   3. Line item mapped (SOURCE_TO_ITEM_TYPE[source] → acc_qbo_item_map
     *      mapped → qbo_item_id) per D-QBO-16-1.
     *   4. DocNumber length (credit_note_number ≤ 21) → typed
     *      failed_preflight_field_too_long.
     *   5. Currency mismatch (multi_currency='1' → probe QBO customer
     *      currency; best-effort, fall-through on transient error) → typed
     *      failed_preflight_currency_mismatch.
     */
    public static function runPreflight(int $ffCreditNoteId, array $cn): array
    {
        // Gate 1: tax override.
        if ((string) settings_get('quickbooks.tax_override_code_id', '') === '') {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => 'Tax override code not configured (settings.quickbooks.tax_override_code_id empty). Run /quickbooks/tax_codes → Pull first (D-QBO-9-2).'];
        }

        // Gate 2: customer mapping.
        $customerMap = db_row(
            "SELECT qbo_customer_id FROM acc_qbo_customer_map
              WHERE ff_customer_id = ? AND mapping_status = 'mapped'",
            [(int) $cn['customer_id']]
        );
        if (!$customerMap || empty($customerMap['qbo_customer_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "FF customer {$cn['customer_id']} has no mapped QBO customer (acc_qbo_customer_map). Map it via /quickbooks/customers first."];
        }

        // Gate 3: line item mapping (D-QBO-16-1).
        $itemType = self::resolveItemType((string) $cn['source']);
        $itemMap = db_row(
            "SELECT qbo_item_id FROM acc_qbo_item_map
              WHERE ff_item_type = ? AND mapping_status = 'mapped'
              ORDER BY id ASC LIMIT 1",
            [$itemType]
        );
        if (!$itemMap || empty($itemMap['qbo_item_id'])) {
            return ['ok' => false, 'status_code' => 'failed_preflight',
                'reason' => "Credit-memo line item_type '{$itemType}' (mapped from source='{$cn['source']}' per D-QBO-16-1) has no mapped QBO item (acc_qbo_item_map). Map it via /quickbooks/items first."];
        }

        // Gate 4: DocNumber length (shared QboFieldLimits::INVOICE_DOC_NUMBER_MAX=21).
        $docNumber = (string) ($cn['credit_note_number'] ?? '');
        if (strlen($docNumber) > QboFieldLimits::INVOICE_DOC_NUMBER_MAX) {
            return ['ok' => false, 'status_code' => 'failed_preflight_field_too_long',
                'reason' => "credit_note_number '{$docNumber}' is " . strlen($docNumber) . " chars; QBO DocNumber max is " . QboFieldLimits::INVOICE_DOC_NUMBER_MAX . "."];
        }

        // Gate 5: currency mismatch (best-effort QBO probe when multi-currency on).
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            try {
                $client = new QuickBooksClient();
                $resp = $client->getEntity('customer', (string) $customerMap['qbo_customer_id']);
                $qboCustomer = $resp['Customer'] ?? null;
                $qboCurrency = is_array($qboCustomer) ? strtoupper((string) ($qboCustomer['CurrencyRef']['value'] ?? '')) : '';
                $ffCurrency  = strtoupper((string) ($cn['currency'] ?? 'CAD'));
                if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                    return ['ok' => false, 'status_code' => 'failed_preflight_currency_mismatch',
                        'reason' => "FF credit note currency='{$ffCurrency}' but mapped QBO customer currency='{$qboCurrency}'. QBO locks customer currency at creation; pushing would corrupt the AR ledger."];
                }
            } catch (\Throwable $e) {
                // Transient probe failure — fall through; the createEntity call
                // surfaces real connectivity issues. Matches InvoicePreflightGate
                // gate 4.5 fall-through behavior.
                error_log("[CreditMemoPusher] currency-mismatch probe failed for credit_note {$ffCreditNoteId} (non-fatal): " . $e->getMessage());
            }
        }

        return ['ok' => true, 'reason' => null, 'status_code' => null];
    }

    /**
     * Build the QBO CreditMemo payload. Public-static so the offline smoke
     * exercises it without the cURL boundary.
     *
     * Single line (credit_notes is header-only). Tax-override per
     * D-QBO-CORE-6: TotalTax=0 (credit notes carry no tax columns); line
     * TaxCodeRef = tax_override_code_id (the QBO 'NON' code from S-QBO-9).
     *
     * @throws QuickBooksException on missing customer mapping / item / override code
     */
    public static function buildQboPayload(array $cn, array $customerMap, string $qboItemId): array
    {
        $qboCustomerId = (string) ($customerMap['qbo_customer_id'] ?? '');
        if ($qboCustomerId === '') {
            throw new QuickBooksException("CreditMemo payload: missing QBO customer mapping for FF customer " . ($cn['customer_id'] ?? '?') . ".");
        }
        if ($qboItemId === '') {
            throw new QuickBooksException("CreditMemo payload: missing QBO item mapping for source='" . ($cn['source'] ?? '?') . "' (item_type=" . self::resolveItemType((string) ($cn['source'] ?? '')) . ").");
        }
        $overrideCodeId = (string) settings_get('quickbooks.tax_override_code_id', '');
        if ($overrideCodeId === '') {
            throw new QuickBooksException("CreditMemo payload: settings.quickbooks.tax_override_code_id is empty (D-QBO-9-2).");
        }

        $amount = bcadd((string) ($cn['amount'] ?? '0'), '0', 2);
        // Line description: reason truncated to QBO's line-description limit.
        $reason = trim((string) ($cn['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Credit memo — ' . (string) ($cn['source'] ?? 'other');
        }
        if (strlen($reason) > QboFieldLimits::INVOICE_LINE_DESCRIPTION_MAX) {
            $reason = substr($reason, 0, QboFieldLimits::INVOICE_LINE_DESCRIPTION_MAX);
        }

        // TxnDate from created_at (credit_notes has no dedicated txn_date column).
        $txnDate = substr((string) ($cn['created_at'] ?? date('Y-m-d')), 0, 10);

        $payload = [
            'CustomerRef' => ['value' => $qboCustomerId],
            'TxnDate'     => $txnDate,
            'DocNumber'   => (string) ($cn['credit_note_number'] ?? ''),
            'Line'        => [
                [
                    'Description'         => $reason,
                    'Amount'              => (float) $amount,
                    'DetailType'          => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => [
                        'ItemRef'    => ['value' => $qboItemId],
                        'Qty'        => 1,
                        'UnitPrice'  => (float) $amount,
                        'TaxCodeRef' => ['value' => $overrideCodeId],
                    ],
                ],
            ],
            // Tax-override: credit notes carry no tax → TotalTax=0 (D-QBO-CORE-6).
            'TxnTaxDetail' => [
                'TxnTaxCodeRef' => ['value' => $overrideCodeId],
                'TotalTax'      => '0.00',
            ],
            'PrivateNote' => self::buildPrivateNoteJson($cn),
        ];

        // CurrencyRef + ExchangeRate gated on multi_currency (D-QBO-FIXPACK-12).
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($cn['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';
            }
            $payload['CurrencyRef'] = ['value' => $currency];
            // Credit notes have no exchange_rate column; CAD=1.0, non-CAD=1.0
            // best-effort (FX revaluation of mirror rows deferred — see F15).
            $payload['ExchangeRate'] = '1.0';
        }

        return $payload;
    }

    /**
     * PrivateNote JSON for QBO-side audit drill-down (accountant-only).
     */
    public static function buildPrivateNoteJson(array $cn): string
    {
        $note = [
            'ff_credit_note_id'     => (int) ($cn['id'] ?? 0),
            'ff_credit_note_number' => (string) ($cn['credit_note_number'] ?? ''),
            'source'                => (string) ($cn['source'] ?? ''),
            'item_type'             => self::resolveItemType((string) ($cn['source'] ?? '')),
            'pushed_at'             => date('c'),
        ];
        return json_encode($note, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers (mirror InvoicePusher)
    // ────────────────────────────────────────────────────────────────────

    private static function ffCreditNoteExists(int $id): bool
    {
        return db_row("SELECT id FROM credit_notes WHERE id = ?", [$id]) !== null;
    }

    private static function recordSuccessfulPush(int $ffCreditNoteId, array $cn, array $qboCm, string $itemType): void
    {
        if (!self::ffCreditNoteExists($ffCreditNoteId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffCreditNoteId, [
            'qbo_credit_memo_id'            => (string) $qboCm['Id'],
            'qbo_sync_token'                => (string) ($qboCm['SyncToken'] ?? '0'),
            'qbo_doc_number'                => isset($qboCm['DocNumber']) ? (string) $qboCm['DocNumber'] : (string) ($cn['credit_note_number'] ?? ''),
            'qbo_total_amt'                 => isset($qboCm['TotalAmt']) ? (string) $qboCm['TotalAmt'] : (string) ($cn['amount'] ?? '0.00'),
            'qbo_balance'                   => isset($qboCm['Balance']) ? (string) $qboCm['Balance'] : null,
            'qbo_currency'                  => isset($qboCm['CurrencyRef']['value']) ? (string) $qboCm['CurrencyRef']['value'] : (string) ($cn['currency'] ?? null),
            'qbo_exchange_rate'             => isset($qboCm['ExchangeRate']) ? (string) $qboCm['ExchangeRate'] : null,
            'qbo_item_type_used'            => $itemType,
            'ff_credit_note_snapshot_total' => (string) ($cn['amount'] ?? '0.00'),
            'push_status'                   => 'pushed',
            'push_error'                    => null,
            'pushed_at'                     => $now,
            'last_synced_at'                => $now,
        ]);
        self::writeSyncLog($ffCreditNoteId, 'create', 'pushed', "QBO CreditMemo #{$qboCm['Id']} created");
    }

    private static function recordPushFailure(int $ffCreditNoteId, array $cn, string $error, int $httpCode): void
    {
        if (!self::ffCreditNoteExists($ffCreditNoteId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffCreditNoteId, [
            'push_status'    => 'failed',
            'push_error'     => substr($error, 0, 2000),
            'last_synced_at' => $now,
        ]);
        self::writeSyncLog($ffCreditNoteId, 'create', 'failed', $error);
    }

    private static function recordFailedPreflight(int $ffCreditNoteId, array $cn, string $reason, string $status): void
    {
        if (!self::ffCreditNoteExists($ffCreditNoteId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffCreditNoteId, [
            'push_status'    => $status,
            'push_error'     => substr($reason, 0, 2000),
            'last_synced_at' => $now,
        ]);
        self::writeSyncLog($ffCreditNoteId, 'create', $status, $reason);
    }

    private static function recordSkipped(int $ffCreditNoteId, array $cn, string $skippedStatus, string $operation): void
    {
        if (!self::ffCreditNoteExists($ffCreditNoteId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $num = (string) ($cn['credit_note_number'] ?? '');
        $msg = "skipped: {$skippedStatus}" . ($num !== '' ? " (CN {$num})" : '');
        self::upsertMappingRow($ffCreditNoteId, [
            'push_status'    => $skippedStatus,
            'push_error'     => $msg,
            'last_synced_at' => $now,
        ]);
        self::writeSyncLog($ffCreditNoteId, $operation, $skippedStatus, $msg);
    }

    /**
     * INSERT-or-UPDATE the acc_qbo_credit_memo_map row keyed on
     * ff_credit_note_id (uq_ff_credit_note).
     */
    private static function upsertMappingRow(int $ffCreditNoteId, array $fields): void
    {
        $existing = db_row("SELECT id FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id = ?", [$ffCreditNoteId]);
        if ($existing) {
            db_update('acc_qbo_credit_memo_map', $fields, 'ff_credit_note_id = ?', [$ffCreditNoteId]);
        } else {
            db_insert('acc_qbo_credit_memo_map', ['ff_credit_note_id' => $ffCreditNoteId] + $fields);
        }
    }

    /**
     * Best-effort sync_log write (never throws). Canonical §6.5
     * acc_qbo_sync_log shape (direction ENUM push/pull; NOT NULL
     * http_method/endpoint/realm_id/environment) — mirrors
     * JournalEntryPusher::writeNonHttpSyncLog. http_method='SKIP' marks a
     * non-HTTP state mutation (skip / preflight fail); 'POST' for real
     * pushes. error_code carries the typed status for non-success rows.
     */
    private static function writeSyncLog(int $ffCreditNoteId, string $operation, string $status, string $message): void
    {
        $isSuccess = ($status === 'pushed');
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'credit_memo',
                'entity_id'       => $ffCreditNoteId,
                'operation'       => $operation,
                'http_method'     => $isSuccess ? 'POST' : 'SKIP',
                'endpoint'        => $isSuccess ? 'creditmemo' : '',
                'response_status' => null,
                'error_code'      => $isSuccess ? null : substr($status, 0, 50),
                'error_message'   => substr($message, 0, 500),
                'queue_id'        => QuickBooksClient::workerQueueId(),
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log("[CreditMemoPusher] writeSyncLog failed for credit_note {$ffCreditNoteId}: " . $e->getMessage());
        }
    }
}
