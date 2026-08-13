<?php
declare(strict_types=1);

/**
 * api/v1/invoices/billing_exceptions/flag.php
 *
 * S-BILLING-EXCEPTIONS — MANUALLY flag a lease for a closer look.
 *
 * The automatic flagging in batch_generate.php only fires when a lease
 * FAILS to bill. This covers the other case, which is just as common in
 * practice: the invoice computes fine, but the reviewer looking at the
 * preview thinks the number is wrong and wants it set aside rather than
 * shipped to the customer.
 *
 * Flagging here means "do not bill this one yet" — the caller drops the
 * lease from the selection so the rest of the batch still generates, and
 * this row is what stops the set-aside lease from being quietly forgotten.
 * That is the whole point: without a durable record, "I'll look at that
 * one later" means nobody ever does.
 *
 * A reason is REQUIRED. A flag that just says "something's off" is
 * useless to whoever picks it up — including the same person next week.
 *
 * Idempotent per (lease, period) via the same uq_lease_period upsert the
 * automatic path uses, so flagging twice refreshes rather than duplicates.
 *
 * @method  POST
 * @body    { lease_id, period_start, period_end, reason }
 * @auth    Session required; require_permission('invoices','create')
 *          — same gate as generating: deciding NOT to bill something is
 *          part of running a batch, not a separate privilege.
 * @returns 200 { flagged: true, lease_id, open_count }
 *
 * @session S-BILLING-EXCEPTIONS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\BillingExceptions;

$body = json_body();

$leaseId = clean_int($body['lease_id'] ?? null);
if (!$leaseId) {
    json_error('MISSING_REQUIRED', 'lease_id is required.', 422);
}

$periodStart = clean_date($body['period_start'] ?? null);
$periodEnd   = clean_date($body['period_end'] ?? null);

$fields = [];
if (!$periodStart) $fields['period_start'] = 'A valid period start date is required.';
if (!$periodEnd)   $fields['period_end']   = 'A valid period end date is required.';

$reason = clean_string($body['reason'] ?? null, 2000);
if (!$reason) {
    $fields['reason'] = 'Say what looks wrong — a flag with no reason is useless to whoever picks it up.';
}
if ($fields) {
    json_validation_error($fields);
}

$lease = db_row(
    "SELECT l.id, l.customer_id, l.contract_number, c.company_name
       FROM leases l
       LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
      WHERE l.id = ? AND l.deleted_at IS NULL",
    [$leaseId]
);
if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

$userId   = current_user_id();
$userName = current_user()['name'] ?? 'System';

BillingExceptions::flag(
    $leaseId,
    $lease['customer_id'] !== null ? (int) $lease['customer_id'] : null,
    $periodStart,
    $periodEnd,
    // Prefixed so the queue makes the provenance obvious at a glance —
    // a human set this aside, the engine did not reject it.
    'Held for review by ' . $userName . ': ' . $reason,
    'manual',
    null,
    $userId
);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => $userName,
    'action'       => 'create',
    'module'       => 'invoices',
    'entity_type'  => 'billing_exception',
    'entity_id'    => $leaseId,
    'entity_label' => (string) ($lease['contract_number'] ?? ('lease #' . $leaseId)),
    'notes'        => "Lease #{$leaseId} ({$lease['company_name']}) held back from billing for "
                    . "{$periodStart}..{$periodEnd} by {$userName} — {$reason}",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success([
    'flagged'    => true,
    'lease_id'   => $leaseId,
    'open_count' => BillingExceptions::openCount(),
]);
