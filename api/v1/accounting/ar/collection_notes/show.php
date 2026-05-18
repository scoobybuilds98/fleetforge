<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/collection_notes/show.php
 *
 * Fetch a single collection note with joined customer + invoice metadata.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { collection_note }
 *          404 if not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('VALIDATION_ERROR', 'Collection note ID is required.', 422);
}

// WHY: acc_collection_notes has no deleted_at column — hard delete only,
// so no soft-delete filter needed here. Customer/invoice join still
// respects their deleted_at since both are SOFT_DELETE_TABLES.
$note = db_row(
    "SELECT cn.*,
            c.company_name AS customer_name,
            i.invoice_number,
            u.name AS created_by_name
       FROM acc_collection_notes cn
  LEFT JOIN customers c ON c.id = cn.customer_id AND c.deleted_at IS NULL
  LEFT JOIN invoices i  ON i.id = cn.invoice_id  AND i.deleted_at IS NULL
  LEFT JOIN users u     ON u.id = cn.created_by
      WHERE cn.id = ?",
    [$id]
);

if (!$note) {
    json_error('NOT_FOUND', 'Collection note not found.', 404);
}

json_success(['collection_note' => $note]);
