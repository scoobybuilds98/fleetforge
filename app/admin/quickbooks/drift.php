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

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
        <h1 class="page-header-title h4">QuickBooks — Drift Detection</h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            FF↔QBO divergence safety net (S-QBO-24). Nightly cron <code>qbo_drift_check.php</code> at 03:30 +
            on-demand via the button. Snapshot layer (push-failed + FF-side amount drift) runs pre-cutover with no
            QBO calls; the live layer (missing-in-FF / live amount drift) activates when sync is enabled at S-QBO-30.
        </div>
    </div>
    <?php if (can('quickbooks', 'edit_credentials')): ?>
    <div x-data="qboDriftRunNow()" style="text-align:right;">
        <button class="btn btn-primary btn-sm" @click="run()" :disabled="running">
            <span x-show="!running">Run drift check now</span>
            <span x-show="running" x-cloak>Running…</span>
        </button>
        <div class="text-xs text-secondary" style="margin-top:6px;" x-show="lastResult" x-cloak x-text="lastResult"></div>
    </div>
    <?php endif; ?>
</div>

<div x-data="qboDrift({ canEditCreds: <?= can('quickbooks', 'edit_credentials') ? 'true' : 'false' ?> })" x-init="init()">

    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Summary cards ───────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo">
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
                <option value="unresolved">Unresolved (open)</option>
                <option value="resolved">Resolved</option>
                <option value="accepted">Accepted</option>
                <option value="suppressed">Suppressed</option>
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

    <?php if (can('quickbooks', 'edit_credentials')): ?>
    <!-- ── Bulk-by-category bar (S-QBO-25 / D-QBO-25-3) ────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:grid;grid-template-columns:1.4fr 1fr 1fr 0.8fr;gap:12px;align-items:flex-end;">
        <div>
            <label class="form-label">Bulk resolve by category</label>
            <select class="form-select form-select-sm" x-model="bulk.category">
                <option value="">Select category…</option>
                <template x-for="c in categories" :key="c"><option :value="c" x-text="c"></option></template>
            </select>
        </div>
        <div>
            <label class="form-label">Entity type (optional)</label>
            <select class="form-select form-select-sm" x-model="bulk.entity">
                <option value="">All entity types</option>
                <template x-for="e in entityTypes" :key="e"><option :value="e" x-text="e"></option></template>
            </select>
        </div>
        <div>
            <label class="form-label">Action</label>
            <select class="form-select form-select-sm" x-model="bulk.action">
                <option value="accept">Accept all open</option>
                <option value="suppress">Suppress all open</option>
            </select>
        </div>
        <div style="text-align:right;">
            <button class="btn btn-secondary btn-sm" :disabled="!bulk.category || bulk.running" @click="openBulkModal()">
                <span x-show="!bulk.running">Apply…</span>
                <span x-show="bulk.running" x-cloak>Running…</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

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
                            <th>State</th>
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
                                    <span class="badge" :class="stateBadge(row).cls" :title="stateTooltip(row)" x-text="stateBadge(row).label"></span>
                                </td>
                                <td class="text-right" style="white-space:nowrap;">
                                    <!-- Resolve stays view-gated per S-QBO-4. Other actions require edit_credentials. -->
                                    <a :href="driftShowUrl(row.id)" class="btn btn-link btn-xs">View</a>
                                    <button x-show="!row.resolved_at" class="btn btn-primary btn-sm" @click="openActionModal(row, 'resolve')">Resolve</button>
                                    <template x-if="canEditCreds">
                                        <span>
                                            <button x-show="!row.resolved_at && row.category === 'push_failed' && row.entity_id" class="btn btn-secondary btn-sm" title="Re-enqueue the FF entity via its Pusher; drift auto-resolves on the next parity check." @click="resync(row)">Re-sync</button>
                                            <button x-show="!row.resolved_at" class="btn btn-secondary btn-sm" title="Accept this divergence as intentional (terminal — never re-flagged)." @click="openActionModal(row, 'accept')">Accept</button>
                                            <button x-show="!row.resolved_at" class="btn btn-secondary btn-sm" title="Suppress this drift as a known false-positive (terminal + hidden by default)." @click="openActionModal(row, 'suppress')">Suppress</button>
                                            <button x-show="row.resolved_at" class="btn btn-secondary btn-sm" title="Reopen this event — clears resolution and re-enters the open pool." @click="openActionModal(row, 'reopen')">Reopen</button>
                                        </span>
                                    </template>
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

    <!-- ── Action modal (resolve / accept / suppress / reopen) ───── -->
    <div x-show="actionTarget" x-cloak class="modal-overlay" style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);" @click.self="closeActionModal()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;"><span x-text="actionTitle()"></span> drift event #<span x-text="actionTarget ? actionTarget.id : ''"></span></h3>
                <button class="modal-close" @click="closeActionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <template x-if="actionTarget">
                    <div>
                        <div class="text-sm text-secondary" style="margin-bottom:10px;">
                            <strong x-text="actionTarget.category"></strong> · <span x-text="actionTarget.entity_type + (actionTarget.entity_id ? '#' + actionTarget.entity_id : '')"></span> · detected <span x-text="formatTs(actionTarget.detected_at)"></span>
                        </div>
                        <div class="text-sm" style="padding:10px;background:var(--bg-surface-2);border-radius:6px;margin-bottom:14px;" x-text="actionTarget.description || '(no description)'"></div>
                        <div class="text-sm text-secondary" style="margin-bottom:8px;" x-text="actionHint()"></div>

                        <label class="form-label"><span x-text="actionType === 'reopen' ? 'Reopen note' : 'Resolution note'"></span> <span style="color:var(--color-danger);">*</span></label>
                        <textarea class="form-input" rows="3" x-model="actionNote" :placeholder="actionPlaceholder()"></textarea>
                        <p class="text-secondary text-sm" style="margin:4px 0 0;" x-show="actionError" x-cloak x-text="actionError"></p>
                    </div>
                </template>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                <button class="btn btn-secondary btn-sm" @click="closeActionModal()" :disabled="actionRunning">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="confirmAction()" :disabled="actionRunning || (actionNote || '').trim().length < 5">
                    <span x-show="!actionRunning" x-text="actionTitle()"></span>
                    <span x-show="actionRunning" x-cloak>Working…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Bulk-action confirm modal ─────────────────────────────── -->
    <div x-show="bulk.confirm" x-cloak class="modal-overlay" style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);" @click.self="closeBulkModal()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;">Bulk <span x-text="bulk.action"></span></h3>
                <button class="modal-close" @click="closeBulkModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-sm" style="margin-bottom:14px;">
                    This will <strong x-text="bulk.action"></strong> <em>every OPEN drift event</em> of category
                    <code x-text="bulk.category"></code><span x-show="bulk.entity"> for entity type <code x-text="bulk.entity"></code></span>.
                    The action is terminal — <code x-text="bulk.action === 'accept' ? 'accepted' : 'suppressed'"></code> events are
                    never re-flagged by the drift cron. Reopen per-event if needed.
                </div>

                <label class="form-label">Resolution note (applied to every event) <span style="color:var(--color-danger);">*</span></label>
                <textarea class="form-input" rows="3" x-model="bulk.note" placeholder="Required. Min 5 chars. Why is this bulk action correct? (e.g. 'Audit confirmed all push_failed are pre-cutover ghosts')"></textarea>
                <p class="text-secondary text-sm" style="margin:4px 0 0;" x-show="bulk.error" x-cloak x-text="bulk.error"></p>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                <button class="btn btn-secondary btn-sm" @click="closeBulkModal()" :disabled="bulk.running">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="confirmBulk()" :disabled="bulk.running || (bulk.note || '').trim().length < 5">
                    <span x-show="!bulk.running">Apply bulk <span x-text="bulk.action"></span></span>
                    <span x-show="bulk.running" x-cloak>Working…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function qboDrift(opts) {
    opts = opts || {};
    return {
        rows: [], total: 0, page: 1, perPage: 25, totalPages: 1,
        loading: false,
        summary: null,
        flash: { message: '', type: 'success' },
        filters: { status: 'unresolved', category: '', entity: '', source: '', range: '30' },
        // S-QBO-25: per-event action modal (resolve/accept/suppress/reopen).
        // resync uses a separate inline flow (no note required, no modal).
        actionTarget: null,
        actionType: 'resolve',
        actionNote: '',
        actionError: '',
        actionRunning: false,
        // S-QBO-25: bulk-by-category action state.
        bulk: { category: '', entity: '', action: 'accept', note: '', confirm: false, error: '', running: false },
        // Permission flag from PHP — gates non-resolve buttons on the row.
        canEditCreds: !!opts.canEditCreds,
        categories:  ['count_mismatch','field_mismatch','missing_in_qbo','missing_in_ff','amount_drift','balance_drift','push_failed','pull_failed','stale_object_unresolved'],
        entityTypes: ['customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code'],

        driftShowUrl(id) {
            return FF_Api.url('/quickbooks/drift_show?id=' + id);
        },

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

        // S-QBO-25: resolution-state badge — maps the four UI states to
        // semantic CSS classes. Open events get danger (operator-actionable);
        // resolved is success; accepted is info (intentional divergence);
        // suppressed is warning (hidden by default — yellow signals "noisy
        // but ignored").
        stateBadge(row) {
            if (!row.resolved_at) return { cls: 'badge-danger', label: 'Open' };
            const rt = row.resolution_type || 'resolved';
            if (rt === 'resolved')   return { cls: 'badge-success', label: 'Resolved' };
            if (rt === 'accepted')   return { cls: 'badge-info',    label: 'Accepted' };
            if (rt === 'suppressed') return { cls: 'badge-warning', label: 'Suppressed' };
            return { cls: 'badge-secondary', label: rt };
        },
        stateTooltip(row) {
            if (!row.resolved_at) return 'Open — operator-actionable.';
            const who = row.resolver_name || ('#' + row.resolved_by_user_id);
            const when = this.formatTs(row.resolved_at);
            const note = row.resolution_note ? ' · ' + row.resolution_note : '';
            return who + ' · ' + when + note;
        },

        // S-QBO-25: action modal (resolve/accept/suppress/reopen).
        openActionModal(row, type) {
            this.actionTarget  = row;
            this.actionType    = type;
            this.actionNote    = '';
            this.actionError   = '';
        },
        closeActionModal() {
            this.actionTarget  = null;
            this.actionNote    = '';
            this.actionError   = '';
        },
        actionTitle() {
            return { resolve:'Resolve', accept:'Accept', suppress:'Suppress', reopen:'Reopen' }[this.actionType] || 'Update';
        },
        actionHint() {
            return ({
                resolve:  'Records the resolution + your note. Drift will re-flag if it recurs.',
                accept:   'Marks the divergence as intentional. TERMINAL — recordDrift will never re-flag.',
                suppress: 'Marks the event as a known false-positive. TERMINAL + hidden from the default view.',
                reopen:   'Clears resolution and re-enters the open pool. Auto-resolves on next parity if drift is gone.',
            })[this.actionType] || '';
        },
        actionPlaceholder() {
            return ({
                resolve:  "Required. Min 5 chars. What did you do to fix this? (e.g. 'Re-mapped customer to QBO #42 in mapping UI')",
                accept:   "Required. Min 5 chars. Why is this divergence intentional? (e.g. 'Customer is FF-only per tax counsel — never pushed to QBO')",
                suppress: "Required. Min 5 chars. Why is this a known false-positive? (e.g. 'Sub-cent tax-rounding within accepted tolerance')",
                reopen:   "Required. Min 5 chars. Why are you reopening this? (e.g. 'Original suppress was premature — drift still present')",
            })[this.actionType] || 'Required. Min 5 chars.';
        },
        async confirmAction() {
            if ((this.actionNote || '').trim().length < 5) {
                this.actionError = 'Resolution note must be at least 5 chars.';
                return;
            }
            this.actionRunning = true;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/drift_resolve.php'), {
                    id: this.actionTarget.id,
                    action: this.actionType,
                    resolution_note: this.actionNote.trim(),
                });
                if (j.success) {
                    this.flash = { message: 'Drift event #' + this.actionTarget.id + ' ' + this.actionTitle().toLowerCase() + 'd.', type: 'success' };
                    this.closeActionModal();
                    await this.reload();
                } else {
                    this.actionError = (j.error && j.error.message) || (this.actionTitle() + ' failed.');
                }
            } catch (e) {
                this.actionError = e.message || 'Network error';
            } finally { this.actionRunning = false; }
        },

        // S-QBO-25: per-row resync — no modal, no note required. The act of
        // re-enqueueing is the audit trail; the drift event auto-resolves
        // on the next parity check if the push succeeds.
        async resync(row) {
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/drift_resolve.php'), {
                    id: row.id, action: 'resync',
                });
                if (j.success) {
                    const d = j.data || {};
                    const action = d.action || 'resync';
                    if (action === 'resync_enqueued') {
                        this.flash = { message: 'Re-enqueued ' + (d.entity_type || row.entity_type) + '#' + (d.entity_id || row.entity_id) + ' — will auto-resolve on next parity check.', type: 'success' };
                    } else {
                        this.flash = { message: 'Resync skipped: ' + (d.reason || 'no reason'), type: 'warning' };
                    }
                    await this.reload();
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Resync failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        // S-QBO-25: bulk-by-category accept/suppress.
        openBulkModal() {
            if (!this.bulk.category) return;
            this.bulk.note    = '';
            this.bulk.error   = '';
            this.bulk.confirm = true;
        },
        closeBulkModal() {
            this.bulk.confirm = false;
            this.bulk.note    = '';
            this.bulk.error   = '';
        },
        async confirmBulk() {
            if ((this.bulk.note || '').trim().length < 5) {
                this.bulk.error = 'Resolution note must be at least 5 chars.';
                return;
            }
            this.bulk.running = true;
            try {
                const payload = {
                    category: this.bulk.category,
                    action: this.bulk.action,
                    resolution_note: this.bulk.note.trim(),
                };
                if (this.bulk.entity) payload.entity_type = this.bulk.entity;
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/drift_bulk_resolve.php'), payload);
                if (j.success) {
                    const d = j.data || {};
                    this.flash = { message: 'Bulk ' + (d.action || this.bulk.action) + ': ' + (d.affected || 0) + ' event(s) updated.', type: 'success' };
                    this.closeBulkModal();
                    // Clear bulk inputs so a stale selection isn't re-fired.
                    this.bulk.category = ''; this.bulk.entity = '';
                    await this.reload();
                } else {
                    this.bulk.error = (j.error && j.error.message) || 'Bulk action failed.';
                }
            } catch (e) {
                this.bulk.error = e.message || 'Network error';
            } finally { this.bulk.running = false; }
        },
    };
}

// S-QBO-24: "Run drift check now" — POSTs to drift_run.php which invokes
// DriftChecker::runCheck() synchronously + returns the run stats. Reloads
// the page so the dashboard reflects any newly-recorded drift events.
function qboDriftRunNow() {
    return {
        running: false,
        lastResult: '',
        async run() {
            this.running = true;
            this.lastResult = '';
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/drift_run.php'), {});
                if (j && j.success) {
                    const r = j.data || j;
                    this.lastResult = 'Checked ' + (r.checked ?? 0) + ' entity types · '
                        + (r.drift_events ?? 0) + ' drift events · mode=' + (r.live ? 'live+snapshot' : 'snapshot-only');
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    this.lastResult = 'Failed: ' + ((j && j.error && j.error.message) || 'unknown error');
                }
            } catch (e) {
                this.lastResult = 'Failed: ' + (e.message || e);
            } finally {
                this.running = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
