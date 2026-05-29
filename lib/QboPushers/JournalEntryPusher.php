<?php
declare(strict_types=1);

/**
 * lib/QboPushers/JournalEntryPusher.php
 *
 * Phase QBO-10 / 1 of 1 (S-QBO-21) — FF→QBO journal entry push.
 * Closes the catch-all Pusher arc: any FF JE that doesn't have a higher-
 * level entity equivalent in QBO pushes as a raw QBO JournalEntry.
 *
 * Mirrors S-QBO-19 BillPaymentPusher dispatcher contract with JE-specific
 * adjustments:
 *   - Reads FF acc_journal_entries + acc_journal_entry_lines tables
 *   - No CustomerRef / VendorRef (JEs are double-entry only, no counterparty)
 *   - Per-line AccountRef (D-QBO-21-3) from acc_qbo_account_map for each
 *     jel.account_id (10+ lines possible)
 *   - PostingType debit/credit emission per line (D-QBO-21-2): debit-line
 *     emits 'Debit', credit-line emits 'Credit', Amount = abs(populated)
 *   - **Bridge-derived filter (D-QBO-21-1)** at pushImpl step 4: rejects
 *     source_type IN ('invoice','payment','credit_note','ap_bill','ap_payment')
 *     per spec §8.10 verbatim. QBO derives JEs from those entities itself;
 *     pushing FF bridge-derived JEs creates DOUBLE accounting in QBO.
 *     Uses no-map-row pattern (mirror PaymentPusher skipped_non_ff_origin)
 *     — sync_log captures via error_code; map row NOT written.
 *   - entry_status='posted' gate (D-QBO-21 eligibility): drafts +
 *     submitted + approved skip with failed_preflight. Spec §8.10 uses
 *     entry_status (AJE-workflow authoritative), not the legacy status
 *     column. Both end up 'posted' together after JournalEntryService::
 *     post() / create(post_immediately=true) so legacy code paths still work.
 *   - DocNumber = entry_number (JE-YYYY-NNNNN, 12 chars; field-length
 *     gate via shared QboFieldLimits::INVOICE_DOC_NUMBER_MAX=21 per
 *     D-QBO-21-4 defense-in-depth)
 *   - PrivateNote (D-QBO-21-7) = "FF JE {entry_number} | source={source_type}
 *     {source_id}" for QBO-side audit drill-down
 *   - Currency: header currency from acc_journal_entries.currency +
 *     D-QBO-FIXPACK-12 multi_currency gating (D-QBO-21-6). JE is single-
 *     currency at header level by FF design (per-line foreign_currency
 *     exists but is unusual)
 *   - No counterparty currency comparison (no customer/vendor to fetch);
 *     currency-mismatch typed sub-state used only when multi_currency_enabled='1'
 *     AND header currency is empty/invalid
 *   - pushUpdate stubbed → S-QBO-21-UPDATE-FOLLOWUP per D-QBO-21-5
 *
 * @session  S-QBO-21
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.10 (Journal Entry),
 *           §6.8 (Pusher Contract), §7.* acc_qbo_journal_entry_map
 * @decision D-QBO-21-1 (bridge-derived filter per spec §8.10:
 *               invoice/payment/credit_note/ap_bill/ap_payment),
 *           D-QBO-21-2 (PostingType mapping debit/credit columns),
 *           D-QBO-21-3 (per-line AccountRef via acc_qbo_account_map),
 *           D-QBO-21-4 (DocNumber from entry_number; 21-char gate),
 *           D-QBO-21-5 (pushUpdate stubbed → S-QBO-21-UPDATE-FOLLOWUP),
 *           D-QBO-21-6 (header currency + D-QBO-FIXPACK-12 gating),
 *           D-QBO-21-7 (PrivateNote source attribution for audit)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class JournalEntryPusher
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
     * Bridge-derived source_type filter per D-QBO-21-1 + QBO_SPEC §8.10.
     * QBO derives JournalEntry records itself when these parent entities
     * sync; pushing the FF bridge-derived JE would DOUBLE-COUNT.
     *
     * K-22 trap: prompt-arc named these QBO-style (bill / credit_memo /
     * bill_payment / refund_receipt) but FF source_type ENUM uses
     * ap_bill / credit_note / ap_payment. ENUM has no bill_payment /
     * refund_receipt values — those don't exist as FF source types.
     */
    private const BRIDGE_DERIVED_SOURCE_TYPES = [
        'invoice',
        'payment',
        'credit_note',
        'ap_bill',
        'ap_payment',
    ];

    /**
     * Fixed-Asset source_type values that get PrivateNote enrichment in
     * buildQboPayload step 6 per D-QBO-22-1 (S-QBO-22). These source types
     * are EXPLICITLY NOT bridge-derived — they push through to QBO as
     * standard JEs per spec §8.13 (FA depreciation + disposal + impairment
     * are all FF-canonical because QBO has no fixed-asset module that can
     * represent FF's CCA Schedule 8 continuity).
     *
     * Smoke regression guard C8 asserts the intersection with
     * BRIDGE_DERIVED_SOURCE_TYPES is empty (D-QBO-22-4 defense-in-depth).
     *
     * @session  S-QBO-22
     * @decision D-QBO-22-1 (PrivateNote FA-specific enrichment),
     *           D-QBO-22-4 (FA source types never bridge-derived;
     *                       smoke regression guard)
     */
    public const FA_SOURCE_TYPES = [
        'depreciation',
        'asset_disposal',
        'impairment',
    ];

    /**
     * Push a new FF JE into QBO as a JournalEntry. Idempotent — if the
     * JE is already mapped (qbo_journal_entry_id), returns
     * ['status' => 'already_mapped'] without re-POSTing.
     */
    public static function pushCreate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return self::pushImpl($entityId, 'create', $payloadSnapshot);
    }

    /**
     * S-QBO-21 v1 stub per D-QBO-21-5 — pushUpdate will be implemented
     * in S-QBO-21-UPDATE-FOLLOWUP. Returns success=false with
     * status='unsupported_in_session'.
     */
    public static function pushUpdate(int $entityId, ?array $payloadSnapshot = null): array
    {
        return [
            'success' => false,
            'status'  => 'unsupported_in_session',
            'outcome' => 'failed',
            'error'   => 'JournalEntryPusher::pushUpdate is not implemented in S-QBO-21 v1 (D-QBO-21-5). Queue this for S-QBO-21-UPDATE-FOLLOWUP.',
        ] + self::RESULT_BASE;
    }

    /**
     * Shared orchestration. Both public methods delegate here.
     */
    private static function pushImpl(int $ffJeId, string $operation, ?array $payloadSnapshot): array
    {
        // 1. Sync mode gate.
        $mode = (string) settings_get('quickbooks.sync_mode.journal_entry', 'queue');
        if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
            $ffForSkip = db_row("SELECT id, entry_status FROM acc_journal_entries WHERE id = ?", [$ffJeId])
                ?? ['id' => $ffJeId, 'entry_status' => null];
            self::recordSkipped($ffJeId, $ffForSkip, 'skipped_by_mode', $operation);
            return [
                'success' => true,
                'status'  => 'skipped_by_mode',
                'outcome' => 'skipped',
                'error'   => "sync_mode.journal_entry={$mode}",
            ] + self::RESULT_BASE;
        }

        // 2. Load FF JE state.
        $ff = db_row(
            "SELECT id, entry_number, entry_date, entry_type, status, entry_status,
                    description, source_type, source_id, currency, exchange_rate,
                    is_reversal, reversal_of_id
               FROM acc_journal_entries
              WHERE id = ?",
            [$ffJeId]
        );
        if ($ff === null) {
            return [
                'success' => false,
                'status'  => 'ff_not_found',
                'outcome' => 'failed',
                'error'   => "FF journal entry {$ffJeId} not found",
            ] + self::RESULT_BASE;
        }

        // 3. Look up existing mapping.
        $mapping = db_row(
            "SELECT id, qbo_journal_entry_id, qbo_sync_token, qbo_currency, push_status
               FROM acc_qbo_journal_entry_map
              WHERE ff_journal_entry_id = ?",
            [$ffJeId]
        );

        // 4. Bridge-derived filter per D-QBO-21-1 + spec §8.10.
        //    Defense-in-depth — Enqueuer gate-0 also rejects, but a JE
        //    enqueued before its source_type was finalized could slip
        //    through. Recording strategy mirrors PaymentPusher::
        //    skipped_non_ff_origin no-map-row pattern: write sync_log
        //    SKIP only; map row NOT written. Avoids needing a
        //    skipped_bridge_derived ENUM value (mirrors D-QBO-14-1
        //    strategy that avoided skipped_non_ff_origin ENUM extension).
        if ($ff['source_type'] !== null && in_array($ff['source_type'], self::BRIDGE_DERIVED_SOURCE_TYPES, true)) {
            self::writeNonHttpSyncLog(
                $ffJeId,
                $operation,
                'skipped_bridge_derived',
                "JE {$ff['entry_number']} source_type='{$ff['source_type']}' (bridge-derived per spec §8.10; QBO derives JE from parent entity sync — pushing creates double accounting). D-QBO-21-1."
            );
            return [
                'success' => true,
                'status'  => 'skipped_bridge_derived',
                'outcome' => 'skipped',
                'error'   => "JE {$ff['entry_number']} has source_type='{$ff['source_type']}'; bridge-derived JEs are skipped per QBO_SPEC §8.10 (QBO derives the JE from the parent entity sync).",
                'qbo_id'  => ($mapping !== null && !empty($mapping['qbo_journal_entry_id'])) ? (string) $mapping['qbo_journal_entry_id'] : null,
            ] + self::RESULT_BASE;
        }

        // 5. Idempotency on CREATE.
        if ($operation === 'create' && $mapping !== null && !empty($mapping['qbo_journal_entry_id'])) {
            $expectedCurrency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            $snapshotCurrency = strtoupper((string) ($mapping['qbo_currency'] ?? ''));
            if ($snapshotCurrency !== '' && $snapshotCurrency !== $expectedCurrency) {
                error_log(
                    "S-QBO-FIXPACK-10 WARNING: FF JE {$ff['id']} expected CurrencyRef='{$expectedCurrency}' " .
                    "but mapped QBO journal entry #{$mapping['qbo_journal_entry_id']} snapshot is '{$snapshotCurrency}'."
                );
            }
            return [
                'success' => true,
                'status'  => 'already_mapped',
                'outcome' => 'created',
                'qbo_id'  => (string) $mapping['qbo_journal_entry_id'],
            ] + self::RESULT_BASE;
        }

        // 6. entry_status='posted' gate per spec §8.10 + AskUserQuestion answer.
        //    Drafts + submitted + approved (AJE workflow mid-flight) skip
        //    with failed_preflight. Reversed JEs are excluded too — only
        //    'posted' pushes (reversal companion JE itself reaches 'posted'
        //    so it pushes separately when JournalEntryService::reverse()
        //    creates it).
        if (($ff['entry_status'] ?? 'posted') !== 'posted') {
            self::recordFailedPreflight(
                $ffJeId,
                "JE {$ff['entry_number']} entry_status='{$ff['entry_status']}'; push requires entry_status='posted' (spec §8.10 + S-QBO-21 eligibility)."
            );
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => "entry_status='{$ff['entry_status']}' — push requires 'posted'",
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
                self::recordTypedPreflight($ffJeId, $statusCode, $reason);
            } else {
                self::recordFailedPreflight($ffJeId, $reason);
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
            self::recordFailedPreflight($ffJeId, 'Payload build failed: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build failed: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        } catch (\Throwable $e) {
            self::recordFailedPreflight($ffJeId, 'Payload build unexpected error: ' . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'failed_preflight',
                'outcome' => 'failed',
                'error'   => 'Payload build unexpected error: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 9. HTTP call.
        $client = new QuickBooksClient();
        try {
            $response = $client->createEntity('journalentry', $qboPayload);
        } catch (QuickBooksException $e) {
            self::recordPushFailure($ffJeId, $e->getMessage());
            return [
                'success' => false,
                'status'  => 'qbo_error',
                'outcome' => 'failed',
                'error'   => $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // 10. Parse response — Intuit returns JournalEntry entity under 'JournalEntry' key.
        $qboJe = $response['JournalEntry'] ?? null;
        if (!is_array($qboJe) || empty($qboJe['Id'])) {
            self::recordPushFailure($ffJeId, 'QBO response missing JournalEntry.Id');
            return [
                'success' => false,
                'status'  => 'qbo_malformed_response',
                'outcome' => 'failed',
                'error'   => 'QBO response missing JournalEntry.Id',
            ] + self::RESULT_BASE;
        }

        self::recordSuccessfulPush($ffJeId, $ff, $qboJe);

        return [
            'success'    => true,
            'status'     => 'pushed',
            'outcome'    => 'created',
            'qbo_id'     => (string) $qboJe['Id'],
            'sync_token' => (string) ($qboJe['SyncToken'] ?? '0'),
        ] + self::RESULT_BASE;
    }

    // ────────────────────────────────────────────────────────────────────
    // Pre-flight gates
    // ────────────────────────────────────────────────────────────────────

    /**
     * Inline pre-flight gates (mirror BillPaymentPusher::runPreflight).
     *
     * Gates:
     *   1. AccountValidator::assertReadyForJournalEntryPush (tax_receivable
     *      + tax_payable per D-QBO-VALIDATOR-3 — JE-specific category set;
     *      already shipped in S-QBO-VALIDATOR-SCOPE-SPLIT)
     *   2. Per-line account mapping — every jel.account_id must have an
     *      acc_qbo_account_map row with non-null qbo_account_id (D-QBO-21-3)
     *   3. At least 2 lines (defense-in-depth — JournalEntryService::create
     *      already enforces but JE pushed via raw INSERT could bypass)
     *   4. Balanced — sum(debit) == sum(credit) defense-in-depth
     *   5. Connection healthy
     *   6. Field-length: entry_number ≤21 chars per shared QboFieldLimits::
     *      INVOICE_DOC_NUMBER_MAX (defense-in-depth — JE-YYYY-NNNNN = 12 chars)
     *   7. Currency-mismatch when multi_currency='1' — header currency must
     *      be non-empty + valid 3-letter code (D-QBO-21-6)
     *
     * @return null|string|array{reason: string, status_code: string}
     */
    private static function runPreflight(array $ff)
    {
        // Gate 1: AccountValidator (tax_receivable + tax_payable per D-QBO-VALIDATOR-3).
        try {
            AccountValidator::assertReadyForJournalEntryPush();
        } catch (ChartOfAccountsIncompleteException $e) {
            return 'AccountValidator: ' . $e->getMessage();
        }

        // Gate 2 + 3: Per-line account mapping + at least 2 lines.
        $lines = db_select(
            "SELECT id, account_id, debit, credit, description, line_number
               FROM acc_journal_entry_lines
              WHERE journal_entry_id = ?
              ORDER BY line_number, id",
            [(int) $ff['id']]
        );
        if (count($lines) < 2) {
            return "JE {$ff['entry_number']} has " . count($lines) . " line(s); QBO JournalEntry requires at least 2 (double-entry).";
        }
        foreach ($lines as $line) {
            $acctMap = db_row(
                "SELECT qbo_account_id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id = ?",
                [(int) $line['account_id']]
            );
            if ($acctMap === null || empty($acctMap['qbo_account_id']) || $acctMap['mapping_status'] !== 'mapped') {
                return "JE {$ff['entry_number']} line #{$line['line_number']} uses FF account #{$line['account_id']} which has no QBO mapping. Map via /quickbooks/accounts before pushing (D-QBO-21-3).";
            }
        }

        // Gate 4: Balanced defense-in-depth.
        $totalDebit  = '0.00';
        $totalCredit = '0.00';
        foreach ($lines as $line) {
            $totalDebit  = bcadd($totalDebit,  (string) ($line['debit']  ?? '0.00'), 2);
            $totalCredit = bcadd($totalCredit, (string) ($line['credit'] ?? '0.00'), 2);
        }
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            return "JE {$ff['entry_number']} is unbalanced: debits ({$totalDebit}) ≠ credits ({$totalCredit}). FF should reject at JournalEntryService::create but defensive gate caught it here.";
        }
        if (bccomp($totalDebit, '0.00', 2) === 0) {
            return "JE {$ff['entry_number']} total is zero — refuse to push empty JE.";
        }

        // Gate 5: Connection status.
        $connStatus = (string) settings_get('quickbooks.connection_status', '');
        if ($connStatus !== 'connected') {
            return "QBO connection is not connected (status={$connStatus}). Connect via /quickbooks/settings.";
        }

        // Gate 6: Field-length — entry_number per D-QBO-21-4 (mirrors
        // D-QBO-FIXPACK-5 DocNumber 21-char limit shared with Invoice/Bill).
        $docNumber = trim((string) ($ff['entry_number'] ?? ''));
        if ($docNumber !== '' && strlen($docNumber) > QboFieldLimits::INVOICE_DOC_NUMBER_MAX) {
            $len = strlen($docNumber);
            $max = QboFieldLimits::INVOICE_DOC_NUMBER_MAX;
            return [
                'reason'      => "entry_number '{$docNumber}' exceeds QBO JournalEntry DocNumber limit of {$max} characters (actual: {$len}). FF JE numbering JE-YYYY-NNNNN = 12 chars normally — this should not be reachable unless numbering scheme changed.",
                'status_code' => 'failed_preflight_field_too_long',
            ];
        }

        // Gate 7: Currency presence + validity (D-QBO-21-6) when multi_currency='1'.
        //   Note: JE has no counterparty (no customer/vendor), so there's no
        //   external party currency to compare against. The check verifies
        //   the header currency is set + a valid 3-letter code. Unlike
        //   BillPaymentPusher gate 8 (which fetches QBO vendor + compares),
        //   JE has no analog — currency-mismatch only triggers on empty/invalid
        //   header currency.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $ffCurrency = strtoupper(trim((string) ($ff['currency'] ?? '')));
            if ($ffCurrency === '' || strlen($ffCurrency) !== 3) {
                return [
                    'reason'      => "JE {$ff['entry_number']} has missing or invalid header currency='{$ffCurrency}'; multi_currency_enabled='1' requires a 3-letter code (CAD/USD).",
                    'status_code' => 'failed_preflight_currency_mismatch',
                ];
            }
        }

        return null;
    }

    // ────────────────────────────────────────────────────────────────────
    // Payload builder (public-static for offline smoke testability)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Emit QBO JournalEntry payload from FF JE row + lines.
     * Per spec §8.10 + D-QBO-21-1/2/3/4/6/7.
     *
     * @throws QuickBooksException on missing references / unbalanced
     */
    public static function buildQboPayload(array $ff): array
    {
        // 1. Defense-in-depth: bridge-derived rejection — buildQboPayload
        //    is exposed public-static for smoke testability so a caller
        //    could pass a bridge-derived $ff directly. Refuse rather than
        //    silently emit a double-counting payload.
        if (($ff['source_type'] ?? null) !== null
            && in_array($ff['source_type'], self::BRIDGE_DERIVED_SOURCE_TYPES, true)) {
            throw new QuickBooksException(
                "Cannot build QBO JournalEntry payload: FF JE {$ff['id']} has source_type='{$ff['source_type']}' which is bridge-derived per spec §8.10 (D-QBO-21-1)."
            );
        }

        // 2. Load lines.
        $lines = db_select(
            "SELECT id, account_id, debit, credit, description, line_number
               FROM acc_journal_entry_lines
              WHERE journal_entry_id = ?
              ORDER BY line_number, id",
            [(int) $ff['id']]
        );
        if (count($lines) < 2) {
            throw new QuickBooksException(
                "Cannot build QBO JournalEntry payload: FF JE {$ff['id']} has " . count($lines) . " line(s); QBO requires at least 2."
            );
        }

        // 3. Balanced check (defense-in-depth).
        $totalDebit  = '0.00';
        $totalCredit = '0.00';
        foreach ($lines as $line) {
            $totalDebit  = bcadd($totalDebit,  (string) ($line['debit']  ?? '0.00'), 2);
            $totalCredit = bcadd($totalCredit, (string) ($line['credit'] ?? '0.00'), 2);
        }
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new QuickBooksException(
                "Cannot build QBO JournalEntry payload: FF JE {$ff['id']} is unbalanced (debits {$totalDebit} ≠ credits {$totalCredit})."
            );
        }

        // 4. Build Line array per spec §8.10 + D-QBO-21-2/3.
        $payloadLines = [];
        foreach ($lines as $line) {
            $debit  = (string) ($line['debit']  ?? '0.00');
            $credit = (string) ($line['credit'] ?? '0.00');
            $debitPositive  = bccomp($debit,  '0.00', 2) > 0;
            $creditPositive = bccomp($credit, '0.00', 2) > 0;

            // D-QBO-21-2: line must have exactly one of debit OR credit
            //   populated. JournalEntryService::create already enforces
            //   this; defense-in-depth here.
            if ($debitPositive && $creditPositive) {
                throw new QuickBooksException(
                    "Cannot build QBO JournalEntry payload: line #{$line['line_number']} has both debit ({$debit}) and credit ({$credit}) populated."
                );
            }
            if (!$debitPositive && !$creditPositive) {
                throw new QuickBooksException(
                    "Cannot build QBO JournalEntry payload: line #{$line['line_number']} has zero debit AND zero credit — every line needs one side populated."
                );
            }

            // D-QBO-21-3: per-line AccountRef from acc_qbo_account_map.
            $acctMap = db_row(
                "SELECT qbo_account_id FROM acc_qbo_account_map WHERE ff_account_id = ?",
                [(int) $line['account_id']]
            );
            if ($acctMap === null || empty($acctMap['qbo_account_id'])) {
                throw new QuickBooksException(
                    "Cannot build QBO JournalEntry payload: line #{$line['line_number']} uses FF account #{$line['account_id']} which has no QBO mapping (D-QBO-21-3)."
                );
            }

            $postingType = $debitPositive ? 'Debit' : 'Credit';
            $amount      = $debitPositive ? (float) $debit : (float) $credit;

            $payloadLine = [
                'Amount'     => $amount,
                'DetailType' => 'JournalEntryLineDetail',
                'JournalEntryLineDetail' => [
                    'PostingType' => $postingType,
                    'AccountRef'  => ['value' => (string) $acctMap['qbo_account_id']],
                ],
            ];
            $description = trim((string) ($line['description'] ?? ''));
            if ($description !== '') {
                $payloadLine['Description'] = $description;
            }
            $payloadLines[] = $payloadLine;
        }

        // 5. Build header.
        $payload = [
            'TxnDate'   => (string) $ff['entry_date'],
            'DocNumber' => (string) $ff['entry_number'],
            'Line'      => $payloadLines,
        ];

        // 6. PrivateNote per D-QBO-21-7 — FF entry_number + source attribution
        //    for QBO-side audit drill-down. Truncates JE description (which
        //    can run long) to keep PrivateNote under QBO's 4000-char limit.
        //
        //    S-QBO-22 / D-QBO-22-1 extension: for FA-derived JEs (source_type
        //    IN self::FA_SOURCE_TYPES), append a short FA-specific section
        //    looked up best-effort from acc_depreciation_runs /
        //    acc_asset_disposals / acc_asset_impairments. Lookup never
        //    blocks the push — try/catch + error_log on miss so a deleted
        //    FA source row degrades gracefully to the generic PrivateNote.
        $noteParts = ["FF JE {$ff['entry_number']}"];
        if (!empty($ff['source_type'])) {
            $srcId = $ff['source_id'] ?? '';
            $noteParts[] = "source={$ff['source_type']}" . ($srcId !== '' ? "#{$srcId}" : '');

            // D-QBO-22-1: FA-specific PrivateNote enrichment.
            if ($srcId !== '' && in_array((string) $ff['source_type'], self::FA_SOURCE_TYPES, true)) {
                try {
                    $faSection = self::buildFixedAssetNoteSection(
                        (string) $ff['source_type'],
                        (int) $srcId
                    );
                    if ($faSection !== '') {
                        $noteParts[] = $faSection;
                    }
                } catch (\Throwable $e) {
                    // Best-effort enrichment — never block push on lookup failure.
                    error_log(
                        "[JournalEntryPusher] FA PrivateNote enrichment failed for JE id={$ff['id']} " .
                        "source={$ff['source_type']}#{$srcId}: " . $e->getMessage()
                    );
                }
            }
        } else {
            $noteParts[] = "source=manual";
        }
        $desc = trim((string) ($ff['description'] ?? ''));
        if ($desc !== '') {
            // Cap description portion at 200 chars to stay well under PrivateNote limit.
            $noteParts[] = strlen($desc) > 200 ? substr($desc, 0, 200) . '…' : $desc;
        }
        if (!empty($ff['is_reversal']) && !empty($ff['reversal_of_id'])) {
            $noteParts[] = "reversal_of_je#{$ff['reversal_of_id']}";
        }
        $payload['PrivateNote'] = implode(' | ', $noteParts);

        // 7. CurrencyRef + ExchangeRate gated on multi_currency per D-QBO-FIXPACK-12.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $currency = strtoupper((string) ($ff['currency'] ?? 'CAD'));
            if ($currency === '') {
                $currency = 'CAD';
            }
            $payload['CurrencyRef'] = ['value' => $currency];
            if ($currency === 'CAD') {
                $payload['ExchangeRate'] = '1.0';
            } else {
                $rate = (string) ($ff['exchange_rate'] ?? '');
                if ($rate === '' || bccomp($rate, '0', 6) <= 0) {
                    throw new QuickBooksException(
                        "Cannot build QBO JournalEntry payload: non-CAD JE #{$ff['id']} has missing/zero exchange_rate."
                    );
                }
                $payload['ExchangeRate'] = $rate;
            }
        }

        return $payload;
    }

    // ────────────────────────────────────────────────────────────────────
    // Fixed-Asset PrivateNote enrichment (S-QBO-22 / D-QBO-22-1)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Build the FA-specific PrivateNote section for FA-derived JEs.
     *
     * Returns a short string suitable for appending as one '|'-separated
     * segment in JournalEntryPusher::buildQboPayload PrivateNote builder.
     * Returns empty string when the FA source row is gone or shape is
     * unrecognized — caller MUST tolerate empty return (the PrivateNote
     * still gets the generic source attribution).
     *
     * Source-type → table mapping:
     *   depreciation    → acc_depreciation_runs       (source_id = run.id)
     *   asset_disposal  → acc_asset_disposals         (source_id = disposal.id)
     *   impairment      → acc_asset_impairments       (source_id = impairment.id)
     *
     * Section formats (designed to stay under ~120 chars each so the
     * PrivateNote stays well under QBO's 4000-char limit even when
     * description + reversal pointer also present):
     *   "FA-DEP run#5 period='2026-04 Monthly' assets=12 total=$4250.00"
     *   "FA-DISP asset=FA-0042 type=sale proceeds=$5000.00 gain_loss=$1200.00"
     *   "FA-IMP asset=FA-0042 reason='market_crash' loss=$2500.00"
     *
     * @param  string $sourceType  One of FA_SOURCE_TYPES
     * @param  int    $sourceId    FF row id in the appropriate FA subledger
     * @return string              FA section or '' if lookup yielded nothing
     *
     * @session  S-QBO-22
     * @decision D-QBO-22-1 (FA-specific PrivateNote enrichment;
     *                       best-effort lookup; never blocks push)
     */
    public static function buildFixedAssetNoteSection(string $sourceType, int $sourceId): string
    {
        if ($sourceId <= 0) {
            return '';
        }

        switch ($sourceType) {
            case 'depreciation':
                // Lookup acc_depreciation_runs + join acc_periods for the
                // period name. asset count = row count in acc_depreciation_run_lines.
                $run = db_row(
                    "SELECT r.id, r.total_depreciation, p.name AS period_name
                       FROM acc_depreciation_runs r
                       LEFT JOIN acc_periods p ON p.id = r.period_id
                      WHERE r.id = ?",
                    [$sourceId]
                );
                if (!$run) {
                    return '';
                }
                $assetCountRow = db_row(
                    "SELECT COUNT(*) AS c FROM acc_depreciation_run_lines WHERE run_id = ?",
                    [$sourceId]
                );
                $assetCount = (int) ($assetCountRow['c'] ?? 0);
                $period = trim((string) ($run['period_name'] ?? ''));
                $total  = number_format((float) ($run['total_depreciation'] ?? 0), 2, '.', '');
                $parts  = ["FA-DEP run#{$sourceId}"];
                if ($period !== '') {
                    // Cap period name at 40 chars defensively.
                    $parts[] = "period='" . (strlen($period) > 40 ? substr($period, 0, 40) . '…' : $period) . "'";
                }
                $parts[] = "assets={$assetCount}";
                $parts[] = "total=\${$total}";
                return implode(' ', $parts);

            case 'asset_disposal':
                // Lookup acc_asset_disposals + join acc_fixed_assets for asset_number.
                $disp = db_row(
                    "SELECT d.id, d.disposal_type, d.proceeds, d.gain_loss, a.asset_number
                       FROM acc_asset_disposals d
                       LEFT JOIN acc_fixed_assets a ON a.id = d.asset_id
                      WHERE d.id = ?",
                    [$sourceId]
                );
                if (!$disp) {
                    return '';
                }
                $assetNum = trim((string) ($disp['asset_number'] ?? ''));
                $type     = trim((string) ($disp['disposal_type'] ?? ''));
                $proceeds = number_format((float) ($disp['proceeds'] ?? 0), 2, '.', '');
                $gainLoss = number_format((float) ($disp['gain_loss'] ?? 0), 2, '.', '');
                $parts    = ['FA-DISP'];
                if ($assetNum !== '') {
                    $parts[] = "asset={$assetNum}";
                }
                if ($type !== '') {
                    $parts[] = "type={$type}";
                }
                $parts[] = "proceeds=\${$proceeds}";
                $parts[] = "gain_loss=\${$gainLoss}";
                return implode(' ', $parts);

            case 'impairment':
                // Lookup acc_asset_impairments + join acc_fixed_assets.
                $imp = db_row(
                    "SELECT i.id, i.impairment_loss, i.reason, a.asset_number
                       FROM acc_asset_impairments i
                       LEFT JOIN acc_fixed_assets a ON a.id = i.asset_id
                      WHERE i.id = ?",
                    [$sourceId]
                );
                if (!$imp) {
                    return '';
                }
                $assetNum = trim((string) ($imp['asset_number'] ?? ''));
                $reason   = trim((string) ($imp['reason'] ?? ''));
                $loss     = number_format((float) ($imp['impairment_loss'] ?? 0), 2, '.', '');
                $parts    = ['FA-IMP'];
                if ($assetNum !== '') {
                    $parts[] = "asset={$assetNum}";
                }
                if ($reason !== '') {
                    // Cap reason at 60 chars + slugify quotes that would
                    // break the '|'-separated PrivateNote structure.
                    $reason = str_replace(["'", '|'], ['', '/'], $reason);
                    $parts[] = "reason='" . (strlen($reason) > 60 ? substr($reason, 0, 60) . '…' : $reason) . "'";
                }
                $parts[] = "loss=\${$loss}";
                return implode(' ', $parts);

            default:
                // Defensive — unknown FA source type. Caller already filtered
                // via FA_SOURCE_TYPES check, so this is a regression net.
                return '';
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Recording helpers (mirror BillPaymentPusher)
    // ────────────────────────────────────────────────────────────────────

    private static function recordSkipped(int $ffJeId, array $ff, string $statusCode, string $operation): void
    {
        if (!self::ffJeExists($ffJeId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $entryNum = (string) ($ff['entry_number'] ?? '');
        $errorMsg = "skipped: {$statusCode}" . ($entryNum !== '' ? " (JE {$entryNum})" : '');
        self::upsertMappingRow($ffJeId, [
            'push_status'    => $statusCode,
            'push_error'     => $errorMsg,
            'last_synced_at' => $now,
        ]);
        self::writeNonHttpSyncLog($ffJeId, $operation, $statusCode, $errorMsg);
    }

    private static function recordSuccessfulPush(int $ffJeId, array $ff, array $qboJe): void
    {
        if (!self::ffJeExists($ffJeId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');

        // FF balanced-total snapshot (sum of debits = sum of credits)
        $sums = db_row(
            "SELECT SUM(debit) AS td FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
            [(int) $ff['id']]
        );
        $ffTotal = (string) ($sums['td'] ?? '0.00');

        self::upsertMappingRow($ffJeId, [
            'qbo_journal_entry_id'  => (string) $qboJe['Id'],
            'qbo_sync_token'        => (string) ($qboJe['SyncToken'] ?? '0'),
            'qbo_doc_number'        => isset($qboJe['DocNumber']) ? (string) $qboJe['DocNumber'] : (string) $ff['entry_number'],
            'qbo_total_amt'         => isset($qboJe['TotalAmt']) ? (string) $qboJe['TotalAmt'] : $ffTotal,
            'qbo_currency'          => isset($qboJe['CurrencyRef']['value']) ? (string) $qboJe['CurrencyRef']['value'] : null,
            'qbo_exchange_rate'     => isset($qboJe['ExchangeRate']) ? (string) $qboJe['ExchangeRate'] : null,
            'qbo_txn_date'          => isset($qboJe['TxnDate']) ? (string) $qboJe['TxnDate'] : (string) $ff['entry_date'],
            'qbo_private_note'      => isset($qboJe['PrivateNote']) ? (string) $qboJe['PrivateNote'] : null,
            'ff_je_snapshot_total'  => $ffTotal,
            'push_status'           => 'pushed',
            'push_error'            => null,
            'pushed_at'             => $now,
            'last_synced_at'        => $now,
        ]);
    }

    private static function recordPushFailure(int $ffJeId, string $errorMessage): void
    {
        if (!self::ffJeExists($ffJeId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffJeId, [
            'push_status'    => 'failed',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    private static function recordFailedPreflight(int $ffJeId, string $errorMessage): void
    {
        if (!self::ffJeExists($ffJeId)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffJeId, [
            'push_status'    => 'failed_preflight',
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    private static function recordTypedPreflight(int $ffJeId, string $statusCode, string $errorMessage): void
    {
        if (!self::ffJeExists($ffJeId)) {
            return;
        }
        $typedStates = ['failed_preflight_currency_mismatch', 'failed_preflight_field_too_long'];
        $persistedStatus = in_array($statusCode, $typedStates, true) ? $statusCode : 'failed_preflight';
        $now = date('Y-m-d H:i:s');
        self::upsertMappingRow($ffJeId, [
            'push_status'    => $persistedStatus,
            'push_error'     => substr($errorMessage, 0, 65535),
            'last_synced_at' => $now,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────────

    private static function ffJeExists(int $ffJeId): bool
    {
        $exists = db_row("SELECT id FROM acc_journal_entries WHERE id = ?", [$ffJeId]);
        return $exists !== null;
    }

    private static function upsertMappingRow(int $ffJeId, array $fields): void
    {
        $existing = db_row("SELECT id FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = ?", [$ffJeId]);
        if ($existing === null) {
            db_insert('acc_qbo_journal_entry_map', array_merge(['ff_journal_entry_id' => $ffJeId], $fields));
        } else {
            db_update('acc_qbo_journal_entry_map', $fields, 'ff_journal_entry_id = ?', [$ffJeId]);
        }
    }

    private static function writeNonHttpSyncLog(int $ffJeId, string $operation, string $statusCode, string $errorMessage): void
    {
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'       => 'push',
                'entity_type'     => 'journal_entry',
                'entity_id'       => $ffJeId,
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
            error_log("JournalEntryPusher: writeNonHttpSyncLog failed for ff_journal_entry_id={$ffJeId}: " . $e->getMessage());
        }
    }
}
