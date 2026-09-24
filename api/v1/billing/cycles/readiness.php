<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/readiness.php
 *
 * S-BILLING-MODULE — run the cycle's readiness checks (BillingReadiness)
 * and return them. Read-only apart from stamping the result summary and
 * time on the cycle (so the list and the stepper show the last result).
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:view
 * @returns 200 { checks: [...], summary: {...}, checked_at }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingReadiness;

$cycle = billing_cycle_from_request();
json_success(BillingReadiness::run($cycle));
