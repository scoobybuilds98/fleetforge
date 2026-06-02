<?php
declare(strict_types=1);

/**
 * api/v1/samsara/import.php
 *
 * POST → Bulk-import all Samsara trackables into FleetForge.
 *
 * Idempotent: already-linked Samsara IDs are skipped, so this is safe
 * to call after a wipe (re-creates all units) or anytime new assets
 * are added in Samsara (only inserts the new ones).
 *
 * Mirrors scripts/samsara_bulk_import.php — same logic, exposed as
 * an authenticated API endpoint so the Fleet Tracking dashboard can
 * trigger it without SSH access.
 *
 * Returns:
 *   { created, skipped_linked, skipped_name, vin_nulled, failed, total_linked }
 *
 * @method  POST
 * @auth    require_permission('equipment', 'edit')
 * @session SAMSARA-2, SAMSARA-IMPORT-UI
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

use FleetForge\GPS\SamsaraClient;

$userId   = current_user_id();
$userName = current_user()['name'] ?? 'system';
$ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$now      = date('Y-m-d H:i:s');

try {
    $client = new SamsaraClient();

    // ── Step 1: Fetch all Samsara trackables ──────────────────
    $trackables = $client->getAllTrackables();
    if (empty($trackables)) {
        json_error('NO_TRACKABLES', 'Samsara returned no trackables. Check the API key in Settings → Integrations.', 502);
    }

    // ── Step 2: Bulk fetch trailer stats in one call ──────────
    $trailerStats = $client->getAllTrailerStats();

    // ── Step 3: Build skip-set from already-linked units ──────
    $alreadyLinked = db_select(
        "SELECT id, unit_number, samsara_vehicle_id
           FROM equipment_units
          WHERE samsara_vehicle_id IS NOT NULL
            AND samsara_vehicle_id <> ''
            AND deleted_at IS NULL"
    );
    $linkedSet    = [];
    $staleTestIds = [];
    foreach ($alreadyLinked as $u) {
        $sid = (string) $u['samsara_vehicle_id'];
        if (str_starts_with($sid, 'TEST-')) {
            $staleTestIds[] = (int) $u['id'];
        } else {
            $linkedSet[$sid] = true;
        }
    }

    // ── Step 4: Build unit_number + VIN skip-sets ─────────────
    $existingUnitNumbers = array_flip(array_column(
        db_select("SELECT unit_number FROM equipment_units WHERE deleted_at IS NULL"),
        'unit_number'
    ));
    $existingVins = array_flip(array_column(
        db_select("SELECT vin FROM equipment_units WHERE vin IS NOT NULL AND vin <> '' AND deleted_at IS NULL"),
        'vin'
    ));

    // Default template for imported units
    $tplRow = db_row("SELECT id FROM equipment_templates WHERE name = '53ft Dry Van' AND deleted_at IS NULL LIMIT 1")
           ?? db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$tplRow) {
        json_error('NO_TEMPLATES', 'No equipment templates exist. Create at least one before importing.', 422);
    }
    $defaultTemplateId = (int) $tplRow['id'];

    // ── Step 5: Import ────────────────────────────────────────
    $created       = 0;
    $skippedLinked = 0;
    $skippedName   = 0;
    $failed        = 0;
    $nullVin       = 0;
    $usedVinsThisRun = [];
    $skippedNames  = [];
    $failedNames   = [];

    foreach ($trackables as $t) {
        $samsaraId  = (string) $t['id'];
        $name       = (string) $t['name'];
        $entityType = (string) ($t['entity_type'] ?? 'vehicle');

        if (isset($linkedSet[$samsaraId])) {
            $skippedLinked++;
            continue;
        }

        if (isset($existingUnitNumbers[$name])) {
            $skippedName++;
            $skippedNames[] = $name;
            continue;
        }

        $vin = ($t['vin'] ?? null) !== '' ? ($t['vin'] ?? null) : null;
        if ($vin !== null && (isset($existingVins[$vin]) || isset($usedVinsThisRun[$vin]))) {
            $vin = null;
            $nullVin++;
        }

        $stats = $entityType === 'trailer'
            ? ($trailerStats[$samsaraId] ?? null)
            : $client->getVehicleStats($samsaraId);
        $gps = $stats['gps'] ?? null;

        $year = null;
        if (!empty($t['year'])) {
            $y    = (int) $t['year'];
            $maxY = (int) date('Y') + 1;
            if ($y >= 1900 && $y <= $maxY) {
                $year = $y;
            }
        }

        try {
            db_transaction(static function () use (
                $samsaraId, $name, $entityType, $vin, $year, $t, $stats, $gps,
                $defaultTemplateId, $now, $userId, $userName, $ip
            ): void {
                $unitId = db_insert('equipment_units', [
                    'template_id'                   => $defaultTemplateId,
                    'unit_number'                   => $name,
                    'vin'                           => $vin,
                    'year'                          => $year,
                    'tracking_provider'             => 'samsara',
                    'ownership_type'                => 'owned',
                    'status'                        => 'available',
                    'mileage'                       => 0,
                    'license_plate'                 => $t['license_plate'] ?? null,
                    'samsara_vehicle_id'            => $samsaraId,
                    'samsara_entity_type'           => $entityType,
                    'samsara_vehicle_name'          => $name,
                    'samsara_vin'                   => $t['vin'] ?? null,
                    'samsara_serial_number'         => $t['serial_number'] ?? null,
                    'samsara_gateway_id'            => $t['gateway_id'] ?? null,
                    'samsara_battery_pct'           => $stats['battery_pct']      ?? null,
                    'samsara_battery_charging'      => $stats['battery_charging'] ?? null,
                    'samsara_power_source'          => $stats['power_source']     ?? null,
                    'samsara_check_in_mode'         => $stats['check_in_mode']    ?? null,
                    'samsara_last_location_lat'     => $gps['lat']     ?? null,
                    'samsara_last_location_lng'     => $gps['lng']     ?? null,
                    'samsara_last_location_address' => $gps['address'] ?? null,
                    'samsara_last_speed_kph'        => $gps['speed_kph'] ?? null,
                    'samsara_last_connected_at'     => isset($stats['last_connected_at'])
                        ? date('Y-m-d H:i:s', strtotime((string) $stats['last_connected_at']))
                        : null,
                    'samsara_last_synced_at'        => $now,
                    'samsara_odometer_km'           => $stats['odometer_km'] ?? null,
                    'created_by'                    => $userId,
                    'updated_by'                    => $userId,
                ]);

                db_insert('equipment_status_log', [
                    'equipment_unit_id'  => $unitId,
                    'old_status'         => 'none',
                    'new_status'         => 'available',
                    'reason'             => 'Imported from Samsara',
                    'changed_by'         => 'system (import)',
                    'changed_by_user_id' => $userId,
                ]);

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

                db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => $userName,
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
                    'ip_address'   => $ip,
                    'notes'        => "Imported from Samsara via Fleet Tracking dashboard",
                ]);
            });

            $created++;
            $existingUnitNumbers[$name] = true;
            if ($vin !== null) {
                $usedVinsThisRun[$vin] = true;
            }
        } catch (\Throwable $e) {
            $failed++;
            $failedNames[] = $name . ': ' . $e->getMessage();
            error_log("[samsara/import] failed for $name: " . $e->getMessage());
        }
    }

    // ── Step 6: Detach stale TEST-* rows ──────────────────────
    if (!empty($staleTestIds)) {
        $cleared = [
            'samsara_vehicle_id' => null, 'samsara_entity_type' => 'vehicle',
            'samsara_vehicle_name' => null, 'samsara_vin' => null,
            'samsara_serial_number' => null, 'samsara_gateway_id' => null,
            'samsara_battery_pct' => null, 'samsara_battery_charging' => null,
            'samsara_power_source' => null, 'samsara_check_in_mode' => null,
            'samsara_last_location_lat' => null, 'samsara_last_location_lng' => null,
            'samsara_last_location_address' => null, 'samsara_last_speed_kph' => null,
            'samsara_last_connected_at' => null, 'samsara_last_synced_at' => null,
            'samsara_odometer_km' => null,
        ];
        foreach ($staleTestIds as $rowId) {
            db_update('equipment_units', $cleared, 'id = ?', [$rowId]);
        }
    }

    $totalLinked = (int) db_count(
        "SELECT COUNT(*) FROM equipment_units WHERE samsara_vehicle_id IS NOT NULL AND deleted_at IS NULL"
    );

    json_success([
        'created'        => $created,
        'skipped_linked' => $skippedLinked,
        'skipped_name'   => $skippedName,
        'skipped_names'  => $skippedNames,
        'vin_nulled'     => $nullVin,
        'failed'         => $failed,
        'failed_details' => $failedNames,
        'total_linked'   => $totalLinked,
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('IMPORT_FAILED', 'Import failed: ' . $e->getMessage(), 500);
}
