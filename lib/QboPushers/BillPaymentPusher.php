<?php
declare(strict_types=1);

/**
 * lib/QboPushers/BillPaymentPusher.php
 *
 * Phase QBO-8 / 2 of 2 (S-QBO-19) — FF→QBO bill payment push. Pairs with
 * S-QBO-18 BillPusher to close Phase QBO-8 (AP-direction surface).
 *
 * Mirrors S-QBO-14 PaymentPusher (AR-direction analog) + S-QBO-18 BillPusher
 * (AP-direction sibling) patterns. Key differences from PaymentPusher:
 *   - Reads FF acc_ap_payments table (not payments)
 *   - VendorRef (not CustomerRef) from acc_qbo_vendor_map
 *   - BankAccountRef from acc_qbo_account_map via ap_payment.bank_account_id
 *     (instead of DepositToAccountRef from undeposited_funds category)
 *   - LinkedTxn[].TxnType='Bill' (not 'Invoice') per D-QBO-19-4
 *   - PayType mapping FF payment_method → QBO PayType per D-QBO-19-2
 *   - No `origin` filter — acc_ap_payments has no origin column (FF-native
 *     only in v1 per D-QBO-19-1; no QBO webhook intake for bill payments)
 *   - Allocations from acc_ap_payment_allocations (not payment_allocations)
 *
 * Per D-QBO-19-1: bill payments are FF-origin only (mirrors D-QBO-18-6 bills).
 * Per D-QBO-19-2: PayType mapping FF payment_method → QBO PayType:
 *   - check / eft / wire → Check
 *   - credit_card → CreditCard
 *   - cash / other → Check (Intuit lacks Cash; Check is operationally-
 *     equivalent for non-card non-wire payments)
 * Per D-QBO-19-3: BankAccountRef from acc_qbo_account_map lookup on
 *   ap_payment.bank_account_id (FF bank account → QBO bank/credit account).
 * Per D-QBO-19-4: LinkedTxn.TxnType='Bill' for each allocation.
 * Per D-QBO-19-5: pushUpdate IMPLEMENTED in S-QBO-BILL-PAYMENT-UPDATE
 *   (closes the stub): routes through pushImpl with operation='update' →
 *   full-payload re-send via QuickBooksClient::updateEntity + SyncToken
 *   round-trip (mirrors InvoicePusher D-QBO-12-1 + BillPusher
 *   D-QBO-BILL-UPDATE-1 + PaymentPusher D-QBO-PAYMENT-UPDATE-1), demote-
 *   to-create when unmapped (D-PUSHER-DEMOTION-RULE). No origin/dedup
 *   gate dance is needed here because acc_ap_payments has no `origin`
 *   column (FF-native only per D-QBO-19-1) — the bill-template placement
 *   at step 5b (after create-idempotency, before status-cleared gate)
 *   is sufficient.
 *
 * @session  S-QBO-19
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.9 (Bill Payment),
 *           §6.8 (Pusher Contract), §7.7 acc_qbo_bill_payment_map
 * @decision D-QBO-19-1 (FF-origin only),
 *           D-QBO-19-2 (PayType mapping),
 *           D-QBO-19-3 (BankAccountRef from acc_qbo_account_map),
 *           D-QBO-19-4 (LinkedTxn.TxnType='Bill'),
 *           D-QBO-19-5 (pushUpdate stub — CLOSED by S-QBO-BILL-PAYMENT-UPDATE /
 *               D-QBO-BILL-PAYMENT-UPDATE-1),
 *           D-PUSHER-DEMOTION-RULE (update→create demotion — ACTIVE since
 *               S-QBO-BILL-PAYMENT-UPDATE: pushImpl step 5b demotes an
 *               'update' of an unmapped ap_payment to 'create'),
 *           D-QBO-19-6 (currency-mismatch gate mirrors D-QBO-BILL-GOTCHAS-1
 *               + D-QBO-14-7; SKIPS when multi_currency='0' per
 *               D-QBO-FIXPACK-12),
 *           D-QBO-19-7 (reference_number 21-char gate via
 *               QboFieldLimits::PAYMENT_REF_NUM_MAX mirrors D-QBO-14-6)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class BillPaymentPusher
{
    /**
     * §6.8 canonical return shape — merged onto every return path so
     * downstream consumers (worker tally, smoke assertions) get a
     * predictable key set.
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
     * FF payment_method → QBO PayType mapping per D-QBO-19-2.
     */
    private const PAYMENT_METHOD_TO_PAY_TYPE = [
        'check'       => 'Check',
        'eft'         => 'Check',          // Intuit lacks EFT; Check is operationally-equivalent
        'wire'        => 'Check',          // same as EFT — Intuit doesn't separate wire
        'credit_card' => 'CreditCard',
        'cash'        => 'Check',          // Intuit lacks Cash
        'other'       => 'Check',          // fallback
    ];

    /**
     * Push a new FF ap_payment into QBO as a BillPayment. Idempotent —
     * if the payment is already mapped (qbo_bill_payment_id), returns
     * ['status' => 'already_mapped'] without re-POSTing.
     */
    public static function pushCreate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'create', $payloadSnapshot);
    }

    /**
     * Push an UPDATE to an existing QBO BillPayment (S-QBO-BILL-PAYMENT-UPDATE,
     * closing the D-QBO-19-5 stub). Full-payload re-send via
     * QuickBooksClient::updateEntity — not a sparse diff (mirrors
     * InvoicePusher D-QBO-12-1 + BillPusher D-QBO-BILL-UPDATE-1 +
     * PaymentPusher D-QBO-PAYMENT-UPDATE-1). FF is canonical (D-QBO-CORE-1);
     * QBO accepts the complete payload + current SyncToken and replaces.
     *
     * Demotion (D-PUSHER-DEMOTION-RULE): if the bill payment was never
     * pushed (no map row / null qbo_bill_payment_id), pushImpl auto-demotes
     * to 'create' at step 5b and re-POSTs — an update of an unmapped
     * entity is just a first push.
     *
     * No origin/dedup gate is needed here because acc_ap_payments has
     * no `origin` column (D-QBO-19-1 — bill payments are FF-native only;
     * there is no QBO-side BillPayment webhook intake).
     *
     * @return array  §6.8 5-key return shape; status='pushed' on a
     *                successful update (acc_qbo_bill_payment_map.push_status
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
     * Shared orchestration. Both public methods delegate here.
     */
    private static function pushImpl(int $ffApPaymentId, string $operation, ?array $payloadSnapshot): array
    {
        // 1. Sync mode gate.
        $mode = (string) settings_get('quickbooks.sync_mode.bill_payment', 'queue');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $ffForSkip = db_row("SELECT id, status FROM acc_ap_payments WHERE id = ?", [$ffApPaymentId]) ?? ['id' => $ffApPaymentId, 'status' => null];
            self::recordSkipped($ffApPaymentId, $ffForSkip, 'skipped_by_mode', $operation);
            return [
                'success' => true,
                'status'  => 'skipped_by_mode',
                'outcome' => 'skipped',
                'error'   => "sync_mode.bill_payment={$mode}",
            ] + self::RESULT_BASE;
        }

        // 2. Load FF ap_payment state. Selects columns the Pusher maps
        //    to QBO. acc_ap_payments has no deleted_at — uses status='void'
        //    instead per S-QBO-18 D-QBO-18-4 pattern.
        $ff = db_row(
            "SELECT id, payment_number, vendor_id, bank_account_id, payment_date,
                    payment_method, reference_number, check_number, amount,
                    currency, exchange_rate_to_cad, status, notes
               FROM acc_ap_payments
              WHERE id = ?",
            [$ffApPaymentId]
        );
        if ($ff === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF ap_payment {$ffApPaymentId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Look up existing mapping.
        $mapping = db_row(
            "SELECT id, qbo_bill_payment_id, qbo_sync_token, qbo_currency, push_status
               FROM acc_qbo_bill_payment_map
              WHERE ff_ap_payment_id = ?",
            [$ffApPaymentId]
        );

        // 4. Voided payment skip per D-QBO-18-4 mirror (no origin column
        //    on acc_ap_payments — FF-native only per D-QBO-19-1).
        if ($ff['status'] === 'void') {
            if ($mapping === null || empty($mapping['qbo_bill_payment_id'])) {
                return [
                    'success' => true,
                    'status'  => 'skipped_unmapped_void',
                    'outcome' => 'skipped',
                ] + self::RESULT_BASE;
            }
            self::recordSkipped($ffApPaymentId, $ff, 'skipped_voided', $operation);
            return [
                'success' => true,
                'status'  => 'skipped_voided',
                'outcome' => 'skipped',
                'qbo_id'  => (string) $mapping['qbo_bill_payment_id'],
            ] + self::RESULT_BASE;
        }

        // 5. Idempotency on CREATE.
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_bill_payment_id'])) {
            $expectedCurrency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            $snapshotCurrency = strtoupper((string) ($mapping['qbo_currency'] ?? ''));
            if ($snapshotCurrency !== '' && $snapshotCurrency !== $expectedCurrency) {
                error_log(
                    "S-QBO-FIXPACK-10 WARNING: FF ap_payment {$ff['id']} expected CurrencyRef='{$expectedCurrency}' " .
                    "but mapped QBO bill payment #{$mapping['qbo_bill_payment_id']} snapshot is '{$snapshotCurrency}'."
                );
            }
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',
                'qbo_id'  => (string) $mapping['qbo_bill_payment_id'],
            ] + self::RESULT_BASE;
        }

        // 5b. Update demotion (D-PUSHER-DEMOTION-RULE, mirrors InvoicePusher
        //     D-QBO-12-1 + BillPusher S-QBO-BILL-UPDATE step 5b + PaymentPusher
        //     S-QBO-PAYMENT-UPDATE step 7b). An 'update' of a bill payment
        //     that was never pushed (no map row OR null qbo_bill_payment_id)
        //     is just a first push — demote to 'create' so the HTTP dispatch
        //     in step 9 calls createEntity. No origin gate exists for
        //     bill_payment (acc_ap_payments has no `origin` column per
        //     D-QBO-19-1 — FF-native only), so placement here is straightforward.
        if ($operation === 'update' && ($mapping === null || empty($mapping['qbo_bill_payment_id']))) {
            $operation = 'create';
        }

        // 6. Status must be 'cleared' to push. 'pending' payments aren't
        //    confirmed yet; 'void' caught at step 4.
        if ($ff['status'] !== 'cleared') {
            self::recordFailedPreflight(
                $ffApPaymentId,
                "Bill payment {$ff['payment_number']} status='{$ff['status']}'; push requires status='cleared' (D-QBO-19-1 eligibility)."
            );
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => "ap_payment status='{$ff['status']}' — push requires 'cleared'",
            ] + self::RESULT_BASE;
        }

        // 7. Pre-flight gates.
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
                self::recordTypedPreflight($ffApPaymentId, $statusCode, $reason);
            } else {
                self::recordFailedPreflight($ffApPaymentId, $reason);
            }
            return [
                'success' => false,
                'status'  => $statusCode,
                'outcome' => 'failed',
                'error'   => $reason,
            ] + self::RESULT_BASE;
        }

        // 8. Build payload.
        try {
            $qboPayload = self::buildQboPayload($ff);
        } catch (QuickBooksException $e) {
            self::recordFailedPreflight($ffApPaymentId, 'Payload build failed: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build failed: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        } catch (\Throwable $e) {
            self::recordFailedPreflight($ffApPaymentId, 'Payload build unexpected error: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build unexpected error: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 9. HTTP call. createEntity for a new bill payment, updateEntity for
        //    an existing one (full-payload re-send per D-QBO-12-1; updateEntity
        //    merges Id + SyncToken into the payload). SyncToken comes from the
        //    mapping row (refreshed on every push). Demotion in step 5b
        //    guarantees $mapping has a qbo_bill_payment_id whenever $operation
        //    is still 'update' here.
        $client = new QuickBooksClient();
        try {
            if ($operation === 'update') {
                $response = $client->updateEntity(
                    'billpayment',
                    (string) $mapping['qbo_bill_payment_id'],
                    (string) ($mapping['qbo_sync_token'] ?? '0'),
                    $qboPayload
                );
            } else {
                $response = $client->createEntity('billpayment', $qboPayload);
            }
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffApPaymentId, $e->getMessage());
            return [
                'success' => false,
                'status'  => 'qbo_error',
                'outcome' => 'failed',
                'error'   => $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 10. Parse response — Intuit returns BillPayment entity under 'BillPayment' key.
        $qboBp = $response['BillPayment'] ?? null;
        if (!is_array($qboBp) || empty($qboBp['Id'])) {
            self::recordPushFailure($ffApPaymentId, 'QBO response missing BillPayment.Id');
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing BillPayment.Id',
            ] + self::RESULT_BASE;
        }

        self::recordSuccessfulPush($ffApPaymentId, $ff, $qboBp);

        return [
            'success'    => true,
            'status'     => 'pushed',
            // acc_qbo_bill_payment_map.push_status ENUM has no 'updated'
            // value — the map row stays 'pushed' for both create + update.
            // The transient outcome field distinguishes them for the worker
            // tally / smoke assertions.
            'outcome'    => $operation === 'update' ? 'updated' : 'created',
            'qbo_id'     => (string) $qboBp['Id'],
            'sync_token' => (string) ($qboBp['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    // ────────────────────────────────────────────────────────────────────
    // Pre-flight gates
    // ────────────────────────────────────────────────────────────────────

    /**
     * Inline pre-flight gates.
     *
     * Gates:
     *   1. Vendor mapping — acc_qbo_vendor_map for ff.vendor_id
     *   2. AccountValidator::assertReadyForBillPaymentPush (ap_clearing +
     *      undeposited_funds — already shipped in S-QBO-VALIDATOR-SCOPE-SPLIT)
     *   3. Bank account mapping — ap_payment.bank_account_id must map to
     *      a QBO account via acc_qbo_account_map (D-QBO-19-3)
     *   4. Per-allocation bill mapping — each acc_ap_payment_allocations
     *      row's bill must have acc_qbo_bill_map row + qbo_bill_id (D-QBO-19-4)
     *   5. At least one allocation
     *   6. Connection healthy
     *   7. Field-length: reference_number ≤21 chars per D-QBO-19-7
     *   8. Currency-mismatch when multi_currency='1' (D-QBO-19-6)
     *
     * @return null|string|array{reason: string, status_code: string}
     */
    private static function runPreflight(array $ff)
    {
        // Gate 1: Vendor mapping.
        $vendorMap = db_row(
            "SELECT qbo_vendor_id, mapping_status FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
            [(int) $ff['vendor_id']]
        );
        if ($vendorMap === null || empty($vendorMap['qbo_vendor_id']) || $vendorMap['mapping_status'] !== 'mapped') {
            return "Vendor #{$ff['vendor_id']} is not mapped to QBO. Map via /quickbooks/vendors before pushing the bill payment.";
        }

        // Gate 2: AccountValidator.
        try {
            AccountValidator::assertReadyForBillPaymentPush();
        } catch (ChartOfAccountsIncompleteException $e) {
            return 'AccountValidator: ' . $e->getMessage();
        }

        // Gate 3: Bank account mapping (D-QBO-19-3).
        // Chain lookup: ap_payment.bank_account_id → acc_bank_accounts.id
        //   → acc_bank_accounts.gl_account_id → acc_qbo_account_map.ff_account_id
        //   → qbo_account_id. K-22 trap: bank_account_id is NOT the ff_account_id
        //   directly — acc_bank_accounts is a separate table with its own id +
        //   gl_account_id pivot to the chart of accounts (acc_accounts).
        $bankAccount = db_row(
            "SELECT gl_account_id FROM acc_bank_accounts WHERE id = ?",
            [(int) $ff['bank_account_id']]
        );
        if ($bankAccount === null || empty($bankAccount['gl_account_id'])) {
            return "Bank account FF#{$ff['bank_account_id']} not found or has no gl_account_id pivot.";
        }
        $bankMap = db_row(
            "SELECT qbo_account_id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id = ?",
            [(int) $bankAccount['gl_account_id']]
        );
        if ($bankMap === null || empty($bankMap['qbo_account_id']) || $bankMap['mapping_status'] !== 'mapped') {
            return "Bank account FF#{$ff['bank_account_id']} (GL #{$bankAccount['gl_account_id']}) is not mapped to QBO. Map via /quickbooks/accounts before pushing.";
        }

        // Gate 4 + 5: Per-allocation bill mapping + at least one allocation.
        $allocations = db_select(
            "SELECT id, bill_id, amount_applied
               FROM acc_ap_payment_allocations
              WHERE ap_payment_id = ?
              ORDER BY id",
            [(int) $ff['id']]
        );
        if (empty($allocations)) {
            return "Bill payment {$ff['payment_number']} has no bill allocations. v1 only pushes invoice-linked bill payments (D-QBO-19-4).";
        }
        foreach ($allocations as $alloc) {
            $billMap = db_row(
                "SELECT qbo_bill_id FROM acc_qbo_bill_map WHERE ff_bill_id = ?",
                [(int) $alloc['bill_id']]
            );
            if ($billMap === null || empty($billMap['qbo_bill_id'])) {
                return "Bill payment {$ff['payment_number']} allocates to FF bill #{$alloc['bill_id']} which has no QBO mapping. Push the bill (S-QBO-18) before pushing the payment.";
            }
        }

        // Gate 6: Connection status.
        $connStatus = (string) settings_get('quickbooks.connection_status', '');
        if ($connStatus !== 'connected') {
            return "QBO connection is not connected (status={$connStatus}). Connect via /quickbooks/settings.";
        }

        // Gate 7: Field-length — reference_number per D-QBO-19-7 (mirrors
        // D-QBO-14-6 PaymentRefNum limit).
        $refNum = trim((string) ($ff['reference_number'] ?? ''));
        if ($refNum !== '' && strlen($refNum) > QboFieldLimits::PAYMENT_REF_NUM_MAX) {
            $len = strlen($refNum);
            $max = QboFieldLimits::PAYMENT_REF_NUM_MAX;
            return [
                'reason'      => "reference_number '{$refNum}' exceeds QBO BillPayment reference limit of {$max} characters (actual: {$len}). Shorten the reference_number.",
                'status_code' => 'failed_preflight_field_too_long',
            ];
        }

        // Gate 8: Currency-mismatch (D-QBO-19-6 mirrors D-QBO-BILL-GOTCHAS-1 + D-QBO-14-7).
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            try {
                $client = new QuickBooksClient();
                $qboVendorResp = $client->getEntity('vendor', (string) $vendorMap['qbo_vendor_id']);
                $qboVendor = $qboVendorResp['Vendor'] ?? null;
                if ($qboVendor === null) {
                    return [
                        'reason'      => "QBO vendor (id={$vendorMap['qbo_vendor_id']}) not found; mapping may be stale.",
                        'status_code' => 'failed_preflight',
                    ];
                }
                $qboCurrency = strtoupper((string) ($qboVendor['CurrencyRef']['value'] ?? ''));
                $ffCurrency  = strtoupper((string) ($ff['currency'] ?? ''));
                if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                    $qboName = (string) ($qboVendor['DisplayName'] ?? '(unnamed)');
                    return [
                        'reason'      => "Currency mismatch: FF bill payment is {$ffCurrency} but QBO vendor (id={$vendorMap['qbo_vendor_id']}, name='{$qboName}') is {$qboCurrency}.",
                        'status_code' => 'failed_preflight_currency_mismatch',
                    ];
                }
            } catch (\Throwable $e) {
                error_log("BillPaymentPusher gate 8 (currency-mismatch) probe failed for ff_ap_payment={$ff['id']}: " . $e->getMessage());
            }
        }

        return null;
    }

    // ────────────────────────────────────────────────────────────────────
    // Payload builder (public-static for offline smoke testability)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Emit QBO BillPayment payload from FF ap_payment row + allocations.
     * Per spec §8.9 + D-QBO-19-2/3/4.
     *
     * @throws QuickBooksException on missing references
     */
    public static function buildQboPayload(array $ff): array
    {
        // 1. Vendor mapping (defensive — preflight should have validated).
        $vendorMap = db_row(
            "SELECT qbo_vendor_id FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
            [(int) $ff['vendor_id']]
        );
        if ($vendorMap === null || empty($vendorMap['qbo_vendor_id'])) {
            throw new QuickBooksException(
                "Cannot build QBO BillPayment payload: FF vendor {$ff['vendor_id']} has no QBO mapping."
            );
        }

        // 2. Bank account mapping (D-QBO-19-3) via chain:
        //    ap_payment.bank_account_id → acc_bank_accounts.id
        //      → acc_bank_accounts.gl_account_id → acc_qbo_account_map.qbo_account_id
        $bankAccount = db_row(
            "SELECT gl_account_id FROM acc_bank_accounts WHERE id = ?",
            [(int) $ff['bank_account_id']]
        );
        if ($bankAccount === null || empty($bankAccount['gl_account_id'])) {
            throw new QuickBooksException(
                "Cannot build QBO BillPayment payload: FF bank_account {$ff['bank_account_id']} not found or missing gl_account_id pivot (D-QBO-19-3)."
            );
        }
        $bankMap = db_row(
            "SELECT qbo_account_id FROM acc_qbo_account_map WHERE ff_account_id = ?",
            [(int) $bankAccount['gl_account_id']]
        );
        if ($bankMap === null || empty($bankMap['qbo_account_id'])) {
            throw new QuickBooksException(
                "Cannot build QBO BillPayment payload: FF bank_account #{$ff['bank_account_id']} (GL #{$bankAccount['gl_account_id']}) has no QBO mapping (D-QBO-19-3)."
            );
        }

        // 3. PayType mapping per D-QBO-19-2.
        $ffMethod = (string) ($ff['payment_method'] ?? 'other');
        $payType  = self::PAYMENT_METHOD_TO_PAY_TYPE[$ffMethod] ?? 'Check';

        $payload = [
            'VendorRef'  => ['value' => (string) $vendorMap['qbo_vendor_id']],
            'TotalAmt'   => (float) $ff['amount'],
            'TxnDate'    => (string) $ff['payment_date'],
            'PayType'    => $payType,
        ];

        // 4. PayType-specific sub-object per spec §8.9 + Intuit API contract.
        if ($payType === 'CreditCard') {
            $payload['CreditCardPayment'] = [
                'CCAccountRef' => ['value' => (string) $bankMap['qbo_account_id']],
            ];
        } else {
            // Check (default; covers eft/wire/cash/other per D-QBO-19-2)
            $payload['CheckPayment'] = [
                'BankAccountRef' => ['value' => (string) $bankMap['qbo_account_id']],
                'PrintStatus'    => 'NotSet',
            ];
        }

        // 5. PrivateNote with FF reference_number + check_number for audit drill-down.
        $refNum = trim((string) ($ff['reference_number'] ?? ''));
        $chkNum = trim((string) ($ff['check_number'] ?? ''));
        $noteParts = ["FF ap_payment #{$ff['payment_number']}"];
        if ($refNum !== '') {
            $noteParts[] = "ref={$refNum}";
        }
        if ($chkNum !== '') {
            $noteParts[] = "check={$chkNum}";
        }
        $payload['PrivateNote'] = implode(' | ', $noteParts);

        // 6. CurrencyRef + ExchangeRate gated on multi_currency per D-QBO-FIXPACK-12.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';
            }
            $payload['CurrencyRef'] = ['value' => $currency];
            if ($currency === 'CAD') {
                $payload['ExchangeRate'] = '1.0';
            } else {
                $rate = (string) ($ff['exchange_rate_to_cad'] ?? '');
                if ($rate === '' || bccomp($rate, '0', 6) <= 0) {
                    throw new QuickBooksException(
                        "Cannot build QBO BillPayment payload: non-CAD payment #{$ff['id']} has missing/zero exchange_rate_to_cad."
                    );
                }
                $payload['ExchangeRate'] = $rate;
            }
        }

        // 7. Build Line array from acc_ap_payment_allocations per D-QBO-19-4.
        $allocations = db_select(
            "SELECT id, bill_id, amount_applied
               FROM acc_ap_payment_allocations
              WHERE ap_payment_id = ?
              ORDER BY id",
            [(int) $ff['id']]
        );
        if (empty($allocations)) {
            throw new QuickBooksException(
                "Cannot build QBO BillPayment payload: ap_payment #{$ff['id']} has no bill allocations (D-QBO-19-4)."
            );
        }
        $payloadLines = [];
        foreach ($allocations as $alloc) {
            $billMap = db_row(
                "SELECT qbo_bill_id FROM acc_qbo_bill_map WHERE ff_bill_id = ?",
                [(int) $alloc['bill_id']]
            );
            if ($billMap === null || empty($billMap['qbo_bill_id'])) {
                throw new QuickBooksException(
                    "Cannot build QBO BillPayment payload: allocation #{$alloc['id']} targets FF bill #{$alloc['bill_id']} which has no QBO mapping."
                );
            }
            $payloadLines[] = [
                'Amount'    => (float) $alloc['amount_applied'],
                'LinkedTxn' => [
                    [
                        'TxnId'   => (string) $billMap['qbo_bill_id'],
                        'TxnType' => 'Bill',
                    ],
                ],
            ];
        }
        $payload['Line'] = $payloadLines;

        return $payload;
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers (mirror BillPusher / PaymentPusher)
    // ────────────────────────────────────────────────────────────────────

    private static function recordSkipped(int $ffApPaymentId, array $ff, string $statusCode, string $operation): void
    {
        if (!self::ffPaymentExists($ffApPaymentId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $payNum = (string) ($ff['payment_number'] ?? '');
        $errorMsg = "skipped: {$statusCode}" . ($payNum !== '' ? " (ap_payment {$payNum})" : '');
        self::upsertMappingRow($ffApPaymentId, [
            'push_status'    => $statusCode,
            'push_error'     => $errorMsg,
            'last_synced_at' => $now,
        ]);
        self::writeNonHttpSyncLog($ffApPaymentId, $operation, $statusCode, $errorMsg);
    }

    private static function recordSuccessfulPush(int $ffApPaymentId, array $ff, array $qboBp): void
    {
        if (!self::ffPaymentExists($ffApPaymentId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $vendorMap = db_row(
            "SELECT qbo_vendor_id FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
            [(int) $ff['vendor_id']]
        );
        // Chain lookup for bank account snapshot (same as buildQboPayload)
        $bankAccount = db_row(
            "SELECT gl_account_id FROM acc_bank_accounts WHERE id = ?",
            [(int) $ff['bank_account_id']]
        );
        $bankMap = $bankAccount && !empty($bankAccount['gl_account_id'])
            ? db_row(
                "SELECT qbo_account_id FROM acc_qbo_account_map WHERE ff_account_id = ?",
                [(int) $bankAccount['gl_account_id']]
            )
            : null;

        self::upsertMappingRow($ffApPaymentId, [
            'qbo_bill_payment_id'      => (string) $qboBp['Id'],
            'qbo_sync_token'           => (string) ($qboBp['SyncToken'] ?? '0'),
            'qbo_vendor_id'            => $vendorMap['qbo_vendor_id'] ?? null,
            'qbo_bank_account_id'      => $bankMap['qbo_account_id'] ?? null,
            'qbo_pay_type'             => (string) ($qboBp['PayType'] ?? ''),
            'qbo_total_amt'            => isset($qboBp['TotalAmt']) ? (string) $qboBp['TotalAmt'] : null,
            'qbo_currency'             => isset($qboBp['CurrencyRef']['value']) ? (string) $qboBp['CurrencyRef']['value'] : null,
            'qbo_exchange_rate'        => isset($qboBp['ExchangeRate']) ? (string) $qboBp['ExchangeRate'] : null,
            'qbo_txn_date'             => isset($qboBp['TxnDate']) ? (string) $qboBp['TxnDate'] : null,
            'ff_payment_snapshot_total' => (string) ($ff['amount'] ?? '0.00'),
            'push_status'              => 'pushed',
            'push_error'               => null,
            'pushed_at'                => $now,
            'last_synced_at'           => $now,
        ]);
    }

    private static function recordPushFailure(int $ffApPaymentId, string $errorMessage): void
    {
        if (!self::ffPaymentExists($ffApPaymentId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffApPaymentId, [
            'push_status'    => 'failed',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    private static function recordFailedPreflight(int $ffApPaymentId, string $errorMessage): void
    {
        if (!self::ffPaymentExists($ffApPaymentId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffApPaymentId, [
            'push_status'    => 'failed_preflight',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    private static function recordTypedPreflight(int $ffApPaymentId, string $statusCode, string $errorMessage): void
    {
        if (!self::ffPaymentExists($ffApPaymentId)) {
            return;
        }
        $typedStates = ['failed_preflight_currency_mismatch', 'failed_preflight_field_too_long'];
        $persistedStatus = in_array($statusCode, $typedStates, true) ? $statusCode : 'failed_preflight';
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffApPaymentId, [
            'push_status'    => $persistedStatus,
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────────

    private static function ffPaymentExists(int $ffApPaymentId): bool
    {
        $exists = db_row("SELECT id FROM acc_ap_payments WHERE id = ?", [$ffApPaymentId]);
        return $exists !== null;
    }

    private static function upsertMappingRow(int $ffApPaymentId, array $fields): void
    {
        $existing = db_row("SELECT id FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = ?", [$ffApPaymentId]);
        if ($existing === null) {
            db_insert('acc_qbo_bill_payment_map', array_merge(['ff_ap_payment_id' => $ffApPaymentId], $fields));
        } else {
            db_update('acc_qbo_bill_payment_map', $fields, 'ff_ap_payment_id = ?', [$ffApPaymentId]);
        }
    }

    private static function writeNonHttpSyncLog(int $ffApPaymentId, string $operation, string $statusCode, string $errorMessage): void
    {
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'bill_payment',
                'entity_id'       => $ffApPaymentId,
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
            error_log("BillPaymentPusher: writeNonHttpSyncLog failed for ff_ap_payment_id={$ffApPaymentId}: " . $e->getMessage());
        }
    }
}
