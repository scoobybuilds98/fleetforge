<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/vendors.php
 *
 * Vendors Sync — bidirectional FF↔QBO mapping UI. Lists every row
 * in acc_qbo_vendor_map with KPI tiles + filter bar + main table.
 * Per-row actions vary by mapping_status (mirror of customers.php
 * from S-QBO-5):
 *
 *   ff_only   → "Link to QBO…" picks a QBO vendor from the
 *               qbo_only side and promotes the row to 'mapped'.
 *   qbo_only  → "Link to FF…" picks an FF vendor from the ff_only
 *               side (or any active FF vendor with no row) and
 *               promotes to 'mapped'.
 *   mapped    → "Unlink" + "Ignore".
 *   ignored   → "Un-ignore" restores natural state based on populated sides.
 *
 * Two top-row buttons:
 *   "Pull from QuickBooks" → POSTs api/v1/quickbooks/vendors/pull.php
 *                            (real HTTP to sandbox; populates qbo_only rows)
 *   "Auto-Match"           → POSTs api/v1/quickbooks/vendors/auto_match.php
 *                            (no HTTP; runs VendorMatcher cascade against
 *                             the snapshot the pull last fetched)
 *
 * @session  S-QBO-7
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.5 (vendor mapping table)
 * @gate     require_permission('quickbooks', 'view')
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Vendors';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Vendors</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Vendor Mapping</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Link FleetForge vendors to their QuickBooks counterparts. Pull refreshes QBO data; Auto-Match suggests pairings using normalized name + Levenshtein + email + phone last-7-digits.
    </div>
</div>

<div x-data="qboVendorMapping()" x-init="init()">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

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
            <div class="text-secondary text-sm">linked both sides</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">FF only</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#d97706;" x-text="kpis ? kpis.ff_only : '—'"></div>
            <div class="text-secondary text-sm">need QBO counterpart</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">QBO only</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#0284c7;" x-text="kpis ? kpis.qbo_only : '—'"></div>
            <div class="text-secondary text-sm">need FF counterpart</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Ignored</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#6b7280;" x-text="kpis ? kpis.ignored : '—'"></div>
            <div class="text-secondary text-sm">intentionally unmapped</div>
        </div>
        <!-- ── Push-state tiles (S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA) ── -->
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Mode-Skipped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#6b7280;" x-text="kpis && kpis.push_kpis ? kpis.push_kpis.skipped_by_mode : '—'"></div>
            <div class="text-secondary text-sm">sync_mode refused push</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Soft-Deleted</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#6b7280;" x-text="kpis && kpis.push_kpis ? kpis.push_kpis.skipped_soft_deleted : '—'"></div>
            <div class="text-secondary text-sm">FF deleted; QBO untouched</div>
        </div>
    </div>

    <!-- ── Filters bar ─────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px;align-items:flex-end;">
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
            <label class="form-label">Confidence</label>
            <select class="form-select form-select-sm" x-model="filters.confidence" @change="page=1; reload()">
                <option value="all">All</option>
                <option value="exact">Exact</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
                <option value="manual">Manual</option>
                <option value="unmatched">Unmatched</option>
            </select>
        </div>
        <div>
            <label class="form-label">Search</label>
            <input type="text" class="form-control form-control-sm" placeholder="FF or QBO name…"
                   x-model.debounce.350ms="filters.q" @input="page=1; reload()">
        </div>
        <div style="text-align:right;">
            <span class="text-sm text-secondary" x-text="total + ' rows'"></span>
        </div>
    </div>

    <!-- ── Empty state ─────────────────────────────────────────── -->
    <template x-if="!loading && rows.length === 0 && (kpis === null || (kpis.mapped + kpis.ff_only + kpis.qbo_only + kpis.ignored) === 0)">
        <div class="card" style="padding:32px;text-align:center;">
            <div class="h5" style="margin-bottom:8px;">No vendor mappings yet</div>
            <div class="text-secondary text-sm" style="margin-bottom:16px;">
                Click <strong>Pull from QuickBooks</strong> to fetch your sandbox vendors,<br>
                then <strong>Auto-Match</strong> to suggest pairings against FleetForge vendors.
            </div>
        </div>
    </template>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <template x-if="rows.length > 0">
        <div class="table-wrapper">
            <table class="table table-sm" style="margin:0;">
                <thead>
                    <tr>
                        <th>FF Vendor</th>
                        <th>QBO Vendor</th>
                        <th>Status</th>
                        <th>Confidence</th>
                        <th>Last Synced</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.mapping_id">
                        <tr>
                            <td>
                                <template x-if="row.ff_vendor_id">
                                    <a :href="ffVendorUrl(row.ff_vendor_id)" x-text="row.ff_name || '#' + row.ff_vendor_id"></a>
                                </template>
                                <template x-if="!row.ff_vendor_id">
                                    <span class="text-secondary">—</span>
                                </template>
                            </td>
                            <td>
                                <template x-if="row.qbo_vendor_id">
                                    <div>
                                        <div x-text="row.qbo_display_name || '(no name)'"></div>
                                        <div class="text-sm text-secondary font-mono" x-text="'qbo #' + row.qbo_vendor_id"></div>
                                    </div>
                                </template>
                                <template x-if="!row.qbo_vendor_id">
                                    <span class="text-secondary">—</span>
                                </template>
                            </td>
                            <td>
                                <span class="badge" :class="statusBadgeClass(row.mapping_status)" x-text="row.mapping_status"></span>
                            </td>
                            <td>
                                <span class="badge badge-outline" x-text="row.match_confidence || '—'"></span>
                            </td>
                            <td class="text-sm font-mono" x-text="row.last_synced_at ? formatTs(row.last_synced_at) : '—'"></td>
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
    <!-- Uses canonical .modal-overlay / .modal / .modal-md classes
         (defined in public/assets/css/app.css) — same pattern as
         customers.php (lifted from drift.php's resolveModal). Inline
         position:fixed centering was unreliable due to ancestor
         transform/filter creating a new containing block. -->
    <div x-show="linkModal.open" x-cloak
         class="modal-overlay"
         style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);"
         @click.self="closeLinkModal()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;" x-text="linkModal.mode === 'pick_qbo' ? 'Link FF vendor to QBO' : 'Link QBO vendor to FF'"></h3>
                <button class="modal-close-btn" @click="closeLinkModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-sm text-secondary" style="margin-bottom:14px;">
                    <strong>FF:</strong> <span x-text="linkModal.row && linkModal.row.ff_name ? linkModal.row.ff_name : '—'"></span><br>
                    <strong>QBO:</strong> <span x-text="linkModal.row && linkModal.row.qbo_display_name ? linkModal.row.qbo_display_name : '—'"></span>
                </div>

                <label class="form-label" x-text="linkModal.mode === 'pick_qbo' ? 'Pick QBO vendor' : 'Pick FF vendor'"></label>
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

<script>
function qboVendorMapping() {
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
        filters: { status: 'all', confidence: 'all', q: '' },

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
                    status:     this.filters.status,
                    confidence: this.filters.confidence,
                    q:          this.filters.q || '',
                    page:       String(this.page),
                    page_size:  String(this.pageSize),
                });
                const j = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/vendors/list.php?' + params.toString()));
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
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/vendors/pull.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'Pulled ' + d.pulled_count + ' QBO vendors (' + d.inserted + ' new, ' + d.updated + ' updated).',
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
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/vendors/auto_match.php'), {});
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
            // Populate picker options from the OPPOSITE side's
            // single-sided rows. mode='pick_qbo' → list qbo_only rows.
            // mode='pick_ff' → list ff_only rows.
            this.linkModal.row        = row;
            this.linkModal.mode       = mode;
            this.linkModal.options    = [];
            this.linkModal.selectedId = '';
            this.linkModal.notes      = '';
            this.linkModal.error      = '';

            const wanted = mode === 'pick_qbo' ? 'qbo_only' : 'ff_only';
            const params = new URLSearchParams({
                status:     wanted,
                confidence: 'all',
                q:          '',
                page:       '1',
                page_size:  '200',
            });
            FF_Api.get(FF_Api.url('/api/v1/quickbooks/vendors/list.php?' + params.toString()))
                .then(j => {
                    if (!j.success) { this.linkModal.error = (j.error && j.error.message) || 'Failed to load candidates'; return; }
                    this.linkModal.options = j.data.rows.map(r => mode === 'pick_qbo'
                        ? { id: r.qbo_vendor_id, label: (r.qbo_display_name || '(no name)') + ' · qbo#' + r.qbo_vendor_id }
                        : { id: r.ff_vendor_id,  label: (r.ff_name          || '(no name)') + ' · ff#'  + r.ff_vendor_id }
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
                    ? { action: 'link', ff_vendor_id: this.linkModal.row.ff_vendor_id, qbo_vendor_id: this.linkModal.selectedId, notes: this.linkModal.notes }
                    : { action: 'link', ff_vendor_id: this.linkModal.selectedId,       qbo_vendor_id: this.linkModal.row.qbo_vendor_id, notes: this.linkModal.notes };
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/vendors/save_mapping.php'), body);
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

        async unlinkRow(row) {
            if (!confirm('Unlink this mapping?')) return;
            await this.callSave({ action: 'unlink', mapping_id: row.mapping_id });
        },

        async ignoreRow(row) {
            await this.callSave({ action: 'ignore', mapping_id: row.mapping_id });
        },

        async unignoreRow(row) {
            await this.callSave({ action: 'unignore', mapping_id: row.mapping_id });
        },

        async callSave(body) {
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/vendors/save_mapping.php'), body);
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

        ffVendorUrl(id) {
            return FF_Api.url('/vendors/show?id=' + encodeURIComponent(id));
        },

        formatTs(s) {
            if (!s) return '—';
            try { return new Date(String(s).replace(' ', 'T')).toLocaleString(); } catch { return s; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
