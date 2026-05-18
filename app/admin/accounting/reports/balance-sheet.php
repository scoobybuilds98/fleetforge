<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/balance-sheet.php
 *
 * Balance Sheet report page — as-of-date picker with optional comparison.
 * Renders ASPE-structured indented sections (current/long-term assets,
 * current/long-term liabilities, equity). Banner if unbalanced.
 *
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$pageTitle = 'Balance Sheet';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Balance Sheet</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Balance Sheet</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="bsReport()" x-init="init()">
    <div class="card" style="padding:14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">As of</label>
            <input type="date" x-model="form.as_of" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Comparison</label>
            <select x-model="form.comparison" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:160px;">
                <option value="none">None</option>
                <option value="prior_period">Prior period</option>
                <option value="prior_year">Prior year</option>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" @click="load()" :disabled="loading" x-text="loading ? 'Loading…' : 'Run Report'">Run Report</button>
        <button class="btn btn-secondary btn-sm" @click="exportPdf()">Export PDF</button>
        <button class="btn btn-secondary btn-sm" @click="aiNarrative()" :disabled="aiLoading || !report" x-text="aiLoading ? 'Thinking…' : 'Explain Balance Sheet'">Explain Balance Sheet</button>
    </div>

    <div class="card" style="padding:10px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);">Saved configurations:</span>
        <select x-model="savedSel" @change="loadSaved()" class="form-input" style="padding:5px 8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:200px;">
            <option value="">— Pick a saved view —</option>
            <template x-for="s in savedList" :key="s.id"><option :value="s.id" x-text="s.name + (s.is_pinned ? ' ★' : '')"></option></template>
        </select>
        <button class="btn btn-ghost btn-xs" @click="saveCurrent()">Save Current…</button>
    </div>

    <template x-if="report && !report.is_balanced">
        <div style="padding:10px 14px;margin-bottom:14px;background:#ffe6e6;border:1px solid #cc0000;color:#990000;border-radius:4px;font-size:0.85rem;">
            <strong>Balance sheet unbalanced</strong> — drift <span x-text="fmt(report.drift)"></span>.
            This usually indicates an AR/AP reconciliation gap (known issue, deferred to S-QBO-27).
        </div>
    </template>

    <div x-show="aiNarrativeText" x-cloak class="card" style="padding:14px;margin-bottom:14px;background:var(--bg-elev);border-left:3px solid var(--color-accent);">
        <div style="font-weight:600;font-size:0.85rem;margin-bottom:6px;">AI Narrative</div>
        <div style="white-space:pre-wrap;font-size:0.8125rem;line-height:1.5;" x-text="aiNarrativeText"></div>
    </div>

    <template x-if="loading"><div class="card" style="padding:36px;text-align:center;color:var(--text-secondary);">Loading…</div></template>
    <template x-if="!loading && report">
        <div class="card" style="padding:18px;overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:8px 10px;text-align:left;">Account</th>
                        <th style="padding:8px 10px;text-align:right;">Amount</th>
                        <template x-if="hasCompare()"><th style="padding:8px 10px;text-align:right;">Compare</th></template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(grp, gi) in sections" :key="gi">
                        <template>
                            <tr style="background:var(--bg-elev);">
                                <td colspan="3" style="padding:8px 10px;font-weight:600;" x-text="grp.label"></td>
                            </tr>
                            <template x-for="r in grp.rows" :key="r.account_id">
                                <tr style="border-bottom:1px solid var(--border-default);">
                                    <td style="padding:6px 14px;font-family:var(--font-mono);font-size:0.78rem;" x-text="r.code + ' — ' + r.name"></td>
                                    <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(r.amount)"></td>
                                    <template x-if="hasCompare()">
                                        <td class="font-mono" style="padding:6px 10px;text-align:right;color:var(--text-secondary);" x-text="fmt(r.compare_amount || '0.00')"></td>
                                    </template>
                                </tr>
                            </template>
                            <tr style="background:var(--bg-elev);border-top:1px solid var(--border-default);">
                                <td style="padding:6px 10px;font-weight:600;" x-text="'Total ' + grp.label"></td>
                                <td class="font-mono" style="padding:6px 10px;text-align:right;font-weight:600;" x-text="fmt(grp.total)"></td>
                                <template x-if="hasCompare()"><td></td></template>
                            </tr>
                        </template>
                    </template>
                    <tr style="border-top:2px solid var(--border-default);background:#d8e6ff;">
                        <td style="padding:10px 10px;font-weight:700;">Total Assets</td>
                        <td class="font-mono" style="padding:10px 10px;text-align:right;font-weight:700;" x-text="fmt(report.total_assets)"></td>
                        <template x-if="hasCompare()"><td></td></template>
                    </tr>
                    <tr style="background:#d8e6ff;">
                        <td style="padding:10px 10px;font-weight:700;">Total Liabilities + Equity</td>
                        <td class="font-mono" style="padding:10px 10px;text-align:right;font-weight:700;" x-text="fmt(report.total_liabilities_and_equity)"></td>
                        <template x-if="hasCompare()"><td></td></template>
                    </tr>
                </tbody>
            </table>
        </div>
    </template>
</div>

<script>
function bsReport() {
    const apiBase = '<?= e(base_url('api/v1/accounting')) ?>';
    return {
        form: { as_of: new Date().toISOString().slice(0,10), comparison: 'none' },
        report: null,
        loading: false,
        aiLoading: false,
        aiNarrativeText: '',
        savedList: [],
        savedSel: '',
        async init() { await this.loadSavedList(); await this.load(); },
        hasCompare() { return this.report && this.report.compare_mode !== 'none' && this.report.compare_totals; },
        sections() {
            if (!this.report) return [];
            return [
                { label: 'Current Assets', rows: this.report.current_assets || [], total: this.report.current_assets_total },
                { label: 'Long-Term Assets', rows: this.report.long_term_assets || [], total: this.report.long_term_assets_total },
                { label: 'Current Liabilities', rows: this.report.current_liabilities || [], total: this.report.current_liabilities_total },
                { label: 'Long-Term Liabilities', rows: this.report.long_term_liabilities || [], total: this.report.long_term_liabilities_total },
                { label: 'Equity', rows: this.report.equity || [], total: this.report.total_equity },
            ];
        },
        async load() {
            this.loading = true;
            try {
                const url = new URL(apiBase + '/reports/balance-sheet.php', window.location.origin);
                url.searchParams.set('as_of_date', this.form.as_of);
                url.searchParams.set('comparison', this.form.comparison);
                const r = await fetch(url.toString());
                const j = await r.json();
                this.report = j && j.success ? j.data : null;
            } catch (e) { this.report = null; }
            this.loading = false;
        },
        exportPdf() {
            const url = new URL(apiBase + '/reports/balance-sheet.php', window.location.origin);
            url.searchParams.set('as_of_date', this.form.as_of);
            url.searchParams.set('comparison', this.form.comparison);
            url.searchParams.set('format', 'pdf');
            window.open(url.toString(), '_blank');
        },
        async aiNarrative() {
            if (!this.report) return;
            this.aiLoading = true; this.aiNarrativeText = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch('<?= e(base_url('api/v1/ai/summary.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ entity_type: 'accounting', entity_id: 0, summary_type: 'bs_narrative', context: { as_of: this.form.as_of } })
                });
                const j = await r.json();
                this.aiNarrativeText = (j && j.summary) ? j.summary : ((j && j.error && j.error.message) || 'AI narrative not available.');
            } catch (e) { this.aiNarrativeText = 'AI narrative failed: ' + e.message; }
            this.aiLoading = false;
        },
        async loadSavedList() {
            try {
                const r = await fetch(apiBase + '/saved-reports/index.php?report_type=balance_sheet');
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
                body: JSON.stringify({ name, report_type: 'balance_sheet', parameters: this.form })
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
