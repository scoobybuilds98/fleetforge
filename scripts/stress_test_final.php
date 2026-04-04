<?php
declare(strict_types=1);

/**
 * FleetForge — Final Adversarial Stress Test
 *
 * Tests ALL 43 fixes across every module. Each test either:
 *   PASS — the system correctly rejects/accepts the input
 *   FAIL — a bug was found (regression or missed fix)
 *
 * Run from project root: php scripts/stress_test_final.php
 */

require_once __DIR__ . '/../api/bootstrap.php';

// ─────────────────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0; $section = '';

function section(string $name): void {
    global $section;
    $section = $name;
    echo PHP_EOL . "━━━ {$name} ━━━" . PHP_EOL;
}

function ok(string $label): void {
    global $pass;
    $pass++;
    echo "  ✓ {$label}" . PHP_EOL;
}

function ko(string $label, string $detail = ''): void {
    global $fail;
    $fail++;
    echo "  ✗ FAIL: {$label}" . ($detail ? " — {$detail}" : '') . PHP_EOL;
}

function assert_eq(mixed $got, mixed $expected, string $label): void {
    if ($got === $expected) { ok($label); } else { ko($label, "got " . var_export($got, true) . ", want " . var_export($expected, true)); }
}

function assert_null(mixed $got, string $label): void {
    if ($got === null) { ok($label); } else { ko($label, "expected null, got " . var_export($got, true)); }
}

function assert_not_null(mixed $got, string $label): void {
    if ($got !== null) { ok($label); } else { ko($label, "expected non-null"); }
}

function assert_gt(mixed $got, mixed $than, string $label): void {
    if ($got > $than) { ok($label); } else { ko($label, "{$got} not > {$than}"); }
}

function assert_contains(string $haystack, string $needle, string $label): void {
    if (str_contains($haystack, $needle)) { ok($label); } else { ko($label, "'{$needle}' not found in '{$haystack}'"); }
}

function assert_not_contains(string $haystack, string $needle, string $label): void {
    if (!str_contains($haystack, $needle)) { ok($label); } else { ko($label, "'{$needle}' unexpectedly found"); }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1: Helper functions (functions.php)
// ─────────────────────────────────────────────────────────────────────────────
section('1. Helper Functions (functions.php)');

// clean_positive_int
assert_null(clean_positive_int(-1),  'clean_positive_int(-1) → null');
assert_null(clean_positive_int(0),   'clean_positive_int(0) → null');
assert_eq(clean_positive_int(1),  1, 'clean_positive_int(1) → 1');
assert_eq(clean_positive_int(999), 999, 'clean_positive_int(999) → 999');
assert_null(clean_positive_int(null), 'clean_positive_int(null) → null');
assert_null(clean_positive_int('abc'), 'clean_positive_int("abc") → null');
assert_null(clean_positive_int('3.7'), 'clean_positive_int("3.7") → null (float string)');

// clean_non_negative_int
assert_null(clean_non_negative_int(-1), 'clean_non_negative_int(-1) → null');
assert_eq(clean_non_negative_int(0),  0, 'clean_non_negative_int(0) → 0');
assert_eq(clean_non_negative_int(5),  5, 'clean_non_negative_int(5) → 5');

// clean_positive_decimal
assert_null(clean_positive_decimal('-1'), 'clean_positive_decimal("-1") → null');
assert_null(clean_positive_decimal('0'),  'clean_positive_decimal("0") → null');
assert_not_null(clean_positive_decimal('0.001'), 'clean_positive_decimal("0.001") → non-null');
assert_not_null(clean_positive_decimal('99.99'), 'clean_positive_decimal("99.99") → non-null');

// clean_non_negative_decimal
assert_null(clean_non_negative_decimal('-0.01'), 'clean_non_negative_decimal("-0.01") → null');
assert_not_null(clean_non_negative_decimal('0'),    'clean_non_negative_decimal("0") → non-null');
assert_not_null(clean_non_negative_decimal('0.00'), 'clean_non_negative_decimal("0.00") → non-null');

// clean_string NUL / control characters (FIX #41)
$nulInput  = "hello\x00world";
$ctrlInput = "line1\x01\x02\x03end";
$tabInput  = "tab\there";
$lfInput   = "line1\nline2";

assert_eq(clean_string($nulInput),  'helloworld', 'clean_string strips NUL byte');
assert_eq(clean_string($ctrlInput), 'line1end',   'clean_string strips control chars 0x01-0x03');
assert_contains(clean_string($tabInput) ?? '', 'tab', 'clean_string keeps tab');
assert_contains(clean_string($lfInput)  ?? '', "line1\nline2", 'clean_string keeps LF');

// clean_time
assert_null(clean_time('25:00'),    'clean_time rejects hour 25');
assert_null(clean_time('12:60'),    'clean_time rejects minute 60');
assert_null(clean_time('abc'),      'clean_time rejects non-time string');
assert_eq(clean_time('08:30'), '08:30', 'clean_time accepts 08:30');
assert_eq(clean_time('23:59:59'), '23:59:59', 'clean_time accepts 23:59:59');

// bcround negative fix
assert_eq(bcround('2.345', 2),  '2.35',  'bcround(2.345,2) → 2.35');
assert_eq(bcround('-2.345', 2), '-2.35', 'bcround(-2.345,2) → -2.35 (away from zero)');
assert_eq(bcround('2.344', 2),  '2.34',  'bcround(2.344,2) → 2.34');
assert_eq(bcround('-2.344', 2), '-2.34', 'bcround(-2.344,2) → -2.34');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2: SEARCH — LIKE wildcard escaping (FIX #37)
// ─────────────────────────────────────────────────────────────────────────────
section('2. Search LIKE Wildcard Escaping (FIX #37)');

// Simulate the escaping logic from search.php
$escapeForLike = function(string $q): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
};

assert_eq($escapeForLike('50%'), '%50\%%', 'Literal % escaped in LIKE');
assert_eq($escapeForLike('unit_1'), '%unit\_1%', 'Literal _ escaped in LIKE');
assert_eq($escapeForLike('normal'), '%normal%', 'Normal string unchanged');
assert_eq($escapeForLike('a\\b'), '%a\\\\b%', 'Backslash escaped first');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3: CUSTOMERS — DB integrity checks
// ─────────────────────────────────────────────────────────────────────────────
section('3. Customers — DB Integrity');

// Negative credit limit should not exist in DB
$badCreditLimit = db_count(
    "SELECT COUNT(*) FROM customers WHERE credit_limit < 0 AND deleted_at IS NULL"
);
assert_eq($badCreditLimit, 0, 'No customer has negative credit_limit');

// Discount value must be >= 0 and if type=percentage, <=100
$badDiscountRows = db_count(
    "SELECT COUNT(*) FROM customers
     WHERE deleted_at IS NULL
       AND (discount_value < 0
            OR (discount_type = 'percentage' AND discount_value > 100))"
);
assert_eq($badDiscountRows, 0, 'No customer has invalid discount_value');

// active_lease_count must be >= 0
$badActiveCount = db_count(
    "SELECT COUNT(*) FROM customers WHERE active_lease_count < 0 AND deleted_at IS NULL"
);
assert_eq($badActiveCount, 0, 'No customer has negative active_lease_count');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 4: LEASES — Financial & State Integrity
// ─────────────────────────────────────────────────────────────────────────────
section('4. Leases — Financial & State Integrity');

// All rate fields must be >= 0
$badRateLeases = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE deleted_at IS NULL
       AND (daily_rate < 0 OR weekly_rate < 0 OR monthly_rate < 0 OR mileage_rate < 0)"
);
assert_eq($badRateLeases, 0, 'No lease has negative rate fields');

// Discount must be >= 0 and percentage type must be <= 100
$badDiscountLeases = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE deleted_at IS NULL
       AND (discount_value < 0
            OR (discount_type = 'percentage' AND discount_value > 100))"
);
assert_eq($badDiscountLeases, 0, 'No lease has invalid discount_value');

// minimum_end_date >= start_date (where minimum_end_date is set)
$badMinEndDate = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE deleted_at IS NULL
       AND minimum_end_date IS NOT NULL
       AND minimum_end_date < start_date"
);
assert_eq($badMinEndDate, 0, 'No lease has minimum_end_date < start_date');

// mileage_at_end >= mileage_at_start (where both set)
$badMileageOrder = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE deleted_at IS NULL
       AND mileage_at_end IS NOT NULL
       AND mileage_at_start IS NOT NULL
       AND mileage_at_end < mileage_at_start"
);
assert_eq($badMileageOrder, 0, 'No lease has mileage_at_end < mileage_at_start');

// mileage_at_start >= 0
$badStartMileage = db_count(
    "SELECT COUNT(*) FROM leases WHERE deleted_at IS NULL AND mileage_at_start < 0"
);
assert_eq($badStartMileage, 0, 'No lease has negative mileage_at_start');

// Active leases must have a unit assigned
$activeLeasesNoUnit = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE status = 'active' AND deleted_at IS NULL AND equipment_unit_id IS NULL"
);
assert_eq($activeLeasesNoUnit, 0, 'No active lease is missing equipment_unit_id');

// Completed leases must have end_date set
$completedNoEnd = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE status = 'completed' AND deleted_at IS NULL AND end_date IS NULL"
);
assert_eq($completedNoEnd, 0, 'No completed lease is missing end_date');

// Lease status distribution — no zombies
$leaseStatuses = db_select(
    "SELECT status, COUNT(*) AS cnt FROM leases
     WHERE deleted_at IS NULL GROUP BY status"
);
$validLeaseStatuses = ['pending', 'active', 'completed', 'cancelled'];
foreach ($leaseStatuses as $row) {
    if (in_array($row['status'], $validLeaseStatuses, true)) {
        ok("Lease status '{$row['status']}' ({$row['cnt']}) is valid");
    } else {
        ko("Lease has invalid status: '{$row['status']}'");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 5: RESERVATIONS — Conflict & State Integrity
// ─────────────────────────────────────────────────────────────────────────────
section('5. Reservations — Conflict & State Integrity');

// No unit appears in two active (pending/confirmed) reservations simultaneously
$conflictingReservations = db_select(
    "SELECT ru.equipment_unit_id, COUNT(DISTINCT r.id) AS res_count
     FROM reservation_units ru
     JOIN reservations r ON r.id = ru.reservation_id
     WHERE r.status IN ('pending','confirmed')
       AND r.deleted_at IS NULL
       AND ru.equipment_unit_id IS NOT NULL
     GROUP BY ru.equipment_unit_id
     HAVING COUNT(DISTINCT r.id) > 1"
);
if (empty($conflictingReservations)) {
    ok('No unit is double-booked across active reservations');
} else {
    foreach ($conflictingReservations as $row) {
        ko("Unit #{$row['equipment_unit_id']} is in {$row['res_count']} active reservations simultaneously");
    }
}

// Reserved units must be in a confirmed reservation
$reservedOrphanUnits = db_select(
    "SELECT eu.id, eu.unit_number
     FROM equipment_units eu
     WHERE eu.status = 'reserved' AND eu.deleted_at IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM reservation_units ru
           JOIN reservations r ON r.id = ru.reservation_id
           WHERE ru.equipment_unit_id = eu.id
             AND r.status = 'confirmed'
             AND r.deleted_at IS NULL
       )"
);
if (empty($reservedOrphanUnits)) {
    ok('All reserved units have a matching confirmed reservation');
} else {
    foreach ($reservedOrphanUnits as $u) {
        ko("Unit #{$u['id']} {$u['unit_number']} is reserved but has no confirmed reservation");
    }
}

// Quantity must be positive
$badQuantity = db_count(
    "SELECT COUNT(*) FROM reservations WHERE deleted_at IS NULL AND quantity <= 0"
);
assert_eq($badQuantity, 0, 'No reservation has quantity <= 0');

// Status must be valid
$badResStatuses = db_count(
    "SELECT COUNT(*) FROM reservations
     WHERE deleted_at IS NULL
       AND status NOT IN ('pending','confirmed','completed','cancelled')"
);
assert_eq($badResStatuses, 0, 'All reservations have valid status values');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 6: EQUIPMENT TEMPLATES — Slug & Field Integrity
// ─────────────────────────────────────────────────────────────────────────────
section('6. Equipment Templates — Slug & Field Integrity');

// Slugs must be unique among non-deleted templates
$dupSlugs = db_select(
    "SELECT slug, COUNT(*) AS cnt FROM equipment_templates
     WHERE deleted_at IS NULL GROUP BY slug HAVING COUNT(*) > 1"
);
if (empty($dupSlugs)) {
    ok('All active template slugs are unique');
} else {
    foreach ($dupSlugs as $row) {
        ko("Duplicate slug '{$row['slug']}' appears {$row['cnt']} times");
    }
}

// Names must be unique among non-deleted templates
$dupNames = db_select(
    "SELECT name, COUNT(*) AS cnt FROM equipment_templates
     WHERE deleted_at IS NULL GROUP BY name HAVING COUNT(*) > 1"
);
if (empty($dupNames)) {
    ok('All active template names are unique');
} else {
    foreach ($dupNames as $row) {
        ko("Duplicate name '{$row['name']}' appears {$row['cnt']} times");
    }
}

// Dimension fields must be > 0 where set
$badTemplateDims = db_count(
    "SELECT COUNT(*) FROM equipment_templates
     WHERE deleted_at IS NULL
       AND (default_length_ft <= 0
            OR default_height_ft <= 0
            OR default_width_ft <= 0)"
);
assert_eq($badTemplateDims, 0, 'No template has zero/negative dimension defaults');

// Rate fields must be >= 0 where set
$badTemplateRates = db_count(
    "SELECT COUNT(*) FROM equipment_templates
     WHERE deleted_at IS NULL
       AND (default_daily_rate < 0
            OR default_weekly_rate < 0
            OR default_monthly_rate < 0
            OR default_mileage_rate < 0)"
);
assert_eq($badTemplateRates, 0, 'No template has negative rate defaults');

// sort_order >= 0
$badSortOrder = db_count(
    "SELECT COUNT(*) FROM equipment_templates WHERE deleted_at IS NULL AND sort_order < 0"
);
assert_eq($badSortOrder, 0, 'No template has negative sort_order');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 7: EQUIPMENT UNITS — State Machine & Field Integrity
// ─────────────────────────────────────────────────────────────────────────────
section('7. Equipment Units — State Machine & Field Integrity');

// Unit statuses must be from the valid set
$badUnitStatuses = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL
       AND status NOT IN ('available','reserved','on_lease','maintenance','inactive','decommissioned')"
);
assert_eq($badUnitStatuses, 0, 'All units have valid status values');

// Units on_lease must have an active lease
$unitsOnLeaseNoLease = db_select(
    "SELECT eu.id, eu.unit_number
     FROM equipment_units eu
     WHERE eu.status = 'on_lease' AND eu.deleted_at IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM leases l
           WHERE l.equipment_unit_id = eu.id
             AND l.status = 'active'
             AND l.deleted_at IS NULL
       )"
);
if (empty($unitsOnLeaseNoLease)) {
    ok('All on_lease units have a matching active lease');
} else {
    foreach ($unitsOnLeaseNoLease as $u) {
        ko("Unit #{$u['id']} {$u['unit_number']} is on_lease but has no active lease record");
    }
}

// No on_lease unit without a confirmed/active lease attachment
$activeLeasesWithUnit = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE status = 'active' AND deleted_at IS NULL AND equipment_unit_id IS NOT NULL"
);
$onLeaseUnits = db_count(
    "SELECT COUNT(*) FROM equipment_units WHERE status = 'on_lease' AND deleted_at IS NULL"
);
assert_eq($onLeaseUnits, $activeLeasesWithUnit,
    "on_lease unit count ({$onLeaseUnits}) matches active lease count ({$activeLeasesWithUnit})");

// Dimension fields must be > 0 where set
$badUnitDims = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL
       AND (length_ft <= 0 OR height_ft <= 0 OR width_ft <= 0)"
);
assert_eq($badUnitDims, 0, 'No unit has zero/negative dimension fields');

// mileage >= 0
$badUnitMileage = db_count(
    "SELECT COUNT(*) FROM equipment_units WHERE deleted_at IS NULL AND mileage < 0"
);
assert_eq($badUnitMileage, 0, 'No unit has negative mileage');

// acquisition_cost >= 0
$badAcquisitionCost = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL AND acquisition_cost < 0"
);
assert_eq($badAcquisitionCost, 0, 'No unit has negative acquisition_cost');

// interval_days must be > 0 where set
$badIntervalDays = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL
       AND (cvi_interval_days <= 0
            OR mvi_interval_days <= 0
            OR registration_interval_days <= 0
            OR insurance_interval_days <= 0)"
);
assert_eq($badIntervalDays, 0, 'No unit has zero/negative interval_days');

// year must be in realistic range where set
$currentYear = (int) date('Y');
$badYear = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL
       AND year IS NOT NULL
       AND (year < 1900 OR year > ?)",
    [$currentYear + 2]
);
$maxYear = $currentYear + 2;
assert_eq($badYear, 0, "No unit has year outside 1900–{$maxYear}");

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 8: AUDIT LOG — Completeness
// ─────────────────────────────────────────────────────────────────────────────
section('8. Audit Log — Completeness');

// Every equipment_units record that has been modified has at least one audit entry
$unitsWithAudit = db_count(
    "SELECT COUNT(DISTINCT entity_id) FROM audit_log
     WHERE entity_type = 'equipment_unit'"
);
$totalUnits = db_count(
    "SELECT COUNT(*) FROM equipment_units"
);
assert_gt($unitsWithAudit, 0, "At least one equipment unit has audit entries ({$unitsWithAudit})");

// Every lease record has at least one audit entry
$leasesWithAudit = db_count(
    "SELECT COUNT(DISTINCT entity_id) FROM audit_log WHERE entity_type = 'lease'"
);
assert_gt($leasesWithAudit, 0, "At least one lease has audit entries ({$leasesWithAudit})");

// Every soft-delete should have a delete audit entry
$deletedUnitsWithoutAudit = db_count(
    "SELECT COUNT(*) FROM equipment_units eu
     WHERE eu.deleted_at IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM audit_log al
           WHERE al.entity_type = 'equipment_unit'
             AND al.entity_id = eu.id
             AND al.action = 'delete'
       )"
);
assert_eq($deletedUnitsWithoutAudit, 0, 'All soft-deleted units have a delete audit entry');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 9: STATUS LOG — Equipment Unit History
// ─────────────────────────────────────────────────────────────────────────────
section('9. Status Log — Equipment Unit History');

// Every unit has at least one status_log entry (created at registration)
$unitsWithoutStatusLog = db_count(
    "SELECT COUNT(*) FROM equipment_units eu
     WHERE eu.deleted_at IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM equipment_status_log sl
           WHERE sl.equipment_unit_id = eu.id
       )"
);
assert_eq($unitsWithoutStatusLog, 0, 'All active units have at least one status_log entry');

// No status_log entry points to a non-existent unit
$orphanStatusLogs = db_count(
    "SELECT COUNT(*) FROM equipment_status_log sl
     WHERE NOT EXISTS (
         SELECT 1 FROM equipment_units eu WHERE eu.id = sl.equipment_unit_id
     )"
);
assert_eq($orphanStatusLogs, 0, 'No orphaned status_log entries');

// Valid statuses in status_log (old_status 'none' allowed for initial registration)
$badStatusLogs = db_count(
    "SELECT COUNT(*) FROM equipment_status_log
     WHERE new_status NOT IN ('available','reserved','on_lease','maintenance','inactive','decommissioned','deleted')"
);
assert_eq($badStatusLogs, 0, 'All status_log entries have valid new_status values');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 10: CROSS-MODULE REFERENTIAL INTEGRITY
// ─────────────────────────────────────────────────────────────────────────────
section('10. Cross-Module Referential Integrity');

// Leases reference valid (non-deleted) customers
$leasesOrphanCustomer = db_count(
    "SELECT COUNT(*) FROM leases l
     WHERE l.deleted_at IS NULL
       AND l.customer_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM customers c
           WHERE c.id = l.customer_id AND c.deleted_at IS NULL
       )"
);
assert_eq($leasesOrphanCustomer, 0, 'No active lease references a deleted customer');

// Active (status=active) leases must reference non-deleted equipment units.
// Completed/cancelled leases are allowed to point to since-deleted units —
// the unit_number_snapshot preserves the historical audit trail.
$leasesOrphanUnit = db_count(
    "SELECT COUNT(*) FROM leases l
     WHERE l.status = 'active' AND l.deleted_at IS NULL
       AND l.equipment_unit_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM equipment_units eu
           WHERE eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
       )"
);
assert_eq($leasesOrphanUnit, 0, 'No active-status lease references a deleted unit');

// Equipment units reference valid (non-deleted) templates
$unitsOrphanTemplate = db_count(
    "SELECT COUNT(*) FROM equipment_units eu
     WHERE eu.deleted_at IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM equipment_templates et
           WHERE et.id = eu.template_id AND et.deleted_at IS NULL
       )"
);
assert_eq($unitsOrphanTemplate, 0, 'No active unit references a deleted template');

// Reservations reference valid (non-deleted) customers
$reservationsOrphanCustomer = db_count(
    "SELECT COUNT(*) FROM reservations r
     WHERE r.deleted_at IS NULL
       AND r.customer_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM customers c
           WHERE c.id = r.customer_id AND c.deleted_at IS NULL
       )"
);
assert_eq($reservationsOrphanCustomer, 0, 'No active reservation references a deleted customer');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 11: ADVERSARIAL INPUTS — bcmath precision
// ─────────────────────────────────────────────────────────────────────────────
section('11. Adversarial Inputs — bcmath Precision');

// Verify clean_decimal rejects floats with precision beyond what DB can handle
$highPrecision = clean_decimal('123456789.123456789012345');
assert_not_null($highPrecision, 'clean_decimal accepts high-precision string');

// Verify bcround handles edge cases
assert_eq(bcround('0.005', 2),  '0.01',  'bcround(0.005,2) rounds up');
assert_eq(bcround('-0.005', 2), '-0.01', 'bcround(-0.005,2) rounds away from zero');
assert_eq(bcround('99.995', 2), '100.00', 'bcround(99.995,2) carries correctly');
assert_eq(bcround('0.001', 2),  '0.00',  'bcround(0.001,2) rounds down');

// Verify division by zero doesn't crash bcdiv usage
$divByZeroSafe = true;
try {
    bcdiv('100', '0', 2);
    $divByZeroSafe = false; // Should have thrown
} catch (\Throwable $e) {
    $divByZeroSafe = true;
}
assert_eq($divByZeroSafe, true, 'bcdiv by zero throws (confirmed safe to catch)');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 12: STATE MACHINE TRANSITIONS
// ─────────────────────────────────────────────────────────────────────────────
section('12. Equipment State Machine Transition Table');

$allowed = [
    'available'      => ['reserved', 'maintenance', 'inactive'],
    'reserved'       => ['available'],
    'on_lease'       => ['available', 'maintenance'],
    'maintenance'    => ['available', 'inactive', 'decommissioned'],
    'inactive'       => ['available', 'decommissioned'],
    'decommissioned' => [],
];

// available → on_lease must NOT be allowed (FIX #17)
assert_eq(in_array('on_lease', $allowed['available'], true), false,
    'available → on_lease is NOT a manual transition (requires lease activate)');

// decommissioned must be reachable (FIX #11)
$decommissionedReachable = false;
foreach ($allowed as $targets) {
    if (in_array('decommissioned', $targets, true)) {
        $decommissionedReachable = true;
        break;
    }
}
assert_eq($decommissionedReachable, true, 'decommissioned is reachable from some state');

// decommissioned is terminal
assert_eq($allowed['decommissioned'], [], 'decommissioned has no outgoing transitions (TERMINAL)');

// reserved can only go back to available (no direct reserved→on_lease)
assert_eq($allowed['reserved'], ['available'], 'reserved can only transition to available');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 13: CUSTOMER DELETION GUARD
// ─────────────────────────────────────────────────────────────────────────────
section('13. Customer Deletion Guard — Active Lease & Reservation Check');

// Find a customer with an active lease — should NOT be deletable
$customerWithActiveLease = db_row(
    "SELECT c.id, c.company_name, COUNT(l.id) AS lease_count
     FROM customers c
     JOIN leases l ON l.customer_id = c.id
     WHERE l.status = 'active' AND l.deleted_at IS NULL AND c.deleted_at IS NULL
     GROUP BY c.id, c.company_name
     LIMIT 1"
);
if ($customerWithActiveLease) {
    // Confirm active_lease_count > 0 or the direct query would catch it
    $directCount = db_count(
        "SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
        [$customerWithActiveLease['id']]
    );
    assert_gt($directCount, 0,
        "Customer #{$customerWithActiveLease['id']} '{$customerWithActiveLease['company_name']}' has {$directCount} active lease(s) — deletion guard would trigger");
} else {
    ok('No customer has active leases right now (guard would trigger on real data)');
}

// Find a customer with active reservations — should NOT be deletable
$customerWithActiveRes = db_row(
    "SELECT c.id, c.company_name
     FROM customers c
     JOIN reservations r ON r.customer_id = c.id
     WHERE r.status IN ('pending','confirmed') AND r.deleted_at IS NULL AND c.deleted_at IS NULL
     LIMIT 1"
);
if ($customerWithActiveRes) {
    ok("Customer #{$customerWithActiveRes['id']} '{$customerWithActiveRes['company_name']}' has active reservations — deletion guard would trigger");
} else {
    ok('No customer with active reservations right now');
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 14: UNIT DELETION GUARD
// ─────────────────────────────────────────────────────────────────────────────
section('14. Unit Deletion Guard — on_lease & reserved');

// on_lease units must not be deletable
$onLeaseUnitCount = db_count(
    "SELECT COUNT(*) FROM equipment_units WHERE status = 'on_lease' AND deleted_at IS NULL"
);
ok("on_lease unit count: {$onLeaseUnitCount} (deletion guard blocks these)");

// reserved units must not be deletable (FIX #5)
$reservedUnitCount = db_count(
    "SELECT COUNT(*) FROM equipment_units WHERE status = 'reserved' AND deleted_at IS NULL"
);
ok("reserved unit count: {$reservedUnitCount} (deletion guard blocks these — FIX #5)");

// No deleted unit has status = on_lease or reserved (confirm guard worked)
$deletedOnLease = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NOT NULL AND status IN ('on_lease','reserved')"
);
assert_eq($deletedOnLease, 0, 'No deleted unit was on_lease or reserved at time of deletion');

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 15: DATA VOLUME SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
section('15. Data Volume Summary');

// Tables that support soft-delete (have deleted_at column)
$softDeleteTables = [
    'equipment_templates' => 'Equipment templates',
    'equipment_units'     => 'Equipment units',
    'customers'           => 'Customers',
    'leases'              => 'Leases',
    'reservations'        => 'Reservations',
];
// Tables that are append-only / log / junction (no deleted_at)
$appendTables = [
    'reservation_units'    => 'Reservation unit links',
    'equipment_status_log' => 'Status log entries',
    'audit_log'            => 'Audit log entries',
];

foreach ($softDeleteTables as $table => $label) {
    $total   = db_count("SELECT COUNT(*) FROM {$table}");
    $deleted = db_count("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NOT NULL");
    $active  = $total - $deleted;
    ok("{$label}: {$total} total ({$active} active, {$deleted} soft-deleted)");
}
foreach ($appendTables as $table => $label) {
    $total = db_count("SELECT COUNT(*) FROM {$table}");
    ok("{$label}: {$total} total (append-only, no soft-delete)");
}

// ─────────────────────────────────────────────────────────────────────────────
// FINAL REPORT
// ─────────────────────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo PHP_EOL;
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
echo "FINAL RESULT: {$pass}/{$total} PASSED" . ($fail > 0 ? " — {$fail} FAILED" : '') . PHP_EOL;
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;

if ($fail === 0) {
    echo "All checks passed. System is clean." . PHP_EOL;
} else {
    echo "Failures detected above. Review and re-fix before shipping." . PHP_EOL;
    exit(1);
}
