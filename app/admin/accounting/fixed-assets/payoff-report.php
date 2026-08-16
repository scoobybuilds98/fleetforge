<?php declare(strict_types=1);

/**
 * FleetForge — Fleet Payoff Report
 *
 * @file        app/admin/accounting/fixed-assets/payoff-report.php
 * @description Fleet-wide payoff analysis. Shows every linked fixed
 *              asset with its total invested, net revenue to date,
 *              still to recover, progress %, 6-month projection, and
 *              projected payoff date. Includes filters, sortable
 *              columns, and CSV export.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/fixed_assets/payoff_report.php
 *
 * @session     PAYOFF-1 — Unit Payoff Calculator
 */

// dirname(__DIR__, 4): fixed-assets/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('fixed_assets', 'view');

$pageTitle = 'Fleet Payoff Report';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/fixed-assets') ?>">Fixed Assets</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Fleet Payoff Report</span>
</nav>

<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Fleet Payoff Report</h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            See how every linked fixed asset is recovering its investment, plus a 6-month projection of when each unit pays itself off.
        </div>
    </div>
    <div class="page-header-actions">
        <a href="<?= base_url('accounting/fixed-assets') ?>" class="btn btn-secondary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="vertical-align:-2px;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
            Back to Assets
        </a>
        <button class="btn btn-primary btn-sm" @click="$dispatch('payoff-export-csv')">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="vertical-align:-2px;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
            Export CSV
        </button>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="FF_PayoffReport()" @payoff-export-csv.window="exportCsv()">

    <!-- ── Summary tiles ─────────────────────────────────────── -->
    <div class="stat-grid" style="margin-bottom:24px;">
        <div class="stat-card stat-card--blue">
            <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
            <div class="stat-label">Assets Tracked</div>
            <div class="stat-value font-mono" x-text="summary.asset_count || 0"></div>
        </div>
        <div class="stat-card">
            <span class="stat-icon"><svg><use href="#icon-banknotes"/></svg></span>
            <div class="stat-label">Total Invested</div>
            <div class="stat-value font-mono" x-text="formatMoney(summary.total_invested || 0)"></div>
        </div>
        <div class="stat-card stat-card--green">
            <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
            <div class="stat-label">Net Revenue to Date</div>
            <div class="stat-value font-mono" x-text="formatMoney(summary.total_net_revenue || 0)"></div>
        </div>
        <div class="stat-card stat-card--amber">
            <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
            <div class="stat-label">Still to Recover</div>
            <div class="stat-value font-mono" x-text="formatMoney(summary.total_still_to_recover || 0)"></div>
        </div>
    </div>

    <!-- ── Filter toolbar (wrapped in card for visual containment) ── -->
    <div class="card" style="margin-bottom:20px;padding:14px 16px;">
        <div class="table-toolbar" style="margin-bottom:0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="text" class="form-control form-control-sm" placeholder="Search by name or asset number…"
                   x-model.debounce.300ms="search" @input="load()" style="min-width:260px;flex:1;max-width:380px;">

            <select class="form-select form-control-sm" x-model="filterStatus" @change="load()" style="min-width:160px;">
                <option value="active">Active</option>
                <option value="impaired">Impaired</option>
                <option value="fully_depreciated">Fully Depreciated</option>
                <option value="disposed">Disposed</option>
                <option value="all">All statuses</option>
            </select>

            <span class="text-secondary text-sm" style="margin-left:auto;"
                  x-text="rows.length + ' assets · avg progress ' + (summary.avg_progress_pct || '0.00') + '% · ' + (summary.fully_paid_count || 0) + ' fully paid'"></span>
        </div>
    </div>

    <!-- ── Loading / empty ───────────────────────────────────── -->
    <template x-if="loading">
        <div class="card"><div class="empty-state">Loading…</div></div>
    </template>

    <template x-if="!loading && rows.length === 0">
        <div class="card"><div class="empty-state">
            <p class="empty-state-title">No linked assets found</p>
            <p class="empty-state-text">Only assets with an equipment_unit_id set will appear here. Link them in the asset register first.</p>
        </div></div>
    </template>

    <!-- ── Report table ──────────────────────────────────────── -->
    <template x-if="!loading && rows.length > 0">
        <div class="card" style="padding:0;overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th @click="toggleSort('asset_number')" style="cursor:pointer;user-select:none;white-space:nowrap;">
                            Asset # <span class="text-secondary" x-text="sort === 'asset_number' ? (dir === 'ASC' ? '↑' : '↓') : ''"></span>
                        </th>
                        <th>Name / Unit</th>
                        <th class="text-right" @click="toggleSort('total_acquisition')" style="cursor:pointer;user-select:none;white-space:nowrap;">
                            Total Invested <span class="text-secondary" x-text="sort === 'total_acquisition' ? (dir === 'ASC' ? '↑' : '↓') : ''"></span>
                        </th>
                        <th class="text-right" @click="toggleSort('net_revenue')" style="cursor:pointer;user-select:none;white-space:nowrap;">
                            Net Revenue <span class="text-secondary" x-text="sort === 'net_revenue' ? (dir === 'ASC' ? '↑' : '↓') : ''"></span>
                        </th>
                        <th class="text-right" @click="toggleSort('still_to_recover')" style="cursor:pointer;user-select:none;white-space:nowrap;">
                            Still to Recover <span class="text-secondary" x-text="sort === 'still_to_recover' ? (dir === 'ASC' ? '↑' : '↓') : ''"></span>
                        </th>
                        <th @click="toggleSort('progress_pct')" style="cursor:pointer;user-select:none;min-width:200px;">
                            Progress <span class="text-secondary" x-text="sort === 'progress_pct' ? (dir === 'ASC' ? '↑' : '↓') : ''"></span>
                        </th>
                        <th class="text-right" @click="toggleSort('projection_months')" style="cursor:pointer;user-select:none;white-space:nowrap;">
                            Projection <span class="text-secondary" x-text="sort === 'projection_months' ? (dir === 'ASC' ? '↑' : '↓') : ''"></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in rows" :key="r.id">
                        <tr>
                            <td class="font-mono" style="white-space:nowrap;">
                                <a :href="'<?= base_url('accounting/fixed-assets') ?>?asset=' + r.id" x-text="r.asset_number"></a>
                            </td>
                            <td>
                                <div style="font-weight:600;" x-text="r.name"></div>
                                <div class="text-secondary text-xs" style="margin-top:2px;" x-text="'Unit: ' + r.equipment_unit_number"></div>
                            </td>
                            <td class="text-right font-mono" x-text="formatMoney(r.total_acquisition)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(r.net_revenue)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(r.still_to_recover)"></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div style="flex:1;height:10px;background:var(--bg-tertiary);border-radius:5px;overflow:hidden;border:1px solid var(--border-default);">
                                        <div :style="barStyle(r.progress_pct)"></div>
                                    </div>
                                    <span class="text-xs font-mono" style="min-width:52px;text-align:right;" x-text="r.progress_pct + '%'"></span>
                                </div>
                            </td>
                            <td class="text-right">
                                <template x-if="r.fully_paid">
                                    <span class="badge badge-green">Paid off</span>
                                </template>
                                <template x-if="!r.fully_paid && r.projection_months !== null">
                                    <div>
                                        <div class="font-mono text-sm" x-text="r.projection_months + ' months'"></div>
                                        <div class="text-secondary text-xs" x-text="r.projection_date"></div>
                                    </div>
                                </template>
                                <template x-if="!r.fully_paid && r.projection_months === null">
                                    <span class="text-secondary text-xs">— no projection —</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>
</div>

<script>
function FF_PayoffReport() {
    return {
        rows: [],
        summary: {},
        loading: true,
        search: '',
        filterStatus: 'active',
        sort: 'progress_pct',
        dir: 'DESC',

        async init() {
            await this.load();
        },

        async load() {
            this.loading = true;
            const params = new URLSearchParams();
            if (this.filterStatus) params.set('status', this.filterStatus);
            if (this.search) params.set('search', this.search);
            params.set('sort', this.sort);
            params.set('dir', this.dir);
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/fixed_assets/payoff_report.php') ?>?' + params.toString());
                if (r.success) {
                    this.rows = r.data.rows || [];
                    this.summary = r.data.summary || {};
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load report.');
                }
            } catch (e) {
                FF_Toast.error('Network error.');
            }
            this.loading = false;
        },

        toggleSort(col) {
            if (this.sort === col) {
                this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sort = col;
                this.dir = 'DESC';
            }
            this.load();
        },

        // Progress bar colour: red < 33%, amber 33-65%, green >= 66%.
        barStyle(pctStr) {
            const pct = Math.max(0, Math.min(100, parseFloat(pctStr) || 0));
            let colour = 'var(--color-danger)';
            if (pct >= 66) colour = 'var(--color-success)';
            else if (pct >= 33) colour = 'var(--color-warning)';
            return 'width:' + pct + '%;height:100%;background:' + colour + ';';
        },

        formatMoney(s) {
            if (s === null || s === undefined || s === '') return '—';
            return '$' + parseFloat(s).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        // Client-side CSV export of the currently-loaded rows.
        exportCsv() {
            if (!this.rows || this.rows.length === 0) {
                FF_Toast.error('Nothing to export.');
                return;
            }
            const headers = [
                'Asset #',
                'Name',
                'Equipment Unit',
                'Status',
                'Acquisition Date',
                'Total Invested',
                'Total Revenue',
                'Total Maintenance',
                'Total Damage',
                'Total Financing Paid',
                'Total Fixed Paid',
                'Net Revenue',
                'Still to Recover',
                'Progress %',
                'Avg Net (6mo)',
                'Projection Months',
                'Projection Date',
                'Fully Paid',
            ];
            const esc = v => {
                if (v === null || v === undefined) return '';
                const s = String(v);
                if (s.includes(',') || s.includes('"') || s.includes('\n')) {
                    return '"' + s.replace(/"/g, '""') + '"';
                }
                return s;
            };
            const lines = [headers.join(',')];
            for (const r of this.rows) {
                lines.push([
                    r.asset_number,
                    r.name,
                    r.equipment_unit_number || '',
                    r.status,
                    r.acquisition_date,
                    r.total_acquisition,
                    r.total_revenue,
                    r.total_maintenance,
                    r.total_damage,
                    r.total_financing_paid,
                    r.total_fixed_paid,
                    r.net_revenue,
                    r.still_to_recover,
                    r.progress_pct,
                    r.avg_monthly_net_6mo,
                    r.projection_months ?? '',
                    r.projection_date ?? '',
                    r.fully_paid ? 'yes' : 'no',
                ].map(esc).join(','));
            }
            const csv = lines.join('\n');
            const today = new Date().toISOString().slice(0, 10);
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'fleet-payoff-report-' + today + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            FF_Toast.success('CSV downloaded.');
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
