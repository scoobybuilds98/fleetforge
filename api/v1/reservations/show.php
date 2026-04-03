<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Show API
 *
 * @file        api/v1/reservations/show.php
 * @description Returns full detail for a single reservation including all
 *              associated reservation_units rows (with live equipment data),
 *              status log would go here but reservations use the audit_log
 *              directly — fetches last 20 audit entries for this reservation.
 *
 * @method      GET
 * @query       id (required)
 * @auth        Session required; require_permission('reservations','view')
 * @returns     200 { reservation object + units[] + audit_log[] } | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D5 (soft-delete), Trap 7 (no server paths in output)
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('reservations', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// ── Fetch reservation ──────────────────────────────────────────
$reservation = db_row(
    "SELECT
        r.id,
        r.status,
        r.customer_id,
        r.contact_name,
        r.company_name,
        r.contact_phone,
        r.contact_email,
        r.quantity,
        r.pickup_date,
        r.pickup_time,
        r.yard_location,
        r.purpose,
        r.priority,
        r.notes,
        r.internal_notes,
        r.marked_out_at,
        r.marked_out_by,
        r.created_by,
        r.updated_by,
        r.created_at,
        r.updated_at,
        COALESCE(c.company_name, r.company_name) AS customer_display_name,
        cb.name  AS created_by_name,
        ub.name  AS updated_by_name,
        mob.name AS marked_out_by_name
     FROM reservations r
     LEFT JOIN customers c  ON c.id = r.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users cb     ON cb.id = r.created_by
     LEFT JOIN users ub     ON ub.id = r.updated_by
     LEFT JOIN users mob    ON mob.id = r.marked_out_by
     WHERE r.id = ? AND r.deleted_at IS NULL",
    [$id]
);

if (!$reservation) {
    json_error('NOT_FOUND', 'Reservation not found.', 404);
}

// ── Fetch reservation units ────────────────────────────────────
$units = db_select(
    "SELECT
        ru.id,
        ru.reservation_id,
        ru.equipment_unit_id,
        ru.unit_number_snapshot,
        ru.template_name_snapshot,
        ru.status_at_reservation,
        ru.lease_id_linked,
        ru.entry_type,
        ru.created_at,
        COALESCE(eu.unit_number, ru.unit_number_snapshot) AS unit_number,
        COALESCE(t.name,         ru.template_name_snapshot) AS template_name,
        eu.status AS current_unit_status,
        eu.vin,
        eu.year
     FROM reservation_units ru
     LEFT JOIN equipment_units eu    ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates t ON t.id = eu.template_id AND t.deleted_at IS NULL
     WHERE ru.reservation_id = ?
     ORDER BY ru.id ASC",
    [$id]
);

// ── Fetch audit log for this reservation ──────────────────────
$auditLog = db_select(
    "SELECT
        al.id,
        al.action,
        al.notes AS description,
        al.old_values,
        al.new_values,
        al.ip_address,
        al.created_at,
        u.name AS user_name
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.entity_type = 'reservation'
       AND al.entity_id = ?
     ORDER BY al.created_at DESC
     LIMIT 20",
    [$id]
);

$reservation['units']     = $units;
$reservation['audit_log'] = $auditLog;

json_success($reservation);
