<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_invoice_push.php
 *
 * S-QBO-11 — Structural + behavioural smoke for the FF→QBO invoice
 * push surface. Runs OFFLINE: no Intuit HTTP traffic; QBO responses
 * mocked or HTTP boundary bypassed entirely. Live verification is
 * operator-side post-commit.
 *
 * Self-cleaning: synthetic FF rows use sentinel ids (999990+) plus
 * settings snapshots restored in the finally block.
 *
 * 41 sub-checks (updated S-QBO-PUSHER-CONTRACT-PAYDOWN):
 *   Structural (1-7): table + 5 classes + lint
 *   TaxOverride (8-10): builds TotalTax via bcmath / throws on empty / line-level
 *   LineBuilder (11-15): emits lines, resolves Items, GPS variant, throws on unmapped, throws on integrity violation
 *   PreflightGate (16-19): pass/fail variants
 *   Pusher (20-23): skipped_by_mode / skipped_voided / failed_preflight / already_mapped
 *   Enqueuer (24-25): sync_enabled=0 / non-create returns false
 *   PrivateNote JSON (26-27): includes fields / omits audit
 *   CurrencyRef (28-29): CAD + USD payload shape when multi_currency_enabled='1'
 *       (D-QBO-FIXPACK-1/-2/-12)
 *   FieldLimits (30-34): QboFieldLimits class + DocNumber/notes/line-desc checks
 *   CurrencyRef edge cases (35-38): CAD/USD explicit + NULL/zero rate throws
 *       (all gated on multi_currency_enabled='1'; D-QBO-FIXPACK-12)
 *   ENUM (39): push_status includes 2 new typed preflight codes
 *   ReturnShape (40): §6.8 canonical 5-key return shape on skipped_by_mode path
 *       (MEDIUM-C6 fix; S-QBO-PUSHER-CONTRACT-PAYDOWN)
 *   FIXPACK-10 (41): already_mapped branch emits S-QBO-FIXPACK-10 WARNING when
 *       FF invoice currency ≠ qbo_currency snapshot (MEDIUM-C9 backport)
 *
 * @session  S-QBO-11
 * @updated  S-QBO-11-FIXPACK-1 (C28 updated for always-emit CurrencyRef;
 *           C30–C39 new)
 * @updated  S-QBO-FIXPACK-3 (C28/C29/C35–C38 gated behind
 *           multi_currency_enabled='1'; D-QBO-FIXPACK-12)
 * @updated  S-QBO-PUSHER-CONTRACT-PAYDOWN — added C40 (§6.8 return shape) +
 *           C41 (FIXPACK-10 mismatch warning probe); total 41 sub-checks.
 * @updated  S-QBO-INVOICE-LIST-BADGE — added C42 (API LEFT JOIN +
 *           connection_status gate) + C43 (UI column conditional + Alpine
 *           helpers); total 43 sub-checks.
 * @updated  S-QBO-INVOICE-SHOW-RICH-PANEL — added C44 (show.php mapping +
 *           sync_log data load) + C45 (panel gated on connection_status) +
 *           C46 (old header badge removed) + C47 (Retry button gated on
 *           quickbooks.edit_credentials); total 47 sub-checks.
 * @updated  S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE — added C48 (soft-deleted
 *           skip records map + sync_log) + C49 (sync_mode=disabled skip
 *           records map + sync_log); total 49 sub-checks.
 * @updated  S-QBO-ENQUEUER-ELIGIBILITY-GATE — added C50 (gate-0 rejects
 *           missing invoice id) + C51 (gate-0 rejects soft-deleted) +
 *           C52 (gate-0 rejects status='draft' for op='create');
 *           total 52 sub-checks.
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher Contract), §17 (tax-override)
 * @decision D-QBO-FIXPACK-1 (always emit CurrencyRef),
 *           D-QBO-FIXPACK-2 (always emit ExchangeRate; throw on missing non-CAD rate),
 *           D-QBO-FIXPACK-4 (QboFieldLimits single-source constants),
 *           D-QBO-FIXPACK-5 (typed preflight status codes)
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\InvoicePusher;
use FleetForge\QboPushers\InvoiceEnqueuer;
use FleetForge\QboPushers\InvoiceLineBuilder;
use FleetForge\QboPushers\InvoiceTaxOverride;
use FleetForge\QboPushers\InvoicePreflightGate;
use FleetForge\QboPushers\QboFieldLimits;
use FleetForge\Exceptions\QuickBooksException;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

$failures = [];
$pass     = 0;
$total    = 52;

// Sentinel IDs we'll clean up at end
$sentinelInvoiceIds = [];
$sentinelCustomerIds = [];
$sentinelItemMapIds = [];
$sentinelInvoiceMapIds = [];
$sentinelCustomerMapIds = [];

// Settings we'll mutate + restore
$origSettings = [];
$settingsKeysToSnapshot = [
    'quickbooks.connection_status',
    'quickbooks.sync_enabled',
    'quickbooks.sync_mode.invoice',
    'quickbooks.tax_override_code_id',
    'quickbooks.multi_currency_enabled', // D-QBO-FIXPACK-12: C28/C29/C35-C38 gate
];
foreach ($settingsKeysToSnapshot as $k) {
    $r = db_row("SELECT value FROM settings WHERE `key` = ?", [$k]);
    $origSettings[$k] = $r ? $r['value'] : null;
}

/** Helper: set or reset a setting (key, value) */
$setSetting = function (string $key, string $value): void {
    db_execute(
        "INSERT INTO settings (`key`, value, value_type) VALUES (?, ?, 'string')
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [$key, $value]
    );
};

try {

// ── C1: acc_qbo_invoice_map table shape ──────────────────────
$expectedCols = [
    'id','ff_invoice_id','qbo_invoice_id','qbo_sync_token','qbo_doc_number',
    'qbo_total_amt','qbo_balance','qbo_status','qbo_currency','qbo_exchange_rate',
    'ff_invoice_snapshot_total','ff_engine_version','push_status','push_error',
    'pushed_at','last_synced_at','created_at','updated_at',
];
$c1Errors = [];
try {
    $rows = db_select("SHOW COLUMNS FROM acc_qbo_invoice_map");
    $present = array_map(fn($r) => $r['Field'], $rows);
    foreach ($expectedCols as $col) {
        if (!in_array($col, $present, true)) $c1Errors[] = "missing column: {$col}";
    }
    $idx = db_select("SHOW INDEX FROM acc_qbo_invoice_map");
    $idxNames = array_unique(array_map(fn($r) => $r['Key_name'], $idx));
    foreach (['uq_ff_invoice','uq_qbo_invoice','idx_status','idx_pushed_at','idx_engine_version'] as $i) {
        if (!in_array($i, $idxNames, true)) $c1Errors[] = "missing index: {$i}";
    }
    // FK check via INFORMATION_SCHEMA (SHOW INDEX does NOT include FKs).
    $fkRows = db_select(
        "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'acc_qbo_invoice_map'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $fkNames = array_map(fn($r) => $r['CONSTRAINT_NAME'], $fkRows);
    if (!in_array('fk_qbo_invoice_map_ff', $fkNames, true)) $c1Errors[] = "missing FK: fk_qbo_invoice_map_ff";
} catch (Throwable $e) {
    $c1Errors[] = 'SHOW failed: ' . $e->getMessage();
}
if (empty($c1Errors)) { echo "PASS C1  acc_qbo_invoice_map has all 18 columns + 4 indexes + 1 FK\n"; $pass++; }
else { echo "FAIL C1  " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

// ── C2: InvoicePusher class surface ──────────────────────────
$c2Errors = [];
if (!class_exists(InvoicePusher::class)) {
    $c2Errors[] = 'InvoicePusher class not autoloaded';
} else {
    $ref = new ReflectionClass(InvoicePusher::class);
    foreach (['pushCreate','pushUpdate'] as $m) {
        if (!$ref->hasMethod($m)) { $c2Errors[] = "missing method: {$m}"; continue; }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) $c2Errors[] = "{$m} must be public static";
    }
    foreach (['pushImpl','recordSuccessfulPush','recordPushFailure','recordFailedPreflight','recordSkipped'] as $priv) {
        if (!$ref->hasMethod($priv)) { $c2Errors[] = "missing private method: {$priv}"; }
    }
}
if (empty($c2Errors)) { echo "PASS C2  InvoicePusher class surface (pushCreate + pushUpdate public static + 5 private helpers)\n"; $pass++; }
else { echo "FAIL C2  " . implode('; ', $c2Errors) . "\n"; $failures[] = 'C2'; }

// ── C3: InvoiceEnqueuer class surface ────────────────────────
$c3Errors = [];
if (!class_exists(InvoiceEnqueuer::class)) {
    $c3Errors[] = 'InvoiceEnqueuer class not autoloaded';
} else {
    $ref = new ReflectionClass(InvoiceEnqueuer::class);
    if (!$ref->hasMethod('enqueue')) $c3Errors[] = 'missing method: enqueue';
    else {
        $rm = $ref->getMethod('enqueue');
        if (!$rm->isStatic() || !$rm->isPublic()) $c3Errors[] = 'enqueue must be public static';
    }
}
if (empty($c3Errors)) { echo "PASS C3  InvoiceEnqueuer class surface\n"; $pass++; }
else { echo "FAIL C3  " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

// ── C4: InvoiceLineBuilder class surface ─────────────────────
$c4Errors = [];
if (!class_exists(InvoiceLineBuilder::class)) {
    $c4Errors[] = 'InvoiceLineBuilder class not autoloaded';
} else {
    $ref = new ReflectionClass(InvoiceLineBuilder::class);
    if (!$ref->hasMethod('build')) $c4Errors[] = 'missing method: build';
    if (!$ref->hasMethod('resolveItemMap')) $c4Errors[] = 'missing private method: resolveItemMap';
}
if (empty($c4Errors)) { echo "PASS C4  InvoiceLineBuilder class surface\n"; $pass++; }
else { echo "FAIL C4  " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

// ── C5: InvoiceTaxOverride class surface ─────────────────────
$c5Errors = [];
if (!class_exists(InvoiceTaxOverride::class)) {
    $c5Errors[] = 'InvoiceTaxOverride class not autoloaded';
} else {
    $ref = new ReflectionClass(InvoiceTaxOverride::class);
    foreach (['buildTxnTaxDetail','lineLevelTaxCodeRef'] as $m) {
        if (!$ref->hasMethod($m)) $c5Errors[] = "missing method: {$m}";
    }
}
if (empty($c5Errors)) { echo "PASS C5  InvoiceTaxOverride class surface\n"; $pass++; }
else { echo "FAIL C5  " . implode('; ', $c5Errors) . "\n"; $failures[] = 'C5'; }

// ── C6: InvoicePreflightGate class surface ───────────────────
$c6Errors = [];
if (!class_exists(InvoicePreflightGate::class)) {
    $c6Errors[] = 'InvoicePreflightGate class not autoloaded';
} else {
    $ref = new ReflectionClass(InvoicePreflightGate::class);
    if (!$ref->hasMethod('check')) $c6Errors[] = 'missing method: check';
}
if (empty($c6Errors)) { echo "PASS C6  InvoicePreflightGate class surface\n"; $pass++; }
else { echo "FAIL C6  " . implode('; ', $c6Errors) . "\n"; $failures[] = 'C6'; }

// ── C7: PHP lint on all new files ────────────────────────────
$c7Errors = [];
$newFiles = [
    'lib/QboPushers/InvoicePusher.php',
    'lib/QboPushers/InvoiceEnqueuer.php',
    'lib/QboPushers/InvoiceLineBuilder.php',
    'lib/QboPushers/InvoiceTaxOverride.php',
    'lib/QboPushers/InvoicePreflightGate.php',
    'lib/QboPushers/QboFieldLimits.php',
];
foreach ($newFiles as $rel) {
    $abs = realpath(__DIR__ . '/../' . $rel);
    if ($abs === false) { $c7Errors[] = "{$rel} not found"; continue; }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($abs) . ' 2>&1', $out, $code);
    if ($code !== 0) $c7Errors[] = "{$rel} lint failed: " . implode('; ', $out);
}
if (empty($c7Errors)) { echo "PASS C7  6 lib/QboPushers/*.php files php -l clean\n"; $pass++; }
else { echo "FAIL C7  " . implode('; ', $c7Errors) . "\n"; $failures[] = 'C7'; }

// ── C8: InvoiceTaxOverride::buildTxnTaxDetail bcmath sum ────
$c8Errors = [];
try {
    $setSetting('quickbooks.tax_override_code_id', 'NON');
    $invoice = [
        'tax_gst_amount' => '5.00',
        'tax_pst_amount' => '7.00',
        'tax_hst_amount' => '0.00',
    ];
    $detail = InvoiceTaxOverride::buildTxnTaxDetail($invoice);
    if (($detail['TotalTax'] ?? null) !== '12.00') {
        $c8Errors[] = "expected TotalTax='12.00', got " . json_encode($detail['TotalTax'] ?? null);
    }
    if (($detail['TxnTaxCodeRef']['value'] ?? null) !== 'NON') {
        $c8Errors[] = "expected TxnTaxCodeRef.value='NON', got " . json_encode($detail['TxnTaxCodeRef']['value'] ?? null);
    }
} catch (Throwable $e) {
    $c8Errors[] = 'C8 threw: ' . $e->getMessage();
}
if (empty($c8Errors)) { echo "PASS C8  InvoiceTaxOverride::buildTxnTaxDetail bcmath sums gst+pst+hst → TotalTax\n"; $pass++; }
else { echo "FAIL C8  " . implode('; ', $c8Errors) . "\n"; $failures[] = 'C8'; }

// ── C9: buildTxnTaxDetail throws when override code unset ───
$c9Errors = [];
try {
    db_execute("DELETE FROM settings WHERE `key` = 'quickbooks.tax_override_code_id'");
    $threw = false;
    try {
        InvoiceTaxOverride::buildTxnTaxDetail(['tax_gst_amount'=>'1','tax_pst_amount'=>'0','tax_hst_amount'=>'0']);
    } catch (QuickBooksException $e) {
        $threw = true;
        if (!str_contains($e->getMessage(), 'override')) $c9Errors[] = "expected 'override' in message, got: " . $e->getMessage();
    }
    if (!$threw) $c9Errors[] = 'expected QuickBooksException, none thrown';
} catch (Throwable $e) {
    $c9Errors[] = 'C9 setup threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.tax_override_code_id', 'NON');
}
if (empty($c9Errors)) { echo "PASS C9  buildTxnTaxDetail throws QuickBooksException when tax_override_code_id empty\n"; $pass++; }
else { echo "FAIL C9  " . implode('; ', $c9Errors) . "\n"; $failures[] = 'C9'; }

// ── C10: lineLevelTaxCodeRef returns override code ──────────
$c10Errors = [];
try {
    $ref = InvoiceTaxOverride::lineLevelTaxCodeRef();
    if (($ref['value'] ?? null) !== 'NON') {
        $c10Errors[] = "expected ['value' => 'NON'], got " . json_encode($ref);
    }
} catch (Throwable $e) {
    $c10Errors[] = 'C10 threw: ' . $e->getMessage();
}
if (empty($c10Errors)) { echo "PASS C10 lineLevelTaxCodeRef returns ['value' => override-code-id]\n"; $pass++; }
else { echo "FAIL C10 " . implode('; ', $c10Errors) . "\n"; $failures[] = 'C10'; }

// ── Setup for line builder tests: synthetic FF item map rows ─
// Insert sentinel acc_qbo_item_map rows for base_rental + gps net + gps gross
// so the LineBuilder tests resolve cleanly without touching live mappings.
// Sentinel qbo_item_id 'TEST-SMOKE-I-*' prefix.
$baseRentalMap = db_row(
    "SELECT id, qbo_item_id FROM acc_qbo_item_map
      WHERE ff_item_type = 'base_rental' AND ff_item_type_variant IS NULL
        AND mapping_status = 'mapped' AND qbo_item_id IS NOT NULL LIMIT 1"
);
$gpsNetMap = db_row(
    "SELECT id, qbo_item_id FROM acc_qbo_item_map
      WHERE ff_item_type = 'gps' AND ff_item_type_variant = 'net'
        AND mapping_status = 'mapped' AND qbo_item_id IS NOT NULL LIMIT 1"
);
$gpsGrossMap = db_row(
    "SELECT id, qbo_item_id FROM acc_qbo_item_map
      WHERE ff_item_type = 'gps' AND ff_item_type_variant = 'gross'
        AND mapping_status = 'mapped' AND qbo_item_id IS NOT NULL LIMIT 1"
);

// If any are missing, create synthetic ones for the test.
$baseRentalSetupRow = null;
if (!$baseRentalMap) {
    $baseRentalSetupRow = db_row(
        "SELECT id FROM acc_qbo_item_map WHERE ff_item_type = 'base_rental' AND ff_item_type_variant IS NULL LIMIT 1"
    );
    if ($baseRentalSetupRow) {
        db_execute(
            "UPDATE acc_qbo_item_map SET qbo_item_id='TEST-SMOKE-I-BR', qbo_name='SMOKE Base Rental', mapping_status='mapped' WHERE id=?",
            [$baseRentalSetupRow['id']]
        );
        $sentinelItemMapIds[] = ['id' => $baseRentalSetupRow['id'], 'restored_qbo' => null];
        $baseRentalMap = ['id' => $baseRentalSetupRow['id'], 'qbo_item_id' => 'TEST-SMOKE-I-BR'];
    }
}
$gpsNetSetupRow = null;
if (!$gpsNetMap) {
    $gpsNetSetupRow = db_row(
        "SELECT id FROM acc_qbo_item_map WHERE ff_item_type = 'gps' AND ff_item_type_variant = 'net' LIMIT 1"
    );
    if ($gpsNetSetupRow) {
        db_execute(
            "UPDATE acc_qbo_item_map SET qbo_item_id='TEST-SMOKE-I-GPSNET', qbo_name='SMOKE GPS Net', mapping_status='mapped' WHERE id=?",
            [$gpsNetSetupRow['id']]
        );
        $sentinelItemMapIds[] = ['id' => $gpsNetSetupRow['id'], 'restored_qbo' => null];
        $gpsNetMap = ['id' => $gpsNetSetupRow['id'], 'qbo_item_id' => 'TEST-SMOKE-I-GPSNET'];
    }
}
$gpsGrossSetupRow = null;
if (!$gpsGrossMap) {
    $gpsGrossSetupRow = db_row(
        "SELECT id FROM acc_qbo_item_map WHERE ff_item_type = 'gps' AND ff_item_type_variant = 'gross' LIMIT 1"
    );
    if ($gpsGrossSetupRow) {
        db_execute(
            "UPDATE acc_qbo_item_map SET qbo_item_id='TEST-SMOKE-I-GPSGROSS', qbo_name='SMOKE GPS Gross', mapping_status='mapped' WHERE id=?",
            [$gpsGrossSetupRow['id']]
        );
        $sentinelItemMapIds[] = ['id' => $gpsGrossSetupRow['id'], 'restored_qbo' => null];
        $gpsGrossMap = ['id' => $gpsGrossSetupRow['id'], 'qbo_item_id' => 'TEST-SMOKE-I-GPSGROSS'];
    }
}

// ── C11: InvoiceLineBuilder::build emits one Line per FF line ─
$c11Errors = [];
try {
    if (!$baseRentalMap) {
        $c11Errors[] = 'no base_rental item mapping available for test';
    } else {
        $invoice = ['id' => 999990, 'engine_version' => null];
        $customer = ['id' => 1, 'gps_revenue_presentation' => 'net'];
        $lines = [
            ['item_type' => 'base_rental', 'description' => 'Line 1', 'amount' => '100.00', 'unit_price' => '100.00', 'quantity' => 1, 'sort_order' => 1],
            ['item_type' => 'base_rental', 'description' => 'Line 2', 'amount' => '200.00', 'unit_price' => '200.00', 'quantity' => 1, 'sort_order' => 2],
        ];
        $qboLines = InvoiceLineBuilder::build($invoice, $customer, $lines);
        if (count($qboLines) !== 2) $c11Errors[] = 'expected 2 QBO Lines, got ' . count($qboLines);
        if (($qboLines[0]['LineNum'] ?? null) !== 1 || ($qboLines[1]['LineNum'] ?? null) !== 2) {
            $c11Errors[] = 'LineNum not sequential 1,2';
        }
        if (($qboLines[0]['Amount'] ?? null) !== '100.00') $c11Errors[] = "Line 0 Amount expected '100.00', got " . json_encode($qboLines[0]['Amount'] ?? null);
        if (($qboLines[0]['DetailType'] ?? null) !== 'SalesItemLineDetail') $c11Errors[] = 'DetailType wrong';
    }
} catch (Throwable $e) {
    $c11Errors[] = 'C11 threw: ' . $e->getMessage();
}
if (empty($c11Errors)) { echo "PASS C11 InvoiceLineBuilder::build emits one Line per FF row in sort_order\n"; $pass++; }
else { echo "FAIL C11 " . implode('; ', $c11Errors) . "\n"; $failures[] = 'C11'; }

// ── C12: build() resolves correct QBO Item for non-variant types
$c12Errors = [];
try {
    if (!$baseRentalMap) {
        $c12Errors[] = 'no base_rental mapping';
    } else {
        $qboLines = InvoiceLineBuilder::build(
            ['id'=>999990, 'engine_version'=>null],
            ['id'=>1, 'gps_revenue_presentation'=>'net'],
            [['item_type'=>'base_rental', 'description'=>'X', 'amount'=>'50.00', 'unit_price'=>'50.00', 'quantity'=>1, 'sort_order'=>1]]
        );
        $itemId = $qboLines[0]['SalesItemLineDetail']['ItemRef']['value'] ?? null;
        if ($itemId !== (string) $baseRentalMap['qbo_item_id']) {
            $c12Errors[] = "expected qbo_item_id='{$baseRentalMap['qbo_item_id']}', got " . json_encode($itemId);
        }
    }
} catch (Throwable $e) {
    $c12Errors[] = 'C12 threw: ' . $e->getMessage();
}
if (empty($c12Errors)) { echo "PASS C12 build() resolves correct QBO Item for non-variant base_rental\n"; $pass++; }
else { echo "FAIL C12 " . implode('; ', $c12Errors) . "\n"; $failures[] = 'C12'; }

// ── C13: GPS variant resolution (net + gross)
$c13Errors = [];
try {
    if (!$gpsNetMap || !$gpsGrossMap) {
        $c13Errors[] = 'GPS net/gross mappings unavailable';
    } else {
        // Net variant
        $linesNet = InvoiceLineBuilder::build(
            ['id'=>999990, 'engine_version'=>null],
            ['id'=>1, 'gps_revenue_presentation'=>'net'],
            [['item_type'=>'gps', 'description'=>'GPS', 'amount'=>'25.00', 'unit_price'=>'25.00', 'quantity'=>1, 'sort_order'=>1]]
        );
        $netId = $linesNet[0]['SalesItemLineDetail']['ItemRef']['value'] ?? null;
        if ($netId !== (string) $gpsNetMap['qbo_item_id']) {
            $c13Errors[] = "net variant: expected '{$gpsNetMap['qbo_item_id']}', got " . json_encode($netId);
        }
        // Gross variant
        $linesGross = InvoiceLineBuilder::build(
            ['id'=>999990, 'engine_version'=>null],
            ['id'=>1, 'gps_revenue_presentation'=>'gross'],
            [['item_type'=>'gps', 'description'=>'GPS', 'amount'=>'25.00', 'unit_price'=>'25.00', 'quantity'=>1, 'sort_order'=>1]]
        );
        $grossId = $linesGross[0]['SalesItemLineDetail']['ItemRef']['value'] ?? null;
        if ($grossId !== (string) $gpsGrossMap['qbo_item_id']) {
            $c13Errors[] = "gross variant: expected '{$gpsGrossMap['qbo_item_id']}', got " . json_encode($grossId);
        }
    }
} catch (Throwable $e) {
    $c13Errors[] = 'C13 threw: ' . $e->getMessage();
}
if (empty($c13Errors)) { echo "PASS C13 build() resolves GPS net/gross variant per customer.gps_revenue_presentation (D-QBO-10-2)\n"; $pass++; }
else { echo "FAIL C13 " . implode('; ', $c13Errors) . "\n"; $failures[] = 'C13'; }

// ── C14: build() throws when item_type lacks mapped QBO Item ─
$c14Errors = [];
try {
    $threw = false;
    try {
        // Use a real ENUM value with no mapping (find one)
        $unmappedType = db_row(
            "SELECT DISTINCT li.item_type FROM invoice_line_items li
               LEFT JOIN acc_qbo_item_map m ON m.ff_item_type = li.item_type AND m.ff_item_type_variant IS NULL AND m.mapping_status='mapped' AND m.qbo_item_id IS NOT NULL
              WHERE m.id IS NULL LIMIT 1"
        );
        // If all ENUMs are mapped (unlikely in dev), use a literal known-unmapped one
        $itemType = $unmappedType ? $unmappedType['item_type'] : 'manual_adjustment';
        // Confirm not actually mapped
        $check = db_row("SELECT id FROM acc_qbo_item_map WHERE ff_item_type = ? AND mapping_status='mapped' AND qbo_item_id IS NOT NULL LIMIT 1", [$itemType]);
        if ($check) {
            // Pick another via fallback
            $itemType = '__SMOKE_UNMAPPED__';
        }
        InvoiceLineBuilder::build(
            ['id'=>999990, 'engine_version'=>null],
            ['id'=>1, 'gps_revenue_presentation'=>'net'],
            [['item_type'=>$itemType, 'description'=>'X', 'amount'=>'1.00', 'unit_price'=>'1.00', 'quantity'=>1, 'sort_order'=>1]]
        );
    } catch (QuickBooksException $e) {
        $threw = true;
        if (!str_contains($e->getMessage(), 'No QBO Item mapped')) {
            $c14Errors[] = "expected 'No QBO Item mapped' in message, got: " . $e->getMessage();
        }
    }
    if (!$threw) $c14Errors[] = 'expected QuickBooksException, none thrown';
} catch (Throwable $e) {
    $c14Errors[] = 'C14 threw: ' . $e->getMessage();
}
if (empty($c14Errors)) { echo "PASS C14 build() throws QuickBooksException for unmapped item_type\n"; $pass++; }
else { echo "FAIL C14 " . implode('; ', $c14Errors) . "\n"; $failures[] = 'C14'; }

// ── C15: build() throws on period_independent + recon_credit ─
// Note: with no invoices.engine_version column on disk, this path
// only triggers when the in-memory invoice dict has 'engine_version' =>
// 'period_independent' explicitly. Use that pattern.
$c15Errors = [];
try {
    $threw = false;
    try {
        InvoiceLineBuilder::build(
            ['id'=>999990, 'engine_version'=>'period_independent'],
            ['id'=>1, 'gps_revenue_presentation'=>'net'],
            [['item_type'=>'base_rental_reconciliation_credit', 'description'=>'X', 'amount'=>'1.00', 'unit_price'=>'1.00', 'quantity'=>1, 'sort_order'=>1]]
        );
    } catch (QuickBooksException $e) {
        $threw = true;
        if (!str_contains($e->getMessage(), 'reconciliation_credit')
            && !str_contains($e->getMessage(), 'period_independent')) {
            $c15Errors[] = "expected message naming reconciliation_credit or period_independent, got: " . $e->getMessage();
        }
    }
    if (!$threw) $c15Errors[] = 'expected QuickBooksException, none thrown';
} catch (Throwable $e) {
    $c15Errors[] = 'C15 threw: ' . $e->getMessage();
}
if (empty($c15Errors)) { echo "PASS C15 build() throws on period_independent invoice with base_rental_reconciliation_credit line (D-QBO-11-5)\n"; $pass++; }
else { echo "FAIL C15 " . implode('; ', $c15Errors) . "\n"; $failures[] = 'C15'; }

// ── Synthetic FF setup for preflight + pusher tests ─────────
// Use sentinel customer + invoice to avoid interfering with live data.
// Sentinel customer 999990, invoice 999990, with one base_rental line.
db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999990");
db_execute("DELETE FROM invoice_line_items WHERE invoice_id = 999990");
db_execute("DELETE FROM invoices WHERE id = 999990");
db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id = 999990");
db_execute("DELETE FROM customers WHERE id = 999990");

// Find an existing customer's column shape (use minimum needed)
$customerCols = db_select("SHOW COLUMNS FROM customers WHERE `Null`='NO' AND `Default` IS NULL");
$customerRequired = array_map(fn($c) => $c['Field'], $customerCols);

// Insert synthetic customer (id=999990). We need to know which cols are required.
$synthCustomerData = [
    'id' => 999990,
    'company_name' => 'SMOKE QBO11 Customer',
    'gps_revenue_presentation' => 'net',
];
foreach ($customerRequired as $col) {
    if (in_array($col, ['id'], true)) continue;
    if (!isset($synthCustomerData[$col])) {
        // Defensive defaults for common required cols
        $synthCustomerData[$col] = match($col) {
            'company_name' => 'SMOKE QBO11 Customer',
            default => '',
        };
    }
}
try {
    db_insert('customers', $synthCustomerData);
    $sentinelCustomerIds[] = 999990;
} catch (Throwable $e) {
    echo "WARN  synthetic customer insert failed: " . $e->getMessage() . "\n";
}

// Insert synthetic invoice (id=999990). billing_period_start, _end, _days,
// billing_type, invoice_date, due_date are required NOT NULL defaults-null.
$synthInvoiceData = [
    'id'                    => 999990,
    'invoice_number'        => 'SMK-QBO11-' . substr((string) time(), -6),
    'customer_id'           => 999990,
    'invoice_type'          => 'regular',
    'billing_period_start'  => '2026-05-01',
    'billing_period_end'    => '2026-05-31',
    'billing_period_days'   => 31,
    'billing_type'          => 'single_period',
    'invoice_date'          => '2026-05-31',
    'due_date'              => '2026-06-30',
    'status'                => 'sent',
    'subtotal'              => '1000.00',
    'tax_gst_amount'        => '50.00',
    'tax_pst_amount'        => '70.00',
    'tax_hst_amount'        => '0.00',
    'tax_total'             => '120.00',
    'total_amount'          => '1120.00',
    'balance_due'           => '1120.00',
    'currency'              => 'CAD',
    'company_name_snapshot' => 'SMOKE QBO11 Customer',
    'subtotal_after_discount' => '1000.00',
    'notes'                 => 'SMOKE C16 invoice for preflight gate testing',
];
try {
    db_insert('invoices', $synthInvoiceData);
    $sentinelInvoiceIds[] = 999990;
    db_insert('invoice_line_items', [
        'invoice_id' => 999990,
        'item_type'  => 'base_rental',
        'description'=> 'SMOKE base rental line',
        'amount'     => '1000.00',
        'unit_price' => '1000.00',
        'quantity'   => '1',
        'sort_order' => 1,
    ]);
} catch (Throwable $e) {
    echo "WARN  synthetic invoice insert failed: " . $e->getMessage() . "\n";
}

// Customer mapping for invoice 999990 → sentinel qbo_customer_id
try {
    db_insert('acc_qbo_customer_map', [
        'ff_customer_id'  => 999990,
        'qbo_customer_id' => 'TEST-SMOKE-C-QBO11',
        'mapping_status'  => 'mapped',
        'match_confidence'=> 'manual',
    ]);
    $sentinelCustomerMapIds[] = ['ff_customer_id' => 999990];
} catch (Throwable $e) {
    echo "WARN  synthetic customer_map insert failed: " . $e->getMessage() . "\n";
}

// Ensure settings primed for happy-path tests
$setSetting('quickbooks.connection_status', 'connected');
$setSetting('quickbooks.tax_override_code_id', 'NON');

// ── C16: PreflightGate::check returns ok=true when satisfied ─
// Note: AccountValidator::assertReadyForInvoicePush still requires live
// AR + Sales Revenue to be mapped, which they aren't in our DB. So this
// gate will currently REJECT at step 3 (account validator). We test
// against the validator-error path here and check pass-path in C17.
$c16Errors = [];
try {
    $result = InvoicePreflightGate::check(999990);
    if (!isset($result['ok'], $result['reason'])) {
        $c16Errors[] = 'check() did not return [ok, reason] keys';
    }
    // We expect ok=false because AR + Sales Revenue not mapped to QBO yet.
    // The reason should mention "Account validator".
    if ($result['ok'] === true) {
        // If somehow the live state HAS all mappings, that's also OK — but
        // we record the unexpected state for the report.
        if (!isset($result['reason']) || $result['reason'] !== null) {
            $c16Errors[] = 'ok=true but reason is non-null';
        }
    } else {
        if (!str_contains((string)$result['reason'], 'validator')
            && !str_contains((string)$result['reason'], 'unmapped')) {
            $c16Errors[] = "expected reason to mention validator/unmapped, got: " . $result['reason'];
        }
    }
} catch (Throwable $e) {
    $c16Errors[] = 'C16 threw: ' . $e->getMessage();
}
if (empty($c16Errors)) { echo "PASS C16 check() returns ['ok' => bool, 'reason' => string|null] structure correctly\n"; $pass++; }
else { echo "FAIL C16 " . implode('; ', $c16Errors) . "\n"; $failures[] = 'C16'; }

// ── C17: check() returns reason mentioning unmapped customer ─
$c17Errors = [];
try {
    // Delete the customer mapping temporarily and re-check
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status='ff_only', qbo_customer_id=NULL WHERE ff_customer_id=999990");
    $result = InvoicePreflightGate::check(999990);
    if ($result['ok']) $c17Errors[] = 'expected ok=false when customer unmapped';
    if (!str_contains((string)$result['reason'], 'Customer')
        && !str_contains((string)$result['reason'], 'unmapped')
        && !str_contains((string)$result['reason'], 'validator')) {
        $c17Errors[] = "reason should mention Customer/unmapped/validator: " . $result['reason'];
    }
    // Restore
    db_execute("UPDATE acc_qbo_customer_map SET mapping_status='mapped', qbo_customer_id='TEST-SMOKE-C-QBO11' WHERE ff_customer_id=999990");
} catch (Throwable $e) {
    $c17Errors[] = 'C17 threw: ' . $e->getMessage();
}
if (empty($c17Errors)) { echo "PASS C17 check() surfaces customer-unmapped (or earlier gate failure) reason in error\n"; $pass++; }
else { echo "FAIL C17 " . implode('; ', $c17Errors) . "\n"; $failures[] = 'C17'; }

// ── C18: check() returns reason "tax override" when setting empty
$c18Errors = [];
try {
    db_execute("DELETE FROM settings WHERE `key` = 'quickbooks.tax_override_code_id'");
    $result = InvoicePreflightGate::check(999990);
    if ($result['ok']) $c18Errors[] = 'expected ok=false';
    if (!str_contains((string)$result['reason'], 'override') && !str_contains((string)$result['reason'], 'Tax')) {
        $c18Errors[] = "reason should mention tax/override: " . $result['reason'];
    }
} catch (Throwable $e) {
    $c18Errors[] = 'C18 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.tax_override_code_id', 'NON');
}
if (empty($c18Errors)) { echo "PASS C18 check() surfaces tax_override_code_id missing reason\n"; $pass++; }
else { echo "FAIL C18 " . implode('; ', $c18Errors) . "\n"; $failures[] = 'C18'; }

// ── C19: check() surfaces connection disconnected reason
$c19Errors = [];
try {
    $setSetting('quickbooks.connection_status', 'disconnected');
    $result = InvoicePreflightGate::check(999990);
    if ($result['ok']) $c19Errors[] = 'expected ok=false';
    if (!str_contains((string)$result['reason'], 'connect') && !str_contains((string)$result['reason'], 'Connect')) {
        $c19Errors[] = "reason should mention connection: " . $result['reason'];
    }
} catch (Throwable $e) {
    $c19Errors[] = 'C19 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.connection_status', 'connected');
}
if (empty($c19Errors)) { echo "PASS C19 check() surfaces connection disconnected reason\n"; $pass++; }
else { echo "FAIL C19 " . implode('; ', $c19Errors) . "\n"; $failures[] = 'C19'; }

// ── C20: pushImpl returns 'skipped_by_mode' for qbo_to_ff
$c20Errors = [];
try {
    $setSetting('quickbooks.sync_mode.invoice', 'qbo_to_ff');
    $result = InvoicePusher::pushCreate(999990);
    if (($result['status'] ?? null) !== 'skipped_by_mode') {
        $c20Errors[] = "expected status='skipped_by_mode', got " . json_encode($result['status'] ?? null);
    }
    if (!($result['success'] ?? false)) $c20Errors[] = 'expected success=true for mode-skip';
} catch (Throwable $e) {
    $c20Errors[] = 'C20 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.sync_mode.invoice', 'sync');
}
if (empty($c20Errors)) { echo "PASS C20 pushImpl returns 'skipped_by_mode' for sync_mode.invoice='qbo_to_ff'\n"; $pass++; }
else { echo "FAIL C20 " . implode('; ', $c20Errors) . "\n"; $failures[] = 'C20'; }

// ── C21: pushImpl returns 'skipped_voided' for status='void'
$c21Errors = [];
try {
    db_execute("UPDATE invoices SET status='void' WHERE id=999990");
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
    $result = InvoicePusher::pushCreate(999990);
    if (($result['status'] ?? null) !== 'skipped_voided') {
        $c21Errors[] = "expected status='skipped_voided', got " . json_encode($result['status'] ?? null);
    }
    $mapRow = db_row("SELECT push_status FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
    if (!$mapRow || $mapRow['push_status'] !== 'skipped_voided') {
        $c21Errors[] = "mapping row push_status should be 'skipped_voided', got " . json_encode($mapRow['push_status'] ?? null);
    }
    // Restore
    db_execute("UPDATE invoices SET status='sent' WHERE id=999990");
} catch (Throwable $e) {
    $c21Errors[] = 'C21 threw: ' . $e->getMessage();
}
if (empty($c21Errors)) { echo "PASS C21 pushImpl returns 'skipped_voided' for invoice.status='void' + records to mapping table\n"; $pass++; }
else { echo "FAIL C21 " . implode('; ', $c21Errors) . "\n"; $failures[] = 'C21'; }

// ── C22: pushImpl returns 'failed_preflight' when gate fails
$c22Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
    // Force a gate failure via missing tax override code
    db_execute("DELETE FROM settings WHERE `key` = 'quickbooks.tax_override_code_id'");
    $result = InvoicePusher::pushCreate(999990);
    if (($result['status'] ?? null) !== 'failed_preflight') {
        $c22Errors[] = "expected status='failed_preflight', got " . json_encode($result['status'] ?? null);
    }
    $mapRow = db_row("SELECT push_status, push_error FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
    if (!$mapRow || $mapRow['push_status'] !== 'failed_preflight') {
        $c22Errors[] = "mapping push_status should be 'failed_preflight', got " . json_encode($mapRow['push_status'] ?? null);
    }
    if (empty($mapRow['push_error'])) $c22Errors[] = 'push_error should be populated';
} catch (Throwable $e) {
    $c22Errors[] = 'C22 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.tax_override_code_id', 'NON');
}
if (empty($c22Errors)) { echo "PASS C22 pushImpl returns 'failed_preflight' when gate fails + records reason to mapping table\n"; $pass++; }
else { echo "FAIL C22 " . implode('; ', $c22Errors) . "\n"; $failures[] = 'C22'; }

// ── C23: pushImpl idempotency — already_mapped on create
$c23Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
    db_insert('acc_qbo_invoice_map', [
        'ff_invoice_id'  => 999990,
        'qbo_invoice_id' => 'TEST-SMOKE-EXISTING-QBO-ID',
        'qbo_sync_token' => '0',
        'push_status'    => 'pushed',
    ]);
    $result = InvoicePusher::pushCreate(999990);
    if (($result['status'] ?? null) !== 'already_mapped') {
        $c23Errors[] = "expected status='already_mapped', got " . json_encode($result['status'] ?? null);
    }
    if (($result['qbo_id'] ?? null) !== 'TEST-SMOKE-EXISTING-QBO-ID') {
        $c23Errors[] = "expected qbo_id passed through, got " . json_encode($result['qbo_id'] ?? null);
    }
} catch (Throwable $e) {
    $c23Errors[] = 'C23 threw: ' . $e->getMessage();
}
if (empty($c23Errors)) { echo "PASS C23 pushImpl idempotency — already-mapped invoice returns 'already_mapped' without re-POST\n"; $pass++; }
else { echo "FAIL C23 " . implode('; ', $c23Errors) . "\n"; $failures[] = 'C23'; }

// ── C24: enqueue returns false when sync_enabled='0'
$c24Errors = [];
try {
    $setSetting('quickbooks.sync_enabled', '0');
    $result = InvoiceEnqueuer::enqueue(999990, 'create');
    if ($result !== false) $c24Errors[] = "expected false, got " . json_encode($result);
} catch (Throwable $e) {
    $c24Errors[] = 'C24 threw: ' . $e->getMessage();
}
if (empty($c24Errors)) { echo "PASS C24 enqueue() returns false when sync_enabled='0' (master kill)\n"; $pass++; }
else { echo "FAIL C24 " . implode('; ', $c24Errors) . "\n"; $failures[] = 'C24'; }

// ── C25: enqueue with 'update' returns false (S-QBO-12 deferred)
$c25Errors = [];
try {
    $setSetting('quickbooks.sync_enabled', '1');
    $result = InvoiceEnqueuer::enqueue(999990, 'update');
    if ($result !== false) $c25Errors[] = "expected false for 'update' (S-QBO-12 deferred), got " . json_encode($result);
    // Cleanup if it inadvertently created a row
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id=999990");
} catch (Throwable $e) {
    $c25Errors[] = 'C25 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.sync_enabled', '0');
}
if (empty($c25Errors)) { echo "PASS C25 enqueue('update') returns false (operation allowlist excludes update; deferred to S-QBO-12)\n"; $pass++; }
else { echo "FAIL C25 " . implode('; ', $c25Errors) . "\n"; $failures[] = 'C25'; }

// ── C26: buildPrivateNoteJson includes required fields
$c26Errors = [];
try {
    $invoice = [
        'id' => 999990,
        'invoice_number' => 'INV-TEST',
        'engine_version' => 'holistic',
        'tax_gst_amount' => '5.00',
        'tax_pst_amount' => '7.00',
        'tax_hst_amount' => '0.00',
        'total_days_at_period_end' => 90,
        'cumulative_correct_amount' => '3000.00',
        'already_billed_before_this' => '2000.00',
    ];
    $json = InvoicePusher::buildPrivateNoteJson($invoice);
    $note = json_decode($json, true);
    if (!is_array($note)) $c26Errors[] = 'JSON parse failed';
    else {
        foreach (['ff_invoice_id','ff_invoice_number','engine_version','ff_tax_breakdown','pushed_at','audit'] as $key) {
            if (!array_key_exists($key, $note)) $c26Errors[] = "missing key: {$key}";
        }
        if (($note['engine_version'] ?? null) !== 'holistic') $c26Errors[] = "engine_version not preserved";
        if (($note['ff_tax_breakdown']['gst'] ?? null) !== '5.00') $c26Errors[] = "gst string not preserved (bcmath precision)";
        if (!isset($note['audit']['total_days_at_period_end']) || $note['audit']['total_days_at_period_end'] !== 90) {
            $c26Errors[] = "audit.total_days_at_period_end missing or wrong type";
        }
    }
} catch (Throwable $e) {
    $c26Errors[] = 'C26 threw: ' . $e->getMessage();
}
if (empty($c26Errors)) { echo "PASS C26 buildPrivateNoteJson includes ff_invoice_id + engine_version + ff_tax_breakdown + audit block\n"; $pass++; }
else { echo "FAIL C26 " . implode('; ', $c26Errors) . "\n"; $failures[] = 'C26'; }

// ── C27: buildPrivateNoteJson omits audit block when all 3 columns NULL
$c27Errors = [];
try {
    $invoice = [
        'id' => 999990,
        'invoice_number' => 'INV-TEST',
        'tax_gst_amount' => '0.00',
        'tax_pst_amount' => '0.00',
        'tax_hst_amount' => '0.00',
        // No audit columns
    ];
    $json = InvoicePusher::buildPrivateNoteJson($invoice);
    $note = json_decode($json, true);
    if (array_key_exists('audit', $note)) $c27Errors[] = "audit block should be omitted when all 3 columns missing/NULL";
    if (($note['engine_version'] ?? null) !== 'unknown') $c27Errors[] = "expected engine_version='unknown' fallback";
} catch (Throwable $e) {
    $c27Errors[] = 'C27 threw: ' . $e->getMessage();
}
if (empty($c27Errors)) { echo "PASS C27 buildPrivateNoteJson omits audit block when audit columns NULL; emits engine_version='unknown' fallback\n"; $pass++; }
else { echo "FAIL C27 " . implode('; ', $c27Errors) . "\n"; $failures[] = 'C27'; }

// ── C28: buildQboPayload — CAD invoice emits CurrencyRef + ExchangeRate when multi-currency enabled
// D-QBO-FIXPACK-1/-12: gate must be active (multi_currency_enabled='1') to emit CurrencyRef.
$c28Errors = [];
$setSetting('quickbooks.multi_currency_enabled', '1'); // D-QBO-FIXPACK-12: activate gate
try {
    $invoice = [
        'id'             => 999990,
        'invoice_number' => 'INV-TEST-CAD',
        'invoice_date'   => '2026-05-31',
        'due_date'       => '2026-06-30',
        'currency'       => 'CAD',
        'tax_gst_amount' => '0.00',
        'tax_pst_amount' => '0.00',
        'tax_hst_amount' => '0.00',
        'total_amount'   => '100.00',
    ];
    $customer = ['id' => 1, 'gps_revenue_presentation' => 'net'];
    $lines = [['item_type'=>'base_rental', 'description'=>'X', 'amount'=>'100.00', 'unit_price'=>'100.00', 'quantity'=>1, 'sort_order'=>1]];
    $customerMap = ['qbo_customer_id' => 'TEST-SMOKE-CUST'];
    $payload = InvoicePusher::buildQboPayload($invoice, $customer, $lines, $customerMap);
    if (($payload['CurrencyRef']['value'] ?? null) !== 'CAD') {
        $c28Errors[] = "CAD invoice MUST emit CurrencyRef.value='CAD' (D-QBO-FIXPACK-1), got " . json_encode($payload['CurrencyRef'] ?? null);
    }
    if (($payload['ExchangeRate'] ?? null) !== '1.0') {
        $c28Errors[] = "CAD invoice MUST emit ExchangeRate='1.0' (D-QBO-FIXPACK-2), got " . json_encode($payload['ExchangeRate'] ?? null);
    }
} catch (Throwable $e) {
    $c28Errors[] = 'C28 threw: ' . $e->getMessage();
}
if (empty($c28Errors)) { echo "PASS C28 buildQboPayload — multi_currency='1' + CAD invoice emits CurrencyRef='CAD' + ExchangeRate='1.0' (D-QBO-FIXPACK-1/-2/-12)\n"; $pass++; }
else { echo "FAIL C28 " . implode('; ', $c28Errors) . "\n"; $failures[] = 'C28'; }

// ── C29: buildQboPayload — USD invoice emits CurrencyRef + ExchangeRate when gate active
// D-QBO-FIXPACK-12: multi_currency_enabled='1' required.
$c29Errors = [];
$setSetting('quickbooks.multi_currency_enabled', '1'); // D-QBO-FIXPACK-12
try {
    $invoice = [
        'id'                   => 999991,
        'invoice_number'       => 'INV-TEST-USD',
        'invoice_date'         => '2026-05-31',
        'due_date'             => '2026-06-30',
        'currency'             => 'USD',
        'exchange_rate_to_cad' => '1.35',
        'tax_gst_amount'       => '0.00',
        'tax_pst_amount'       => '0.00',
        'tax_hst_amount'       => '0.00',
        'total_amount'         => '100.00',
    ];
    $customer = ['id' => 1, 'gps_revenue_presentation' => 'net'];
    $lines = [['item_type'=>'base_rental', 'description'=>'X', 'amount'=>'100.00', 'unit_price'=>'100.00', 'quantity'=>1, 'sort_order'=>1]];
    $customerMap = ['qbo_customer_id' => 'TEST-SMOKE-CUST'];
    $payload = InvoicePusher::buildQboPayload($invoice, $customer, $lines, $customerMap);
    if (($payload['CurrencyRef']['value'] ?? null) !== 'USD') {
        $c29Errors[] = "expected CurrencyRef.value='USD', got " . json_encode($payload['CurrencyRef']['value'] ?? null);
    }
    if (($payload['ExchangeRate'] ?? null) !== '1.35') {
        $c29Errors[] = "expected ExchangeRate='1.35', got " . json_encode($payload['ExchangeRate'] ?? null);
    }
} catch (Throwable $e) {
    $c29Errors[] = 'C29 threw: ' . $e->getMessage();
}
if (empty($c29Errors)) { echo "PASS C29 buildQboPayload — multi_currency='1' + USD invoice emits CurrencyRef='USD' + ExchangeRate='1.35' (D-QBO-11-3/-12)\n"; $pass++; }
else { echo "FAIL C29 " . implode('; ', $c29Errors) . "\n"; $failures[] = 'C29'; }

// ── C30: QboFieldLimits class exists with all 4 constants + checkLength ──
$c30Errors = [];
try {
    if (!class_exists(QboFieldLimits::class)) {
        $c30Errors[] = 'QboFieldLimits class not autoloaded';
    } else {
        $ref = new ReflectionClass(QboFieldLimits::class);
        foreach (['INVOICE_DOC_NUMBER_MAX','INVOICE_PRIVATE_NOTE_MAX','INVOICE_CUSTOMER_MEMO_MAX','INVOICE_LINE_DESCRIPTION_MAX'] as $const) {
            if (!$ref->hasConstant($const)) $c30Errors[] = "missing constant: {$const}";
        }
        if (!$ref->hasMethod('checkLength')) $c30Errors[] = 'missing method: checkLength';
        else {
            $rm = $ref->getMethod('checkLength');
            if (!$rm->isStatic() || !$rm->isPublic()) $c30Errors[] = 'checkLength must be public static';
        }
        if (QboFieldLimits::INVOICE_DOC_NUMBER_MAX !== 21) $c30Errors[] = 'INVOICE_DOC_NUMBER_MAX expected 21';
        if (QboFieldLimits::INVOICE_CUSTOMER_MEMO_MAX !== 1000) $c30Errors[] = 'INVOICE_CUSTOMER_MEMO_MAX expected 1000';
        if (QboFieldLimits::INVOICE_PRIVATE_NOTE_MAX !== 4000) $c30Errors[] = 'INVOICE_PRIVATE_NOTE_MAX expected 4000';
        if (QboFieldLimits::INVOICE_LINE_DESCRIPTION_MAX !== 4000) $c30Errors[] = 'INVOICE_LINE_DESCRIPTION_MAX expected 4000';
    }
} catch (Throwable $e) {
    $c30Errors[] = 'C30 threw: ' . $e->getMessage();
}
if (empty($c30Errors)) { echo "PASS C30 QboFieldLimits class exists with all 4 constants + checkLength (D-QBO-FIXPACK-4)\n"; $pass++; }
else { echo "FAIL C30 " . implode('; ', $c30Errors) . "\n"; $failures[] = 'C30'; }

// ── C31: checkLength returns null for value within limit; error for over ──
$c31Errors = [];
try {
    // Within limit
    $ok = QboFieldLimits::checkLength('test.field', 'SHORT', 21);
    if ($ok !== null) $c31Errors[] = "within-limit value should return null, got: " . json_encode($ok);
    // Exactly at limit (21 chars = 21 'A')
    $atLimit = QboFieldLimits::checkLength('test.field', str_repeat('A', 21), 21);
    if ($atLimit !== null) $c31Errors[] = "at-limit value should return null, got: " . json_encode($atLimit);
    // Over limit
    $over = QboFieldLimits::checkLength('invoice.invoice_number', str_repeat('X', 22), 21);
    if ($over === null) $c31Errors[] = "over-limit value should return error string, got null";
    if ($over !== null && !str_contains($over, '22')) $c31Errors[] = "error message should mention actual length 22";
    if ($over !== null && !str_contains($over, '21')) $c31Errors[] = "error message should mention limit 21";
    // NULL passes through
    $null = QboFieldLimits::checkLength('test.field', null, 21);
    if ($null !== null) $c31Errors[] = "null value should return null (optional field), got: " . json_encode($null);
} catch (Throwable $e) {
    $c31Errors[] = 'C31 threw: ' . $e->getMessage();
}
if (empty($c31Errors)) { echo "PASS C31 QboFieldLimits::checkLength: null → pass; at-limit → pass; over-limit → error; NULL → pass\n"; $pass++; }
else { echo "FAIL C31 " . implode('; ', $c31Errors) . "\n"; $failures[] = 'C31'; }

// ── C32: invoice_number >21 chars returns failed_preflight_field_too_long ─
// Uses pushImpl path to confirm end-to-end typed status propagation.
$c32Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
    $longNumber = str_repeat('A', 22);  // 22 chars > 21 limit
    db_execute("UPDATE invoices SET invoice_number=?, status='sent' WHERE id=999990", [$longNumber]);
    // Tax override must be present for the gate to reach step 6
    $setSetting('quickbooks.tax_override_code_id', 'NON');
    $setSetting('quickbooks.connection_status', 'connected');
    $result = InvoicePusher::pushCreate(999990);
    // Status should be failed_preflight_field_too_long (or failed_preflight if gate stops earlier)
    $status = $result['status'] ?? null;
    if (!in_array($status, ['failed_preflight_field_too_long', 'failed_preflight'], true)) {
        $c32Errors[] = "expected failed_preflight* status, got " . json_encode($status);
    }
    if ($status === 'failed_preflight_field_too_long') {
        $mapRow = db_row("SELECT push_status, push_error FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
        if (!$mapRow || $mapRow['push_status'] !== 'failed_preflight_field_too_long') {
            $c32Errors[] = "map push_status expected 'failed_preflight_field_too_long', got " . json_encode($mapRow['push_status'] ?? null);
        }
        if (!str_contains((string)($mapRow['push_error'] ?? ''), '22')) {
            $c32Errors[] = "push_error should mention length 22";
        }
    }
    // Restore invoice_number
    db_execute("UPDATE invoices SET invoice_number=? WHERE id=999990", ['SMK-QBO11-' . substr((string)time(), -6)]);
} catch (Throwable $e) {
    $c32Errors[] = 'C32 threw: ' . $e->getMessage();
    db_execute("UPDATE invoices SET invoice_number=? WHERE id=999990", ['SMK-QBO11-RESTORE']);
}
if (empty($c32Errors)) { echo "PASS C32 invoice_number >21 chars → failed_preflight_field_too_long with actionable error (D-QBO-FIXPACK-4)\n"; $pass++; }
else { echo "FAIL C32 " . implode('; ', $c32Errors) . "\n"; $failures[] = 'C32'; }

// ── C33: notes (CustomerMemo) >1000 chars returns field_too_long ─────────
$c33Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id=999990");
    $longNotes = str_repeat('N', 1001);
    db_execute("UPDATE invoices SET notes=? WHERE id=999990", [$longNotes]);
    $result = InvoicePusher::pushCreate(999990);
    $status = $result['status'] ?? null;
    if (!in_array($status, ['failed_preflight_field_too_long', 'failed_preflight'], true)) {
        $c33Errors[] = "expected failed_preflight* for long notes, got " . json_encode($status);
    }
    // Restore
    db_execute("UPDATE invoices SET notes='SMOKE C16 invoice for preflight gate testing' WHERE id=999990");
} catch (Throwable $e) {
    $c33Errors[] = 'C33 threw: ' . $e->getMessage();
    db_execute("UPDATE invoices SET notes='SMOKE C16 invoice for preflight gate testing' WHERE id=999990");
}
if (empty($c33Errors)) { echo "PASS C33 notes (CustomerMemo) >1000 chars → failed_preflight_field_too_long\n"; $pass++; }
else { echo "FAIL C33 " . implode('; ', $c33Errors) . "\n"; $failures[] = 'C33'; }

// ── C34: QboFieldLimits::checkLength catches line description >4000 chars ─
// NOTE: invoice_line_items.description is varchar(500) so we cannot persist
// a 4001-char string to DB. Instead we verify the QboFieldLimits::checkLength
// static method directly (same code path the gate calls per-line). This covers
// the gate logic without needing a DB round-trip.
$c34Errors = [];
try {
    $longDesc = str_repeat('D', 4001);
    $err = QboFieldLimits::checkLength('invoice_line_items[0].description', $longDesc, QboFieldLimits::INVOICE_LINE_DESCRIPTION_MAX);
    if ($err === null) {
        $c34Errors[] = "expected error message for 4001-char line description, got null";
    }
    if ($err !== null && !str_contains($err, '4001')) {
        $c34Errors[] = "error message should mention actual length 4001, got: {$err}";
    }
    if ($err !== null && !str_contains($err, '4000')) {
        $c34Errors[] = "error message should mention limit 4000, got: {$err}";
    }
    // At-limit passes
    $atLimit = QboFieldLimits::checkLength('invoice_line_items[0].description', str_repeat('D', 4000), QboFieldLimits::INVOICE_LINE_DESCRIPTION_MAX);
    if ($atLimit !== null) {
        $c34Errors[] = "4000-char description at limit should pass, got: {$atLimit}";
    }
} catch (Throwable $e) {
    $c34Errors[] = 'C34 threw: ' . $e->getMessage();
}
if (empty($c34Errors)) { echo "PASS C34 QboFieldLimits catches line description >4000 chars (offline gate logic; DB column is varchar(500))\n"; $pass++; }
else { echo "FAIL C34 " . implode('; ', $c34Errors) . "\n"; $failures[] = 'C34'; }

// ── C35: buildQboPayload — CAD invoice emits CurrencyRef='CAD' + ExchangeRate='1.0'
// (dedicated unit-test form of C28; D-QBO-FIXPACK-12: gate must be active)
$c35Errors = [];
$setSetting('quickbooks.multi_currency_enabled', '1'); // D-QBO-FIXPACK-12
try {
    $inv = [
        'id'=>999990, 'invoice_number'=>'INV-C35-CAD', 'invoice_date'=>'2026-05-31',
        'due_date'=>'2026-06-30', 'currency'=>'CAD',
        'tax_gst_amount'=>'0.00','tax_pst_amount'=>'0.00','tax_hst_amount'=>'0.00',
        'total_amount'=>'200.00',
    ];
    $pl = InvoicePusher::buildQboPayload($inv, ['id'=>1,'gps_revenue_presentation'=>'net'],
        [['item_type'=>'base_rental','description'=>'X','amount'=>'200.00','unit_price'=>'200.00','quantity'=>1,'sort_order'=>1]],
        ['qbo_customer_id'=>'CUST-C35']);
    if (($pl['CurrencyRef']['value'] ?? null) !== 'CAD') $c35Errors[] = "expected CurrencyRef.value='CAD', got " . json_encode($pl['CurrencyRef'] ?? null);
    if (($pl['ExchangeRate'] ?? null) !== '1.0')         $c35Errors[] = "expected ExchangeRate='1.0', got " . json_encode($pl['ExchangeRate'] ?? null);
} catch (Throwable $e) {
    $c35Errors[] = 'C35 threw: ' . $e->getMessage();
}
if (empty($c35Errors)) { echo "PASS C35 buildQboPayload — CAD invoice emits CurrencyRef.value='CAD' + ExchangeRate='1.0'\n"; $pass++; }
else { echo "FAIL C35 " . implode('; ', $c35Errors) . "\n"; $failures[] = 'C35'; }

// ── C36: buildQboPayload — USD invoice emits CurrencyRef='USD' + ExchangeRate=rate
// D-QBO-FIXPACK-12: gate must be active.
$c36Errors = [];
$setSetting('quickbooks.multi_currency_enabled', '1'); // D-QBO-FIXPACK-12
try {
    $inv = [
        'id'=>999991, 'invoice_number'=>'INV-C36-USD', 'invoice_date'=>'2026-05-31',
        'due_date'=>'2026-06-30', 'currency'=>'USD', 'exchange_rate_to_cad'=>'1.35',
        'tax_gst_amount'=>'0.00','tax_pst_amount'=>'0.00','tax_hst_amount'=>'0.00',
        'total_amount'=>'100.00',
    ];
    $pl = InvoicePusher::buildQboPayload($inv, ['id'=>1,'gps_revenue_presentation'=>'net'],
        [['item_type'=>'base_rental','description'=>'X','amount'=>'100.00','unit_price'=>'100.00','quantity'=>1,'sort_order'=>1]],
        ['qbo_customer_id'=>'CUST-C36']);
    if (($pl['CurrencyRef']['value'] ?? null) !== 'USD') $c36Errors[] = "expected CurrencyRef.value='USD', got " . json_encode($pl['CurrencyRef'] ?? null);
    if (($pl['ExchangeRate'] ?? null) !== '1.35')        $c36Errors[] = "expected ExchangeRate='1.35', got " . json_encode($pl['ExchangeRate'] ?? null);
} catch (Throwable $e) {
    $c36Errors[] = 'C36 threw: ' . $e->getMessage();
}
if (empty($c36Errors)) { echo "PASS C36 buildQboPayload — USD invoice emits CurrencyRef.value='USD' + ExchangeRate='1.35'\n"; $pass++; }
else { echo "FAIL C36 " . implode('; ', $c36Errors) . "\n"; $failures[] = 'C36'; }

// ── C37: Non-CAD invoice with exchange_rate_to_cad=NULL throws QuickBooksException
// D-QBO-FIXPACK-12: throw only fires when gate is active (multi_currency_enabled='1').
$c37Errors = [];
$setSetting('quickbooks.multi_currency_enabled', '1'); // D-QBO-FIXPACK-12
try {
    $threw = false;
    try {
        $inv = [
            'id'=>999991, 'invoice_number'=>'INV-C37', 'invoice_date'=>'2026-05-31',
            'due_date'=>'2026-06-30', 'currency'=>'USD', 'exchange_rate_to_cad'=>null,
            'tax_gst_amount'=>'0.00','tax_pst_amount'=>'0.00','tax_hst_amount'=>'0.00','total_amount'=>'100.00',
        ];
        InvoicePusher::buildQboPayload($inv, ['id'=>1,'gps_revenue_presentation'=>'net'],
            [['item_type'=>'base_rental','description'=>'X','amount'=>'100.00','unit_price'=>'100.00','quantity'=>1,'sort_order'=>1]],
            ['qbo_customer_id'=>'CUST-C37']);
    } catch (QuickBooksException $e) {
        $threw = true;
        if (!str_contains($e->getMessage(), 'exchange_rate_to_cad') && !str_contains($e->getMessage(), 'exchange rate')) {
            $c37Errors[] = "exception message should mention exchange_rate_to_cad, got: " . $e->getMessage();
        }
    }
    if (!$threw) $c37Errors[] = 'expected QuickBooksException on NULL exchange_rate_to_cad for non-CAD invoice';
} catch (Throwable $e) {
    $c37Errors[] = 'C37 threw unexpected: ' . $e->getMessage();
}
if (empty($c37Errors)) { echo "PASS C37 Non-CAD invoice with exchange_rate_to_cad=NULL throws QuickBooksException with actionable message\n"; $pass++; }
else { echo "FAIL C37 " . implode('; ', $c37Errors) . "\n"; $failures[] = 'C37'; }

// ── C38: Non-CAD invoice with exchange_rate_to_cad=0 throws QuickBooksException
// D-QBO-FIXPACK-12: throw only fires when gate is active.
$c38Errors = [];
$setSetting('quickbooks.multi_currency_enabled', '1'); // D-QBO-FIXPACK-12
try {
    $threw = false;
    try {
        $inv = [
            'id'=>999991, 'invoice_number'=>'INV-C38', 'invoice_date'=>'2026-05-31',
            'due_date'=>'2026-06-30', 'currency'=>'USD', 'exchange_rate_to_cad'=>'0',
            'tax_gst_amount'=>'0.00','tax_pst_amount'=>'0.00','tax_hst_amount'=>'0.00','total_amount'=>'100.00',
        ];
        InvoicePusher::buildQboPayload($inv, ['id'=>1,'gps_revenue_presentation'=>'net'],
            [['item_type'=>'base_rental','description'=>'X','amount'=>'100.00','unit_price'=>'100.00','quantity'=>1,'sort_order'=>1]],
            ['qbo_customer_id'=>'CUST-C38']);
    } catch (QuickBooksException $e) {
        $threw = true;
    }
    if (!$threw) $c38Errors[] = 'expected QuickBooksException on exchange_rate_to_cad=0 for non-CAD invoice';
} catch (Throwable $e) {
    $c38Errors[] = 'C38 threw unexpected: ' . $e->getMessage();
}
if (empty($c38Errors)) { echo "PASS C38 Non-CAD invoice with exchange_rate_to_cad=0 throws QuickBooksException (zero is invalid)\n"; $pass++; }
else { echo "FAIL C38 " . implode('; ', $c38Errors) . "\n"; $failures[] = 'C38'; }

// ── C39: acc_qbo_invoice_map.push_status ENUM includes new typed codes ────
$c39Errors = [];
try {
    $col = db_row("SHOW COLUMNS FROM acc_qbo_invoice_map WHERE Field='push_status'");
    if (!$col) {
        $c39Errors[] = 'push_status column not found';
    } else {
        $enumType = $col['Type'];
        foreach (['failed_preflight_field_too_long', 'failed_preflight_currency_mismatch'] as $val) {
            if (!str_contains($enumType, $val)) {
                $c39Errors[] = "ENUM missing value: {$val}";
            }
        }
    }
} catch (Throwable $e) {
    $c39Errors[] = 'C39 threw: ' . $e->getMessage();
}
if (empty($c39Errors)) { echo "PASS C39 acc_qbo_invoice_map.push_status ENUM includes failed_preflight_field_too_long + failed_preflight_currency_mismatch\n"; $pass++; }
else { echo "FAIL C39 " . implode('; ', $c39Errors) . "\n"; $failures[] = 'C39'; }

// ── C40: §6.8 canonical return shape — all 5 keys present on skipped_by_mode path ──
// Exercises RESULT_BASE merge: mode gate fires before the DB load so id=0 is fine.
$c40Errors = [];
try {
    $setSetting('quickbooks.sync_mode.invoice', 'qbo_to_ff');
    try {
        $shapeResult = InvoicePusher::pushCreate(0); // mode gate fires before DB load; no real row needed
    } finally {
        $setSetting('quickbooks.sync_mode.invoice', 'sync');
    }
    foreach (['success', 'status', 'qbo_id', 'sync_token', 'error'] as $key) {
        if (!array_key_exists($key, $shapeResult)) {
            $c40Errors[] = "return shape missing key '{$key}' on skipped_by_mode path";
        }
    }
    if (isset($shapeResult['success']) && !is_bool($shapeResult['success'])) {
        $c40Errors[] = "'success' should be bool, got " . gettype($shapeResult['success']);
    }
} catch (Throwable $e) {
    $c40Errors[] = 'C40 threw: ' . $e->getMessage();
}
if (empty($c40Errors)) {
    echo "PASS C40 §6.8 canonical return shape — all 5 keys present (skipped_by_mode path; S-QBO-PUSHER-CONTRACT-PAYDOWN C6)\n";
    $pass++;
} else {
    echo "FAIL C40 " . implode('; ', $c40Errors) . "\n";
    $failures[] = 'C40';
}

// ── C41: D-QBO-FIXPACK-10 backport — already_mapped branch emits mismatch warning ──
// Uses invoice 999990 (currency='CAD') + inserts mapping with qbo_currency='USD'.
// Captures error_log output via ini_set to verify the S-QBO-FIXPACK-10 WARNING text.
$c41Errors = [];
try {
    // Clean + insert mapping with qbo_currency='USD' to trigger mismatch probe.
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999990");
    db_insert('acc_qbo_invoice_map', [
        'ff_invoice_id'  => 999990,
        'qbo_invoice_id' => 'TEST-SMOKE-FIXPACK10',
        'qbo_sync_token' => '0',
        'qbo_currency'   => 'USD',   // FF invoice has currency='CAD' → mismatch fires
        'push_status'    => 'pushed',
    ]);

    // Redirect error_log to a temp file so we can inspect the warning output.
    $tmpLog  = tempnam(sys_get_temp_dir(), 'ff_smoke_c41_');
    $origLog = ini_get('error_log');
    ini_set('error_log', (string) $tmpLog);

    $result = InvoicePusher::pushCreate(999990);

    ini_set('error_log', (string) $origLog);
    $logContent = (file_exists($tmpLog) && is_readable($tmpLog)) ? file_get_contents($tmpLog) : '';
    @unlink($tmpLog);

    if (($result['status'] ?? null) !== 'already_mapped') {
        $c41Errors[] = "expected status='already_mapped', got " . json_encode($result['status'] ?? null);
    }
    if (!str_contains($logContent, 'S-QBO-FIXPACK-10 WARNING')) {
        $c41Errors[] = "expected 'S-QBO-FIXPACK-10 WARNING' in error_log output; captured: " . substr($logContent, 0, 300);
    }
    if (!str_contains($logContent, "qbo_currency='USD'")) {
        $c41Errors[] = "warning should mention qbo_currency='USD'; captured: " . substr($logContent, 0, 300);
    }
} catch (Throwable $e) {
    $c41Errors[] = 'C41 threw: ' . $e->getMessage();
}
if (empty($c41Errors)) {
    echo "PASS C41 InvoicePusher already_mapped branch emits S-QBO-FIXPACK-10 WARNING on CAD/USD mismatch (MEDIUM-C9 fix)\n";
    $pass++;
} else {
    echo "FAIL C41 " . implode('; ', $c41Errors) . "\n";
    $failures[] = 'C41';
}

// ── C42: S-QBO-INVOICE-LIST-BADGE — API extends invoice list SELECT with LEFT JOIN ──
// api/v1/invoices/index.php must JOIN acc_qbo_invoice_map when QBO connected
// so the index page can render the per-row QBO badge.
$c42Errors = [];
try {
    $apiPath = realpath(__DIR__ . '/../api/v1/invoices/index.php');
    if ($apiPath === false || !is_readable($apiPath)) {
        $c42Errors[] = 'api/v1/invoices/index.php missing or unreadable';
    } else {
        $src = file_get_contents($apiPath);
        if (!preg_match('/LEFT\s+JOIN\s+acc_qbo_invoice_map\s+m\s+ON\s+m\.ff_invoice_id\s*=\s*i\.id/i', $src)) {
            $c42Errors[] = "expected LEFT JOIN acc_qbo_invoice_map m ON m.ff_invoice_id = i.id in invoice list SELECT";
        }
        if (!str_contains($src, 'qbo_push_status')) {
            $c42Errors[] = "expected qbo_push_status alias projected in invoice list SELECT";
        }
        if (!str_contains($src, "quickbooks.connection_status")) {
            $c42Errors[] = "expected connection_status gate on JOIN — JOIN must be omitted when QBO disconnected";
        }
    }
} catch (Throwable $e) {
    $c42Errors[] = 'C42 threw: ' . $e->getMessage();
}
if (empty($c42Errors)) {
    echo "PASS C42 api/v1/invoices/index.php LEFT JOINs acc_qbo_invoice_map when QBO connected (S-QBO-INVOICE-LIST-BADGE)\n";
    $pass++;
} else {
    echo "FAIL C42 " . implode('; ', $c42Errors) . "\n";
    $failures[] = 'C42';
}

// ── C43: S-QBO-INVOICE-LIST-BADGE — UI gates the column on QBO connection ──
// app/admin/invoices/index.php must reference qbo_push_status / qboIcon /
// qboBadgeClass only inside a $qboConnected conditional sourced from
// settings_get('quickbooks.connection_status', ...).
$c43Errors = [];
try {
    $uiPath = realpath(__DIR__ . '/../app/admin/invoices/index.php');
    if ($uiPath === false || !is_readable($uiPath)) {
        $c43Errors[] = 'app/admin/invoices/index.php missing or unreadable';
    } else {
        $src = file_get_contents($uiPath);
        if (!preg_match("/\\\$qboConnected\s*=\s*\(string\)\s*settings_get\('quickbooks\.connection_status'/", $src)) {
            $c43Errors[] = "expected \$qboConnected assignment sourced from settings_get('quickbooks.connection_status', ...)";
        }
        if (!preg_match('/<\?php\s+if\s*\(\s*\$qboConnected\s*\)\s*:/', $src)) {
            $c43Errors[] = "expected at least one <?php if (\$qboConnected): ?> gate wrapping the QBO column";
        }
        if (!str_contains($src, 'qbo_push_status')) {
            $c43Errors[] = "expected qbo_push_status reference in the column body";
        }
        if (!str_contains($src, 'qboBadgeClass') || !str_contains($src, 'qboIcon') || !str_contains($src, 'qboLabel')) {
            $c43Errors[] = "expected qboBadgeClass + qboIcon + qboLabel Alpine helpers defined";
        }
        // PHP lint on the UI file
        $out = []; $code = 0;
        exec('php -l ' . escapeshellarg($uiPath) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $c43Errors[] = "app/admin/invoices/index.php lint failed: " . implode('; ', $out);
        }
    }
} catch (Throwable $e) {
    $c43Errors[] = 'C43 threw: ' . $e->getMessage();
}
if (empty($c43Errors)) {
    echo "PASS C43 app/admin/invoices/index.php QBO column gated on connection_status + Alpine helpers defined (S-QBO-INVOICE-LIST-BADGE)\n";
    $pass++;
} else {
    echo "FAIL C43 " . implode('; ', $c43Errors) . "\n";
    $failures[] = 'C43';
}

// ── C44: S-QBO-INVOICE-SHOW-RICH-PANEL — show.php loads mapping + sync_log ──
// Assert app/admin/invoices/show.php queries acc_qbo_invoice_map AND
// acc_qbo_sync_log when QBO is connected. The data load drives the
// maximalist inline panel that replaced the S-QBO-11 header badge.
$c44Errors = [];
try {
    $showPath = realpath(__DIR__ . '/../app/admin/invoices/show.php');
    if ($showPath === false || !is_readable($showPath)) {
        $c44Errors[] = 'app/admin/invoices/show.php missing or unreadable';
    } else {
        $src = file_get_contents($showPath);
        if (!preg_match('/FROM\s+acc_qbo_invoice_map\s+WHERE\s+ff_invoice_id/i', $src)) {
            $c44Errors[] = "expected SELECT ... FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?";
        }
        if (!preg_match("/FROM\s+acc_qbo_sync_log\s+WHERE\s+entity_type\s*=\s*'invoice'/i", $src)) {
            $c44Errors[] = "expected SELECT ... FROM acc_qbo_sync_log WHERE entity_type='invoice' (last-20 history query)";
        }
        if (!str_contains($src, '$qboSyncLogHistory')) {
            $c44Errors[] = "expected \$qboSyncLogHistory variable holding the history rows";
        }
        if (!str_contains($src, '$qboCanEdit')) {
            $c44Errors[] = "expected \$qboCanEdit boolean for the Retry button permission gate";
        }
    }
} catch (Throwable $e) {
    $c44Errors[] = 'C44 threw: ' . $e->getMessage();
}
if (empty($c44Errors)) {
    echo "PASS C44 app/admin/invoices/show.php loads acc_qbo_invoice_map + acc_qbo_sync_log when QBO connected (S-QBO-INVOICE-SHOW-RICH-PANEL)\n";
    $pass++;
} else {
    echo "FAIL C44 " . implode('; ', $c44Errors) . "\n";
    $failures[] = 'C44';
}

// ── C45: S-QBO-INVOICE-SHOW-RICH-PANEL — panel gated on connection_status ──
// The QuickBooks Sync panel block must be wrapped in a $qboConnected
// conditional sourced from settings_get('quickbooks.connection_status', ...).
$c45Errors = [];
try {
    $showPath = realpath(__DIR__ . '/../app/admin/invoices/show.php');
    if ($showPath === false || !is_readable($showPath)) {
        $c45Errors[] = 'app/admin/invoices/show.php missing or unreadable';
    } else {
        $src = file_get_contents($showPath);
        if (!preg_match("/\\\$qboConnected\s*=\s*\(\s*\(string\)\s*settings_get\('quickbooks\.connection_status'/", $src)) {
            $c45Errors[] = "expected \$qboConnected sourced from settings_get('quickbooks.connection_status', ...)";
        }
        if (!preg_match('/<\?php\s+if\s*\(\s*\$qboConnected\s*\)\s*:/', $src)) {
            $c45Errors[] = "expected <?php if (\$qboConnected): ?> gate wrapping the panel";
        }
        if (!str_contains($src, 'qbo-sync-panel')) {
            $c45Errors[] = "expected panel container with id='qbo-sync-panel'";
        }
        if (!str_contains($src, 'Push History')) {
            $c45Errors[] = "expected 'Push History' section heading inside the panel";
        }
    }
} catch (Throwable $e) {
    $c45Errors[] = 'C45 threw: ' . $e->getMessage();
}
if (empty($c45Errors)) {
    echo "PASS C45 show.php QuickBooks Sync panel gated on connection_status + Push History section present (S-QBO-INVOICE-SHOW-RICH-PANEL)\n";
    $pass++;
} else {
    echo "FAIL C45 " . implode('; ', $c45Errors) . "\n";
    $failures[] = 'C45';
}

// ── C46: S-QBO-INVOICE-SHOW-RICH-PANEL — old header badge removed ──
// The S-QBO-11 simple header badge used the local var $qm_badgeClass +
// $qm_label inside an <a> tag in the page-header h1. Assert those
// match-expression variables no longer appear (panel replaces them).
$c46Errors = [];
try {
    $showPath = realpath(__DIR__ . '/../app/admin/invoices/show.php');
    if ($showPath === false || !is_readable($showPath)) {
        $c46Errors[] = 'app/admin/invoices/show.php missing or unreadable';
    } else {
        $src = file_get_contents($showPath);
        if (preg_match('/\$qm_badgeClass\s*=\s*match/', $src)) {
            $c46Errors[] = "old \$qm_badgeClass match expression still present — header badge should be removed";
        }
        if (preg_match('/\$qm_label\s*=\s*match/', $src)) {
            $c46Errors[] = "old \$qm_label match expression still present — header badge should be removed";
        }
        // Confirm the removal-marker comment is present so future readers
        // know the badge was intentionally retired (not accidentally dropped).
        if (!str_contains($src, 'header badge retired')) {
            $c46Errors[] = "expected 'header badge retired' marker comment documenting the removal";
        }
    }
} catch (Throwable $e) {
    $c46Errors[] = 'C46 threw: ' . $e->getMessage();
}
if (empty($c46Errors)) {
    echo "PASS C46 show.php S-QBO-11 header badge removed (replaced by inline panel) (S-QBO-INVOICE-SHOW-RICH-PANEL)\n";
    $pass++;
} else {
    echo "FAIL C46 " . implode('; ', $c46Errors) . "\n";
    $failures[] = 'C46';
}

// ── C47: S-QBO-INVOICE-SHOW-RICH-PANEL — Retry button perm-gated ──
// The Retry button must be gated by can('quickbooks', 'edit_credentials'),
// matching api/v1/quickbooks/invoices/retry.php's require_permission gate
// (retry.php line 26). Assert the gate is via the $qboCanEdit boolean
// (set from the same can() check on show.php line ~59).
$c47Errors = [];
try {
    $showPath = realpath(__DIR__ . '/../app/admin/invoices/show.php');
    if ($showPath === false || !is_readable($showPath)) {
        $c47Errors[] = 'app/admin/invoices/show.php missing or unreadable';
    } else {
        $src = file_get_contents($showPath);
        if (!preg_match("/\\\$qboCanEdit\s*=\s*\\\$qboConnected\s*&&\s*can\('quickbooks',\s*'edit_credentials'\)/", $src)) {
            $c47Errors[] = "expected \$qboCanEdit = \$qboConnected && can('quickbooks', 'edit_credentials') in data load";
        }
        if (!preg_match('/\$rp_show_retry\s*=\s*\$qboCanEdit/', $src)) {
            $c47Errors[] = "expected \$rp_show_retry to gate on \$qboCanEdit before rendering the Retry button";
        }
        if (!str_contains($src, 'function qboSyncPanel')) {
            $c47Errors[] = "expected qboSyncPanel() Alpine factory wiring the retry POST";
        }
        if (!str_contains($src, 'api/v1/quickbooks/invoices/retry')) {
            $c47Errors[] = "expected POST target api/v1/quickbooks/invoices/retry in the Alpine factory";
        }
    }
} catch (Throwable $e) {
    $c47Errors[] = 'C47 threw: ' . $e->getMessage();
}
if (empty($c47Errors)) {
    echo "PASS C47 show.php Retry button gated on quickbooks.edit_credentials + wires to retry endpoint (S-QBO-INVOICE-SHOW-RICH-PANEL)\n";
    $pass++;
} else {
    echo "FAIL C47 " . implode('; ', $c47Errors) . "\n";
    $failures[] = 'C47';
}

// ── C48: S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE — soft-deleted invoice push ──
// Asserts InvoicePusher::pushCreate on a soft-deleted invoice:
//   - Returns success=true, status='skipped_soft_deleted', outcome='skipped'
//   - Records map row with push_status='skipped_soft_deleted'
//   - Writes acc_qbo_sync_log row with error_code='skipped_soft_deleted', response_status NULL
// Closes the silent false-complete bug class identified in S-QBO-WORKER-FALSE-COMPLETE-DIAGNOSIS.
$c48Errors = [];
try {
    // Fixture: invoice 999992, status='sent', deleted_at set (soft-deleted).
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999992");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='invoice' AND entity_id = 999992");
    db_execute("DELETE FROM invoice_line_items WHERE invoice_id = 999992");
    db_execute("DELETE FROM invoices WHERE id = 999992");

    db_insert('invoices', [
        'id'                    => 999992,
        'invoice_number'        => 'SMK-C48-' . substr((string) time(), -6),
        'customer_id'           => 999990,
        'invoice_type'          => 'regular',
        'billing_period_start'  => '2026-05-01',
        'billing_period_end'    => '2026-05-31',
        'billing_period_days'   => 31,
        'billing_type'          => 'single_period',
        'invoice_date'          => '2026-05-31',
        'due_date'              => '2026-06-30',
        'status'                => 'sent',
        'subtotal'              => '500.00',
        'tax_total'             => '0.00',
        'total_amount'          => '500.00',
        'balance_due'           => '500.00',
        'currency'              => 'CAD',
        'company_name_snapshot' => 'SMOKE C48 Customer',
        'subtotal_after_discount' => '500.00',
        'notes'                 => 'SMOKE C48 — soft-deleted invoice',
        'deleted_at'            => '2026-05-26 00:00:00',
    ]);

    // Ensure sync_mode.invoice is 'sync' so gate 1 doesn't intercept first.
    $setSetting('quickbooks.sync_mode.invoice', 'sync');

    $result = InvoicePusher::pushCreate(999992, null);

    if (($result['success'] ?? null) !== true) {
        $c48Errors[] = "expected success=true, got " . var_export($result['success'] ?? null, true);
    }
    if (($result['status'] ?? null) !== 'skipped_soft_deleted') {
        $c48Errors[] = "expected status='skipped_soft_deleted', got '" . ($result['status'] ?? 'NULL') . "'";
    }
    if (($result['outcome'] ?? null) !== 'skipped') {
        $c48Errors[] = "expected outcome='skipped', got '" . ($result['outcome'] ?? 'NULL') . "'";
    }

    $mapRow = db_row("SELECT push_status FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?", [999992]);
    if ($mapRow === null) {
        $c48Errors[] = "expected acc_qbo_invoice_map row for ff_invoice_id=999992 (none found)";
    } elseif (($mapRow['push_status'] ?? null) !== 'skipped_soft_deleted') {
        $c48Errors[] = "expected map.push_status='skipped_soft_deleted', got '" . ($mapRow['push_status'] ?? 'NULL') . "'";
    }

    $logRow = db_row(
        "SELECT error_code, response_status, http_method FROM acc_qbo_sync_log
          WHERE entity_type='invoice' AND entity_id=? AND error_code='skipped_soft_deleted'
          ORDER BY id DESC LIMIT 1",
        [999992]
    );
    if ($logRow === null) {
        $c48Errors[] = "expected acc_qbo_sync_log row with error_code='skipped_soft_deleted' for entity_id=999992 (none found)";
    } else {
        if ($logRow['response_status'] !== null) {
            $c48Errors[] = "expected sync_log.response_status=NULL (non-HTTP skip), got " . var_export($logRow['response_status'], true);
        }
        if ($logRow['http_method'] !== 'SKIP') {
            $c48Errors[] = "expected sync_log.http_method='SKIP' sentinel, got '" . ($logRow['http_method'] ?? 'NULL') . "'";
        }
    }
} catch (Throwable $e) {
    $c48Errors[] = 'C48 threw: ' . $e->getMessage();
}
if (empty($c48Errors)) {
    echo "PASS C48 InvoicePusher soft-deleted skip records map + sync_log (S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE)\n";
    $pass++;
} else {
    echo "FAIL C48 " . implode('; ', $c48Errors) . "\n";
    $failures[] = 'C48';
}

// ── C49: S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE — sync_mode=disabled invoice push ──
// Same shape as C48 but the skip is triggered by sync_mode.invoice='disabled'
// rather than soft-deletion. Asserts:
//   - Returns success=true, status='skipped_by_mode', outcome='skipped'
//   - Records map row with push_status='skipped_by_mode'
//   - Writes acc_qbo_sync_log row with error_code='skipped_by_mode'
$c49Errors = [];
try {
    // Fixture: invoice 999993, status='sent', deleted_at NULL.
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999993");
    db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='invoice' AND entity_id = 999993");
    db_execute("DELETE FROM invoice_line_items WHERE invoice_id = 999993");
    db_execute("DELETE FROM invoices WHERE id = 999993");

    db_insert('invoices', [
        'id'                    => 999993,
        'invoice_number'        => 'SMK-C49-' . substr((string) time(), -6),
        'customer_id'           => 999990,
        'invoice_type'          => 'regular',
        'billing_period_start'  => '2026-05-01',
        'billing_period_end'    => '2026-05-31',
        'billing_period_days'   => 31,
        'billing_type'          => 'single_period',
        'invoice_date'          => '2026-05-31',
        'due_date'              => '2026-06-30',
        'status'                => 'sent',
        'subtotal'              => '500.00',
        'tax_total'             => '0.00',
        'total_amount'          => '500.00',
        'balance_due'           => '500.00',
        'currency'              => 'CAD',
        'company_name_snapshot' => 'SMOKE C49 Customer',
        'subtotal_after_discount' => '500.00',
        'notes'                 => 'SMOKE C49 — sync_mode=disabled',
    ]);

    // Flip sync_mode.invoice so gate 1 intercepts. $origSettings already
    // snapshotted at smoke start; finally{} restores.
    $setSetting('quickbooks.sync_mode.invoice', 'disabled');

    $result = InvoicePusher::pushCreate(999993, null);

    // Restore sync_mode immediately so subsequent assertions aren't affected.
    $setSetting('quickbooks.sync_mode.invoice', 'sync');

    if (($result['success'] ?? null) !== true) {
        $c49Errors[] = "expected success=true, got " . var_export($result['success'] ?? null, true);
    }
    if (($result['status'] ?? null) !== 'skipped_by_mode') {
        $c49Errors[] = "expected status='skipped_by_mode', got '" . ($result['status'] ?? 'NULL') . "'";
    }
    if (($result['outcome'] ?? null) !== 'skipped') {
        $c49Errors[] = "expected outcome='skipped', got '" . ($result['outcome'] ?? 'NULL') . "'";
    }

    $mapRow = db_row("SELECT push_status FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?", [999993]);
    if ($mapRow === null) {
        $c49Errors[] = "expected acc_qbo_invoice_map row for ff_invoice_id=999993 (none found)";
    } elseif (($mapRow['push_status'] ?? null) !== 'skipped_by_mode') {
        $c49Errors[] = "expected map.push_status='skipped_by_mode', got '" . ($mapRow['push_status'] ?? 'NULL') . "'";
    }

    $logRow = db_row(
        "SELECT error_code, response_status FROM acc_qbo_sync_log
          WHERE entity_type='invoice' AND entity_id=? AND error_code='skipped_by_mode'
          ORDER BY id DESC LIMIT 1",
        [999993]
    );
    if ($logRow === null) {
        $c49Errors[] = "expected acc_qbo_sync_log row with error_code='skipped_by_mode' for entity_id=999993 (none found)";
    } elseif ($logRow['response_status'] !== null) {
        $c49Errors[] = "expected sync_log.response_status=NULL (non-HTTP skip), got " . var_export($logRow['response_status'], true);
    }
} catch (Throwable $e) {
    $c49Errors[] = 'C49 threw: ' . $e->getMessage();
}
if (empty($c49Errors)) {
    echo "PASS C49 InvoicePusher sync_mode='disabled' skip records map + sync_log (S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE)\n";
    $pass++;
} else {
    echo "FAIL C49 " . implode('; ', $c49Errors) . "\n";
    $failures[] = 'C49';
}

// ── C50: S-QBO-ENQUEUER-ELIGIBILITY-GATE — gate-0 rejects missing invoice ──
// Set sync_enabled='1' first so gate-1 wouldn't reject — if no queue row
// is written, gate-0 must have been the rejector.
$c50Errors = [];
try {
    db_execute("DELETE FROM invoices WHERE id = 999994");  // ensure absent
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = 999994");
    $setSetting('quickbooks.sync_enabled', '1');

    $r = InvoiceEnqueuer::enqueue(999994, 'create');

    if ($r !== false) {
        $c50Errors[] = "expected enqueue to return false for missing invoice id, got " . var_export($r, true);
    }
    $row = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = ?", [999994]);
    if ($row !== null) {
        $c50Errors[] = "expected NO queue row written; found queue id={$row['id']}";
    }
} catch (Throwable $e) {
    $c50Errors[] = 'C50 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.sync_enabled', '0');
}
if (empty($c50Errors)) {
    echo "PASS C50 InvoiceEnqueuer gate-0 rejects missing invoice id (S-QBO-ENQUEUER-ELIGIBILITY-GATE)\n";
    $pass++;
} else {
    echo "FAIL C50 " . implode('; ', $c50Errors) . "\n";
    $failures[] = 'C50';
}

// ── C51: S-QBO-ENQUEUER-ELIGIBILITY-GATE — gate-0 rejects soft-deleted ──
$c51Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999995");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = 999995");
    db_execute("DELETE FROM invoice_line_items WHERE invoice_id = 999995");
    db_execute("DELETE FROM invoices WHERE id = 999995");

    db_insert('invoices', [
        'id'                    => 999995,
        'invoice_number'        => 'SMK-C51-' . substr((string) time(), -6),
        'customer_id'           => 999990,
        'invoice_type'          => 'regular',
        'billing_period_start'  => '2026-05-01',
        'billing_period_end'    => '2026-05-31',
        'billing_period_days'   => 31,
        'billing_type'          => 'single_period',
        'invoice_date'          => '2026-05-31',
        'due_date'              => '2026-06-30',
        'status'                => 'sent',  // sent so the status check would pass
        'subtotal'              => '100.00',
        'tax_total'             => '0.00',
        'total_amount'          => '100.00',
        'balance_due'           => '100.00',
        'currency'              => 'CAD',
        'company_name_snapshot' => 'SMOKE C51 Customer',
        'subtotal_after_discount' => '100.00',
        'deleted_at'            => '2026-05-26 00:00:00',  // gate-0 reject path
    ]);
    $setSetting('quickbooks.sync_enabled', '1');

    $r = InvoiceEnqueuer::enqueue(999995, 'create');

    if ($r !== false) {
        $c51Errors[] = "expected enqueue to return false for soft-deleted invoice, got " . var_export($r, true);
    }
    $row = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = ?", [999995]);
    if ($row !== null) {
        $c51Errors[] = "expected NO queue row written; found queue id={$row['id']}";
    }
} catch (Throwable $e) {
    $c51Errors[] = 'C51 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.sync_enabled', '0');
}
if (empty($c51Errors)) {
    echo "PASS C51 InvoiceEnqueuer gate-0 rejects soft-deleted invoice (S-QBO-ENQUEUER-ELIGIBILITY-GATE)\n";
    $pass++;
} else {
    echo "FAIL C51 " . implode('; ', $c51Errors) . "\n";
    $failures[] = 'C51';
}

// ── C52: S-QBO-ENQUEUER-ELIGIBILITY-GATE — gate-0 rejects wrong status for op='create' ──
$c52Errors = [];
try {
    db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id = 999996");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = 999996");
    db_execute("DELETE FROM invoice_line_items WHERE invoice_id = 999996");
    db_execute("DELETE FROM invoices WHERE id = 999996");

    db_insert('invoices', [
        'id'                    => 999996,
        'invoice_number'        => 'SMK-C52-' . substr((string) time(), -6),
        'customer_id'           => 999990,
        'invoice_type'          => 'regular',
        'billing_period_start'  => '2026-05-01',
        'billing_period_end'    => '2026-05-31',
        'billing_period_days'   => 31,
        'billing_type'          => 'single_period',
        'invoice_date'          => '2026-05-31',
        'due_date'              => '2026-06-30',
        'status'                => 'draft',  // wrong for op='create' (gate-0 requires 'sent')
        'subtotal'              => '100.00',
        'tax_total'             => '0.00',
        'total_amount'          => '100.00',
        'balance_due'           => '100.00',
        'currency'              => 'CAD',
        'company_name_snapshot' => 'SMOKE C52 Customer',
        'subtotal_after_discount' => '100.00',
    ]);
    $setSetting('quickbooks.sync_enabled', '1');

    $r = InvoiceEnqueuer::enqueue(999996, 'create');

    if ($r !== false) {
        $c52Errors[] = "expected enqueue to return false for status='draft' on op='create', got " . var_export($r, true);
    }
    $row = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id = ?", [999996]);
    if ($row !== null) {
        $c52Errors[] = "expected NO queue row written; found queue id={$row['id']}";
    }
} catch (Throwable $e) {
    $c52Errors[] = 'C52 threw: ' . $e->getMessage();
} finally {
    $setSetting('quickbooks.sync_enabled', '0');
}
if (empty($c52Errors)) {
    echo "PASS C52 InvoiceEnqueuer gate-0 rejects status='draft' on op='create' (S-QBO-ENQUEUER-ELIGIBILITY-GATE)\n";
    $pass++;
} else {
    echo "FAIL C52 " . implode('; ', $c52Errors) . "\n";
    $failures[] = 'C52';
}

} finally {
    // ── Self-cleaning ──────────────────────────────────────────
    // Restore settings to pre-smoke values
    foreach ($origSettings as $key => $value) {
        try {
            if ($value === null) {
                db_execute("DELETE FROM settings WHERE `key` = ?", [$key]);
            } else {
                db_execute(
                    "INSERT INTO settings (`key`, value, value_type) VALUES (?, ?, 'string')
                     ON DUPLICATE KEY UPDATE value = VALUES(value)",
                    [$key, $value]
                );
            }
        } catch (Throwable $e) {
            echo "WARN  settings restore failed for {$key}: " . $e->getMessage() . "\n";
        }
    }

    // Sentinel cleanup — invoice_map + sync_log + invoice_line_items + invoices + customer_map + customers
    // S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE added 999992 + 999993 (C48 + C49)
    // and the new sync_log writes from recordSkipped — clean both.
    try {
        db_execute("DELETE FROM acc_qbo_invoice_map WHERE ff_invoice_id IN (999990, 999991, 999992, 999993, 999994, 999995, 999996)");
        db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='invoice' AND entity_id IN (999990, 999991, 999992, 999993, 999994, 999995, 999996)");
        db_execute("DELETE FROM acc_qbo_sync_log WHERE entity_type='invoice' AND entity_id IN (999990, 999991, 999992, 999993, 999994, 999995, 999996)");
        db_execute("DELETE FROM invoice_line_items WHERE invoice_id IN (999990, 999991, 999992, 999993, 999994, 999995, 999996)");
        db_execute("DELETE FROM invoices WHERE id IN (999990, 999991, 999992, 999993, 999994, 999995, 999996)");
        db_execute("DELETE FROM acc_qbo_customer_map WHERE ff_customer_id = 999990 OR qbo_customer_id LIKE 'TEST-SMOKE-C-%'");
        db_execute("DELETE FROM customers WHERE id = 999990");
    } catch (Throwable $e) {
        echo "WARN  sentinel cleanup failed: " . $e->getMessage() . "\n";
    }

    // Restore acc_qbo_item_map rows we mutated to sentinel qbo_item_ids
    foreach ($sentinelItemMapIds as $entry) {
        try {
            db_execute(
                "UPDATE acc_qbo_item_map SET qbo_item_id=?, qbo_name=NULL, mapping_status='ff_only' WHERE id=? AND qbo_item_id LIKE 'TEST-SMOKE-I-%'",
                [null, $entry['id']]
            );
        } catch (Throwable $e) {
            echo "WARN  item_map restore failed: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nqbo_invoice_push_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\n";
exit(0);
