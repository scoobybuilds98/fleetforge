---
description: Book trucks and trailers ahead of time, confirm pickups, mark units out of the yard, and track booking density across the fleet.
---

# Reservations

Hold equipment for a customer before pickup, then walk each booking from pending through to checked-out — all on one dispatch board.

## Reading the dashboard

When you open **Reservations** in the sidebar, four tiles sit across the top. Each one is also a quick filter — click it to narrow the board, click again to clear.

- **Total Active** — pending + confirmed reservations (everything not yet checked out). Click to clear all filters.
- **Pending** — bookings awaiting confirmation. Click to show only pending rows.
- **Confirmed** — bookings confirmed but still in the yard. Click to show only confirmed rows.
- **Today's Pickups** — reservations whose pickup date is today. Turns amber when there's at least one. Click to filter to today.

Below the tiles you'll find a **Status Breakdown** donut, a **Pickups — Past 7 Days** bar chart, and a **Fleet Booking Density — Next 4 Weeks** heat-map calendar (busier days show darker). These are read-only at-a-glance views.

The board itself is split into two tables:

- **Chassis In** — pending and confirmed reservations (units still in the yard).
- **Chassis Out** — completed reservations (units that have been marked out).

Use the **Table** / **Timeline** toggle above the tables to switch between the row view and a 14-day Gantt timeline of active reservations by unit.

## Creating a reservation

1. Click **+ New Reservation** (top right of the dashboard).
2. Choose an entry mode at the top:
   - **Existing Customer** — search and pick a customer; their company and contact auto-fill, and their leased units plus all **Available Units** load into the **Unit#** dropdown.
   - **Manual Entry** — type **Company Name**, **Contact Name**, and unit numbers as free text.
3. Set the **Status** dropdown to **Pending** or **Confirmed** (these are the only options at creation).
4. Pick a **Trailer Type** (optional), set the **Quantity**, and choose a **Pickup Date** (required — cannot be in the past). **Pickup Time** and **Pickup Yard** are optional.
5. Add units. In Existing Customer mode, pick from the **Unit#** dropdown; in Manual Entry, type a number and click **Add**. Each unit appears as a chip below — the **Quantity** auto-updates to match the number of units you add.
6. Open **Additional Fields** to set **Priority**, **Contact Phone**, **Contact Email**, **Purpose**, and **Internal Notes** (staff-only).
7. Click **Submit Reservation**.

> **Booking conflict?** If a unit you selected is already on another active reservation, you'll see a **Booking Conflict Detected** dialog. You can click **Cancel — Go Back**, or **Override & Double-Book** — the override is recorded in the reservation's internal notes. Units with an active **lease** on the same start date are a hard block and cannot be overridden.

## Confirming a reservation

1. Open the reservation, or find its row in **Chassis In**.
2. From the row, click **Confirm**; from the detail page, click **Confirm Reservation**.
3. Any system-linked units that are currently **Available** flip to **Reserved**.

## Marking a unit out (Chassis Out)

When the customer picks up the equipment:

1. Open a **Confirmed** reservation (or use its row).
2. Click **Chassis Out** in the row, or **Mark Out (Chassis Out)** on the detail page, and confirm.
3. The reservation moves to **Completed** and drops into the **Chassis Out** table, stamped with who marked it out and when.

## Linking a reservation to a lease

FleetForge does not auto-convert a reservation into a lease — the two stay as separate records with a soft link.

- When a reservation is **marked out**, an optional lease ID can be attached. Linked units then show their **Linked Lease** on the detail page, and the marked-out units move to **On Lease** status (otherwise they return to **Available**).
- On the reservation detail page, the **Units** table's **Linked Lease** column shows **Lease #…** for any unit tied to a lease — click through to open it in [Leases](/help/leases).
- Create or manage the lease itself from the [Leases](/help/leases) module.

## Editing a reservation

1. Open the reservation and click **Edit** (available while it's **Pending** or **Confirmed**).
2. Update fields inline, then **Save Changes** — or **Discard Changes** to back out.
3. If someone else changed the reservation while you were editing, you'll be asked to reload and try again.

## Cancelling, reversing, and deleting

- **Cancel** — from a Pending or Confirmed reservation, click **Cancel** / **Cancel Reservation**. A reason is **required**. Reserved units are released back to **Available**.
- **Reverse** — Managers can move a **Completed** reservation back to **Confirmed** using **Reverse** (row) or **Reverse Mark-Out** (detail page).
- **Delete** — only **Pending** or **Cancelled** reservations can be deleted. Confirmed or Completed reservations must be cancelled first.

## Finding and filtering

The toolbar above the tables lets you:

- Search by **contact or company** name.
- Filter by **pickup date**.
- Filter by priority: **All Priorities**, **Urgent**, **High**, **Medium**, **Low**.
- **Sort** by Pickup Date, Created, Company, Priority, or Status, and switch direction (**ASC** / **DESC**).
- Click **Clear** to reset every active filter.

Urgent and high-priority rows are highlighted with a colored left border so they stand out.

| Status | Meaning |
|--------|---------|
| **Pending** | Booked but not yet confirmed. Appears in Chassis In. |
| **Confirmed** | Confirmed and held; reserved units are marked Reserved. Appears in Chassis In. |
| **Completed** | Unit marked out of the yard (Chassis Out). |
| **Cancelled** | Booking called off with a reason; units released. Terminal — cannot move to another status. |

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Status lifecycle** — A strict state machine governs transitions: `pending → confirmed`, `pending → cancelled`, `confirmed → cancelled`, and `completed → confirmed` (reverse). **Cancelled** is terminal. Marking out is a separate `confirmed → completed` transition.
- **Unit holds** — Confirming flips system-linked units from `available` to `reserved`. Cancelling reverts `reserved → available`. Marking out sets units to `on_lease` when a lease is linked, otherwise `available`.
- **One active reservation per unit** — A system unit can be on only one pending/confirmed reservation at a time, regardless of date. Adding it to a second triggers the conflict/override flow; `decommissioned` and `inactive` units are blocked outright.
- **Lease relationship** — There is no convert action. Mark-out optionally writes a `lease_id` to each unit's `lease_id_linked`, a soft association surfaced as the **Linked Lease** column. Leases are created and managed in their own module.
- **Reverse is manager-gated** — Moving `completed → confirmed` requires the Manager or Super Admin role.
- **Optimistic locking** — Inline edits carry the record's last-updated timestamp; a concurrent change produces a stale-data prompt to reload.
- **Soft-delete** — Deleting sets `deleted_at` rather than removing the row; only `pending` or `cancelled` reservations are deletable, and any reserved units are released first.
- **Audit trail** — Create, status change, mark-out, and delete each write an audit-log entry shown in the reservation's **Activity Log**, and fire in-app notifications.

</details>

## Related guides

- [Leases](/help/leases)
- [Equipment](/help/equipment)
- [Customers](/help/customers)
