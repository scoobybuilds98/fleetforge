---
description: Conduct unit inspections — pre-lease, post-lease, periodic, damage, and compliance — with a 9-section checklist, photos, sign-off, and one-click damage claims.
---

# Inspections

Record the condition of a unit at any point in its life, then complete and sign off the report or turn it into a damage claim.

## Reading the dashboard

The Inspections list opens with four KPI tiles. Each tile is clickable and filters the table below (click again to clear).

- **Total Inspections** — every inspection on record, all time.
- **Draft** — inspections still in progress.
- **Complete** — inspections finished and awaiting signature.
- **Signed This Month** — inspections signed off since the first of the month. Clicking this tile filters the table to **Complete** inspections.

Below the tiles, each row shows the **Inspection #**, **Type**, **Unit**, **Date**, **Inspector**, **Lease** (contract number, if linked), **Condition** (the overall condition once completed), and **Status**.

## Finding and filtering inspections

1. Type in the **Search inspection #, unit, inspector...** box to match on those fields.
2. Use the status dropdown (**All Statuses**) to narrow to **Draft**, **Complete**, or **Signed**.
3. Use the type dropdown (**All Types**) to narrow to **Pre-Lease**, **Post-Lease**, **Periodic**, **Damage**, or **Compliance**.
4. Click **Clear** to reset all filters. The table is sorted newest-first by inspection date.
5. Click **View** on any row to open that inspection.

## Starting a new inspection

1. Click **+ New Inspection** in the top right of the Inspections list.
2. Select the **Equipment Unit** (required). Decommissioned and inactive units can't be inspected and are greyed out; a unit that is leased or in maintenance can still be inspected.
3. Choose the **Inspection Type** (required) — Pre-Lease, Post-Lease, Periodic, Damage, or Compliance.
4. Optionally pick a **Linked Lease**. (For pre-lease and post-lease inspections you'll normally want one.)
5. Set the **Inspection Date** (required) — it can't be in the future.
6. Record the inspector: type a name in **Inspected By (Name)**, and/or pick a system user in **Inspector (User)**.
7. Fill in any trailer readings as needed — **Odometer / Mileage at Inspection**, **Reefer Hours**, **Fuel Level**, **CVI Expiry Date**, and **Unit Cleanliness** (Clean / Dirty / Not recorded).
8. Add general **Notes** if you like, then click **Create Inspection**.

The inspection is created in **Draft** status with its full 9-section checklist ready to fill in, and you're taken straight to its detail page.

> **Tip:** You can also start an inspection pre-filled from elsewhere — the **Inspections** tab on an equipment unit, or the pre/post-lease buttons on a lease, open this form with the unit, lease, and type already selected.

## Filling in the checklist

Open a draft inspection to see its nine sections. The header tiles summarize **Overall Condition**, **Mileage at Inspection**, **Reefer Hours**, **Fuel Level**, **CVI Expiry**, and **Cleanliness**.

Each section carries a condition badge and saves on its own as you go:

- **Sections 1–7 (Exterior – Left Side, Right Side, Front Wall, Rear Door, Roof, Floor, and Interior)** — pick a **Condition** (OK, Fair, Damaged, Missing, or N/A) and add **Notes**. Both save automatically when you change the dropdown or click out of the notes box.
- **Section 8 — Tires** — a per-position table (Left/Right, Outer/Inner, axles 1–5) with **Brakes**, **Tread**, **Brand**, **OEM/ORG**, and **Wheels** (AL or STL). The legend at the top defines the damage codes. Click **Save Tire Data** to record the table.
- **Section 9 — Trailer Condition** — a 7-item checklist (Mud Flaps, Lights, Canlocks, Landing Gear, Inflation, Tray / Skirts, Rub Rail), each with a **Condition Code** and **Notes**. Click **Save Trailer Condition** to record it.

To attach a photo to any section, click **+ Add Photo** in that section's **Photos** strip and choose a JPEG, PNG, or HEIC file (10 MB max). Click the **x** on a thumbnail to remove it.

## Completing and signing off

A draft must be marked complete before it can be signed. Both actions live in the **Status transitions** bar near the top of the inspection.

1. With the inspection in **Draft**, click **Mark Complete** and confirm. The report's **Overall Condition** is calculated from the worst section condition, and all sections become read-only.
2. With the inspection in **Complete**, click **Sign Off** and confirm. The inspection moves to **Signed**, stamped with the sign-off time, and is now a permanent, locked record.
3. If you need to correct a completed (but not yet signed) inspection, a manager can click **Re-open (Draft)** to send it back to **Draft** for editing.

> **Note:** A **Signed** inspection is final — it can't be edited, re-opened, or deleted.

## Creating a damage claim from an inspection

Once an inspection is **Complete** or **Signed**, you can spin a damage claim out of it directly.

1. Open the inspection and click **+ Create Damage Claim** in the page header.
2. The damage-claim form opens pre-filled with this inspection, its unit, and its lease (if one is linked).
3. Complete and save the claim.

→ See the [Damage Claims guide](/help/damage-claims) for the full walkthrough.

## Deleting an inspection

Only **Draft** inspections can be deleted.

1. Open the draft inspection.
2. Click **Delete** in the page header and confirm.

Completed and signed inspections can't be deleted — they're permanent records.

---

## Inspection types

| Type | When you'd use it |
|------|-------------------|
| **Pre-Lease** | Condition check before a unit goes out on a lease |
| **Post-Lease** | Condition check when a unit comes back |
| **Periodic** | Routine scheduled inspection |
| **Damage** | Documenting damage on a unit |
| **Compliance** | Regulatory / CVI compliance check |

## Statuses

| Status | Meaning |
|--------|---------|
| **Draft** | In progress — fully editable, and the only status that can be deleted |
| **Complete** | Finished and locked for editing, awaiting signature; can be re-opened to Draft by a manager |
| **Signed** | Signed off and permanent — terminal, no further changes |

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Status lifecycle** — statuses are `draft`, `complete`, `signed`. The state machine allows `draft` → `complete`, `complete` → `signed`, and `complete` → `draft` (re-open). `signed` is terminal. Re-opening (`complete` → `draft`) requires manager access (`inspections.settings`). Every transition writes an `audit_log` entry.
- **Inspection types** — the type enum is `pre_lease`, `post_lease`, `periodic`, `damage`, `compliance`. New inspections always start as `draft`.
- **Nine auto-created sections** — on create, the inspection seeds nine `inspection_sections` rows: seven standard exterior/interior sections (condition + notes), a **Tires** section holding a 20-position JSON skeleton (L/R × outer/inner × 5 axles, each with brakes/tread/brand/org/wheels), and a **Trailer Condition** section holding a 7-item checklist JSON skeleton. Sections save individually via the section update endpoint and are blocked once the inspection is `signed`.
- **Overall condition** — set when you click **Mark Complete**, derived from the worst section condition: `ok` → excellent, `fair` → fair, `damaged` → damaged, `missing` → poor.
- **Sign-off** — moving to `signed` stamps `signed_at`. The "Signed This Month" KPI counts `signed` rows whose `signed_at` is on or after the first of the current month.
- **Photos** — uploaded per section to `inspection_photos` (JPEG/PNG/HEIC, 10 MB max), and removed when the parent inspection is deleted.
- **Damage-claim link** — the **+ Create Damage Claim** button (shown on `complete` and `signed` inspections) deep-links to the damage-claim create form with `inspection_id`, `unit_id`, and, when present, `lease_id` pre-filled.
- **Lease & equipment linkage** — an inspection optionally references a `lease_id` and always references an `equipment_unit_id`; the detail page links back to both. The same records surface under the **Inspections** tab on the equipment unit and the lease.
- **Delete is a hard delete** — inspections have no soft-delete column. Deleting a draft removes the row and cascades to its sections and photos; any damage claim that referenced it survives with its `inspection_id` set to null. `complete` and `signed` inspections cannot be deleted.

</details>

## Related guides

- [Leases](/help/leases)
- [Equipment](/help/equipment)
- [Damage Claims](/help/damage-claims)
