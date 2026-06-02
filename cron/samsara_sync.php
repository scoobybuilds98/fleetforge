<?php
declare(strict_types=1);

// ============================================================
// cron/samsara_sync.php — Live Samsara telemetry sync
//
// Schedule:        every 5 minutes  (*/5 * * * *)
// Advisory lock:   ff_cron_samsara_sync     [D21]
//
// For every equipment_unit that is currently mapped to a Samsara
// trackable (samsara_vehicle_id IS NOT NULL), pull the latest
// stats from Samsara and:
//   1. Update samsara_* live telemetry columns on equipment_units
//   2. Append a row to samsara_location_history IF the lat/lng
//      changed vs the previously-stored values (parked trackables
//      do not bloat the breadcrumb table).
//   3. Stamp samsara_last_synced_at to NOW() so the UI staleness
//      indicator stays accurate.
//
// SAMSARA-2: dispatches via SamsaraClient::getEntityStats($type, $id)
// per unit so vehicles hit /fleet/vehicles/stats and trailers hit
// /fleet/trailers/stats. The wrong path returns HTTP 400 from
// Samsara. The samsara_entity_type column is read alongside the
// other unit fields so no extra queries are needed.
//
// Failures are isolated per-unit — one bad trackable never
// short-circuits the rest of the batch. The cron writes ONE
// summary row to audit_log at the end and a per-unit line to
// logs/gps.log so you can reconstruct exactly what happened.
//
// Static identifier columns (vin, serial, gateway, vehicle_name,
// entity_type) are NEVER touched here — they were snapshotted at
// link time and we do not chase Samsara renames (avoids confusing
// diffs when ops staff rename trackables in Samsara).
//
// Run manually for testing:
//   php /Users/avi/Documents/fleetforge/cron/samsara_sync.php
//
// @depends config/app.php, lib/GPS/SamsaraClient.php
// @session SAMSARA-1, SAMSARA-2
// ============================================================

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\GPS\SamsaraClient;

// ── Advisory lock (D21) — prevents two parallel cron ticks ─
// from racing each other if the previous run hasn't finished.
// Timeout 0: return immediately rather than blocking.
$lock = db_row("SELECT GET_LOCK('ff_cron_samsara_sync', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    // Another instance is already running — exit silently and
    // wait for the next tick. This is the expected behaviour the
    // first few seconds after a deploy.
    exit(0);
}

$startedAt = microtime(true);
$processed = 0;   // unit synced + telemetry written
$movedUnit = 0;   // unit synced AND a breadcrumb row appended
$skipped   = 0;   // Samsara returned no data for this vehicle
$failed    = 0;   // DB or unexpected error during sync of this unit
$now       = date('Y-m-d H:i:s');

// Pending alerts keyed by type — filled per-unit, dispatched as
// grouped notifications after the loop (one notif per type, not per unit).
$pendingAlerts = [
    'samsara.battery_critical' => [],
    'samsara.battery_low'      => [],
    'samsara.not_connected'    => [],
];

/**
 * Append a single line to logs/gps.log.
 *
 * Centralised so per-unit success / failure / move events all use
 * the same timestamp/format and can be tailed reliably.
 */
function ff_samsara_log(string $level, string $msg): void
{
    $logPath = FF_ROOT . '/logs/gps.log';
    @file_put_contents(
        $logPath,
        sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg),
        FILE_APPEND | LOCK_EX
    );
}

try {
    $client = new SamsaraClient();

    // ── Fetch every linked unit ─────────────────────────────
    // We pull the previous lat/lng so the per-unit logic can
    // decide whether to write a breadcrumb row without an
    // extra round trip. samsara_entity_type drives the dispatch
    // to /fleet/vehicles/stats vs /fleet/trailers/stats.
    $linked = db_select(
        "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type,
                samsara_last_location_lat, samsara_last_location_lng
           FROM equipment_units
          WHERE samsara_vehicle_id IS NOT NULL
            AND samsara_vehicle_id <> ''
            AND deleted_at IS NULL"
    );

    ff_samsara_log('CRON_START', sprintf('Tick: %d linked units to sync', count($linked)));

    // Resolve the offline-alert timezone once outside the unit loop.
    // samsara_last_connected_at is stored as a local-tz string (date() uses
    // APP_TIMEZONE); using DateTimeImmutable with an explicit DateTimeZone
    // gives an accurate Unix timestamp for the >8h offline threshold, avoiding
    // the prior ~7-8h skew from strtotime($lastConn . ' UTC').
    // (D-SAMSARA-OFFLINE-TZ, locked S-CRON-FIX-REMAINING 2026-06-03)
    $tzName = (string) settings_get('company.timezone', APP_TIMEZONE);
    try {
        $alertTz = new \DateTimeZone($tzName);
    } catch (\Exception) {
        $alertTz = new \DateTimeZone(APP_TIMEZONE);
    }

    foreach ($linked as $unit) {
        $unitId     = (int) $unit['id'];
        $unitNum    = (string) $unit['unit_number'];
        $vehicleId  = (string) $unit['samsara_vehicle_id'];
        // SAMSARA-2: dispatcher key. Defaults to 'vehicle' for
        // safety even though the column is NOT NULL DEFAULT 'vehicle'
        // — this protects us from a hand-edited DB row.
        $entityType = (string) ($unit['samsara_entity_type'] ?? 'vehicle');

        try {
            $stats = $client->getEntityStats($entityType, $vehicleId);

            // Empty stats = transient API failure (logged inside
            // the client). Skip but never abort the batch — the
            // next tick will retry automatically.
            if (empty($stats)) {
                ff_samsara_log('CRON_SKIP', "Unit $unitNum ($entityType): getEntityStats returned []");
                $skipped++;
                continue;
            }

            $gps = $stats['gps'] ?? null;

            $update = [
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
            ];

            // ── Single transaction per unit ─────────────────
            // Transaction scope is intentionally per-unit so a
            // failure in one row doesn't roll back successful
            // syncs of other units in the same tick.
            $movedThisUnit = db_transaction(static function () use ($unitId, $update, $gps, $unit, $vehicleId, $entityType, $now): bool {
                // db_update SET clause uses positional ?, so the WHERE
                // fragment must too or PDO throws "mixed named and positional
                // parameters". [SAMSARA-2 bugfix — SAMSARA-1 used :id.]
                db_update('equipment_units', $update, 'id = ?', [$unitId]);

                // Breadcrumb append — only when the lat/lng moved.
                if ($gps && isset($gps['lat'], $gps['lng'])) {
                    $prevLat = isset($unit['samsara_last_location_lat']) ? (float) $unit['samsara_last_location_lat'] : null;
                    $prevLng = isset($unit['samsara_last_location_lng']) ? (float) $unit['samsara_last_location_lng'] : null;
                    $newLat  = (float) $gps['lat'];
                    $newLng  = (float) $gps['lng'];

                    // Round to DB column precision (7dp) before
                    // comparing so a no-op rounding diff doesn't
                    // create a phantom breadcrumb row.
                    $moved = $prevLat === null
                          || $prevLng === null
                          || round($prevLat, 7) !== round($newLat, 7)
                          || round($prevLng, 7) !== round($newLng, 7);

                    if ($moved) {
                        db_insert('samsara_location_history', [
                            'equipment_unit_id'   => $unitId,
                            'samsara_vehicle_id'  => $vehicleId,
                            'samsara_entity_type' => $entityType,
                            'latitude'            => $newLat,
                            'longitude'           => $newLng,
                            'speed_kph'           => $gps['speed_kph'] ?? null,
                            'heading'             => $gps['heading']   ?? null,
                            'address'             => $gps['address']   ?? null,
                            'recorded_at'         => isset($gps['time'])
                                ? date('Y-m-d H:i:s', strtotime((string) $gps['time']))
                                : $now,
                            'synced_at'           => $now,
                        ]);
                        return true;
                    }
                }
                return false;
            });

            $processed++;
            if ($movedThisUnit) {
                $movedUnit++;
                ff_samsara_log('CRON_MOVED', sprintf(
                    'Unit %s → %.6f,%.6f (%s)',
                    $unitNum,
                    (float) ($gps['lat'] ?? 0),
                    (float) ($gps['lng'] ?? 0),
                    (string) ($gps['address'] ?? 'no address')
                ));
            } else {
                ff_samsara_log('CRON_SYNC', "Unit $unitNum: telemetry updated (no movement)");
            }

            // ── [NOTIF-1] Battery + connectivity alerts — accumulate ─
            // Dispatch is grouped after the full sync loop so
            // "5 units offline" fires as one notification, not five.
            try {
                $batteryPct = isset($stats['battery_pct']) ? (int) $stats['battery_pct'] : null;
                $lastConn   = $update['samsara_last_connected_at'];

                if ($batteryPct !== null) {
                    if ($batteryPct < 10) {
                        $pendingAlerts['samsara.battery_critical'][] =
                            ['id' => $unitId, 'num' => $unitNum, 'pct' => $batteryPct];
                    } elseif ($batteryPct < 20) {
                        $pendingAlerts['samsara.battery_low'][] =
                            ['id' => $unitId, 'num' => $unitNum, 'pct' => $batteryPct];
                    }
                }
                if ($lastConn !== null) {
                    $lastConnDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastConn, $alertTz);
                    $hoursSince = $lastConnDt !== false
                        ? (int) floor((time() - $lastConnDt->getTimestamp()) / 3600)
                        : 0;
                    if ($hoursSince > 8) {
                        $pendingAlerts['samsara.not_connected'][] =
                            ['id' => $unitId, 'num' => $unitNum, 'hours' => $hoursSince];
                    }
                }
            } catch (\Throwable $notifErr) {
                error_log('[NOTIF samsara] ' . $notifErr->getMessage());
            }
        } catch (\Throwable $e) {
            // DB or unexpected error for this unit only — keep going.
            $failed++;
            ff_samsara_log('CRON_FAIL', "Unit $unitNum: " . $e->getMessage());
        }
    }

    // ── [NOTIF-1] Grouped alert dispatch ──────────────────────────
    // Fires at most one notification per alert type per cron tick.
    // Per-unit 6h dedup via notification_log prevents re-alerting the
    // same unit until it clears; units that already fired are excluded
    // from the grouped message without suppressing new ones.
    try {
        $alertMeta = [
            'samsara.battery_critical' => [
                'sev'   => 'critical',
                'title' => static fn(int $n): string  => $n === 1
                    ? '1 unit battery CRITICAL'
                    : "{$n} units battery CRITICAL",
                'msg'   => static fn(array $us): string => 'Battery below 10% on: '
                    . implode(', ', array_map(
                        static fn($u) => $u['num'] . " ({$u['pct']}%)", $us
                    ))
                    . ' — charge immediately.',
            ],
            'samsara.battery_low' => [
                'sev'   => 'warning',
                'title' => static fn(int $n): string  => $n === 1
                    ? '1 unit battery low'
                    : "{$n} units battery low",
                'msg'   => static fn(array $us): string => 'Battery below 20% on: '
                    . implode(', ', array_map(
                        static fn($u) => $u['num'] . " ({$u['pct']}%)", $us
                    ))
                    . ' — charge needed.',
            ],
            'samsara.not_connected' => [
                'sev'   => 'warning',
                'title' => static fn(int $n): string  => $n === 1
                    ? '1 unit offline'
                    : "{$n} units offline",
                'msg'   => static fn(array $us): string => 'Not connected for 8+ hours: '
                    . implode(', ', array_map(
                        static fn($u) => $u['num'] . " ({$u['hours']}h)", $us
                    )) . '.',
            ],
        ];

        foreach ($alertMeta as $type => $meta) {
            $candidates = $pendingAlerts[$type];
            if (empty($candidates)) {
                continue;
            }

            // Filter out units already notified within the 6h window
            $fresh = [];
            foreach ($candidates as $c) {
                $recent = db_row(
                    "SELECT id FROM notification_log
                      WHERE entity_type = 'equipment_unit' AND entity_id = ?
                        AND notification_type = ?
                        AND created_at >= NOW() - INTERVAL 6 HOUR
                      LIMIT 1",
                    [$c['id'], $type]
                );
                if (!$recent) {
                    $fresh[] = $c;
                }
            }
            if (empty($fresh)) {
                continue;
            }

            $n     = count($fresh);
            $title = ($meta['title'])($n);
            $msg   = ($meta['msg'])($fresh);

            \FleetForge\Notifications\NotificationService::notify(
                type:       $type,
                title:      $title,
                message:    $msg,
                entityType: 'equipment_unit',
                entityId:   $n === 1 ? (int) $fresh[0]['id'] : null,
                url:        '/fleetforge/equipment/',
                severity:   $meta['sev']
            );

            // One notification_log row per unit so the 6h dedup works on
            // the next tick — entity_id tracks the individual unit, not the group.
            foreach ($fresh as $c) {
                db_insert('notification_log', [
                    'rule_id'           => null,
                    'channel'           => 'in_app',
                    'recipient'         => 'all_staff',
                    'subject'           => $title,
                    'body'              => $msg,
                    'entity_type'       => 'equipment_unit',
                    'entity_id'         => (int) $c['id'],
                    'notification_type' => $type,
                    'status'            => 'sent',
                    'sent_at'           => $now,
                ]);
            }
        }
    } catch (\Throwable $notifErr) {
        error_log('[NOTIF samsara grouped] ' . $notifErr->getMessage());
    }

    $duration = round(microtime(true) - $startedAt, 2);
    $summary = sprintf(
        'Samsara sync done in %ss: %d processed, %d moved, %d skipped, %d failed (of %d linked)',
        $duration,
        $processed,
        $movedUnit,
        $skipped,
        $failed,
        count($linked)
    );

    ff_samsara_log('CRON_END', $summary);

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'equipment',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'samsara_sync',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

    echo "[SAMSARA_SYNC] $summary\n";

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    $msg = $e->getMessage();
    ff_samsara_log('CRON_FATAL', $msg);
    error_log("[SAMSARA_SYNC] Fatal error: $msg");

    try {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'equipment',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'samsara_sync',
            'notes'        => "Samsara sync FAILED: $msg",
            'ip_address'   => '127.0.0.1',
        ]);
    } catch (\Throwable) {
        // Audit logging failure inside a fatal — nothing we can do
    }

    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_samsara_sync')", []);
}
