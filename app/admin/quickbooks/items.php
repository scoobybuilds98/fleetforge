<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/items.php
 *
 * Items / Products Mapping — FF ↔ QuickBooks. Hybrid pattern: the FF
 * "source side" is the 17-value invoice_line_items.item_type ENUM
 * (not a table), so unlike Customer/Vendor/Account/TaxCode mappings
 * the UI iterates ENUM tuples on the left.
 *
 * Distinguishing UX elements:
 *   - Status banner (red when any FF item_type is unmapped — S-QBO-11
 *     invoice push needs every type provisioned; green when complete)
 *   - Per-row "Create QBO Item" button on ff_only rows — operator
 *     authors missing QBO Items via ItemCreator (D-QBO-10-4)
 *   - GPS net/gross variants displayed side-by-side per D-QBO-10-2
 *   - D-QBO-10-1 reconciliation_credit row carries a dedicated badge
 *
 * @session  S-QBO-10
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.3
 * @gate     require_permission('quickbooks', 'view')
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Items';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Items</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Item / Product Mapping</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Map FleetForge invoice line item types to QuickBooks Items. Create missing Items as needed —
        every FF item_type needs a mapped QBO Item before S-QBO-11 invoice push will work.
    </div>
</div>

<div x-data="qboItemMapping(<?= $canEditCredentials ? 'true' : 'false' ?>)" x-init="init()">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Status banner ──────────────────────────────────────── -->
    <template x-if="kpis && kpis.ff_only > 0">
        <div class="alert alert-danger" style="margin-bottom:14px;">
            <strong>⚠ <span x-text="kpis.ff_only"></span> FF item types have no QBO mapping.</strong>
            Invoice push (S-QBO-11) requires every item type to have a mapped QBO Item.
            Use <strong>Create QBO Item</strong> on each unmapped row to author missing ones,
            or link to an existing QBO Item if it's already there.
        </div>
    </template>
    <template x-if="kpis && kpis.ff_only === 0 && (kpis.mapped + kpis.qbo_only + kpis.ignored) > 0">
        <div class="alert alert-success" style="margin-bottom:14px;">
            <strong>✓ All FF item types mapped.</strong>
            Invoice push (S-QBO-11) ready for these item types.
        </div>
    </template>

    <!-- ── Action bar ──────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div class="text-sm text-secondary">
            Last pulled: <span class="font-mono" x-text="lastPulledAt ? formatTs(lastPulledAt) : '— never —'"></span>
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:0.875rem;">
                <input type="checkbox" x-model="filters.show_inactive_qbo" @change="page=1; reload()">
                Show inactive QBO Items
            </label>
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

    <!-- ── 5 KPI tiles ─────────────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(5,1fr);">
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Mapped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#16a34a;" x-text="kpis ? kpis.mapped : '—'"></div>
            <div class="text-secondary text-sm">both sides linked</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">FF unmapped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#d97706;" x-text="kpis ? kpis.ff_only : '—'"></div>
            <div class="text-secondary text-sm">need QBO Item</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">QBO unmapped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#0284c7;" x-text="kpis ? kpis.qbo_only : '—'"></div>
            <div class="text-secondary text-sm">unmapped QBO Items</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Ignored</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#6b7280;" x-text="kpis ? kpis.ignored : '—'"></div>
            <div class="text-secondary text-sm">intentionally unmapped</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Auto-created</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#7c3aed;" x-text="kpis ? kpis.auto_created : '—'"></div>
            <div class="text-secondary text-sm">FF authored in QBO</div>
        </div>
    </div>

    <!-- ── Filters bar ─────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;align-items:flex-end;">
        <div>
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" x-model="filters.status" @change="page=1; reload()">
                <option value="all">All</option>
                <option value="mapped">Mapped</option>
                <option value="ff_only">FF only (needs QBO)</option>
                <option value="qbo_only">QBO only</option>
                <option value="ignored">Ignored</option>
            </select>
        </div>
        <div>
            <label class="form-label">Search</label>
            <input type="text" class="form-control form-control-sm" placeholder="ff item_type, qbo name, account…"
                   x-model.debounce.350ms="filters.q" @input="page=1; reload()">
        </div>
        <div style="text-align:right;">
            <span class="text-sm text-secondary" x-text="total + ' rows'"></span>
        </div>
    </div>

    <!-- ── Empty state ─────────────────────────────────────────── -->
    <template x-if="!loading && rows.length === 0 && (kpis === null || (kpis.mapped + kpis.ff_only + kpis.qbo_only + kpis.ignored) === 0)">
        <div class="card" style="padding:32px;text-align:center;">
            <div class="h5" style="margin-bottom:8px;">No item mappings yet</div>
            <div class="text-secondary text-sm" style="margin-bottom:16px;">
                Click <strong>Pull from QuickBooks</strong> to fetch your sandbox Items + populate FF item_type rows,<br>
                then <strong>Auto-Match</strong> to suggest pairings based on display names.
            </div>
        </div>
    </template>

    <!-- ── Main table — grouped by category ────────────────────── -->
    <template x-if="rows.length > 0">
        <div>
            <template x-for="(group, label) in groupedRows" :key="label">
                <div style="margin-bottom:18px;">
                    <h3 class="h6" style="margin:0 0 8px 0;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:0.04em;font-size:0.78rem;" x-text="label"></h3>
                    <div class="table-wrapper">
                        <table class="table table-sm" style="margin:0;">
                            <thead>
                                <tr>
                                    <th>FF item_type</th>
                                    <th>QBO Item</th>
                                    <th>Income account</th>
                                    <th>Status</th>
                                    <th>Confidence</th>
                                    <th style="width:340px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="row in group" :key="row.mapping_id">
                                    <tr :class="row.is_credit_variant == 1 ? 'credit-variant-row' : ''">
                                        <td>
                                            <template x-if="row.ff_item_type">
                                                <div>
                                                    <div x-text="row.ff_display_name || row.ff_item_type"></div>
                                                    <div class="text-sm text-secondary font-mono">
                                                        <span x-text="row.ff_item_type"></span>
                                                        <template x-if="row.presentation_variant">
                                                            <span class="badge badge-info" style="margin-left:6px;" x-text="row.presentation_variant"></span>
                                                        </template>
                                                        <template x-if="row.is_credit_variant == 1">
                                                            <span class="badge badge-warning" style="margin-left:6px;" title="D-QBO-10-1: dedicated reconciliation credit Item">credit</span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                            <template x-if="!row.ff_item_type">
                                                <span class="text-secondary">—</span>
                                            </template>
                                        </td>
                                        <td>
                                            <template x-if="row.qbo_item_id">
                                                <div>
                                                    <div x-text="row.qbo_name || '(no name)'"></div>
                                                    <div class="text-sm text-secondary font-mono">
                                                        <span x-text="'qbo #' + row.qbo_item_id"></span>
                                                        <template x-if="row.qbo_type">
                                                            <span class="badge badge-outline" style="margin-left:6px;" x-text="row.qbo_type"></span>
                                                        </template>
                                                        <template x-if="row.qbo_active != 1">
                                                            <span class="badge badge-secondary" style="margin-left:6px;">inactive</span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                            <template x-if="!row.qbo_item_id"><span class="text-secondary">—</span></template>
                                        </td>
                                        <td class="text-sm">
                                            <template x-if="row.qbo_income_account_name">
                                                <div>
                                                    <div x-text="row.qbo_income_account_name"></div>
                                                    <div class="text-secondary font-mono" x-text="'qbo #' + row.qbo_income_account_id"></div>
                                                </div>
                                            </template>
                                            <template x-if="!row.qbo_income_account_name"><span class="text-secondary">—</span></template>
                                        </td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(row.mapping_status)" x-text="row.mapping_status"></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-outline" x-text="row.match_confidence || '—'"></span>
                                        </td>
                                        <td>
                                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                                <template x-if="row.mapping_status === 'ff_only' && canEditCredentials">
                                                    <button class="btn btn-sm btn-primary" @click="openCreateModal(row)" title="Author a new QBO Item for this FF item_type">
                                                        Create QBO Item
                                                    </button>
                                                </template>
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
                </div>
            </template>
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
                <h3 class="h5" style="margin:0;" x-text="linkModal.mode === 'pick_qbo' ? 'Link FF item_type to QBO Item' : 'Link QBO Item to FF item_type'"></h3>
                <button class="modal-close-btn" @click="closeLinkModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-sm text-secondary" style="margin-bottom:14px;">
                    <strong>FF:</strong> <span x-text="linkModal.row ? (linkModal.row.ff_display_name || linkModal.row.ff_item_type || '—') : '—'"></span><br>
                    <strong>QBO:</strong> <span x-text="linkModal.row ? (linkModal.row.qbo_name || '—') : '—'"></span>
                </div>

                <label class="form-label" x-text="linkModal.mode === 'pick_qbo' ? 'Pick QBO Item' : 'Pick FF item_type'"></label>
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

    <!-- ── Create QBO Item modal (D-QBO-10-4) ──────────────────── -->
    <div x-show="createModal.open" x-cloak
         class="modal-overlay"
         style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);"
         @click.self="closeCreateModal()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;">Create QBO Item</h3>
                <button class="modal-close-btn" @click="closeCreateModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-sm" style="margin-bottom:14px;">
                    <div><strong>FF item_type:</strong> <span class="font-mono" x-text="createModal.row ? createModal.row.ff_item_type : '—'"></span>
                        <template x-if="createModal.row && createModal.row.ff_item_type_variant">
                            <span class="badge badge-info" style="margin-left:6px;" x-text="createModal.row.ff_item_type_variant"></span>
                        </template>
                    </div>
                    <div style="margin-top:4px;"><strong>QBO Item name (will be created as):</strong> <span x-text="createModal.row ? createModal.row.ff_display_name : '—'"></span></div>
                    <div style="margin-top:4px;"><strong>QBO Item type:</strong> Service</div>
                </div>

                <label class="form-label">Income account</label>
                <select class="form-select" x-model="createModal.overrideAccountId">
                    <option value="">— default (resolved via S-QBO-8 mapping) —</option>
                    <template x-for="opt in createModal.incomeAccountOptions" :key="opt.qbo_account_id">
                        <option :value="opt.qbo_account_id" x-text="opt.label"></option>
                    </template>
                </select>
                <div class="text-sm text-secondary" style="margin-top:4px;">
                    Default: ItemCreator picks a critical revenue account from <a :href="qboAccountsUrl">Chart of Accounts mapping</a>; falls back to any mapped revenue account.
                </div>

                <div x-show="createModal.error" x-cloak class="alert alert-danger" style="margin-top:12px;" x-text="createModal.error"></div>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                <button class="btn btn-outline" @click="closeCreateModal()">Cancel</button>
                <button class="btn btn-primary" :disabled="createModal.saving" @click="submitCreate()">
                    <span x-show="!createModal.saving">Create in QuickBooks</span>
                    <span x-show="createModal.saving" x-cloak>Creating…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .credit-variant-row td {
        background-color: rgba(217, 119, 6, 0.05);
    }
    .credit-variant-row td:first-child {
        box-shadow: inset 3px 0 0 0 #d97706;
    }
</style>

<script>
function qboItemMapping(canEditCredentials) {
    return {
        canEditCredentials: !!canEditCredentials,
        loading: false,
        pulling: false,
        autoMatching: false,
        rows: [],
        total: 0,
        totalPages: 1,
        page: 1,
        pageSize: 200,
        kpis: null,
        lastPulledAt: null,
        uiCategories: {},
        groupedRows: {},
        qboAccountsUrl: window.FF_BASE_URL ? (window.FF_BASE_URL + '/quickbooks/accounts') : '/quickbooks/accounts',
        flash: { message: '', type: '' },
        filters: { status: 'all', q: '', show_inactive_qbo: false },

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

        createModal: {
            open: false,
            row: null,
            overrideAccountId: '',
            incomeAccountOptions: [],
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
                    status:            this.filters.status,
                    q:                 this.filters.q || '',
                    show_inactive_qbo: this.filters.show_inactive_qbo ? 'true' : 'false',
                    page:              String(this.page),
                    page_size:         String(this.pageSize),
                });
                const j = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/items/list.php?' + params.toString()));
                if (j.success) {
                    const d = j.data;
                    this.rows         = d.rows;
                    this.total        = d.pagination.total;
                    this.totalPages   = d.pagination.total_pages;
                    this.kpis         = d.kpis;
                    this.lastPulledAt = d.last_pulled_at;
                    this.uiCategories = d.ui_categories;
                    this.groupedRows  = this.computeGrouped(d.rows);
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.loading = false;
            }
        },

        computeGrouped(rows) {
            const groups = {
                'Rental & Mileage': [],
                'Fees': [],
                'Other Recoveries': [],
                'Adjustments': [],
                'QBO Only': [],
            };
            for (const r of rows) {
                const label = r.ff_category || 'QBO Only';
                if (!groups[label]) groups[label] = [];
                groups[label].push(r);
            }
            // Drop empty groups for cleaner UI.
            const out = {};
            for (const [k, v] of Object.entries(groups)) {
                if (v.length > 0) out[k] = v;
            }
            return out;
        },

        async runPull() {
            this.pulling = true;
            this.flash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/items/pull.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'Pulled ' + d.pulled_count + ' QBO Items (' + d.inserted + ' new, ' + d.updated + ' updated). '
                               + d.ff_types_count + ' FF item types tracked; ' + d.missing_ff_to_qbo + ' still unmapped.',
                        type: d.missing_ff_to_qbo === 0 ? 'success' : 'error',
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
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/items/auto_match.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'Auto-match: ' + d.matched + ' mapped, ' + d.ff_only + ' ff_only, ' + d.qbo_only + ' qbo_only, '
                               + d.manual_preserved + ' manual preserved, ' + d.auto_created_preserved + ' auto-created preserved.',
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

            const wantedStatus = mode === 'pick_qbo' ? 'qbo_only' : 'ff_only';
            const params = new URLSearchParams({
                status:            wantedStatus,
                q:                 '',
                show_inactive_qbo: 'true',
                page:              '1',
                page_size:         '500',
            });
            FF_Api.get(FF_Api.url('/api/v1/quickbooks/items/list.php?' + params.toString()))
                .then(j => {
                    if (!j.success) { this.linkModal.error = (j.error && j.error.message) || 'Failed to load candidates'; return; }
                    this.linkModal.options = j.data.rows.map(r => mode === 'pick_qbo'
                        ? { id: r.qbo_item_id, label: (r.qbo_name || '(no name)') + ' · qbo#' + r.qbo_item_id + (r.qbo_active != 1 ? ' [inactive]' : '') }
                        : { id: r.ff_item_type, label: (r.ff_display_name || r.ff_item_type) + (r.ff_item_type_variant ? ' (' + r.ff_item_type_variant + ')' : '') + ' · ' + r.ff_item_type }
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
                    ? {
                        action: 'link',
                        ff_item_type: this.linkModal.row.ff_item_type,
                        ff_item_type_variant: this.linkModal.row.ff_item_type_variant,
                        qbo_item_id: this.linkModal.selectedId,
                        notes: this.linkModal.notes,
                      }
                    : {
                        action: 'link',
                        ff_item_type: this.linkModal.selectedId,
                        qbo_item_id: this.linkModal.row.qbo_item_id,
                        notes: this.linkModal.notes,
                      };
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/items/save_mapping.php'), body);
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

        openCreateModal(row) {
            if (!this.canEditCredentials) {
                this.flash = { message: 'You do not have permission to author QBO Items (quickbooks.edit_credentials required).', type: 'error' };
                return;
            }
            this.createModal.row               = row;
            this.createModal.overrideAccountId = '';
            this.createModal.incomeAccountOptions = [];
            this.createModal.saving            = false;
            this.createModal.error             = '';
            this.createModal.open              = true;

            // Load mapped revenue accounts for the dropdown.
            this.loadRevenueAccounts();
        },

        async loadRevenueAccounts() {
            try {
                const params = new URLSearchParams({
                    status:    'mapped',
                    page:      '1',
                    page_size: '200',
                });
                const j = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/accounts/list.php?' + params.toString()));
                if (j.success) {
                    this.createModal.incomeAccountOptions = j.data.rows
                        .filter(r => (r.ff_account_type || '').toLowerCase() === 'revenue')
                        .map(r => ({
                            qbo_account_id: r.qbo_account_id,
                            label: (r.qbo_name || r.ff_name || '(no name)') + ' · qbo#' + r.qbo_account_id + (r.ff_code ? ' · ff ' + r.ff_code : ''),
                        }));
                }
            } catch (e) {
                // Non-fatal — operator can still submit with default resolution.
            }
        },

        closeCreateModal() {
            this.createModal.open = false;
            this.createModal.row  = null;
        },

        async submitCreate() {
            if (!this.createModal.row) return;
            this.createModal.saving = true;
            this.createModal.error  = '';
            try {
                const body = {
                    ff_item_type:         this.createModal.row.ff_item_type,
                    ff_item_type_variant: this.createModal.row.ff_item_type_variant,
                };
                if (this.createModal.overrideAccountId) {
                    body.override_income_account_id = this.createModal.overrideAccountId;
                }
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/items/create_qbo_item.php'), body);
                if (j.success) {
                    this.flash = {
                        message: 'Created QBO Item "' + j.data.qbo_name + '" (Id=' + j.data.qbo_id + '); IncomeAccountRef=' + j.data.income_account_id + '.',
                        type: 'success',
                    };
                    this.closeCreateModal();
                    await this.reload();
                } else {
                    this.createModal.error = (j.error && j.error.message) || 'Create failed';
                }
            } catch (e) {
                this.createModal.error = e.message || 'Network error';
            } finally {
                this.createModal.saving = false;
            }
        },

        async unlinkRow(row)   { if (!confirm('Unlink this mapping?')) return; await this.callSave({ action: 'unlink', mapping_id: row.mapping_id }); },
        async ignoreRow(row)   { await this.callSave({ action: 'ignore', mapping_id: row.mapping_id }); },
        async unignoreRow(row) { await this.callSave({ action: 'unignore', mapping_id: row.mapping_id }); },

        async callSave(body) {
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/items/save_mapping.php'), body);
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

        formatTs(s) {
            if (!s) return '—';
            try { return new Date(String(s).replace(' ', 'T')).toLocaleString(); } catch { return s; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
