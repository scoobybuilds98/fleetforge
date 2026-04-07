<?php
declare(strict_types=1);

/**
 * FleetForge — Retroactive starting-odometer capture
 *
 * @file        api/v1/leases/update_odometer.php
 * @description Sets (or replaces) a lease's starting odometer after the
 *              lease has already been created. Used when the user forgot
 *              to capture the odometer at lease-start time and wants to
 *              fill it in later. Only works on active leases — completed
 *              leases are immutable per D12.
 *
 * @method      POST
 * @body        JSON — { lease_id: int, odometer_start_km: decimal,
 *                        source: 'gps'|'manual', fetched_at?: ISO datetime }
 * @auth        Session required; require_permission('leases','edit')
 * @returns     200 { lease_id, odometer_start_km }
 *              404 NOT_FOUND — lease doesn't exist
 *              409 LEASE_NOT_ACTIVE — can't retroactively update closed leases
 *              422 VALIDATION_ERROR
 *
 * @depends     api/bootstrap.php
 * @session     SAMSARA-3
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body   = json_body();
$fields = [];

$leaseId = clean_int($body['lease_id'] ?? null);
if (!$leaseId) {
    $fields['lease_id'] = 'lease_id is required.';
}

$odoKm = null;
if (!isset($body['odometer_start_km']) || $body['odometer_start_km'] === '' || $body['odometer_start_km'] === null) {
    $fields['odometer_start_km'] = 'Starting odometer is required.';
} else {
    $dec = clean_decimal($body['odometer_start_km']);
    if ($dec === null || bccomp($dec, '0', 2) < 0) {
        $fields['odometer_start_km'] = 'Starting odometer cannot be negative.';
    } else {
        $odoKm = $dec;
    }
}

$srcRaw = $body['source'] ?? 'manual';
if (!in_array($srcRaw, ['gps', 'manual'], true)) {
    $fields['source'] = "Source must be 'gps' or 'manual'.";
}
$source = $srcRaw;

if ($fields) {
    json_validation_error($fields);
}

// Parse fetched_at if GPS
$fetchedAt = null;
if ($source === 'gps') {
    if (!empty($body['fetched_at'])) {
        try {
            $dt = new DateTime((string) $body['fetched_at']);
            $fetchedAt = $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $fetchedAt = null;
        }
    }
    if ($fetchedAt === null) {
        // Stamp server-side so there's always an audit trail
        $fetchedAt = date('Y-m-d H:i:s');
    }
}

// ── Verify the lease exists + is still active ─────────────────
$lease = db_row(
    "SELECT id, contract_number, status, odometer_start_km FROM leases
      WHERE id = ? AND deleted_at IS NULL",
    [$leaseId]
);
if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

// Only active / pending leases can have their starting odometer
// captured or updated — closed leases are immutable (D12).
if (!in_array($lease['status'], ['active', 'pending'], true)) {
    json_error('LEASE_NOT_ACTIVE',
        "Cannot update starting odometer: lease is '{$lease['status']}'. Only active or pending leases are editable.",
        409);
}

db_transaction(function () use ($leaseId, $lease, $odoKm, $source, $fetchedAt) {
    db_update('leases', [
        'odometer_start_km'         => $odoKm,
        'odometer_start_source'     => $source,
        'odometer_start_fetched_at' => $fetchedAt,
        'updated_by'                => current_user_id(),
    ], 'id = ?', [$leaseId]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $leaseId,
        'entity_label' => $lease['contract_number'],
        'notes'        => "Retroactive starting odometer captured: {$odoKm} km (source: {$source})",
        'old_values'   => json_encode(['odometer_start_km' => $lease['odometer_start_km']]),
        'new_values'   => json_encode([
            'odometer_start_km'     => $odoKm,
            'odometer_start_source' => $source,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success([
    'lease_id'          => $leaseId,
    'odometer_start_km' => (float) $odoKm,
    'source'            => $source,
    'fetched_at'        => $fetchedAt,
]);
