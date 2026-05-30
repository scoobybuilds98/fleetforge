<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_tax_code_mapping.php
 *
 * S-QBO-9 — Structural + behavioural smoke for the tax code mapping
 * flow. Runs OFFLINE: no Intuit HTTP traffic. The Puller's pullAll()
 * is exercised via mock JSON through normalize(); the live HTTP
 * round-trip is covered by operator-side post-commit verification.
 *
 * Self-cleaning: synthetic rows use sentinel ids/qbo_ids ('TEST-SMOKE-T-*')
 * + the finally block scrubs them on pass or fail. settings is
 * snapshotted at start and restored at end.
 *
 * 14 sub-checks:
 *   C1   acc_qbo_tax_code_map table shape — expected columns +
 *        indexes (uq_ff_tax_rate / uq_qbo_tax_code / uq_override_target) + FK
 *   C2   TaxCodePuller class + pullAll + normalize public static
 *   C3   TaxCodeMatcher class + normalizeName + findBestMatch +
 *        matchAll + identifyOverrideTarget + rateSum public static
 *   C4   identifyOverrideTarget returns 'NON' entry (case-insensitive
 *        — also tests 'non' and 'Non' variants)
 *   C5   identifyOverrideTarget returns null when no NON in list
 *   C6   findBestMatch returns 'exact_name' on case-insensitive
 *        name equality (FF 'GST' matches QBO 'gst')
 *   C7   findBestMatch returns null when no signal aligns
 *   C8   normalizeName collapses casing + punctuation + whitespace
 *   C9   uq_override_target UNIQUE-on-NULLABLE behavior verified
 *        (D-QBO-9-2 enforcement — multiple NULLs allowed, single 1
 *        enforced)
 *  C10   set_override_target logic — transactional clear-then-set
 *        flips the flag correctly without UNIQUE violation
 *  C11   4 API endpoint files exist + php -l clean
 *  C12   tax_codes.php page exists, lints, Alpine factory + view gate
 *  C13   Nav config has 9 QuickBooks children incl. Tax Codes
 *  C14   TaxCodePuller::normalize maps representative QBO TaxCode
 *        JSON to expected flat shape (offline; no HTTP)
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-9
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §7.2 (tax code mapping table)
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\TaxCodePuller;
use FleetForge\QboPushers\TaxCodeMatcher;

$failures = [];
$pass     = 0;
$total    = 16;

/** Sentinel ids we'll clean up. */
$sentinelMappingIds = [];
$sentinelQboIds     = [];

/** Snapshot the override-target setting + any current is_override_target row. */
$origOverrideSetting = settings_get('quickbooks.tax_override_code_id', null);
$origOverrideRow = db_row(
    "SELECT id, qbo_tax_code_id FROM acc_qbo_tax_code_map WHERE is_override_target = 1"
);

try {

// ── C1: table shape ─────────────────────────────────────────
$expectedCols = [
    'id', 'ff_tax_rate_id', 'qbo_tax_code_id', 'qbo_sync_token',
    'qbo_name', 'qbo_description', 'qbo_taxable', 'qbo_hidden',
    'qbo_active', 'qbo_tax_group', 'qbo_sales_rate_refs',
    'ff_rate_snapshot', 'ff_province', 'mapping_status',
    'match_confidence', 'is_override_target', 'match_notes',
    'last_synced_at', 'last_pull_at', 'created_at', 'updated_at',
    'created_by_user_id',
];
$c1Errors = [];
try {
    $rows = db_select("SHOW COLUMNS FROM acc_qbo_tax_code_map");
    $present = array_map(fn($r) => $r['Field'], $rows);
    foreach ($expectedCols as $col) {
        if (!in_array($col, $present, true)) {
            $c1Errors[] = "missing column: {$col}";
        }
    }
    $idx = db_select("SHOW INDEX FROM acc_qbo_tax_code_map");
    $idxNames = array_unique(array_map(fn($r) => $r['Key_name'], $idx));
    foreach (['uq_ff_tax_rate', 'uq_qbo_tax_code', 'uq_override_target'] as $i) {
        if (!in_array($i, $idxNames, true)) {
            $c1Errors[] = "missing index: {$i}";
        }
    }
    // Verify is_override_target is NULLABLE (the D-QBO-9-2 enforcement
    // technique depends on this).
    foreach ($rows as $r) {
        if ($r['Field'] === 'is_override_target' && $r['Null'] !== 'YES') {
            $c1Errors[] = "is_override_target must be NULLABLE for UNIQUE-on-nullable enforcement; got Null='{$r['Null']}'";
        }
    }
} catch (Throwable $e) {
    $c1Errors[] = 'SHOW COLUMNS/INDEX threw: ' . $e->getMessage();
}
if (empty($c1Errors)) {
    echo "PASS C1  acc_qbo_tax_code_map has all 22 columns + uq_ff/uq_qbo/uq_override_target + is_override_target NULLABLE\n";
    $pass++;
} else {
    echo "FAIL C1  " . implode('; ', $c1Errors) . "\n";
    $failures[] = 'C1';
}

// ── C2: TaxCodePuller surface ──────────────────────────────
$c2Errors = [];
if (!class_exists(TaxCodePuller::class)) {
    $c2Errors[] = 'TaxCodePuller class not autoloaded';
} else {
    $ref = new ReflectionClass(TaxCodePuller::class);
    foreach (['pullAll', 'normalize'] as $m) {
        if (!$ref->hasMethod($m)) { $c2Errors[] = "missing method: {$m}"; continue; }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) { $c2Errors[] = "{$m} must be public static"; }
    }
}
if (empty($c2Errors)) {
    echo "PASS C2  TaxCodePuller class surface (pullAll + normalize public static)\n";
    $pass++;
} else {
    echo "FAIL C2  " . implode('; ', $c2Errors) . "\n";
    $failures[] = 'C2';
}

// ── C3: TaxCodeMatcher surface ─────────────────────────────
$c3Errors = [];
if (!class_exists(TaxCodeMatcher::class)) {
    $c3Errors[] = 'TaxCodeMatcher class not autoloaded';
} else {
    $ref = new ReflectionClass(TaxCodeMatcher::class);
    foreach (['normalizeName', 'findBestMatch', 'matchAll', 'identifyOverrideTarget', 'rateSum'] as $m) {
        if (!$ref->hasMethod($m)) { $c3Errors[] = "missing method: {$m}"; continue; }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) { $c3Errors[] = "{$m} must be public static"; }
    }
}
if (empty($c3Errors)) {
    echo "PASS C3  TaxCodeMatcher class surface (normalizeName + findBestMatch + matchAll + identifyOverrideTarget + rateSum public static)\n";
    $pass++;
} else {
    echo "FAIL C3  " . implode('; ', $c3Errors) . "\n";
    $failures[] = 'C3';
}

// ── C4: identifyOverrideTarget returns 'NON' (case-insensitive) ──
$c4Errors = [];
try {
    $variants = [
        ['UPPER', [['qbo_id'=>'1','name'=>'GST'], ['qbo_id'=>'2','name'=>'NON'], ['qbo_id'=>'3','name'=>'HST ON']]],
        ['lower', [['qbo_id'=>'1','name'=>'GST'], ['qbo_id'=>'2','name'=>'non'], ['qbo_id'=>'3','name'=>'HST ON']]],
        ['Mixed', [['qbo_id'=>'1','name'=>'GST'], ['qbo_id'=>'2','name'=>'Non'], ['qbo_id'=>'3','name'=>'HST ON']]],
        ['Whitespace-padded', [['qbo_id'=>'1','name'=>'  NON  ']]],
    ];
    foreach ($variants as [$label, $input]) {
        $r = TaxCodeMatcher::identifyOverrideTarget($input);
        if ($r === null) {
            $c4Errors[] = "variant '{$label}' returned null; expected match";
            continue;
        }
        if ((string) $r['qbo_id'] !== ($input[count($input) > 1 ? 1 : 0]['qbo_id'] ?? '')) {
            // For multi-element variants, NON is at index 1; for single, index 0.
            $expectedId = count($input) > 1 ? '2' : '1';
            if ((string) $r['qbo_id'] !== $expectedId) {
                $c4Errors[] = "variant '{$label}' returned wrong qbo_id: " . json_encode($r);
            }
        }
    }
} catch (Throwable $e) {
    $c4Errors[] = 'identifyOverrideTarget threw: ' . $e->getMessage();
}
if (empty($c4Errors)) {
    echo "PASS C4  identifyOverrideTarget returns NON entry (case-insensitive + whitespace-tolerant)\n";
    $pass++;
} else {
    echo "FAIL C4  " . implode('; ', $c4Errors) . "\n";
    $failures[] = 'C4';
}

// ── C5: identifyOverrideTarget returns null when no NON ────
$c5Errors = [];
try {
    $r = TaxCodeMatcher::identifyOverrideTarget([
        ['qbo_id'=>'1','name'=>'GST'],
        ['qbo_id'=>'2','name'=>'HST ON'],
        ['qbo_id'=>'3','name'=>'PST BC'],
    ]);
    if ($r !== null) {
        $c5Errors[] = 'expected null (no NON in list), got ' . json_encode($r);
    }
} catch (Throwable $e) {
    $c5Errors[] = 'identifyOverrideTarget threw: ' . $e->getMessage();
}
if (empty($c5Errors)) {
    echo "PASS C5  identifyOverrideTarget returns null when no NON in list\n";
    $pass++;
} else {
    echo "FAIL C5  " . implode('; ', $c5Errors) . "\n";
    $failures[] = 'C5';
}

// ── C6: findBestMatch exact_name ───────────────────────────
$c6Errors = [];
try {
    $ff  = ['name' => 'GST', 'province' => 'AB'];
    $qbo = [
        ['qbo_id' => '1', 'name' => 'gst',    'description' => '', 'active' => true],
        ['qbo_id' => '2', 'name' => 'PST BC', 'description' => '', 'active' => true],
    ];
    $m = TaxCodeMatcher::findBestMatch($ff, $qbo);
    if ($m === null) {
        $c6Errors[] = 'expected match, got null';
    } elseif ($m['qbo_id'] !== '1' || $m['confidence'] !== 'exact_name') {
        $c6Errors[] = "expected qbo_id=1 confidence=exact_name, got " . json_encode($m);
    }
} catch (Throwable $e) {
    $c6Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c6Errors)) {
    echo "PASS C6  findBestMatch returns exact_name on case-insensitive name match\n";
    $pass++;
} else {
    echo "FAIL C6  " . implode('; ', $c6Errors) . "\n";
    $failures[] = 'C6';
}

// ── C7: findBestMatch null when no signal ──────────────────
$c7Errors = [];
try {
    $ff  = ['name' => 'Made up rate', 'province' => 'ZZ'];
    $qbo = [['qbo_id' => '1', 'name' => 'GST', 'description' => '', 'active' => true]];
    $m = TaxCodeMatcher::findBestMatch($ff, $qbo);
    if ($m !== null) {
        $c7Errors[] = 'expected null, got ' . json_encode($m);
    }
} catch (Throwable $e) {
    $c7Errors[] = 'findBestMatch threw: ' . $e->getMessage();
}
if (empty($c7Errors)) {
    echo "PASS C7  findBestMatch returns null when no signal aligns\n";
    $pass++;
} else {
    echo "FAIL C7  " . implode('; ', $c7Errors) . "\n";
    $failures[] = 'C7';
}

// ── C8: normalizeName collapses ────────────────────────────
$c8Errors = [];
try {
    $n1 = TaxCodeMatcher::normalizeName('GST/HST');
    $n2 = TaxCodeMatcher::normalizeName('gst hst');
    $n3 = TaxCodeMatcher::normalizeName('  GST   HST  ');
    if ($n1 !== $n2 || $n2 !== $n3) {
        $c8Errors[] = "normalizations diverged: '{$n1}' / '{$n2}' / '{$n3}'";
    }
    if ($n1 !== 'gst hst') {
        $c8Errors[] = "expected 'gst hst', got '{$n1}'";
    }
} catch (Throwable $e) {
    $c8Errors[] = 'normalizeName threw: ' . $e->getMessage();
}
if (empty($c8Errors)) {
    echo "PASS C8  normalizeName collapses 'GST/HST' = 'gst hst' = '  GST   HST  '\n";
    $pass++;
} else {
    echo "FAIL C8  " . implode('; ', $c8Errors) . "\n";
    $failures[] = 'C8';
}

// ── C9: uq_override_target UNIQUE-on-NULLABLE behavior ─────
// D-QBO-9-2: must allow multiple NULLs, must enforce single '1'.
$c9Errors = [];
try {
    // Clear any prior override target (snapshot restored in finally).
    db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = NULL WHERE is_override_target = 1");

    $sentA = 'TEST-SMOKE-T-' . bin2hex(random_bytes(6));
    $sentB = 'TEST-SMOKE-T-' . bin2hex(random_bytes(6));
    $sentC = 'TEST-SMOKE-T-' . bin2hex(random_bytes(6));
    $sentinelQboIds[] = $sentA;
    $sentinelQboIds[] = $sentB;
    $sentinelQboIds[] = $sentC;

    // Two NULL inserts (no override target) — both should succeed.
    $idA = db_insert('acc_qbo_tax_code_map', [
        'qbo_tax_code_id'     => $sentA,
        'mapping_status'      => 'qbo_only',
        'is_override_target'  => null,
    ]);
    $sentinelMappingIds[] = $idA;
    $idB = db_insert('acc_qbo_tax_code_map', [
        'qbo_tax_code_id'     => $sentB,
        'mapping_status'      => 'qbo_only',
        'is_override_target'  => null,
    ]);
    $sentinelMappingIds[] = $idB;

    // One 1 insert — should succeed.
    $idC = db_insert('acc_qbo_tax_code_map', [
        'qbo_tax_code_id'     => $sentC,
        'mapping_status'      => 'qbo_only',
        'is_override_target'  => 1,
    ]);
    $sentinelMappingIds[] = $idC;

    // Attempt to flip A to 1 while C is also 1 — should FAIL (UNIQUE violation).
    $duplicateRejected = false;
    try {
        db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = 1 WHERE id = ?", [$idA]);
    } catch (Throwable $e) {
        $duplicateRejected = true;
    }
    if (!$duplicateRejected) {
        $c9Errors[] = "UPDATE to 1 succeeded while another row has is_override_target=1 — UNIQUE constraint broken";
    }

    // Reset C to NULL, then UPDATE A to 1 — should succeed.
    db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = NULL WHERE id = ?", [$idC]);
    db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = 1 WHERE id = ?", [$idA]);
    $verifyA = db_row("SELECT is_override_target FROM acc_qbo_tax_code_map WHERE id = ?", [$idA]);
    if ((int) $verifyA['is_override_target'] !== 1) {
        $c9Errors[] = "after clear+set, A should be 1; got " . ($verifyA['is_override_target'] ?? 'NULL');
    }
} catch (Throwable $e) {
    $c9Errors[] = 'C9 setup threw: ' . $e->getMessage();
}
if (empty($c9Errors)) {
    echo "PASS C9  uq_override_target enforces single-1 (multiple NULLs allowed, second '1' rejected)\n";
    $pass++;
} else {
    echo "FAIL C9  " . implode('; ', $c9Errors) . "\n";
    $failures[] = 'C9';
}

// ── C10: set_override_target transactional clear-then-set ──
// Mirror the save_mapping.php set_override_target action body.
$c10Errors = [];
try {
    // Setup: two sentinel qbo_only rows, one with is_override_target=1.
    db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = NULL WHERE is_override_target = 1");
    $sentD = 'TEST-SMOKE-T-' . bin2hex(random_bytes(6));
    $sentE = 'TEST-SMOKE-T-' . bin2hex(random_bytes(6));
    $sentinelQboIds[] = $sentD;
    $sentinelQboIds[] = $sentE;
    $idD = db_insert('acc_qbo_tax_code_map', [
        'qbo_tax_code_id'    => $sentD,
        'qbo_name'           => 'SMOKE D — old target',
        'mapping_status'     => 'qbo_only',
        'is_override_target' => 1,
    ]);
    $sentinelMappingIds[] = $idD;
    $idE = db_insert('acc_qbo_tax_code_map', [
        'qbo_tax_code_id'    => $sentE,
        'qbo_name'           => 'SMOKE E — new target',
        'mapping_status'     => 'qbo_only',
        'is_override_target' => null,
    ]);
    $sentinelMappingIds[] = $idE;

    // Perform the transactional flip — exactly like save_mapping.php does.
    db_transaction(function () use ($idE) {
        db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = NULL WHERE is_override_target = 1");
        db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = 1 WHERE id = ?", [$idE]);
    });

    // Verify D is now NULL, E is 1.
    $checkD = db_row("SELECT is_override_target FROM acc_qbo_tax_code_map WHERE id = ?", [$idD]);
    $checkE = db_row("SELECT is_override_target FROM acc_qbo_tax_code_map WHERE id = ?", [$idE]);
    if ($checkD['is_override_target'] !== null) {
        $c10Errors[] = "old target D should be NULL after flip; got " . ($checkD['is_override_target'] ?? 'NULL');
    }
    if ((int) $checkE['is_override_target'] !== 1) {
        $c10Errors[] = "new target E should be 1 after flip; got " . ($checkE['is_override_target'] ?? 'NULL');
    }
    // Verify UNIQUE invariant still holds (exactly one row with =1).
    $count = (int) db_count("SELECT COUNT(*) FROM acc_qbo_tax_code_map WHERE is_override_target = 1", []);
    if ($count !== 1) {
        $c10Errors[] = "expected exactly 1 row with is_override_target=1, got {$count}";
    }
} catch (Throwable $e) {
    $c10Errors[] = 'C10 setup threw: ' . $e->getMessage();
}
if (empty($c10Errors)) {
    echo "PASS C10 set_override_target transactional clear-then-set flips flag correctly + UNIQUE invariant preserved\n";
    $pass++;
} else {
    echo "FAIL C10 " . implode('; ', $c10Errors) . "\n";
    $failures[] = 'C10';
}

// ── C11: 4 API endpoint files exist + lint clean ───────────
$c11Errors = [];
$endpoints = [
    'api/v1/quickbooks/tax_codes/pull.php',
    'api/v1/quickbooks/tax_codes/auto_match.php',
    'api/v1/quickbooks/tax_codes/save_mapping.php',
    'api/v1/quickbooks/tax_codes/list.php',
];
foreach ($endpoints as $rel) {
    $abs = realpath(__DIR__ . '/../' . $rel);
    if ($abs === false || !is_readable($abs)) {
        $c11Errors[] = "endpoint missing: {$rel}";
        continue;
    }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($abs) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c11Errors[] = "lint failed: {$rel} — " . implode('; ', $out);
    }
}
if (empty($c11Errors)) {
    echo "PASS C11 4 API endpoints exist + php -l clean\n";
    $pass++;
} else {
    echo "FAIL C11 " . implode('; ', $c11Errors) . "\n";
    $failures[] = 'C11';
}

// ── C12: tax_codes.php page exists + lints + Alpine factory ─
$c12Errors = [];
$pagePath = realpath(__DIR__ . '/../app/admin/quickbooks/tax_codes.php');
if ($pagePath === false || !is_readable($pagePath)) {
    $c12Errors[] = 'app/admin/quickbooks/tax_codes.php missing or unreadable';
} else {
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($pagePath) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c12Errors[] = 'tax_codes.php lint failed: ' . implode('; ', $out);
    }
    $src = file_get_contents($pagePath);
    if (!str_contains($src, "qboTaxCodeMapping()")) {
        $c12Errors[] = 'tax_codes.php does not define qboTaxCodeMapping() Alpine factory';
    }
    if (!preg_match("/require_permission\(\s*'quickbooks'\s*,\s*'view'\s*\)/", $src)) {
        $c12Errors[] = "tax_codes.php missing require_permission('quickbooks','view') gate";
    }
}
if (empty($c12Errors)) {
    echo "PASS C12 tax_codes.php page exists, lints, declares Alpine factory + view gate\n";
    $pass++;
} else {
    echo "FAIL C12 " . implode('; ', $c12Errors) . "\n";
    $failures[] = 'C12';
}

// ── C13: nav has 9 QuickBooks children incl. Tax Codes ─────
$c13Errors = [];
$nav = require __DIR__ . '/../config/navigation.php';
$qbo = null;
foreach ($nav as $group) {
    if (($group['label'] ?? '') === 'QuickBooks' && !empty($group['children'] ?? [])) {
        $qbo = $group;
        break;
    }
}
if ($qbo === null) {
    $c13Errors[] = 'QuickBooks nav group not found';
} else {
    $children = $qbo['children'] ?? [];
    $labels = array_map(fn($c) => $c['label'] ?? '', $children);
    if (count($children) !== 17) {
        $c13Errors[] = 'expected 17 QuickBooks children, got ' . count($children) . ' (' . implode(', ', $labels) . ')';
    }
    if (!in_array('Tax Codes', $labels, true)) {
        $c13Errors[] = "no 'Tax Codes' child in QuickBooks nav";
    }
    $expectedOrder = ['Dashboard', 'Sync Queue', 'Sync Log', 'Drift', 'Manual Sync', 'Customers', 'Vendors', 'Accounts', 'Tax Codes', 'Items', 'Invoices', 'Credit Memos', 'Bills', 'Bill Payments', 'Payments', 'Journal Entries', 'Settings'];
    if ($labels !== $expectedOrder) {
        $c13Errors[] = 'nav order mismatch — got [' . implode(', ', $labels) . '], expected [' . implode(', ', $expectedOrder) . ']';
    }
}
if (empty($c13Errors)) {
    echo "PASS C13 nav has 16 QuickBooks children with Tax Codes in expected position\n";
    $pass++;
} else {
    echo "FAIL C13 " . implode('; ', $c13Errors) . "\n";
    $failures[] = 'C13';
}

// ── C14: TaxCodePuller::normalize maps representative JSON ──
$c14Errors = [];
try {
    $sample = [
        'Id'            => '5',
        'SyncToken'     => '2',
        'Name'          => 'NON',
        'Description'   => 'Non-Taxable (used for tax overrides)',
        'Taxable'       => false,
        'Hidden'        => false,
        'Active'        => true,
        'TaxGroup'      => false,
        'SalesTaxRateList' => [
            'TaxRateDetail' => [
                'TaxRateRef'    => ['value' => '12'],
                'TaxTypeApplicable' => 'TaxOnAmount',
                'TaxOrder'      => 0,
            ],
        ],
        'MetaData' => ['LastUpdatedTime' => '2026-05-21T14:00:00-07:00'],
    ];
    $n = TaxCodePuller::normalize($sample);
    $expected = [
        'qbo_id'           => '5',
        'sync_token'       => '2',
        'name'             => 'NON',
        'description'      => 'Non-Taxable (used for tax overrides)',
        'taxable'          => false,
        'hidden'           => false,
        'active'           => true,
        'tax_group'        => false,
        'last_updated_qbo' => '2026-05-21T14:00:00-07:00',
    ];
    foreach ($expected as $k => $v) {
        if (!array_key_exists($k, $n)) {
            $c14Errors[] = "missing key in normalize output: {$k}";
            continue;
        }
        if ($n[$k] !== $v) {
            $c14Errors[] = "{$k} mismatch: got " . var_export($n[$k], true) . ", want " . var_export($v, true);
        }
    }
    // Sales rate refs: single-detail object should be wrapped to 1-element array.
    if (!is_array($n['sales_rate_refs']) || count($n['sales_rate_refs']) !== 1) {
        $c14Errors[] = "sales_rate_refs should be 1-element array (defensive wrap of single-detail object); got " . var_export($n['sales_rate_refs'], true);
    }
} catch (Throwable $e) {
    $c14Errors[] = 'normalize threw: ' . $e->getMessage();
}
if (empty($c14Errors)) {
    echo "PASS C14 TaxCodePuller::normalize maps QBO TaxCode JSON to expected flat shape (incl. single-detail wrap)\n";
    $pass++;
} else {
    echo "FAIL C14 " . implode('; ', $c14Errors) . "\n";
    $failures[] = 'C14';
}

// ── C15: S-QBO-MATCHER-WEDGE-RECOVERY — tax code wedge rescue (by qbo_name) ──
$c15Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id = ? OR ff_tax_rate_id = ?", ['TEST-SMOKE-T-WEDGE15', 999993]);
    db_execute("DELETE FROM tax_rates WHERE id = 999993");
    db_execute("INSERT INTO tax_rates (id, name, gst_rate, pst_rate, hst_rate, effective_from, is_active) VALUES (999993, ?, 0, 0, 0, '2026-01-01', 1)", ['SMOKE C15 Wedge Rate']);

    db_insert('acc_qbo_tax_code_map', [
        'ff_tax_rate_id'   => 999993,
        'qbo_tax_code_id'  => null,
        'mapping_status'   => 'ff_only',
        'qbo_name'         => 'Wedge Tax Code',
        'last_synced_at'   => date('Y-m-d H:i:s'),
    ]);
    db_insert('acc_qbo_tax_code_map', [
        'ff_tax_rate_id'   => null,
        'qbo_tax_code_id'  => 'TEST-SMOKE-T-WEDGE15',
        'qbo_sync_token'   => '0',
        'mapping_status'   => 'qbo_only',
        'qbo_name'         => 'Wedge Tax Code',
        'last_synced_at'   => date('Y-m-d H:i:s'),
    ]);

    TaxCodeMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_tax_code_id, mapping_status, match_notes FROM acc_qbo_tax_code_map WHERE ff_tax_rate_id = ?", [999993]);
    if ($wedged === null) {
        $c15Errors[] = 'wedge row gone';
    } else {
        if ($wedged['qbo_tax_code_id'] !== 'TEST-SMOKE-T-WEDGE15') {
            $c15Errors[] = "expected absorbed qbo_tax_code_id, got '" . ($wedged['qbo_tax_code_id'] ?? 'NULL') . "'";
        }
        if ($wedged['mapping_status'] !== 'mapped') {
            $c15Errors[] = "expected mapped, got '" . $wedged['mapping_status'] . "'";
        }
        if (!str_contains((string) $wedged['match_notes'], 'wedge_recovery')) {
            $c15Errors[] = "expected wedge_recovery in notes";
        }
    }
    $candidate = db_row("SELECT id FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id = ? AND mapping_status = 'qbo_only'", ['TEST-SMOKE-T-WEDGE15']);
    if ($candidate !== null) {
        $c15Errors[] = "expected qbo_only candidate deleted";
    }
} catch (Throwable $e) {
    $c15Errors[] = 'C15 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id = ? OR ff_tax_rate_id = ?", ['TEST-SMOKE-T-WEDGE15', 999993]);
    db_execute("DELETE FROM tax_rates WHERE id = 999993");
}
if (empty($c15Errors)) {
    echo "PASS C15 TaxCodeMatcher rescueHalfStateRows absorbs wedge by qbo_name (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C15 " . implode('; ', $c15Errors) . "\n";
    $failures[] = 'C15';
}

// ── C16: S-QBO-MATCHER-WEDGE-RECOVERY — tax code no-op when no match ──
$c16Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_tax_code_map WHERE ff_tax_rate_id = 999994");
    db_execute("DELETE FROM tax_rates WHERE id = 999994");
    db_execute("INSERT INTO tax_rates (id, name, gst_rate, pst_rate, hst_rate, effective_from, is_active) VALUES (999994, ?, 0, 0, 0, '2026-01-01', 1)", ['SMOKE C16 Lone']);
    db_insert('acc_qbo_tax_code_map', [
        'ff_tax_rate_id'   => 999994,
        'qbo_tax_code_id'  => null,
        'mapping_status'   => 'ff_only',
        'qbo_name'         => 'Lone Tax Wedge ' . substr((string) time(), -6),
        'last_synced_at'   => date('Y-m-d H:i:s'),
    ]);

    TaxCodeMatcher::matchAll([]);

    $wedged = db_row("SELECT qbo_tax_code_id, mapping_status FROM acc_qbo_tax_code_map WHERE ff_tax_rate_id = ?", [999994]);
    if ($wedged === null) {
        $c16Errors[] = 'wedge row gone (false positive)';
    } elseif ($wedged['qbo_tax_code_id'] !== null) {
        $c16Errors[] = "no-op expected, got qbo_tax_code_id='" . $wedged['qbo_tax_code_id'] . "'";
    }
} catch (Throwable $e) {
    $c16Errors[] = 'C16 threw: ' . $e->getMessage();
} finally {
    db_execute("DELETE FROM acc_qbo_tax_code_map WHERE ff_tax_rate_id = 999994");
    db_execute("DELETE FROM tax_rates WHERE id = 999994");
}
if (empty($c16Errors)) {
    echo "PASS C16 TaxCodeMatcher rescueHalfStateRows no-ops when no match (S-QBO-MATCHER-WEDGE-RECOVERY)\n";
    $pass++;
} else {
    echo "FAIL C16 " . implode('; ', $c16Errors) . "\n";
    $failures[] = 'C16';
}

} finally {
    // ── Self-cleaning ──────────────────────────────────────────
    // Delete all sentinel mapping rows. Use explicit IDs + qbo_id
    // prefix filter for defense in depth.
    if (!empty($sentinelMappingIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelMappingIds), '?'));
            db_execute("DELETE FROM acc_qbo_tax_code_map WHERE id IN ({$ph})", $sentinelMappingIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel mapping rows failed: " . $e->getMessage() . "\n";
        }
    }
    try {
        db_execute("DELETE FROM acc_qbo_tax_code_map WHERE qbo_tax_code_id LIKE 'TEST-SMOKE-T-%'");
    } catch (Throwable $e) {
        echo "WARN  defensive sentinel cleanup failed: " . $e->getMessage() . "\n";
    }

    // Restore original override-target state (the C9/C10 tests
    // temporarily cleared it).
    try {
        db_execute("UPDATE acc_qbo_tax_code_map SET is_override_target = NULL WHERE is_override_target = 1");
        if ($origOverrideRow !== null) {
            db_execute(
                "UPDATE acc_qbo_tax_code_map SET is_override_target = 1 WHERE id = ?",
                [(int) $origOverrideRow['id']]
            );
        }
    } catch (Throwable $e) {
        echo "WARN  restore override-target row failed: " . $e->getMessage() . "\n";
    }

    // Restore settings.quickbooks.tax_override_code_id.
    try {
        if ($origOverrideSetting === null) {
            db_execute("DELETE FROM settings WHERE `key` = 'quickbooks.tax_override_code_id'");
        } else {
            db_execute(
                "INSERT INTO settings (`key`, `value`, is_public, is_sensitive)
                 VALUES ('quickbooks.tax_override_code_id', ?, 0, 0)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [(string) $origOverrideSetting]
            );
        }
    } catch (Throwable $e) {
        echo "WARN  restore settings.quickbooks.tax_override_code_id failed: " . $e->getMessage() . "\n";
    }
}

echo "\nqbo_tax_code_mapping_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\n";
exit(0);
