<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/tax_codes.php
 *
 * Tax Code Mapping — FF ↔ QuickBooks. Puller-only per D-QBO-9-1
 * (accountant owns tax codes in QBO; FF mirrors via mapping).
 *
 * Distinguishing UI element: override-target banner (D-QBO-9-2).
 * Green banner when QBO's 'NON' tax code has been identified and
 * settings.quickbooks.tax_override_code_id is set — that single
 * setting unlocks S-QBO-11 invoice push. Red banner when it's not
 * resolved (sandbox didn't return NON for some reason; rare).
 *
 * Lighter than accounts.php — no type grouping, no critical-account
 * validator. The chief UX work is the override-target indicator +
 * the "Show historical" toggle for FF effective-dated tax_rates rows.
 *
 * @session  S-QBO-9
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.2 (tax code mapping table)
 * @gate     require_permission('quickbooks', 'view')
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Tax Codes';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Tax Codes</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Tax Code Mapping</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Map FleetForge tax rates to QuickBooks tax codes. FleetForge computes taxes authoritatively;
        QBO uses these mappings for reporting parity. The single critical mapping is the
        <strong>'NON' override target</strong> — identified automatically on Pull, used by S-QBO-11
        invoice push to send FF-computed tax totals without QBO recalculation.
    </div>
</div>

<div x-data="qboTaxCodeMapping()" x-init="init()">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Override target banner (D-QBO-9-2) ─────────────────── -->
    <template x-if="kpis && kpis.override_target_set === 1 && overrideTargetRow">
        <div class="alert alert-success" style="margin-bottom:14px;">
            <strong>✓ Override target resolved:</strong>
            QBO TaxCode <span class="font-mono" x-text="'#' + overrideTargetRow.qbo_tax_code_id"></span>
            <span x-text="'(' + (overrideTargetRow.qbo_name || 'NON') + ')'"></span>.
            FF→QBO invoice push (S-QBO-11) will route all line-level tax through this code.
        </div>
    </template>

    <template x-if="kpis && kpis.override_target_set === 0 && lastPulledAt">
        <div class="alert alert-danger" style="margin-bottom:14px;">
            <strong>⚠ No override target identified.</strong>
            QBO 'NON' tax code not found in the last Pull. S-QBO-11 invoice push will be blocked
            until this is resolved. Try <button class="btn btn-sm btn-outline" @click="runPull()">Pull from QuickBooks</button>
            again, or contact your QuickBooks admin (NON should be auto-seeded).
        </div>
    </template>

    <!-- ── Action bar ──────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div class="text-sm text-secondary">
            Last pulled: <span class="font-mono" x-text="lastPulledAt ? formatTs(lastPulledAt) : '— never —'"></span>
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:0.875rem;">
                <input type="checkbox" x-model="filters.show_historical" @change="page=1; reload()">
                Show historical FF rates
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
            <div class="text-secondary text-sm">FF only</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#d97706;" x-text="kpis ? kpis.ff_only : '—'"></div>
            <div class="text-secondary text-sm">unmapped FF rates</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">QBO only</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#0284c7;" x-text="kpis ? kpis.qbo_only : '—'"></div>
            <div class="text-secondary text-sm">unmapped QBO codes</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Ignored</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#6b7280;" x-text="kpis ? kpis.ignored : '—'"></div>
            <div class="text-secondary text-sm">intentionally unmapped</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Override target</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;"
                 :style="kpis && kpis.override_target_set === 1 ? 'color:#16a34a;' : 'color:#dc2626;'"
                 x-text="kpis ? (kpis.override_target_set === 1 ? '✓' : '✗') : '—'"></div>
            <div class="text-secondary text-sm" x-text="kpis && kpis.override_target_set === 1 ? 'NON identified' : 'NON not found'"></div>
        </div>
    </div>

    <!-- ── Filters bar ─────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;align-items:flex-end;">
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
            <input type="text" class="form-control form-control-sm" placeholder="name, province…"
                   x-model.debounce.350ms="filters.q" @input="page=1; reload()">
        </div>
        <div style="text-align:right;">
            <span class="text-sm text-secondary" x-text="total + ' rows'"></span>
        </div>
    </div>

    <!-- ── Empty state ─────────────────────────────────────────── -->
    <template x-if="!loading && rows.length === 0 && (kpis === null || (kpis.mapped + kpis.ff_only + kpis.qbo_only + kpis.ignored) === 0)">
        <div class="card" style="padding:32px;text-align:center;">
            <div class="h5" style="margin-bottom:8px;">No tax code mappings yet</div>
            <div class="text-secondary text-sm" style="margin-bottom:16px;">
                Click <strong>Pull from QuickBooks</strong> to fetch your sandbox tax codes,<br>
                then <strong>Auto-Match</strong> to suggest pairings against FleetForge tax rates.
            </div>
        </div>
    </template>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <template x-if="rows.length > 0">
        <div class="table-wrapper">
            <table class="table table-sm" style="margin:0;">
                <thead>
                    <tr>
                        <th>FF Tax Rate</th>
                        <th>FF Rates (GST/PST/HST)</th>
                        <th>QBO Tax Code</th>
                        <th>Status</th>
                        <th>Confidence</th>
                        <th style="text-align:center;" title="Override target — the QBO TaxCode used in S-QBO-11 invoice push">★</th>
                        <th style="width:280px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.mapping_id">
                        <tr :class="row.is_override_target == 1 ? 'override-target-row' : ''">
                            <td>
                                <template x-if="row.ff_tax_rate_id">
                                    <div>
                                        <div x-text="row.ff_name || '#' + row.ff_tax_rate_id"></div>
                                        <div class="text-sm text-secondary">
                                            <span x-text="row.ff_province ? row.ff_province + ' · ' : ''"></span>
                                            <template x-if="row.ff_effective_to">
                                                <span class="badge badge-warning" style="font-size:0.7rem;">historical → <span x-text="row.ff_effective_to"></span></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!row.ff_tax_rate_id">
                                    <span class="text-secondary">—</span>
                                </template>
                            </td>
                            <td class="font-mono text-sm">
                                <template x-if="row.ff_tax_rate_id">
                                    <span x-text="formatRates(row.ff_gst_rate, row.ff_pst_rate, row.ff_hst_rate)"></span>
                                </template>
                                <template x-if="!row.ff_tax_rate_id"><span class="text-secondary">—</span></template>
                            </td>
                            <td>
                                <template x-if="row.qbo_tax_code_id">
                                    <div>
                                        <div x-text="row.qbo_name || '(no name)'"></div>
                                        <div class="text-sm text-secondary font-mono">
                                            <span x-text="'qbo #' + row.qbo_tax_code_id"></span>
                                            <template x-if="row.qbo_tax_group == 1">
                                                <span class="badge badge-outline" style="margin-left:6px;">group</span>
                                            </template>
                                            <template x-if="row.qbo_active != 1">
                                                <span class="badge badge-secondary" style="margin-left:6px;">inactive</span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!row.qbo_tax_code_id"><span class="text-secondary">—</span></template>
                            </td>
                            <td>
                                <span class="badge" :class="statusBadgeClass(row.mapping_status)" x-text="row.mapping_status"></span>
                            </td>
                            <td>
                                <span class="badge badge-outline" x-text="row.match_confidence || '—'"></span>
                            </td>
                            <td style="text-align:center;">
                                <template x-if="row.is_override_target == 1">
                                    <span class="badge badge-success" title="QBO 'NON' override target — drives S-QBO-11 invoice push">★</span>
                                </template>
                                <template x-if="row.is_override_target != 1">
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
                                    <template x-if="row.qbo_tax_code_id && row.is_override_target != 1">
                                        <button class="btn btn-sm btn-outline" @click="setOverrideTarget(row)" title="Make this QBO TaxCode the S-QBO-11 override target">★ Set override</button>
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
                <h3 class="h5" style="margin:0;" x-text="linkModal.mode === 'pick_qbo' ? 'Link FF tax rate to QBO' : 'Link QBO tax code to FF'"></h3>
                <button class="modal-close-btn" @click="closeLinkModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-sm text-secondary" style="margin-bottom:14px;">
                    <strong>FF:</strong> <span x-text="linkModal.row && linkModal.row.ff_name ? (linkModal.row.ff_name + ' (' + (linkModal.row.ff_province || '?') + ')') : '—'"></span><br>
                    <strong>QBO:</strong> <span x-text="linkModal.row && linkModal.row.qbo_name ? linkModal.row.qbo_name : '—'"></span>
                </div>

                <label class="form-label" x-text="linkModal.mode === 'pick_qbo' ? 'Pick QBO tax code' : 'Pick FF tax rate'"></label>
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
    .override-target-row td {
        background-color: rgba(22, 163, 74, 0.06);
    }
    .override-target-row td:first-child {
        box-shadow: inset 3px 0 0 0 #16a34a;
    }
</style>

<script>
function qboTaxCodeMapping() {
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
        overrideTargetRow: null,
        flash: { message: '', type: '' },
        filters: { status: 'all', q: '', show_historical: false },

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
                    status:          this.filters.status,
                    q:               this.filters.q || '',
                    show_historical: this.filters.show_historical ? 'true' : 'false',
                    page:            String(this.page),
                    page_size:       String(this.pageSize),
                });
                const j = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/tax_codes/list.php?' + params.toString()));
                if (j.success) {
                    const d = j.data;
                    this.rows              = d.rows;
                    this.total             = d.pagination.total;
                    this.totalPages        = d.pagination.total_pages;
                    this.kpis              = d.kpis;
                    this.lastPulledAt      = d.last_pulled_at;
                    this.overrideTargetRow = d.override_target_row;
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
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/tax_codes/pull.php'), {});
                if (j.success) {
                    const d = j.data;
                    const overrideMsg = d.override_resolved
                        ? ' Override target: ' + d.override_target_name + ' (qbo#' + d.override_target_id + ').'
                        : ' ⚠ NON not found — override target NOT resolved.';
                    this.flash = {
                        message: 'Pulled ' + d.pulled_count + ' QBO tax codes (' + d.inserted + ' new, ' + d.updated + ' updated).' + overrideMsg,
                        type: d.override_resolved ? 'success' : 'error',
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
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/tax_codes/auto_match.php'), {});
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

            const wantedStatus = mode === 'pick_qbo' ? 'qbo_only' : 'ff_only';
            const params = new URLSearchParams({
                status:          wantedStatus,
                q:               '',
                show_historical: 'true',
                page:            '1',
                page_size:       '200',
            });
            FF_Api.get(FF_Api.url('/api/v1/quickbooks/tax_codes/list.php?' + params.toString()))
                .then(j => {
                    if (!j.success) { this.linkModal.error = (j.error && j.error.message) || 'Failed to load candidates'; return; }
                    this.linkModal.options = j.data.rows.map(r => mode === 'pick_qbo'
                        ? { id: r.qbo_tax_code_id, label: (r.qbo_name || '(no name)') + ' · qbo#' + r.qbo_tax_code_id + (r.qbo_tax_group == 1 ? ' [group]' : '') }
                        : { id: r.ff_tax_rate_id,  label: (r.ff_name || '(no name)') + ' (' + (r.ff_province || '?') + ') · ff#' + r.ff_tax_rate_id }
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
                    ? { action: 'link', ff_tax_rate_id: this.linkModal.row.ff_tax_rate_id, qbo_tax_code_id: this.linkModal.selectedId, notes: this.linkModal.notes }
                    : { action: 'link', ff_tax_rate_id: this.linkModal.selectedId,        qbo_tax_code_id: this.linkModal.row.qbo_tax_code_id, notes: this.linkModal.notes };
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/tax_codes/save_mapping.php'), body);
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
        async setOverrideTarget(row) {
            if (!confirm('Set this QBO TaxCode as the override target? This unwires any prior target and updates settings.quickbooks.tax_override_code_id (used by S-QBO-11 invoice push).')) return;
            await this.callSave({ action: 'set_override_target', mapping_id: row.mapping_id });
        },

        async callSave(body) {
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/tax_codes/save_mapping.php'), body);
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

        formatRates(gst, pst, hst) {
            // FF stores rates as decimals (0.05 = 5%). Show as percentages.
            const parts = [];
            const g = parseFloat(gst); if (g > 0) parts.push('GST ' + (g * 100).toFixed(3).replace(/\.?0+$/, '') + '%');
            const p = parseFloat(pst); if (p > 0) parts.push('PST ' + (p * 100).toFixed(3).replace(/\.?0+$/, '') + '%');
            const h = parseFloat(hst); if (h > 0) parts.push('HST ' + (h * 100).toFixed(3).replace(/\.?0+$/, '') + '%');
            return parts.length === 0 ? '—' : parts.join(' + ');
        },

        formatTs(s) {
            if (!s) return '—';
            try { return new Date(String(s).replace(' ', 'T')).toLocaleString(); } catch { return s; }
        },
    };
}
</script>

<?php
// ── F9: ITC tax-rate mapping section (S-QBO-BILL-ITC-TAX-RATE) ──────────────
// Self-contained sibling component. Maps FF tax components (gst/pst/hst) → QBO
// TaxRate.Ids + flips the bill tax-emission mode. Default-off: the proven
// override path is untouched until tax_mode='per_rate' AND rates are mapped.
$billTaxMode = (string) settings_get('quickbooks.bill.tax_mode', 'override');
$rateRows = db_select("SELECT ff_tax_component, qbo_tax_rate_id, qbo_tax_rate_name, mapping_status FROM acc_qbo_tax_rate_map");
$rateMap = ['gst' => ['id'=>'','name'=>''], 'pst' => ['id'=>'','name'=>''], 'hst' => ['id'=>'','name'=>'']];
foreach ($rateRows as $rr) {
    $rateMap[$rr['ff_tax_component']] = [
        'id'   => (string) ($rr['qbo_tax_rate_id'] ?? ''),
        'name' => (string) ($rr['qbo_tax_rate_name'] ?? ''),
    ];
}
$canEditTaxRate = can('quickbooks', 'edit_credentials');
?>
<div x-data="qboTaxRateMapping()" style="margin-top:32px;max-width:880px;">
    <div class="page-header">
        <h2 class="h5" style="margin:0;">ITC Tax-Rate Mapping (bill push)</h2>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            Optional per-rate bill tax. <strong>Default-off</strong> — bills push with the proven tax-override
            pattern (every line <code>TaxCodeRef=NON</code> + header <code>TotalTax</code>). Switch to
            <strong>per-rate</strong> to expose the recoverable GST/HST Input Tax Credit as a QBO tax-rate line
            (spec §8.8). Map each component to its QBO <code>TaxRate.Id</code> first — per-rate falls back to
            override on any unmapped non-zero component, so it can never ship a wrong tax detail.
            The QBO TaxRate ids are confirmed against the live company at cutover (F9 live-verify).
        </div>
    </div>

    <div x-show="flash.message" x-cloak :class="flash.type==='success'?'alert alert-success':'alert alert-danger'"
         style="margin:14px 0;" x-text="flash.message"></div>

    <div class="card" style="padding:18px 20px;">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px;">
            <label class="form-label" style="margin:0;">Bill tax mode:</label>
            <select class="form-control" style="max-width:240px;" x-model="taxMode" :disabled="!canEdit">
                <option value="override">override (proven; default)</option>
                <option value="per_rate">per_rate (ITC tax lines)</option>
            </select>
            <span class="badge" :class="taxMode==='per_rate'?'badge-warning':'badge-secondary'"
                  x-text="taxMode==='per_rate'?'PER-RATE (opt-in)':'OVERRIDE (safe)'"></span>
        </div>

        <table class="table" style="margin:0;">
            <thead><tr><th>FF component</th><th>QBO TaxRate.Id</th><th>QBO TaxRate name</th><th>Status</th></tr></thead>
            <tbody>
                <template x-for="c in ['gst','pst','hst']" :key="c">
                    <tr>
                        <td class="font-mono" x-text="c.toUpperCase()"></td>
                        <td><input type="text" class="form-control" style="max-width:200px;" x-model="rates[c].id" :disabled="!canEdit" placeholder="(unmapped)"></td>
                        <td><input type="text" class="form-control" x-model="rates[c].name" :disabled="!canEdit" placeholder="e.g. GST 5% (ITC)"></td>
                        <td>
                            <span class="badge" :class="(rates[c].id && rates[c].id.trim()) ? 'badge-success' : 'badge-secondary'"
                                  x-text="(rates[c].id && rates[c].id.trim()) ? 'mapped' : 'unmapped'"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <template x-if="canEdit">
            <div style="margin-top:16px;">
                <button class="btn btn-primary btn-sm" @click="save()" :disabled="saving">
                    <span x-show="!saving">Save tax-rate mapping</span>
                    <span x-show="saving" x-cloak>Saving…</span>
                </button>
            </div>
        </template>
    </div>
</div>

<script>
function qboTaxRateMapping() {
    return {
        canEdit: <?= $canEditTaxRate ? 'true' : 'false' ?>,
        saving: false,
        flash: { message: '', type: 'success' },
        taxMode: '<?= e($billTaxMode) ?>',
        rates: {
            gst: { id: '<?= e($rateMap['gst']['id']) ?>', name: '<?= e($rateMap['gst']['name']) ?>' },
            pst: { id: '<?= e($rateMap['pst']['id']) ?>', name: '<?= e($rateMap['pst']['name']) ?>' },
            hst: { id: '<?= e($rateMap['hst']['id']) ?>', name: '<?= e($rateMap['hst']['name']) ?>' },
        },
        async save() {
            this.saving = true; this.flash = { message: '', type: 'success' };
            try {
                const payload = {
                    tax_mode: this.taxMode,
                    components: {
                        gst: { id: (this.rates.gst.id||'').trim(), name: (this.rates.gst.name||'').trim() },
                        pst: { id: (this.rates.pst.id||'').trim(), name: (this.rates.pst.name||'').trim() },
                        hst: { id: (this.rates.hst.id||'').trim(), name: (this.rates.hst.name||'').trim() },
                    },
                };
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/save_tax_rate_map.php'), payload);
                if (r.success) { this.flash = { message: 'Tax-rate mapping saved (mode: ' + ((r.data && r.data.tax_mode) || this.taxMode) + ').', type: 'success' }; }
                else { this.flash = { message: (r.error && r.error.message) || 'Save failed.', type: 'error' }; }
            } catch (e) { this.flash = { message: e.message || 'Network error', type: 'error' }; }
            finally { this.saving = false; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
