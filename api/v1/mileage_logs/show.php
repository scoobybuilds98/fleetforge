<?php
declare(strict_types=1);

// ============================================================
// GET /api/v1/mileage_logs/show?id=N
//
// Returns a single mileage log entry with unit and lease context.
//
// Permission: maintenance view
// ============================================================

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$log = db_row(
    "SELECT
        ml.id,
        ml.equipment_unit_id,
        ml.lease_id,
        ml.log_type,
        ml.odometer_reading,
        ml.mileage_unit,
        ml.log_date,
        ml.notes,
        ml.recorded_by,
        ml.created_at,
        eu.unit_number,
        et.brand,
        et.model,
        et.category         AS unit_category,
        u.name              AS recorded_by_name
     FROM mileage_logs ml
     JOIN equipment_units eu ON eu.id = ml.equipment_unit_id AND eu.deleted_at IS NULL
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN users u ON u.id = ml.recorded_by
     WHERE ml.id = ?",
    [$id]
);

if (!$log) {
    json_error('NOT_FOUND', 'Mileage log not found.', 404);
}

// Attach lease context if linked
$leaseContext = null;
if ($log['lease_id']) {
    $leaseContext = db_row(
        "SELECT id, contract_number, status, start_date, end_date
         FROM leases
         WHERE id = ? AND deleted_at IS NULL",
        [$log['lease_id']]
    );
}

$log['lease'] = $leaseContext;

json_success($log);
