<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/update.php
 *
 * S-BILLING-MODULE — change a cycle's owner, target dates or notes.
 * Only the keys sent are changed. Notes can be edited on a closed cycle
 * (a close-out remark); owner and targets only while it is open.
 *
 * @method  POST
 * @body    { id, owner_user_id?, bill_by_date?, send_by_date?, notes? }
 * @auth    Session required; invoices:edit
 * @returns 200 { cycle }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingCycles;

$cycle = billing_cycle_from_request();
$body  = json_body();

$set = [];
$fields = [];
$scheduling = array_intersect(array_keys($body), ['owner_user_id', 'bill_by_date', 'send_by_date']);
if ($scheduling && $cycle['status'] !== 'open') {
    json_error('CYCLE_CLOSED', 'Owner and target dates can only change while the cycle is open.', 409);
}

if (array_key_exists('owner_user_id', $body)) {
    $owner = clean_int($body['owner_user_id']);
    if ($owner) {
        if (!db_row("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL AND status = 'active'", [$owner])) {
            $fields['owner_user_id'] = 'Pick an active user.';
        }
        $set['owner_user_id'] = $owner;
    } else {
        $set['owner_user_id'] = null;
    }
}
foreach (['bill_by_date', 'send_by_date'] as $k) {
    if (array_key_exists($k, $body)) {
        $raw = $body[$k];
        if ($raw === null || $raw === '') {
            $set[$k] = null;
        } elseif ($d = clean_date($raw)) {
            $set[$k] = $d;
        } else {
            $fields[$k] = 'Enter a valid date.';
        }
    }
}
if (!empty($set['bill_by_date']) && !empty($set['send_by_date']) && $set['send_by_date'] < $set['bill_by_date']) {
    $fields['send_by_date'] = 'The send-by date cannot be before the review-by date.';
}
if (array_key_exists('notes', $body)) {
    $notes = trim((string) $body['notes']);
    if (mb_strlen($notes) > 5000) {
        $fields['notes'] = 'Keep notes under 5,000 characters.';
    }
    $set['notes'] = $notes !== '' ? $notes : null;
}
if ($fields) {
    json_validation_error($fields);
}
if (!$set) {
    json_error('MISSING_REQUIRED', 'Nothing to change.', 422);
}

$old = array_intersect_key($cycle, $set);
db_update('billing_cycles', $set, 'id = ?', [$cycle['id']]);
BillingCycles::audit($cycle, 'update', 'Updated billing cycle ' . $cycle['reference'] . ' (' . implode(', ', array_keys($set)) . ').', $old, $set);

json_success(['cycle' => BillingCycles::find($cycle['id'])]);
