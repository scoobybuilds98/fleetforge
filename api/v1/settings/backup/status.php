<?php
declare(strict_types=1);

/**
 * api/v1/settings/backup/status.php
 *
 * GET — poll the state of a manual full backup job. The UI calls this every
 * few seconds after enqueue until status flips to success (→ show Download)
 * or failed (→ show the error).
 *
 * @method  GET
 * @auth    Session; require_permission('settings', 'view')
 * @query   ?run_id=N  (optional — defaults to the latest manual/full row)
 * @returns 200 { run_id, status, size, completed_at, downloadable, error }
 *        | 404 NOT_FOUND when no manual/full row exists
 *
 * Session: S-BACKUP-3c
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

$runId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;

if ($runId > 0) {
    $row = db_row(
        "SELECT id, status, progress_pct, progress_stage, file_key, file_size_bytes, completed_at, error_message
           FROM backup_runs
          WHERE id = ? AND destination = 'manual' AND backup_type = 'full' LIMIT 1",
        [$runId]
    );
} else {
    $row = db_row(
        "SELECT id, status, progress_pct, progress_stage, file_key, file_size_bytes, completed_at, error_message
           FROM backup_runs
          WHERE destination = 'manual' AND backup_type = 'full'
          ORDER BY id DESC LIMIT 1",
        []
    );
}

if (!$row) {
    json_error('NOT_FOUND', 'No manual backup job found.', 404);
}

$downloadable = $row['status'] === 'success' && !empty($row['file_key']);

json_success([
    'run_id'         => (int) $row['id'],
    'status'         => (string) $row['status'],
    'progress_pct'   => $row['progress_pct'] !== null ? (int) $row['progress_pct'] : null,
    'progress_stage' => $row['progress_stage'] !== null ? (string) $row['progress_stage'] : null,
    'size'           => $row['file_size_bytes'] !== null ? (int) $row['file_size_bytes'] : null,
    'completed_at'   => $row['completed_at'],
    'downloadable'   => $downloadable,
    'error'          => $row['status'] === 'failed' ? (string) ($row['error_message'] ?? '') : null,
]);
