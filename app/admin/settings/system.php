<?php
declare(strict_types=1);

/**
 * app/admin/settings/system.php
 *
 * System information tab — included by settings/index.php.
 * Shows environment info, database stats, table sizes, cron status, and health checks.
 *
 * Variables inherited from parent: $canEdit, $isSuperAdmin, $csrfToken
 */

// ── Environment Info ──────────────────────────────────────────────────────────
$envInfo = [
    'PHP Version'       => PHP_VERSION,
    'Server Software'   => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
    'App Environment'   => APP_ENV,
    'App URL'           => APP_URL,
    'Base Path'         => FF_BASE_PATH,
    'Timezone'          => settings_get('company.timezone', date_default_timezone_get()),
    'Session Handler'   => ini_get('session.save_handler'),
    'Memory Limit'      => ini_get('memory_limit'),
    'Max Execution'     => ini_get('max_execution_time') . 's',
    'Upload Max Size'   => ini_get('upload_max_filesize'),
    'Post Max Size'     => ini_get('post_max_size'),
    'OPcache Enabled'   => function_exists('opcache_get_status') && opcache_get_status() ? 'Yes' : 'No',
];

// ── Database Stats ──────────────────────────────────────────────────────────
$dbStats = [];
try {
    $dbVersion = db_row("SELECT VERSION() AS ver");
    $dbStats['MySQL Version'] = $dbVersion['ver'] ?? 'Unknown';

    $dbSize = db_row(
        "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
         FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
    $dbStats['Database Size'] = ($dbSize['size_mb'] ?? '0') . ' MB';

    $tableCount = db_count(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
    $dbStats['Total Tables'] = (string)$tableCount;

    $dbStats['Database Name'] = defined('FF_DB_NAME') ? FF_DB_NAME : 'fleetforge';
} catch (Throwable $e) {
    $dbStats['Error'] = $e->getMessage();
}

// ── Table Row Counts ────────────────────────────────────────────────────────
$tableCounts = [];
$coreTables = [
    'customers', 'leases', 'invoices', 'payments', 'equipment_units',
    'equipment_templates', 'documents', 'users', 'portal_users',
    'portal_service_requests', 'audit_log', 'credit_notes',
    'damage_claims', 'vendors', 'inspections', 'mileage_logs',
];
foreach ($coreTables as $t) {
    try {
        $tableCounts[$t] = db_count("SELECT COUNT(*) FROM `{$t}`");
    } catch (Throwable) {
        $tableCounts[$t] = -1; // table might not exist
    }
}

// ── Cron Status (last run times) ────────────────────────────────────────────
$cronJobs = [];
$cronNames = [
    'invoice_generate_monthly' => 'Monthly Invoice Generation',
    'invoice_overdue'          => 'Overdue Invoice Marking',
    'late_fee_apply'           => 'Late Fee Application',
    'gps_sync'                 => 'GPS Location Sync',
];
foreach ($cronNames as $slug => $label) {
    $lastRun = db_row(
        "SELECT created_at, notes FROM audit_log
         WHERE module = 'cron' AND entity_label LIKE ?
         ORDER BY created_at DESC LIMIT 1",
        ['%' . $slug . '%']
    );
    $cronJobs[] = [
        'name'     => $label,
        'slug'     => $slug,
        'last_run' => $lastRun['created_at'] ?? null,
        'notes'    => $lastRun['notes'] ?? null,
    ];
}

// ── Health Checks ───────────────────────────────────────────────────────────
$healthChecks = [];

// DB connection
try {
    db_row("SELECT 1");
    $healthChecks['Database'] = ['status' => 'ok', 'detail' => 'Connected'];
} catch (Throwable $e) {
    $healthChecks['Database'] = ['status' => 'error', 'detail' => $e->getMessage()];
}

// Storage directory
$storageDir = FF_ROOT . '/storage';
$healthChecks['Storage Directory'] = [
    'status' => is_writable($storageDir) ? 'ok' : 'warn',
    'detail' => is_writable($storageDir) ? 'Writable' : 'Not writable or missing',
];

// Logs directory
$logsDir = FF_ROOT . '/logs';
$healthChecks['Logs Directory'] = [
    'status' => is_dir($logsDir) && is_writable($logsDir) ? 'ok' : 'warn',
    'detail' => is_dir($logsDir) && is_writable($logsDir) ? 'Writable' : 'Not writable or missing',
];

// Session
$healthChecks['Sessions'] = [
    'status' => session_status() === PHP_SESSION_ACTIVE ? 'ok' : 'error',
    'detail' => session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Not started',
];

// PHP extensions
$requiredExts = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'curl'];
$missingExts  = array_filter($requiredExts, fn($ext) => !extension_loaded($ext));
$healthChecks['PHP Extensions'] = [
    'status' => empty($missingExts) ? 'ok' : 'error',
    'detail' => empty($missingExts) ? 'All required extensions loaded' : 'Missing: ' . implode(', ', $missingExts),
];

$statusIcon = [
    'ok'    => '<span style="color:var(--color-success);">&#10003;</span>',
    'warn'  => '<span style="color:var(--color-warning);">&#9888;</span>',
    'error' => '<span style="color:var(--color-danger);">&#10007;</span>',
];
?>

<!-- Health Checks -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Health Checks</div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead><tr><th></th><th>Component</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($healthChecks as $name => $check): ?>
                <tr>
                    <td style="width:40px;text-align:center;"><?= $statusIcon[$check['status']] ?? '' ?></td>
                    <td style="font-weight:500;"><?= e($name) ?></td>
                    <td style="font-size:0.8125rem;color:var(--text-secondary);"><?= e($check['detail']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

<!-- Environment -->
<div class="card">
    <div class="card-header" style="font-weight:600;">Environment</div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <tbody>
                <?php foreach ($envInfo as $label => $value): ?>
                <tr>
                    <td style="font-weight:500;width:45%;font-size:0.8125rem;"><?= e($label) ?></td>
                    <td class="font-mono" style="font-size:0.8125rem;"><?= e($value) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Database -->
<div class="card">
    <div class="card-header" style="font-weight:600;">Database</div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <tbody>
                <?php foreach ($dbStats as $label => $value): ?>
                <tr>
                    <td style="font-weight:500;width:45%;font-size:0.8125rem;"><?= e($label) ?></td>
                    <td class="font-mono" style="font-size:0.8125rem;"><?= e($value) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /grid -->

<!-- Cron Jobs -->
<div class="card" style="margin-top:20px;">
    <div class="card-header" style="font-weight:600;">Scheduled Tasks (Cron)</div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr><th>Task</th><th>Last Run</th><th>Notes</th></tr>
            </thead>
            <tbody>
                <?php foreach ($cronJobs as $cj): ?>
                <tr>
                    <td style="font-weight:500;font-size:0.8125rem;"><?= e($cj['name']) ?></td>
                    <td class="font-mono" style="font-size:0.8125rem;">
                        <?php if ($cj['last_run']): ?>
                            <?= e(format_datetime($cj['last_run'])) ?>
                            <?php
                            $ago = time() - strtotime($cj['last_run']);
                            if ($ago > 86400 * 2):
                            ?>
                            <span class="badge badge-warning" style="font-size:0.65rem;margin-left:4px;">
                                <?= e(round($ago / 86400)) ?>d ago
                            </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">Never</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.75rem;color:var(--text-muted);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($cj['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Table Row Counts -->
<div class="card" style="margin-top:20px;">
    <div class="card-header" style="font-weight:600;">Table Row Counts</div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;">
            <?php foreach ($tableCounts as $table => $count): ?>
            <div style="display:flex;justify-content:space-between;padding:6px 10px;background:var(--bg-card);border:1px solid var(--border-default);border-radius:6px;font-size:0.8125rem;">
                <span style="color:var(--text-secondary);"><?= e($table) ?></span>
                <span class="font-mono" style="font-weight:600;"><?= $count >= 0 ? e(number_format($count)) : '<span style="color:var(--text-muted);">—</span>' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
