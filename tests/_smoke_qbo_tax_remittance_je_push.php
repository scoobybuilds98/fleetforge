<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_tax_remittance_je_push.php
 *
 * Smoke test for S-QBO-23 Tax Remittance JE sync (Phase QBO-11 / 2 of 2).
 *
 * Covers tax_remittance JE flow through the EXISTING JournalEntryPusher
 * (no new Pusher class — spec §8.15 mandates tax remittance JEs push as
 * standard JE per §8.10). Sibling of S-QBO-22 (Fixed Asset JE sync).
 *
 * Per [[extensive-test-and-full-report]]: sub-checks organized as
 * PER-FUNCTION coverage — every NEW/CHANGED public method on
 * JournalEntryPusher gets a named sub-check exercising happy path + edge
 * cases + invariants. CRITICAL additions vs S-QBO-22: regression checks
 * that the D-QBO-23-1 dispatcher refactor did NOT break S-QBO-22's FA
 * enrichment (C9 routes depreciation through the dispatcher; C13 confirms
 * an FA JE still gets its FA-DEP PrivateNote section end-to-end).
 *
 * Sub-check map:
 *
 *   Module A — Constants + bridge regression guard (D-QBO-23-4)
 *     C1  TAX_REMITTANCE_SOURCE_TYPES constant = ['tax_remittance']
 *     C2  TAX_REMITTANCE_SOURCE_TYPES ∩ Pusher::BRIDGE_DERIVED = ∅
 *     C3  TAX_REMITTANCE_SOURCE_TYPES ∩ Enqueuer::BRIDGE_DERIVED = ∅
 *     C4  enrichedSourceTypes() = FA ∪ tax_remittance (4 values)
 *
 *   Module B — buildTaxRemittanceNoteSection (happy + missing + invalid)
 *     C5  happy: "TAX-REMIT remit#X type=gst_hst period=A..B amount=$Y method=Z"
 *     C6  non-existent remittance id → '' (graceful)
 *     C7  invalid source_id (0) → ''
 *
 *   Module C — buildSourceNoteSection dispatcher (D-QBO-23-1)
 *     C8  routes 'tax_remittance' → TAX-REMIT section
 *     C9  routes 'depreciation' → FA-DEP section (REGRESSION — dispatcher
 *         didn't break S-QBO-22 FA routing)
 *     C10 unknown source_type → ''
 *     C11 source_id=0 → ''
 *
 *   Module D — PrivateNote integration via buildQboPayload
 *     C12 tax_remittance JE → PrivateNote contains "TAX-REMIT remit#X"
 *     C13 depreciation JE → PrivateNote STILL contains "FA-DEP" (REGRESSION)
 *     C14 manual JE → no TAX-REMIT and no FA-* prefix (regression guard)
 *     C15 tax_remittance JE with deleted remittance row → no throw, no
 *         section, generic "source=tax_remittance#X" kept (best-effort
 *         enrichment lock D-QBO-23-1)
 *
 *   Module E — Enqueuer accepts tax_remittance
 *     C16 source_type='tax_remittance' → Enqueuer accepts (queue row written)
 *
 *   Module F — Migration / marker setting
 *     C17 quickbooks.sync_mode.tax_remittance='inherit_je' seeded
 *
 *   Module G — API list endpoint source_filter=tax_remittance + KPIs
 *     C18 source_filter=tax_remittance SQL scope returns TR rows only
 *     C19 list.php emits tax_remittance_pushed + tax_remittance_total +
 *         source_filter=tax_remittance branch
 *
 * Fixtures use sentinel IDs 999990-999999, cleaned in finally.
 *
 * @session  S-QBO-23
 * @decision D-QBO-23-1 (PrivateNote enrichment dispatcher),
 *           D-QBO-23-2 (no ENUM ALTER / no new Pusher),
 *           D-QBO-23-3 (admin UI source-type chip group + TR KPIs),
 *           D-QBO-23-4 (tax_remittance never bridge-derived — regression guard)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\JournalEntryPusher;
use FleetForge\QboPushers\JournalEntryEnqueuer;

$pass = 0;
$total = 19;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_tr_set_setting(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function ff_smoke_tr_get_setting(string $key): ?string
{
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $row['value'] ?? null;
}

function ff_smoke_tr_cleanup(): void
{
    // JE map + lines + entries first
    db_execute("DELETE FROM acc_qbo_sync_log           WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue         WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entry_lines   WHERE journal_entry_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entries       WHERE id BETWEEN 999990 AND 999999");
    // Tax remittance subledger (FK to filing periods)
    db_execute("DELETE FROM acc_tax_remittances        WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_tax_filing_periods     WHERE id BETWEEN 999990 AND 999999");
    // FA chain (for the C9 + C13 regression checks that route depreciation)
    db_execute("DELETE FROM acc_depreciation_run_lines WHERE run_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_depreciation_runs      WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_account_map        WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts               WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_periods                WHERE year = 9999 AND month = 11");
}

/**
 * Seed: filing period + remittance + 2 GL accounts (+ QBO maps) + a
 * depreciation run for the FA regression checks (C9/C13).
 *
 * Returns ['remittance_id'=>999990, 'run_id'=>999990, 'period_id'=>N].
 */
function ff_smoke_tr_seed(): array
{
    // GL accounts for JE lines + QBO maps (so buildQboPayload step 4 passes)
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999990, '2030-SMK-TR', 'Smoke GST Payable', 'liability', 'current_liability', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999991, '1010-SMK-TR', 'Smoke Cash', 'asset', 'current_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999992, '6500-SMK-TR', 'Smoke Depr Expense', 'operating_expense', 'operating_expense', 0, 1)"
    );
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999990, 'A-GST-TR-9000', 'mapped')");
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999991, 'A-CASH-TR-9000','mapped')");
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999992, 'A-DEP-TR-9000', 'mapped')");

    // Tax filing period (all NOT NULL columns supplied)
    db_execute(
        "INSERT INTO acc_tax_filing_periods
            (id, tax_type, period_start, period_end, filing_due_date, frequency,
             total_sales, total_tax_collected, total_itc, net_tax_owing, status)
         VALUES (999990, 'gst_hst', '2026-01-01', '2026-03-31', '2026-04-30', 'quarterly',
                 '250000.00', '12500.00', '0.00', '12500.00', 'remitted')"
    );

    // Tax remittance (source_id target for the tax_remittance JE)
    db_execute(
        "INSERT INTO acc_tax_remittances
            (id, filing_period_id, remittance_date, amount, payment_method, reference_number)
         VALUES (999990, 999990, '2026-04-15', '12500.00', 'online_banking', 'CRA-REF-SMOKE')"
    );

    // FA period + depreciation run for the C9/C13 regression checks
    db_execute(
        "INSERT INTO acc_periods (year, month, name, start_date, end_date, status)
         VALUES (9999, 11, 'Smoke TR Period 9999-11', '9999-11-01', '9999-11-30', 'open')"
    );
    $period = db_row("SELECT id FROM acc_periods WHERE year = 9999 AND month = 11");
    db_execute(
        "INSERT INTO acc_depreciation_runs (id, period_id, run_date, status, total_depreciation, asset_count, notes)
         VALUES (999990, ?, '2026-05-15 10:00:00', 'posted', '3000.00', 5, 'Smoke TR-regression run')",
        [(int) $period['id']]
    );

    return [
        'remittance_id' => 999990,
        'run_id'        => 999990,
        'period_id'     => (int) $period['id'],
    ];
}

/**
 * Seed a posted JE with given source_type/source_id. Default 2 balanced
 * lines (DR 999990 / CR 999991 for $12,500).
 */
function ff_smoke_tr_seed_je(int $jeId, string $sourceType, ?int $sourceId, array $overrides = []): int
{
    $entryNumber = $overrides['entry_number'] ?? "JE-TR-{$jeId}";
    $amount      = (string) ($overrides['amount'] ?? '12500.00');
    $debitAcct   = $overrides['debit_acct']  ?? 999990;
    $creditAcct  = $overrides['credit_acct'] ?? 999991;
    // Reuse the FA sentinel period if present, else any period.
    $period = db_row("SELECT id FROM acc_periods WHERE year = 9999 AND month = 11");
    $periodId = $period ? (int) $period['id'] : (int) (db_row("SELECT id FROM acc_periods ORDER BY id LIMIT 1")['id'] ?? 1);

    db_execute(
        "INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, entry_type,
                                          status, entry_status, description, source_type, source_id,
                                          currency, posted_at)
         VALUES (?, ?, ?, '2026-04-15', 'system', 'posted', 'posted', ?, ?, ?, 'CAD', NOW())",
        [$jeId, $entryNumber, $periodId, "Smoke TR JE {$jeId}", $sourceType, $sourceId]
    );
    db_execute(
        "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
         VALUES (?, ?, 1, 'TR debit',  ?, '0.00')",
        [$jeId, $debitAcct, $amount]
    );
    db_execute(
        "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
         VALUES (?, ?, 2, 'TR credit', '0.00', ?)",
        [$jeId, $creditAcct, $amount]
    );
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
    $snapshot[$k] = ff_smoke_tr_get_setting($k);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-23 Tax Remittance JE Sync Smoke (19 sub-checks)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_tr_cleanup();
    $ids = ff_smoke_tr_seed();
    ff_smoke_tr_set_setting('quickbooks.connection_status', 'connected');
    ff_smoke_tr_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_tr_set_setting('quickbooks.sync_mode.journal_entry', 'queue');
    ff_smoke_tr_set_setting('quickbooks.multi_currency_enabled', '0');

    // ══════════════════════════════════════════════════════════════════════
    // Module A — Constants + bridge regression guard (D-QBO-23-4)
    // ══════════════════════════════════════════════════════════════════════

    // ── C1: TAX_REMITTANCE_SOURCE_TYPES constant ──────────────────────────
    $c1Errors = [];
    $ref = new ReflectionClass(JournalEntryPusher::class);
    if (!$ref->hasConstant('TAX_REMITTANCE_SOURCE_TYPES')) {
        $c1Errors[] = 'TAX_REMITTANCE_SOURCE_TYPES const missing';
    } else {
        $tr = JournalEntryPusher::TAX_REMITTANCE_SOURCE_TYPES;
        if ($tr !== ['tax_remittance']) {
            $c1Errors[] = "expected ['tax_remittance']; got " . json_encode($tr);
        }
    }
    if (empty($c1Errors)) { echo "PASS C1 TAX_REMITTANCE_SOURCE_TYPES constant (D-QBO-23-1)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

    // ── C2: TR ∩ Pusher::BRIDGE_DERIVED = ∅ ───────────────────────────────
    $bridge = (new ReflectionClass(JournalEntryPusher::class))->getConstant('BRIDGE_DERIVED_SOURCE_TYPES');
    $tr = JournalEntryPusher::TAX_REMITTANCE_SOURCE_TYPES;
    $overlap = array_intersect($tr, $bridge ?: []);
    if (empty($overlap)) { echo "PASS C2 TR ∩ Pusher::BRIDGE_DERIVED = ∅ (D-QBO-23-4)\n"; $pass++; }
    else { echo "FAIL C2 overlap=" . json_encode(array_values($overlap)) . "\n"; $failures[] = 'C2'; }

    // ── C3: TR ∩ Enqueuer::BRIDGE_DERIVED = ∅ ─────────────────────────────
    $c3Errors = [];
    $enqConst = (new ReflectionClass(JournalEntryEnqueuer::class))->getReflectionConstant('BRIDGE_DERIVED_SOURCE_TYPES');
    if (!$enqConst) {
        $c3Errors[] = 'Enqueuer::BRIDGE_DERIVED_SOURCE_TYPES missing';
    } else {
        $overlap = array_intersect($tr, $enqConst->getValue() ?: []);
        if (!empty($overlap)) { $c3Errors[] = 'overlap=' . json_encode(array_values($overlap)); }
    }
    if (empty($c3Errors)) { echo "PASS C3 TR ∩ Enqueuer::BRIDGE_DERIVED = ∅ (D-QBO-23-4)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

    // ── C4: enrichedSourceTypes() = FA ∪ tax_remittance ───────────────────
    $c4Errors = [];
    if (!method_exists(JournalEntryPusher::class, 'enrichedSourceTypes')) {
        $c4Errors[] = 'enrichedSourceTypes() method missing';
    } else {
        $enriched = JournalEntryPusher::enrichedSourceTypes();
        $expected = array_merge(JournalEntryPusher::FA_SOURCE_TYPES, JournalEntryPusher::TAX_REMITTANCE_SOURCE_TYPES);
        sort($enriched); sort($expected);
        if ($enriched !== $expected) {
            $c4Errors[] = 'expected ' . json_encode($expected) . ' got ' . json_encode($enriched);
        }
    }
    if (empty($c4Errors)) { echo "PASS C4 enrichedSourceTypes() = FA ∪ tax_remittance (4 values)\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module B — buildTaxRemittanceNoteSection
    // ══════════════════════════════════════════════════════════════════════

    // ── C5: happy path ────────────────────────────────────────────────────
    $c5Errors = [];
    $section = JournalEntryPusher::buildTaxRemittanceNoteSection($ids['remittance_id']);
    if (strpos($section, "TAX-REMIT remit#{$ids['remittance_id']}") !== 0) $c5Errors[] = "expected 'TAX-REMIT remit#X' prefix; got: " . substr($section, 0, 90);
    if (strpos($section, 'type=gst_hst') === false)            $c5Errors[] = "expected type=gst_hst";
    if (strpos($section, 'period=2026-01-01..2026-03-31') === false) $c5Errors[] = "expected period span";
    if (strpos($section, 'amount=$12500.00') === false)        $c5Errors[] = "expected amount=\$12500.00";
    if (strpos($section, 'method=online_banking') === false)   $c5Errors[] = "expected method=online_banking";
    if (empty($c5Errors)) { echo "PASS C5 buildTaxRemittanceNoteSection(happy) — TAX-REMIT format\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $c5Errors) . " — got: " . substr($section, 0, 140) . "\n"; $failures[] = 'C5'; }

    // ── C6: non-existent remittance id → '' ───────────────────────────────
    $section = JournalEntryPusher::buildTaxRemittanceNoteSection(999998);
    if ($section === '') { echo "PASS C6 buildTaxRemittanceNoteSection(missing) → '' (graceful)\n"; $pass++; }
    else { echo "FAIL C6 expected ''; got: " . substr($section, 0, 80) . "\n"; $failures[] = 'C6'; }

    // ── C7: invalid source_id (0) → '' ────────────────────────────────────
    $section = JournalEntryPusher::buildTaxRemittanceNoteSection(0);
    if ($section === '') { echo "PASS C7 buildTaxRemittanceNoteSection(0) → '' (invalid id guard)\n"; $pass++; }
    else { echo "FAIL C7 expected ''; got: {$section}\n"; $failures[] = 'C7'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module C — buildSourceNoteSection dispatcher (D-QBO-23-1)
    // ══════════════════════════════════════════════════════════════════════

    // ── C8: dispatcher routes tax_remittance → TAX-REMIT ──────────────────
    $section = JournalEntryPusher::buildSourceNoteSection('tax_remittance', $ids['remittance_id']);
    if (strpos($section, 'TAX-REMIT') === 0) { echo "PASS C8 buildSourceNoteSection routes 'tax_remittance' → TAX-REMIT\n"; $pass++; }
    else { echo "FAIL C8 expected TAX-REMIT; got: " . substr($section, 0, 80) . "\n"; $failures[] = 'C8'; }

    // ── C9: dispatcher routes depreciation → FA-DEP (REGRESSION) ──────────
    $section = JournalEntryPusher::buildSourceNoteSection('depreciation', $ids['run_id']);
    if (strpos($section, 'FA-DEP') === 0) { echo "PASS C9 buildSourceNoteSection routes 'depreciation' → FA-DEP (S-QBO-22 regression OK)\n"; $pass++; }
    else { echo "FAIL C9 dispatcher broke FA routing; got: " . substr($section, 0, 80) . "\n"; $failures[] = 'C9'; }

    // ── C10: dispatcher unknown source_type → '' ──────────────────────────
    $section = JournalEntryPusher::buildSourceNoteSection('manual', 42);
    if ($section === '') { echo "PASS C10 buildSourceNoteSection('manual') → '' (no enrichment)\n"; $pass++; }
    else { echo "FAIL C10 expected ''; got: {$section}\n"; $failures[] = 'C10'; }

    // ── C11: dispatcher source_id=0 → '' ──────────────────────────────────
    $section = JournalEntryPusher::buildSourceNoteSection('tax_remittance', 0);
    if ($section === '') { echo "PASS C11 buildSourceNoteSection(*, 0) → '' (invalid id guard)\n"; $pass++; }
    else { echo "FAIL C11 expected ''; got: {$section}\n"; $failures[] = 'C11'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module D — PrivateNote integration via buildQboPayload
    // ══════════════════════════════════════════════════════════════════════

    ff_smoke_tr_seed_je(999990, 'tax_remittance', $ids['remittance_id']);
    ff_smoke_tr_seed_je(999991, 'depreciation', $ids['run_id'], ['debit_acct' => 999992, 'credit_acct' => 999990, 'amount' => '3000.00']);
    ff_smoke_tr_seed_je(999992, 'manual', null);

    // ── C12: tax_remittance JE → PrivateNote contains TAX-REMIT ───────────
    $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $payload = JournalEntryPusher::buildQboPayload($ffRow);
    $note = (string) ($payload['PrivateNote'] ?? '');
    if (strpos($note, 'TAX-REMIT') !== false && strpos($note, "remit#{$ids['remittance_id']}") !== false) {
        echo "PASS C12 tax_remittance JE PrivateNote contains TAX-REMIT section\n"; $pass++;
    } else { echo "FAIL C12 PrivateNote missing TAX-REMIT; got: {$note}\n"; $failures[] = 'C12'; }

    // ── C13: depreciation JE → PrivateNote STILL contains FA-DEP (REGRESSION) ──
    $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999991");
    $payload = JournalEntryPusher::buildQboPayload($ffRow);
    $note = (string) ($payload['PrivateNote'] ?? '');
    if (strpos($note, 'FA-DEP') !== false) {
        echo "PASS C13 depreciation JE PrivateNote STILL contains FA-DEP (dispatcher refactor regression OK)\n"; $pass++;
    } else { echo "FAIL C13 dispatcher refactor broke FA enrichment; got: {$note}\n"; $failures[] = 'C13'; }

    // ── C14: manual JE → no TAX-REMIT, no FA-* ────────────────────────────
    $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999992");
    $payload = JournalEntryPusher::buildQboPayload($ffRow);
    $note = (string) ($payload['PrivateNote'] ?? '');
    if (strpos($note, 'TAX-REMIT') === false && strpos($note, 'FA-DEP') === false && strpos($note, 'FA-DISP') === false && strpos($note, 'FA-IMP') === false) {
        echo "PASS C14 manual JE PrivateNote has NO enrichment section (regression guard)\n"; $pass++;
    } else { echo "FAIL C14 manual JE leaked enrichment; got: {$note}\n"; $failures[] = 'C14'; }

    // ── C15: tax_remittance JE with deleted remittance row → graceful ─────
    ff_smoke_tr_seed_je(999993, 'tax_remittance', 999988 /* non-existent */);
    try {
        $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999993");
        $payload = JournalEntryPusher::buildQboPayload($ffRow);
        $note = (string) ($payload['PrivateNote'] ?? '');
        if (strpos($note, 'TAX-REMIT') === false && strpos($note, 'source=tax_remittance#999988') !== false) {
            echo "PASS C15 TR JE with missing source row → no throw, generic source attribution kept\n"; $pass++;
        } else {
            echo "FAIL C15 unexpected PrivateNote shape; got: {$note}\n"; $failures[] = 'C15';
        }
    } catch (\Throwable $e) {
        echo "FAIL C15 buildQboPayload threw on missing TR source row: " . $e->getMessage() . "\n";
        $failures[] = 'C15';
    }

    // ══════════════════════════════════════════════════════════════════════
    // Module E — Enqueuer accepts tax_remittance
    // ══════════════════════════════════════════════════════════════════════

    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999993");

    // ── C16: source_type='tax_remittance' → Enqueuer accepts ──────────────
    $ok = JournalEntryEnqueuer::enqueue(999990, 'create');
    $queued = (int) (db_row("SELECT COUNT(*) AS c FROM acc_qbo_sync_queue WHERE entity_type = 'journal_entry' AND entity_id = 999990 AND status = 'queued'")['c'] ?? 0);
    if ($ok === true && $queued === 1) { echo "PASS C16 Enqueuer accepts source_type='tax_remittance' (not bridge-derived)\n"; $pass++; }
    else { echo "FAIL C16 Enqueuer rejected; ok=" . var_export($ok, true) . ", queued={$queued}\n"; $failures[] = 'C16'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module F — Migration / marker setting
    // ══════════════════════════════════════════════════════════════════════

    // ── C17: quickbooks.sync_mode.tax_remittance='inherit_je' seeded ──────
    $marker = ff_smoke_tr_get_setting('quickbooks.sync_mode.tax_remittance');
    if ($marker === 'inherit_je') { echo "PASS C17 quickbooks.sync_mode.tax_remittance='inherit_je' seeded (migration applied)\n"; $pass++; }
    else { echo "FAIL C17 expected 'inherit_je'; got: " . var_export($marker, true) . "\n"; $failures[] = 'C17'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module G — API list endpoint source_filter=tax_remittance + KPIs
    // ══════════════════════════════════════════════════════════════════════

    db_execute(
        "INSERT INTO acc_qbo_journal_entry_map (ff_journal_entry_id, push_status) VALUES
            (999990, 'pushed'),
            (999991, 'pushed'),
            (999992, 'pending')"
    );

    // ── C18: source_filter=tax_remittance SQL scope ───────────────────────
    $trTypes = JournalEntryPusher::TAX_REMITTANCE_SOURCE_TYPES;
    $placeholders = implode(',', array_fill(0, count($trTypes), '?'));
    $trScoped = db_select(
        "SELECT m.id, je.source_type
           FROM acc_qbo_journal_entry_map m
      LEFT JOIN acc_journal_entries je ON je.id = m.ff_journal_entry_id
          WHERE m.ff_journal_entry_id BETWEEN 999990 AND 999992
            AND je.source_type IN ({$placeholders})",
        $trTypes
    );
    $c18Errors = [];
    if (count($trScoped) !== 1) {
        $c18Errors[] = "expected 1 TR row (999990); got " . count($trScoped);
    }
    foreach ($trScoped as $r) {
        if ($r['source_type'] !== 'tax_remittance') $c18Errors[] = "leaked non-TR row source_type={$r['source_type']}";
    }
    if (empty($c18Errors)) { echo "PASS C18 source_filter=tax_remittance SQL scope returns TR rows only (D-QBO-23-3)\n"; $pass++; }
    else { echo "FAIL C18 " . implode('; ', $c18Errors) . "\n"; $failures[] = 'C18'; }

    // ── C19: list.php emits TR KPIs + source_filter branch ────────────────
    $c19Errors = [];
    $endpointSrc = (string) file_get_contents(__DIR__ . '/../api/v1/quickbooks/journal_entries/list.php');
    if (strpos($endpointSrc, "'tax_remittance_total'") === false)  $c19Errors[] = "list.php missing kpis['tax_remittance_total']";
    if (strpos($endpointSrc, "'tax_remittance_pushed'") === false) $c19Errors[] = "list.php missing kpis['tax_remittance_pushed']";
    if (strpos($endpointSrc, "=== 'tax_remittance'") === false)    $c19Errors[] = "list.php missing source_filter='tax_remittance' branch";
    if (empty($c19Errors)) { echo "PASS C19 list.php emits tax_remittance_pushed + tax_remittance_total + source_filter branch\n"; $pass++; }
    else { echo "FAIL C19 " . implode('; ', $c19Errors) . "\n"; $failures[] = 'C19'; }

} finally {
    ff_smoke_tr_cleanup();
    foreach ($snapshot as $k => $v) {
        if ($v === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_tr_set_setting($k, $v);
        }
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "RESULT: {$pass}/{$total} PASS\n";
if (!empty($failures)) {
    echo "FAILED: " . implode(', ', $failures) . "\n";
    exit(1);
}
exit(0);
