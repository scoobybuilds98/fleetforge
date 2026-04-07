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
// 1. Input validation
// -----------------------------------------------------------------------
$body = json_body();

$unitId = clean_int($body['equipment_unit_id'] ?? null);
if (!$unitId) {
    json_error('MISSING_REQUIRED', 'equipment_unit_id is required.', 422);
}

$description = clean_string($body['description'] ?? null, 5000);
if (!$description) {
    json_error('MISSING_REQUIRED', 'description is required.', 422);
}

$severity = clean_string($body['severity'] ?? null);
$validSeverities = ['minor', 'moderate', 'major', 'total_loss'];
if (!$severity || !in_array($severity, $validSeverities, true)) {
    json_error('VALIDATION_ERROR', 'severity is required and must be one of: ' . implode(', ', $validSeverities) . '.', 422);
}

// Optional fields
$customerId            = clean_int($body['customer_id'] ?? null);
$customerName          = clean_string($body['customer_name'] ?? null, 255);   // free-text fallback
$leaseId               = clean_int($body['lease_id'] ?? null);
$vendorId              = clean_int($body['vendor_id'] ?? null);

// customer_id and customer_name are mutually exclusive — clear name when ID is set
if ($customerId) {
    $customerName = null;
}
$damageLocation        = clean_string($body['damage_location'] ?? null);
$notes                 = clean_string($body['notes'] ?? null, 5000);

// D16: monetary amounts — positive or null
$estimatedRepairCost   = clean_decimal($body['estimated_repair_cost'] ?? null);
$customerLiableAmount  = clean_decimal($body['customer_liable_amount'] ?? null);
$insuranceClaimAmount  = clean_decimal($body['insurance_claim_amount'] ?? null);

if ($estimatedRepairCost !== null) {
    if (bccomp($estimatedRepairCost, '0', 6) < 0) {
        json_error('VALIDATION_ERROR', 'estimated_repair_cost must be a non-negative number.', 422);
    }
    $estimatedRepairCost = bcround($estimatedRepairCost, 2);
}
if ($customerLiableAmount !== null) {
    if (bccomp($customerLiableAmount, '0', 6) < 0) {
        json_error('VALIDATION_ERROR', 'customer_liable_amount must be a non-negative number.', 422);
    }
    $customerLiableAmount = bcround($customerLiableAmount, 2);
}
if ($insuranceClaimAmount !== null) {
    if (bccomp($insuranceClaimAmount, '0', 6) < 0) {
        json_error('VALIDATION_ERROR', 'insurance_claim_amount must be a non-negative number.', 422);
    }
    $insuranceClaimAmount = bcround($insuranceClaimAmount, 2);
}

// -----------------------------------------------------------------------
// 2. Pre-flight: verify related entities exist
// -----------------------------------------------------------------------
$unit = db_row(
    "SELECT id, unit_number FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

if ($customerId) {
    $customer = db_row(
        "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
        [$customerId]
    );
    if (!$customer) {
        json_error('NOT_FOUND', 'Customer not found.', 404);
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
        json_error('NOT_FOUND', 'Lease not found.', 404);
    }
}

if ($vendorId) {
    $vendor = db_row(
        "SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL",
        [$vendorId]
    );
    if (!$vendor) {
        json_error('NOT_FOUND', 'Vendor not found.', 404);
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
