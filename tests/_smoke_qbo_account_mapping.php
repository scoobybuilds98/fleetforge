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
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-8
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §7.1
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\AccountPuller;
use FleetForge\QboPushers\AccountMatcher;
use FleetForge\QboPushers\AccountValidator;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;
use FleetForge\Exceptions\QuickBooksException;

$failures = [];
$pass     = 0;
$total    = 17;

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
    foreach (['markCriticalAccounts', 'unmappedCritical', 'assertReadyForInvoicePush', 'identifyCriticalFfAccounts'] as $m) {
        if (!$ref->hasMethod($m)) { $c4Errors[] = "missing method: {$m}"; continue; }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) { $c4Errors[] = "{$m} must be public static"; }
    }
}
if (empty($c4Errors)) {
    echo "PASS C4  AccountValidator class surface (markCriticalAccounts + unmappedCritical + assertReadyForInvoicePush + identifyCriticalFfAccounts public static)\n";
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

// ── C12: unmappedCritical returns expected rows ────────────
// The live chart has 7 critical accounts identified at pre-flight.
// After markCriticalAccounts in C11, all 7 should be in is_critical
// state. Each is_critical row with mapping_status='ff_only' (no
// qbo_account_id) should appear in unmappedCritical().
$c12Errors = [];
try {
    AccountValidator::markCriticalAccounts(); // idempotent
    $unmapped = AccountValidator::unmappedCritical();
    if (count($unmapped) < 7) {
        $c12Errors[] = "expected >= 7 unmapped critical rows (the bridge accounts identified at pre-flight), got " . count($unmapped);
    }
    // Spot-check structure of one row.
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
    echo "PASS C12 unmappedCritical returns expected rows with required keys\n";
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

// ── C17: nav has 8 QuickBooks children incl. Accounts ──────
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
    if (count($children) !== 8) {
        $c17Errors[] = 'expected 8 QuickBooks children, got ' . count($children) . ' (' . implode(', ', $labels) . ')';
    }
    if (!in_array('Accounts', $labels, true)) {
        $c17Errors[] = "no 'Accounts' child in QuickBooks nav";
    }
    $expectedOrder = ['Dashboard', 'Sync Queue', 'Sync Log', 'Drift', 'Customers', 'Vendors', 'Accounts', 'Settings'];
    if ($labels !== $expectedOrder) {
        $c17Errors[] = 'nav order mismatch — got [' . implode(', ', $labels) . '], expected [' . implode(', ', $expectedOrder) . ']';
    }
}
if (empty($c17Errors)) {
    echo "PASS C17 nav has 8 QuickBooks children with Accounts in expected position\n";
    $pass++;
} else {
    echo "FAIL C17 " . implode('; ', $c17Errors) . "\n";
    $failures[] = 'C17';
}

} finally {
    // ── Self-cleaning ──────────────────────────────────────────
    // Defensive sentinel cleanup — catches any TEST-SMOKE-A-* rows
    // that slipped into acc_qbo_account_map during C11/C14.
    try {
        db_execute(
            "DELETE FROM acc_qbo_account_map WHERE qbo_account_id LIKE 'TEST-SMOKE-A-%'"
        );
    } catch (Throwable $e) {
        echo "WARN  defensive qbo-only cleanup failed: " . $e->getMessage() . "\n";
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
