<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/summary.php
 *
 * S-BILLING-MODULE — the month's billing in figures (Close tab): live
 * summary, the frozen close snapshot when closed, invoices added after the
 * close, and the pre-close checks.
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:view (amounts need can_view_financials)
 * @returns 200 { live, snapshot, late_additions, checks }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\CycleClose;

$cycle = billing_cycle_from_request();
$showMoney = can_view_financials();

json_success([
    'live'           => CycleClose::summary($cycle, $showMoney),
    'snapshot'       => $showMoney ? $cycle['close_snapshot'] : null,
    'late_additions' => CycleClose::lateAdditions($cycle, $showMoney),
    'checks'         => CycleClose::preCloseChecks($cycle),
]);
