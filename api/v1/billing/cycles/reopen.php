<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/reopen.php
 *
 * S-BILLING-MODULE — reopen a closed billing cycle so the workbench can
 * bill the month again. Needs the invoices "approve" permission (reopening
 * signed-off billing is a manager decision) and a reason. The earlier close
 * snapshot is kept until the cycle closes again.
 *
 * @method  POST
 * @body    { id, reason }
 * @auth    Session required; invoices:approve
 * @returns 200 { cycle }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'approve');

use FleetForge\Billing\Cycle\BillingCycles;
use FleetForge\Billing\Cycle\CycleClose;

$cycle = billing_cycle_from_request();
$body  = json_body();
$reason = (string) (clean_string($body['reason'] ?? null, 2000) ?? '');

try {
    CycleClose::reopen($cycle, $reason, current_user_id());
} catch (\DomainException $e) {
    json_error('REOPEN_REFUSED', $e->getMessage(), 409);
}

json_success(['cycle' => BillingCycles::find($cycle['id'])]);
