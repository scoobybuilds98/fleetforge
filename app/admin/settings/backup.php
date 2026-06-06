<?php
declare(strict_types=1);

/**
 * app/admin/settings/backup.php
 *
 * Backup & Storage settings tab — normally included by settings/index.php.
 * Surfaces the 3-destination backup state (AWS S3 / Dropbox / Manual) plus a
 * recent backup_runs history table, and lets the operator manage the Dropbox
 * connection from the UI (replacing the scripts/dropbox_configure.php CLI).
 *
 * Inherited from parent when included: $canEdit, $isSuperAdmin, $csrfToken.
 *
 * Reads: BackupRun::lastSuccess(...) + db_select on backup_runs.
 * Writes: handled by api/v1/settings/dropbox/{save,disconnect}.php (not here).
 *
 * Standalone-safe bootstrap (see system.php): enforce auth before any data
 * renders if reached via a direct URL.
 *
 * Session: S-BACKUP-3b   (manual "generate" wiring deferred to S-BACKUP-3c)
 */

use FleetForge\Backup\BackupRun;

// Standalone execution guard — mirror system.php / users.php.
if (!isset($canEdit)) {
    require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
    require_once FF_ROOT . '/includes/auth.php';

    require_auth();
    require_permission('settings', 'view');

    $canEdit      = can('settings', 'edit');
    $isSuperAdmin = can('settings', 'delete');
    $csrfToken    = generate_csrf_token();

    $pageTitle = 'Backup & Storage';
    require_once FF_ROOT . '/includes/header.php';
    $_ff_standalone_backup = true;
}

// ── Local helpers ───────────────────────────────────────────────────────────
if (!function_exists('ff_backup_fmt_bytes')) {
    /** Human-readable byte size; '—' for null/0. */
    function ff_backup_fmt_bytes(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = max(0, min($i, count($units) - 1));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
if (!function_exists('ff_backup_fmt_when')) {
    /** Format a stored UTC datetime for display; '—' when null/empty. */
    function ff_backup_fmt_when(?string $dt): string
    {
        if ($dt === null || $dt === '' || $dt === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($dt . ' UTC');
        return $ts === false ? (string) $dt : gmdate('Y-m-d H:i', $ts) . ' UTC';
    }
}
if (!function_exists('ff_backup_freshness')) {
    /**
     * Freshness dot class for a last-success timestamp.
     *   'none'  — never succeeded (grey)
     *   'amber' — older than $maxAgeSeconds (stale, ~2× cadence)
     *   'green' — fresh
     */
    function ff_backup_freshness(?string $completedAt, int $maxAgeSeconds): string
    {
        if ($completedAt === null || $completedAt === '') {
            return 'none';
        }
        $ts = strtotime($completedAt . ' UTC');
        if ($ts === false) {
            return 'none';
        }
        return (time() - $ts) > $maxAgeSeconds ? 'amber' : 'green';
    }
}

/** Render a small colored freshness dot. */
$ffDot = static function (string $state): string {
    $color = match ($state) {
        'green' => 'var(--color-success)',
        'amber' => '#b45309',
        default => 'var(--text-muted)',
    };
    return '<span aria-hidden="true" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . $color . ';margin-right:6px;vertical-align:middle;"></span>';
};

// ── Gather backup state ───────────────────────────────────────────────────────
$s3Db       = BackupRun::lastSuccess('s3', 'db');
$s3Storage  = BackupRun::lastSuccess('s3', 'storage');
$dropDb      = BackupRun::lastSuccess('dropbox', 'db');
$dropStorage = BackupRun::lastSuccess('dropbox', 'storage');
$manualFull  = BackupRun::lastSuccess('manual', 'full');

// Dropbox connection state
$dropEnabled   = settings_get('dropbox.enabled') === '1';
$dropToken     = (string) settings_get('dropbox.refresh_token', '');
$dropAppKey    = (string) settings_get('dropbox.app_key', '');
$dropAccount   = (string) settings_get('dropbox.connected_account', '');
$dropConnected = $dropEnabled && $dropToken !== '';
// WHY: never render the stored secret — only whether one is set.
$dropHasSecret = ((string) settings_get('dropbox.app_secret', '')) !== '';

// Cadence-based staleness (~2× cadence):
//   s3/db every 6h → amber > 12h;  s3/storage weekly → amber > 14d
//   dropbox/db daily → amber > 2d;  dropbox/storage weekly → amber > 14d
$AGE_DB_S3      = 12 * 3600;
$AGE_STORAGE    = 14 * 86400;
$AGE_DB_DROPBOX = 2 * 86400;

// Manual initiator name
$manualBy = 'system';
if ($manualFull && !empty($manualFull['initiated_by'])) {
    $mrow = db_row('SELECT name FROM users WHERE id = ?', [(int) $manualFull['initiated_by']]);
    $manualBy = $mrow['name'] ?? 'system';
}

// S-BACKUP-3c: latest manual/full run (any status) seeds the UI poll, and the
// in_progress check disables the Generate button + starts polling on load.
$latestManual = db_row(
    "SELECT id, status FROM backup_runs
      WHERE destination = 'manual' AND backup_type = 'full'
      ORDER BY id DESC LIMIT 1",
    []
);
$manualInProgress  = $latestManual && $latestManual['status'] === 'in_progress';
$latestManualRunId = $latestManual ? (int) $latestManual['id'] : 0;

// ── Recent history (newest first) ─────────────────────────────────────────────
$history = db_select(
    "SELECT br.id, br.destination, br.backup_type, br.status, br.file_size_bytes,
            br.completed_at, br.started_at, br.created_at, br.initiated_by,
            u.name AS initiated_by_name
     FROM backup_runs br
     LEFT JOIN users u ON u.id = br.initiated_by
     ORDER BY br.id DESC
     LIMIT 50",
    []
);
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Backup &amp; Storage</div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:0.875rem;margin:0 0 4px;">
            Three backup destinations protect your data. AWS S3 runs automatically on a schedule;
            Dropbox mirrors the latest S3 artifact off-site; manual full backups are on-demand.
        </p>
    </div>
</div>

<!-- ── Status cards ──────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-bottom:20px;">

    <!-- AWS S3 (read-only) -->
    <div class="card">
        <div class="card-header" style="font-weight:600;">AWS S3 <span style="font-weight:400;color:var(--text-muted);font-size:0.8125rem;">— automatic</span></div>
        <div class="card-body" style="font-size:0.875rem;">
            <div style="margin-bottom:10px;">
                <?= $ffDot(ff_backup_freshness($s3Db['completed_at'] ?? null, $AGE_DB_S3)) ?>
                <strong>Database:</strong>
                <?= e(ff_backup_fmt_when($s3Db['completed_at'] ?? null)) ?>
                <span style="color:var(--text-muted);">(<?= e(ff_backup_fmt_bytes(isset($s3Db['file_size_bytes']) ? (int) $s3Db['file_size_bytes'] : null)) ?>)</span>
            </div>
            <div style="margin-bottom:10px;">
                <?= $ffDot(ff_backup_freshness($s3Storage['completed_at'] ?? null, $AGE_STORAGE)) ?>
                <strong>Storage:</strong>
                <?= e(ff_backup_fmt_when($s3Storage['completed_at'] ?? null)) ?>
                <span style="color:var(--text-muted);">(<?= e(ff_backup_fmt_bytes(isset($s3Storage['file_size_bytes']) ? (int) $s3Storage['file_size_bytes'] : null)) ?>)</span>
            </div>
            <div style="color:var(--text-muted);font-size:0.75rem;border-top:1px solid var(--border-default);padding-top:8px;">
                Retention: database 90 days · storage 12 months
            </div>
        </div>
    </div>

    <!-- Dropbox -->
    <div class="card" x-data="FF_DropboxConfig()">
        <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
            Dropbox
            <span style="font-weight:400;font-size:0.8125rem;<?= $dropConnected ? 'color:var(--color-success);' : 'color:var(--text-muted);' ?>">
                — <?= $dropConnected ? 'connected' : 'not connected' ?>
            </span>
        </div>
        <div class="card-body" style="font-size:0.875rem;">
        <?php if ($dropConnected): ?>
            <div style="margin-bottom:10px;">
                <strong>Account:</strong> <?= e($dropAccount !== '' ? $dropAccount : 'connected') ?>
            </div>
            <div style="margin-bottom:8px;">
                <?= $ffDot(ff_backup_freshness($dropDb['completed_at'] ?? null, $AGE_DB_DROPBOX)) ?>
                <strong>DB mirror:</strong> <?= e(ff_backup_fmt_when($dropDb['completed_at'] ?? null)) ?>
            </div>
            <div style="margin-bottom:10px;">
                <?= $ffDot(ff_backup_freshness($dropStorage['completed_at'] ?? null, $AGE_STORAGE)) ?>
                <strong>Storage mirror:</strong> <?= e(ff_backup_fmt_when($dropStorage['completed_at'] ?? null)) ?>
            </div>
            <?php if ($canEdit): ?>
            <button class="btn btn-danger btn-sm" @click="disconnect()" :disabled="saving" style="white-space:nowrap;">
                <span x-show="!saving">Disconnect</span>
                <span x-show="saving" x-cloak>Working…</span>
            </button>
            <?php endif; ?>
        <?php else: ?>
            <p style="color:var(--text-muted);margin:0 0 12px;">
                Enter your Dropbox app credentials, save, then connect via OAuth.
            </p>
            <?php if ($canEdit): ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <label style="display:block;">
                    <span style="display:block;font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">App Key</span>
                    <input type="text" x-model="appKey" class="form-input" style="width:100%;" autocomplete="off">
                </label>
                <label style="display:block;">
                    <span style="display:block;font-size:0.75rem;color:var(--text-muted);margin-bottom:2px;">App Secret <?php if ($dropHasSecret): ?><span style="color:var(--color-success);">(saved — leave blank to keep)</span><?php endif; ?></span>
                    <input type="password" x-model="appSecret" class="form-input" style="width:100%;" autocomplete="new-password"
                           placeholder="<?= $dropHasSecret ? '••••••••' : '' ?>">
                </label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button class="btn btn-secondary btn-sm" @click="saveKeys()" :disabled="saving" style="white-space:nowrap;">
                        <span x-show="!saving">Save Keys</span>
                        <span x-show="saving" x-cloak>Saving…</span>
                    </button>
                    <a class="btn btn-primary btn-sm" href="<?= e(base_url('oauth/dropbox/init.php')) ?>"
                       style="white-space:nowrap;<?= $dropAppKey === '' ? 'pointer-events:none;opacity:0.5;' : '' ?>"
                       <?= $dropAppKey === '' ? 'title="Save your App Key first" aria-disabled="true"' : '' ?>>
                        Connect Dropbox
                    </a>
                </div>
                <div x-show="flash" x-cloak style="color:var(--color-danger);font-size:0.8125rem;" x-text="flash"></div>
            </div>
            <?php else: ?>
            <p style="color:var(--text-muted);margin:0;">You don't have permission to manage Dropbox.</p>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>

    <!-- Manual full backup (async — S-BACKUP-3c) -->
    <div class="card" x-data="FF_ManualBackup()" x-init="init()">
        <div class="card-header" style="font-weight:600;">Manual Backup <span style="font-weight:400;color:var(--text-muted);font-size:0.8125rem;">— on-demand</span></div>
        <div class="card-body" style="font-size:0.875rem;">
            <?php if ($manualFull): ?>
            <div style="margin-bottom:8px;">
                <strong>Last manual full:</strong> <?= e(ff_backup_fmt_when($manualFull['completed_at'] ?? null)) ?>
                <span style="color:var(--text-muted);">(<?= e(ff_backup_fmt_bytes(isset($manualFull['file_size_bytes']) ? (int) $manualFull['file_size_bytes'] : null)) ?>)</span>
            </div>
            <div style="color:var(--text-muted);margin-bottom:12px;">By: <?= e($manualBy) ?></div>
            <?php else: ?>
            <p style="color:var(--text-muted);margin:0 0 12px;">No manual backup has been generated yet.</p>
            <?php endif; ?>

            <?php if ($canEdit): ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button class="btn btn-secondary btn-sm" @click="generate()"
                        :disabled="status === 'in_progress' || working" style="white-space:nowrap;">
                    <span x-show="status !== 'in_progress' && !working">Generate full backup</span>
                    <span x-show="status === 'in_progress' || working" x-cloak>Building…</span>
                </button>
                <a class="btn btn-primary btn-sm" x-show="status === 'success' && downloadable" x-cloak
                   :href="downloadUrl" style="white-space:nowrap;">Download</a>
            </div>
            <div x-show="status === 'in_progress'" x-cloak style="margin-top:10px;">
                <div style="height:8px;background:var(--border-default);border-radius:4px;overflow:hidden;">
                    <div :style="'height:100%;background:var(--color-primary);border-radius:4px;transition:width .4s ease;width:' + (progressPct !== null ? progressPct : 8) + '%'"></div>
                </div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">
                    <span x-text="progressStage || 'Working…'"></span><span x-show="progressPct !== null" x-text="' (' + progressPct + '%)'"></span>
                </div>
            </div>
            <div x-show="status === 'failed'" x-cloak style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;" x-text="errorMsg"></div>
            <div x-show="flash" x-cloak style="color:var(--color-danger);font-size:0.8125rem;margin-top:8px;" x-text="flash"></div>
            <?php endif; ?>

            <div style="color:var(--text-muted);font-size:0.75rem;border-top:1px solid var(--border-default);padding-top:8px;margin-top:8px;">
                Bundles the latest S3 database + storage backups into one downloadable archive.
            </div>
        </div>
    </div>

</div>

<!-- ── History table ─────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Recent backup history</div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($history)): ?>
        <p style="color:var(--text-muted);padding:16px;margin:0;">No backup runs recorded yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table" style="width:100%;font-size:0.8125rem;">
            <thead>
                <tr>
                    <th>When</th><th>Destination</th><th>Type</th><th>Status</th><th>Size</th><th>By</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <?php
                $when = $row['completed_at'] ?: ($row['started_at'] ?: $row['created_at']);
                $statusColor = match ($row['status']) {
                    'success'     => 'var(--color-success)',
                    'failed'      => 'var(--color-danger)',
                    default       => '#b45309', // in_progress
                };
                $by = $row['initiated_by_name'] ?? ($row['initiated_by'] === null ? 'system' : 'system');
                ?>
                <tr>
                    <td style="white-space:nowrap;"><?= e(ff_backup_fmt_when($when)) ?></td>
                    <td><?= e(ucfirst((string) $row['destination'])) ?></td>
                    <td><?= e((string) $row['backup_type']) ?></td>
                    <td><span style="color:<?= $statusColor ?>;font-weight:600;"><?= e((string) $row['status']) ?></span></td>
                    <td><?= e(ff_backup_fmt_bytes(isset($row['file_size_bytes']) ? (int) $row['file_size_bytes'] : null)) ?></td>
                    <td><?= e((string) $by) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function FF_DropboxConfig() {
    return {
        appKey:   <?= json_encode($dropAppKey) ?>,   // app_key is non-sensitive
        appSecret: '',                                // NEVER pre-filled with the stored secret
        saving:   false,
        flash:    '',
        async saveKeys() {
            this.saving = true; this.flash = '';
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/settings/dropbox/save.php'), {
                    app_key:    this.appKey,
                    app_secret: this.appSecret,
                });
                if (r.success) {
                    window.location = <?= json_encode(base_url('settings?tab=backup') . '&flash_success=' . rawurlencode('Dropbox keys saved.')) ?>;
                } else {
                    this.flash = r.error?.message || 'Save failed.';
                    this.saving = false;
                }
            } catch (e) {
                this.flash = 'Network error. Could not reach the server.';
                this.saving = false;
            }
        },
        async disconnect() {
            if (!confirm('Disconnect Dropbox? The stored refresh token will be cleared.')) return;
            this.saving = true; this.flash = '';
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/settings/dropbox/disconnect.php'), {});
                if (r.success) {
                    window.location = <?= json_encode(base_url('settings?tab=backup') . '&flash_success=' . rawurlencode('Dropbox disconnected.')) ?>;
                } else {
                    this.flash = r.error?.message || 'Disconnect failed.';
                    this.saving = false;
                }
            } catch (e) {
                this.flash = 'Network error. Could not reach the server.';
                this.saving = false;
            }
        },
    };
}

function FF_ManualBackup() {
    return {
        runId:       <?= (int) $latestManualRunId ?>,
        status:      <?= json_encode($manualInProgress ? 'in_progress' : ($manualFull ? 'success' : 'idle')) ?>,
        downloadable: <?= $manualFull ? 'true' : 'false' ?>,
        downloadUrl: '',
        progressPct:  null,   // null → indeterminate/low bar, not 0%-stuck
        progressStage: '',
        working:     false,
        flash:       '',
        errorMsg:    '',
        _timer:      null,

        init() {
            if (this.runId > 0) {
                this.downloadUrl = FF_Api.url('/api/v1/settings/backup/download.php') + '?run_id=' + this.runId;
            }
            // Resume polling if a build is already running when the page loads.
            if (this.status === 'in_progress') this.startPolling();
        },

        async generate() {
            if (this.status === 'in_progress' || this.working) return;
            this.working = true; this.flash = ''; this.errorMsg = '';
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/settings/backup/enqueue.php'), {});
                if (r.success) {
                    this.runId  = r.data.run_id;
                    this.status = 'in_progress';
                    this.progressPct = null;
                    this.progressStage = 'Starting';
                    this.downloadUrl = FF_Api.url('/api/v1/settings/backup/download.php') + '?run_id=' + this.runId;
                    this.poll();          // immediate first read so the bar appears fast
                    this.startPolling();
                } else {
                    this.flash = r.error?.message || 'Could not start the backup.';
                }
            } catch (e) {
                this.flash = 'Network error. Could not reach the server.';
            }
            this.working = false;
        },

        startPolling() {
            if (this._timer) clearInterval(this._timer);
            this._timer = setInterval(() => this.poll(), 2000);
        },

        async poll() {
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/settings/backup/status.php') + '?run_id=' + this.runId);
                if (r.success) {
                    this.status       = r.data.status;
                    this.downloadable = !!r.data.downloadable;
                    this.progressPct  = (r.data.progress_pct === null || r.data.progress_pct === undefined) ? null : r.data.progress_pct;
                    this.progressStage = r.data.progress_stage || '';
                    if (this.status === 'success') {
                        this.downloadUrl = FF_Api.url('/api/v1/settings/backup/download.php') + '?run_id=' + this.runId;
                        clearInterval(this._timer); this._timer = null;
                    } else if (this.status === 'failed') {
                        this.errorMsg = r.data.error || 'Backup failed.';
                        clearInterval(this._timer); this._timer = null;
                    }
                }
            } catch (e) { /* transient — keep polling */ }
        },
    };
}
</script>

<?php if (!empty($_ff_standalone_backup)): ?>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
<?php endif; ?>
