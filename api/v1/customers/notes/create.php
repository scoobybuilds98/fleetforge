<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Note Create API
 *
 * @file        api/v1/customers/notes/create.php
 * @description Adds a new note to a customer. Optionally pin the note.
 *              Logs to audit_log. Notes are soft-deletable but creation
 *              does not require a separate delete permission check.
 *
 * @method      POST
 * @body        JSON { customer_id: int, note: string, is_pinned?: bool }
 * @required    customer_id (int), note (string)
 * @optional    is_pinned (bool, default false)
 * @auth        Session required; require_permission('customers','create')
 * @returns     201 { id, customer_id, note, is_pinned, created_by, created_at }
 *              404 if customer not found
 *              422 on missing required fields
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 4): api/v1/customers/notes/ → api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'create');

$body = json_body();

// ── Input ──────────────────────────────────────────────────────
$customerId = clean_int($body['customer_id'] ?? null);
if (!$customerId || $customerId <= 0) {
    json_error('INVALID_ID', 'A valid customer_id is required.', 400);
}

$note = clean_string($body['note'] ?? null, 10000);
if (!$note) {
    json_error('VALIDATION_ERROR', 'note is required.', 422, [
        'errors' => ['note' => 'Note text is required.'],
    ]);
}

$isPinned = isset($body['is_pinned']) ? (bool) $body['is_pinned'] : false;

// ── Verify customer exists ─────────────────────────────────────
$customerExists = db_exists('customers', 'id = ?', [$customerId]);
if (!$customerExists) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

$userId = current_user_id();
$now    = date('Y-m-d H:i:s');

// ── Insert ──────────────────────────────────────────────────────
$noteId = null;

db_transaction(function () use (&$noteId, $customerId, $note, $isPinned, $userId, $now): void {
    $noteId = db_insert('customer_notes', [
        'customer_id' => $customerId,
        'note'        => $note,
        'is_pinned'   => $isPinned ? 1 : 0,
        'created_by'  => $userId,
    ]);

    // Audit log
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'unknown',
        'action'       => 'create',
        'module'       => 'customers',
        'entity_type'  => 'customer_note',
        'entity_id'    => $customerId,
        'entity_label' => 'Note #' . $noteId,
        'new_values'   => json_encode(['is_pinned' => $isPinned]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success([
    'id'          => $noteId,
    'customer_id' => $customerId,
    'note'        => $note,
    'is_pinned'   => $isPinned,
    'created_by'  => $userId,
    'created_at'  => $now,
], 201);
