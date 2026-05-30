<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/drift_resolve.php
 *
 * Per-event drift resolution endpoint. Five action verbs:
 *
 *   • resolve   — operator marked drift fixed (default; legacy S-QBO-4 contract
 *                 — body {id, resolution_note} continues to work). Sets
 *                 resolved_at + resolution_type='resolved' + resolution_note.
 *   • accept    — operator accepts the divergence (intentional, e.g. FF
 *                 customer not in QBO for tax reasons). Sets resolved_at +
 *                 resolution_type='accepted' + resolution_note. TERMINAL —
 *                 DriftChecker::recordDrift will never re-flag this event.
 *   • suppress  — false-positive suppression (e.g. known sub-cent rounding).
 *                 Sets resolved_at + resolution_type='suppressed' +
 *                 resolution_note. TERMINAL + hidden from default drift view.
 *   • reopen    — operator-undo for accept/suppress/resolved. Clears
 *                 resolved_at + resolution_type + resolution_note so the row
 *                 re-enters the OPEN pool; next parity check refreshes it if
 *                 still drifted, or auto-resolves it if drift is gone
 *                 (D-QBO-25-2).
 *   • resync    — re-enqueue the FF entity via its Enqueuer (the §15.4
 *                 "Resolve via FF re-sync" button). Does NOT modify the drift
 *                 row — the event auto-resolves on the next parity check if
 *                 the push succeeds. Mirrors the per-entity retry endpoints'
 *                 reason-ladder contract.
 *
 * Permission model:
 *   • require_permission('quickbooks','view') for the endpoint.
 *   • require_permission('quickbooks','edit_credentials') for any action
 *     other than 'resolve'. resolve stays view-gated to preserve the
 *     S-QBO-4 locked decision ("resolve is an accountant-level annotation,
 *     NOT an extended action"). accept/suppress/reopen are policy decisions
 *     and resync is operational — both warrant the elevated gate that the
 *     per-entity retry endpoints already use.
 *
 * Optimistic-lock contract (resolve/accept/suppress):
 *   UPDATE … WHERE resolved_at IS NULL. If affected_rows is 0, returns 409
 *   CONFLICT — prevents two operators from both clicking and the second one
 *   overwriting the first's resolution_type silently.
 *
 * Optimistic-lock contract (reopen):
 *   UPDATE … WHERE resolved_at IS NOT NULL. If affected_rows is 0, returns
 *   409 CONFLICT (the row was reopened concurrently or was already open).
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'view') +
 *          require_permission('quickbooks', 'edit_credentials') for non-resolve.
 * @body    JSON: { id: int, action?: string=resolve, resolution_note?: string }
 *          For resolve/accept/suppress/reopen, resolution_note required (5+
 *          chars). For resync, resolution_note optional (logged if present).
 * @returns 200 { action: string, ... } — shape per action verb
 *        | 400 BAD_REQUEST (unknown action)
 *        | 403 FORBIDDEN (action requires edit_credentials)
 *        | 404 NOT_FOUND
 *        | 409 CONFLICT (state precondition failed)
 *        | 422 VALIDATION_ERROR
 *
 * Spec ref: §15.4 (drift resolution workflows)
 * Session:  S-QBO-4 (initial resolve), S-QBO-25 (accept/suppress/reopen/resync)
 * Decision: D-QBO-25-1 (resolution_type state machine),
 *           D-QBO-25-4 (reopen action for operator-undo)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

$body   = json_body();
$id     = isset($body['id']) ? (int) $body['id'] : 0;
$action = isset($body['action']) ? strtolower(trim((string) $body['action'])) : 'resolve';
$note   = isset($body['resolution_note']) ? trim((string) $body['resolution_note']) : '';

$allowedActions = ['resolve', 'accept', 'suppress', 'reopen', 'resync'];
if (!in_array($action, $allowedActions, true)) {
    json_error('BAD_REQUEST', "Unknown action '{$action}'. Allowed: " . implode(', ', $allowedActions), 400);
}

if ($id <= 0) {
    json_validation_error(['id' => 'Provide id (positive integer).']);
}

// Non-resolve actions are operational (write to QBO via queue) or policy
// (terminal accept/suppress, reopen) — both warrant the per-entity-retry
// elevated permission gate. Preserves S-QBO-4's view-gated 'resolve'.
if ($action !== 'resolve' && !can('quickbooks', 'edit_credentials')) {
    json_error('FORBIDDEN', "Action '{$action}' requires the 'edit_credentials' permission on quickbooks.", 403);
}

// resync doesn't require a note (the act of re-enqueueing is the audit
// trail; reason ladder explains a 'skipped' outcome). Everything else
// requires a substantive note.
if ($action !== 'resync') {
    if (strlen($note) < 5) {
        json_validation_error(['resolution_note' => 'Resolution note required (min 5 chars).']);
    }
    if (strlen($note) > 500) {
        $note = substr($note, 0, 497) . '...';
    }
} elseif ($note !== '' && strlen($note) > 500) {
    $note = substr($note, 0, 497) . '...';
}

// Map FF entity_type → Enqueuer FQCN for the resync action. Mirrors
// DriftChecker::ENTITY_CHECKS keys; all 8 Enqueuers share an identical
// `public static function enqueue(int $ffEntityId, string $operation): bool`
// contract (returns true on queue insert, false on silent gate refusal).
$enqueuerMap = [
    'invoice'       => '\\FleetForge\\QboPushers\\InvoiceEnqueuer',
    'payment'       => '\\FleetForge\\QboPushers\\PaymentEnqueuer',
    'bill'          => '\\FleetForge\\QboPushers\\BillEnqueuer',
    'bill_payment'  => '\\FleetForge\\QboPushers\\BillPaymentEnqueuer',
    'credit_memo'   => '\\FleetForge\\QboPushers\\CreditMemoEnqueuer',
    'journal_entry' => '\\FleetForge\\QboPushers\\JournalEntryEnqueuer',
    'customer'      => '\\FleetForge\\QboPushers\\CustomerEnqueuer',
    'vendor'        => '\\FleetForge\\QboPushers\\VendorEnqueuer',
];

try {
    $user   = current_user();
    $userId = $user['id'] ?? null;
    if (!$userId) {
        json_error('FORBIDDEN', 'No authenticated user.', 403);
    }

    // Existence check first — gives a precise 404 if id doesn't exist
    // (vs the more ambiguous 0-affected-rows result that could be either
    // 404 or 409). Also pulls the row state we need for action gating.
    $row = db_row(
        "SELECT id, entity_type, entity_id, qbo_entity_id, category,
                detection_source, resolved_at, resolution_type
           FROM acc_qbo_drift_events
          WHERE id = ?",
        [$id]
    );
    if (!$row) {
        json_error('NOT_FOUND', "Drift event #{$id} not found.", 404);
    }

    // ── Dispatch per action ─────────────────────────────────────────
    if ($action === 'resync') {
        // Resync: re-enqueue the FF entity via its Enqueuer. Does NOT
        // touch the drift event — it auto-resolves on the next parity
        // check if the push succeeds (D-QBO-25-2).
        if ((int) $row['entity_id'] <= 0) {
            json_error(
                'INVALID_STATE',
                "Cannot resync drift event #{$id} — entity_id is NULL "
                . "(missing_in_ff). Operator must create FF mapping or accept the divergence.",
                422
            );
        }
        $entityType = (string) $row['entity_type'];
        if (!isset($enqueuerMap[$entityType])) {
            json_error(
                'INVALID_STATE',
                "Cannot resync drift event #{$id} — entity_type='{$entityType}' has no Enqueuer mapping. "
                . "Resync supports: " . implode(', ', array_keys($enqueuerMap)),
                422
            );
        }

        // Payment-specific D-QBO-14-1 invariant — webhook-originated
        // payments must NOT be re-pushed (would create duplicate in QBO).
        // Mirrors api/v1/quickbooks/payments/retry.php's guard.
        if ($entityType === 'payment') {
            $payRow = db_row("SELECT origin, push_status FROM payments WHERE id = ?", [(int) $row['entity_id']]);
            if ($payRow && $payRow['origin'] !== 'ff_native') {
                json_error(
                    'INVALID_STATE',
                    "Cannot resync payment #{$row['entity_id']} — origin='{$payRow['origin']}' "
                    . "(webhook-originated; re-push would create duplicate QBO Payment per D-QBO-14-1).",
                    409
                );
            }
        }

        $enqueuerFqcn = $enqueuerMap[$entityType];
        $enqueued = $enqueuerFqcn::enqueue((int) $row['entity_id'], 'create');

        if (!$enqueued) {
            // Gate refused — build a fallthrough reason ladder mirroring
            // the per-entity retry endpoints. Tier 1 sync_enabled →
            // tier 2 sync_mode → tier 3 entity-state (generic fallback).
            $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
            $syncMode    = (string) settings_get("quickbooks.sync_mode.{$entityType}", 'sync');
            $reason = $syncEnabled !== '1'
                ? "sync_enabled='{$syncEnabled}' — flip to '1' to allow QBO writes"
                : (($syncMode === 'qbo_to_ff' || $syncMode === 'disabled')
                    ? "sync_mode.{$entityType}='{$syncMode}' rejects push direction"
                    : "{$entityType} entity not eligible for create push (status / preflight failed)");

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $user['name'] ?? 'system',
                'action'       => 'update',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_drift_resync',
                'entity_id'    => $id,
                'entity_label' => "{$entityType}#{$row['entity_id']} drift={$row['category']}",
                'notes'        => "Drift resync skipped: {$reason}" . ($note !== '' ? " | op note: {$note}" : ''),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            json_success([
                'action' => 'resync_skipped',
                'reason' => $reason,
            ]);
        }

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $user['name'] ?? 'system',
            'action'       => 'update',
            'module'       => 'quickbooks',
            'entity_type'  => 'qbo_drift_resync',
            'entity_id'    => $id,
            'entity_label' => "{$entityType}#{$row['entity_id']} drift={$row['category']}",
            'notes'        => "Re-enqueued via drift resync (will auto-resolve on next parity check)"
                              . ($note !== '' ? " | op note: {$note}" : ''),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        json_success([
            'action'      => 'resync_enqueued',
            'entity_type' => $entityType,
            'entity_id'   => (int) $row['entity_id'],
        ]);
    }

    if ($action === 'reopen') {
        // Reopen: clear resolved_at + resolution_type. Must be currently
        // resolved (resolved_at IS NOT NULL) to reopen.
        if ($row['resolved_at'] === null) {
            json_error('CONFLICT', 'Drift event is already open.', 409);
        }
        $affected = (int) db_execute(
            "UPDATE acc_qbo_drift_events
                SET resolved_at         = NULL,
                    resolution_type     = NULL,
                    resolution_note     = NULL,
                    resolved_by_user_id = NULL
              WHERE id = ?
                AND resolved_at IS NOT NULL",
            [$id]
        );
        if ($affected === 0) {
            json_error('CONFLICT', 'Already reopened by another user.', 409);
        }

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $user['name'] ?? 'system',
            'action'       => 'update',
            'module'       => 'quickbooks',
            'entity_type'  => 'qbo_drift_event',
            'entity_id'    => $id,
            'entity_label' => "{$row['entity_type']}#{$row['entity_id']} {$row['category']}",
            'notes'        => "Drift reopened (was {$row['resolution_type']}): " . substr($note, 0, 400),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        json_success(['action' => 'reopened']);
    }

    // resolve / accept / suppress — all flip OPEN → resolved with a
    // specific resolution_type. Map action → ENUM value.
    $resolutionType = $action === 'resolve' ? 'resolved' : $action;
    if ($row['resolved_at'] !== null) {
        json_error('CONFLICT', "Already resolved (resolution_type='{$row['resolution_type']}') by another user.", 409);
    }

    $affected = (int) db_execute(
        "UPDATE acc_qbo_drift_events
            SET resolved_at         = NOW(),
                resolution_type     = ?,
                resolved_by_user_id = ?,
                resolution_note     = ?
          WHERE id = ?
            AND resolved_at IS NULL",
        [$resolutionType, $userId, $note, $id]
    );
    if ($affected === 0) {
        // Race — somebody else resolved between our SELECT + UPDATE.
        json_error('CONFLICT', 'Already resolved by another user.', 409);
    }

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => $user['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_drift_event',
        'entity_id'    => $id,
        'entity_label' => "{$row['entity_type']}#{$row['entity_id']} {$row['category']}",
        'notes'        => "Drift {$resolutionType}: " . substr($note, 0, 400),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'action'          => $resolutionType,
        'resolution_type' => $resolutionType,
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Drift action failed: ' . $e->getMessage(), 500);
}
