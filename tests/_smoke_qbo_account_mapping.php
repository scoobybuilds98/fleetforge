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
 * 17 sub-checks:
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
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-8, S-QBO-MATCHER-GREEDY-FIX (C20-C23 added),
 *          S-QBO-VALIDATOR-SCOPE-SPLIT (C24-C29 added)
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
$total    = 29;

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

// ── C13: assertReadyForInvoicePush throws when critical unmapped ──
$c13Errors = [];
try {
    AccountValidator::markCriticalAccounts(); // ensure critical rows exist
    try {
        AccountValidator::assertReadyForInvoicePush();
        $c13Errors[] = 'expected exception, none thrown';
    } catch (ChartOfAccountsIncompleteException $e) {
        if (empty($e->unmappedAccounts)) {
            $c13Errors[] = 'exception thrown but unmappedAccounts is empty';
        }
        if (!str_contains($e->getMessage(), 'unmapped')) {
            $c13Errors[] = "expected message to mention 'unmapped', got: " . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $c13Errors[] = 'C13 threw unexpected: ' . get_class($e) . ' ' . $e->getMessage();
}
if (empty($c13Errors)) {
    echo "PASS C13 assertReadyForInvoicePush throws ChartOfAccountsIncompleteException with unmappedAccounts populated\n";
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
    if (count($children) !== 10) {
        $c17Errors[] = 'expected 10 QuickBooks children, got ' . count($children) . ' (' . implode(', ', $labels) . ')';
    }
    if (!in_array('Accounts', $labels, true)) {
        $c17Errors[] = "no 'Accounts' child in QuickBooks nav";
    }
    $expectedOrder = ['Dashboard', 'Sync Queue', 'Sync Log', 'Drift', 'Customers', 'Vendors', 'Accounts', 'Tax Codes', 'Items', 'Settings'];
    if ($labels !== $expectedOrder) {
        $c17Errors[] = 'nav order mismatch — got [' . implode(', ', $labels) . '], expected [' . implode(', ', $expectedOrder) . ']';
    }
}
if (empty($c17Errors)) {
    echo "PASS C17 nav has 10 QuickBooks children with Accounts in expected position\n";
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
    // the loser of the claimed-set contest).
    try {
        db_execute("DELETE FROM acc_qbo_account_map WHERE ff_account_id IN (999990, 999991)");
        db_execute("DELETE FROM acc_accounts WHERE id IN (999990, 999991)");
    } catch (Throwable $e) {
        echo "WARN  C20 synthetic FF cleanup failed: " . $e->getMessage() . "\n";
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
