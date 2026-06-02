---
description: Your at-a-glance command center — KPI tiles, revenue and fleet charts, and live carousels of leases, invoices, and reservations that need attention.
---

# Dashboard

The home screen of FleetForge — a read-only overview of fleet, revenue, and what needs attention right now. Every tile and card links straight to the underlying records.

## Reading the KPI tiles

The row of tiles at the top is your live snapshot. Each tile is **clickable** and drills through to a pre-filtered list.

- **Active Revenue** — total monthly rate across all active leases. Opens **Reports**.
- **Fleet Utilization** — percentage of active units currently on lease, with the *X of Y units* count below. Opens **Equipment**.
- **Overdue Invoices** — count of overdue invoices, with the total dollars outstanding below. Opens **Invoices** filtered to *overdue*.
- **Compliance Alerts** — units with any document (CVI, registration, MVI, or insurance) expiring in the next 30 days. Opens **Compliance**.
- **Open Leases** — active and pending leases combined. Opens **Leases** filtered to *active*.
- **Today's Pickups** — reservations scheduled to pick up today. Opens **Reservations** filtered to today's pickup date.
- **Available Units** — units ready to rent. Opens **Equipment** filtered to *available*.
- **Open Work Orders** — open and in-progress maintenance work orders. Opens **Maintenance** filtered to *open*.
- **Damage Claims** — open claims (turns red when any exist). Opens **Damage Claims**.
- **Sent Invoices** — sent invoices awaiting payment. Opens **Invoices** filtered to *sent*.
- **Monthly Collections** — total payments collected this calendar month. Opens **Payments**.
- **Active Reservations** — pending and confirmed reservations. Opens **Reservations**.

> **Note:** A tile reads `—` until its data loads. KPI numbers are cached and refresh roughly every 5 minutes — reload the page to pull the latest.

---

## Reading the charts

Twelve charts visualize trends across revenue, fleet, and receivables:

- **Revenue — Last 12 Months** — monthly revenue trend (area).
- **Fleet Status** — current unit breakdown by status (donut).
- **Revenue Forecast — Next 6 Months** — projected revenue (bar).
- **Occupancy by Equipment Type** — share of each type currently on lease (100% stacked bar).
- **AR Aging** — outstanding receivables by age bucket (horizontal bar).
- **Utilization Trend** — fleet utilization over time (line).
- **Avg Days to Pay — Last 12 Months** — average invoice payment speed (line).
- **Lease Expiry Calendar — Next 12 Months** — leases expiring per month (bar).
- **Top Customers — YTD** — customers ranked by revenue this year (horizontal bar).
- **Leases Opened vs Closed** — monthly open/close counts (grouped bar).
- **Revenue by Equipment Type — YTD** — revenue share per type (donut).
- **Daily Revenue — Last 12 Weeks** — daily revenue intensity (heatmap).

> **Tip:** Hover any chart for exact values, and use the chart's menu icon (top right) to download it as an image.

---

## Working the carousels

Between the charts are horizontal card strips covering the work that needs attention. Each card links to that record's detail page, and every strip has a **View all →** link to the full filtered list.

- **Active Leases** — currently running leases, with rate and days active.
- **Reservations** — upcoming reservations, with pickup date and time.
- **High-Value Leases** — active leases sorted by highest rate.
- **Outstanding Invoices** — sent, partially paid, and overdue invoices with balance owing.
- **Overdue Payments** — overdue invoices flagged by days overdue.
- **Draft Invoices** — invoices not yet sent, with days in draft.
- **Expiring This Month** — leases ending this month (red badge at 7 days or fewer).
- **Pending Activations** — pending leases awaiting activation (red if past the scheduled start).
- **Upcoming Returns** — leases returning soon, color-coded by days remaining.
- **Recently Activated** — leases activated in the last 7 days.

If a strip is empty it says so (e.g. *No overdue payments*). If a strip fails to load, click **Retry**.

---

## Recent Activity

The **Recent Activity** feed lists the latest actions staff have taken across the system — creates, updates, status changes, and more — each tagged with who did it and how long ago. It is a read-only log; click into the relevant module to act on anything you see.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Four parallel fetches on load** — the page calls `api/v1/dashboard/kpis`, `charts`, `tables`, and `activity_feed` in parallel when it opens. Each section shows its own loading skeleton and renders independently as its data arrives.
- **No auto-refresh** — data is fetched once per page load. Reload the page to refresh.
- **Caching** — KPI tiles are cached for 5 minutes and chart datasets for 15 minutes in the `report_cache` table (shared cache, not per-user). Carousels and the activity feed are queried live on each load.
- **No permission gate** — every dashboard endpoint requires only an authenticated session (`require_auth_api`). There is no module permission, so the dashboard is visible to all signed-in staff.
- **Activity feed source** — built from the `audit_log` table, newest first, limited to the most recent entries. Descriptions are generated from each row's action and module.
- **Charts render client-side** — drawn with ApexCharts after the chart data resolves; colors follow the current light/dark theme.
- **Empty data** — the *Revenue by Equipment Type* donut shows a "No data yet" message until invoices exist; other charts simply render empty.

</details>

## Related guides

- [Leases](/help/leases)
- [Invoices](/help/invoices)
- [Customers](/help/customers)
