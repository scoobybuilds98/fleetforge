<?php
declare(strict_types=1);

/**
 * api/v1/payments/bulk_delete.php
 *
 * Bulk soft-delete payments and reverse all their effects. Each ID is processed
 * independently inside its own transaction; a failure on one ID does not abort
 * the remaining IDs.
 *
 * S-AUDIT-BILLING-ENGINE-1 #3: each id now DELEGATES to
 * FinancialActions::voidPayment — the previous hand-copied reversal omitted
 * the journal-entry reversal (bulk-voided payments left DR Cash / CR AR
 * posted). voidPayment handles: soft-delete, allocation reversal, Path-B
 * counters, invoice status revert, JE reversal, audit, notification, QBO
 * enqueue, dashboard invalidation.
 *
 * Requires Manager or higher permission. Accountants can record but not void.
 *
 * @method  POST
 * @body    JSON: { "ids": [int, ...], "reason": string }   (max 100 ids)
 * @auth    Session required; require_permission('payments','delete')
 * @returns 200 { success:true, data:{ actioned:N, skipped:N, errors:[{id,reason}] } }
 *
 * Decisions: D5/D13 (soft-delete), D16 (bcmath), Trap 6 (counters in same txn),
 *            S-FIX-2 Path B (terminal invoice guard)
 * Session: S-BULK-DELETE-PAYMENTS
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'delete');

$body = json_body();

// ── Validate ids array ────────────────────────────────────────────────────────

$rawIds = $body['ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}
if (count($rawIds) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 ids per request.', 422);
}

// Coerce to clean integers and reject any non-positive values.
$ids = [];
foreach ($rawIds as $raw) {
    $int = clean_int($raw);
    if (!$int || $int <= 0) {
        json_error('VALIDATION_ERROR', 'All ids must be positive integers.', 422);
    }
    $ids[] = $int;
}
$ids = array_values(array_unique($ids));

// ── Validate reason ───────────────────────────────────────────────────────────

$reason = clean_string($body['reason'] ?? null, 500);
if (!$reason) {
    json_error('MISSING_REQUIRED', 'reason is required when deleting payments.', 422);
}

// ── Shared context (read once, reused per iteration) ─────────────────────────

$now       = date('Y-m-d H:i:s');
$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'System';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// ── Process each ID independently ────────────────────────────────────────────

$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($ids as $id) {
    // S-AUDIT-BILLING-ENGINE-1 #3: delegate to FinancialActions::voidPayment —
    // this file used to hand-copy the counter reversal and OMITTED the journal
    // entry reversal, so bulk-voided payments left DR Cash / CR AR posted
    // forever (GL corruption). voidPayment is the single void authority:
    // allocations, statuses, Path-B counters, JE reversal (409 on closed
    // period), notification, QBO enqueue, dashboard invalidation.
    try {
        \FleetForge\AI\Actions\FinancialActions::voidPayment($id, $reason, $userId, $userName, $ipAddress);
        $actioned++;
    } catch (\FleetForge\AI\Actions\ActionException $e) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => $e->getMessage()];
    } catch (\Throwable $e) {
        // Isolate the failure; log server-side but return a safe message to the caller.
        error_log('bulk_delete payments: id=' . $id . ' failed: ' . $e->getMessage());
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Delete failed due to a server error.'];
    }
}

if ($actioned > 0) {
    invalidate_dashboard_cache();
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
