<?php declare(strict_types=1);

/**
 * FleetForge — Depreciation Runs
 *
 * @file        app/admin/accounting/depreciation/index.php
 * @description Depreciation run management page. KPI tiles show total runs,
 *              preview / posted counts, and total depreciation in the current
 *              fiscal year. Filter by status and period. The data table lists
 *              every run with status, asset count, total depreciation, JE
 *              number, and actions. "+ Generate Preview" opens a modal that
 *              accepts a period_id and (optionally) per-asset unit overrides
 *              for UOP assets. View modal shows the per-asset breakdown.
 *              Preview rows can be posted (creates the consolidated JE).
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/depreciation/*,
 *              api/v1/accounting/periods/*
 *
 * @session     S029 — Fixed Assets module (depreciation runs UI)
 */

// dirname(__DIR__, 4): depreciation/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('fixed_assets', 'view');

// ── KPI tiles — server-side for accuracy on initial load ───────
// WHY: Server-side counts let the page render meaningful numbers even
// before the Alpine fetch resolves, and they double as a sanity check
// against what the API returns.
$totalRuns    = db_count("SELECT COUNT(*) FROM acc_depreciation_runs");
$previewRuns  = db_count("SELECT COUNT(*) FROM acc_depreciation_runs WHERE status = 'preview'");
$postedRuns   = db_count("SELECT COUNT(*) FROM acc_depreciation_runs WHERE status = 'posted'");
$ytdTotal     = db_row(
    "SELECT COALESCE(SUM(r.total_depreciation), 0) AS total
     FROM acc_depreciation_runs r
     JOIN acc_periods p ON p.id = r.period_id
     WHERE r.status = 'posted' AND p.year = YEAR(CURDATE())"
)['total'] ?? '0.00';

$pageTitle = 'Depreciation Runs';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Depreciation Runs</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Depreciation Runs</h1>
    <div class="page-header-actions">
        <?php if (can('fixed_assets', 'edit')): ?>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-depr-preview')">
            + Generate Preview
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── KPI Tiles ───────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Total Runs</div>
        <div class="stat-value font-mono"><?= e((string) $totalRuns) ?></div>
    </div>
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Preview</div>
        <div class="stat-value font-mono"><?= e((string) $previewRuns) ?></div>
    </div>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Posted</div>
        <div class="stat-value font-mono"><?= e((string) $postedRuns) ?></div>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><svg><use href="#icon-banknotes"/></svg></span>
        <div class="stat-label">YTD Posted Depreciation</div>
        <div class="stat-value font-mono">$<?= number_format((float) $ytdTotal, 2) ?></div>
    </div>
</div>

<!-- ============================================================
     DEPRECIATION RUNS — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_DepreciationRuns()" x-init="init()" @open-depr-preview.window="openPreview()">

    <!-- ── Filter toolbar ────────────────────────────────────── -->
    <div class="table-toolbar" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select class="form-select form-control-sm" x-model="filterStatus" @change="load()" style="min-width:140px;">
            <option value="">All statuses</option>
            <option value="preview">Preview</option>
            <option value="posted">Posted</option>
            <option value="reversed">Reversed</option>
        </select>

        <select class="form-select form-control-sm" x-model="filterPeriod" @change="load()" style="min-width:200px;">
            <option value="">All periods</option>
            <template x-for="p in periods" :key="p.id">
                <option :value="p.id" x-text="p.name + ' (' + p.status + ')'"></option>
            </template>
        </select>

        <span class="text-secondary text-sm" x-text="pagination.total + ' run' + (pagination.total !== 1 ? 's' : '')"></span>
    </div>

    <!-- ── Loading state ─────────────────────────────────────── -->
    <template x-if="loading">
        <div class="card"><div class="empty-state">Loading…</div></div>
    </template>

    <!-- ── Empty state ───────────────────────────────────────── -->
    <template x-if="!loading && runs.length === 0">
        <div class="card"><div class="empty-state">
            <p class="empty-state-title">No depreciation runs yet</p>
            <p class="empty-state-text">Click "+ Generate Preview" above to create one for an open period.</p>
        </div></div>
    </template>

    <!-- ── Runs table ────────────────────────────────────────── -->
    <template x-if="!loading && runs.length > 0">
        <div class="card" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Run #</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th class="text-right">Assets</th>
                        <th class="text-right">Total Depreciation</th>
                        <th>Run Date</th>
                        <th>JE #</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in runs" :key="r.id">
                        <tr>
                            <td class="font-mono" x-text="'#' + r.id"></td>
                            <td x-text="r.period_name || '—'"></td>
                            <td><span class="badge badge-no-dot" :class="statusBadge(r.status)" x-text="r.status"></span></td>
                            <td class="text-right font-mono" x-text="r.asset_count"></td>
                            <td class="text-right font-mono" x-text="formatMoney(r.total_depreciation)"></td>
                            <td class="text-sm" x-text="(r.run_date || '').slice(0,16).replace('T',' ')"></td>
                            <td class="font-mono text-sm" x-text="r.journal_entry_id ? '#' + r.journal_entry_id : '—'"></td>
                            <td>
                                <button class="btn btn-secondary btn-xs" @click="openDetail(r.id)">View</button>
                                <?php if (can('fixed_assets', 'edit')): ?>
                                <template x-if="r.status === 'preview'">
                                    <button class="btn btn-success btn-xs" @click="postRun(r.id)">Post</button>
                                </template>
                                <template x-if="r.status === 'posted'">
                                    <button class="btn btn-warning btn-xs" @click="reverseRun(r.id)">Reverse</button>
                                </template>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- ── Pagination ────────────────────────────────────────── -->
    <div class="table-pagination" x-show="pagination.total_pages > 1" style="margin-top:16px;text-align:center;">
        <button class="btn btn-secondary btn-sm" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">‹ Prev</button>
        <span x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages" style="margin:0 12px;"></span>
        <button class="btn btn-secondary btn-sm" :disabled="!pagination.has_more" @click="goPage(pagination.page + 1)">Next ›</button>
    </div>

    <!-- ============================================================
         DETAIL MODAL — full run + per-asset lines
         ============================================================ -->
    <div class="modal-backdrop" x-show="detailOpen" x-transition @click.self="detailOpen = false" style="display:none;">
        <div class="modal" style="max-width:900px;width:95%;max-height:85vh;overflow-y:auto;">
            <div class="modal-header">
                <h2 class="h5" x-text="detailRun ? 'Depreciation Run #' + detailRun.id + ' — ' + (detailRun.period_name || '') : ''"></h2>
                <button class="modal-close" @click="detailOpen = false">×</button>
            </div>
            <div class="modal-body">
                <template x-if="detailLoading">
                    <p>Loading…</p>
                </template>
                <template x-if="!detailLoading && detailRun">
                    <div>
                        <!-- Summary grid -->
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px 24px;margin-bottom:18px;">
                            <div><strong>Period:</strong> <span x-text="detailRun.period_name || '—'"></span></div>
                            <div><strong>Status:</strong> <span class="badge badge-no-dot" :class="statusBadge(detailRun.status)" x-text="detailRun.status"></span></div>
                            <div><strong>Asset Count:</strong> <span class="font-mono" x-text="detailRun.asset_count"></span></div>
                            <div><strong>Total:</strong> <span class="font-mono" x-text="formatMoney(detailRun.total_depreciation)"></span></div>
                            <div><strong>Run Date:</strong> <span class="text-sm" x-text="(detailRun.run_date || '').slice(0,16).replace('T',' ')"></span></div>
                            <div><strong>JE #:</strong> <span class="font-mono" x-text="detailRun.journal_entry_id ? '#' + detailRun.journal_entry_id : '— (preview)'"></span></div>
                            <template x-if="detailRun.notes">
                                <div style="grid-column: 1 / -1;"><strong>Notes:</strong> <span class="text-sm" x-text="detailRun.notes"></span></div>
                            </template>
                        </div>

                        <!-- Per-asset breakdown -->
                        <h3 class="h6" style="margin-top:16px;margin-bottom:8px;">Per-Asset Breakdown</h3>
                        <template x-if="detailLines.length === 0">
                            <p class="text-secondary text-sm">No lines (zero-amount run).</p>
                        </template>
                        <template x-if="detailLines.length > 0">
                            <div style="max-height:340px;overflow-y:auto;border:1px solid var(--border-default);border-radius:6px;">
                                <table class="data-table" style="font-size:0.8125rem;">
                                    <thead>
                                        <tr>
                                            <th>Asset #</th>
                                            <th>Name</th>
                                            <th>Method</th>
                                            <th class="text-right">Opening NBV</th>
                                            <th class="text-right">Depreciation</th>
                                            <th class="text-right">Closing NBV</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="ln in detailLines" :key="ln.id">
                                            <tr>
                                                <td class="font-mono" x-text="ln.asset_number"></td>
                                                <td x-text="ln.asset_name"></td>
                                                <td x-text="formatMethod(ln.method_used)"></td>
                                                <td class="text-right font-mono" x-text="formatMoney(ln.opening_nbv)"></td>
                                                <td class="text-right font-mono" x-text="formatMoney(ln.depreciation)"></td>
                                                <td class="text-right font-mono" x-text="formatMoney(ln.closing_nbv)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div class="modal-footer">
                <?php if (can('fixed_assets', 'edit')): ?>
                <button class="btn btn-success btn-sm"
                        x-show="detailRun && detailRun.status === 'preview'"
                        @click="postRun(detailRun.id); detailOpen = false">Post to GL</button>
                <button class="btn btn-warning btn-sm"
                        x-show="detailRun && detailRun.status === 'posted'"
                        @click="reverseRun(detailRun.id); detailOpen = false">Reverse Run</button>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" @click="detailOpen = false">Close</button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PREVIEW (Generate) MODAL
         ============================================================ -->
    <div class="modal-backdrop" x-show="previewOpen" x-transition @click.self="previewOpen = false" style="display:none;">
        <div class="modal" style="max-width:560px;width:95%;">
            <div class="modal-header"><h2 class="h5">Generate Depreciation Preview</h2><button class="modal-close" @click="previewOpen = false">×</button></div>
            <div class="modal-body">
                <div class="form-error-banner" x-show="previewFormError" x-cloak x-text="previewFormError"></div>
                <p class="text-secondary text-sm">Select an open period. A preview run will be generated showing each active asset's depreciation. You can then review and post it to create the consolidated JE.</p>
                <div class="form-group"><label>Period *</label>
                    <select class="form-select form-control-sm" x-model="previewForm.period_id"
                            :class="previewErrors.period_id ? 'is-invalid' : ''"
                            @change="previewErrors.period_id = ''">
                        <option value="">— Choose period —</option>
                        <template x-for="p in periods.filter(p => p.status === 'open')" :key="p.id">
                            <option :value="p.id" x-text="p.name"></option>
                        </template>
                    </select>
                    <div class="field-error" x-show="previewErrors.period_id" x-cloak x-text="previewErrors.period_id"></div>
                </div>
                <p class="text-warning text-sm">Re-running on a period with an existing preview will replace it. Periods with a posted run are blocked.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="previewOpen = false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="submitPreview()" :disabled="previewBusy">
                    <span x-show="!previewBusy">Generate Preview</span><span x-show="previewBusy">Generating…</span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_DepreciationRuns() {
    return {
        // ── State ──────────────────────────────────────────────
        runs: [],
        periods: [],
        loading: true,
        filterStatus: '',
        filterPeriod: '',
        pagination: { total: 0, page: 1, per_page: 25, total_pages: 0, has_more: false },

        // ── Detail ─────────────────────────────────────────────
        detailOpen: false,
        detailLoading: false,
        detailRun: null,
        detailLines: [],

        // ── Preview ────────────────────────────────────────────
        previewOpen: false,
        previewBusy: false,
        previewForm: { period_id: '' },
        previewFormError: '',
        previewErrors: { period_id: '' },

        // ── VALID-2: Error helpers ──────────────────────────────
        _extractError(r, fallback) {
            if (r && r.error) {
                if (typeof r.error === 'string') return r.error;
                if (r.error.message) return r.error.message;
            }
            if (r && r.message) return r.message;
            return fallback || 'Something went wrong.';
        },
        _clearErrors(errorsObj, bannerKey) {
            this[bannerKey] = '';
            for (const k in errorsObj) {
                if (Object.prototype.hasOwnProperty.call(errorsObj, k)) errorsObj[k] = '';
            }
        },
        _paintErrors(errorsObj, bannerKey, r, fallback) {
            const fields = (r && r.error && r.error.fields) || (r && r.fields) || null;
            if (fields && typeof fields === 'object') {
                let any = false;
                for (const k in fields) {
                    if (!Object.prototype.hasOwnProperty.call(fields, k)) continue;
                    if (Object.prototype.hasOwnProperty.call(errorsObj, k)) {
                        errorsObj[k] = fields[k];
                        any = true;
                    } else if (k === '_general' || k === '') {
                        this[bannerKey] = fields[k];
                        any = true;
                    }
                }
                if (!any) this[bannerKey] = this._extractError(r, fallback);
            } else {
                this[bannerKey] = this._extractError(r, fallback);
            }
        },
        validatePreview() {
            this._clearErrors(this.previewErrors, 'previewFormError');
            let ok = true;
            if (!this.previewForm.period_id) {
                this.previewErrors.period_id = 'Please select a period.';
                ok = false;
            }
            return ok;
        },

        // ── Init ───────────────────────────────────────────────
        async init() {
            await this.loadPeriods();
            await this.load();
        },

        async loadPeriods() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/periods/index.php') ?>?year=' + (new Date().getFullYear()));
                if (r.success) this.periods = r.data.periods;
            } catch (e) { /* non-fatal */ }
        },

        // ── Load ───────────────────────────────────────────────
        async load() {
            this.loading = true;
            const params = new URLSearchParams();
            if (this.filterStatus) params.set('status', this.filterStatus);
            if (this.filterPeriod) params.set('period_id', this.filterPeriod);
            params.set('page', String(this.pagination.page));
            params.set('per_page', String(this.pagination.per_page));
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/depreciation/index.php') ?>?' + params.toString());
                if (r.success) {
                    this.runs = r.data.items;
                    this.pagination = r.data.pagination;
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load runs.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.loading = false;
        },

        goPage(p) {
            this.pagination.page = p;
            this.load();
        },

        // ── Detail ─────────────────────────────────────────────
        async openDetail(id) {
            this.detailOpen = true;
            this.detailLoading = true;
            this.detailRun = null;
            this.detailLines = [];
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/depreciation/show.php') ?>?id=' + id);
                if (r.success) {
                    this.detailRun = r.data.run;
                    this.detailLines = r.data.lines || [];
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load run.');
                    this.detailOpen = false;
                }
            } catch (e) {
                FF_Toast.error('Network error.');
                this.detailOpen = false;
            }
            this.detailLoading = false;
        },

        // ── Preview generation ─────────────────────────────────
        openPreview() {
            this.previewForm = { period_id: '' };
            this._clearErrors(this.previewErrors, 'previewFormError');
            this.previewOpen = true;
        },
        async submitPreview() {
            if (!this.validatePreview()) return;
            this.previewBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/depreciation/preview.php') ?>', {
                    period_id: parseInt(this.previewForm.period_id),
                });
                if (r.success) {
                    FF_Toast.success('Preview generated. ' + r.data.run.asset_count + ' assets, total $' + parseFloat(r.data.run.total_depreciation).toFixed(2));
                    this.previewOpen = false;
                    await this.load();
                } else {
                    this._paintErrors(this.previewErrors, 'previewFormError', r, 'Failed to generate preview.');
                }
            } catch (e) { this.previewFormError = 'Network error. Please try again.'; }
            this.previewBusy = false;
        },

        // ── Post to GL ─────────────────────────────────────────
        async postRun(id) {
            if (!confirm('Post this depreciation run to the general ledger? This creates a JE and updates each asset\'s accumulated depreciation.')) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/depreciation/post.php') ?>', { run_id: id });
                if (r.success) {
                    FF_Toast.success('Posted. JE #' + (r.data.journal_entry_id || '?'));
                    await this.load();
                } else {
                    FF_Toast.error(r.error?.message || 'Post failed.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
        },

        // ── Reverse a posted run ───────────────────────────────
        async reverseRun(id) {
            if (!confirm('Reverse this depreciation run? This creates a reversal JE (DR/CR swapped), restores asset NBVs, and marks the original run as reversed. This action is logged in the audit trail.')) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/depreciation/reverse.php') ?>', { run_id: id });
                if (r.success) {
                    FF_Toast.success('Reversed. Reversal JE #' + (r.data.reversal_je?.id || '?'));
                    await this.load();
                } else {
                    FF_Toast.error(r.error?.message || 'Reverse failed.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
        },

        // ── Display helpers ────────────────────────────────────
        formatMoney(s) {
            if (s === null || s === undefined) return '—';
            return '$' + parseFloat(s).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        formatMethod(m) {
            const map = { straight_line:'Straight Line', declining_balance:'Declining Balance', units_of_production:'Units of Production', none:'None' };
            return map[m] || m;
        },
        statusBadge(s) {
            const m = { preview:'badge-amber', posted:'badge-green', reversed:'badge-neutral' };
            return m[s] || 'badge-neutral';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
