<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_account_mapping.php
 *
 * S-QBO-8 — Structural + behavioural smoke for the chart of accounts
 * mapping flow. Runs OFFLINE: no Intuit HTTP traffic (the Puller's
 * pullAll() is exercised via mock JSON through normalize()). Live
 * verification is operator-side post-commit.
 *
 * Self-cleaning: any synthetic rows the smoke creates use sentinel
 * ids (FF acc_accounts id=999990+, qbo_account_id='TEST-SMOKE-A-*')
 * so the finally block scrubs them on pass or fail.
 *
 * 42 sub-checks:
 *   C1: acc_qbo_account_map table shape — columns + indexes + FK +
 *       is_critical + critical_reason
 *   C2: AccountPuller class surface (pullAll + normalize public static)
 *   C3: AccountMatcher class surface (normalizeAccountName +
 *       findBestMatch + matchAll + typesCompatible public static)
 *   C4: AccountValidator class surface (markCriticalAccounts +
 *       unmappedCritical + assertReadyForInvoicePush +
 *       identifyCriticalFfAccounts public static)
 *   C5: ChartOfAccountsIncompleteException exists, extends
 *       QuickBooksException
 *   C6: normalizeAccountName collapses variants ("Sales Revenue" =
 *       "sales-revenue" = "SALES_REVENUE")
 *   C7: findBestMatch returns 'exact_code' when AcctNum matches
 *       (code wins over name)
 *   C8: findBestMatch respects type compatibility — matches Income
 *       not Expense for an FF Revenue account
 *   C9: findBestMatch returns null when only incompatible-type
 *       candidates exist
 *  C10: matchAll preserves operator-overridden manual rows is a
 *       caller concern (auto_match.php endpoint) — we test that the
 *       cascade itself produces 'ff_only' for an FF account without
 *       any QBO match (negative test)
 *  C11: AccountValidator::markCriticalAccounts flags a synthetic AR
 *       account as critical with the right reason
 *  C12: AccountValidator::unmappedCritical returns the unmapped
 *       critical accounts
 *  C13: AccountValidator::assertReadyForInvoicePush throws
 *       ChartOfAccountsIncompleteException when critical unmapped
 *  C14: AccountValidator::assertReadyForInvoicePush passes silently
 *       when all critical accounts mapped
 *  C15: 4 API endpoints exist + php -l clean
 *  C16: accounts.php page exists + php -l clean + Alpine factory
 *  C17: Nav config has 8 QuickBooks children incl. Accounts
 *  C18: save_mapping.php link action PRESERVES is_critical +
 *       critical_reason from the source ff_only row (regression for
 *       S-QBO-8 live-verify Finding F1)
 *  C19: save_mapping.php unlink action MOVES is_critical to the new
 *       ff_only row and CLEARS it from the demoted qbo_only side
 *       (is_critical is an FF-side semantic; qbo_only rows shouldn't
 *       carry it)
 *  C20: claimed-set tracking — when two FF accounts both auto-match
 *       the same QBO account, only the first claims it; the second
 *       falls through to no-match (D-QBO-MATCHER-1 Bug 1 fix)
 *  C21: subtype-agreement gate rejects medium-confidence cross-subtype
 *       match (FF long_term_liability cannot medium-match QBO
 *       AccountsPayable even on shared token; D-QBO-MATCHER-2 Bug 2 fix)
 *  C22: subtype-agreement gate permissive when QBO subtype empty —
 *       allows medium match when type-compat holds and QBO subtype
 *       unknown (D-QBO-MATCHER-2)
 *  C23: SUBTYPE_EQUIVALENCE constant exists with 8 baseline FF subtype
 *       keys (D-QBO-MATCHER-2)
 *  C24: assertReadyForInvoicePush passes with only ar_clearing +
 *       sales_revenue mapped (new narrowed semantics per
 *       D-QBO-VALIDATOR-4 — does NOT require tax_receivable/
 *       tax_payable/ap_clearing)
 *  C25: assertReadyForInvoicePush throws when sales_revenue category
 *       unmapped; exception message mentions 'sales_revenue'
 *  C26: assertReadyForFullCompliance throws when any category unmapped
 *       (preserves legacy all-categories semantics for cutover gating)
 *  C27: assertReadyForPaymentPush requires ar_clearing +
 *       undeposited_funds; undeposited_funds with zero FF accounts
 *       produces actionable error per D-QBO-VALIDATOR-3 operator
 *       resolution
 *  C28: markCriticalAccounts populates critical_category column on
 *       acc_qbo_account_map rows it flags
 *  C29: Migration backfill verification — every is_critical=1 row in
 *       acc_qbo_account_map has non-null critical_category in the
 *       expected category whitelist
 *  C30: assertReadyForInvoicePush × S4 — ar_clearing empty-category
 *       throw path (Trap #68 / D-QBO-VALIDATOR-5 D4): strip is_critical
 *       from all ar_clearing rows; expect throw with 'no FF account
 *       tagged' phrase + 'ar_clearing'; restore is_critical in finally
 *  C31: assertReadyForInvoicePush × S4 — sales_revenue empty-category
 *       throw path (same Trap #68 phrase for the second invoice
 *       required category)
 *  C32: assertReadyForPaymentPush × S3 multi-blocked default state —
 *       ar_clearing has FF 1030 unmapped + UF has zero tagged FF;
 *       throws naming both with 'no FF account tagged' on UF only
 *  C33: assertReadyForPaymentPush × S2 — synthetic UF mapped + AR
 *       unmapped; throws naming ONLY ar_clearing (UF correctly absent)
 *       + singular '1 required category' inflection
 *  C34: assertReadyForPaymentPush × S1+S5 — synthetic UF + AR both
 *       mapped; gate passes (AP/tax/sales unmapped — irrelevant,
 *       proves D-QBO-VALIDATOR-4 narrow-scope discipline)
 *  C35: assertReadyForBillPush × S2 — ap_clearing unmapped (default
 *       state); throws naming 'ap_clearing' + FF '2010' + singular
 *  C36: assertReadyForBillPush × S1+S5 — ap_clearing mapped, all
 *       other categories unmapped; passes silently (S3 N/A —
 *       single-required-category gate cannot multi-block)
 *  C37: assertReadyForBillPaymentPush × S3 — default state: AP
 *       unmapped + UF empty-cat both blocking; throws naming both
 *  C38: assertReadyForBillPaymentPush × S2 — synthetic UF mapped +
 *       AP unmapped; throws naming ONLY ap_clearing
 *  C39: assertReadyForBillPaymentPush × S1+S5 — synthetic UF + AP
 *       both mapped; gate passes (AR/tax/sales unmapped — irrelevant)
 *  C40: assertReadyForJournalEntryPush × S3 — both tax_receivable +
 *       tax_payable unmapped (default state); throws naming both
 *  C41: assertReadyForJournalEntryPush × S2 — only tax_receivable
 *       mapped (1050); throws naming ONLY tax_payable + singular
 *  C42: assertReadyForJournalEntryPush × S1+S5 — both tax categories
 *       mapped (1050 + 2030); gate passes (AR/AP/UF/sales unmapped
 *       — irrelevant, proves narrow scope)
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-8, S-QBO-MATCHER-GREEDY-FIX (C20-C23 added),
 *          S-QBO-VALIDATOR-SCOPE-SPLIT (C24-C29 added),
 *          S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE (C30-C42 added +
 *          C13 strengthened — closes Phase 3 audit F-P3-01 CRITICAL
 *          + F-P3-02 through F-P3-07 MEDIUM ×6)
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §7.1, §6.8 (Pusher pre-flight gates)
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\AccountPuller;
use FleetForge\QboPushers\AccountMatcher;
use FleetForge\QboPushers\AccountValidator;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;
use FleetForge\Exceptions\QuickBooksException;

$failures = [];
$pass     = 0;
$total    = 45;

/** Sentinel ids we'll clean up. */
$sentinelFfIds       = [];
$sentinelQboIds      = [];
$sentinelMappingIds  = [];

try {

// ── C1: table shape ─────────────────────────────────────────
$expectedCols = [
    'id', 'ff_account_id', 'qbo_account_id', 'qbo_sync_token',
    'qbo_name', 'qbo_fully_qualified_name', 'qbo_account_type',
    'qbo_account_subtype', 'qbo_classification', 'qbo_account_number',
    'qbo_active', 'qbo_current_balance', 'mapping_status',
    'match_confidence', 'is_critical', 'critical_reason', 'match_notes',
    'last_synced_at', 'last_pull_at', 'created_at', 'updated_at',
    'created_by_user_id',
];
$c1Errors = [];
try {
    $rows = db_select("SHOW COLUMNS FROM acc_qbo_account_map");
    $present = array_map(fn($r) => $r['Field'], $rows);
    foreach ($expectedCols as $col) {
        if (!in_array($col, $present, true)) {
            $c1Errors[] = "missing column: {$col}";
        }
    }
    $idx = db_select("SHOW INDEX FROM acc_qbo_account_map");
    $idxNames = array_unique(array_map(fn($r) => $r['Key_name'], $idx));
    foreach (['uq_ff_account', 'uq_qbo_account', 'idx_critical'] as $i) {
        if (!in_array($i, $idxNames, true)) {
            $c1Errors[] = "missing index: {$i}";
        }
    }
} catch (Throwable $e) {
    $c1Errors[] = 'SHOW COLUMNS/INDEX threw: ' . $e->getMessage();
}
if (empty($c1Errors)) {
    echo "PASS C1  acc_qbo_account_map has all 22 columns + uq_ff/uq_qbo + idx_critical\n";
    $pass++;
} else {
    echo "FAIL C1  " . implode('; ', $c1Errors) . "\n";
    $failures[] = 'C1';
}

// ── C2: AccountPuller surface ──────────────────────────────
$c2Errors = [];
if (!class_exists(AccountPuller::class)) {
    $c2Errors[] = 'AccountPuller class not autoloaded';
} else {
    $ref = new ReflectionClass(AccountPuller::class);
    foreach (['pullAll', 'normalize'] as $m) {
        if (!$ref->hasMethod($m)) { $c2Errors[] = "missing method: {$m}"; continue; }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) { $c2Errors[] = "{$m} must be public static"; }
    }
}
if (empty($c2Errors)) {
    echo "PASS C2  AccountPuller class surface (pullAll + normalize public static)\n";
    $pass++;
} else {
    echo "FAIL C2  " . implode('; ', $c2Errors) . "\n";
    $failures[] = 'C2';
}

// ── C3: AccountMatcher surface ─────────────────────────────
$c3Errors = [];
if (!class_exists(AccountMatcher::class)) {
    $c3Errors[] = 'AccountMatcher class not autoloaded';
} else {
    $ref = new ReflectionClass(AccountMatcher::class);
    foreach (['normalizeAccountName', 'findBestMatch', 'matchAll', 'typesCompatible'] as $m) {
        if (!$ref->hasMethod($m)) { $c3Errors[] = "missing method: {$m}"; continue; }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) { $c3Errors[] = "{$m} must be public static"; }
    }
}
if (empty($c3Errors)) {
    echo "PASS C3  AccountMatcher class surface (normalizeAccountName + findBestMatch + matchAll + typesCompatible public static)\n";
    $pass++;
} else {
    echo "FAIL C3  " . implode('; ', $c3Errors) . "\n";
    $failures[] = 'C3';
}

// ── C4: AccountValidator surface ───────────────────────────
$c4Errors = [];
if (!class_exists(AccountValidator::class)) {
    $c4Errors[] = 'AccountValidator class not autoloaded';
} else {
    $ref = new ReflectionClass(AccountValidator::class);
    // Per-session assert methods + helpers — D-QBO-VALIDATOR-3.
    $expectedMethods = [
        'markCriticalAccounts', 'unmappedCritical', 'unmappedByCategory',
        'blockingCategories', 'identifyCriticalFfAccounts',
        'assertReadyForInvoicePush', 'assertReadyForPaymentPush',
        'assertReadyForBillPush', 'assertReadyForBillPaymentPush',
        'assertReadyForJournalEntryPush', 'assertReadyForFullCompliance',
    ];
    foreach ($expectedMethods as $m) {
        if (!$ref->hasMethod($m)) { $c4Errors[] = "missing method: {$m}"; continue; }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) { $c4Errors[] = "{$m} must be public static"; }
    }
    // Constants per D-QBO-VALIDATOR-2.
    foreach (['CATEGORIES', 'SESSION_REQUIREMENTS'] as $c) {
        if (!$ref->hasConstant($c)) { $c4Errors[] = "missing constant: {$c}"; }
    }
}
if (empty($c4Errors)) {
    echo "PASS C4  AccountValidator class surface (11 public static + CATEGORIES + SESSION_REQUIREMENTS)\n";
    $pass++;
} else {
    echo "FAIL C4  " . implode('; ', $c4Errors) . "\n";
    $failures[] = 'C4';
}

// ── C5: ChartOfAccountsIncompleteException ─────────────────
$c5Errors = [];
if (!class_exists(ChartOfAccountsIncompleteException::class)) {
    $c5Errors[] = 'ChartOfAccountsIncompleteException class not autoloaded';
} elseif (!is_subclass_of(ChartOfAccountsIncompleteException::class, QuickBooksException::class)) {
    $c5Errors[] = 'ChartOfAccountsIncompleteException must extend QuickBooksException';
}
if (empty($c5Errors)) {
    echo "PASS C5  ChartOfAccountsIncompleteException exists + extends QuickBooksException\n";
    $pass++;
} else {
    echo "FAIL C5  " . implode('; ', $c5Errors) . "\n";
    $failures[] = 'C5';
}

// ── C6: normalizeAccountName collapses variants ────────────
$c6Errors = [];
try {
    $n1 = AccountMatcher::normalizeAccountName('Sales Revenue');
    $n2 = AccountMatcher::normalizeAccountName('sales-revenue');
    $n3 = AccountMatcher::normalizeAccountName('SALES_REVENUE');
    if ($n1 !== $n2 || $n2 !== $n3) {
        $c6Errors[] = "normalizations diverged: '{$n1}' / '{$n2}' / '{$n3}' (all should equal 'sales revenue')";
    }
    if ($n1 !== 'sales revenue') {
        $c6Errors[] = "expected 'sales revenue', got '{$n1}'";
    }
} catch (Throwable $e) {
    $c6Errors[] = 'normalizeAccountName threw: ' . $e->getMessage();
}
if (empty($c6Errors)) {
    echo "PASS C6  normalizeAccountName collapses 'Sales Revenue' = 'sales-revenue' = 'SALES_REVENUE'\n";
    $pass++;
} else {
    echo "FAIL C6  " . implode('; ', $c6Errors) . "\n";
    $failures[] = 'C6';
}

// ── C7: findBestMatch exact_code (code wins over name) ─────
$c7Errors = [];
try {
    $ff  = ['code' => '1200', 'name' => 'Accounts Receivable', 'account_type' => 'asset', 'account_subtype' => 'current_asset'];
    $qbo = [
        ['qbo_id' => '101', 'name' => 'Different Name',    'account_type' => 'Other Current Asset', 'account_subtype' => '', 'account_number' => '1200'],
        ['qbo_id' => '102', 'name' => 'Accounts Receivable','account_type' => 'Accounts Receivable', 'account_subtype' => '', 'account_number' => '9999'],
    ];
    $m = AccountMatcher::findBestMatch($ff, $qbo);
    if ($m === null) {
        $c7Errors[] = 'expected match, got null';
    } elseif ($m['qbo_id'] !== '101' || $m['confidence'] !== 'exact_code') {
        $c7Errors[] = "expected qbo_id=101 confidence=exact_code (code wins over name), got " . json_encode($m);
    }
} catch (Throwable $e) {
    $c7Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c7Errors)) {
    echo "PASS C7  findBestMatch returns exact_code (AcctNum match wins over name match)\n";
    $pass++;
} else {
    echo "FAIL C7  " . implode('; ', $c7Errors) . "\n";
    $failures[] = 'C7';
}

// ── C8: findBestMatch type compatibility ───────────────────
$c8Errors = [];
try {
    $ff  = ['code' => '4100', 'name' => 'Service Revenue', 'account_type' => 'revenue', 'account_subtype' => 'revenue'];
    $qbo = [
        ['qbo_id' => '201', 'name' => 'Service Revenue', 'account_type' => 'Expense', 'account_subtype' => '', 'account_number' => ''],
        ['qbo_id' => '202', 'name' => 'Service Revenue', 'account_type' => 'Income',  'account_subtype' => 'ServiceFeeIncome', 'account_number' => ''],
    ];
    $m = AccountMatcher::findBestMatch($ff, $qbo);
    if ($m === null) {
        $c8Errors[] = 'expected match, got null';
    } elseif ($m['qbo_id'] !== '202') {
        $c8Errors[] = "expected qbo_id=202 (Income — type-compatible with revenue), got qbo_id=" . ($m['qbo_id'] ?? '?');
    }
    if (!isset($m['confidence']) || $m['confidence'] !== 'exact_name') {
        $c8Errors[] = "expected confidence=exact_name (type+name match), got " . json_encode($m['confidence'] ?? null);
    }
} catch (Throwable $e) {
    $c8Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c8Errors)) {
    echo "PASS C8  findBestMatch respects type compatibility — matches Income not Expense for FF revenue\n";
    $pass++;
} else {
    echo "FAIL C8  " . implode('; ', $c8Errors) . "\n";
    $failures[] = 'C8';
}

// ── C9: findBestMatch refuses incompatible-type match ──────
$c9Errors = [];
try {
    $ff  = ['code' => '4200', 'name' => 'Foo', 'account_type' => 'revenue', 'account_subtype' => 'revenue'];
    $qbo = [
        ['qbo_id' => '301', 'name' => 'Foo', 'account_type' => 'Expense', 'account_subtype' => '', 'account_number' => ''],
    ];
    $m = AccountMatcher::findBestMatch($ff, $qbo);
    if ($m !== null) {
        $c9Errors[] = 'expected null (incompatible type), got ' . json_encode($m);
    }
} catch (Throwable $e) {
    $c9Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c9Errors)) {
    echo "PASS C9  findBestMatch returns null when only incompatible-type QBO candidates exist\n";
    $pass++;
} else {
    echo "FAIL C9  " . implode('; ', $c9Errors) . "\n";
    $failures[] = 'C9';
}

// ── C10: cascade returns ff_only when no match ─────────────
// (Real-world manual-preserve test belongs in the auto_match.php
// endpoint behavior; smoke covers the negative cascade case here.)
$c10Errors = [];
try {
    $ff  = ['code' => '4999', 'name' => 'Unrelated Account', 'account_type' => 'revenue', 'account_subtype' => 'revenue'];
    $qbo = [
        ['qbo_id' => '401', 'name' => 'Office Supplies', 'account_type' => 'Expense', 'account_subtype' => '', 'account_number' => '6100'],
    ];
    $m = AccountMatcher::findBestMatch($ff, $qbo);
    if ($m !== null) {
        $c10Errors[] = 'expected null on no-match, got ' . json_encode($m);
    }
} catch (Throwable $e) {
    $c10Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c10Errors)) {
    echo "PASS C10 findBestMatch returns null when no signal aligns\n";
    $pass++;
} else {
    echo "FAIL C10 " . implode('; ', $c10Errors) . "\n";
    $failures[] = 'C10';
}

// ── C11: markCriticalAccounts flags AR account ─────────────
// Setup: insert a synthetic FF account with code='1030' (AR per
// AccountValidator heuristic). markCriticalAccounts should flag it.
// Note: we use an explicit synthetic id but the real chart already
// has id=4 code='1030' so we need a code that's UNIQUE — pick code
// '9030' and TEMPORARILY rename the live row's heuristic check OR
// use a different approach. Simpler: instead of inserting a
// synthetic AR account (which would collide on the code unique
// index), we exercise the validator against the REAL AR row that's
// already in the chart and assert that the resulting mapping row
// gets flagged.
$c11Errors = [];
try {
    // Snapshot current mapping state for the real AR account so we
    // can detect the change.
    $arFf = db_row("SELECT id, code, name FROM acc_accounts WHERE code = '1030' AND is_active = 1");
    if ($arFf === null) {
        $c11Errors[] = "test pre-condition failed: no live FF account with code='1030'";
    } else {
        // Delete any pre-existing mapping row for AR so we test the
        // INSERT path. Capture the row first to restore after the test.
        $existingMapping = db_row("SELECT * FROM acc_qbo_account_map WHERE ff_account_id = ?", [(int) $arFf['id']]);
        if ($existingMapping !== null) {
            db_execute("DELETE FROM acc_qbo_account_map WHERE id = ?", [(int) $existingMapping['id']]);
        }

        $flagged = AccountValidator::markCriticalAccounts();
        if ($flagged < 1) {
            $c11Errors[] = "expected flagged >= 1, got {$flagged}";
        }
        $row = db_row(
            "SELECT id, is_critical, critical_reason, mapping_status
               FROM acc_qbo_account_map WHERE ff_account_id = ?",
            [(int) $arFf['id']]
        );
        if ($row === null) {
            $c11Errors[] = 'no acc_qbo_account_map row created for AR';
        } else {
            if ((int) $row['is_critical'] !== 1) {
                $c11Errors[] = 'is_critical not set to 1 for AR row';
            }
            if (empty($row['critical_reason'])) {
                $c11Errors[] = 'critical_reason empty for AR row';
            }
            if (!str_contains((string) $row['critical_reason'], 'Receivable')) {
                $c11Errors[] = "expected critical_reason to mention 'Receivable', got '{$row['critical_reason']}'";
            }
            $sentinelMappingIds[] = (int) $row['id'];
        }

        // Restore the original mapping row if there was one.
        if ($existingMapping !== null && $row !== null && (int) $row['id'] !== (int) $existingMapping['id']) {
            db_execute("DELETE FROM acc_qbo_account_map WHERE id = ?", [(int) $row['id']]);
            // Re-INSERT the original.
            unset($existingMapping['id'], $existingMapping['created_at'], $existingMapping['updated_at']);
            db_insert('acc_qbo_account_map', $existingMapping);
        }
    }
} catch (Throwable $e) {
    $c11Errors[] = 'C11 threw: ' . $e->getMessage();
}
if (empty($c11Errors)) {
    echo "PASS C11 markCriticalAccounts flags live AR account (code=1030) with reason mentioning 'Receivable'\n";
    $pass++;
} else {
    echo "FAIL C11 " . implode('; ', $c11Errors) . "\n";
    $failures[] = 'C11';
}

// ── C12: unmappedCritical invariant + row shape ────────────
// Invariant: total critical (per heuristic) == mapped+critical + unmapped+critical.
// This is more robust than a fixed count assertion — it works whether
// operator has manually mapped some criticals already (live state) or
// none (fresh-pull state). Also spot-checks the row shape.
$c12Errors = [];
try {
    AccountValidator::markCriticalAccounts(); // idempotent
    $totalCritical    = count(AccountValidator::identifyCriticalFfAccounts());
    $mappedCritical   = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_account_map m
           JOIN acc_accounts a ON a.id = m.ff_account_id
          WHERE m.is_critical = 1 AND m.mapping_status = 'mapped'
            AND m.qbo_account_id IS NOT NULL AND a.is_active = 1",
        []
    );
    $unmapped = AccountValidator::unmappedCritical();
    $unmappedCount = count($unmapped);

    if ($totalCritical < 1) {
        $c12Errors[] = "heuristic identified zero critical FF accounts (expected ≥1; FF chart should have AR/AP/tax/sales)";
    }
    if ($totalCritical !== $mappedCritical + $unmappedCount) {
        $c12Errors[] = "invariant violated: totalCritical={$totalCritical}, mapped+critical={$mappedCritical}, unmapped+critical={$unmappedCount} (sum mismatch)";
    }
    if (!empty($unmapped)) {
        $first = $unmapped[0];
        foreach (['ff_account_id', 'code', 'name', 'account_type', 'critical_reason'] as $k) {
            if (!array_key_exists($k, $first)) {
                $c12Errors[] = "unmappedCritical row missing key: {$k}";
            }
        }
    }
} catch (Throwable $e) {
    $c12Errors[] = 'C12 threw: ' . $e->getMessage();
}
if (empty($c12Errors)) {
    echo "PASS C12 unmappedCritical invariant holds (total = mapped+critical + unmapped+critical) + row shape\n";
    $pass++;
} else {
    echo "FAIL C12 " . implode('; ', $c12Errors) . "\n";
    $failures[] = 'C12';
}

// ── C13: assertReadyForInvoicePush throws with full message content ──
// Strengthened per Phase 3 audit F-P3-02: original C13 verified throw +
// 'unmapped' substring only; now also asserts D-QBO-VALIDATOR-5 D1
// (both blocking categories named explicitly), D2 (FF account in
// '{code} {name}' format — AR '1030' is the stable check), and D3
// (plural inflection '2 required categories').
$c13Errors = [];
try {
    AccountValidator::markCriticalAccounts(); // ensure critical rows exist
    try {
        AccountValidator::assertReadyForInvoicePush();
        $c13Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        $msg = $e->getMessage();
        if (empty($e->unmappedAccounts)) {
            $c13Errors[] = 'exception thrown but unmappedAccounts is empty';
        }
        if (!str_contains($msg, 'unmapped')) {
            $c13Errors[] = "expected message to mention 'unmapped', got: {$msg}";
        }
        // D1 — both blocking categories named explicitly.
        if (!str_contains($msg, 'ar_clearing')) {
            $c13Errors[] = "D1: expected 'ar_clearing' in message, got: {$msg}";
        }
        if (!str_contains($msg, 'sales_revenue')) {
            $c13Errors[] = "D1: expected 'sales_revenue' in message, got: {$msg}";
        }
        // D2 — at least one FF account named in '{code} {name}' format
        // (AR code '1030' is stable across seed; sales_revenue codes
        // vary by chart but 1030 is reliable).
        if (!str_contains($msg, '1030')) {
            $c13Errors[] = "D2: expected '1030' (AR FF code) in message, got: {$msg}";
        }
        // D3 — plural inflection (both ar_clearing + sales_revenue blocking
        // in default state → 'categories' not 'category').
        if (!str_contains($msg, '2 required categories')) {
            $c13Errors[] = "D3: expected plural '2 required categories', got: {$msg}";
        }
    }
} catch (Throwable $e) {
    $c13Errors[] = 'C13 threw unexpected: ' . get_class($e) . ' ' . $e->getMessage();
}
if (empty($c13Errors)) {
    echo "PASS C13 assertReadyForInvoicePush throws naming ar_clearing + sales_revenue + FF '1030' + plural inflection (D-QBO-VALIDATOR-5 D1+D2+D3)\n";
    $pass++;
} else {
    echo "FAIL C13 " . implode('; ', $c13Errors) . "\n";
    $failures[] = 'C13';
}

// ── C14: assertReadyForInvoicePush passes when all mapped ──
// Synthetic setup: temporarily map ALL critical FF accounts to
// sentinel qbo_account_ids, assert passes, then revert.
$c14Errors = [];
$tempMappings = [];
try {
    $crit = AccountValidator::identifyCriticalFfAccounts();
    foreach ($crit as $cf) {
        $sentinelQbo = 'TEST-SMOKE-A-' . bin2hex(random_bytes(6));
        $sentinelQboIds[] = $sentinelQbo;
        // UPDATE the existing ff_only row (created by markCriticalAccounts
        // in C11/C12) to add a synthetic qbo_account_id + mapped status.
        db_execute(
            "UPDATE acc_qbo_account_map SET
                qbo_account_id   = ?,
                qbo_sync_token   = '0',
                qbo_name         = CONCAT('SMOKE QBO Mirror of ', ?),
                mapping_status   = 'mapped',
                match_confidence = 'manual'
              WHERE ff_account_id = ? AND is_critical = 1",
            [$sentinelQbo, (string) $cf['name'], (int) $cf['id']]
        );
        $tempMappings[] = (int) $cf['id'];
    }

    try {
        AccountValidator::assertReadyForInvoicePush();
        // No exception = pass.
    } catch (\Throwable $e) {
        $c14Errors[] = 'expected no exception, got ' . get_class($e) . ': ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $c14Errors[] = 'C14 setup threw: ' . $e->getMessage();
} finally {
    // Revert: clear sentinel qbo_account_ids so the next test run sees
    // the chart back in unmapped state. The defensive sentinel cleanup
    // at the end of the smoke also catches these.
    foreach ($tempMappings as $ffId) {
        try {
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    qbo_account_id   = NULL,
                    qbo_sync_token   = NULL,
                    qbo_name         = NULL,
                    mapping_status   = 'ff_only',
                    match_confidence = NULL
                  WHERE ff_account_id = ?",
                [$ffId]
            );
        } catch (\Throwable $e) {
            // Best-effort cleanup; nothing we can do.
        }
    }
}
if (empty($c14Errors)) {
    echo "PASS C14 assertReadyForInvoicePush passes silently when all critical mapped (synthetic temp-map)\n";
    $pass++;
} else {
    echo "FAIL C14 " . implode('; ', $c14Errors) . "\n";
    $failures[] = 'C14';
}

// ── C15: 4 API endpoint files exist + lint clean ───────────
$c15Errors = [];
$endpoints = [
    'api/v1/quickbooks/accounts/pull.php',
    'api/v1/quickbooks/accounts/auto_match.php',
    'api/v1/quickbooks/accounts/save_mapping.php',
    'api/v1/quickbooks/accounts/list.php',
];
foreach ($endpoints as $rel) {
    $abs = realpath(__DIR__ . '/../' . $rel);
    if ($abs === false || !is_readable($abs)) {
        $c15Errors[] = "endpoint missing: {$rel}";
        continue;
    }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($abs) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c15Errors[] = "lint failed: {$rel} — " . implode('; ', $out);
    }
}
if (empty($c15Errors)) {
    echo "PASS C15 4 API endpoints exist + php -l clean\n";
    $pass++;
} else {
    echo "FAIL C15 " . implode('; ', $c15Errors) . "\n";
    $failures[] = 'C15';
}

// ── C16: accounts.php page exists + lints + Alpine factory ──
$c16Errors = [];
$pagePath = realpath(__DIR__ . '/../app/admin/quickbooks/accounts.php');
if ($pagePath === false || !is_readable($pagePath)) {
    $c16Errors[] = 'app/admin/quickbooks/accounts.php missing or unreadable';
} else {
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($pagePath) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c16Errors[] = 'accounts.php lint failed: ' . implode('; ', $out);
    }
    $src = file_get_contents($pagePath);
    if (!str_contains($src, "qboAccountMapping()")) {
        $c16Errors[] = 'accounts.php does not define qboAccountMapping() Alpine factory';
    }
    if (!preg_match("/require_permission\(\s*'quickbooks'\s*,\s*'view'\s*\)/", $src)) {
        $c16Errors[] = "accounts.php missing require_permission('quickbooks','view') gate";
    }
}
if (empty($c16Errors)) {
    echo "PASS C16 accounts.php page exists, lints, declares Alpine factory + view gate\n";
    $pass++;
} else {
    echo "FAIL C16 " . implode('; ', $c16Errors) . "\n";
    $failures[] = 'C16';
}

// ── C17: nav has 9 QuickBooks children incl. Accounts ──────
// Bumped 8→9 in S-QBO-9 (Tax Codes between Accounts and Settings).
$c17Errors = [];
$nav = require __DIR__ . '/../config/navigation.php';
$qbo = null;
foreach ($nav as $group) {
    if (($group['label'] ?? '') === 'QuickBooks' && !empty($group['children'] ?? [])) {
        $qbo = $group;
        break;
    }
}
if ($qbo === null) {
    $c17Errors[] = 'QuickBooks nav group not found';
} else {
    $children = $qbo['children'] ?? [];
    $labels = array_map(fn($c) => $c['label'] ?? '', $children);
    if (count($children) !== 17) {
        $c17Errors[] = 'expected 17 QuickBooks children, got ' . count($children) . ' (' . implode(', ', $labels) . ')';
    }
    if (!in_array('Accounts', $labels, true)) {
        $c17Errors[] = "no 'Accounts' child in QuickBooks nav";
    }
    $expectedOrder = ['Dashboard', 'Sync Queue', 'Sync Log', 'Drift', 'Manual Sync', 'Customers', 'Vendors', 'Accounts', 'Tax Codes', 'Items', 'Invoices', 'Credit Memos', 'Bills', 'Bill Payments', 'Payments', 'Journal Entries', 'Settings'];
    if ($labels !== $expectedOrder) {
        $c17Errors[] = 'nav order mismatch — got [' . implode(', ', $labels) . '], expected [' . implode(', ', $expectedOrder) . ']';
    }
}
if (empty($c17Errors)) {
    echo "PASS C17 nav has 16 QuickBooks children with Accounts in expected position\n";
    $pass++;
} else {
    echo "FAIL C17 " . implode('; ', $c17Errors) . "\n";
    $failures[] = 'C17';
}

// ── C18: link action preserves is_critical (Finding F1 fix) ─
// Setup: pick a live critical FF account that has an ff_only row
// (markCriticalAccounts ran in C11/C12 so this is guaranteed). Build
// a sentinel qbo_only row, then call the link action logic by
// mirroring what save_mapping.php does — capture is_critical BEFORE
// DELETE, propagate into the UPDATE/INSERT.
$c18Errors = [];
try {
    // Find an ff_only critical row to test against. Use AP (code 2010)
    // because it's reliably in ff_only state from prior tests.
    $ffOnly = db_row(
        "SELECT m.id, m.ff_account_id, m.is_critical, m.critical_reason
           FROM acc_qbo_account_map m
           JOIN acc_accounts a ON a.id = m.ff_account_id
          WHERE a.code = '2010' AND m.mapping_status = 'ff_only' AND m.is_critical = 1
          LIMIT 1"
    );
    if ($ffOnly === null) {
        $c18Errors[] = "test pre-condition: no ff_only critical row for AP (code 2010) — markCriticalAccounts may have not yet run";
    } else {
        $ffId            = (int) $ffOnly['ff_account_id'];
        $beforeCritical  = (int) $ffOnly['is_critical'];
        $beforeReason    = $ffOnly['critical_reason'];
        $sentinelQboId   = 'TEST-SMOKE-A-LINK-' . bin2hex(random_bytes(4));
        $sentinelQboIds[] = $sentinelQboId;

        // Insert a qbo_only sentinel row representing the link target.
        $qboOnlyId = db_insert('acc_qbo_account_map', [
            'qbo_account_id'  => $sentinelQboId,
            'qbo_name'        => 'SMOKE Link Target',
            'mapping_status'  => 'qbo_only',
        ]);
        $sentinelMappingIds[] = $qboOnlyId;

        // Invoke the link action by POSTing to save_mapping.php via
        // file_get_contents wouldn't work in CLI; instead inline the
        // logic that mirrors api/v1/quickbooks/accounts/save_mapping.php
        // link branch — same SELECT-before-DELETE + UPDATE-with-inherit.
        $captured = db_row(
            "SELECT is_critical, critical_reason FROM acc_qbo_account_map
              WHERE ff_account_id = ? AND qbo_account_id IS NULL",
            [$ffId]
        );
        if ($captured === null || (int) $captured['is_critical'] !== $beforeCritical) {
            $c18Errors[] = 'capture step failed: is_critical not readable from ff_only row';
        }
        $inheritCritical = $captured !== null ? (int) $captured['is_critical']  : 0;
        $inheritReason   = $captured !== null ? $captured['critical_reason']    : null;
        db_execute(
            "DELETE FROM acc_qbo_account_map
              WHERE ff_account_id = ? AND qbo_account_id IS NULL",
            [$ffId]
        );
        db_execute(
            "UPDATE acc_qbo_account_map SET
                ff_account_id    = ?,
                mapping_status   = 'mapped',
                match_confidence = 'manual',
                is_critical      = ?,
                critical_reason  = ?,
                last_synced_at   = NOW()
              WHERE id = ?",
            [$ffId, $inheritCritical, $inheritReason, (int) $qboOnlyId]
        );

        // Verify is_critical + critical_reason carried through.
        $after = db_row("SELECT is_critical, critical_reason, mapping_status FROM acc_qbo_account_map WHERE id = ?", [(int) $qboOnlyId]);
        if ((int) $after['is_critical'] !== $beforeCritical) {
            $c18Errors[] = "is_critical not preserved: was={$beforeCritical}, after-link=" . ($after['is_critical'] ?? 'NULL');
        }
        if ($after['critical_reason'] !== $beforeReason) {
            $c18Errors[] = "critical_reason not preserved: was='{$beforeReason}', after-link='" . ($after['critical_reason'] ?? 'NULL') . "'";
        }
        if ($after['mapping_status'] !== 'mapped') {
            $c18Errors[] = "expected mapping_status=mapped, got={$after['mapping_status']}";
        }

        // Cleanup C18: revert to baseline ff_only state via markCriticalAccounts.
        db_execute("UPDATE acc_qbo_account_map SET ff_account_id = NULL, mapping_status='qbo_only', match_confidence=NULL, is_critical=0, critical_reason=NULL WHERE id = ?", [(int) $qboOnlyId]);
    }
} catch (Throwable $e) {
    $c18Errors[] = 'C18 threw: ' . $e->getMessage();
}
if (empty($c18Errors)) {
    echo "PASS C18 save_mapping link action preserves is_critical + critical_reason from ff_only row (Finding F1 fix)\n";
    $pass++;
} else {
    echo "FAIL C18 " . implode('; ', $c18Errors) . "\n";
    $failures[] = 'C18';
}

// ── C19: unlink moves is_critical to ff_only side, clears qbo_only ──
// Setup: create a synthetic mapped row with is_critical=1 + critical_reason.
// Unlink it. Verify: new ff_only row has is_critical=1; old row (now
// qbo_only) has is_critical=0.
$c19Errors = [];
try {
    // Use a sentinel FF account id and qbo_account_id for full isolation.
    // First we need a real FF account id to satisfy FK. Reuse AP (id=21).
    $ffId2 = 21;
    $sentinelQboId2 = 'TEST-SMOKE-A-UNLINK-' . bin2hex(random_bytes(4));
    $sentinelQboIds[] = $sentinelQboId2;

    // Drop any prior mapping row for AP so we can INSERT fresh.
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = ?", [$ffId2]);

    // INSERT a mapped+critical row directly.
    $mappedId = db_insert('acc_qbo_account_map', [
        'ff_account_id'   => $ffId2,
        'qbo_account_id'  => $sentinelQboId2,
        'qbo_name'        => 'SMOKE Unlink Target',
        'mapping_status'  => 'mapped',
        'match_confidence'=> 'manual',
        'is_critical'     => 1,
        'critical_reason' => 'SMOKE C19 critical reason',
    ]);
    $sentinelMappingIds[] = $mappedId;

    // Inline the unlink-with-both-sides logic from save_mapping.php.
    $row = db_row("SELECT * FROM acc_qbo_account_map WHERE id = ?", [(int) $mappedId]);
    db_execute(
        "UPDATE acc_qbo_account_map SET
            ff_account_id    = NULL,
            mapping_status   = 'qbo_only',
            match_confidence = NULL,
            is_critical      = 0,
            critical_reason  = NULL
          WHERE id = ?",
        [(int) $mappedId]
    );
    $newFfOnlyId = db_insert('acc_qbo_account_map', [
        'ff_account_id'   => (int) $row['ff_account_id'],
        'mapping_status'  => 'ff_only',
        'is_critical'     => (int) $row['is_critical'],
        'critical_reason' => $row['critical_reason'],
    ]);
    $sentinelMappingIds[] = $newFfOnlyId;

    // Verify both sides.
    $demotedQboOnly = db_row("SELECT is_critical, critical_reason, mapping_status FROM acc_qbo_account_map WHERE id = ?", [(int) $mappedId]);
    $newFfOnly      = db_row("SELECT is_critical, critical_reason, mapping_status FROM acc_qbo_account_map WHERE id = ?", [(int) $newFfOnlyId]);

    if ((int) $demotedQboOnly['is_critical'] !== 0) {
        $c19Errors[] = "demoted qbo_only side: is_critical should be 0, got " . $demotedQboOnly['is_critical'];
    }
    if ($demotedQboOnly['critical_reason'] !== null) {
        $c19Errors[] = "demoted qbo_only side: critical_reason should be NULL, got '" . $demotedQboOnly['critical_reason'] . "'";
    }
    if ($demotedQboOnly['mapping_status'] !== 'qbo_only') {
        $c19Errors[] = "demoted side: mapping_status should be qbo_only, got " . $demotedQboOnly['mapping_status'];
    }
    if ((int) $newFfOnly['is_critical'] !== 1) {
        $c19Errors[] = "new ff_only side: is_critical should be 1, got " . $newFfOnly['is_critical'];
    }
    if ($newFfOnly['critical_reason'] !== 'SMOKE C19 critical reason') {
        $c19Errors[] = "new ff_only side: critical_reason should be 'SMOKE C19 critical reason', got '" . $newFfOnly['critical_reason'] . "'";
    }
    if ($newFfOnly['mapping_status'] !== 'ff_only') {
        $c19Errors[] = "new side: mapping_status should be ff_only, got " . $newFfOnly['mapping_status'];
    }
} catch (Throwable $e) {
    $c19Errors[] = 'C19 threw: ' . $e->getMessage();
}
if (empty($c19Errors)) {
    echo "PASS C19 unlink moves is_critical to new ff_only row, clears it from demoted qbo_only side\n";
    $pass++;
} else {
    echo "FAIL C19 " . implode('; ', $c19Errors) . "\n";
    $failures[] = 'C19';
}

// ── C20: claimed-set tracking prevents double-claim (Bug 1) ─
// Two FF accounts both auto-matchable via exact_name to the same
// QBO account. After matchAll, only the first FF (by iteration order
// = id ASC) gets mapped; the second falls through to ff_only.
$c20Errors = [];
$c20FfIds  = [];
try {
    // Insert 2 synthetic FF accounts with same normalized name + type.
    $ffId1 = db_insert('acc_accounts', [
        'id'              => 999990,
        'code'            => 'SMOKE-C20-A',
        'name'            => 'Zzqq Sentinel Sync',
        'account_type'    => 'asset',
        'account_subtype' => 'current_asset',
        'is_active'       => 1,
    ]);
    $ffId2 = db_insert('acc_accounts', [
        'id'              => 999991,
        'code'            => 'SMOKE-C20-B',
        'name'            => 'Zzqq Sentinel Sync',
        'account_type'    => 'asset',
        'account_subtype' => 'current_asset',
        'is_active'       => 1,
    ]);
    $c20FfIds = [(int) $ffId1, (int) $ffId2];

    // Single QBO candidate with matching name.
    $qboCandidates = [
        ['qbo_id' => 'TEST-SMOKE-A-C20', 'name' => 'Zzqq Sentinel Sync', 'account_type' => 'Other Current Asset', 'account_subtype' => '', 'account_number' => ''],
    ];
    $decisions = AccountMatcher::matchAll($qboCandidates);

    // Count how many of our 2 synthetic FFs got mapped to the QBO.
    $mappedCount = 0;
    $ff1Status = null;
    $ff2Status = null;
    foreach ($decisions as $d) {
        if ((int) ($d['ff_account_id'] ?? 0) === (int) $ffId1) { $ff1Status = $d['mapping_status']; }
        if ((int) ($d['ff_account_id'] ?? 0) === (int) $ffId2) { $ff2Status = $d['mapping_status']; }
        if (($d['qbo_account_id'] ?? null) === 'TEST-SMOKE-A-C20' && $d['mapping_status'] === 'mapped') {
            $mappedCount++;
        }
    }
    if ($mappedCount !== 1) {
        $c20Errors[] = "expected exactly 1 mapped decision for QBO TEST-SMOKE-A-C20, got {$mappedCount}";
    }
    // First FF (lower id) wins; second falls through to ff_only.
    if ($ff1Status !== 'mapped') {
        $c20Errors[] = "expected ffId1 mapped, got " . ($ff1Status ?? 'null');
    }
    if ($ff2Status !== 'ff_only') {
        $c20Errors[] = "expected ffId2 ff_only (claimed-set blocked it), got " . ($ff2Status ?? 'null');
    }
} catch (Throwable $e) {
    $c20Errors[] = 'C20 threw: ' . $e->getMessage();
}
if (empty($c20Errors)) {
    echo "PASS C20 claimed-set tracking — only first FF claims a contested QBO account (Bug 1)\n";
    $pass++;
} else {
    echo "FAIL C20 " . implode('; ', $c20Errors) . "\n";
    $failures[] = 'C20';
}

// ── C21: subtype-agreement gate rejects cross-subtype medium (Bug 2) ─
// FF account: long_term_liability subtype + name 'Equipment Loans Payable'.
// QBO candidate: AccountsPayable subtype, name shares token 'payable'.
// Type-compat passes (FF liability → QBO 'Accounts Payable'). Token
// 'payable' overlaps. WITHOUT the gate, this would have matched
// medium. WITH the gate, FF long_term_liability does NOT permit QBO
// AccountsPayable subtype → returns null.
$c21Errors = [];
try {
    $ff = [
        'code'            => '',
        'name'            => 'Equipment Loans Payable',
        'account_type'    => 'liability',
        'account_subtype' => 'long_term_liability',
    ];
    $qbo = [
        ['qbo_id' => 'TEST-SMOKE-A-C21', 'name' => 'Accounts Payable A/P', 'account_type' => 'Accounts Payable', 'account_subtype' => 'AccountsPayable', 'account_number' => ''],
    ];
    $m = AccountMatcher::findBestMatch($ff, $qbo);
    if ($m !== null) {
        $c21Errors[] = "expected null (subtype gate rejects long_term_liability → AccountsPayable), got " . json_encode($m);
    }
} catch (Throwable $e) {
    $c21Errors[] = 'C21 threw: ' . $e->getMessage();
}
if (empty($c21Errors)) {
    echo "PASS C21 subtype-agreement gate rejects long_term_liability → AccountsPayable medium match (Bug 2)\n";
    $pass++;
} else {
    echo "FAIL C21 " . implode('; ', $c21Errors) . "\n";
    $failures[] = 'C21';
}

// ── C22: subtype-agreement permissive when QBO subtype empty ─
// FF operating_expense (not in SUBTYPE_EQUIVALENCE so falls back to
// type-compat-only). QBO Expense type, empty AccountSubType. Token
// 'office' shared. Should match at medium confidence.
$c22Errors = [];
try {
    $ff = [
        'code'            => '',
        'name'            => 'Office Supplies',
        'account_type'    => 'operating_expense',
        'account_subtype' => 'operating_expense',
    ];
    $qbo = [
        ['qbo_id' => 'TEST-SMOKE-A-C22', 'name' => 'Office Equipment', 'account_type' => 'Expense', 'account_subtype' => '', 'account_number' => ''],
    ];
    $m = AccountMatcher::findBestMatch($ff, $qbo);
    if ($m === null) {
        $c22Errors[] = 'expected medium match when QBO subtype empty, got null';
    } elseif ($m['confidence'] !== 'medium' || $m['qbo_id'] !== 'TEST-SMOKE-A-C22') {
        $c22Errors[] = 'expected medium match for C22, got ' . json_encode($m);
    }
} catch (Throwable $e) {
    $c22Errors[] = 'C22 threw: ' . $e->getMessage();
}
if (empty($c22Errors)) {
    echo "PASS C22 subtype-agreement gate permissive when QBO subtype empty\n";
    $pass++;
} else {
    echo "FAIL C22 " . implode('; ', $c22Errors) . "\n";
    $failures[] = 'C22';
}

// ── C23: SUBTYPE_EQUIVALENCE has 8 baseline FF keys ────────
$c23Errors = [];
try {
    $expectedKeys = [
        'current_asset', 'fixed_asset',
        'current_liability', 'long_term_liability',
        'equity', 'revenue', 'cost_of_revenue',
    ];
    $present = array_keys(AccountMatcher::SUBTYPE_EQUIVALENCE);
    foreach ($expectedKeys as $k) {
        if (!in_array($k, $present, true)) {
            $c23Errors[] = "SUBTYPE_EQUIVALENCE missing FF subtype key: {$k}";
        }
    }
    // FF keys are snake_case (NOT PascalCase) per K-22 resolution.
    foreach ($present as $k) {
        if ($k !== strtolower($k) || str_contains($k, ' ')) {
            $c23Errors[] = "SUBTYPE_EQUIVALENCE key '{$k}' is not lowercase snake_case";
        }
    }
    // Values should be PascalCase QBO subtype names — sanity check one.
    if (!in_array('AccountsReceivable', AccountMatcher::SUBTYPE_EQUIVALENCE['current_asset'], true)) {
        $c23Errors[] = "SUBTYPE_EQUIVALENCE[current_asset] missing 'AccountsReceivable'";
    }
    if (!in_array('OtherCurrentLiabilities', AccountMatcher::SUBTYPE_EQUIVALENCE['current_liability'], true)) {
        $c23Errors[] = "SUBTYPE_EQUIVALENCE[current_liability] missing 'OtherCurrentLiabilities' (note: PLURAL per QBO taxonomy)";
    }
} catch (Throwable $e) {
    $c23Errors[] = 'C23 threw: ' . $e->getMessage();
}
if (empty($c23Errors)) {
    echo "PASS C23 SUBTYPE_EQUIVALENCE has 7 baseline FF snake_case keys with QBO PascalCase values\n";
    $pass++;
} else {
    echo "FAIL C23 " . implode('; ', $c23Errors) . "\n";
    $failures[] = 'C23';
}

// ── C24: invoice push passes with ar_clearing + sales_revenue only ──
// New narrowed semantics per D-QBO-VALIDATOR-4. Setup: temp-map AR
// (1030) + Sales Revenue (4122) to synthetic QBO ids; leave tax /
// undeposited_funds / AP unmapped. assertReadyForInvoicePush must
// pass silently.
$c24Errors = [];
$c24TempMappedFfIds = [];
try {
    AccountValidator::markCriticalAccounts(); // ensure baseline
    $arFf    = db_row("SELECT id FROM acc_accounts WHERE code='1030' AND is_active=1");
    $salesFf = db_row("SELECT id FROM acc_accounts WHERE code='4122' AND is_active=1 LIMIT 1");
    if ($arFf === null || $salesFf === null) {
        $c24Errors[] = 'pre-condition: AR (1030) or Sales Revenue (4122) absent from live chart';
    } else {
        foreach ([$arFf, $salesFf] as $ff) {
            $sentinelQbo = 'TEST-SMOKE-A-' . bin2hex(random_bytes(6));
            $sentinelQboIds[] = $sentinelQbo;
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    qbo_account_id   = ?,
                    qbo_sync_token   = '0',
                    qbo_name         = 'SMOKE C24 mirror',
                    mapping_status   = 'mapped',
                    match_confidence = 'manual'
                  WHERE ff_account_id = ? AND is_critical = 1",
                [$sentinelQbo, (int) $ff['id']]
            );
            $c24TempMappedFfIds[] = (int) $ff['id'];
        }
        try {
            AccountValidator::assertReadyForInvoicePush();
            // PASS — no exception.
        } catch (\Throwable $e) {
            $c24Errors[] = 'expected no exception, got ' . get_class($e) . ': ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c24Errors[] = 'C24 setup threw: ' . $e->getMessage();
} finally {
    foreach ($c24TempMappedFfIds as $ffId) {
        try {
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    qbo_account_id   = NULL,
                    qbo_sync_token   = NULL,
                    qbo_name         = NULL,
                    mapping_status   = 'ff_only',
                    match_confidence = NULL
                  WHERE ff_account_id = ?",
                [$ffId]
            );
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
if (empty($c24Errors)) {
    echo "PASS C24 assertReadyForInvoicePush passes with only ar_clearing + sales_revenue mapped (D-QBO-VALIDATOR-4 narrowed semantics)\n";
    $pass++;
} else {
    echo "FAIL C24 " . implode('; ', $c24Errors) . "\n";
    $failures[] = 'C24';
}

// ── C25: invoice push throws when sales_revenue unmapped ──
// Setup: map only AR (ar_clearing satisfied); leave sales_revenue
// unmapped. Gate must throw with message mentioning 'sales_revenue'.
$c25Errors = [];
$c25TempFfId = null;
try {
    AccountValidator::markCriticalAccounts();
    $arFf = db_row("SELECT id FROM acc_accounts WHERE code='1030' AND is_active=1");
    if ($arFf === null) {
        $c25Errors[] = 'pre-condition: AR (1030) absent';
    } else {
        $sentinelQbo = 'TEST-SMOKE-A-' . bin2hex(random_bytes(6));
        $sentinelQboIds[] = $sentinelQbo;
        db_execute(
            "UPDATE acc_qbo_account_map SET
                qbo_account_id   = ?,
                qbo_sync_token   = '0',
                qbo_name         = 'SMOKE C25 mirror',
                mapping_status   = 'mapped',
                match_confidence = 'manual'
              WHERE ff_account_id = ? AND is_critical = 1",
            [$sentinelQbo, (int) $arFf['id']]
        );
        $c25TempFfId = (int) $arFf['id'];

        try {
            AccountValidator::assertReadyForInvoicePush();
            $c25Errors[] = 'expected exception, none thrown';
        } catch (ChartOfAccountsIncompleteException $e) {
            if (!str_contains($e->getMessage(), 'sales_revenue')) {
                $c25Errors[] = "expected message to mention 'sales_revenue', got: " . $e->getMessage();
            }
            if (empty($e->unmappedAccounts)) {
                $c25Errors[] = 'exception thrown but unmappedAccounts is empty';
            }
        } catch (\Throwable $e) {
            $c25Errors[] = 'unexpected exception type: ' . get_class($e) . ' — ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c25Errors[] = 'C25 setup threw: ' . $e->getMessage();
} finally {
    if ($c25TempFfId !== null) {
        try {
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    qbo_account_id   = NULL,
                    qbo_sync_token   = NULL,
                    qbo_name         = NULL,
                    mapping_status   = 'ff_only',
                    match_confidence = NULL
                  WHERE ff_account_id = ?",
                [$c25TempFfId]
            );
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
if (empty($c25Errors)) {
    echo "PASS C25 assertReadyForInvoicePush throws naming 'sales_revenue' when that category unmapped\n";
    $pass++;
} else {
    echo "FAIL C25 " . implode('; ', $c25Errors) . "\n";
    $failures[] = 'C25';
}

// ── C26: full compliance throws when any category unmapped ──
// Setup: same as C24 (ar_clearing + sales_revenue mapped). Invoice
// push gate passes (C24) but FullCompliance gate must throw because
// ap_clearing / tax_receivable / tax_payable / undeposited_funds
// remain unmapped.
$c26Errors = [];
$c26TempMappedFfIds = [];
try {
    AccountValidator::markCriticalAccounts();
    $arFf    = db_row("SELECT id FROM acc_accounts WHERE code='1030' AND is_active=1");
    $salesFf = db_row("SELECT id FROM acc_accounts WHERE code='4122' AND is_active=1 LIMIT 1");
    if ($arFf === null || $salesFf === null) {
        $c26Errors[] = 'pre-condition: AR or Sales Revenue absent';
    } else {
        foreach ([$arFf, $salesFf] as $ff) {
            $sentinelQbo = 'TEST-SMOKE-A-' . bin2hex(random_bytes(6));
            $sentinelQboIds[] = $sentinelQbo;
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    qbo_account_id   = ?,
                    qbo_sync_token   = '0',
                    qbo_name         = 'SMOKE C26 mirror',
                    mapping_status   = 'mapped',
                    match_confidence = 'manual'
                  WHERE ff_account_id = ? AND is_critical = 1",
                [$sentinelQbo, (int) $ff['id']]
            );
            $c26TempMappedFfIds[] = (int) $ff['id'];
        }
        try {
            AccountValidator::assertReadyForFullCompliance();
            $c26Errors[] = 'expected exception, none thrown';
        } catch (ChartOfAccountsIncompleteException $e) {
            // At least 3 categories should be blocking (ap_clearing,
            // tax_receivable, tax_payable, undeposited_funds — exact
            // count depends on chart state but must be > 1).
            $blockingMentioned = 0;
            foreach (['ap_clearing', 'tax_receivable', 'tax_payable', 'undeposited_funds'] as $cat) {
                if (str_contains($e->getMessage(), $cat)) { $blockingMentioned++; }
            }
            if ($blockingMentioned < 3) {
                $c26Errors[] = "expected ≥3 blocking categories named in message, got {$blockingMentioned}: " . $e->getMessage();
            }
        } catch (\Throwable $e) {
            $c26Errors[] = 'unexpected exception type: ' . get_class($e) . ' — ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c26Errors[] = 'C26 setup threw: ' . $e->getMessage();
} finally {
    foreach ($c26TempMappedFfIds as $ffId) {
        try {
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    qbo_account_id   = NULL,
                    qbo_sync_token   = NULL,
                    qbo_name         = NULL,
                    mapping_status   = 'ff_only',
                    match_confidence = NULL
                  WHERE ff_account_id = ?",
                [$ffId]
            );
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
if (empty($c26Errors)) {
    echo "PASS C26 assertReadyForFullCompliance throws naming multiple missing categories when only invoice-push categories mapped\n";
    $pass++;
} else {
    echo "FAIL C26 " . implode('; ', $c26Errors) . "\n";
    $failures[] = 'C26';
}

// ── C27: payment push requires ar_clearing + undeposited_funds ──
// Setup: map AR only. Gate must throw, naming 'undeposited_funds'
// AND noting that no FF account is tagged with that category (per
// D-QBO-VALIDATOR-3 operator resolution — UF has no FF account in
// v1 chart). This is the actionable error path.
$c27Errors = [];
$c27TempFfId = null;
try {
    AccountValidator::markCriticalAccounts();
    $arFf = db_row("SELECT id FROM acc_accounts WHERE code='1030' AND is_active=1");
    if ($arFf === null) {
        $c27Errors[] = 'pre-condition: AR absent';
    } else {
        $sentinelQbo = 'TEST-SMOKE-A-' . bin2hex(random_bytes(6));
        $sentinelQboIds[] = $sentinelQbo;
        db_execute(
            "UPDATE acc_qbo_account_map SET
                qbo_account_id   = ?,
                qbo_sync_token   = '0',
                qbo_name         = 'SMOKE C27 mirror',
                mapping_status   = 'mapped',
                match_confidence = 'manual'
              WHERE ff_account_id = ? AND is_critical = 1",
            [$sentinelQbo, (int) $arFf['id']]
        );
        $c27TempFfId = (int) $arFf['id'];

        try {
            AccountValidator::assertReadyForPaymentPush();
            $c27Errors[] = 'expected exception, none thrown';
        } catch (ChartOfAccountsIncompleteException $e) {
            // Message should NAME undeposited_funds + indicate no FF
            // account is tagged (the operator-actionable variant).
            if (!str_contains($e->getMessage(), 'undeposited_funds')) {
                $c27Errors[] = "expected message to mention 'undeposited_funds', got: " . $e->getMessage();
            }
            if (!str_contains($e->getMessage(), 'no FF account tagged')) {
                $c27Errors[] = "expected message to note 'no FF account tagged' for empty-category state, got: " . $e->getMessage();
            }
            // ar_clearing should NOT be named (it IS mapped); message
            // should only name the blocking category.
            if (str_contains($e->getMessage(), 'ar_clearing:')) {
                $c27Errors[] = "ar_clearing is mapped — should NOT be in error message: " . $e->getMessage();
            }
        } catch (\Throwable $e) {
            $c27Errors[] = 'unexpected exception type: ' . get_class($e) . ' — ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c27Errors[] = 'C27 setup threw: ' . $e->getMessage();
} finally {
    if ($c27TempFfId !== null) {
        try {
            db_execute(
                "UPDATE acc_qbo_account_map SET
                    qbo_account_id   = NULL,
                    qbo_sync_token   = NULL,
                    qbo_name         = NULL,
                    mapping_status   = 'ff_only',
                    match_confidence = NULL
                  WHERE ff_account_id = ?",
                [$c27TempFfId]
            );
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
if (empty($c27Errors)) {
    echo "PASS C27 assertReadyForPaymentPush throws with 'undeposited_funds no FF account tagged' when only ar_clearing mapped\n";
    $pass++;
} else {
    echo "FAIL C27 " . implode('; ', $c27Errors) . "\n";
    $failures[] = 'C27';
}

// ── C28: markCriticalAccounts populates critical_category ──
// Confirm that running markCriticalAccounts() against live chart
// produces critical_category values matching the FF code-pattern
// heuristic. Idempotent — safe to re-run.
$c28Errors = [];
try {
    AccountValidator::markCriticalAccounts();
    $rows = db_select(
        "SELECT a.code, m.critical_category
           FROM acc_qbo_account_map m
           JOIN acc_accounts a ON a.id = m.ff_account_id
          WHERE m.is_critical = 1
            AND a.is_active = 1"
    );
    $expectedMap = [
        '1030' => 'ar_clearing',
        '2010' => 'ap_clearing',
        '1050' => 'tax_receivable',
        '1060' => 'tax_receivable',
        '2030' => 'tax_payable',
        '2040' => 'tax_payable',
        // 4xxx is_system → sales_revenue (current chart has 4122)
    ];
    foreach ($rows as $r) {
        $code = (string) $r['code'];
        $cat  = (string) ($r['critical_category'] ?? '');
        if ($cat === '') {
            $c28Errors[] = "FF code {$code} has is_critical=1 but critical_category empty";
            continue;
        }
        if (isset($expectedMap[$code]) && $expectedMap[$code] !== $cat) {
            $c28Errors[] = "FF code {$code} expected category '{$expectedMap[$code]}', got '{$cat}'";
        } elseif (!isset($expectedMap[$code]) && str_starts_with($code, '4')) {
            // 4xxx accounts should be sales_revenue per heuristic.
            if ($cat !== 'sales_revenue') {
                $c28Errors[] = "FF 4xxx code {$code} expected category 'sales_revenue', got '{$cat}'";
            }
        }
    }
    if (count($rows) < 6) {
        $c28Errors[] = "expected ≥6 critical rows, got " . count($rows);
    }
} catch (Throwable $e) {
    $c28Errors[] = 'C28 threw: ' . $e->getMessage();
}
if (empty($c28Errors)) {
    echo "PASS C28 markCriticalAccounts populates critical_category matching FF code heuristic (idempotent)\n";
    $pass++;
} else {
    echo "FAIL C28 " . implode('; ', $c28Errors) . "\n";
    $failures[] = 'C28';
}

// ── C29: backfill invariant — no NULL category among critical rows ──
// Migration backfill should have populated critical_category for every
// existing is_critical=1 row. Any post-migration insert via
// markCriticalAccounts() also sets it. NULL category among is_critical=1
// rows indicates a heuristic gap.
$c29Errors = [];
try {
    $nullCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_account_map m
           JOIN acc_accounts a ON a.id = m.ff_account_id
          WHERE m.is_critical = 1
            AND m.critical_category IS NULL
            AND a.is_active = 1",
        []
    );
    if ($nullCount > 0) {
        // Surface which codes were affected so the operator can
        // diagnose the heuristic gap quickly.
        $nullRows = db_select(
            "SELECT a.code FROM acc_qbo_account_map m
               JOIN acc_accounts a ON a.id = m.ff_account_id
              WHERE m.is_critical = 1
                AND m.critical_category IS NULL
                AND a.is_active = 1
              ORDER BY a.code"
        );
        $codes = implode(',', array_map(fn($r) => $r['code'], $nullRows));
        $c29Errors[] = "{$nullCount} is_critical=1 rows have NULL critical_category (codes: {$codes}) — heuristic gap or backfill skipped";
    }

    // Sanity-check the column whitelist: every NON-NULL category is in
    // AccountValidator::CATEGORIES.
    $cats = db_select(
        "SELECT DISTINCT critical_category FROM acc_qbo_account_map
          WHERE critical_category IS NOT NULL"
    );
    foreach ($cats as $row) {
        $cat = (string) $row['critical_category'];
        if (!in_array($cat, AccountValidator::CATEGORIES, true)) {
            $c29Errors[] = "critical_category '{$cat}' not in AccountValidator::CATEGORIES whitelist";
        }
    }
} catch (Throwable $e) {
    $c29Errors[] = 'C29 threw: ' . $e->getMessage();
}
if (empty($c29Errors)) {
    echo "PASS C29 No is_critical=1 rows with NULL critical_category; all categories in CATEGORIES whitelist\n";
    $pass++;
} else {
    echo "FAIL C29 " . implode('; ', $c29Errors) . "\n";
    $failures[] = 'C29';
}

// ─────────────────────────────────────────────────────────────────────
// S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE — C30-C42 below close Phase 3
// audit findings F-P3-01 (CRITICAL) + F-P3-02 through F-P3-07 (MEDIUM
// ×6). All gates are real production code; sub-checks exercise empty-
// category Trap #68 branch for the LIVE invoice gate + S1/S2/S3/S5
// coverage for the 4 dormant gates (Payment/Bill/BillPayment/JE) so
// when those Pusher sessions ship (S-QBO-13+) the regression net is
// already in place. F-P3-08/09/10 LOW deferred (revisit post Phase 4).
// ─────────────────────────────────────────────────────────────────────

// ── C30: InvoicePush × S4 — ar_clearing empty-category ─────
// Strip is_critical from all ar_clearing rows; the live invoice gate
// must throw with Trap #68 'no FF account tagged' phrasing per
// D-QBO-VALIDATOR-5 D4. ar_clearing IS naturally populated in v1 chart
// (FF 1030), so the strip simulates the S4 state for regression-net.
$c30Errors = [];
$c30Stripped = [];
try {
    AccountValidator::markCriticalAccounts();
    $c30Stripped = p3_smoke_strip_category('ar_clearing');
    if (empty($c30Stripped)) {
        $c30Errors[] = 'pre-condition: ar_clearing had no is_critical rows to strip';
    } else {
        try {
            AccountValidator::assertReadyForInvoicePush();
            $c30Errors[] = 'expected exception, none thrown';
        } catch (ChartOfAccountsIncompleteException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'ar_clearing')) {
                $c30Errors[] = "expected 'ar_clearing' in message, got: {$msg}";
            }
            if (!str_contains($msg, 'no FF account tagged')) {
                $c30Errors[] = "expected 'no FF account tagged' phrase, got: {$msg}";
            }
        } catch (\Throwable $e) {
            $c30Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c30Errors[] = 'C30 setup threw: ' . $e->getMessage();
} finally {
    p3_smoke_restore_stripped($c30Stripped);
}
if (empty($c30Errors)) {
    echo "PASS C30 assertReadyForInvoicePush throws 'no FF account tagged' when ar_clearing empty-cat (F-P3-01)\n";
    $pass++;
} else {
    echo "FAIL C30 " . implode('; ', $c30Errors) . "\n";
    $failures[] = 'C30';
}

// ── C31: InvoicePush × S4 — sales_revenue empty-category ───
$c31Errors = [];
$c31Stripped = [];
try {
    AccountValidator::markCriticalAccounts();
    $c31Stripped = p3_smoke_strip_category('sales_revenue');
    if (empty($c31Stripped)) {
        $c31Errors[] = 'pre-condition: sales_revenue had no is_critical rows to strip';
    } else {
        try {
            AccountValidator::assertReadyForInvoicePush();
            $c31Errors[] = 'expected exception, none thrown';
        } catch (ChartOfAccountsIncompleteException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'sales_revenue')) {
                $c31Errors[] = "expected 'sales_revenue' in message, got: {$msg}";
            }
            if (!str_contains($msg, 'no FF account tagged')) {
                $c31Errors[] = "expected 'no FF account tagged' phrase, got: {$msg}";
            }
        } catch (\Throwable $e) {
            $c31Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c31Errors[] = 'C31 setup threw: ' . $e->getMessage();
} finally {
    p3_smoke_restore_stripped($c31Stripped);
}
if (empty($c31Errors)) {
    echo "PASS C31 assertReadyForInvoicePush throws 'no FF account tagged' when sales_revenue empty-cat (F-P3-01)\n";
    $pass++;
} else {
    echo "FAIL C31 " . implode('; ', $c31Errors) . "\n";
    $failures[] = 'C31';
}

// ── C32: PaymentPush × S3 — multi-blocked default state ────
// AR has FF 1030 unmapped (mapping_status blocking); UF has zero
// FF rows (empty-cat blocking via Trap #68). Both must appear in
// the gate's message; UF gets the canonical "no FF account tagged"
// phrase, AR gets "{code} {name}" formatting per D2.
$c32Errors = [];
try {
    AccountValidator::markCriticalAccounts();
    try {
        AccountValidator::assertReadyForPaymentPush();
        $c32Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'ar_clearing')) {
            $c32Errors[] = "expected 'ar_clearing' in message, got: {$msg}";
        }
        if (!str_contains($msg, 'undeposited_funds')) {
            $c32Errors[] = "expected 'undeposited_funds' in message, got: {$msg}";
        }
        if (!str_contains($msg, 'no FF account tagged')) {
            $c32Errors[] = "expected 'no FF account tagged' phrase (UF empty-cat), got: {$msg}";
        }
        if (!str_contains($msg, '1030')) {
            $c32Errors[] = "expected FF code '1030' in message, got: {$msg}";
        }
        if (!str_contains($msg, '2 required categories')) {
            $c32Errors[] = "expected plural '2 required categories', got: {$msg}";
        }
    } catch (\Throwable $e) {
        $c32Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $c32Errors[] = 'C32 setup threw: ' . $e->getMessage();
}
if (empty($c32Errors)) {
    echo "PASS C32 assertReadyForPaymentPush S3 multi-blocked — names both ar_clearing + undeposited_funds; UF gets Trap #68 phrase (F-P3-03)\n";
    $pass++;
} else {
    echo "FAIL C32 " . implode('; ', $c32Errors) . "\n";
    $failures[] = 'C32';
}

// ── C33: PaymentPush × S2 — synthetic UF mapped, AR unmapped ──
// Verifies one-blocked-with-specific-category path: UF is satisfied
// (synthetic FF account exists + is mapped) so the message names ONLY
// ar_clearing (NOT undeposited_funds) + singular '1 required category'.
$c33Errors = [];
try {
    AccountValidator::markCriticalAccounts();
    p3_smoke_create_synthetic_uf('mapped');
    try {
        AccountValidator::assertReadyForPaymentPush();
        $c33Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'ar_clearing')) {
            $c33Errors[] = "expected 'ar_clearing' in message, got: {$msg}";
        }
        // UF should NOT appear — it's satisfied by the synthetic FF.
        if (str_contains($msg, 'undeposited_funds')) {
            $c33Errors[] = "UF is mapped — should NOT appear in message, got: {$msg}";
        }
        if (!str_contains($msg, '1 required category') || str_contains($msg, '1 required categories')) {
            $c33Errors[] = "expected singular '1 required category' (only ar_clearing blocking), got: {$msg}";
        }
        if (!str_contains($msg, '1030')) {
            $c33Errors[] = "expected FF code '1030' for ar_clearing, got: {$msg}";
        }
    } catch (\Throwable $e) {
        $c33Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $c33Errors[] = 'C33 setup threw: ' . $e->getMessage();
} finally {
    p3_smoke_delete_synthetic_uf();
}
if (empty($c33Errors)) {
    echo "PASS C33 assertReadyForPaymentPush S2 — synthetic UF mapped, AR unmapped → throws naming ONLY ar_clearing + singular (F-P3-04)\n";
    $pass++;
} else {
    echo "FAIL C33 " . implode('; ', $c33Errors) . "\n";
    $failures[] = 'C33';
}

// ── C34: PaymentPush × S1+S5 — both required mapped ────────
// Synthetic UF + live AR (1030) both mapped. AP/tax/sales_revenue
// remain unmapped (default state) — gate must NOT block on those
// (D-QBO-VALIDATOR-4 narrow-scope discipline). Combined S1 pass-state
// and S5 scope-narrow since the live chart's default has non-required
// categories unmapped.
$c34Errors = [];
$c34ArFfId = null;
try {
    AccountValidator::markCriticalAccounts();
    p3_smoke_create_synthetic_uf('mapped');
    $arFf = db_row("SELECT id FROM acc_accounts WHERE code='1030' AND is_active=1");
    if ($arFf === null) {
        $c34Errors[] = 'pre-condition: AR (1030) absent';
    } else {
        $c34ArFfId = (int) $arFf['id'];
        p3_smoke_map_critical($c34ArFfId);
        try {
            AccountValidator::assertReadyForPaymentPush();
            // No exception = PASS.
        } catch (\Throwable $e) {
            $c34Errors[] = 'expected no exception, got ' . get_class($e) . ': ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c34Errors[] = 'C34 setup threw: ' . $e->getMessage();
} finally {
    if ($c34ArFfId !== null) {
        p3_smoke_revert_critical($c34ArFfId);
    }
    p3_smoke_delete_synthetic_uf();
}
if (empty($c34Errors)) {
    echo "PASS C34 assertReadyForPaymentPush S1+S5 passes — synthetic UF + AR mapped; AP/tax/sales unmapped (narrow-scope, F-P3-04)\n";
    $pass++;
} else {
    echo "FAIL C34 " . implode('; ', $c34Errors) . "\n";
    $failures[] = 'C34';
}

// ── C35: BillPush × S2 — ap_clearing unmapped (default) ────
$c35Errors = [];
try {
    AccountValidator::markCriticalAccounts();
    try {
        AccountValidator::assertReadyForBillPush();
        $c35Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'ap_clearing')) {
            $c35Errors[] = "expected 'ap_clearing' in message, got: {$msg}";
        }
        if (!str_contains($msg, '2010')) {
            $c35Errors[] = "expected FF code '2010' (AP) in message, got: {$msg}";
        }
        if (!str_contains($msg, '1 required category') || str_contains($msg, '1 required categories')) {
            $c35Errors[] = "expected singular '1 required category', got: {$msg}";
        }
    } catch (\Throwable $e) {
        $c35Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $c35Errors[] = 'C35 setup threw: ' . $e->getMessage();
}
if (empty($c35Errors)) {
    echo "PASS C35 assertReadyForBillPush S2 — AP unmapped → throws naming ap_clearing + FF '2010' + singular (F-P3-05)\n";
    $pass++;
} else {
    echo "FAIL C35 " . implode('; ', $c35Errors) . "\n";
    $failures[] = 'C35';
}

// ── C36: BillPush × S1+S5 — ap_clearing mapped, others unmapped ──
// Single-required-category gate; AR/UF/tax/sales must not affect
// gate decision. S3 N/A by definition (only 1 required category
// → cannot multi-block).
$c36Errors = [];
$c36ApFfId = null;
try {
    AccountValidator::markCriticalAccounts();
    $apFf = db_row("SELECT id FROM acc_accounts WHERE code='2010' AND is_active=1");
    if ($apFf === null) {
        $c36Errors[] = 'pre-condition: AP (2010) absent';
    } else {
        $c36ApFfId = (int) $apFf['id'];
        p3_smoke_map_critical($c36ApFfId);
        try {
            AccountValidator::assertReadyForBillPush();
            // PASS — no exception.
        } catch (\Throwable $e) {
            $c36Errors[] = 'expected no exception (S5 narrow-scope), got ' . get_class($e) . ': ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c36Errors[] = 'C36 setup threw: ' . $e->getMessage();
} finally {
    if ($c36ApFfId !== null) {
        p3_smoke_revert_critical($c36ApFfId);
    }
}
if (empty($c36Errors)) {
    echo "PASS C36 assertReadyForBillPush S1+S5 passes — AP mapped; AR/UF/tax/sales unmapped don't block (S3 N/A, F-P3-05)\n";
    $pass++;
} else {
    echo "FAIL C36 " . implode('; ', $c36Errors) . "\n";
    $failures[] = 'C36';
}

// ── C37: BillPaymentPush × S3 — AP unmapped + UF empty-cat ──
$c37Errors = [];
try {
    AccountValidator::markCriticalAccounts();
    try {
        AccountValidator::assertReadyForBillPaymentPush();
        $c37Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'ap_clearing')) {
            $c37Errors[] = "expected 'ap_clearing' in message, got: {$msg}";
        }
        if (!str_contains($msg, 'undeposited_funds')) {
            $c37Errors[] = "expected 'undeposited_funds' in message, got: {$msg}";
        }
        if (!str_contains($msg, 'no FF account tagged')) {
            $c37Errors[] = "expected 'no FF account tagged' for UF empty-cat, got: {$msg}";
        }
        if (!str_contains($msg, '2010')) {
            $c37Errors[] = "expected FF '2010' (AP) in message, got: {$msg}";
        }
        if (!str_contains($msg, '2 required categories')) {
            $c37Errors[] = "expected plural '2 required categories', got: {$msg}";
        }
    } catch (\Throwable $e) {
        $c37Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $c37Errors[] = 'C37 setup threw: ' . $e->getMessage();
}
if (empty($c37Errors)) {
    echo "PASS C37 assertReadyForBillPaymentPush S3 multi-blocked — names both ap_clearing + undeposited_funds; UF Trap #68 phrase (F-P3-06)\n";
    $pass++;
} else {
    echo "FAIL C37 " . implode('; ', $c37Errors) . "\n";
    $failures[] = 'C37';
}

// ── C38: BillPaymentPush × S2 — synthetic UF mapped, AP unmapped ──
$c38Errors = [];
try {
    AccountValidator::markCriticalAccounts();
    p3_smoke_create_synthetic_uf('mapped');
    try {
        AccountValidator::assertReadyForBillPaymentPush();
        $c38Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'ap_clearing')) {
            $c38Errors[] = "expected 'ap_clearing' in message, got: {$msg}";
        }
        if (str_contains($msg, 'undeposited_funds')) {
            $c38Errors[] = "UF is mapped — should NOT appear in message, got: {$msg}";
        }
        if (!str_contains($msg, '1 required category') || str_contains($msg, '1 required categories')) {
            $c38Errors[] = "expected singular '1 required category', got: {$msg}";
        }
    } catch (\Throwable $e) {
        $c38Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $c38Errors[] = 'C38 setup threw: ' . $e->getMessage();
} finally {
    p3_smoke_delete_synthetic_uf();
}
if (empty($c38Errors)) {
    echo "PASS C38 assertReadyForBillPaymentPush S2 — synthetic UF mapped, AP unmapped → throws naming ONLY ap_clearing + singular (F-P3-06)\n";
    $pass++;
} else {
    echo "FAIL C38 " . implode('; ', $c38Errors) . "\n";
    $failures[] = 'C38';
}

// ── C39: BillPaymentPush × S1+S5 — both required mapped ────
$c39Errors = [];
$c39ApFfId = null;
try {
    AccountValidator::markCriticalAccounts();
    p3_smoke_create_synthetic_uf('mapped');
    $apFf = db_row("SELECT id FROM acc_accounts WHERE code='2010' AND is_active=1");
    if ($apFf === null) {
        $c39Errors[] = 'pre-condition: AP (2010) absent';
    } else {
        $c39ApFfId = (int) $apFf['id'];
        p3_smoke_map_critical($c39ApFfId);
        try {
            AccountValidator::assertReadyForBillPaymentPush();
            // PASS — no exception.
        } catch (\Throwable $e) {
            $c39Errors[] = 'expected no exception, got ' . get_class($e) . ': ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c39Errors[] = 'C39 setup threw: ' . $e->getMessage();
} finally {
    if ($c39ApFfId !== null) {
        p3_smoke_revert_critical($c39ApFfId);
    }
    p3_smoke_delete_synthetic_uf();
}
if (empty($c39Errors)) {
    echo "PASS C39 assertReadyForBillPaymentPush S1+S5 passes — synthetic UF + AP mapped; AR/tax/sales unmapped (F-P3-06)\n";
    $pass++;
} else {
    echo "FAIL C39 " . implode('; ', $c39Errors) . "\n";
    $failures[] = 'C39';
}

// ── C40: JournalEntryPush × S3 — both tax categories unmapped ──
// Default state: tax_receivable (1050+1060) + tax_payable (2030+2040)
// all is_critical=1, ff_only. Gate throws naming both categories.
$c40Errors = [];
try {
    AccountValidator::markCriticalAccounts();
    try {
        AccountValidator::assertReadyForJournalEntryPush();
        $c40Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'tax_receivable')) {
            $c40Errors[] = "expected 'tax_receivable' in message, got: {$msg}";
        }
        if (!str_contains($msg, 'tax_payable')) {
            $c40Errors[] = "expected 'tax_payable' in message, got: {$msg}";
        }
        // D2 — at least one FF code per category (1050 receivable, 2030 payable).
        if (!str_contains($msg, '1050')) {
            $c40Errors[] = "expected FF '1050' (GST/HST Receivable) in message, got: {$msg}";
        }
        if (!str_contains($msg, '2030')) {
            $c40Errors[] = "expected FF '2030' (GST/HST Payable) in message, got: {$msg}";
        }
        if (!str_contains($msg, '2 required categories')) {
            $c40Errors[] = "expected plural '2 required categories', got: {$msg}";
        }
    } catch (\Throwable $e) {
        $c40Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
    }
} catch (Throwable $e) {
    $c40Errors[] = 'C40 setup threw: ' . $e->getMessage();
}
if (empty($c40Errors)) {
    echo "PASS C40 assertReadyForJournalEntryPush S3 multi-blocked — names both tax_receivable + tax_payable + FF codes (F-P3-07)\n";
    $pass++;
} else {
    echo "FAIL C40 " . implode('; ', $c40Errors) . "\n";
    $failures[] = 'C40';
}

// ── C41: JournalEntryPush × S2 — only tax_receivable mapped ──
// Maps 1050 (GST/HST Receivable). tax_receivable category now has
// 1 mapped FF → not blocking. tax_payable still 0 mapped → blocking.
// Message names ONLY tax_payable, singular inflection.
$c41Errors = [];
$c41TaxRecFfId = null;
try {
    AccountValidator::markCriticalAccounts();
    $taxRecFf = db_row("SELECT id FROM acc_accounts WHERE code='1050' AND is_active=1");
    if ($taxRecFf === null) {
        $c41Errors[] = 'pre-condition: tax_receivable FF (1050) absent';
    } else {
        $c41TaxRecFfId = (int) $taxRecFf['id'];
        p3_smoke_map_critical($c41TaxRecFfId);
        try {
            AccountValidator::assertReadyForJournalEntryPush();
            $c41Errors[] = 'expected exception, none thrown';
        } catch (ChartOfAccountsIncompleteException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'tax_payable')) {
                $c41Errors[] = "expected 'tax_payable' in message, got: {$msg}";
            }
            // tax_receivable is now satisfied; should NOT appear with colon prefix.
            // (Substring 'tax_receivable' could appear in a different context, so
            // we assert the colon-prefixed form is absent — that's what the
            // message-build loop emits for blocking categories.)
            if (str_contains($msg, 'tax_receivable:') || str_contains($msg, 'tax_receivable (no FF')) {
                $c41Errors[] = "tax_receivable is mapped — should NOT appear as blocking, got: {$msg}";
            }
            if (!str_contains($msg, '1 required category') || str_contains($msg, '1 required categories')) {
                $c41Errors[] = "expected singular '1 required category', got: {$msg}";
            }
        } catch (\Throwable $e) {
            $c41Errors[] = 'unexpected exception: ' . get_class($e) . ' — ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c41Errors[] = 'C41 setup threw: ' . $e->getMessage();
} finally {
    if ($c41TaxRecFfId !== null) {
        p3_smoke_revert_critical($c41TaxRecFfId);
    }
}
if (empty($c41Errors)) {
    echo "PASS C41 assertReadyForJournalEntryPush S2 — tax_receivable mapped, tax_payable unmapped → throws naming ONLY tax_payable + singular (F-P3-07)\n";
    $pass++;
} else {
    echo "FAIL C41 " . implode('; ', $c41Errors) . "\n";
    $failures[] = 'C41';
}

// ── C42: JournalEntryPush × S1+S5 — both tax categories mapped ──
// Maps 1050 + 2030 (one FF per tax category). AR/AP/UF/sales remain
// unmapped (default) — gate must not block on those (narrow-scope).
$c42Errors = [];
$c42TaxRecFfId = null;
$c42TaxPayFfId = null;
try {
    AccountValidator::markCriticalAccounts();
    $taxRecFf = db_row("SELECT id FROM acc_accounts WHERE code='1050' AND is_active=1");
    $taxPayFf = db_row("SELECT id FROM acc_accounts WHERE code='2030' AND is_active=1");
    if ($taxRecFf === null || $taxPayFf === null) {
        $c42Errors[] = 'pre-condition: tax FF accounts (1050 or 2030) absent';
    } else {
        $c42TaxRecFfId = (int) $taxRecFf['id'];
        $c42TaxPayFfId = (int) $taxPayFf['id'];
        p3_smoke_map_critical($c42TaxRecFfId);
        p3_smoke_map_critical($c42TaxPayFfId);
        try {
            AccountValidator::assertReadyForJournalEntryPush();
            // PASS — no exception.
        } catch (\Throwable $e) {
            $c42Errors[] = 'expected no exception, got ' . get_class($e) . ': ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c42Errors[] = 'C42 setup threw: ' . $e->getMessage();
} finally {
    if ($c42TaxRecFfId !== null) {
        p3_smoke_revert_critical($c42TaxRecFfId);
    }
    if ($c42TaxPayFfId !== null) {
        p3_smoke_revert_critical($c42TaxPayFfId);
    }
}
if (empty($c42Errors)) {
    echo "PASS C42 assertReadyForJournalEntryPush S1+S5 passes — both tax categories mapped; AR/AP/UF/sales unmapped (narrow-scope, F-P3-07)\n";
    $pass++;
} else {
    echo "FAIL C42 " . implode('; ', $c42Errors) . "\n";
    $failures[] = 'C42';
}

// ── C43: S-QBO-MATCHER-WEDGE-RECOVERY — rescue wedge with matching qbo_only ──
// Fixture: synthetic FF account 999993 (acc_accounts row); wedged
// acc_qbo_account_map row with mapping_status='ff_only', qbo_account_id=NULL,
// qbo_fully_qualified_name='Wedge Test Account'; separate qbo_only row
// with the same qbo_fully_qualified_name AND qbo_account_id='TEST-SMOKE-A-WEDGE43'.
// Call AccountMatcher::matchAll([]) — empty $qboAccounts is fine because
// rescue reads existing DB rows directly. Assert wedge row now has the
// qbo_account_id absorbed, mapping_status='mapped', match_notes contains
// 'wedge_recovery'. Assert the qbo_only candidate row was DELETED.
$c43Errors = [];
try {
    // Clean any prior wedge fixture
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999993 OR qbo_account_id LIKE 'TEST-SMOKE-A-WEDGE43%'");
    db_execute("DELETE FROM acc_accounts WHERE id = 999993");

    db_insert('acc_accounts', [
        'id' => 999993, 'code' => 'SMK43',
        'name' => 'SMOKE C43 Wedge Account',
        'account_type' => 'asset', 'is_active' => 1, 'is_system' => 0,
    ]);

    // Wedged row: ff_only, qbo_account_id=NULL, breadcrumbs present
    db_insert('acc_qbo_account_map', [
        'ff_account_id'            => 999993,
        'qbo_account_id'           => null,
        'mapping_status'           => 'ff_only',
        'qbo_name'                 => 'Wedge Test Account',
        'qbo_fully_qualified_name' => 'Wedge Test Account',
        'qbo_account_type'         => 'Asset',
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);

    // qbo_only candidate with matching qbo_fully_qualified_name
    db_insert('acc_qbo_account_map', [
        'ff_account_id'            => null,
        'qbo_account_id'           => 'TEST-SMOKE-A-WEDGE43',
        'qbo_sync_token'           => '0',
        'mapping_status'           => 'qbo_only',
        'qbo_name'                 => 'Wedge Test Account',
        'qbo_fully_qualified_name' => 'Wedge Test Account',
        'qbo_account_type'         => 'Asset',
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);

    AccountMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_account_id, mapping_status, match_confidence, match_notes FROM acc_qbo_account_map WHERE ff_account_id = ?", [999993]);
    if ($wedged === null) {
        $c43Errors[] = 'wedge row gone after matchAll';
    } else {
        if ($wedged['qbo_account_id'] !== 'TEST-SMOKE-A-WEDGE43') {
            $c43Errors[] = "expected qbo_account_id='TEST-SMOKE-A-WEDGE43', got '" . ($wedged['qbo_account_id'] ?? 'NULL') . "'";
        }
        if ($wedged['mapping_status'] !== 'mapped') {
            $c43Errors[] = "expected mapping_status='mapped', got '" . ($wedged['mapping_status'] ?? 'NULL') . "'";
        }
        if ($wedged['match_confidence'] !== 'high') {
            $c43Errors[] = "expected match_confidence='high', got '" . ($wedged['match_confidence'] ?? 'NULL') . "'";
        }
        if (!str_contains((string) $wedged['match_notes'], 'wedge_recovery')) {
            $c43Errors[] = "expected match_notes to contain 'wedge_recovery', got '" . ($wedged['match_notes'] ?? 'NULL') . "'";
        }
    }

    // Candidate qbo_only row should be deleted (data consolidated into wedge row)
    $candidate = db_row("SELECT id FROM acc_qbo_account_map WHERE qbo_account_id = ? AND mapping_status = 'qbo_only'", ['TEST-SMOKE-A-WEDGE43']);
    if ($candidate !== null) {
        $c43Errors[] = "expected qbo_only candidate row to be deleted; still exists at id={$candidate['id']}";
    }
} catch (Throwable $e) {
    $c43Errors[] = 'C43 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999993 OR qbo_account_id LIKE 'TEST-SMOKE-A-WEDGE43%'");
    db_execute("DELETE FROM acc_accounts WHERE id = 999993");
}
if (empty($c43Errors)) {
    echo "PASS C43 AccountMatcher rescueHalfStateRows absorbs wedged ff_only into matching qbo_only (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C43 " . implode('; ', $c43Errors) . "\n";
    $failures[] = 'C43';
}

// ── C44: S-QBO-MATCHER-WEDGE-RECOVERY — wedge with no matching qbo_only → no-op ──
$c44Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999994");
    db_execute("DELETE FROM acc_accounts WHERE id = 999994");

    db_insert('acc_accounts', [
        'id' => 999994, 'code' => 'SMK44',
        'name' => 'SMOKE C44 Lone Wedge Account',
        'account_type' => 'asset', 'is_active' => 1, 'is_system' => 0,
    ]);
    db_insert('acc_qbo_account_map', [
        'ff_account_id'            => 999994,
        'qbo_account_id'           => null,
        'mapping_status'           => 'ff_only',
        'qbo_fully_qualified_name' => 'Unmatched Lone Wedge ' . substr((string) time(), -6),
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);

    AccountMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_account_id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id = ?", [999994]);
    if ($wedged === null) {
        $c44Errors[] = 'wedge row gone after matchAll (false positive)';
    } else {
        if ($wedged['qbo_account_id'] !== null) {
            $c44Errors[] = "no-op expected — qbo_account_id should remain NULL, got '" . $wedged['qbo_account_id'] . "'";
        }
        if ($wedged['mapping_status'] !== 'ff_only') {
            $c44Errors[] = "no-op expected — mapping_status should remain 'ff_only', got '" . ($wedged['mapping_status'] ?? 'NULL') . "'";
        }
    }
} catch (Throwable $e) {
    $c44Errors[] = 'C44 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999994");
    db_execute("DELETE FROM acc_accounts WHERE id = 999994");
}
if (empty($c44Errors)) {
    echo "PASS C44 AccountMatcher rescueHalfStateRows no-ops when no matching qbo_only (no false positives) (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C44 " . implode('; ', $c44Errors) . "\n";
    $failures[] = 'C44';
}

// ── C45: S-QBO-MATCHER-WEDGE-RECOVERY — claimed-set discipline (first wedge wins) ──
// Two wedges pointing at the same qbo_fully_qualified_name + one qbo_only
// candidate. First wedge (lower id) absorbs the candidate; second wedge
// remains wedged.
$c45Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id IN (999995, 999996) OR qbo_account_id LIKE 'TEST-SMOKE-A-WEDGE45%'");
    db_execute("DELETE FROM acc_accounts WHERE id IN (999995, 999996)");

    db_insert('acc_accounts', [
        'id' => 999995, 'code' => 'SMK45A',
        'name' => 'SMOKE C45 Wedge A',
        'account_type' => 'asset', 'is_active' => 1, 'is_system' => 0,
    ]);
    db_insert('acc_accounts', [
        'id' => 999996, 'code' => 'SMK45B',
        'name' => 'SMOKE C45 Wedge B',
        'account_type' => 'asset', 'is_active' => 1, 'is_system' => 0,
    ]);

    db_insert('acc_qbo_account_map', [
        'ff_account_id'            => 999995,
        'qbo_account_id'           => null,
        'mapping_status'           => 'ff_only',
        'qbo_fully_qualified_name' => 'Claimed-Set Test Account',
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);
    db_insert('acc_qbo_account_map', [
        'ff_account_id'            => 999996,
        'qbo_account_id'           => null,
        'mapping_status'           => 'ff_only',
        'qbo_fully_qualified_name' => 'Claimed-Set Test Account',
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);
    db_insert('acc_qbo_account_map', [
        'ff_account_id'            => null,
        'qbo_account_id'           => 'TEST-SMOKE-A-WEDGE45',
        'qbo_sync_token'           => '0',
        'mapping_status'           => 'qbo_only',
        'qbo_fully_qualified_name' => 'Claimed-Set Test Account',
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);

    AccountMatcher::matchAll([]);

    $first  = db_row("SELECT qbo_account_id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id = ?", [999995]);
    $second = db_row("SELECT qbo_account_id, mapping_status FROM acc_qbo_account_map WHERE ff_account_id = ?", [999996]);

    if ($first === null || ($first['qbo_account_id'] ?? null) !== 'TEST-SMOKE-A-WEDGE45') {
        $c45Errors[] = "expected first wedge (999995) to absorb candidate, got " . json_encode($first);
    }
    if ($second === null) {
        $c45Errors[] = "second wedge (999996) row missing";
    } elseif ($second['qbo_account_id'] !== null) {
        $c45Errors[] = "expected second wedge (999996) to remain wedged (qbo_account_id NULL), got '" . $second['qbo_account_id'] . "'";
    }
} catch (Throwable $e) {
    $c45Errors[] = 'C45 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id IN (999995, 999996) OR qbo_account_id LIKE 'TEST-SMOKE-A-WEDGE45%'");
    db_execute("DELETE FROM acc_accounts WHERE id IN (999995, 999996)");
}
if (empty($c45Errors)) {
    echo "PASS C45 AccountMatcher rescueHalfStateRows claimed-set discipline (first wedge wins) (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C45 " . implode('; ', $c45Errors) . "\n";
    $failures[] = 'C45';
}

} finally {
    // ── Self-cleaning ──────────────────────────────────────────
    // Defensive sentinel cleanup — catches any TEST-SMOKE-A-* rows
    // that slipped into acc_qbo_account_map during C11/C14/C20.
    try {
        db_execute(
            "DELETE FROM acc_qbo_account_map WHERE qbo_account_id LIKE 'TEST-SMOKE-A-%'"
        );
    } catch (Throwable $e) {
        echo "WARN  defensive qbo-only cleanup failed: " . $e->getMessage() . "\n";
    }
    // C20 synthetic FF accounts (id 999990, 999991). Also their
    // acc_qbo_account_map rows (ff_only rows created by matchAll for
    // the loser of the claimed-set contest). 999992 = C33/C34/C38/C39
    // synthetic UF (S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE); per-sub-check
    // teardown deletes it but defensive cleanup catches any leak.
    try {
        db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id IN (999990, 999991, 999992)");
        db_execute("DELETE FROM acc_accounts WHERE id IN (999990, 999991, 999992)");
    } catch (Throwable $e) {
        echo "WARN  C20/P3 synthetic FF cleanup failed: " . $e->getMessage() . "\n";
    }
    // Captured mapping ids (C11 baseline).
    if (!empty($sentinelMappingIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelMappingIds), '?'));
            db_execute("DELETE FROM acc_qbo_account_map WHERE id IN ({$ph})", $sentinelMappingIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel mapping rows failed: " . $e->getMessage() . "\n";
        }
    }
    // Restore mapping rows for live critical FF accounts to a clean
    // ff_only + is_critical=1 state so subsequent operator workflow
    // remains predictable (markCriticalAccounts is idempotent and
    // re-creates these on the next pull anyway).
    try {
        AccountValidator::markCriticalAccounts();
    } catch (Throwable $e) {
        echo "WARN  post-test markCriticalAccounts failed: " . $e->getMessage() . "\n";
    }
}

echo "\nqbo_account_mapping_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\n";
exit(0);

// ─────────────────────────────────────────────────────────────────────
// S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE helpers (C30–C42). Declared at
// the bottom of the file; PHP hoists top-level function declarations
// at parse time so they're callable from the try block above. Kept
// out of the main flow so the sub-check sequence reads top-down
// without helper-bodies cluttering the test narrative.
// ─────────────────────────────────────────────────────────────────────

/**
 * Insert a synthetic Undeposited-Funds FF account + its mapping row.
 * v1 chart has no UF FF account by design (per D-QBO-VALIDATOR-3
 * operator AskUserQuestion resolution); these helpers create one on
 * the fly for Payment/BillPayment gate pass-state coverage.
 *
 * @param string $state  'ff_only' or 'mapped'
 * @return int  Synthetic FF account id (always 999992)
 */
function p3_smoke_create_synthetic_uf(string $state = 'ff_only'): int
{
    db_insert('acc_accounts', [
        'id'              => 999992,
        'code'            => 'SMOKE-P3-UF',
        'name'            => 'Zzqq SMOKE Undeposited Funds',
        'account_type'    => 'asset',
        'account_subtype' => 'current_asset',
        'is_active'       => 1,
    ]);
    $cols = [
        'ff_account_id'     => 999992,
        'mapping_status'    => $state,
        'is_critical'       => 1,
        'critical_reason'   => 'SMOKE P3 synthetic Undeposited Funds',
        'critical_category' => 'undeposited_funds',
    ];
    if ($state === 'mapped') {
        $cols['qbo_account_id']   = 'TEST-SMOKE-A-P3-UF-' . bin2hex(random_bytes(4));
        $cols['qbo_sync_token']   = '0';
        $cols['qbo_name']         = 'SMOKE P3 UF mirror';
        $cols['match_confidence'] = 'manual';
    }
    db_insert('acc_qbo_account_map', $cols);
    return 999992;
}

/**
 * Tear down the synthetic UF FF account + its mapping row. Safe to
 * call when no synthetic UF exists (DELETE is no-op).
 */
function p3_smoke_delete_synthetic_uf(): void
{
    db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id = 999992");
    db_execute("DELETE FROM acc_accounts WHERE id = 999992");
}

/**
 * Temporarily UPDATE a live critical FF mapping row to mapped state
 * with a sentinel qbo_account_id. Sentinel prefix is caught by the
 * defensive 'TEST-SMOKE-A-%' cleanup at the smoke's global finally.
 *
 * @param int $ffId  FF account id whose acc_qbo_account_map row is
 *                   already is_critical=1, mapping_status='ff_only'.
 * @return string  Sentinel qbo_account_id assigned.
 */
function p3_smoke_map_critical(int $ffId): string
{
    $sentinel = 'TEST-SMOKE-A-P3-' . bin2hex(random_bytes(4));
    db_execute(
        "UPDATE acc_qbo_account_map SET
            qbo_account_id   = ?, qbo_sync_token = '0',
            qbo_name         = 'SMOKE P3 mirror',
            mapping_status   = 'mapped',
            match_confidence = 'manual'
          WHERE ff_account_id = ? AND is_critical = 1",
        [$sentinel, $ffId]
    );
    return $sentinel;
}

/**
 * Revert a critical FF mapping row back to ff_only state. Matches
 * the per-sub-check cleanup pattern in C14/C24/C25/C26/C27.
 */
function p3_smoke_revert_critical(int $ffId): void
{
    db_execute(
        "UPDATE acc_qbo_account_map SET
            qbo_account_id   = NULL, qbo_sync_token = NULL, qbo_name = NULL,
            mapping_status   = 'ff_only', match_confidence = NULL
          WHERE ff_account_id = ?",
        [$ffId]
    );
}

/**
 * Strip is_critical=0 from all mapping rows in a category. Used to
 * simulate the S4 empty-category state for categories whose default
 * chart state has FF accounts (ar_clearing, sales_revenue, etc.).
 * Returns the captured row state so p3_smoke_restore_stripped can
 * put it back exactly.
 *
 * @param string $category
 * @return list<array{id:int,is_critical:int,critical_reason:?string,critical_category:?string}>
 */
function p3_smoke_strip_category(string $category): array
{
    $captured = db_select(
        "SELECT id, is_critical, critical_reason, critical_category
           FROM acc_qbo_account_map
          WHERE critical_category = ? AND is_critical = 1",
        [$category]
    );
    db_execute(
        "UPDATE acc_qbo_account_map SET is_critical = 0
          WHERE critical_category = ? AND is_critical = 1",
        [$category]
    );
    return $captured;
}

/**
 * Restore the rows captured by p3_smoke_strip_category().
 */
function p3_smoke_restore_stripped(array $captured): void
{
    foreach ($captured as $row) {
        db_execute(
            "UPDATE acc_qbo_account_map SET
                is_critical = ?, critical_reason = ?, critical_category = ?
              WHERE id = ?",
            [
                (int) $row['is_critical'],
                $row['critical_reason'],
                $row['critical_category'],
                (int) $row['id'],
            ]
        );
    }
}
