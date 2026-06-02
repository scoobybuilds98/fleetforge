---
description: Forward-looking fleet intelligence — revenue forecasting, utilization efficiency, concentration risk, seasonal and cohort patterns, fleet-sizing recommendations, and an AI report generator.
---

# Analytics

Eight forward-looking chart panels that turn your operating history into forecasts and patterns. Where the [Dashboard](/help/dashboard) shows what's happening now and [Reports](/help/reports) pulls historical tables, **Analytics** is about *what's coming and what's out of balance* — revenue projections, where fleet capacity is over- or under-sized, and which customers carry too much of your revenue.

The page loads all eight panels at once. Each panel has a short **KPI strip** of headline numbers above its chart.

## AI Report Generator

At the very top sits the **AI Report Generator** — describe any chart or report in plain English and it queries your live data and renders the result.

1. Type into the **Describe the chart or report you want...** box, or click a suggestion chip such as *Revenue forecast for the next 3 months as a line chart* or *Customer concentration — top 5 customers by revenue*.
2. Press Enter or click **Generate**.
3. The response appears below as a mix of text, charts, and tables. A running token count shows in the top right.

> **Note:** This tool only appears when AI is enabled and configured, and only for users who can access both AI and Reports. If you don't see it, that's expected — the eight panels below work regardless.

---

## The eight panels

### Revenue Forecast
Full-width panel at the top. Historical monthly revenue (solid line) plus a **3-month projection** (dashed) wrapped in a **±10% confidence band**. KPIs: **Total (Period)**, **Avg Monthly**, **Peak Month**, and **Projected (3m)**.

### Utilization Efficiency Matrix
A scatter plot where each dot is one unit — its **utilization %** (x-axis) against its **revenue per day** (y-axis), grouped and colored by category. Hover a dot for the unit number, utilization, and rev/day. KPIs: **Avg Utilization**, **Avg Rev/Day**, **High (≥70%)**, and **Low (<30%)** unit counts. Top-left dots (low use, low return) are your weakest assets; top-right are your best.

### Customer Concentration Risk
A pie of your **Top 5 customers by % of total revenue (last 12 months)**, with everyone else rolled into an *Other* slice. KPIs: **Top Customer**, **Top Customer %**, **Top 5 %**, and **Total Revenue**. The **Top Customer %** turns red above 40%, and **Top 5 %** turns red above 75% — flags that too much revenue rests on too few accounts.

### Seasonal Revenue Pattern
A radar chart of **average revenue by month of year**, using **all-time data** (every January averaged together, every February, and so on). KPIs: **Best Month**, **Worst Month**, **Seasonal Variance**, and **Monthly Avg**.

### Cohort Revenue Analysis
A stacked-area view of **monthly revenue by lease start year** — each cohort (the year a lease began) is its own band, so you can see how much current revenue comes from older vs newer business. KPIs: **Cohorts**, the current year's **Revenue**, and **YoY Growth**.

### Fleet Composition Optimizer
A grouped bar chart of **Current vs Recommended** units per category. The recommendation is based on **peak demand + 15% headroom** — the busiest concurrent day in the last 12 months, plus a buffer. KPIs: **Categories**, **Over Capacity**, **Under Capacity**, and **Balanced**. Use it to decide where to buy, sell, or redeploy.

### Lead Time Analysis
A line of **average days from lease created to activated, per month** — how long it takes to turn a new lease into an active one. KPIs: **Avg Lead (days)**, **Best Month**, and **Worst Month**.

### Average Lease Value Trend
A line of the **average invoice total per month** over the selected period. KPIs: **All-Time Avg**, **Current Month**, and a **Trend** indicator (*Up* / *Down* / *Flat*) comparing the period's last month to its first.

---

## Changing the date range

Four panels — **Revenue Forecast**, **Cohort Revenue Analysis**, **Lead Time Analysis**, and **Average Lease Value Trend** — have their own **From** and **To** date pickers.

1. Find the panel you want to rescope.
2. Pick a start date in **From** and an end date in **To**.
3. The panel reloads on its own as soon as a date changes — no submit button.

The other four panels (**Utilization Efficiency Matrix**, **Customer Concentration Risk**, **Seasonal Revenue Pattern**, **Fleet Composition Optimizer**) use fixed windows and have no date pickers — utilization, concentration, and the optimizer always look at the last 12 months, and the seasonal radar is all-time.

## Refreshing and exporting

- Click **Refresh All** in the page header to reload every panel at once. It's disabled while any panel is still loading.
- Hover any chart for exact values.
- Each chart has a menu icon (top right) — use it to **download the chart as an image**.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **One API, eight views** — every panel calls `api/v1/analytics/index.php?view=<name>`; the eight views are `revenue_forecast`, `utilization_matrix`, `concentration_risk`, `seasonal_pattern`, `cohort_revenue`, `fleet_optimizer`, `lead_time`, `avg_lease_value`. The page fires all eight in parallel on load.
- **No caching** — these are real-time fast-path queries; unlike the Dashboard, results are not cached, so a refresh always reflects current data.
- **Revenue source** — revenue panels sum `invoices.total_amount` for non-`void`, non-`draft` invoices (soft-deleted rows excluded).
- **Forecast math** — the projection is a least-squares linear regression computed in PHP over the **last 6 monthly data points**, extended 3 months forward; the band is simply ±10% of each projected value, floored at zero.
- **Utilization** — per unit, days on lease are clamped to a fixed 365-day window (active/completed leases) and divided by 365; revenue/day is invoice revenue over those days.
- **Fleet optimizer** — a sweep algorithm finds the peak number of concurrent active leases per category over the last 12 months; recommended = `ceil(peak × 1.15)`.
- **Lead time** — measured as days from `leases.created_at` to the first `active` entry in `lease_status_log`.
- **AI generator** — the top component posts to `api/v1/ai/generate-visual`; Claude runs read-only queries and returns text plus chart/table specs. It's gated behind `ai:view` + `reports:view`, requires `ai.enabled` and an API key, and is user rate-limited.
- **Role-gating** — the whole module requires the `analytics` / `view` permission. Per the permission matrix, the **dispatcher** role has no access to Analytics.
- **Charts render client-side** — drawn with ApexCharts after each view resolves; colors are read from CSS variables, so charts follow the current light/dark theme.

</details>

## Related guides

- [Reports](/help/reports)
- [Dashboard](/help/dashboard)
