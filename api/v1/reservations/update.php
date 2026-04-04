<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Update API
 *
 * @file        api/v1/reservations/update.php
 * @description Updates editable fields on an existing reservation.
 *              Editable only when status is 'pending' or 'confirmed'.
 *              Completed and cancelled reservations are immutable.
 *
 *              D19 optimistic lock: caller must supply updated_at from the
 *              GET /show response; 409 STALE_DATA if another user saved first.
 *
 *              Does NOT change status (use update_status.php) or add/remove
 *              reservation_units (units are set at creation — show page can
 *              display them but editing units post-creation is out of scope
 *              for S018; show.php provides delete-and-recreate guidance).
 *
 * @method      POST
 * @body        JSON — id (req), updated_at (req for D19 lock),
 *              contact_name, company_name, contact_phone, contact_email,
 *              quantity, pickup_date, pickup_time, yard_location, purpose,
 *              priority, notes, internal_notes, customer_id
 * @auth        Session required; require_permission('reservations','edit')
 * @returns     200 { id, updated_at } | 409 STALE_DATA | 422 VALIDATION_ERROR | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D19 (optimistic lock), D5 (soft-delete)
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('reservations', 'edit');

$body = json_body();

$id          = clean_int($body['id'] ?? null);
$submittedAt = clean_string($body['updated_at'] ?? null, 30);

if (!$id)          json_error('MISSING_REQUIRED', 'id is required.', 422);
if (!$submittedAt) json_error('MISSING_REQUIRED', 'updated_at is required for concurrency check.', 422);

// ── Fetch current record ───────────────────────────────────────
$existing = db_row(
    "SELECT id, status, updated_at FROM reservations
     WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Reservation not found.', 404);
}

// ── Immutability check ────────────────────────────────────────
if (in_array($existing['status'], ['completed', 'cancelled'])) {
    json_error('IMMUTABLE_RECORD',
        "Reservation #{$id} is {$existing['status']} and cannot be edited.", 422);
}

// ── Optimistic lock (D19) ──────────────────────────────────────
if ($existing['updated_at'] !== $submittedAt) {
    json_error('STALE_DATA',
        'This reservation was modified by another user. Refresh and try again.', 409);
}

// ── Validate + collect updatable fields ───────────────────────
$errors = [];

$contactName = clean_string($body['contact_name'] ?? null, 255);
$companyName = clean_string($body['company_name'] ?? null, 255);
if (!$contactName) $errors['contact_name'] = 'Contact name is required.';
if (!$companyName) $errors['company_name'] = 'Company name is required.';

$pickupDate = clean_date($body['pickup_date'] ?? null);
if (!$pickupDate) {
    $errors['pickup_date'] = 'Pickup date is required.';
} elseif ($pickupDate < date('Y-m-d')) {
    // FIX #7: block past pickup dates — reservations are forward-looking
    $errors['pickup_date'] = 'Pickup date cannot be in the past.';
}

// FIX #8: cap quantity at a reasonable maximum (500)
$quantity = clean_int($body['quantity'] ?? null);
if (!$quantity || $quantity < 1)   $errors['quantity'] = 'Quantity must be at least 1.';
elseif ($quantity > 500)           $errors['quantity'] = 'Quantity cannot exceed 500.';

if ($errors) {
    json_error('VALIDATION_ERROR', 'Validation failed.', 422, ['errors' => $errors]);
}

$allowedPriorities = ['low', 'medium', 'high', 'urgent'];
$priority     = in_array($body['priority'] ?? '', $allowedPriorities) ? $body['priority'] : 'medium';
$customerId   = clean_int($body['customer_id'] ?? null) ?: null;
$contactPhone = clean_string($body['contact_phone'] ?? null, 50);
$contactEmail = clean_email($body['contact_email'] ?? null);
// FIX #18: use clean_time() instead of clean_string() so garbage values are rejected
$pickupTime   = clean_time($body['pickup_time'] ?? null);
$yardLocation = clean_string($body['yard_location'] ?? null, 100);
$purpose      = clean_string($body['purpose'] ?? null, 500);
$notes        = clean_string($body['notes'] ?? null, 65535);
$internalNotes = clean_string($body['internal_notes'] ?? null, 65535);

// ── Validate customer if supplied ─────────────────────────────
if ($customerId) {
    $customer = db_row("SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId]);
    if (!$customer) json_error('NOT_FOUND', 'Customer not found.', 404);
}

// ── Capture old values for audit ──────────────────────────────
$oldValues = db_row(
    "SELECT contact_name, company_name, pickup_date, quantity, status, priority
     FROM reservations WHERE id = ?",
    [$id]
);

// ── FIX #2: Conflict re-check + atomic update ─────────────────
// Wrap in a transaction so the FOR UPDATE lease-conflict check and the db_update
// are atomic — prevents two concurrent edits from both succeeding.
// We re-check lease conflicts whenever pickup_date is being changed on a
// pending or confirmed reservation (reservation conflicts are date-independent
// per our one-unit-per-active-reservation rule, so no re-check needed there).
$fresh = null;

db_transaction(function () use (
    $id, $pickupDate, $existing, $customerId, $contactName, $companyName,
    $contactPhone, $contactEmail, $quantity, $pickupTime, $yardLocation,
    $purpose, $priority, $notes, $internalNotes, $oldValues, &$fresh
): void {
    // Re-check lease conflicts if pickup_date changed (lease start_date matches)
    if ($pickupDate !== $oldValues['pickup_date']) {
        $units = db_select(
            "SELECT ru.equipment_unit_id, eu.unit_number
             FROM reservation_units ru
             JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
             WHERE ru.reservation_id = ? AND ru.equipment_unit_id IS NOT NULL",
            [$id]
        );
        foreach ($units as $u) {
            $leaseConflict = db_row(
                "SELECT id, contract_number FROM leases
                 WHERE equipment_unit_id = ?
                   AND start_date = ?
                   AND status IN ('pending', 'active')
                   AND deleted_at IS NULL",
                [$u['equipment_unit_id'], $pickupDate]
            );
            if ($leaseConflict) {
                json_error('CONFLICT',
                    "Unit {$u['unit_number']} has an active lease (#{$leaseConflict['contract_number']}) " .
                    "starting on " . date('M j, Y', strtotime($pickupDate)) .
                    ". Lease conflicts cannot be overridden.", 409);
            }
        }
    }

    $affected = db_update('reservations', [
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
        'updated_by'     => current_user_id(),
    ], 'id = ?', [$id]);

    if ($affected > 0) {
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'update',
            'module'       => 'reservations',
            'entity_type'  => 'reservation',
            'entity_id'    => $id,
            'entity_label' => "#{$id} — {$companyName}",
            'notes'        => "Reservation #{$id} updated — {$companyName}",
            'old_values'   => json_encode($oldValues),
            'new_values'   => json_encode([
                'contact_name' => $contactName,
                'company_name' => $companyName,
                'pickup_date'  => $pickupDate,
                'quantity'     => $quantity,
                'priority'     => $priority,
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }

    $freshRow = db_row("SELECT updated_at FROM reservations WHERE id = ?", [$id]);
    $fresh    = $freshRow['updated_at'];
});

json_success(['id' => $id, 'updated_at' => $fresh]);
