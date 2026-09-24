<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/open.php
 *
 * S-BILLING-MODULE — open (or return the existing) billing cycle for a
 * month. Idempotent: opening a month that already has a cycle returns it.
 * Any month from 2000-01 up to NEXT month can be opened — past months so a
 * draft backlog can be worked through as ordinary cycles.
 *
 * @method  POST
 * @body    { month: 'YYYY-MM' }
 * @auth    Session required; invoices:create
 * @returns 200/201 { cycle: {...}, created: bool, url }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\Cycle\BillingCycles;

$body  = json_body();
$month = (string) ($body['month'] ?? '');
if (!BillingCycles::isValidMonth($month)) {
    json_validation_error(['month' => 'Pick a month (YYYY-MM).']);
}
$nextMonth = BillingCycles::shiftMonth(substr(ff_today(), 0, 7), 1);
if ($month > $nextMonth) {
    json_validation_error(['month' => 'A cycle can be opened at most one month ahead.']);
}

[$cycle, $created] = BillingCycles::ensure($month, current_user_id());

json_success([
    'cycle'   => [
        'id'        => $cycle['id'],
        'reference' => $cycle['reference'],
        'month'     => $cycle['month'],
        'label'     => $cycle['label'],
        'status'    => $cycle['status'],
    ],
    'created' => $created,
    'url'     => base_url('billing/cycle') . '?id=' . $cycle['id'],
], $created ? 201 : 200);
