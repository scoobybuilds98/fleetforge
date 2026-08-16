<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/cash-flow.php
 *
 * Cash Flow Statement page (indirect method per ASPE 1540). Renders
 * the operating / investing / financing waterfall. Amber banner if
 * the calculated closing cash differs from the GL 1010 balance by
 * more than $1 (known issue, S-QBO-27).
 *
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$pageTitle = 'Cash Flow Statement';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Cash Flow</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Cash Flow Statement</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="cfReport()">
    <div class="card" style="padding:14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">From</label>
            <input type="date" x-model="form.from" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">To</label>
            <input type="date" x-model="form.to" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <button class="btn btn-primary btn-sm" @click="load()" :disabled="loading" x-text="loading ? 'Loading…' : 'Run Report'">Run Report</button>
        <button class="btn btn-secondary btn-sm" @click="exportPdf()">Export PDF</button>
        <button class="btn btn-secondary btn-sm" @click="aiNarrative()" :disabled="aiLoading || !report" x-text="aiLoading ? 'Thinking…' : 'What\'s Driving Cash Flow?'">What's Driving Cash Flow?</button>
    </div>

    <div class="card" style="padding:10px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);">Saved configurations:</span>
        <select x-model="savedSel" @change="loadSaved()" class="form-input" style="padding:5px 8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:200px;">
            <option value="">— Pick a saved view —</option>
            <template x-for="s in savedList" :key="s.id"><option :value="s.id" x-text="s.name + (s.is_pinned ? ' ★' : '')"></option></template>
        </select>
        <button class="btn btn-ghost btn-xs" @click="saveCurrent()">Save Current…</button>
    </div>

    <template x-if="report && !report.is_tied_out">
        <div class="alert alert-warning" style="margin-bottom:14px;font-size:0.85rem;">
            <strong>Cash tie-out difference</strong> <span x-text="fmt(report.tie_diff)"></span>.
            Calculated closing cash does not match the GL 1010 balance (known issue, S-QBO-27).
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
                <tbody>
                    <tr style="background:var(--bg-elev);">
                        <td colspan="2" style="padding:8px 10px;font-weight:600;">Operating Activities</td>
                    </tr>
                    <tr><td style="padding:6px 14px;">Net Income</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.net_income)"></td></tr>
                    <tr><td style="padding:6px 22px;">+ Depreciation</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.non_cash.depreciation)"></td></tr>
                    <tr><td style="padding:6px 22px;">+ Asset Disposals</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.non_cash.asset_disposal)"></td></tr>
                    <tr><td style="padding:6px 22px;">+ Bad Debt</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.non_cash.bad_debt)"></td></tr>
                    <tr><td style="padding:6px 22px;">+ FX Revaluation</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.non_cash.fx_revaluation)"></td></tr>
                    <template x-for="wc in report.working_capital" :key="wc.label">
                        <tr><td style="padding:6px 22px;" x-text="'Δ ' + wc.label"></td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(wc.cash_impact)"></td></tr>
                    </template>
                    <tr style="border-top:1px solid var(--border-default);font-weight:600;background:var(--bg-elev);">
                        <td style="padding:8px 10px;">Net Cash from Operating</td>
                        <td class="font-mono" style="padding:8px 10px;text-align:right;" x-text="fmt(report.operating_cash)"></td>
                    </tr>

                    <tr style="background:var(--bg-elev);">
                        <td colspan="2" style="padding:8px 10px;font-weight:600;">Investing Activities</td>
                    </tr>
                    <tr><td style="padding:6px 14px;">Asset Acquisitions</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="'(' + fmt(report.investing.asset_acquisitions) + ')'"></td></tr>
                    <tr><td style="padding:6px 14px;">Asset Disposal Proceeds</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.investing.asset_disposal_proceeds)"></td></tr>
                    <tr style="border-top:1px solid var(--border-default);font-weight:600;background:var(--bg-elev);">
                        <td style="padding:8px 10px;">Net Cash from Investing</td>
                        <td class="font-mono" style="padding:8px 10px;text-align:right;" x-text="fmt(report.investing.net)"></td>
                    </tr>

                    <tr style="background:var(--bg-elev);">
                        <td colspan="2" style="padding:8px 10px;font-weight:600;">Financing Activities</td>
                    </tr>
                    <tr><td style="padding:6px 14px;">Long-Term Debt (net)</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.financing.long_term_debt_net)"></td></tr>
                    <tr><td style="padding:6px 14px;">Dividends / Owner Draws</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="'(' + fmt(report.financing.dividends) + ')'"></td></tr>
                    <tr style="border-top:1px solid var(--border-default);font-weight:600;background:var(--bg-elev);">
                        <td style="padding:8px 10px;">Net Cash from Financing</td>
                        <td class="font-mono" style="padding:8px 10px;text-align:right;" x-text="fmt(report.financing.net)"></td>
                    </tr>

                    <tr style="border-top:2px solid var(--border-default);background:#d8e6ff;">
                        <td style="padding:10px;font-weight:700;">Net Change in Cash</td>
                        <td class="font-mono" style="padding:10px;text-align:right;font-weight:700;" x-text="fmt(report.net_change)"></td>
                    </tr>
                    <tr><td style="padding:6px 14px;">Opening Cash</td><td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(report.opening_cash)"></td></tr>
                    <tr style="background:#d8e6ff;font-weight:700;">
                        <td style="padding:10px;">Closing Cash (calc)</td>
                        <td class="font-mono" style="padding:10px;text-align:right;" x-text="fmt(report.closing_cash_calc)"></td>
                    </tr>
                    <tr><td style="padding:6px 14px;color:var(--text-secondary);">Closing Cash (GL 1010)</td><td class="font-mono" style="padding:6px 10px;text-align:right;color:var(--text-secondary);" x-text="fmt(report.closing_cash_gl)"></td></tr>
                </tbody>
            </table>
        </div>
    </template>
</div>

<script>
function cfReport() {
    const apiBase = '<?= e(base_url('api/v1/accounting')) ?>';
    const todayIso = new Date().toISOString().slice(0,10);
    const yearStart = todayIso.slice(0,4) + '-01-01';
    return {
        form: { from: yearStart, to: todayIso },
        report: null,
        loading: false,
        aiLoading: false,
        aiNarrativeText: '',
        savedList: [],
        savedSel: '',
        async init() { await this.loadSavedList(); await this.load(); },
        async load() {
            this.loading = true;
            try {
                const url = new URL(apiBase + '/reports/cash-flow.php', window.location.origin);
                url.searchParams.set('period_start', this.form.from);
                url.searchParams.set('period_end', this.form.to);
                const r = await fetch(url.toString());
                const j = await r.json();
                this.report = j && j.success ? j.data : null;
            } catch (e) { this.report = null; }
            this.loading = false;
        },
        exportPdf() {
            const url = new URL(apiBase + '/reports/cash-flow.php', window.location.origin);
            url.searchParams.set('period_start', this.form.from);
            url.searchParams.set('period_end', this.form.to);
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
                    body: JSON.stringify({ entity_type: 'accounting', entity_id: 0, summary_type: 'cashflow_narrative', context: { from: this.form.from, to: this.form.to } })
                });
                const j = await r.json();
                this.aiNarrativeText = (j && j.summary) ? j.summary : ((j && j.error && j.error.message) || 'AI narrative not available.');
            } catch (e) { this.aiNarrativeText = 'AI narrative failed: ' + e.message; }
            this.aiLoading = false;
        },
        async loadSavedList() {
            try {
                const r = await fetch(apiBase + '/saved-reports/index.php?report_type=cash_flow');
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
                body: JSON.stringify({ name, report_type: 'cash_flow', parameters: this.form })
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
