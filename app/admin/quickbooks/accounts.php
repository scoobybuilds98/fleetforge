<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/accounts.php
 *
 * Chart of Accounts Mapping — FF ↔ QuickBooks. Differs from
 * customers/vendors mapping UIs because the flow is UNIDIRECTIONAL
 * per D-QBO-8-1 (Puller-only — accountant owns COA structure in QBO,
 * FF mirrors via mapping). No push button, no "ff_only" being a
 * blocker — the operator-facing concept is "are the critical bridge
 * accounts mapped so downstream invoice push can run".
 *
 * Layout sections:
 *   - Bridge-account banner (red, conditional on unmappedCritical > 0)
 *   - Action bar: Pull from QuickBooks + Auto-Match
 *   - 5 KPI tiles (mapped / ff_only / qbo_only / ignored / critical_unmapped)
 *   - Type filter chips (asset/liability/equity/revenue/...)
 *   - Critical-only toggle
 *   - Main table grouped by FF account_type (server sorts via list.php
 *     ORDER BY critical-first then code natural order)
 *   - Per-row actions: Link / Unlink / Ignore / Mark Critical / Unmark Critical
 *
 * @session  S-QBO-8
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.1 (account mapping table)
 * @gate     require_permission('quickbooks', 'view')
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Accounts';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Accounts</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Chart of Accounts Mapping</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Link FleetForge accounts to their QuickBooks counterparts. Auto-Match cascades on
        account code first (most-reliable signal), then type-compatible name match, then fuzzy.
        <strong>Critical bridge accounts must be mapped before invoice + journal-entry push (S-QBO-11/21).</strong>
    </div>
</div>

<div x-data="qboAccountMapping()" x-init="init()">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Bridge-account banner (D-QBO-8-2) ───────────────────── -->
    <template x-if="kpis && kpis.critical_unmapped > 0">
        <div class="alert alert-danger" style="margin-bottom:14px;">
            <strong>⚠ <span x-text="kpis.critical_unmapped"></span> critical account<span x-text="kpis.critical_unmapped === 1 ? '' : 's'"></span> unmapped.</strong>
            Mapping these is required before <a href="#" @click.prevent="">invoice push (S-QBO-11)</a>
            and journal-entry push (S-QBO-21) can function.
            <button class="btn btn-sm btn-outline" style="margin-left:8px;" @click="filters.critical_only = true; reload();">
                Show only critical unmapped
            </button>
        </div>
    </template>

    <!-- ── Action bar ──────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div class="text-sm text-secondary">
            Last pulled: <span class="font-mono" x-text="lastPulledAt ? formatTs(lastPulledAt) : '— never —'"></span>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-secondary btn-sm" @click="runPull()" :disabled="pulling || autoMatching">
                <span x-show="!pulling">Pull from QuickBooks</span>
                <span x-show="pulling" x-cloak>Pulling…</span>
            </button>
            <button class="btn btn-primary btn-sm" @click="runAutoMatch()" :disabled="pulling || autoMatching">
                <span x-show="!autoMatching">Auto-Match</span>
                <span x-show="autoMatching" x-cloak>Matching…</span>
            </button>
        </div>
    </div>

    <!-- ── KPI tiles ───────────────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo">
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Mapped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#16a34a;" x-text="kpis ? kpis.mapped : '—'"></div>
            <div class="text-secondary text-sm">both sides linked</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">FF only</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#d97706;" x-text="kpis ? kpis.ff_only : '—'"></div>
            <div class="text-secondary text-sm">unmapped FF accounts</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">QBO only</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#0284c7;" x-text="kpis ? kpis.qbo_only : '—'"></div>
            <div class="text-secondary text-sm">unmapped QBO accounts</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Critical unmapped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;"
                 :style="kpis && kpis.critical_unmapped > 0 ? 'color:#dc2626;' : 'color:#16a34a;'"
                 x-text="kpis ? kpis.critical_unmapped : '—'"></div>
            <div class="text-secondary text-sm">bridge accounts blocking push</div>
        </div>
    </div>

    <!-- ── Filters bar ─────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px;align-items:flex-end;">
        <div>
            <label class="form-label">Type</label>
            <select class="form-select form-select-sm" x-model="filters.type" @change="page=1; reload()">
                <option value="all">All types</option>
                <option value="asset">Asset</option>
                <option value="liability">Liability</option>
                <option value="equity">Equity</option>
                <option value="revenue">Revenue</option>
                <option value="cost_of_revenue">Cost of Revenue</option>
                <option value="operating_expense">Operating Expense</option>
                <option value="other_income">Other Income</option>
                <option value="other_expense">Other Expense</option>
            </select>
        </div>
        <div>
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" x-model="filters.status" @change="page=1; reload()">
                <option value="all">All</option>
                <option value="mapped">Mapped</option>
                <option value="ff_only">FF only</option>
                <option value="qbo_only">QBO only</option>
                <option value="ignored">Ignored</option>
            </select>
        </div>
        <div>
            <label class="form-label">Search</label>
            <input type="text" class="form-control form-control-sm" placeholder="code, name…"
                   x-model.debounce.350ms="filters.q" @input="page=1; reload()">
        </div>
        <div style="text-align:right;">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:0.875rem;">
                <input type="checkbox" x-model="filters.critical_only" @change="page=1; reload()">
                Critical only
            </label>
            <div class="text-sm text-secondary" style="margin-top:4px;" x-text="total + ' rows'"></div>
        </div>
    </div>

    <!-- ── Empty state ─────────────────────────────────────────── -->
    <template x-if="!loading && rows.length === 0 && (kpis === null || (kpis.mapped + kpis.ff_only + kpis.qbo_only + kpis.ignored) === 0)">
        <div class="card" style="padding:32px;text-align:center;">
            <div class="h5" style="margin-bottom:8px;">No account mappings yet</div>
            <div class="text-secondary text-sm" style="margin-bottom:16px;">
                Click <strong>Pull from QuickBooks</strong> to fetch your QBO chart of accounts,<br>
                then <strong>Auto-Match</strong> to suggest pairings using account codes + name + type compatibility.
            </div>
        </div>
    </template>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <template x-if="rows.length > 0">
        <div class="table-wrapper">
            <table class="table table-sm" style="margin:0;">
                <thead>
                    <tr>
                        <th style="width:90px;">FF Code</th>
                        <th>FF Account</th>
                        <th>FF Type</th>
                        <th>QBO Account</th>
                        <th>Status</th>
                        <th>Confidence</th>
                        <th style="text-align:center;">Critical</th>
                        <th style="width:280px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.mapping_id">
                        <tr :class="row.is_critical == 1 && (row.mapping_status !== 'mapped' || !row.qbo_account_id) ? 'critical-unmapped-row' : ''">
                            <td class="font-mono">
                                <template x-if="row.ff_code"><span x-text="row.ff_code"></span></template>
                                <template x-if="!row.ff_code"><span class="text-secondary">—</span></template>
                            </td>
                            <td>
                                <template x-if="row.ff_account_id">
                                    <div>
                                        <div x-text="row.ff_name || '#' + row.ff_account_id"></div>
                                        <div class="text-sm text-secondary" x-text="row.ff_account_subtype || ''"></div>
                                    </div>
                                </template>
                                <template x-if="!row.ff_account_id">
                                    <span class="text-secondary">—</span>
                                </template>
                            </td>
                            <td>
                                <span class="badge badge-outline" x-text="row.ff_account_type ? formatType(row.ff_account_type) : '—'"></span>
                            </td>
                            <td>
                                <template x-if="row.qbo_account_id">
                                    <div>
                                        <div x-text="row.qbo_name || '(no name)'"></div>
                                        <div class="text-sm text-secondary font-mono">
                                            <span x-text="row.qbo_account_number ? ('AcctNum ' + row.qbo_account_number + ' · ') : ''"></span>
                                            <span x-text="row.qbo_account_type || ''"></span>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!row.qbo_account_id">
                                    <span class="text-secondary">—</span>
                                </template>
                            </td>
                            <td>
                                <span class="badge" :class="statusBadgeClass(row.mapping_status)" x-text="row.mapping_status"></span>
                            </td>
                            <td>
                                <span class="badge badge-outline" x-text="row.match_confidence || '—'"></span>
                            </td>
                            <td style="text-align:center;">
                                <template x-if="row.is_critical == 1">
                                    <span class="badge badge-danger" :title="row.critical_reason">★</span>
                                </template>
                                <template x-if="row.is_critical != 1">
                                    <span class="text-secondary">—</span>
                                </template>
                            </td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <template x-if="row.mapping_status === 'ff_only'">
                                        <button class="btn btn-sm btn-secondary" @click="openLinkModal(row, 'pick_qbo')">Link to QBO…</button>
                                    </template>
                                    <template x-if="row.mapping_status === 'qbo_only'">
                                        <button class="btn btn-sm btn-secondary" @click="openLinkModal(row, 'pick_ff')">Link to FF…</button>
                                    </template>
                                    <template x-if="row.mapping_status === 'mapped'">
                                        <button class="btn btn-sm btn-outline" @click="unlinkRow(row)">Unlink</button>
                                    </template>
                                    <template x-if="row.mapping_status !== 'ignored'">
                                        <button class="btn btn-sm btn-outline" @click="ignoreRow(row)">Ignore</button>
                                    </template>
                                    <template x-if="row.mapping_status === 'ignored'">
                                        <button class="btn btn-sm btn-outline" @click="unignoreRow(row)">Un-ignore</button>
                                    </template>
                                    <template x-if="row.is_critical != 1 && row.ff_account_id">
                                        <button class="btn btn-sm btn-outline" @click="markCritical(row)" title="Mark as bridge account">★ Mark critical</button>
                                    </template>
                                    <template x-if="row.is_critical == 1 && row.ff_account_id">
                                        <button class="btn btn-sm btn-outline" @click="unmarkCritical(row)" title="Remove bridge-account flag">Unmark critical</button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- ── Pagination ──────────────────────────────────────────── -->
    <template x-if="rows.length > 0 && totalPages > 1">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
            <button class="btn btn-sm btn-outline" :disabled="page <= 1" @click="page--; reload()">Prev</button>
            <span class="text-sm text-secondary">
                Page <span x-text="page"></span> of <span x-text="totalPages"></span>
            </span>
            <button class="btn btn-sm btn-outline" :disabled="page >= totalPages" @click="page++; reload()">Next</button>
        </div>
    </template>

    <!-- ── Link modal ──────────────────────────────────────────── -->
    <div x-show="linkModal.open" x-cloak
         class="modal-overlay"
         style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);"
         @click.self="closeLinkModal()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;" x-text="linkModal.mode === 'pick_qbo' ? 'Link FF account to QBO' : 'Link QBO account to FF'"></h3>
                <button class="modal-close-btn" @click="closeLinkModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-sm text-secondary" style="margin-bottom:14px;">
                    <strong>FF:</strong> <span x-text="linkModal.row && linkModal.row.ff_code ? (linkModal.row.ff_code + ' · ' + (linkModal.row.ff_name || '—')) : '—'"></span><br>
                    <strong>QBO:</strong> <span x-text="linkModal.row && linkModal.row.qbo_name ? linkModal.row.qbo_name : '—'"></span>
                </div>

                <label class="form-label" x-text="linkModal.mode === 'pick_qbo' ? 'Pick QBO account' : 'Pick FF account'"></label>
                <select class="form-select" x-model="linkModal.selectedId">
                    <option value="">— select —</option>
                    <template x-for="opt in linkModal.options" :key="opt.id">
                        <option :value="opt.id" x-text="opt.label"></option>
                    </template>
                </select>

                <label class="form-label" style="margin-top:14px;">Notes (optional)</label>
                <textarea class="form-control" rows="2" x-model="linkModal.notes" placeholder="Why this link…"></textarea>

                <div x-show="linkModal.error" x-cloak class="text-sm" style="color:var(--color-danger,#dc2626);margin-top:8px;" x-text="linkModal.error"></div>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                <button class="btn btn-outline" @click="closeLinkModal()">Cancel</button>
                <button class="btn btn-primary" :disabled="!linkModal.selectedId || linkModal.saving" @click="saveLink()">
                    <span x-show="!linkModal.saving">Save link</span>
                    <span x-show="linkModal.saving" x-cloak>Saving…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Critical-unmapped row highlight — subtle red left border + tint */
    .critical-unmapped-row td {
        background-color: rgba(220, 38, 38, 0.04);
    }
    .critical-unmapped-row td:first-child {
        box-shadow: inset 3px 0 0 0 #dc2626;
    }
</style>

<script>
function qboAccountMapping() {
    return {
        loading: false,
        pulling: false,
        autoMatching: false,
        rows: [],
        total: 0,
        totalPages: 1,
        page: 1,
        pageSize: 50,
        kpis: null,
        lastPulledAt: null,
        flash: { message: '', type: '' },
        filters: { type: 'all', status: 'all', q: '', critical_only: false },

        linkModal: {
            open: false,
            mode: 'pick_qbo',
            row: null,
            options: [],
            selectedId: '',
            notes: '',
            saving: false,
            error: '',
        },

        async init() {
            await this.reload();
        },

        async reload() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    type:          this.filters.type,
                    status:        this.filters.status,
                    q:             this.filters.q || '',
                    critical_only: this.filters.critical_only ? 'true' : 'false',
                    page:          String(this.page),
                    page_size:     String(this.pageSize),
                });
                const j = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/accounts/list.php?' + params.toString()));
                if (j.success) {
                    const d = j.data;
                    this.rows         = d.rows;
                    this.total        = d.pagination.total;
                    this.totalPages   = d.pagination.total_pages;
                    this.kpis         = d.kpis;
                    this.lastPulledAt = d.last_pulled_at;
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.loading = false;
            }
        },

        async runPull() {
            this.pulling = true;
            this.flash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/accounts/pull.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'Pulled ' + d.pulled_count + ' QBO accounts (' + d.inserted + ' new, ' + d.updated + ' updated). ' + d.critical_unmapped + ' critical unmapped.',
                        type: 'success',
                    };
                    await this.reload();
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.pulling = false;
            }
        },

        async runAutoMatch() {
            this.autoMatching = true;
            this.flash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/accounts/auto_match.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'Auto-match: ' + d.matched + ' mapped, ' + d.ff_only + ' ff_only, ' + d.qbo_only + ' qbo_only, ' + d.manual_preserved + ' manual preserved.',
                        type: 'success',
                    };
                    await this.reload();
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.autoMatching = false;
            }
        },

        openLinkModal(row, mode) {
            this.linkModal.row        = row;
            this.linkModal.mode       = mode;
            this.linkModal.options    = [];
            this.linkModal.selectedId = '';
            this.linkModal.notes      = '';
            this.linkModal.error      = '';

            // For account linking we filter the picker candidate list to
            // type-compatible accounts. Pull from /list and filter client-side.
            const wantedStatus = mode === 'pick_qbo' ? 'qbo_only' : 'ff_only';
            const params = new URLSearchParams({
                type:          'all',
                status:        wantedStatus,
                q:             '',
                critical_only: 'false',
                page:          '1',
                page_size:     '200',
            });
            FF_Api.get(FF_Api.url('/api/v1/quickbooks/accounts/list.php?' + params.toString()))
                .then(j => {
                    if (!j.success) { this.linkModal.error = (j.error && j.error.message) || 'Failed to load candidates'; return; }
                    this.linkModal.options = j.data.rows.map(r => mode === 'pick_qbo'
                        ? { id: r.qbo_account_id, label: (r.qbo_account_number ? (r.qbo_account_number + ' · ') : '') + (r.qbo_name || '(no name)') + ' · ' + (r.qbo_account_type || '') }
                        : { id: r.ff_account_id,  label: (r.ff_code ? (r.ff_code + ' · ') : '') + (r.ff_name || '(no name)') + ' · ' + (r.ff_account_type || '') }
                    );
                })
                .catch(e => { this.linkModal.error = e.message || 'Network error'; });
            this.linkModal.open = true;
        },

        closeLinkModal() {
            this.linkModal.open = false;
            this.linkModal.row  = null;
        },

        async saveLink() {
            if (!this.linkModal.selectedId) return;
            this.linkModal.saving = true;
            this.linkModal.error  = '';
            try {
                const body = (this.linkModal.mode === 'pick_qbo')
                    ? { action: 'link', ff_account_id: this.linkModal.row.ff_account_id, qbo_account_id: this.linkModal.selectedId, notes: this.linkModal.notes }
                    : { action: 'link', ff_account_id: this.linkModal.selectedId,        qbo_account_id: this.linkModal.row.qbo_account_id, notes: this.linkModal.notes };
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/accounts/save_mapping.php'), body);
                if (j.success) {
                    this.flash = { message: 'Linked.', type: 'success' };
                    this.closeLinkModal();
                    await this.reload();
                } else {
                    this.linkModal.error = (j.error && j.error.message) || 'Save failed';
                }
            } catch (e) {
                this.linkModal.error = e.message || 'Network error';
            } finally {
                this.linkModal.saving = false;
            }
        },

        async unlinkRow(row)   { if (!confirm('Unlink this mapping?')) return; await this.callSave({ action: 'unlink', mapping_id: row.mapping_id }); },
        async ignoreRow(row)   { await this.callSave({ action: 'ignore', mapping_id: row.mapping_id }); },
        async unignoreRow(row) { await this.callSave({ action: 'unignore', mapping_id: row.mapping_id }); },
        async markCritical(row) {
            const reason = prompt('Reason for marking as critical (e.g. "Tax Holding Account"):', row.critical_reason || '');
            if (reason === null) return;
            await this.callSave({ action: 'mark_critical', mapping_id: row.mapping_id, critical_reason: reason });
        },
        async unmarkCritical(row) {
            if (!confirm('Remove the critical flag from this account?')) return;
            await this.callSave({ action: 'unmark_critical', mapping_id: row.mapping_id });
        },

        async callSave(body) {
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/accounts/save_mapping.php'), body);
                if (j.success) {
                    this.flash = { message: 'Mapping updated.', type: 'success' };
                    await this.reload();
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        flashError(j) {
            this.flash = { message: (j.error && j.error.message) || 'Request failed', type: 'error' };
        },

        statusBadgeClass(s) {
            return {
                'mapped':   'badge-success',
                'ff_only':  'badge-warning',
                'qbo_only': 'badge-info',
                'ignored':  'badge-secondary',
            }[s] || 'badge-secondary';
        },

        formatType(t) {
            // Render snake_case lowercase as Title Case Words
            if (!t) return '—';
            return t.split('_').map(p => p.charAt(0).toUpperCase() + p.slice(1)).join(' ');
        },

        formatTs(s) {
            if (!s) return '—';
            try { return new Date(String(s).replace(' ', 'T')).toLocaleString(); } catch { return s; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
