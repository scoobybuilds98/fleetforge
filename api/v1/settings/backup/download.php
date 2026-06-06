<?php
declare(strict_types=1);

/**
 * api/v1/settings/backup/download.php
 *
 * GET — issue a FRESH short-TTL presigned download URL for a completed manual
 * full backup and 302-redirect to it. The bundle never streams through PHP;
 * StorageClient::url() produces an S3 presigned URL (prod) or an HMAC-signed
 * local serve URL (dev), both expiring quickly.
 *
 * Only a manual/full/success row with a non-empty file_key is downloadable —
 * anything else is rejected.
 *
 * @method  GET
 * @auth    Session; require_permission('settings', 'view'); audit_log on success
 * @query   ?run_id=N  (required)
 * @returns 302 redirect to the presigned URL
 *        | 404 NOT_FOUND / 422 NOT_DOWNLOADABLE
 *
 * Session: S-BACKUP-3c
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

$runId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;
if ($runId <= 0) {
    json_error('VALIDATION_ERROR', 'run_id is required.', 422);
}

$row = db_row(
    "SELECT id, destination, backup_type, status, file_key
       FROM backup_runs WHERE id = ? LIMIT 1",
    [$runId]
);

if (!$row) {
    json_error('NOT_FOUND', 'Backup job not found.', 404);
}

// Strict gate: only a successful manual full bundle is downloadable.
if (
    $row['destination'] !== 'manual'
    || $row['backup_type'] !== 'full'
    || $row['status'] !== 'success'
    || empty($row['file_key'])
) {
    json_error('NOT_DOWNLOADABLE', 'This backup is not available for download.', 422);
}

$fileKey = (string) $row['file_key'];

// Fresh short-TTL link (5 min).
$url = StorageClient::url($fileKey, 300);

$user = current_user();
db_insert('audit_log', [
    'user_id'     => $user['id'] ?? null,
    'user_name'   => $user['name'] ?? 'system',
    'action'      => 'export',
    'module'      => 'settings',
    'entity_type' => 'manual_backup',
    'entity_id'   => (int) $row['id'],
    'entity_label' => 'manual_full',
    'notes'       => 'Manual full backup downloaded (run #' . (int) $row['id'] . ', key ' . $fileKey . ').',
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
]);

header('Location: ' . $url, true, 302);
exit;
