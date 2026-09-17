<?php
declare(strict_types=1);

/**
 * tests/_smoke_samsara_business_day_window.php — S-GPS-LOCAL-WINDOW
 *
 * Proves every business-date → Samsara window conversion is anchored on
 * COMPANY-LOCAL midnight (America/Vancouver), not UTC midnight, and that the
 * exact UTC startTime/endTime sent to Samsara is right in both a PDT month
 * (UTC-7) and a PST month (UTC-8).
 *
 * The bug: InvoiceGenerator and SamsaraClient::getMileageForLease built the
 * window as `$date 00:00:00 UTC` → `$date 23:59:59 UTC`, so a Pacific
 * "Sep 1–Sep 30" invoice really covered Aug 31 5pm → Sep 30 5pm local and the
 * last evening's driving billed on the NEXT invoice.
 *
 * Tiers:
 *   PART A (A1–A8) — BusinessDayWindow pure math: PDT, PST, both DST
 *                    transitions, contiguity, strict input rejection, and
 *                    default-timezone resolution.
 *   PART B (B1–B4) — SamsaraClient with a MOCKED HTTP transport
 *                    (setHttpTransportForTesting): exact startTime/endTime on
 *                    the vehicle odometer query and the trailer GPS-fallback
 *                    query (which uses the un-widened period bounds), plus the
 *                    evening-of-last-day reading landing in the right period.
 *   PART C (C1–C2) — InvoiceGenerator::createFromLease end-to-end (samsara
 *                    mileage_tracking_mode) inside BEGIN/ROLLBACK: the invoice
 *                    path sends the same local window, persists the
 *                    local-window distance, and clamps the OBD feed's
 *                    source='obd' to the odometer_source enum ('gps') — the
 *                    unclamped value was a STRICT 1265 that aborted the invoice.
 *   PART D (D1)    — Source wiring: no business-date caller builds a UTC-midnight
 *                    window any more; mileage.php + period_distance.php use the
 *                    shared helper / business "today".
 *
 * NEVER touches the live Samsara API: the transport override replaces cURL for
 * every history request, and any URL that is not a stats/history call fails
 * the run. fixture_mode is forced to '0' (so the real request builder runs)
 * and restored afterwards.
 *
 * Run: php tests/_smoke_samsara_business_day_window.php
 * Exit 0 on all-pass, 1 on any failure.
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\GPS\BusinessDayWindow;
use FleetForge\GPS\SamsaraClient;

$pass = 0;
$fail = 0;

/** Record + print one assertion. */
function check(string $id, string $name, bool $ok, string $msg): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS $id  $name — $msg\n"; }
    else     { $fail++; echo "  FAIL $id  $name — $msg\n"; }
}

/** Format an instant as the ISO-Z string used in assertions. */
function z(\DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** Pull one query parameter out of a captured Samsara URL. */
function qparam(string $url, string $key): ?string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    return isset($q[$key]) ? (string) $q[$key] : null;
}

echo "FleetForge — Samsara business-day window smoke (S-GPS-LOCAL-WINDOW)\n";
echo str_repeat('=', 74) . "\n";

$van = new \DateTimeZone('America/Vancouver');

// =====================================================================
// PART A — BusinessDayWindow pure math
// =====================================================================
$w = BusinessDayWindow::toUtc('2026-09-01', '2026-09-30', $van);
check('A1', 'PDT month (UTC-7)',
    z($w['start']) === '2026-09-01T07:00:00Z' && z($w['end']) === '2026-10-01T07:00:00Z',
    z($w['start']) . ' → ' . z($w['end']) . ' (expect 2026-09-01T07:00:00Z → 2026-10-01T07:00:00Z)');

$w = BusinessDayWindow::toUtc('2026-01-01', '2026-01-31', $van);
check('A2', 'PST month (UTC-8)',
    z($w['start']) === '2026-01-01T08:00:00Z' && z($w['end']) === '2026-02-01T08:00:00Z',
    z($w['start']) . ' → ' . z($w['end']) . ' (expect 2026-01-01T08:00:00Z → 2026-02-01T08:00:00Z)');

// DST ends 2026-11-01 02:00 local: Nov 1 midnight is still PDT, Dec 1 is PST.
$w = BusinessDayWindow::toUtc('2026-11-01', '2026-11-30', $van);
check('A3', 'fall-back month straddles PDT→PST',
    z($w['start']) === '2026-11-01T07:00:00Z' && z($w['end']) === '2026-12-01T08:00:00Z',
    z($w['start']) . ' → ' . z($w['end']));

// DST starts 2026-03-08 02:00 local: that local day is 23 hours long.
$w = BusinessDayWindow::toUtc('2026-03-08', '2026-03-08', $van);
$hrs = ($w['end']->getTimestamp() - $w['start']->getTimestamp()) / 3600;
check('A4', 'spring-forward day is 23h',
    z($w['start']) === '2026-03-08T08:00:00Z' && z($w['end']) === '2026-03-09T07:00:00Z' && $hrs === 23,
    z($w['start']) . ' → ' . z($w['end']) . " ({$hrs}h)");

// Contiguous periods partition time exactly — no 1-second gap, no overlap.
$aug = BusinessDayWindow::toUtc('2026-08-01', '2026-08-31', $van);
$sep = BusinessDayWindow::toUtc('2026-09-01', '2026-09-30', $van);
check('A5', 'contiguous periods share the boundary instant',
    $aug['end'] == $sep['start'],
    'Aug end ' . z($aug['end']) . ' == Sep start ' . z($sep['start']));

$w = BusinessDayWindow::toUtc('2026-07-15', '2026-07-15', $van);
check('A6', 'single ordinary day is 24h',
    ($w['end']->getTimestamp() - $w['start']->getTimestamp()) === 86400,
    z($w['start']) . ' → ' . z($w['end']));

$rejected = [];
foreach ([['2026-02-30', '2026-03-01'], ['2026/09/01', '2026-09-30'], ['2026-09-30', '2026-09-01'], ['', '2026-09-01']] as [$s, $e]) {
    try {
        BusinessDayWindow::toUtc($s, $e, $van);
    } catch (\InvalidArgumentException) {
        $rejected[] = "{$s}..{$e}";
    }
}
check('A7', 'strict input: overflow / wrong format / inverted / empty rejected',
    count($rejected) === 4, count($rejected) . '/4 rejected');

$expectTz = (string) (settings_get('company.timezone', APP_TIMEZONE) ?: APP_TIMEZONE);
check('A8', 'default zone = company.timezone → APP_TIMEZONE',
    BusinessDayWindow::timezone()->getName() === $expectTz,
    'resolved ' . BusinessDayWindow::timezone()->getName() . " (expect {$expectTz})");

// =====================================================================
// PART B / C shared: mocked Samsara transport
// =====================================================================
/**
 * Fake odometer history (vehicle obdOdometerMeters) for one Pacific month,
 * built so the UTC-midnight bug and the local-midnight fix give DIFFERENT
 * answers. Readings (local time → metres):
 *   start-of-period 00:00                → 1,000,000
 *   mid-period                           → 1,200,000
 *   last day 16:00                       → 1,400,000   (before the old UTC-midnight cutoff in PDT)
 *   last day 18:30                       → 1,450,000   (after it: 5pm PDT / 4pm PST)
 *   last day 20:00                       → 1,480,000
 *   next day 00:00 (local boundary)      → 1,500,000
 *   next day 10:00                       → 1,600,000
 * Local-window distance = 1,500,000 − 1,000,000 = 500.00 km.
 */
function fake_odometer_month(string $first, string $last, \DateTimeZone $tz): array
{
    $mk = fn (string $local) => (new \DateTimeImmutable($local, $tz))->setTimezone(new \DateTimeZone('UTC'));
    $next = (new \DateTimeImmutable($last, $tz))->modify('+1 day')->format('Y-m-d');
    $mid  = substr($first, 0, 8) . '15';
    return [
        [$mk("{$first} 00:00:00"), 1000000],
        [$mk("{$mid} 12:00:00"),   1200000],
        [$mk("{$last} 16:00:00"),  1400000],
        [$mk("{$last} 18:30:00"),  1450000],
        [$mk("{$last} 20:00:00"),  1480000],
        [$mk("{$next} 00:00:00"),  1500000],
        [$mk("{$next} 10:00:00"),  1600000],
    ];
}

$mockCalls   = [];      // every URL the client tried to fetch
$mockVehicle = [];      // samsara id => readings for obdOdometerMeters
$mockTrailer = [];      // samsara id => [[instant, lat, lon], ...] for types=gps
$badUrls     = [];

SamsaraClient::setHttpTransportForTesting(
    function (string $method, string $url) use (&$mockCalls, &$mockVehicle, &$mockTrailer, &$badUrls): array {
        $mockCalls[] = $url;
        if (!preg_match('#^https://api\.samsara\.com/fleet/(vehicles|trailers)/stats/history\?#', $url)) {
            $badUrls[] = $url;
            return ['code' => 500, 'body' => '{}'];
        }
        $from = strtotime((string) qparam($url, 'startTime'));
        $to   = strtotime((string) qparam($url, 'endTime'));
        $in   = fn (\DateTimeImmutable $t) => $t->getTimestamp() >= $from && $t->getTimestamp() <= $to;

        // Vehicle odometer history — return only readings inside the window,
        // exactly like the real endpoint.
        if (str_contains($url, '/fleet/vehicles/')) {
            $id = (string) qparam($url, 'vehicleIds');
            $pts = [];
            foreach ($mockVehicle[$id] ?? [] as [$t, $m]) {
                if ($in($t)) $pts[] = ['time' => $t->format('Y-m-d\TH:i:s.000\Z'), 'value' => $m];
            }
            $data = $pts ? [['id' => $id, 'obdOdometerMeters' => $pts]] : [];
            return ['code' => 200, 'body' => json_encode(['data' => $data, 'pagination' => ['hasNextPage' => false]])];
        }

        // Trailers: no odometer feed at all (forces the GPS-position fallback);
        // types=gps returns the canned track inside the window.
        $id = (string) qparam($url, 'trailerIds');
        if (qparam($url, 'types') !== 'gps') {
            return ['code' => 200, 'body' => json_encode(['data' => [], 'pagination' => ['hasNextPage' => false]])];
        }
        $pts = [];
        foreach ($mockTrailer[$id] ?? [] as [$t, $lat, $lon]) {
            if ($in($t)) $pts[] = ['time' => $t->format('Y-m-d\TH:i:s.000\Z'), 'latitude' => $lat, 'longitude' => $lon];
        }
        $data = $pts ? [['id' => $id, 'gps' => $pts]] : [];
        return ['code' => 200, 'body' => json_encode(['data' => $data, 'pagination' => ['hasNextPage' => false]])];
    }
);

// The real request builder only runs with fixture mode OFF and a non-empty
// token. The token is never sent anywhere — the transport above swallows it.
$origFixture = (string) settings_get('samsara.fixture_mode');
$_ENV['SAMSARA_API_TOKEN'] = $_ENV['SAMSARA_API_TOKEN'] ?? 'smoke-mock-token';

db_execute("BEGIN");
try {
    db_execute("UPDATE settings SET value = '0' WHERE `key` = 'samsara.fixture_mode'");

    // =================================================================
    // PART B — SamsaraClient request windows (mocked HTTP)
    // =================================================================
    $client = new SamsaraClient();

    // B1 — PDT month through getMileageForLease (the close-form pre-fill path).
    $mockVehicle['SMOKE_WIN_VEH'] = fake_odometer_month('2026-09-01', '2026-09-30', $van);
    $mockCalls = [];
    $km = $client->getMileageForLease('SMOKE_WIN_VEH', '2026-09-01', '2026-09-30');
    $u  = $mockCalls[0] ?? '';
    check('B1', 'PDT getMileageForLease exact UTC window',
        qparam($u, 'startTime') === '2026-08-31T07:00:00.000Z'
        && qparam($u, 'endTime') === '2026-10-02T07:00:00.000Z'
        && $km !== null && abs($km - 500.0) < 0.001,
        'startTime=' . qparam($u, 'startTime') . ' endTime=' . qparam($u, 'endTime')
        . ' (period Sep 1 07:00Z → Oct 1 07:00Z ±24h bookend margin) distance=' . var_export($km, true) . ' km (expect 500)');

    // B2 — the same data through the OLD UTC-midnight anchors: proves the fix
    // matters (last-evening driving after 5pm local fell into October).
    $oldUtc = $client->getDistanceForPeriod('SMOKE_WIN_VEH',
        new \DateTimeImmutable('2026-09-01 00:00:00', new \DateTimeZone('UTC')),
        new \DateTimeImmutable('2026-09-30 23:59:59', new \DateTimeZone('UTC')), 'km');
    check('B2', 'old UTC anchors under-bill the last evening',
        ($oldUtc['distance'] ?? null) === '450.00',
        'UTC-midnight window distance=' . ($oldUtc['distance'] ?? 'null') . ' km vs local 500.00 (expect 450.00)');

    // B3 — PST month.
    $mockVehicle['SMOKE_WIN_VEH'] = fake_odometer_month('2026-01-01', '2026-01-31', $van);
    $mockCalls = [];
    $km = $client->getMileageForLease('SMOKE_WIN_VEH', '2026-01-01', '2026-01-31');
    $u  = $mockCalls[0] ?? '';
    check('B3', 'PST getMileageForLease exact UTC window',
        qparam($u, 'startTime') === '2025-12-31T08:00:00.000Z'
        && qparam($u, 'endTime') === '2026-02-02T08:00:00.000Z'
        && $km !== null && abs($km - 500.0) < 0.001,
        'startTime=' . qparam($u, 'startTime') . ' endTime=' . qparam($u, 'endTime') . ' distance=' . var_export($km, true));

    // B4 — trailer GPS-position fallback uses the UN-widened period bounds, so
    // it is the most direct read of the window itself. Track: ~1 km hops every
    // 2h across the month, including hops after 5pm on the last local day.
    $mkTrack = function (string $first, string $last) use ($van): array {
        $t   = new \DateTimeImmutable("{$first} 00:00:00", $van);
        $end = (new \DateTimeImmutable("{$last} 00:00:00", $van))->modify('+1 day');
        $out = []; $i = 0;
        while ($t <= $end) {
            $out[] = [$t->setTimezone(new \DateTimeZone('UTC')), 49.0 + 0.009 * ($i % 2), -122.0];
            $t = $t->modify('+2 hours'); $i++;
        }
        return $out;
    };
    $trailerCases = [
        'PDT' => ['2026-09-01', '2026-09-30', '2026-09-01T07:00:00.000Z', '2026-10-01T07:00:00.000Z'],
        'PST' => ['2026-01-01', '2026-01-31', '2026-01-01T08:00:00.000Z', '2026-02-01T08:00:00.000Z'],
    ];
    $b4Msgs = []; $b4Ok = true;
    foreach ($trailerCases as $label => [$ps, $pe, $expStart, $expEnd]) {
        $mockTrailer['SMOKE_WIN_TRL'] = $mkTrack($ps, $pe);
        $mockCalls = [];
        $km = $client->getMileageForLease('SMOKE_WIN_TRL', $ps, $pe, 'trailer');
        $gpsUrl = '';
        foreach ($mockCalls as $c) {
            if (qparam($c, 'types') === 'gps') { $gpsUrl = $c; break; }
        }
        $ok = qparam($gpsUrl, 'startTime') === $expStart && qparam($gpsUrl, 'endTime') === $expEnd && $km !== null;
        $b4Ok = $b4Ok && $ok;
        $b4Msgs[] = "{$label} gps startTime=" . qparam($gpsUrl, 'startTime') . ' endTime=' . qparam($gpsUrl, 'endTime')
            . ' km=' . var_export($km, true);
    }
    check('B4', 'trailer GPS fallback exact period bounds (PDT+PST)', $b4Ok, implode(' | ', $b4Msgs));

    // =================================================================
    // PART C — InvoiceGenerator end-to-end (samsara mode)
    // =================================================================
    $cust = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $unit = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $user = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if (!$cust || !$unit || !$user) {
        throw new \RuntimeException("Missing seed data (customer=$cust unit=$unit user=$user)");
    }
    db_execute(
        "UPDATE equipment_units SET samsara_vehicle_id = 'SMOKE_WIN_VEH', samsara_entity_type = 'vehicle' WHERE id = ?",
        [$unit]
    );
    // S-SMOKE-SAMSARA-HERMETIC: lift the counter above committed MAX for every
    // year an invoice may be numbered in (reverted by ROLLBACK).
    foreach (array_unique([date('Y'), '2026']) as $yr) {
        $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
        $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
        db_execute(
            "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ["invoice.next_number.{$yr}", (string) ($maxNum + 100)]
        );
    }

    $gen = new \FleetForge\Billing\InvoiceGenerator();
    // Past periods only — InvoiceGenerator never fetches for a future period end.
    $invoiceCases = [
        'C1' => ['PDT', '2026-08-01', '2026-08-31', '2026-07-31T07:00:00.000Z', '2026-09-02T07:00:00.000Z'],
        'C2' => ['PST', '2026-01-01', '2026-01-31', '2025-12-31T08:00:00.000Z', '2026-02-02T08:00:00.000Z'],
    ];
    foreach ($invoiceCases as $cid => [$label, $ps, $pe, $expStart, $expEnd]) {
        $mockVehicle['SMOKE_WIN_VEH'] = fake_odometer_month($ps, $pe, $van);
        $leaseId = db_insert('leases', [
            'contract_number'       => "SMOKE-GPSWIN-{$cid}",
            'customer_id'           => $cust,
            'equipment_unit_id'     => $unit,
            'start_date'            => $ps,
            'status'                => 'active',
            'daily_rate'            => '10.00',
            'weekly_rate'           => '60.00',
            'monthly_rate'          => '250.00',
            'mileage_unit'          => 'km',
            'mileage_rate_km'       => '0.18',
            'currency'              => 'CAD',
            'billing_cycle'         => 'monthly',
            'mileage_tracking_mode' => 'samsara',   // THE Samsara gate
            'created_by'            => $user,
            'updated_by'            => $user,
        ]);
        $mockCalls = [];
        $inv = $gen->createFromLease([
            'lease_id' => $leaseId, 'period_start' => $ps, 'period_end' => $pe,
            'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => $user,
        ]);
        $row = db_row("SELECT period_distance_km, odometer_source FROM invoices WHERE id = ?", [$inv['invoice_id']]);
        $u   = $mockCalls[0] ?? '';
        check($cid, "{$label} invoice sends local window + bills local distance",
            count($mockCalls) >= 1
            && qparam($u, 'startTime') === $expStart
            && qparam($u, 'endTime') === $expEnd
            && (string) ($row['period_distance_km'] ?? '') === '500.00'
            // OBD feed must be clamped into enum('gps','manual','estimated').
            && ($row['odometer_source'] ?? null) === 'gps',
            "period {$ps}..{$pe} startTime=" . qparam($u, 'startTime') . ' endTime=' . qparam($u, 'endTime')
            . ' period_distance_km=' . ($row['period_distance_km'] ?? 'null')
            . ' odometer_source=' . ($row['odometer_source'] ?? 'null') . ' (expect 500.00 / gps)');
    }
} catch (\Throwable $ex) {
    check('EX', 'unexpected exception', false, get_class($ex) . ': ' . $ex->getMessage());
} finally {
    db_execute("ROLLBACK");
    SamsaraClient::setHttpTransportForTesting(null);
    // ROLLBACK already restores fixture_mode; re-assert it defensively.
    if ((string) settings_get('samsara.fixture_mode') !== $origFixture) {
        db_execute("UPDATE settings SET value = ? WHERE `key` = 'samsara.fixture_mode'", [$origFixture === '' ? '0' : $origFixture]);
    }
}

check('M1', 'mock transport saw only stats/history URLs (no live leak path)',
    $badUrls === [], count($badUrls) . ' unexpected URL(s)');

// =====================================================================
// PART D — source wiring: no UTC-midnight business-date windows remain
// =====================================================================
$genSrc = file_get_contents(FF_ROOT . '/lib/Billing/InvoiceGenerator.php');
$sam   = file_get_contents(FF_ROOT . '/lib/GPS/SamsaraClient.php');
$mil   = file_get_contents(FF_ROOT . '/api/v1/gps/mileage.php');
$pdist = file_get_contents(FF_ROOT . '/api/v1/samsara/period_distance.php');
$utcMidnight = '/DateTimeImmutable\(\s*\$\w+\s*\.\s*[\'"][ T]00:00:00[\'"]\s*,\s*(new \\\\DateTimeZone\([\'"]UTC[\'"]\)|\$utc)/';
$d1 = !preg_match($utcMidnight, $genSrc)
   && !preg_match($utcMidnight, $sam)
   && str_contains($genSrc, 'BusinessDayWindow::toUtc(')
   && str_contains($sam, 'BusinessDayWindow::toUtc(')
   && str_contains($pdist, 'BusinessDayWindow::toUtc(')
   && str_contains($mil, 'AccountingService::businessToday()')
   && !str_contains($mil, "date('Y-m-d')");
check('D1', 'callers use BusinessDayWindow; no UTC-midnight anchors', $d1,
    $d1 ? 'InvoiceGenerator + getMileageForLease + period_distance share the helper; mileage.php clamps on business today'
        : 'a caller still hand-rolls a UTC window — inspect the four files');

echo str_repeat('-', 74) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
