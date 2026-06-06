<?php
declare(strict_types=1);

/**
 * lib/Backup/BackupRun.php
 *
 * Thin helper that writes rows to backup_runs, giving every backup cron and
 * the future manual-trigger endpoint a unified history trail.
 *
 * FAIL-SOFT CONTRACT: every method catches \Throwable and error_logs it.
 * A backup_runs write failure MUST NEVER abort the actual backup. Callers
 * should treat the returned id as advisory — if start() returns 0, the
 * subsequent success()/fail() calls are no-ops.
 *
 * @session  S-BACKUP-1
 * @decision D-BACKUP-1, D-BACKUP-2, D-BACKUP-3
 */

namespace FleetForge\Backup;

class BackupRun
{
    /**
     * Open a new in_progress run record.
     *
     * Returns the inserted row id, or 0 on write failure (fail-soft).
     */
    public static function start(
        string  $destination,
        string  $type,
        ?int    $userId       = null,
        string  $triggerSource = 'cron'
    ): int {
        try {
            return (int) db_insert('backup_runs', [
                'destination'    => $destination,
                'backup_type'    => $type,
                'status'         => 'in_progress',
                'started_at'     => gmdate('Y-m-d H:i:s'),
                'initiated_by'   => $userId,
                'trigger_source' => $triggerSource,
            ]);
        } catch (\Throwable $e) {
            error_log('[BackupRun::start] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark a run successful; record file key, size, and wall-clock duration.
     */
    public static function success(int $id, ?string $fileKey, ?int $sizeBytes): void
    {
        if ($id === 0) return;
        try {
            db_execute(
                'UPDATE backup_runs
                    SET status          = ?,
                        completed_at    = UTC_TIMESTAMP(),
                        duration_ms     = TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) * 1000,
                        file_key        = ?,
                        file_size_bytes = ?
                  WHERE id = ?',
                ['success', $fileKey, $sizeBytes, $id]
            );
        } catch (\Throwable $e) {
            error_log('[BackupRun::success] ' . $e->getMessage());
        }
    }

    /**
     * Mark a run failed; record the error message (truncated to TEXT limit).
     */
    public static function fail(int $id, string $error): void
    {
        if ($id === 0) return;
        try {
            db_execute(
                'UPDATE backup_runs
                    SET status        = ?,
                        completed_at  = UTC_TIMESTAMP(),
                        duration_ms   = TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) * 1000,
                        error_message = ?
                  WHERE id = ?',
                ['failed', mb_substr($error, 0, 65535), $id]
            );
        } catch (\Throwable $e) {
            error_log('[BackupRun::fail] ' . $e->getMessage());
        }
    }

    /**
     * Return the most recent success row for a destination (and optionally
     * a specific backup_type). Returns null if none exists or on error.
     */
    public static function lastSuccess(string $destination, ?string $type = null): ?array
    {
        try {
            if ($type !== null) {
                $row = db_row(
                    'SELECT * FROM backup_runs
                      WHERE destination = ? AND backup_type = ? AND status = ?
                      ORDER BY completed_at DESC LIMIT 1',
                    [$destination, $type, 'success']
                );
            } else {
                $row = db_row(
                    'SELECT * FROM backup_runs
                      WHERE destination = ? AND status = ?
                      ORDER BY completed_at DESC LIMIT 1',
                    [$destination, 'success']
                );
            }
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('[BackupRun::lastSuccess] ' . $e->getMessage());
            return null;
        }
    }
}
