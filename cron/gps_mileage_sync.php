<?php
declare(strict_types=1);

// ============================================================
// cron/gps_mileage_sync.php — Daily GPS Mileage Sync
//
// Schedule: 0 7 * * * (daily at 7AM)
// Advisory lock: ff_cron_gps_mileage_sync
//
// For each active lease where the unit has a gps_device_id:
//   1. Call SamsaraClient::getOdometerReading() for current odometer
//   2. If successful: insert mileage_logs row (log_type='gps_sync')
//   3. Update equipment_units.mileage with latest reading
//   4. Failures: log to logs/gps.log and continue — never blocking
//
// Dev mode: SamsaraClient returns null when API keys are blank.
// Cron still runs but skips all Samsara calls (zero API errors).
//
// Run manually for testing:
//   php /Users/avi/Documents/fleetforge/cron/gps_mileage_sync.php
// ============================================================

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// Operator on/off switch (Settings -> Intelligence -> Scheduled Jobs). S-CRON-TOGGLES.
if (!cron_enabled('gps_mileage_sync')) { error_log('[CRON] gps_mileage_sync disabled - skipping.'); exit(0); }

use FleetForge\GPS\SamsaraClient;

// ── Advisory lock (D21): prevents duplicate runs
$lock = db_row("SELECT GET_LOCK('ff_cron_gps_mileage_sync', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    // Another instance is running — exit silently
    exit(0);
}

$processed = 0;
$skipped   = 0;
$failed    = 0;
$today     = date('Y-m-d');

try {
    // ── Instantiate GPS client (returns null if API keys blank — dev safe)
    $gps = new SamsaraClient();

    // ── Query all active leases with GPS-enabled units
    // gps_device_id is the Samsara vehicle ID stored on equipment_units
    $leases = db_select(
        "SELECT
            l.id           AS lease_id,
            l.start_date,
            l.customer_id,
            l.equipment_unit_id,
            l.mileage_unit,
            eu.unit_number,
            eu.gps_device_id,
            eu.mileage     AS current_odometer
         FROM leases l
         JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
         WHERE l.status = 'active'
           AND l.deleted_at IS NULL
           AND eu.gps_device_id IS NOT NULL
           AND eu.gps_device_id != ''",
        []
    );

    foreach ($leases as $lease) {
        $vehicleId = $lease['gps_device_id'];
        $unitId    = $lease['equipment_unit_id'];
        $leaseId   = $lease['lease_id'];
        $unitNum   = $lease['unit_number'];

        // ── Skip if we already have a gps_sync entry for today on this unit
        $alreadySynced = db_exists(
            'mileage_logs',
            "equipment_unit_id = ? AND log_type = 'gps_sync' AND log_date = ?",
            [$unitId, $today]
        );

        if ($alreadySynced) {
            $skipped++;
            continue;
        }

        // ── Fetch current odometer from Samsara
        $odometerKm = $gps->getOdometerReading($vehicleId);

        if ($odometerKm === null) {
            // GPS unavailable — log and continue (non-blocking)
            error_log("[GPS_SYNC] Unit $unitNum (vehicle $vehicleId): GPS returned null — skipping.");
            $skipped++;
            continue;
        }

        // ── Determine mileage unit for this lease (D34 km default)
        $mileageUnit = $lease['mileage_unit'] ?? 'km';
        $odometer    = $odometerKm; // getOdometerReading() returns km

        if ($mileageUnit === 'miles') {
            // Convert km → miles for storage consistency with lease preference
            $odometer = (int) round($odometerKm * 0.621371);
        }

        // ── Insert mileage_logs row + update equipment_units.mileage
        try {
            db_transaction(function () use ($unitId, $leaseId, $odometer, $mileageUnit, $today) {
                db_insert('mileage_logs', [
                    'equipment_unit_id' => $unitId,
                    'lease_id'          => $leaseId,
                    'log_type'          => 'gps_sync',
                    'odometer_reading'  => $odometer,
                    'mileage_unit'      => $mileageUnit,
                    'log_date'          => $today,
                    'notes'             => 'Automatic daily GPS sync',
                    'recorded_by'       => null, // system-generated
                ]);

                // Update unit's current mileage (always take the latest reading)
                db_execute(
                    "UPDATE equipment_units SET mileage = ? WHERE id = ?",
                    [$odometer, $unitId]
                );
            });

            $processed++;
            error_log("[GPS_SYNC] Unit $unitNum: odometer $odometer $mileageUnit recorded.");

        } catch (\Throwable $e) {
            // DB error for this unit — log and continue processing others
            error_log("[GPS_SYNC] Unit $unitNum: DB error — " . $e->getMessage());
            $failed++;
        }
    }

    // ── Log completion to audit_log
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'maintenance',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'gps_mileage_sync',
        'notes'        => "GPS mileage sync completed: $processed synced, $skipped skipped, $failed failed.",
        'ip_address'   => '127.0.0.1',
    ]);

    echo "[GPS_SYNC] Done. Processed: $processed, Skipped: $skipped, Failed: $failed\n";

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    $msg = $e->getMessage();
    error_log("[GPS_SYNC] Fatal error: $msg");

    try {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'maintenance',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'gps_mileage_sync',
            'notes'        => "GPS mileage sync FAILED: $msg",
            'ip_address'   => '127.0.0.1',
        ]);
    } catch (\Throwable) {
        // Audit log failure during fatal — nothing we can do
    }

    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_gps_mileage_sync')", []);
}
