<?php
declare(strict_types=1);

/**
 * FleetForge — Units By Customer API
 *
 * @file        api/v1/reservations/units_by_customer.php
 * @description Returns equipment units linked to a specific customer via
 *              their active or pending leases, plus all 'available' units
 *              in the fleet (for manual assignment).
 *
 *              Used by the reservation create form to populate the unit
 *              selector dropdown. Customer-linked units appear first (labelled
 *              with the customer's lease info); all other available units follow.
 *
 *              Also returns the list of all active equipment_templates for the
 *              "Trailer Type" dropdown on the create form.
 *
 * @method      GET
 * @query       customer_id (optional — if omitted, returns only available units)
 * @auth        Session required; require_permission('reservations','create')
 * @returns     200 { customer_units[], available_units[], templates[] }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations — create form
 * @decisions   D5 (soft-delete)
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('reservations', 'create');

$customerId = clean_int($_GET['customer_id'] ?? null);

// ── Customer-linked units (via active/pending leases) ──────────
$customerUnits = [];
if ($customerId) {
    $customerUnits = db_select(
        "SELECT
            eu.id,
            eu.unit_number,
            eu.status,
            t.name AS template_name,
            eu.year,
            l.contract_number,
            l.status AS lease_status
         FROM leases l
         JOIN equipment_units eu     ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
         LEFT JOIN equipment_templates t ON t.id = eu.template_id AND t.deleted_at IS NULL
         WHERE l.customer_id = ?
           AND l.status IN ('active', 'pending')
           AND l.deleted_at IS NULL
         ORDER BY eu.unit_number ASC",
        [$customerId]
    );
}

// ── All available units (not on lease, not reserved, not in maintenance) ──
$availableUnits = db_select(
    "SELECT
        eu.id,
        eu.unit_number,
        eu.status,
        t.name AS template_name,
        eu.year,
        eu.yard_location
     FROM equipment_units eu
     LEFT JOIN equipment_templates t ON t.id = eu.template_id AND t.deleted_at IS NULL
     WHERE eu.status = 'available'
       AND eu.deleted_at IS NULL
     ORDER BY t.name ASC, eu.unit_number ASC",
    []
);

// ── Equipment templates for Trailer Type dropdown ──────────────
$templates = db_select(
    "SELECT id, name, default_daily_rate, default_weekly_rate, default_monthly_rate
     FROM equipment_templates
     WHERE deleted_at IS NULL
     ORDER BY name ASC",
    []
);

json_success([
    'customer_units'  => $customerUnits,
    'available_units' => $availableUnits,
    'templates'       => $templates,
]);
