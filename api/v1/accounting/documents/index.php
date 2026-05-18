<?php
declare(strict_types=1);

/**
 * api/v1/accounting/documents/index.php
 *
 * List all acc_documents rows for a given accounting entity, ordered by
 * uploaded_at DESC. Used by every Documents section across the accounting
 * admin pages (bills, journal-entries, ap-payments, bank-transactions,
 * fixed-assets, tax filings).
 *
 * Each row carries a freshly-minted signed URL via StorageClient::url().
 * file_path is NEVER in the response (Trap 7).
 *
 * @method  GET
 * @query   entity_type (required), entity_id (required)
 * @auth    Session required; require_permission('journal_entries', 'view')
 * @returns 200 { data: [ { id, title, file_name, file_size_kb, mime_type,
 *                          notes, uploaded_at, uploaded_by_name, signed_url } ] }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3
 * Session:  S-ACCT-FIX-DOCS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$allowedTypes = [
    'journal_entry', 'bill', 'ap_payment', 'bank_transaction',
    'asset', 'tax_filing', 'reconciliation', 'other',
];
$entityType = clean_string($_GET['entity_type'] ?? '');
if (!in_array($entityType, $allowedTypes, true)) {
    json_error('VALIDATION_ERROR',
        'entity_type must be one of: ' . implode(', ', $allowedTypes), 422);
}

$entityId = clean_int($_GET['entity_id'] ?? null);
if (!$entityId) {
    json_error('MISSING_REQUIRED', 'entity_id is required.', 422);
}

$rows = db_select(
    "SELECT d.id, d.entity_type, d.entity_id, d.title, d.file_name,
            d.file_size_kb, d.mime_type, d.notes, d.uploaded_at,
            u.name AS uploaded_by_name,
            d.file_path
       FROM acc_documents d
       LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.entity_type = ? AND d.entity_id = ?
      ORDER BY d.uploaded_at DESC, d.id DESC",
    [$entityType, $entityId]
);

foreach ($rows as &$r) {
    // signed URL with 1-hour TTL; key passed in, raw path stripped (Trap 7)
    $r['signed_url'] = StorageClient::url($r['file_path'], 3600);
    unset($r['file_path']);
}
unset($r);

json_success($rows);
