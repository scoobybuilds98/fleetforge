<?php declare(strict_types=1);

/**
 * api/v1/cron/trigger.php
 *
 * Manual trigger for scheduled cron jobs.
 * Runs the cron script synchronously via exec() and returns results.
 *
 * WHY: Every automation must have a manual override so admins can
 * trigger jobs on-demand without waiting for the cron schedule.
 *
 * @method  POST
 * @body    { "job": "compliance_alerts" | "invoice_overdue" | "late_fee_apply"
 *                  | "invoice_generate_monthly" | "gps_mileage_sync" }
 * @auth    Session required; require_role('super_admin')
 * @returns 200 { job, exit_code, output, last_log }
 *
 * @depends api/bootstrap.php, cron/*.php
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_role('super_admin');

$body = json_body();
$job  = trim($body['job'] ?? '');

// ── Whitelist of triggerable jobs ───────────────────────────
$allowedJobs = [
    'compliance_alerts'        => 'Compliance Alerts',
    'invoice_overdue'          => 'Overdue Invoice Marking',
    'late_fee_apply'           => 'Late Fee Application',
    'invoice_generate_monthly' => 'Monthly Invoice Generation',
    'gps_mileage_sync'         => 'GPS Mileage Sync',
];

if (!$job || !isset($allowedJobs[$job])) {
    json_error(
        'INVALID_JOB',
        'Invalid job name. Allowed: ' . implode(', ', array_keys($allowedJobs)),
        422
    );
}

$script = FF_ROOT . '/cron/' . $job . '.php';

if (!file_exists($script)) {
    json_error('JOB_NOT_FOUND', "Cron script not found: {$job}.php", 404);
}

// ── Run the cron script synchronously via PHP CLI ───────────
// WHY: exec() reuses the full cron script with its own advisory lock,
// error handling, and audit logging — zero code duplication.
// NOTE: exec() must not be in disable_functions for this to work.
// Herd (php-fpm) ignores the mod_php disable_functions in .htaccess.
if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions') ?: '')), true)) {
    json_error('EXEC_DISABLED', 'exec() is disabled on this server. Cannot trigger cron jobs.', 503);
}
$phpBinary = PHP_BINARY;
$command   = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' 2>&1';

$output     = [];
$returnCode = 0;

$userId = current_user_id();
$user   = current_user();

// Audit log: manual trigger started
db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => $user['name'] ?? 'Admin',
    'action'       => 'manual_trigger',
    'module'       => 'system',
    'entity_type'  => 'cron',
    'entity_id'    => null,
    'entity_label' => $job,
    'notes'        => "Manual trigger of {$allowedJobs[$job]} by {$user['name']}",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

exec($command, $output, $returnCode);

// ── Fetch the most recent audit log entry for this job ──────
$lastLog = db_row(
    "SELECT created_at, notes FROM audit_log
     WHERE entity_type = 'cron' AND entity_label = ?
       AND action = 'cron'
     ORDER BY id DESC LIMIT 1",
    [$job]
);

json_success([
    'job'       => $job,
    'label'     => $allowedJobs[$job],
    'exit_code' => $returnCode,
    'output'    => implode("\n", $output),
    'last_log'  => $lastLog,
]);
