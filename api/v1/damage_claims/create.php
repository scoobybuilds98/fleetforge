<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/create.php
 *
 * Create a new damage claim. Photos are uploaded separately via upload_photo.php
 * after the claim is created (claim ID is required for storage path).
 *
 * Business rules:
 *   - Claim number: DMG-YYYY-NNNNN, gap-free via FOR UPDATE on settings row.
 *   - equipment_unit_id is required and must exist (not soft-deleted).
 *   - customer_id is optional (claim may be pre-customer or unit-only).
 *   - lease_id (optional): if provided, must not be soft-deleted.
 *   - D16: monetary amounts via clean_decimal() / bcmath.
 *   - Initial status is always 'reported'.
 *   - reported_by defaults to the current logged-in user.
 *
 * @method  POST
 * @body    JSON: equipment_unit_id (required), description (required),
 *               severity (required), customer_id?, lease_id?,
 *               damage_location?, estimated_repair_cost?,
 *               customer_liable_amount?, insurance_claim_amount?,
 *               notes?
 * @auth    Session required; require_permission('maintenance','create')
 * @returns 201 { id, claim_number, status }
 *
 * Decisions: D5 (soft delete), D16 (bcmath), §7 (audit log), §9 (atomic counter)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'create');

// -----------------------------------------------------------------------
// 1. Input validation — VALID-2: accumulate every error
// -----------------------------------------------------------------------
$body   = json_body();
$fields = [];

$unitId = clean_int($body['equipment_unit_id'] ?? null);
if (!$unitId) {
    $fields['equipment_unit_id'] = 'Please select an equipment unit.';
}

$description = clean_string($body['description'] ?? null, 5000);
if (!$description) {
    $fields['description'] = 'Description is required.';
}

$severity        = clean_string($body['severity'] ?? null);
$validSeverities = ['minor', 'moderate', 'major', 'total_loss'];
if (!$severity) {
    $fields['severity'] = 'Please select a severity.';
} elseif (!in_array($severity, $validSeverities, true)) {
    $fields['severity'] = 'Please select a valid severity.';
}

// Optional fields
$customerId   = clean_int($body['customer_id'] ?? null);
$customerName = clean_string($body['customer_name'] ?? null, 255);   // free-text fallback
$leaseId      = clean_int($body['lease_id'] ?? null);
$vendorId     = clean_int($body['vendor_id'] ?? null);

// customer_id and customer_name are mutually exclusive — clear name when ID is set
if ($customerId) {
    $customerName = null;
}
$damageLocation = clean_string($body['damage_location'] ?? null);
$notes          = clean_string($body['notes'] ?? null, 5000);

// D16: monetary amounts — non-negative or null
$estimatedRepairCost = null;
if (array_key_exists('estimated_repair_cost', $body)
    && $body['estimated_repair_cost'] !== null
    && $body['estimated_repair_cost'] !== '') {
    $d = clean_decimal((string)$body['estimated_repair_cost']);
    if ($d === null || bccomp($d, '0', 6) < 0) {
        $fields['estimated_repair_cost'] = 'Estimated repair cost cannot be negative.';
    } else {
        $estimatedRepairCost = bcround($d, 2);
    }
}

$customerLiableAmount = null;
if (array_key_exists('customer_liable_amount', $body)
    && $body['customer_liable_amount'] !== null
    && $body['customer_liable_amount'] !== '') {
    $d = clean_decimal((string)$body['customer_liable_amount']);
    if ($d === null || bccomp($d, '0', 6) < 0) {
        $fields['customer_liable_amount'] = 'Customer liable amount cannot be negative.';
    } else {
        $customerLiableAmount = bcround($d, 2);
    }
}

$insuranceClaimAmount = null;
if (array_key_exists('insurance_claim_amount', $body)
    && $body['insurance_claim_amount'] !== null
    && $body['insurance_claim_amount'] !== '') {
    $d = clean_decimal((string)$body['insurance_claim_amount']);
    if ($d === null || bccomp($d, '0', 6) < 0) {
        $fields['insurance_claim_amount'] = 'Insurance claim amount cannot be negative.';
    } else {
        $insuranceClaimAmount = bcround($d, 2);
    }
}

if ($fields) {
    json_validation_error($fields);
}

// -----------------------------------------------------------------------
// 2. Pre-flight: verify related entities exist
// -----------------------------------------------------------------------
$unit = db_row(
    "SELECT id, unit_number FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_validation_error(['equipment_unit_id' => 'Equipment unit not found.'], 'Equipment unit not found.');
}

if ($customerId) {
    $customer = db_row(
        "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
        [$customerId]
    );
    if (!$customer) {
        json_validation_error(['customer_id' => 'Customer not found.'], 'Customer not found.');
    }
} else {
    $customer = null;
}

if ($leaseId) {
    $lease = db_row(
        "SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL",
        [$leaseId]
    );
    if (!$lease) {
        json_validation_error(['lease_id' => 'Lease not found.'], 'Lease not found.');
    }
}

if ($vendorId) {
    $vendor = db_row(
        "SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL",
        [$vendorId]
    );
    if (!$vendor) {
        json_validation_error(['vendor_id' => 'Vendor not found.'], 'Vendor not found.');
    }
} else {
    $vendor = null;
}

// -----------------------------------------------------------------------
// 3. Transaction: generate gap-free number, insert row, audit
// -----------------------------------------------------------------------
$result = null;

db_transaction(function () use (
    $unitId, $customerId, $customerName, $leaseId, $vendorId, $description, $severity,
    $damageLocation, $notes,
    $estimatedRepairCost, $customerLiableAmount, $insuranceClaimAmount,
    $unit, $customer, $vendor, &$result
) {
    // ------------------------------------------------------------------
    // 3a. Gap-free claim number via FOR UPDATE on settings row
    //     Pattern: DMG-YYYY-NNNNN
    // ------------------------------------------------------------------
    $year = date('Y');
    $key  = "damage_claim.next_number.{$year}";

    $settingsRow = db_row(
        "SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE",
        [$key]
    );
    $next        = $settingsRow ? (int) $settingsRow['value'] : 1;
    // WHY: prefix from settings so admin can rebrand without code change
    $prefix      = settings_get('damage_claim.prefix', 'DMG');
    $claimNumber = sprintf('%s-%s-%05d', $prefix, $year, $next);

    if ($settingsRow) {
        db_execute(
            "UPDATE settings SET `value` = ? WHERE `key` = ?",
            [$next + 1, $key]
        );
    } else {
        db_execute(
            "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'damage_claims')",
            [$key, $next + 1]
        );
    }

    // ------------------------------------------------------------------
    // 3b. Insert damage_claim row — initial status always 'reported'
    // ------------------------------------------------------------------
    $claimId = db_insert('damage_claims', [
        'claim_number'          => $claimNumber,
        'equipment_unit_id'     => $unitId,
        'customer_id'           => $customerId,
        'customer_name'         => $customerName,
        'lease_id'              => $leaseId,
        'vendor_id'             => $vendorId,
        'description'           => $description,
        'damage_location'       => $damageLocation,
        'severity'              => $severity,
        'estimated_repair_cost' => $estimatedRepairCost,
        'customer_liable_amount'=> $customerLiableAmount,
        'insurance_claim_amount'=> $insuranceClaimAmount,
        'status'                => 'reported',   // always starts here
        'notes'                 => $notes,
        'reported_by'           => current_user_id(),
    ]);

    // ------------------------------------------------------------------
    // 3c. Audit log — inside transaction (FIX #19 pattern)
    // ------------------------------------------------------------------
    $customerDesc = $customer
        ? " for {$customer['company_name']}"
        : ($customerName ? " for {$customerName}" : '');
    $vendorDesc   = $vendor   ? ", vendor: {$vendor['name']}"      : '';
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'create',
        'module'       => 'maintenance',
        'entity_type'  => 'damage_claim',
        'entity_id'    => $claimId,
        'entity_label' => $claimNumber,
        'notes'        => "Damage claim {$claimNumber} ({$severity}) created for unit {$unit['unit_number']}{$customerDesc}{$vendorDesc}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'id'           => $claimId,
        'claim_number' => $claimNumber,
        'status'       => 'reported',
    ];
});

json_success($result, 201);
