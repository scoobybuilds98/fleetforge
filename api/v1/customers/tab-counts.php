<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Tab Counts
 *
 * @file        api/v1/customers/tab-counts.php
 * @description Returns record counts for all tabs on the customer profile page
 *              in a single round-trip so every badge is accurate on page load
 *              without lazy-loading each tab individually.
 *
 * @method      GET
 * @param       id (int) — customer ID
 * @auth        Session; require_permission('customers','view')
 * @returns     200 { notes, invoices, damage_claims, mileage_logs, documents, emails }
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id || $id <= 0) {
    json_error('INVALID_ID', 'A valid customer ID is required.', 400);
}

// Confirm customer exists and is accessible
if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$id])) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

$notes = (int) db_row(
    "SELECT COUNT(*) AS n FROM customer_notes WHERE customer_id = ?",
    [$id]
)['n'];

$invoices = (int) db_row(
    "SELECT COUNT(*) AS n FROM invoices WHERE customer_id = ? AND deleted_at IS NULL",
    [$id]
)['n'];

$damage_claims = (int) db_row(
    "SELECT COUNT(*) AS n FROM damage_claims WHERE customer_id = ? AND deleted_at IS NULL",
    [$id]
)['n'];

$mileage_logs = (int) db_row(
    "SELECT COUNT(*) AS n
       FROM mileage_logs
      WHERE lease_id IN (
          SELECT id FROM leases WHERE customer_id = ? AND deleted_at IS NULL
      )",
    [$id]
)['n'];

$documents = (int) db_row(
    "SELECT COUNT(*) AS n
       FROM documents
      WHERE entity_type = 'customer' AND entity_id = ? AND deleted_at IS NULL",
    [$id]
)['n'];

$emails = (int) db_row(
    "SELECT COUNT(*) AS n FROM email_logs WHERE customer_id = ?",
    [$id]
)['n'];

json_success(compact('notes', 'invoices', 'damage_claims', 'mileage_logs', 'documents', 'emails'));
