---
description: Run financial, fleet, customer, and compliance reports — KPI tiles, charts, data tables, date-range presets, CSV export, and print.
---

# Reports

Financial, fleet, customer, and compliance analytics for your whole operation.

The Reports page is organised into four tabs across the top — **Financial**, **Fleet**, **Customers**, and **Compliance** — each with its own KPI tiles, sub-views, charts, and a data table. A shared **DATE RANGE** bar at the top of the page scopes every report (except Compliance, which uses a look-ahead window). If you have AI access, a fifth **✨ AI Generate** tab lets you describe a report in plain English.

> **Reports vs. Analytics:** Reports answers *"what happened"* over a period you choose — historical revenue, utilization, customer value, and document expiry, with detail tables you can export. [Analytics](/help/analytics) is forward-looking — forecasting, concentration risk, seasonality, and fleet optimisation. Use Reports to review and export; use Analytics to plan.

---

## Financial reports

KPI tiles: **Gross Revenue**, **Collected**, **Outstanding**, **Overdue Amount**, **Avg Invoice**, and **Tax Collected**. Sub-views:

- **By Period** — monthly net revenue, tax, gross, collected, and outstanding, with a *Revenue Trend* chart and a totals row.
- **By Customer** — top 15 customers ranked by gross revenue, with invoices, collected, outstanding, and average invoice value.
- **By Type** — revenue broken down by trailer / equipment category, including unit and lease counts.
- **AR Aging** — outstanding balances grouped into **Current**, **1–30 Days**, **31–60 Days**, **61–90 Days**, and **90+ Days**, with a per-invoice list showing balance due and days overdue.
- **Collection Rate** — monthly invoiced vs. collected with the collection-rate percentage overlaid.
- **Invoice Status** — count and total value by invoice status (a *Status Distribution* donut chart).

---

## Fleet reports

KPI tiles: **Avg Utilization**, **Total Units**, **Idle Units**, **Fleet Revenue**, **Maintenance Cost**, and **Fleet ROI** (revenue minus maintenance). Sub-views:

- **Utilization** — a *Fleet Utilization Distribution* histogram bucketing units into 10% brackets (with an average marker), plus a per-unit table of days leased, utilization %, revenue, maintenance cost, and ROI.
- **ROI Ranking** — **Top 10 — Highest ROI** and **Bottom 10 — Lowest ROI** charts side by side, with a table breaking out labour, parts, and work-order counts.
- **Idle Units** — units with zero lease days in the period, grouped by equipment type.
- **Maintenance** — maintenance cost by work-order type (labour, parts, total, and average cost).
- **By Yard** — revenue vs. maintenance cost, average utilization, idle count, and ROI per yard location.

---

## Customer reports

KPI tiles: **Unique Customers**, **Period Revenue**, **Avg Invoice Value**, **Avg Days to Pay**, **Invoices**, and **Leases**. Sub-views:

- **Lifetime Value** — top 15 customers by all-time revenue (with collected, outstanding, period revenue, and credit issued). LTV is a cumulative metric, so it spans all time regardless of the date range.
- **Payment Behavior** — a *Payment Timing Distribution* showing how many customers pay early, on time, or late, plus per-customer best/worst/average days to pay.
- **New vs Returning** — monthly revenue split between first-time and returning customers.
- **Lease Frequency** — customers ranked by number of leases in the period, with active/completed counts and first/last lease dates.
- **Credit Notes** — credit issued, used, and remaining per customer, with active, used-up, and expired counts.

---

## Compliance reports

This tab tracks three document types per unit — **CVI**, **Registration**, and **Insurance**. Instead of a date range it uses a **LOOK-AHEAD WINDOW** bar with presets **30d**, **60d**, **90d**, **180d**, and **365d**. KPI tiles: **Total Units Tracked**, **Expired Documents**, **Expiring ≤30 Days**, **Expiring ≤90 Days**, **Compliant Units**, and **As Of**. Sub-views:

- **Timeline** — number of CVI, registration, and insurance documents expiring each month.
- **Status** — current state (Expired / ≤30d / 31–90d / OK / No Date) for each document type across all units.
- **Expired** — units with already-expired documents and how many days overdue each is.
- **Upcoming** — units with renewals due inside the look-ahead window and days left on each.

---

## Running a report

1. Open **Reports** from the sidebar.
2. Pick a tab — **Financial**, **Fleet**, **Customers**, or **Compliance**.
3. Set the period in the **DATE RANGE** bar. Choose a preset — **This Month**, **Last Month**, **This Quarter**, **Last Quarter**, **This Year**, **Last Year**, **Last 30d**, **Last 90d**, or **All Time** — or pick **Custom** and enter a start and end date, then click **Go**. (On the **Compliance** tab, set the **LOOK-AHEAD WINDOW** instead.)
4. Choose a sub-view using the secondary tabs under the KPI tiles (e.g. **AR Aging**, **ROI Ranking**, **Lifetime Value**, **Upcoming**). The chart and table reload for that view.
5. Tables show the first 25 rows. If there are more, click **Show all ▼** in the table footer to expand (and **Show less ▲** to collapse).

### Exporting and printing

- **CSV** — click the **CSV** button on the right of the sub-view tabs to download the current view's full table. Files open cleanly in Excel.
- **Print** — click **Print** in the page header for a print-formatted layout (the date bars, tab bars, and buttons are hidden, and a "Printed:" timestamp is stamped at the bottom).

### AI Generate (if enabled)

If you have AI access, open the **✨ AI Generate** tab, type a request like "Describe the chart or report you want…", and click **Generate** to produce a custom chart or written report.

---

<details>
<summary>Under the hood — how it works technically</summary>

**Data sources**
Each tab is served by its own endpoint under `api/v1/reports/` — `revenue.php` (Financial), `fleet.php`, `customer.php`, and `compliance.php`. All four are built on `lib/Reports/ReportBuilder.php`. The `?view=` parameter selects the sub-view; `?preset=` (or `date_from`/`date_to` for custom) sets the range; Compliance uses `?window_days=` (clamped to 7–365) instead.

**Caching**
JSON results are cached in the `report_cache` table for 15 minutes, keyed by report type plus a hash of the resolved date range and view. CSV exports always regenerate from live data (never served from cache) so downloads are current.

**Scale-aware charts**
Built for 1000+ units. Fleet utilization and customer payment timing render as distribution histograms (client-side binning) rather than one bar per unit, and tables cap at 25 rows with an expand toggle — charts summarise the pattern, tables hold the detail.

**Date scoping**
Utilization overlaps each lease's date range with the report window (inclusive day count, D14); revenue and maintenance are scoped to invoices and work orders falling inside the period. AR aging includes only sent / partially-paid / overdue invoices with a balance due. Customer Lifetime Value is intentionally all-time, with a separate period-revenue column for comparison.

**Export format**
`ReportBuilder::outputCsv()` streams the file with a UTF-8 BOM (so Excel reads accents correctly), an `attachment` Content-Disposition, and no-cache headers, then exits — no JSON wrapper.

**Role-gating**
The page and all four APIs require the `reports` / `view` permission (`require_permission('reports','view')`). The **AI Generate** tab and its generator require both `ai` / `view` and `reports` / `view`.

</details>

## Related guides

- [Analytics](/help/analytics)
- [Invoices](/help/invoices)
- [Payments](/help/payments)
