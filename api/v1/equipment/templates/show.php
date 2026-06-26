<?php
declare(strict_types=1);

/**
 * api/v1/equipment/templates/show.php
 *
 * Returns a single equipment template by ID, including full field set
 * and unit_count (non-deleted units using this template).
 *
 * @method  GET
 * @params  id (required, int) — template ID
 * @auth    Session required; require_permission('equipment','view')
 * @returns 200 { template object } or 404 NOT_FOUND
 *
 * @depends api/bootstrap.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.3 Equipment Templates
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
        t.*,
        COUNT(u.id) AS unit_count
       FROM equipment_templates t
       LEFT JOIN equipment_units u
              ON u.template_id = t.id AND u.deleted_at IS NULL
      WHERE t.id = ? AND t.deleted_at IS NULL
      GROUP BY t.id",
    [$id]
);

if (!$row) {
    json_error('NOT_FOUND', 'Equipment template not found.', 404);
}

$template = [
    'id'                               => (int) $row['id'],
    'name'                             => $row['name'],
    'slug'                             => $row['slug'],
    'description'                      => $row['description'],
    'category'                         => $row['category'],
    'category_id'                      => $row['category_id'] !== null ? (int) $row['category_id'] : null,
    'subcategory_id'                   => $row['subcategory_id'] !== null ? (int) $row['subcategory_id'] : null,
    'brand'                            => $row['brand'],
    'model'                            => $row['model'],
    'default_length_ft'                => $row['default_length_ft'],
    'default_height_ft'                => $row['default_height_ft'],
    'default_width_ft'                 => $row['default_width_ft'],
    'default_weight_capacity_lbs'      => $row['default_weight_capacity_lbs'] ? (int) $row['default_weight_capacity_lbs'] : null,
    'default_wheel_size'               => $row['default_wheel_size'],
    'default_tire_size'                => $row['default_tire_size'],
    'default_axle_count'               => $row['default_axle_count'] ? (int) $row['default_axle_count'] : null,
    'default_ownership_type'           => $row['default_ownership_type'],
    'default_yard_location'            => $row['default_yard_location'],
    'default_tracking_provider'        => $row['default_tracking_provider'],
    'default_cvi_interval_days'        => $row['default_cvi_interval_days'] ? (int) $row['default_cvi_interval_days'] : null,
    'default_mvi_interval_days'        => $row['default_mvi_interval_days'] ? (int) $row['default_mvi_interval_days'] : null,
    'default_registration_interval_days'=> $row['default_registration_interval_days'] ? (int) $row['default_registration_interval_days'] : null,
    'default_insurance_interval_days'  => $row['default_insurance_interval_days'] ? (int) $row['default_insurance_interval_days'] : null,
    'default_daily_rate'               => $row['default_daily_rate'],
    'default_weekly_rate'              => $row['default_weekly_rate'],
    'default_monthly_rate'             => $row['default_monthly_rate'],
    'default_mileage_rate'             => $row['default_mileage_rate'],
    'default_currency'                 => $row['default_currency'],
    'default_mileage_unit'             => $row['default_mileage_unit'],
    'default_notes'                    => $row['default_notes'],
    'default_inspection_notes'         => $row['default_inspection_notes'],
    'is_active'                        => (bool) $row['is_active'],
    'sort_order'                       => (int) $row['sort_order'],
    'unit_count'                       => (int) $row['unit_count'],
    'created_at'                       => $row['created_at'],
    'updated_at'                       => $row['updated_at'],
];

// E14: strip the template default rate-card fields for roles without
// can_view_financials() (dispatchers don't manage rates). Specs + intervals stay.
if (!can_view_financials()) {
    $template = redact_keys($template, [
        'default_daily_rate', 'default_weekly_rate',
        'default_monthly_rate', 'default_mileage_rate',
    ]);
}

json_success($template);
