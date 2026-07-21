<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/per-unit-pnl.php
 *
 * Per-Unit Profitability admin page per spec §23.10. Operator-facing
 * dashboard surfacing per-unit revenue, direct costs, contribution
 * margin, allocated overhead, EBIT, and ROIC — plus fleet-wide KPIs
 * (RevPAU, dollar utilization, maintenance ratio, fleet age).
 *
 * Controls: date range + unit picker + overhead-basis selector
 * (writes accounting.overhead_allocation_basis on change).
 * Output: 4-card fleet KPI bar (when "All Units") + per-unit table
 * with expandable revenue/cost breakdown + per-customer rollup tab.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/partials/accounting-nav.php,
 *           api/v1/accounting/reports/{per-unit-pnl,fleet-kpis}.php
 * @session  S-ACCT-UNIT
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Default period: current YTD (Jan 1 → today) — operators most often
// want YTD vs comparing periods, which they pick manually.
$defaultStart = date('Y') . '-01-01';
$defaultEnd   = date('Y-m-d');

$activeUnits = db_select(
    "SELECT u.id, u.unit_number, u.year, eb.label AS template_brand, t.model AS template_model
       FROM equipment_units u
       LEFT JOIN equipment_templates t ON t.id = u.template_id
       LEFT JOIN equipment_brands eb ON eb.id = u.brand_id
      WHERE u.status NOT IN ('decommissioned','inactive')
        AND u.deleted_at IS NULL
      ORDER BY u.unit_number ASC"
);

$currentBasis = (string) settings_get('accounting.overhead_allocation_basis', 'revenue');

$pageTitle = 'Per-Unit P&L';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Per-Unit P&L</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Per-Unit Profitability + Fleet KPIs</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="perUnitPnl()" x-init="periodStart = '<?= e($defaultStart) ?>'; periodEnd = '<?= e($defaultEnd) ?>'; overheadBasis = '<?= e($currentBasis) ?>'; load()">

    <!-- Controls -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:end;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Period Start</label>
            <input type="date" x-model="periodStart" class="form-input"
                   style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Period End</label>
            <input type="date" x-model="periodEnd" class="form-input"
                   style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Unit</label>
            <select x-model.number="selectedUnitId" class="form-input"
                    style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:200px;">
                <option :value="null">All Units</option>
                <?php foreach ($activeUnits as $u): ?>
                    <option value="<?= (int) $u['id'] ?>">
                        <?= e($u['unit_number']) ?>
                        <?= $u['template_brand'] || $u['template_model'] ? '(' . e(trim(($u['year'] ?? '') . ' ' . ($u['template_brand'] ?? '') . ' ' . ($u['template_model'] ?? ''))) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Overhead Basis</label>
            <select x-model="overheadBasis" @change="saveBasis()" class="form-input"
                    style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <option value="revenue">Revenue</option>
                <option value="unit_count">Unit Count</option>
                <option value="weighted">Weighted (70/30)</option>
                <option value="manual">Manual</option>
            </select>
        </div>
        <button @click="load()" :disabled="loading" class="btn btn-primary" style="height:36px;">
            <span x-show="!loading">Run Report</span>
            <span x-show="loading">Working...</span>
        </button>
        <button @click="downloadCsv()" :disabled="loading || !report" class="btn btn-secondary" style="height:36px;">Export CSV</button>
        <button @click="downloadPdf()" :disabled="loading || !report" class="btn btn-secondary" style="height:36px;">Export PDF</button>
    </div>

    <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:16px;padding-left:6px;">
        <strong>Revenue basis</strong> allocates overhead proportionally to unit revenue.
        <strong>Weighted</strong> blends revenue (70%) and unit count (30%).
        <strong>Manual</strong> reads per-unit setting <code>accounting.overhead_allocation_manual_{unit_id}</code>.
    </div>

    <!-- Error -->
    <template x-if="error">
        <div class="card" style="padding:18px;color:var(--color-danger);" x-text="error"></div>
    </template>

    <!-- Fleet KPIs (only when All Units) -->
    <template x-if="!selectedUnitId && kpis">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
            <div class="card" style="padding:14px;text-align:center;">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">RevPAU</div>
                <div style="font-size:1.5rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);" x-text="'$' + money(kpis.revpau)"></div>
                <div style="font-size:0.7rem;color:var(--text-secondary);margin-top:2px;">per unit per day</div>
            </div>
            <div class="card" style="padding:14px;text-align:center;">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">Dollar Utilization</div>
                <div style="font-size:1.5rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);"
                     x-text="kpis.dollar_utilization_pct !== null ? parseFloat(kpis.dollar_utilization_pct).toFixed(1) + '%' : '—'"></div>
                <div style="font-size:0.7rem;color:var(--text-secondary);margin-top:2px;">annualized / OEC</div>
            </div>
            <div class="card" style="padding:14px;text-align:center;"
                 :style="kpis.maintenance_benchmark_flag === 'high' ? 'border-color:#b8860b;' : (kpis.maintenance_benchmark_flag === 'low' ? 'border-color:#1e5e1e;' : '')">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">Maintenance Ratio</div>
                <div style="font-size:1.5rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);"
                     :style="kpis.maintenance_benchmark_flag === 'high' ? 'color:#b8860b;' : ''"
                     x-text="parseFloat(kpis.maintenance_cost_ratio_pct).toFixed(1) + '%'"></div>
                <div style="font-size:0.7rem;margin-top:2px;"
                     :style="kpis.maintenance_benchmark_flag === 'high' ? 'color:#b8860b;font-weight:600;' : 'color:var(--text-secondary);'"
                     x-text="kpis.maintenance_benchmark_flag === 'high' ? '⚠ Above 20%' : (kpis.maintenance_benchmark_flag === 'low' ? '✓ Below 15%' : 'Within band')"></div>
            </div>
            <div class="card" style="padding:14px;text-align:center;">
                <div style="font-size:0.7rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;">Fleet Age</div>
                <div style="font-size:1.5rem;font-weight:600;margin-top:4px;font-family:var(--font-mono,monospace);"
                     x-text="kpis.fleet_age_weighted_avg_years !== null ? parseFloat(kpis.fleet_age_weighted_avg_years).toFixed(1) + ' yr' : '—'"></div>
                <div style="font-size:0.7rem;color:var(--text-secondary);margin-top:2px;">weighted by cost</div>
            </div>
        </div>
    </template>

    <!-- Tabs (per-unit / per-customer) -->
    <template x-if="report">
        <div>
            <div style="display:flex;gap:8px;margin-bottom:12px;border-bottom:1px solid var(--border-default);">
                <button @click="tab='units'" class="btn btn-ghost"
                        :style="tab === 'units' ? 'border-bottom:2px solid var(--color-primary,#1d1d1f);font-weight:600;' : ''">
                    Per-Unit P&L (<span x-text="(report.units || []).length"></span>)
                </button>
                <button @click="tab='customers'" class="btn btn-ghost"
                        :style="tab === 'customers' ? 'border-bottom:2px solid var(--color-primary,#1d1d1f);font-weight:600;' : ''">
                    Per-Customer Rollup (<span x-text="(report.by_customer || []).length"></span>)
                </button>
            </div>

            <!-- Empty state -->
            <template x-if="(report.units || []).length === 0">
                <div class="card" style="padding:36px;text-align:center;color:var(--text-secondary);">
                    <span x-text="report.message || 'No data for this period.'"></span>
                </div>
            </template>

            <!-- Per-unit table -->
            <template x-if="tab === 'units' && (report.units || []).length > 0">
                <div class="card" style="padding:0;overflow:auto;">
                    <table class="table" style="font-size:0.8125rem;">
                        <thead>
                            <tr>
                                <th>Unit #</th>
                                <th>Make/Model</th>
                                <th class="text-right">Revenue</th>
                                <th class="text-right">Direct Costs</th>
                                <th class="text-right">Contribution</th>
                                <th class="text-right">CM %</th>
                                <th class="text-right">OH Alloc</th>
                                <th class="text-right">EBIT</th>
                                <th class="text-right">ROIC %</th>
                                <th>Attribution</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="u in report.units" :key="u.unit_id">
                                <template>
                                    <tr>
                                        <td class="font-mono" x-text="u.unit_number"></td>
                                        <td style="color:var(--text-secondary);font-size:0.75rem;" x-text="u.display_label"></td>
                                        <td class="font-mono text-right" x-text="'$' + money(u.revenue.total)"></td>
                                        <td class="font-mono text-right" x-text="'$' + money(u.direct_costs.total_direct)"></td>
                                        <td class="font-mono text-right" x-text="'$' + money(u.contribution_margin)"></td>
                                        <td class="font-mono text-right" x-text="u.contribution_margin_pct !== null ? parseFloat(u.contribution_margin_pct).toFixed(1) + '%' : '—'"></td>
                                        <td class="font-mono text-right" x-text="'$' + money(u.overhead_allocated)"></td>
                                        <td class="font-mono text-right" style="font-weight:600;"
                                            :style="parseFloat(u.ebit) > 0 ? 'color:#1e5e1e;' : (parseFloat(u.ebit) < 0 ? 'color:#a30000;' : 'color:#666;')"
                                            x-text="'$' + money(u.ebit)"></td>
                                        <td class="font-mono text-right" x-text="u.roic_pct !== null ? parseFloat(u.roic_pct).toFixed(2) + '%' : '—'"></td>
                                        <td>
                                            <span class="badge"
                                                  :class="u.attribution_method === 'je_lines' ? 'badge-success' : 'badge-warning'"
                                                  style="padding:1px 6px;font-size:0.625rem;"
                                                  x-text="u.attribution_method === 'je_lines' ? 'GL Lines' : 'Estimated'"></span>
                                        </td>
                                        <td>
                                            <button class="btn btn-ghost btn-xs" @click="toggleDrill(u.unit_id)"
                                                    :title="expanded[u.unit_id] ? 'Hide' : 'Show'">
                                                <span x-text="expanded[u.unit_id] ? '−' : '+'"></span>
                                            </button>
                                        </td>
                                    </tr>
                                    <template x-if="expanded[u.unit_id]">
                                        <tr>
                                            <td colspan="11" style="background:var(--bg-subtle);padding:14px 18px;">
                                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;font-size:0.75rem;">
                                                    <div>
                                                        <div style="font-weight:600;margin-bottom:6px;color:var(--text-secondary);">Revenue Breakdown</div>
                                                        <table class="table" style="font-size:0.75rem;">
                                                            <tbody>
                                                                <tr><td>Base Rental</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.base_rental)"></td></tr>
                                                                <tr><td>Mileage</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.mileage)"></td></tr>
                                                                <tr><td>GPS</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.gps)"></td></tr>
                                                                <tr><td>Insurance</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.insurance)"></td></tr>
                                                                <tr><td>Warranty</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.warranty)"></td></tr>
                                                                <tr><td>Damage Recovery</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.damage_recovery)"></td></tr>
                                                                <tr><td>Other</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.other)"></td></tr>
                                                                <tr style="border-top:1px solid var(--border-default);font-weight:600;"><td>Total</td><td class="font-mono text-right" x-text="'$' + money(u.revenue.total)"></td></tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight:600;margin-bottom:6px;color:var(--text-secondary);">Direct Cost Breakdown</div>
                                                        <table class="table" style="font-size:0.75rem;">
                                                            <tbody>
                                                                <tr><td>Depreciation</td><td class="font-mono text-right" x-text="'$' + money(u.direct_costs.depreciation)"></td></tr>
                                                                <tr><td>Insurance</td><td class="font-mono text-right" x-text="'$' + money(u.direct_costs.insurance)"></td></tr>
                                                                <tr><td>Licensing &amp; Reg.</td><td class="font-mono text-right" x-text="'$' + money(u.direct_costs.licensing)"></td></tr>
                                                                <tr><td>GPS Cost</td><td class="font-mono text-right" x-text="'$' + money(u.direct_costs.gps_cost)"></td></tr>
                                                                <tr><td>Maintenance</td><td class="font-mono text-right" x-text="'$' + money(u.direct_costs.maintenance)"></td></tr>
                                                                <tr><td>Other Direct</td><td class="font-mono text-right" x-text="'$' + money(u.direct_costs.other_direct)"></td></tr>
                                                                <tr style="border-top:1px solid var(--border-default);font-weight:600;"><td>Total Direct</td><td class="font-mono text-right" x-text="'$' + money(u.direct_costs.total_direct)"></td></tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </template>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr style="background:var(--bg-subtle);font-weight:600;">
                                <td colspan="2">Fleet Total</td>
                                <td class="font-mono text-right" x-text="'$' + money(report.fleet_totals.fleet_revenue)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(report.fleet_totals.fleet_direct_costs)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(report.fleet_totals.fleet_contribution_margin)"></td>
                                <td></td>
                                <td class="font-mono text-right" x-text="'$' + money(report.fleet_totals.fleet_overhead_allocated)"></td>
                                <td class="font-mono text-right" x-text="'$' + money(report.fleet_totals.fleet_ebit)"></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </template>

            <!-- Per-customer tab -->
            <template x-if="tab === 'customers'">
                <div class="card" style="padding:0;overflow:auto;">
                    <table class="table" style="font-size:0.8125rem;">
                        <thead>
                            <tr><th>Customer</th><th class="text-right">Gross Revenue</th><th class="text-right">Invoice Count</th></tr>
                        </thead>
                        <tbody>
                            <template x-for="c in (report.by_customer || [])" :key="c.customer_id">
                                <tr>
                                    <td x-text="c.company_name"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(c.gross_revenue)"></td>
                                    <td class="text-right" x-text="c.invoice_count"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </template>
</div>

<script>
function perUnitPnl() {
    return {
        periodStart: '<?= e($defaultStart) ?>',
        periodEnd: '<?= e($defaultEnd) ?>',
        selectedUnitId: null,
        overheadBasis: '<?= e($currentBasis) ?>',
        loading: false,
        error: '',
        report: null,
        kpis: null,
        tab: 'units',
        expanded: {},

        money(v) {
            const n = parseFloat(v || 0);
            return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        toggleDrill(id) {
            this.expanded[id] = !this.expanded[id];
        },

        async load() {
            this.error = '';
            this.loading = true;
            this.expanded = {};
            try {
                const params = new URLSearchParams({
                    period_start: this.periodStart,
                    period_end: this.periodEnd,
                });
                if (this.selectedUnitId) params.set('unit_id', this.selectedUnitId);

                const pnlPromise = fetch('<?= base_url('api/v1/accounting/reports/per-unit-pnl.php') ?>?' + params, {
                    credentials: 'same-origin'
                }).then(r => r.json());

                const kpiParams = new URLSearchParams({
                    period_start: this.periodStart,
                    period_end: this.periodEnd,
                });
                const kpiPromise = this.selectedUnitId
                    ? Promise.resolve({ success: true, data: { kpis: null } })
                    : fetch('<?= base_url('api/v1/accounting/reports/fleet-kpis.php') ?>?' + kpiParams, {
                        credentials: 'same-origin'
                    }).then(r => r.json());

                const [pnl, kpi] = await Promise.all([pnlPromise, kpiPromise]);
                if (!pnl.success) throw new Error(pnl.message || 'P&L load failed');
                if (kpi && !kpi.success) throw new Error(kpi.message || 'KPI load failed');

                this.report = pnl.data;
                this.kpis = kpi?.data?.kpis ?? null;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        downloadCsv() {
            const params = new URLSearchParams({
                period_start: this.periodStart,
                period_end: this.periodEnd,
                format: 'csv',
            });
            if (this.selectedUnitId) params.set('unit_id', this.selectedUnitId);
            window.location.href = '<?= base_url('api/v1/accounting/reports/per-unit-pnl.php') ?>?' + params;
        },

        downloadPdf() {
            const params = new URLSearchParams({
                period_start: this.periodStart,
                period_end: this.periodEnd,
                format: 'pdf',
            });
            if (this.selectedUnitId) params.set('unit_id', this.selectedUnitId);
            window.open('<?= base_url('api/v1/accounting/reports/per-unit-pnl.php') ?>?' + params, '_blank');
        },

        async saveBasis() {
            try {
                const r = await fetch('<?= base_url('api/v1/accounting/settings/update.php') ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: { 'accounting.overhead_allocation_basis': this.overheadBasis }
                    })
                });
                const j = await r.json();
                if (!j.success) throw new Error(j.message || 'save failed');
                // Reload to pick up new allocation
                await this.load();
            } catch (e) {
                this.error = e.message;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
