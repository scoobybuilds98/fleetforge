<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/acknowledge.php
 *
 * S-BILLING-MODULE — acknowledge (or un-acknowledge) a readiness WARNING
 * for a cycle: "we know about this, bill anyway". Recorded on the cycle
 * (readiness_ack) with who and when, and in the audit log. Blockers cannot
 * be acknowledged — they have to be fixed.
 *
 * @method  POST
 * @body    { id, key, acknowledged: bool, note? }
 * @auth    Session required; invoices:edit
 * @returns 200 { readiness_ack }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingCycles;

$cycle = billing_cycle_from_request();
billing_cycle_require_open($cycle);
$body = json_body();

$key = (string) ($body['key'] ?? '');
if (!preg_match('/^[a-z_]{3,40}$/', $key)) {
    json_validation_error(['key' => 'Unknown check.']);
}
// Always-blocker checks cannot be acknowledged. (fx_rate_missing is a
// blocker only when no rate exists at all; BillingReadiness::run() ignores
// an acknowledgement on any check while it is at blocker severity.)
if ($key === 'rates_missing') {
    json_error('NOT_ACKNOWLEDGEABLE', 'Leases with no rate cannot be billed — fix them instead.', 422);
}
$ack  = !empty($body['acknowledged']);
$note = clean_string($body['note'] ?? null, 500);

$acks = $cycle['readiness_ack'] ?: [];
if ($ack) {
    $acks[$key] = ['by' => current_user()['name'] ?? 'user', 'by_id' => current_user_id(), 'at' => ff_now_utc(), 'note' => $note];
} else {
    unset($acks[$key]);
}
db_execute("UPDATE billing_cycles SET readiness_ack = ? WHERE id = ?", [$acks ? json_encode($acks) : null, $cycle['id']]);
BillingCycles::audit($cycle, 'update',
    ($ack ? 'Acknowledged' : 'Withdrew acknowledgement of') . " readiness warning '{$key}'" . ($note ? ": {$note}" : '.'));

json_success(['readiness_ack' => (object) $acks]);
