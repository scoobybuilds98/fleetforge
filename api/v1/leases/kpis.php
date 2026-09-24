<?php
declare(strict_types=1);

/**
 * FleetForge — Leases summary strip
 *
 * @file        api/v1/leases/kpis.php
 * @description The numbers on the leases list's summary strip, in one query.
 *              Replaces the old four list calls (active / pending / completed /
 *              cancelled counts — the status tabs already show those) plus a
 *              dashboard call, with the lists a dispatcher acts on: leases
 *              ending (or past their end date), starting this week, and
 *              active leases whose billing is behind. Each count uses the
 *              same window as the list's ?focus= filter (_focus.php), so a
 *              click on a tile always lists exactly that many leases.
 *
 * @method      GET
 * @auth        Session required; require_permission('leases','view')
 * @returns     200 { active, pending, closed, ending, overdue, starting,
 *                    unbilled, active_revenue (CAD/month, null without
 *                    financial access) }
 *
 * @decisions   D5 (soft delete), D16 (bcmath money), reporting policy (CAD
 *              canonical via exchange_rate_to_cad)
 * @session     S-LIST-COMPACT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once __DIR__ . '/_focus.php';

require_method('GET');
require_auth_api();
require_permission('leases', 'view');

$today = ff_today();

// One pass over the table: each window is the _focus.php fragment, so the
// strip and the filtered list cannot drift apart.
$cols   = [];
$params = [];
foreach (FF_LEASE_FOCUS_KEYS as $key) {
    [$sql, $bind] = ff_lease_focus_sql($key, $today);
    $cols[]  = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) AS `{$key}`";
    array_push($params, ...$bind);
}

$row = db_row(
    "SELECT SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END)  AS active,
            SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN l.status IN ('completed','cancelled') THEN 1 ELSE 0 END) AS closed,
            COALESCE(SUM(CASE WHEN l.status = 'active'
                              THEN (CASE WHEN l.currency = 'USD'
                                         THEN l.monthly_rate * COALESCE(l.exchange_rate_to_cad, 1)
                                         ELSE l.monthly_rate END)
                              ELSE 0 END), 0) AS active_revenue,
            " . implode(",\n            ", $cols) . "
       FROM leases l
      WHERE l.deleted_at IS NULL",
    $params
) ?? [];

$out = [
    'active'         => (int) ($row['active'] ?? 0),
    'pending'        => (int) ($row['pending'] ?? 0),
    'closed'         => (int) ($row['closed'] ?? 0),
    // Monthly revenue is a money total: only for roles that see money
    // (dispatchers see each lease's rate, not the fleet's takings).
    'active_revenue' => can_view_financials() ? bcround((string) ($row['active_revenue'] ?? '0'), 2) : null,
];
foreach (FF_LEASE_FOCUS_KEYS as $key) {
    $out[$key] = (int) ($row[$key] ?? 0);
}

json_success($out);
