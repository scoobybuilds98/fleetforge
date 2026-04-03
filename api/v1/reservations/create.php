<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Create API
 *
 * @file        api/v1/reservations/create.php
 * @description Creates a new reservation with status 'pending' (default) or
 *              'confirmed' if the caller explicitly sets status='confirmed'.
 *
 *              Units are supplied as an array of objects:
 *                { equipment_unit_id: int|null, unit_number: string, entry_type: 'system'|'manual' }
 *
 *              Conflict detection (D20): before inserting, each system-linked unit
 *              (entry_type='system', equipment_unit_id set) is checked for an
 *              overlapping confirmed/pending reservation on the same pickup_date.
 *              Uses FOR UPDATE row lock to prevent race conditions.
 *
 *              System units with entry_type='system' have their equipment status
 *              updated to 'reserved' and an equipment_status_log entry written.
 *
 * @method      POST
 * @body        JSON — contact_name (req), company_name (req), pickup_date (req),
 *              quantity (req), status (optional: pending|confirmed),
 *              trailer_type_id (optional: equipment_template_id for display),
 *              units[] (array of unit objects),
 *              customer_id, contact_phone, contact_email, pickup_time,
 *              yard_location, purpose, priority, notes, internal_notes
 * @auth        Session required; require_permission('reservations','create')
 * @returns     201 { id } | 409 CONFLICT | 422 VALIDATION_ERROR
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D5 (soft-delete), D20 (FOR UPDATE conflict detection), D19 (no optimistic lock on create)
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('reservations', 'create');

$body = json_body();

// ── Required fields ────────────────────────────────────────────
$contactName = clean_string($body['contact_name'] ?? null, 255);
$companyName = clean_string($body['company_name'] ?? null, 255);
$pickupDate  = clean_date($body['pickup_date'] ?? null);
$quantity    = clean_int($body['quantity'] ?? null);
$errors      = [];

if (!$contactName) $errors['contact_name'] = 'Contact name is required.';
if (!$companyName) $errors['company_name'] = 'Company name is required.';
if (!$pickupDate)  $errors['pickup_date']  = 'Pickup date is required.';
if (!$quantity || $quantity < 1) $errors['quantity'] = 'Quantity must be at least 1.';

if ($errors) {
    json_error('VALIDATION_ERROR', 'Validation failed.', 422, ['errors' => $errors]);
}

// ── Optional fields ────────────────────────────────────────────
$allowedStatuses = ['pending', 'confirmed'];
$status       = in_array($body['status'] ?? '', $allowedStatuses) ? $body['status'] : 'pending';
$customerId   = clean_int($body['customer_id'] ?? null);
$contactPhone = clean_string($body['contact_phone'] ?? null, 50);
$contactEmail = clean_email($body['contact_email'] ?? null);
$pickupTime   = clean_string($body['pickup_time'] ?? null, 8);  // HH:MM:SS
$yardLocation = clean_string($body['yard_location'] ?? null, 100);
$purpose      = clean_string($body['purpose'] ?? null, 500);
$allowedPriorities = ['low', 'medium', 'high', 'urgent'];
$priority     = in_array($body['priority'] ?? '', $allowedPriorities) ? $body['priority'] : 'medium';
$notes        = clean_string($body['notes'] ?? null, 65535);
$internalNotes = clean_string($body['internal_notes'] ?? null, 65535);

// ── Units array ────────────────────────────────────────────────
$units = is_array($body['units'] ?? null) ? $body['units'] : [];

// Validate each unit object
$unitErrors = [];
foreach ($units as $i => $u) {
    $entryType = $u['entry_type'] ?? 'system';
    if (!in_array($entryType, ['system', 'manual'])) {
        $unitErrors[] = "Unit $i: entry_type must be 'system' or 'manual'.";
        continue;
    }
    if ($entryType === 'system' && empty($u['equipment_unit_id'])) {
        $unitErrors[] = "Unit $i: equipment_unit_id is required for system entry type.";
    }
    if (empty($u['unit_number'])) {
        $unitErrors[] = "Unit $i: unit_number is required.";
    }
}
if ($unitErrors) {
    json_error('VALIDATION_ERROR', implode(' ', $unitErrors), 422);
}

// ── Validate customer exists (if provided) ─────────────────────
if ($customerId) {
    $customer = db_row(
        "SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL",
        [$customerId]
    );
    if (!$customer) {
        json_error('NOT_FOUND', 'Customer not found.', 404);
    }
}

// ── Conflict detection + creation (atomic) ────────────────────
$newId = null;

db_transaction(function () use (
    $status, $customerId, $contactName, $companyName, $contactPhone, $contactEmail,
    $quantity, $pickupDate, $pickupTime, $yardLocation, $purpose, $priority,
    $notes, $internalNotes, $units, &$newId
) {
    // ── Check conflicts for each system-linked unit ─────────────
    // For each unit with entry_type='system', acquire a row lock and verify
    // there is no existing pending/confirmed reservation for the same pickup_date.
    // WHY FOR UPDATE: prevents two concurrent requests from reserving the same
    // unit for the same date (D20 concurrency pattern).
    foreach ($units as $u) {
        $entryType = $u['entry_type'] ?? 'system';
        if ($entryType !== 'system') continue;

        $unitId = clean_int($u['equipment_unit_id'] ?? null);
        if (!$unitId) continue;

        // Lock the equipment unit row
        $unit = db_row(
            "SELECT id, unit_number, status FROM equipment_units
             WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
            [$unitId]
        );
        if (!$unit) {
            json_error('NOT_FOUND', "Equipment unit ID $unitId not found.", 404);
        }

        // Check for conflicting reservation on this date
        $conflict = db_row(
            "SELECT r.id, r.company_name, r.pickup_date
             FROM reservation_units ru
             JOIN reservations r ON r.id = ru.reservation_id
             WHERE ru.equipment_unit_id = ?
               AND r.pickup_date = ?
               AND r.status IN ('pending', 'confirmed')
               AND r.deleted_at IS NULL",
            [$unitId, $pickupDate]
        );
        if ($conflict) {
            json_error('CONFLICT',
                "Unit {$unit['unit_number']} is already reserved on " .
                date('M j, Y', strtotime($pickupDate)) .
                " (Reservation #{$conflict['id']} — {$conflict['company_name']}).",
                409
            );
        }

        // Also check no active lease starts on the same date for this unit
        $leaseConflict = db_row(
            "SELECT id, contract_number FROM leases
             WHERE equipment_unit_id = ?
               AND start_date = ?
               AND status IN ('pending', 'active')
               AND deleted_at IS NULL",
            [$unitId, $pickupDate]
        );
        if ($leaseConflict) {
            json_error('CONFLICT',
                "Unit {$unit['unit_number']} has an active lease (#{$leaseConflict['contract_number']}) starting on " .
                date('M j, Y', strtotime($pickupDate)) . ".",
                409
            );
        }
    }

    // ── Insert reservation ─────────────────────────────────────
    $newId = db_insert('reservations', [
        'status'         => $status,
        'customer_id'    => $customerId,
        'contact_name'   => $contactName,
        'company_name'   => $companyName,
        'contact_phone'  => $contactPhone,
        'contact_email'  => $contactEmail,
        'quantity'       => $quantity,
        'pickup_date'    => $pickupDate,
        'pickup_time'    => $pickupTime,
        'yard_location'  => $yardLocation,
        'purpose'        => $purpose,
        'priority'       => $priority,
        'notes'          => $notes,
        'internal_notes' => $internalNotes,
        'created_by'     => current_user_id(),
        'updated_by'     => current_user_id(),
    ]);

    // ── Insert reservation_units + update equipment status ─────
    foreach ($units as $u) {
        $entryType   = $u['entry_type'] ?? 'system';
        $unitId      = $entryType === 'system' ? clean_int($u['equipment_unit_id'] ?? null) : null;
        $unitNumber  = clean_string($u['unit_number'] ?? '', 100);
        $templateName = clean_string($u['template_name'] ?? null, 100);
        $currentStatus = null;

        // Fetch snapshot data for system units
        if ($unitId) {
            $unitRow = db_row(
                "SELECT eu.unit_number, eu.status, t.name AS template_name
                 FROM equipment_units eu
                 LEFT JOIN equipment_templates t ON t.id = eu.template_id
                 WHERE eu.id = ? AND eu.deleted_at IS NULL",
                [$unitId]
            );
            if ($unitRow) {
                $unitNumber    = $unitRow['unit_number'];
                $templateName  = $unitRow['template_name'] ?? $templateName;
                $currentStatus = $unitRow['status'];
            }
        }

        db_insert('reservation_units', [
            'reservation_id'         => $newId,
            'equipment_unit_id'      => $unitId,
            'unit_number_snapshot'   => $unitNumber,
            'template_name_snapshot' => $templateName,
            'status_at_reservation'  => $currentStatus,
            'entry_type'             => $entryType,
        ]);

        // Update equipment status to 'reserved' for system units when status is confirmed
        if ($unitId && $status === 'confirmed' && $currentStatus === 'available') {
            db_execute(
                "UPDATE equipment_units SET status = 'reserved', updated_at = NOW()
                 WHERE id = ? AND deleted_at IS NULL",
                [$unitId]
            );
            db_insert('equipment_status_log', [
                'equipment_unit_id' => $unitId,
                'changed_by'        => current_user_id(),
                'old_status'        => $currentStatus,
                'new_status'        => 'reserved',
                'reason'            => "Reservation #{$newId} created as confirmed",
                'changed_at'        => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ── Audit log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'reservations',
        'entity_type'  => 'reservation',
        'entity_id'    => $newId,
        'entity_label' => "#{$newId} — {$companyName}",
        'notes'        => "Reservation #{$newId} created for {$companyName} — pickup {$pickupDate} — status: {$status}",
        'old_values'   => null,
        'new_values'   => json_encode([
            'status'       => $status,
            'company_name' => $companyName,
            'pickup_date'  => $pickupDate,
            'quantity'     => $quantity,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $newId], 201);
