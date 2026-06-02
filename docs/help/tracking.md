---
description: Live GPS location, battery, and telemetry for every unit linked to Samsara.
---

# Fleet Tracking

Live Samsara telemetry for your tracked equipment — synced every 5 minutes.

The page header shows a **Linked** count and an **Unlinked** count, plus the subtitle **Live Samsara telemetry · synced every 5 minutes** and an **Updated** timestamp once data loads. It is organized into three tabs.

## The three tabs

- **Map View** — a Leaflet/OpenStreetMap map with a color-coded pin for every linked unit that has a GPS fix, alongside a searchable sidebar list.
- **List View** — a sortable table of every linked unit and its full telemetry. The tab shows the linked count badge.
- **Unlinked** — every unit that is *not* yet mapped to Samsara, with a button to link each one. The tab shows the unlinked count badge.

## Viewing the live map

- Each pin is color-coded by the unit's **status** (available, on lease, reserved, maintenance, inactive, decommissioned).
- The sidebar has a **Search units…** box and two chips — **Online** and **Offline** counts.
- Click a unit in the sidebar (or click its pin) to fly to it and open its popup. The popup shows unit number, type, customer, address, **Speed**, **Battery**, **Odometer**, and a **View Unit Profile →** link.
- If no units are linked yet, the sidebar reads **No linked units yet. Link units from their detail page.**

## Reading the telemetry (List View)

Each row in **Linked Units** shows:

- **Unit** — unit number (links to the unit profile) plus its template/type.
- **Status** — the equipment status badge.
- **Customer** — the customer on the unit's active lease, or `—`.
- **Battery** — battery percentage, colored red below 20% and amber below 50%. Trailers report no battery, so this is `—`.
- **Odometer** — distance in km.
- **Speed** — current speed in km/h.
- **Last Location** — the address Samsara reverse-geocoded.
- **Last Connected** — how long ago Samsara last heard from the device.
- **Last Synced** — how long ago FleetForge's cron last pulled this unit.

Use the **Search…** box to filter by unit number, type, location, or customer.

## Active Alerts

When there are issues, a collapsible **Active Alerts** strip appears above the tabs. It surfaces:

- **Battery critical** (10% or below) — critical.
- **Battery low** (25% or below) — warning.
- **Offline for X hrs** — critical at 24+ hours, warning at 8+ hours.
- **No GPS fix** — the unit has never returned coordinates.

Click any alert's unit number to open its profile. Press **Dismiss** to hide one alert, or **Dismiss all** to clear the strip — dismissed alerts stay hidden for 24 hours (per browser).

## Refreshing the data

- **Refresh** — re-pull the dashboard from FleetForge's cached data (does not call Samsara).
- **Auto-refresh** — when checked, the page re-pulls every 60 seconds. On by default.
- **Sync All Now** — force every linked unit to fetch fresh telemetry from Samsara right now, then refresh the dashboard. *(Requires edit permission.)*
- **Import from Samsara** — fetch all vehicles and trailers from Samsara and create any that are missing in FleetForge. Safe to re-run; already-linked units are skipped and new ones are created with status "available". *(Requires edit permission.)*

## Linking a unit to Samsara

Linking happens on the **unit's detail page**, not here. From the **Unlinked** tab:

1. Find the unit and click **Link to Samsara** in its row (this opens the unit's profile on its tracking tab).
2. In the **Link to Samsara** card, pick from the **Samsara Vehicle or Trailer** dropdown — each option is tagged **[Vehicle]** or **[Trailer]**, and already-linked trackables are unavailable.
3. Click **Link to Samsara**.

Once linked, the unit appears in **Map View** and **List View** and starts syncing on the next cron tick. See the [Equipment guide](/help/equipment) for the full unit profile, including **Sync Now** and **Unlink** on the tracking tab.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Read-only dashboard** — this page calls only `GET /api/v1/samsara/fleet`, which reads cached `samsara_*` columns from FleetForge's database. It never calls Samsara live, so it loads instantly.
- **5-minute sync cron** — `cron/samsara_sync.php` runs every 5 minutes (`*/5 * * * *`), pulls fresh stats per linked unit, and stamps `samsara_last_synced_at`. Vehicles hit Samsara's vehicle stats endpoint and trailers hit the trailer endpoint, dispatched by `samsara_entity_type`.
- **Linkage** — a unit is "linked" when its `samsara_vehicle_id` column is set (done by `api/v1/samsara/link.php`). That ID is opaque and works for both vehicles and trailers.
- **Online vs offline** — "online" means the unit has a GPS fix *and* its `samsara_last_connected_at` is within the last 8 hours. Everything else counts as offline.
- **Alert thresholds** — battery critical ≤10%, battery low ≤25%, offline ≥24h (critical) / ≥8h (warning), and no-GPS when latitude is null. These are evaluated in `fleet.php`; alert dismissals live in browser `localStorage` with a 24-hour TTL.
- **Breadcrumb trail** — the cron appends a row to `samsara_location_history` only when the lat/lng actually changed (rounded to 7 decimals), so parked units don't bloat the trail. The dashboard map shows current positions only, not the trail.
- **Trailers** — report no battery, power, or odometer-OBD fields, so those columns show `—` and battery/connectivity alerts simply skip them.
- **Notifications** — the cron also pushes grouped in-app alerts (e.g. "3 units offline") with a 6-hour per-unit dedup window, separate from this page's on-screen strip.

</details>

## Related guides

- [Equipment](/help/equipment)
- [Leases](/help/leases)
