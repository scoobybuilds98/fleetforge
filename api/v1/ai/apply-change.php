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
if (!in_array($action, ['apply', 'undo'], true)) {
    json_validation_error(['action' => 'Action must be "apply" or "undo".']);
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

// ── Route by entity type → real per-resource permission ────────────────────
// The slice only knows equipment_unit; reject anything else loudly so we never
// silently no-op a future change_type.
if ($proposal['entity_type'] !== 'equipment_unit') {
    json_error('UNSUPPORTED', 'This proposal type cannot be applied yet.', 422);
}
if (!can('equipment', 'edit')) {
    json_error('FORBIDDEN', 'You do not have permission to edit equipment.', 403);
}

$userName = current_user()['name'] ?? 'system';
$ip       = $_SERVER['REMOTE_ADDR'] ?? null;

// ════════════════════════════════════════════════════════════════════════════
//  UNDO — restore the before-values captured at apply time
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'undo') {
    if ($proposal['status'] !== 'applied') {
        json_error('CONFLICT', 'Only an applied change can be undone (this one is "' . $proposal['status'] . '").', 409);
    }
    try {
        $reverted = \FleetForge\AI\ChangeApplier::undo($proposal, $userId, $userName, $ip);
    } catch (\RuntimeException $e) {
        json_error('CONFLICT', $e->getMessage(), 409);
    }

    db_update('ai_pending_changes', ['status' => 'undone'], 'id = ?', [$proposalId]);

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

// Delegate the write to ChangeApplier (mirrors the manual Edit Unit path:
// db_transaction + audit per row, last-write-wins). It re-reads each row at
// apply time for the audit old_values and the undo before-snapshot.
try {
    $result = \FleetForge\AI\ChangeApplier::apply($proposal, $userId, $userName, $ip);
} catch (\PDOException $e) {
    // Mirror the unique-key handling of the manual update endpoint.
    if ($e->getCode() === '23000') {
        json_error('CONFLICT', 'The change collided with a unique constraint and was not applied.', 409);
    }
    throw $e;
}

if ($result['count'] === 0) {
    json_error('CONFLICT', 'The target unit(s) no longer exist, so nothing was changed.', 409);
}

db_update('ai_pending_changes', [
    'status'       => 'applied',
    'applied_diff' => json_encode($result['diff']),
    'applied_at'   => date('Y-m-d H:i:s'),
    'applied_by'   => $userId,
], 'id = ?', [$proposalId]);

json_success([
    'id'            => $proposalId,
    'status'        => 'applied',
    'summary'       => $proposal['summary'],
    'applied_count' => $result['count'],
]);
