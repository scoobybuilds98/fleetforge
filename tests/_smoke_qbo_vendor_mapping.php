<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_vendor_mapping.php
 *
 * S-QBO-7 — Structural + behavioural smoke for the vendor mapping
 * flow. Runs OFFLINE: no Intuit HTTP traffic (the Puller's pullAll()
 * is exercised by feeding sample JSON through normalize(), not by
 * hitting QBO). Live verification is operator-side post-commit.
 *
 * Self-cleaning: any synthetic rows the smoke creates use sentinel
 * ids (FF vendor id=999990+, qbo_vendor_id='TEST-SMOKE-V-*') so the
 * finally block can scrub them on either pass or fail.
 *
 * 12 sub-checks (mirror of _smoke_qbo_customer_mapping.php):
 *   C1: acc_qbo_vendor_map table shape — expected columns + indexes + FK
 *   C2: VendorPuller class exists with public static pullAll + normalize
 *   C3: VendorMatcher class exists with public static normalizeName /
 *       findBestMatch / matchAll
 *   C4: normalizeName behavior — three corporate-suffix variants all
 *       normalize to the same string
 *   C5: findBestMatch returns 'exact' for a normalized-name match
 *   C6: findBestMatch returns null when there is no match
 *   C7: findBestMatch returns 'high' confidence for a Levenshtein hit
 *       (single-character typo within distance 3)
 *   C8: UNIQUE(ff_vendor_id) + UNIQUE(qbo_vendor_id) allow multiple NULL
 *       but reject duplicate non-null values
 *   C9: 4 API endpoint files exist + php -l clean
 *  C10: vendors.php page exists + php -l clean
 *  C11: Nav config has 7 QuickBooks children including Vendors in
 *       expected position (between Customers and Settings)
 *  C12: VendorPuller::normalize maps a representative QBO Vendor JSON
 *       fragment to the documented flat-record shape (offline — no
 *       live HTTP call)
 *
 * Exit 0 on all PASS; exit 1 with a diagnostic list on any FAIL.
 *
 * @session S-QBO-7
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §7.5
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\VendorPuller;
use FleetForge\QboPushers\VendorMatcher;

$failures = [];
$pass     = 0;
$total    = 14;

/** Sentinel-tagged rows we'll clean up at the end. */
$sentinelFfIds       = [];
$sentinelQboIds      = [];
$sentinelMappingIds  = [];

try {

// ── C1: table shape ─────────────────────────────────────────
// Expected columns per S-QBO-7 migration. Note vendor_map differs
// from customer_map: qbo_balance REMOVED, qbo_given_name +
// qbo_family_name + qbo_v4v_status ADDED.
$expectedCols = [
    'id', 'ff_vendor_id', 'qbo_vendor_id', 'qbo_sync_token',
    'qbo_display_name', 'qbo_company_name', 'qbo_given_name',
    'qbo_family_name', 'qbo_email', 'qbo_phone', 'qbo_active',
    'qbo_v4v_status', 'mapping_status', 'match_confidence',
    'match_notes', 'last_synced_at', 'last_pull_at', 'last_push_at',
    'created_at', 'updated_at', 'created_by_user_id',
];
$c1Errors = [];
try {
    $rows = db_select("SHOW COLUMNS FROM acc_qbo_vendor_map");
    $present = array_map(fn($r) => $r['Field'], $rows);
    foreach ($expectedCols as $col) {
        if (!in_array($col, $present, true)) {
            $c1Errors[] = "missing column: {$col}";
        }
    }
    // qbo_balance must NOT exist (intentional D-QBO-7 removal).
    if (in_array('qbo_balance', $present, true)) {
        $c1Errors[] = 'qbo_balance column should NOT exist on vendor map (S-QBO-18 territory)';
    }
    // Check the two UNIQUE indexes and the FK.
    $idx = db_select("SHOW INDEX FROM acc_qbo_vendor_map");
    $idxNames = array_unique(array_map(fn($r) => $r['Key_name'], $idx));
    foreach (['uq_ff_vendor', 'uq_qbo_vendor'] as $i) {
        if (!in_array($i, $idxNames, true)) {
            $c1Errors[] = "missing index: {$i}";
        }
    }
} catch (Throwable $e) {
    $c1Errors[] = 'SHOW COLUMNS/INDEX threw: ' . $e->getMessage();
}
if (empty($c1Errors)) {
    echo "PASS C1  acc_qbo_vendor_map has all 21 columns + 2 UNIQUE indexes + qbo_balance absent\n";
    $pass++;
} else {
    echo "FAIL C1  " . implode('; ', $c1Errors) . "\n";
    $failures[] = 'C1';
}

// ── C2: VendorPuller surface ───────────────────────────────
$c2Errors = [];
if (!class_exists(VendorPuller::class)) {
    $c2Errors[] = 'VendorPuller class not autoloaded under FleetForge\\QboPushers';
} else {
    $ref = new ReflectionClass(VendorPuller::class);
    foreach (['pullAll', 'normalize'] as $m) {
        if (!$ref->hasMethod($m)) {
            $c2Errors[] = "missing method: {$m}";
            continue;
        }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic()) {
            $c2Errors[] = "{$m} must be static";
        }
    }
}
if (empty($c2Errors)) {
    echo "PASS C2  VendorPuller class surface (pullAll + normalize static)\n";
    $pass++;
} else {
    echo "FAIL C2  " . implode('; ', $c2Errors) . "\n";
    $failures[] = 'C2';
}

// ── C3: VendorMatcher surface ──────────────────────────────
$c3Errors = [];
if (!class_exists(VendorMatcher::class)) {
    $c3Errors[] = 'VendorMatcher class not autoloaded under FleetForge\\QboPushers';
} else {
    $ref = new ReflectionClass(VendorMatcher::class);
    foreach (['normalizeName', 'findBestMatch', 'matchAll'] as $m) {
        if (!$ref->hasMethod($m)) {
            $c3Errors[] = "missing method: {$m}";
            continue;
        }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) {
            $c3Errors[] = "{$m} must be public static";
        }
    }
}
if (empty($c3Errors)) {
    echo "PASS C3  VendorMatcher class surface (normalizeName + findBestMatch + matchAll public static)\n";
    $pass++;
} else {
    echo "FAIL C3  " . implode('; ', $c3Errors) . "\n";
    $failures[] = 'C3';
}

// ── C4: normalizeName collapses corporate suffixes ─────────
$c4Errors = [];
try {
    $n1 = VendorMatcher::normalizeName('Acme Corp Ltd.');
    $n2 = VendorMatcher::normalizeName('Acme Inc');
    $n3 = VendorMatcher::normalizeName('ACME, LLC');
    if ($n1 !== $n2 || $n2 !== $n3) {
        $c4Errors[] = "normalizations diverged: '{$n1}' / '{$n2}' / '{$n3}' (all should equal 'acme')";
    }
    if ($n1 !== 'acme') {
        $c4Errors[] = "expected 'acme', got '{$n1}'";
    }
} catch (Throwable $e) {
    $c4Errors[] = 'normalizeName threw: ' . $e->getMessage();
}
if (empty($c4Errors)) {
    echo "PASS C4  normalizeName collapses corporate suffixes ('Acme Corp Ltd.' = 'Acme Inc' = 'ACME, LLC' = 'acme')\n";
    $pass++;
} else {
    echo "FAIL C4  " . implode('; ', $c4Errors) . "\n";
    $failures[] = 'C4';
}

// ── C5: findBestMatch — exact normalized name ──────────────
// VendorMatcher uses `name` as the FF field (NOT company_name).
$c5Errors = [];
try {
    $qbo = [
        ['qbo_id' => '1', 'display_name' => 'Acme Inc',  'company_name' => 'Acme Inc',  'email' => '', 'phone' => ''],
        ['qbo_id' => '2', 'display_name' => 'Different', 'company_name' => 'Different', 'email' => '', 'phone' => ''],
    ];
    $m = VendorMatcher::findBestMatch(
        ['name' => 'Acme Corp', 'email' => '', 'phone' => ''],
        $qbo
    );
    if ($m === null) {
        $c5Errors[] = 'expected match, got null';
    } elseif ($m['qbo_id'] !== '1' || $m['confidence'] !== 'exact') {
        $c5Errors[] = "expected qbo_id=1 confidence=exact, got " . json_encode($m);
    }
} catch (Throwable $e) {
    $c5Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c5Errors)) {
    echo "PASS C5  findBestMatch returns 'exact' on normalized name equality (uses vendors.name)\n";
    $pass++;
} else {
    echo "FAIL C5  " . implode('; ', $c5Errors) . "\n";
    $failures[] = 'C5';
}

// ── C6: findBestMatch — no match ───────────────────────────
$c6Errors = [];
try {
    $qbo = [
        ['qbo_id' => '1', 'display_name' => 'Acme Inc',  'company_name' => 'Acme Inc',  'email' => '', 'phone' => ''],
        ['qbo_id' => '2', 'display_name' => 'Different', 'company_name' => 'Different', 'email' => '', 'phone' => ''],
    ];
    $m = VendorMatcher::findBestMatch(
        ['name' => 'Zaphod Industries', 'email' => '', 'phone' => ''],
        $qbo
    );
    if ($m !== null) {
        $c6Errors[] = 'expected null, got ' . json_encode($m);
    }
} catch (Throwable $e) {
    $c6Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c6Errors)) {
    echo "PASS C6  findBestMatch returns null when no signal aligns\n";
    $pass++;
} else {
    echo "FAIL C6  " . implode('; ', $c6Errors) . "\n";
    $failures[] = 'C6';
}

// ── C7: findBestMatch — Levenshtein typo ───────────────────
$c7Errors = [];
try {
    $qbo = [
        ['qbo_id' => '1', 'display_name' => 'Acme Corp', 'company_name' => 'Acme Corp', 'email' => '', 'phone' => ''],
    ];
    // 'Acmee Corp' vs 'Acme Corp' — one extra char → distance 1 → 'high'.
    $m = VendorMatcher::findBestMatch(
        ['name' => 'Acmee Corp', 'email' => '', 'phone' => ''],
        $qbo
    );
    if ($m === null) {
        $c7Errors[] = 'expected match, got null';
    } elseif ($m['confidence'] !== 'high') {
        $c7Errors[] = "expected confidence=high, got " . json_encode($m);
    }
} catch (Throwable $e) {
    $c7Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c7Errors)) {
    echo "PASS C7  findBestMatch returns 'high' for Levenshtein ≤ 3 (typo)\n";
    $pass++;
} else {
    echo "FAIL C7  " . implode('; ', $c7Errors) . "\n";
    $failures[] = 'C7';
}

// ── C8: UNIQUE behavior under NULL + non-NULL ──────────────
$c8Errors = [];
try {
    // Two NULL-FF rows should both succeed.
    $qboA = 'TEST-SMOKE-V-UNIQ-' . bin2hex(random_bytes(8));
    $qboB = 'TEST-SMOKE-V-UNIQ-' . bin2hex(random_bytes(8));
    $sentinelQboIds[] = $qboA;
    $sentinelQboIds[] = $qboB;

    $idA = db_insert('acc_qbo_vendor_map', [
        'qbo_vendor_id'  => $qboA,
        'mapping_status' => 'qbo_only',
    ]);
    $sentinelMappingIds[] = $idA;
    $idB = db_insert('acc_qbo_vendor_map', [
        'qbo_vendor_id'  => $qboB,
        'mapping_status' => 'qbo_only',
    ]);
    $sentinelMappingIds[] = $idB;

    // Now insert a duplicate qbo_vendor_id — should fail.
    $duplicateRejected = false;
    try {
        db_insert('acc_qbo_vendor_map', [
            'qbo_vendor_id'  => $qboA,
            'mapping_status' => 'qbo_only',
        ]);
    } catch (Throwable $e) {
        $duplicateRejected = true;
    }
    if (!$duplicateRejected) {
        $c8Errors[] = 'duplicate qbo_vendor_id INSERT was accepted (UNIQUE constraint broken)';
    }
} catch (Throwable $e) {
    $c8Errors[] = 'C8 test setup threw: ' . $e->getMessage();
}
if (empty($c8Errors)) {
    echo "PASS C8  UNIQUE allows multiple NULL but rejects duplicate non-NULL qbo_vendor_id\n";
    $pass++;
} else {
    echo "FAIL C8  " . implode('; ', $c8Errors) . "\n";
    $failures[] = 'C8';
}

// ── C9: 4 API endpoint files exist + lint clean ────────────
$c9Errors = [];
$endpoints = [
    'api/v1/quickbooks/vendors/pull.php',
    'api/v1/quickbooks/vendors/auto_match.php',
    'api/v1/quickbooks/vendors/save_mapping.php',
    'api/v1/quickbooks/vendors/list.php',
];
foreach ($endpoints as $rel) {
    $abs = realpath(__DIR__ . '/../' . $rel);
    if ($abs === false || !is_readable($abs)) {
        $c9Errors[] = "endpoint missing: {$rel}";
        continue;
    }
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($abs) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c9Errors[] = "lint failed: {$rel} — " . implode('; ', $out);
    }
}
if (empty($c9Errors)) {
    echo "PASS C9  4 API endpoints exist + php -l clean\n";
    $pass++;
} else {
    echo "FAIL C9  " . implode('; ', $c9Errors) . "\n";
    $failures[] = 'C9';
}

// ── C10: vendors.php page exists + lints ───────────────────
$c10Errors = [];
$pagePath = realpath(__DIR__ . '/../app/admin/quickbooks/vendors.php');
if ($pagePath === false || !is_readable($pagePath)) {
    $c10Errors[] = 'app/admin/quickbooks/vendors.php missing or unreadable';
} else {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($pagePath) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c10Errors[] = 'vendors.php lint failed: ' . implode('; ', $out);
    }
    // Verify Alpine factory + page-level gate.
    $src = file_get_contents($pagePath);
    if (!str_contains($src, "qboVendorMapping()")) {
        $c10Errors[] = 'vendors.php does not define qboVendorMapping() Alpine factory';
    }
    if (!preg_match("/require_permission\(\s*'quickbooks'\s*,\s*'view'\s*\)/", $src)) {
        $c10Errors[] = "vendors.php missing require_permission('quickbooks','view') gate";
    }
}
if (empty($c10Errors)) {
    echo "PASS C10 vendors.php page exists, lints, declares Alpine factory + view gate\n";
    $pass++;
} else {
    echo "FAIL C10 " . implode('; ', $c10Errors) . "\n";
    $failures[] = 'C10';
}

// ── C11: nav has 9 QuickBooks children incl. Vendors ───────
// Grew 7→8 in S-QBO-8 (Accounts), then 8→9 in S-QBO-9 (Tax Codes).
$c11Errors = [];
$nav = require __DIR__ . '/../config/navigation.php';
$qbo = null;
foreach ($nav as $group) {
    if (($group['label'] ?? '') === 'QuickBooks' && !empty($group['children'] ?? [])) {
        $qbo = $group;
        break;
    }
}
if ($qbo === null) {
    $c11Errors[] = 'QuickBooks nav group not found';
} else {
    $children = $qbo['children'] ?? [];
    $labels = array_map(fn($c) => $c['label'] ?? '', $children);
    if (count($children) !== 12) {
        $c11Errors[] = 'expected 12 QuickBooks children, got ' . count($children) . ' (' . implode(', ', $labels) . ')';
    }
    if (!in_array('Vendors', $labels, true)) {
        $c11Errors[] = "no 'Vendors' child in QuickBooks nav";
    }
    $expectedOrder = ['Dashboard', 'Sync Queue', 'Sync Log', 'Drift', 'Customers', 'Vendors', 'Accounts', 'Tax Codes', 'Items', 'Invoices', 'Bills', 'Settings'];
    if ($labels !== $expectedOrder) {
        $c11Errors[] = 'nav order mismatch — got [' . implode(', ', $labels) . '], expected [' . implode(', ', $expectedOrder) . ']';
    }
}
if (empty($c11Errors)) {
    echo "PASS C11 nav has 11 QuickBooks children with Vendors in expected position\n";
    $pass++;
} else {
    echo "FAIL C11 " . implode('; ', $c11Errors) . "\n";
    $failures[] = 'C11';
}

// ── C12: Puller::normalize maps representative QBO Vendor JSON
$c12Errors = [];
try {
    // Representative QBO Vendor JSON fragment — exercises all
    // mapped fields plus at least one missing field to hit the
    // defensive `?? ''` accessors.
    $sample = [
        'Id'               => '42',
        'SyncToken'        => '3',
        'DisplayName'      => 'Pacific Diesel Repair',
        'CompanyName'      => 'Pacific Diesel Repair Ltd.',
        'GivenName'        => 'Maria',
        'FamilyName'       => 'Gonzalez',
        'PrimaryEmailAddr' => ['Address' => 'service@pacdiesel.test'],
        'PrimaryPhone'     => ['FreeFormNumber' => '+1-604-555-0142'],
        'Active'           => true,
        'V4VStatus'        => 'NotEligible',
        'MetaData'         => ['LastUpdatedTime' => '2026-05-20T14:00:00-07:00'],
    ];
    $n = VendorPuller::normalize($sample);
    $expected = [
        'qbo_id'           => '42',
        'sync_token'       => '3',
        'display_name'     => 'Pacific Diesel Repair',
        'company_name'     => 'Pacific Diesel Repair Ltd.',
        'given_name'       => 'Maria',
        'family_name'      => 'Gonzalez',
        'email'            => 'service@pacdiesel.test',
        'phone'            => '+1-604-555-0142',
        'active'           => true,
        'v4v_status'       => 'NotEligible',
        'last_updated_qbo' => '2026-05-20T14:00:00-07:00',
    ];
    foreach ($expected as $k => $v) {
        if (!array_key_exists($k, $n)) {
            $c12Errors[] = "missing key in normalize output: {$k}";
            continue;
        }
        if ($n[$k] !== $v) {
            $c12Errors[] = "{$k} mismatch: got " . var_export($n[$k], true) . ", want " . var_export($v, true);
        }
    }
} catch (Throwable $e) {
    $c12Errors[] = 'normalize threw: ' . $e->getMessage();
}
if (empty($c12Errors)) {
    echo "PASS C12 VendorPuller::normalize maps representative QBO JSON to expected flat shape\n";
    $pass++;
} else {
    echo "FAIL C12 " . implode('; ', $c12Errors) . "\n";
    $failures[] = 'C12';
}

// ── C13: S-QBO-MATCHER-WEDGE-RECOVERY — vendor wedge rescue ──
$c13Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE qbo_vendor_id = ? OR ff_vendor_id = ?", ['TEST-SMOKE-V-WEDGE13', 999993]);
    db_execute("DELETE FROM vendors WHERE id = 999993");
    db_execute("INSERT INTO vendors (id, name, vendor_type) VALUES (999993, ?, 'other')", ['SMOKE C13 Wedge Vendor']);

    db_insert('acc_qbo_vendor_map', [
        'ff_vendor_id'      => 999993,
        'qbo_vendor_id'     => null,
        'mapping_status'    => 'ff_only',
        'qbo_display_name'  => 'Wedge Vendor Display',
        'qbo_company_name'  => 'Wedge Vendor Display',
        'last_synced_at'    => date('Y-m-d H:i:s'),
    ]);
    db_insert('acc_qbo_vendor_map', [
        'ff_vendor_id'      => null,
        'qbo_vendor_id'     => 'TEST-SMOKE-V-WEDGE13',
        'qbo_sync_token'    => '0',
        'mapping_status'    => 'qbo_only',
        'qbo_display_name'  => 'Wedge Vendor Display',
        'qbo_company_name'  => 'Wedge Vendor Display',
        'last_synced_at'    => date('Y-m-d H:i:s'),
    ]);

    VendorMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_vendor_id, mapping_status, match_notes FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?", [999993]);
    if ($wedged === null) {
        $c13Errors[] = 'wedge row gone';
    } else {
        if ($wedged['qbo_vendor_id'] !== 'TEST-SMOKE-V-WEDGE13') {
            $c13Errors[] = "expected absorbed qbo_vendor_id, got '" . ($wedged['qbo_vendor_id'] ?? 'NULL') . "'";
        }
        if ($wedged['mapping_status'] !== 'mapped') {
            $c13Errors[] = "expected mapped, got '" . $wedged['mapping_status'] . "'";
        }
        if (!str_contains((string) $wedged['match_notes'], 'wedge_recovery')) {
            $c13Errors[] = "expected wedge_recovery in notes";
        }
    }
    $candidate = db_row("SELECT id FROM acc_qbo_vendor_map WHERE qbo_vendor_id = ? AND mapping_status = 'qbo_only'", ['TEST-SMOKE-V-WEDGE13']);
    if ($candidate !== null) {
        $c13Errors[] = "expected qbo_only candidate deleted";
    }
} catch (Throwable $e) {
    $c13Errors[] = 'C13 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE qbo_vendor_id = ? OR ff_vendor_id = ?", ['TEST-SMOKE-V-WEDGE13', 999993]);
    db_execute("DELETE FROM vendors WHERE id = 999993");
}
if (empty($c13Errors)) {
    echo "PASS C13 VendorMatcher rescueHalfStateRows absorbs wedge by qbo_display_name (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C13 " . implode('; ', $c13Errors) . "\n";
    $failures[] = 'C13';
}

// ── C14: S-QBO-MATCHER-WEDGE-RECOVERY — no-op when no match ──
$c14Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE ff_vendor_id = 999994");
    db_execute("DELETE FROM vendors WHERE id = 999994");
    db_execute("INSERT INTO vendors (id, name, vendor_type) VALUES (999994, ?, 'other')", ['SMOKE C14 Lone']);
    db_insert('acc_qbo_vendor_map', [
        'ff_vendor_id'      => 999994,
        'qbo_vendor_id'     => null,
        'mapping_status'    => 'ff_only',
        'qbo_display_name'  => 'Lone Vendor Wedge ' . substr((string) time(), -6),
        'last_synced_at'    => date('Y-m-d H:i:s'),
    ]);

    VendorMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_vendor_id, mapping_status FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?", [999994]);
    if ($wedged === null) {
        $c14Errors[] = 'wedge row gone (false positive)';
    } elseif ($wedged['qbo_vendor_id'] !== null) {
        $c14Errors[] = "no-op expected, got qbo_vendor_id='" . $wedged['qbo_vendor_id'] . "'";
    }
} catch (Throwable $e) {
    $c14Errors[] = 'C14 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_vendor_map WHERE ff_vendor_id = 999994");
    db_execute("DELETE FROM vendors WHERE id = 999994");
}
if (empty($c14Errors)) {
    echo "PASS C14 VendorMatcher rescueHalfStateRows no-ops when no match (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C14 " . implode('; ', $c14Errors) . "\n";
    $failures[] = 'C14';
}

} finally {
    // ── Self-cleaning ──────────────────────────────────────────
    if (!empty($sentinelMappingIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelMappingIds), '?'));
            db_execute("DELETE FROM acc_qbo_vendor_map WHERE id IN ({$ph})", $sentinelMappingIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel mapping rows failed: " . $e->getMessage() . "\n";
        }
    }
    if (!empty($sentinelQboIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelQboIds), '?'));
            db_execute("DELETE FROM acc_qbo_vendor_map WHERE qbo_vendor_id IN ({$ph})", $sentinelQboIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel qbo rows failed: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nqbo_vendor_mapping_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\n";
exit(0);
