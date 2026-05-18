<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/asset-schedule.php
 *
 * PP&E continuity schedule by asset class. As-of-date picker + category
 * filter (all / specific asset_class ENUM value).
 *
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$pageTitle = 'Asset Schedule';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Asset Schedule</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Fixed Asset Schedule</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="asReport()" x-init="load()">
    <div class="card" style="padding:14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">As of</label>
            <input type="date" x-model="form.as_of" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:3px;">Category</label>
            <select x-model="form.category" class="form-input" style="padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:200px;">
                <option value="all">All</option>
                <option value="fleet_equipment">Fleet Equipment</option>
                <option value="vehicles">Vehicles</option>
                <option value="office_equipment">Office Equipment</option>
                <option value="leasehold_improvements">Leasehold Improvements</option>
                <option value="land">Land</option>
                <option value="building">Building</option>
                <option value="other">Other</option>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" @click="load()" :disabled="loading" x-text="loading ? 'Loading…' : 'Run Report'">Run Report</button>
        <button class="btn btn-secondary btn-sm" @click="exportPdf()">Export PDF</button>
    </div>

    <template x-if="loading"><div class="card" style="padding:36px;text-align:center;color:var(--text-secondary);">Loading…</div></template>
    <template x-if="!loading && report">
        <div class="card" style="padding:18px;overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:8px 10px;text-align:left;">Asset Class</th>
                        <th style="padding:8px 10px;text-align:right;">Opening Cost</th>
                        <th style="padding:8px 10px;text-align:right;">Additions</th>
                        <th style="padding:8px 10px;text-align:right;">Disposals</th>
                        <th style="padding:8px 10px;text-align:right;">Closing Cost</th>
                        <th style="padding:8px 10px;text-align:right;">Opening A/D</th>
                        <th style="padding:8px 10px;text-align:right;">Current Depr.</th>
                        <th style="padding:8px 10px;text-align:right;">Closing A/D</th>
                        <th style="padding:8px 10px;text-align:right;">NBV</th>
                        <th style="padding:8px 10px;text-align:center;">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="cls in report.classes" :key="cls.asset_class">
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td style="padding:6px 10px;text-transform:capitalize;" x-text="cls.asset_class.replace(/_/g,' ')"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(cls.opening_cost)"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(cls.additions)"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(cls.disposals_cost)"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;font-weight:600;" x-text="fmt(cls.closing_cost)"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(cls.opening_accum_dep)"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;" x-text="fmt(cls.current_depr)"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;font-weight:600;" x-text="fmt(cls.closing_accum_dep)"></td>
                            <td class="font-mono" style="padding:6px 10px;text-align:right;font-weight:600;" x-text="fmt(cls.nbv)"></td>
                            <td style="padding:6px 10px;text-align:center;">
                                <button class="btn btn-ghost btn-xs" @click="drillClass = drillClass === cls.asset_class ? null : cls.asset_class">▾</button>
                            </td>
                        </tr>
                        <tr x-show="drillClass === cls.asset_class" x-cloak>
                            <td colspan="10" style="padding:10px 18px;background:var(--bg-elev);">
                                <strong style="font-size:0.8125rem;" x-text="cls.assets.length + ' assets'"></strong>
                                <table style="width:100%;margin-top:8px;border-collapse:collapse;font-size:0.78rem;">
                                    <thead>
                                        <tr style="border-bottom:1px solid var(--border-default);">
                                            <th style="padding:5px 8px;text-align:left;">Asset #</th>
                                            <th style="padding:5px 8px;text-align:left;">Name</th>
                                            <th style="padding:5px 8px;text-align:left;">Acquired</th>
                                            <th style="padding:5px 8px;text-align:right;">Cost</th>
                                            <th style="padding:5px 8px;text-align:right;">A/D</th>
                                            <th style="padding:5px 8px;text-align:right;">NBV</th>
                                            <th style="padding:5px 8px;text-align:right;">YTD Depr.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="a in cls.assets" :key="a.asset_id">
                                            <tr>
                                                <td class="font-mono" style="padding:5px 8px;" x-text="a.asset_number"></td>
                                                <td style="padding:5px 8px;" x-text="a.name"></td>
                                                <td class="font-mono" style="padding:5px 8px;" x-text="a.acquisition_date"></td>
                                                <td class="font-mono" style="padding:5px 8px;text-align:right;" x-text="fmt(a.acquisition_cost)"></td>
                                                <td class="font-mono" style="padding:5px 8px;text-align:right;" x-text="fmt(a.accumulated_depreciation)"></td>
                                                <td class="font-mono" style="padding:5px 8px;text-align:right;" x-text="fmt(a.net_book_value)"></td>
                                                <td class="font-mono" style="padding:5px 8px;text-align:right;" x-text="fmt(a.ytd_depreciation)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>
</div>

<script>
function asReport() {
    const apiBase = '<?= e(base_url('api/v1/accounting')) ?>';
    return {
        form: { as_of: new Date().toISOString().slice(0,10), category: 'all' },
        report: null,
        loading: false,
        drillClass: null,
        async load() {
            this.loading = true;
            try {
                const url = new URL(apiBase + '/reports/asset-schedule.php', window.location.origin);
                url.searchParams.set('as_of_date', this.form.as_of);
                url.searchParams.set('category', this.form.category);
                const r = await fetch(url.toString());
                const j = await r.json();
                this.report = j && j.success ? j.data : null;
            } catch (e) { this.report = null; }
            this.loading = false;
        },
        exportPdf() {
            const url = new URL(apiBase + '/reports/asset-schedule.php', window.location.origin);
            url.searchParams.set('as_of_date', this.form.as_of);
            url.searchParams.set('category', this.form.category);
            url.searchParams.set('format', 'pdf');
            window.open(url.toString(), '_blank');
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
