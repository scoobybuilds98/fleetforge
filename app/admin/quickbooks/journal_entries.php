<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/journal_entries.php
 *
 * QBO Journal Entry Push admin — operator surface for the FF→QBO journal
 * entry push pipeline (Phase QBO-10 / S-QBO-21). Mirror of /quickbooks/
 * bill_payments with JE-specific deltas:
 *   - 10 KPI tiles incl. typed preflight sub-states (currency_mismatch +
 *     field_too_long) — bridge-derived skips count via sync_log, NOT
 *     map row (no-map-row pattern per D-QBO-21-1) so no Bridge-Derived
 *     KPI; instead the Mode-Skipped tile is paired with a Bridge-Derived
 *     COUNT pulled from acc_qbo_sync_log entries with
 *     error_code='skipped_bridge_derived'
 *   - Source Type column (manual / depreciation / fx_revaluation / etc.)
 *   - Lines count badge (debit-line count + credit-line count)
 *   - Retry button on failed / failed_preflight* rows
 *
 * @session  S-QBO-21
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.10 + §6.8
 * @gate     require_permission('quickbooks', 'view') for read; retry needs edit_credentials
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Journal Entries';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Journal Entries</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Journal Entry Push</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Read-only visibility into the FF→QBO journal entry push pipeline (Phase QBO-10 / S-QBO-21).
        FF JEs enqueue on entry_status='posted' transition via JournalEntryService::create / post / reverse;
        the worker picks them up + creates QBO JournalEntry entities with per-line PostingType debit/credit
        emission per D-QBO-21-2. Bridge-derived JEs (source_type IN invoice/payment/credit_note/ap_bill/ap_payment
        per spec §8.10) skip BOTH Enqueuer gate-0 AND Pusher pushImpl step 4 — no map row written for
        bridge skips; counted via sync_log Bridge-Derived tile below. Retry failed pushes from this page;
        investigate failed_preflight states by checking the listed reason (typically unmapped line account
        per D-QBO-21-3, or AccountValidator missing tax_receivable / tax_payable categories).
    </div>
</div>

<div x-data="qboJournalEntriesAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>)">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── 10 KPI tiles ─────────────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(5,1fr);margin-bottom:14px;">
        <div class="kpi-tile">
            <div class="kpi-label">Pushed</div>
            <div class="kpi-value text-success" x-text="kpis.pushed">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Pending</div>
            <div class="kpi-value" x-text="kpis.pending">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Failed</div>
            <div class="kpi-value text-danger" x-text="kpis.failed">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Pre-flight Block</div>
            <div class="kpi-value text-warning" x-text="kpis.failed_preflight">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Currency Mismatch</div>
            <div class="kpi-value text-warning" x-text="kpis.failed_preflight_currency_mismatch">0</div>
        </div>
    </div>
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(5,1fr);margin-bottom:14px;">
        <div class="kpi-tile">
            <div class="kpi-label">Field Too Long</div>
            <div class="kpi-value text-warning" x-text="kpis.failed_preflight_field_too_long">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Voided</div>
            <div class="kpi-value text-secondary" x-text="kpis.voided">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Skipped Void</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_voided">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Bridge-Derived</div>
            <div class="kpi-value text-secondary" x-text="kpis.bridge_derived_sync_log">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Mode-Skipped</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_by_mode">0</div>
        </div>
    </div>

    <!-- ── S-QBO-23 "By source type" KPI cross-cuts (D-QBO-23-3) ──
         Generalizes the S-QBO-22 FA-only strip (D-QBO-22-3) into a
         per-source-type strip showing Fixed Asset + Tax Remittance sync
         health side-by-side. Tiles always render regardless of the active
         source-type chip below so the operator sees both at a glance. -->
    <div class="card" style="padding:10px 16px;margin-bottom:14px;display:flex;gap:32px;align-items:center;background:var(--bg-surface-2);border-left:3px solid var(--color-info);flex-wrap:wrap;">
        <div style="display:flex;gap:18px;align-items:center;">
            <div class="text-sm text-secondary" style="font-weight:600;">Fixed Asset JEs</div>
            <div>
                <div class="text-xs text-secondary">Pushed</div>
                <div class="font-mono text-lg text-success" x-text="kpis.fa_pushed">0</div>
            </div>
            <div>
                <div class="text-xs text-secondary">Total</div>
                <div class="font-mono text-lg" x-text="kpis.fa_total">0</div>
            </div>
            <div>
                <div class="text-xs text-secondary">% synced</div>
                <div class="font-mono text-lg" x-text="(kpis.fa_total > 0 ? Math.round((kpis.fa_pushed / kpis.fa_total) * 100) : 0) + '%'">0%</div>
            </div>
            <?php if ($canEditCredentials): ?>
            <!-- S-QBO-FA-MAP: refresh the per-asset QBO reference map
                 (acc_qbo_fixed_asset_map) — resolves each asset's QBO GL-account
                 refs + cost/depr snapshots + sync_status. -->
            <div style="border-left:1px solid #e2e8f0;padding-left:18px;" x-data="qboFaMapSync()">
                <button class="btn btn-secondary btn-xs" @click="run()" :disabled="running">
                    <span x-show="!running">Refresh FA reference map</span>
                    <span x-show="running" x-cloak>Syncing…</span>
                </button>
                <div class="text-xs text-secondary" style="margin-top:4px;" x-show="result" x-cloak x-text="result"></div>
            </div>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:18px;align-items:center;border-left:1px solid #e2e8f0;padding-left:24px;">
            <div class="text-sm text-secondary" style="font-weight:600;">Tax Remittance JEs</div>
            <div>
                <div class="text-xs text-secondary">Pushed</div>
                <div class="font-mono text-lg text-success" x-text="kpis.tax_remittance_pushed">0</div>
            </div>
            <div>
                <div class="text-xs text-secondary">Total</div>
                <div class="font-mono text-lg" x-text="kpis.tax_remittance_total">0</div>
            </div>
            <div>
                <div class="text-xs text-secondary">% synced</div>
                <div class="font-mono text-lg" x-text="(kpis.tax_remittance_total > 0 ? Math.round((kpis.tax_remittance_pushed / kpis.tax_remittance_total) * 100) : 0) + '%'">0%</div>
            </div>
        </div>
    </div>

    <!-- ── FILTER TOOLBAR ──────────────────────────────────────── -->
    <!-- S-LIST-TOOLBAR: same .table-toolbar shape as customers/invoices.
         Source type stays a mutually-exclusive chip group and Status stays a
         checkbox set — a <select> would collapse the multi-select. -->
    <div class="table-toolbar">

        <div class="table-toolbar-left table-toolbar-left--wrap">
            <!-- Source-type chip group (D-QBO-23-3): All / Fixed Asset / Tax
                 Remittance — mutually exclusive radio-style; sets source_filter
                 param on reload. Replaces S-QBO-22's single "Show FA only" toggle. -->
            <span class="text-secondary text-sm" style="white-space:nowrap;">Source type:</span>
            <div style="display:inline-flex;gap:6px;">
                <template x-for="sf in [{k:'',label:'All'},{k:'fa',label:'Fixed Asset'},{k:'tax_remittance',label:'Tax Remittance'}]" :key="sf.k">
                    <button
                        class="btn btn-sm"
                        :class="filters.sourceFilter === sf.k ? 'btn-primary' : 'btn-secondary'"
                        @click="filters.sourceFilter = sf.k; page=1; reload()"
                        x-text="sf.label"></button>
                </template>
            </div>

            <span class="text-secondary text-sm" style="white-space:nowrap;margin-left:6px;">Status:</span>
            <template x-for="s in ['pending','pushed','voided','failed','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long','skipped_voided','skipped_by_mode']" :key="s">
                <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:0.825rem;">
                    <input type="checkbox" :value="s" x-model="filters.statuses" @change="page=1; reload()">
                    <span x-text="s"></span>
                </label>
            </template>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-text="total + ' row' + (total === 1 ? '' : 's')"></span>
            <button class="btn btn-secondary btn-sm"
                    @click="filters.statuses = []; filters.sourceFilter = ''; page=1; reload()">Reset</button>
        </div>

    </div>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <div class="card" style="padding:0;">
        <table class="table table-striped" style="margin:0;">
            <thead>
                <tr>
                    <th>FF JE</th>
                    <th>Source</th>
                    <th class="text-right">Total</th>
                    <th>Lines</th>
                    <th>QBO Id</th>
                    <th>Status</th>
                    <th>Pushed At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="8" class="text-center text-secondary" style="padding:24px;">Loading…</td></tr>
                </template>
                <template x-if="!loading && rows.length === 0">
                    <tr><td colspan="8" class="text-center text-secondary" style="padding:24px;">
                        No journal entry push activity yet. Posted manual / depreciation / fx_revaluation / year_end / recurring / damage / lease JEs will appear here.
                        Bridge-derived JEs (invoice/payment/credit_note/ap_bill/ap_payment per spec §8.10) skip without writing a map row — counted in the Bridge-Derived tile above.
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <a :href="ffJeUrl(row.ff_journal_entry_id)" x-text="row.entry_number || ('#' + row.ff_journal_entry_id)"></a>
                            <div class="text-xs text-secondary" x-text="row.entry_date"></div>
                        </td>
                        <td>
                            <span class="badge badge-secondary" x-text="row.source_type || 'manual'"></span>
                            <template x-if="row.source_id">
                                <div class="text-xs text-secondary font-mono">id=<span x-text="row.source_id"></span></div>
                            </template>
                            <template x-if="row.is_reversal">
                                <div class="text-xs text-warning">reversal of #<span x-text="row.reversal_of_id"></span></div>
                            </template>
                        </td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.ff_je_snapshot_total || row.ff_balanced_total, row.ff_currency)"></span>
                        </td>
                        <td class="text-sm">
                            <span x-text="(row.line_count || 0) + ' lines'"></span>
                            <template x-if="row.debit_line_count != null">
                                <div class="text-xs text-secondary">
                                    <span x-text="row.debit_line_count"></span>D / <span x-text="row.credit_line_count"></span>C
                                </div>
                            </template>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_journal_entry_id || '—'"></td>
                        <td>
                            <span class="badge" :class="statusBadgeClass(row.push_status)" x-text="row.push_status"></span>
                            <template x-if="row.push_error">
                                <div class="text-xs text-danger" style="margin-top:4px;cursor:help;" :title="row.push_error">
                                    <span x-text="truncate(row.push_error, 80)"></span>
                                </div>
                            </template>
                        </td>
                        <td class="text-sm text-secondary font-mono" x-text="row.pushed_at ? formatTs(row.pushed_at) : '—'"></td>
                        <td>
                            <template x-if="canRetry && ['failed','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long'].includes(row.push_status)">
                                <button class="btn btn-secondary btn-xs" @click="retry(row.id)" :disabled="retrying[row.id]">
                                    <span x-show="!retrying[row.id]">Retry</span>
                                    <span x-show="retrying[row.id]" x-cloak>…</span>
                                </button>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;">
        <div class="text-sm text-secondary">
            Showing <span x-text="rows.length"></span> of <span x-text="total"></span>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-secondary btn-sm" @click="page = Math.max(1, page-1); reload()" :disabled="page <= 1">Prev</button>
            <span class="text-sm text-secondary" style="align-self:center;">Page <span x-text="page"></span></span>
            <button class="btn btn-secondary btn-sm" @click="page++; reload()" :disabled="rows.length < perPage">Next</button>
        </div>
    </div>
</div>

<script>
function qboJournalEntriesAdmin(canEdit) {
    return {
        canRetry: canEdit,
        loading: false,
        rows: [],
        kpis: {
            pushed: 0, pending: 0, voided: 0, failed: 0,
            failed_preflight: 0,
            failed_preflight_currency_mismatch: 0,
            failed_preflight_field_too_long: 0,
            skipped_voided: 0,
            skipped_by_mode: 0,
            bridge_derived_sync_log: 0,
            // S-QBO-22 / D-QBO-22-3: Fixed-Asset cross-cuts.
            fa_pushed: 0,
            fa_total: 0,
            // S-QBO-23 / D-QBO-23-3: Tax-Remittance cross-cuts.
            tax_remittance_pushed: 0,
            tax_remittance_total: 0,
        },
        page: 1,
        perPage: 25,
        total: 0,
        // S-QBO-23 / D-QBO-23-3: sourceFilter chip group ('' | 'fa' | 'tax_remittance').
        //   Generalizes S-QBO-22's boolean faOnly toggle into a mutually-
        //   exclusive source-type selector.
        filters: { statuses: [], sourceFilter: '' },
        retrying: {},
        flash: { type: '', message: '' },

        async init() { await this.reload(); },

        async reload() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ page: this.page, per_page: this.perPage });
                if (this.filters.statuses.length > 0) {
                    params.set('status', this.filters.statuses.join(','));
                }
                // S-QBO-23 / D-QBO-23-3: source_filter chip group scopes table.
                //   'fa' → depreciation/asset_disposal/impairment (D-QBO-22-3);
                //   'tax_remittance' → tax_remittance (D-QBO-23-3); '' → all.
                if (this.filters.sourceFilter) {
                    params.set('source_filter', this.filters.sourceFilter);
                }
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/journal_entries/list') ?>?' + params.toString());
                if (r.success) {
                    // Envelope contract: json_success([...]) nests EVERY key under
                    // `data`, so these reads must go through r.data — reading
                    // r.rows / r.kpis / r.total off the envelope yields undefined,
                    // which the `|| []` / `|| 0` fallbacks silently turned into a
                    // permanently empty table and zeroed KPI tiles. Matches the
                    // `const d = j.data` convention already used by the
                    // accounts / customers / vendors / items / tax_codes consoles.
                    const d = r.data || {};
                    this.rows = d.rows || [];
                    this.kpis = d.kpis || this.kpis;
                    this.total = d.total || 0;
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Failed to load: ' + (e.message || e) };
            } finally {
                this.loading = false;
            }
        },

        async retry(mappingId) {
            this.retrying[mappingId] = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/journal_entries/retry') ?>', { id: mappingId });
                if (r.success) {
                    // Envelope contract (same class as reload above): retry.php
                    // emits json_success(['action' => …, 'reason' => …]), so the
                    // keys live under r.data. Reading r.action off the envelope
                    // was always undefined, which sent EVERY successful re-enqueue
                    // down the else branch and reported "Skipped: gate refused".
                    const d = r.data || {};
                    if (d.action === 'enqueued') {
                        this.flash = { type: 'success', message: 'Re-enqueued for push.' };
                    } else {
                        this.flash = { type: 'danger', message: 'Skipped: ' + (d.reason || 'gate refused') };
                    }
                    await this.reload();
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Retry failed: ' + (e.message || e) };
            } finally {
                this.retrying[mappingId] = false;
            }
        },

        ffJeUrl(id) {
            return '<?= base_url('accounting/journal-entries/show?id=') ?>' + id;
        },

        statusBadgeClass(s) {
            return {
                'pushed': 'badge-success',
                'pending': 'badge-secondary',
                'voided': 'badge-secondary',
                'failed': 'badge-danger',
                'failed_preflight': 'badge-warning',
                'failed_preflight_currency_mismatch': 'badge-warning',
                'failed_preflight_field_too_long': 'badge-warning',
                'skipped_voided': 'badge-secondary',
                'skipped_by_mode': 'badge-secondary',
            }[s] || 'badge-secondary';
        },

        formatMoney(amt, ccy) {
            if (amt == null) return '—';
            const sym = (ccy === 'USD') ? 'US$' : '$';
            return sym + parseFloat(amt).toFixed(2);
        },

        formatTs(ts) { return ts ? ts.replace('T', ' ').substring(0, 16) : '—'; },
        truncate(s, n) { if (!s) return ''; return s.length > n ? s.substring(0, n) + '…' : s; },
    };
}

// S-QBO-FA-MAP: refresh acc_qbo_fixed_asset_map (per-asset QBO reference rows).
function qboFaMapSync() {
    return {
        running: false,
        result: '',
        async run() {
            this.running = true;
            this.result = '';
            try {
                const j = await FF_Api.post('<?= base_url('api/v1/quickbooks/fixed_asset_map_sync') ?>', {});
                if (j && j.success) {
                    const r = j.data || j;
                    this.result = (r.total ?? 0) + ' assets · ' + (r.synced ?? 0) + ' synced · ' + (r.pending ?? 0) + ' pending';
                } else {
                    this.result = 'Failed: ' + ((j && j.error && j.error.message) || 'error');
                }
            } catch (e) {
                this.result = 'Failed: ' + (e.message || e);
            } finally {
                this.running = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
