<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Create API
 *
 * @file        api/v1/leases/create.php
 * @description Creates a new lease in 'pending' status.
 *
 *              Concurrency (D20): acquires FOR UPDATE lock on equipment_unit
 *              before checking availability — prevents two concurrent requests
 *              from double-leasing the same unit.
 *
 *              Snapshots frozen at creation: customer_name_snapshot,
 *              company_name_snapshot, unit_number_snapshot, template_name_snapshot,
 *              equipment_snapshot_json. These persist even if the customer/unit
 *              is later soft-deleted.
 *
 *              Tax rates (D11): looked up from customer's tax_rate_id at creation
 *              time and frozen on the lease (tax_rate_gst, tax_rate_pst, tax_rate_hst).
 *
 *              Contract number format: CN-XXXXXX-YYYY (generate_random_code(6) + year).
 *              De-duplicated with numeric suffix if collision (unlikely but safe).
 *
 * @method      POST
 * @body        JSON — customer_id, equipment_unit_id, start_date (required)
 *              daily_rate, weekly_rate, monthly_rate, mileage_rate (at least one rate required)
 *              Optional: end_date, currency, mileage_unit, billing_cycle,
 *              gst_exempt, pst_exempt, discount_type, discount_value,
 *              insurance_opt_in, insurance_cost, warranty_opt_in, warranty_cost,
 *              po_number, notes, internal_notes, estimated_mileage,
 *              mileage_at_start, rate_notes, minimum_end_date
 * @auth        Session required; require_permission('leases','create')
 * @returns     201 { id, contract_number } | 409 UNIT_UNAVAILABLE | 422 validation errors
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases, §12 billing
 * @decisions   D14 (day counting), D16 (bcmath), D19 (optimistic lock), D20 (FOR UPDATE)
 * @session     S007
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'create');

$body = json_body();

// ── Required fields ────────────────────────────────────────────
$customerId    = clean_int($body['customer_id'] ?? null);
$unitId        = clean_int($body['equipment_unit_id'] ?? null);
$startDate     = clean_date($body['start_date'] ?? null);
$errors        = [];

if (!$customerId)  $errors['customer_id']        = 'Customer is required.';
if (!$unitId)      $errors['equipment_unit_id']   = 'Equipment unit is required.';
if (!$startDate)   $errors['start_date']          = 'Start date is required.';

if ($errors) {
    json_error('VALIDATION_ERROR', 'Validation failed.', 422, ['errors' => $errors]);
}

// ── Rate fields — at least one must be > 0; negatives rejected ────
// FIX #1 (negative rates), FIX #2 (all-zero rates), FIX #6 (discount bounds)
$dailyRate    = clean_non_negative_decimal($body['daily_rate']   ?? null) ?? '0.00';
$weeklyRate   = clean_non_negative_decimal($body['weekly_rate']  ?? null) ?? '0.00';
$monthlyRate  = clean_non_negative_decimal($body['monthly_rate'] ?? null) ?? '0.00';
$mileageRate  = clean_non_negative_decimal($body['mileage_rate'] ?? null) ?? '0.0000';
// Enforce: at least one rate must be positive (spec §7.5 docblock)
if (
    bccomp($dailyRate, '0', 4) <= 0 &&
    bccomp($weeklyRate, '0', 4) <= 0 &&
    bccomp($monthlyRate, '0', 4) <= 0 &&
    bccomp($mileageRate, '0', 4) <= 0
) {
    json_error('VALIDATION_ERROR',
        'At least one rate (daily, weekly, monthly, or mileage) must be greater than zero.', 422,
        ['errors' => ['daily_rate' => 'At least one rate must be greater than zero.']]);
}

// ── Optional fields ────────────────────────────────────────────
$endDate        = clean_date($body['end_date'] ?? null);
$currency       = in_array($body['currency'] ?? '', ['CAD','USD']) ? $body['currency'] : 'CAD';
$mileageUnit    = in_array($body['mileage_unit'] ?? '', ['km','miles']) ? $body['mileage_unit'] : 'km';
$billingCycle   = in_array($body['billing_cycle'] ?? '', ['monthly','on_close_only']) ? $body['billing_cycle'] : 'monthly';
$gstExempt      = isset($body['gst_exempt']) ? (bool) $body['gst_exempt'] : null;
$pstExempt      = isset($body['pst_exempt']) ? (bool) $body['pst_exempt'] : null;
$discountType   = in_array($body['discount_type'] ?? '', ['none','percentage','flat']) ? $body['discount_type'] : 'none';
// FIX #5 / #6: discount must be >= 0; percentage capped at 100
$discountValue  = clean_non_negative_decimal($body['discount_value'] ?? null) ?? '0.0000';
if ($discountType === 'percentage' && bccomp($discountValue, '100', 4) > 0) {
    json_error('VALIDATION_ERROR',
        'Percentage discount cannot exceed 100%.', 422,
        ['errors' => ['discount_value' => 'Percentage discount cannot exceed 100%.']]);
}
$insuranceOptIn = isset($body['insurance_opt_in']) ? (bool) $body['insurance_opt_in'] : false;
// FIX #7: insurance/warranty costs must be >= 0
$insuranceCost  = clean_non_negative_decimal($body['insurance_cost'] ?? null) ?? '0.00';
$warrantyOptIn  = isset($body['warranty_opt_in']) ? (bool) $body['warranty_opt_in'] : false;
$warrantyCost   = clean_non_negative_decimal($body['warranty_cost'] ?? null) ?? '0.00';
$poNumber       = clean_string($body['po_number'] ?? null, 100);
$notes          = clean_string($body['notes'] ?? null, 5000);
$internalNotes  = clean_string($body['internal_notes'] ?? null, 5000);
$rateNotes      = clean_string($body['rate_notes'] ?? null, 5000);
// FIX #4: estimated_mileage must be >= 0
$estimatedMileage  = clean_non_negative_decimal($body['estimated_mileage'] ?? null) ?? '0.00';
// FIX #3: mileage_at_start must be >= 0
$mileageAtStart    = clean_non_negative_int($body['mileage_at_start'] ?? null);
$minimumEndDate    = clean_date($body['minimum_end_date'] ?? null);

// ── Date validation ─────────────────────────────────────────────
if ($endDate && $endDate < $startDate) {
    json_error('VALIDATION_ERROR', 'end_date must be on or after start_date.', 422,
        ['errors' => ['end_date' => 'End date must be on or after start date.']]);
}
// FIX #8: minimum_end_date must be >= start_date
if ($minimumEndDate && $minimumEndDate < $startDate) {
    json_error('VALIDATION_ERROR', 'minimum_end_date must be on or after start_date.', 422,
        ['errors' => ['minimum_end_date' => 'Minimum end date must be on or after start date.']]);
}

// ── Fetch customer (for snapshot + tax defaults) ───────────────
$customer = db_row(
    "SELECT id, contact_name, company_name, province, currency, mileage_unit,
            gst_exempt, pst_exempt, gst_exempt_expiry, pst_exempt_expiry,
            billing_cycle, discount_type, discount_value, tax_rate_id
     FROM customers
     WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// ── Resolve tax exemption (customer defaults if not overridden) ─
// D22: gst_exempt and pst_exempt are independent, frozen on lease at creation
$gstExemptFinal = $gstExempt ?? (bool) $customer['gst_exempt'];
$pstExemptFinal = $pstExempt ?? (bool) $customer['pst_exempt'];
// FIX #10: check exemption expiry against start_date (not today), because the
// exemption must still be valid when the lease actually starts, not just today.
if ($customer['gst_exempt_expiry'] && $customer['gst_exempt_expiry'] < $startDate) {
    $gstExemptFinal = false; // D22: expired by lease start date = not exempt
}
if ($customer['pst_exempt_expiry'] && $customer['pst_exempt_expiry'] < $startDate) {
    $pstExemptFinal = false;
}

// ── Look up tax rates (D11: at creation time from tax_rates table) ─
$taxRateGst = '0.0000';
$taxRatePst = '0.0000';
$taxRateHst = '0.0000';
if ($customer['tax_rate_id']) {
    $taxRow = db_row(
        "SELECT gst_rate, pst_rate, hst_rate FROM tax_rates
         WHERE id = ? AND is_active = 1",
        [$customer['tax_rate_id']]
    );
    if ($taxRow) {
        $taxRateGst = $taxRow['gst_rate'];
        $taxRatePst = $taxRow['pst_rate'];
        $taxRateHst = $taxRow['hst_rate'];
    }
}

// ── Build contract number: PREFIX-XXXXXX-YYYY ─────────────────
// WHY: prefix from settings so admin can rebrand without code change
$leasePrefix     = settings_get('lease.prefix', 'CN');
$year            = date('Y');
$contractNumber  = $leasePrefix . '-' . generate_random_code(6) . '-' . $year;
$attempt         = 0;
// De-duplicate on collision (extremely rare with 6 char A-Z0-9 space)
while (db_exists('leases', 'contract_number = ?', [$contractNumber])) {
    $attempt++;
    if ($attempt > 20) {
        json_error('SERVER_ERROR', 'Could not generate unique contract number.', 500);
    }
    $contractNumber = $leasePrefix . '-' . generate_random_code(6) . '-' . $year;
}

// ── Transaction: FOR UPDATE on unit + create lease ─────────────
$leaseId = null;

db_transaction(function () use (
    $unitId, $customerId, $contractNumber, $customer,
    $startDate, $endDate, $minimumEndDate,
    $dailyRate, $weeklyRate, $monthlyRate, $mileageRate, $rateNotes,
    $currency, $mileageUnit, $billingCycle,
    $gstExemptFinal, $pstExemptFinal,
    $taxRateGst, $taxRatePst, $taxRateHst,
    $discountType, $discountValue,
    $insuranceOptIn, $insuranceCost, $warrantyOptIn, $warrantyCost,
    $poNumber, $notes, $internalNotes,
    $estimatedMileage, $mileageAtStart,
    &$leaseId
) {
    // D20: FOR UPDATE — lock the unit row before status check
    // Prevents two concurrent create requests from both passing the availability check
    // equipment_units has no mileage_unit column — that field lives on customers/leases/templates
    $unit = db_row(
        "SELECT u.id, u.unit_number, u.status, u.vin, u.year, u.length_ft, u.width_ft,
                u.height_ft, u.axle_count, u.weight_capacity_lbs, u.license_plate,
                u.license_state, u.tracking_provider, u.mileage,
                t.name AS template_name, t.category
         FROM equipment_units u
         JOIN equipment_templates t ON t.id = u.template_id AND t.deleted_at IS NULL
         WHERE u.id = ? AND u.deleted_at IS NULL
         FOR UPDATE",
        [$unitId]
    );

    if (!$unit) {
        json_error('NOT_FOUND', 'Equipment unit not found.', 404);
    }

    if ($unit['status'] !== 'available') {
        json_error('UNIT_UNAVAILABLE',
            "Unit {$unit['unit_number']} is not available (status: {$unit['status']}).", 409);
    }

    // ── Build snapshots ────────────────────────────────────────
    $customerNameSnapshot  = $customer['contact_name'] ?? null;
    $companyNameSnapshot   = $customer['company_name'];
    $unitNumberSnapshot    = $unit['unit_number'];
    $templateNameSnapshot  = $unit['template_name'];

    // Equipment snapshot JSON — captures unit specs at lease creation
    $equipmentSnapshotJson = json_encode([
        'unit_number'         => $unit['unit_number'],
        'template_name'       => $unit['template_name'],
        'category'            => $unit['category'],
        'vin'                 => $unit['vin'],
        'year'                => $unit['year'],
        'length_ft'           => $unit['length_ft'],
        'width_ft'            => $unit['width_ft'],
        'height_ft'           => $unit['height_ft'],
        'axle_count'          => $unit['axle_count'],
        'weight_capacity_lbs' => $unit['weight_capacity_lbs'],
        'license_plate'       => $unit['license_plate'],
        'license_state'       => $unit['license_state'],
        'tracking_provider'   => $unit['tracking_provider'],
        'mileage_at_snapshot' => $unit['mileage'], // equipment_units.mileage (odometer INT at snapshot time)
    ]);

    // ── Insert lease (status = 'pending') ──────────────────────
    $leaseId = db_insert('leases', [
        'contract_number'          => $contractNumber,
        'customer_id'              => $customerId,
        'equipment_unit_id'        => $unitId,
        'customer_name_snapshot'   => $customerNameSnapshot,
        'company_name_snapshot'    => $companyNameSnapshot,
        'unit_number_snapshot'     => $unitNumberSnapshot,
        'template_name_snapshot'   => $templateNameSnapshot,
        'equipment_snapshot_json'  => $equipmentSnapshotJson,
        'status'                   => 'pending',
        'start_date'               => $startDate,
        'end_date'                 => $endDate,
        'minimum_end_date'         => $minimumEndDate,
        'daily_rate'               => $dailyRate,
        'weekly_rate'              => $weeklyRate,
        'monthly_rate'             => $monthlyRate,
        'mileage_rate'             => $mileageRate,
        'rate_notes'               => $rateNotes,
        'currency'                 => $currency,
        'mileage_unit'             => $mileageUnit,
        'billing_cycle'            => $billingCycle,
        'gst_exempt'               => $gstExemptFinal ? 1 : 0,
        'pst_exempt'               => $pstExemptFinal ? 1 : 0,
        'tax_exempt'               => ($gstExemptFinal && $pstExemptFinal) ? 1 : 0,
        'tax_rate_gst'             => $taxRateGst,
        'tax_rate_pst'             => $taxRatePst,
        'tax_rate_hst'             => $taxRateHst,
        'discount_type'            => $discountType,
        'discount_value'           => $discountValue,
        'insurance_opt_in'         => $insuranceOptIn ? 1 : 0,
        'insurance_cost'           => $insuranceCost,
        'warranty_opt_in'          => $warrantyOptIn ? 1 : 0,
        'warranty_cost'            => $warrantyCost,
        'po_number'                => $poNumber,
        'notes'                    => $notes,
        'internal_notes'           => $internalNotes,
        'estimated_mileage'        => $estimatedMileage,
        'mileage_at_start'         => $mileageAtStart,
        'created_by'               => current_user_id(),
        'updated_by'               => current_user_id(),
    ]);

    // ── FIX #16: Reserve unit — prevents a second pending lease ──
    // Unit status: available → reserved. Activate moves it to on_lease.
    // Cancel moves it back to available. This ensures UNIT_UNAVAILABLE
    // fires on a second create attempt for the same unit.
    db_execute(
        "UPDATE equipment_units SET status = 'reserved', updated_by = ?, updated_at = NOW() WHERE id = ?",
        [current_user_id(), $unitId]
    );
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $unitId,
        'old_status'         => 'available',
        'new_status'         => 'reserved',
        'reason'             => "Lease {$contractNumber} created — unit reserved pending activation",
        'changed_by'         => current_user()['name'] ?? 'system',
        'changed_by_user_id' => current_user_id(),
    ]);

    // ── lease_status_log: record initial status transition ─────
    db_insert('lease_status_log', [
        'lease_id'   => $leaseId,
        'old_status' => '',
        'new_status' => 'pending',
        'notes'      => 'Lease created',
        'changed_by' => current_user()['name'] ?? 'system',
        'user_id'    => current_user_id(),
    ]);

    // ── audit_log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $leaseId,
        'entity_label' => $contractNumber,
        'notes'        => "Lease {$contractNumber} created for {$companyNameSnapshot}",
        'old_values'   => null,
        'new_values'   => json_encode(['contract_number' => $contractNumber, 'status' => 'pending']),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $leaseId, 'contract_number' => $contractNumber], 201);
