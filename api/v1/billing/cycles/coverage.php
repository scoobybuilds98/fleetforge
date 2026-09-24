<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/coverage.php
 *
 * S-BILLING-MODULE — lease-by-lease picture of the cycle: every lease on
 * rent during the month and what happened to it (billed, covered elsewhere,
 * held, exception, bills at close, closed-unbilled, void-rebillable, to
 * bill). See BillingCycles::coverage() for the status rules.
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:view (amounts need can_view_financials)
 * @returns 200 { rows: [...], counts: {status: n} }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCycles;

$cycle = billing_cycle_from_request();
json_success(BillingCycles::coverage($cycle, can_view_financials()));
