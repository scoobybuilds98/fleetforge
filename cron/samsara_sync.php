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
// SAMSARA-2: vehicles hit /fleet/vehicles/stats and trailers hit
// /fleet/trailers/stats (the wrong path returns HTTP 400 from
// Samsara). The samsara_entity_type column is read alongside the
// other unit fields so no extra queries are needed.
//
// S-PERF-3: trailers (every linked unit on prod) are fetched with
// ONE fleet-wide SamsaraClient::getAllTrailerStatsForSync() call per
// tick instead of one HTTPS call per unit — 167 serial calls made a
// tick take ~30 s (p90 45 s, worst 272 s); the bulk page takes ~2 s.
// Each trailer's stats go through the same normalizer, so everything
// downstream (the $update build, breadcrumbs, battery alerts) is
// unchanged. A trailer missing from the map (not in Samsara's answer,
// or on a page that failed) is skipped and NOT stamped — exactly what
// the old per-unit [] meant. Vehicles keep the per-unit call.
//
// Failures are isolated per-unit — one bad trackable never
// short-circuits the rest of the batch. Every tick logs CRON_END to
// logs/gps.log. S-PERF-3: the audit_log summary row is written only
// when something needs a human (a unit failed, the trailer fetch was
// incomplete, or the fatal path) plus one hourly heartbeat — the
// every-5-min rows were 64% of audit_log and buried real user actions.
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
// @session SAMSARA-1, SAMSARA-2, S-PERF-3
// ============================================================

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// Operator on/off switch (Settings -> Intelligence -> Scheduled Jobs). S-CRON-TOGGLES.
if (!cron_enabled('samsara_sync')) { error_log('[CRON] samsara_sync disabled - skipping.'); exit(0); }

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
// S-UTC-STAMPS: $now feeds samsara_last_synced_at + location_history.synced_at,
// UTC DATETIMEs (db.php session +00:00). Samsara's ISO-8601 'Z' times below go
// through gmdate(), not date(). (gps.log lines stay local — human-read log.)
$now       = ff_now_utc();

// Pending alerts keyed by type — filled per-unit, dispatched as
// grouped notifications after the loop (one notif per type, not per unit).
// NOTE: 'samsara.not_connected' (device-offline) was intentionally removed
// 2026-06-23 — see [NOTIF-OFFLINE-REMOVED] below.
$pendingAlerts = [
    'samsara.battery_critical' => [],
    'samsara.battery_low'      => [],
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

/**
 * Whether to emit a log line for EVERY unit on EVERY tick, including the
 * ones that did not move.
 *
 * WHY this is off by default: this cron runs every 5 minutes over ~167 units,
 * and on a normal tick almost none of them have moved. Logging each one cost
 * ~48,000 lines/day -- 83% of gps.log -- all of them saying nothing happened,
 * which buried the CRON_MOVED / CRON_SKIP / error lines that are actually
 * worth reading. The CRON_END summary already reports the processed count,
 * so the per-unit line adds no information the operator does not already get.
 *
 * Set FF_SAMSARA_VERBOSE_LOG=1 in .env to restore per-unit tracing while
 * debugging a specific unit.
 */
function ff_samsara_verbose_log(): bool
{
    static $verbose = null;
    if ($verbose === null) {
        $raw = function_exists('env')
            ? env('FF_SAMSARA_VERBOSE_LOG', '')
            : ($_ENV['FF_SAMSARA_VERBOSE_LOG'] ?? getenv('FF_SAMSARA_VERBOSE_LOG') ?: '');
        $verbose = in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }
    return $verbose;
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

    // ── S-PERF-3: one fleet-wide trailer fetch per tick ─────
    // Only when a linked trailer exists (a vehicles-only fleet makes no
    // extra call). $trailerFetchComplete=false means a page still failed
    // after its one retry: the map then holds only the pages that arrived.
    $trailerStatsMap      = [];
    $trailerFetchComplete = true;
    $missingTrailers      = [];   // unit numbers with no stats this tick → one CRON_SKIP line
    foreach ($linked as $linkedUnit) {
        if ((string) ($linkedUnit['samsara_entity_type'] ?? 'vehicle') === 'trailer') {
            $bulk                 = $client->getAllTrailerStatsForSync();
            $trailerStatsMap      = $bulk['stats'];
            $trailerFetchComplete = $bulk['complete'];
            if (!$trailerFetchComplete) {
                ff_samsara_log('CRON_BULK_INCOMPLETE', sprintf(
                    'Trailer stats fetch incomplete (%d trailers received) — linked trailers missing from it are skipped, not stamped',
                    count($trailerStatsMap)
                ));
            }
            break;
        }
    }

    // (The offline-alert timezone resolution that used to live here was removed
    // with the device-offline notification — see [NOTIF-OFFLINE-REMOVED]. Offline
    // status is still computed for the dashboard in api/v1/samsara/fleet.php.)

    foreach ($linked as $unit) {
        $unitId     = (int) $unit['id'];
        $unitNum    = (string) $unit['unit_number'];
        $vehicleId  = (string) $unit['samsara_vehicle_id'];
        // SAMSARA-2: dispatcher key. Defaults to 'vehicle' for
        // safety even though the column is NOT NULL DEFAULT 'vehicle'
        // — this protects us from a hand-edited DB row.
        $entityType = (string) ($unit['samsara_entity_type'] ?? 'vehicle');

        try {
            // S-PERF-3: trailers read the tick's fleet-wide map ('trailer' is
            // exactly getEntityStats()'s trailer branch; anything else was and
            // is the per-unit vehicle call). A missing id yields [] — the same
            // value the old per-unit call returned for "no data".
            $stats = $entityType === 'trailer'
                ? ($trailerStatsMap[$vehicleId] ?? [])
                : $client->getEntityStats($entityType, $vehicleId);

            // Empty stats = no data / transient API failure. Skip — never
            // stamp — and never abort the batch; the next tick retries.
            if (empty($stats)) {
                if ($entityType === 'trailer') {
                    // Collected into ONE CRON_SKIP line after the loop: the same
                    // dead trailers used to log NO_MATCH + CRON_SKIP every tick
                    // (65% of gps.log).
                    $missingTrailers[] = $unitNum;
                } else {
                    ff_samsara_log('CRON_SKIP', "Unit $unitNum ($entityType): getEntityStats returned []");
                }
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
                    ? gmdate('Y-m-d H:i:s', strtotime((string) $stats['last_connected_at']))
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
                                ? gmdate('Y-m-d H:i:s', strtotime((string) $gps['time']))
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
            } elseif (ff_samsara_verbose_log()) {
                // Only traced when FF_SAMSARA_VERBOSE_LOG=1 -- see
                // ff_samsara_verbose_log() for why this is off by default.
                ff_samsara_log('CRON_SYNC', "Unit $unitNum: telemetry updated (no movement)");
            }

            // ── [NOTIF-1] Battery alerts — accumulate ───────────────
            // Dispatch is grouped after the full sync loop so
            // "5 units low battery" fires as one notification, not five.
            try {
                $batteryPct = isset($stats['battery_pct']) ? (int) $stats['battery_pct'] : null;

                if ($batteryPct !== null) {
                    if ($batteryPct < 10) {
                        $pendingAlerts['samsara.battery_critical'][] =
                            ['id' => $unitId, 'num' => $unitNum, 'pct' => $batteryPct];
                    } elseif ($batteryPct < 20) {
                        $pendingAlerts['samsara.battery_low'][] =
                            ['id' => $unitId, 'num' => $unitNum, 'pct' => $batteryPct];
                    }
                }
                // [NOTIF-OFFLINE-REMOVED 2026-06-23] Device-offline ("not connected
                // for 8+ hours") notifications were generating hundreds of alerts a
                // day: unpowered trailers normally go quiet for 8h+, so the alert was
                // almost entirely noise rather than an actionable fault. Offline
                // status is still surfaced on the Samsara fleet dashboard
                // (api/v1/samsara/fleet.php) — we just no longer push it as a
                // notification. Battery alerts above are unaffected.
            } catch (\Throwable $notifErr) {
                error_log('[NOTIF samsara] ' . $notifErr->getMessage());
            }
        } catch (\Throwable $e) {
            // DB or unexpected error for this unit only — keep going.
            $failed++;
            ff_samsara_log('CRON_FAIL', "Unit $unitNum: " . $e->getMessage());
        }
    }

    if ($missingTrailers !== []) {
        ff_samsara_log('CRON_SKIP', sprintf(
            '%d trailer(s) had no stats in the fleet-wide response%s: %s',
            count($missingTrailers),
            $trailerFetchComplete ? '' : ' (fetch incomplete)',
            implode(', ', $missingTrailers)
        ));
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
            // 'samsara.not_connected' (device-offline) removed 2026-06-23 —
            // see [NOTIF-OFFLINE-REMOVED]. Battery alerts only from here on.
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
                    // S-LOCAL-DAY-TS: sent_at is UTC like the DB-defaulted created_at. Not
                    // $now (also UTC since S-UTC-STAMPS, but stamped at tick start).
                    'sent_at'           => ff_now_utc(),
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

    // ── S-PERF-3: audit row only when it tells someone something ──
    // Written when a unit failed, when the trailer fetch was incomplete (a
    // Samsara outage now shows every tick, not just hourly), or once an hour
    // as a heartbeat: the */5 tick whose START minute is 0-4 (minute-only, so
    // it is timezone-safe; taken at start so a slow run can't skip it). The
    // summary still goes to gps.log (CRON_END) and stdout on every tick. The
    // fatal path below always writes. FF_SAMSARA_SYNC_TEST_MINUTE is a test
    // seam defined only by tests/_smoke_samsara_sync_exec.php.
    $auditMinute = defined('FF_SAMSARA_SYNC_TEST_MINUTE')
        ? (int) constant('FF_SAMSARA_SYNC_TEST_MINUTE')
        : (int) date('i', (int) $startedAt);
    if ($failed > 0 || !$trailerFetchComplete || $auditMinute < 5) {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'equipment',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'samsara_sync',
            // Say WHY an off-the-hour row exists when the fetch was the
            // trigger — the counts alone ("0 failed") would not explain it.
            'notes'        => $summary . ($trailerFetchComplete
                ? ''
                : ' — trailer stats fetch incomplete (see gps.log CRON_BULK_INCOMPLETE)'),
            'ip_address'   => '127.0.0.1',
        ]);
    }

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
