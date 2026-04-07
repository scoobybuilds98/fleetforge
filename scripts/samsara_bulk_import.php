<?php
declare(strict_types=1);

/**
 * scripts/samsara_bulk_import.php
 *
 * SAMSARA-2: One-shot (and re-runnable) bulk importer.
 *
 * Walks every Samsara trackable (vehicles + trailers via the merged
 * /fleet/vehicles + /fleet/trailers endpoints) and, for each one
 * that is NOT already linked to a FleetForge equipment_unit, creates
 * a brand-new equipment_unit row pre-linked to that Samsara entity
 * with the static identifier snapshot AND first live GPS fix in one
 * shot. After this script runs the Fleet Tracking dashboard will
 * show every asset in your Samsara org, no manual click-through.
 *
 * Why this exists:
 *   The standard SAMSARA-2 link flow assumes the FleetForge unit
 *   already exists and a human picks the trackable from a dropdown.
 *   That's fine for 5 units, miserable for 162. Customers whose
 *   Samsara org is the source of truth for their asset list need a
 *   way to backfill.
 *
 * Idempotency:
 *   • Already-linked Samsara IDs are skipped (no duplicate inserts)
 *   • A Samsara name that collides with an existing unit_number is
 *     skipped with a warning — the human can rename one side then
 *     re-run. The default ALL Samsara names are unique vs existing
 *     FleetForge unit_numbers in this dataset.
 *   • A VIN that already exists in equipment_units is set to NULL
 *     on the new row and logged. The Samsara id is the canonical
 *     identifier so the row still works as a tracking target.
 *   • A VIN that appears more than once WITHIN the Samsara fetch
 *     itself only goes onto the first row that claims it; later
 *     duplicates get NULL.
 *
 * Field mapping (Samsara → equipment_units):
 *   name              → unit_number  (and samsara_vehicle_name)
 *   id                → samsara_vehicle_id
 *   entity_type       → samsara_entity_type
 *   vin               → vin (also samsara_vin)
 *   serial_number     → samsara_serial_number
 *   gateway_id        → samsara_gateway_id
 *   year              → year
 *   license_plate     → license_plate
 *   live stats GPS    → samsara_last_location_*
 *   live stats odo    → samsara_odometer_km
 *
 * Defaults applied to satisfy NOT NULL constraints:
 *   template_id       → first matching template by category guess,
 *                       falling back to id=1 (53ft Dry Van)
 *   tracking_provider → 'samsara'
 *   ownership_type    → 'owned'      (best guess; human can edit later)
 *   status            → 'available'  (matches manual create.php flow)
 *   mileage           → 0            (column is NOT NULL UNSIGNED)
 *
 * Side effects per imported trailer:
 *   • equipment_units row inserted
 *   • equipment_status_log row inserted ("Imported from Samsara")
 *   • samsara_location_history seed row inserted (if GPS available)
 *   • audit_log row inserted (action=create + module=equipment)
 *
 * Cleanup:
 *   The 5 SAMSARA-1 fake TEST-VEH-* seed rows (which can never
 *   actually sync — Samsara HTTP 400s their IDs) are detached from
 *   Samsara at the start of the run, freeing the unit_numbers and
 *   removing the noise from the dashboard. The unit rows themselves
 *   are kept (they have lease history etc) but their samsara_*
 *   columns are cleared. The user can re-link them via the normal UI.
 *
 * Usage:
 *   php scripts/samsara_bulk_import.php             # do it
 *   php scripts/samsara_bulk_import.php --dry-run   # preview only
 *
 * @depends  config/app.php, lib/GPS/SamsaraClient.php
 * @session  SAMSARA-2
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\GPS\SamsaraClient;

$dryRun = in_array('--dry-run', $argv, true);

echo "═══════════════════════════════════════════════════\n";
echo " FleetForge — Samsara Bulk Import\n";
echo "═══════════════════════════════════════════════════\n";
echo $dryRun ? " MODE: DRY RUN (no DB writes)\n" : " MODE: LIVE WRITE\n";
echo "\n";

// ── Step 1: Pull every Samsara trackable in one go ─────────
// getAllTrackables() merges /fleet/vehicles and /fleet/trailers
// and tags each entry with entity_type. This is one combined
// HTTP round trip per page-of-100, so even 1000+ assets is fast.
$client = new SamsaraClient();
$trackables = $client->getAllTrackables();
echo "[1/5] Fetched " . count($trackables) . " trackables from Samsara\n";

if (empty($trackables)) {
    echo "Nothing to import. Verify the API key in Settings → Integrations.\n";
    exit(0);
}

// ── Step 2: Bulk fetch trailer stats in ONE call ───────────
// /fleet/trailers/stats returns every trailer's GPS + odometer
// in a single response, so we don't pay 162 round trips. The
// helper returns a [trailerId => normalized stats] map keyed
// by Samsara ID. Vehicles still get pinged per-unit because
// /fleet/vehicles/stats doesn't have a comparably efficient
// "all" endpoint for unrestricted token scopes.
$trailerStats = $client->getAllTrailerStats();
echo "[2/5] Bulk-fetched stats for " . count($trailerStats) . " trailers\n";

// ── Step 3: Build skip-set from already-linked Samsara IDs ─
// Re-run safety. If the script is invoked twice we MUST not
// double-insert rows for the same Samsara id. The unique index
// on equipment_units.unit_number would catch the unit_number
// collision but a per-entry check is friendlier and gives a
// clean "skipped, already linked" log line.
$alreadyLinked = db_select(
    "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type
       FROM equipment_units
      WHERE samsara_vehicle_id IS NOT NULL
        AND samsara_vehicle_id <> ''
        AND deleted_at IS NULL"
);
$linkedSet = [];   // samsara_id => unit_number
$staleTestRows = []; // unit ids whose Samsara id starts with TEST-
foreach ($alreadyLinked as $u) {
    $sid = (string) $u['samsara_vehicle_id'];
    if (str_starts_with($sid, 'TEST-')) {
        // SAMSARA-1 seed-script garbage that the cron skips on every
        // tick. Detach so we free the slot and stop polluting the
        // dashboard. The unit row itself is preserved.
        $staleTestRows[] = (int) $u['id'];
        continue;
    }
    $linkedSet[$sid] = (string) $u['unit_number'];
}
echo "[3/5] Already-linked: " . count($linkedSet)
    . ", stale TEST-* rows to detach: " . count($staleTestRows) . "\n";

// ── Step 4: Build a unit_number + VIN skip-set so we don't ─
// trip the unique indexes. The Samsara name becomes the new
// unit_number; if a row already exists with that name, we skip
// the import (the operator should pick a side and re-run).
$existingUnitNumbers = array_flip(array_column(
    db_select("SELECT unit_number FROM equipment_units WHERE deleted_at IS NULL"),
    'unit_number'
));
$existingVins = array_flip(array_column(
    db_select("SELECT vin FROM equipment_units WHERE vin IS NOT NULL AND vin <> '' AND deleted_at IS NULL"),
    'vin'
));

// ── Pick a sensible template_id default ─────────────────────
// Prefer "53ft Dry Van" (id=1 from the canonical seed) since
// most fleet trailers are 53ft dry vans. If that template isn't
// present we just take the first non-soft-deleted template.
$defaultTemplate = db_row(
    "SELECT id FROM equipment_templates
      WHERE name = '53ft Dry Van' AND deleted_at IS NULL
      LIMIT 1"
);
if (!$defaultTemplate) {
    $defaultTemplate = db_row(
        "SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1"
    );
}
if (!$defaultTemplate) {
    fwrite(STDERR, "FATAL: no equipment_templates rows exist; create at least one before importing.\n");
    exit(1);
}
$defaultTemplateId = (int) $defaultTemplate['id'];

// ── Step 5: Walk every trackable and import / skip / link ──
$created      = 0;
$skippedLinked = 0;
$skippedName  = 0;
$failed       = 0;
$nullVin      = 0;
$now          = date('Y-m-d H:i:s');

// Track VINs we've used in this run so an in-Samsara duplicate
// only consumes the first occurrence.
$usedVinsThisRun = [];

echo "[4/5] Importing…\n";

foreach ($trackables as $t) {
    $samsaraId  = (string) $t['id'];
    $name       = (string) $t['name'];
    $entityType = (string) ($t['entity_type'] ?? 'vehicle');

    // Skip already-linked
    if (isset($linkedSet[$samsaraId])) {
        $skippedLinked++;
        continue;
    }

    // Skip name collisions — don't auto-rename, force human resolution
    if (isset($existingUnitNumbers[$name])) {
        echo "  ⚠ SKIP-NAME    $name (a unit with this number already exists)\n";
        $skippedName++;
        continue;
    }

    // Decide whether to copy the VIN. NULL it on:
    //   (a) collision with an existing equipment_units.vin
    //   (b) collision with another VIN we already used this run
    $vin = $t['vin'] ?? null;
    if ($vin !== null && $vin !== '') {
        if (isset($existingVins[$vin]) || isset($usedVinsThisRun[$vin])) {
            $vin = null;
            $nullVin++;
        }
    }

    // Pull live stats. For trailers we use the bulk-fetched map
    // (zero round trips); for vehicles we ping per-unit since the
    // /fleet/vehicles/stats endpoint can be permission-scoped.
    $stats = null;
    if ($entityType === 'trailer') {
        $stats = $trailerStats[$samsaraId] ?? null;
    } else {
        $stats = $client->getVehicleStats($samsaraId);
    }
    $gps = $stats['gps'] ?? null;

    // Year may be missing on trailers. Validate against the
    // create.php contract: 1900..currentYear+1.
    $year = null;
    if (!empty($t['year'])) {
        $y = (int) $t['year'];
        $maxY = (int) date('Y') + 1;
        if ($y >= 1900 && $y <= $maxY) {
            $year = $y;
        }
    }

    if ($dryRun) {
        printf(
            "  ✓ DRY-CREATE  %-12s [%s] vin=%s year=%s lat=%s\n",
            $name,
            $entityType,
            $vin ?? 'NULL',
            $year ?? '—',
            $gps['lat'] ?? '—'
        );
        $created++;
        // Reserve the unit_number + VIN in the in-memory sets so
        // a dry-run still surfaces collisions BETWEEN trailers in
        // the same import batch.
        $existingUnitNumbers[$name] = true;
        if ($vin !== null) $usedVinsThisRun[$vin] = true;
        continue;
    }

    try {
        db_transaction(static function () use (
            $samsaraId, $name, $entityType, $vin, $year, $t, $stats, $gps,
            $defaultTemplateId, $now
        ): void {
            // Build the row. Static identifier columns go in the
            // SAME insert as the unit fields so the import is atomic
            // — no half-created units.
            $unitId = db_insert('equipment_units', [
                'template_id'                  => $defaultTemplateId,
                'unit_number'                  => $name,
                'vin'                          => $vin,
                'year'                         => $year,
                'tracking_provider'            => 'samsara',
                'ownership_type'               => 'owned',  // best guess; editable later
                'status'                       => 'available',
                'mileage'                      => 0,        // NOT NULL UNSIGNED
                'license_plate'                => $t['license_plate'] ?? null,
                // SAMSARA-1+2 columns: snapshot of identifiers
                'samsara_vehicle_id'           => $samsaraId,
                'samsara_entity_type'          => $entityType,
                'samsara_vehicle_name'         => $name,
                'samsara_vin'                  => $t['vin'] ?? null,
                'samsara_serial_number'        => $t['serial_number'] ?? null,
                'samsara_gateway_id'           => $t['gateway_id'] ?? null,
                // Live telemetry from stats (may be null if the
                // trailer is offline or has no fix yet)
                'samsara_battery_pct'          => $stats['battery_pct']      ?? null,
                'samsara_battery_charging'     => $stats['battery_charging'] ?? null,
                'samsara_power_source'         => $stats['power_source']     ?? null,
                'samsara_check_in_mode'        => $stats['check_in_mode']    ?? null,
                'samsara_last_location_lat'    => $gps['lat']     ?? null,
                'samsara_last_location_lng'    => $gps['lng']     ?? null,
                'samsara_last_location_address'=> $gps['address'] ?? null,
                'samsara_last_speed_kph'       => $gps['speed_kph'] ?? null,
                'samsara_last_connected_at'    => isset($stats['last_connected_at'])
                    ? date('Y-m-d H:i:s', strtotime((string) $stats['last_connected_at']))
                    : null,
                'samsara_last_synced_at'       => $now,
                'samsara_odometer_km'          => $stats['odometer_km'] ?? null,
                'created_by'                   => null,
                'updated_by'                   => null,
            ]);

            // Status log — every newly-tracked unit gets a "registered"
            // entry so the Status Log tab shows the row from day 1.
            db_insert('equipment_status_log', [
                'equipment_unit_id'   => $unitId,
                'old_status'          => 'none',
                'new_status'          => 'available',
                'reason'              => 'Imported from Samsara',
                'changed_by'          => 'system (samsara_bulk_import)',
                'changed_by_user_id'  => null,
            ]);

            // Seed the breadcrumb history with the first known position
            // so the location replay UI isn't empty until the next cron.
            if ($gps && isset($gps['lat'], $gps['lng'])) {
                db_insert('samsara_location_history', [
                    'equipment_unit_id'   => $unitId,
                    'samsara_vehicle_id'  => $samsaraId,
                    'samsara_entity_type' => $entityType,
                    'latitude'            => $gps['lat'],
                    'longitude'           => $gps['lng'],
                    'speed_kph'           => $gps['speed_kph'] ?? null,
                    'heading'             => $gps['heading']   ?? null,
                    'address'             => $gps['address']   ?? null,
                    'recorded_at'         => isset($gps['time'])
                        ? date('Y-m-d H:i:s', strtotime((string) $gps['time']))
                        : $now,
                    'synced_at'           => $now,
                ]);
            }

            // Audit trail entry so the global audit log captures
            // exactly which Samsara id seeded which unit.
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system (samsara_bulk_import)',
                'action'       => 'create',
                'module'       => 'equipment',
                'entity_type'  => 'equipment_unit',
                'entity_id'    => $unitId,
                'entity_label' => $name,
                'new_values'   => json_encode([
                    'unit_number'         => $name,
                    'samsara_vehicle_id'  => $samsaraId,
                    'samsara_entity_type' => $entityType,
                ]),
                'ip_address'   => '127.0.0.1',
                'notes'        => "Created and linked from Samsara $entityType $name during bulk import",
            ]);
        });

        $created++;
        // Reserve the names so subsequent iterations in this run
        // can't reuse them.
        $existingUnitNumbers[$name] = true;
        if ($vin !== null) $usedVinsThisRun[$vin] = true;
        printf("  ✓ CREATED     %-12s [%s] %s\n", $name, $entityType, $gps ? sprintf('%.4f,%.4f', $gps['lat'], $gps['lng']) : 'no GPS');
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ FAILED      $name: " . $e->getMessage() . "\n";
    }
}

// ── Step 6: Detach the stale TEST-VEH-* rows ───────────────
// Done last so a partial import failure doesn't strand them
// with no Samsara mapping. Same column-clear set as unlink.php.
if (!$dryRun && !empty($staleTestRows)) {
    $cleared = [
        'samsara_vehicle_id'            => null,
        'samsara_entity_type'           => 'vehicle',  // NOT NULL DEFAULT
        'samsara_vehicle_name'          => null,
        'samsara_vin'                   => null,
        'samsara_serial_number'         => null,
        'samsara_gateway_id'            => null,
        'samsara_battery_pct'           => null,
        'samsara_battery_charging'      => null,
        'samsara_power_source'          => null,
        'samsara_check_in_mode'         => null,
        'samsara_last_location_lat'     => null,
        'samsara_last_location_lng'     => null,
        'samsara_last_location_address' => null,
        'samsara_last_speed_kph'        => null,
        'samsara_last_connected_at'     => null,
        'samsara_last_synced_at'        => null,
        'samsara_odometer_km'           => null,
    ];
    foreach ($staleTestRows as $rowId) {
        db_update('equipment_units', $cleared, 'id = ?', [$rowId]);
    }
    echo "[5/5] Detached " . count($staleTestRows) . " stale TEST-VEH-* rows\n";
} else {
    echo "[5/5] No stale rows to detach\n";
}

echo "\n═══════════════════════════════════════════════════\n";
echo " RESULT\n";
echo "═══════════════════════════════════════════════════\n";
printf("  created:                %d\n", $created);
printf("  skipped (already linked): %d\n", $skippedLinked);
printf("  skipped (name collision): %d\n", $skippedName);
printf("  vin nulled (collision):  %d\n", $nullVin);
printf("  failed:                 %d\n", $failed);

if ($dryRun) {
    echo "\nDRY RUN — no changes were written. Re-run without --dry-run to commit.\n";
} else {
    // Final fleet count so the operator can see the dashboard impact
    $linkedNow = db_count("SELECT COUNT(*) FROM equipment_units WHERE samsara_vehicle_id IS NOT NULL AND deleted_at IS NULL");
    echo "\nFleet Tracking will now show $linkedNow linked units. ✓\n";
    echo "The next cron tick (every 5 min) will refresh telemetry; or run\n";
    echo "  php cron/samsara_sync.php\n";
    echo "to trigger an immediate sync.\n";
}
