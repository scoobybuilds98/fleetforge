<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/customers/save_mapping.php
 *
 * Operator-driven manual mapping mutations from the Customers Sync
 * page. Four actions:
 *
 *   action='link'     — link an FF customer to a QBO customer.
 *                       Sets ff_customer_id + qbo_customer_id on the
 *                       same row, mapping_status='mapped',
 *                       match_confidence='manual'. If either FF or
 *                       QBO already has a row, the other side's row
 *                       is DELETED first to avoid UNIQUE collisions,
 *                       then the surviving row is updated to carry
 *                       both sides.
 *
 *   action='unlink'   — break a 'mapped' row into separate ff_only
 *                       and qbo_only halves. Operator might do this
 *                       when an erroneous match was confirmed earlier.
 *
 *   action='ignore'   — mark mapping_status='ignored'. Preserves all
 *                       other fields so the row stays in the table
 *                       for audit ("we intentionally didn't map this
 *                       QBO customer").
 *
 *   action='unignore' — flip ignored row back to its natural state
 *                       based on populated sides (mapped/ff_only/qbo_only).
 *
 * audit_log: every mutation writes one row with action='update',
 * module='quickbooks', entity_type='qbo_customer_map'.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — writes to mapping
 *          table only; no QBO HTTP, no FF customer changes.
 * @body    JSON: { action, ff_customer_id?, qbo_customer_id?, mapping_id?, notes? }
 * @returns 200 { success: true, mapping_id: int, status: string }
 *
 * Spec ref: §7.4
 * Session:  S-QBO-5
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

$body      = json_body();
$action    = (string) ($body['action'] ?? '');
$ffCustId  = isset($body['ff_customer_id']) && $body['ff_customer_id'] !== null && $body['ff_customer_id'] !== ''
             ? (int) $body['ff_customer_id']
             : null;
$qboCustId = isset($body['qbo_customer_id']) && $body['qbo_customer_id'] !== '' && $body['qbo_customer_id'] !== null
             ? (string) $body['qbo_customer_id']
             : null;
$mappingId = isset($body['mapping_id']) && (int) $body['mapping_id'] > 0
             ? (int) $body['mapping_id']
             : null;
$notes     = isset($body['notes']) ? (string) $body['notes'] : null;

if (!in_array($action, ['link', 'unlink', 'ignore', 'unignore', 'create_new'], true)) {
    json_error('VALIDATION_ERROR', 'action must be one of: link, unlink, ignore, unignore, create_new', 422);
}

// Per-action input validation up front — easier to reason about than
// validating mid-transaction (json_error exits, which would silently
// rollback an open transaction).
switch ($action) {
    case 'link':
        if ($ffCustId === null || $qboCustId === null) {
            json_error('VALIDATION_ERROR', 'link requires both ff_customer_id and qbo_customer_id', 422);
        }
        break;
    case 'unlink':
        if ($mappingId === null && $ffCustId === null && $qboCustId === null) {
            json_error('VALIDATION_ERROR', 'unlink requires mapping_id, ff_customer_id, or qbo_customer_id', 422);
        }
        break;
    case 'create_new':
        if ($ffCustId === null) {
            json_error('VALIDATION_ERROR', 'create_new requires ff_customer_id', 422);
        }
        break;
    case 'ignore':
    case 'unignore':
        if ($mappingId === null) {
            json_error('VALIDATION_ERROR', $action . ' requires mapping_id', 422);
        }
        break;
}

$userId = current_user_id();
$now    = ff_now_utc(); // S-UTC-STAMPS: QBO map stamps (last_synced_at/pushed_at/…) are UTC

try {
    $result = db_transaction(function () use ($action, $ffCustId, $qboCustId, $mappingId, $notes, $userId, $now): array {

        if ($action === 'link') {
            // Find the qbo_only row created by a prior pull. It carries
            // the QBO snapshot (display_name / email / phone / balance)
            // that powers the Customers Sync table's QBO Customer column.
            // We MUST find it BEFORE deleting anything — earlier versions
            // deleted both single-sided rows then looked up qbo_customer_id
            // (which by then was gone), so the lookup always returned null,
            // the code fell into the INSERT branch, and the new mapped row
            // shipped with NO snapshot fields → UI rendered "(no name)".
            $qboOnly = db_row(
                "SELECT id FROM acc_qbo_customer_map
                  WHERE qbo_customer_id = ? AND ff_customer_id IS NULL",
                [$qboCustId]
            );

            // Avoid UNIQUE(ff_customer_id) collision: drop any pre-existing
            // ff_only row for this FF customer. (No snapshot to preserve
            // on the ff_only side — it has no QBO fields populated.)
            db_execute(
                "DELETE FROM acc_qbo_customer_map
                  WHERE ff_customer_id = ? AND qbo_customer_id IS NULL",
                [$ffCustId]
            );

            if ($qboOnly !== null) {
                // Promote the qbo_only row by attaching ff_customer_id.
                // Snapshot fields (qbo_display_name / qbo_email / qbo_phone /
                // qbo_active / qbo_balance / qbo_sync_token / qbo_company_name /
                // last_pull_at) survive the UPDATE since we don't touch them.
                $id = (int) $qboOnly['id'];
                db_execute(
                    "UPDATE acc_qbo_customer_map SET
                        ff_customer_id   = ?,
                        mapping_status   = 'mapped',
                        match_confidence = 'manual',
                        match_notes      = ?,
                        last_synced_at   = ?
                      WHERE id = ?",
                    [$ffCustId, $notes, $now, $id]
                );
            } else {
                // No prior qbo_only row for this QBO customer (operator
                // is linking to a QBO id that wasn't pulled — uncommon,
                // since the link modal's dropdown is populated from
                // qbo_only rows, but the API still accepts arbitrary
                // qbo_customer_id strings). Insert fresh; snapshot will
                // populate on the next Pull from QuickBooks. UI shows
                // "(no name)" until then.
                $id = db_insert('acc_qbo_customer_map', [
                    'ff_customer_id'     => $ffCustId,
                    'qbo_customer_id'    => $qboCustId,
                    'mapping_status'     => 'mapped',
                    'match_confidence'   => 'manual',
                    'match_notes'        => $notes,
                    'last_synced_at'     => $now,
                    'created_by_user_id' => $userId,
                ]);
            }

            return ['id' => $id, 'status' => 'mapped'];
        }

        if ($action === 'create_new') {
            // S-QBO-GOLIVE-AUDIT: a person confirms QuickBooks has NO such
            // customer. Recorded as a manual ff_only decision — it releases the
            // pre-go-live create guard (CustomerPusher) and auto-match keeps it.
            $row = db_row("SELECT * FROM acc_qbo_customer_map WHERE ff_customer_id = ?", [$ffCustId]);
            if ($row !== null && $row['qbo_customer_id'] !== null) {
                throw new \RuntimeException('CONFLICT: This customer is already linked to QuickBooks customer #' . $row['qbo_customer_id'] . '.');
            }
            $decision = 'Operator: not in QuickBooks — create it' . ($notes !== null && $notes !== '' ? " ({$notes})" : '');
            if ($row !== null) {
                db_execute(
                    "UPDATE acc_qbo_customer_map
                        SET mapping_status = 'ff_only', match_confidence = 'manual', match_notes = ?, last_synced_at = ?
                      WHERE id = ?",
                    [$decision, $now, (int) $row['id']]
                );
                $id = (int) $row['id'];
            } else {
                $id = db_insert('acc_qbo_customer_map', [
                    'ff_customer_id'     => $ffCustId,
                    'mapping_status'     => 'ff_only',
                    'match_confidence'   => 'manual',
                    'match_notes'        => $decision,
                    'created_by_user_id' => $userId,
                ]);
            }
            return ['id' => $id, 'status' => 'ff_only'];
        }

        if ($action === 'unlink') {
            // Find the row. Prefer mapping_id when supplied; else
            // resolve via ff or qbo identifier.
            if ($mappingId !== null) {
                $row = db_row("SELECT * FROM acc_qbo_customer_map WHERE id = ?", [$mappingId]);
            } elseif ($ffCustId !== null) {
                $row = db_row(
                    "SELECT * FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
                    [$ffCustId]
                );
            } else {
                $row = db_row(
                    "SELECT * FROM acc_qbo_customer_map WHERE qbo_customer_id = ?",
                    [(string) $qboCustId]
                );
            }
            if ($row === null) {
                // Throwing rolls back the transaction cleanly.
                throw new \RuntimeException('NOT_FOUND: Mapping not found');
            }

            $id     = (int) $row['id'];
            $hadFf  = $row['ff_customer_id'] !== null;
            $hadQbo = $row['qbo_customer_id'] !== null;

            if ($hadFf && $hadQbo) {
                // Demote existing row to qbo_only side (preserves QBO
                // snapshot fields). Re-create the FF side as a fresh
                // ff_only row so the operator can re-link separately.
                db_execute(
                    "UPDATE acc_qbo_customer_map SET
                        ff_customer_id   = NULL,
                        mapping_status   = 'qbo_only',
                        match_confidence = NULL,
                        match_notes      = ?
                      WHERE id = ?",
                    [$notes, $id]
                );
                db_insert('acc_qbo_customer_map', [
                    'ff_customer_id'     => (int) $row['ff_customer_id'],
                    'mapping_status'     => 'ff_only',
                    'created_by_user_id' => $userId,
                ]);
                return ['id' => $id, 'status' => 'qbo_only'];
            }

            // Single-sided unlink — row carried no information beyond
            // the link, so delete it entirely.
            db_execute("DELETE FROM acc_qbo_customer_map WHERE id = ?", [$id]);
            return ['id' => $id, 'status' => 'deleted'];
        }

        if ($action === 'ignore') {
            $exists = db_row("SELECT id FROM acc_qbo_customer_map WHERE id = ?", [$mappingId]);
            if ($exists === null) {
                throw new \RuntimeException('NOT_FOUND: Mapping not found');
            }
            db_execute(
                "UPDATE acc_qbo_customer_map SET
                    mapping_status = 'ignored',
                    match_notes    = ?
                  WHERE id = ?",
                [$notes, $mappingId]
            );
            return ['id' => (int) $mappingId, 'status' => 'ignored'];
        }

        // action === 'unignore'
        $row = db_row("SELECT * FROM acc_qbo_customer_map WHERE id = ?", [$mappingId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found');
        }
        $hadFf  = $row['ff_customer_id']  !== null;
        $hadQbo = $row['qbo_customer_id'] !== null;
        // Restore the natural state. The match expression keeps the
        // default-qbo_only branch matching the schema default for any
        // pathological all-null row (shouldn't happen but defensive).
        $newStatus = match (true) {
            $hadFf && $hadQbo => 'mapped',
            $hadFf            => 'ff_only',
            default           => 'qbo_only',
        };
        db_execute(
            "UPDATE acc_qbo_customer_map SET mapping_status = ? WHERE id = ?",
            [$newStatus, $mappingId]
        );
        return ['id' => (int) $mappingId, 'status' => $newStatus];
    });

    // Audit. action='update' per K-22 Trap #57 (ENUM has no 'edit').
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_customer_map',
        'entity_id'    => $result['id'],
        'entity_label' => 'Mapping #' . $result['id'],
        'notes'        => "Action={$action}" . ($notes !== null && $notes !== '' ? "; notes={$notes}" : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    // create_new: queue the create now (post-commit, best-effort like every
    // enqueuer). False while sync is off — it pushes on the next edit then.
    $enqueued = $action === 'create_new'
        ? \FleetForge\QboPushers\CustomerEnqueuer::enqueue((int) $ffCustId, 'create')
        : null;

    json_success([
        'mapping_id' => $result['id'],
        'status'     => $result['status'],
        'enqueued'   => $enqueued,
    ]);

} catch (\RuntimeException $e) {
    // We use RuntimeException with a "CODE: message" prefix as the
    // typed-failure carrier inside the transaction body (json_error
    // can't be called there without leaking the open transaction).
    $message = $e->getMessage();
    if (str_starts_with($message, 'NOT_FOUND:')) {
        json_error('NOT_FOUND', trim(substr($message, 10)), 404);
    }
    if (str_starts_with($message, 'CONFLICT:')) {
        json_error('CONFLICT', trim(substr($message, 9)), 409);
    }
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $message, 500);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $e->getMessage(), 500);
}
