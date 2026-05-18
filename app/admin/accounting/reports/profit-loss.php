<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/profit-loss.php
 *
 * Profit & Loss report page. Date range pickers + comparison dropdown
 * (none / prior period / prior year / budget). Alpine-driven: fetches
 * api/v1/accounting/reports/profit-loss.php on init + on param change.
 * Includes PDF export, save-configuration, and AI narrative button.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Available budgets for the comparison dropdown
$budgets = db_select(
    "SELECT id, name, year, version FROM acc_budgets ORDER BY year DESC, name ASC",
    []
);

$pageTitle = 'Profit & Loss';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Profit &amp; Loss</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Profit &amp; Loss</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="plReport()" x-init="init()">
    <!-- Filters -->
    <div class="card" style="padding:14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">From</label>
            <input type="date" x-model="form.from" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">To</label>
            <input type="date" x-model="form.to" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Comparison</label>
            <select x-model="form.comparison" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:160px;">
                <option value="none">None</option>
                <option value="prior_period">Prior period</option>
                <option value="prior_year">Prior year</option>
                <option value="budget">Budget</option>
            </select>
        </div>
        <div x-show="form.comparison === 'budget'">
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Budget</label>
            <select x-model="form.budget_id" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:200px;">
                <option value="">Select budget…</option>
                <?php foreach ($budgets as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?> (<?= (int) $b['year'] ?>, <?= e($b['version']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" @click="load()" :disabled="loading" x-text="loading ? 'Loading…' : 'Run Report'">Run Report</button>
        <button class="btn btn-secondary btn-sm" @click="exportPdf()">Export PDF</button>
        <button class="btn btn-secondary btn-sm" @click="aiNarrative()" :disabled="aiLoading || !report" x-text="aiLoading ? 'Thinking…' : 'AI Narrative'">AI Narrative</button>
    </div>

    <!-- Saved configurations -->
    <div class="card" style="padding:10px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);">Saved configurations:</span>
        <select x-model="savedSel" @change="loadSaved()" class="form-input" style="padding:5px 8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:200px;">
            <option value="">— Pick a saved view —</option>
            <template x-for="s in savedList" :key="s.id"><option :value="s.id" x-text="s.name + (s.is_pinned ? ' ★' : '')"></option></template>
        </select>
        <button class="btn btn-ghost btn-xs" @click="saveCurrent()">Save Current…</button>
    </div>

    <!-- AI Narrative panel -->
    <div x-show="aiNarrativeText" x-cloak class="card" style="padding:14px;margin-bottom:14px;background:var(--bg-elev);border-left:3px solid var(--color-accent);">
        <div style="font-weight:600;font-size:0.85rem;margin-bottom:6px;">AI Narrative</div>
        <div style="white-space:pre-wrap;font-size:0.8125rem;line-height:1.5;" x-text="aiNarrativeText"></div>
    </div>

    <!-- Report -->
    <template x-if="loading">
        <div class="card" style="padding:36px;text-align:center;color:var(--text-secondary);">Loading…</div>
    </template>
    <template x-if="!loading && report">
        <div class="card" style="padding:18px;overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:8px 10px;text-align:left;">Account</th>
                        <th style="padding:8px 10px;text-align:right;">Amount</th>
                        <template x-if="hasCompare()">
                            <th style="padding:8px 10px;text-align:right;">Compare</th>
                        </template>
                        <template x-if="hasCompare()">
                            <th style="padding:8px 10px;text-align:right;">Var</th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(grp, gi) in sections" :key="gi">
                        <template>
                            <tr style="background:var(--bg-elev);">
                                <td colspan="4" style="padding:8px 10px;font-weight:600;" x-text="grp.label"></td>
                            </tr>
                            <template x-for="r in grp.rows" :key="r.account_id">
                                <tr style="border-bottom:1px solid var(--border-default);">
                                    <td style="padding:6px 14px;font-family:var(--font-mono);font-size:0.78rem;" x-text="r.code + ' — ' + r.name"></td>
                                    <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(r.amount)"></td>
                                    <template x-if="hasCompare()">
                                        <td class="font-mono" style="padding:6px 10px;text-align:right;color:var(--text-secondary);" x-text="fmt(r.compare_amount || '0.00')"></td>
                                    </template>
                                    <template x-if="hasCompare()">
                                        <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(r.var_amt || '0.00')"></td>
                                    </template>
                                </tr>
                            </template>
                            <tr style="background:var(--bg-elev);border-top:1px solid var(--border-default);">
                                <td style="padding:6px 10px;font-weight:600;" x-text="'Total ' + grp.label"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;font-weight:600;" x-text="fmt(grp.total)"></td>
                                <template x-if="hasCompare()"><td></td></template>
                                <template x-if="hasCompare()"><td></td></template>
                            </tr>
                        </template>
                    </template>
                    <tr style="border-top:2px solid var(--border-default);">
                        <td style="padding:8px 10px;font-weight:700;">Gross Profit</td>
                        <td class="font-mono" style="padding:8px 10px;text-align:right;font-weight:700;" x-text="fmt(report.gross_profit)"></td>
                        <template x-if="hasCompare()"><td></td></template>
                        <template x-if="hasCompare()"><td></td></template>
                    </tr>
                    <tr>
                        <td style="padding:8px 10px;font-weight:700;">Operating Income</td>
                        <td class="font-mono" style="padding:8px 10px;text-align:right;font-weight:700;" x-text="fmt(report.operating_income)"></td>
                        <template x-if="hasCompare()"><td></td></template>
                        <template x-if="hasCompare()"><td></td></template>
                    </tr>
                    <tr style="border-top:2px solid var(--border-default);background:#e8f0fe;">
                        <td style="padding:8px 10px;font-weight:700;">Net Income</td>
                        <td class="font-mono" style="padding:8px 10px;text-align:right;font-weight:700;" x-text="fmt(report.net_income)"></td>
                        <template x-if="hasCompare()"><td></td></template>
                        <template x-if="hasCompare()"><td></td></template>
                    </tr>
                </tbody>
            </table>
        </div>
    </template>
</div>

<script>
function plReport() {
    const apiBase = '<?= e(base_url('api/v1/accounting')) ?>';
    const todayIso = new Date().toISOString().slice(0,10);
    const yearStart = todayIso.slice(0,4) + '-01-01';
    return {
        form: { from: yearStart, to: todayIso, comparison: 'none', budget_id: '' },
        report: null,
        loading: false,
        aiLoading: false,
        aiNarrativeText: '',
        savedList: [],
        savedSel: '',
        async init() {
            await this.loadSavedList();
            await this.load();
        },
        hasCompare() { return this.report && this.report.compare_mode !== 'none' && this.report.compare_total; },
        sections() {
            if (!this.report) return [];
            return [
                { label: 'Revenue', rows: this.report.revenue || [], total: this.report.revenue_total },
                { label: 'Cost of Revenue', rows: this.report.direct_costs || [], total: this.report.direct_costs_total },
                { label: 'Operating Expenses', rows: this.report.operating_expenses || [], total: this.report.opex_total },
                { label: 'Other Income/Expense', rows: this.report.other || [], total: this.report.other_total },
            ];
        },
        async load() {
            this.loading = true;
            try {
                const url = new URL(apiBase + '/reports/profit-loss.php', window.location.origin);
                url.searchParams.set('period_start', this.form.from);
                url.searchParams.set('period_end', this.form.to);
                url.searchParams.set('comparison', this.form.comparison);
                if (this.form.comparison === 'budget' && this.form.budget_id) {
                    url.searchParams.set('budget_id', this.form.budget_id);
                }
                const r = await fetch(url.toString());
                const j = await r.json();
                this.report = j && j.success ? j.data : null;
            } catch (e) { this.report = null; }
            this.loading = false;
        },
        exportPdf() {
            const url = new URL(apiBase + '/reports/profit-loss.php', window.location.origin);
            url.searchParams.set('period_start', this.form.from);
            url.searchParams.set('period_end', this.form.to);
            url.searchParams.set('comparison', this.form.comparison);
            url.searchParams.set('format', 'pdf');
            if (this.form.comparison === 'budget' && this.form.budget_id) url.searchParams.set('budget_id', this.form.budget_id);
            window.open(url.toString(), '_blank');
        },
        async aiNarrative() {
            if (!this.report) return;
            this.aiLoading = true;
            this.aiNarrativeText = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch('<?= e(base_url('api/v1/ai/summary.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({
                        entity_type: 'accounting',
                        entity_id:   0,
                        summary_type: 'pl_narrative',
                        context: { from: this.form.from, to: this.form.to }
                    })
                });
                const j = await r.json();
                this.aiNarrativeText = (j && j.summary) ? j.summary : ((j && j.error && j.error.message) || 'AI narrative not available.');
            } catch (e) { this.aiNarrativeText = 'AI narrative failed: ' + e.message; }
            this.aiLoading = false;
        },
        async loadSavedList() {
            try {
                const r = await fetch(apiBase + '/saved-reports/index.php?report_type=profit_loss');
                const j = await r.json();
                this.savedList = (j && j.success) ? j.data : [];
            } catch (e) { this.savedList = []; }
        },
        loadSaved() {
            const id = parseInt(this.savedSel, 10);
            if (!id) return;
            const cfg = this.savedList.find(s => s.id === id);
            if (!cfg) return;
            Object.assign(this.form, cfg.parameters || {});
            this.load();
        },
        async saveCurrent() {
            const name = prompt('Save this report configuration as:');
            if (!name) return;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            await fetch(apiBase + '/saved-reports/create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ name, report_type: 'profit_loss', parameters: this.form })
            });
            await this.loadSavedList();
        },
        fmt(s) {
            if (s === null || s === undefined || s === '') return '—';
            const n = parseFloat(s);
            if (n === 0) return '—';
            return (n < 0 ? '-$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
