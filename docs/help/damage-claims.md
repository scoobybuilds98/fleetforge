---
description: File and track damage claims against rental equipment — severity, repair costs, photos, and billing the customer.
---

# Damage Claims

Quick tutorials for filing and resolving equipment damage claims in FleetForge.

## Reading the dashboard

The Damage Claims list shows four KPI tiles at the top. Each tile is clickable and filters the table below:

- **Open Claims** — claims still in progress (*reported*, *assessed*, or *repair ordered*).
- **Awaiting Invoice** — claims in *invoiced* status.
- **Claims This Year** — every claim created in the current calendar year.
- **Avg Repair Cost** — average estimated repair cost across this year's claims; clicking drills into the *repair ordered* subset.

The table lists each claim with its **Claim #**, **Unit**, **Customer**, **Severity**, **Status**, **Est. Cost**, and **Reported** date. Click any row to open the claim.

---

## Filing a new damage claim

1. Click **+ New Claim** (top right of the Damage Claims page).

2. **Equipment Unit** *(required)* — select the unit that was damaged. Decommissioned and inactive units cannot have new claims; a unit that is on lease or in maintenance can.

3. **Customer** *(optional)* — pick an existing customer from the dropdown, **or** type a name in the "Or type customer name…" box. Setting one clears the other.

4. **Vendor Sent To** *(optional)* — the repair vendor the unit is going to, if known.

5. **Severity** *(required)* — choose **Minor**, **Moderate**, **Major**, or **Total Loss**.

6. **Damage Location** *(optional)* — e.g. "Front bumper, driver-side door".

7. **Linked Lease ID** *(optional)* — enter the lease ID if the damage happened during a lease.

8. **Description** *(required)* — describe the damage in detail (up to 2000 characters).

9. **Financial estimates** *(optional)* — fill in **Est. Repair Cost ($)**, **Customer Liable ($)**, and **Insurance Claim ($)** as known. None can be negative.

10. **Internal Notes** *(optional)* — staff-only notes.

11. Click **Create Claim**. The claim is assigned a number (format `DMG-YYYY-NNNNN`), opens in **Reported** status, and you are redirected to its detail page.

> **Tip:** You can pre-fill the unit, customer, or lease by reaching this form from an equipment, customer, or lease page.

---

## Filing a claim from an inspection

1. Open a completed or signed inspection (see the [Inspections guide](/help/inspections)).
2. Click **+ Create Damage Claim** in the inspection header.
3. This opens the **New Damage Claim** form. Fill in the **Severity** and **Description** and complete the form as above, then click **Create Claim**.

---

## Adding photos to a claim

1. Open the claim and find the **Photos** card.
2. Click **Add Photo**.
3. Choose a **Photo Type**: **Damage**, **Before Repair**, **After Repair**, or **Other**.
4. Add an optional **Caption**.
5. Choose a **File** — JPG, PNG, or HEIC, max 10 MB. A claim holds up to 10 photos.
6. Click **Upload**. The photo appears in the gallery. Click any thumbnail to view it full size, or **Delete** to remove it.

---

## Editing a claim

1. Open the claim and find the **Claim Details** card.
2. Click **Edit**.
3. Update any field — **Severity**, **Damage Location**, **Description**, **Customer**, the cost fields (**Est. Repair Cost**, **Actual Repair Cost**, **Liable Amount ($)**, **Insurance Claim**), **Notes**, **Resolution Notes**, or **Vendor Sent To**.
4. Click **Save Changes**.

> **Note:** If someone else edited the claim while you had it open, you'll be asked to reload before saving (the latest version wins).

---

## Moving a claim through its lifecycle

1. Open the claim and click **Change Status** in the header.
2. In the **Change Status** panel, pick a **New Status** from the dropdown — only valid next steps are shown.
3. Click **Apply**.

The allowed transitions are:

- **Reported** → **Assessed** or **Written Off**
- **Assessed** → **Repair Ordered** or **Written Off**
- **Repair Ordered** → **Invoiced**, **Resolved**, or **Written Off**
- **Invoiced** → **Resolved** or **Written Off**
- **Resolved** and **Written Off** are final — no further changes.

---

## Billing the customer for damage

To charge a customer for damage, link the recovery invoice and move the claim to **Invoiced**:

1. Create the invoice for the customer (see the [Invoices guide](/help/invoices)).
2. Open the claim, click **Edit**, and link the invoice via the **Invoice** field, then **Save Changes**.
3. Click **Change Status** and move the claim to **Invoiced**, then **Apply**.

When a claim becomes **Invoiced** with an invoice linked, FleetForge records the damage-recovery accounting entry automatically. When a claim is **Written Off**, the write-off entry is recorded instead.

---

## Deleting a claim

Only **Reported** and **Assessed** claims can be deleted. Once a claim reaches *repair ordered* or beyond, write it off via the status transition instead.

1. Open a claim in **Reported** or **Assessed** status.
2. Click **Delete** in the header.
3. Confirm in the modal. The claim is removed from lists (photos are kept for the audit trail).

---

## Severity reference

| Severity | Meaning |
| --- | --- |
| **Minor** | Cosmetic or light damage. |
| **Moderate** | Noticeable damage requiring repair. |
| **Major** | Significant damage, substantial repair. |
| **Total Loss** | Unit beyond economical repair. |

## Status reference

| Status | What it means |
| --- | --- |
| **Reported** | Claim filed; initial state for every new claim. |
| **Assessed** | Damage has been evaluated. |
| **Repair Ordered** | Repair work has been ordered or is underway. |
| **Invoiced** | Customer has been billed for the damage recovery. |
| **Resolved** | Claim closed and settled. |
| **Written Off** | Claim closed without recovery (absorbed as a loss). |

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Status lifecycle** — `reported` → (`assessed` | `written_off`); `assessed` → (`repair_ordered` | `written_off`); `repair_ordered` → (`invoiced` | `resolved` | `written_off`); `invoiced` → (`resolved` | `written_off`). `resolved` and `written_off` are terminal. The state machine is enforced server-side; an invalid jump returns `INVALID_TRANSITION`.
- **Severity** — one of `minor`, `moderate`, `major`, `total_loss`. Required on create and validated against this list on both create and edit.
- **Claim number** — gap-free `DMG-YYYY-NNNNN`, generated inside a transaction with a `FOR UPDATE` lock on the per-year settings counter. Prefix comes from Settings (default `DMG`).
- **Links** — a claim always references an `equipment_unit_id`. It optionally links to a customer (FK **or** a free-text `customer_name`, mutually exclusive), a lease, a vendor, a work order, an invoice, and an inspection. The detail page deep-links to the equipment, customer, lease, work order, invoice, and vendor records.
- **Billing the customer** — moving a claim to `invoiced` while `invoice_id` is set fires `AutoEntryBridge::onDamageRecoveryBilled`, recording the `damage_recovery` journal entry; moving to `written_off` fires `onDamageWrittenOff` (`damage_writeoff` entry). These run outside the status-update transaction, so an accounting hiccup logs an error rather than rolling back the operational status change. If `invoiced` is set with no linked invoice, the bridge call is skipped and logged.
- **Accounting subledger** — the damage-claims accounting report (spec §23.11) totals repair cost (actual preferred over estimated), recovery billed, recovery collected, and net P&L per claim, joined to its `damage_recovery` / `damage_repair` / `damage_writeoff` journal entries.
- **Optimistic locking** — edits and status changes require the row's current `updated_at`; a mismatch returns `STALE_DATA` and the UI prompts a reload.
- **Photos** — up to 10 per claim, max 10 MB each, JPEG/PNG/HEIC only. MIME type is detected server-side (the client-supplied type is ignored) and files are stored under `damage/{claim_id}/` with a safe generated name; the UI is served signed URLs, never raw storage paths.
- **Soft-delete** — deletion sets `deleted_at` and is allowed only for `reported`/`assessed` claims; later stages must be `written_off` instead. Photos are intentionally retained for the audit trail.
- **Audit + notifications** — create, update, status change, photo upload, and delete each write an `audit_log` row under the `maintenance` module, and create/update raise in-app notifications.

</details>

## Related guides

- [Inspections](/help/inspections)
- [Equipment](/help/equipment)
- [Leases](/help/leases)
- [Maintenance](/help/maintenance)
