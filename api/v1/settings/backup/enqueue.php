<?php
declare(strict_types=1);

/**
 * api/v1/settings/backup/enqueue.php
 *
 * POST — enqueue an async manual "download everything" full backup
 * (D-BACKUP-3). A backup_runs row IS the job record (no separate queue
 * table): this endpoint opens an in_progress manual/full row; the worker
 * cron (cron/backup_manual_worker.php) builds the bundle and flips it to
 * success/failed; the UI polls status.php and then hits download.php.
 *
 * Concurrency guard: if a manual/full in_progress row already exists, we
 * return THAT row's id (already_running=true) instead of starting a second
 * concurrent build.
 *
 * @method  POST
 * @auth    Session; require_permission('settings', 'edit'); CSRF via bootstrap
 * @returns 200 { run_id: int, already_running: bool }
 *
 * Session: S-BACKUP-3c
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Backup\BackupRun;

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

// Never start two concurrent builds — return the in-flight one if present.
$existing = db_row(
    "SELECT id FROM backup_runs
      WHERE destination = 'manual' AND backup_type = 'full' AND status = 'in_progress'
      ORDER BY id ASC LIMIT 1",
    []
);
if ($existing) {
    json_success(['run_id' => (int) $existing['id'], 'already_running' => true]);
}

$runId = BackupRun::start('manual', 'full', current_user_id(), 'manual');
if ($runId === 0) {
    json_error('BACKUP_ENQUEUE_FAILED', 'Could not create the backup job. Please try again.', 500);
}

$user = current_user();
db_insert('audit_log', [
    'user_id'     => $user['id'] ?? null,
    'user_name'   => $user['name'] ?? 'system',
    'action'      => 'create',
    'module'      => 'settings',
    'entity_type' => 'manual_backup',
    'entity_id'   => $runId,
    'entity_label' => 'manual_full',
    'notes'       => 'Manual full backup enqueued (run #' . $runId . ').',
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
]);

json_success(['run_id' => $runId, 'already_running' => false]);
