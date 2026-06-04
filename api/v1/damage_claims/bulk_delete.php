<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/bulk_delete.php
 *
 * Soft-delete multiple damage claims in a single request.
 *
 * Business rules:
 *   - Only 'reported' and 'assessed' claims may be deleted — once a claim
 *     has progressed to repair_ordered or beyond it affects linked records
 *     (work orders, invoices) and must be written_off via status transition,
 *     not deleted.
 *   - Sets deleted_at = NOW(). Photos are NOT deleted (audit trail).
 *   - Each ID is processed in its own transaction; a failure on one does not
 *     roll back others.
 *   - Audit log: action='delete' written per successfully deleted claim.
 *   - Maximum 100 IDs per request.
 *
 * @method  POST
 * @body    JSON: { ids: [int, ...] }
 * @auth    Session required; require_permission('maintenance','delete')
 * @returns 200 { success:true, data:{ actioned:N, skipped:N, errors:[{id,reason}] } }
 *
 * Decisions: D5 (soft delete)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'delete');

$body = json_body();

// ── Validate top-level input ──────────────────────────────────────────────────

$ids = $body['ids'] ?? null;

if (!is_array($ids) || count($ids) === 0) {
    json_validation_error(['ids' => 'ids must be a non-empty array of integers.']);
}

if (count($ids) > 100) {
    json_validation_error(['ids' => 'A maximum of 100 IDs may be submitted per request.']);
}

// Sanitise every element to a positive integer; reject the batch if any are malformed.
$clean_ids = [];
foreach ($ids as $raw) {
    $int = clean_int($raw);
    if (!$int) {
        json_validation_error(['ids' => 'All values in ids must be positive integers.']);
    }
    $clean_ids[] = $int;
}

// ── Per-ID processing ─────────────────────────────────────────────────────────

$actioned = 0;
$skipped  = 0;
$errors   = [];

// Cache caller context outside the loop — avoids repeated session lookups.
$current_user    = current_user();
$current_user_id = current_user_id();
$ip              = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Only these statuses are safe to hard-delete; anything further along is
// entangled with work orders / invoices and must go through written_off instead.
$deletable_statuses = ['reported', 'assessed'];

foreach ($clean_ids as $id) {
    try {
        $claim = db_row(
            "SELECT id, claim_number, status FROM damage_claims
             WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$claim) {
            $skipped++;
            $errors[] = ['id' => $id, 'reason' => 'Damage claim not found.'];
            continue;
        }

        // Block deletion on any status that indicates downstream linkages exist.
        if (!in_array($claim['status'], $deletable_statuses, true)) {
            $skipped++;
            $errors[] = [
                'id'     => $id,
                'reason' => "Cannot delete a claim with status '{$claim['status']}'. Only reported or assessed claims may be deleted.",
            ];
            continue;
        }

        db_transaction(function () use ($id, $claim, $current_user_id, $current_user, $ip) {
            db_execute(
                "UPDATE damage_claims SET deleted_at = NOW() WHERE id = ?",
                [$id]
            );

            db_insert('audit_log', [
                'user_id'      => $current_user_id,
                'user_name'    => $current_user['name'] ?? 'system',
                'action'       => 'delete',
                'module'       => 'maintenance',
                'entity_type'  => 'damage_claim',
                'entity_id'    => $id,
                'entity_label' => $claim['claim_number'],
                'old_values'   => json_encode(['status' => $claim['status']]),
                'ip_address'   => $ip,
            ]);
        });

        $actioned++;

    } catch (\Throwable $e) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Unexpected error: ' . $e->getMessage()];
    }
}

// ── Response ──────────────────────────────────────────────────────────────────

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
