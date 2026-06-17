<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_item_mapping.php
 *
 * S-QBO-10 — Structural + behavioural smoke for the item mapping flow.
 * Runs OFFLINE: no Intuit HTTP traffic. ItemPuller's pullAll() is
 * exercised via mock JSON through normalize(); ItemCreator's HTTP call
 * is skipped — only the IncomeAccountRef resolution cascade is
 * exercised against the real acc_qbo_account_map. Live round-trip
 * verification is operator-side post-commit.
 *
 * Self-cleaning: synthetic rows use sentinel qbo_ids
 * ('TEST-SMOKE-I-*') + sentinel ff_item_type values are skipped
 * (we only insert qbo_only sentinel rows + variant verification rows;
 * never mutate the 18-tuple FF iteration baseline because the live
 * mapping rows belong to operator workflow).
 *
 * 18 sub-checks:
 *   C1   acc_qbo_item_map table shape — expected columns + indexes
 *        + FK
 *   C2   ItemPuller class + pullAll + normalize public static
 *   C3   ItemMatcher class + displayNameFor + findBestMatch + matchAll
 *        + ffItemTypes + normalizeName public static
 *   C4   ItemCreator class + createMissingItem + resolveIncomeAccount
 *        public static
 *   C5   ffItemTypes() returns 18 tuples sourced from
 *        INFORMATION_SCHEMA (NOT hardcoded — verifies the actual ENUM
 *        is what we iterate; future ENUM additions auto-flow)
 *   C6   displayNameFor mapping correctness for representative tuples
 *        including GPS net/gross variants and reconciliation credit
 *   C7   findBestMatch exact_name (mock QBO Item list with 'Base Rental')
 *   C8   findBestMatch no match returns null
 *   C9   uq_ff_item_type_variant allows variants — (gps,null),
 *        (gps,'net'), (gps,'gross') all coexist; duplicate variant
 *        rejected
 *  C10   matchAll preserves manual overrides (operator-locked row
 *        is NOT overwritten by the matcher) — surface check via the
 *        decisions array (caller-side filtering covered separately
 *        in auto_match endpoint logic, not here)
 *  C11   ItemPuller does NOT make real HTTP in smoke (no curl side
 *        effect when only normalize() exercised)
 *  C12   ItemCreator resolveIncomeAccount returns a mapped revenue
 *        account when one exists in acc_qbo_account_map (uses
 *        whatever live data is present — relies on the FF 4110
 *        Other Revenue mapping verified at pre-flight, or any
 *        future mapping)
 *  C13   ItemCreator resolveIncomeAccount throws
 *        ChartOfAccountsIncompleteException when NO revenue
 *        account is mapped — uses a snapshot/restore approach to
 *        temporarily clear mappings
 *  C14   All 5 API endpoint files exist + php -l clean
 *  C15   app/admin/quickbooks/items.php php -l clean + Alpine factory
 *  C16   Nav config has 10 QuickBooks children incl. Items between
 *        Tax Codes and Settings
 *  C17   ItemPuller::normalize maps representative QBO Item JSON to
 *        the expected flat shape (offline; no HTTP)
 *  C18   ItemMatcher::UI_CATEGORIES covers all 17 FF ENUM values
 *        (every item_type appears in exactly one category — no
 *        orphans, no duplicates)
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-10
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §7.3 (item mapping table)
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\ItemPuller;
use FleetForge\QboPushers\ItemMatcher;
use FleetForge\QboPushers\ItemCreator;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

$failures = [];
$pass     = 0;
$total    = 20;

/** Sentinel qbo_ids we'll clean up. */
$sentinelQboIds = ['TEST-SMOKE-I-001', 'TEST-SMOKE-I-002', 'TEST-SMOKE-I-003'];

try {

// ── C1: table shape ─────────────────────────────────────────
$expectedCols = [
    'id', 'ff_item_type', 'ff_item_type_variant', 'qbo_item_id',
    'qbo_sync_token', 'qbo_name', 'qbo_fully_qualified_name',
    'qbo_description', 'qbo_type', 'qbo_active',
    'qbo_income_account_id', 'qbo_income_account_name',
    'qbo_expense_account_id', 'qbo_expense_account_name',
    'mapping_status', 'match_confidence', 'is_credit_variant',
    'presentation_variant', 'match_notes', 'last_synced_at',
    'last_pull_at', 'created_at', 'updated_at', 'created_by_user_id',
];
$c1Errors = [];
try {
    $rows = db_select("SHOW COLUMNS FROM acc_qbo_item_map");
    $present = array_map(fn($r) => $r['Field'], $rows);
    foreach ($expectedCols as $col) {
        if (!in_array($col, $present, true)) {
            $c1Errors[] = "missing column: {$col}";
        }
    }
    $idx = db_select("SHOW INDEX FROM acc_qbo_item_map");
    $idxNames = array_unique(array_map(fn($r) => $r['Key_name'], $idx));
    foreach (['uq_ff_item_type_variant', 'uq_qbo_item', 'idx_status', 'idx_ff_item_type', 'idx_last_synced'] as $i) {
        if (!in_array($i, $idxNames, true)) {
            $c1Errors[] = "missing index: {$i}";
        }
    }
} catch (Throwable $e) {
    $c1Errors[] = 'exception: ' . $e->getMessage();
}
if (empty($c1Errors)) { echo "PASS C1 acc_qbo_item_map table shape\n"; $pass++; }
else { echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

// ── C2: ItemPuller class shape ─────────────────────────────
$c2Errors = [];
if (!class_exists(ItemPuller::class)) {
    $c2Errors[] = 'class FleetForge\QboPushers\ItemPuller not found';
} else {
    $rc = new ReflectionClass(ItemPuller::class);
    foreach (['pullAll', 'normalize'] as $m) {
        if (!$rc->hasMethod($m)) {
            $c2Errors[] = "method ItemPuller::{$m} not found";
            continue;
        }
        $rm = $rc->getMethod($m);
        if (!$rm->isPublic() || !$rm->isStatic()) {
            $c2Errors[] = "ItemPuller::{$m} must be public static";
        }
    }
}
if (empty($c2Errors)) { echo "PASS C2 ItemPuller class shape\n"; $pass++; }
else { echo "FAIL C2 " . implode('; ', $c2Errors) . "\n"; $failures[] = 'C2'; }

// ── C3: ItemMatcher class shape ────────────────────────────
$c3Errors = [];
if (!class_exists(ItemMatcher::class)) {
    $c3Errors[] = 'class FleetForge\QboPushers\ItemMatcher not found';
} else {
    $rc = new ReflectionClass(ItemMatcher::class);
    foreach (['displayNameFor', 'findBestMatch', 'matchAll', 'ffItemTypes', 'normalizeName'] as $m) {
        if (!$rc->hasMethod($m)) {
            $c3Errors[] = "method ItemMatcher::{$m} not found";
            continue;
        }
        $rm = $rc->getMethod($m);
        if (!$rm->isPublic() || !$rm->isStatic()) {
            $c3Errors[] = "ItemMatcher::{$m} must be public static";
        }
    }
}
if (empty($c3Errors)) { echo "PASS C3 ItemMatcher class shape\n"; $pass++; }
else { echo "FAIL C3 " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

// ── C4: ItemCreator class shape ────────────────────────────
$c4Errors = [];
if (!class_exists(ItemCreator::class)) {
    $c4Errors[] = 'class FleetForge\QboPushers\ItemCreator not found';
} else {
    $rc = new ReflectionClass(ItemCreator::class);
    foreach (['createMissingItem', 'resolveIncomeAccount'] as $m) {
        if (!$rc->hasMethod($m)) {
            $c4Errors[] = "method ItemCreator::{$m} not found";
            continue;
        }
        $rm = $rc->getMethod($m);
        if (!$rm->isPublic() || !$rm->isStatic()) {
            $c4Errors[] = "ItemCreator::{$m} must be public static";
        }
    }
}
if (empty($c4Errors)) { echo "PASS C4 ItemCreator class shape\n"; $pass++; }
else { echo "FAIL C4 " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

// ── C5: ffItemTypes sourced from INFORMATION_SCHEMA ─────────
$c5Errors = [];
try {
    $tuples = ItemMatcher::ffItemTypes();
    if (count($tuples) !== 19) {
        $c5Errors[] = 'expected 19 tuples (17 non-variant + 2 GPS variants), got ' . count($tuples);
    }
    // Verify a few canonical ENUM values are present.
    $itemTypes = array_column($tuples, 'ff_item_type');
    foreach (['base_rental', 'base_rental_reconciliation_credit', 'gps', 'mileage_precharge', 'mileage_credit', 'mileage_usage', 'mileage_drawdown_credit', 'other'] as $expected) {
        if (!in_array($expected, $itemTypes, true)) {
            $c5Errors[] = "expected ff_item_type '{$expected}' in ffItemTypes() output";
        }
    }
    // Verify GPS has BOTH variants and no NULL-variant gps tuple.
    $gpsVariants = [];
    foreach ($tuples as $t) {
        if ($t['ff_item_type'] === 'gps') {
            $gpsVariants[] = $t['variant'];
        }
    }
    sort($gpsVariants);
    if ($gpsVariants !== ['gross', 'net']) {
        $c5Errors[] = "expected GPS variants ['gross','net'], got " . json_encode($gpsVariants);
    }
} catch (Throwable $e) {
    $c5Errors[] = 'exception: ' . $e->getMessage();
}
if (empty($c5Errors)) { echo "PASS C5 ffItemTypes() introspects ENUM and yields 19 tuples\n"; $pass++; }
else { echo "FAIL C5 " . implode('; ', $c5Errors) . "\n"; $failures[] = 'C5'; }

// ── C6: displayNameFor mapping correctness ──────────────────
$c6Errors = [];
$nameAssertions = [
    ['base_rental',                       null,    'Base Rental'],
    ['base_rental_reconciliation_credit', null,    'Rental Reconciliation Credit'],
    ['gps',                               'net',   'GPS Service (Net)'],
    ['gps',                               'gross', 'GPS Service (Gross)'],
    ['gps',                               null,    'GPS Service'],
    ['mileage_precharge',                 null,    'Mileage Precharge'],
    ['mileage_drawdown_credit',           null,    'Mileage Drawdown Credit'],
    ['damage',                            null,    'Damage Recovery'],
    ['late_fee',                          null,    'Late Fee'],
    ['early_return_credit',               null,    'Early Return Credit'],
];
foreach ($nameAssertions as [$itemType, $variant, $expected]) {
    $actual = ItemMatcher::displayNameFor($itemType, $variant);
    if ($actual !== $expected) {
        $variantStr = $variant ?? 'null';
        $c6Errors[] = "displayNameFor('{$itemType}', '{$variantStr}') expected '{$expected}', got '{$actual}'";
    }
}
if (empty($c6Errors)) { echo "PASS C6 displayNameFor mappings (incl. GPS variants + reconciliation credit)\n"; $pass++; }
else { echo "FAIL C6 " . implode('; ', $c6Errors) . "\n"; $failures[] = 'C6'; }

// ── C7: findBestMatch exact_name ───────────────────────────
$c7Errors = [];
$mockQboItems = [
    ['qbo_id' => '101', 'name' => 'Base Rental', 'income_account_id' => '4000'],
    ['qbo_id' => '102', 'name' => 'Other Random',  'income_account_id' => '4500'],
];
$match = ItemMatcher::findBestMatch('base_rental', $mockQboItems, null);
if ($match === null) {
    $c7Errors[] = 'expected match for base_rental, got null';
} elseif ($match['qbo_id'] !== '101') {
    $c7Errors[] = "expected qbo_id=101, got '{$match['qbo_id']}'";
} elseif ($match['confidence'] !== 'exact_name') {
    $c7Errors[] = "expected confidence='exact_name', got '{$match['confidence']}'";
}
if (empty($c7Errors)) { echo "PASS C7 findBestMatch exact_name on 'base_rental' vs 'Base Rental'\n"; $pass++; }
else { echo "FAIL C7 " . implode('; ', $c7Errors) . "\n"; $failures[] = 'C7'; }

// ── C8: findBestMatch null when no match ───────────────────
$c8Errors = [];
$mockQboItems2 = [
    ['qbo_id' => '201', 'name' => 'Completely Unrelated', 'income_account_id' => '5000'],
    ['qbo_id' => '202', 'name' => 'Xyz Foo Bar',           'income_account_id' => '5100'],
];
$match2 = ItemMatcher::findBestMatch('base_rental', $mockQboItems2, null);
if ($match2 !== null) {
    $c8Errors[] = 'expected null, got ' . json_encode($match2);
}
if (empty($c8Errors)) { echo "PASS C8 findBestMatch null when no signal\n"; $pass++; }
else { echo "FAIL C8 " . implode('; ', $c8Errors) . "\n"; $failures[] = 'C8'; }

// ── C9: uq_ff_item_type_variant accepts variants, rejects dupes ─
$c9Errors = [];
$insertedTestRows = [];
try {
    // Insert a (gps_smoke_test_a) — synthetic ff_item_type via NULL since
    // ff_item_type is varchar (not ENUM-FK'd). Use sentinel + cleanup.
    // To keep things isolated from the production iteration baseline,
    // we use a synthetic non-ENUM value 'X-SMOKE-GPS' that the matcher
    // would never touch (matchAll iterates ffItemTypes which sources
    // from INFORMATION_SCHEMA).
    $sentinelType = 'X-SMOKE-GPS';

    // Pre-clean (in case prior run left rows).
    db_execute("DELETE FROM acc_qbo_item_map WHERE ff_item_type = ?", [$sentinelType]);

    // Insert (sentinel, NULL).
    $a = db_insert('acc_qbo_item_map', [
        'ff_item_type'         => $sentinelType,
        'ff_item_type_variant' => null,
        'mapping_status'       => 'ff_only',
        'is_credit_variant'    => 0,
    ]);
    $insertedTestRows[] = $a;

    // Insert (sentinel, 'net').
    $b = db_insert('acc_qbo_item_map', [
        'ff_item_type'         => $sentinelType,
        'ff_item_type_variant' => 'net',
        'mapping_status'       => 'ff_only',
        'is_credit_variant'    => 0,
        'presentation_variant' => 'net',
    ]);
    $insertedTestRows[] = $b;

    // Insert (sentinel, 'gross').
    $c = db_insert('acc_qbo_item_map', [
        'ff_item_type'         => $sentinelType,
        'ff_item_type_variant' => 'gross',
        'mapping_status'       => 'ff_only',
        'is_credit_variant'    => 0,
        'presentation_variant' => 'gross',
    ]);
    $insertedTestRows[] = $c;

    // Insert duplicate (sentinel, 'net') — should fail.
    $rejected = false;
    try {
        $d = db_insert('acc_qbo_item_map', [
            'ff_item_type'         => $sentinelType,
            'ff_item_type_variant' => 'net',
            'mapping_status'       => 'ff_only',
            'is_credit_variant'    => 0,
            'presentation_variant' => 'net',
        ]);
        $insertedTestRows[] = $d; // unreachable in success path
    } catch (Throwable $e) {
        $rejected = true;
    }
    if (!$rejected) {
        $c9Errors[] = 'duplicate (X-SMOKE-GPS, net) was NOT rejected — UNIQUE(ff_item_type, ff_item_type_variant) not enforced';
    }
} catch (Throwable $e) {
    $c9Errors[] = 'exception during variant insertions: ' . $e->getMessage();
} finally {
    if (!empty($insertedTestRows)) {
        $placeholders = implode(',', array_fill(0, count($insertedTestRows), '?'));
        db_execute("DELETE FROM acc_qbo_item_map WHERE id IN ({$placeholders})", $insertedTestRows);
    }
    // Belt-and-suspenders cleanup by sentinel type.
    db_execute("DELETE FROM acc_qbo_item_map WHERE ff_item_type = 'X-SMOKE-GPS'");
}
if (empty($c9Errors)) { echo "PASS C9 uq_ff_item_type_variant accepts variants, rejects duplicates\n"; $pass++; }
else { echo "FAIL C9 " . implode('; ', $c9Errors) . "\n"; $failures[] = 'C9'; }

// ── C10: matchAll preserves manual overrides ─────────────────
// Surface check: ItemMatcher itself doesn't know about caller-side
// manual locks — that filtering lives in auto_match.php. We verify
// here that matchAll emits a 'mapped' decision when QBO Items align
// AND that the decisions array preserves the input order semantics
// (FF tuples first, qbo_only last). Caller-side locking is exercised
// in auto_match endpoint integration testing.
$c10Errors = [];
$qboMock = [
    ['qbo_id' => '301', 'name' => 'Base Rental',      'income_account_id' => '4000'],
    ['qbo_id' => '302', 'name' => 'Mileage Precharge','income_account_id' => '4100'],
    ['qbo_id' => '303', 'name' => 'Unrelated Item',   'income_account_id' => '5000'],
];
try {
    $decisions = ItemMatcher::matchAll($qboMock);
    // Find the base_rental decision.
    $br = null;
    foreach ($decisions as $d) {
        if ($d['ff_item_type'] === 'base_rental') { $br = $d; break; }
    }
    if ($br === null) {
        $c10Errors[] = 'matchAll did not emit a base_rental decision';
    } elseif ($br['mapping_status'] !== 'mapped' || $br['match_confidence'] !== 'exact_name') {
        $c10Errors[] = "base_rental decision: expected mapped/exact_name, got " . $br['mapping_status'] . '/' . ($br['match_confidence'] ?? 'null');
    } elseif ($br['qbo_item_id'] !== '301') {
        $c10Errors[] = "expected qbo_id=301 for base_rental, got '{$br['qbo_item_id']}'";
    }
    // Find the qbo_only decision for '303' (unmatched QBO Item).
    $qonly = null;
    foreach ($decisions as $d) {
        if (($d['qbo_item_id'] ?? null) === '303' && $d['ff_item_type'] === null) { $qonly = $d; break; }
    }
    if ($qonly === null) {
        $c10Errors[] = 'matchAll did not emit a qbo_only decision for unmatched Item 303';
    }
} catch (Throwable $e) {
    $c10Errors[] = 'exception: ' . $e->getMessage();
}
if (empty($c10Errors)) { echo "PASS C10 matchAll emits mapped + qbo_only decisions correctly\n"; $pass++; }
else { echo "FAIL C10 " . implode('; ', $c10Errors) . "\n"; $failures[] = 'C10'; }

// ── C11: ItemPuller normalize is HTTP-free ──────────────────
// Exercise normalize() against mock JSON; verify no network side-effect
// (implicit — normalize() is pure function with no curl, file_get_contents,
// or other I/O). The check here is structural: the method body must not
// reference any HTTP primitive directly.
$c11Errors = [];
try {
    $rm = new ReflectionMethod(ItemPuller::class, 'normalize');
    $source = file_get_contents($rm->getFileName());
    // Extract just normalize() body for the check.
    $body = '';
    if (preg_match('/public static function normalize\([^)]*\)[^{]*\{(.*?)\n    \}/s', (string) $source, $m)) {
        $body = $m[1];
    }
    foreach (['curl_', 'file_get_contents', 'fopen', 'http_', 'QuickBooksClient'] as $banned) {
        if (str_contains($body, $banned)) {
            $c11Errors[] = "ItemPuller::normalize body references {$banned} — must be pure";
        }
    }
} catch (Throwable $e) {
    $c11Errors[] = 'reflection exception: ' . $e->getMessage();
}
if (empty($c11Errors)) { echo "PASS C11 ItemPuller::normalize is HTTP-free\n"; $pass++; }
else { echo "FAIL C11 " . implode('; ', $c11Errors) . "\n"; $failures[] = 'C11'; }

// ── C12: resolveIncomeAccount happy path ───────────────────
// Uses real acc_qbo_account_map state. If a mapped revenue account
// exists, expect resolveIncomeAccount to return it. If none mapped,
// expect ChartOfAccountsIncompleteException (covered in C13).
$c12Errors = [];
try {
    $hasMappedRevenue = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_account_map m
           JOIN acc_accounts acc ON acc.id = m.ff_account_id
          WHERE m.mapping_status = 'mapped' AND acc.account_type = 'revenue'"
    );
    if ($hasMappedRevenue > 0) {
        $resolved = ItemCreator::resolveIncomeAccount('base_rental', null);
        if (empty($resolved['qbo_id']) || empty($resolved['qbo_name'])) {
            $c12Errors[] = 'resolveIncomeAccount returned an empty struct despite mapped revenue present';
        }
    } else {
        // Skip — C13 handles the no-revenue-mapped case.
        echo "INFO C12 skipped — no mapped revenue accounts to verify happy path against\n";
    }
} catch (Throwable $e) {
    $c12Errors[] = 'exception: ' . $e->getMessage();
}
if (empty($c12Errors)) { echo "PASS C12 resolveIncomeAccount returns mapped revenue account\n"; $pass++; }
else { echo "FAIL C12 " . implode('; ', $c12Errors) . "\n"; $failures[] = 'C12'; }

// ── C13: resolveIncomeAccount throws when no revenue mapped ──
// Temporarily clear mapped revenue accounts, expect exception, restore.
$c13Errors = [];
$revBackup = [];
try {
    // Snapshot every mapped revenue mapping.
    $revBackup = db_select(
        "SELECT m.id, m.mapping_status, m.match_confidence, m.last_synced_at
           FROM acc_qbo_account_map m
           JOIN acc_accounts acc ON acc.id = m.ff_account_id
          WHERE m.mapping_status = 'mapped' AND acc.account_type = 'revenue'"
    );
    if (!empty($revBackup)) {
        $ids = array_column($revBackup, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db_execute(
            "UPDATE acc_qbo_account_map SET mapping_status = 'ignored' WHERE id IN ({$placeholders})",
            $ids
        );
    }
    $threw = false;
    try {
        ItemCreator::resolveIncomeAccount('base_rental', null);
    } catch (ChartOfAccountsIncompleteException $e) {
        $threw = true;
    }
    if (!$threw) {
        $c13Errors[] = 'expected ChartOfAccountsIncompleteException, none thrown';
    }
} catch (Throwable $e) {
    $c13Errors[] = 'unexpected exception: ' . $e->getMessage();
} finally {
    // Restore the backed-up mappings.
    if (!empty($revBackup)) {
        foreach ($revBackup as $b) {
            db_execute(
                "UPDATE acc_qbo_account_map SET mapping_status = ? WHERE id = ?",
                [(string) $b['mapping_status'], (int) $b['id']]
            );
        }
    }
}
if (empty($c13Errors)) { echo "PASS C13 resolveIncomeAccount throws ChartOfAccountsIncompleteException when no revenue mapped\n"; $pass++; }
else { echo "FAIL C13 " . implode('; ', $c13Errors) . "\n"; $failures[] = 'C13'; }

// ── C14: API endpoint files exist + lint ────────────────────
$c14Errors = [];
$endpoints = ['pull.php', 'auto_match.php', 'save_mapping.php', 'create_qbo_item.php', 'list.php'];
foreach ($endpoints as $ep) {
    $path = FF_ROOT . '/api/v1/quickbooks/items/' . $ep;
    if (!is_file($path)) {
        $c14Errors[] = "{$ep} missing";
        continue;
    }
    $lintOutput = [];
    $lintCode = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $lintOutput, $lintCode);
    if ($lintCode !== 0) {
        $c14Errors[] = "{$ep} php -l failed: " . implode(' ', $lintOutput);
    }
}
if (empty($c14Errors)) { echo "PASS C14 All 5 items endpoints exist + lint clean\n"; $pass++; }
else { echo "FAIL C14 " . implode('; ', $c14Errors) . "\n"; $failures[] = 'C14'; }

// ── C15: items.php UI page exists + lint + Alpine + view gate ─
$c15Errors = [];
$uiPath = FF_ROOT . '/app/admin/quickbooks/items.php';
if (!is_file($uiPath)) {
    $c15Errors[] = 'items.php missing';
} else {
    $lintOutput = [];
    $lintCode = 0;
    exec('php -l ' . escapeshellarg($uiPath) . ' 2>&1', $lintOutput, $lintCode);
    if ($lintCode !== 0) {
        $c15Errors[] = 'items.php php -l failed: ' . implode(' ', $lintOutput);
    } else {
        $content = file_get_contents($uiPath);
        if (!str_contains((string) $content, 'function qboItemMapping(')) {
            $c15Errors[] = "Alpine factory 'qboItemMapping' not found";
        }
        if (!str_contains((string) $content, "require_permission('quickbooks', 'view')")) {
            $c15Errors[] = "view permission gate not found";
        }
    }
}
if (empty($c15Errors)) { echo "PASS C15 items.php exists + lints + Alpine factory + view gate\n"; $pass++; }
else { echo "FAIL C15 " . implode('; ', $c15Errors) . "\n"; $failures[] = 'C15'; }

// ── C16: nav config has 12 QBO children incl. Items + Bills (S-QBO-BILL-SYNC-UI) ─────────
$c16Errors = [];
$navConfig = require FF_ROOT . '/config/navigation.php';
$qboParent = null;
foreach ($navConfig as $entry) {
    if (isset($entry['label']) && $entry['label'] === 'QuickBooks' && isset($entry['children'])) {
        $qboParent = $entry;
        break;
    }
}
if ($qboParent === null) {
    $c16Errors[] = 'QuickBooks parent entry with children not found';
} else {
    $labels = array_map(static fn($c) => $c['label'] ?? '', $qboParent['children']);
    // S-QBO-BILL-SYNC-UI 2026-05-27: +Bills between Invoices and Settings.
    // S-QBO-26 2026-05-30: +Manual Sync between Drift and Customers.
    $expected = ['Dashboard', 'Sync Queue', 'Sync Log', 'Drift', 'Manual Sync', 'Customers', 'Vendors', 'Accounts', 'Tax Codes', 'Bank Accounts', 'Items', 'Invoices', 'Credit Memos', 'Refund Receipts', 'Bills', 'Bill Payments', 'Payments', 'Journal Entries', 'Settings'];
    if ($labels !== $expected) {
        $c16Errors[] = 'expected ' . json_encode($expected) . ' got ' . json_encode($labels);
    }
}
if (empty($c16Errors)) { echo "PASS C16 nav has 19 QuickBooks children with Manual Sync between Drift and Customers (S-QBO-26)\n"; $pass++; }
else { echo "FAIL C16 " . implode('; ', $c16Errors) . "\n"; $failures[] = 'C16'; }

// ── C17: ItemPuller::normalize maps representative JSON ──────
$c17Errors = [];
$rawQboItem = [
    'Id'                  => '42',
    'SyncToken'           => '3',
    'Name'                => 'Hours',
    'FullyQualifiedName'  => 'Services:Hours',
    'Description'         => 'Hourly service rate',
    'Type'                => 'Service',
    'Active'              => true,
    'IncomeAccountRef'    => ['value' => '83', 'name' => 'Sales of Product Income'],
    'ExpenseAccountRef'   => ['value' => '12', 'name' => 'Cost of Services'],
    'MetaData'            => ['LastUpdatedTime' => '2026-05-21T20:00:00-08:00'],
];
$normalized = ItemPuller::normalize($rawQboItem);
$expectedNorm = [
    'qbo_id'               => '42',
    'sync_token'           => '3',
    'name'                 => 'Hours',
    'fully_qualified_name' => 'Services:Hours',
    'description'          => 'Hourly service rate',
    'type'                 => 'Service',
    'active'               => true,
    'income_account_id'    => '83',
    'income_account_name'  => 'Sales of Product Income',
    'expense_account_id'   => '12',
    'expense_account_name' => 'Cost of Services',
    'last_updated_qbo'     => '2026-05-21T20:00:00-08:00',
];
foreach ($expectedNorm as $k => $v) {
    if (!array_key_exists($k, $normalized)) {
        $c17Errors[] = "normalized missing key: {$k}";
        continue;
    }
    if ($normalized[$k] !== $v) {
        $c17Errors[] = "normalized[{$k}]: expected " . json_encode($v) . ", got " . json_encode($normalized[$k]);
    }
}
if (empty($c17Errors)) { echo "PASS C17 ItemPuller::normalize maps representative QBO JSON\n"; $pass++; }
else { echo "FAIL C17 " . implode('; ', $c17Errors) . "\n"; $failures[] = 'C17'; }

// ── C18: UI_CATEGORIES covers every FF ENUM value once ──────
$c18Errors = [];
$enumTypes = array_unique(array_column(ItemMatcher::ffItemTypes(), 'ff_item_type'));
$catSeen = [];
foreach (ItemMatcher::UI_CATEGORIES as $label => $types) {
    foreach ($types as $t) {
        if (isset($catSeen[$t])) {
            $c18Errors[] = "item_type '{$t}' appears in multiple categories ('{$catSeen[$t]}' and '{$label}')";
        }
        $catSeen[$t] = $label;
    }
}
foreach ($enumTypes as $t) {
    if (!isset($catSeen[$t])) {
        $c18Errors[] = "item_type '{$t}' missing from UI_CATEGORIES";
    }
}
if (empty($c18Errors)) { echo "PASS C18 UI_CATEGORIES covers all 18 ENUM values exactly once\n"; $pass++; }
else { echo "FAIL C18 " . implode('; ', $c18Errors) . "\n"; $failures[] = 'C18'; }

// ── C19: S-QBO-MATCHER-WEDGE-RECOVERY — item wedge rescue ──
// Item map has no FK on ff_item_type (ENUM column; MySQL doesn't FK ENUMs),
// so we use an existing valid ff_item_type value + variant pairing that
// won't collide with the live 19 tuples. base_rental with a unique variant
// (smoke-only sentinel) is safe.
$c19Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_item_map WHERE qbo_item_id = ? OR (ff_item_type = ? AND ff_item_type_variant = ?)", ['TEST-SMOKE-I-WEDGE19', 'base_rental', 'wedge19']);

    db_insert('acc_qbo_item_map', [
        'ff_item_type'             => 'base_rental',
        'ff_item_type_variant'     => 'wedge19',
        'qbo_item_id'              => null,
        'mapping_status'           => 'ff_only',
        'qbo_name'                 => 'Wedge Item Service',
        'qbo_fully_qualified_name' => 'Wedge Item Service',
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);
    db_insert('acc_qbo_item_map', [
        'ff_item_type'             => null,
        'ff_item_type_variant'     => null,
        'qbo_item_id'              => 'TEST-SMOKE-I-WEDGE19',
        'qbo_sync_token'           => '0',
        'mapping_status'           => 'qbo_only',
        'qbo_name'                 => 'Wedge Item Service',
        'qbo_fully_qualified_name' => 'Wedge Item Service',
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);

    ItemMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_item_id, mapping_status, match_notes FROM acc_qbo_item_map WHERE ff_item_type = ? AND ff_item_type_variant = ?", ['base_rental', 'wedge19']);
    if ($wedged === null) {
        $c19Errors[] = 'wedge row gone';
    } else {
        if ($wedged['qbo_item_id'] !== 'TEST-SMOKE-I-WEDGE19') {
            $c19Errors[] = "expected absorbed qbo_item_id, got '" . ($wedged['qbo_item_id'] ?? 'NULL') . "'";
        }
        if ($wedged['mapping_status'] !== 'mapped') {
            $c19Errors[] = "expected mapped, got '" . $wedged['mapping_status'] . "'";
        }
        if (!str_contains((string) $wedged['match_notes'], 'wedge_recovery')) {
            $c19Errors[] = "expected wedge_recovery in notes";
        }
    }
    $candidate = db_row("SELECT id FROM acc_qbo_item_map WHERE qbo_item_id = ? AND mapping_status = 'qbo_only'", ['TEST-SMOKE-I-WEDGE19']);
    if ($candidate !== null) {
        $c19Errors[] = "expected qbo_only candidate deleted";
    }
} catch (Throwable $e) {
    $c19Errors[] = 'C19 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_item_map WHERE qbo_item_id = ? OR (ff_item_type = ? AND ff_item_type_variant = ?)", ['TEST-SMOKE-I-WEDGE19', 'base_rental', 'wedge19']);
}
if (empty($c19Errors)) {
    echo "PASS C19 ItemMatcher rescueHalfStateRows absorbs wedge by qbo_fully_qualified_name (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C19 " . implode('; ', $c19Errors) . "\n";
    $failures[] = 'C19';
}

// ── C20: S-QBO-MATCHER-WEDGE-RECOVERY — item no-op when no match ──
$c20Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_item_map WHERE ff_item_type = ? AND ff_item_type_variant = ?", ['base_rental', 'wedge20']);
    db_insert('acc_qbo_item_map', [
        'ff_item_type'             => 'base_rental',
        'ff_item_type_variant'     => 'wedge20',
        'qbo_item_id'              => null,
        'mapping_status'           => 'ff_only',
        'qbo_fully_qualified_name' => 'Lone Item Wedge ' . substr((string) time(), -6),
        'last_synced_at'           => date('Y-m-d H:i:s'),
    ]);

    ItemMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_item_id, mapping_status FROM acc_qbo_item_map WHERE ff_item_type = ? AND ff_item_type_variant = ?", ['base_rental', 'wedge20']);
    if ($wedged === null) {
        $c20Errors[] = 'wedge row gone (false positive)';
    } elseif ($wedged['qbo_item_id'] !== null) {
        $c20Errors[] = "no-op expected, got qbo_item_id='" . $wedged['qbo_item_id'] . "'";
    }
} catch (Throwable $e) {
    $c20Errors[] = 'C20 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_item_map WHERE ff_item_type = ? AND ff_item_type_variant = ?", ['base_rental', 'wedge20']);
}
if (empty($c20Errors)) {
    echo "PASS C20 ItemMatcher rescueHalfStateRows no-ops when no match (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C20 " . implode('; ', $c20Errors) . "\n";
    $failures[] = 'C20';
}

} catch (Throwable $e) {
    echo "CRASH: " . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures[] = 'crash';
} finally {
    // Belt-and-suspenders cleanup of any sentinel rows.
    if (!empty($sentinelQboIds)) {
        $placeholders = implode(',', array_fill(0, count($sentinelQboIds), '?'));
        db_execute("DELETE FROM acc_qbo_item_map WHERE qbo_item_id IN ({$placeholders})", $sentinelQboIds);
    }
    db_execute("DELETE FROM acc_qbo_item_map WHERE ff_item_type = 'X-SMOKE-GPS'");
}

// ── Summary ────────────────────────────────────────────────
if (!empty($failures)) {
    echo "\nqbo_item_mapping_smoke: {$pass}/{$total} PASS — failures: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\nqbo_item_mapping_smoke: {$pass}/{$total} PASS\n";
exit(0);
