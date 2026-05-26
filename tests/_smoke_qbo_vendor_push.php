<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_vendor_push.php
 *
 * S-QBO-7 — Structural + behavioural smoke for the vendor push
 * machinery (VendorPusher + VendorEnqueuer + dispatcher binding).
 * Runs OFFLINE: no real Intuit HTTP traffic. The pure-function code
 * paths (payload builder, mode gating, idempotency, soft-delete skip,
 * demotion rule, contact_name split) are exercised directly. Live
 * HTTP round-trip is covered by operator-side verification.
 *
 * Self-cleaning: every synthetic row uses id≥999990 sentinels +
 * sentinel qbo_vendor_id strings ('TEST-SMOKE-V-PUSH-*'). The finally
 * block scrubs vendors + acc_qbo_vendor_map + acc_qbo_sync_queue
 * inserts on pass or fail. Settings mutations are bracketed by save
 * + restore so the smoke leaves quickbooks.sync_enabled at its
 * original value (D-CPA-5 — must remain '0' after the smoke).
 *
 * 14 sub-checks (mirror of _smoke_qbo_customer_push.php):
 *   C1: VendorPusher class surface — pushCreate + pushUpdate +
 *       buildQboPayload public static, match dispatcher contract
 *   C2: VendorEnqueuer class surface — enqueue public static
 *   C3: buildQboPayload full FF row → expected QBO payload shape
 *       (DisplayName + CompanyName + CurrencyRef='CAD' + GivenName +
 *       FamilyName + PrimaryEmailAddr + PrimaryPhone + BillAddr
 *       with Country=CA; multi_currency_enabled='1'; D-QBO-FIXPACK-6/-8/-12)
 *   C4: buildQboPayload minimal FF row (name only) → DisplayName +
 *       CompanyName + CurrencyRef='CAD', no GivenName / no PrimaryEmail
 *       / no BillAddr (multi_currency_enabled='1'; D-QBO-FIXPACK-8/-12)
 *   C5: pushCreate sync mode gate — sync_mode.vendor='qbo_to_ff'
 *       returns ['status'=>'skipped_by_mode'] without hitting QBO
 *   C6: pushCreate soft-delete skip — deleted_at non-null returns
 *       ['status'=>'skipped_soft_deleted']
 *   C7: pushCreate idempotency — existing mapping with qbo_vendor_id
 *       returns ['status'=>'already_mapped'] (no duplicate POST)
 *   C8: VendorEnqueuer master kill — sync_enabled='0' returns false
 *       and inserts zero queue rows
 *   C9: VendorEnqueuer mode kill — sync_enabled='1' + mode='qbo_to_ff'
 *       returns false (no row)
 *  C10: VendorEnqueuer happy path — sync_enabled='1' + mode='sync'
 *       inserts a queue row with entity_type='vendor', operation
 *       matches input, status='queued'
 *  C11: buildQboPayload multi_currency='1' → CurrencyRef='CAD' emitted
 *       (hardcoded; vendors has no currency column; D-QBO-FIXPACK-8/-12)
 * C11b: buildQboPayload multi_currency='0' → CurrencyRef absent from payload
 *       (D-QBO-FIXPACK-12: gate suppresses CurrencyRef in single-currency mode)
 *  C12: buildQboPayload multi_currency='1' + 'currency'='USD' in $ff →
 *       CurrencyRef still 'CAD' (hardcoded, not pass-through; D-QBO-FIXPACK-8/-12)
 *  C14: §6.8 canonical 5-key return shape present on skipped_by_mode path
 *       (MEDIUM-C6 fix; S-QBO-PUSHER-CONTRACT-PAYDOWN)
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-7
 * @updated S-QBO-FIXPACK-2 — added CurrencyRef assertions to C3/C4;
 *          added C11/C12 for hardcoded CAD emission (D-QBO-FIXPACK-6/-8);
 *          total 12 sub-checks.
 * @updated S-QBO-FIXPACK-3 — gated C3/C4/C11/C12 behind
 *          multi_currency_enabled='1'; added C11b (multi_currency='0'
 *          → CurrencyRef absent); total 13 sub-checks (D-QBO-FIXPACK-12).
 * @updated S-QBO-PUSHER-CONTRACT-PAYDOWN — added C14 (§6.8 return shape
 *          check); total 14 sub-checks.
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 + §7.5
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\VendorPusher;
use FleetForge\QboPushers\VendorEnqueuer;

$failures = [];
$pass     = 0;
$total    = 16;

/** Sentinel vendor ids we inserted for synthetic testing. */
$sentinelVendorIds = [];
/** Sentinel queue ids we inserted directly. */
$sentinelQueueIds  = [];
/** Sentinel mapping ids we inserted directly. */
$sentinelMappingIds = [];

/** Settings we mutated and must restore. */
$settingsToRestore = [];
function ff_smoke_v_save_setting(string $key): void {
    global $settingsToRestore;
    if (!array_key_exists($key, $settingsToRestore)) {
        $settingsToRestore[$key] = settings_get($key, null);
    }
}
function ff_smoke_v_set_setting(string $key, string $val): void {
    ff_smoke_v_save_setting($key);
    db_execute(
        "INSERT INTO settings (`key`, `value`, is_public) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $val]
    );
}

try {

// ── C1: VendorPusher surface ───────────────────────────────
$c1Errors = [];
if (!class_exists(VendorPusher::class)) {
    $c1Errors[] = 'VendorPusher class not autoloaded';
} else {
    $ref = new ReflectionClass(VendorPusher::class);
    foreach (['pushCreate', 'pushUpdate', 'buildQboPayload'] as $m) {
        if (!$ref->hasMethod($m)) {
            $c1Errors[] = "missing method: {$m}";
            continue;
        }
        $rm = $ref->getMethod($m);
        if (!$rm->isStatic() || !$rm->isPublic()) {
            $c1Errors[] = "{$m} must be public static";
        }
    }
}
if (empty($c1Errors)) {
    echo "PASS C1  VendorPusher class surface (pushCreate + pushUpdate + buildQboPayload public static)\n";
    $pass++;
} else {
    echo "FAIL C1  " . implode('; ', $c1Errors) . "\n";
    $failures[] = 'C1';
}

// ── C2: VendorEnqueuer surface ─────────────────────────────
$c2Errors = [];
if (!class_exists(VendorEnqueuer::class)) {
    $c2Errors[] = 'VendorEnqueuer class not autoloaded';
} else {
    $ref = new ReflectionClass(VendorEnqueuer::class);
    if (!$ref->hasMethod('enqueue')) {
        $c2Errors[] = 'missing method: enqueue';
    } else {
        $rm = $ref->getMethod('enqueue');
        if (!$rm->isStatic() || !$rm->isPublic()) {
            $c2Errors[] = 'enqueue must be public static';
        }
    }
}
if (empty($c2Errors)) {
    echo "PASS C2  VendorEnqueuer class surface (enqueue public static)\n";
    $pass++;
} else {
    echo "FAIL C2  " . implode('; ', $c2Errors) . "\n";
    $failures[] = 'C2';
}

// ── C3: buildQboPayload full row ───────────────────────────
// Exercises name → DisplayName+CompanyName, contact_name split
// per D-QBO-7-3 ("John A. Smith" → GivenName='John', FamilyName='A. Smith'),
// address+city+state → BillAddr with default Country=CA,
// and D-QBO-FIXPACK-6/-8 CurrencyRef='CAD' (hardcoded, gate active).
$c3Errors = [];
// D-QBO-FIXPACK-12: activate gate so CurrencyRef is emitted.
ff_smoke_v_set_setting('quickbooks.multi_currency_enabled', '1');
$fullFf = [
    'id'           => 999990,
    'name'         => 'Acme Diesel Repair Ltd.',
    'contact_name' => 'John A. Smith',
    'email'        => 'service@acmediesel.test',
    'phone'        => '+1-604-555-0100',
    'address'      => '1234 Industrial Way',
    'city'         => 'Burnaby',
    'state'        => 'BC',
];
$payload = VendorPusher::buildQboPayload($fullFf);
foreach ([
    ['DisplayName', 'Acme Diesel Repair Ltd.'],
    ['CompanyName', 'Acme Diesel Repair Ltd.'],
    ['GivenName',   'John'],
    ['FamilyName',  'A. Smith'],
] as [$key, $expected]) {
    if (($payload[$key] ?? null) !== $expected) {
        $c3Errors[] = "{$key} mismatch: got " . json_encode($payload[$key] ?? null) . ", want " . json_encode($expected);
    }
}
// D-QBO-FIXPACK-6/-8: CurrencyRef must always be present with value='CAD'.
if (($payload['CurrencyRef']['value'] ?? null) !== 'CAD') {
    $c3Errors[] = "CurrencyRef.value mismatch: got " . json_encode($payload['CurrencyRef']['value'] ?? null) . ", want 'CAD'";
}
if (($payload['PrimaryEmailAddr']['Address'] ?? null) !== 'service@acmediesel.test') {
    $c3Errors[] = 'PrimaryEmailAddr.Address missing/mismatch';
}
if (($payload['PrimaryPhone']['FreeFormNumber'] ?? null) !== '+1-604-555-0100') {
    $c3Errors[] = 'PrimaryPhone.FreeFormNumber missing/mismatch';
}
// vendors table has no postal_code or country column; Country
// defaults to 'CA' in the payload. vendors.state maps to
// CountrySubDivisionCode.
$expectedAddr = [
    'Line1'                  => '1234 Industrial Way',
    'City'                   => 'Burnaby',
    'CountrySubDivisionCode' => 'BC',
    'Country'                => 'CA',
];
foreach ($expectedAddr as $k => $v) {
    if (($payload['BillAddr'][$k] ?? null) !== $v) {
        $c3Errors[] = "BillAddr.{$k} mismatch: got " . json_encode($payload['BillAddr'][$k] ?? null);
    }
}
// PostalCode must NOT be on the BillAddr (vendors has no postal_code).
if (array_key_exists('PostalCode', $payload['BillAddr'] ?? [])) {
    $c3Errors[] = 'BillAddr.PostalCode should be absent (vendors has no postal_code column)';
}
if (empty($c3Errors)) {
    echo "PASS C3  buildQboPayload full FF row maps DisplayName+CompanyName+CurrencyRef+GivenName+FamilyName+PrimaryEmail+PrimaryPhone+BillAddr\n";
    $pass++;
} else {
    echo "FAIL C3  " . implode('; ', $c3Errors) . "\n";
    $failures[] = 'C3';
}

// ── C4: buildQboPayload minimal row ────────────────────────
// Also exercises single-name contact (no space) → GivenName only.
// D-QBO-FIXPACK-8/-12: CurrencyRef='CAD' must be emitted when gate active
// (hardcoded; no currency column on vendors).
$c4Errors = [];
// D-QBO-FIXPACK-12: activate gate so CurrencyRef is emitted.
ff_smoke_v_set_setting('quickbooks.multi_currency_enabled', '1');
$minFf = ['name' => 'Solo Vendor Inc'];
$minPayload = VendorPusher::buildQboPayload($minFf);
if (($minPayload['DisplayName'] ?? null) !== 'Solo Vendor Inc') {
    $c4Errors[] = 'DisplayName mismatch on minimal row';
}
if (($minPayload['CompanyName'] ?? null) !== 'Solo Vendor Inc') {
    $c4Errors[] = 'CompanyName mismatch on minimal row';
}
// D-QBO-FIXPACK-6/-8: CurrencyRef must always be present with value='CAD'.
if (($minPayload['CurrencyRef']['value'] ?? null) !== 'CAD') {
    $c4Errors[] = "CurrencyRef.value mismatch on minimal row: got " . json_encode($minPayload['CurrencyRef']['value'] ?? null);
}
if (array_key_exists('GivenName', $minPayload)) {
    $c4Errors[] = 'GivenName should be omitted when contact_name absent';
}
if (array_key_exists('FamilyName', $minPayload)) {
    $c4Errors[] = 'FamilyName should be omitted when contact_name absent';
}
if (array_key_exists('PrimaryEmailAddr', $minPayload)) {
    $c4Errors[] = 'PrimaryEmailAddr should be omitted on empty email';
}
if (array_key_exists('PrimaryPhone', $minPayload)) {
    $c4Errors[] = 'PrimaryPhone should be omitted on empty phone';
}
if (array_key_exists('BillAddr', $minPayload)) {
    $c4Errors[] = 'BillAddr should be omitted when no address fields populated';
}
// Bonus check — single-word contact name maps to GivenName only.
$singleNameFf = ['name' => 'Whatever Co', 'contact_name' => 'Cher'];
$singleNamePayload = VendorPusher::buildQboPayload($singleNameFf);
if (($singleNamePayload['GivenName'] ?? null) !== 'Cher') {
    $c4Errors[] = "single-name contact: expected GivenName='Cher', got " . json_encode($singleNamePayload['GivenName'] ?? null);
}
if (($singleNamePayload['FamilyName'] ?? null) !== '') {
    $c4Errors[] = "single-name contact: expected FamilyName='', got " . json_encode($singleNamePayload['FamilyName'] ?? null);
}
if (empty($c4Errors)) {
    echo "PASS C4  buildQboPayload minimal row emits CurrencyRef='CAD', omits empty nested objects; single-name contact → GivenName only\n";
    $pass++;
} else {
    echo "FAIL C4  " . implode('; ', $c4Errors) . "\n";
    $failures[] = 'C4';
}

// ── C5: pushCreate sync mode gate ──────────────────────────
// Insert a synthetic FF vendor with sentinel id; use raw INSERT to
// bypass API uniqueness check + mandatory-field validation. vendors
// requires `vendor_type` (NOT NULL ENUM, no DEFAULT), so pass 'other'.
$c5Errors = [];
try {
    $synthName1 = 'SMOKE-S-QBO-7-C5 ' . bin2hex(random_bytes(4));
    db_execute(
        "INSERT INTO vendors (id, name, vendor_type) VALUES (999991, ?, 'other')",
        [$synthName1]
    );
    $sentinelVendorIds[] = 999991;

    ff_smoke_v_set_setting('quickbooks.sync_mode.vendor', 'qbo_to_ff');
    $result = VendorPusher::pushCreate(999991);
    if (($result['status'] ?? null) !== 'skipped_by_mode') {
        $c5Errors[] = 'expected status=skipped_by_mode, got ' . json_encode($result);
    }
    if (($result['success'] ?? null) !== true) {
        $c5Errors[] = 'skipped_by_mode should return success=true';
    }
} catch (Throwable $e) {
    $c5Errors[] = 'C5 setup threw: ' . $e->getMessage();
}
if (empty($c5Errors)) {
    echo "PASS C5  pushCreate respects sync_mode.vendor='qbo_to_ff' → skipped_by_mode\n";
    $pass++;
} else {
    echo "FAIL C5  " . implode('; ', $c5Errors) . "\n";
    $failures[] = 'C5';
}

// Restore mode to 'sync' for subsequent tests.
ff_smoke_v_set_setting('quickbooks.sync_mode.vendor', 'sync');

// ── C6: pushCreate soft-delete skip ────────────────────────
$c6Errors = [];
try {
    $synthName2 = 'SMOKE-S-QBO-7-C6 ' . bin2hex(random_bytes(4));
    db_execute(
        "INSERT INTO vendors (id, name, vendor_type, deleted_at) VALUES (999992, ?, 'other', NOW())",
        [$synthName2]
    );
    $sentinelVendorIds[] = 999992;

    $result = VendorPusher::pushCreate(999992);
    if (($result['status'] ?? null) !== 'skipped_soft_deleted') {
        $c6Errors[] = 'expected status=skipped_soft_deleted, got ' . json_encode($result);
    }
} catch (Throwable $e) {
    $c6Errors[] = 'C6 setup threw: ' . $e->getMessage();
}
if (empty($c6Errors)) {
    echo "PASS C6  pushCreate skips soft-deleted FF vendors (deleted_at non-null)\n";
    $pass++;
} else {
    echo "FAIL C6  " . implode('; ', $c6Errors) . "\n";
    $failures[] = 'C6';
}

// ── C7: pushCreate idempotency on already-mapped ───────────
// Pre-insert a mapping row with qbo_vendor_id; pushCreate should
// short-circuit with status=already_mapped without HTTP.
$c7Errors = [];
try {
    $synthName3 = 'SMOKE-S-QBO-7-C7 ' . bin2hex(random_bytes(4));
    db_execute(
        "INSERT INTO vendors (id, name, vendor_type) VALUES (999993, ?, 'other')",
        [$synthName3]
    );
    $sentinelVendorIds[] = 999993;

    $sentinelQboId = 'TEST-SMOKE-V-PUSH-' . bin2hex(random_bytes(6));
    $mappingId = db_insert('acc_qbo_vendor_map', [
        'ff_vendor_id'     => 999993,
        'qbo_vendor_id'    => $sentinelQboId,
        'qbo_sync_token'   => '0',
        'qbo_display_name' => $synthName3,
        'mapping_status'   => 'mapped',
        'match_confidence' => 'manual',
    ]);
    $sentinelMappingIds[] = $mappingId;

    $beforeCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
        [999993]
    );

    $result = VendorPusher::pushCreate(999993);
    if (($result['status'] ?? null) !== 'already_mapped') {
        $c7Errors[] = 'expected status=already_mapped, got ' . json_encode($result);
    }
    if (($result['qbo_id'] ?? null) !== $sentinelQboId) {
        $c7Errors[] = 'qbo_id should match the pre-existing mapping, got ' . json_encode($result['qbo_id'] ?? null);
    }

    $afterCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
        [999993]
    );
    if ($afterCount !== $beforeCount) {
        $c7Errors[] = "mapping row count changed (before={$beforeCount}, after={$afterCount}) — idempotency broken";
    }
} catch (Throwable $e) {
    $c7Errors[] = 'C7 setup threw: ' . $e->getMessage();
}
if (empty($c7Errors)) {
    echo "PASS C7  pushCreate idempotent — existing mapping returns already_mapped without re-INSERT\n";
    $pass++;
} else {
    echo "FAIL C7  " . implode('; ', $c7Errors) . "\n";
    $failures[] = 'C7';
}

// ── C8: VendorEnqueuer master kill switch ──────────────────
$c8Errors = [];
try {
    ff_smoke_v_set_setting('quickbooks.sync_enabled', '0');

    $beforeCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'vendor'",
        [999991]
    );
    $returned = VendorEnqueuer::enqueue(999991, 'create');
    if ($returned !== false) {
        $c8Errors[] = 'expected false when sync_enabled=0, got ' . var_export($returned, true);
    }
    $afterCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'vendor'",
        [999991]
    );
    if ($afterCount !== $beforeCount) {
        $c8Errors[] = "queue row count changed (before={$beforeCount}, after={$afterCount}) — master kill should be a no-op";
    }
} catch (Throwable $e) {
    $c8Errors[] = 'C8 setup threw: ' . $e->getMessage();
}
if (empty($c8Errors)) {
    echo "PASS C8  VendorEnqueuer master kill (sync_enabled=0) returns false + no queue insert\n";
    $pass++;
} else {
    echo "FAIL C8  " . implode('; ', $c8Errors) . "\n";
    $failures[] = 'C8';
}

// ── C9: VendorEnqueuer mode kill ───────────────────────────
$c9Errors = [];
try {
    ff_smoke_v_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_v_set_setting('quickbooks.sync_mode.vendor', 'qbo_to_ff');

    $beforeCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'vendor'",
        [999991]
    );
    $returned = VendorEnqueuer::enqueue(999991, 'create');
    if ($returned !== false) {
        $c9Errors[] = 'expected false on mode=qbo_to_ff, got ' . var_export($returned, true);
    }
    $afterCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'vendor'",
        [999991]
    );
    if ($afterCount !== $beforeCount) {
        $c9Errors[] = "queue row count changed (before={$beforeCount}, after={$afterCount}) — mode kill should be a no-op";
    }
} catch (Throwable $e) {
    $c9Errors[] = 'C9 setup threw: ' . $e->getMessage();
}
if (empty($c9Errors)) {
    echo "PASS C9  VendorEnqueuer mode kill (sync_mode.vendor=qbo_to_ff) returns false + no queue insert\n";
    $pass++;
} else {
    echo "FAIL C9  " . implode('; ', $c9Errors) . "\n";
    $failures[] = 'C9';
}

// ── C10: VendorEnqueuer happy path ─────────────────────────
$c10Errors = [];
try {
    ff_smoke_v_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_v_set_setting('quickbooks.sync_mode.vendor', 'sync');

    $returned = VendorEnqueuer::enqueue(999991, 'create');
    if ($returned !== true) {
        $c10Errors[] = 'expected true on happy path, got ' . var_export($returned, true);
    }
    $row = db_row(
        "SELECT id, entity_type, entity_id, operation, status FROM acc_qbo_sync_queue
          WHERE entity_id = ? AND entity_type = 'vendor' AND operation = 'create'
          ORDER BY id DESC LIMIT 1",
        [999991]
    );
    if ($row === null) {
        $c10Errors[] = 'no queue row inserted on happy path';
    } else {
        $sentinelQueueIds[] = (int) $row['id'];
        if ($row['status'] !== 'queued') {
            $c10Errors[] = "queue row status='{$row['status']}', expected 'queued'";
        }
        if ($row['entity_type'] !== 'vendor') {
            $c10Errors[] = "queue row entity_type='{$row['entity_type']}', expected 'vendor'";
        }
        if ($row['operation'] !== 'create') {
            $c10Errors[] = "queue row operation='{$row['operation']}', expected 'create'";
        }
    }
} catch (Throwable $e) {
    $c10Errors[] = 'C10 setup threw: ' . $e->getMessage();
}
if (empty($c10Errors)) {
    echo "PASS C10 VendorEnqueuer happy path (sync_enabled=1 + mode=sync) inserts queued row\n";
    $pass++;
} else {
    echo "FAIL C10 " . implode('; ', $c10Errors) . "\n";
    $failures[] = 'C10';
}

// ── C11: buildQboPayload multi_currency='1' → CurrencyRef='CAD' emitted ──────
// D-QBO-FIXPACK-8/-12: vendors table has no currency column, so CurrencyRef is
// hardcoded 'CAD' when gate is active. Backlog: S-VENDOR-CURRENCY-COLUMN.
$c11Errors = [];
ff_smoke_v_set_setting('quickbooks.multi_currency_enabled', '1');
$c11Ff = ['name' => 'Northern Freight Ltd.'];
$c11Payload = VendorPusher::buildQboPayload($c11Ff);
if (($c11Payload['CurrencyRef']['value'] ?? null) !== 'CAD') {
    $c11Errors[] = "CurrencyRef.value: got " . json_encode($c11Payload['CurrencyRef']['value'] ?? null) . ", want 'CAD'";
}
if (empty($c11Errors)) {
    echo "PASS C11 buildQboPayload multi_currency='1' → hardcoded CurrencyRef='CAD' emitted (D-QBO-FIXPACK-8/-12)\n";
    $pass++;
} else {
    echo "FAIL C11 " . implode('; ', $c11Errors) . "\n";
    $failures[] = 'C11';
}

// ── C11b: buildQboPayload multi_currency='0' → CurrencyRef absent ─────────────
// D-QBO-FIXPACK-12: gate suppresses CurrencyRef when multi-currency is disabled
// (QBO error 6000 fires when CurrencyRef is sent to single-currency company).
$c11bErrors = [];
ff_smoke_v_set_setting('quickbooks.multi_currency_enabled', '0');
$c11bPayload = VendorPusher::buildQboPayload($c11Ff);
if (array_key_exists('CurrencyRef', $c11bPayload)) {
    $c11bErrors[] = "CurrencyRef present in payload when multi_currency_enabled='0'; got "
                  . json_encode($c11bPayload['CurrencyRef']);
}
if (empty($c11bErrors)) {
    echo "PASS C11b buildQboPayload multi_currency='0' → CurrencyRef absent (gate suppresses CurrencyRef)\n";
    $pass++;
} else {
    echo "FAIL C11b " . implode('; ', $c11bErrors) . "\n";
    $failures[] = 'C11b';
}

// ── C12: buildQboPayload multi_currency='1' + 'currency'=>'USD' → still 'CAD' ─
// D-QBO-FIXPACK-8/-12: even if a caller passes 'currency' => 'USD' in the
// $ff array, the vendor payload always emits 'CAD' (hardcoded; no currency
// column on vendors). Gate must be active to test the hardcoded emission path.
// When S-VENDOR-CURRENCY-COLUMN ships, this test will be updated.
$c12Errors = [];
ff_smoke_v_set_setting('quickbooks.multi_currency_enabled', '1');
$c12Ff = ['name' => 'Future USD Vendor', 'currency' => 'USD']; // vendors table has no currency column
$c12Payload = VendorPusher::buildQboPayload($c12Ff);
if (($c12Payload['CurrencyRef']['value'] ?? null) !== 'CAD') {
    $c12Errors[] = "CurrencyRef.value: got " . json_encode($c12Payload['CurrencyRef']['value'] ?? null) .
        ", want 'CAD' (hardcoded per D-QBO-FIXPACK-8; 'currency' key in \$ff has no effect until S-VENDOR-CURRENCY-COLUMN)";
}
if (empty($c12Errors)) {
    echo "PASS C12 buildQboPayload multi_currency='1' + 'currency'=>'USD' → CurrencyRef still 'CAD' (hardcoded, not pass-through; D-QBO-FIXPACK-8)\n";
    $pass++;
} else {
    echo "FAIL C12 " . implode('; ', $c12Errors) . "\n";
    $failures[] = 'C12';
}

// ── C14: §6.8 canonical return shape — all 5 keys present on skipped_by_mode path ──
// Exercises RESULT_BASE merge: mode gate fires before the DB load so id=0 is fine.
$c14Errors = [];
try {
    ff_smoke_v_set_setting('quickbooks.sync_mode.vendor', 'qbo_to_ff');
    $shapeResult = VendorPusher::pushCreate(0); // mode gate fires before DB load; no real row needed
    ff_smoke_v_set_setting('quickbooks.sync_mode.vendor', 'sync');
    foreach (['success', 'status', 'qbo_id', 'sync_token', 'error'] as $key) {
        if (!array_key_exists($key, $shapeResult)) {
            $c14Errors[] = "return shape missing key '{$key}' on skipped_by_mode path";
        }
    }
    if (isset($shapeResult['success']) && !is_bool($shapeResult['success'])) {
        $c14Errors[] = "'success' should be bool, got " . gettype($shapeResult['success']);
    }
} catch (Throwable $e) {
    $c14Errors[] = 'C14 threw: ' . $e->getMessage();
}
if (empty($c14Errors)) {
    echo "PASS C14 §6.8 canonical return shape — all 5 keys present (skipped_by_mode path; S-QBO-PUSHER-CONTRACT-PAYDOWN C6)\n";
    $pass++;
} else {
    echo "FAIL C14 " . implode('; ', $c14Errors) . "\n";
    $failures[] = 'C14';
}

// ── C15: S-QBO-ENQUEUER-ELIGIBILITY-GATE — gate-0 rejects missing vendor ──
// Set sync_enabled='1' first so gate-1 wouldn't reject. If no queue row
// is written, gate-0 must have been the rejector.
$c15Errors = [];
try {
    db_execute("DELETE FROM vendors WHERE id = 999994");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='vendor' AND entity_id = 999994");
    ff_smoke_v_set_setting('quickbooks.sync_enabled', '1');

    $r = VendorEnqueuer::enqueue(999994, 'create');

    if ($r !== false) {
        $c15Errors[] = "expected enqueue to return false for missing vendor id, got " . var_export($r, true);
    }
    $row = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='vendor' AND entity_id = ?", [999994]);
    if ($row !== null) {
        $c15Errors[] = "expected NO queue row written; found queue id={$row['id']}";
    }
} catch (Throwable $e) {
    $c15Errors[] = 'C15 threw: ' . $e->getMessage();
} finally {
    ff_smoke_v_set_setting('quickbooks.sync_enabled', '0');
}
if (empty($c15Errors)) {
    echo "PASS C15 VendorEnqueuer gate-0 rejects missing vendor id (S-QBO-ENQUEUER-ELIGIBILITY-GATE)\n";
    $pass++;
} else {
    echo "FAIL C15 " . implode('; ', $c15Errors) . "\n";
    $failures[] = 'C15';
}

// ── C16: S-QBO-ENQUEUER-ELIGIBILITY-GATE — gate-0 rejects soft-deleted ──
$c16Errors = [];
try {
    db_execute("DELETE FROM vendors WHERE id = 999995");
    db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type='vendor' AND entity_id = 999995");
    db_execute(
        "INSERT INTO vendors (id, name, vendor_type, deleted_at) VALUES (999995, ?, 'other', NOW())",
        ['SMOKE C16 Soft-Deleted Vendor']
    );
    $sentinelVendorIds[] = 999995;
    ff_smoke_v_set_setting('quickbooks.sync_enabled', '1');

    $r = VendorEnqueuer::enqueue(999995, 'create');

    if ($r !== false) {
        $c16Errors[] = "expected enqueue to return false for soft-deleted vendor, got " . var_export($r, true);
    }
    $row = db_row("SELECT id FROM acc_qbo_sync_queue WHERE entity_type='vendor' AND entity_id = ?", [999995]);
    if ($row !== null) {
        $c16Errors[] = "expected NO queue row written; found queue id={$row['id']}";
    }
} catch (Throwable $e) {
    $c16Errors[] = 'C16 threw: ' . $e->getMessage();
} finally {
    ff_smoke_v_set_setting('quickbooks.sync_enabled', '0');
}
if (empty($c16Errors)) {
    echo "PASS C16 VendorEnqueuer gate-0 rejects soft-deleted vendor (S-QBO-ENQUEUER-ELIGIBILITY-GATE)\n";
    $pass++;
} else {
    echo "FAIL C16 " . implode('; ', $c16Errors) . "\n";
    $failures[] = 'C16';
}

} finally {
    // ── Self-cleaning ──────────────────────────────────────────
    // Delete in FK-aware order: queue + mapping rows depend on vendors.
    if (!empty($sentinelQueueIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelQueueIds), '?'));
            db_execute("DELETE FROM acc_qbo_sync_queue WHERE id IN ({$ph})", $sentinelQueueIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel queue rows failed: " . $e->getMessage() . "\n";
        }
    }
    // Defensive queue cleanup by sentinel id range — catches rows the
    // smoke triggered indirectly via VendorEnqueuer that weren't
    // captured in our list.
    try {
        db_execute(
            "DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'vendor' AND entity_id BETWEEN 999990 AND 999999"
        );
    } catch (Throwable $e) {
        echo "WARN  defensive queue cleanup failed: " . $e->getMessage() . "\n";
    }

    if (!empty($sentinelMappingIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelMappingIds), '?'));
            db_execute("DELETE FROM acc_qbo_vendor_map WHERE id IN ({$ph})", $sentinelMappingIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel mapping rows failed: " . $e->getMessage() . "\n";
        }
    }
    // Defensive map cleanup by ff_vendor_id range.
    try {
        db_execute(
            "DELETE FROM acc_qbo_vendor_map WHERE ff_vendor_id BETWEEN 999990 AND 999999"
        );
    } catch (Throwable $e) {
        echo "WARN  defensive mapping cleanup failed: " . $e->getMessage() . "\n";
    }

    if (!empty($sentinelVendorIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelVendorIds), '?'));
            db_execute("DELETE FROM vendors WHERE id IN ({$ph})", $sentinelVendorIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel vendor rows failed: " . $e->getMessage() . "\n";
        }
    }

    // Restore mutated settings.
    foreach ($settingsToRestore as $key => $originalValue) {
        try {
            if ($originalValue === null) {
                db_execute("DELETE FROM settings WHERE `key` = ?", [$key]);
            } else {
                db_execute(
                    "INSERT INTO settings (`key`, `value`, is_public) VALUES (?, ?, 0)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    [$key, (string) $originalValue]
                );
            }
        } catch (Throwable $e) {
            echo "WARN  failed to restore setting '{$key}': " . $e->getMessage() . "\n";
        }
    }
}

echo "\nqbo_vendor_push_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\n";
exit(0);
