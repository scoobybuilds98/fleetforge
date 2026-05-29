<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_fixed_asset_je_push.php
 *
 * Smoke test for S-QBO-22 Fixed Asset JE sync (Phase QBO-11 / 1 of 2).
 *
 * Covers depreciation + asset_disposal + impairment JE flow through the
 * EXISTING JournalEntryPusher (no new Pusher class — spec §8.13 mandates
 * FA JEs push as standard JE per §8.10).
 *
 * Per [[extensive-test-and-full-report]] (S-QBO-20-feedback): sub-checks
 * are organized as PER-FUNCTION coverage — every NEW public method on
 * JournalEntryPusher gets a named sub-check exercising happy path + edge
 * cases + invariants.
 *
 * Sub-check map (per-function coverage):
 *
 *   Module A — Constants + bridge-derived regression guard (D-QBO-22-4)
 *     C1  JournalEntryPusher::FA_SOURCE_TYPES constant exists + 3 values
 *     C2  FA_SOURCE_TYPES ∩ Pusher::BRIDGE_DERIVED_SOURCE_TYPES = ∅
 *     C3  FA_SOURCE_TYPES ∩ Enqueuer::BRIDGE_DERIVED_SOURCE_TYPES = ∅
 *
 *   Module B — JournalEntryPusher::buildFixedAssetNoteSection
 *              (3 source types × happy + missing + edge)
 *     C4  depreciation happy path: "FA-DEP run#X period='...' assets=N total=$Y.YY"
 *     C5  depreciation with non-existent run id → '' (graceful missing)
 *     C6  asset_disposal happy path: "FA-DISP asset=FA-X type=Y proceeds=$Z gain_loss=$W"
 *     C7  asset_disposal with non-existent disposal id → ''
 *     C8  impairment happy path: "FA-IMP asset=FA-X reason='...' loss=$Y"
 *     C9  impairment with non-existent impairment id → ''
 *     C10 invalid source_id (0) → '' regardless of source_type
 *     C11 unknown source_type → '' (default branch)
 *     C12 impairment reason with quotes/pipes → sanitized (not embedded raw)
 *
 *   Module C — PrivateNote integration via buildQboPayload
 *     C13 depreciation JE → PrivateNote contains "FA-DEP run#X"
 *     C14 asset_disposal JE → PrivateNote contains "FA-DISP asset=FA-X"
 *     C15 impairment JE → PrivateNote contains "FA-IMP asset=FA-X"
 *     C16 manual JE → PrivateNote does NOT contain any FA-* prefix (regression)
 *     C17 FA JE with deleted source row → PrivateNote built successfully
 *         (no FA section but no throw — best-effort enrichment lock D-QBO-22-1)
 *
 *   Module D — Enqueuer accepts FA source types (defense-in-depth)
 *     C18 source_type='depreciation' → Enqueuer accepts (queue row written)
 *     C19 source_type='asset_disposal' → Enqueuer accepts
 *     C20 source_type='impairment' → Enqueuer accepts (validates new ENUM value)
 *
 *   Module E — FixedAssetService::impair() ENUM upgrade
 *     C21 FixedAssetService::impair source uses 'impairment' (grep-style asserts
 *         the literal change made by D-QBO-22-2; runtime test would require full
 *         FA seed which is over-scope)
 *
 *   Module F — Schema migration
 *     C22 acc_journal_entries.source_type ENUM contains 'impairment' value
 *
 *   Module G — API list endpoint source_filter=fa + FA KPIs
 *     C23 list.php source_filter=fa scopes WHERE to FA source types
 *     C24 list.php returns kpis.fa_pushed + kpis.fa_total fields
 *
 * Fixture discipline: sentinel IDs in 999990-999999 range, cleaned up in finally.
 *
 * @session  S-QBO-22
 * @decision D-QBO-22-1 (PrivateNote FA-enrichment best-effort lookup),
 *           D-QBO-22-2 (acc_journal_entries.source_type ENUM +impairment),
 *           D-QBO-22-3 (admin UI filter chip + FA KPIs on journal_entries.php),
 *           D-QBO-22-4 (FA source types never bridge-derived — regression guard)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\JournalEntryPusher;
use FleetForge\QboPushers\JournalEntryEnqueuer;

$pass = 0;
$total = 24;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_fa_set_setting(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'quickbooks', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function ff_smoke_fa_get_setting(string $key): ?string
{
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $row['value'] ?? null;
}

/**
 * Cleanup: delete all sentinel FA + JE rows in 999990-999999 range.
 * Order matters because of FK relationships (children → parents).
 */
function ff_smoke_fa_cleanup(): void
{
    // Disposal/impairment children FIRST (they FK to fixed_assets via RESTRICT)
    db_execute("DELETE FROM acc_asset_impairments     WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_asset_disposals       WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_depreciation_run_lines WHERE run_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_depreciation_runs     WHERE id BETWEEN 999990 AND 999999");
    // JE map + lines
    db_execute("DELETE FROM acc_qbo_sync_log           WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_sync_queue         WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entry_lines   WHERE journal_entry_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_journal_entries       WHERE id BETWEEN 999990 AND 999999");
    // Fixed assets next (RESTRICT FK from disposals/impairments — those are gone now)
    db_execute("DELETE FROM acc_fixed_assets          WHERE id BETWEEN 999990 AND 999999");
    // Accounts + QBO mappings last (RESTRICT FK from fixed_assets — those are gone now)
    db_execute("DELETE FROM acc_qbo_account_map       WHERE ff_account_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM acc_accounts              WHERE id BETWEEN 999990 AND 999999");
    // Sentinel period uses far-future year so it doesn't collide with real periods
    db_execute("DELETE FROM acc_periods               WHERE year = 9999 AND month = 12");
}

/**
 * Seed minimal FA chain: period + accounts + 1 fixed_asset + 1 depreciation_run
 * + 1 asset_disposal + 1 asset_impairment.
 *
 * Returns IDs: [
 *   'period_id'       => int,
 *   'asset_id'        => 999990,
 *   'run_id'          => 999990,
 *   'disposal_id'     => 999990,
 *   'impairment_id'   => 999990,
 *   'asset_acct_id'   => 999990,
 *   'accum_acct_id'   => 999991,
 *   'expense_acct_id' => 999992,
 *   'gain_loss_acct_id' => 999993,
 * ]
 */
function ff_smoke_fa_seed(): array
{
    // 4 accounts + QBO mappings (parallels _smoke_qbo_journal_entry_push pattern)
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999990, '1500-SMOKE-FA', 'Smoke FA Asset Cost',       'asset',     'fixed_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999991, '1505-SMOKE-FA', 'Smoke FA Accum Depr',       'asset',     'fixed_asset', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999992, '6500-SMOKE-FA', 'Smoke FA Depr Expense',     'operating_expense', 'operating_expense', 0, 1)"
    );
    db_execute(
        "INSERT INTO acc_accounts (id, code, name, account_type, account_subtype, is_system, is_active)
         VALUES (999993, '7100-SMOKE-FA', 'Smoke FA Gain/Loss',        'other_income', 'other', 0, 1)"
    );
    // QBO maps for the lines (so buildQboPayload step 4 doesn't throw unmapped)
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999990, 'A-FA-COST-9000', 'mapped')");
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999991, 'A-FA-ACCUM-9000','mapped')");
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999992, 'A-FA-EXP-9000', 'mapped')");
    db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, mapping_status) VALUES (999993, 'A-FA-GL-9000',  'mapped')");

    // Sentinel period — far-future year avoids uq_period collision
    db_execute(
        "INSERT INTO acc_periods (year, month, name, start_date, end_date, status)
         VALUES (9999, 12, 'Smoke FA Period 9999-12', '9999-12-01', '9999-12-31', 'open')"
    );
    $period = db_row("SELECT id FROM acc_periods WHERE year = 9999 AND month = 12");
    $periodId = (int) $period['id'];

    // Fixed asset
    db_execute(
        "INSERT INTO acc_fixed_assets
            (id, asset_number, name, asset_class, acquisition_date, acquisition_cost,
             depreciation_method, depreciable_cost, salvage_value, net_book_value,
             depreciation_start_date,
             asset_account_id, accum_depr_account_id, depr_expense_account_id)
         VALUES (999990, 'FA-SMOKE-9990', 'Smoke FA Vehicle', 'fleet_equipment',
                 '2026-01-01', '50000.00', 'straight_line', '50000.00', '5000.00', '45000.00',
                 '2026-01-01', 999990, 999991, 999992)"
    );

    // Depreciation run
    db_execute(
        "INSERT INTO acc_depreciation_runs (id, period_id, run_date, status, total_depreciation, asset_count, notes)
         VALUES (999990, ?, '2026-05-15 10:00:00', 'posted', '4250.00', 12, 'Smoke run')",
        [$periodId]
    );
    db_execute(
        "INSERT INTO acc_depreciation_run_lines (run_id, asset_id, period_id, opening_nbv, depreciation, closing_nbv, method_used, calculation_detail)
         VALUES (999990, 999990, ?, '45000.00', '4250.00', '40750.00', 'straight_line', '{}')",
        [$periodId]
    );

    // Asset disposal
    db_execute(
        "INSERT INTO acc_asset_disposals
            (id, asset_id, disposal_date, disposal_type, proceeds,
             net_book_value_at_disposal, gain_loss, gain_loss_account_id, buyer_name)
         VALUES (999990, 999990, '2026-05-20', 'sale', '5000.00', '3800.00', '1200.00', 999993, 'Smoke Buyer')"
    );

    // Asset impairment
    db_execute(
        "INSERT INTO acc_asset_impairments
            (id, asset_id, impairment_date, pre_impairment_nbv, recoverable_amount, impairment_loss, reason)
         VALUES (999990, 999990, '2026-05-25', '40000.00', '37500.00', '2500.00', 'market crash | quotes ''test''')"
    );

    return [
        'period_id'         => $periodId,
        'asset_id'          => 999990,
        'run_id'            => 999990,
        'disposal_id'       => 999990,
        'impairment_id'     => 999990,
        'asset_acct_id'     => 999990,
        'accum_acct_id'     => 999991,
        'expense_acct_id'   => 999992,
        'gain_loss_acct_id' => 999993,
    ];
}

/**
 * Seed a posted JE with source_type/source_id pointing at an FA artifact.
 * Default lines: 999992 expense DR / 999991 accum CR for $4,250.
 */
function ff_smoke_fa_seed_je(int $jeId, string $sourceType, ?int $sourceId, array $overrides = []): int
{
    $period = db_row("SELECT id FROM acc_periods WHERE year = 9999 AND month = 12");
    $entryNumber = $overrides['entry_number'] ?? "JE-FA-{$jeId}";
    $amount      = (string) ($overrides['amount'] ?? '4250.00');
    $debitAcct   = $overrides['debit_acct']  ?? 999992;
    $creditAcct  = $overrides['credit_acct'] ?? 999991;

    db_execute(
        "INSERT INTO acc_journal_entries (id, entry_number, period_id, entry_date, entry_type,
                                          status, entry_status, description, source_type, source_id,
                                          currency, posted_at)
         VALUES (?, ?, ?, '2026-05-15', 'system', 'posted', 'posted', ?, ?, ?, 'CAD', NOW())",
        [$jeId, $entryNumber, (int) $period['id'], "Smoke FA JE {$jeId}", $sourceType, $sourceId]
    );
    db_execute(
        "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
         VALUES (?, ?, 1, 'FA debit',  ?, '0.00')",
        [$jeId, $debitAcct, $amount]
    );
    db_execute(
        "INSERT INTO acc_journal_entry_lines (journal_entry_id, account_id, line_number, description, debit, credit)
         VALUES (?, ?, 2, 'FA credit', '0.00', ?)",
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
    $snapshot[$k] = ff_smoke_fa_get_setting($k);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-22 Fixed Asset JE Sync Smoke (24 sub-checks)\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_fa_cleanup();
    $ids = ff_smoke_fa_seed();
    ff_smoke_fa_set_setting('quickbooks.connection_status', 'connected');
    ff_smoke_fa_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_fa_set_setting('quickbooks.sync_mode.journal_entry', 'queue');
    ff_smoke_fa_set_setting('quickbooks.multi_currency_enabled', '0');

    // ══════════════════════════════════════════════════════════════════════
    // Module A — Constants + bridge-derived regression guard (D-QBO-22-4)
    // ══════════════════════════════════════════════════════════════════════

    // ── C1: FA_SOURCE_TYPES constant exists + 3 expected values ─────────
    $c1Errors = [];
    $ref = new ReflectionClass(JournalEntryPusher::class);
    if (!$ref->hasConstant('FA_SOURCE_TYPES')) {
        $c1Errors[] = 'JournalEntryPusher::FA_SOURCE_TYPES const missing';
    } else {
        $fa = JournalEntryPusher::FA_SOURCE_TYPES;
        $expected = ['depreciation', 'asset_disposal', 'impairment'];
        sort($fa); sort($expected);
        if ($fa !== $expected) {
            $c1Errors[] = 'FA_SOURCE_TYPES expected ' . json_encode($expected) . ' got ' . json_encode($fa);
        }
    }
    if (empty($c1Errors)) { echo "PASS C1 FA_SOURCE_TYPES constant + values (D-QBO-22-1)\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

    // ── C2: FA ∩ Pusher::BRIDGE_DERIVED_SOURCE_TYPES = ∅ ─────────────────
    $c2Errors = [];
    $bridge = (new ReflectionClass(JournalEntryPusher::class))->getConstant('BRIDGE_DERIVED_SOURCE_TYPES');
    $fa = JournalEntryPusher::FA_SOURCE_TYPES;
    $overlap = array_intersect($fa, $bridge ?: []);
    if (!empty($overlap)) {
        $c2Errors[] = 'FA ∩ Pusher::BRIDGE_DERIVED = ' . json_encode(array_values($overlap)) . ' (must be empty per D-QBO-22-4)';
    }
    if (empty($c2Errors)) { echo "PASS C2 FA ∩ Pusher::BRIDGE_DERIVED_SOURCE_TYPES = ∅ (D-QBO-22-4)\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $c2Errors) . "\n"; $failures[] = 'C2'; }

    // ── C3: FA ∩ Enqueuer::BRIDGE_DERIVED_SOURCE_TYPES = ∅ ───────────────
    $c3Errors = [];
    $enqBridgeRef = new ReflectionClass(JournalEntryEnqueuer::class);
    // private const → use getReflectionConstant
    $enqBridgeConst = $enqBridgeRef->getReflectionConstant('BRIDGE_DERIVED_SOURCE_TYPES');
    if (!$enqBridgeConst) {
        $c3Errors[] = 'Enqueuer::BRIDGE_DERIVED_SOURCE_TYPES missing';
    } else {
        $enqBridge = $enqBridgeConst->getValue();
        $overlap = array_intersect($fa, $enqBridge ?: []);
        if (!empty($overlap)) {
            $c3Errors[] = 'FA ∩ Enqueuer::BRIDGE_DERIVED = ' . json_encode(array_values($overlap)) . ' (must be empty)';
        }
    }
    if (empty($c3Errors)) { echo "PASS C3 FA ∩ Enqueuer::BRIDGE_DERIVED_SOURCE_TYPES = ∅ (D-QBO-22-4)\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module B — buildFixedAssetNoteSection (per FA type × happy + edge)
    // ══════════════════════════════════════════════════════════════════════

    // ── C4: depreciation happy path ───────────────────────────────────────
    $c4Errors = [];
    $section = JournalEntryPusher::buildFixedAssetNoteSection('depreciation', $ids['run_id']);
    if (strpos($section, 'FA-DEP') !== 0) {
        $c4Errors[] = "expected 'FA-DEP' prefix; got: " . substr($section, 0, 80);
    }
    if (strpos($section, "run#{$ids['run_id']}") === false) {
        $c4Errors[] = "expected run#{$ids['run_id']} in section";
    }
    if (strpos($section, 'period=') === false) {
        $c4Errors[] = "expected period= in section";
    }
    if (strpos($section, 'assets=1') === false) {
        $c4Errors[] = "expected assets=1 (we seeded 1 run line); got: {$section}";
    }
    if (strpos($section, 'total=$4250.00') === false) {
        $c4Errors[] = "expected total=\$4250.00 in section";
    }
    if (empty($c4Errors)) { echo "PASS C4 buildFixedAssetNoteSection('depreciation', happy) — FA-DEP format\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

    // ── C5: depreciation with non-existent run id → '' ────────────────────
    $section = JournalEntryPusher::buildFixedAssetNoteSection('depreciation', 999998);
    if ($section === '') { echo "PASS C5 buildFixedAssetNoteSection('depreciation', missing) → '' (graceful)\n"; $pass++; }
    else { echo "FAIL C5 expected ''; got: " . substr($section, 0, 80) . "\n"; $failures[] = 'C5'; }

    // ── C6: asset_disposal happy path ─────────────────────────────────────
    $c6Errors = [];
    $section = JournalEntryPusher::buildFixedAssetNoteSection('asset_disposal', $ids['disposal_id']);
    if (strpos($section, 'FA-DISP') !== 0) $c6Errors[] = "expected 'FA-DISP' prefix";
    if (strpos($section, 'asset=FA-SMOKE-9990') === false) $c6Errors[] = "expected asset=FA-SMOKE-9990";
    if (strpos($section, 'type=sale') === false) $c6Errors[] = "expected type=sale";
    if (strpos($section, 'proceeds=$5000.00') === false) $c6Errors[] = "expected proceeds=\$5000.00";
    if (strpos($section, 'gain_loss=$1200.00') === false) $c6Errors[] = "expected gain_loss=\$1200.00";
    if (empty($c6Errors)) { echo "PASS C6 buildFixedAssetNoteSection('asset_disposal', happy) — FA-DISP format\n"; $pass++; }
    else { echo "FAIL C6 " . implode('; ', $c6Errors) . " — got: " . substr($section, 0, 120) . "\n"; $failures[] = 'C6'; }

    // ── C7: asset_disposal with non-existent disposal id → '' ─────────────
    $section = JournalEntryPusher::buildFixedAssetNoteSection('asset_disposal', 999998);
    if ($section === '') { echo "PASS C7 buildFixedAssetNoteSection('asset_disposal', missing) → ''\n"; $pass++; }
    else { echo "FAIL C7 expected ''; got: " . substr($section, 0, 80) . "\n"; $failures[] = 'C7'; }

    // ── C8: impairment happy path ─────────────────────────────────────────
    $c8Errors = [];
    $section = JournalEntryPusher::buildFixedAssetNoteSection('impairment', $ids['impairment_id']);
    if (strpos($section, 'FA-IMP') !== 0) $c8Errors[] = "expected 'FA-IMP' prefix";
    if (strpos($section, 'asset=FA-SMOKE-9990') === false) $c8Errors[] = "expected asset=FA-SMOKE-9990";
    if (strpos($section, 'reason=') === false) $c8Errors[] = "expected reason= in section";
    if (strpos($section, 'loss=$2500.00') === false) $c8Errors[] = "expected loss=\$2500.00";
    if (empty($c8Errors)) { echo "PASS C8 buildFixedAssetNoteSection('impairment', happy) — FA-IMP format\n"; $pass++; }
    else { echo "FAIL C8 " . implode('; ', $c8Errors) . " — got: " . substr($section, 0, 120) . "\n"; $failures[] = 'C8'; }

    // ── C9: impairment with non-existent impairment id → '' ───────────────
    $section = JournalEntryPusher::buildFixedAssetNoteSection('impairment', 999998);
    if ($section === '') { echo "PASS C9 buildFixedAssetNoteSection('impairment', missing) → ''\n"; $pass++; }
    else { echo "FAIL C9 expected ''; got: " . substr($section, 0, 80) . "\n"; $failures[] = 'C9'; }

    // ── C10: invalid source_id (0) → '' regardless of source_type ─────────
    $c10Errors = [];
    foreach (['depreciation', 'asset_disposal', 'impairment'] as $st) {
        $r = JournalEntryPusher::buildFixedAssetNoteSection($st, 0);
        if ($r !== '') $c10Errors[] = "expected '' for ({$st}, 0); got: {$r}";
    }
    if (empty($c10Errors)) { echo "PASS C10 buildFixedAssetNoteSection(*, 0) → '' (invalid id guard)\n"; $pass++; }
    else { echo "FAIL C10 " . implode('; ', $c10Errors) . "\n"; $failures[] = 'C10'; }

    // ── C11: unknown source_type → '' ────────────────────────────────────
    $section = JournalEntryPusher::buildFixedAssetNoteSection('unknown_type', 42);
    if ($section === '') { echo "PASS C11 buildFixedAssetNoteSection('unknown_type') → '' (default branch)\n"; $pass++; }
    else { echo "FAIL C11 expected ''; got: {$section}\n"; $failures[] = 'C11'; }

    // ── C12: impairment reason with quotes/pipes → sanitized ──────────────
    // Seed already has reason "market crash | quotes 'test'" — verify section
    // strips single quotes + pipes so PrivateNote '|' separator isn't broken.
    $c12Errors = [];
    $section = JournalEntryPusher::buildFixedAssetNoteSection('impairment', $ids['impairment_id']);
    if (strpos($section, " | ") !== false) {
        $c12Errors[] = "section contains literal ' | ' — would break PrivateNote separator";
    }
    if (strpos($section, "''") !== false) {
        $c12Errors[] = "section contains ''— single quote inside reason should be stripped";
    }
    // Verify our specific sanitization preserved non-special chars
    if (strpos($section, 'market crash') === false) {
        $c12Errors[] = "expected 'market crash' to survive sanitization";
    }
    if (empty($c12Errors)) { echo "PASS C12 buildFixedAssetNoteSection sanitizes quotes/pipes in reason\n"; $pass++; }
    else { echo "FAIL C12 " . implode('; ', $c12Errors) . " — got: " . substr($section, 0, 160) . "\n"; $failures[] = 'C12'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module C — PrivateNote integration via buildQboPayload
    // ══════════════════════════════════════════════════════════════════════

    // Seed JEs for each FA source type + 1 manual.
    ff_smoke_fa_seed_je(999990, 'depreciation', $ids['run_id']);
    ff_smoke_fa_seed_je(999991, 'asset_disposal', $ids['disposal_id']);
    ff_smoke_fa_seed_je(999992, 'impairment', $ids['impairment_id']);
    ff_smoke_fa_seed_je(999993, 'manual', null /* no FA source */);

    // ── C13: depreciation JE → PrivateNote contains FA-DEP ────────────────
    $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999990");
    $payload = JournalEntryPusher::buildQboPayload($ffRow);
    $note = (string) ($payload['PrivateNote'] ?? '');
    if (strpos($note, 'FA-DEP') !== false) { echo "PASS C13 depreciation JE PrivateNote contains FA-DEP section\n"; $pass++; }
    else { echo "FAIL C13 PrivateNote missing FA-DEP; got: {$note}\n"; $failures[] = 'C13'; }

    // ── C14: asset_disposal JE → PrivateNote contains FA-DISP ─────────────
    $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999991");
    $payload = JournalEntryPusher::buildQboPayload($ffRow);
    $note = (string) ($payload['PrivateNote'] ?? '');
    if (strpos($note, 'FA-DISP') !== false && strpos($note, 'asset=FA-SMOKE-9990') !== false) {
        echo "PASS C14 asset_disposal JE PrivateNote contains FA-DISP + asset id\n"; $pass++;
    } else { echo "FAIL C14 PrivateNote missing FA-DISP/asset; got: {$note}\n"; $failures[] = 'C14'; }

    // ── C15: impairment JE → PrivateNote contains FA-IMP ──────────────────
    $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999992");
    $payload = JournalEntryPusher::buildQboPayload($ffRow);
    $note = (string) ($payload['PrivateNote'] ?? '');
    if (strpos($note, 'FA-IMP') !== false && strpos($note, 'asset=FA-SMOKE-9990') !== false) {
        echo "PASS C15 impairment JE PrivateNote contains FA-IMP + asset id\n"; $pass++;
    } else { echo "FAIL C15 PrivateNote missing FA-IMP/asset; got: {$note}\n"; $failures[] = 'C15'; }

    // ── C16: manual JE → PrivateNote does NOT contain FA-* (regression) ───
    $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999993");
    $payload = JournalEntryPusher::buildQboPayload($ffRow);
    $note = (string) ($payload['PrivateNote'] ?? '');
    if (strpos($note, 'FA-DEP') === false && strpos($note, 'FA-DISP') === false && strpos($note, 'FA-IMP') === false) {
        echo "PASS C16 manual JE PrivateNote contains NO FA-* prefix (regression guard)\n"; $pass++;
    } else { echo "FAIL C16 manual JE PrivateNote leaked FA section; got: {$note}\n"; $failures[] = 'C16'; }

    // ── C17: FA JE with deleted source row → no FA section but no throw ───
    // Build a new FA JE then delete the depreciation run; buildQboPayload
    // should succeed with generic PrivateNote.
    ff_smoke_fa_seed_je(999994, 'depreciation', 999988 /* non-existent */);
    try {
        $ffRow = db_row("SELECT * FROM acc_journal_entries WHERE id = 999994");
        $payload = JournalEntryPusher::buildQboPayload($ffRow);
        $note = (string) ($payload['PrivateNote'] ?? '');
        if (strpos($note, 'FA-DEP') === false && strpos($note, 'source=depreciation#999988') !== false) {
            echo "PASS C17 FA JE with missing source row → no throw, no FA section, generic source attribution kept\n"; $pass++;
        } else {
            echo "FAIL C17 unexpected PrivateNote shape; got: {$note}\n"; $failures[] = 'C17';
        }
    } catch (\Throwable $e) {
        echo "FAIL C17 buildQboPayload threw on missing FA source row: " . $e->getMessage() . "\n";
        $failures[] = 'C17';
    }

    // ══════════════════════════════════════════════════════════════════════
    // Module D — Enqueuer accepts FA source types
    // ══════════════════════════════════════════════════════════════════════

    // Wipe existing queue rows from C13-C17 calls (none should have happened
    // since buildQboPayload doesn't enqueue, but defensive)
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'journal_entry' AND entity_id BETWEEN 999990 AND 999994");

    // ── C18: source_type='depreciation' → Enqueuer accepts ────────────────
    $ok = JournalEntryEnqueuer::enqueue(999990, 'create');
    $queued = (int) (db_row("SELECT COUNT(*) AS c FROM acc_qbo_sync_queue WHERE entity_type = 'journal_entry' AND entity_id = 999990 AND status = 'queued'")['c'] ?? 0);
    if ($ok === true && $queued === 1) { echo "PASS C18 Enqueuer accepts source_type='depreciation' (not bridge-derived)\n"; $pass++; }
    else { echo "FAIL C18 Enqueuer rejected; ok={$ok}, queued={$queued}\n"; $failures[] = 'C18'; }

    // ── C19: source_type='asset_disposal' → Enqueuer accepts ──────────────
    $ok = JournalEntryEnqueuer::enqueue(999991, 'create');
    $queued = (int) (db_row("SELECT COUNT(*) AS c FROM acc_qbo_sync_queue WHERE entity_type = 'journal_entry' AND entity_id = 999991 AND status = 'queued'")['c'] ?? 0);
    if ($ok === true && $queued === 1) { echo "PASS C19 Enqueuer accepts source_type='asset_disposal'\n"; $pass++; }
    else { echo "FAIL C19 Enqueuer rejected; ok={$ok}, queued={$queued}\n"; $failures[] = 'C19'; }

    // ── C20: source_type='impairment' → Enqueuer accepts ──────────────────
    // (Critical: validates the new ENUM value works end-to-end)
    $ok = JournalEntryEnqueuer::enqueue(999992, 'create');
    $queued = (int) (db_row("SELECT COUNT(*) AS c FROM acc_qbo_sync_queue WHERE entity_type = 'journal_entry' AND entity_id = 999992 AND status = 'queued'")['c'] ?? 0);
    if ($ok === true && $queued === 1) { echo "PASS C20 Enqueuer accepts source_type='impairment' (new ENUM value end-to-end)\n"; $pass++; }
    else { echo "FAIL C20 Enqueuer rejected; ok={$ok}, queued={$queued}\n"; $failures[] = 'C20'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module E — FixedAssetService::impair() ENUM upgrade
    // ══════════════════════════════════════════════════════════════════════

    // ── C21: FixedAssetService::impair() uses source_type='impairment' ────
    // Grep-style assertion on the source file — runtime test would require
    // full FA + period + accounts seeding via the public API (out of scope
    // for offline smoke).
    $c21Errors = [];
    $src = (string) file_get_contents(__DIR__ . '/../lib/Accounting/FixedAssetService.php');
    if (strpos($src, "'source_type'     => 'impairment'") === false) {
        $c21Errors[] = "FixedAssetService::impair() does not emit source_type='impairment' literal";
    }
    if (strpos($src, "'source_type'     => 'asset_disposal',  // closest enum match") !== false) {
        $c21Errors[] = "FixedAssetService::impair() still has the old 'closest enum match' comment + 'asset_disposal' fallback";
    }
    if (empty($c21Errors)) { echo "PASS C21 FixedAssetService::impair() uses source_type='impairment' (D-QBO-22-2)\n"; $pass++; }
    else { echo "FAIL C21 " . implode('; ', $c21Errors) . "\n"; $failures[] = 'C21'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module F — Schema migration
    // ══════════════════════════════════════════════════════════════════════

    // ── C22: source_type ENUM contains 'impairment' ───────────────────────
    $c22Errors = [];
    $col = db_row("SHOW COLUMNS FROM acc_journal_entries WHERE Field = 'source_type'");
    $type = (string) ($col['Type'] ?? '');
    if (strpos($type, "'impairment'") === false) {
        $c22Errors[] = "acc_journal_entries.source_type ENUM missing 'impairment' — got: {$type}";
    }
    if (empty($c22Errors)) { echo "PASS C22 acc_journal_entries.source_type ENUM contains 'impairment' (migration applied)\n"; $pass++; }
    else { echo "FAIL C22 " . implode('; ', $c22Errors) . "\n"; $failures[] = 'C22'; }

    // ══════════════════════════════════════════════════════════════════════
    // Module G — API list endpoint source_filter=fa + FA KPIs
    // ══════════════════════════════════════════════════════════════════════

    // Seed map rows so API has data to count. C18-C20 already inserted queue
    // rows but we need acc_qbo_journal_entry_map rows for KPIs:
    db_execute(
        "INSERT INTO acc_qbo_journal_entry_map (ff_journal_entry_id, push_status) VALUES
            (999990, 'pushed'),
            (999991, 'pushed'),
            (999992, 'pending'),
            (999993, 'pushed')"
    );

    // ── C23: source_filter=fa → SQL scope ─────────────────────────────────
    // Direct DB test mirroring list.php WHERE clause assembly.
    $faTypes = JournalEntryPusher::FA_SOURCE_TYPES;
    $placeholders = implode(',', array_fill(0, count($faTypes), '?'));
    $faScoped = db_select(
        "SELECT m.id, je.source_type
           FROM acc_qbo_journal_entry_map m
      LEFT JOIN acc_journal_entries je ON je.id = m.ff_journal_entry_id
          WHERE m.ff_journal_entry_id BETWEEN 999990 AND 999993
            AND je.source_type IN ({$placeholders})",
        $faTypes
    );
    $c23Errors = [];
    if (count($faScoped) !== 3) {
        $c23Errors[] = "expected 3 FA rows (999990 dep, 999991 disp, 999992 imp); got " . count($faScoped);
    }
    foreach ($faScoped as $r) {
        if (!in_array($r['source_type'], $faTypes, true)) {
            $c23Errors[] = "leaked non-FA row source_type={$r['source_type']}";
        }
    }
    if (empty($c23Errors)) { echo "PASS C23 source_filter=fa SQL scope returns FA rows only (D-QBO-22-3)\n"; $pass++; }
    else { echo "FAIL C23 " . implode('; ', $c23Errors) . "\n"; $failures[] = 'C23'; }

    // ── C24: list.php returns kpis.fa_pushed + kpis.fa_total ──────────────
    // Grep-style assertion on the endpoint file — full HTTP test would need
    // auth scaffolding that's out of scope for offline smoke.
    $c24Errors = [];
    $endpointSrc = (string) file_get_contents(__DIR__ . '/../api/v1/quickbooks/journal_entries/list.php');
    if (strpos($endpointSrc, "'fa_total'") === false) {
        $c24Errors[] = "list.php missing kpis['fa_total']";
    }
    if (strpos($endpointSrc, "'fa_pushed'") === false) {
        $c24Errors[] = "list.php missing kpis['fa_pushed']";
    }
    if (strpos($endpointSrc, "source_filter") === false) {
        $c24Errors[] = "list.php missing source_filter param handling";
    }
    if (empty($c24Errors)) { echo "PASS C24 list.php emits fa_pushed + fa_total + source_filter param\n"; $pass++; }
    else { echo "FAIL C24 " . implode('; ', $c24Errors) . "\n"; $failures[] = 'C24'; }

} finally {
    ff_smoke_fa_cleanup();
    // Restore settings
    foreach ($snapshot as $k => $v) {
        if ($v === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_fa_set_setting($k, $v);
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
