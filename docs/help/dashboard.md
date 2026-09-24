---
description: Your home screen — a welcome banner with the fleet at a glance, what needs your attention today, the twelve key numbers, and sections for money, leases, the fleet, customers and activity.
---

# Dashboard

The home screen of FleetForge. It answers one question first — *what needs me today?* — and then gives you the full picture of money, leases and the fleet. Every card, tile and row links straight to the records behind it.

## The welcome banner

- **Greeting and summary** — one sentence on the fleet right now, e.g. *140 of 167 units are out on lease, 27 are ready to rent and 3 pickups are booked for today.*
- **Shortcuts** — **New lease**, **New reservation**, **Record payment** and **Batch invoicing**. You only see the ones your role can use.
- **The fleet ring** — on the right: units on lease, units ready to rent, and everything else (in the shop, reserved, inactive). The number in the middle is the share of the fleet on lease right now.

## Needs attention

A row of cards for the things waiting on you, most urgent first. Only the ones that apply appear, and the row shows the **five most urgent** — when more apply, the heading says so and **Show all** opens the rest:

- **Overdue invoices** — past their due date (with the amount owed if your role sees money).
- **Leases waiting to start** — pending leases; red when a start date has already passed.
- **Draft invoices** — not sent yet.
- **Returns this week** — leases ending in the next 7 days.
- **Customers waiting on a reply** — open service requests from the customer portal.
- **Pickups today**, **Renewals due** (unit documents expiring within 30 days), **Open damage claims** and **Open work orders**.
- **Units idle 30+ days** — ready to rent but not earning, with the longest wait.

Click a card to open that list already filtered. When nothing needs you, the row says **Nothing needs you right now**.

> **Note:** lists on the dashboard show up to 10 rows, so a count of **10+** means "ten or more" — open the list to see them all.

## The twelve key numbers

Grouped into three panels. Each tile is **clickable** and opens a pre-filtered list.

- **Money** — **Active Revenue** (monthly rate of every active lease), **Monthly Collections** (paid this month), **Sent Invoices** (awaiting payment) and **Overdue Invoices** (count and amount owed).
- **Fleet** — **On Lease Now** (share of the fleet on lease right now), **Available Units**, **Open Work Orders** and **Damage Claims** (red when any are open).
- **Pipeline** — **Open Leases** (active and pending), **Active Reservations**, **Today's Pickups** and **Compliance Alerts** (documents expiring within 30 days).

> **Note:** a tile reads `—` until its data loads. The numbers are cached for about 5 minutes — reload the page for the latest.

## The section bar

Under the numbers, a bar jumps to **Money**, **Leases**, **Fleet**, **Customers** or **Activity**. It stays at the top of the screen as you scroll and lights up the section you are in.

## Money

- **Billed vs Collected** — each of the last 12 months: what you invoiced (bars) and what customers paid (line). Above it: billed and collected this month, each compared with last month, and how much of what you billed over the year has come in.
- **Owed to you** — everything customers still owe, as one bar split by how late it is (not due yet, 1–30, 31–60, 61–90, 90+ days), with the amount and number of invoices in each.
- **Receivables** — one panel with tabs: **Overdue**, **Unpaid** (sent, partly paid and overdue invoices with a balance) and **Drafts**. **View all →** opens the full list for the tab you are on.
- **Most overdue** — the customers with the largest past-due balances, how many invoices and how late the oldest is. Click one to open the customer.
- **Coming up** — what today's active leases will bill in each of the next 6 months.
- **Days to pay** — how long customers take to pay on average over the last 3 months, whether that is faster or slower than the 3 months before, and the monthly trend.

## Leases

- **Leases** — one panel with tabs: **On lease**, **Starting** (pending activation), **Returning** (ending within 60 days), **Ending this month**, **Just started** (last 7 days) and **Top value**.
- **Reservations** — upcoming pickups, soonest first.
- **Starting vs returning** — leases that started (up) and ended (down) each month, with how many are on rent now and the change over the year.
- **Coming to an end** — a 12-month calendar of leases reaching their end date (open-ended leases don't appear).

## Fleet

- **Fleet mix** — every unit by status in one bar, then each equipment type: how many are on lease, available or elsewhere.
- **Utilization** — occupied ÷ available unit-days each month, with the 12-month average marked.
- **Sitting idle** — available units that have waited longest for a lease. Click one to open the unit.

## Customers

- **Top customers** — who you billed the most this year and each one's share of the year.
- **Revenue by equipment type** — which kinds of equipment bring the money in, as a share of this year's billing.

## Activity

**Recent Activity** — the latest changes your team made, newest first, each with who did it and when.

## If you don't see money

Roles without access to financial figures (for example dispatchers) get the same dashboard without dollar amounts: the Money panel becomes **Billing** (the Receivables lists and Days to pay), the money visuals and the Customers section are left out, and lists show no amounts.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Four parallel fetches on load** — `api/v1/dashboard/kpis`, `charts`, `tables` and `activity_feed`. Each block shows its own loading skeleton and fills in as its data arrives.
- **No auto-refresh** — data is fetched once per page load. Reload to refresh.
- **Caching** — KPI tiles are cached for 5 minutes and chart datasets for 15 minutes in the shared `report_cache` table. Lists and the activity feed are queried live and return up to 10 rows each.
- **Money is decided on the server** — without financial access the page never renders the money tiles, money charts or Customers section, and the APIs strip the figures as well.
- **Needs attention** is built in the browser from the KPIs and the lists; nothing extra is queried.
- **Charts** — only the month-by-month series are ApexCharts (billed vs collected, coming up, days to pay, starting vs returning, utilization); the rest are simple bars and lists. All follow the light/dark theme and brand colour.
- **One request for the visuals** — `api/v1/dashboard/charts?charts=…` returns just the datasets this page uses.

</details>

## Related guides

- [Leases](/help/leases)
- [Invoices](/help/invoices)
- [Customers](/help/customers)
