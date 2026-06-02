---
description: Manage the physical lots and depots where your equipment is stored and picked up.
---

# Yards

Manage the physical lots and depots where your equipment is stored.

## Reading the page

The Yards page shows three KPI tiles at the top. Each one is clickable and filters the table below:

- **Total Yards** — every yard configured in the system (shows all).
- **Active** — yards available for reservations.
- **Inactive** — deactivated yards, hidden from dropdowns.

Below the tiles, the **All Yards** card lists every yard. Use the **Active Only** / **All Yards** toggle to switch between just the active yards and the full list. Each row shows **Name**, **Location** (city, state, with the street address underneath), **Capacity**, **Phone**, and **Status**.

---

## Adding a yard

1. Click **+ New Yard** (top right of the Yards page).
2. In the **New Yard** dialog, enter the **Yard Name** — this is required and must be unique.
3. Optionally fill in **Address**, **City**, **Province / State**, **Postal Code**, **Capacity (units)**, **Phone**, and **Notes** (for access instructions, hours, or contacts).
4. Click **Create Yard**.

New yards are active right away and appear in reservation dropdowns.

---

## Editing a yard

1. Find the yard in the table and click **Edit**.
2. The **Edit Yard** dialog opens pre-filled with the current details. Change any field.
3. Use the **Yard is Active** checkbox to switch the yard on or off. Inactive yards are hidden from reservation dropdowns, but historical data is preserved.
4. Click **Save Changes**.

---

## Deactivating and reactivating a yard

1. To deactivate an active yard, click **Deactivate** on its row and confirm. The yard is hidden from reservation dropdowns.
2. To bring a yard back, switch to **All Yards**, find the inactive yard, and click **Activate**.

A yard **cannot be deactivated while it has upcoming reservations** (pickup date today or later, still pending or confirmed). Cancel or move those reservations first, then try again.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Permissions** — viewing the page requires the *reservations* `view` permission. Creating, editing, deactivating, and reactivating are limited to the **super_admin** and **manager** roles; other roles see a read-only list with no action buttons.
- **No hard delete (soft-delete by flag)** — the `yards` table has no `deleted_at` column. Deactivation just sets `is_active = 0`. The record and its history always remain.
- **How equipment links to a yard** — an equipment unit stores its location in `equipment_units.yard_location`, which holds the yard's **name** as text (not a numeric link). Renaming a yard does not retroactively update units already stamped with the old name.
- **How reservations link to a yard** — a reservation stores the pickup yard in `reservations.yard_location`, also by **name**. The deactivation guard counts upcoming pending/confirmed reservations whose `yard_location` matches the yard's name.
- **Unique name + slug** — yard **Yard Name** must be unique. A URL-friendly `slug` is auto-generated from the name (lowercase, hyphenated); slug collisions get a numeric suffix appended automatically.
- **Optimistic locking on edit** — saving an edit sends the row's `updated_at` token. If someone else changed the yard in the meantime, you get *"Yard was modified by another user. Refresh and try again."*
- **Capacity** — an optional whole number (non-negative). It is informational only — there is no enforced cap on how many units or reservations a yard can hold.
- **Audit trail** — every create, update, and deactivate writes an entry to the audit log under the *settings* module.

</details>

## Related guides

- [Equipment](/help/equipment)
- [Reservations](/help/reservations)
