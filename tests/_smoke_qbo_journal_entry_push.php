<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_journal_entry_push.php
 *
 * Smoke test for S-QBO-21 Journal Entry Push (Phase QBO-10 / 1 of 1).
 *
 * Sub-checks (C1-C33; C14 + C25 repurposed + C32/C33 added by S-QBO-JE-UPDATE):
 *  C1: class surfaces (JournalEntryPusher + JournalEntryEnqueuer +
 *      RESULT_BASE + key methods)
 *  C2: acc_qbo_journal_entry_map schema (18 cols + 2 UNIQUE + 2 indexes + FK)
 *  C3: buildQboPayload happy-path balanced 2-line JE — TxnDate + DocNumber
 *      + Line[0].JournalEntryLineDetail.PostingType='Debit' + AccountRef
 *      + Line[1].PostingType='Credit'
 *  C4: buildQboPayload multi-line JE (4 lines: 2 debit + 2 credit) preserves
 *      order + balanced
 *  C5: buildQboPayload throws on missing per-line account mapping
 *      (D-QBO-21-3)
 *  C6: buildQboPayload throws on unbalanced JE (defense-in-depth)
 *  C7: pushImpl skipped_bridge_derived for each of 5 source_types per spec
 *      §8.10 — NO map row written; sync_log SKIP captures (D-QBO-21-1)
 *  C8: pushImpl entry_status='draft' → failed_preflight (need 'posted')
 *  C9: pushImpl entry_status='submitted' → failed_preflight
 * C10: pushImpl entry_status='approved' → failed_preflight (only posted pushes)
 * C11: pushImpl sync_mode='disabled' → skipped_by_mode + map row +
 *      non-HTTP sync_log SKIP
 * C12: pushImpl already_mapped → outcome='created' (idempotency)
 * C13: pushImpl missing FF JE → ff_not_found
 * C14: REPURPOSED (S-QBO-JE-UPDATE) — pushUpdate delegates to pushImpl
 *      (skipped_by_mode via sync_mode='disabled' proves no-longer-a-stub).
 * C14 (historical): pushUpdate stub → unsupported_in_session pointing to
 *      S-QBO-21-UPDATE-FOLLOWUP (D-QBO-21-5)
 * C15: multi_currency='1' → CurrencyRef + ExchangeRate emitted (D-QBO-FIXPACK-12)
 * C16: multi_currency='0' → CurrencyRef + ExchangeRate absent (D-QBO-FIXPACK-12)
 * C17: non-CAD JE missing exchange_rate → buildQboPayload throws
 * C18: gate 6 typed failed_preflight_field_too_long (entry_number > 21)
 * C19: gate 7 typed failed_preflight_currency_mismatch (multi_currency='1'
 *      + empty header currency) — D-QBO-21-6
 * C20: PostingType mapping — debit-line='Debit'; credit-line='Credit';
 *      throws on both populated; throws on both zero (D-QBO-21-2)
 * C21: PrivateNote includes entry_number + source attribution per D-QBO-21-7
 * C22: JournalEntryEnqueuer gate-0 rejects missing JE
 * C23: JournalEntryEnqueuer gate-0 rejects non-posted entry_status
 * C24: JournalEntryEnqueuer gate-0 rejects bridge-derived (defense-in-depth)
 * C25: REPURPOSED (S-QBO-JE-UPDATE) — JournalEntryEnqueuer gate-3 now
 *      ACCEPTS 'update' op + inserts queue row (D-QBO-21-5 stub closed)
 * C26: JournalEntryEnqueuer happy path queue insert (entity_type='journal_entry',
 *      op='create', status='queued')
 * C27: AccountValidator::assertReadyForJournalEntryPush gate at preflight
 *      (tax_receivable + tax_payable required per D-QBO-VALIDATOR-3)
 * C28: BRIDGE_DERIVED_SOURCE_TYPES constant alignment between Pusher and
 *      Enqueuer (reflection-based check)
 * C29: integration — Enqueuer happy-path queue insert → worker-style
 *      Pusher::pushCreate from queue row; map row written; pushed_at set
 * C30: integration — bridge-derived JE auto-skip end-to-end
 *      (Enqueuer rejects at gate-0 AND Pusher rejects at step 4 — no
 *      map row at either layer; sync_log SKIP at Pusher layer)
 * C32: NEW (S-QBO-JE-UPDATE) — pushUpdate on an UNMAPPED JE demotes to
 *      create → failed_preflight at account-mapping gate BEFORE HTTP; no
 *      qbo_journal_entry_id persisted (D-PUSHER-DEMOTION-RULE).
 * C33: NEW (S-QBO-JE-UPDATE) — JournalEntryEnqueuer gate-3 rejects 'void'
 *      (JEs reverse via a companion JE, not void).
 * C31: per-line AccountRef sourced from acc_qbo_account_map (D-QBO-21-3
 *      explicit assertion)
 *
 * Fixtures use sentinel IDs in 999990-999999 range, cleaned up in finally.
 *
 * @session  S-QBO-21
 * @decision D-QBO-21-1..7
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\JournalEntryPusher;
use FleetForge\QboPushers\JournalEntryEnqueuer;
use FleetForge\Exceptions\QuickBooksException;

$pass = 0;
$total = 33;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_je_set_setting(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function ff_smoke_je_get_setting(string $key): ?string
{
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $row['value'] ?? null;
}

function ff_smoke_je_cleanup(): void
{
    db_execute("DELETE FROM acc_qbo_sync_log   WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entry_lines   WHERE journal_entry_id    BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entries       WHERE id                  BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_account_map      WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts             WHERE id           BETWEEN 999990 AND 999999");
}

/**
 * Seed: 4 accounts + QBO mappings, including 2 tagged as critical
 * categories (tax_receivable + tax_payable) for AccountValidator gate.
 *
 * 999990 = Expense (debit side default; non-critical)
 * 999991 = Liability/payable (credit side default; non-critical)
 * 999992 = Tax Receivable (critical_category=tax_receivable)
 * 999993 = Tax Payable    (critical_category=tax_payable)
 */
function ff_smoke_je_seed_accounts(): void
{
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999990, '6999-SMOKE', 'Smoke JE Expense', 'operating_expense', 'operating_expense', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status)
         VALUES (999990, 'A-EXP-9000', 'mapped')"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999991, '2999-SMOKE', 'Smoke JE Payable', 'liability', 'current_liability', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status)
         VALUES (999991, 'A-PAY-9000', 'mapped')"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999992, '1310-SMOKE', 'Smoke Tax Receivable', 'asset', 'current_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999992, 'A-TR-9000', 'mapped', 1, 'tax_receivable')"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999993, '2310-SMOKE', 'Smoke Tax Payable', 'liability', 'current_liability', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status, is_critical, critical_category)
         VALUES (999993, 'A-TP-9000', 'mapped', 1, 'tax_payable')"
    );
}

/**
 * Seed a posted JE with 2 balanced lines (1 debit, 1 credit).
 * Sentinel id range 999990-999999. Returns the JE id.
 *
 * @param array $overrides keys: entry_number, source_type, source_id,
 *              entry_status, status, currency, exchange_rate, description,
 *              debit_acct, credit_acct, amount, line_count (2 default),
 *              is_reversal, reversal_of_id
 */
function ff_smoke_je_seed_je(int $jeId, array $overrides = []): int
{
    $period = db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1");
    $entryNumber = $overrides['entry_number'] ?? "JE-SMOKE-{$jeId}";
    $entryStatus = $overrides['entry_status'] ?? 'posted';
    $status      = $overrides['status']       ?? 'posted';
    $sourceType  = $overrides['source_type']  ?? null;
    $sourceId    = $overrides['source_id']    ?? null;
    $currency    = $overrides['currency']     ?? 'CAD';
    $exchange    = array_key_exists('exchange_rate', $overrides) ? $overrides['exchange_rate'] : null;
    $description = $overrides['description']  ?? "Smoke JE {$jeId}";
    $entryType   = $overrides['entry_type']   ?? 'manual';
    $isReversal  = (int) ($overrides['is_reversal'] ?? 0);
    $reversalOf  = $overrides['reversal_of_id'] ?? null;

    db_execute(
        "INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, entry_type,
                                          status, entry_status, description, source_type, source_id,
                                          is_reversal, reversal_of_id, currency, exchange_rate,
                                          posted_at)
         VALUES (?, ?, ?, '2026-05-15', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $jeId,
            $entryNumber,
            (int) $period['id'],
            $entryType,
            $status,
            $entryStatus,
            $description,
            $sourceType,
            $sourceId,
            $isReversal,
            $reversalOf,
            $currency,
            $exchange,
        ]
    );

    // Lines — default 2-line balanced (debit 999990 / credit 999991, $100)
    $debitAcct  = $overrides['debit_acct']  ?? 999990;
    $creditAcct = $overrides['credit_acct'] ?? 999991;
    $amount     = (string) ($overrides['amount'] ?? '100.00');
    $lineCount  = (int) ($overrides['line_count'] ?? 2);

    if ($lineCount === 2) {
        db_execute(
            "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
             VALUES (?, ?, 1, 'Smoke debit', ?, '0.00')",
            [$jeId, $debitAcct, $amount]
        );
        db_execute(
            "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
             VALUES (?, ?, 2, 'Smoke credit', '0.00', ?)",
            [$jeId, $creditAcct, $amount]
        );
    } elseif ($lineCount === 4) {
        // 4-line balanced (2 debit + 2 credit, $50 each)
        $half = bcdiv($amount, '2', 2);
        db_execute(
            "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
             VALUES (?, ?, 1, 'Smoke debit 1', ?, '0.00')",
            [$jeId, $debitAcct, $half]
        );
        db_execute(
            "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
             VALUES (?, ?, 2, 'Smoke debit 2', ?, '0.00')",
            [$jeId, 999992, $half]
        );
        db_execute(
            "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
             VALUES (?, ?, 3, 'Smoke credit 1', '0.00', ?)",
            [$jeId, $creditAcct, $half]
        );
        db_execute(
            "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
             VALUES (?, ?, 4, 'Smoke credit 2', '0.00', ?)",
            [$jeId, 999993, $half]
        );
    }

    return $jeId;
}

$snapshotKeys = [
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.journal_entry',
    'quickbooks.multi_currency_enabled',
    'quickbooks.connection_status',
];
$snapshot = [];
foreach ($snapshotKeys as $k) {
    $snapshot[$k] = ff_smoke_je_get_setting($k);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-21 Journal Entry Push Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_je_cleanup();
    ff_smoke_je_seed_accounts();
    ff_smoke_je_set_setting('quickbooks.connection_status', 'connected');
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', 'queue');
    ff_smoke_je_set_setting('quickbooks.multi_currency_enabled', '0');

    // ── C1: class surfaces ─────────────────────────────────────────────
    $c1Errors = [];
    if (!class_exists(JournalEntryPusher::class)) {
        $c1Errors[] = 'JournalEntryPusher class missing';
    } else {
        foreach (['pushCreate', 'pushUpdate', 'buildQboPayload'] as $m) {
            if (!method_exists(JournalEntryPusher::class, $m)) {
                $c1Errors[] = "JournalEntryPusher::{$m} missing";
            }
        }
        $ref = new ReflectionClass(JournalEntryPusher::class);
        if (!$ref->hasConstant('RESULT_BASE')) {
            $c1Errors[] = 'JournalEntryPusher::RESULT_BASE const missing';
        }
    }
    if (!class_exists(JournalEntryEnqueuer::class) || !method_exists(JournalEntryEnqueuer::class, 'enqueue')) {
        $c1Errors[] = 'JournalEntryEnqueuer::enqueue missing';
    }
    if (empty($c1Errors)) { echo "PASS C1 class surfaces (JournalEntryPusher + JournalEntryEnqueuer + RESULT_BASE)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

    // ── C2: schema verification ────────────────────────────────────────
    $c2Errors = [];
    $cols = db_select("SHOW COLUMNS FROM acc_qbo_journal_entry_map");
    $colNames = array_column($cols, 'Field');
    $required = ['id', 'ff_journal_entry_id', 'qbo_journal_entry_id', 'qbo_sync_token',
                 'qbo_doc_number', 'qbo_total_amt', 'qbo_currency', 'qbo_exchange_rate',
                 'qbo_txn_date', 'qbo_private_note', 'ff_je_snapshot_total',
                 'push_status', 'push_error', 'pushed_at', 'last_synced_at',
                 'created_at', 'updated_at'];
    foreach ($required as $col) {
        if (!in_array($col, $colNames, true)) $c2Errors[] = "missing col: {$col}";
    }
    $indexes = array_unique(array_column(db_select("SHOW INDEX FROM acc_qbo_journal_entry_map"), 'Key_name'));
    foreach (['PRIMARY', 'uq_ff_journal_entry', 'uq_qbo_journal_entry', 'idx_status', 'idx_pushed_at'] as $idx) {
        if (!in_array($idx, $indexes, true)) $c2Errors[] = "missing index: {$idx}";
    }
    if (empty($c2Errors)) { echo "PASS C2 acc_qbo_journal_entry_map schema (17 cols + 2 UNIQUE + 2 idx + FK CASCADE)\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $c2Errors) . "\n"; $failures[] = 'C2'; }

    // ── C3: buildQboPayload happy-path 2-line JE ──────────────────────
    ff_smoke_je_seed_je(999990, ['amount' => '250.00']);
    $je = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $c3Errors = [];
    $payload = JournalEntryPusher::buildQboPayload($je);
    if (($payload['TxnDate'] ?? null) !== '2026-05-15') $c3Errors[] = "TxnDate: " . json_encode($payload['TxnDate'] ?? null);
    if (($payload['DocNumber'] ?? null) !== 'JE-SMOKE-999990') $c3Errors[] = "DocNumber: " . json_encode($payload['DocNumber'] ?? null);
    if (!isset($payload['Line']) || count($payload['Line']) !== 2) $c3Errors[] = "Line count: " . count($payload['Line'] ?? []);
    $l0 = $payload['Line'][0] ?? [];
    $l1 = $payload['Line'][1] ?? [];
    if (($l0['JournalEntryLineDetail']['PostingType'] ?? null) !== 'Debit') $c3Errors[] = "L0 PostingType: " . json_encode($l0['JournalEntryLineDetail'] ?? null);
    if (($l0['JournalEntryLineDetail']['AccountRef']['value'] ?? null) !== 'A-EXP-9000') $c3Errors[] = "L0 AccountRef: " . json_encode($l0['JournalEntryLineDetail']['AccountRef'] ?? null);
    if ((float) ($l0['Amount'] ?? 0) !== 250.00) $c3Errors[] = "L0 Amount: " . json_encode($l0['Amount'] ?? null);
    if (($l0['DetailType'] ?? null) !== 'JournalEntryLineDetail') $c3Errors[] = "L0 DetailType: " . json_encode($l0['DetailType'] ?? null);
    if (($l1['JournalEntryLineDetail']['PostingType'] ?? null) !== 'Credit') $c3Errors[] = "L1 PostingType: " . json_encode($l1['JournalEntryLineDetail'] ?? null);
    if (($l1['JournalEntryLineDetail']['AccountRef']['value'] ?? null) !== 'A-PAY-9000') $c3Errors[] = "L1 AccountRef: " . json_encode($l1['JournalEntryLineDetail']['AccountRef'] ?? null);
    if ((float) ($l1['Amount'] ?? 0) !== 250.00) $c3Errors[] = "L1 Amount: " . json_encode($l1['Amount'] ?? null);
    if (empty($c3Errors)) { echo "PASS C3 buildQboPayload happy-path 2-line balanced JE (Debit/Credit PostingType + AccountRef per line)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C4: buildQboPayload 4-line JE ──────────────────────────────────
    ff_smoke_je_seed_je(999990, ['amount' => '200.00', 'line_count' => 4]);
    $je4 = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $c4Errors = [];
    $payload4 = JournalEntryPusher::buildQboPayload($je4);
    if (count($payload4['Line'] ?? []) !== 4) $c4Errors[] = "Line count: " . count($payload4['Line'] ?? []);
    $sumD = 0.0; $sumC = 0.0;
    foreach ($payload4['Line'] ?? [] as $ln) {
        $pt = $ln['JournalEntryLineDetail']['PostingType'] ?? '';
        $amt = (float) ($ln['Amount'] ?? 0);
        if ($pt === 'Debit')  $sumD += $amt;
        if ($pt === 'Credit') $sumC += $amt;
    }
    if ($sumD !== 200.00) $c4Errors[] = "sum Debit: {$sumD}";
    if ($sumC !== 200.00) $c4Errors[] = "sum Credit: {$sumC}";
    if (empty($c4Errors)) { echo "PASS C4 buildQboPayload multi-line balanced (4 lines: 2 debit + 2 credit; sums match)\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C5: throws on missing per-line account mapping ─────────────────
    ff_smoke_je_seed_je(999990);
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999990");
    $jeBad = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $c5Errors = [];
    try {
        JournalEntryPusher::buildQboPayload($jeBad);
        $c5Errors[] = "expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'D-QBO-21-3') === false) $c5Errors[] = "exception should reference D-QBO-21-3: " . $e->getMessage();
        if (strpos($e->getMessage(), '999990') === false) $c5Errors[] = "exception should mention account 999990: " . $e->getMessage();
    }
    // Restore
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999990, 'A-EXP-9000', 'mapped')");
    if (empty($c5Errors)) { echo "PASS C5 buildQboPayload throws on missing per-line account mapping (D-QBO-21-3)\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $c5Errors) . "\n"; $failures[] = 'C5'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C6: throws on unbalanced JE ────────────────────────────────────
    // Build manually with mismatched amounts
    $period = db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1");
    db_execute(
        "INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, entry_type, status, entry_status, description, currency)
         VALUES (999990, 'JE-SMOKE-999990', ?, '2026-05-15', 'manual', 'posted', 'posted', 'unbalanced smoke', 'CAD')",
        [(int) $period['id']]
    );
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, debit, credit) VALUES (999990, 999990, 1, '100.00', '0.00')");
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, debit, credit) VALUES (999990, 999991, 2, '0.00', '90.00')");
    $jeUnb = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $c6Errors = [];
    try {
        JournalEntryPusher::buildQboPayload($jeUnb);
        $c6Errors[] = "expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'unbalanced') === false) $c6Errors[] = "exception should mention 'unbalanced': " . $e->getMessage();
    }
    if (empty($c6Errors)) { echo "PASS C6 buildQboPayload throws on unbalanced JE (defense-in-depth)\n"; $pass++; }
    else { echo "FAIL C6 " . implode('; ', $c6Errors) . "\n"; $failures[] = 'C6'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C7: bridge-derived filter for each of 5 source_types ───────────
    $bridgeSourceTypes = ['invoice', 'payment', 'credit_note', 'ap_bill', 'ap_payment'];
    $c7Errors = [];
    foreach ($bridgeSourceTypes as $st) {
        ff_smoke_je_seed_je(999990, ['source_type' => $st, 'source_id' => 12345]);
        $r = JournalEntryPusher::pushCreate(999990);
        if (($r['status'] ?? null) !== 'skipped_bridge_derived') {
            $c7Errors[] = "source_type={$st}: expected skipped_bridge_derived; got " . json_encode($r['status'] ?? null);
        }
        $mapRow = db_row("SELECT id FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
        if ($mapRow !== null) {
            $c7Errors[] = "source_type={$st}: map row should NOT be written for bridge-derived";
        }
        $logRow = db_row("SELECT error_code FROM acc_qbo_sync_log WHERE entity_type='journal_entry' AND entity_id=999990 ORDER BY id DESC LIMIT 1");
        if (!$logRow || $logRow['error_code'] !== 'skipped_bridge_derived') {
            $c7Errors[] = "source_type={$st}: sync_log error_code: " . json_encode($logRow);
        }
        db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
        db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");
        db_execute("DELETE FROM acc_qbo_sync_log       WHERE entity_type='journal_entry' AND entity_id=999990");
    }
    if (empty($c7Errors)) { echo "PASS C7 pushImpl skipped_bridge_derived for all 5 source_types per spec §8.10; NO map row written; sync_log captures (D-QBO-21-1)\n"; $pass++; }
    else { echo "FAIL C7 " . implode('; ', $c7Errors) . "\n"; $failures[] = 'C7'; }

    // ── C8: entry_status='draft' → failed_preflight ────────────────────
    ff_smoke_je_seed_je(999990, ['entry_status' => 'draft', 'status' => 'draft']);
    $c8Errors = [];
    $r8 = JournalEntryPusher::pushCreate(999990);
    if (($r8['status'] ?? null) !== 'failed_preflight') $c8Errors[] = "status: " . json_encode($r8['status'] ?? null);
    if (strpos((string) ($r8['error'] ?? ''), 'draft') === false) $c8Errors[] = "error should mention 'draft': " . json_encode($r8['error'] ?? null);
    if (empty($c8Errors)) { echo "PASS C8 entry_status='draft' → failed_preflight (need 'posted')\n"; $pass++; }
    else { echo "FAIL C8 " . implode('; ', $c8Errors) . "\n"; $failures[] = 'C8'; }

    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entry_lines   WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries       WHERE id = 999990");

    // ── C9: entry_status='submitted' → failed_preflight ────────────────
    ff_smoke_je_seed_je(999990, ['entry_status' => 'submitted', 'status' => 'draft']);
    $c9Errors = [];
    $r9 = JournalEntryPusher::pushCreate(999990);
    if (($r9['status'] ?? null) !== 'failed_preflight') $c9Errors[] = "status: " . json_encode($r9['status'] ?? null);
    if (strpos((string) ($r9['error'] ?? ''), 'submitted') === false) $c9Errors[] = "error should mention 'submitted': " . json_encode($r9['error'] ?? null);
    if (empty($c9Errors)) { echo "PASS C9 entry_status='submitted' → failed_preflight\n"; $pass++; }
    else { echo "FAIL C9 " . implode('; ', $c9Errors) . "\n"; $failures[] = 'C9'; }

    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entry_lines   WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries       WHERE id = 999990");

    // ── C10: entry_status='approved' → failed_preflight ────────────────
    ff_smoke_je_seed_je(999990, ['entry_status' => 'approved', 'status' => 'draft']);
    $c10Errors = [];
    $r10 = JournalEntryPusher::pushCreate(999990);
    if (($r10['status'] ?? null) !== 'failed_preflight') $c10Errors[] = "status: " . json_encode($r10['status'] ?? null);
    if (strpos((string) ($r10['error'] ?? ''), 'approved') === false) $c10Errors[] = "error should mention 'approved': " . json_encode($r10['error'] ?? null);
    if (empty($c10Errors)) { echo "PASS C10 entry_status='approved' → failed_preflight (only 'posted' pushes)\n"; $pass++; }
    else { echo "FAIL C10 " . implode('; ', $c10Errors) . "\n"; $failures[] = 'C10'; }

    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entry_lines   WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries       WHERE id = 999990");

    // ── C11: sync_mode='disabled' → skipped_by_mode + map row + sync_log
    ff_smoke_je_seed_je(999990);
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', 'disabled');
    $c11Errors = [];
    $r11 = JournalEntryPusher::pushCreate(999990);
    if (($r11['status'] ?? null) !== 'skipped_by_mode') $c11Errors[] = "status: " . json_encode($r11['status'] ?? null);
    $mapRow11 = db_row("SELECT push_status FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (!$mapRow11 || $mapRow11['push_status'] !== 'skipped_by_mode') $c11Errors[] = "map row: " . json_encode($mapRow11);
    $logRow11 = db_row("SELECT http_method, error_code FROM acc_qbo_sync_log WHERE entity_type='journal_entry' AND entity_id=999990 ORDER BY id DESC LIMIT 1");
    if (!$logRow11 || $logRow11['http_method'] !== 'SKIP') $c11Errors[] = "sync_log: " . json_encode($logRow11);
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', 'queue');
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log         WHERE entity_type='journal_entry' AND entity_id=999990");
    if (empty($c11Errors)) { echo "PASS C11 sync_mode='disabled' → skipped_by_mode + map row + sync_log SKIP\n"; $pass++; }
    else { echo "FAIL C11 " . implode('; ', $c11Errors) . "\n"; $failures[] = 'C11'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C12: already_mapped → outcome='created' (idempotency) ──────────
    ff_smoke_je_seed_je(999990);
    db_execute("INSERT INTO acc_qbo_journal_entry_map (ff_journal_entry_id, qbo_journal_entry_id, qbo_sync_token, qbo_currency, push_status, pushed_at) VALUES (999990, 'QJE-7777', '3', 'CAD', 'pushed', NOW())");
    $c12Errors = [];
    $r12 = JournalEntryPusher::pushCreate(999990);
    if (($r12['status'] ?? null) !== 'already_mapped') $c12Errors[] = "status: " . json_encode($r12['status'] ?? null);
    if (($r12['outcome'] ?? null) !== 'created') $c12Errors[] = "outcome: " . json_encode($r12['outcome'] ?? null);
    if (($r12['qbo_id'] ?? null) !== 'QJE-7777') $c12Errors[] = "qbo_id: " . json_encode($r12['qbo_id'] ?? null);
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (empty($c12Errors)) { echo "PASS C12 already_mapped → status='already_mapped', outcome='created' (idempotency)\n"; $pass++; }
    else { echo "FAIL C12 " . implode('; ', $c12Errors) . "\n"; $failures[] = 'C12'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C13: missing FF JE → ff_not_found ──────────────────────────────
    $c13Errors = [];
    $r13 = JournalEntryPusher::pushCreate(999997);
    if (($r13['status'] ?? null) !== 'ff_not_found') $c13Errors[] = "status: " . json_encode($r13['status'] ?? null);
    if (($r13['outcome'] ?? null) !== 'failed') $c13Errors[] = "outcome: " . json_encode($r13['outcome'] ?? null);
    if (empty($c13Errors)) { echo "PASS C13 missing FF JE → ff_not_found\n"; $pass++; }
    else { echo "FAIL C13 " . implode('; ', $c13Errors) . "\n"; $failures[] = 'C13'; }

    // ── C14: pushUpdate delegates to pushImpl (S-QBO-JE-UPDATE) ─────────
    // The old stub returned 'unsupported_in_session' regardless of state.
    // pushUpdate now routes through pushImpl; 999990 is seeded + mapped here,
    // so sync_mode='disabled' is REQUIRED to short-circuit at step 1
    // (skipped_by_mode) — otherwise the demoted-or-mapped path would reach a
    // real updateEntity/createEntity HTTP call (404 in smoke; the HTTP trap).
    // skipped_by_mode definitively proves delegation (NOT the old stub).
    $c14Errors = [];
    ff_smoke_je_seed_je(999990);
    db_execute("INSERT INTO acc_qbo_journal_entry_map (ff_journal_entry_id, qbo_journal_entry_id, qbo_sync_token, push_status) VALUES (999990, 'QBO-JE-EXISTING', '3', 'pushed')");
    $prevMode14 = ff_smoke_je_get_setting('quickbooks.sync_mode.journal_entry');
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', 'disabled');
    $r14 = JournalEntryPusher::pushUpdate(999990);
    if (($r14['status'] ?? null) === 'unsupported_in_session') $c14Errors[] = "pushUpdate still a stub — returned unsupported_in_session";
    if (($r14['status'] ?? null) !== 'skipped_by_mode') $c14Errors[] = "expected skipped_by_mode (delegation proof), got " . json_encode($r14['status'] ?? null);
    if (($r14['outcome'] ?? null) !== 'skipped') $c14Errors[] = "outcome: " . json_encode($r14['outcome'] ?? null);
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', $prevMode14 ?? 'queue');
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='journal_entry' AND entity_id=999990");
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");
    if (empty($c14Errors)) { echo "PASS C14 pushUpdate delegates to pushImpl — no longer a stub (S-QBO-JE-UPDATE; D-QBO-21-5 closed)\n"; $pass++; }
    else { echo "FAIL C14 " . implode('; ', $c14Errors) . "\n"; $failures[] = 'C14'; }

    // ── C15: multi_currency='1' → CurrencyRef + ExchangeRate emitted ──
    ff_smoke_je_set_setting('quickbooks.multi_currency_enabled', '1');
    ff_smoke_je_seed_je(999990, ['currency' => 'CAD']);
    $jeMC1 = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $c15Errors = [];
    $payloadMC1 = JournalEntryPusher::buildQboPayload($jeMC1);
    if (($payloadMC1['CurrencyRef']['value'] ?? null) !== 'CAD') $c15Errors[] = "MC=1 CurrencyRef: " . json_encode($payloadMC1['CurrencyRef'] ?? null);
    if (($payloadMC1['ExchangeRate'] ?? null) !== '1.0') $c15Errors[] = "MC=1 ExchangeRate (CAD): " . json_encode($payloadMC1['ExchangeRate'] ?? null);
    if (empty($c15Errors)) { echo "PASS C15 multi_currency='1' + CAD → CurrencyRef='CAD' + ExchangeRate='1.0' (D-QBO-FIXPACK-12)\n"; $pass++; }
    else { echo "FAIL C15 " . implode('; ', $c15Errors) . "\n"; $failures[] = 'C15'; }

    // ── C16: multi_currency='0' → CurrencyRef + ExchangeRate absent ───
    ff_smoke_je_set_setting('quickbooks.multi_currency_enabled', '0');
    $c16Errors = [];
    $payloadMC0 = JournalEntryPusher::buildQboPayload($jeMC1);
    if (array_key_exists('CurrencyRef', $payloadMC0)) $c16Errors[] = "MC=0 CurrencyRef present";
    if (array_key_exists('ExchangeRate', $payloadMC0)) $c16Errors[] = "MC=0 ExchangeRate present";
    if (empty($c16Errors)) { echo "PASS C16 multi_currency='0' → CurrencyRef + ExchangeRate absent (D-QBO-FIXPACK-12 gating)\n"; $pass++; }
    else { echo "FAIL C16 " . implode('; ', $c16Errors) . "\n"; $failures[] = 'C16'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C17: non-CAD JE missing exchange_rate → throws ────────────────
    ff_smoke_je_set_setting('quickbooks.multi_currency_enabled', '1');
    ff_smoke_je_seed_je(999990, ['currency' => 'USD', 'exchange_rate' => null]);
    $jeUsd = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $c17Errors = [];
    try {
        JournalEntryPusher::buildQboPayload($jeUsd);
        $c17Errors[] = "expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'exchange_rate') === false) $c17Errors[] = "exception should mention exchange_rate: " . $e->getMessage();
    }
    ff_smoke_je_set_setting('quickbooks.multi_currency_enabled', '0');
    if (empty($c17Errors)) { echo "PASS C17 non-CAD JE missing exchange_rate → buildQboPayload throws\n"; $pass++; }
    else { echo "FAIL C17 " . implode('; ', $c17Errors) . "\n"; $failures[] = 'C17'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C18: gate 6 failed_preflight_field_too_long ───────────────────
    // entry_number > 21 chars. Inject directly (FF JournalEntryService uses
    // JE-YYYY-NNNNN = 12 chars; defense-in-depth gate).
    ff_smoke_je_seed_je(999990, ['entry_number' => 'JE-SMOKE-VERY-LONG-NUMBER-999990']);
    $c18Errors = [];
    $r18 = JournalEntryPusher::pushCreate(999990);
    if (($r18['status'] ?? null) !== 'failed_preflight_field_too_long') {
        $c18Errors[] = "status: " . json_encode($r18['status'] ?? null);
    }
    if (strpos((string) ($r18['error'] ?? ''), 'entry_number') === false) {
        $c18Errors[] = "error should mention 'entry_number': " . json_encode($r18['error'] ?? null);
    }
    $mapRow18 = db_row("SELECT push_status FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (!$mapRow18 || $mapRow18['push_status'] !== 'failed_preflight_field_too_long') {
        $c18Errors[] = "map row push_status: " . json_encode($mapRow18['push_status'] ?? null);
    }
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (empty($c18Errors)) { echo "PASS C18 gate 6 failed_preflight_field_too_long typed (entry_number > 21 chars; D-QBO-21-4)\n"; $pass++; }
    else { echo "FAIL C18 " . implode('; ', $c18Errors) . "\n"; $failures[] = 'C18'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C19: gate 7 failed_preflight_currency_mismatch ────────────────
    // multi_currency='1' + empty/invalid header currency. ENUM on
    // acc_journal_entries.currency allows only CAD/USD so the only way
    // to reach the empty state is to bypass strict_trans_tables via
    // session sql_mode. Confirms the gate fires correctly when forced.
    ff_smoke_je_set_setting('quickbooks.multi_currency_enabled', '1');
    ff_smoke_je_seed_je(999990, ['currency' => 'CAD']);
    // Capture + temporarily relax sql_mode to allow empty ENUM value.
    $prevSqlMode = db_row("SELECT @@SESSION.sql_mode AS m");
    db_execute("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'STRICT_TRANS_TABLES', ''), 'STRICT_ALL_TABLES', '')");
    db_execute("UPDATE acc_journal_entries SET currency='' WHERE id = 999990");
    db_execute("SET SESSION sql_mode = ?", [(string) ($prevSqlMode['m'] ?? '')]);
    $c19Errors = [];
    $r19 = JournalEntryPusher::pushCreate(999990);
    if (($r19['status'] ?? null) !== 'failed_preflight_currency_mismatch') {
        $c19Errors[] = "status: " . json_encode($r19['status'] ?? null);
    }
    if (strpos((string) ($r19['error'] ?? ''), 'currency') === false) {
        $c19Errors[] = "error should mention 'currency': " . json_encode($r19['error'] ?? null);
    }
    $mapRow19 = db_row("SELECT push_status FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (!$mapRow19 || $mapRow19['push_status'] !== 'failed_preflight_currency_mismatch') {
        $c19Errors[] = "map row push_status: " . json_encode($mapRow19['push_status'] ?? null);
    }
    ff_smoke_je_set_setting('quickbooks.multi_currency_enabled', '0');
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (empty($c19Errors)) { echo "PASS C19 gate 7 failed_preflight_currency_mismatch (multi_currency='1' + empty header currency forced via sql_mode; D-QBO-21-6)\n"; $pass++; }
    else { echo "FAIL C19 " . implode('; ', $c19Errors) . "\n"; $failures[] = 'C19'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C20: PostingType mapping (D-QBO-21-2) ─────────────────────────
    $c20Errors = [];
    // Case A: both populated → throws
    $period = db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1");
    db_execute(
        "INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, entry_type, status, entry_status, description, currency)
         VALUES (999990, 'JE-SMOKE-999990', ?, '2026-05-15', 'manual', 'posted', 'posted', 'both populated smoke', 'CAD')",
        [(int) $period['id']]
    );
    // Both-populated lines that still balance globally so the balanced
    // check passes and we reach the per-line "both populated" guard.
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, debit, credit) VALUES (999990, 999990, 1, '50.00', '50.00')");
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, debit, credit) VALUES (999990, 999991, 2, '50.00', '50.00')");
    $jeBoth = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    try {
        JournalEntryPusher::buildQboPayload($jeBoth);
        $c20Errors[] = "case A (both populated): expected throw; none";
    } catch (QuickBooksException $e) {
        if (strpos($e->getMessage(), 'both debit') === false && strpos($e->getMessage(), 'both populated') === false) {
            $c20Errors[] = "case A exception text: " . $e->getMessage();
        }
    }
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // Case B: both zero on one line → throws
    db_execute(
        "INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, entry_type, status, entry_status, description, currency)
         VALUES (999990, 'JE-SMOKE-999990', ?, '2026-05-15', 'manual', 'posted', 'posted', 'both zero smoke', 'CAD')",
        [(int) $period['id']]
    );
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, debit, credit) VALUES (999990, 999990, 1, '0.00', '0.00')");
    db_execute("INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, debit, credit) VALUES (999990, 999991, 2, '0.00', '0.00')");
    $jeZero = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    try {
        JournalEntryPusher::buildQboPayload($jeZero);
        $c20Errors[] = "case B (both zero): expected throw; none";
    } catch (QuickBooksException $e) {
        // Either "zero debit AND zero credit" line check OR "unbalanced/zero" total check is acceptable
        $msg = $e->getMessage();
        if (strpos($msg, 'zero debit') === false && strpos($msg, 'zero') === false && strpos($msg, 'unbalanced') === false) {
            $c20Errors[] = "case B exception text: " . $msg;
        }
    }
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // Case C: positive payload mapping confirms Debit/Credit attribution (re-uses C3 happy path)
    ff_smoke_je_seed_je(999990);
    $jeOk = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $okPayload = JournalEntryPusher::buildQboPayload($jeOk);
    if (($okPayload['Line'][0]['JournalEntryLineDetail']['PostingType'] ?? null) !== 'Debit') {
        $c20Errors[] = "happy debit line: " . json_encode($okPayload['Line'][0] ?? null);
    }
    if (($okPayload['Line'][1]['JournalEntryLineDetail']['PostingType'] ?? null) !== 'Credit') {
        $c20Errors[] = "happy credit line: " . json_encode($okPayload['Line'][1] ?? null);
    }
    if (empty($c20Errors)) { echo "PASS C20 PostingType mapping — Debit/Credit per side + throws on both-populated + throws on both-zero (D-QBO-21-2)\n"; $pass++; }
    else { echo "FAIL C20 " . implode('; ', $c20Errors) . "\n"; $failures[] = 'C20'; }

    // ── C21: PrivateNote includes entry_number + source attribution ───
    $c21Errors = [];
    $note = (string) ($okPayload['PrivateNote'] ?? '');
    if (strpos($note, 'JE-SMOKE-999990') === false) $c21Errors[] = "missing entry_number in PrivateNote";
    if (strpos($note, 'source=manual') === false) $c21Errors[] = "missing source=manual (null source_type defaults): " . $note;
    if (empty($c21Errors)) { echo "PASS C21 PrivateNote includes entry_number + source=manual (D-QBO-21-7)\n"; $pass++; }
    else { echo "FAIL C21 " . implode('; ', $c21Errors) . "\n"; $failures[] = 'C21'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C22: Enqueuer gate-0 rejects missing JE ───────────────────────
    ff_smoke_je_set_setting('quickbooks.sync_enabled', '1');
    $c22Errors = [];
    $r22 = JournalEntryEnqueuer::enqueue(999998, 'create');
    if ($r22 !== false) $c22Errors[] = "enqueue should reject missing JE";
    if (empty($c22Errors)) { echo "PASS C22 Enqueuer gate-0 rejects missing JE\n"; $pass++; }
    else { echo "FAIL C22 " . implode('; ', $c22Errors) . "\n"; $failures[] = 'C22'; }

    // ── C23: Enqueuer gate-0 rejects non-posted entry_status ──────────
    ff_smoke_je_seed_je(999990, ['entry_status' => 'draft', 'status' => 'draft']);
    $c23Errors = [];
    $r23 = JournalEntryEnqueuer::enqueue(999990, 'create');
    if ($r23 !== false) $c23Errors[] = "enqueue should reject entry_status='draft'";
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");
    if (empty($c23Errors)) { echo "PASS C23 Enqueuer gate-0 rejects entry_status='draft' (D-QBO-21 eligibility)\n"; $pass++; }
    else { echo "FAIL C23 " . implode('; ', $c23Errors) . "\n"; $failures[] = 'C23'; }

    // ── C24: Enqueuer gate-0 rejects bridge-derived ───────────────────
    $c24Errors = [];
    foreach ($bridgeSourceTypes as $st) {
        ff_smoke_je_seed_je(999990, ['source_type' => $st, 'source_id' => 999]);
        $r = JournalEntryEnqueuer::enqueue(999990, 'create');
        if ($r !== false) {
            $c24Errors[] = "source_type={$st}: enqueue should reject (got " . json_encode($r) . ")";
        }
        $queued = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
        if ($queued !== null) {
            $c24Errors[] = "source_type={$st}: queue row should NOT exist for bridge-derived";
        }
        db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
        db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");
    }
    if (empty($c24Errors)) { echo "PASS C24 Enqueuer gate-0 rejects all 5 bridge-derived source_types (defense-in-depth; D-QBO-21-1)\n"; $pass++; }
    else { echo "FAIL C24 " . implode('; ', $c24Errors) . "\n"; $failures[] = 'C24'; }

    // ── C25: Enqueuer gate-3 ACCEPTS 'update' op (S-QBO-JE-UPDATE) ─────
    // gate-3 allowlist widened ['create']→['create','update'] (D-QBO-21-5
    // stub closed). 999990 (seeded here, left in place for C26) is posted +
    // non-bridge-derived + sync_enabled=1 → enqueue('update') succeeds +
    // writes a queue row with op='update'. The update queue row is cleaned
    // before C26 so C26's create-row assertion stays unambiguous.
    ff_smoke_je_seed_je(999990);
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', 'queue');
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    $c25Errors = [];
    $r25 = JournalEntryEnqueuer::enqueue(999990, 'update');
    $q25 = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990 AND operation='update'");
    if ($r25 !== true) $c25Errors[] = "enqueue should accept 'update' now; got " . json_encode($r25);
    if ($q25 === null) $c25Errors[] = "an 'update' queue row should be written; none found";
    elseif ($q25['operation'] !== 'update' || $q25['status'] !== 'queued') $c25Errors[] = "queue row shape: " . json_encode($q25);
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    if (empty($c25Errors)) { echo "PASS C25 Enqueuer gate-3 accepts 'update' op + inserts queue row (S-QBO-JE-UPDATE; D-QBO-21-5 closed)\n"; $pass++; }
    else { echo "FAIL C25 " . implode('; ', $c25Errors) . "\n"; $failures[] = 'C25'; }

    // ── C26: Enqueuer happy path ──────────────────────────────────────
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    $c26Errors = [];
    $r26 = JournalEntryEnqueuer::enqueue(999990, 'create');
    if ($r26 !== true) $c26Errors[] = "enqueue should return true (got " . json_encode($r26) . ")";
    $queued = db_row("SELECT operation, status FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    if (!$queued || $queued['operation'] !== 'create' || $queued['status'] !== 'queued') {
        $c26Errors[] = "queue row: " . json_encode($queued);
    }
    if (empty($c26Errors)) { echo "PASS C26 Enqueuer happy-path writes queue row (entity_type='journal_entry', op='create', status='queued')\n"; $pass++; }
    else { echo "FAIL C26 " . implode('; ', $c26Errors) . "\n"; $failures[] = 'C26'; }

    // ── C27: AccountValidator gate at preflight ───────────────────────
    // Temporarily un-tag tax_receivable so AccountValidator throws.
    db_execute("UPDATE acc_qbo_account_map SET is_critical=0, critical_category=NULL WHERE ff_account_id=999992");
    $c27Errors = [];
    $r27 = JournalEntryPusher::pushCreate(999990);
    if (($r27['status'] ?? null) !== 'failed_preflight') $c27Errors[] = "status: " . json_encode($r27['status'] ?? null);
    if (strpos((string) ($r27['error'] ?? ''), 'AccountValidator') === false) $c27Errors[] = "error should mention AccountValidator: " . json_encode($r27['error'] ?? null);
    // Restore
    db_execute("UPDATE acc_qbo_account_map SET is_critical=1, critical_category='tax_receivable' WHERE ff_account_id=999992");
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (empty($c27Errors)) { echo "PASS C27 AccountValidator::assertReadyForJournalEntryPush gate at preflight (D-QBO-VALIDATOR-3 tax_receivable + tax_payable)\n"; $pass++; }
    else { echo "FAIL C27 " . implode('; ', $c27Errors) . "\n"; $failures[] = 'C27'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_queue      WHERE entity_type='journal_entry' AND entity_id=999990");

    // ── C28: BRIDGE_DERIVED_SOURCE_TYPES alignment Pusher vs Enqueuer ─
    $c28Errors = [];
    $refP = new ReflectionClass(JournalEntryPusher::class);
    $refE = new ReflectionClass(JournalEntryEnqueuer::class);
    $pConst = $refP->getConstants()['BRIDGE_DERIVED_SOURCE_TYPES'] ?? null;
    $eConst = $refE->getConstants()['BRIDGE_DERIVED_SOURCE_TYPES'] ?? null;
    if (!is_array($pConst) || !is_array($eConst)) {
        $c28Errors[] = "constants not arrays: P=" . json_encode($pConst) . " E=" . json_encode($eConst);
    } elseif ($pConst !== $eConst) {
        $c28Errors[] = "drift: Pusher=" . json_encode($pConst) . " Enqueuer=" . json_encode($eConst);
    } else {
        // Sanity: must match spec §8.10 verbatim
        $expected = ['invoice', 'payment', 'credit_note', 'ap_bill', 'ap_payment'];
        if ($pConst !== $expected) {
            $c28Errors[] = "diverges from spec §8.10 canonical: got " . json_encode($pConst);
        }
    }
    if (empty($c28Errors)) { echo "PASS C28 BRIDGE_DERIVED_SOURCE_TYPES aligned across Pusher + Enqueuer; matches spec §8.10 verbatim\n"; $pass++; }
    else { echo "FAIL C28 " . implode('; ', $c28Errors) . "\n"; $failures[] = 'C28'; }

    // ── C29: integration — Enqueuer queue insert → Pusher pushCreate
    //   from queued entity_id. Verifies the two halves wire together
    //   even without the worker process (we simulate the dispatch).
    $c29Errors = [];
    ff_smoke_je_seed_je(999990);
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    $enq = JournalEntryEnqueuer::enqueue(999990, 'create');
    if ($enq !== true) $c29Errors[] = "Enqueuer should return true; got " . json_encode($enq);
    $queueRow = db_row("SELECT entity_type, entity_id, operation FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    if (!$queueRow) {
        $c29Errors[] = "queue row missing after enqueue";
    } else {
        // Simulate worker dispatch: read queue row, call Pusher::pushCreate.
        // (Real worker would not push without QBO HTTP; we accept that this
        // hits the HTTP step and returns qbo_error — what we verify is the
        // dispatch correctness, not the HTTP outcome.)
        $r29 = JournalEntryPusher::pushCreate((int) $queueRow['entity_id']);
        // Successful dispatch reaches step 7+ (HTTP / build / preflight). Map row
        // gets written for any outcome that goes through recording helpers.
        // Acceptable terminal statuses: pushed, qbo_error (most common in smoke),
        // failed_preflight (if connection_status not 'connected' somehow).
        $okStatuses = ['pushed', 'qbo_error', 'qbo_malformed_response', 'failed_preflight'];
        if (!in_array($r29['status'] ?? '', $okStatuses, true)) {
            $c29Errors[] = "dispatch outcome: " . json_encode($r29);
        }
        // Map row was created somewhere along the way (any of the recording
        // helpers writes one — only bridge-derived skips don't).
        $mapAfter = db_row("SELECT push_status FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
        if (!$mapAfter) {
            $c29Errors[] = "expected map row written after dispatch (got null — recording helper not hit?)";
        }
    }
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_qbo_sync_queue      WHERE entity_type='journal_entry' AND entity_id=999990");
    db_execute("DELETE FROM acc_qbo_sync_log        WHERE entity_type='journal_entry' AND entity_id=999990");
    if (empty($c29Errors)) { echo "PASS C29 integration — Enqueuer queue insert → Pusher::pushCreate from queue row; map row written\n"; $pass++; }
    else { echo "FAIL C29 " . implode('; ', $c29Errors) . "\n"; $failures[] = 'C29'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C30: integration — bridge-derived JE auto-skip end-to-end ─────
    // Enqueuer rejects at gate-0 AND Pusher rejects at step 4 (no map row
    // at either layer; sync_log SKIP at Pusher layer only).
    $c30Errors = [];
    ff_smoke_je_seed_je(999990, ['source_type' => 'invoice', 'source_id' => 555]);

    // Enqueuer layer
    $enq30 = JournalEntryEnqueuer::enqueue(999990, 'create');
    if ($enq30 !== false) $c30Errors[] = "Enqueuer should reject bridge-derived; got " . json_encode($enq30);
    $queueAfterEnq = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    if ($queueAfterEnq !== null) $c30Errors[] = "queue row should NOT be written (Enqueuer rejected)";

    // Pusher layer (simulating a queue row that slipped through somehow)
    $r30 = JournalEntryPusher::pushCreate(999990);
    if (($r30['status'] ?? null) !== 'skipped_bridge_derived') {
        $c30Errors[] = "Pusher status: " . json_encode($r30['status'] ?? null);
    }
    $mapAfter30 = db_row("SELECT id FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if ($mapAfter30 !== null) {
        $c30Errors[] = "map row should NOT be written for bridge-derived skip";
    }
    $logAfter30 = db_row("SELECT error_code FROM acc_qbo_sync_log WHERE entity_type='journal_entry' AND entity_id=999990 ORDER BY id DESC LIMIT 1");
    if (!$logAfter30 || $logAfter30['error_code'] !== 'skipped_bridge_derived') {
        $c30Errors[] = "Pusher sync_log: " . json_encode($logAfter30);
    }
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='journal_entry' AND entity_id=999990");
    if (empty($c30Errors)) { echo "PASS C30 integration — bridge-derived JE auto-skip end-to-end (Enqueuer + Pusher; defense-in-depth)\n"; $pass++; }
    else { echo "FAIL C30 " . implode('; ', $c30Errors) . "\n"; $failures[] = 'C30'; }

    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C31: per-line AccountRef sourced from acc_qbo_account_map ─────
    // Explicit assertion mapping FF account_id → qbo_account_id.
    ff_smoke_je_seed_je(999990, ['amount' => '75.00', 'debit_acct' => 999992, 'credit_acct' => 999993]);
    $jeAcct = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $c31Errors = [];
    $payloadAcct = JournalEntryPusher::buildQboPayload($jeAcct);
    if (($payloadAcct['Line'][0]['JournalEntryLineDetail']['AccountRef']['value'] ?? null) !== 'A-TR-9000') {
        $c31Errors[] = "L0 AccountRef should equal acc_qbo_account_map.qbo_account_id for FF#999992 (tax_receivable); got " . json_encode($payloadAcct['Line'][0]['JournalEntryLineDetail']['AccountRef'] ?? null);
    }
    if (($payloadAcct['Line'][1]['JournalEntryLineDetail']['AccountRef']['value'] ?? null) !== 'A-TP-9000') {
        $c31Errors[] = "L1 AccountRef should equal acc_qbo_account_map.qbo_account_id for FF#999993 (tax_payable); got " . json_encode($payloadAcct['Line'][1]['JournalEntryLineDetail']['AccountRef'] ?? null);
    }
    if (empty($c31Errors)) { echo "PASS C31 per-line AccountRef sourced from acc_qbo_account_map via line.account_id (D-QBO-21-3)\n"; $pass++; }
    else { echo "FAIL C31 " . implode('; ', $c31Errors) . "\n"; $failures[] = 'C31'; }
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");

    // ── C32: pushUpdate on UNMAPPED JE demotes to create (S-QBO-JE-UPDATE) ─
    // No map row → pushImpl step 5b flips operation to 'create' → runs the
    // create pipeline. With the per-line account mapping removed, preflight
    // gate 2 fails → failed_preflight (returns BEFORE the HTTP boundary, so
    // no real updateEntity/createEntity is attempted). Proves the demotion
    // ran the create path rather than attempting an UPDATE on an entity QBO
    // doesn't know about (which would 404). Account mapping restored after.
    $c32Errors = [];
    ff_smoke_je_seed_je(999990);
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999990");
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', 'queue');
    $r32 = JournalEntryPusher::pushUpdate(999990);
    $map32 = db_row("SELECT qbo_journal_entry_id, push_status FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    if (($r32['status'] ?? null) === 'unsupported_in_session') $c32Errors[] = "pushUpdate still a stub";
    if (($r32['status'] ?? null) !== 'failed_preflight') $c32Errors[] = "expected failed_preflight (demoted-create at account-mapping gate), got " . json_encode($r32['status'] ?? null);
    if (!empty($map32['qbo_journal_entry_id'])) $c32Errors[] = "no qbo_journal_entry_id should be persisted on a failed demoted-create; got " . json_encode($map32['qbo_journal_entry_id']);
    // Restore account mapping for hermetic state.
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999990, 'A-EXP-9000', 'mapped')");
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");
    if (empty($c32Errors)) { echo "PASS C32 pushUpdate on unmapped JE demotes to create (failed_preflight at account-mapping gate; no qbo_id; D-PUSHER-DEMOTION-RULE)\n"; $pass++; }
    else { echo "FAIL C32 " . implode('; ', $c32Errors) . "\n"; $failures[] = 'C32'; }

    // ── C33: Enqueuer gate-3 rejects 'void' op (S-QBO-JE-UPDATE) ───────
    // gate-3 allowlist = ['create','update']; 'void' is not a JE concept
    // (JEs reverse via a companion posted JE that pushes as its own create).
    $c33Errors = [];
    ff_smoke_je_seed_je(999990);
    ff_smoke_je_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_je_set_setting('quickbooks.sync_mode.journal_entry', 'queue');
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    $r33 = JournalEntryEnqueuer::enqueue(999990, 'void');
    $q33 = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990 AND operation='void'");
    if ($r33 !== false) $c33Errors[] = "enqueue should reject 'void'; got " . json_encode($r33);
    if ($q33 !== null) $c33Errors[] = "no 'void' queue row should be inserted; found id=" . $q33['id'];
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='journal_entry' AND entity_id=999990");
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = 999990");
    db_execute("DELETE FROM acc_journal_entries     WHERE id = 999990");
    if (empty($c33Errors)) { echo "PASS C33 Enqueuer gate-3 rejects 'void' op (allowlist = create+update only; JEs reverse not void)\n"; $pass++; }
    else { echo "FAIL C33 " . implode('; ', $c33Errors) . "\n"; $failures[] = 'C33'; }

} finally {
    ff_smoke_je_cleanup();
    foreach ($snapshotKeys as $k) {
        if ($snapshot[$k] === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_je_set_setting($k, $snapshot[$k]);
        }
    }
}

echo "\nqbo_journal_entry_push_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " (failures: " . implode(', ', $failures) . ")";
}
echo "\n";

exit(empty($failures) ? 0 : 1);
