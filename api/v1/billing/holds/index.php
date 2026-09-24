<?php
declare(strict_types=1);

/**
 * api/v1/billing/holds/index.php
 *
 * S-BILLING-MODULE — billing holds (standing "do not bill" instructions on
 * a lease or a whole customer). See lib/Billing/Cycle/BillingHolds.php.
 *
 * @method  GET
 * @query   state = active (default) | released | all, customer_id?, lease_id?
 * @auth    Session required; invoices:view
 * @returns 200 { holds: [...] }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingHolds;

$state = (string) ($_GET['state'] ?? 'active');
if (!in_array($state, ['active', 'released', 'all'], true)) {
    $state = 'active';
}
$rows = BillingHolds::list($state, clean_int($_GET['customer_id'] ?? null), clean_int($_GET['lease_id'] ?? null));

json_success(['holds' => array_map(static fn($h) => [
    'id'               => (int) $h['id'],
    'scope'            => $h['scope'],
    'lease_id'         => $h['lease_id'] !== null ? (int) $h['lease_id'] : null,
    'contract_number'  => $h['contract_number'],
    'unit_number'      => $h['unit_number'],
    'customer_id'      => (int) $h['customer_id'],
    'company_name'     => $h['company_name'],
    'customer_active_leases' => (int) $h['customer_active_leases'],
    'reason'           => $h['reason'],
    'starts_on'        => $h['starts_on'],
    'ends_on'          => $h['ends_on'],
    'active'           => $h['released_at'] === null && ($h['ends_on'] === null || $h['ends_on'] >= ff_today()),
    'released_at'      => $h['released_at'],
    'released_by_name' => $h['released_by_name'],
    'release_note'     => $h['release_note'],
    'created_by_name'  => $h['created_by_name'],
    'created_at'       => $h['created_at'],
], $rows)]);
