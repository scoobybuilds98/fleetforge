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
        l.estimated_mileage,
        l.actual_mileage,
        l.mileage_at_start,
        l.mileage_at_end,
        l.mileage_precharge_amount,
        l.mileage_precharge_invoiced,
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
        t.name AS template_display_name
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
