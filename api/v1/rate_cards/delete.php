<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/delete.php
 *
 * Soft-delete a rate card.
 *
 * Business rules:
 *   - Cannot delete the default rate card (is_default=1). Remove default
 *     status first, then delete.
 *   - Soft delete: sets deleted_at = NOW(). Items remain in DB for history
 *     (they are only removed if the card is hard-deleted, which we never do).
 *   - Audit log: action='delete'.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('rates','delete')
 * @returns 200 { id, deleted: true }
 *          404 NOT_FOUND | 422 VALIDATION_ERROR
 *
 * Decisions: D5 (soft delete), §7 (audit log)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('rates', 'delete');

// -----------------------------------------------------------------------
// 1. Parse body + resolve card
// -----------------------------------------------------------------------
$body   = json_body();
$fields = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Rate card ID is required.';
    json_validation_error($fields);
}

$card = db_row(
    "SELECT id, name, is_default FROM rate_cards WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$card) {
    json_error('NOT_FOUND', 'Rate card not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Block deletion of the default card
// -----------------------------------------------------------------------
if ($card['is_default']) {
    json_validation_error([
        'is_default' => 'Cannot delete the default rate card. Remove its default status first.',
    ], 'Cannot delete the default rate card. Remove its default status first.');
}

// -----------------------------------------------------------------------
// 3. Soft delete inside transaction + audit log
// -----------------------------------------------------------------------
db_transaction(function() use ($id, $card) {
    db_execute(
        "UPDATE rate_cards SET deleted_at = NOW() WHERE id = ?",
        [$id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'rates',
        'entity_type'  => 'rate_card',
        'entity_id'    => $id,
        'entity_label' => $card['name'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
