<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/void.php
 *
 * Void an active or partially_used credit note.
 * Voiding sets status → 'void', zeroes amount_remaining, and records voided_by/voided_at.
 *
 * Business rules:
 *   - Only 'active' or 'partially_used' notes can be voided
 *   - 'fully_used', 'expired', and 'void' → 409 INVALID_TRANSITION
 *   - D20: FOR UPDATE prevents concurrent void + apply race
 *   - No counter reversals needed: credit notes do not affect customers.outstanding_balance
 *     until they are *applied* to an invoice. Voiding before application has no AR impact.
 *   - WHY: partially_used notes can be voided — the already-applied portion was consumed
 *     when apply.php ran; only the remaining_amount is being cancelled now.
 *
 * @method  POST
 * @body    JSON: id (required), reason (required)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { id, credit_note_number, status, voided_at }
 *
 * Decisions: D20, §6 State Machines, §7 Audit log
 * Session: S011
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$reason = clean_string($body['reason'] ?? null, 2000);
if (!$reason) {
    json_error('MISSING_REQUIRED', 'reason is required to void a credit note.', 422);
}

// Pre-flight: check exists outside the lock for a fast fail
$cnCheck = db_row(
    "SELECT id, credit_note_number, status FROM credit_notes WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$cnCheck) {
    json_error('NOT_FOUND', 'Credit note not found.', 404);
}

$voidableStatuses = ['active', 'partially_used'];
if (!in_array($cnCheck['status'], $voidableStatuses, true)) {
    json_error(
        'INVALID_TRANSITION',
        "Cannot void a credit note with status '{$cnCheck['status']}'. Only active or partially_used notes can be voided.",
        409
    );
}

$result = null;

db_transaction(function () use ($id, $reason, $cnCheck, &$result) {
    // D20: FOR UPDATE — prevents concurrent apply + void race
    $cn = db_row(
        "SELECT id, credit_note_number, status, amount_remaining FROM credit_notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$id]
    );
    if (!$cn) {
        json_error('NOT_FOUND', 'Credit note not found.', 404);
    }

    // Re-check inside lock (state may have changed between pre-flight and lock)
    if (!in_array($cn['status'], ['active', 'partially_used'], true)) {
        json_error(
            'INVALID_TRANSITION',
            "Cannot void a credit note with status '{$cn['status']}'.",
            409
        );
    }

    $voidedAt = date('Y-m-d H:i:s');

    db_update('credit_notes', [
        'status'          => 'void',
        'amount_remaining' => '0.00',   // WHY: voided note has no remaining usable balance
        'voided_by'       => current_user_id(),
        'voided_at'       => $voidedAt,
        'internal_notes'  => trim(($cn['internal_notes'] ?? '') . "\n---\nVoid reason: {$reason}"),
        'updated_at'      => $voidedAt,
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'status_change',
        'module'       => 'invoices',
        'entity_type'  => 'credit_note',
        'entity_id'    => $id,
        'entity_label' => $cn['credit_note_number'],
        'old_values'   => json_encode(['status' => $cn['status'], 'amount_remaining' => $cn['amount_remaining']]),
        'new_values'   => json_encode(['status' => 'void', 'amount_remaining' => '0.00']),
        'notes'        => "Credit note {$cn['credit_note_number']} voided. Reason: {$reason}",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'id'                 => $id,
        'credit_note_number' => $cn['credit_note_number'],
        'status'             => 'void',
        'voided_at'          => $voidedAt,
    ];
});

// ── QBO sync enqueue (S-QBO-PUSHVOID-TRIO / F7) ─────────────────────────
// Best-effort per §6.9 D-ENQUEUER-CONTRACT — never throws. AFTER the
// db_transaction commits (status now 'void'), BEFORE json_success. The
// Enqueuer's gate-0 requires status='void' for the 'void' op. Propagates
// the void to the mapped QBO CreditMemo (CreditMemoPusher::pushVoid).
if (!empty($id)) {
    \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $id, 'void');
}

json_success($result);
