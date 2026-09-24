<?php
declare(strict_types=1);

/**
 * api/v1/billing/holds/create.php
 *
 * S-BILLING-MODULE — place a billing hold on a lease or a whole customer.
 * Held leases are refused by the workbench and skipped by the monthly job
 * until the hold is released or its end date passes.
 *
 * @method  POST
 * @body    { scope: 'lease'|'customer', lease_id?, customer_id?, reason, starts_on?, ends_on? }
 * @auth    Session required; invoices:edit
 * @returns 201 { id }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingHolds;

$body = json_body();
$startsOn = ($body['starts_on'] ?? '') !== '' ? clean_date($body['starts_on']) : ff_today();
$endsOn   = ($body['ends_on'] ?? '') !== '' ? clean_date($body['ends_on']) : null;
$fields = [];
if (!$startsOn) $fields['starts_on'] = 'Enter a valid start date.';
if (($body['ends_on'] ?? '') !== '' && !$endsOn) $fields['ends_on'] = 'Enter a valid end date.';
if ($fields) json_validation_error($fields);

try {
    $id = BillingHolds::create(
        (string) ($body['scope'] ?? ''),
        clean_int($body['lease_id'] ?? null),
        clean_int($body['customer_id'] ?? null),
        (string) ($body['reason'] ?? ''),
        $startsOn,
        $endsOn,
        current_user_id()
    );
} catch (\InvalidArgumentException $e) {
    $f = json_decode($e->getMessage(), true);
    json_validation_error(is_array($f) ? $f : ['reason' => $e->getMessage()]);
}

json_success(['id' => $id], 201);
