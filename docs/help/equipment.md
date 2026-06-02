---
description: Register and manage your fleet of trucks and trailers — specs, compliance dates, health, live tracking, and reusable equipment templates.
---

# Equipment

Track every unit in your fleet, from registration through compliance, maintenance, and decommissioning.

## Reading the dashboard

The Equipment list opens with four KPI tiles. The first three are clickable — selecting one filters the table to that status (click again to clear).

- **Available** — units ready to lease right now.
- **On Lease** — units currently out on an active lease.
- **In Maintenance** — units pulled out of service for work.
- **Total Fleet** — every unit in the fleet, with a **% utilization** figure (On Lease ÷ Total) underneath.

Below the tiles, each row shows the unit's **Unit #**, **Template**, **Year**, **Status**, **Yard**, **Mileage**, **Health** (a 0–100 score badge), and **Compliance** (shows **Expiring** when any expiry date is within 30 days or already past, otherwise **OK**).

## Adding a new unit

1. Click **+ New Unit** in the top right of the Equipment list.
2. Under **Unit Identity**, pick an **Equipment Template** (required), enter a unique **Unit Number** (required), and choose an **Ownership** type — Owned, Leased, or Brokered (required). VIN and Year are optional. Selecting a template pre-fills dimensions, ownership, tracking, and compliance-interval defaults, which you can override.
3. Under **Location & GPS**, optionally set a **Yard Location** and a **Tracking Provider**. Choosing **Samsara** reveals **GPS Device ID** and **Samsara Vehicle URL** fields.
4. Under **Physical Specifications**, fill in dimensions, axles, tire size, weight capacity, license plate/province, current mileage, and acquired date as needed.
5. Under **Compliance & Expiry Dates**, set **CVI Expiry**, **Registration Expiry**, **MVI Expiry**, **Insurance Expiry**, and their renewal intervals.
6. Add **Notes** or **Internal Notes** (internal notes are never shown to customers), then click **Register Unit**.

New units always start with a status of **Available**.

## Finding and filtering units

1. Type in the **Search unit #, VIN, plate…** box to match on unit number, VIN, or license plate.
2. Use the status dropdown (**All Statuses**) to narrow to one status, or click a KPI tile to do the same.
3. Use the **All Templates** dropdown to show only one equipment type.
4. Use the sort dropdown to order by **Unit # (A→Z)**, **Status**, **Template**, or **Newest first**. You can also click the **Unit #** or **Status** column headers to sort.

## Viewing a unit

1. Click a **Unit #** (or **View**) in the list to open the unit's detail page.
2. The hero strip shows **Health Score**, **Mileage**, **CVI Expiry** (with days remaining/overdue), and **VIN**. A pulsing **Live** badge and **Track in Samsara** button appear when the unit is linked to Samsara.
3. Move between tabs to drill in:

| Tab | What it shows |
|-----|---------------|
| **Overview** | Identity, specifications, live Samsara summary, and notes |
| **Payoff Analysis** | Investment-recovery projections from the linked fixed asset |
| **Compliance** | CVI, registration, MVI, and insurance expiry dates and status |
| **Lease History** | Every lease this unit has been on |
| **Damage Claims** | Damage claims filed against this unit |
| **Mileage Log** | Odometer readings over time (manual, GPS sync, lease, service) |
| **Status Log** | A full history of status changes, with reason and who made them |
| **Maintenance** | Work order history for this unit |
| **Inspections** | Pre-lease, post-lease, periodic, damage, and compliance inspections |
| **Documents** | Uploaded CVI, registration, insurance, and other PDFs |
| **Samsara Mapping** | Link/unlink the unit to a Samsara vehicle or trailer and see live telemetry |

## Editing a unit

1. From the unit detail page, click **Edit Unit** (or **Edit** from the list).
2. Update any field across **Unit Identity**, **Location & GPS**, **Physical Specifications**, **Compliance & Expiry**, and **Notes**.
3. Click **Save Changes**.

The **Equipment Template** can't be changed after a unit is created, and **status is not editable from this form** — see below for how statuses change.

## Changing a unit's status

Most status changes happen automatically: a unit moves to **On Lease** when a lease is activated and back to **Available** when the lease closes. Other transitions (for example into **Maintenance** or **Inactive**) follow a fixed set of allowed moves and are recorded in the **Status Log** tab with a reason and the user who made the change.

| Status | Meaning |
|--------|---------|
| **Available** | Ready to lease |
| **On Lease** | Currently out on an active lease (set automatically by leases) |
| **Reserved** | Held for an upcoming reservation |
| **Maintenance** | Out of service for work |
| **Inactive** | Temporarily out of the rentable pool |
| **Decommissioned** | Permanently retired — terminal, no further status changes |

## Retiring a unit

1. To permanently retire a unit, move it to **Maintenance** or **Inactive** first, then to **Decommissioned** (a terminal status).
2. To remove a unit from the active list entirely, open it and click **Delete Unit**, then confirm. This is a soft delete — the unit number and all of its history are kept for audit. A unit that is **On Lease** or **Reserved** can't be deleted; close the lease or cancel the reservation first.

## Equipment templates

Templates are reusable presets for an equipment type (for example "53ft Dry Van"). Every unit is created from a template, and the template supplies default dimensions, rental rates, and compliance intervals.

1. From the Equipment list, click **Templates** (top right), then **+ New Template**.
2. Under **Template Identity**, enter a unique **Template Name** and pick a **Category** (Chassis, Dry Van, Reefer, Container, Flatbed, Step Deck, Lowboy, Tanker, Dump, or Other). Brand/Make, Model, and Description are optional.
3. Under **Default Dimensions**, set length/width/height, axle count, weight capacity, and default ownership. Units inherit these but can override them.
4. Under **Default Rental Rates**, set currency, mileage unit, and daily/weekly/monthly/mileage rates. Set the mileage rate to **0** to disable mileage billing for the template.
5. Under **Compliance Renewal Intervals**, set CVI, MVI, registration, and insurance intervals in days.
6. Click **Create Template**.

On the Templates list, the **Units** column links to the units built from each template, and the **Status** badge shows **Active** or **Inactive**. Use **Edit** to change a template; **Delete** is disabled while any units still use it.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Status enum** — units use one of six statuses: `available`, `on_lease`, `reserved`, `maintenance`, `inactive`, `decommissioned`. New units are created as `available`.
- **Status state machine** — operator-initiated changes go through a dedicated endpoint (`update_status.php`) that enforces allowed transitions: available → reserved / maintenance / inactive; reserved → available; on_lease → available / maintenance; maintenance → available / inactive / decommissioned; inactive → available / decommissioned. `decommissioned` is terminal. `on_lease` is only ever set implicitly by activating a lease. Every change writes an `equipment_status_log` row and an `audit_log` entry.
- **Soft delete** — deleting sets `deleted_at` rather than removing the row; history is retained. Blocked when the unit is `on_lease` or `reserved`.
- **Templates relationship** — units join `equipment_templates` on `template_id`; a template can't be deleted while `unit_count > 0`. Template defaults are copied into the unit at creation and can be overridden per unit. The template can't be changed after creation.
- **Optimistic locking** — edit forms capture `updated_at` on load and send it back (D19); a concurrent edit returns a `STALE_DATA` error prompting a reload.
- **Health score** — a 0–100 value computed by a background job, colored in four bands (green ≥80, yellow/orange ≥20, red below).
- **Compliance flagging** — the list flags **Expiring** when any of CVI, registration, MVI, or insurance expiry is within 30 days or in the past.
- **Samsara linkage** — units can map to a Samsara vehicle or trailer; mapped units sync GPS (and, for vehicles, odometer/battery/power) on a 5-minute cron and store data in `samsara_*` columns. Soft-deleting a Samsara-linked trailer also removes it from Samsara.
- **Fixed-asset linkage** — the Payoff Analysis tab reads from a linked `acc_fixed_assets` record (matched on `equipment_unit_id`); set a fixed asset's Equipment Unit field to wire it up.

</details>

## Related guides

- [Leases](/help/leases)
- [Samsara Tracking](/help/tracking)
- [Maintenance](/help/maintenance)
- [Inspections](/help/inspections)
