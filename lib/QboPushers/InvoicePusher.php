<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoicePusher.php
 *
 * First FF → QBO invoice push class (S-QBO-11). Handles CREATE + UPDATE
 * via shared pushImpl pipeline; VOID via separate pushVoidImpl (S-QBO-12).
 *
 * Method-per-operation contract (D-QBO-3-2 + D-PUSHER-CONTRACT):
 *   create → pushCreate(int $entityId, ?array $payloadSnapshot = null): array
 *   update → pushUpdate(int $entityId, ?array $payloadSnapshot = null): array  (S-QBO-12)
 *   void   → pushVoid(int $entityId): array                                    (S-QBO-12)
 *
 * Pipeline (pushImpl handles create + update, 10 steps):
 *   1. Sync mode gate (D-QBO-11-1)
 *   2. Load FF invoice (404 → fail with ff_not_found)
 *   3. Voided check (status='void' per FF status ENUM; D-QBO-11-9 skip)
 *   4. Soft-deleted check (deleted_at non-null → skip)
 *   5. Pre-flight gate via InvoicePreflightGate (5 sub-checks; records
 *      failure to acc_qbo_invoice_map on fail)
 *   6. Idempotency check (existing qbo_invoice_id → 'already_mapped')
 *   7. Load customer + lines + customer mapping
 *   8. Build QBO Invoice payload (try/catch; payload failures recorded)
 *   9. HTTP createEntity('invoice', $payload) via QuickBooksClient
 *  10. Persist mapping with success state + return
 *
 * K-22 silent resolutions per [[feedback_trust_file_over_prompt]] +
 * S-QBO-11 pre-flight AskUserQuestion:
 *   - invoices.status='void' (NOT 'voided')
 *   - invoices.tax_gst_amount/tax_pst_amount/tax_hst_amount columns
 *   - invoices.exchange_rate_to_cad (NOT fx_rate)
 *   - invoice_line_items.sort_order, .amount
 *   - invoices.notes used for QBO CustomerMemo (no invoices.memo column)
 *   - invoices.engine_version DOES NOT exist → buildPrivateNoteJson emits
 *     'unknown' literal; D-QBO-11-5 recon-credit enforcement code-path
 *     preserved but never triggers
 *
 * @session  S-QBO-11
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher Contract),
 *           §17/§18 (tax-override architecture)
 * @updated  S-QBO-11-FIXPACK-1 — always emit CurrencyRef + ExchangeRate
 *           (D-QBO-FIXPACK-1/-2); throw on non-CAD missing rate rather than
 *           silently defaulting (closes silent CAD→USD coercion bug F2);
 *           pushImpl uses typed status_code from gate result (D-QBO-FIXPACK-5);
 *           recordFailedPreflight accepts $status param for typed statuses
 * @updated  S-QBO-FIXPACK-3 — CurrencyRef + ExchangeRate emission gated on
 *           quickbooks.multi_currency_enabled (D-QBO-FIXPACK-12). When '0':
 *           omit both fields (QBO error 6000 fires on single-currency companies
 *           if CurrencyRef is sent). When '1': emit as FIXPACK-1 specified.
 * @updated  S-QBO-PUSHER-CONTRACT-PAYDOWN — added private RESULT_BASE +
 *           applied §6.8 canonical 5-key return shape on every path
 *           (MEDIUM-C6 fix); backported D-QBO-FIXPACK-10 qbo_currency
 *           snapshot mismatch warning to already_mapped branch
 *           (MEDIUM-C9 fix; uses acc_qbo_invoice_map.qbo_currency column,
 *           no HTTP call needed).
 * @updated  S-QBO-12 — pushUpdate real implementation + pushVoid new
 *           method. pushImpl extended to handle 'update' operation:
 *           gate 5 (mapping check) demotes 'update' with no mapping
 *           to 'create' per §6.8; gate 9 branches on operation —
 *           createEntity vs updateEntity. pushVoidImpl is separate
 *           (status='void' invariant inverts gate 3; voidEntity HTTP
 *           call uses QuickBooksClient::voidEntity shipped in
 *           S-QBO-11-POSTVERIFY-FIXES). New recordVoid helper writes
 *           push_status='voided' (new ENUM value via migration
 *           202605270200_S-QBO-12.sql). api/v1/invoices/void.php +1
 *           enqueue call so FF void operator action propagates to QBO
 *           (pattern matches send.php enqueue).
 * @decision D-QBO-11-1 (queued path; sync_mode gate),
 *           D-QBO-11-2 (tax-override at line + header),
 *           D-QBO-11-3 (FX rate via exchange_rate_to_cad),
 *           D-QBO-11-4 (UPDATE stubbed — superseded by S-QBO-12 implementation),
 *           D-QBO-11-5 (engine-version dispatch),
 *           D-QBO-11-6 (PrivateNote JSON audit),
 *           D-QBO-11-7 (customer mapping gate),
 *           D-QBO-11-8 (item mapping gate),
 *           D-QBO-11-9 (voided-invoice skip for create/update path; opposite for void path),
 *           D-QBO-11-10 (D12 immutability + post-push freeze),
 *           D-QBO-FIXPACK-1 (always emit CurrencyRef),
 *           D-QBO-FIXPACK-2 (always emit ExchangeRate; throw on non-CAD missing rate),
 *           D-QBO-FIXPACK-5 (typed preflight status codes),
 *           D-QBO-12-1 (pushUpdate full payload re-send via updateEntity; no sparse-diff),
 *           D-QBO-12-2 (pushUpdate demote to create when mapping absent per §6.8),
 *           D-QBO-12-3 (pushVoid separate impl — status='void' invariant inverts gate 3),
 *           D-QBO-12-4 (pushVoid idempotent when push_status='voided' already),
 *           D-QBO-12-5 (FF-voided-but-never-pushed: skip silently with status='skipped_unmapped_void')
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class InvoicePusher
{
    /**
     * Canonical §6.8 Pusher return shape — ensures all 5 keys are
     * present on every return path. PHP + union operator fills absent
     * keys with null; left-hand values always win so method-specific
     * keys (http_code, error_code, mode) pass through unchanged.
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
     * Push a new FF invoice into QBO. Idempotent — if already mapped,
     * returns 'already_mapped' without re-POSTing.
     */
    public static function pushCreate(int $ffInvoiceId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($ffInvoiceId, 'create', $payloadSnapshot);
    }

    /**
     * Push an UPDATE to an existing QBO invoice (S-QBO-12).
     *
     * Delegates to the shared pushImpl pipeline with operation='update'.
     * Per §6.8 demotion rule (D-QBO-12-2): if no mapping exists,
     * pushImpl demotes to create automatically (re-dispatches as 'create').
     *
     * Full payload re-send via QuickBooksClient::updateEntity — not sparse
     * diff (D-QBO-12-1). FF is canonical (D-QBO-CORE-1); QBO accepts the
     * complete payload + current SyncToken and replaces.
     *
     * @return array  Canonical §6.8 5-key return shape; status='updated' on
     *                successful update, 'created' if demoted, plus skip /
     *                failure status codes inherited from pushImpl.
     */
    public static function pushUpdate(int $ffInvoiceId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($ffInvoiceId, 'update', $payloadSnapshot);
    }

    /**
     * Push a VOID to an existing QBO invoice (S-QBO-12).
     *
     * Separate pipeline from pushImpl (D-QBO-12-3): the void operation
     * inverts the gate 3 invariant — pushImpl requires status != 'void'
     * (skips voided invoices), pushVoidImpl requires status == 'void'
     * (rejects non-voided invoices). Payload is minimal (Id + SyncToken
     * + sparse=true); skips gates 4-6 (currency / item / field length)
     * because QBO void doesn't validate them.
     *
     * Idempotent: if mapping already shows push_status='voided', returns
     * success without HTTP call (D-QBO-12-4). If no mapping exists
     * (FF voided before ever pushing), returns success with status=
     * 'skipped_unmapped_void' — no QBO entity to void (D-QBO-12-5).
     *
     * @return array  Canonical §6.8 5-key return shape; status='voided'
     *                on successful void, 'already_voided' on idempotent
     *                replay, 'skipped_unmapped_void' for never-pushed
     *                invoices.
     */
    public static function pushVoid(int $ffInvoiceId): array
    {
        return self::pushVoidImpl($ffInvoiceId);
    }

    /**
     * Shared 10-step pipeline. Public entry points delegate here.
     *
     * @return array{success: bool, status?: string, qbo_id?: string, sync_token?: string, error?: string, ...}
     */
    private static function pushImpl(int $ffInvoiceId, string $operation, ?array $payloadSnapshot): array
    {
        // 1. Sync mode gate. Per-call check so operator flipping mode
        //    mid-queue gets the new behavior next dispatch.
        $mode = (string) settings_get('quickbooks.sync_mode.invoice', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            // S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE: previously returned
            // success=true with no side effects → worker false-completed.
            // Now records the skip in both map row + sync_log.
            // Invoice load before recordSkipped — needs ff_engine_version
            // for the map row insert path.
            $invoiceForSkip = db_row("SELECT * FROM invoices WHERE id = ?", [$ffInvoiceId]) ?? ['id' => $ffInvoiceId];
            self::recordSkipped($ffInvoiceId, $invoiceForSkip, 'skipped_by_mode', $operation);
            return ['success' => true, 'status' => 'skipped_by_mode', 'outcome' => 'skipped', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load FF invoice.
        $invoice = db_row("SELECT * FROM invoices WHERE id = ?", [$ffInvoiceId]);
        if ($invoice === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF invoice {$ffInvoiceId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Voided check per D-QBO-11-9. K-22: status enum value is
        //    'void' (NOT 'voided' as prompt drafted).
        if ($invoice['status'] === 'void') {
            self::recordSkipped($ffInvoiceId, $invoice, 'skipped_voided', $operation);
            return ['success' => true, 'status' => 'skipped_voided', 'outcome' => 'skipped'] + self::RESULT_BASE;
        }

        // 4. Soft-deleted check.
        // S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE: previously returned
        // success=true with no side effects → worker false-completed.
        // Now records the skip in both map row + sync_log.
        if (($invoice['deleted_at'] ?? null) !== null) {
            self::recordSkipped($ffInvoiceId, $invoice, 'skipped_soft_deleted', $operation);
            return ['success' => true, 'status' => 'skipped_soft_deleted', 'outcome' => 'skipped'] + self::RESULT_BASE;
        }

        // 5. Existing mapping check (idempotency on CREATE per D-QBO-6-2 pattern).
        // Short-circuit BEFORE pre-flight so already-pushed invoices don't
        // re-evaluate the gates on every retry — they're done.
        $mapping = db_row(
            "SELECT id, qbo_invoice_id, qbo_sync_token, push_status, qbo_currency
               FROM acc_qbo_invoice_map
              WHERE ff_invoice_id = ?",
            [$ffInvoiceId]
        );
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_invoice_id'])) {
            // D-QBO-FIXPACK-10 backport: compare FF invoice currency against
            // qbo_currency snapshot (no HTTP needed — snapshotted at push time by
            // recordSuccessfulPush). Best-effort: any error is caught silently so
            // the idempotency no-op is never blocked by the advisory check.
            try {
                $ffCurrency  = strtoupper((string) ($invoice['currency'] ?? 'CAD'));
                $qboCurrency = strtoupper((string) ($mapping['qbo_currency'] ?? ''));
                if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                    error_log(
                        "S-QBO-FIXPACK-10 WARNING: FF invoice {$ffInvoiceId} currency='{$ffCurrency}' " .
                        "but mapped QBO invoice #{$mapping['qbo_invoice_id']} has qbo_currency='{$qboCurrency}'. " .
                        "Currency drift detected. Operator action: void the QBO invoice, unlink the FF invoice " .
                        "mapping (set qbo_invoice_id=NULL), then re-push."
                    );
                }
            } catch (\Throwable $e) {
                error_log("S-QBO-FIXPACK-10: InvoicePusher currency-mismatch check failed for FF invoice {$ffInvoiceId}: " . $e->getMessage());
            }
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',  // replay no-op == created semantically
                'qbo_id'  => (string) $mapping['qbo_invoice_id'],
            ] + self::RESULT_BASE;
        }

        // S-QBO-12: 'update' operation demotion per §6.8 Pusher Contract +
        // D-QBO-12-2. If operation='update' but no existing mapping (or
        // mapping has no qbo_invoice_id — e.g. previous push failed at gate
        // 6 and stored a failed_preflight row with qbo_invoice_id=NULL),
        // demote to 'create' by re-dispatching. Avoids the §6.8 anti-pattern
        // of "UPDATE on entity QBO doesn't know about" which would 404.
        if ($operation === 'update' && ($mapping === null || empty($mapping['qbo_invoice_id']))) {
            return self::pushImpl($ffInvoiceId, 'create', $payloadSnapshot);
        }
        // For 'update' WITH mapping: fall through to gates 6-10. We re-evaluate
        // pre-flight every time (the FF state may have changed since the
        // initial push, e.g. customer mapping changed, item mapping changed,
        // currency mismatch surfaced). Then re-build payload and POST via
        // updateEntity at step 9.

        // 6. Pre-flight gates (via InvoicePreflightGate; see gate docblock for full list).
        // WHY: gate returns optional typed status_code (D-QBO-FIXPACK-5) so the
        // mapping row uses the most specific status available — operator sees
        // 'failed_preflight_field_too_long' vs the generic 'failed_preflight'.
        $gate = InvoicePreflightGate::check($ffInvoiceId);
        if (!$gate['ok']) {
            $typedStatus = (string) ($gate['status_code'] ?? 'failed_preflight');
            self::recordFailedPreflight($ffInvoiceId, $invoice, (string) $gate['reason'], $typedStatus);
            return [
                'success' => false,
                'status'  => $typedStatus,
                'outcome' => 'failed',
                'error'   => $gate['reason'],
            ] + self::RESULT_BASE;
        }

        // 7. Load related entities. Customer used for GPS variant; lines
        //    ordered by sort_order ASC (K-22: NOT line_order), id ASC tiebreak.
        $customer = db_row("SELECT * FROM customers WHERE id = ?", [(int) $invoice['customer_id']]);
        $lines = db_select(
            "SELECT * FROM invoice_line_items
              WHERE invoice_id = ?
              ORDER BY sort_order ASC, id ASC",
            [$ffInvoiceId]
        );
        $customerMap = db_row(
            "SELECT qbo_customer_id FROM acc_qbo_customer_map
              WHERE ff_customer_id = ? AND mapping_status = 'mapped'",
            [(int) $invoice['customer_id']]
        );

        // 8. Build QBO payload.
        try {
            $qboPayload = self::buildQboPayload($invoice, $customer ?: [], $lines, $customerMap ?: []);
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffInvoiceId, $invoice, "Payload build failed: " . $e->getMessage(), 0);
            return [
                'success' => false,
                'status'  => 'payload_build_failed',
                'outcome' => 'failed',
                'error'   => "Payload build failed: " . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 9. HTTP call. Branches on operation per S-QBO-12 (D-QBO-12-1):
        //    create → createEntity (POST /v3/company/{realm}/invoice)
        //    update → updateEntity (POST /v3/company/{realm}/invoice?operation=update
        //             with Id + SyncToken merged into payload)
        $client = new QuickBooksClient();
        try {
            if ($operation === 'update') {
                $response = $client->updateEntity(
                    'invoice',
                    (string) $mapping['qbo_invoice_id'],
                    (string) ($mapping['qbo_sync_token'] ?? '0'),
                    $qboPayload
                );
            } else {
                $response = $client->createEntity('invoice', $qboPayload);
            }
        } catch (QuickBooksException $e) {
            // QuickBooksException exposes httpStatus/errorCode as readonly PROPERTIES,
            // not methods — method_exists() is always false (drops the real status/code).
            $httpCode = $e->httpStatus ?? 0;
            self::recordPushFailure($ffInvoiceId, $invoice, $e->getMessage(), $httpCode);
            return [
                'success'    => false,
                'status'     => 'qbo_error',
                'outcome'    => 'failed',
                'error'      => $e->getMessage(),
                'http_code'  => $httpCode,
                'error_code' => $e->errorCode ?? null,
            ] + self::RESULT_BASE;
        }

        $qboInvoice = $response['Invoice'] ?? null;
        if (!is_array($qboInvoice) || empty($qboInvoice['Id'])) {
            self::recordPushFailure($ffInvoiceId, $invoice, 'QBO response missing Invoice.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing Invoice.Id',
            ] + self::RESULT_BASE;
        }

        // 10. Persist mapping with success state. SyncToken refreshed from QBO
        //     response (QBO increments on every update; D-QBO-11-10 freeze
        //     applies post-create but pushUpdate intentionally moves it).
        self::recordSuccessfulPush($ffInvoiceId, $invoice, $qboInvoice, $mapping);

        $returnStatus = $operation === 'update' ? 'updated' : 'created';

        return [
            'success'    => true,
            'status'     => $returnStatus,
            'outcome'    => $returnStatus,
            'qbo_id'     => (string) $qboInvoice['Id'],
            'sync_token' => (string) ($qboInvoice['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Build complete QBO Invoice payload from FF invoice + customer + lines.
     *
     * Public-static so the offline smoke can exercise it without going
     * through the cURL boundary — matches CustomerPusher::buildQboPayload
     * pattern from S-QBO-6.
     *
     * @throws QuickBooksException via InvoiceTaxOverride if tax_override_code_id unset, OR via InvoiceLineBuilder on item-mapping / engine-version violations
     */
    public static function buildQboPayload(array $invoice, array $customer, array $lines, array $customerMap): array
    {
        $payload = [
            'Line'         => InvoiceLineBuilder::build($invoice, $customer, $lines),
            'CustomerRef'  => ['value' => (string) ($customerMap['qbo_customer_id'] ?? '')],
            // D-QBO-DATING-1 (extends D-GL-REVREC-1): a QBO Invoice posts
            // Dr A/R / Cr Income on TxnDate, so TxnDate IS the QBO recognition
            // date. Derive it from the SAME future-guarded source as the internal
            // GL JE (AccountingService::recognitionDate) — never recomputed here —
            // so FF and QBO recognize in the same period and a future-dated
            // billing_period_start can never push a future TxnDate. For normal
            // current-period invoices this equals invoice_date (guard never fires).
            'TxnDate'      => \FleetForge\Accounting\AccountingService::recognitionDate((string) $invoice['invoice_date']),
            'DocNumber'    => (string) $invoice['invoice_number'],
            'TxnTaxDetail' => InvoiceTaxOverride::buildTxnTaxDetail($invoice),
            'PrivateNote'  => self::buildPrivateNoteJson($invoice),
        ];

        if (!empty($invoice['due_date'])) {
            $payload['DueDate'] = (string) $invoice['due_date'];
        }

        // CurrencyRef + ExchangeRate per D-QBO-FIXPACK-1 + D-QBO-FIXPACK-2,
        // gated on quickbooks.multi_currency_enabled per D-QBO-FIXPACK-12.
        //
        // WHY emit when multi-currency enabled (D-QBO-FIXPACK-1): Omitting
        // CurrencyRef causes QBO to default to the customer's currency, silently
        // corrupting the AR ledger when FF + QBO currencies differ.
        //
        // WHY omit when multi-currency disabled (D-QBO-FIXPACK-3): QBO returns
        // error 6000 ("Multi Currency should be enabled to perform this operation")
        // when CurrencyRef is sent to a single-currency company. Operator discovered
        // this live; CompanyInfoSync.syncFromQbo() detects and caches the flag.
        //
        // WHY also omit ExchangeRate when disabled: meaningless for single-currency
        // companies and may confuse QBO's validation logic.
        //
        // K-22: column is exchange_rate_to_cad (NOT fx_rate).
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($invoice['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';  // defensive; should not occur given ENUM constraint
            }

            // Emit CurrencyRef.
            $payload['CurrencyRef'] = ['value' => $currency];

            // Emit ExchangeRate.
            if ($currency === 'CAD') {
                $payload['ExchangeRate'] = '1.0';  // home currency; explicit for audit clarity
            } else {
                $fxRate = $invoice['exchange_rate_to_cad'] ?? null;
                if ($fxRate === null || (float) $fxRate <= 0) {
                    throw new QuickBooksException(
                        "Non-CAD invoice " . ($invoice['id'] ?? '?') . " ({$currency}) has no "
                        . "exchange_rate_to_cad or rate is ≤ 0 (value=" . json_encode($fxRate) . "). "
                        . "Cannot push without an explicit exchange rate — doing so would silently "
                        . "corrupt QBO FX accounting. Populate invoices.exchange_rate_to_cad before retrying."
                    );
                }
                $payload['ExchangeRate'] = (string) $fxRate;
            }
        }
        // When multi_currency_enabled='0': omit CurrencyRef + ExchangeRate entirely
        // (QBO error 6000 would fire on single-currency companies otherwise).

        // Customer-facing memo. K-22: no invoices.memo column — use
        // invoices.notes (customer-visible) NOT internal_notes.
        if (!empty($invoice['notes'])) {
            $payload['CustomerMemo'] = ['value' => (string) $invoice['notes']];
        }

        return $payload;
    }

    /**
     * Build PrivateNote JSON per D-QBO-11-6. Pushed to QBO Invoice.PrivateNote
     * (accountant-only visibility). All numeric values as strings to
     * preserve bcmath precision across JSON encode boundary.
     *
     * Audit block omitted when all 3 audit columns are NULL/missing
     * (graceful fallback). K-22: invoices.engine_version DOES NOT exist
     * → emit 'unknown' literal.
     */
    public static function buildPrivateNoteJson(array $invoice): string
    {
        $note = [
            'ff_invoice_id'     => (int) $invoice['id'],
            'ff_invoice_number' => (string) $invoice['invoice_number'],
            'engine_version'    => (string) ($invoice['engine_version'] ?? 'unknown'),
            'ff_tax_breakdown'  => [
                'gst' => (string) ($invoice['tax_gst_amount'] ?? '0'),
                'pst' => (string) ($invoice['tax_pst_amount'] ?? '0'),
                'hst' => (string) ($invoice['tax_hst_amount'] ?? '0'),
            ],
            'pushed_at' => date('c'),
        ];

        $audit = [];
        if (isset($invoice['total_days_at_period_end']) && $invoice['total_days_at_period_end'] !== null) {
            $audit['total_days_at_period_end'] = (int) $invoice['total_days_at_period_end'];
        }
        if (isset($invoice['cumulative_correct_amount']) && $invoice['cumulative_correct_amount'] !== null) {
            $audit['cumulative_correct_amount'] = (string) $invoice['cumulative_correct_amount'];
        }
        if (isset($invoice['already_billed_before_this']) && $invoice['already_billed_before_this'] !== null) {
            $audit['already_billed_before_this'] = (string) $invoice['already_billed_before_this'];
        }
        if (!empty($audit)) {
            $note['audit'] = $audit;
        }

        return json_encode($note, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Upsert mapping row with success state. Pinned per D-QBO-11-10
     * (post-push freeze; future modifications via pushUpdate only).
     */
    private static function recordSuccessfulPush(
        int $ffInvoiceId,
        array $ffInvoice,
        array $qboInvoice,
        ?array $existingMapping
    ): void {
        $now = date('Y-m-d H:i:s');
        $data = [
            'qbo_invoice_id'            => (string) $qboInvoice['Id'],
            'qbo_sync_token'            => (string) ($qboInvoice['SyncToken'] ?? '0'),
            'qbo_doc_number'            => (string) ($qboInvoice['DocNumber'] ?? ''),
            'qbo_total_amt'             => (string) ($qboInvoice['TotalAmt'] ?? '0'),
            'qbo_balance'               => (string) ($qboInvoice['Balance'] ?? '0'),
            'qbo_status'                => (string) ($qboInvoice['EmailStatus'] ?? ''),
            'qbo_currency'              => (string) ($qboInvoice['CurrencyRef']['value'] ?? ''),
            'qbo_exchange_rate'         => (float) ($qboInvoice['ExchangeRate'] ?? 1),
            'ff_invoice_snapshot_total' => (string) ($ffInvoice['total_amount'] ?? '0'),
            'ff_engine_version'         => (string) ($ffInvoice['engine_version'] ?? 'unknown'),
            'push_status'               => 'pushed',
            'push_error'                => null,
            'pushed_at'                 => $now,
            'last_synced_at'            => $now,
        ];

        if ($existingMapping === null) {
            $data['ff_invoice_id'] = $ffInvoiceId;
            db_insert('acc_qbo_invoice_map', $data);
        } else {
            $setSql = [];
            $params = [];
            foreach ($data as $col => $val) {
                $setSql[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = (int) $existingMapping['id'];
            db_execute(
                "UPDATE acc_qbo_invoice_map SET " . implode(', ', $setSql) . " WHERE id = ?",
                $params
            );
        }
    }

    /**
     * Record push failure (QBO HTTP error or malformed response). Creates
     * mapping row if absent so the operator can see the failure state in
     * the admin UI.
     */
    private static function recordPushFailure(int $ffInvoiceId, array $ffInvoice, string $error, int $httpCode): void
    {
        $existing = db_row(
            "SELECT id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
            [$ffInvoiceId]
        );
        $now = date('Y-m-d H:i:s');
        $errorWithCode = $httpCode > 0 ? "HTTP {$httpCode}: {$error}" : $error;

        if ($existing === null) {
            db_insert('acc_qbo_invoice_map', [
                'ff_invoice_id'     => $ffInvoiceId,
                'ff_engine_version' => (string) ($ffInvoice['engine_version'] ?? 'unknown'),
                'push_status'       => 'failed',
                'push_error'        => $errorWithCode,
                'last_synced_at'    => $now,
            ]);
        } else {
            db_execute(
                "UPDATE acc_qbo_invoice_map
                    SET push_status = 'failed',
                        push_error  = ?,
                        last_synced_at = ?
                  WHERE id = ?",
                [$errorWithCode, $now, (int) $existing['id']]
            );
        }
    }

    /**
     * Record failed_preflight state. Distinct from push failure because
     * no HTTP call was attempted — operator remediation is mapping/setting
     * level, not retry-the-call.
     *
     * $status accepts typed preflight status codes (D-QBO-FIXPACK-5):
     *   'failed_preflight'                   — generic gate failure
     *   'failed_preflight_field_too_long'    — F3 field-length violation
     *   'failed_preflight_currency_mismatch' — F2 FF↔QBO currency mismatch
     */
    private static function recordFailedPreflight(
        int    $ffInvoiceId,
        array  $ffInvoice,
        string $reason,
        string $status = 'failed_preflight'
    ): void {
        $existing = db_row(
            "SELECT id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
            [$ffInvoiceId]
        );
        $now = date('Y-m-d H:i:s');

        if ($existing === null) {
            db_insert('acc_qbo_invoice_map', [
                'ff_invoice_id'     => $ffInvoiceId,
                'ff_engine_version' => (string) ($ffInvoice['engine_version'] ?? 'unknown'),
                'push_status'       => $status,
                'push_error'        => $reason,
                'last_synced_at'    => $now,
            ]);
        } else {
            db_execute(
                "UPDATE acc_qbo_invoice_map
                    SET push_status    = ?,
                        push_error     = ?,
                        last_synced_at = ?
                  WHERE id = ?",
                [$status, $reason, $now, (int) $existing['id']]
            );
        }
    }

    /**
     * Record skipped state. Extended in S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE
     * to also write an acc_qbo_sync_log row so the operator-facing show-page
     * Push History table + worker tally surface every dispatch attempt,
     * including the non-HTTP skip branches (skipped_by_mode +
     * skipped_soft_deleted) that previously left no trace.
     *
     * @param string $skippedStatus one of: skipped_voided | skipped_by_mode | skipped_soft_deleted
     * @param string $operation     the queue operation ('create' typically) — used for the sync_log row
     */
    private static function recordSkipped(int $ffInvoiceId, array $ffInvoice, string $skippedStatus, string $operation = 'create'): void
    {
        // ── FK guard ───────────────────────────────────────────────
        // recordSkipped can be invoked from the sync_mode gate (line 126-128)
        // which fires BEFORE the invoice load — so $ffInvoiceId may not
        // exist in `invoices`. Cases:
        //   - C40 smoke uses pushCreate(0) to exercise the early-gate
        //     return-shape invariant.
        //   - An orphan queue row pre-S-QBO-ENQUEUER-ELIGIBILITY-GATE
        //     could point at a hard-deleted invoice.
        // acc_qbo_invoice_map.ff_invoice_id has an FK to invoices(id);
        // a ghost id would trigger a constraint violation. Early-return
        // makes recordSkipped a no-op in that case. The Pusher's return
        // shape is unchanged (caller still receives outcome='skipped'),
        // so the worker tally + queue.status='skipped' write are preserved.
        $invoiceExists = db_row("SELECT 1 AS x FROM invoices WHERE id = ?", [$ffInvoiceId]) !== null;
        if (!$invoiceExists) {
            return;
        }

        // ── Map row write (existing behaviour generalized) ─────────
        $existing = db_row(
            "SELECT id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
            [$ffInvoiceId]
        );
        $now = date('Y-m-d H:i:s');

        if ($existing === null) {
            db_insert('acc_qbo_invoice_map', [
                'ff_invoice_id'     => $ffInvoiceId,
                'ff_engine_version' => (string) ($ffInvoice['engine_version'] ?? 'unknown'),
                'push_status'       => $skippedStatus,
                'last_synced_at'    => $now,
            ]);
        } else {
            db_execute(
                "UPDATE acc_qbo_invoice_map
                    SET push_status = ?,
                        last_synced_at = ?
                  WHERE id = ?",
                [$skippedStatus, $now, (int) $existing['id']]
            );
        }

        // ── Sync log row (NEW per D-SYNC-LOG-NON-HTTP-INVOICE-4) ───
        // Records the non-HTTP skip event so the show-page Push History
        // table reflects every dispatch attempt, not just real HTTP calls.
        // Best-effort: any error is logged + swallowed so a sync_log
        // failure never blocks the map row write or the worker tally.
        try {
            $messages = [
                'skipped_voided'       => 'Invoice voided in FF; skip recorded without QBO push attempt.',
                'skipped_by_mode'      => 'sync_mode.invoice refuses FF→QBO direction; skip recorded without QBO push attempt.',
                'skipped_soft_deleted' => 'Invoice soft-deleted in FF; skip recorded without QBO push attempt.',
            ];
            $msg = $messages[$skippedStatus] ?? "Skipped: {$skippedStatus}";

            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'invoice',
                'entity_id'       => $ffInvoiceId,
                'operation'       => $operation,
                'http_method'     => 'SKIP',  // sentinel: no real HTTP call made
                'endpoint'        => '',      // no endpoint exercised
                'response_status' => null,    // http_status_code per D-SYNC-LOG-NON-HTTP-INVOICE-4
                'error_code'      => $skippedStatus,  // typed status code (reuses error_code column; not an error semantically but the schema's typed-code channel)
                'error_message'   => $msg,
                'queue_id'        => QuickBooksClient::workerQueueId(),  // null when not under worker context (CLI/smoke)
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log(
                "InvoicePusher::recordSkipped sync_log insert failed for ff_invoice_id={$ffInvoiceId} "
                . "status={$skippedStatus}: " . $e->getMessage()
            );
        }
    }

    /**
     * Separate pipeline for VOID operation (S-QBO-12, D-QBO-12-3).
     *
     * Differs from pushImpl in 3 critical ways:
     *   1. Status invariant INVERTS — pushImpl rejects status='void' as
     *      skipped_voided; pushVoidImpl rejects status!='void' as
     *      void_status_mismatch.
     *   2. Skips gates 4-6 (currency / item / field length) — void payload
     *      is minimal (Id + SyncToken + sparse=true via QuickBooksClient::
     *      voidEntity); QBO's void operation doesn't validate the omitted
     *      fields.
     *   3. Idempotent on push_status='voided' (D-QBO-12-4) instead of on
     *      qbo_invoice_id presence (D-QBO-CORE-6 pattern). The void
     *      operation can be safely re-attempted on an already-voided QBO
     *      invoice; QBO returns the voided entity unchanged.
     *
     * Returns success=true status='skipped_unmapped_void' when no mapping
     * exists (D-QBO-12-5) — FF voided an invoice before ever pushing it;
     * no QBO entity to void. Doesn't write a mapping row for this case
     * (no QBO state to mirror).
     */
    private static function pushVoidImpl(int $ffInvoiceId): array
    {
        // 1. Sync mode gate (same as pushImpl step 1).
        $mode = (string) settings_get('quickbooks.sync_mode.invoice', 'sync');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $invoiceForSkip = db_row("SELECT * FROM invoices WHERE id = ?", [$ffInvoiceId]) ?? ['id' => $ffInvoiceId];
            self::recordSkipped($ffInvoiceId, $invoiceForSkip, 'skipped_by_mode', 'void');
            return [
                'success' => true,
                'status'  => 'skipped_by_mode',
                'outcome' => 'skipped',
                'mode'    => $mode,
            ] + self::RESULT_BASE;
        }

        // 2. Load FF invoice.
        $invoice = db_row("SELECT * FROM invoices WHERE id = ?", [$ffInvoiceId]);
        if ($invoice === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF invoice {$ffInvoiceId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. INVERTED status invariant (D-QBO-12-3): must be 'void'. The
        //    canonical FF→QBO void trigger is api/v1/invoices/void.php after
        //    the db_transaction commits — status is guaranteed 'void' at
        //    that point. This check catches programmatic mis-dispatch
        //    (manual CLI, dispatcher race, replay of a stale queue row that
        //    has since been un-voided — though FF has no un-void operation).
        if ($invoice['status'] !== 'void') {
            return [
                'success' => false,
                'status'  => 'void_status_mismatch',
                'outcome' => 'failed',
                'error'   => "Cannot push void for FF invoice {$ffInvoiceId}: status='{$invoice['status']}', expected 'void'",
            ] + self::RESULT_BASE;
        }

        // 4. Soft-deleted check (mirrors pushImpl step 4). An invoice that
        //    was voided + then soft-deleted shouldn't propagate the void
        //    either; treat as already-skipped lifecycle.
        if (($invoice['deleted_at'] ?? null) !== null) {
            self::recordSkipped($ffInvoiceId, $invoice, 'skipped_soft_deleted', 'void');
            return [
                'success' => true,
                'status'  => 'skipped_soft_deleted',
                'outcome' => 'skipped',
            ] + self::RESULT_BASE;
        }

        // 5. Mapping lookup. Void requires an existing QBO entity to void.
        $mapping = db_row(
            "SELECT id, qbo_invoice_id, qbo_sync_token, push_status
               FROM acc_qbo_invoice_map
              WHERE ff_invoice_id = ?",
            [$ffInvoiceId]
        );

        // 5a. No mapping → FF voided before ever pushing (D-QBO-12-5).
        //     No QBO entity to void. Skip silently with status='skipped_unmapped_void'.
        //     Don't INSERT a row for an invoice that never existed in QBO —
        //     less noise on the admin invoice-show page.
        if ($mapping === null || empty($mapping['qbo_invoice_id'])) {
            return [
                'success' => true,
                'status'  => 'skipped_unmapped_void',
                'outcome' => 'skipped',
            ] + self::RESULT_BASE;
        }

        // 5b. Already voided in QBO → idempotent no-op (D-QBO-12-4).
        //     QBO would accept a repeat void without error, but skipping
        //     here saves the HTTP round-trip + clarifies the worker tally.
        if ($mapping['push_status'] === 'voided') {
            return [
                'success'    => true,
                'status'     => 'already_voided',
                'outcome'    => 'voided',
                'qbo_id'     => (string) $mapping['qbo_invoice_id'],
                'sync_token' => (string) ($mapping['qbo_sync_token'] ?? '0'),
            ] + self::RESULT_BASE;
        }

        // 6. HTTP void call via QuickBooksClient::voidEntity (S-QBO-11-POSTVERIFY-FIXES).
        //    QBO endpoint: POST /v3/company/{realm}/invoice?operation=void
        //    Payload: { Id, SyncToken, sparse: true }
        //    Response: { Invoice: {... voided state ...} }
        $client = new QuickBooksClient();
        try {
            $response = $client->voidEntity(
                'invoice',
                (string) $mapping['qbo_invoice_id'],
                (string) ($mapping['qbo_sync_token'] ?? '0')
            );
        } catch (QuickBooksException $e) {
            // QuickBooksException exposes httpStatus/errorCode as readonly PROPERTIES,
            // not methods — method_exists() is always false (drops the real status/code).
            $httpCode = $e->httpStatus ?? 0;
            self::recordPushFailure($ffInvoiceId, $invoice, "Void failed: " . $e->getMessage(), $httpCode);
            return [
                'success'    => false,
                'status'     => 'qbo_error',
                'outcome'    => 'failed',
                'error'      => "Void failed: " . $e->getMessage(),
                'http_code'  => $httpCode,
                'error_code' => $e->errorCode ?? null,
            ] + self::RESULT_BASE;
        }

        $voidedInvoice = $response['Invoice'] ?? null;
        if (!is_array($voidedInvoice) || empty($voidedInvoice['Id'])) {
            self::recordPushFailure($ffInvoiceId, $invoice, 'QBO void response missing Invoice.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO void response missing Invoice.Id',
            ] + self::RESULT_BASE;
        }

        // 7. Persist voided state. sync_log row written by
        //    QuickBooksClient::dispatch — recordVoid only handles
        //    the FF-side mapping table.
        self::recordVoid($ffInvoiceId, $voidedInvoice, $mapping);

        return [
            'success'    => true,
            'status'     => 'voided',
            'outcome'    => 'voided',
            'qbo_id'     => (string) $voidedInvoice['Id'],
            'sync_token' => (string) ($voidedInvoice['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    /**
     * Update an existing mapping row to voided state (S-QBO-12).
     *
     * Always operates on an existing mapping — pushVoidImpl gate 5a
     * early-returns if no mapping exists. Refreshes the SyncToken from
     * QBO's void response (QBO increments on void) so any future
     * already_voided idempotent replay has the correct token (though
     * the replay path doesn't actually use it).
     */
    private static function recordVoid(int $ffInvoiceId, array $qboVoidedInvoice, array $existingMapping): void
    {
        $now = date('Y-m-d H:i:s');
        db_execute(
            "UPDATE acc_qbo_invoice_map
                SET push_status     = 'voided',
                    push_error      = NULL,
                    qbo_sync_token  = ?,
                    qbo_balance     = ?,
                    qbo_total_amt   = ?,
                    pushed_at       = ?,
                    last_synced_at  = ?
              WHERE id = ?",
            [
                (string) ($qboVoidedInvoice['SyncToken'] ?? '0'),
                (string) ($qboVoidedInvoice['Balance'] ?? '0'),
                (string) ($qboVoidedInvoice['TotalAmt'] ?? '0'),
                $now,
                $now,
                (int) $existingMapping['id'],
            ]
        );
    }
}
