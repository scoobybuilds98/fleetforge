<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Tables API
 *
 * @file        api/v1/dashboard/tables.php
 * @description Returns eleven operational tables for the dashboard. The first
 *              ten return up to 10 rows; idle_units returns up to 8.
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
 *              idle_units          — S-DASHBOARD-VIZ. Up to 8 AVAILABLE units
 *                                    idle longest: { id, unit_number, type,
 *                                    category, yard, idle_since, idle_days }.
 *                                    No money fields. See WHY at the query.
 *
 *              No caching — these need to be live (small queries, fast).
 *              No module permission required — dashboard accessible to all staff.
 *
 * @method      GET
 * @auth        Session required (require_auth_api)
 * @returns     200 { active_leases[], pending_leases[], upcoming_returns[],
 *                    invoices[], reservations[], expiring_this_month[],
 *                    draft_invoices[], high_value_leases[],
 *                    recently_activated[], overdue_payments[], idle_units[] }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.1 Dashboard
 * @session     S008, S-DASHBOARD-CAROUSEL-REORGANIZE, S-DASHBOARD-CAROUSEL-ENRICH,
 *              S-DASHBOARD-VIZ
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// Company-local business "today". The PDO session is pinned to UTC
// (includes/db.php), so SQL CURDATE() is the UTC calendar day — after 5pm
// Pacific it is already tomorrow. Every DATE column compared below (start_date,
// end_date, due_date, invoice_date, pickup_date) holds a Pacific calendar day,
// so each query binds this value instead of calling CURDATE().
$today = ff_today();

// ── Active leases — top 10, newest start_date first ───────────
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
$activeLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date, l.status,
            l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(?, l.start_date)                       AS days_active,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active' AND l.deleted_at IS NULL
     ORDER BY l.start_date DESC
     LIMIT 10",
    [$today]
);

// ── Pending activations — top 10, most overdue start_date first ─
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
$pendingLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date, l.created_at,
            l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number,
            DATEDIFF(?, l.start_date) AS days_overdue
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'pending' AND l.deleted_at IS NULL
     ORDER BY l.start_date ASC
     LIMIT 10",
    [$today]
);

// ── Upcoming returns — active leases ending within 60 days ─────
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
// Three placeholders (days_remaining, window start, window end) — one $today each.
$upcomingReturns = db_select(
    "SELECT l.id, l.contract_number, l.end_date,
            l.monthly_rate, l.daily_rate, l.currency,
            DATEDIFF(l.end_date, ?) AS days_remaining,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number,
            l.template_name_snapshot
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active'
       AND l.end_date IS NOT NULL
       AND l.end_date >= ?
       AND l.end_date <= DATE_ADD(?, INTERVAL 60 DAY)
       AND l.deleted_at IS NULL
     ORDER BY l.end_date ASC
     LIMIT 10",
    [$today, $today, $today]
);

// ── Outstanding invoices — sent/overdue/partially_paid with balance > 0 ─
// S-DASHBOARD-CAROUSEL-REORGANIZE: spec asked for status='partial' but the
// actual invoices.status enum uses 'partially_paid'; balance_due > 0 filters
// out fully-paid items that haven't transitioned to status='paid' yet.
// 3-level COALESCE for customer name picks live name, falls back to invoice
// snapshot, then to legacy customer_name_snapshot for the oldest rows.
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
$invoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date,
            COALESCE(c.company_name, i.company_name_snapshot, i.customer_name_snapshot) AS customer_name,
            i.total_amount, i.balance_due, i.due_date, i.status,
            DATEDIFF(?, i.due_date) AS days_overdue
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.status IN ('sent', 'overdue', 'partially_paid')
       AND i.deleted_at IS NULL
       AND i.balance_due > 0
     ORDER BY i.due_date ASC
     LIMIT 10",
    [$today]
);

// ── Upcoming reservations — confirmed/pending with pickup today or later ─
// S-DASHBOARD-CAROUSEL-REORGANIZE: reservations table has no
// reservation_number, no equipment_unit_id FK, and no return_date column —
// see FLEETFORGE_DATABASE_MASTER.sql line ~2740.
// S-DASHBOARD-CAROUSEL-ENRICH: synthesize missing fields rather than JOINing
// non-existent FKs. CONCAT('RES-', r.id) provides a human-readable reference.
// NULL placeholders for unit/equipment keep the card template consistent.
// days_until_pickup is computed so the card can colour-code urgency.
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
// Two placeholders (days_until_pickup, pickup window start) — one $today each.
$reservations = db_select(
    "SELECT r.id,
            CONCAT('RES-', r.id)                              AS reservation_number,
            r.status, r.pickup_date, r.pickup_time, r.quantity,
            DATEDIFF(r.pickup_date, ?)                        AS days_until_pickup,
            NULL                                              AS unit_number,
            NULL                                              AS equipment_type,
            NULL                                              AS return_date,
            COALESCE(c.company_name, r.company_name)          AS customer_name
     FROM reservations r
     LEFT JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
     WHERE r.status IN ('confirmed', 'pending')
       AND r.pickup_date >= ?
       AND r.deleted_at IS NULL
     ORDER BY r.pickup_date ASC
     LIMIT 10",
    [$today, $today]
);

// ── Expiring this month — active leases ending this calendar month ─
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
// On the last evening of a month the UTC day is already next month, so the
// "this month" boundary must come from $today too. Three placeholders
// (days_remaining, YEAR, MONTH) — one $today each.
$expiringThisMonth = db_select(
    "SELECT l.id, l.contract_number, l.end_date,
            l.monthly_rate, l.daily_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(l.end_date, ?) AS days_remaining,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active'
       AND l.end_date IS NOT NULL
       AND YEAR(l.end_date)  = YEAR(?)
       AND MONTH(l.end_date) = MONTH(?)
       AND l.deleted_at IS NULL
     ORDER BY l.end_date ASC
     LIMIT 10",
    [$today, $today, $today]
);

// ── Draft invoices — oldest draft first (longest sitting in draft) ─
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
$draftInvoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount,
            COALESCE(c.company_name, i.company_name_snapshot, i.customer_name_snapshot) AS customer_name,
            DATEDIFF(?, i.invoice_date) AS days_in_draft
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.status = 'draft'
       AND i.deleted_at IS NULL
     ORDER BY i.invoice_date ASC
     LIMIT 10",
    [$today]
);

// ── High-value leases — top 10 active by effective monthly rate ───
// Normalise all billing cadences to a comparable monthly figure:
// weekly_rate * 4.33 and daily_rate * 30 so GREATEST() picks the right row.
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
$highValueLeases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date,
            l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(?, l.start_date) AS days_active,
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
    [$today]
);

// ── Recently activated — leases that went active in the last 7 days ─
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
// Two placeholders (days_active, 7-day window start) — one $today each.
$recentlyActivated = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date,
            l.monthly_rate, l.daily_rate, l.currency,
            l.template_name_snapshot,
            DATEDIFF(?, l.start_date) AS days_active,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
            COALESCE(u.unit_number,  l.unit_number_snapshot)  AS unit_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status = 'active'
       AND l.start_date >= DATE_SUB(?, INTERVAL 7 DAY)
       AND l.deleted_at IS NULL
     ORDER BY l.start_date DESC
     LIMIT 10",
    [$today, $today]
);

// ── Overdue payments — invoices past due, biggest balance first ───
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
$overduePayments = db_select(
    "SELECT i.id, i.invoice_number, i.due_date, i.balance_due, i.total_amount,
            DATEDIFF(?, i.due_date) AS days_overdue,
            COALESCE(c.company_name, i.company_name_snapshot, i.customer_name_snapshot) AS customer_name
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.status = 'overdue'
       AND i.deleted_at IS NULL
       AND i.balance_due > 0
     ORDER BY i.balance_due DESC
     LIMIT 10",
    [$today]
);

// ── Idle units — AVAILABLE units idle longest (S-DASHBOARD-VIZ) ────
// idle_since = the latest effective end (actual_return_date, else end_date) of
// the unit's leases that is NOT after today; a unit with no such lease falls
// back to its created_at, converted to the company-local date (created_at is a
// UTC DATETIME — DATE() in SQL would be the UTC day). idle_days = days since.
// STATUS CHOICES:
//   - Only active + completed leases count. cancelled never ran, and a pending
//     lease has not been handed over, so its (planned) end_date says nothing
//     about when the unit was last out. Same allowlist as FleetUtilization.
//   - An end date AFTER today (a future-dated return, or an active lease's
//     planned end) is not "idle since" — only ends <= today count.
//   - A unit whose status says 'available' but which still has an active/
//     completed lease covering today (started, not yet ended — e.g. an open-
//     ended active lease) is NOT idle; that is unit-status drift, so it is
//     left out rather than listed with a misleading idle age.
// All available units are fetched and ranked in PHP because the created_at
// fallback needs the UTC → local conversion; the available pool is small.
// Business DATE vs company-local today: SQL CURDATE() is the UTC day (ff_today).
// Three placeholders (last-end cutoff, still-out start, still-out end) — one $today each.
$idleCandidates = db_select(
    "SELECT eu.id, eu.unit_number, eu.created_at,
            et.name                               AS type,
            et.category                           AS category_slug,
            ec.label                              AS category_label,
            NULLIF(TRIM(eu.yard_location), '')    AS yard,
            (SELECT MAX(COALESCE(l.actual_return_date, l.end_date))
               FROM leases l
              WHERE l.equipment_unit_id = eu.id
                AND l.deleted_at IS NULL
                AND l.status IN ('active', 'completed')
                AND COALESCE(l.actual_return_date, l.end_date) <= ?) AS last_end
     FROM equipment_units eu
     LEFT JOIN equipment_templates et  ON et.id   = eu.template_id
     LEFT JOIN equipment_categories ec ON ec.slug = et.category
     WHERE eu.status = 'available'
       AND eu.deleted_at IS NULL
       AND NOT EXISTS (
             SELECT 1 FROM leases l2
              WHERE l2.equipment_unit_id = eu.id
                AND l2.deleted_at IS NULL
                AND l2.status IN ('active', 'completed')
                AND l2.start_date <= ?
                AND (COALESCE(l2.actual_return_date, l2.end_date) IS NULL
                     OR COALESCE(l2.actual_return_date, l2.end_date) > ?)
           )",
    [$today, $today, $today]
);

$idleUnits = [];
$todayDt   = new DateTimeImmutable($today);
foreach ($idleCandidates as $u) {
    // yard_location is the yard NAME string (no FK — see api/v1/yards/destroy.php).
    $since = $u['last_end'] !== null
        ? (string) $u['last_end']
        : ($u['created_at'] !== null ? ff_utc_to_local((string) $u['created_at']) : null);
    $days  = null;
    if ($since !== null) {
        // max(0): a created_at stamped later today (UTC → local) can't go negative.
        $days = max(0, (int) (new DateTimeImmutable($since))->diff($todayDt)->format('%r%a'));
    }
    $slug = $u['category_slug'];
    $idleUnits[] = [
        'id'          => (int) $u['id'],
        'unit_number' => (string) $u['unit_number'],
        'type'        => (string) ($u['type'] ?? ''),
        // Operator label for the category slug, else the humanised slug (the
        // chart_occupancy_by_type / fleet_mix format).
        'category'    => $u['category_label'] !== null && $u['category_label'] !== ''
            ? (string) $u['category_label']
            : ($slug !== null && $slug !== '' ? ucwords(str_replace('_', ' ', (string) $slug)) : null),
        'yard'        => $u['yard'] !== null ? (string) $u['yard'] : null,
        'idle_since'  => $since,
        'idle_days'   => $days,
    ];
}
// Longest idle first; unknown idle age last; unit number breaks ties so the
// list is stable between refreshes.
usort($idleUnits, static function (array $a, array $b): int {
    if ($a['idle_days'] === null || $b['idle_days'] === null) {
        return ($a['idle_days'] === null) <=> ($b['idle_days'] === null)
            ?: strnatcasecmp($a['unit_number'], $b['unit_number']);
    }
    return ($b['idle_days'] <=> $a['idle_days']) ?: strnatcasecmp($a['unit_number'], $b['unit_number']);
});
$idleUnits = array_slice($idleUnits, 0, 8);

$payload = [
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
    'idle_units'           => $idleUnits,
];

// Serve-time financial redaction. Dispatchers (payments=NONE) get the
// operational lists but never the dollar columns — strip every money field
// from every row. high_value_leases keeps its server-side ranking; only the
// amounts are removed, so no figure leaks.
if (!can_view_financials()) {
    $moneyKeys = ['monthly_rate', 'daily_rate', 'weekly_rate', 'total_amount', 'balance_due'];
    foreach ($payload as $key => $rows) {
        if (is_array($rows)) {
            $payload[$key] = redact_rows($rows, $moneyKeys);
        }
    }
}

json_success($payload);
