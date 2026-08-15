<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/dashboard.php
 *
 * QuickBooks operational dashboard. Single-page Alpine view that
 * surfaces:
 *   - Connection status strip (compact)
 *   - 4 KPI cards (queue / drift / 24h activity / master)
 *   - 14-day activity chart (ApexCharts area, completed vs failed)
 *   - Recent activity feed (last 20 sync log entries)
 *   - Quick actions strip (Test Connection / Refresh Token / Sandbox link)
 *
 * Single dashboard_metrics.php roundtrip backs the entire view —
 * Alpine x-init fetches once, rebuilds the chart on payload change.
 *
 * Empty-state safe: when zero sync activity exists, the chart still
 * renders with 14 zero buckets, KPI cards show "0", and the recent
 * feed shows a contextual "No sync activity yet" message.
 *
 * Spec ref: §6.5 (sync log feed), §13.4 (error display), §15
 * Session:  S-QBO-4
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$canEditCredentials = can('quickbooks', 'edit_credentials');

$pageTitle = 'QuickBooks Dashboard';
// S-PERF-CHARTS: this page draws ApexCharts — opt in before header.php so
// footer.php emits the 522 KB chart bundle. Without this the charts are blank.
$pageNeedsCharts = true;
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">QuickBooks</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Dashboard</h1>
</div>

<div x-data="qboDashboard()" x-init="init()">

    <!-- ── Flash message strip ───────────────────────────────── -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ============================================================
         CONNECTION STATUS STRIP
         ============================================================ -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <template x-if="!data.connection">
            <span class="text-secondary text-sm">Loading…</span>
        </template>

        <template x-if="data.connection">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;width:100%;">
                <span :class="connectionBadgeClass" style="text-transform:capitalize;font-weight:600;" x-text="data.connection.status"></span>
                <span class="badge badge-info" style="text-transform:capitalize;" x-text="data.connection.environment"></span>

                <template x-if="data.connection.status === 'connected'">
                    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:0.8125rem;color:var(--text-secondary);">
                        <div>Realm <code x-text="data.connection.realm_id"></code></div>
                        <div>Last refresh <span x-text="formatTs(data.connection.last_token_refresh_at)"></span></div>
                        <div x-show="data.connection.refresh_token_expires_in_days !== null">
                            Token expires in <strong x-text="data.connection.refresh_token_expires_in_days"></strong> day(s)
                        </div>
                    </div>
                </template>

                <template x-if="data.connection.status === 'disconnected'">
                    <a href="<?= base_url('quickbooks/settings') ?>" class="btn btn-primary btn-sm" style="margin-left:auto;">Connect to QuickBooks →</a>
                </template>

                <template x-if="data.connection.status === 'error' || data.connection.status === 'expired'">
                    <a href="<?= base_url('quickbooks/settings') ?>" class="btn btn-secondary btn-sm" style="margin-left:auto;">View settings →</a>
                </template>
            </div>
        </template>
    </div>

    <template x-if="data.connection && data.connection.connection_error">
        <div class="alert alert-danger" style="margin-bottom:14px;">
            <strong>Connection error:</strong> <span x-text="data.connection.connection_error"></span>
        </div>
    </template>

    <!-- ============================================================
         KPI ROW (4 cards)
         ============================================================ -->
    <div class="kpi-grid kpi-grid--qbo">

        <!-- Card 1: Sync Queue -->
        <a href="<?= base_url('quickbooks/sync_queue') ?>" class="stat-card" style="text-decoration:none;color:inherit;padding:18px;">
            <div class="stat-card__header text-secondary text-sm">Sync Queue</div>
            <div class="stat-card__value" style="font-size:2rem;font-weight:600;margin:4px 0;" x-text="data.cards ? data.cards.sync_queue.queued : '—'"></div>
            <div class="text-secondary text-sm">queued items</div>
            <div class="text-secondary text-sm" style="margin-top:8px;font-size:0.75rem;" x-show="data.cards" x-cloak>
                <span x-text="data.cards.sync_queue.processing"></span> processing · <span x-text="data.cards.sync_queue.failed"></span> failed
            </div>
        </a>

        <!-- Card 2: Unresolved Drift -->
        <a href="<?= base_url('quickbooks/drift') ?>" class="stat-card" style="text-decoration:none;color:inherit;padding:18px;">
            <div class="stat-card__header text-secondary text-sm">Unresolved Drift</div>
            <div class="stat-card__value" style="font-size:2rem;font-weight:600;margin:4px 0;" x-text="data.cards ? data.cards.drift.unresolved : '—'"></div>
            <div class="text-secondary text-sm">open events</div>
            <div class="text-secondary text-sm" style="margin-top:8px;font-size:0.75rem;" x-show="data.cards" x-cloak>
                <span x-text="driftCategoryFoot()"></span>
            </div>
        </a>

        <!-- Card 3: Last 24h Activity -->
        <a href="<?= base_url('quickbooks/sync_log') ?>" class="stat-card" style="text-decoration:none;color:inherit;padding:18px;">
            <div class="stat-card__header text-secondary text-sm">Last 24h Activity</div>
            <div class="stat-card__value" style="font-size:2rem;font-weight:600;margin:4px 0;" x-text="data.cards ? data.cards.activity_24h.total : '—'"></div>
            <div class="text-secondary text-sm">API calls</div>
            <div class="text-secondary text-sm" style="margin-top:8px;font-size:0.75rem;" x-show="data.cards" x-cloak>
                <span x-text="data.cards.activity_24h.ok"></span> ok · <span x-text="data.cards.activity_24h.errors"></span> errors
            </div>
        </a>

        <!-- Card 4: Master Sync Status -->
        <a href="<?= base_url('quickbooks/settings') ?>" class="stat-card" style="text-decoration:none;color:inherit;padding:18px;">
            <div class="stat-card__header text-secondary text-sm">Master Sync</div>
            <div class="stat-card__value" style="font-size:2rem;font-weight:600;margin:4px 0;"
                 :class="data.cards && data.cards.master.sync_enabled === '1' ? 'text-success' : 'text-secondary'"
                 x-text="data.cards ? (data.cards.master.sync_enabled === '1' ? 'ON' : 'OFF') : '—'"></div>
            <div class="text-secondary text-sm" x-text="masterSubLabel()"></div>
            <div class="text-secondary text-sm" style="margin-top:8px;font-size:0.75rem;">Cutover scheduled: S-QBO-30</div>
        </a>
    </div>

    <!-- ============================================================
         14-DAY ACTIVITY CHART
         ============================================================ -->
    <div class="card" style="padding:18px;margin-bottom:14px;">
        <h3 class="h6" style="margin:0 0 12px;">Last 14 Days — Sync Activity</h3>
        <div style="position:relative;">
            <div id="chart-qbo-activity" style="min-height:280px;"></div>
            <!-- Empty-state overlay — only shown when 14d total = 0 -->
            <div x-show="isEmptyChart()" x-cloak
                 style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;">
                <div class="text-secondary text-sm" style="background:var(--bg-surface);padding:8px 14px;border-radius:6px;border:1px solid var(--border-color);">
                    No activity yet — sync turns on at S-QBO-30
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         RECENT ACTIVITY FEED (last 20)
         ============================================================ -->
    <div class="card" style="padding:0;margin-bottom:14px;">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);">
            <h3 class="h6" style="margin:0;">Recent Activity</h3>
        </div>

        <template x-if="data.recent && data.recent.length === 0">
            <div style="padding:24px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">
                No sync activity yet. Pushers ship in S-QBO-5+. The sync log will start populating then.
            </div>
        </template>

        <template x-if="data.recent && data.recent.length > 0">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Direction</th>
                            <th>Entity</th>
                            <th>Op</th>
                            <th>Status</th>
                            <th class="text-right">Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in data.recent" :key="row.id">
                            <tr style="cursor:pointer;" @click="openLogDetail(row.id)">
                                <td class="text-secondary text-sm font-mono" x-text="formatTs(row.created_at)"></td>
                                <td><span class="badge" :class="row.direction === 'push' ? 'badge-info' : 'badge-neutral'" x-text="row.direction"></span></td>
                                <td class="text-sm" x-text="row.entity_type + '#' + row.entity_id"></td>
                                <td class="text-sm" x-text="row.operation"></td>
                                <td><span class="badge" :class="statusBadgeClass(row)" x-text="statusLabel(row)"></span></td>
                                <td class="text-right text-sm font-mono" x-text="(row.duration_ms ?? 0) + ' ms'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <!-- ============================================================
         QUICK ACTIONS STRIP
         ============================================================ -->
    <div class="card" style="padding:18px;">
        <h3 class="h6" style="margin:0 0 12px;">Quick Actions</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-secondary btn-sm" @click="testConnection()" :disabled="testing">
                <span x-show="!testing">Test Connection</span>
                <span x-show="testing" x-cloak>Testing…</span>
            </button>

            <?php if ($canEditCredentials): ?>
            <button class="btn btn-secondary btn-sm" @click="refreshTokenNow()" :disabled="refreshing">
                <span x-show="!refreshing">Refresh Token Now</span>
                <span x-show="refreshing" x-cloak>Refreshing…</span>
            </button>
            <?php endif; ?>

            <template x-if="data.connection && data.connection.environment === 'sandbox'">
                <a href="https://app.sandbox.qbo.intuit.com" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                    Open Sandbox in Intuit ↗
                </a>
            </template>
        </div>

        <!-- Inline result panel for Test Connection -->
        <div x-show="testResult" x-cloak style="margin-top:12px;">
            <div :class="testResult && testResult.success ? 'alert alert-success' : 'alert alert-danger'">
                <template x-if="testResult && testResult.success">
                    <div><strong>Connected.</strong> Company: <span x-text="testResult.company_name"></span> — Realm <code x-text="testResult.realm_id"></code></div>
                </template>
                <template x-if="testResult && !testResult.success">
                    <div><strong>Test failed:</strong> <span x-text="testResult.error"></span></div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function qboDashboard() {
    return {
        data:        { connection: null, cards: null, chart: null, recent: null },
        flash:       { message: '', type: 'success' },
        testing:     false,
        refreshing:  false,
        testResult:  null,
        chart:       null,

        get connectionBadgeClass() {
            if (!this.data.connection) return 'badge badge-neutral';
            return {
                connected:    'badge badge-success',
                disconnected: 'badge badge-neutral',
                expired:      'badge badge-danger',
                error:        'badge badge-danger',
            }[this.data.connection.status] || 'badge badge-neutral';
        },

        async init() {
            await this.loadMetrics();
            this.renderChart();
        },

        async loadMetrics() {
            try {
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/dashboard_metrics.php'), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const j = await r.json();
                if (j.success) {
                    this.data = {
                        connection: j.data ? j.data.connection : j.connection,
                        cards:      j.data ? j.data.cards      : j.cards,
                        chart:      j.data ? j.data.chart      : j.chart,
                        recent:     j.data ? j.data.recent     : j.recent,
                    };
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Failed to load metrics.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        renderChart() {
            const el = document.getElementById('chart-qbo-activity');
            if (!el || !this.data.chart || typeof ApexCharts === 'undefined') return;

            const css = getComputedStyle(document.documentElement);
            // Deliberate semantic colours: completed=success (green), failed=danger (red).
            const cSuccess = css.getPropertyValue('--color-success').trim() || '#22c55e';
            const cDanger  = css.getPropertyValue('--color-danger').trim()  || '#ef4444';

            const opts = {
                chart:    { type: 'area', height: 280 },
                colors:   [cSuccess, cDanger],
                xaxis:    { categories: this.data.chart.labels },
                legend:   { position: 'top', horizontalAlign: 'right' },
                series:   this.data.chart.series,
            };

            this.chart = new ApexCharts(el, FF_CHART_THEME(opts));
            this.chart.render();
        },

        isEmptyChart() {
            if (!this.data.chart) return false;
            return this.data.chart.series.every(s => s.data.every(v => v === 0));
        },

        driftCategoryFoot() {
            if (!this.data.cards) return '';
            const map = this.data.cards.drift.by_category || {};
            const keys = Object.keys(map);
            if (keys.length === 0) return '—';
            return keys.map(k => map[k] + ' ' + k).join(' · ');
        },

        masterSubLabel() {
            if (!this.data.cards) return '';
            if (this.data.cards.master.dry_run_mode === '1') return 'DRY RUN MODE';
            return this.data.cards.master.environment === 'production' ? 'Production' : 'Sandbox';
        },

        statusBadgeClass(row) {
            const s = row.response_status;
            if (s === null || s === undefined) return 'badge badge-neutral';
            if (s >= 200 && s < 300) return 'badge badge-success';
            return 'badge badge-danger';
        },

        statusLabel(row) {
            if (row.response_status === null || row.response_status === undefined) return row.error_code || '—';
            if (row.response_status >= 200 && row.response_status < 300) return 'OK';
            return (row.response_status + (row.error_code ? ' · ' + row.error_code : ''));
        },

        formatTs(s) {
            if (!s) return '—';
            try {
                const d = new Date(s.replace(' ', 'T'));
                return d.toLocaleString();
            } catch { return s; }
        },

        openLogDetail(id) {
            window.location.href = '<?= base_url('quickbooks/sync_log') ?>?id=' + id;
        },

        async testConnection() {
            this.testing = true; this.testResult = null;
            try {
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/test_connection.php'), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const j = await r.json();
                this.testResult = j.data || j;
            } catch (e) {
                this.testResult = { success: false, error: e.message || 'Network error' };
            } finally {
                this.testing = false;
            }
        },

        async refreshTokenNow() {
            this.refreshing = true; this.testResult = null;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/refresh_token_now.php'), {});
                if (j.success) {
                    this.flash = { message: 'Token refreshed.', type: 'success' };
                    await this.loadMetrics(); // reflect new expiry
                } else {
                    this.flash = { message: (j.error && j.error.message) || j.error || 'Refresh failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.refreshing = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
