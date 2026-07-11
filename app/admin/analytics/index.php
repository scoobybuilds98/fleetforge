<?php
declare(strict_types=1);
/**
 * app/admin/analytics/index.php
 *
 * Analytics Module — 8 forward-looking chart panels.
 * Revenue forecasting, utilization efficiency, concentration risk,
 * seasonal patterns, cohort analysis, fleet optimizer, lead time,
 * avg lease value trend.
 *
 * All data fetched from api/v1/analytics/index.php (?view= param).
 * No caching — fast-path queries, analytics is real-time.
 *
 * Required by: sidebar navigation (module: analytics)
 * Requires:    includes/auth.php, includes/header.php, app.css, app.js, ApexCharts v3
 *
 * Spec ref: §7.11 Analytics, §9 Analytics Module Charts (8), PROGRESS.md S023
 * Decisions: D7 (base_url/FF_BASE_PATH), D32 (only confirmed CSS classes),
 *            D41 (cssVar() for chart colors — no hardcoded hex in ApexCharts)
 * Permission: analytics / view  (dispatcher has NO access — see permission matrix)
 */

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('analytics', 'view');

$pageTitle      = 'Analytics';
$helpModuleSlug = 'analytics';
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<style>
/* ── Analytics page layout ─────────────────────────────────────────────── */
.an-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}
@media (max-width: 900px) {
    .an-grid { grid-template-columns: 1fr; }
}

/* Full-width panel spans both columns */
.an-panel--full { grid-column: 1 / -1; }

.an-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.an-panel-header {
    padding: 14px 18px 10px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.an-panel-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.3;
}

.an-panel-subtitle {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}

/* KPI strip — 3–5 small stats below title */
.an-kpi-strip {
    display: flex;
    gap: 0;
    padding: 10px 18px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-muted);
    flex-wrap: wrap;
}

.an-kpi {
    flex: 1;
    min-width: 100px;
    padding: 4px 12px 4px 0;
}

.an-kpi-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .3px;
    color: var(--text-muted);
    margin-bottom: 2px;
}

.an-kpi-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
}

.an-kpi-value--up   { color: var(--color-success); }
.an-kpi-value--down { color: var(--color-danger); }

/* Chart area */
.an-chart-area {
    padding: 12px 8px 8px;
    min-height: 260px;
    position: relative;
}

/* Date range controls (panels that support it) */
.an-date-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 18px 10px;
    flex-wrap: wrap;
}

.an-date-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap;
}

/* Skeleton loader */
.an-skeleton {
    background: linear-gradient(90deg, var(--bg-muted) 25%, var(--bg-hover) 50%, var(--bg-muted) 75%);
    background-size: 200% 100%;
    animation: an-shimmer 1.4s infinite;
    border-radius: 4px;
}
@keyframes an-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* Error state */
.an-error {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    color: var(--color-danger);
    font-size: 13px;
    gap: 8px;
}

/* Empty state */
.an-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    color: var(--text-muted);
    font-size: 13px;
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Analytics</h1>
        <p class="page-subtitle">Revenue forecasting, utilization patterns, and fleet intelligence</p>
    </div>
    <div class="page-header-actions">
        <?= help_button('analytics') ?>
        <button class="btn btn-secondary btn-sm"
                @click.prevent="refreshAll()"
                :disabled="anyLoading"
                x-data>
            <svg width="14" height="14"><use href="#icon-arrow-path"/></svg>
            Refresh All
        </button>
    </div>
</div>

<!-- ── AI Report Generator ────────────────────────────────────────────────── -->
<?php
// WHY: AI visualization generator at the top of analytics for easy access
$aiVizId      = 'FF_AiVizAnalytics';
$aiVizContext  = 'analytics';
$aiVizCompact  = false;
include FF_ROOT . '/includes/partials/ai-report-generator.php';
?>

<div x-data="FF_Analytics()" x-init="init()" x-cloak>

    <!-- ════════════════════════════════════════════════════════════════════
         PANEL 1 — Revenue Forecast  (full width)
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="an-grid" style="grid-template-columns:1fr;">
    <div class="an-panel">
        <div class="an-panel-header">
            <div>
                <div class="an-panel-title">Revenue Forecast</div>
                <div class="an-panel-subtitle">Historical (solid) + 3-month projection (dashed) with ±10% confidence band</div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span x-show="loading.revenue_forecast" class="rpt-spinner"></span>
            </div>
        </div>

        <!-- Date range selector -->
        <div class="an-date-row">
            <span class="an-date-label">From</span>
            <input type="date" class="form-control" style="width:140px;font-size:12px;"
                   x-model="dateFrom.revenue_forecast"
                   @change="loadView('revenue_forecast')">
            <span class="an-date-label">To</span>
            <input type="date" class="form-control" style="width:140px;font-size:12px;"
                   x-model="dateTo.revenue_forecast"
                   @change="loadView('revenue_forecast')">
        </div>

        <!-- KPI strip -->
        <div class="an-kpi-strip" x-show="kpis.revenue_forecast">
            <div class="an-kpi">
                <div class="an-kpi-label">Total (Period)</div>
                <div class="an-kpi-value font-mono" x-text="money(kpis.revenue_forecast?.total_12m)"></div>
            </div>
            <div class="an-kpi">
                <div class="an-kpi-label">Avg Monthly</div>
                <div class="an-kpi-value font-mono" x-text="money(kpis.revenue_forecast?.avg_monthly)"></div>
            </div>
            <div class="an-kpi">
                <div class="an-kpi-label">Peak Month</div>
                <div class="an-kpi-value" x-text="kpis.revenue_forecast?.peak_month || '—'"></div>
            </div>
            <div class="an-kpi">
                <div class="an-kpi-label">Projected (3m)</div>
                <div class="an-kpi-value font-mono an-kpi-value--up" x-text="money(kpis.revenue_forecast?.projected_3m)"></div>
            </div>
        </div>
        <div class="an-kpi-strip" x-show="loading.revenue_forecast && !kpis.revenue_forecast">
            <template x-for="i in 4">
                <div class="an-kpi">
                    <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                    <div class="an-skeleton" style="width:80%;height:18px;"></div>
                </div>
            </template>
        </div>

        <!-- Chart -->
        <div class="an-chart-area">
            <div x-show="loading.revenue_forecast && !charts.revenue_forecast"
                 class="an-skeleton" style="height:280px;margin:0 8px;border-radius:6px;"></div>
            <div x-show="errors.revenue_forecast" class="an-error">
                Chart data unavailable
            </div>
            <div id="chart-revenue-forecast"
                 x-show="!loading.revenue_forecast || charts.revenue_forecast"></div>
        </div>
    </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════
         2-COLUMN GRID — Panels 2–7
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="an-grid">

        <!-- ── PANEL 2 — Utilization Matrix ─────────────────────────────── -->
        <div class="an-panel">
            <div class="an-panel-header">
                <div>
                    <div class="an-panel-title">Utilization Efficiency Matrix</div>
                    <div class="an-panel-subtitle">Each unit: utilization % vs revenue per day — grouped by category</div>
                </div>
                <span x-show="loading.utilization_matrix" class="rpt-spinner"></span>
            </div>

            <div class="an-kpi-strip" x-show="kpis.utilization_matrix">
                <div class="an-kpi">
                    <div class="an-kpi-label">Avg Utilization</div>
                    <div class="an-kpi-value" x-text="(kpis.utilization_matrix?.avg_utilization||0) + '%'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Avg Rev/Day</div>
                    <div class="an-kpi-value font-mono" x-text="money(kpis.utilization_matrix?.avg_revenue_per_day)"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">High (&ge;70%)</div>
                    <div class="an-kpi-value an-kpi-value--up" x-text="kpis.utilization_matrix?.high_util_count ?? '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Low (&lt;30%)</div>
                    <div class="an-kpi-value an-kpi-value--down" x-text="kpis.utilization_matrix?.low_util_count ?? '—'"></div>
                </div>
            </div>
            <div class="an-kpi-strip" x-show="loading.utilization_matrix && !kpis.utilization_matrix">
                <template x-for="i in 4">
                    <div class="an-kpi">
                        <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                        <div class="an-skeleton" style="width:80%;height:18px;"></div>
                    </div>
                </template>
            </div>

            <div class="an-chart-area">
                <div x-show="loading.utilization_matrix && !charts.utilization_matrix"
                     class="an-skeleton" style="height:260px;margin:0 8px;border-radius:6px;"></div>
                <div x-show="errors.utilization_matrix" class="an-error">Chart data unavailable</div>
                <div id="chart-utilization-matrix"
                     x-show="!loading.utilization_matrix || charts.utilization_matrix"></div>
            </div>
        </div>

        <!-- ── PANEL 3 — Customer Concentration Risk ─────────────────────── -->
        <div class="an-panel">
            <div class="an-panel-header">
                <div>
                    <div class="an-panel-title">Customer Concentration Risk</div>
                    <div class="an-panel-subtitle">Top 5 customers by % of total revenue (last 12 months)</div>
                </div>
                <span x-show="loading.concentration_risk" class="rpt-spinner"></span>
            </div>

            <div class="an-kpi-strip" x-show="kpis.concentration_risk">
                <div class="an-kpi">
                    <div class="an-kpi-label">Top Customer</div>
                    <div class="an-kpi-value" style="font-size:12px;" x-text="kpis.concentration_risk?.top_customer_name || '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Top Customer %</div>
                    <div class="an-kpi-value"
                         :class="(kpis.concentration_risk?.top_customer_pct||0) > 40 ? 'an-kpi-value--down' : ''"
                         x-text="(kpis.concentration_risk?.top_customer_pct||0) + '%'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Top 5 %</div>
                    <div class="an-kpi-value"
                         :class="(kpis.concentration_risk?.top5_pct||0) > 75 ? 'an-kpi-value--down' : ''"
                         x-text="(kpis.concentration_risk?.top5_pct||0) + '%'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Total Revenue</div>
                    <div class="an-kpi-value font-mono" x-text="money(kpis.concentration_risk?.total_revenue)"></div>
                </div>
            </div>
            <div class="an-kpi-strip" x-show="loading.concentration_risk && !kpis.concentration_risk">
                <template x-for="i in 4">
                    <div class="an-kpi">
                        <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                        <div class="an-skeleton" style="width:80%;height:18px;"></div>
                    </div>
                </template>
            </div>

            <div class="an-chart-area">
                <div x-show="loading.concentration_risk && !charts.concentration_risk"
                     class="an-skeleton" style="height:260px;margin:0 8px;border-radius:6px;"></div>
                <div x-show="errors.concentration_risk" class="an-error">Chart data unavailable</div>
                <div id="chart-concentration-risk"
                     x-show="!loading.concentration_risk || charts.concentration_risk"></div>
            </div>
        </div>

        <!-- ── PANEL 4 — Seasonal Pattern ────────────────────────────────── -->
        <div class="an-panel">
            <div class="an-panel-header">
                <div>
                    <div class="an-panel-title">Seasonal Revenue Pattern</div>
                    <div class="an-panel-subtitle">Average revenue by month of year — all-time data</div>
                </div>
                <span x-show="loading.seasonal_pattern" class="rpt-spinner"></span>
            </div>

            <div class="an-kpi-strip" x-show="kpis.seasonal_pattern">
                <div class="an-kpi">
                    <div class="an-kpi-label">Best Month</div>
                    <div class="an-kpi-value an-kpi-value--up" x-text="kpis.seasonal_pattern?.best_month || '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Worst Month</div>
                    <div class="an-kpi-value an-kpi-value--down" x-text="kpis.seasonal_pattern?.worst_month || '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Seasonal Variance</div>
                    <div class="an-kpi-value" x-text="(kpis.seasonal_pattern?.seasonal_variance||0) + '%'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Monthly Avg</div>
                    <div class="an-kpi-value font-mono" x-text="money(kpis.seasonal_pattern?.avg_all_time)"></div>
                </div>
            </div>
            <div class="an-kpi-strip" x-show="loading.seasonal_pattern && !kpis.seasonal_pattern">
                <template x-for="i in 4">
                    <div class="an-kpi">
                        <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                        <div class="an-skeleton" style="width:80%;height:18px;"></div>
                    </div>
                </template>
            </div>

            <div class="an-chart-area">
                <div x-show="loading.seasonal_pattern && !charts.seasonal_pattern"
                     class="an-skeleton" style="height:260px;margin:0 8px;border-radius:6px;"></div>
                <div x-show="errors.seasonal_pattern" class="an-error">Chart data unavailable</div>
                <div id="chart-seasonal-pattern"
                     x-show="!loading.seasonal_pattern || charts.seasonal_pattern"></div>
            </div>
        </div>

        <!-- ── PANEL 5 — Cohort Revenue ───────────────────────────────────── -->
        <div class="an-panel">
            <div class="an-panel-header">
                <div>
                    <div class="an-panel-title">Cohort Revenue Analysis</div>
                    <div class="an-panel-subtitle">Monthly revenue by lease start year — stacked view</div>
                </div>
                <span x-show="loading.cohort_revenue" class="rpt-spinner"></span>
            </div>

            <!-- Date range -->
            <div class="an-date-row">
                <span class="an-date-label">From</span>
                <input type="date" class="form-control" style="width:140px;font-size:12px;"
                       x-model="dateFrom.cohort_revenue"
                       @change="loadView('cohort_revenue')">
                <span class="an-date-label">To</span>
                <input type="date" class="form-control" style="width:140px;font-size:12px;"
                       x-model="dateTo.cohort_revenue"
                       @change="loadView('cohort_revenue')">
            </div>

            <div class="an-kpi-strip" x-show="kpis.cohort_revenue">
                <div class="an-kpi">
                    <div class="an-kpi-label">Cohorts</div>
                    <div class="an-kpi-value" x-text="kpis.cohort_revenue?.cohort_count ?? '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label" x-text="(kpis.cohort_revenue?.current_year||new Date().getFullYear()) + ' Revenue'"></div>
                    <div class="an-kpi-value font-mono" x-text="money(kpis.cohort_revenue?.current_year_total)"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">YoY Growth</div>
                    <div class="an-kpi-value"
                         :class="(kpis.cohort_revenue?.growth_pct||0) >= 0 ? 'an-kpi-value--up' : 'an-kpi-value--down'"
                         x-text="(kpis.cohort_revenue?.growth_pct >= 0 ? '+' : '') + (kpis.cohort_revenue?.growth_pct||0) + '%'"></div>
                </div>
            </div>
            <div class="an-kpi-strip" x-show="loading.cohort_revenue && !kpis.cohort_revenue">
                <template x-for="i in 3">
                    <div class="an-kpi">
                        <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                        <div class="an-skeleton" style="width:80%;height:18px;"></div>
                    </div>
                </template>
            </div>

            <div class="an-chart-area">
                <div x-show="loading.cohort_revenue && !charts.cohort_revenue"
                     class="an-skeleton" style="height:260px;margin:0 8px;border-radius:6px;"></div>
                <div x-show="errors.cohort_revenue" class="an-error">Chart data unavailable</div>
                <div id="chart-cohort-revenue"
                     x-show="!loading.cohort_revenue || charts.cohort_revenue"></div>
            </div>
        </div>

        <!-- ── PANEL 6 — Fleet Optimizer ──────────────────────────────────── -->
        <div class="an-panel">
            <div class="an-panel-header">
                <div>
                    <div class="an-panel-title">Fleet Composition Optimizer</div>
                    <div class="an-panel-subtitle">Current vs recommended units per category (based on peak demand + 15% headroom)</div>
                </div>
                <span x-show="loading.fleet_optimizer" class="rpt-spinner"></span>
            </div>

            <div class="an-kpi-strip" x-show="kpis.fleet_optimizer">
                <div class="an-kpi">
                    <div class="an-kpi-label">Categories</div>
                    <div class="an-kpi-value" x-text="kpis.fleet_optimizer?.total_categories ?? '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Over Capacity</div>
                    <div class="an-kpi-value an-kpi-value--down" x-text="kpis.fleet_optimizer?.over_capacity ?? '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Under Capacity</div>
                    <div class="an-kpi-value an-kpi-value--up" x-text="kpis.fleet_optimizer?.under_capacity ?? '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Balanced</div>
                    <div class="an-kpi-value" x-text="kpis.fleet_optimizer?.balanced ?? '—'"></div>
                </div>
            </div>
            <div class="an-kpi-strip" x-show="loading.fleet_optimizer && !kpis.fleet_optimizer">
                <template x-for="i in 4">
                    <div class="an-kpi">
                        <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                        <div class="an-skeleton" style="width:80%;height:18px;"></div>
                    </div>
                </template>
            </div>

            <div class="an-chart-area">
                <div x-show="loading.fleet_optimizer && !charts.fleet_optimizer"
                     class="an-skeleton" style="height:260px;margin:0 8px;border-radius:6px;"></div>
                <div x-show="errors.fleet_optimizer" class="an-error">Chart data unavailable</div>
                <div id="chart-fleet-optimizer"
                     x-show="!loading.fleet_optimizer || charts.fleet_optimizer"></div>
            </div>
        </div>

        <!-- ── PANEL 7 — Lead Time ─────────────────────────────────────────── -->
        <div class="an-panel">
            <div class="an-panel-header">
                <div>
                    <div class="an-panel-title">Lead Time Analysis</div>
                    <div class="an-panel-subtitle">Avg days from lease created to activated, per month</div>
                </div>
                <span x-show="loading.lead_time" class="rpt-spinner"></span>
            </div>

            <!-- Date range -->
            <div class="an-date-row">
                <span class="an-date-label">From</span>
                <input type="date" class="form-control" style="width:140px;font-size:12px;"
                       x-model="dateFrom.lead_time"
                       @change="loadView('lead_time')">
                <span class="an-date-label">To</span>
                <input type="date" class="form-control" style="width:140px;font-size:12px;"
                       x-model="dateTo.lead_time"
                       @change="loadView('lead_time')">
            </div>

            <div class="an-kpi-strip" x-show="kpis.lead_time">
                <div class="an-kpi">
                    <div class="an-kpi-label">Avg Lead (days)</div>
                    <div class="an-kpi-value" x-text="kpis.lead_time?.avg_lead_days ?? '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Best Month</div>
                    <div class="an-kpi-value an-kpi-value--up" x-text="kpis.lead_time?.best_month || '—'"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Worst Month</div>
                    <div class="an-kpi-value an-kpi-value--down" x-text="kpis.lead_time?.worst_month || '—'"></div>
                </div>
            </div>
            <div class="an-kpi-strip" x-show="loading.lead_time && !kpis.lead_time">
                <template x-for="i in 3">
                    <div class="an-kpi">
                        <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                        <div class="an-skeleton" style="width:80%;height:18px;"></div>
                    </div>
                </template>
            </div>

            <div class="an-chart-area">
                <div x-show="loading.lead_time && !charts.lead_time"
                     class="an-skeleton" style="height:260px;margin:0 8px;border-radius:6px;"></div>
                <div x-show="errors.lead_time" class="an-error">Chart data unavailable</div>
                <div id="chart-lead-time"
                     x-show="!loading.lead_time || charts.lead_time"></div>
            </div>
        </div>

        <!-- ── PANEL 8 — Avg Lease Value ──────────────────────────────────── -->
        <div class="an-panel">
            <div class="an-panel-header">
                <div>
                    <div class="an-panel-title">Average Lease Value Trend</div>
                    <div class="an-panel-subtitle">Average invoice total per month over selected period</div>
                </div>
                <span x-show="loading.avg_lease_value" class="rpt-spinner"></span>
            </div>

            <!-- Date range -->
            <div class="an-date-row">
                <span class="an-date-label">From</span>
                <input type="date" class="form-control" style="width:140px;font-size:12px;"
                       x-model="dateFrom.avg_lease_value"
                       @change="loadView('avg_lease_value')">
                <span class="an-date-label">To</span>
                <input type="date" class="form-control" style="width:140px;font-size:12px;"
                       x-model="dateTo.avg_lease_value"
                       @change="loadView('avg_lease_value')">
            </div>

            <div class="an-kpi-strip" x-show="kpis.avg_lease_value">
                <div class="an-kpi">
                    <div class="an-kpi-label">All-Time Avg</div>
                    <div class="an-kpi-value font-mono" x-text="money(kpis.avg_lease_value?.avg_all_time)"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Current Month</div>
                    <div class="an-kpi-value font-mono" x-text="money(kpis.avg_lease_value?.current_month)"></div>
                </div>
                <div class="an-kpi">
                    <div class="an-kpi-label">Trend</div>
                    <div class="an-kpi-value"
                         :class="kpis.avg_lease_value?.trend_direction==='up' ? 'an-kpi-value--up' : kpis.avg_lease_value?.trend_direction==='down' ? 'an-kpi-value--down' : ''"
                         x-text="kpis.avg_lease_value?.trend_direction === 'up' ? 'Up' : kpis.avg_lease_value?.trend_direction === 'down' ? 'Down' : 'Flat'"></div>
                </div>
            </div>
            <div class="an-kpi-strip" x-show="loading.avg_lease_value && !kpis.avg_lease_value">
                <template x-for="i in 3">
                    <div class="an-kpi">
                        <div class="an-skeleton" style="width:60%;height:10px;margin-bottom:6px;"></div>
                        <div class="an-skeleton" style="width:80%;height:18px;"></div>
                    </div>
                </template>
            </div>

            <div class="an-chart-area">
                <div x-show="loading.avg_lease_value && !charts.avg_lease_value"
                     class="an-skeleton" style="height:260px;margin:0 8px;border-radius:6px;"></div>
                <div x-show="errors.avg_lease_value" class="an-error">Chart data unavailable</div>
                <div id="chart-avg-lease-value"
                     x-show="!loading.avg_lease_value || charts.avg_lease_value"></div>
            </div>
        </div>

    </div><!-- end .an-grid -->

</div><!-- end x-data -->

<script>
function FF_Analytics() {
    // ── Default date range helpers ─────────────────────────────────────────
    const today  = new Date().toISOString().slice(0, 10);
    const from12 = new Date(Date.now() - 365 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const from24 = new Date(Date.now() - 730 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

    const ALL_VIEWS = [
        'revenue_forecast', 'utilization_matrix', 'concentration_risk',
        'seasonal_pattern', 'cohort_revenue', 'fleet_optimizer',
        'lead_time', 'avg_lease_value'
    ];

    // Views that do NOT use date range (fixed windows)
    const FIXED_VIEWS = ['utilization_matrix', 'concentration_risk', 'seasonal_pattern', 'fleet_optimizer'];

    // ── Initial state ──────────────────────────────────────────────────────
    const loadingInit  = Object.fromEntries(ALL_VIEWS.map(v => [v, false]));
    const errorsInit   = Object.fromEntries(ALL_VIEWS.map(v => [v, false]));
    const kpisInit     = Object.fromEntries(ALL_VIEWS.map(v => [v, null]));
    const chartsInit   = Object.fromEntries(ALL_VIEWS.map(v => [v, null]));

    return {
        loading: { ...loadingInit },
        errors:  { ...errorsInit  },
        kpis:    { ...kpisInit    },
        charts:  { ...chartsInit  },

        // Per-view date ranges (only used by views that support it)
        dateFrom: {
            revenue_forecast: from12,
            cohort_revenue:   from24,
            lead_time:        from12,
            avg_lease_value:  from12,
        },
        dateTo: {
            revenue_forecast: today,
            cohort_revenue:   today,
            lead_time:        today,
            avg_lease_value:  today,
        },

        // ── Init: load all 8 views in parallel ─────────────────────────────
        init() {
            ALL_VIEWS.forEach(v => this.loadView(v));
        },

        // ── Load a single view ──────────────────────────────────────────────
        async loadView(view) {
            this.loading[view] = true;
            this.errors[view]  = false;

            // Destroy existing chart instance before re-rendering
            // WHY: ApexCharts throws if you render into a non-empty element
            if (this.charts[view]) {
                try { this.charts[view].destroy(); } catch(e) {}
                this.charts[view] = null;
            }

            const base = (window.FF_BASE_PATH || '') + '/api/v1/analytics/index.php';
            const params = new URLSearchParams({ view });

            // Attach date params for views that support them
            if (!FIXED_VIEWS.includes(view)) {
                if (this.dateFrom[view]) params.set('date_from', this.dateFrom[view]);
                if (this.dateTo[view])   params.set('date_to',   this.dateTo[view]);
            }

            try {
                const resp = await fetch(base + '?' + params.toString(), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    }
                });
                const json = await resp.json();

                if (!json.success) {
                    this.errors[view] = true;
                    return;
                }

                this.kpis[view] = json.data.kpis || {};

                // Wait for DOM to paint KPI strip before rendering chart
                await this.$nextTick();
                this.renderChart(view, json.data.chart_data || {});

            } catch(e) {
                this.errors[view] = true;
            } finally {
                this.loading[view] = false;
            }
        },

        // ── Refresh all panels ─────────────────────────────────────────────
        refreshAll() {
            ALL_VIEWS.forEach(v => this.loadView(v));
        },

        // ── Loading guard (used by Refresh All button) ─────────────────────
        get anyLoading() {
            return ALL_VIEWS.some(v => this.loading[v]);
        },

        // ══════════════════════════════════════════════════════════════════
        // CHART RENDERER
        // D41 FIX: All colors read from CSS variables via getComputedStyle.
        //          No hardcoded hex values in any chart config.
        // ══════════════════════════════════════════════════════════════════
        renderChart(view, cd) {
            if (!cd || !Object.keys(cd).length) return;

            const el = document.getElementById('chart-' + view.replace(/_/g, '-'));
            if (!el) return;

            // WHY (D41): read colors from CSS vars so charts follow dark/light theme
            const cs      = getComputedStyle(document.documentElement);
            const cssVar  = (v) => cs.getPropertyValue(v).trim();
            const isDark  = document.documentElement.getAttribute('data-theme') === 'dark';

            // Semantic color palette from CSS variables (spec §9 palette order)
            const palette = [
                cssVar('--color-accent')   || '#3b82f6',
                cssVar('--color-success')  || '#10b981',
                cssVar('--color-warning')  || '#f59e0b',
                '#8b5cf6',
                cssVar('--color-danger')   || '#ef4444',
                cssVar('--color-info')     || '#06b6d4',
                '#f97316',
                '#84cc16',
            ];

            const txtMuted = cssVar('--text-secondary') || (isDark ? '#9c9c96' : '#6b6b66');
            const gridCol  = cssVar('--border-color')   || (isDark ? '#2e2e2e' : '#e5e5e2');
            const bgCard   = cssVar('--bg-card')        || (isDark ? '#1a1a1a' : '#ffffff');

            const moneyFmt = (v) => '$' + (v||0).toLocaleString('en-CA', {minimumFractionDigits:0, maximumFractionDigits:0});
            const daysFmt  = (v) => (v||0).toFixed(1) + 'd';

            // S-LUX-2: shared base from the global Atelier chart theme
            // (FF_CHART_THEME) — Geist font (was 'inherit'), token palette,
            // token grid/axes, tooltip.theme, dataLabels-off, legend. We pass
            // the analytics download-only toolbar + the base money yaxis
            // formatter (charts that aren't money override yaxis wholesale).
            // The `palette`/`txtMuted`/`gridCol`/`bgCard` locals above are kept
            // — per-chart overrides still use them for semantic series colours
            // (e.g. Current vs Recommended, confidence bands, annotations).
            const base = FF_CHART_THEME({
                chart: {
                    toolbar: {
                        show: true,
                        tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false }
                    },
                },
                yaxis: { labels: { formatter: moneyFmt } },
            });

            let opts = null;

            switch (view) {

                // ── 1. Revenue Forecast ─────────────────────────────────────
                // Historical solid line + projected dashed line + confidence area
                case 'revenue_forecast': {
                    const histCats = cd.categories || [];
                    const projCats = cd.proj_categories || [];
                    const allCats  = [...histCats, ...projCats];

                    // Pad historical with nulls for projected months
                    const histData = [...(cd.historical || []), ...projCats.map(() => null)];
                    // Pad projected with nulls for historical months (connect at last real point)
                    const lastHist = (cd.historical || []).at(-1) ?? null;
                    const projData = [...histCats.map(() => null), ...(cd.projected || [])];
                    projData[histCats.length - 1] = lastHist; // connect gap

                    // Confidence band: upper and lower series
                    const upperData = [...histCats.map(() => null), ...(cd.upper_band || [])];
                    const lowerData = [...histCats.map(() => null), ...(cd.lower_band || [])];
                    upperData[histCats.length - 1] = lastHist;
                    lowerData[histCats.length - 1] = lastHist;

                    opts = {
                        ...base,
                        chart: { ...base.chart, type: 'line', height: 300 },
                        series: [
                            { name: 'Historical',  data: histData },
                            { name: 'Projected',   data: projData },
                            { name: 'Upper Band',  data: upperData },
                            { name: 'Lower Band',  data: lowerData },
                        ],
                        stroke: {
                            width:     [2, 2, 1, 1],
                            dashArray: [0, 6, 0, 0],   // projected = dashed
                            curve:     'smooth',
                        },
                        fill: {
                            type:     ['solid', 'solid', 'solid', 'solid'],
                            opacity:  [1, 1, 0.15, 0.15],
                        },
                        colors:  [palette[0], palette[1], palette[1], palette[1]],
                        xaxis:   { ...base.xaxis, categories: allCats },
                        yaxis:   { ...base.yaxis, labels: { ...base.yaxis.labels, formatter: moneyFmt } },
                        tooltip: { ...base.tooltip, shared: true, intersect: false },
                        legend:  { ...base.legend, show: true },
                    };
                    break;
                }

                // ── 2. Utilization Matrix ───────────────────────────────────
                case 'utilization_matrix':
                    opts = {
                        ...base,
                        chart: { ...base.chart, type: 'scatter', height: 300 },
                        series: cd.series || [],
                        xaxis: {
                            ...base.xaxis,
                            title: { text: 'Utilization %', style: { color: txtMuted, fontSize: '11px' } },
                            min: 0, max: 100,
                            labels: { ...base.xaxis.labels, formatter: v => v + '%' },
                        },
                        yaxis: {
                            ...base.yaxis,
                            title: { text: 'Revenue / Day', style: { color: txtMuted, fontSize: '11px' } },
                            labels: { ...base.yaxis.labels, formatter: moneyFmt },
                        },
                        tooltip: {
                            ...base.tooltip,
                            custom: function({ seriesIndex, dataPointIndex, w }) {
                                const pt = w.config.series[seriesIndex].data[dataPointIndex];
                                return '<div style="padding:8px 12px;font-size:12px;">' +
                                    '<b>' + (pt.unit_number || '') + '</b><br>' +
                                    'Utilization: ' + pt.x + '%<br>' +
                                    'Rev/Day: $' + (pt.y||0).toFixed(2) +
                                    '</div>';
                            }
                        },
                    };
                    break;

                // ── 3. Concentration Risk (pie) ─────────────────────────────
                case 'concentration_risk':
                    opts = {
                        ...base,
                        chart:  { ...base.chart, type: 'pie', height: 300 },
                        labels: cd.labels || [],
                        series: (cd.series || []).map(Number),
                        legend: { ...base.legend, position: 'bottom', fontSize: '12px' },
                        dataLabels: {
                            enabled: true,
                            formatter: (val) => val.toFixed(1) + '%',
                            style: { fontSize: '11px' },
                        },
                        tooltip: {
                            ...base.tooltip,
                            y: { formatter: moneyFmt },
                        },
                        plotOptions: { pie: { expandOnClick: false } },
                    };
                    break;

                // ── 4. Seasonal Pattern (radar) ─────────────────────────────
                case 'seasonal_pattern':
                    opts = {
                        ...base,
                        chart:  { ...base.chart, type: 'radar', height: 300 },
                        series: cd.series || [],
                        xaxis:  { categories: cd.categories || [] },
                        yaxis:  { show: false },
                        dataLabels: { enabled: false },
                        fill:   { opacity: 0.25 },
                        stroke: { width: 2 },
                        markers: { size: 3 },
                        tooltip: {
                            ...base.tooltip,
                            y: { formatter: moneyFmt },
                        },
                        plotOptions: { radar: { polygons: { strokeColor: gridCol, fill: { colors: ['transparent'] } } } },
                    };
                    break;

                // ── 5. Cohort Revenue (stacked area) ────────────────────────
                case 'cohort_revenue':
                    opts = {
                        ...base,
                        chart:  { ...base.chart, type: 'area', height: 300, stacked: true },
                        series: cd.series || [],
                        xaxis:  { ...base.xaxis, categories: cd.categories || [] },
                        yaxis:  { ...base.yaxis, labels: { ...base.yaxis.labels, formatter: moneyFmt } },
                        stroke: { width: 2, curve: 'smooth' },
                        fill:   { type: 'gradient', gradient: { opacityFrom: 0.55, opacityTo: 0.15 } },
                        tooltip: { ...base.tooltip, shared: true, intersect: false, y: { formatter: moneyFmt } },
                        legend: { ...base.legend, position: 'top' },
                    };
                    break;

                // ── 6. Fleet Optimizer (grouped bar) ────────────────────────
                case 'fleet_optimizer':
                    opts = {
                        ...base,
                        chart: { ...base.chart, type: 'bar', height: 300 },
                        series: [
                            { name: 'Current',     data: cd.current     || [] },
                            { name: 'Recommended', data: cd.recommended || [] },
                        ],
                        xaxis:  { ...base.xaxis, categories: cd.categories || [] },
                        yaxis:  { ...base.yaxis, labels: { ...base.yaxis.labels, formatter: v => Math.round(v) } },
                        colors: [palette[0], palette[2]],
                        plotOptions: { bar: { borderRadius: 3, columnWidth: '60%', grouped: true } },
                        tooltip:     { ...base.tooltip, shared: true, intersect: false },
                        dataLabels:  { enabled: true, style: { fontSize: '11px', colors: [txtMuted] } },
                        legend:      { ...base.legend, position: 'top' },
                    };
                    break;

                // ── 7. Lead Time (line) ─────────────────────────────────────
                case 'lead_time':
                    opts = {
                        ...base,
                        chart:  { ...base.chart, type: 'line', height: 260 },
                        series: cd.series || [],
                        xaxis:  { ...base.xaxis, categories: cd.categories || [] },
                        yaxis:  { ...base.yaxis, labels: { ...base.yaxis.labels, formatter: daysFmt } },
                        stroke: { width: 2, curve: 'smooth' },
                        markers: { size: 4 },
                        colors:  [palette[3]],
                        tooltip: { ...base.tooltip, y: { formatter: daysFmt } },
                    };
                    break;

                // ── 8. Avg Lease Value (line) ───────────────────────────────
                case 'avg_lease_value':
                    opts = {
                        ...base,
                        chart:  { ...base.chart, type: 'line', height: 260 },
                        series: cd.series || [],
                        xaxis:  { ...base.xaxis, categories: cd.categories || [] },
                        yaxis:  { ...base.yaxis, labels: { ...base.yaxis.labels, formatter: moneyFmt } },
                        stroke: { width: 2, curve: 'smooth' },
                        markers: { size: 4 },
                        fill:   { type: 'gradient', gradient: { opacityFrom: 0.2, opacityTo: 0.0 } },
                        colors:  [palette[1]],
                        tooltip: { ...base.tooltip, y: { formatter: moneyFmt } },
                    };
                    break;
            }

            if (!opts) return;

            const chart = new ApexCharts(el, opts);
            chart.render();
            this.charts[view] = chart;
        },

        // ── Money formatter helper ─────────────────────────────────────────
        money(v) {
            const n = parseFloat(v) || 0;
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        },
    };
}
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
