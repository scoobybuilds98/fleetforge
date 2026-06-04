<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/bulk_delete.php
 *
 * Bulk soft-delete rate cards.
 *
 * Business rules:
 *   - Cannot delete a rate card whose is_default = 1. Those IDs are skipped
 *     and reported in the errors array. Remove default status first, then
 *     delete.
 *   - Soft delete: sets deleted_at = NOW(). Records remain in DB for history.
 *   - Audit log: action='delete' written per successfully deleted card.
 *   - Maximum 100 IDs per request.
 *
 * @method  POST
 * @body    JSON: { ids: [int, ...] }
 * @auth    Session required; require_permission('rates','delete')
 * @returns 200 { success:true, data:{ actioned:N, skipped:N, errors:[{id,reason}] } }
 *          422 VALIDATION_ERROR (missing/empty ids, or > 100 ids)
 *
 * Decisions: D5 (soft delete), §7 (audit log)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('rates', 'delete');

// -----------------------------------------------------------------------
// 1. Parse and validate request body
// -----------------------------------------------------------------------
$body = json_body();

$ids = $body['ids'] ?? null;
if (!is_array($ids) || count($ids) === 0) {
    json_validation_error(['ids' => 'ids must be a non-empty array of integers.']);
}

if (count($ids) > 100) {
    json_validation_error(['ids' => 'A maximum of 100 IDs may be submitted per request.']);
}

// Coerce to clean integers; reject anything that does not resolve to a
// positive int so callers get an early, unambiguous error.
$cleanIds = [];
foreach ($ids as $raw) {
    $int = clean_int($raw);
    if (!$int) {
        json_validation_error(['ids' => 'All entries in ids must be valid positive integers.']);
    }
    $cleanIds[] = $int;
}
$cleanIds = array_values(array_unique($cleanIds));

// -----------------------------------------------------------------------
// 2. Process each ID independently
// -----------------------------------------------------------------------
$actioned = 0;
$skipped  = 0;
$errors   = [];

$currentUser = current_user();
$userId      = current_user_id();
$userName    = $currentUser['name'] ?? 'system';
$ipAddress   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

foreach ($cleanIds as $id) {
    try {
        // -- Resolve card (must exist and not already be soft-deleted) ----
        $card = db_row(
            "SELECT id, name, is_default FROM rate_cards WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$card) {
            // Not found or already deleted — count as skipped, not actioned.
            $skipped++;
            $errors[] = ['id' => $id, 'reason' => 'Rate card not found.'];
            continue;
        }

        // -- Block deletion of the default card --------------------------
        if ($card['is_default']) {
            $skipped++;
            $errors[] = ['id' => $id, 'reason' => 'Cannot delete the default rate card.'];
            continue;
        }

        // -- Soft delete + audit log inside a per-row transaction --------
        db_transaction(function() use ($id, $card, $userId, $userName, $ipAddress) {
            db_execute(
                "UPDATE rate_cards SET deleted_at = NOW() WHERE id = ?",
                [$id]
            );

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'delete',
                'module'       => 'rates',
                'entity_type'  => 'rate_card',
                'entity_id'    => $id,
                'entity_label' => $card['name'],
                'ip_address'   => $ipAddress,
            ]);
        });

        $actioned++;

    } catch (\Throwable $e) {
        // Isolate failures so one bad row does not abort the whole batch.
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Unexpected error: ' . $e->getMessage()];
    }
}

// -----------------------------------------------------------------------
// 3. Return aggregated result
// -----------------------------------------------------------------------
json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
