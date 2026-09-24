<?php
declare(strict_types=1);

/**
 * app/admin/equipment/payoff.php
 *
 * RETIRED page — kept as a redirect so old links, bookmarks and the fleet
 * reports keep working.
 *
 * S-PAYOFF-ONE-PAGE (2026-09-24): the operator asked for ONE payoff page
 * instead of two (the unit page's Payoff tab + this "View Full Analysis"
 * deep-dive, which duplicated the tab's KPIs, scenarios, chart and cost
 * tables and computed its numbers with its own copy of the payoff math). The
 * unit page's Payoff tab now shows everything this page had — cost structure,
 * financing, depreciation, revenue by lease, a 24-month P&L, the monthly
 * revenue-vs-costs chart — from api/v1/accounting/fixed_assets/payoff.php
 * ?detail=1. Work orders, damage claims and utilisation were already on the
 * unit page's own tabs (Maintenance, Damage Claims, Lease History).
 *
 * Route: /equipment/payoff?id={unit_id} → /equipment/show?id={unit_id}#payoff
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$unitId = clean_int($_GET['id'] ?? null);
header('Location: ' . ($unitId
    ? base_url('equipment/show') . '?id=' . $unitId . '#payoff'
    : base_url('equipment')), true, 301);
exit;
