---
description: Keep a directory of the repair shops, parts suppliers, and service providers your fleet relies on — contacts, rates, ratings, and a running total of what you've spent with each one.
---

# Vendors

Track the suppliers and service providers behind your maintenance work orders and bills.

## Reading the dashboard

The Vendors list opens with four KPI tiles:

- **Total Vendors** — every active vendor in the system. Click to clear the preferred filter on the table below.
- **Preferred Vendors** — vendors you've marked as preferred. Click to filter the table to preferred only (click again to clear).
- **Active Work Orders** — open, in-progress, and waiting-parts work orders assigned to a vendor. Click to jump to the Maintenance list filtered to open work orders.
- **Top Vendor by Spend** — the vendor with the highest **Total Spent**, with their name underneath. Click to open that vendor (or the Maintenance list if no spend is recorded yet).

Below the tiles, each row shows the **Name**, **Type** (a coloured badge), **Contact**, **Phone**, **Rating** (shown as stars), **Total Spent**, **Work Orders** (count of all work orders for that vendor), and **Preferred** (Yes or `—`).

Filter the table with the **All Types** dropdown (Maintenance, Repair, Parts, Inspection, Towing, Other) and the **All Vendors** / **Preferred Only** dropdown, or type into the **Search name or contact…** box. Click the **Name** or **Total Spent** column headers to sort, and use **Reset** to clear all filters.

## Adding a vendor

1. Click **+ New Vendor** in the top right of the Vendors list.
2. Under **Identity**, enter a **Vendor Name** (required) and pick a **Vendor Type** (required — Maintenance, Repair, Parts, Inspection, Towing, or Other).
3. Under **Contact**, optionally add a **Contact Name**, **Email**, and **Phone**.
4. Under **Location**, optionally add an **Address**, **City**, and **Province / State**.
5. Under **Rates & Rating**, optionally set an **Hourly Rate ($)**, a **Rating (1–5)**, and the **Currency** (CAD or USD).
6. Under **Specializations**, hold Ctrl/Cmd to select one or more skills (Brakes, Diesel Engine, Electrical, Exhaust, Hydraulics, HVAC, Suspension, Tires, Transmission, Welding).
7. Under **Settings & Notes**, tick **Mark as Preferred Vendor** if this is a go-to supplier, and add any **Notes**.
8. Click **Create Vendor**.

Vendor names must be unique. The **Currency** matters: QuickBooks locks a vendor's currency at creation, so set it correctly now — changing it later only takes effect if the vendor is re-created in QuickBooks.

## Viewing a vendor

Click any row to open the vendor's profile. The header shows the vendor name, a **Preferred** badge if applicable, and — when QuickBooks is connected — a sync-status badge. Four tiles summarise **Total Spent**, **Active Work Orders**, **Completed**, and **Total Work Orders**; each links to the Maintenance list filtered to this vendor. Below the tiles are stacked cards:

- **Vendor Details** — the full profile (name, type, contact, email, phone, address, hourly rate, currency, rating, specializations, notes, and who created it).
- **Work Orders** — every work order assigned to this vendor, with filters for **All Statuses**, **All Types**, and sort order. Use **+ New Work Order** to raise one pre-filled for this vendor, or **View** to open one.
- **Equipment Worked On** — the units this vendor has serviced, with a service count and last-service date per unit.
- **Active Lease Exposure** — any units this vendor has worked on that are currently on an active or pending lease, with the customer and lease shown.

## Editing a vendor

1. On the vendor's profile, click **Edit** in the page header.
2. Update any field — **Vendor Name**, **Vendor Type**, contact details, location, **Hourly Rate ($)**, **Rating (1–5)**, **Currency**, **Specializations**, **Mark as Preferred Vendor**, or **Notes**.
3. Click **Save Changes**.

**Total Spent** is not editable here — it's a running total maintained automatically (see below). If someone else saved changes to the same vendor while you had the form open, you'll be asked to reload before your edit is accepted.

## Deleting a vendor

1. On the vendor's profile, click **Delete** in the page header.
2. Confirm in the **Delete Vendor** dialog.

A vendor with active work orders (open, in progress, or waiting parts) cannot be deleted — complete or cancel those work orders first. Deleting is a soft delete: the vendor is hidden but its work order history is preserved.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Vendor type** — one of six values (`maintenance`, `repair`, `parts`, `inspection`, `towing`, `other`), required on create and validated server-side.
- **Total Spent rollup** — `total_spent` starts at `0.00` and is never edited by hand. It's incremented in the same transaction whenever a work order with this vendor is moved to **Completed** (by the work order's total cost), and whenever an accounts-payable bill for this vendor is approved (by the bill's total). Voiding an approved bill subtracts the amount back off (never below zero).
- **Bills / accounts payable** — an AP bill must reference a vendor (`acc_bills.vendor_id`); that link is how vendor spend flows in from the accounting side and how a bill can be tied to a specific work order.
- **QuickBooks sync** — creating or updating a vendor enqueues a best-effort sync job. It only runs when the QuickBooks connection is enabled and the vendor sync mode allows FF→QBO pushes; the mapping is tracked in `acc_qbo_vendor_map` and surfaced as the header badge (Synced / Not synced / Linked from QBO side / Excluded). The vendor name, contact, email, phone, and address are pushed; **vendor type** is not mapped. Currency is locked by QuickBooks at creation, so a later currency change only reaches QBO via a fresh re-create.
- **Soft delete** — deleting sets `deleted_at` rather than removing the row, so past work orders keep their vendor. A soft delete does **not** propagate to QuickBooks — the QBO vendor stays active and the mapping row is left intact.
- **Optimistic locking** — the edit form captures `updated_at` on load and sends it back; a concurrent edit is rejected with a stale-data error rather than silently overwriting.

</details>

## Related guides

- [Maintenance](/help/maintenance)
- [QuickBooks Sync](/help/quickbooks)
