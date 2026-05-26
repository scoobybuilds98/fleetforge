<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoicePusher.php
 *
 * First FF → QBO invoice push class (S-QBO-11). Handles CREATE only;
 * UPDATE/VOID stubbed for S-QBO-12.
 *
 * Method-per-operation contract (D-QBO-3-2 + D-PUSHER-CONTRACT):
 *   create → pushCreate(int $entityId, ?array $payloadSnapshot = null): array
 *   update → pushUpdate stubbed; returns 'unsupported_in_session'
 *
 * Pipeline (pushImpl, 10 steps):
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
 * @decision D-QBO-11-1 (queued path; sync_mode gate),
 *           D-QBO-11-2 (tax-override at line + header),
 *           D-QBO-11-3 (FX rate via exchange_rate_to_cad),
 *           D-QBO-11-4 (UPDATE stubbed for S-QBO-12),
 *           D-QBO-11-5 (engine-version dispatch),
 *           D-QBO-11-6 (PrivateNote JSON audit),
 *           D-QBO-11-7 (customer mapping gate),
 *           D-QBO-11-8 (item mapping gate),
 *           D-QBO-11-9 (voided-invoice skip),
 *           D-QBO-11-10 (D12 immutability + post-push freeze),
 *           D-QBO-FIXPACK-1 (always emit CurrencyRef),
 *           D-QBO-FIXPACK-2 (always emit ExchangeRate; throw on non-CAD missing rate),
 *           D-QBO-FIXPACK-5 (typed preflight status codes)
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
     * Update path stubbed per D-QBO-11-4. Deferred to S-QBO-12.
     * Returns success=false with status='unsupported_in_session' so the
     * worker dead-letters the queue row rather than retrying forever.
     */
    public static function pushUpdate(int $ffInvoiceId, ?array $payloadSnapshot = null): array
    {
        return [
            'success' => false,
            'status'  => 'unsupported_in_session',
            'error'   => 'InvoicePusher::pushUpdate deferred to S-QBO-12 (Invoice Modification & Void Semantics).',
        ] + self::RESULT_BASE;
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
            return ['success' => true, 'status' => 'skipped_by_mode', 'mode' => $mode] + self::RESULT_BASE;
        }

        // 2. Load FF invoice.
        $invoice = db_row("SELECT * FROM invoices WHERE id = ?", [$ffInvoiceId]);
        if ($invoice === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'error'   => "FF invoice {$ffInvoiceId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Voided check per D-QBO-11-9. K-22: status enum value is
        //    'void' (NOT 'voided' as prompt drafted).
        if ($invoice['status'] === 'void') {
            self::recordSkipped($ffInvoiceId, $invoice, 'skipped_voided');
            return ['success' => true, 'status' => 'skipped_voided'] + self::RESULT_BASE;
        }

        // 4. Soft-deleted check.
        if (($invoice['deleted_at'] ?? null) !== null) {
            return ['success' => true, 'status' => 'skipped_soft_deleted'] + self::RESULT_BASE;
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
                'qbo_id'  => (string) $mapping['qbo_invoice_id'],
            ] + self::RESULT_BASE;
        }

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
                'error'   => "Payload build failed: " . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 9. HTTP call.
        $client = new QuickBooksClient();
        try {
            $response = $client->createEntity('invoice', $qboPayload);
        } catch (QuickBooksException $e) {
            $httpCode = method_exists($e, 'getHttpStatus') ? (int) $e->getHttpStatus() : 0;
            self::recordPushFailure($ffInvoiceId, $invoice, $e->getMessage(), $httpCode);
            return [
                'success'    => false,
                'status'     => 'qbo_error',
                'error'      => $e->getMessage(),
                'http_code'  => $httpCode,
                'error_code' => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : null,
            ] + self::RESULT_BASE;
        }

        $createdInvoice = $response['Invoice'] ?? null;
        if (!is_array($createdInvoice) || empty($createdInvoice['Id'])) {
            self::recordPushFailure($ffInvoiceId, $invoice, 'QBO response missing Invoice.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'error'   => 'QBO response missing Invoice.Id',
            ] + self::RESULT_BASE;
        }

        // 10. Persist mapping with success state. SyncToken pinned per D-QBO-11-4.
        self::recordSuccessfulPush($ffInvoiceId, $invoice, $createdInvoice, $mapping);

        return [
            'success'    => true,
            'status'     => 'created',
            'qbo_id'     => (string) $createdInvoice['Id'],
            'sync_token' => (string) ($createdInvoice['SyncToken'] ?? '0'),
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
            'TxnDate'      => (string) $invoice['invoice_date'],
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
     * Record skipped state (voided or by mode).
     */
    private static function recordSkipped(int $ffInvoiceId, array $ffInvoice, string $skippedStatus): void
    {
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
    }
}
