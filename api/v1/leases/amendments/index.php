<?php
declare(strict_types=1);

/**
 * api/v1/leases/amendments/index.php
 *
 * GET ?lease_id=N → list all amendments for a lease, newest first.
 *
 * Returns amendment records joined with user names so the UI can
 * show "Recorded by" and "Approved by" without extra requests.
 *
 * @method  GET
 * @param   lease_id  int  required
 * @session AMEND-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('leases', 'view');

$leaseId = clean_int($_GET['lease_id'] ?? null);
if (!$leaseId) {
    json_error('VALIDATION_ERROR', 'lease_id is required.', 422);
}

// Confirm lease exists and is visible to this request
$leaseExists = db_row(
    "SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$leaseId]
);
if (!$leaseExists) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

$rows = db_select(
    "SELECT
        a.id,
        a.lease_id,
        a.amendment_type,
        a.description,
        a.old_values,
        a.new_values,
        a.created_by,
        a.approved_by,
        a.created_at,
        COALESCE(uc.name, 'System') AS created_by_name,
        COALESCE(ua.name, '')       AS approved_by_name
     FROM lease_amendments a
     LEFT JOIN users uc ON uc.id = a.created_by
     LEFT JOIN users ua ON ua.id = a.approved_by
     WHERE a.lease_id = ?
     ORDER BY a.created_at DESC",
    [$leaseId]
);

// Decode JSON blobs
foreach ($rows as &$r) {
    $r['id']          = (int)$r['id'];
    $r['lease_id']    = (int)$r['lease_id'];
    $r['created_by']  = $r['created_by']  ? (int)$r['created_by']  : null;
    $r['approved_by'] = $r['approved_by'] ? (int)$r['approved_by'] : null;
    $r['old_values']  = $r['old_values']  ? json_decode((string)$r['old_values'],  true) : null;
    $r['new_values']  = $r['new_values']  ? json_decode((string)$r['new_values'],  true) : null;
}
unset($r);

json_success([
    'amendments' => $rows,
    'count'      => count($rows),
]);
