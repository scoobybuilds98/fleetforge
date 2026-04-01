<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Notes List API
 *
 * @file        api/v1/customers/notes/index.php
 * @description Returns all non-deleted notes for a customer. Pinned notes
 *              appear first, then sorted by created_at DESC (newest first).
 *
 * @method      GET
 * @params      customer_id (required, int)
 * @auth        Session required; require_permission('customers','view')
 * @returns     { notes: [ { id, customer_id, note, is_pinned,
 *                           created_by, created_by_name, created_at } ] }
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 4): api/v1/customers/notes/ → api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

// ── Input ──────────────────────────────────────────────────────
$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId || $customerId <= 0) {
    json_error('INVALID_ID', 'A valid customer_id is required.', 400);
}

// ── Verify customer exists ─────────────────────────────────────
$customerExists = db_exists('customers', 'id = ?', [$customerId]);
if (!$customerExists) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// ── Fetch notes ────────────────────────────────────────────────
// Pinned notes sort first (is_pinned DESC), then newest first.
// customer_notes IS in SOFT_DELETE_TABLES → filter deleted_at IS NULL.
$rows = db_select(
    "SELECT
        n.id,
        n.customer_id,
        n.note,
        n.is_pinned,
        n.created_by,
        u.name AS created_by_name,
        n.created_at
       FROM customer_notes n
       LEFT JOIN users u ON u.id = n.created_by AND u.deleted_at IS NULL
      WHERE n.customer_id = ?
        AND n.deleted_at IS NULL
      ORDER BY n.is_pinned DESC, n.created_at DESC",
    [$customerId]
);

// ── Format ─────────────────────────────────────────────────────
$notes = [];
foreach ($rows as $row) {
    $notes[] = [
        'id'              => (int) $row['id'],
        'customer_id'     => (int) $row['customer_id'],
        'note'            => $row['note'],
        'is_pinned'       => (bool) $row['is_pinned'],
        'created_by'      => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'created_by_name' => $row['created_by_name'],
        'created_at'      => $row['created_at'],
    ];
}

json_success(['notes' => $notes]);
