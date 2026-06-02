---
description: Track regulatory document expiries across your fleet — CVI, registration, and insurance — with color-coded status, renewal windows, and one-click date editing.
---

# Compliance

See at a glance which units have valid, expiring, or expired documents, and update the dates without leaving the page.

## Reading the dashboard

The Compliance page opens with three KPI tiles. Each one is clickable and filters the grid below (click the same tile again to clear).

- **Units Tracked** — every active unit in the fleet, excluding inactive and decommissioned units. Clicking this tile resets all filters.
- **Expired Documents** — units with at least one document already past its expiry date. Clicking filters the grid to just those units.
- **Expiring Within 30 Days** — units needing renewal soon. Clicking applies the 30-day window filter.

Below the tiles, a color legend explains the status edge on every date cell:

- **Expired** — red.
- **Expiring within 30 days** — amber.
- **Valid (>30 days)** — green.
- **Not set** — gray (no date on file).

Each row in the grid shows the unit's **Unit** number (a link to the unit's detail page), **Type**, **Yard**, **Status**, and three document columns: **CVI**, **Registration**, and **Insurance**. Within a document cell you'll see a **From:** date (when the document took effect) above a **To:** date (when it expires), plus a colored **PDF** badge linking to the document when one is on file.

## Finding and filtering units

1. Type in the **Search unit…** box to match on unit number or equipment type.
2. Use the **All Yards** dropdown to limit to one yard.
3. Use the **All Statuses** dropdown to narrow to **Available**, **Reserved**, **On Lease**, or **Maintenance**.
4. Use the window dropdown (**All units**) to show only units with a document expiring or already expired within **7**, **14**, **30**, **60**, or **90** days.
5. Click **Reset** to clear every filter.
6. Click any column header (**Unit**, **Type**, **Yard**, **Status**, **CVI**, **Registration**, **Insurance**) to sort; click again to flip the direction.

## Updating a document's dates

1. Click any **CVI**, **Registration**, or **Insurance** cell (look for the small **Edit** hint inside the cell).
2. In the **Update … Dates** dialog, set the **Valid From** date (when the document became effective).
3. Set the **Expiry (To)** date. Leave it empty to clear it — the cell then shows gray ("Not Set").
4. Click **Save**. The cell, the color status, and the KPI tiles update immediately.

> Editing dates requires the compliance edit permission. Without it, the cells are read-only and the dialog does not appear.

## Viewing a document

Click the colored **PDF** badge in any document cell to open the stored file in a new tab. The badge only appears when a document has been uploaded for that unit and type. Documents are uploaded against the equipment unit itself — see the [Equipment](/help/equipment) and [Documents](/help/documents) guides.

## Exporting compliance data

1. Apply any filters you want reflected in the export (search, yard, status, window).
2. Click **Export CSV** in the top right.
3. The download includes each unit's from date, expiry date, and a computed status (**Valid**, **Expiring Soon**, **Expired**, or **Not Set**) for CVI, Registration, and Insurance.

## Document types tracked

| Document | Column | What it covers |
|----------|--------|----------------|
| **CVI** | CVI | Commercial Vehicle Inspection certificate |
| **Registration** | Registration | Vehicle/trailer registration |
| **Insurance** | Insurance | Insurance coverage |

> MVI (Motor Vehicle Inspection) expiry is also stored on each unit and counts toward the sidebar **Compliance** alert badge and the nightly alert emails, but it is not shown or editable on this dashboard. Edit MVI on the equipment unit instead.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Data source** — every cell reads from the `equipment_units` table: `cvi_expiry` / `cvi_from_date`, `registration_expiry` / `registration_from_date`, and `insurance_expiry` / `insurance_from_date`. Inactive and decommissioned units, and soft-deleted rows (`deleted_at`), are always excluded.
- **30-day window** — a date cell is amber when its expiry is between today and today + 30 days, red when on or before today, green when more than 30 days out, gray when null. The **Expiring Within 30 Days** tile counts units with any of the three expiries in that window; the **Expired Documents** tile counts units with any expiry before today (`api/v1/compliance/kpis.php`).
- **Where dates are edited** — the **Valid From** + **Expiry (To)** pair is saved here via `api/v1/compliance/update.php`, which writes both columns in one transaction and logs to `audit_log`. The equipment unit's own **Edit** form (under **Compliance & Expiry**) also edits the expiry dates — plus MVI — but not the "Valid From" dates. An optimistic lock (`updated_at`, D19) blocks saving over a concurrent edit with a "modified by another user" error.
- **Sidebar badge** — the **Compliance** nav badge (`compliance_alerts`) counts distinct active units where CVI, Registration, **MVI**, or Insurance expiry is within 30 days or past (`includes/sidebar.php`). It includes MVI, so its number can exceed what this dashboard's tiles show.
- **Nightly alerts** — `cron/compliance_alerts.php` runs at 6:00 AM, creating in-app notifications and one digest email per affected customer. It checks all four documents (CVI, Registration, MVI, Insurance) and grades each as expired, critical (within `alerts.compliance_critical_days`, default 7), or warning (within `alerts.compliance_warning_days`, default 30); those windows are tunable in settings.
- **PDF links** — document files are stored as private storage keys (`cvi_document`, etc.) and never returned raw; the API converts them to short-lived signed URLs (1 hour) before the **PDF** badge renders.

</details>

## Related guides

- [Equipment](/help/equipment)
- [Documents](/help/documents)
