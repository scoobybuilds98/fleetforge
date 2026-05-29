<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_bank_mapping.php
 *
 * S-QBO-20 BankAccountMatcher coverage. Offline — uses synthetic QBO
 * data arrays so no live QBO API call is required. Live integration is
 * deferred to operator live-verify per session F* follow-ups.
 *
 * Sub-checks:
 *   C1: Class surfaces (BankAccountMatcher::pullFromQbo, getCandidates,
 *       assignMapping, unmapping, verifyMappingStillValid, typeCompatible,
 *       normalizeName + the BANK_ACCOUNT_TYPES public const).
 *   C2: Migration shape — acc_qbo_bank_account_map (13 cols + 2 UNIQUE
 *       + 2 idx + 2 FKs CASCADE/SET NULL).
 *   C3: typeCompatible map — checking → Bank, savings → Bank,
 *       line_of_credit → Bank+CreditCard, credit_card → CreditCard.
 *   C4: normalizeName collapses punctuation + lowercases.
 *   C5: getCandidates ranking — currency match wins over fuzzy distance.
 *   C6: getCandidates ranking — within same currency, type-compatible
 *       ranked above incompatible.
 *   C7: getCandidates ranking — distance breaks ties within same currency
 *       + type bucket.
 *   C8: getCandidates returns top 10 max.
 *   C9: assignMapping inserts with snapshot when no row exists.
 *  C10: assignMapping updates snapshot on re-link (same FF, new QBO).
 *  C11: assignMapping throws on duplicate qbo_id mapped to different FF.
 *  C12: unmapping removes row + returns true.
 *  C13: unmapping returns false when no row exists.
 *  C14: getCandidates returns empty for missing FF row.
 *
 * @session S-QBO-20
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\BankAccountMatcher;

$pass     = 0;
$total    = 14;
$failures = [];

/** Sentinel IDs in 999900-999910 range to avoid colliding with real data. */
const SMOKE_BAM_FF_BASE = 999900;
const SMOKE_BAM_USER    = null;  // system origin

function ff_smoke_bam_cleanup(): void
{
    db_execute(
        "DELETE FROM acc_qbo_bank_account_map WHERE ff_bank_account_id BETWEEN ? AND ?",
        [SMOKE_BAM_FF_BASE, SMOKE_BAM_FF_BASE + 99]
    );
    db_execute(
        "DELETE FROM acc_bank_accounts WHERE id BETWEEN ? AND ?",
        [SMOKE_BAM_FF_BASE, SMOKE_BAM_FF_BASE + 99]
    );
    db_execute(
        "DELETE FROM audit_log WHERE entity_type IN ('bank_account','bank_transaction_mirror') AND entity_id BETWEEN ? AND ?",
        [SMOKE_BAM_FF_BASE, SMOKE_BAM_FF_BASE + 99]
    );
}

function ff_smoke_bam_seed_ff(int $id, string $name, string $type = 'checking', string $currency = 'CAD'): void
{
    // Need at least one acc_accounts row to satisfy gl_account_id FK.
    // Reuse an existing one to avoid coupling the smoke to acc_accounts schema.
    $gl = db_row("SELECT id FROM acc_accounts WHERE account_type = 'asset' LIMIT 1");
    if (!$gl) {
        throw new \RuntimeException('smoke setup: no acc_accounts asset row to attach to');
    }
    db_execute(
        "INSERT INTO acc_bank_accounts (id, name, account_type, currency, gl_account_id, is_active, opening_balance)
              VALUES (?, ?, ?, ?, ?, 1, '0.00')",
        [$id, $name, $type, $currency, (int) $gl['id']]
    );
}

/**
 * Build a synthetic QBO bank-account array shaped like
 * BankAccountMatcher::pullFromQbo() output.
 */
function ff_smoke_bam_qbo(string $qboId, string $name, string $currency = 'CAD', bool $active = true, string $accountType = 'Bank'): array
{
    return [
        'qbo_id'          => $qboId,
        'name'            => $name,
        'currency'        => $currency,
        'active'          => $active,
        'account_type'    => $accountType,
        'account_subtype' => $accountType === 'Bank' ? 'Checking' : 'CreditCard',
        'account_number'  => '',
    ];
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-20 Bank Account Mapping Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_bam_cleanup();

    // ── C1: Class surfaces ─────────────────────────────────────────────
    $c1Errors = [];
    if (!class_exists(BankAccountMatcher::class)) {
        $c1Errors[] = 'BankAccountMatcher class missing';
    }
    foreach (['pullFromQbo', 'getCandidates', 'assignMapping', 'unmapping', 'verifyMappingStillValid', 'typeCompatible', 'normalizeName'] as $m) {
        if (!method_exists(BankAccountMatcher::class, $m)) {
            $c1Errors[] = "BankAccountMatcher::{$m} missing";
        }
    }
    if (!defined('FleetForge\QboPushers\BankAccountMatcher::BANK_ACCOUNT_TYPES')
        || BankAccountMatcher::BANK_ACCOUNT_TYPES !== ['Bank', 'CreditCard']) {
        $c1Errors[] = 'BANK_ACCOUNT_TYPES const wrong shape';
    }
    if (empty($c1Errors)) {
        echo "PASS C1 class surfaces\n"; $pass++;
    } else {
        echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1';
    }

    // ── C2: Migration shape ────────────────────────────────────────────
    $cols = [];
    foreach (db_select("SHOW COLUMNS FROM acc_qbo_bank_account_map") as $r) {
        $cols[$r['Field']] = $r['Type'];
    }
    $expectedCols = [
        'id', 'ff_bank_account_id', 'qbo_bank_account_id',
        'qbo_account_name_snapshot', 'qbo_currency_snapshot',
        'qbo_active_snapshot', 'qbo_account_type_snapshot',
        'mapping_status', 'last_synced_at', 'mapped_by', 'mapped_at',
        'created_at', 'updated_at',
    ];
    $missing = array_diff($expectedCols, array_keys($cols));
    $hasUq1 = !empty(db_select("SHOW INDEX FROM acc_qbo_bank_account_map WHERE Key_name = ?", ['uq_ff_bank_account']));
    $hasUq2 = !empty(db_select("SHOW INDEX FROM acc_qbo_bank_account_map WHERE Key_name = ?", ['uq_qbo_bank_account']));
    if (empty($missing) && $hasUq1 && $hasUq2 && (string) $cols['mapping_status'] === "enum('mapped','unmapped','conflict')") {
        echo "PASS C2 migration shape (13+ cols + 2 UNIQUE + mapping_status ENUM)\n"; $pass++;
    } else {
        echo "FAIL C2 missing=" . implode(',', $missing) . " uq_ff=" . ($hasUq1 ? '1' : '0') . " uq_qbo=" . ($hasUq2 ? '1' : '0') . " status=" . $cols['mapping_status'] . "\n";
        $failures[] = 'C2';
    }

    // ── C3: typeCompatible ─────────────────────────────────────────────
    $c3 = BankAccountMatcher::typeCompatible('checking', 'Bank')
        && BankAccountMatcher::typeCompatible('savings', 'Bank')
        && BankAccountMatcher::typeCompatible('line_of_credit', 'Bank')
        && BankAccountMatcher::typeCompatible('line_of_credit', 'CreditCard')
        && BankAccountMatcher::typeCompatible('credit_card', 'CreditCard')
        && !BankAccountMatcher::typeCompatible('credit_card', 'Bank')
        && !BankAccountMatcher::typeCompatible('checking', 'CreditCard');
    if ($c3) { echo "PASS C3 typeCompatible\n"; $pass++; } else { echo "FAIL C3\n"; $failures[] = 'C3'; }

    // ── C4: normalizeName ──────────────────────────────────────────────
    $c4 = BankAccountMatcher::normalizeName('RBC-Operating_Account') === 'rbc operating account'
        && BankAccountMatcher::normalizeName('  TD   Bank...  ') === 'td bank';
    if ($c4) { echo "PASS C4 normalizeName collapses punctuation + lowercase\n"; $pass++; } else { echo "FAIL C4\n"; $failures[] = 'C4'; }

    // ── C5: getCandidates ranks currency match first ───────────────────
    ff_smoke_bam_seed_ff(SMOKE_BAM_FF_BASE, 'RBC Operating', 'checking', 'CAD');
    $qbo = [
        ff_smoke_bam_qbo('qbo-1', 'Operating', 'USD'),   // close name but wrong currency
        ff_smoke_bam_qbo('qbo-2', 'RBC Operating', 'CAD'),  // exact + currency match
        ff_smoke_bam_qbo('qbo-3', 'Random Other', 'CAD'),  // currency match but bad name
    ];
    $cands = BankAccountMatcher::getCandidates(SMOKE_BAM_FF_BASE, $qbo);
    if (count($cands) === 3 && $cands[0]['qbo_id'] === 'qbo-2' && $cands[1]['qbo_id'] === 'qbo-3' && $cands[2]['qbo_id'] === 'qbo-1') {
        echo "PASS C5 getCandidates ranks currency match first then distance\n"; $pass++;
    } else {
        $ids = array_column($cands, 'qbo_id');
        echo "FAIL C5 expected [qbo-2,qbo-3,qbo-1]; got [" . implode(',', $ids) . "]\n"; $failures[] = 'C5';
    }

    // ── C6: type compatibility within same currency ────────────────────
    $qbo = [
        ff_smoke_bam_qbo('qbo-4', 'Some Card', 'CAD', true, 'CreditCard'),
        ff_smoke_bam_qbo('qbo-5', 'Some Bank', 'CAD', true, 'Bank'),
    ];
    $cands = BankAccountMatcher::getCandidates(SMOKE_BAM_FF_BASE, $qbo);
    // FF is 'checking' → typeCompatible with Bank only. Bank should rank above CreditCard.
    if ($cands[0]['qbo_id'] === 'qbo-5' && $cands[1]['qbo_id'] === 'qbo-4') {
        echo "PASS C6 type-compatible ranks above incompatible\n"; $pass++;
    } else {
        echo "FAIL C6 expected [qbo-5,qbo-4]; got [" . implode(',', array_column($cands, 'qbo_id')) . "]\n"; $failures[] = 'C6';
    }

    // ── C7: distance tie-break ─────────────────────────────────────────
    $qbo = [
        ff_smoke_bam_qbo('qbo-6', 'RBC Operating XYZ', 'CAD'),  // distance ~5
        ff_smoke_bam_qbo('qbo-7', 'RBC Operating', 'CAD'),       // distance 0
    ];
    $cands = BankAccountMatcher::getCandidates(SMOKE_BAM_FF_BASE, $qbo);
    if ($cands[0]['qbo_id'] === 'qbo-7') {
        echo "PASS C7 distance tie-break — exact name first\n"; $pass++;
    } else {
        echo "FAIL C7 got first=" . $cands[0]['qbo_id'] . "\n"; $failures[] = 'C7';
    }

    // ── C8: top 10 max ─────────────────────────────────────────────────
    $qbo = [];
    for ($i = 0; $i < 15; $i++) {
        $qbo[] = ff_smoke_bam_qbo("bulk-{$i}", "Bulk Account {$i}", 'CAD');
    }
    $cands = BankAccountMatcher::getCandidates(SMOKE_BAM_FF_BASE, $qbo);
    if (count($cands) === 10) {
        echo "PASS C8 returns top 10 max\n"; $pass++;
    } else {
        echo "FAIL C8 expected 10; got " . count($cands) . "\n"; $failures[] = 'C8';
    }

    // ── C9: assignMapping inserts ──────────────────────────────────────
    $snapshot = ff_smoke_bam_qbo('qbo-c9', 'Test C9', 'CAD');
    $r9 = BankAccountMatcher::assignMapping(SMOKE_BAM_FF_BASE, 'qbo-c9', $snapshot, SMOKE_BAM_USER);
    $mapRow = db_row("SELECT * FROM acc_qbo_bank_account_map WHERE id = ?", [(int) $r9['mapping_id']]);
    if ($r9['action'] === 'inserted'
        && $mapRow
        && $mapRow['ff_bank_account_id'] == SMOKE_BAM_FF_BASE
        && $mapRow['qbo_bank_account_id'] === 'qbo-c9'
        && $mapRow['qbo_account_name_snapshot'] === 'Test C9'
        && $mapRow['qbo_currency_snapshot'] === 'CAD'
        && $mapRow['mapping_status'] === 'mapped') {
        echo "PASS C9 assignMapping inserts with snapshot\n"; $pass++;
    } else {
        echo "FAIL C9 r9=" . json_encode($r9) . " mapRow=" . json_encode($mapRow) . "\n"; $failures[] = 'C9';
    }

    // ── C10: assignMapping update path ─────────────────────────────────
    $snapshot2 = ff_smoke_bam_qbo('qbo-c10', 'Test C10 New', 'USD');
    $r10 = BankAccountMatcher::assignMapping(SMOKE_BAM_FF_BASE, 'qbo-c10', $snapshot2, SMOKE_BAM_USER);
    $mapRow2 = db_row("SELECT * FROM acc_qbo_bank_account_map WHERE ff_bank_account_id = ?", [SMOKE_BAM_FF_BASE]);
    if ($r10['action'] === 'updated'
        && $mapRow2
        && $mapRow2['qbo_bank_account_id'] === 'qbo-c10'
        && $mapRow2['qbo_currency_snapshot'] === 'USD') {
        echo "PASS C10 assignMapping updates snapshot on re-link\n"; $pass++;
    } else {
        echo "FAIL C10 r10=" . json_encode($r10) . " mapRow=" . json_encode($mapRow2) . "\n"; $failures[] = 'C10';
    }

    // ── C11: duplicate qbo_id rejected ─────────────────────────────────
    ff_smoke_bam_seed_ff(SMOKE_BAM_FF_BASE + 1, 'Second FF', 'checking', 'CAD');
    $threw = false;
    try {
        BankAccountMatcher::assignMapping(SMOKE_BAM_FF_BASE + 1, 'qbo-c10', $snapshot2, SMOKE_BAM_USER);
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'already mapped');
    }
    if ($threw) {
        echo "PASS C11 duplicate qbo_id rejected with typed message\n"; $pass++;
    } else {
        echo "FAIL C11 expected RuntimeException with 'already mapped'\n"; $failures[] = 'C11';
    }

    // ── C12: unmapping removes ─────────────────────────────────────────
    $ok = BankAccountMatcher::unmapping(SMOKE_BAM_FF_BASE, SMOKE_BAM_USER);
    $remaining = db_row("SELECT id FROM acc_qbo_bank_account_map WHERE ff_bank_account_id = ?", [SMOKE_BAM_FF_BASE]);
    if ($ok === true && $remaining === null) {
        echo "PASS C12 unmapping removes row + returns true\n"; $pass++;
    } else {
        echo "FAIL C12 ok=" . var_export($ok, true) . " remaining=" . json_encode($remaining) . "\n"; $failures[] = 'C12';
    }

    // ── C13: unmapping returns false when no row ───────────────────────
    $ok2 = BankAccountMatcher::unmapping(SMOKE_BAM_FF_BASE, SMOKE_BAM_USER);
    if ($ok2 === false) {
        echo "PASS C13 unmapping returns false when no row\n"; $pass++;
    } else {
        echo "FAIL C13 ok2=" . var_export($ok2, true) . "\n"; $failures[] = 'C13';
    }

    // ── C14: getCandidates returns empty for missing FF row ────────────
    $cands14 = BankAccountMatcher::getCandidates(999999, [ff_smoke_bam_qbo('q14', 'X', 'CAD')]);
    if ($cands14 === []) {
        echo "PASS C14 missing FF row returns empty candidates\n"; $pass++;
    } else {
        echo "FAIL C14 expected []; got " . count($cands14) . " rows\n"; $failures[] = 'C14';
    }

} catch (\Throwable $e) {
    echo "FAIL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures[] = 'EXCEPTION';
} finally {
    ff_smoke_bam_cleanup();
}

echo "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "Result: {$pass}/{$total} passed";
if (!empty($failures)) {
    echo " — failures: " . implode(', ', $failures);
}
echo "\n";
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total ? 0 : 1);
