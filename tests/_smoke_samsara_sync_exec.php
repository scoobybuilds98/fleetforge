<?php
declare(strict_types=1);

/**
 * tests/_smoke_samsara_sync_exec.php
 *
 * S-PERF-3 (batch I) — EXECUTES the real cron/samsara_sync.php, the real
 * api/v1/samsara/sync.php "sync all" path and the real cron/cache_cleanup.php
 * against the real schema, with Samsara mocked through
 * SamsaraClient::setHttpTransportForTesting(). Before S-PERF-3 no smoke ran the
 * 5-min sync at all (_smoke_samsara_sync_gate.php is source-level only), and it
 * could not: apiRequest() had its own cURL handle outside the test seam.
 *
 * What S-PERF-3 changed and what this proves:
 *   1. Trailers are fetched with ONE fleet-wide /fleet/trailers/stats call per
 *      tick (cursor-paginated, one retry per page) instead of one call per
 *      linked unit. The DB effects must be byte-identical to the per-unit
 *      cron on the same mock — equipment_units samsara_* columns, breadcrumbs,
 *      notification_log battery rows, notifications, attention items, counts.
 *      (--emit=<dir> writes those normalised effects so the pre-change cron,
 *      run from a HEAD worktree, can be diffed against this one.)
 *   2. A trailer missing from the map (not in Samsara's answer, or on a page
 *      that failed) is skipped and NOT stamped — its samsara_last_synced_at
 *      stays old. Page-1 failure → no trailer written; page-2 failure → only
 *      page-1 trailers written; a transient 503 is retried once and recovers.
 *   3. The audit_log heartbeat is gated: a clean tick writes no row, except
 *      the hourly one (minute 0-4) — also written when a unit failed, when the
 *      trailer fetch came back incomplete, and on the fatal path. gps.log still
 *      gets CRON_END every tick and ONE CRON_SKIP line for missing trailers.
 *   4. cache_cleanup writes its audit row only when it deleted something;
 *      notification_digest's gate helper skips all-zero, non-forced runs.
 *
 * Hermetic: every scenario runs in its own child PHP process inside ONE
 * transaction that is rolled back (db_transaction() is nesting-safe, so the
 * cron's per-unit transactions join it). The mock never reaches the network
 * and fails the run on any URL that is not a current-stats call. The only
 * lasting side effect is a few lines appended to logs/gps.log.
 *
 * Run:  php tests/_smoke_samsara_sync_exec.php            Exit 0 = pass, 1 = fail, 2 = setup.
 *       php tests/_smoke_samsara_sync_exec.php --emit=DIR (writes effects JSON, no asserts)
 *
 * @session S-PERF-3
 */

// Marker that separates a child's JSON report from anything the code under
// test printed.
const SSE_MARK = '@@SSE_RESULT@@';

// Fixture roles, in the order the first seven linked units are assigned.
//   A moved trailer · B parked trailer (same 7dp position) · C trailer missing
//   from Samsara's answer · D trailer with no gps block · E vehicle, battery 15%
//   (low) with the charging / power / check-in fields · F vehicle, battery 5%
//   (critical), no gps · G trailer on bulk page 2, first ever position.
const SSE_ROLES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

$opts = getopt('', ['child:', 'minute:', 'sessdir:', 'emit:']);

// =====================================================================
// CHILD MODE — one scenario, one process, one rolled-back transaction.
// Runs at file scope on purpose: the cron is a script whose counters
// ($processed, $now, …) must land in $GLOBALS for the report.
// =====================================================================
if (isset($opts['child'])) {
    $SSE_SCENARIO = (string) $opts['child'];
    $SSE_IS_API   = $SSE_SCENARIO === 'api';

    if ($SSE_IS_API) {
        // A real POST with no body → the sync-all branch. Session lives in a
        // private save path so nothing touches the dev server's sessions.
        $_SERVER['REQUEST_METHOD']  = 'POST';
        $_SERVER['REQUEST_URI']     = '/fleetforge/api/v1/samsara/sync';
        $_SERVER['HTTP_HOST']       = 'fleetforge.test';
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'ff-smoke-samsara-sync-exec';
        $_SERVER['HTTP_ACCEPT']     = 'application/json';
        ini_set('session.save_path', (string) ($opts['sessdir'] ?? sys_get_temp_dir()));
    }

    require_once dirname(__DIR__) . '/config/app.php';

    // Fallback key so the client never short-circuits on "no API key" on a box
    // without one. The transport mock below means no request leaves anyway.
    if (!isset($_ENV['SAMSARA_API_TOKEN']) || $_ENV['SAMSARA_API_TOKEN'] === '') {
        $_ENV['SAMSARA_API_TOKEN'] = 'smoke-dummy-token';
    }

    $SSE_LOG_PATH   = FF_ROOT . '/logs/gps.log';
    clearstatcache();
    $SSE_LOG_OFFSET = is_file($SSE_LOG_PATH) ? (int) filesize($SSE_LOG_PATH) : 0;

    db_pdo()->beginTransaction();

    if ($SSE_IS_API) {
        require_once FF_ROOT . '/includes/auth.php';
        $u = db_row(
            "SELECT u.id, u.name, u.email, u.role_id, r.slug AS role_slug, u.theme_preference, u.status,
                    u.display_font_size, u.display_density
               FROM users u JOIN user_roles r ON r.id = u.role_id
              WHERE u.status = 'active' AND u.deleted_at IS NULL AND r.slug = 'super_admin'
              ORDER BY (u.id = 54) DESC, u.id LIMIT 1"
        );
        if (!$u) { fwrite(STDOUT, SSE_MARK . json_encode(['setup_error' => 'no active super_admin']) . "\n"); exit(2); }
        @auth_login($u, false);
        if (($_SESSION['csrf_token'] ?? '') === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
    }

    $SSE_FIX   = sse_setup_fixtures($SSE_SCENARIO);
    $SSE_MAXID = sse_max_ids();
    $SSE_OTHER = sse_other_units_digest($SSE_FIX['ids']);
    $SSE_ATTN  = sse_attention_snapshot();
    $SSE_CALLS = [];
    \FleetForge\GPS\SamsaraClient::setHttpTransportForTesting(sse_make_transport($SSE_SCENARIO, $SSE_FIX, $SSE_CALLS));

    if (isset($opts['minute'])) {
        // Test seam read by cron/samsara_sync.php (the pre-S-PERF-3 cron ignores it).
        define('FF_SAMSARA_SYNC_TEST_MINUTE', (int) $opts['minute']);
    }

    register_shutdown_function(static function (): void {
        $out = '';
        while (ob_get_level() > 0) { $out = (string) ob_get_clean() . $out; }
        $report = sse_collect_report($out);
        try {
            if (db_pdo()->inTransaction()) { db_pdo()->rollBack(); }
        } catch (\Throwable $e) {
            $report['rollback_error'] = $e->getMessage();
        }
        \FleetForge\GPS\SamsaraClient::setHttpTransportForTesting(null);
        fwrite(STDOUT, "\n" . SSE_MARK . json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    });

    ob_start();
    if ($SSE_IS_API) {
        require FF_ROOT . '/api/v1/samsara/sync.php';   // ends in json_success() → exit
    } else {
        require FF_ROOT . '/cron/samsara_sync.php';
    }
    exit(0);
}

// =====================================================================
// Shared helpers (hoisted — used by both modes)
// =====================================================================

/**
 * Samsara ISO-8601 time for a fixed offset from a fixed epoch, so the mock
 * payload (and therefore every stored value) is identical across runs.
 */
function sse_iso(int $minutesAfter): string
{
    return gmdate('Y-m-d\TH:i:s\Z', 1790000000 + $minutesAfter * 60);
}

/**
 * Put the first seven linked units into a known starting state (inside the
 * child's transaction) and build the Samsara payload each role answers with.
 *
 * @return array{ids:int[], units:array<string,array>, trailers:array<string,array>, vehicles:array<string,array>, pages:array<int,string[]>}
 */
function sse_setup_fixtures(string $scenario): array
{
    $rows = db_select(
        "SELECT id, unit_number, samsara_vehicle_id FROM equipment_units
          WHERE samsara_vehicle_id IS NOT NULL AND samsara_vehicle_id <> '' AND deleted_at IS NULL
          ORDER BY id LIMIT 7"
    );
    if (count($rows) < 7) {
        fwrite(STDOUT, SSE_MARK . json_encode(['setup_error' => 'need >= 7 linked units, found ' . count($rows)]) . "\n");
        exit(2);
    }
    $units = [];
    foreach (SSE_ROLES as $i => $role) { $units[$role] = $rows[$i]; }

    // Known "before" values. Everything the cron writes is overwritten here so
    // the result does not depend on whatever the dev DB last synced.
    $base = [
        'samsara_battery_pct' => null, 'samsara_battery_charging' => null,
        'samsara_power_source' => null, 'samsara_check_in_mode' => null,
        'samsara_last_location_lat' => null, 'samsara_last_location_lng' => null,
        'samsara_last_location_address' => 'old address', 'samsara_last_speed_kph' => null,
        'samsara_last_connected_at' => '2001-01-01 00:00:00',
        'samsara_last_synced_at' => '2001-02-03 04:05:06',
        'samsara_odometer_km' => '1.00',
    ];
    $start = [
        'A' => ['samsara_last_location_lat' => '49.0000000', 'samsara_last_location_lng' => '-123.0000000', 'samsara_entity_type' => 'trailer'],
        'B' => ['samsara_last_location_lat' => '49.5000001', 'samsara_last_location_lng' => '-122.5000001', 'samsara_entity_type' => 'trailer'],
        'C' => ['samsara_last_location_lat' => '49.3000000', 'samsara_last_location_lng' => '-122.3000000', 'samsara_entity_type' => 'trailer'],
        'D' => ['samsara_last_location_lat' => '49.2000000', 'samsara_last_location_lng' => '-122.2000000', 'samsara_entity_type' => 'trailer'],
        'E' => ['samsara_entity_type' => 'vehicle'],
        'F' => ['samsara_entity_type' => 'vehicle'],
        'G' => ['samsara_entity_type' => 'trailer'],
    ];
    foreach ($units as $role => $u) {
        db_update('equipment_units', array_merge($base, $start[$role]), 'id = ?', [(int) $u['id']]);
    }

    $sid = static fn(string $r): string => (string) $units[$r]['samsara_vehicle_id'];
    $gps = static fn(float $lat, float $lng, ?int $t, string $addr, float $mph, float $hdg): array => array_filter([
        'time' => $t === null ? null : sse_iso($t), 'latitude' => $lat, 'longitude' => $lng,
        'headingDegrees' => $hdg, 'speedMilesPerHour' => $mph,
        'reverseGeo' => ['formattedLocation' => $addr],
    ], static fn($v) => $v !== null);

    $trailers = [
        $sid('A') => ['id' => $sid('A'), 'name' => 'SMOKE-A', 'gps' => $gps(49.1234567, -122.7654321, 1, '1 Moved Rd', 10.0, 181.6),
                      'gpsOdometerMeters' => ['time' => sse_iso(1), 'value' => 1234567]],
        $sid('B') => ['id' => $sid('B'), 'name' => 'SMOKE-B', 'gps' => $gps(49.5000001, -122.5000001, 2, '2 Parked Ave', 0.0, 0.0),
                      'gpsOdometerMeters' => ['time' => sse_iso(2), 'value' => 2000000]],
        '999000000000001' => ['id' => '999000000000001', 'name' => 'UNLINKED', 'gps' => $gps(50.0, -120.0, 3, 'Nowhere', 0.0, 0.0)],
        $sid('D') => ['id' => $sid('D'), 'name' => 'SMOKE-D', 'gpsOdometerMeters' => ['time' => sse_iso(4), 'value' => 3000000]],
        $sid('G') => ['id' => $sid('G'), 'name' => 'SMOKE-G', 'gps' => $gps(48.9876543, -123.4567891, null, '7 First Fix Way', 55.5, 90.0),
                      // dbfail: 1e11 km overflows DECIMAL(10,2) → the unit's UPDATE throws.
                      'gpsOdometerMeters' => ['time' => sse_iso(5), 'value' => $scenario === 'dbfail' ? 1.0E14 : 7000000]],
    ];
    $vehicles = [
        $sid('E') => ['id' => $sid('E'), 'name' => 'SMOKE-E', 'gps' => $gps(49.7777777, -122.1111111, 6, '5 Vehicle St', 30.0, 45.0),
                      'obdOdometerMeters' => ['time' => sse_iso(6), 'value' => 5000000],
                      'batteryLevelPercent' => ['value' => 15], 'chargingStatus' => ['value' => 'Charging'],
                      'powerSource' => ['value' => 'External'], 'checkInMode' => ['value' => 'Unpowered mode']],
        $sid('F') => ['id' => $sid('F'), 'name' => 'SMOKE-F', 'batteryLevelPercent' => ['value' => 5]],
    ];

    return [
        'ids'      => array_map(static fn($u) => (int) $u['id'], array_values($units)),
        'units'    => $units,
        'trailers' => $trailers,
        'vehicles' => $vehicles,
        // Bulk layout: page 1 = A, B + one unlinked trailer; page 2 = D, G. C is absent.
        'pages'    => [1 => [$sid('A'), $sid('B'), '999000000000001'], 2 => [$sid('D'), $sid('G')]],
    ];
}

/**
 * The mock Samsara API. Answers per-unit (?trailerIds= / ?vehicleIds=) and
 * fleet-wide paginated (?after=) current-stats calls from the fixture payload;
 * injects the scenario's failure; records every URL in $calls.
 */
function sse_make_transport(string $scenario, array $fix, array &$calls): callable
{
    $attempts = [];
    return static function (string $method, string $url) use ($scenario, $fix, &$calls, &$attempts): array {
        $calls[] = $url;
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $q);
        $path = (string) ($parts['path'] ?? '');
        $ok = static fn(array $data, bool $more = false, ?string $cursor = null): array => [
            'code' => 200,
            'body' => json_encode(['data' => $data, 'pagination' => ['endCursor' => $cursor ?? '', 'hasNextPage' => $more]]),
        ];

        if ($method !== 'GET' || ($parts['host'] ?? '') !== 'api.samsara.com') {
            return ['code' => 599, 'body' => '{"bad":"host"}'];
        }
        if ($path === '/fleet/vehicles/stats') {
            $ids = array_filter(explode(',', (string) ($q['vehicleIds'] ?? '')));
            return $ok(array_values(array_intersect_key($fix['vehicles'], array_flip($ids))));
        }
        if ($path !== '/fleet/trailers/stats') {
            return ['code' => 599, 'body' => '{"bad":"path"}'];
        }
        if (isset($q['trailerIds'])) {   // per-unit (pre-S-PERF-3 cron, sync-one)
            $ids = array_filter(explode(',', (string) $q['trailerIds']));
            return $ok(array_values(array_intersect_key($fix['trailers'], array_flip($ids))));
        }

        // Fleet-wide, paginated.
        $page = (($q['after'] ?? '') === 'cursor-p2') ? 2 : 1;
        $attempts[$page] = ($attempts[$page] ?? 0) + 1;
        if ($scenario === 'fatal') {
            throw new \RuntimeException('smoke: transport exploded');
        }
        if (($scenario === 'page1_fail' && $page === 1)
            || ($scenario === 'page2_fail' && $page === 2)
            || ($scenario === 'retry' && $page === 1 && $attempts[1] === 1)) {
            return ['code' => 503, 'body' => '{"message":"smoke: unavailable"}'];
        }
        $data = [];
        foreach ($fix['pages'][$page] as $id) { $data[] = $fix['trailers'][$id]; }
        return $page === 1 ? $ok($data, true, 'cursor-p2') : $ok($data);
    };
}

/** Highest id per table, so the report can pick out rows this run inserted. */
function sse_max_ids(): array
{
    $out = [];
    foreach (['samsara_location_history', 'notification_log', 'notifications', 'audit_log', 'attention_items'] as $t) {
        $out[$t] = (int) (db_row("SELECT COALESCE(MAX(id), 0) AS m FROM `$t`")['m'] ?? 0);
    }
    return $out;
}

/** md5 per NON-fixture linked unit over the columns the sync writes. */
function sse_other_units_digest(array $fixtureIds): array
{
    $rows = db_select(
        "SELECT id, CONCAT_WS('|', samsara_battery_pct, samsara_battery_charging, samsara_power_source,
                samsara_check_in_mode, samsara_last_location_lat, samsara_last_location_lng,
                samsara_last_location_address, samsara_last_speed_kph, samsara_last_connected_at,
                samsara_last_synced_at, samsara_odometer_km) AS sig
           FROM equipment_units
          WHERE samsara_vehicle_id IS NOT NULL AND samsara_vehicle_id <> '' AND deleted_at IS NULL"
    );
    $out = [];
    foreach ($rows as $r) {
        if (!in_array((int) $r['id'], $fixtureIds, true)) { $out[(int) $r['id']] = md5((string) $r['sig']); }
    }
    return $out;
}

/** Battery attention items as they stand (routing may update an existing row). */
function sse_attention_snapshot(): array
{
    return sse_normalise_rows(db_select("SELECT * FROM attention_items WHERE kind = 'gps_battery' ORDER BY id"));
}

/**
 * Drop ids and replace every *_at wall-clock stamp with <TS>, so two runs of
 * the same scenario compare byte-for-byte (a second boundary between two
 * writes must not look like a behaviour change). Callers that need to prove a
 * column got the tick's own $now token it as <NOW> themselves, first.
 */
function sse_normalise_rows(array $rows, array $keepTimeCols = []): array
{
    foreach ($rows as &$r) {
        unset($r['id']);
        foreach ($r as $k => $v) {
            if (in_array($k, $keepTimeCols, true) || $v === null) { continue; }
            if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $v) && str_ends_with((string) $k, '_at')) {
                $r[$k] = '<TS>';
            }
        }
    }
    unset($r);
    return $rows;
}

/** Everything a child reports back to the parent. Runs in the shutdown handler. */
function sse_collect_report(string $capturedOutput): array
{
    $g   = $GLOBALS;
    $fix = $g['SSE_FIX'];
    $max = $g['SSE_MAXID'];
    $now = (string) ($g['now'] ?? '');

    // Fixture units — every column the sync writes, keyed by role.
    $units = [];
    foreach ($fix['units'] as $role => $u) {
        $row = db_row(
            "SELECT samsara_battery_pct, samsara_battery_charging, samsara_power_source, samsara_check_in_mode,
                    samsara_last_location_lat, samsara_last_location_lng, samsara_last_location_address,
                    samsara_last_speed_kph, samsara_last_connected_at, samsara_last_synced_at, samsara_odometer_km
               FROM equipment_units WHERE id = ?",
            [(int) $u['id']]
        );
        if ($now !== '' && ($row['samsara_last_synced_at'] ?? null) === $now) { $row['samsara_last_synced_at'] = '<NOW>'; }
        // sync.php stamps each unit with its own ff_now_utc() (no shared tick
        // $now), so there any fresh stamp is just "stamped".
        if ($g['SSE_IS_API'] && ($row['samsara_last_synced_at'] ?? null) !== '2001-02-03 04:05:06') {
            $row['samsara_last_synced_at'] = '<NOW>';
        }
        $units[$role] = $row;
    }
    $roleOf = [];
    foreach ($fix['units'] as $role => $u) { $roleOf[(int) $u['id']] = $role; }

    $slh = db_select("SELECT * FROM samsara_location_history WHERE id > ? ORDER BY id", [$max['samsara_location_history']]);
    foreach ($slh as &$b) { $b['equipment_unit_id'] = $roleOf[(int) $b['equipment_unit_id']] ?? ('?' . $b['equipment_unit_id']); }
    unset($b);
    // recorded_at is Samsara's own time (deterministic) unless the gps block had
    // none, in which case the writer used its sync stamp — token that case.
    foreach ($slh as &$b) { if ($b['recorded_at'] === $b['synced_at']) { $b['recorded_at'] = '<NOW>'; } }
    unset($b);
    $slh = sse_normalise_rows($slh, ['recorded_at']);
    foreach ($slh as &$b) { if ($b['synced_at'] === '<TS>') { $b['synced_at'] = '<NOW>'; } }
    unset($b);

    $nlog = sse_normalise_rows(db_select(
        "SELECT channel, recipient, subject, body, entity_type, entity_id, notification_type, status, sent_at
           FROM notification_log WHERE id > ? ORDER BY id", [$max['notification_log']]));
    // sent_at is a fresh ff_now_utc() at dispatch, a second or so after $now.
    foreach ($nlog as &$n) {
        $n['entity_id'] = $roleOf[(int) $n['entity_id']] ?? $n['entity_id'];
        $n['sent_at']   = $n['sent_at'] === null ? null : '<TS>';
    }
    unset($n);

    $notifs = sse_normalise_rows(db_select(
        "SELECT user_id, title, message, type, category, url, entity_type, entity_id, severity
           FROM notifications WHERE id > ? ORDER BY id", [$max['notifications']]));

    $attnAfter = sse_attention_snapshot();

    $audit = db_select(
        "SELECT entity_label, module, notes FROM audit_log
          WHERE id > ? AND action = 'cron' AND entity_type = 'cron' ORDER BY id", [$max['audit_log']]);
    foreach ($audit as &$a) { $a['notes'] = preg_replace('/in [0-9.]+s:/', 'in <D>s:', (string) $a['notes']); }
    unset($a);

    $otherNow  = sse_other_units_digest($fix['ids']);
    $otherDiff = [];
    foreach ($g['SSE_OTHER'] as $id => $sig) { if (($otherNow[$id] ?? null) !== $sig) { $otherDiff[] = $id; } }

    clearstatcache();
    $log = '';
    if (is_file($g['SSE_LOG_PATH'])) {
        $log = (string) file_get_contents($g['SSE_LOG_PATH'], false, null, $g['SSE_LOG_OFFSET']);
    }

    // API response: stamp tokens + role names so it compares across runs.
    $api = null;
    if ($g['SSE_IS_API']) {
        $api = json_decode(trim($capturedOutput), true);
        foreach (['synced', 'failed'] as $k) {
            foreach ($api['data'][$k] ?? [] as $i => $r) {
                if (isset($r['synced_at'])) { $api['data'][$k][$i]['synced_at'] = '<TS>'; }
                $api['data'][$k][$i]['unit_id'] = $roleOf[(int) $r['unit_id']] ?? 'other';
                if (!isset($roleOf[(int) $r['unit_id']])) { $api['data'][$k][$i]['unit_number'] = 'other'; }
            }
        }
    }

    return [
        'scenario' => $g['SSE_SCENARIO'],
        // "effects" = what must be byte-identical before vs after S-PERF-3.
        'effects' => [
            'counts'        => $g['SSE_IS_API'] ? null : [
                'processed' => $g['processed'] ?? null, 'moved' => $g['movedUnit'] ?? null,
                'skipped'   => $g['skipped'] ?? null,   'failed' => $g['failed'] ?? null,
                'linked'    => isset($g['linked']) ? count($g['linked']) : null,
            ],
            'units'         => $units,
            'breadcrumbs'   => $slh,
            'notification_log' => $nlog,
            'notifications' => $notifs,
            'attention_changed' => $attnAfter !== $g['SSE_ATTN'],
            'attention_after'   => $attnAfter,
            'other_units_changed' => $otherDiff,
            'api'           => $api,
        ],
        // Intentionally different after S-PERF-3 (audit gating, log lines, HTTP calls).
        'audit'    => $audit,
        'stdout'   => $g['SSE_IS_API'] ? null : preg_replace('/in [0-9.]+s:/', 'in <D>s:', trim($capturedOutput)),
        'log'      => $log,
        'calls'    => $g['SSE_CALLS'],
        'unit_numbers' => array_map(static fn($u) => (string) $u['unit_number'], $fix['units']),
    ];
}

/**
 * Run one scenario in a child process.
 *
 * @return array{report:?array, exit:int, raw:string}
 */
function sse_run(string $scenario, ?int $minute = null, array $extra = []): array
{
    $cmd = [PHP_BINARY, __FILE__, '--child=' . $scenario];
    if ($minute !== null) { $cmd[] = '--minute=' . $minute; }
    foreach ($extra as $e) { $cmd[] = $e; }
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    $report = null;
    $pos = strrpos($out, SSE_MARK);
    if ($pos !== false) {
        $report = json_decode(trim(substr($out, $pos + strlen(SSE_MARK))), true);
    }
    return ['report' => $report, 'exit' => $code, 'raw' => $out . $err];
}

// =====================================================================
// PARENT MODE
// =====================================================================
require_once dirname(__DIR__) . '/config/app.php';

$failures = [];
$passes   = 0;
$pass  = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail  = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };
$check = static function (bool $c, string $m, string $why = '') use ($pass, $fail): void { $c ? $pass($m) : $fail($m . ($why !== '' ? " — {$why}" : '')); };

$sessDir = sys_get_temp_dir() . '/_ff_sse_sess_' . getmypid();
@mkdir($sessDir, 0700, true);
// The sync-all child mints one throwaway session file here; remove it on exit.
register_shutdown_function(static function () use ($sessDir): void {
    foreach (glob($sessDir . '/sess_*') ?: [] as $f) { @unlink($f); }
    @rmdir($sessDir);
});

// ── --emit: dump normalised effects for a before/after diff, no asserts ──
if (isset($opts['emit'])) {
    $dir = (string) $opts['emit'];
    @mkdir($dir, 0775, true);
    foreach ([['full', 17], ['dbfail', 17], ['api', null]] as [$sc, $min]) {
        $r = sse_run($sc, $min, $sc === 'api' ? ['--sessdir=' . $sessDir] : []);
        if ($r['report'] === null) { echo "EMIT {$sc}: no report\n{$r['raw']}\n"; exit(2); }
        file_put_contents("{$dir}/{$sc}.effects.json", json_encode($r['report']['effects'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        file_put_contents("{$dir}/{$sc}.full.json", json_encode($r['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        echo "EMIT {$sc}: exit={$r['exit']} calls=" . count($r['report']['calls']) . "\n";
    }
    exit(0);
}

echo str_repeat('─', 72) . "\n";
echo "S-PERF-3 SAMSARA SYNC EXEC — real cron + sync-all + cache_cleanup, mocked Samsara\n";
echo str_repeat('─', 72) . "\n";

$get = static function (string $sc, ?int $min = null, array $extra = []) use ($fail): ?array {
    $r = sse_run($sc, $min, $extra);
    if ($r['report'] === null || isset($r['report']['setup_error'])) {
        $fail("{$sc}: child produced no report (exit {$r['exit']}) " . substr($r['raw'], 0, 600));
        return null;
    }
    // Anchored: the cron's own "[SAMSARA_SYNC] Fatal error: …" error_log line
    // (fatal scenario, by design) must not count as a PHP error.
    if (preg_match('/(^|\n)(PHP )?(Fatal error|Parse error|Warning|Deprecated):|Uncaught |undefined function/', $r['raw'])) {
        $fail("{$sc}: PHP error in child output: " . substr($r['raw'], 0, 600));
    }
    $r['report']['_exit'] = $r['exit'];
    return $r['report'];
};
$badCalls = static fn(array $rep): array => array_values(array_filter(
    $rep['calls'],
    static fn($u) => !preg_match('#^https://api\.samsara\.com/fleet/(trailers|vehicles)/stats\?#', $u)
));
$bulkCalls = static fn(array $rep): int => count(array_filter(
    $rep['calls'], static fn($u) => str_contains($u, '/fleet/trailers/stats?') && !str_contains($u, 'trailerIds=')));
$perUnitTrailerCalls = static fn(array $rep): int => count(array_filter(
    $rep['calls'], static fn($u) => str_contains($u, 'trailerIds=')));

// ── 1. Normal tick, minute 17 (no heartbeat) ────────────────────────
echo "\n[1] normal tick (minute 17)\n";
$full = $get('full', 17);
if ($full) {
    $e = $full['effects'];
    $c = $e['counts'];
    $linked = (int) $c['linked'];
    $check($full['_exit'] === 0, '1a cron exits 0', "exit {$full['_exit']}");
    $check($badCalls($full) === [], '1b mock saw only current-stats URLs (no live leak)', implode(' ', $badCalls($full)));
    $check($bulkCalls($full) === 2 && $perUnitTrailerCalls($full) === 0,
        '1c trailers fetched fleet-wide: 2 paginated calls, 0 per-unit calls',
        'bulk=' . $bulkCalls($full) . ' per-unit=' . $perUnitTrailerCalls($full));
    $check($c['processed'] === 6 && $c['moved'] === 3 && $c['skipped'] === $linked - 6 && $c['failed'] === 0,
        "1d counts: 6 processed, 3 moved, " . ($linked - 6) . " skipped, 0 failed",
        json_encode($c));
    $u = $e['units'];
    $check($u['A']['samsara_last_location_lat'] === '49.1234567' && $u['A']['samsara_last_location_lng'] === '-122.7654321'
        && $u['A']['samsara_odometer_km'] === '1234.57' && $u['A']['samsara_last_speed_kph'] === '16.09'
        && $u['A']['samsara_last_connected_at'] === gmdate('Y-m-d H:i:s', 1790000000 + 60)
        && $u['A']['samsara_last_synced_at'] === '<NOW>',
        '1e moved trailer A: position, odometer, speed, connected_at (UTC) and synced_at written', json_encode($u['A']));
    $check($u['B']['samsara_last_synced_at'] === '<NOW>' && $u['B']['samsara_odometer_km'] === '2000.00',
        '1f parked trailer B: still stamped + telemetry written', json_encode($u['B']));
    $check($u['C']['samsara_last_synced_at'] === '2001-02-03 04:05:06' && $u['C']['samsara_last_location_lat'] === '49.3000000'
        && $u['C']['samsara_odometer_km'] === '1.00',
        '1g trailer C missing from Samsara: untouched, synced_at NOT stamped', json_encode($u['C']));
    $check($u['D']['samsara_last_synced_at'] === '<NOW>' && $u['D']['samsara_last_location_lat'] === null
        && $u['D']['samsara_odometer_km'] === '3000.00',
        '1h gps-null trailer D: written exactly as before (position cleared, odometer set)', json_encode($u['D']));
    $check($u['E']['samsara_battery_pct'] === 15 || $u['E']['samsara_battery_pct'] === '15',
        '1i vehicle E: battery 15% written (vehicle path still per-unit)', json_encode($u['E']));
    $check($u['E']['samsara_power_source'] === 'External' && $u['E']['samsara_check_in_mode'] === 'Unpowered mode'
        && (string) $u['E']['samsara_battery_charging'] === '1',
        '1j vehicle E: power_source / check_in_mode / battery_charging written', json_encode($u['E']));
    $crumbs = array_column($e['breadcrumbs'], 'equipment_unit_id');
    sort($crumbs);
    $check($crumbs === ['A', 'E', 'G'], '1k breadcrumbs only for moved units A, E, G (not parked B / gps-null D / missing C)', json_encode($crumbs));
    $gCrumb = array_values(array_filter($e['breadcrumbs'], static fn($b) => $b['equipment_unit_id'] === 'G'))[0] ?? [];
    $check(($gCrumb['recorded_at'] ?? '') === '<NOW>' && ($gCrumb['synced_at'] ?? '') === '<NOW>',
        '1l breadcrumb with no gps.time falls back to the tick $now (UTC)', json_encode($gCrumb));
    $types = array_column($e['notification_log'], 'notification_type', 'entity_id');
    $check(($types['E'] ?? '') === 'samsara.battery_low' && ($types['F'] ?? '') === 'samsara.battery_critical' && count($types) === 2,
        '1m battery alerts: E low + F critical logged to notification_log', json_encode($types));
    $check($e['other_units_changed'] === [], '1n no non-fixture linked unit was written or stamped', count($e['other_units_changed']) . ' changed');
    $check($full['audit'] === [], '1o clean tick outside minute 0-4 writes NO audit_log heartbeat', json_encode($full['audit']));
    $check(substr_count($full['log'], '[CRON_END]') === 1, '1p gps.log still gets CRON_END');
    $skipLines = preg_match_all('/\[CRON_SKIP\][^\n]*/', $full['log'], $m) ? $m[0] : [];
    $unitC = $full['unit_numbers']['C'];
    $check(count($skipLines) === 1 && str_contains($skipLines[0], $unitC) && !str_contains($full['log'], 'GPS_TRAILER_STATS_NO_MATCH'),
        "1q ONE CRON_SKIP line lists the missing trailers (incl. {$unitC}); no per-unit NO_MATCH lines",
        count($skipLines) . ' skip lines');
    $check(str_starts_with((string) $full['stdout'], '[SAMSARA_SYNC] Samsara sync done in <D>s: 6 processed, 3 moved,'),
        '1r cron stdout summary line unchanged in format', (string) $full['stdout']);
}

// ── 2. Same tick at minute 2 → the hourly heartbeat row ────────────
echo "\n[2] hourly heartbeat (minute 2)\n";
$hb = $get('full', 2);
if ($hb && $full) {
    $check(count($hb['audit']) === 1 && $hb['audit'][0]['entity_label'] === 'samsara_sync'
        && str_starts_with($hb['audit'][0]['notes'], 'Samsara sync done in <D>s: 6 processed'),
        '2a minute 0-4 tick writes exactly one samsara_sync audit row with the summary', json_encode($hb['audit']));
    $check($hb['effects'] === $full['effects'], '2b DB effects identical to the minute-17 tick (only the audit row differs)');
}

// ── 3. One unit fails (DB error) → audit row even at minute 17 ─────
echo "\n[3] per-unit DB failure (minute 17)\n";
$dbf = $get('dbfail', 17);
if ($dbf) {
    $c = $dbf['effects']['counts'];
    $check($c['failed'] === 1 && $c['processed'] === 5, '3a unit G fails in isolation, the rest still sync', json_encode($c));
    $check($dbf['effects']['units']['G']['samsara_last_synced_at'] === '2001-02-03 04:05:06', '3b failed unit G not stamped');
    $check(count($dbf['audit']) === 1 && str_contains($dbf['audit'][0]['notes'], '1 failed'),
        '3c failed>0 writes the audit row outside the heartbeat minute', json_encode($dbf['audit']));
    $check(str_contains($dbf['log'], '[CRON_FAIL]'), '3d CRON_FAIL logged for the failing unit');
}

// ── 4. Page 1 fails (retried once, still 503) → no trailer stamped ─
echo "\n[4] bulk page 1 fails\n";
$p1 = $get('page1_fail', 17);
if ($p1) {
    $c = $p1['effects']['counts'];
    $u = $p1['effects']['units'];
    $stampedTrailers = array_filter(['A', 'B', 'C', 'D', 'G'], static fn($r) => $u[$r]['samsara_last_synced_at'] !== '2001-02-03 04:05:06');
    $check($stampedTrailers === [] && $p1['effects']['other_units_changed'] === [],
        '4a no trailer written or stamped when page 1 fails', json_encode(array_values($stampedTrailers)));
    $check($c['processed'] === 2 && $u['E']['samsara_last_synced_at'] === '<NOW>',
        '4b vehicles (per-unit path) still sync: 2 processed', json_encode($c));
    $check($bulkCalls($p1) === 2 && str_contains($p1['log'], 'SAMSARA_HISTORY_RETRY'),
        '4c page 1 retried exactly once (2 attempts)', 'bulk calls ' . $bulkCalls($p1));
    $check(count($p1['audit']) === 1 && str_contains($p1['log'], 'CRON_BULK_INCOMPLETE')
        && str_contains((string) $p1['audit'][0]['notes'], 'trailer stats fetch incomplete'),
        '4d incomplete trailer fetch is logged and writes the audit row (notes say why)', json_encode($p1['audit']));
}

// ── 5. Page 2 fails → only page-1 trailers written ─────────────────
echo "\n[5] bulk page 2 fails (partial map)\n";
$p2 = $get('page2_fail', 17);
if ($p2) {
    $u = $p2['effects']['units'];
    $check($u['A']['samsara_last_synced_at'] === '<NOW>' && $u['B']['samsara_last_synced_at'] === '<NOW>',
        '5a page-1 trailers A, B written');
    $check($u['D']['samsara_last_synced_at'] === '2001-02-03 04:05:06' && $u['G']['samsara_last_synced_at'] === '2001-02-03 04:05:06'
        && $u['D']['samsara_odometer_km'] === '1.00' && $u['G']['samsara_last_location_lat'] === null,
        '5b page-2 trailers D, G NOT written or stamped (partial map ≠ "unchanged")', json_encode([$u['D'], $u['G']]));
    $check($p2['effects']['counts']['processed'] === 4, '5c 4 processed (A, B, E, F)', json_encode($p2['effects']['counts']));
}

// ── 6. Transient 503 on page 1 → the retry recovers the whole tick ─
echo "\n[6] transient 503, retry recovers\n";
$rt = $get('retry', 17);
if ($rt && $full) {
    $check($rt['effects'] === $full['effects'], '6a DB effects identical to a clean tick');
    $check($bulkCalls($rt) === 3, '6b 3 bulk calls (page 1 twice, page 2 once)', 'bulk calls ' . $bulkCalls($rt));
}

// ── 7. Fatal path → exit 1 + FAILED audit row, nothing stamped ─────
echo "\n[7] fatal path\n";
$fa = $get('fatal', 17);
if ($fa) {
    $check($fa['_exit'] === 1, '7a cron exits 1 on an unexpected exception', "exit {$fa['_exit']}");
    $check(count($fa['audit']) === 1 && str_starts_with($fa['audit'][0]['notes'], 'Samsara sync FAILED: smoke: transport exploded'),
        '7b fatal path still writes its audit row', json_encode($fa['audit']));
    $stamped = array_filter($fa['effects']['units'], static fn($r) => $r['samsara_last_synced_at'] !== '2001-02-03 04:05:06');
    $check($stamped === [] && str_contains($fa['log'], '[CRON_FATAL]'), '7c nothing stamped; CRON_FATAL logged');
}

// ── 8. api/v1/samsara/sync.php sync-all uses the same fleet-wide map ──
echo "\n[8] Refresh now (sync-all endpoint)\n";
$api = $get('api', null, ['--sessdir=' . $sessDir]);
if ($api) {
    $resp = $api['effects']['api'];
    $check(($resp['success'] ?? false) === true && isset($resp['data']['synced_count'], $resp['data']['failed_count'], $resp['data']['synced'], $resp['data']['failed']),
        '8a response envelope + shape unchanged (data.synced_count/failed_count/synced/failed)', substr(json_encode($resp), 0, 300));
    $check(($resp['data']['synced_count'] ?? -1) === 6, '8b 6 units synced (A, B, D, E, F, G)', (string) ($resp['data']['synced_count'] ?? '?'));
    $syncedA = array_values(array_filter($resp['data']['synced'] ?? [], static fn($r) => $r['unit_id'] === 'A'))[0] ?? [];
    $check(($syncedA['stats']['odometer_km'] ?? null) === 1234.57 && ($syncedA['stats']['gps']['lat'] ?? null) === 49.1234567,
        '8c per-unit stats payload still returned', json_encode($syncedA['stats'] ?? null));
    $failedC = array_values(array_filter($resp['data']['failed'] ?? [], static fn($r) => $r['unit_id'] === 'C'))[0] ?? [];
    $check(($failedC['error'] ?? '') === 'Samsara returned no data for this trailer.', '8d missing trailer reported as before');
    $check($bulkCalls($api) === 2 && $perUnitTrailerCalls($api) === 0 && $badCalls($api) === [],
        '8e sync-all made 2 fleet-wide calls, 0 per-unit trailer calls', 'bulk=' . $bulkCalls($api) . ' per-unit=' . $perUnitTrailerCalls($api));
    $check($api['effects']['units']['C']['samsara_last_synced_at'] === '2001-02-03 04:05:06'
        && $api['effects']['units']['A']['samsara_last_synced_at'] !== '2001-02-03 04:05:06',
        '8f sync-all: missing trailer not stamped, present trailer written');
}

// ── 9. cache_cleanup: audit row only when something was deleted ─────
echo "\n[9] cache_cleanup audit gating\n";
$cacheRun = static function (bool $seedExpired): array {
    // Inline child: the cron is a script, so it runs in its own process inside
    // one rolled-back transaction, exactly like the samsara scenarios.
    $code = '<?php require ' . var_export(FF_ROOT . '/config/app.php', true) . ';'
        . 'db_pdo()->beginTransaction();'
        . '$max = (int) db_row("SELECT COALESCE(MAX(id),0) m FROM audit_log")["m"];'
        // Pre-clear what the cron would delete so the "nothing to do" run is deterministic.
        . 'db_execute("DELETE FROM report_cache WHERE expires_at < NOW()", []);'
        . 'db_execute("DELETE FROM ai_summaries WHERE expires_at IS NOT NULL AND expires_at < NOW()", []);'
        . 'db_execute("DELETE FROM rate_limit_attempts WHERE window_start < DATE_SUB(NOW(), INTERVAL 7 DAY)", []);'
        . ($seedExpired
            ? 'db_insert("report_cache", ["report_type"=>"smoke_sse","parameters_hash"=>md5("sse"),"parameters"=>"{}","result_data"=>"{}","expires_at"=>ff_now_utc("-1 hour")]);'
            : '')
        . 'register_shutdown_function(function () use ($max) {'
        . '  $rows = db_select("SELECT notes FROM audit_log WHERE id > ? AND entity_label = \'cache_cleanup\'", [$max]);'
        . '  db_pdo()->rollBack();'
        . '  echo "\n' . SSE_MARK . '", json_encode($rows), "\n";'
        . '});'
        . 'require ' . var_export(FF_ROOT . '/cron/cache_cleanup.php', true) . ';';
    // tempnam() creates the placeholder file itself; the child script lives
    // beside it with a .php suffix, so BOTH must be removed afterwards.
    $tmp = (string) tempnam(sys_get_temp_dir(), 'sse_cache_');
    $f   = $tmp . '.php';
    file_put_contents($f, $code);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    @unlink($f);
    @unlink($tmp);
    $raw = implode("\n", $out);
    $pos = strrpos($raw, SSE_MARK);
    return ['rows' => $pos === false ? null : json_decode(trim(substr($raw, $pos + strlen(SSE_MARK))), true), 'rc' => $rc, 'raw' => $raw];
};
$zero = $cacheRun(false);
$check($zero['rc'] === 0 && $zero['rows'] === [], '9a nothing expired → NO cache_cleanup audit row', substr($zero['raw'], 0, 300));
$some = $cacheRun(true);
$check($some['rc'] === 0 && is_array($some['rows']) && count($some['rows']) === 1
    && str_starts_with((string) ($some['rows'][0]['notes'] ?? ''), 'Cache cleanup: report_cache=1,'),
    '9b one expired row → audit row written with the count', substr($some['raw'], 0, 300));

// ── 10. notification_digest: gate helper + wiring ───────────────────
echo "\n[10] notification_digest audit gating\n";
define('FF_NOTIFICATION_DIGEST_INCLUDE', true);
require_once FF_ROOT . '/cron/notification_digest.php';
if (!function_exists('digest_audit_row_due')) {
    $fail('10a digest_audit_row_due() not defined');
} else {
    $zeros = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
    $check(digest_audit_row_due(false, $zeros) === false, '10a all-zero, non-forced hour → no audit row');
    $ok = true;
    for ($i = 0; $i < 10; $i++) { $v = $zeros; $v[$i] = 1; $ok = $ok && digest_audit_row_due(false, $v) === true; }
    $check($ok, '10b ANY non-zero count (sent, skipped or errors in any section) → audit row');
    $check(digest_audit_row_due(true, $zeros) === true, '10c a forced (manual) run always leaves its row');
    $src = (string) file_get_contents(FF_ROOT . '/cron/notification_digest.php');
    $check((bool) preg_match('/if\s*\(\s*digest_audit_row_due\(\s*\$forced\s*,/', $src)
        && substr_count($src, "'notification_digest fatal error: '") === 1,
        '10d success insert gated on the helper; fatal-path insert untouched');
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("SAMSARA SYNC EXEC — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
