<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/sync_log.php
 *
 * Sync Log admin view. Lists every QBO API call from
 * acc_qbo_sync_log with filters (direction / entity / status class
 * / error code / date range / free text) + row-click detail modal.
 *
 * Detail modal payload gating: full request/response JSON is shown
 * only when caller holds `quickbooks.view_raw_payloads`; otherwise
 * the payload sections render a [REDACTED] notice (server enforces
 * this in sync_log_detail.php — page is just a thin display layer).
 *
 * URL parameter support:
 *   ?id={n}        auto-opens the detail modal on load (used by
 *                  Dashboard's recent feed + Sync Queue's "View Log")
 *   ?queue_id={n}  pre-filters to rows from a single queue row
 *
 * Empty-state safe: when zero rows match the filters, shows a
 * contextual "No sync log entries yet — Pushers ship in S-QBO-5+"
 * message instead of an empty table.
 *
 * Spec ref: §6.5 (sync log table), §13.4 (operator-visible error display)
 * Session:  S-QBO-4
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$canViewPayloads = can('quickbooks', 'view_raw_payloads');

$pageTitle = 'QuickBooks Sync Log';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Sync Log</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Sync Log</h1>
</div>

<div x-data="qboSyncLog()">

    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Filters bar ─────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
            <div>
                <label class="form-label">Direction</label>
                <select class="form-select form-select-sm" x-model="filters.direction" @change="reload()">
                    <option value="">All</option><option value="push">Push</option><option value="pull">Pull</option>
                </select>
            </div>
            <div>
                <label class="form-label">Entity</label>
                <select class="form-select form-select-sm" x-model="filters.entity" @change="reload()">
                    <option value="">All</option>
                    <template x-for="e in entityTypes" :key="e"><option :value="e" x-text="e"></option></template>
                </select>
            </div>
            <div>
                <label class="form-label">Status</label>
                <select class="form-select form-select-sm" x-model="filters.status" @change="reload()">
                    <option value="">All</option>
                    <option value="success">Success (2xx)</option>
                    <option value="client_error">Client error (4xx)</option>
                    <option value="server_error">Server error (5xx / transport)</option>
                </select>
            </div>
            <div>
                <label class="form-label">Date range</label>
                <select class="form-select form-select-sm" x-model="filters.range" @change="applyRange()">
                    <option value="7">Last 7 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="90">Last 90 days</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:10px;">
            <div>
                <label class="form-label">Error code</label>
                <input type="text" class="form-input form-input-sm" x-model="filters.error_code" @keyup.enter="reload()" placeholder="contains…">
            </div>
            <div style="grid-column:span 2;">
                <label class="form-label">Free text</label>
                <input type="text" class="form-input form-input-sm" x-model="filters.q" @keyup.enter="reload()" placeholder="message / endpoint / entity_id / qbo_entity_id">
            </div>
            <div>
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary btn-sm" @click="reload()" style="width:100%;">Apply filters</button>
            </div>
        </div>
        <div x-show="filters.range === 'custom'" x-cloak style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:10px;">
            <div>
                <label class="form-label">From</label>
                <input type="date" class="form-input form-input-sm" x-model="filters.date_from">
            </div>
            <div>
                <label class="form-label">To</label>
                <input type="date" class="form-input form-input-sm" x-model="filters.date_to">
            </div>
            <div style="grid-column:span 2;align-self:end;">
                <button class="btn btn-secondary btn-sm" @click="reload()">Apply date range</button>
            </div>
        </div>
    </div>

    <!-- ── Rows table ──────────────────────────────────────── -->
    <div class="card" style="padding:0;">
        <template x-if="loading">
            <div style="padding:24px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Loading…</div>
        </template>

        <template x-if="!loading && rows.length === 0">
            <div style="padding:24px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">
                No sync log entries match the filters. Pushers ship in S-QBO-5+; this view will populate then.
            </div>
        </template>

        <template x-if="!loading && rows.length > 0">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Direction</th>
                            <th>Entity</th>
                            <th>QBO ID</th>
                            <th>Op</th>
                            <th>HTTP</th>
                            <th>Endpoint</th>
                            <th>Status</th>
                            <th class="text-right">Duration</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in rows" :key="row.id">
                            <tr style="cursor:pointer;" @click="openDetail(row.id)">
                                <td class="text-sm font-mono" x-text="formatTs(row.created_at)"></td>
                                <td><span class="badge" :class="row.direction === 'push' ? 'badge-info' : 'badge-neutral'" x-text="row.direction"></span></td>
                                <td class="text-sm" x-text="row.entity_type + (row.entity_id ? '#' + row.entity_id : '')"></td>
                                <td class="text-sm font-mono" x-text="row.qbo_entity_id || '—'"></td>
                                <td class="text-sm" x-text="row.operation"></td>
                                <td class="text-sm font-mono" x-text="row.http_method"></td>
                                <td class="text-sm" :title="row.endpoint" x-text="truncate(row.endpoint, 30)"></td>
                                <td><span class="badge" :class="statusBadgeClass(row.response_status)" x-text="row.response_status || '—'"></span></td>
                                <td class="text-right text-sm font-mono" x-text="(row.duration_ms ?? 0) + ' ms'"></td>
                                <td class="text-sm" x-text="row.error_code || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <div x-show="total > 0" x-cloak style="padding:12px 18px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border-color);">
            <span class="text-secondary text-sm" x-text="'Page ' + page + ' of ' + totalPages + ' · ' + total + ' total'"></span>
            <div style="display:flex;gap:6px;">
                <button class="btn btn-secondary btn-sm" :disabled="page<=1" @click="page--; reload()">← Prev</button>
                <button class="btn btn-secondary btn-sm" :disabled="page>=totalPages" @click="page++; reload()">Next →</button>
            </div>
        </div>
    </div>

    <!-- ── Detail modal ────────────────────────────────────── -->
    <div x-show="detailOpen" x-cloak class="modal-overlay" style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);" @click.self="closeDetail()">
        <div class="modal modal-lg" style="max-height:90vh;overflow:auto;">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;">Sync Log #<span x-text="detail ? detail.id : '—'"></span></h3>
                <button class="modal-close" @click="closeDetail()">&times;</button>
            </div>

            <div class="modal-body">
                <template x-if="!detail">
                    <div class="text-secondary">Loading…</div>
                </template>

                <template x-if="detail">
                    <div>
                        <!-- Outcome strip -->
                        <div class="card" style="padding:14px;margin-bottom:14px;background:var(--bg-surface-2);">
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:0.875rem;">
                                <div><strong>Time:</strong> <span class="font-mono" x-text="formatTs(detail.created_at)"></span></div>
                                <div><strong>Direction:</strong> <span x-text="detail.direction"></span></div>
                                <div><strong>Operation:</strong> <span x-text="detail.operation"></span></div>
                                <div><strong>Entity:</strong> <span x-text="detail.entity_type + (detail.entity_id ? '#' + detail.entity_id : '')"></span></div>
                                <div><strong>QBO ID:</strong> <span class="font-mono" x-text="detail.qbo_entity_id || '—'"></span></div>
                                <div><strong>Duration:</strong> <span class="font-mono" x-text="(detail.duration_ms ?? 0) + ' ms'"></span></div>
                                <div><strong>HTTP Status:</strong> <span x-text="detail.response_status || '—'"></span></div>
                                <div><strong>Error code:</strong> <span x-text="detail.error_code || '—'"></span></div>
                                <div><strong>Queue ID:</strong>
                                    <a x-show="detail.queue_id" :href="'<?= base_url('quickbooks/sync_queue') ?>?queue_id=' + detail.queue_id" x-text="'#' + detail.queue_id"></a>
                                    <span x-show="!detail.queue_id">—</span>
                                </div>
                                <div><strong>Realm:</strong> <span class="font-mono text-sm" x-text="detail.realm_id"></span></div>
                                <div><strong>Environment:</strong> <span x-text="detail.environment"></span></div>
                            </div>
                            <div x-show="detail.error_message" x-cloak style="margin-top:10px;padding:10px;background:var(--color-danger-light);border-radius:6px;">
                                <strong>Error message:</strong>
                                <pre style="margin:6px 0 0;white-space:pre-wrap;font-size:0.8125rem;" x-text="detail.error_message"></pre>
                            </div>
                        </div>

                        <!-- Request panel -->
                        <h4 class="h6" style="margin:0 0 6px;">Request</h4>
                        <div class="card" style="padding:14px;margin-bottom:14px;">
                            <div class="text-sm" style="margin-bottom:8px;">
                                <span class="badge badge-info" x-text="detail.http_method"></span>
                                <code style="margin-left:6px;font-size:0.8125rem;" x-text="detail.endpoint"></code>
                            </div>
                            <?php if ($canViewPayloads): ?>
                            <pre style="margin:0;padding:10px;background:var(--bg-body);border-radius:6px;max-height:300px;overflow:auto;font-size:0.75rem;white-space:pre-wrap;" x-text="prettyJson(detail.request_payload)"></pre>
                            <?php else: ?>
                            <div class="alert alert-warning text-sm" style="margin:0;">
                                Payload redacted — requires <code>quickbooks.view_raw_payloads</code> permission. See your administrator.
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Response panel -->
                        <h4 class="h6" style="margin:0 0 6px;">Response</h4>
                        <div class="card" style="padding:14px;">
                            <div class="text-sm" style="margin-bottom:8px;">
                                <span class="badge" :class="statusBadgeClass(detail.response_status)" x-text="detail.response_status || 'no response'"></span>
                            </div>
                            <?php if ($canViewPayloads): ?>
                            <pre style="margin:0;padding:10px;background:var(--bg-body);border-radius:6px;max-height:300px;overflow:auto;font-size:0.75rem;white-space:pre-wrap;" x-text="prettyJson(detail.response_payload)"></pre>
                            <?php else: ?>
                            <div class="alert alert-warning text-sm" style="margin:0;">
                                Payload redacted — requires <code>quickbooks.view_raw_payloads</code> permission. See your administrator.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function qboSyncLog() {
    return {
        rows: [], total: 0, page: 1, perPage: 50, totalPages: 1,
        loading: false,
        detailOpen: false,
        detail: null,
        flash: { message: '', type: 'success' },
        filters: {
            direction:  '',
            entity:     '',
            status:     '',
            error_code: '',
            q:          '',
            range:      '7',
            date_from:  '',
            date_to:    '',
            queue_id:   0,
        },
        entityTypes: ['customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code','companyinfo','query'],

        async init() {
            // URL params: ?id={n} auto-opens detail; ?queue_id={n} pre-filters
            const url = new URL(window.location.href);
            const idParam      = url.searchParams.get('id');
            const queueIdParam = url.searchParams.get('queue_id');
            if (queueIdParam) this.filters.queue_id = parseInt(queueIdParam, 10) || 0;

            this.applyRange();
            await this.reload();

            if (idParam) {
                this.openDetail(parseInt(idParam, 10));
            }
        },

        applyRange() {
            if (this.filters.range === 'custom') return;
            const days = parseInt(this.filters.range, 10) || 7;
            const today = new Date();
            const from  = new Date(today.getTime() - days * 86400000);
            this.filters.date_from = from.toISOString().slice(0, 10);
            this.filters.date_to   = today.toISOString().slice(0, 10);
        },

        async reload() {
            this.loading = true;
            try {
                const qs = new URLSearchParams({
                    direction:  this.filters.direction,
                    entity:     this.filters.entity,
                    status:     this.filters.status,
                    error_code: this.filters.error_code,
                    q:          this.filters.q,
                    date_from:  this.filters.date_from,
                    date_to:    this.filters.date_to,
                    page:       this.page,
                    per_page:   this.perPage,
                });
                if (this.filters.queue_id > 0) qs.set('queue_id', String(this.filters.queue_id));
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/sync_log_search.php?' + qs.toString()), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const j = await r.json();
                if (j.success) {
                    this.rows       = j.data.rows;
                    this.total      = j.data.total;
                    this.totalPages = j.data.total_pages;
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Load failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally { this.loading = false; }
        },

        async openDetail(id) {
            this.detailOpen = true;
            this.detail = null;
            try {
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/sync_log_detail.php?id=' + id), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const j = await r.json();
                if (j.success) {
                    this.detail = j.data.row;
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Detail load failed.', type: 'error' };
                    this.closeDetail();
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
                this.closeDetail();
            }
        },

        closeDetail() {
            this.detailOpen = false;
            this.detail = null;
            // Strip ?id= from URL so refresh doesn't re-open
            const url = new URL(window.location.href);
            if (url.searchParams.has('id')) {
                url.searchParams.delete('id');
                window.history.replaceState({}, '', url.toString());
            }
        },

        statusBadgeClass(s) {
            if (s === null || s === undefined) return 'badge badge-neutral';
            if (s >= 200 && s < 300) return 'badge badge-success';
            if (s >= 400 && s < 500) return 'badge badge-warning';
            return 'badge badge-danger';
        },

        truncate(s, n) {
            if (!s) return '—';
            return s.length > n ? s.slice(0, n - 1) + '…' : s;
        },

        formatTs(s) {
            if (!s) return '—';
            try { return new Date(s.replace(' ', 'T')).toLocaleString(); } catch { return s; }
        },

        prettyJson(s) {
            if (s === null || s === undefined || s === '') return '(empty)';
            if (typeof s === 'string' && s.startsWith('[REDACTED')) return s;
            try {
                const parsed = (typeof s === 'string') ? JSON.parse(s) : s;
                return JSON.stringify(parsed, null, 2);
            } catch { return String(s); }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
