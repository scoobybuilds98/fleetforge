<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Tables API
 *
 * @file        api/v1/dashboard/tables.php
 * @description Returns five operational tables for the dashboard:
 *
 *              active_leases     — top 10 active leases, newest start_date first.
 *              pending_leases    — pending leases awaiting activation, oldest first
 *                                  (most overdue start_date at top).
 *              upcoming_returns  — active leases with end_date within 60 days,
 *                                  soonest first. Includes days_remaining (int).
 *              invoices          — outstanding invoices (sent/overdue/partially_paid
 *                                  with balance_due > 0), soonest due_date first.
 *                                  S-DASHBOARD-CAROUSEL-REORGANIZE.
 *              reservations      — upcoming confirmed/pending reservations with
 *                                  pickup_date today or later, soonest first.
 *                                  S-DASHBOARD-CAROUSEL-REORGANIZE.
 *
 *              No caching — these need to be live (small queries, fast).
 *              No module permission required — dashboard accessible to all staff.
 *
 * @method      GET
 * @auth        Session required (require_auth_api)
 * @returns     200 { active_leases[], pending_leases[], upcoming_returns[],
 *                    invoices[], reservations[] }
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

// ── Outstanding invoices — sent/overdue/partially_paid with balance > 0 ─
// S-DASHBOARD-CAROUSEL-REORGANIZE: spec asked for status='partial' but the
// actual invoices.status enum uses 'partially_paid'; balance_due > 0 filters
// out fully-paid items that haven't transitioned to status='paid' yet.
// 3-level COALESCE for customer name picks live name, falls back to invoice
// snapshot, then to legacy customer_name_snapshot for the oldest rows.
$invoices = db_select(
    "SELECT i.id, i.invoice_number,
            COALESCE(c.company_name, i.company_name_snapshot, i.customer_name_snapshot) AS customer_name,
            i.total_amount, i.balance_due, i.due_date, i.status
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.status IN ('sent', 'overdue', 'partially_paid')
       AND i.deleted_at IS NULL
       AND i.balance_due > 0
     ORDER BY i.due_date ASC
     LIMIT 10",
    []
);

// ── Upcoming reservations — confirmed/pending with pickup today or later ─
// S-DASHBOARD-CAROUSEL-REORGANIZE: reservations table has no
// reservation_number, no equipment_unit_id FK, and no return_date column —
// see FLEETFORGE_DATABASE_MASTER.sql line ~2740. So the dashboard card uses
// '#' + id for the link, drops the Unit row entirely (replaced with quantity
// in the UI), and has no Return row. customer_name falls back to the
// reservation's company_name snapshot when the customer FK is soft-deleted.
$reservations = db_select(
    "SELECT r.id, r.status, r.pickup_date, r.pickup_time, r.quantity,
            COALESCE(c.company_name, r.company_name) AS customer_name
     FROM reservations r
     LEFT JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
     WHERE r.status IN ('confirmed', 'pending')
       AND r.pickup_date >= CURDATE()
       AND r.deleted_at IS NULL
     ORDER BY r.pickup_date ASC
     LIMIT 10",
    []
);

json_success([
    'active_leases'    => $activeLeases,
    'pending_leases'   => $pendingLeases,
    'upcoming_returns' => $upcomingReturns,
    'invoices'         => $invoices,
    'reservations'     => $reservations,
]);
