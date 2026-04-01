<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Close API
 *
 * @file        api/v1/leases/close.php
 * @description Transitions a lease from 'active' → 'completed'.
 *
 *              State machine (spec §6):
 *                active → completed (this endpoint)
 *                active → cancelled (via cancel endpoint — future session)
 *
 *              Concurrency (D20): FOR UPDATE lock on equipment_unit prevents
 *              race with monthly invoice cron (which also writes to the lease).
 *
 *              On close:
 *              1. Validate lease is active
 *              2. FOR UPDATE lock on equipment_unit
 *              3. Update lease: status → 'completed', actual_return_date, closed_at, closed_by
 *              4. Update equipment_unit: status → 'available'
 *              5. Write equipment_status_log
 *              6. Write lease_status_log
 *              7. Write audit_log
 *
 *              Final invoice generation (mileage reconciliation) is stubbed here;
 *              InvoiceGenerator will be called from this point in S009+.
 *
 * @method      POST
 * @body        JSON — id (required), actual_return_date (optional, defaults today),
 *              mileage_at_end (optional), close_notes (optional)
 * @auth        Session required; require_permission('leases','edit')
 * @returns     200 { id, status } | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases, §12 Final Invoice [PASS-3:2C]
 * @decisions   D20 (FOR UPDATE on unit)
 * @session     S007
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$actualReturnDate = clean_date($body['actual_return_date'] ?? null) ?? date('Y-m-d');
$mileageAtEnd     = clean_int($body['mileage_at_end'] ?? null);
$closeNotes       = clean_string($body['close_notes'] ?? null, 5000);

$result = null;

db_transaction(function () use ($id, $actualReturnDate, $mileageAtEnd, $closeNotes, &$result) {
    // ── Fetch lease ────────────────────────────────────────────
    $lease = db_row(
        "SELECT id, status, contract_number, company_name_snapshot,
                equipment_unit_id, unit_number_snapshot, mileage_at_start,
                mileage_rate, mileage_unit, estimated_mileage, mileage_precharge_amount,
                start_date, last_billed_date
         FROM leases WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$lease) {
        json_error('NOT_FOUND', 'Lease not found.', 404);
    }

    // ── State machine validation ───────────────────────────────
    if ($lease['status'] !== 'active') {
        json_error('INVALID_TRANSITION',
            "Cannot close lease {$lease['contract_number']}: current status is '{$lease['status']}'. Only active leases can be closed.", 409);
    }

    // ── D20: FOR UPDATE lock on unit ───────────────────────────
    // Prevents race with monthly billing cron that also reads/writes the lease
    $unit = db_row(
        "SELECT id, unit_number, status
         FROM equipment_units
         WHERE id = ? AND deleted_at IS NULL
         FOR UPDATE",
        [$lease['equipment_unit_id']]
    );

    if (!$unit) {
        json_error('NOT_FOUND', 'Equipment unit not found.', 404);
    }

    // Validate mileage order — end must be >= start
    if ($mileageAtEnd !== null && $lease['mileage_at_start'] !== null) {
        if ($mileageAtEnd < (int) $lease['mileage_at_start']) {
            json_error('MILEAGE_DATA_ERROR',
                'End mileage cannot be less than start mileage.', 422);
        }
    }

    $user      = current_user();
    $changedBy = $user['name'] ?? 'system';

    // ── Update lease → completed ───────────────────────────────
    $leaseUpdate = [
        'status'             => 'completed',
        'actual_return_date' => $actualReturnDate,
        'closed_at'          => date('Y-m-d H:i:s'),
        'closed_by_user_id'  => current_user_id(),
        'updated_by'         => current_user_id(),
    ];
    if ($mileageAtEnd !== null) {
        $leaseUpdate['mileage_at_end'] = $mileageAtEnd;
        // Calculate actual mileage for reconciliation
        if ($lease['mileage_at_start'] !== null) {
            $leaseUpdate['actual_mileage'] = $mileageAtEnd - (int) $lease['mileage_at_start'];
        }
    }
    if ($closeNotes) {
        // FIX #37: append close_notes to internal_notes instead of replacing
        $existing = db_row("SELECT internal_notes FROM leases WHERE id = ?", [$id]);
        $prior = $existing['internal_notes'] ?? '';
        $leaseUpdate['internal_notes'] = $prior
            ? $prior . "\n---\nClose notes: " . $closeNotes
            : 'Close notes: ' . $closeNotes;
    }

    db_update('leases', $leaseUpdate, 'id = ?', [$id]);

    // ── Update unit → available ────────────────────────────────
    db_execute(
        "UPDATE equipment_units SET status = 'available', updated_by = ? WHERE id = ?",
        [current_user_id(), $lease['equipment_unit_id']]
    );

    // ── equipment_status_log ───────────────────────────────────
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $lease['equipment_unit_id'],
        'old_status'         => 'on_lease',
        'new_status'         => 'available',
        'reason'             => "Lease {$lease['contract_number']} closed",
        'changed_by'         => $changedBy,
        'changed_by_user_id' => current_user_id(),
    ]);

    // ── lease_status_log ───────────────────────────────────────
    db_insert('lease_status_log', [
        'lease_id'   => $id,
        'old_status' => 'active',
        'new_status' => 'completed',
        'notes'      => $closeNotes ?? 'Lease closed',
        'changed_by' => $changedBy,
        'user_id'    => current_user_id(),
    ]);

    // ── audit_log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => $changedBy,
        'action'       => 'lease_closed',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $id,
        'entity_label' => $lease['contract_number'],
        'notes'        => "Lease {$lease['contract_number']} closed — unit {$unit['unit_number']} → available",
        'old_values'   => json_encode(['status' => 'active']),
        'new_values'   => json_encode(['status' => 'completed', 'actual_return_date' => $actualReturnDate]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // ── FIX #18: Generate final invoice on close ───────────────
    // Per spec §12 [PASS-3:2C]: final period = day after last_billed_date (or
    // start_date if never billed) through actual_return_date. Pro-rated.
    // Mileage overage passed as extra_lines if mileage_rate > 0 and actual > estimated.
    $periodStart = $lease['last_billed_date']
        ? date('Y-m-d', strtotime($lease['last_billed_date'] . ' +1 day'))
        : $lease['start_date'];

    $extraLines = [];
    if ($mileageAtEnd !== null && $lease['mileage_at_start'] !== null
        && bccomp((string)$lease['mileage_rate'], '0', 4) > 0)
    {
        $actualMileage    = (string)($mileageAtEnd - (int)$lease['mileage_at_start']);
        $includedMileage  = (string)($lease['estimated_mileage'] ?? '0');
        $overageMileage   = bcsub($actualMileage, $includedMileage, 4);
        if (bccomp($overageMileage, '0', 4) > 0) {
            $mileageCharge = bcround(bcmul($overageMileage, (string)$lease['mileage_rate'], 6), 2);
            if (bccomp($mileageCharge, '0', 2) > 0) {
                $extraLines[] = [
                    'item_type'   => 'mileage',
                    'description' => "Mileage overage: {$overageMileage} km × \${$lease['mileage_rate']}/km",
                    'quantity'    => $overageMileage,
                    'unit'        => $lease['mileage_unit'] ?? 'km',
                    'unit_price'  => (string)$lease['mileage_rate'],
                    'amount'      => $mileageCharge,
                    'is_credit'   => 0,
                    'taxable'     => 1,
                ];
            }
        }
    }

    $generator     = new \FleetForge\Billing\InvoiceGenerator();
    $invoiceResult = $generator->createFromLease([
        'lease_id'          => $id,
        'period_start'      => $periodStart,
        'period_end'        => $actualReturnDate,
        'billing_type'      => 'partial_end',
        'invoice_type'      => 'final',
        'notes'             => $closeNotes,
        'created_by'        => current_user_id(),
        'auto_generated'    => 1,
        'generation_source' => 'lease_close',  // ENUM: cron|manual|lease_close|late_fee_cron
        'extra_lines'       => $extraLines,
    ]);

    $result = ['id' => $id, 'status' => 'completed', 'invoice_id' => $invoiceResult['invoice_id']];
});

json_success($result);
