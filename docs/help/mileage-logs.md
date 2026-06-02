---
description: Track odometer readings across every equipment unit — manual entries, automatic daily GPS syncs, and lease-tied readings used for mileage billing.
---

# Mileage Logs

Every odometer reading for your fleet, in one place — recorded by hand, pulled automatically from Samsara, or captured against a lease.

## Reading the dashboard

The Mileage Logs list opens with four KPI tiles. The first three are clickable — selecting one filters the table to that entry type (click the same tile again to clear).

- **Total Entries** — every mileage log across the whole fleet.
- **Manual Entries** — readings someone entered by hand.
- **GPS Sync Entries** — readings pulled automatically from Samsara.
- **Last GPS Sync** — the date of the most recent GPS-sourced reading (display only, not clickable).

Below the tiles, each row shows the reading's **Date**, **Unit**, **Equipment** (brand and model), **Odometer** (the reading with its `km`/`mi` unit), **Type** (a colour-coded badge), **Lease** (a link to the linked lease, or **—** if none), and **Recorded By** (the person who entered it, or **—** for system entries).

## Recording a reading

1. Click **+ Record Mileage** in the top right of the Mileage Logs list.
2. Pick the **Equipment Unit** (required). Decommissioned and inactive units can't have new readings, so they appear greyed out.
3. Optionally choose a **Linked Lease**. Only active and pending leases appear; linking ties the reading to that lease's mileage history.
4. Choose the **Entry Type** (required) — **Manual — admin entry** or **Service — recorded at maintenance**. (GPS Sync and Lease Start/End are recorded automatically and aren't offered here.)
5. Enter the **Odometer Reading** (required, whole number) and pick the **Unit** — **km (kilometres)** or **mi (miles)**. The reading can't be lower than the most recent prior reading on that unit.
6. Set the **Date** (required) — it defaults to today and can't be in the future. Use the calendar button to pick a date.
7. Add optional **Notes** (up to 1,000 characters), then click **Save Entry**.

On success you're taken to the new entry's detail page.

## Finding and filtering entries

1. Use the **All Units** dropdown to narrow to a single unit (every unit is selectable here, including decommissioned ones, since this is a read-only report).
2. Use the **All Types** dropdown to filter by **Manual**, **GPS Sync**, **Lease Start**, **Lease End**, or **Service** — or click a KPI tile to do the same.
3. Set the **From date** and **To date** fields to limit the entries to a date range.
4. Click **Clear** to reset every filter at once.

## Viewing and editing an entry

1. Click any row (or **View**) to open the entry's detail page.
2. The summary tiles show **Odometer Reading**, **Log Date**, **Entry Type**, and **Equipment Unit**. **Entry Details** below lists the linked unit, linked lease, recorded-by name, notes, and created date.
3. For **Manual** and **Service** entries, click **Edit** to change the odometer reading, unit, date, or notes, then **Save Changes**. Click **Delete** to remove the entry.
4. **GPS Sync**, **Lease Start**, and **Lease End** entries are system-generated and show a notice that they can't be edited; **Lease Start** and **Lease End** also can't be deleted because they're tied to invoicing.

→ See the [Samsara Tracking guide](/help/tracking) for how GPS readings flow in automatically.

## Entry types

| Type | Badge | How it's created | Editable? | Deletable? |
|------|-------|------------------|-----------|------------|
| **Manual** | blue | Recorded by hand on the **Record Mileage** form | Yes | Yes |
| **Service** | orange | Recorded by hand, typically at maintenance | Yes | Yes |
| **GPS Sync** | green | Created automatically by the daily Samsara sync | No | Yes |
| **Lease Start** | grey | System-reserved for the reading captured at lease activation | No | No |
| **Lease End** | grey | System-reserved for the reading captured at lease close | No | No |

---

<details>
<summary>Under the hood — how it works technically</summary>

- **The five log types** — `mileage_logs.log_type` is one of `manual`, `gps_sync`, `lease_start`, `lease_end`, `service`. The Record Mileage form and its API only accept `manual` or `service`; any other value submitted is silently coerced to `manual`, so operators can never create a system type by hand.
- **Auto-created GPS syncs** — a daily cron (`cron/gps_mileage_sync.php`) reads each Samsara-linked unit's odometer, inserts a `gps_sync` row with `recorded_by = null` and the note "Automatic daily GPS sync", and skips any unit already synced that day. It also updates `equipment_units.mileage`.
- **Lease Start / Lease End** — these types are billing-critical and the system reserves them for lease activation and close. The detail page treats them as immutable (`update.php` returns `IMMUTABLE_RECORD`) and `delete.php` blocks deletion entirely because they're tied to invoicing. Lease activation/close also store odometer figures directly on the lease (`odometer_start_km`) and on the final invoice (`odometer_at_period_end_km`) for mileage reconciliation.
- **km vs miles** — each reading carries its own `mileage_unit` (`km` default, per D34). The list and detail pages render `km`/`mi` next to the number. Samsara always returns kilometres; the GPS-sync cron and the close-lease estimate convert to miles (× 0.621371) when the lease's preferred unit is miles.
- **Odometer can't go backwards** — on create, the API rejects a reading lower than the most recent prior reading on that same unit. Saving a reading also bumps `equipment_units.mileage` to the latest value.
- **Lease linkage** — an entry can link to a lease via `lease_id` (only active/pending leases are offered, and the API verifies the lease belongs to the chosen unit). This is how readings feed a lease's mileage history; the list can also be filtered by lease or by customer (via that customer's leases).
- **No soft delete, optimistic lock via created_at** — `mileage_logs` has no `deleted_at` (deletes are permanent) and no `updated_at`; edits use the immutable `created_at` as the optimistic-lock token, returning `STALE_DATA` if the row changed since load.

</details>

## Related guides

- [Equipment](/help/equipment)
- [Leases](/help/leases)
- [Samsara Tracking](/help/tracking)
