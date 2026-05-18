<?php
declare(strict_types=1);

/**
 * api/v1/accounting/documents/delete.php
 *
 * Hard-delete an acc_documents row + remove the file from storage. The
 * acc_documents table has no `deleted_at` column (per FLEETFORGE_DATABASE_MASTER.sql:590-607)
 * so this is a permanent delete — different from the soft-delete used by
 * the non-accounting api/v1/documents/delete.php.
 *
 * Order of operations:
 *   1. Load the document row (file_path needed for StorageClient::delete).
 *   2. Attempt StorageClient::delete() — log on failure but continue.
 *      A dangling file in storage is preferable to a dangling DB row
 *      because the DB row would re-appear in every Documents listing
 *      with a broken signed URL.
 *   3. Delete the acc_documents row.
 *   4. Audit log.
 *
 * @method  POST
 * @body    JSON or form-encoded { id: int }
 * @auth    Session required; require_permission('journal_entries', 'view')
 * @returns 200 { success: true, data: { deleted: true } }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3
 * Session:  S-ACCT-FIX-DOCS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'view');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$docId = clean_int($input['id'] ?? null);
if (!$docId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$doc = db_row(
    "SELECT id, entity_type, entity_id, title, file_path, file_name
       FROM acc_documents
      WHERE id = ?",
    [$docId]
);
if (!$doc) {
    json_error('NOT_FOUND', 'Document not found.', 404);
}

// Storage delete first. Log on failure but do not abort — see docblock.
try {
    StorageClient::delete($doc['file_path']);
} catch (\Throwable $e) {
    error_log(sprintf(
        'acc_documents storage delete failed for id=%d path=%s: %s',
        $docId, $doc['file_path'], $e->getMessage()
    ));
}

db_execute("DELETE FROM acc_documents WHERE id = ?", [$docId]);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'delete',
    'module'       => 'accounting',
    'entity_type'  => 'acc_document',
    'entity_id'    => $docId,
    'entity_label' => $doc['title'],
    'notes'        => "Document deleted: {$doc['title']} for {$doc['entity_type']} #{$doc['entity_id']}",
    'old_values'   => json_encode([
        'entity_type' => $doc['entity_type'],
        'entity_id'   => $doc['entity_id'],
        'file_name'   => $doc['file_name'],
    ]),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['deleted' => true]);
