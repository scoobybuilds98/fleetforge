<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/vendors/save_mapping.php
 *
 * Operator-driven manual mapping mutations from the Vendors Sync
 * page. Four actions (mirrors customers/save_mapping.php):
 *
 *   action='link'     — link an FF vendor to a QBO vendor.
 *                       Sets ff_vendor_id + qbo_vendor_id on the
 *                       same row, mapping_status='mapped',
 *                       match_confidence='manual'. If either side
 *                       already has a row, the other side's row is
 *                       DELETED first to avoid UNIQUE collisions,
 *                       then the surviving row is updated to carry
 *                       both sides — preserving the QBO snapshot.
 *
 *   action='unlink'   — break a 'mapped' row into separate ff_only
 *                       and qbo_only halves.
 *
 *   action='ignore'   — mark mapping_status='ignored'. Preserves
 *                       all other fields so the row stays in the
 *                       table for audit.
 *
 *   action='unignore' — flip ignored row back to its natural state
 *                       based on populated sides.
 *
 * audit_log: every mutation writes one row with action='update',
 * module='quickbooks', entity_type='qbo_vendor_map'.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — writes only to mapping table
 * @body    JSON: { action, ff_vendor_id?, qbo_vendor_id?, mapping_id?, notes? }
 * @returns 200 { success: true, mapping_id: int, status: string }
 *
 * Spec ref: §7.5
 * Session:  S-QBO-7
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

$body      = json_body();
$action    = (string) ($body['action'] ?? '');
$ffVendId  = isset($body['ff_vendor_id']) && $body['ff_vendor_id'] !== null && $body['ff_vendor_id'] !== ''
             ? (int) $body['ff_vendor_id']
             : null;
$qboVendId = isset($body['qbo_vendor_id']) && $body['qbo_vendor_id'] !== '' && $body['qbo_vendor_id'] !== null
             ? (string) $body['qbo_vendor_id']
             : null;
$mappingId = isset($body['mapping_id']) && (int) $body['mapping_id'] > 0
             ? (int) $body['mapping_id']
             : null;
$notes     = isset($body['notes']) ? (string) $body['notes'] : null;

if (!in_array($action, ['link', 'unlink', 'ignore', 'unignore'], true)) {
    json_error('VALIDATION_ERROR', 'action must be one of: link, unlink, ignore, unignore', 422);
}

// Per-action input validation up front.
switch ($action) {
    case 'link':
        if ($ffVendId === null || $qboVendId === null) {
            json_error('VALIDATION_ERROR', 'link requires both ff_vendor_id and qbo_vendor_id', 422);
        }
        break;
    case 'unlink':
        if ($mappingId === null && $ffVendId === null && $qboVendId === null) {
            json_error('VALIDATION_ERROR', 'unlink requires mapping_id, ff_vendor_id, or qbo_vendor_id', 422);
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
$now    = date('Y-m-d H:i:s');

try {
    $result = db_transaction(function () use ($action, $ffVendId, $qboVendId, $mappingId, $notes, $userId, $now): array {

        if ($action === 'link') {
            // Find the qbo_only row created by a prior pull. It carries
            // the QBO snapshot (display_name / email / phone / etc.)
            // that powers the Vendors Sync table's QBO column. MUST
            // find it BEFORE deleting anything (per S-QBO-5 hotfix
            // discipline — same bug pattern bit customers UI).
            $qboOnly = db_row(
                "SELECT id FROM acc_qbo_vendor_map
                  WHERE qbo_vendor_id = ? AND ff_vendor_id IS NULL",
                [$qboVendId]
            );

            // Avoid UNIQUE(ff_vendor_id) collision: drop any pre-
            // existing ff_only row. (No snapshot to preserve — ff_only
            // rows have no QBO fields populated.)
            db_execute(
                "DELETE FROM acc_qbo_vendor_map
                  WHERE ff_vendor_id = ? AND qbo_vendor_id IS NULL",
                [$ffVendId]
            );

            if ($qboOnly !== null) {
                // Promote the qbo_only row by attaching ff_vendor_id.
                // Snapshot fields survive the UPDATE since we don't
                // touch them — UI keeps showing the QBO-side data.
                $id = (int) $qboOnly['id'];
                db_execute(
                    "UPDATE acc_qbo_vendor_map SET
                        ff_vendor_id     = ?,
                        mapping_status   = 'mapped',
                        match_confidence = 'manual',
                        match_notes      = ?,
                        last_synced_at   = ?
                      WHERE id = ?",
                    [$ffVendId, $notes, $now, $id]
                );
            } else {
                // No prior qbo_only row for this QBO vendor (operator
                // is linking to a QBO id that wasn't pulled — uncommon
                // since the link modal's dropdown is populated from
                // qbo_only rows, but the API accepts arbitrary strings).
                // Snapshot will populate on next Pull. UI shows
                // '(no name)' until then.
                $id = db_insert('acc_qbo_vendor_map', [
                    'ff_vendor_id'       => $ffVendId,
                    'qbo_vendor_id'      => $qboVendId,
                    'mapping_status'     => 'mapped',
                    'match_confidence'   => 'manual',
                    'match_notes'        => $notes,
                    'last_synced_at'     => $now,
                    'created_by_user_id' => $userId,
                ]);
            }

            return ['id' => $id, 'status' => 'mapped'];
        }

        if ($action === 'unlink') {
            // Find the row. Prefer mapping_id when supplied; else
            // resolve via ff or qbo identifier.
            if ($mappingId !== null) {
                $row = db_row("SELECT * FROM acc_qbo_vendor_map WHERE id = ?", [$mappingId]);
            } elseif ($ffVendId !== null) {
                $row = db_row(
                    "SELECT * FROM acc_qbo_vendor_map WHERE ff_vendor_id = ?",
                    [$ffVendId]
                );
            } else {
                $row = db_row(
                    "SELECT * FROM acc_qbo_vendor_map WHERE qbo_vendor_id = ?",
                    [(string) $qboVendId]
                );
            }
            if ($row === null) {
                throw new \RuntimeException('NOT_FOUND: Mapping not found');
            }

            $id     = (int) $row['id'];
            $hadFf  = $row['ff_vendor_id'] !== null;
            $hadQbo = $row['qbo_vendor_id'] !== null;

            if ($hadFf && $hadQbo) {
                // Demote existing row to qbo_only side (preserves QBO
                // snapshot fields). Re-create the FF side as a fresh
                // ff_only row so the operator can re-link separately.
                db_execute(
                    "UPDATE acc_qbo_vendor_map SET
                        ff_vendor_id     = NULL,
                        mapping_status   = 'qbo_only',
                        match_confidence = NULL,
                        match_notes      = ?
                      WHERE id = ?",
                    [$notes, $id]
                );
                db_insert('acc_qbo_vendor_map', [
                    'ff_vendor_id'       => (int) $row['ff_vendor_id'],
                    'mapping_status'     => 'ff_only',
                    'created_by_user_id' => $userId,
                ]);
                return ['id' => $id, 'status' => 'qbo_only'];
            }

            // Single-sided unlink — row carried no information beyond
            // the link, so delete it entirely.
            db_execute("DELETE FROM acc_qbo_vendor_map WHERE id = ?", [$id]);
            return ['id' => $id, 'status' => 'deleted'];
        }

        if ($action === 'ignore') {
            $exists = db_row("SELECT id FROM acc_qbo_vendor_map WHERE id = ?", [$mappingId]);
            if ($exists === null) {
                throw new \RuntimeException('NOT_FOUND: Mapping not found');
            }
            db_execute(
                "UPDATE acc_qbo_vendor_map SET
                    mapping_status = 'ignored',
                    match_notes    = ?
                  WHERE id = ?",
                [$notes, $mappingId]
            );
            return ['id' => (int) $mappingId, 'status' => 'ignored'];
        }

        // action === 'unignore'
        $row = db_row("SELECT * FROM acc_qbo_vendor_map WHERE id = ?", [$mappingId]);
        if ($row === null) {
            throw new \RuntimeException('NOT_FOUND: Mapping not found');
        }
        $hadFf  = $row['ff_vendor_id']  !== null;
        $hadQbo = $row['qbo_vendor_id'] !== null;
        $newStatus = match (true) {
            $hadFf && $hadQbo => 'mapped',
            $hadFf            => 'ff_only',
            default           => 'qbo_only',
        };
        db_execute(
            "UPDATE acc_qbo_vendor_map SET mapping_status = ? WHERE id = ?",
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
        'entity_type'  => 'qbo_vendor_map',
        'entity_id'    => $result['id'],
        'entity_label' => 'Mapping #' . $result['id'],
        'notes'        => "Action={$action}" . ($notes !== null && $notes !== '' ? "; notes={$notes}" : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'mapping_id' => $result['id'],
        'status'     => $result['status'],
    ]);

} catch (\RuntimeException $e) {
    // RuntimeException with "CODE: message" prefix is our typed-failure
    // carrier inside the transaction body (json_error can't run there
    // without leaking the open transaction).
    $message = $e->getMessage();
    if (str_starts_with($message, 'NOT_FOUND:')) {
        json_error('NOT_FOUND', trim(substr($message, 10)), 404);
    }
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $message, 500);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $e->getMessage(), 500);
}
