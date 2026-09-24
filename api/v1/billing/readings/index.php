<?php
declare(strict_types=1);

/**
 * api/v1/billing/readings/index.php
 *
 * S-BILLING-MODULE — the cycle's Readings sheet: every lease on rent this
 * month that bills from a MANUAL reading (manual-mileage leases with a
 * mileage rate or precharge; hourly leases), with the previous reading, the
 * saved period-end reading, the Samsara cached odometer as a hint, and
 * whether the month is already billed. See CycleReadings::sheet().
 *
 * @method  GET
 * @query   cycle_id | month
 * @auth    Session required; invoices:view
 * @returns 200 { rows: [...], progress: {required, entered, missing} }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\CycleReadings;

$cycle = billing_cycle_from_request();
json_success([
    'rows'     => CycleReadings::sheet($cycle),
    'progress' => CycleReadings::progress($cycle),
]);
