<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/show.php
 *
 * Returns a single equipment unit by ID with full field set including
 * template details. Does NOT return file_path columns (cvi_document,
 * registration_document, insurance_document) — those are server paths.
 * (Trap 7: never expose server filesystem paths in API responses.)
 *
 * @method  GET
 * @params  id (required, int) — unit ID
 * @auth    Session required; require_permission('equipment','view')
 * @returns 200 { unit object with nested template } or 404 NOT_FOUND
 *
 * @depends api/bootstrap.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.4 Equipment Units
 * @decisions Trap 7 (never return file paths in API)
 * @session S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('VALIDATION_ERROR', 'id is required.', 422);
}

$row = db_row(
    "SELECT
        u.id,
        u.template_id,
        u.unit_number,
        u.vin,
        u.year,
        u.gps_device_id,
        u.samsara_vehicle_url,
        u.tracking_provider,
        u.ownership_type,
        u.owner_company_id,
        u.yard_location,
        u.length_ft,
        u.height_ft,
        u.width_ft,
        u.weight_capacity_lbs,
        u.wheel_size,
        u.tire_size,
        u.axle_count,
        u.license_plate,
        u.license_state,
        u.cvi_expiry,
        u.registration_expiry,
        u.mvi_expiry,
        u.insurance_expiry,
        u.cvi_interval_days,
        u.mvi_interval_days,
        u.registration_interval_days,
        u.insurance_interval_days,
        u.status,
        u.mileage,
        u.notes,
        u.inspection_notes,
        u.internal_notes,
        u.tags,
        u.health_score,
        u.health_score_updated_at,
        u.qr_code_path,
        u.lease_count,
        u.total_revenue,
        u.total_maintenance_cost,
        u.acquired_date,
        u.acquisition_cost,
        u.decommissioned_date,
        u.decommission_reason,
        u.created_by,
        u.updated_by,
        u.created_at,
        u.updated_at,
        t.name     AS template_name,
        t.category AS template_category,
        t.brand    AS template_brand,
        t.model    AS template_model
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
      WHERE u.id = ? AND u.deleted_at IS NULL",
    [$id]
);

if (!$row) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// WHY: Trap 7 — qr_code_path is a server filesystem path, strip it from API response
// The QR download endpoint handles generation/serving separately

json_success([
    'id'                     => (int) $row['id'],
    'template_id'            => (int) $row['template_id'],
    'template_name'          => $row['template_name'],
    'template_category'      => $row['template_category'],
    'template_brand'         => $row['template_brand'],
    'template_model'         => $row['template_model'],
    'unit_number'            => $row['unit_number'],
    'vin'                    => $row['vin'],
    'year'                   => $row['year'] ? (int) $row['year'] : null,
    'gps_device_id'          => $row['gps_device_id'],
    'samsara_vehicle_url'    => $row['samsara_vehicle_url'],
    'tracking_provider'      => $row['tracking_provider'],
    'ownership_type'         => $row['ownership_type'],
    'owner_company_id'       => $row['owner_company_id'] ? (int) $row['owner_company_id'] : null,
    'yard_location'          => $row['yard_location'],
    'length_ft'              => $row['length_ft'],
    'height_ft'              => $row['height_ft'],
    'width_ft'               => $row['width_ft'],
    'weight_capacity_lbs'    => $row['weight_capacity_lbs'] ? (int) $row['weight_capacity_lbs'] : null,
    'wheel_size'             => $row['wheel_size'],
    'tire_size'              => $row['tire_size'],
    'axle_count'             => $row['axle_count'] ? (int) $row['axle_count'] : null,
    'license_plate'          => $row['license_plate'],
    'license_state'          => $row['license_state'],
    'cvi_expiry'             => $row['cvi_expiry'],
    'registration_expiry'    => $row['registration_expiry'],
    'mvi_expiry'             => $row['mvi_expiry'],
    'insurance_expiry'       => $row['insurance_expiry'],
    'cvi_interval_days'      => $row['cvi_interval_days'] ? (int) $row['cvi_interval_days'] : null,
    'mvi_interval_days'      => $row['mvi_interval_days'] ? (int) $row['mvi_interval_days'] : null,
    'registration_interval_days' => $row['registration_interval_days'] ? (int) $row['registration_interval_days'] : null,
    'insurance_interval_days'=> $row['insurance_interval_days'] ? (int) $row['insurance_interval_days'] : null,
    'status'                 => $row['status'],
    'mileage'                => (int) $row['mileage'],
    'notes'                  => $row['notes'],
    'inspection_notes'       => $row['inspection_notes'],
    'internal_notes'         => $row['internal_notes'],
    'tags'                   => $row['tags'] ? json_decode($row['tags'], true) : [],
    'health_score'           => $row['health_score'] !== null ? (int) $row['health_score'] : null,
    'health_score_updated_at'=> $row['health_score_updated_at'],
    'lease_count'            => (int) $row['lease_count'],
    'total_revenue'          => $row['total_revenue'],
    'total_maintenance_cost' => $row['total_maintenance_cost'],
    'acquired_date'          => $row['acquired_date'],
    'acquisition_cost'       => $row['acquisition_cost'],
    'decommissioned_date'    => $row['decommissioned_date'],
    'decommission_reason'    => $row['decommission_reason'],
    'created_by'             => $row['created_by'] ? (int) $row['created_by'] : null,
    'updated_by'             => $row['updated_by'] ? (int) $row['updated_by'] : null,
    'created_at'             => $row['created_at'],
    'updated_at'             => $row['updated_at'],
]);
