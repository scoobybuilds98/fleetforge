<?php
declare(strict_types=1);

/**
 * FleetForge — Mark Cash Refund Settled API
 *
 * @file        api/v1/leases/mark_refund_settled.php
 * @description Stamps `leases.precharge_refund_settled_at = NOW()` when
 *              the operator confirms a cash refund has been physically
 *              disbursed (cheque issued, EFT sent, etc.). Companion
 *              endpoint to api/v1/leases/close.php — the D-B (i)
 *              "manual deferred-settle" pattern locked in
 *              S-MILEAGE-3-SPEC-LOCK splits the cash-refund event into
 *              two steps: (1) close transaction stamps method='cash'
 *              with settled_at=NULL (intent recorded); (2) this
 *              endpoint stamps settled_at=NOW() when money moves.
 *
 *              Validation:
 *              - Lease must exist + not soft-deleted
 *              - Lease status must be 'completed'
 *              - precharge_refund_method must be 'cash'
 *              - precharge_refund_settled_at must be NULL (idempotent
 *                via 409 PRECHARGE_REFUND_ALREADY_SETTLED on retry)
 *
 *              Concurrency (D20): FOR UPDATE lock on the lease row.
 *              audit_log entity_type='lease_precharge_refund_settled'
 *              per D-L (D102 workaround pattern — descriptive
 *              entity_type, action='update' for ENUM compat).
 *
 *              Permission: requires leases.edit (existing close
 *              permission). Manager-role gate NOT enforced — the
 *              operator who closes can settle. Operationally the
 *              "mark settled" action is downstream of close and
 *              carries the same operational risk profile.
 *
 * @method      POST
 * @body        JSON — id (required, the lease_id to settle)
 * @auth        Session required; require_permission('leases','edit')
 * @returns     200 { id, precharge_refund_method, precharge_refund_settled_at }
 *              | 409 PRECHARGE_REFUND_ALREADY_SETTLED
 *              | 422 PRECHARGE_REFUND_METHOD_MISMATCH | 422 INVALID_LEASE_STATUS
 *              | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_CURRENT_SESSIONS.md S-MILEAGE-3 spec block D-B (i)
 *              + D-E (ii) + D-K + D-L (locked via S-MILEAGE-3-SPEC-WRITE +
 *              S-MILEAGE-3-SPEC-LOCK).
 * @decisions   D20 (FOR UPDATE), D-B (i) deferred-settle, D-E (ii)
 *              settled_at means money moved, D-K mark settled button,
 *              D-L audit_log entity_type='lease_precharge_refund_settled'.
 * @session     S-MILEAGE-3
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$result = null;

db_transaction(function () use ($id, &$result) {
    // ── Fetch lease + FOR UPDATE lock ──────────────────────────
    // D20 FOR UPDATE on the lease row prevents racing settle attempts
    // (two operators both clicking "Mark Refund Settled" at the same
    // moment — second commit fails the WHERE-clause guard below).
    $lease = db_row(
        "SELECT id, status, contract_number,
                precharge_enabled, precharge_balance,
                precharge_refund_method, precharge_refund_settled_at
           FROM leases
          WHERE id = ? AND deleted_at IS NULL
          FOR UPDATE",
        [$id]
    );

    if (!$lease) {
        json_error('NOT_FOUND', 'Lease not found.', 404);
    }

    // ── State machine validation ───────────────────────────────
    // Settling a refund only makes sense on a closed lease.
    if ($lease['status'] !== 'completed') {
        json_error('INVALID_LEASE_STATUS',
            "Cannot mark refund settled on lease {$lease['contract_number']}: status is '{$lease['status']}'. Only closed (completed) leases support settle-stamping.",
            422);
    }

    // ── Method mismatch — only cash refunds use deferred-settle ──
    // Credit refunds stamp settled_at at close-commit (D-E (ii) /
    // credit_note creation IS settlement). If a credit-method lease
    // hits this endpoint, the request shape is wrong.
    if ($lease['precharge_refund_method'] !== 'cash') {
        $methodLabel = $lease['precharge_refund_method'] ?? 'none';
        json_error('PRECHARGE_REFUND_METHOD_MISMATCH',
            "Mark settled only applies to cash refunds. Lease {$lease['contract_number']} has refund method '{$methodLabel}'.",
            422);
    }

    // ── Idempotency: 409 if already settled ────────────────────
    if ($lease['precharge_refund_settled_at'] !== null) {
        $alreadySettledAt = $lease['precharge_refund_settled_at'];
        json_error('PRECHARGE_REFUND_ALREADY_SETTLED',
            "Cash refund for lease {$lease['contract_number']} already marked as settled on {$alreadySettledAt}.",
            409);
    }

    // ── Stamp settled_at = NOW() ──────────────────────────────
    $now       = date('Y-m-d H:i:s');
    $user      = current_user();
    $changedBy = $user['name'] ?? 'system';

    db_update('leases', [
        'precharge_refund_settled_at' => $now,
        'updated_by'                  => current_user_id(),
    ], 'id = ?', [$id]);

    // ── audit_log: lease_precharge_refund_settled ─────────────
    // D-L D102 workaround pattern — descriptive entity_type since
    // audit_log.action ENUM doesn't include refund-specific values.
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => $changedBy,
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease_precharge_refund_settled',
        'entity_id'    => $id,
        'entity_label' => $lease['contract_number'],
        'notes'        => sprintf(
            'S-MILEAGE-3 D-B (i): cash refund marked settled. lease=%s, balance=%s, settled_at=%s, settled_by=%s.',
            $lease['contract_number'],
            (string) $lease['precharge_balance'],
            $now,
            $changedBy
        ),
        'old_values'   => json_encode([
            'precharge_refund_settled_at' => null,
        ]),
        'new_values'   => json_encode([
            'method'             => 'cash',
            'settled_at'         => $now,
            'settled_by_user_id' => current_user_id(),
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'id'                          => $id,
        'precharge_refund_method'     => 'cash',
        'precharge_refund_settled_at' => $now,
    ];
});

json_success($result);
