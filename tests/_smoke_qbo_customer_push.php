<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_customer_push.php
 *
 * S-QBO-6 — Structural + behavioural smoke for the customer push
 * machinery (CustomerPusher + CustomerEnqueuer + dispatcher binding).
 * Runs OFFLINE: no real Intuit HTTP traffic. The pure-function code
 * paths (payload builder, mode gating, idempotency, soft-delete skip)
 * are exercised directly; the actual HTTP round-trip is covered by
 * the operator-side live verification (Part I of the session prompt).
 *
 * Self-cleaning: every synthetic row uses id≥999990 sentinels +
 * sentinel qbo_customer_id strings ('TEST-SMOKE-PUSH-*'). The finally
 * block scrubs both customers + acc_qbo_customer_map + acc_qbo_sync_queue
 * inserts on pass or fail. Settings mutations are bracketed by save +
 * restore so the smoke leaves quickbooks.sync_enabled at its original
 * value (D-CPA-5 — must remain '0' after the smoke).
 *
 * 10 sub-checks:
 *   C1: CustomerPusher class surface — pushCreate + pushUpdate +
 *       buildQboPayload public static, match dispatcher contract
 *   C2: CustomerEnqueuer class surface — enqueue public static
 *   C3: buildQboPayload full FF row → expected QBO payload shape
 *   C4: buildQboPayload minimal FF row (company_name only) →
 *       DisplayName + CompanyName only, no PrimaryEmail / no BillAddr
 *   C5: pushCreate sync mode gate — sync_mode.customer='qbo_to_ff'
 *       returns ['status'=>'skipped_by_mode'] without hitting QBO
 *   C6: pushCreate soft-delete skip — deleted_at non-null returns
 *       ['status'=>'skipped_soft_deleted']
 *   C7: pushCreate idempotency — existing mapping with qbo_customer_id
 *       returns ['status'=>'already_mapped'] (no duplicate POST)
 *   C8: CustomerEnqueuer master kill — sync_enabled='0' returns false
 *       and inserts zero queue rows
 *   C9: CustomerEnqueuer mode kill — sync_enabled='1' + mode='qbo_to_ff'
 *       returns false (no row)
 *  C10: CustomerEnqueuer happy path — sync_enabled='1' + mode='sync'
 *       inserts a queue row with entity_type='customer', operation
 *       matches input, status='queued'
 *
 * Exit 0 on all PASS; exit 1 with diagnostic list on any FAIL.
 *
 * @session S-QBO-6
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §8.1
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\QboPushers\CustomerPusher;
use FleetForge\QboPushers\CustomerEnqueuer;

$failures = [];
$pass     = 0;
$total    = 10;

/** Sentinel customer ids we inserted for synthetic testing. */
$sentinelCustomerIds = [];
/** Sentinel queue ids we inserted directly. */
$sentinelQueueIds    = [];
/** Sentinel mapping ids we inserted directly. */
$sentinelMappingIds  = [];

/** Settings we mutated and must restore. */
$settingsToRestore = [];
function ff_smoke_save_setting(string $key): void {
    global $settingsToRestore;
    if (!array_key_exists($key, $settingsToRestore)) {
        $settingsToRestore[$key] = settings_get($key, null);
    }
}
function ff_smoke_set_setting(string $key, string $val): void {
    ff_smoke_save_setting($key);
    db_execute(
        "INSERT INTO settings (`key`, `value`, is_public) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $val]
    );
}

try {

// ── C1: CustomerPusher surface ─────────────────────────────
$c1Errors = [];
if (!class_exists(CustomerPusher::class)) {
    $c1Errors[] = 'CustomerPusher class not autoloaded';
} else {
    $ref = new ReflectionClass(CustomerPusher::class);
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
    echo "PASS C1  CustomerPusher class surface (pushCreate + pushUpdate + buildQboPayload public static)\n";
    $pass++;
} else {
    echo "FAIL C1  " . implode('; ', $c1Errors) . "\n";
    $failures[] = 'C1';
}

// ── C2: CustomerEnqueuer surface ───────────────────────────
$c2Errors = [];
if (!class_exists(CustomerEnqueuer::class)) {
    $c2Errors[] = 'CustomerEnqueuer class not autoloaded';
} else {
    $ref = new ReflectionClass(CustomerEnqueuer::class);
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
    echo "PASS C2  CustomerEnqueuer class surface (enqueue public static)\n";
    $pass++;
} else {
    echo "FAIL C2  " . implode('; ', $c2Errors) . "\n";
    $failures[] = 'C2';
}

// ── C3: buildQboPayload full row ───────────────────────────
$c3Errors = [];
$fullFf = [
    'id'           => 999990,
    'company_name' => 'Acme Trucking Ltd.',
    'email'        => 'ops@acmetrucking.test',
    'phone'        => '+1-604-555-0100',
    'address'      => '1234 Industrial Way',
    'city'         => 'Burnaby',
    'province'     => 'BC',
    'postal_code'  => 'V5C 1A1',
    'country'      => 'CA',
];
$payload = CustomerPusher::buildQboPayload($fullFf);
foreach ([
    ['DisplayName',                     'Acme Trucking Ltd.'],
    ['CompanyName',                     'Acme Trucking Ltd.'],
] as [$key, $expected]) {
    if (($payload[$key] ?? null) !== $expected) {
        $c3Errors[] = "{$key} mismatch: got " . json_encode($payload[$key] ?? null);
    }
}
if (($payload['PrimaryEmailAddr']['Address'] ?? null) !== 'ops@acmetrucking.test') {
    $c3Errors[] = 'PrimaryEmailAddr.Address missing/mismatch';
}
if (($payload['PrimaryPhone']['FreeFormNumber'] ?? null) !== '+1-604-555-0100') {
    $c3Errors[] = 'PrimaryPhone.FreeFormNumber missing/mismatch';
}
$expectedAddr = [
    'Line1'                  => '1234 Industrial Way',
    'City'                   => 'Burnaby',
    'CountrySubDivisionCode' => 'BC',
    'PostalCode'             => 'V5C 1A1',
    'Country'                => 'CA',
];
foreach ($expectedAddr as $k => $v) {
    if (($payload['BillAddr'][$k] ?? null) !== $v) {
        $c3Errors[] = "BillAddr.{$k} mismatch: got " . json_encode($payload['BillAddr'][$k] ?? null);
    }
}
if (empty($c3Errors)) {
    echo "PASS C3  buildQboPayload full FF row maps DisplayName + CompanyName + PrimaryEmailAddr + PrimaryPhone + BillAddr\n";
    $pass++;
} else {
    echo "FAIL C3  " . implode('; ', $c3Errors) . "\n";
    $failures[] = 'C3';
}

// ── C4: buildQboPayload minimal row ────────────────────────
$c4Errors = [];
$minFf = ['company_name' => 'Solo Driver Inc'];
$minPayload = CustomerPusher::buildQboPayload($minFf);
if (($minPayload['DisplayName'] ?? null) !== 'Solo Driver Inc') {
    $c4Errors[] = 'DisplayName mismatch on minimal row';
}
if (($minPayload['CompanyName'] ?? null) !== 'Solo Driver Inc') {
    $c4Errors[] = 'CompanyName mismatch on minimal row';
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
if (empty($c4Errors)) {
    echo "PASS C4  buildQboPayload minimal row omits empty PrimaryEmail / PrimaryPhone / BillAddr\n";
    $pass++;
} else {
    echo "FAIL C4  " . implode('; ', $c4Errors) . "\n";
    $failures[] = 'C4';
}

// ── C5: pushCreate sync mode gate ──────────────────────────
// Insert a synthetic FF customer at id=999991 (use INSERT to bypass
// the API's company_name uniqueness check + skip mandatory fields).
$c5Errors = [];
try {
    $synthName1 = 'SMOKE-S-QBO-6-C5 ' . bin2hex(random_bytes(4));
    db_execute(
        "INSERT INTO customers (id, company_name) VALUES (999991, ?)",
        [$synthName1]
    );
    $sentinelCustomerIds[] = 999991;

    ff_smoke_set_setting('quickbooks.sync_mode.customer', 'qbo_to_ff');
    $result = CustomerPusher::pushCreate(999991);
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
    echo "PASS C5  pushCreate respects sync_mode.customer='qbo_to_ff' → skipped_by_mode\n";
    $pass++;
} else {
    echo "FAIL C5  " . implode('; ', $c5Errors) . "\n";
    $failures[] = 'C5';
}

// Restore mode to 'sync' for subsequent tests (C5 left it as qbo_to_ff).
ff_smoke_set_setting('quickbooks.sync_mode.customer', 'sync');

// ── C6: pushCreate soft-delete skip ────────────────────────
$c6Errors = [];
try {
    $synthName2 = 'SMOKE-S-QBO-6-C6 ' . bin2hex(random_bytes(4));
    db_execute(
        "INSERT INTO customers (id, company_name, deleted_at) VALUES (999992, ?, NOW())",
        [$synthName2]
    );
    $sentinelCustomerIds[] = 999992;

    $result = CustomerPusher::pushCreate(999992);
    if (($result['status'] ?? null) !== 'skipped_soft_deleted') {
        $c6Errors[] = 'expected status=skipped_soft_deleted, got ' . json_encode($result);
    }
} catch (Throwable $e) {
    $c6Errors[] = 'C6 setup threw: ' . $e->getMessage();
}
if (empty($c6Errors)) {
    echo "PASS C6  pushCreate skips soft-deleted FF customers (deleted_at non-null)\n";
    $pass++;
} else {
    echo "FAIL C6  " . implode('; ', $c6Errors) . "\n";
    $failures[] = 'C6';
}

// ── C7: pushCreate idempotency on already-mapped ───────────
// Pre-insert a mapping row with qbo_customer_id; pushCreate should
// short-circuit with status=already_mapped without HTTP.
$c7Errors = [];
try {
    $synthName3 = 'SMOKE-S-QBO-6-C7 ' . bin2hex(random_bytes(4));
    db_execute(
        "INSERT INTO customers (id, company_name) VALUES (999993, ?)",
        [$synthName3]
    );
    $sentinelCustomerIds[] = 999993;

    $sentinelQboId = 'TEST-SMOKE-PUSH-' . bin2hex(random_bytes(6));
    $mappingId = db_insert('acc_qbo_customer_map', [
        'ff_customer_id'   => 999993,
        'qbo_customer_id'  => $sentinelQboId,
        'qbo_sync_token'   => '0',
        'qbo_display_name' => $synthName3,
        'mapping_status'   => 'mapped',
        'match_confidence' => 'manual',
    ]);
    $sentinelMappingIds[] = $mappingId;

    $beforeCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
        [999993]
    );

    $result = CustomerPusher::pushCreate(999993);
    if (($result['status'] ?? null) !== 'already_mapped') {
        $c7Errors[] = 'expected status=already_mapped, got ' . json_encode($result);
    }
    if (($result['qbo_id'] ?? null) !== $sentinelQboId) {
        $c7Errors[] = 'qbo_id should match the pre-existing mapping, got ' . json_encode($result['qbo_id'] ?? null);
    }

    $afterCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
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

// ── C8: CustomerEnqueuer master kill switch ────────────────
$c8Errors = [];
try {
    ff_smoke_set_setting('quickbooks.sync_enabled', '0');

    $beforeCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'customer'",
        [999991]
    );
    $returned = CustomerEnqueuer::enqueue(999991, 'create');
    if ($returned !== false) {
        $c8Errors[] = 'expected false when sync_enabled=0, got ' . var_export($returned, true);
    }
    $afterCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'customer'",
        [999991]
    );
    if ($afterCount !== $beforeCount) {
        $c8Errors[] = "queue row count changed (before={$beforeCount}, after={$afterCount}) — master kill should be a no-op";
    }
} catch (Throwable $e) {
    $c8Errors[] = 'C8 setup threw: ' . $e->getMessage();
}
if (empty($c8Errors)) {
    echo "PASS C8  CustomerEnqueuer master kill (sync_enabled=0) returns false + no queue insert\n";
    $pass++;
} else {
    echo "FAIL C8  " . implode('; ', $c8Errors) . "\n";
    $failures[] = 'C8';
}

// ── C9: CustomerEnqueuer mode kill ─────────────────────────
$c9Errors = [];
try {
    ff_smoke_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_set_setting('quickbooks.sync_mode.customer', 'qbo_to_ff');

    $beforeCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'customer'",
        [999991]
    );
    $returned = CustomerEnqueuer::enqueue(999991, 'create');
    if ($returned !== false) {
        $c9Errors[] = 'expected false on mode=qbo_to_ff, got ' . var_export($returned, true);
    }
    $afterCount = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_id = ? AND entity_type = 'customer'",
        [999991]
    );
    if ($afterCount !== $beforeCount) {
        $c9Errors[] = "queue row count changed (before={$beforeCount}, after={$afterCount}) — mode kill should be a no-op";
    }
} catch (Throwable $e) {
    $c9Errors[] = 'C9 setup threw: ' . $e->getMessage();
}
if (empty($c9Errors)) {
    echo "PASS C9  CustomerEnqueuer mode kill (sync_mode.customer=qbo_to_ff) returns false + no queue insert\n";
    $pass++;
} else {
    echo "FAIL C9  " . implode('; ', $c9Errors) . "\n";
    $failures[] = 'C9';
}

// ── C10: CustomerEnqueuer happy path ───────────────────────
$c10Errors = [];
try {
    ff_smoke_set_setting('quickbooks.sync_enabled', '1');
    ff_smoke_set_setting('quickbooks.sync_mode.customer', 'sync');

    $returned = CustomerEnqueuer::enqueue(999991, 'create');
    if ($returned !== true) {
        $c10Errors[] = 'expected true on happy path, got ' . var_export($returned, true);
    }
    $row = db_row(
        "SELECT id, entity_type, entity_id, operation, status FROM acc_qbo_sync_queue
          WHERE entity_id = ? AND entity_type = 'customer' AND operation = 'create'
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
        if ($row['entity_type'] !== 'customer') {
            $c10Errors[] = "queue row entity_type='{$row['entity_type']}', expected 'customer'";
        }
        if ($row['operation'] !== 'create') {
            $c10Errors[] = "queue row operation='{$row['operation']}', expected 'create'";
        }
    }
} catch (Throwable $e) {
    $c10Errors[] = 'C10 setup threw: ' . $e->getMessage();
}
if (empty($c10Errors)) {
    echo "PASS C10 CustomerEnqueuer happy path (sync_enabled=1 + mode=sync) inserts queued row\n";
    $pass++;
} else {
    echo "FAIL C10 " . implode('; ', $c10Errors) . "\n";
    $failures[] = 'C10';
}

} finally {
    // ── Self-cleaning ──────────────────────────────────────────
    // Delete in FK-aware order: queue + mapping rows depend on customers.
    if (!empty($sentinelQueueIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelQueueIds), '?'));
            db_execute("DELETE FROM acc_qbo_sync_queue WHERE id IN ({$ph})", $sentinelQueueIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel queue rows failed: " . $e->getMessage() . "\n";
        }
    }
    // Also catch any queue rows the smoke triggered indirectly via
    // CustomerEnqueuer happy-path that weren't captured in our list
    // (defense in depth — entity_id sentinels are still ours).
    try {
        db_execute(
            "DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'customer' AND entity_id BETWEEN 999990 AND 999999"
        );
    } catch (Throwable $e) {
        echo "WARN  defensive queue cleanup failed: " . $e->getMessage() . "\n";
    }

    if (!empty($sentinelMappingIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelMappingIds), '?'));
            db_execute("DELETE FROM acc_qbo_customer_map WHERE id IN ({$ph})", $sentinelMappingIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel mapping rows failed: " . $e->getMessage() . "\n";
        }
    }
    // Defensive map cleanup by ff_customer_id range.
    try {
        db_execute(
            "DELETE FROM acc_qbo_customer_map WHERE ff_customer_id BETWEEN 999990 AND 999999"
        );
    } catch (Throwable $e) {
        echo "WARN  defensive mapping cleanup failed: " . $e->getMessage() . "\n";
    }

    if (!empty($sentinelCustomerIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sentinelCustomerIds), '?'));
            db_execute("DELETE FROM customers WHERE id IN ({$ph})", $sentinelCustomerIds);
        } catch (Throwable $e) {
            echo "WARN  cleanup of sentinel customer rows failed: " . $e->getMessage() . "\n";
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

echo "\nqbo_customer_push_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\n";
exit(0);
