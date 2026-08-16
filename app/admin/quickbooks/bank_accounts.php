<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/bank_accounts.php
 *
 * Phase QBO-9 / 1 of 1 (S-QBO-20) — Bank Account Mapping +
 * Read-Only CDC Mirror admin surface.
 *
 * Mirrors /quickbooks/accounts shape but for bank-specific mappings
 * (filter: FF acc_bank_accounts rows; QBO AccountType IN Bank /
 * CreditCard). Unlike Accounts, this is a SIMPLER mapping surface —
 * acc_qbo_bank_account_map has no ff_only/qbo_only split-state (FF rows
 * either have a map row or they don't), no critical-flag concept (every
 * bank account is critical for downstream bill_payment + payment push),
 * and no auto-match cascade (the FF list is short enough that operator
 * pick is faster than tuning a cascade).
 *
 * Layout sections:
 *   - Action bar: Pull from QuickBooks + Verify Mappings + Run CDC Now
 *   - 4 KPI tiles (mapped / unmapped FF / mapping_drift / last_bank_cdc_at)
 *   - Main table: every FF acc_bank_accounts row, joined to map state
 *   - Per-row actions: Link/Change QBO | Unmap
 *   - Link modal: top-10 ranked candidates from BankAccountMatcher::getCandidates
 *
 * @session  S-QBO-20
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.11 (Bank Account),
 *           §8.12 (Bank Transaction read-only mirror)
 * @gate     require_permission('quickbooks', 'view')
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Bank Accounts';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Bank Accounts</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Bank Account Mapping</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Link FleetForge bank accounts to their QuickBooks counterparts. The daily CDC cron
        (<code>qbo_bank_cdc.php</code> at 02:30) pulls bank transactions per mapped account into
        FF as read-only mirror rows. Used by payment + bill-payment push for DepositToAccountRef
        + BankAccountRef. <strong>This is Exception #2 per D-QBO-CORE-2 — QBO is canonical for
        bank reconciliation; FF rows are observational only.</strong>
    </div>
</div>

<div x-data="qboBankAccountMapping()">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Action bar ──────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div class="text-sm text-secondary">
            Last bank CDC pull:
            <span class="font-mono" x-text="lastBankCdcAt ? formatTs(lastBankCdcAt) : '— never —'"></span>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-secondary btn-sm" @click="runPull()" :disabled="pulling || cdcRunning || verifying">
                <span x-show="!pulling">Pull QBO bank accounts</span>
                <span x-show="pulling" x-cloak>Pulling…</span>
            </button>
            <button class="btn btn-secondary btn-sm" @click="runVerify()" :disabled="pulling || cdcRunning || verifying">
                <span x-show="!verifying">Verify mappings</span>
                <span x-show="verifying" x-cloak>Verifying…</span>
            </button>
            <button class="btn btn-primary btn-sm" @click="runCdc()" :disabled="pulling || cdcRunning || verifying">
                <span x-show="!cdcRunning">Run CDC now</span>
                <span x-show="cdcRunning" x-cloak>Running CDC…</span>
            </button>
        </div>
    </div>

    <!-- ── KPI tiles ───────────────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo">
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Mapped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#16a34a;" x-text="kpis ? kpis.mapped : '—'"></div>
            <div class="text-secondary text-sm">FF ↔ QBO linked</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Unmapped</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;"
                 :style="kpis && kpis.unmapped > 0 ? 'color:#d97706;' : 'color:#16a34a;'"
                 x-text="kpis ? kpis.unmapped : '—'"></div>
            <div class="text-secondary text-sm">FF accounts not linked yet</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">Drift detected</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;"
                 :style="kpis && kpis.conflict > 0 ? 'color:#dc2626;' : 'color:#16a34a;'"
                 x-text="kpis ? kpis.conflict : '—'"></div>
            <div class="text-secondary text-sm">QBO-side changes vs snapshot</div>
        </div>
        <div class="stat-card" style="padding:18px;">
            <div class="text-secondary text-sm">CDC mirror rows</div>
            <div style="font-size:2rem;font-weight:600;margin:4px 0;color:#0284c7;" x-text="kpis ? kpis.mirror_rows : '—'"></div>
            <div class="text-secondary text-sm">read-only QBO bank txns</div>
        </div>
    </div>

    <!-- ── Empty state ─────────────────────────────────────────── -->
    <template x-if="!loading && rows.length === 0">
        <div class="card" style="padding:32px;text-align:center;">
            <div class="h5" style="margin-bottom:8px;">No bank accounts yet</div>
            <div class="text-secondary text-sm">
                Create an FF bank account in <a :href="ffUrl('accounting/bank_accounts')">Accounting → Bank Accounts</a> first,
                then return here to link it to a QBO bank account.
            </div>
        </div>
    </template>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <template x-if="rows.length > 0">
        <div class="table-wrapper">
            <table class="table table-sm" style="margin:0;">
                <thead>
                    <tr>
                        <th style="width:25%;">FF Bank Account</th>
                        <th>FF Type/Currency</th>
                        <th style="width:30%;">QBO Account (snapshot)</th>
                        <th>Status</th>
                        <th>Last Synced</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.ff_bank_account_id">
                        <tr :class="row.mapping_status === 'conflict' ? 'drift-row' : ''">
                            <td>
                                <div x-text="row.ff_name"></div>
                                <div class="text-sm text-secondary font-mono"
                                     x-text="row.ff_institution ? row.ff_institution + (row.ff_last4 ? ' · ····' + row.ff_last4 : '') : ''"></div>
                            </td>
                            <td>
                                <span class="badge badge-outline" x-text="row.ff_account_type"></span>
                                <span class="badge badge-outline" x-text="row.ff_currency"></span>
                            </td>
                            <td>
                                <template x-if="row.qbo_bank_account_id">
                                    <div>
                                        <div x-text="row.qbo_name_snapshot || '(no name)'"></div>
                                        <div class="text-sm text-secondary font-mono">
                                            <span x-text="row.qbo_account_type_snapshot || ''"></span>
                                            <span x-show="row.qbo_currency_snapshot"> · </span>
                                            <span x-text="row.qbo_currency_snapshot || ''"></span>
                                            <span> · QBO #</span>
                                            <span x-text="row.qbo_bank_account_id"></span>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!row.qbo_bank_account_id">
                                    <span class="text-secondary">— not linked —</span>
                                </template>
                            </td>
                            <td>
                                <span class="badge" :class="statusBadgeClass(row.mapping_status)" x-text="row.mapping_status"></span>
                            </td>
                            <td class="text-sm text-secondary"
                                x-text="row.last_synced_at ? formatTs(row.last_synced_at) : '—'"></td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <template x-if="!row.qbo_bank_account_id">
                                        <button class="btn btn-sm btn-secondary" @click="openLinkModal(row)">Link to QBO…</button>
                                    </template>
                                    <template x-if="row.qbo_bank_account_id">
                                        <button class="btn btn-sm btn-outline" @click="openLinkModal(row)">Change…</button>
                                        <button class="btn btn-sm btn-outline" @click="unmapRow(row)">Unmap</button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- ── Link modal ──────────────────────────────────────────── -->
    <div x-show="linkModal.open" x-cloak
         class="modal-overlay"
         style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);"
         @click.self="closeLinkModal()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="h5" style="margin:0;">Link FF bank account to QBO</h3>
                <button class="modal-close-btn" @click="closeLinkModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="text-sm text-secondary" style="margin-bottom:14px;">
                    <strong>FF:</strong> <span x-text="linkModal.row ? linkModal.row.ff_name : '—'"></span>
                    (<span x-text="linkModal.row ? linkModal.row.ff_account_type : ''"></span>
                    · <span x-text="linkModal.row ? linkModal.row.ff_currency : ''"></span>)
                </div>

                <template x-if="linkModal.candidates.length === 0 && !linkModal.loading">
                    <div class="text-sm text-secondary" style="padding:16px 0;">
                        No QBO bank accounts pulled yet. Click <strong>Pull QBO bank accounts</strong>
                        on the main page first.
                    </div>
                </template>
                <template x-if="linkModal.loading">
                    <div class="text-sm text-secondary" style="padding:16px 0;">Loading candidates…</div>
                </template>

                <template x-if="linkModal.candidates.length > 0">
                    <div>
                        <label class="form-label">Pick QBO bank account</label>
                        <select class="form-select" x-model="linkModal.selectedId">
                            <option value="">— select —</option>
                            <template x-for="c in linkModal.candidates" :key="c.qbo_id">
                                <option :value="c.qbo_id"
                                        x-text="(c.rank_currency_match ? '★ ' : '  ') + c.name + ' (' + c.account_type + ' · ' + c.currency + (c.active ? '' : ' · INACTIVE') + ' · QBO #' + c.qbo_id + ')'"></option>
                            </template>
                        </select>
                        <div class="text-sm text-secondary" style="margin-top:6px;">
                            ★ = currency match. Ranked by currency, type compatibility, name fuzzy match, active.
                        </div>
                    </div>
                </template>

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
    /* Drift-row tint */
    .drift-row td {
        background-color: rgba(220, 38, 38, 0.04);
    }
    .drift-row td:first-child {
        box-shadow: inset 3px 0 0 0 #dc2626;
    }
</style>

<script>
function qboBankAccountMapping() {
    return {
        loading: false,
        pulling: false,
        cdcRunning: false,
        verifying: false,
        rows: [],
        kpis: null,
        lastBankCdcAt: null,
        lastPulledAt: null,
        flash: { message: '', type: '' },

        linkModal: {
            open: false,
            row: null,
            candidates: [],
            selectedId: '',
            loading: false,
            saving: false,
            error: '',
        },

        async init() {
            await this.reload();
        },

        ffUrl(path) {
            return FF_Api.url('/' + path.replace(/^\/+/, ''));
        },

        async reload() {
            this.loading = true;
            try {
                const j = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/bank_accounts/list.php'));
                if (j.success) {
                    const d = j.data;
                    this.rows           = d.rows;
                    this.kpis           = d.kpis;
                    this.lastBankCdcAt  = d.last_bank_cdc_at;
                    this.lastPulledAt   = d.last_pulled_at;
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
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/bank_accounts/pull.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'Pulled ' + d.qbo_count + ' QBO bank/credit-card accounts.',
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

        async runVerify() {
            this.verifying = true;
            this.flash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/bank_accounts/verify.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'Verified ' + d.checked + ' mappings; ' + d.drift_count + ' drift detected.',
                        type: d.drift_count > 0 ? 'error' : 'success',
                    };
                    await this.reload();
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.verifying = false;
            }
        },

        async runCdc() {
            this.cdcRunning = true;
            this.flash = { message: '', type: '' };
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/bank_accounts/run_cdc.php'), {});
                if (j.success) {
                    const d = j.data;
                    this.flash = {
                        message: 'CDC pulled=' + d.pulled + ' updated=' + d.updated + ' unchanged=' + d.unchanged
                              + ' skipped=' + d.skipped + ' errors=' + d.errors,
                        type: d.errors > 0 ? 'error' : 'success',
                    };
                    await this.reload();
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.cdcRunning = false;
            }
        },

        async openLinkModal(row) {
            this.linkModal.row        = row;
            this.linkModal.candidates = [];
            this.linkModal.selectedId = '';
            this.linkModal.error      = '';
            this.linkModal.open       = true;
            this.linkModal.loading    = true;
            try {
                const j = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/bank_accounts/candidates.php?ff_id=' + row.ff_bank_account_id));
                if (j.success) {
                    this.linkModal.candidates = j.data.candidates;
                } else {
                    this.linkModal.error = j.error?.message || 'Failed to load candidates';
                }
            } catch (e) {
                this.linkModal.error = e.message || 'Network error';
            } finally {
                this.linkModal.loading = false;
            }
        },

        closeLinkModal() {
            this.linkModal.open = false;
        },

        async saveLink() {
            this.linkModal.saving = true;
            this.linkModal.error  = '';
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/bank_accounts/save_mapping.php'), {
                    action: 'link',
                    ff_bank_account_id: this.linkModal.row.ff_bank_account_id,
                    qbo_bank_account_id: this.linkModal.selectedId,
                });
                if (j.success) {
                    this.flash = { message: 'Mapping saved.', type: 'success' };
                    this.closeLinkModal();
                    await this.reload();
                } else {
                    this.linkModal.error = j.error?.message || 'Save failed';
                }
            } catch (e) {
                this.linkModal.error = e.message || 'Network error';
            } finally {
                this.linkModal.saving = false;
            }
        },

        async unmapRow(row) {
            if (!confirm('Unmap "' + row.ff_name + '" from QBO #' + row.qbo_bank_account_id + '?')) return;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/bank_accounts/save_mapping.php'), {
                    action: 'unmap',
                    ff_bank_account_id: row.ff_bank_account_id,
                });
                if (j.success) {
                    this.flash = { message: 'Unmapped.', type: 'success' };
                    await this.reload();
                } else {
                    this.flashError(j);
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            }
        },

        statusBadgeClass(status) {
            return {
                'badge-success': status === 'mapped',
                'badge-warning': status === 'unmapped',
                'badge-danger':  status === 'conflict',
            };
        },

        formatTs(iso) {
            if (!iso) return '—';
            try {
                const d = new Date(iso);
                return d.toLocaleString();
            } catch (_) {
                return iso;
            }
        },

        flashError(j) {
            this.flash = {
                message: (j.error && (j.error.message || j.error.code)) || 'Request failed',
                type: 'error',
            };
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
