<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/review.php
 *
 * S-BILLING-MODULE — the cycle's invoices with their review flags (double
 * billing, double mileage, swings against last month, $0, no tax, ...),
 * review marks, and the leases billed last month but missing this month.
 * See BillingReview for the flag rules.
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:view (amounts need can_view_financials)
 * @returns 200 { rows, missing, flag_counts, thresholds }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingReview;

$cycle = billing_cycle_from_request();
json_success(BillingReview::run($cycle, can_view_financials()));
