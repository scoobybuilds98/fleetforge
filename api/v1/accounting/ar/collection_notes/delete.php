<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/collection_notes/delete.php
 *
 * Hard-delete a collection note. acc_collection_notes has no deleted_at
 * column — these are operational records (call logs / contact history),
 * not financial records, so hard delete is acceptable per spec §22.5.
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('journal_entries','delete')
 * @returns 200 { id }
 *          404 if not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'delete');

$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('VALIDATION_ERROR', 'Collection note ID is required.', 422);
}

$existing = db_row("SELECT * FROM acc_collection_notes WHERE id = ?", [$id]);
if (!$existing) {
    json_error('NOT_FOUND', 'Collection note not found.', 404);
}

db_transaction(function () use ($id, $existing) {
    db_execute("DELETE FROM acc_collection_notes WHERE id = ?", [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'delete',
        'module'      => 'accounting',
        'entity_type' => 'collection_note',
        'entity_id'   => $id,
        'old_values'  => json_encode($existing),
        'notes'       => "Collection note #{$id} deleted (hard delete — operational record)",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
