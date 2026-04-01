<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Tables API
 *
 * @file        api/v1/dashboard/tables.php
 * @description Returns three operational tables for the dashboard:
 *
 *              active_leases     — top 10 active leases, newest start_date first.
 *              pending_leases    — pending leases awaiting activation, oldest first
 *                                  (most overdue start_date at top).
 *              upcoming_returns  — active leases with end_date within 60 days,
 *                                  soonest first. Includes days_remaining (int).
 *
 *              No caching — these need to be live (small queries, fast).
 *              No module permission required — dashboard accessible to all staff.
 *
 * @method      GET
 * @auth        Session required (require_auth_api)
 * @returns     200 { active_leases[], pending_leases[], upcoming_returns[] }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.1 Dashboard
 * @session     S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// ── Active leases — top 10, newest start_date first ───────────
$activeLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date,
            l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
            l.template_name_snapshot,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active' AND l.deleted_at IS NULL
     ORDER BY l.start_date DESC
     LIMIT 10",
    []
);

// ── Pending activations — top 8, most overdue start_date first ─
$pendingLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.created_at,
            l.monthly_rate, l.daily_rate, l.currency,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number,
            DATEDIFF(CURDATE(), l.start_date) AS days_overdue
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'pending' AND l.deleted_at IS NULL
     ORDER BY l.start_date ASC
     LIMIT 8",
    []
);

// ── Upcoming returns — active leases ending within 60 days ─────
$upcomingReturns = db_select(
    "SELECT l.id, l.contract_number, l.end_date,
            DATEDIFF(l.end_date, CURDATE()) AS days_remaining,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number,
            l.template_name_snapshot
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active'
       AND l.end_date IS NOT NULL
       AND l.end_date >= CURDATE()
       AND l.end_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
       AND l.deleted_at IS NULL
     ORDER BY l.end_date ASC
     LIMIT 8",
    []
);

json_success([
    'active_leases'    => $activeLeases,
    'pending_leases'   => $pendingLeases,
    'upcoming_returns' => $upcomingReturns,
]);
