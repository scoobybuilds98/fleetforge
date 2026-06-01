<?php
declare(strict_types=1);

/**
 * lib/QboPushers/BillPusher.php
 *
 * Phase QBO-8 / 1 of 2 (S-QBO-18) — first AP-direction Pusher.
 *
 * FF→QBO bill push on draft→approved transition (D-QBO-18-1). Mirrors
 * the InvoicePusher (S-QBO-11) §6.8 dispatcher contract with bill-
 * specific adjustments:
 *   - Trigger: api/v1/accounting/bills/approve.php fires enqueue after
 *     db_transaction commits (status flip draft→approved + JE post).
 *   - Account-based expense lines (DetailType=AccountBasedExpenseLineDetail)
 *     instead of invoice's sales-line shape (D-QBO-18-3). Each line names
 *     a QBO AccountRef via acc_qbo_account_map lookup for the FF
 *     account_id.
 *   - Tax-override pattern (D-QBO-18-2) same as S-QBO-11: every line
 *     carries TaxCodeRef='NON' + header TxnTaxDetail.TotalTax computed
 *     FF-side via bcmath. ITC tax-rate mapping (per QBO_SPEC §8.8
 *     example) is deferred to a follow-up session (S-QBO-BILL-ITC-TAX-
 *     RATE-MAPPING) because S-QBO-9 only maps tax codes, not rates;
 *     mapping rates would require new tooling out of S-QBO-18 scope.
 *   - DocNumber = vendor_bill_number (when set) else FF bill_number
 *     (D-QBO-18-7). QBO Bill.DocNumber represents the vendor's
 *     reference number, not FF's internal sequence.
 *   - pushUpdate IMPLEMENTED in S-QBO-BILL-UPDATE (closes the D-QBO-18-5
 *     stub): routes through pushImpl with operation='update' — full-payload
 *     re-send via QuickBooksClient::updateEntity + SyncToken round-trip
 *     (mirrors InvoicePusher D-QBO-12-1), demote-to-create when unmapped.
 *   - pushVoid absent in v1 — voided bills before push hit
 *     skipped_unmapped_void (D-QBO-18-4); voided bills after push need
 *     pushVoid implementation in a future pushVoid follow-up slice
 *     (see OPERATOR_FOLLOWUPS F7).
 *   - No engine-version dispatch (bills don't have the holistic-vs-
 *     period_independent split that invoices have).
 *   - No customer mismatch preflight (bills push to vendor; vendor
 *     currency handling via the CurrencyRef gate per D-QBO-FIXPACK-12).
 *
 * @session  S-QBO-18
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher Contract),
 *           §7.6-style acc_qbo_bill_map shape, §8.8 (Bill payload),
 *           §8.13 (Tax-override pattern per D-QBO-CORE-6)
 * @decision D-QBO-18-1 (trigger: draft→approved via approve.php),
 *           D-QBO-18-2 (tax-override per D-QBO-CORE-6; ITC tax-rate
 *               mapping deferred),
 *           D-QBO-18-3 (AccountBasedExpenseLineDetail per line),
 *           D-QBO-18-4 (skipped_voided when bill.status='void'),
 *           D-QBO-18-5 (pushUpdate stub — CLOSED by S-QBO-BILL-UPDATE /
 *               D-QBO-BILL-UPDATE-1),
 *           D-QBO-18-6 (ff_bill_id NOT NULL — bills FF-origin only),
 *           D-QBO-18-7 (DocNumber = vendor_bill_number ?? bill_number),
 *           D-QBO-FIXPACK-6 (class-of-entity CurrencyRef principle),
 *           D-QBO-FIXPACK-12 (CurrencyRef emission gated on
 *               multi_currency_enabled),
 *           D-QBO-FIXPACK-10 (idempotency-replay mismatch warning),
 *           D-PUSHER-DEMOTION-RULE (update→create demotion — ACTIVE since
 *               S-QBO-BILL-UPDATE: pushImpl step 5b demotes an 'update' of
 *               an unmapped bill to 'create'),
 *           D-PUSHER-OUTCOME-FIELD-UNIVERSAL-2 (outcome field on
 *               every return path)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class BillPusher
{
    /**
     * §6.8 canonical return shape — merged onto every return path so
     * downstream consumers (worker tally, smoke assertions) get a
     * predictable key set. Sparse return shapes were MEDIUM-C6 finding
     * from S-QBO-PHASE-2-CONTRACT-AUDIT closed by RESULT_BASE pattern
     * in S-QBO-PUSHER-CONTRACT-PAYDOWN; mirror that pattern here.
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
     * Push a new FF bill into QBO. Idempotent — if the bill is already
     * mapped (has a qbo_bill_id), returns ['status' => 'already_mapped']
     * without re-POSTing.
     */
    public static function pushCreate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'create', $payloadSnapshot);
    }

    /**
     * Push an UPDATE to an existing QBO bill (S-QBO-BILL-UPDATE, closing
     * the D-QBO-18-5 stub). Full-payload re-send via
     * QuickBooksClient::updateEntity — not a sparse diff (mirrors
     * InvoicePusher D-QBO-12-1). FF is canonical (D-QBO-CORE-1); QBO
     * accepts the complete payload + current SyncToken and replaces.
     *
     * Demotion (D-PUSHER-DEMOTION-RULE): if the bill was never pushed
     * (no map row / null qbo_bill_id), pushImpl auto-demotes to 'create'
     * and re-POSTs — an update of an unmapped entity is just a first push.
     *
     * @return array  §6.8 5-key return shape; status='pushed' on a
     *                successful update (the acc_qbo_bill_map.push_status
     *                ENUM has no 'updated' value, so we reuse 'pushed'),
     *                'created' if demoted, plus the skip / failure status
     *                codes inherited from pushImpl.
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
    private static function pushImpl(int $ffBillId, string $operation, ?array $payloadSnapshot): array
    {
        // 1. Sync mode gate — per-call check so an operator flipping the
        //    mode mid-queue gets the new behavior on the next dispatch.
        $mode = (string) settings_get('quickbooks.sync_mode.bill', 'queue');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $ffForSkip = db_row("SELECT id, status FROM acc_bills WHERE id = ?", [$ffBillId]) ?? ['id' => $ffBillId, 'status' => null];
            self::recordSkipped($ffBillId, $ffForSkip, 'skipped_by_mode', $operation);
            return [
                'success' => true,
                'status'  => 'skipped_by_mode',
                'outcome' => 'skipped',
                'error'   => "sync_mode.bill={$mode}",
            ] + self::RESULT_BASE;
        }

        // 2. Load FF bill state. Selects columns this Pusher actually
        //    maps to QBO. Bills don't have deleted_at — they use
        //    status='void' instead. Per D-QBO-18-4, status='void' bills
        //    are skipped (FF voids don't auto-propagate to QBO; the
        //    pushVoid path in S-QBO-19 handles post-push voiding).
        $ff = db_row(
            "SELECT id, bill_number, vendor_bill_number, vendor_id, bill_date, due_date,
                    status, currency, exchange_rate_to_cad, subtotal,
                    tax_gst_amount, tax_pst_amount, tax_hst_amount, tax_total, total_amount,
                    balance_due, notes, updated_at
               FROM acc_bills
              WHERE id = ?",
            [$ffBillId]
        );
        if ($ff === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF bill {$ffBillId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Look up existing mapping. May be NULL (first push), or may
        //    exist from a prior push.
        $mapping = db_row(
            "SELECT id, qbo_bill_id, qbo_sync_token, qbo_currency, push_status
               FROM acc_qbo_bill_map
              WHERE ff_bill_id = ?",
            [$ffBillId]
        );

        // 4. Voided-bill skip per D-QBO-18-4. Two sub-cases:
        //    (a) status='void' AND no mapping row → skipped_unmapped_void
        //        (FF voided before first push; nothing in QBO to void;
        //         no map row written; mirrors S-QBO-12 D-QBO-12-5).
        //    (b) status='void' AND mapping exists with qbo_bill_id →
        //        skipped_voided (post-push void; pushVoid in S-QBO-19
        //        handles the QBO-side void; for v1 just record the skip).
        if ($ff['status'] === 'void') {
            if ($mapping === null || empty($mapping['qbo_bill_id'])) {
                // No map row written — silent skip mirroring
                // InvoicePusher::pushImpl for skipped_unmapped_void.
                return [
                    'success' => true,
                    'status'  => 'skipped_unmapped_void',
                    'outcome' => 'skipped',
                ] + self::RESULT_BASE;
            }
            self::recordSkipped($ffBillId, $ff, 'skipped_voided', $operation);
            return [
                'success' => true,
                'status'  => 'skipped_voided',
                'outcome' => 'skipped',
                'qbo_id'  => (string) $mapping['qbo_bill_id'],
            ] + self::RESULT_BASE;
        }

        // 5. Idempotency on CREATE (mirror of D-QBO-6-2). If we already
        //    have a qbo_bill_id, MUST NOT re-POST. Treat as no-op.
        //    D-QBO-FIXPACK-10: surface mismatch warning on already_mapped.
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_bill_id'])) {
            // Currency-mismatch advisory probe. Uses snapshot column to
            // avoid an HTTP call on every replay (matches the FIXPACK-10
            // pattern backported to InvoicePusher via S-QBO-PUSHER-
            // CONTRACT-PAYDOWN MEDIUM-C9 fix). Compare against FF
            // bill.currency rather than vendor.currency: the bill carries
            // its own currency field, which is the authoritative push
            // value (D-QBO-FIXPACK-12 gates emission separately).
            $expectedCurrency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            $snapshotCurrency = strtoupper((string) ($mapping['qbo_currency'] ?? ''));
            if ($snapshotCurrency !== '' && $snapshotCurrency !== $expectedCurrency) {
                error_log(
                    "S-QBO-FIXPACK-10 WARNING: FF bill {$ff['id']} expected CurrencyRef='{$expectedCurrency}' " .
                    "but mapped QBO bill #{$mapping['qbo_bill_id']} snapshot is '{$snapshotCurrency}'. " .
                    "Mapping is currency-poisoned (Intuit forbids QBO bill currency change after create). " .
                    "Operator action: investigate the bill mapping; may need unlink + re-push."
                );
            }
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',
                'qbo_id'  => (string) $mapping['qbo_bill_id'],
            ] + self::RESULT_BASE;
        }

        // 5b. Update demotion (D-PUSHER-DEMOTION-RULE, mirrors InvoicePusher
        //     D-QBO-12-1). An 'update' of a bill that was never pushed (no
        //     map row OR null qbo_bill_id) is just a first push — demote to
        //     'create' so the HTTP dispatch in step 9 calls createEntity.
        if ($operation === 'update' && ($mapping === null || empty($mapping['qbo_bill_id']))) {
            $operation = 'create';
        }

        // 6. Status must be approved-or-later to push. Bills in draft
        //    state should not push (they're not yet committed accounting
        //    documents). approve.php is the canonical fire point.
        if ($ff['status'] === 'draft') {
            self::recordFailedPreflight($ffBillId, "Bill {$ff['bill_number']} is in draft status; push fires on draft→approved transition. Approve the bill first.");
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => "bill status='draft' — push requires approved-or-later",
            ] + self::RESULT_BASE;
        }

        // 7. Pre-flight gates — vendor mapping + chart-of-accounts +
        //    per-line account mapping + tax-override target + connection
        //    status + field length + currency-mismatch (S-QBO-BILL-GOTCHAS).
        //
        // Return shape: null on pass; string (legacy) or
        // array{reason: string, status_code: string} for typed states
        // (D-QBO-BILL-GOTCHAS-1/-2). Map both shapes to the same return
        // contract here so all 8 gates funnel through one branch.
        $preflightError = self::runPreflight($ff);
        if ($preflightError !== null) {
            if (is_array($preflightError)) {
                $reason     = (string) ($preflightError['reason'] ?? 'preflight failed');
                $statusCode = (string) ($preflightError['status_code'] ?? 'failed_preflight');
            } else {
                $reason     = (string) $preflightError;
                $statusCode = 'failed_preflight';
            }
            // recordFailedPreflight always writes push_status='failed_preflight'
            // — the typed sub-state is recorded via a separate helper when
            // the status_code is more specific.
            if ($statusCode !== 'failed_preflight') {
                self::recordTypedPreflight($ffBillId, $statusCode, $reason);
            } else {
                self::recordFailedPreflight($ffBillId, $reason);
            }
            return [
                'success' => false,
                'status'  => $statusCode,
                'outcome' => 'failed',
                'error'   => $reason,
            ] + self::RESULT_BASE;
        }

        // 8. Build payload from FF row + line items.
        try {
            $qboPayload = self::buildQboPayload($ff);
        } catch (QuickBooksException $e) {
            self::recordFailedPreflight($ffBillId, 'Payload build failed: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build failed: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        } catch (\Throwable $e) {
            self::recordFailedPreflight($ffBillId, 'Payload build unexpected error: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build unexpected error: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 9. HTTP call via QuickBooksClient. createEntity for a new bill,
        //    updateEntity for an existing one (full-payload re-send per
        //    D-QBO-12-1; updateEntity merges Id + SyncToken into the
        //    payload). The client handles auth, retries, Sentry capture,
        //    and sync_log writes per S-QBO-2 — Pusher consumes the parsed
        //    response. SyncToken comes from the mapping row (refreshed on
        //    every push). Demotion in step 5b guarantees $mapping has a
        //    qbo_bill_id whenever $operation is still 'update' here.
        $client = new QuickBooksClient();
        try {
            if ($operation === 'update') {
                $response = $client->updateEntity(
                    'bill',
                    (string) $mapping['qbo_bill_id'],
                    (string) ($mapping['qbo_sync_token'] ?? '0'),
                    $qboPayload
                );
            } else {
                $response = $client->createEntity('bill', $qboPayload);
            }
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffBillId, $e->getMessage());
            return [
                'success' => false,
                'status'  => 'qbo_error',
                'outcome' => 'failed',
                'error'   => $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 10. Parse response + persist mapping. QBO returns the created
        //     Bill entity under 'Bill' key.
        $qboBill = $response['Bill'] ?? null;
        if (!is_array($qboBill) || empty($qboBill['Id'])) {
            self::recordPushFailure($ffBillId, 'QBO response missing Bill.Id');
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing Bill.Id',
            ] + self::RESULT_BASE;
        }

        self::recordSuccessfulPush($ffBillId, $ff, $qboBill);

        return [
            'success'    => true,
            'status'     => 'pushed',
            // acc_qbo_bill_map.push_status ENUM has no 'updated' value — the
            // map row stays 'pushed' for both create + update. The transient
            // outcome field distinguishes them for the worker tally / smoke.
            'outcome'    => $operation === 'update' ? 'updated' : 'created',
            'qbo_id'     => (string) $qboBill['Id'],
            'sync_token' => (string) ($qboBill['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    // ────────────────────────────────────────────────────────────────────
    // Pre-flight gates
    // ────────────────────────────────────────────────────────────────────

    /**
     * Inline pre-flight gates for bill push. Returns NULL on pass, or
     * an actionable error string on first failing gate. Order is most-
     * likely-to-fail first to fail fast.
     *
     * Gates:
     *   1. Vendor mapping — acc_qbo_vendor_map.mapping_status='mapped'
     *      AND qbo_vendor_id non-null for ff.vendor_id.
     *   2. AccountValidator::assertReadyForBillPush — ap_clearing FF
     *      account must be mapped (per D-QBO-VALIDATOR-3).
     *   3. Per-line expense account mapping — every bill_line.account_id
     *      must have an acc_qbo_account_map row with qbo_account_id
     *      non-null. Bills have no Item concept; each line specifies
     *      its own expense AccountRef.
     *   4. Bill has at least one line item.
     *   5. Tax-override target set — settings.quickbooks.tax_override_
     *      code_id non-empty (required for D-QBO-CORE-6 NON override
     *      pattern; auto-wired by S-QBO-9 TaxCodeMatcher).
     *   6. Connection healthy — settings.quickbooks.connection_status=
     *      'connected'. The HTTP call would fail anyway but preflight
     *      avoids the QBO call entirely (faster failure surface).
     *   7. Field-length: DocNumber ≤21 chars (QBO Bill.DocNumber limit
     *      per D-QBO-FIXPACK-4 QboFieldLimits constant).
     */
    /**
     * Inline pre-flight gates. Returns NULL on pass; legacy string on
     * generic-failed_preflight; array{reason,status_code} on typed
     * preflight failures (gates 7-8 currently — field-too-long +
     * currency-mismatch). Caller funnels both shapes through one branch.
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
            return "Vendor #{$ff['vendor_id']} is not mapped to QBO. Map via /quickbooks/vendors before pushing the bill.";
        }

        // Gate 2: AccountValidator — ap_clearing must be mapped.
        try {
            AccountValidator::assertReadyForBillPush();
        } catch (ChartOfAccountsIncompleteException $e) {
            return 'AccountValidator: ' . $e->getMessage();
        }

        // Gate 3: Per-line expense account mapping.
        $lines = db_select(
            "SELECT id, account_id, description, amount
               FROM acc_bill_lines
              WHERE bill_id = ?
              ORDER BY sort_order, id",
            [(int) $ff['id']]
        );

        // Gate 4: At least one line.
        if (empty($lines)) {
            return "Bill {$ff['bill_number']} has no line items. Add at least one expense line before pushing.";
        }

        foreach ($lines as $line) {
            $acctMap = db_row(
                "SELECT qbo_account_id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id = ?",
                [(int) $line['account_id']]
            );
            if ($acctMap === null || empty($acctMap['qbo_account_id']) || $acctMap['mapping_status'] !== 'mapped') {
                $acct = db_row("SELECT code, name FROM acc_accounts WHERE id = ?", [(int) $line['account_id']]);
                $acctLabel = $acct ? "{$acct['code']} {$acct['name']}" : "FF account #{$line['account_id']}";
                return "Bill line account is not mapped to QBO: {$acctLabel}. Map via /quickbooks/accounts before pushing.";
            }
        }

        // Gate 5: Tax-override target. Tax-override pattern (D-QBO-CORE-6)
        // sends FF-computed tax via TxnTaxDetail.TotalTax + each line
        // carries TaxCodeRef='NON'. Without the override target QBO
        // would recompute tax server-side and create drift.
        $overrideId = (string) settings_get('quickbooks.tax_override_code_id', '');
        if ($overrideId === '') {
            return "QBO tax-override target ('NON') not configured. Run a Pull on /quickbooks/tax_codes to auto-wire it (D-QBO-9-2).";
        }

        // Gate 6: Connection status.
        $connStatus = (string) settings_get('quickbooks.connection_status', '');
        if ($connStatus !== 'connected') {
            return "QBO connection is not connected (status={$connStatus}). Connect via /quickbooks/settings.";
        }

        // Gate 7: Field-length — DocNumber per D-QBO-FIXPACK-4 QboFieldLimits.
        // Status code returned: failed_preflight_field_too_long (D-QBO-BILL-
        // GOTCHAS-2, mirrors D-QBO-FIXPACK-5 for invoices).
        $docNumber = trim((string) ($ff['vendor_bill_number'] ?? ''));
        if ($docNumber === '') {
            $docNumber = (string) $ff['bill_number'];
        }
        if (strlen($docNumber) > QboFieldLimits::INVOICE_DOC_NUMBER_MAX) {
            $len = strlen($docNumber);
            $max = QboFieldLimits::INVOICE_DOC_NUMBER_MAX;
            return [
                'reason'      => "DocNumber '{$docNumber}' exceeds QBO Bill.DocNumber limit of {$max} characters (actual: {$len}). Shorten the vendor_bill_number or bill_number.",
                'status_code' => 'failed_preflight_field_too_long',
            ];
        }

        // Gate 8: Currency-mismatch (D-QBO-BILL-GOTCHAS-1, mirrors D-QBO-
        // FIXPACK-3 for invoices). When multi-currency is enabled and FF bill
        // currency differs from the mapped QBO vendor's currency, the push
        // would create a corrupted AP entry (QBO accepts the bill but the
        // vendor's AR/AP ledger ends up in the wrong currency). Catch
        // pre-flight so operator can re-map vendor or fix bill currency
        // BEFORE the wasted HTTP round-trip.
        //
        // SKIP when multi-currency disabled: BillPusher omits CurrencyRef
        // entirely in single-currency mode (D-QBO-FIXPACK-12), so QBO uses
        // its home currency — no mismatch is possible (the FF currency intent
        // is lost regardless; that's a separate concern that needs operator
        // awareness, not a per-bill gate).
        //
        // FALLTHROUGH: any QBO API transient error swallowed — pre-flight
        // should never block a push on a read-only connectivity issue.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            try {
                $client = new QuickBooksClient();
                $qboVendorResp = $client->getEntity('vendor', (string) $vendorMap['qbo_vendor_id']);
                $qboVendor = $qboVendorResp['Vendor'] ?? null;

                if ($qboVendor === null) {
                    // Mapping points to a QBO vendor that no longer exists.
                    return [
                        'reason'      => "QBO vendor (id={$vendorMap['qbo_vendor_id']}) not found in sandbox/production; "
                                       . "the mapping may be stale. Re-pull vendors via /quickbooks/vendors to refresh.",
                        'status_code' => 'failed_preflight',
                    ];
                }

                $qboCurrency = strtoupper((string) ($qboVendor['CurrencyRef']['value'] ?? ''));
                $ffCurrency  = strtoupper((string) ($ff['currency'] ?? ''));

                if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                    $qboName = (string) ($qboVendor['DisplayName'] ?? '(unnamed)');
                    return [
                        'reason'      => "Currency mismatch: FF bill is {$ffCurrency} but QBO vendor "
                                       . "(id={$vendorMap['qbo_vendor_id']}, name='{$qboName}') is {$qboCurrency}. "
                                       . "Either re-map FF vendor {$ff['vendor_id']} to a QBO vendor with "
                                       . "matching currency, or change FF bill.currency to match. "
                                       . "(QBO forbids vendor currency change after create; you may need a new QBO vendor.)",
                        'status_code' => 'failed_preflight_currency_mismatch',
                    ];
                }
            } catch (\Throwable $e) {
                // Transient QBO API issue — fall through gracefully.
                // The actual createEntity call will surface real connectivity issues.
                error_log("BillPusher gate 8 (currency-mismatch) probe failed for ff_bill={$ff['id']}: " . $e->getMessage());
            }
        }

        return null;
    }

    // ────────────────────────────────────────────────────────────────────
    // Payload builder (public-static for offline smoke testability)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Emit QBO Bill payload from FF bill row + line items + mapped
     * VendorRef. Pure function — no DB writes; reads from FF for line
     * items + per-line account mapping. Throws QuickBooksException on
     * missing references (caller catches + records as failed_preflight).
     *
     * Public-static so the offline smoke can exercise it without going
     * through the cURL boundary.
     *
     * Per D-QBO-18-2: tax-override pattern — every line TaxCodeRef='NON'
     * + header TxnTaxDetail.TotalTax = bcmath(gst+pst+hst). Per D-QBO-
     * FIXPACK-12: CurrencyRef emission gated on multi_currency_enabled.
     * Per D-QBO-18-7: DocNumber = vendor_bill_number ?? bill_number.
     *
     * @param  array<string, mixed> $ff FF acc_bills row (must include
     *         id, vendor_id, vendor_bill_number, bill_number, bill_date,
     *         due_date, currency, exchange_rate_to_cad, tax_gst_amount,
     *         tax_pst_amount, tax_hst_amount, notes)
     * @return array<string, mixed>      QBO Bill payload
     * @throws QuickBooksException       on missing vendor mapping or
     *         per-line account mapping (also raised when no lines)
     */
    public static function buildQboPayload(array $ff): array
    {
        // 1. Vendor mapping (caller should have validated via preflight
        //    but buildQboPayload is callable in test contexts that skip
        //    preflight — duplicate the check defensively).
        $vendorMap = db_row(
            "SELECT qbo_vendor_id FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
            [(int) $ff['vendor_id']]
        );
        if ($vendorMap === null || empty($vendorMap['qbo_vendor_id'])) {
            throw new QuickBooksException(
                "Cannot build QBO Bill payload: FF vendor {$ff['vendor_id']} has no QBO mapping."
            );
        }

        $payload = [
            'VendorRef' => ['value' => (string) $vendorMap['qbo_vendor_id']],
            'TxnDate'   => (string) $ff['bill_date'],
            'DueDate'   => (string) $ff['due_date'],
        ];

        // 2. DocNumber per D-QBO-18-7. vendor_bill_number is the
        //    vendor's own invoice/bill reference (e.g. "INV-5523" from
        //    the vendor's billing system); QBO Bill.DocNumber represents
        //    this in QBO's UI. Falls back to FF's internal bill_number
        //    when the vendor's number isn't recorded.
        $docNumber = trim((string) ($ff['vendor_bill_number'] ?? ''));
        if ($docNumber === '') {
            $docNumber = (string) $ff['bill_number'];
        }
        $payload['DocNumber'] = $docNumber;

        // 3. CurrencyRef emission gated on multi-currency setting per
        //    D-QBO-FIXPACK-12. Reads bill.currency (NOT vendor.currency)
        //    because the bill carries its own currency at creation time;
        //    that's the authoritative push value.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';
            }
            $payload['CurrencyRef'] = ['value' => $currency];
            // ExchangeRate emit policy per S-QBO-11 D-QBO-FIXPACK-1/-2:
            // CAD → '1.0'; non-CAD → bill.exchange_rate_to_cad (throw
            // when non-CAD + null/zero rate to avoid silent FX errors).
            if ($currency === 'CAD') {
                $payload['ExchangeRate'] = '1.0';
            } else {
                $rate = (string) ($ff['exchange_rate_to_cad'] ?? '');
                if ($rate === '' || bccomp($rate, '0', 6) <= 0) {
                    throw new QuickBooksException(
                        "Cannot build QBO Bill payload: non-CAD bill #{$ff['id']} has missing/zero exchange_rate_to_cad. Set the FX rate before push."
                    );
                }
                $payload['ExchangeRate'] = $rate;
            }
        }

        // 4. Build Lines from acc_bill_lines. Each line emits as
        //    AccountBasedExpenseLineDetail per D-QBO-18-3 with AccountRef
        //    pointing at the QBO account mapped from FF line.account_id.
        //    Tax-override per D-QBO-18-2: each line TaxCodeRef='NON'.
        $overrideId = (string) settings_get('quickbooks.tax_override_code_id', '');

        $lines = db_select(
            "SELECT id, account_id, description, amount
               FROM acc_bill_lines
              WHERE bill_id = ?
              ORDER BY sort_order, id",
            [(int) $ff['id']]
        );

        if (empty($lines)) {
            throw new QuickBooksException(
                "Cannot build QBO Bill payload: bill #{$ff['id']} has no line items."
            );
        }

        $payloadLines = [];
        foreach ($lines as $line) {
            $acctMap = db_row(
                "SELECT qbo_account_id FROM acc_qbo_account_map WHERE ff_account_id = ?",
                [(int) $line['account_id']]
            );
            if ($acctMap === null || empty($acctMap['qbo_account_id'])) {
                throw new QuickBooksException(
                    "Cannot build QBO Bill payload: line #{$line['id']} account #{$line['account_id']} has no QBO mapping."
                );
            }
            $payloadLines[] = [
                'Description' => (string) $line['description'],
                'Amount'      => (float) $line['amount'],
                'DetailType'  => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef'  => ['value' => (string) $acctMap['qbo_account_id']],
                    'TaxCodeRef'  => ['value' => $overrideId !== '' ? $overrideId : 'NON'],
                ],
            ];
        }
        $payload['Line'] = $payloadLines;

        // 5. TxnTaxDetail header. Default (tax_mode='override') = the proven
        //    D-QBO-CORE-6 pattern (TotalTax = bcmath(gst+pst+hst); each line
        //    carries NON). The opt-in per-rate ITC path (F9 / D-QBO-BILL-ITC-1)
        //    is built in buildBillTaxDetail() and gated default-off.
        $payload['TxnTaxDetail'] = self::buildBillTaxDetail($ff);

        // 6. PrivateNote — JSON audit trail for QBO-side review per
        //    D-QBO-11-6 pattern (mirror for bills). Includes the FF
        //    bill_number + tax breakdown for accountant drill-down.
        $payload['PrivateNote'] = self::buildPrivateNoteJson($ff);

        return $payload;
    }

    /**
     * Build the bill's TxnTaxDetail (F9 / D-QBO-BILL-ITC-1). GATED:
     *
     *   - tax_mode='override' (DEFAULT): returns the exact proven shape
     *     ['TotalTax' => bcmath(gst+pst+hst)] — byte-identical to pre-F9, so the
     *     working bill push is completely untouched.
     *
     *   - tax_mode='per_rate': emits TxnTaxDetail.TaxLine[] per NON-ZERO FF tax
     *     component that has a mapped QBO TaxRate (spec §8.8 — exposes the
     *     recoverable GST/HST ITC as a QBO tax-rate line). If ANY non-zero
     *     component is unmapped, FALLS BACK to override — never ships a partial
     *     or incorrect tax detail.
     *
     * Public-static so the offline smoke exercises both modes without cURL.
     */
    public static function buildBillTaxDetail(array $ff): array
    {
        $components = [
            'gst' => (string) ($ff['tax_gst_amount'] ?? '0.00'),
            'pst' => (string) ($ff['tax_pst_amount'] ?? '0.00'),
            'hst' => (string) ($ff['tax_hst_amount'] ?? '0.00'),
        ];
        $taxTotal = bcadd(bcadd($components['gst'], $components['pst'], 2), $components['hst'], 2);
        $override = ['TotalTax' => (float) $taxTotal];

        $mode = (string) settings_get('quickbooks.bill.tax_mode', 'override');
        if ($mode !== 'per_rate') {
            return $override;
        }

        // Per-rate: build a TaxLine for every non-zero, mapped component.
        $taxLines = [];
        foreach ($components as $component => $amount) {
            if (bccomp($amount, '0', 2) === 0) {
                continue; // zero component → no tax line
            }
            $rateId = self::qboTaxRateId($component);
            if ($rateId === null) {
                // A non-zero component with no mapped QBO TaxRate → cannot emit a
                // correct per-rate detail. Fall back to the safe override shape.
                error_log("[BillPusher] tax_mode=per_rate but FF '{$component}' tax (\${$amount}) has no mapped QBO TaxRate — falling back to override for bill #" . ($ff['id'] ?? '?') . ".");
                return $override;
            }
            $taxLines[] = [
                'Amount'        => (float) $amount,
                'DetailType'    => 'TaxLineDetail',
                'TaxLineDetail' => [
                    'TaxRateRef'  => ['value' => $rateId],
                    'PercentBased'=> true,
                ],
            ];
        }

        if ($taxLines === []) {
            return $override; // no taxable components → plain TotalTax
        }
        return [
            'TotalTax' => (float) $taxTotal,
            'TaxLine'  => $taxLines,
        ];
    }

    /**
     * Resolve the mapped QBO TaxRate.Id for an FF tax component (gst/pst/hst),
     * or null when unmapped. Operator-configured via acc_qbo_tax_rate_map (F9).
     */
    public static function qboTaxRateId(string $component): ?string
    {
        $row = db_row(
            "SELECT qbo_tax_rate_id FROM acc_qbo_tax_rate_map
              WHERE ff_tax_component = ? AND mapping_status = 'mapped' AND qbo_tax_rate_id IS NOT NULL",
            [$component]
        );
        return $row && $row['qbo_tax_rate_id'] !== '' ? (string) $row['qbo_tax_rate_id'] : null;
    }

    /**
     * Build PrivateNote JSON audit trail. Mirrors S-QBO-11 D-QBO-11-6
     * shape with bill-specific fields. Operator/accountant can drill
     * down from QBO into FF state via this audit blob.
     */
    public static function buildPrivateNoteJson(array $ff): string
    {
        $audit = [
            'ff_bill_id'     => (int) $ff['id'],
            'ff_bill_number' => (string) $ff['bill_number'],
            'ff_tax_breakdown' => [
                'gst' => (string) ($ff['tax_gst_amount'] ?? '0.00'),
                'pst' => (string) ($ff['tax_pst_amount'] ?? '0.00'),
                'hst' => (string) ($ff['tax_hst_amount'] ?? '0.00'),
            ],
            'pushed_at' => date('c'),
        ];
        if (!empty($ff['notes'])) {
            $audit['ff_notes'] = (string) $ff['notes'];
        }
        return json_encode($audit, JSON_UNESCAPED_SLASHES);
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers (mirror InvoicePusher / S-QBO-PUSHER-SKIP-RECORD-FIX)
    //
    // All helpers FK-guard via early-return when the FF bill no longer
    // exists. Smokes use sentinel IDs (999990-999999) for ghost rows;
    // FK guard prevents constraint violations on those test fixtures.
    // ────────────────────────────────────────────────────────────────────

    /**
     * Record a skip event in BOTH the map row AND a non-HTTP sync_log
     * row. Mirror of InvoicePusher::recordSkipped per S-QBO-PUSHER-SKIP-
     * RECORD-FIX-INVOICE D-PUSHER-SKIP-RECORD-INVOICE-1.
     */
    private static function recordSkipped(int $ffBillId, array $ff, string $statusCode, string $operation): void
    {
        if (!self::ffBillExists($ffBillId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $billNum = (string) ($ff['bill_number'] ?? '');
        $errorMsg = "skipped: {$statusCode}" . ($billNum !== '' ? " (bill {$billNum})" : '');

        self::upsertMappingRow($ffBillId, [
            'push_status'    => $statusCode,
            'push_error'     => $errorMsg,
            'last_synced_at' => $now,
        ]);
        self::writeNonHttpSyncLog($ffBillId, $operation, $statusCode, $errorMsg);
    }

    /**
     * Record a successful push: write/update the map row with QBO
     * identifiers + snapshot fields. Mirror of InvoicePusher pattern.
     */
    private static function recordSuccessfulPush(int $ffBillId, array $ff, array $qboBill): void
    {
        if (!self::ffBillExists($ffBillId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $vendorMap = db_row(
            "SELECT qbo_vendor_id FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
            [(int) $ff['vendor_id']]
        );

        self::upsertMappingRow($ffBillId, [
            'qbo_bill_id'            => (string) $qboBill['Id'],
            'qbo_sync_token'         => (string) ($qboBill['SyncToken'] ?? '0'),
            'qbo_doc_number'         => (string) ($qboBill['DocNumber'] ?? ''),
            'qbo_vendor_id'          => $vendorMap['qbo_vendor_id'] ?? null,
            'qbo_total_amt'          => isset($qboBill['TotalAmt']) ? (string) $qboBill['TotalAmt'] : null,
            'qbo_balance'            => isset($qboBill['Balance']) ? (string) $qboBill['Balance'] : null,
            'qbo_currency'           => isset($qboBill['CurrencyRef']['value']) ? (string) $qboBill['CurrencyRef']['value'] : null,
            'qbo_exchange_rate'      => isset($qboBill['ExchangeRate']) ? (string) $qboBill['ExchangeRate'] : null,
            'ff_bill_snapshot_total' => (string) ($ff['total_amount'] ?? '0.00'),
            'push_status'            => 'pushed',
            'push_error'             => null,
            'pushed_at'              => $now,
            'last_synced_at'         => $now,
        ]);
    }

    /**
     * Record a push failure (HTTP error from QBO). Writes push_status=
     * 'failed' + push_error to the map row. Mirror of InvoicePusher.
     */
    private static function recordPushFailure(int $ffBillId, string $errorMessage): void
    {
        if (!self::ffBillExists($ffBillId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffBillId, [
            'push_status'    => 'failed',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    /**
     * Record a preflight failure. Writes push_status='failed_preflight'
     * + push_error. Mirror of InvoicePusher.
     */
    private static function recordFailedPreflight(int $ffBillId, string $errorMessage): void
    {
        if (!self::ffBillExists($ffBillId)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffBillId, [
            'push_status'    => 'failed_preflight',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    /**
     * Record a typed pre-flight failure (S-QBO-BILL-GOTCHAS-PAYDOWN).
     * Lets the map row carry the specific sub-state — failed_preflight_
     * currency_mismatch OR failed_preflight_field_too_long — so the
     * operator can filter/retry the precise class via the admin UI.
     * Falls back to recordFailedPreflight if the status code isn't a
     * recognized typed state (defensive).
     */
    private static function recordTypedPreflight(int $ffBillId, string $statusCode, string $errorMessage): void
    {
        if (!self::ffBillExists($ffBillId)) {
            return;
        }
        $typedStates = ['failed_preflight_currency_mismatch', 'failed_preflight_field_too_long'];
        $persistedStatus = in_array($statusCode, $typedStates, true) ? $statusCode : 'failed_preflight';

        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffBillId, [
            'push_status'    => $persistedStatus,
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────────

    private static function ffBillExists(int $ffBillId): bool
    {
        $exists = db_row("SELECT id FROM acc_bills WHERE id = ?", [$ffBillId]);
        return $exists !== null;
    }

    /**
     * INSERT-or-UPDATE the mapping row for a given FF bill. Uses
     * INSERT … ON DUPLICATE KEY UPDATE on the uq_ff_bill UNIQUE index.
     */
    private static function upsertMappingRow(int $ffBillId, array $fields): void
    {
        $existing = db_row("SELECT id FROM acc_qbo_bill_map WHERE ff_bill_id = ?", [$ffBillId]);
        if ($existing === null) {
            db_insert('acc_qbo_bill_map', array_merge(['ff_bill_id' => $ffBillId], $fields));
        } else {
            db_update('acc_qbo_bill_map', $fields, 'ff_bill_id = ?', [$ffBillId]);
        }
    }

    /**
     * Write a non-HTTP sync_log row for skip events. http_method='SKIP'
     * sentinel + response_status=NULL distinguishes from real HTTP
     * traffic. Per D-SYNC-LOG-NON-HTTP-INVOICE-4 carry-over from
     * S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE — Pushers can link skip
     * rows to the worker's queue context via setWorkerContext static.
     */
    private static function writeNonHttpSyncLog(int $ffBillId, string $operation, string $statusCode, string $errorMessage): void
    {
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',     // ENUM('push','pull') — skip is still a push attempt
                'entity_type'     => 'bill',
                'entity_id'       => $ffBillId,
                'operation'       => $operation,
                'http_method'     => 'SKIP',     // sentinel: no real HTTP call made
                'endpoint'        => '',         // no endpoint exercised
                'response_status' => null,       // response_status NULL per D-SYNC-LOG-NON-HTTP-INVOICE-4
                'error_code'      => substr($statusCode, 0, 50),    // typed status code (reuses error_code channel)
                'error_message'   => substr($errorMessage, 0, 500),
                'queue_id'        => QuickBooksClient::workerQueueId(),  // null in CLI/smoke; set under worker context
                'realm_id'        => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'     => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
        } catch (\Throwable $e) {
            error_log("BillPusher: writeNonHttpSyncLog failed for ff_bill_id={$ffBillId}: " . $e->getMessage());
        }
    }
}
