<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Tables API
 *
 * @file        api/v1/dashboard/tables.php
 * @description Returns ten operational tables for the dashboard. All ten
 *              return up to 10 rows.
 *
 *              active_leases       — newest start_date first. Includes
 *                                    days_active for the "Active For X days"
 *                                    sub-value on the card.
 *              pending_leases      — most overdue start_date first. Includes
 *                                    weekly_rate + end_date for the card.
 *              upcoming_returns    — active leases with end_date within 60 days,
 *                                    soonest first. Includes days_remaining,
 *                                    monthly_rate, daily_rate, currency.
 *              invoices            — outstanding (sent/overdue/partially_paid
 *                                    with balance_due > 0), soonest due_date
 *                                    first. Includes days_overdue + invoice_date.
 *              reservations        — confirmed/pending with pickup today or
 *                                    later, soonest first. Includes
 *                                    days_until_pickup. Several fields are
 *                                    synthesized — see WHY at the query.
 *              expiring_this_month — active leases whose end_date falls in the
 *                                    current calendar month, soonest first.
 *                                    Includes days_remaining.
 *              draft_invoices      — invoices in draft status, oldest first
 *                                    (longest sitting in draft). Includes
 *                                    days_in_draft.
 *              high_value_leases   — top 10 active leases by effective monthly
 *                                    rate (weekly*4.33 and daily*30 normalised
 *                                    for comparison). Includes days_active.
 *              recently_activated  — active leases with start_date in the last
 *                                    7 days, newest first. Includes days_active.
 *              overdue_payments    — invoices with status='overdue' and
 *                                    balance_due > 0, sorted biggest debt first.
 *                                    Includes days_overdue.
 *
 *              No caching — these need to be live (small queries, fast).
 *              No module permission required — dashboard accessible to all staff.
 *
 * @method      GET
 * @auth        Session required (require_auth_api)
 * @returns     200 { active_leases[], pending_leases[], upcoming_returns[],
 *                    invoices[], reservations[], expiring_this_month[],
 *                    draft_invoices[], high_value_leases[],
 *                    recently_activated[], overdue_payments[] }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.1 Dashboard
 * @session     S008, S-DASHBOARD-CAROUSEL-REORGANIZE, S-DASHBOARD-CAROUSEL-ENRICH
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// ── Active leases — top 10, newest start_date first ───────────
$activeLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date, l.status,
            l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(CURDATE(), l.start_date)               AS days_active,
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

// ── Pending activations — top 10, most overdue start_date first ─
$pendingLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date, l.created_at,
            l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number,
            DATEDIFF(CURDATE(), l.start_date) AS days_overdue
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'pending' AND l.deleted_at IS NULL
     ORDER BY l.start_date ASC
     LIMIT 10",
    []
);

// ── Upcoming returns — active leases ending within 60 days ─────
$upcomingReturns = db_select(
    "SELECT l.id, l.contract_number, l.end_date,
            l.monthly_rate, l.daily_rate, l.currency,
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
     LIMIT 10",
    []
);

// ── Outstanding invoices — sent/overdue/partially_paid with balance > 0 ─
// S-DASHBOARD-CAROUSEL-REORGANIZE: spec asked for status='partial' but the
// actual invoices.status enum uses 'partially_paid'; balance_due > 0 filters
// out fully-paid items that haven't transitioned to status='paid' yet.
// 3-level COALESCE for customer name picks live name, falls back to invoice
// snapshot, then to legacy customer_name_snapshot for the oldest rows.
$invoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date,
            COALESCE(c.company_name, i.company_name_snapshot, i.customer_name_snapshot) AS customer_name,
            i.total_amount, i.balance_due, i.due_date, i.status,
            DATEDIFF(CURDATE(), i.due_date) AS days_overdue
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
// see FLEETFORGE_DATABASE_MASTER.sql line ~2740.
// S-DASHBOARD-CAROUSEL-ENRICH: synthesize missing fields rather than JOINing
// non-existent FKs. CONCAT('RES-', r.id) provides a human-readable reference.
// NULL placeholders for unit/equipment keep the card template consistent.
// days_until_pickup is computed so the card can colour-code urgency.
$reservations = db_select(
    "SELECT r.id,
            CONCAT('RES-', r.id)                              AS reservation_number,
            r.status, r.pickup_date, r.pickup_time, r.quantity,
            DATEDIFF(r.pickup_date, CURDATE())                AS days_until_pickup,
            NULL                                              AS unit_number,
            NULL                                              AS equipment_type,
            NULL                                              AS return_date,
            COALESCE(c.company_name, r.company_name)          AS customer_name
     FROM reservations r
     LEFT JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
     WHERE r.status IN ('confirmed', 'pending')
       AND r.pickup_date >= CURDATE()
       AND r.deleted_at IS NULL
     ORDER BY r.pickup_date ASC
     LIMIT 10",
    []
);

// ── Expiring this month — active leases ending this calendar month ─
$expiringThisMonth = db_select(
    "SELECT l.id, l.contract_number, l.end_date,
            l.monthly_rate, l.daily_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(l.end_date, CURDATE()) AS days_remaining,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active'
       AND l.end_date IS NOT NULL
       AND YEAR(l.end_date)  = YEAR(CURDATE())
       AND MONTH(l.end_date) = MONTH(CURDATE())
       AND l.deleted_at IS NULL
     ORDER BY l.end_date ASC
     LIMIT 10",
    []
);

// ── Draft invoices — oldest draft first (longest sitting in draft) ─
$draftInvoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount,
            COALESCE(c.company_name, i.company_name_snapshot, i.customer_name_snapshot) AS customer_name,
            DATEDIFF(CURDATE(), i.invoice_date) AS days_in_draft
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.status = 'draft'
       AND i.deleted_at IS NULL
     ORDER BY i.invoice_date ASC
     LIMIT 10",
    []
);

// ── High-value leases — top 10 active by effective monthly rate ───
// Normalise all billing cadences to a comparable monthly figure:
// weekly_rate * 4.33 and daily_rate * 30 so GREATEST() picks the right row.
$highValueLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date,
            l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(CURDATE(), l.start_date) AS days_active,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active'
       AND l.deleted_at IS NULL
     ORDER BY GREATEST(
         COALESCE(l.monthly_rate, 0),
         COALESCE(l.weekly_rate,  0) * 4.33,
         COALESCE(l.daily_rate,   0) * 30
     ) DESC
     LIMIT 10",
    []
);

// ── Recently activated — leases that went active in the last 7 days ─
$recentlyActivated = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date,
            l.monthly_rate, l.daily_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(CURDATE(), l.start_date) AS days_active,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active'
       AND l.start_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
       AND l.deleted_at IS NULL
     ORDER BY l.start_date DESC
     LIMIT 10",
    []
);

// ── Overdue payments — invoices past due, biggest balance first ───
$overduePayments = db_select(
    "SELECT i.id, i.invoice_number, i.due_date, i.balance_due, i.total_amount,
            DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
            COALESCE(c.company_name, i.company_name_snapshot, i.customer_name_snapshot) AS customer_name
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.status = 'overdue'
       AND i.deleted_at IS NULL
       AND i.balance_due > 0
     ORDER BY i.balance_due DESC
     LIMIT 10",
    []
);

json_success([
    'active_leases'        => $activeLeases,
    'pending_leases'       => $pendingLeases,
    'upcoming_returns'     => $upcomingReturns,
    'invoices'             => $invoices,
    'reservations'         => $reservations,
    'expiring_this_month'  => $expiringThisMonth,
    'draft_invoices'       => $draftInvoices,
    'high_value_leases'    => $highValueLeases,
    'recently_activated'   => $recentlyActivated,
    'overdue_payments'     => $overduePayments,
]);
