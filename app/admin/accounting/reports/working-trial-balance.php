<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/working-trial-balance.php
 *
 * Working Trial Balance v2 — practitioner-grade trial balance per
 * spec §23.2 with PY comparison, AJE column separation, materiality
 * flags, lead schedule drill-down, and workpaper annotations.
 *
 * Server renders period dropdown + tickmark legend; Alpine fetches
 * the 10-column WTB on Run.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, includes/partials/accounting-nav.php,
 *           api/v1/accounting/reports/working-trial-balance.php,
 *           api/v1/accounting/workpaper-annotations/{create,index}.php
 * @session  S-ACCT-WTB
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Server-side period list (open + closed, but not locked, so practitioners
// can run WTB against any postable period).
$periods = db_select(
    "SELECT id, name, start_date, end_date, year, status
       FROM acc_periods
      ORDER BY year DESC, month DESC"
);

// Default period: latest period with any posted activity in the current year,
// or just the latest period if none.
$defaultPeriodId = (int) (db_row(
    "SELECT p.id FROM acc_periods p
      WHERE EXISTS (SELECT 1 FROM acc_journal_entries je
                    WHERE je.period_id = p.id AND je.status = 'posted')
      ORDER BY p.year DESC, p.month DESC LIMIT 1"
)['id'] ?? ($periods[0]['id'] ?? 0));

// Tickmark legend for annotation modal dropdown.
$legendRaw = (string) settings_get('accounting.tickmark_legend', '{}');
$tickmarkLegend = json_decode($legendRaw, true) ?: [];

$pageTitle = 'Working Trial Balance';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Working Trial Balance</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Working Trial Balance</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="workingTrialBalance()" x-init="periodId = <?= (int) $defaultPeriodId ?>; load()">

    <!-- ============================================================
         Controls
         ============================================================ -->
    <div class="card" style="padding:16px;margin-bottom:20px;display:flex;gap:16px;align-items:end;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Period</label>
            <select x-model.number="periodId" class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <?php foreach ($periods as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['status']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">PY Comparison Period (optional)</label>
            <select x-model.number="comparisonPeriodId" class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <option value="">— Auto (PY year-end Dec 31) —</option>
                <?php foreach ($periods as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Materiality (optional)</label>
            <input type="number" x-model="materiality" step="0.01" min="0" placeholder="0.00"
                   class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;width:120px;">
        </div>
        <button @click="load()" :disabled="loading" class="btn btn-primary" style="height:36px;">
            <span x-show="!loading">Run</span>
            <span x-show="loading">Loading...</span>
        </button>
        <button @click="downloadPdf()" :disabled="loading || !data" class="btn btn-secondary" style="height:36px;">
            Export PDF
        </button>
    </div>

    <!-- ============================================================
         Balance banner
         ============================================================ -->
    <template x-if="data">
        <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
            <template x-if="data.is_balanced">
                <span class="badge badge-success" style="padding:4px 10px;font-size:0.75rem;">WTB Balanced</span>
            </template>
            <template x-if="!data.is_balanced">
                <span class="badge badge-warning" style="padding:4px 10px;font-size:0.75rem;">
                    WTB unbalanced — difference $<span x-text="diffStr()"></span>
                </span>
            </template>
            <div style="font-size:0.8125rem;">
                <span style="color:var(--text-secondary);">PY:</span>
                <span class="font-mono" style="margin-left:4px;" x-text="'$' + money(data.totals.py_balance)"></span>
            </div>
            <div style="font-size:0.8125rem;">
                <span style="color:var(--text-secondary);">Unadj CY:</span>
                <span class="font-mono" style="margin-left:4px;" x-text="'$' + money(data.totals.unadj_cy)"></span>
            </div>
            <div style="font-size:0.8125rem;">
                <span style="color:var(--text-secondary);">AJEs:</span>
                <span class="font-mono" style="margin-left:4px;" x-text="'$' + money(data.totals.ajes)"></span>
            </div>
            <div style="font-size:0.8125rem;">
                <span style="color:var(--text-secondary);">Adj CY:</span>
                <span class="font-mono" style="font-weight:600;margin-left:4px;" x-text="'$' + money(data.totals.adj_cy)"></span>
            </div>
            <div style="margin-left:auto;font-size:0.75rem;color:var(--text-secondary);">
                <span x-text="data.accounts.length"></span> accounts
                <template x-if="data.annotations_count > 0">
                    <span style="margin-left:12px;">
                        <span class="badge badge-blue" x-text="data.annotations_count + ' annotation' + (data.annotations_count === 1 ? '' : 's')"></span>
                    </span>
                </template>
            </div>
        </div>
    </template>

    <!-- ============================================================
         Loading / Error
         ============================================================ -->
    <template x-if="loading">
        <div class="card" style="padding:48px;text-align:center;">Loading WTB...</div>
    </template>
    <template x-if="error">
        <div class="card" style="padding:24px;color:var(--color-danger);" x-text="error"></div>
    </template>

    <!-- ============================================================
         WTB Table
         ============================================================ -->
    <template x-if="!loading && !error && data">
        <div class="card" style="padding:0;overflow:auto;">
            <table class="table" style="font-size:0.8125rem;">
                <thead>
                    <tr>
                        <th>GL#</th>
                        <th>Account</th>
                        <th>Lead</th>
                        <th class="text-right">PY Balance</th>
                        <th class="text-right">Unadj CY</th>
                        <th class="text-right">AJEs</th>
                        <th class="text-right">Adj CY</th>
                        <th class="text-right">Var $</th>
                        <th class="text-right">Var %</th>
                        <th>Ref</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(typeGroup, typeName) in groupedAccounts()" :key="typeName">
                        <template>
                            <tr style="background:var(--bg-subtle);">
                                <td colspan="11" style="font-weight:600;padding:8px 12px;" x-text="prettyType(typeName)"></td>
                            </tr>
                            <template x-for="row in typeGroup" :key="row.account_id">
                                <tr>
                                    <td class="font-mono" x-text="row.code"></td>
                                    <td x-text="row.name"></td>
                                    <td x-html="leadLink(row)"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(row.py_balance)"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(row.unadj_cy)"></td>
                                    <td class="font-mono text-right"
                                        :style="row.ajes && bcAbs(row.ajes) > 0 ? 'font-style:italic;cursor:pointer;color:var(--color-primary);' : ''"
                                        @click="row.aje_entries && row.aje_entries.length ? toggleAje(row.account_id) : null"
                                        x-text="'$' + money(row.ajes)"></td>
                                    <td class="font-mono text-right"
                                        :style="row.balance_flag === 'red' ? 'background:var(--color-danger-light);font-weight:600;' : ''"
                                        x-text="'$' + money(row.adj_cy)"></td>
                                    <td class="font-mono text-right"
                                        :style="row.variance_flag === 'yellow' ? 'background:var(--color-warning-light);' : ''"
                                        x-text="'$' + money(row.var_amt)"></td>
                                    <td class="font-mono text-right"
                                        :style="row.variance_flag === 'yellow' ? 'background:var(--color-warning-light);' : ''"
                                        x-text="row.var_pct !== null ? Number(row.var_pct).toFixed(2) + '%' : '—'"></td>
                                    <td class="text-secondary text-sm" x-text="row.ref || ''"></td>
                                    <td>
                                        <button class="btn btn-ghost btn-xs" title="Add annotation"
                                                @click="openAnnotationModal(row.account_id)">+</button>
                                    </td>
                                </tr>
                            </template>
                            <!-- AJE drill-down row, expandable -->
                            <template x-for="row in typeGroup.filter(r => expandedAje[r.account_id] && r.aje_entries.length)" :key="'aje-'+row.account_id">
                                <tr>
                                    <td colspan="11" style="background:var(--bg-subtle);padding:10px 16px;">
                                        <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:6px;">
                                            AJE entries on <span x-text="row.code + ' — ' + row.name"></span>
                                            (showing first <span x-text="row.aje_entries.length"></span>):
                                        </div>
                                        <table class="table" style="font-size:0.75rem;margin-bottom:0;">
                                            <thead><tr>
                                                <th>JE #</th><th>Date</th><th>Description</th>
                                                <th class="text-right">Debit</th><th class="text-right">Credit</th>
                                            </tr></thead>
                                            <tbody>
                                                <template x-for="je in row.aje_entries" :key="je.je_id">
                                                    <tr>
                                                        <td class="font-mono" x-text="je.entry_number"></td>
                                                        <td x-text="je.entry_date"></td>
                                                        <td x-text="je.description"></td>
                                                        <td class="font-mono text-right" x-text="parseFloat(je.debit) > 0 ? '$' + money(je.debit) : ''"></td>
                                                        <td class="font-mono text-right" x-text="parseFloat(je.credit) > 0 ? '$' + money(je.credit) : ''"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </template>
                </tbody>
                <tfoot>
                    <tr style="font-weight:600;border-top:2px solid var(--border-default);">
                        <td colspan="3">Totals</td>
                        <td class="font-mono text-right" x-text="'$' + money(data.totals.py_balance)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(data.totals.unadj_cy)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(data.totals.ajes)"></td>
                        <td class="font-mono text-right" x-text="'$' + money(data.totals.adj_cy)"></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </template>

    <!-- ============================================================
         Annotations panel
         ============================================================ -->
    <div class="card" style="margin-top:20px;padding:16px;" x-show="data">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h3 style="margin:0;font-size:1rem;font-weight:600;">Annotations</h3>
            <button class="btn btn-secondary btn-sm" @click="openAnnotationModal(null)">+ Add Annotation</button>
        </div>
        <template x-if="annotations.length === 0">
            <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0;">No annotations recorded for this WTB.</p>
        </template>
        <template x-if="annotations.length > 0">
            <table class="table" style="font-size:0.8125rem;">
                <thead><tr>
                    <th style="width:80px;">Tickmark</th>
                    <th>Account</th>
                    <th>Note</th>
                    <th style="width:140px;">By</th>
                    <th style="width:140px;">At</th>
                </tr></thead>
                <tbody>
                    <template x-for="a in annotations" :key="a.id">
                        <tr>
                            <td>
                                <span class="badge badge-blue" x-show="a.tickmark" x-text="a.tickmark"></span>
                            </td>
                            <td x-text="a.account_code ? (a.account_code + ' — ' + a.account_name) : '(WTB-level)'"></td>
                            <td x-text="a.note || ''"></td>
                            <td x-text="a.created_by_name || ('user #' + a.created_by)"></td>
                            <td x-text="a.created_at"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </template>
    </div>

    <!-- ============================================================
         Annotation modal
         ============================================================ -->
    <div x-show="annotationModal.open" x-cloak class="modal-backdrop" @click.self="annotationModal.open = false" style="background:rgba(0,0,0,0.4);">
        <div class="card" style="padding:24px;width:min(560px,95vw);">
            <h3 style="margin-top:0;font-size:1rem;font-weight:600;">Add Annotation</h3>
            <div style="display:grid;gap:12px;">
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Tickmark</label>
                    <select x-model="annotationModal.tickmark" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;">
                        <option value="">— None —</option>
                        <?php foreach ($tickmarkLegend as $sym => $meaning): ?>
                            <option value="<?= e($sym) ?>"><?= e($sym . ' — ' . $meaning) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Note</label>
                    <textarea x-model="annotationModal.note" rows="4" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;"></textarea>
                </div>
                <p style="font-size:0.75rem;color:var(--text-secondary);margin:0;">
                    Account: <strong x-text="annotationModal.account_id ? lookupAccountLabel(annotationModal.account_id) : '(WTB-level annotation)'"></strong>
                </p>
                <p x-show="annotationModal.error" x-cloak style="font-size:0.75rem;color:var(--color-danger);margin:0;" x-text="annotationModal.error"></p>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button class="btn btn-ghost" @click="annotationModal.open = false">Cancel</button>
                <button class="btn btn-primary" :disabled="annotationModal.saving" @click="saveAnnotation()">
                    <span x-show="!annotationModal.saving">Save Annotation</span>
                    <span x-show="annotationModal.saving">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function workingTrialBalance() {
    return {
        periodId: 0,
        comparisonPeriodId: '',
        materiality: '',
        loading: false,
        error: null,
        data: null,
        annotations: [],
        expandedAje: {},
        annotationModal: {
            open: false, saving: false, error: null,
            account_id: null, tickmark: '', note: '',
        },

        async load() {
            if (!this.periodId) return;
            this.loading = true; this.error = null;
            try {
                let url = '<?= base_url('api/v1/accounting/reports/working-trial-balance') ?>?period_id=' + this.periodId;
                if (this.comparisonPeriodId) url += '&comparison_period_id=' + this.comparisonPeriodId;
                if (this.materiality) url += '&materiality=' + encodeURIComponent(this.materiality);
                const r = await FF_Api.get(url);
                if (r.success) {
                    this.data = r.data;
                    await this.loadAnnotations();
                } else {
                    this.error = r.error?.message || 'Failed to load WTB.';
                }
            } catch (e) {
                this.error = 'Network error.';
            }
            this.loading = false;
        },

        async loadAnnotations() {
            if (!this.periodId) return;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/workpaper-annotations') ?>?period_id=' + this.periodId + '&workpaper_type=trial_balance');
                this.annotations = r.success ? (r.data || []) : [];
            } catch (e) { this.annotations = []; }
        },

        downloadPdf() {
            if (!this.periodId) return;
            let url = '<?= base_url('api/v1/accounting/reports/working-trial-balance') ?>?period_id=' + this.periodId + '&format=pdf';
            if (this.comparisonPeriodId) url += '&comparison_period_id=' + this.comparisonPeriodId;
            if (this.materiality) url += '&materiality=' + encodeURIComponent(this.materiality);
            window.open(url, '_blank');
        },

        groupedAccounts() {
            const order = ['asset','liability','equity','revenue','cost_of_revenue','operating_expense','other_income','other_expense'];
            const groups = {};
            if (!this.data) return groups;
            for (const r of this.data.accounts) {
                if (!groups[r.account_type]) groups[r.account_type] = [];
                groups[r.account_type].push(r);
            }
            // Return in canonical type order.
            const ordered = {};
            for (const k of order) if (groups[k]) ordered[k] = groups[k];
            return ordered;
        },

        prettyType(t) {
            return ({
                asset: 'ASSETS', liability: 'LIABILITIES', equity: 'EQUITY',
                revenue: 'REVENUE', cost_of_revenue: 'COST OF REVENUE',
                operating_expense: 'OPERATING EXPENSES',
                other_income: 'OTHER INCOME', other_expense: 'OTHER EXPENSE',
            })[t] || t.toUpperCase();
        },

        toggleAje(aid) {
            this.expandedAje[aid] = !this.expandedAje[aid];
        },

        leadLink(row) {
            if (!row.lead_schedule_code) return '<span class="text-secondary">—</span>';
            const url = '<?= base_url('accounting/reports/lead-schedule') ?>?code=' + encodeURIComponent(row.lead_schedule_code) + '&period_id=' + this.periodId;
            return '<a href="' + url + '" style="color:var(--color-primary);text-decoration:underline;">' + row.lead_schedule_code + '</a>';
        },

        money(v) {
            const n = parseFloat(v || 0);
            const sign = n < 0 ? '-' : '';
            return sign + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        bcAbs(v) { return Math.abs(parseFloat(v || 0)); },

        diffStr() {
            if (!this.data) return '0.00';
            const d = parseFloat(this.data.totals.debits) - parseFloat(this.data.totals.credits);
            return Math.abs(d).toLocaleString('en-CA', { minimumFractionDigits: 2 });
        },

        openAnnotationModal(accountId) {
            this.annotationModal = {
                open: true, saving: false, error: null,
                account_id: accountId,
                tickmark: '', note: '',
            };
        },

        lookupAccountLabel(accountId) {
            if (!this.data) return '#' + accountId;
            const r = this.data.accounts.find(x => x.account_id === accountId);
            return r ? (r.code + ' — ' + r.name) : '#' + accountId;
        },

        async saveAnnotation() {
            const m = this.annotationModal;
            if (!m.tickmark && !m.note.trim()) {
                m.error = 'Provide at least a tickmark or a note.'; return;
            }
            m.saving = true; m.error = null;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/workpaper-annotations/create') ?>', {
                    workpaper_type: 'trial_balance',
                    workpaper_ref:  'WTB-' + (this.data ? this.data.period.year : new Date().getFullYear()),
                    period_id:      this.periodId,
                    account_id:     m.account_id,
                    tickmark:       m.tickmark || null,
                    note:           m.note ? m.note.trim() : null,
                });
                if (r.success) {
                    this.annotationModal.open = false;
                    await this.loadAnnotations();
                    if (this.data) this.data.annotations_count = (this.data.annotations_count || 0) + 1;
                } else {
                    m.error = (r.error && (r.error.message || JSON.stringify(r.error.fields || {}))) || 'Save failed.';
                }
            } catch (e) {
                m.error = 'Network error.';
            }
            m.saving = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
