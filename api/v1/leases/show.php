<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Show API
 *
 * @file        api/v1/leases/show.php
 * @description Returns full detail for a single lease including associated
 *              customer name, unit info, and status log entries.
 *              Strips contract_file, inspection_in_file, inspection_out_file
 *              (server filesystem paths — Trap 7).
 *
 * @method      GET
 * @query       id (required)
 * @auth        Session required; require_permission('leases','view')
 * @returns     200 { lease object } | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D5 (soft-delete), Trap 7 (no file paths in API output)
 * @session     S007
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('leases', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$lease = db_row(
    "SELECT
        l.id,
        l.contract_number,
        l.customer_id,
        l.equipment_unit_id,
        l.customer_name_snapshot,
        l.company_name_snapshot,
        l.unit_number_snapshot,
        l.template_name_snapshot,
        l.equipment_snapshot_json,
        l.start_date,
        l.end_date,
        l.actual_return_date,
        l.status,
        l.daily_rate,
        l.weekly_rate,
        l.monthly_rate,
        l.rate_notes,
        l.currency,
        l.exchange_rate_to_cad,
        l.mileage_unit,
        l.mileage_rate,
        l.mileage_rate_km,
        l.mileage_rate_miles,
        l.estimated_mileage,
        l.estimated_mileage_km,
        l.estimated_mileage_miles,
        l.km_to_miles_conversion,
        l.miles_to_km_conversion,
        l.actual_mileage,
        l.mileage_at_start,
        l.mileage_at_end,
        l.mileage_precharge_amount,
        l.mileage_precharge_invoiced,
        l.odometer_start_km,
        l.odometer_start_source,
        l.odometer_start_fetched_at,
        l.odometer_end_km,
        l.odometer_end_source,
        l.odometer_end_fetched_at,
        l.total_distance_km,
        l.tax_exempt,
        l.gst_exempt,
        l.pst_exempt,
        l.tax_rate_gst,
        l.tax_rate_pst,
        l.tax_rate_hst,
        l.discount_type,
        l.discount_value,
        l.insurance_opt_in,
        l.insurance_cost,
        l.warranty_opt_in,
        l.warranty_cost,
        l.billing_cycle,
        l.advance_billing_periods,
        l.po_number,
        l.last_billed_date,
        l.next_billing_date,
        l.total_invoiced,
        l.total_paid,
        l.outstanding_balance,
        l.total_estimated_charge,
        l.final_total_charge,
        l.notes,
        l.internal_notes,
        l.created_by,
        l.updated_by,
        l.closed_by_user_id,
        l.closed_at,
        l.minimum_end_date,
        l.cancellation_reason,
        l.created_at,
        l.updated_at,
        COALESCE(c.company_name, l.company_name_snapshot) AS customer_display_name,
        COALESCE(u.unit_number, l.unit_number_snapshot)   AS unit_display_number,
        t.name AS template_display_name,
        -- SAMSARA-1: live telemetry from the linked equipment unit so the
        -- lease detail page can render a GPS card and the Close modal can
        -- pre-fill End Mileage from the latest cron-cached odometer.
        -- These come from equipment_units (NOT a live Samsara API call) so
        -- the lease show endpoint stays fast and side-effect free.
        u.samsara_vehicle_id,
        u.samsara_vehicle_name,
        u.samsara_battery_pct,
        u.samsara_battery_charging,
        u.samsara_last_location_lat,
        u.samsara_last_location_lng,
        u.samsara_last_location_address,
        u.samsara_last_speed_kph,
        u.samsara_last_connected_at,
        u.samsara_last_synced_at,
        u.samsara_odometer_km
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     LEFT JOIN equipment_templates t ON t.id = u.template_id AND t.deleted_at IS NULL
     WHERE l.id = ? AND l.deleted_at IS NULL",
    [$id]
);

if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

// Trap 7 — strip server filesystem paths; never return file paths in API output
unset($lease['contract_file'], $lease['inspection_in_file'], $lease['inspection_out_file']);

// SAMSARA-1: cast nullable numerics so the Alpine UI gets typed numbers
// (avoids ".toFixed is not a function" on numeric-string columns).
$lease['samsara_battery_pct']         = $lease['samsara_battery_pct']         !== null ? (int)   $lease['samsara_battery_pct']         : null;
$lease['samsara_battery_charging']    = $lease['samsara_battery_charging']    !== null ? (int)   $lease['samsara_battery_charging']    : null;
$lease['samsara_last_location_lat']   = $lease['samsara_last_location_lat']   !== null ? (float) $lease['samsara_last_location_lat']   : null;
$lease['samsara_last_location_lng']   = $lease['samsara_last_location_lng']   !== null ? (float) $lease['samsara_last_location_lng']   : null;
$lease['samsara_last_speed_kph']      = $lease['samsara_last_speed_kph']      !== null ? (float) $lease['samsara_last_speed_kph']      : null;
// SAMSARA-3 / S-LEASE-MILEAGE: cast DECIMAL columns so JS gets typed numbers.
$lease['odometer_start_km']           = $lease['odometer_start_km']           !== null ? (float) $lease['odometer_start_km']           : null;
$lease['odometer_end_km']             = $lease['odometer_end_km']             !== null ? (float) $lease['odometer_end_km']             : null;
$lease['total_distance_km']           = $lease['total_distance_km']           !== null ? (float) $lease['total_distance_km']           : null;
$lease['estimated_mileage_km']        = $lease['estimated_mileage_km']        !== null ? (float) $lease['estimated_mileage_km']        : null;
$lease['estimated_mileage_miles']     = $lease['estimated_mileage_miles']     !== null ? (float) $lease['estimated_mileage_miles']     : null;
$lease['mileage_rate_km']             = $lease['mileage_rate_km']             !== null ? (float) $lease['mileage_rate_km']             : null;
$lease['mileage_rate_miles']          = $lease['mileage_rate_miles']          !== null ? (float) $lease['mileage_rate_miles']          : null;
$lease['km_to_miles_conversion']      = $lease['km_to_miles_conversion']      !== null ? (float) $lease['km_to_miles_conversion']      : null;

// SAMSARA-3: pull the latest invoice's period-end odometer and invoice number
// so the Overview tab can show "Latest recorded: 2,456.78 km (from INV-...)"
$latestOdoInv = db_row(
    "SELECT i.odometer_at_period_end_km, i.cumulative_distance_km, i.invoice_number, i.id
       FROM invoices i
      WHERE i.lease_id = ? AND i.deleted_at IS NULL
        AND i.odometer_at_period_end_km IS NOT NULL
      ORDER BY i.billing_period_end DESC, i.id DESC LIMIT 1",
    [$id]
);
$lease['latest_invoice_odometer_km']     = $latestOdoInv && $latestOdoInv['odometer_at_period_end_km'] !== null
    ? (float) $latestOdoInv['odometer_at_period_end_km'] : null;
$lease['latest_invoice_cumulative_km']   = $latestOdoInv && $latestOdoInv['cumulative_distance_km'] !== null
    ? (float) $latestOdoInv['cumulative_distance_km'] : null;
$lease['latest_invoice_number_for_odo']  = $latestOdoInv['invoice_number'] ?? null;
$lease['latest_invoice_id_for_odo']      = $latestOdoInv && $latestOdoInv['id'] ? (int) $latestOdoInv['id'] : null;
$lease['samsara_odometer_km']         = $lease['samsara_odometer_km']         !== null ? (float) $lease['samsara_odometer_km']         : null;

// S-MILEAGE-FIX-0 (Q9): expose total prior monthly excess (km canonical)
// so the close-modal UI can detect the inverse case (priorOverbillKm > 0)
// and render a warning banner BEFORE the manager picks a decision.
// Voided invoices excluded — their excess never reached customer AR.
$priorExcessRow = db_row(
    "SELECT COALESCE(SUM(excess_distance_km), 0) AS prior_excess
       FROM invoices
      WHERE lease_id = ? AND deleted_at IS NULL AND status != 'void'",
    [$id]
);
$lease['prior_excess_km'] = (float) ($priorExcessRow['prior_excess'] ?? 0);

// Fetch status log for this lease
$statusLog = db_select(
    "SELECT id, old_status, new_status, notes, changed_by, user_id, changed_at
     FROM lease_status_log
     WHERE lease_id = ?
     ORDER BY changed_at DESC",
    [$id]
);

$lease['status_log'] = $statusLog;

json_success($lease);
