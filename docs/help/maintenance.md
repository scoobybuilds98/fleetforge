---
description: Open and track repair and service work orders against fleet units — priorities, line items for parts and labour, vendor assignment, and a status lifecycle from open to completed.
---

# Maintenance

Raise and track work orders for repairs, scheduled service, and inspections on your fleet units.

## Reading the dashboard

The Maintenance Work Orders list opens with four KPI tiles. All four are clickable — selecting one filters the table to that status (click again to clear).

- **Total Work Orders** — every work order, all time.
- **Open** — work orders awaiting start.
- **Active** — work orders that are **in progress** or **waiting parts**.
- **Completed This Month** — work orders closed out since the first of the current month.

Below the tiles, each row shows the **WO #**, the **Unit** (its number plus year/brand/model, linking to the equipment profile), the **Title**, the **Type**, the **Priority**, the **Status**, the **Requested** date, the **Cost** (total, shown as `—` until line items are added), and the **Vendor**.

Filter the table with the **All Statuses**, **All Types**, and **All Priorities** dropdowns, or the **Search title or WO#…** box. Click the **WO #**, **Priority**, **Status**, **Requested**, or **Cost** column headers to sort, and use **Reset** to clear all filters.

## Creating a work order

1. Click **+ New Work Order** in the top right of the Maintenance list.
2. Under **Equipment & Vendor**, pick the **Equipment Unit** (required) — decommissioned and inactive units are greyed out and can't be serviced. Optionally choose a **Vendor**.
3. Under **Work Order Details**, enter a **Title** (required), pick a **Work Type** (required — Scheduled Service, Repair, Inspection, Tire, Electrical, Body Damage, Breakdown, or Other), set a **Priority** (required — defaults to Medium), and add a **Description**.
4. Under **Dates & Mileage**, set the **Requested Date** (required, defaults to today), an optional **Scheduled Date** (cannot be earlier than the requested date), and the **Odometer (km)** reading at service.
5. Under **Assignment & Notes**, optionally set **Assigned To**, **Notes (visible to vendor)**, and **Internal Notes (admin only)**.
6. Click **Create Work Order**.

New work orders always start in **Open** status with a cost of $0.00 — costs are built up from line items. You can also start a work order pre-filled for a specific unit from the **Maintenance** tab of that unit's equipment profile.

## Viewing a work order

Click any row (or the **WO #**) to open the work order. The header shows the work order number with its status badge and title, and four tiles summarise **Priority**, **Labour Cost**, **Parts Cost**, and **Total Cost**. Below that, stacked cards hold the status-transition buttons, the **Work Order Details** (the unit with its live status badge, vendor, dates, odometer, assignee, and all notes), and the **Line Items** table.

## Moving a work order through its lifecycle

Open work orders are advanced with the transition buttons in the **Transition:** row. Only the moves allowed from the current status are shown:

1. From **Open**, click **▶ Start Work** to begin, or **✕ Cancel** to cancel.
2. From **In Progress**, click **⏸ Waiting Parts** to pause for parts, or **✓ Complete** to close it out.
3. From **Waiting Parts**, click **▶ Start Work** to resume.
4. When you click **✓ Complete**, a **Resolution Notes (optional)** box appears — describe what was done, then click **Confirm Complete**.

**Completed** and **Cancelled** are final — a work order in either state can't be transitioned, edited, or have line items changed.

## Editing a work order

1. On the work order, click **Edit** in the **Work Order Details** card header.
2. Update the **Work Type**, **Priority**, **Title**, **Vendor**, **Requested Date**, **Scheduled Date**, **Odometer (km)**, **Assigned To**, **Description**, **Notes (visible to vendor)**, or **Internal Notes**.
3. Click **Save**.

Editing is available only while the work order is open, in progress, or waiting parts — not once it's completed or cancelled.

## Adding parts and labour (line items)

1. On the work order, click **+ Add Item** in the **Line Items** card.
2. Choose a **Type** — **Labour**, **Part**, **Sublet**, or **Other**.
3. Enter the **Part Number** (optional), a **Description** (required), the **Quantity** (required), and the **Unit Cost** (required). The **Line Total** is calculated for you.
4. Click **Add Item**.

Each line's total is quantity × unit cost. The work order's **Labour Cost** rolls up all Labour lines, **Parts Cost** rolls up Part, Sublet, and Other lines, and **Total Cost** is the sum of both — updated automatically every time you add or remove a line. Use the **✕** button on a row to delete a line item.

## Deleting a work order

1. Open a work order that is **Open** or **Cancelled** and click **Delete** in the page header.
2. Confirm in the **Delete Work Order?** dialog.

Only open or cancelled work orders can be deleted. In-progress, waiting-parts, and completed work orders cannot — cancel an unstarted job first if you need to remove it.

## Status reference

| Status | Meaning | Can move to |
|--------|---------|-------------|
| **Open** | Raised, awaiting start | In Progress, Cancelled |
| **In Progress** | Work underway | Waiting Parts, Completed |
| **Waiting Parts** | Paused pending parts | In Progress |
| **Completed** | Closed out — terminal | — |
| **Cancelled** | Cancelled — terminal | — |

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Status lifecycle** — five statuses (`open`, `in_progress`, `waiting_parts`, `completed`, `cancelled`). A dedicated endpoint enforces the allowed moves: open → in_progress / cancelled; in_progress → waiting_parts / completed; waiting_parts → in_progress; `completed` and `cancelled` are terminal. Same-status transitions are a no-op. Every change writes an `audit_log` row (`action='status_change'`); there is no separate status-log table.
- **On completion** — completing sets `completed_date` to today and stamps `completed_by`, and saves resolution notes if entered. If the work order has a vendor, the vendor's `total_spent` counter is incremented by the work order's total cost in the same transaction.
- **Equipment relationship** — a work order references one `equipment_unit`. Creating a work order does **not** automatically change that unit's status to Maintenance; the unit status badge on the detail page is informational only. Decommissioned and inactive units are rejected for service (both in the form and server-side); leased and in-maintenance units are still serviceable.
- **Work order number** — generated atomically as `WO-…-{year}` inside the create transaction so numbers never collide.
- **Line items & cost rollup** — line item totals are computed with bcmath (quantity × unit cost). After any add/delete, the work order's `labor_cost` (sum of `labor` lines), `parts_cost` (sum of `part`, `sublet`, `other` lines), and `total_cost` (their sum) are recalculated. Line items can't be added to a completed or cancelled work order.
- **Optimistic locking** — the edit form captures `updated_at` on load and sends it back (D19); a concurrent edit returns a `STALE_DATA` error and prompts a reload.
- **Soft delete** — deleting sets `deleted_at` rather than removing the row, and is blocked unless the status is open or cancelled. Line items are not cascaded — they remain in the database for historical reference but are no longer reachable.
- **Notifications** — creating a work order, completing one, and moving one to waiting-parts each raise an in-app notification.

</details>

## Related guides

- [Equipment](/help/equipment)
- [Vendors](/help/vendors)
- [Inspections](/help/inspections)
