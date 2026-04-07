<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Update API
 *
 * @file        api/v1/leases/update.php
 * @description Updates lease metadata. Status changes are intentionally excluded —
 *              they require state machine validation and go through dedicated endpoints
 *              (activate.php, close.php).
 *
 *              D19 optimistic lock: client must supply updated_at from the initial load.
 *              Mismatch → 409 STALE_DATA (another user has modified the record).
 *
 *              Partial-update pattern: only fields present in the request body are
 *              updated. Fields not sent retain their current DB values.
 *
 * @method      POST
 * @body        JSON — id, updated_at (required for optimistic lock)
 *              Optional metadata: end_date, minimum_end_date, rate_notes, po_number,
 *              notes, internal_notes, mileage_at_start, mileage_at_end,
 *              estimated_mileage, insurance_opt_in, insurance_cost,
 *              warranty_opt_in, warranty_cost, gst_exempt, pst_exempt
 *              NOTE: status, daily_rate, weekly_rate, monthly_rate are immutable
 *              after creation (require amendment record — not implemented here)
 * @auth        Session required; require_permission('leases','edit')
 * @returns     200 { updated_at } | 409 STALE_DATA | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D19 (optimistic lock)
 * @session     S007
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body = json_body();

$id        = clean_int($body['id'] ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);

if (!$id)        json_error('MISSING_REQUIRED', 'id is required.', 422);
if (!$updatedAt) json_error('MISSING_REQUIRED', 'updated_at is required for optimistic lock.', 422);

// ── Fetch existing lease ───────────────────────────────────────
$existing = db_row(
    "SELECT id, status, contract_number, company_name_snapshot, updated_at
     FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$existing) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

// FIX #15: completed and cancelled leases are read-only; prevent post-hoc editing
if (in_array($existing['status'], ['completed', 'cancelled'])) {
    json_error('IMMUTABLE_RECORD',
        "Lease {$existing['contract_number']} is {$existing['status']} and cannot be edited.", 422);
}

// ── D19 Optimistic lock check ──────────────────────────────────
if ($existing['updated_at'] !== $updatedAt) {
    json_error('STALE_DATA',
        'This lease was modified by another user. Reload the page to get the latest version.', 409);
}

// ── Build partial update — only fields supplied in body ────────
// VALID-2: collect every error into $fields, one 422 response at the end.
$data   = [];
$fields = [];

if (array_key_exists('end_date', $body))
    $data['end_date'] = clean_date($body['end_date']);

if (array_key_exists('minimum_end_date', $body))
    $data['minimum_end_date'] = clean_date($body['minimum_end_date']);

if (array_key_exists('rate_notes', $body))
    $data['rate_notes'] = clean_string($body['rate_notes'], 5000);

if (array_key_exists('po_number', $body))
    $data['po_number'] = clean_string($body['po_number'], 100);

if (array_key_exists('notes', $body))
    $data['notes'] = clean_string($body['notes'], 5000);

if (array_key_exists('internal_notes', $body))
    $data['internal_notes'] = clean_string($body['internal_notes'], 5000);

// VALID-2: mileage readings must be >= 0 — use clean_int so we can surface a specific message
if (array_key_exists('mileage_at_start', $body)) {
    $raw = $body['mileage_at_start'];
    if ($raw === null || $raw === '') {
        $data['mileage_at_start'] = null;
    } else {
        $i = clean_int($raw);
        if ($i === null || $i < 0) {
            $fields['mileage_at_start'] = 'Starting mileage cannot be negative.';
        } else {
            $data['mileage_at_start'] = $i;
        }
    }
}

if (array_key_exists('mileage_at_end', $body)) {
    $raw = $body['mileage_at_end'];
    if ($raw === null || $raw === '') {
        $data['mileage_at_end'] = null;
    } else {
        $i = clean_int($raw);
        if ($i === null || $i < 0) {
            $fields['mileage_at_end'] = 'End mileage cannot be negative.';
        } else {
            $data['mileage_at_end'] = $i;
        }
    }
}

if (array_key_exists('estimated_mileage', $body)) {
    $d = clean_decimal($body['estimated_mileage']);
    if ($d !== null && bccomp($d, '0', 4) < 0) {
        $fields['estimated_mileage'] = 'Estimated mileage cannot be negative.';
    }
    $data['estimated_mileage'] = ($d !== null && bccomp($d, '0', 4) >= 0) ? $d : '0.00';
}

if (array_key_exists('insurance_opt_in', $body))
    $data['insurance_opt_in'] = (bool) $body['insurance_opt_in'] ? 1 : 0;

if (array_key_exists('insurance_cost', $body)) {
    $d = clean_decimal($body['insurance_cost']);
    if ($d !== null && bccomp($d, '0', 4) < 0) {
        $fields['insurance_cost'] = 'Insurance cost cannot be negative.';
    }
    $data['insurance_cost'] = ($d !== null && bccomp($d, '0', 4) >= 0) ? $d : '0.00';
}

if (array_key_exists('warranty_opt_in', $body))
    $data['warranty_opt_in'] = (bool) $body['warranty_opt_in'] ? 1 : 0;

if (array_key_exists('warranty_cost', $body)) {
    $d = clean_decimal($body['warranty_cost']);
    if ($d !== null && bccomp($d, '0', 4) < 0) {
        $fields['warranty_cost'] = 'Warranty cost cannot be negative.';
    }
    $data['warranty_cost'] = ($d !== null && bccomp($d, '0', 4) >= 0) ? $d : '0.00';
}

// D22: gst_exempt and pst_exempt can be changed via amendment (allow here for now)
if (array_key_exists('gst_exempt', $body)) {
    $data['gst_exempt'] = (bool) $body['gst_exempt'] ? 1 : 0;
}
if (array_key_exists('pst_exempt', $body)) {
    $data['pst_exempt'] = (bool) $body['pst_exempt'] ? 1 : 0;
}

// Validate end_date and mileage cross-field rules
$currentLease = db_row("SELECT start_date, mileage_at_start FROM leases WHERE id = ?", [$id]);

if (isset($data['end_date']) && $data['end_date']) {
    if ($data['end_date'] < $currentLease['start_date']) {
        $fields['end_date'] = 'End date must be after start date.';
    }
}

if (array_key_exists('minimum_end_date', $data) && $data['minimum_end_date']) {
    if ($data['minimum_end_date'] < $currentLease['start_date']) {
        $fields['minimum_end_date'] = 'Minimum end date must be on or after start date.';
    }
}

if (array_key_exists('mileage_at_end', $data) && $data['mileage_at_end'] !== null) {
    $startMileage = (int) ($data['mileage_at_start'] ?? $currentLease['mileage_at_start'] ?? 0);
    if ((int) $data['mileage_at_end'] < $startMileage) {
        $fields['mileage_at_end'] = 'End mileage must be greater than or equal to start mileage.';
    }
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($data)) {
    json_error('VALIDATION_ERROR', 'No updatable fields provided.', 422);
}

$data['updated_by'] = current_user_id();

// FIX #20: wrap db_update + audit_log in transaction so both commit or rollback together
$newUpdatedAt = null;

db_transaction(function () use ($id, $data, $existing, &$newUpdatedAt) {
    db_update('leases', $data, 'id = ?', [$id]);

    $newRow = db_row("SELECT updated_at FROM leases WHERE id = ?", [$id]);
    $newUpdatedAt = $newRow['updated_at'];

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $id,
        'entity_label' => $existing['contract_number'],
        'notes'        => "Lease {$existing['contract_number']} metadata updated",
        'old_values'   => null,
        'new_values'   => json_encode($data),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['updated_at' => $newUpdatedAt]);
