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
 * @decision D-QBO-11-1 (queued path; sync_mode gate),
 *           D-QBO-11-2 (tax-override at line + header),
 *           D-QBO-11-3 (FX rate via exchange_rate_to_cad),
 *           D-QBO-11-4 (UPDATE stubbed for S-QBO-12),
 *           D-QBO-11-5 (engine-version dispatch),
 *           D-QBO-11-6 (PrivateNote JSON audit),
 *           D-QBO-11-7 (customer mapping gate),
 *           D-QBO-11-8 (item mapping gate),
 *           D-QBO-11-9 (voided-invoice skip),
 *           D-QBO-11-10 (D12 immutability + post-push freeze)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class InvoicePusher
{
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
        ];
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
            return ['success' => true, 'status' => 'skipped_by_mode', 'mode' => $mode];
        }

        // 2. Load FF invoice.
        $invoice = db_row("SELECT * FROM invoices WHERE id = ?", [$ffInvoiceId]);
        if ($invoice === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'error'   => "FF invoice {$ffInvoiceId} not found",
            ];
        }

        // 3. Voided check per D-QBO-11-9. K-22: status enum value is
        //    'void' (NOT 'voided' as prompt drafted).
        if ($invoice['status'] === 'void') {
            self::recordSkipped($ffInvoiceId, $invoice, 'skipped_voided');
            return ['success' => true, 'status' => 'skipped_voided'];
        }

        // 4. Soft-deleted check.
        if (($invoice['deleted_at'] ?? null) !== null) {
            return ['success' => true, 'status' => 'skipped_soft_deleted'];
        }

        // 5. Existing mapping check (idempotency on CREATE per D-QBO-6-2 pattern).
        // Short-circuit BEFORE pre-flight so already-pushed invoices don't
        // re-evaluate the gates on every retry — they're done.
        $mapping = db_row(
            "SELECT id, qbo_invoice_id, qbo_sync_token, push_status
               FROM acc_qbo_invoice_map
              WHERE ff_invoice_id = ?",
            [$ffInvoiceId]
        );
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_invoice_id'])) {
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'qbo_id'  => (string) $mapping['qbo_invoice_id'],
            ];
        }

        // 6. Pre-flight gates (5 sub-checks via InvoicePreflightGate).
        $gate = InvoicePreflightGate::check($ffInvoiceId);
        if (!$gate['ok']) {
            self::recordFailedPreflight($ffInvoiceId, $invoice, (string) $gate['reason']);
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'error'   => $gate['reason'],
            ];
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
                'error'   => "Payload build failed: " . $e->getMessage(),
            ];
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
            ];
        }

        $createdInvoice = $response['Invoice'] ?? null;
        if (!is_array($createdInvoice) || empty($createdInvoice['Id'])) {
            self::recordPushFailure($ffInvoiceId, $invoice, 'QBO response missing Invoice.Id', 0);
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'error'   => 'QBO response missing Invoice.Id',
            ];
        }

        // 10. Persist mapping with success state. SyncToken pinned per D-QBO-11-4.
        self::recordSuccessfulPush($ffInvoiceId, $invoice, $createdInvoice, $mapping);

        return [
            'success'    => true,
            'status'     => 'created',
            'qbo_id'     => (string) $createdInvoice['Id'],
            'sync_token' => (string) ($createdInvoice['SyncToken'] ?? '0'),
        ];
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

        // FX handling per D-QBO-11-3. K-22: column is exchange_rate_to_cad
        // (NOT fx_rate as prompt drafted). Direction: rate from
        // invoice.currency to home (CAD) — matches QBO Canada home-currency
        // convention. CAD invoices: omit CurrencyRef so QBO defaults home.
        $currency = strtoupper((string) ($invoice['currency'] ?? 'CAD'));
        if ($currency !== 'CAD' && $currency !== '') {
            $payload['CurrencyRef'] = ['value' => $currency];
            $fxRate = (float) ($invoice['exchange_rate_to_cad'] ?? 0);
            if ($fxRate <= 0) {
                $fxRateSetting = (string) settings_get("quickbooks.default_fx_rate_{$currency}_cad", '');
                $fxRate = $fxRateSetting !== '' ? (float) $fxRateSetting : 1.0;
                error_log(
                    "[S-QBO-11] Invoice " . ($invoice['id'] ?? '?') . " has no exchange_rate_to_cad "
                    . "for currency={$currency}; defaulting to {$fxRate}. Operator should pin "
                    . "settings.quickbooks.default_fx_rate_{$currency}_cad to avoid surprise."
                );
            }
            $payload['ExchangeRate'] = (string) $fxRate;
        }

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
     */
    private static function recordFailedPreflight(int $ffInvoiceId, array $ffInvoice, string $reason): void
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
                'push_status'       => 'failed_preflight',
                'push_error'        => $reason,
                'last_synced_at'    => $now,
            ]);
        } else {
            db_execute(
                "UPDATE acc_qbo_invoice_map
                    SET push_status = 'failed_preflight',
                        push_error  = ?,
                        last_synced_at = ?
                  WHERE id = ?",
                [$reason, $now, (int) $existing['id']]
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
