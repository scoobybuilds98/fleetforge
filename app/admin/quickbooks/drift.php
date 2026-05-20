<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/drift.php
 *
 * Drift Detection admin view. Lists acc_qbo_drift_events rows with
 * filters + summary card row + per-row resolve flow (modal with
 * required resolution_note).
 *
 * Today (S-QBO-4) the only drift event source is the worker's
 * push-failed insert (S-QBO-3 cron/qbo_sync_worker.php). The full
 * drift-cron lands in S-QBO-24 (per-entity comparison populating
 * count_mismatch / field_mismatch / etc.).
 *
 * Empty-state safe — no rows yet is the expected state for this
 * session; page shows contextual messaging tied to S-QBO-24.
 *
 * Spec ref: §15.1 (drift dashboard structure), §15.4 (resolution workflows)
 * Session:  S-QBO-4 (drift table + page) → S-QBO-24 (drift cron) → S-QBO-25 (bulk resolve)
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Drift';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Drift</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Drift Detection</h1>
</div>

<div x-data="qboDrift()" x-init="init()">

    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Summary cards ───────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px;">
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Unresolved</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;" x-text="summary ? summary.unresolved : '—'"></div>
            <div class="text-secondary text-sm">open events</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Resolved (30d)</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;" x-text="summary ? summary.resolved_30d : '—'"></div>
            <div class="text-secondary text-sm">in the last 30 days</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Open by category</div>
            <div class="text-sm" style="margin-top:6px;" x-html="topCategoryHtml()"></div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Last detected</div>
            <div class="text-sm font-mono" style="margin-top:6px;" x-text="summary && summary.last_detected ? formatTs(summary.last_detected) : '—'"></div>
        </div>
    </div>

    <!-- ── Filters bar ─────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:grid;grid-template-columns:repeat(5,1fr);gap:12px;align-items:flex-end;">
        <div>
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" x-model="filters.status" @change="page=1; reload()">
                <option value="unresolved">Unresolved</option>
                <option value="resolved">Resolved</option>
                <option value="all">All</option>
            </select>
        </div>
        <div>
            <label class="form-label">Category</label>
            <select class="form-select form-select-sm" x-model="filters.category" @change="page=1; reload()">
                <option value="">All</option>
                <template x-for="c in categories" :key="c"><option :value="c" x-text="c"></option></template>
            </select>
        </div>
        <div>
            <label class="form-label">Entity</label>
            <select class="form-select form-select-sm" x-model="filters.entity" @change="page=1; reload()">
                <option value="">All</option>
                <template x-for="e in entityTypes" :key="e"><option :value="e" x-text="e"></option></template>
            </select>
        </div>
        <div>
            <label class="form-label">Source</label>
            <select class="form-select form-select-sm" x-model="filters.source" @change="page=1; reload()">
                <option value="">All</option>
                <option value="drift_cron">drift_cron</option>
                <option value="push_failure">push_failure</option>
                <option value="pull_failure">pull_failure</option>
                <option value="manual">manual</option>
            </select>
        </div>
        <div>
            <label class="form-label">Range</label>
            <select class="form-select form-select-sm" x-model="filters.range" @change="page=1; reload()">
                <option value="7">7d</option>
                <option value="30">30d</option>
                <option value="90">90d</option>
                <option value="all">All</option>
            </select>
        </div>
    </div>

    <!-- ── Rows table ──────────────────────────────────────── -->
    <div class="card" style="padding:0;">
        <template x-if="loading">
            <div style="padding:24px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Loading…</div>
        </template>

        <template x-if="!loading && rows.length === 0">
            <div style="padding:24px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">
                No drift events recorded. Drift detection cron lands in S-QBO-24; push-failure drift events start populating when sync turns on at S-QBO-30.
            </div>
        </template>

        <template x-if="!loading && rows.length > 0">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Detected</th>
                            <th>Category</th>
                            <th>Entity</th>
                            <th>QBO ID</th>
                            <th>FF / QBO</th>
                            <th class="text-right">Drift</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in rows" :key="row.id">
                            <tr>
                                <td class="text-sm font-mono" x-text="formatTs(row.detected_at)"></td>
                                <td><span class="badge badge-warning" x-text="row.category"></span></td>
                                <td class="text-sm" x-text="row.entity_type + (row.entity_id ? '#' + row.entity_id : '')"></td>
                                <td class="text-sm font-mono" x-text="row.qbo_entity_id || '—'"></td>
                                <td class="text-sm" x-text="ffQboValueDisplay(row)"></td>
                                <td class="text-right text-sm font-mono" x-text="row.drift_amount !== null ? row.drift_amount : '—'"></td>
                                <td class="text-sm" :title="row.description || ''" x-text="truncate(row.description, 40)"></td>
                                <td>
                                    <template x-if="row.resolved_at">
                                        <span class="badge badge-success" :title="(row.resolver_name || '#' + row.resolved_by_user_id) + ' · ' + formatTs(row.resolved_at) + ' · ' + (row.resolution_note || '')">Resolved</span>
                                    </template>
                                    <template x-if="!row.resolved_at">
                                        <span class="badge badge-danger">Open</span>
                                    </template>
                                </td>
                                <td class="text-right">
                                    <button x-show="!row.resolved_at" class="btn btn-primary btn-sm" @click="openResolveModal(row)">Resolve</button>
                                </td>
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

    <!-- ── Resolve modal ───────────────────────────────────── -->
    <div x-show="resolveTarget" x-cloak class="modal-overlay" style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);" @click.self="closeResolveModal()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;">Resolve drift event #<span x-text="resolveTarget ? resolveTarget.id : ''"></span></h3>
                <button class="modal-close" @click="closeResolveModal()">&times;</button>
            </div>
            <div class="modal-body">
                <template x-if="resolveTarget">
                    <div>
                        <div class="text-sm text-secondary" style="margin-bottom:10px;">
                            <strong x-text="resolveTarget.category"></strong> · <span x-text="resolveTarget.entity_type + '#' + resolveTarget.entity_id"></span> · detected <span x-text="formatTs(resolveTarget.detected_at)"></span>
                        </div>
                        <div class="text-sm" style="padding:10px;background:var(--bg-surface-2);border-radius:6px;margin-bottom:14px;" x-text="resolveTarget.description || '(no description)'"></div>

                        <label class="form-label">Resolution note <span style="color:var(--color-danger);">*</span></label>
                        <textarea class="form-input" rows="3" x-model="resolveNote" placeholder="Required. Min 5 chars. What did you do to resolve this? (e.g. 'Re-mapped customer to QBO #42 in mapping UI')"></textarea>
                        <p class="text-secondary text-sm" style="margin:4px 0 0;" x-show="resolveError" x-cloak x-text="resolveError"></p>
                    </div>
                </template>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                <button class="btn btn-secondary btn-sm" @click="closeResolveModal()" :disabled="resolving">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="confirmResolve()" :disabled="resolving || (resolveNote || '').trim().length < 5">
                    <span x-show="!resolving">Resolve</span>
                    <span x-show="resolving" x-cloak>Resolving…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function qboDrift() {
    return {
        rows: [], total: 0, page: 1, perPage: 25, totalPages: 1,
        loading: false,
        summary: null,
        flash: { message: '', type: 'success' },
        filters: { status: 'unresolved', category: '', entity: '', source: '', range: '30' },
        resolveTarget: null,
        resolveNote: '',
        resolveError: '',
        resolving: false,
        categories:  ['count_mismatch','field_mismatch','missing_in_qbo','missing_in_ff','amount_drift','balance_drift','push_failed','pull_failed','stale_object_unresolved'],
        entityTypes: ['customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code'],

        async init() { await this.reload(); },

        async reload() {
            this.loading = true;
            try {
                const qs = new URLSearchParams({
                    status:   this.filters.status,
                    category: this.filters.category,
                    entity:   this.filters.entity,
                    source:   this.filters.source,
                    range:    this.filters.range,
                    page:     this.page,
                    per_page: this.perPage,
                });
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/drift_list.php?' + qs.toString()), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const j = await r.json();
                if (j.success) {
                    const d = j.data;
                    this.rows       = d.rows;
                    this.total      = d.total;
                    this.totalPages = d.total_pages;
                    this.summary    = d.summary;
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Load failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally { this.loading = false; }
        },

        topCategoryHtml() {
            if (!this.summary || !this.summary.top_categories || this.summary.top_categories.length === 0) {
                return '<span class="text-secondary">No open events</span>';
            }
            return this.summary.top_categories.map(c => '<div>' + this.escHtml(c.n + ' · ' + c.category) + '</div>').join('');
        },

        escHtml(s) {
            return String(s).replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch]));
        },

        ffQboValueDisplay(row) {
            if (row.ff_value === null && row.qbo_value === null) return '—';
            const ff  = row.ff_value === null ? '∅' : String(row.ff_value);
            const qbo = row.qbo_value === null ? '∅' : String(row.qbo_value);
            return ff + ' / ' + qbo;
        },

        truncate(s, n) { if (!s) return '—'; return s.length > n ? s.slice(0, n - 1) + '…' : s; },

        formatTs(s) {
            if (!s) return '—';
            try { return new Date(s.replace(' ', 'T')).toLocaleString(); } catch { return s; }
        },

        openResolveModal(row) {
            this.resolveTarget = row;
            this.resolveNote   = '';
            this.resolveError  = '';
        },

        closeResolveModal() {
            this.resolveTarget = null;
            this.resolveNote   = '';
            this.resolveError  = '';
        },

        async confirmResolve() {
            if ((this.resolveNote || '').trim().length < 5) {
                this.resolveError = 'Resolution note must be at least 5 chars.';
                return;
            }
            this.resolving = true;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/drift_resolve.php'), {
                    id: this.resolveTarget.id,
                    resolution_note: this.resolveNote.trim(),
                });
                if (j.success) {
                    this.flash = { message: 'Drift event #' + this.resolveTarget.id + ' resolved.', type: 'success' };
                    this.closeResolveModal();
                    await this.reload();
                } else {
                    this.resolveError = (j.error && j.error.message) || 'Resolve failed.';
                }
            } catch (e) {
                this.resolveError = e.message || 'Network error';
            } finally { this.resolving = false; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
