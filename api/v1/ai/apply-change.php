<?php
declare(strict_types=1);

/**
 * api/v1/ai/apply-change.php
 *
 * S-AI-WRITE-1 — Executes (or undoes) a pending AI change proposal.
 *
 * The AI chat NEVER mutates data directly. A plan_* tool persists a
 * PENDING row in ai_pending_changes; the chat reply renders a confirm
 * card; only an explicit click here applies the change. This endpoint:
 *   - re-loads the proposal (must belong to the user, still actionable)
 *   - re-checks the REAL per-resource edit permission (not just ai:view)
 *   - re-reads current values (captures a before-snapshot for undo)
 *   - writes through the same transaction + audit_log pattern the manual
 *     Edit Unit endpoint uses (api/v1/equipment/units/update.php)
 *   - records a before/after diff and flips status to applied / undone
 *
 * POST /api/v1/ai/apply-change
 *   Body: { proposal_id, action? = "apply" | "undo" }
 *   → 200 { id, status, summary, applied_count } or 4xx error
 *
 * Permission: ai:view (to reach the endpoint) + entity-specific edit
 *             permission (equipment:edit) to actually apply.
 *
 * @depends lib/AI/Tools/FleetForgeTools.php (plan_update_equipment)
 * @session S-AI-WRITE-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

if (!can('ai', 'view')) {
    json_error('FORBIDDEN', 'Forbidden', 403);
}

$userId = current_user_id();
$body   = json_body();

$proposalId = clean_int($body['proposal_id'] ?? null);
$action     = strtolower(trim((string) ($body['action'] ?? 'apply')));

if (!$proposalId) {
    json_validation_error(['proposal_id' => 'A proposal id is required.']);
}
if (!in_array($action, ['apply', 'undo', 'cancel'], true)) {
    json_validation_error(['action' => 'Action must be "apply", "undo", or "cancel".']);
}

// ── Load the proposal (must belong to this user) ───────────────────────────
$proposal = db_row(
    "SELECT * FROM ai_pending_changes WHERE id = ? AND user_id = ?",
    [$proposalId, $userId]
);
if (!$proposal) {
    json_error('NOT_FOUND', 'That change proposal was not found.', 404);
}

$payload = json_decode($proposal['payload'], true) ?: [];
$targets = $payload['targets'] ?? [];

$userName = current_user()['name'] ?? 'system';
$ip       = $_SERVER['REMOTE_ADDR'] ?? null;

// ── CANCEL — dismiss a still-pending proposal so it can never be applied ──────
// No entity write (just flips the proposal's own status), so ownership — already
// enforced by the user-scoped load above — plus ai:view is sufficient; the
// per-entity edit permission is NOT required to cancel. Atomic claim mirrors the
// apply/undo paths. Handles both action-kind and field-edit proposals.
if ($action === 'cancel') {
    $cancelled = db_update(
        'ai_pending_changes',
        ['status' => 'cancelled'],
        'id = ? AND status = ?',
        [$proposalId, 'pending']
    );
    if ($cancelled !== 1) {
        json_error('CONFLICT', 'This proposal is no longer pending (status: "' . $proposal['status'] . '").', 409);
    }
    json_success(['id' => $proposalId, 'status' => 'cancelled', 'summary' => $proposal['summary']]);
}

// ════════════════════════════════════════════════════════════════════════════
//  LIFECYCLE ACTIONS (S-AI-ACTION-1) — status transitions, not field edits.
//  Routed to ActionRegistry, which delegates to the same service the canonical
//  endpoint uses. Actions are NOT undoable.
// ════════════════════════════════════════════════════════════════════════════
if (($payload['kind'] ?? '') === 'action') {
    $actionEntry = \FleetForge\AI\ActionRegistry::get($payload['action'] ?? '');
    if ($actionEntry === null) {
        json_error('UNSUPPORTED', 'This action type cannot be applied.', 422);
    }
    if (!\FleetForge\AI\ActionRegistry::canPerform($actionEntry)) {
        json_error('FORBIDDEN', "You do not have permission to {$actionEntry['label']}.", 403);
    }
    if ($action === 'undo') {
        json_error('UNSUPPORTED', 'This action cannot be undone.', 422);
    }
    if ($proposal['status'] !== 'pending') {
        json_error('CONFLICT', 'This action is no longer pending (status: "' . $proposal['status'] . '").', 409);
    }
    if (!empty($proposal['expires_at']) && strtotime($proposal['expires_at']) < time()) {
        db_update('ai_pending_changes', ['status' => 'expired'], 'id = ?', [$proposalId]);
        json_error('EXPIRED', 'This action proposal has expired. Ask the AI again for a fresh one.', 409);
    }

    // Atomically CLAIM the proposal before running the (non-undoable) action:
    // a conditional UPDATE only one concurrent request can win. Without it, two
    // parallel requests both pass the pending check above and double-execute the
    // action (e.g. a send/void posted twice → double-counted balance counters).
    // S-AI-AUDIT-HIGH-FIX.
    $claimed = db_update('ai_pending_changes', [
        'status'     => 'applied',
        'applied_at' => date('Y-m-d H:i:s'),
        'applied_by' => $userId,
    ], 'id = ? AND status = ?', [$proposalId, 'pending']);
    if ($claimed !== 1) {
        json_error('CONFLICT', 'This action is no longer pending (it may have just been applied).', 409);
    }

    try {
        $res = \FleetForge\AI\ActionRegistry::execute(
            (string) $payload['action'], (int) ($payload['entity_id'] ?? 0),
            $payload['params'] ?? [], $userId, $userName, $ip
        );
    } catch (\FleetForge\AI\Actions\ActionException $e) {
        // Release the claim so a business/transient failure doesn't strand the
        // proposal as "applied" when the action never actually ran.
        db_update('ai_pending_changes', ['status' => 'pending', 'applied_at' => null, 'applied_by' => null], 'id = ?', [$proposalId]);
        json_error($e->errorCode, $e->getMessage(), $e->status);
    }

    json_success(['id' => $proposalId, 'status' => 'applied', 'summary' => $proposal['summary'], 'result' => $res]);
}

// ── Route by entity type → real per-resource permission ────────────────────
// Look the entity up in the write registry; reject unknown types loudly so we
// never silently no-op a change_type.
$registryEntry = \FleetForge\AI\WriteRegistry::get($proposal['entity_type']);
if ($registryEntry === null) {
    json_error('UNSUPPORTED', 'This proposal type cannot be applied.', 422);
}
if (!can($registryEntry['permission'], 'edit')) {
    json_error('FORBIDDEN', "You do not have permission to edit {$registryEntry['label']}s.", 403);
}

// ════════════════════════════════════════════════════════════════════════════
//  UNDO — restore the before-values captured at apply time
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'undo') {
    if ($proposal['status'] !== 'applied') {
        json_error('CONFLICT', 'Only an applied change can be undone (this one is "' . $proposal['status'] . '").', 409);
    }
    // Atomically claim the applied→undone transition so a double-click / parallel
    // tab can't revert the same change twice (S-AI-AUDIT-HIGH-FIX).
    $claimed = db_update('ai_pending_changes', ['status' => 'undone'], 'id = ? AND status = ?', [$proposalId, 'applied']);
    if ($claimed !== 1) {
        json_error('CONFLICT', 'Only an applied change can be undone (it may have already been undone).', 409);
    }
    try {
        $reverted = \FleetForge\AI\ChangeApplier::undo($proposal, $userId, $userName, $ip);
    } catch (\RuntimeException $e) {
        // Release the claim back to applied so the undo can be retried.
        db_update('ai_pending_changes', ['status' => 'applied'], 'id = ?', [$proposalId]);
        json_error('CONFLICT', $e->getMessage(), 409);
    }

    json_success([
        'id'            => $proposalId,
        'status'        => 'undone',
        'summary'       => $proposal['summary'],
        'applied_count' => $reverted,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
//  APPLY
// ════════════════════════════════════════════════════════════════════════════
if ($proposal['status'] !== 'pending') {
    json_error('CONFLICT', 'This change is no longer pending (status: "' . $proposal['status'] . '").', 409);
}
if (!empty($proposal['expires_at']) && strtotime($proposal['expires_at']) < time()) {
    db_update('ai_pending_changes', ['status' => 'expired'], 'id = ?', [$proposalId]);
    json_error('EXPIRED', 'This change proposal has expired. Ask the AI again to get a fresh one.', 409);
}
if (!$targets) {
    json_error('CONFLICT', 'This proposal has no changes to apply.', 422);
}

// Atomically CLAIM the proposal so two concurrent requests can't both apply it
// (S-AI-AUDIT-HIGH-FIX). The before-check above is a fast-fail; this conditional
// UPDATE is the real gate — only one request flips pending→applied.
$claimed = db_update('ai_pending_changes', [
    'status'     => 'applied',
    'applied_at' => date('Y-m-d H:i:s'),
    'applied_by' => $userId,
], 'id = ? AND status = ?', [$proposalId, 'pending']);
if ($claimed !== 1) {
    json_error('CONFLICT', 'This change is no longer pending (it may have just been applied).', 409);
}

// Delegate the write to ChangeApplier (mirrors the manual Edit Unit path:
// db_transaction + audit per row, last-write-wins). It re-reads each row at
// apply time for the audit old_values and the undo before-snapshot.
try {
    $result = \FleetForge\AI\ChangeApplier::apply($proposal, $userId, $userName, $ip);
} catch (\PDOException $e) {
    // Release the claim — ChangeApplier is transactional so nothing landed; the
    // proposal returns to pending and can be retried after the cause is fixed.
    db_update('ai_pending_changes', ['status' => 'pending', 'applied_at' => null, 'applied_by' => null], 'id = ?', [$proposalId]);
    // Mirror the unique-key handling of the manual update endpoint.
    if ($e->getCode() === '23000') {
        json_error('CONFLICT', 'The change collided with a unique constraint and was not applied.', 409);
    }
    throw $e;
}

if ($result['count'] === 0) {
    db_update('ai_pending_changes', ['status' => 'pending', 'applied_at' => null, 'applied_by' => null], 'id = ?', [$proposalId]);
    json_error('CONFLICT', 'The target unit(s) no longer exist, so nothing was changed.', 409);
}

// The claim already flipped status→applied; now record the before/after diff so
// the change can be undone.
db_update('ai_pending_changes', [
    'applied_diff' => json_encode($result['diff']),
], 'id = ?', [$proposalId]);

json_success([
    'id'            => $proposalId,
    'status'        => 'applied',
    'summary'       => $proposal['summary'],
    'applied_count' => $result['count'],
]);
