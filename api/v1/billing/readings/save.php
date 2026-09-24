<?php
declare(strict_types=1);

/**
 * api/v1/billing/readings/save.php
 *
 * S-BILLING-MODULE — save period-end readings on a cycle's Readings sheet.
 * Each row is validated on its own (a reading lower than the previous one
 * is refused with the previous value in the message); valid rows save even
 * when others fail. A row with neither value clears that lease's reading.
 * Odometers arrive in the lease's own unit and are stored in km.
 *
 * @method  POST
 * @body    { cycle_id, readings: [{lease_id, odometer?, engine_hours?, reading_date?, notes?}] } (max 500)
 * @auth    Session required; invoices:create
 * @returns 200 { saved, cleared, errors: [{lease_id, field, message}], progress }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\Cycle\CycleReadings;

$cycle = billing_cycle_from_request();
billing_cycle_require_open($cycle);
$body = json_body();

$readings = $body['readings'] ?? null;
if (!is_array($readings) || !$readings) {
    json_validation_error(['readings' => 'Nothing to save.']);
}
if (count($readings) > 500) {
    json_validation_error(['readings' => 'At most 500 readings at a time.']);
}

$result = CycleReadings::save($cycle, array_values(array_filter($readings, 'is_array')), current_user_id());
$result['progress'] = CycleReadings::progress($cycle);
json_success($result);
