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
        l.start_time,
        l.end_date,
        l.end_time,
        l.actual_return_date,
        l.actual_return_time,
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
        l.precharge_enabled,
        l.precharge_amount,
        l.precharge_balance,
        l.precharge_invoiced_at,
        l.precharge_refund_method,
        l.precharge_refund_settled_at,
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
        l.gps_opt_in,
        l.gps_cost,
        l.hourly_rate,
        -- S-LEASE-HOURLY-BILLING: manual engine-hours readings for display/edit.
        l.engine_hours_at_start,
        l.engine_hours_at_end,
        -- S-LEASE-SERVICE-CHARGES: one-time cartage charge + billed marker.
        l.cartage_amount,
        l.cartage_billed_at,
        l.billing_cycle,
        l.advance_billing_periods,
        -- S-LEASE-MIN-DAYS: frozen short-lease floor (Config Layer 2). NULL = no
        -- per-lease minimum (falls back to the global setting at billing time).
        -- Surfaced so the edit form can pre-fill the field and the show page can
        -- display the configured minimum-billing-days value.
        l.minimum_billing_days,
        -- S-LEASE-MILEAGE-MODE: per-lease mileage data source, surfaced so the
        -- edit form pre-fills the toggle and the show page renders the badge.
        l.mileage_tracking_mode,
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
        creator.name AS created_by_name,
        COALESCE(c.company_name, l.company_name_snapshot) AS customer_display_name,
        COALESCE(u.unit_number, l.unit_number_snapshot)   AS unit_display_number,
        -- S-UNIT-STATUS-COLOR 2026-05-14: live unit status for the lease show
        -- detail table badge. NULL when the lease's equipment_unit_id has
        -- been soft-deleted (snapshot still renders, badge does not).
        u.status AS unit_current_status,
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
     LEFT JOIN users creator ON creator.id = l.created_by
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
// S-LEASE-MIN-DAYS: cast the TINYINT short-lease floor so the Alpine UI gets a
// typed int (not a numeric string); null stays null when no per-lease minimum.
$lease['minimum_billing_days']        = $lease['minimum_billing_days']        !== null ? (int)   $lease['minimum_billing_days']        : null;
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

// S-DROPDOWN-RETROFIT-1: latest non-void invoice's billing_period_end so the
// invoice create picker can auto-fill period_start = latest_period_end + 1 day.
// Separate from the odometer query above (that query filters by odometer IS NOT NULL).
$latestPeriodInv = db_row(
    "SELECT i.billing_period_end
       FROM invoices i
      WHERE i.lease_id = ? AND i.deleted_at IS NULL
        AND i.status != 'void'
        AND i.billing_period_end IS NOT NULL
      ORDER BY i.billing_period_end DESC, i.id DESC LIMIT 1",
    [$id]
);
$lease['latest_invoice_period_end'] = $latestPeriodInv['billing_period_end'] ?? null;

// S-LEASE-SERVICE-CHARGES: actually-billed service charges, summed from this
// lease's non-void invoice line items, so the Rates section can show cartage +
// the closeout sweep/wash/fuel amounts (null = not charged → UI shows N/A).
$svcRows = db_select(
    "SELECT li.item_type, SUM(li.amount) AS total
       FROM invoice_line_items li
       JOIN invoices i ON i.id = li.invoice_id
      WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
        AND li.item_type IN ('sweep','wash','fuel','cartage')
      GROUP BY li.item_type",
    [$id]
);
$closeout = ['sweep' => null, 'wash' => null, 'fuel' => null, 'cartage' => null];
foreach ($svcRows as $r) {
    $closeout[$r['item_type']] = (float) $r['total'];
}
$lease['closeout_charges'] = $closeout;

// S-MILEAGE-3 D-F: prior_excess_km block retired 2026-05-13.
// excess_distance_km column dropped in S-MILEAGE-2B C4 migration
// 202605120907; the SUM query above would have failed silently
// post-C4 (latent bug surfaced + fixed during S-MILEAGE-3 C5
// retirement scan). Close-modal closeReconciliation Alpine getter
// (sole consumer) retired in the same commit (D-F + D-G).

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
