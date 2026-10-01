<?php
/**
 * tests/_smoke_invoice_distance_entry.php
 *
 * S-INVOICE-DISTANCE-ENTRY regression — Generate Invoice's "Distance driven"
 * entry (D-DISTANCE-ENTRY-1): the operator types the distance driven in a period
 * instead of an end odometer reading.
 *
 *   • manual lease  → the engine turns it into a reading: end = start + distance,
 *     start = the caller's start, else the last LIVE reading before the period,
 *     else the lease's starting odometer, else 0. The odometer chain every other
 *     reader uses (next invoice, Readings tab, close, Regenerate) stays intact.
 *   • samsara lease → it replaces the GPS distance (distance set, no end reading,
 *     source 'manual'); Regenerate carries it forward instead of re-fetching GPS.
 *   • off lease     → ignored by the engine, refused (422) by the API.
 *   • one period only — a multi-month fan-out refuses it.
 *   • the start-reading lookups skip VOID invoices (leases/show, close).
 *
 * Coverage (per function):
 *   InvoiceGenerator::createFromLease  E1–E9, E12 (direct)
 *     E1 manual, no start → counts from the lease's starting odometer
 *     E2 manual, next month → counts from the previous invoice's end reading
 *     E3 void-and-redo → the voided month's reading is skipped
 *     E4 caller start (the form's "Counted from") + distance
 *     E5 an end reading wins over a distance
 *     E6 samsara + distance → replaces GPS, no end reading, source manual
 *     E7 samsara, no distance → GPS distance unchanged (regression)
 *     E8 off lease → distance ignored, no mileage line
 *     E9 miles lease: 1,000 mi typed (via a browser-rounded start) bills 1,000.00 mi
 *     E12 estimate lease (per-day > 0): true-up settles to typed distance × rate
 *     E13 no start anywhere (lease start NULL, no reading) → refused, nothing written
 *   InvoiceGenerator::generateForLease E10 single-segment passthrough, E11 fan-out refused
 *   InvoiceGenerator::typedDistanceKm  E9.3, R1.1 miles typed → miles shown (0.01–30,000 mi), R1.2 km lease
 *   api/v1/invoices/create (HTTP)      H1 happy path + audit note, H2 end+distance 422,
 *                                      H3 negative 422, H4 off lease 422, H5 fan-out 422,
 *                                      H10 implausibly large 422, H11 no-start 422
 *   api/v1/invoices/regenerate (HTTP)  H6 samsara typed distance survives, H7 manual pair survives,
 *                                      H12 a large typed miles distance survives to the cent (exact flag)
 *   api/v1/leases/show (HTTP)          H8 latest reading + odometer_readings skip a void invoice
 *   api/v1/leases/close (HTTP)         H9 final invoice counts from the last LIVE reading
 *
 * Fixtures are COMMITTED (HTTP can't share a transaction) and removed on
 * shutdown, along with the Samsara fixture-mode flag and the borrowed unit's
 * Samsara link, which are restored to their prior values.
 *
 * USAGE: php tests/_smoke_invoice_distance_entry.php
 *        FF_SMOKE_BASE_URL=http://fleetforge.test/fleetforge FF_SMOKE_SESS_DIR=/var/tmp php tests/…
 *        (defaults: the `php -S` preview at http://localhost:8899/fleetforge + the CLI temp dir)
 * EXIT:  0 = all pass, 1 = any failure.
 *
 * @session S-INVOICE-DISTANCE-ENTRY
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;

$FAILURES = 0;
$TESTS    = 0;

/**
 * Record one strict-equality assertion.
 *
 * @param string $label
 * @param mixed  $expected
 * @param mixed  $actual
 * @return void
 */
function ok(string $label, $expected, $actual): void {
    global $FAILURES, $TESTS;
    $TESTS++;
    $pass = ($expected === $actual);
    if (!$pass) $FAILURES++;
    $fmt = static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : ($v === null ? 'null' : (string) $v);
    printf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label);
    if (!$pass) printf("        expected: %s\n        actual:   %s\n", $fmt($expected), $fmt($actual));
}

/**
 * Call an app endpoint with the smoke's session (+ CSRF on POST).
 *
 * @param string     $method 'GET' | 'POST'
 * @param string     $url
 * @param array|null $body   JSON body for POST
 * @return array{http_code:int, json:?array, body:string}
 */
function http_call(string $method, string $url, ?array $body = null): array {
    global $sessId, $csrf;
    $ch = curl_init($url);
    $headers = ['Cookie: ff_session=' . $sessId, 'Accept: application/json'];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'X-CSRF-Token: ' . $csrf;
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($resp) ? $resp : '';
    $json = json_decode($text, true);
    return ['http_code' => $code, 'json' => is_array($json) ? $json : null, 'body' => $text];
}

/**
 * A line's quantity at 2dp (quantities are stored at 4dp); '' when absent.
 *
 * @param array|null $line
 * @return string
 */
function q2(?array $line): string {
    return $line ? bcadd((string) $line['quantity'], '0', 2) : '';
}

/**
 * The stored mileage snapshot of one invoice.
 *
 * @param int $invoiceId
 * @return array
 */
function inv(int $invoiceId): array {
    return db_row(
        "SELECT id, invoice_number, status, odometer_at_period_start_km AS s, odometer_at_period_end_km AS e,
                period_distance_km AS d, cumulative_distance_km AS c, odometer_source AS src, odometer_fetched_at AS f
           FROM invoices WHERE id = ?",
        [$invoiceId]
    ) ?? [];
}

/**
 * One line of a given type on an invoice (null when absent).
 *
 * @param int    $invoiceId
 * @param string $type
 * @return array|null
 */
function line(int $invoiceId, string $type): ?array {
    return db_row(
        "SELECT quantity, unit, unit_price, amount, is_credit FROM invoice_line_items
          WHERE invoice_id = ? AND item_type = ? ORDER BY sort_order LIMIT 1",
        [$invoiceId, $type]
    );
}

// ── Session bootstrap for HTTP (super_admin) ───────────────────────────────
// Same file-session pattern as _smoke_close_mileage_semantics.php. The `php -S`
// preview reads the CLI temp dir; Herd's php-fpm reads /var/tmp.
$baseUrl  = rtrim(getenv('FF_SMOKE_BASE_URL') ?: 'http://localhost:8899/fleetforge', '/');
$sessDir  = rtrim(getenv('FF_SMOKE_SESS_DIR') ?: sys_get_temp_dir(), '/');
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = $sessDir . '/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_user|' . serialize([
        'id' => 1, 'name' => 'Distance Entry Bot', 'email' => 'distance-entry@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ]) .
    'ff_last_activity|' . serialize(time()) .
    'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);

// ── Fixtures (committed; removed on shutdown) ──────────────────────────────
$unitRow = db_row("SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
if (!$unitRow) { fwrite(STDERR, "No equipment_units in dev DB — cannot run smoke.\n"); exit(1); }
$unitId       = (int) $unitRow['id'];
$unitNumber   = (string) $unitRow['unit_number'];
$prefix       = 'SMOKE-DIST-' . date('YmdHis');
$origFixture  = (string) (settings_get('samsara.fixture_mode') ?? '0');
$origSamsara  = [$unitRow['samsara_vehicle_id'], $unitRow['samsara_entity_type']];

$customerId = db_insert('customers', [
    'company_name' => $prefix . ' Co', 'contact_name' => 'Distance Entry',
    'email' => strtolower($prefix) . '@example.invalid', 'phone' => '555-0402',
    'province' => 'BC', 'currency' => 'CAD',
    'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0, 'outstanding_balance' => '0.00',
]);

// Samsara is faked (FixtureProvider: FIX_STD answers 1234.56 km for a period).
db_execute("UPDATE settings SET value = '1' WHERE `key` = 'samsara.fixture_mode'");
db_execute("UPDATE equipment_units SET samsara_vehicle_id = 'FIX_STD', samsara_entity_type = 'vehicle' WHERE id = ?", [$unitId]);

register_shutdown_function(static function () use ($sessFile, $customerId, $origFixture, $origSamsara, $unitId) {
    if (is_file($sessFile)) @unlink($sessFile);
    db_execute("UPDATE settings SET value = ? WHERE `key` = 'samsara.fixture_mode'", [$origFixture]);
    db_execute("UPDATE equipment_units SET samsara_vehicle_id = ?, samsara_entity_type = ? WHERE id = ?",
        [$origSamsara[0], $origSamsara[1], $unitId]);
    foreach (array_column(db_select("SELECT id FROM leases WHERE customer_id = ?", [$customerId]), 'id') as $lid) {
        foreach (array_column(db_select("SELECT id FROM invoices WHERE lease_id = ?", [$lid]), 'id') as $iid) {
            db_execute("DELETE FROM invoice_line_items    WHERE invoice_id = ?", [$iid]);
            db_execute("DELETE FROM lease_billing_periods WHERE invoice_id = ?", [$iid]);
            db_execute("DELETE FROM credit_notes          WHERE source_invoice_id = ?", [$iid]);
            db_execute("DELETE FROM notifications         WHERE entity_type = 'invoice' AND entity_id = ?", [$iid]);
        }
        db_execute("DELETE FROM invoices              WHERE lease_id = ?", [$lid]);
        db_execute("DELETE FROM lease_billing_periods WHERE lease_id = ?", [$lid]);
        db_execute("DELETE FROM lease_status_log      WHERE lease_id = ?", [$lid]);
    }
    db_execute("DELETE FROM leases    WHERE customer_id = ?", [$customerId]);
    db_execute("DELETE FROM customers WHERE id = ?",          [$customerId]);
});

/**
 * An active monthly lease starting 2025-01-01 (a backfill-shaped lease).
 *
 * @param string $tag
 * @param string $mode  mileage_tracking_mode: manual | samsara | off
 * @param array  $over  column overrides
 * @return int lease id
 */
function make_lease(string $tag, string $mode, array $over = []): int {
    global $customerId, $unitId, $unitNumber, $prefix;
    return db_insert('leases', array_merge([
        'contract_number'         => $prefix . '-' . $tag,
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'unit_number_snapshot'    => $unitNumber,
        'company_name_snapshot'   => $prefix . ' Co',
        'customer_name_snapshot'  => 'Distance Entry',
        'status'                  => 'active',
        'start_date'              => '2025-01-01',
        'monthly_rate'            => '1000.00', 'daily_rate' => '40.00', 'weekly_rate' => '250.00',
        'currency'                => 'CAD', 'billing_cycle' => 'monthly', 'advance_billing_periods' => 0,
        'gps_opt_in'              => 0, 'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'discount_type'           => 'none', 'discount_value' => '0.0000',
        'mileage_tracking_mode'   => $mode,
        'mileage_rate'            => '0.2500', 'mileage_rate_km' => '0.2500', 'mileage_rate_miles' => '0.4023',
        'mileage_unit'            => 'km',
        'km_to_miles_conversion'  => '0.621371', 'miles_to_km_conversion' => '1.609344',
        'estimated_mileage'       => '0.00', 'estimated_mileage_km' => '0.000',
        'estimated_mileage_per_day' => '0.00', 'estimated_mileage_per_day_km' => '0.0000',
        'precharge_enabled'       => 0,
        'odometer_start_km'       => '0.00',
        'odometer_start_source'   => 'manual',
        'total_invoiced'          => '0.00', 'total_paid' => '0.00', 'outstanding_balance' => '0.00',
        'next_billing_date'       => '2025-02-01',
        'created_by'              => 1, 'updated_by' => 1,
    ], $over));
}

/**
 * Bill one calendar month of 2025 through the engine directly.
 *
 * @param int   $leaseId
 * @param int   $month  1..12
 * @param array $extra  mileage params
 * @return int invoice id
 */
function bill_month(int $leaseId, int $month, array $extra = []): int {
    $start = sprintf('2025-%02d-01', $month);
    $inv = (new InvoiceGenerator())->createFromLease(array_merge([
        'lease_id' => $leaseId, 'period_start' => $start, 'period_end' => date('Y-m-t', strtotime($start)),
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'created_by' => 1,
        'generation_source' => 'manual',
    ], $extra));
    return (int) $inv['invoice_id'];
}

echo "S-INVOICE-DISTANCE-ENTRY — typed distance on Generate Invoice\n";
echo str_repeat('=', 78) . "\n\n";

try {
    // ── E1–E5: manual lease, the virtual odometer chain ─────────────────────
    $L1 = make_lease('L1', 'manual');
    $i1 = bill_month($L1, 1, ['period_distance_km' => '1500']);
    $r  = inv($i1); $ml = line($i1, 'mileage_usage');
    ok('E1.1 manual, no start → counts from the lease starting odometer (0.00)', '0.00', $r['s']);
    ok('E1.2 end reading = 0 + 1,500', '1500.00', $r['e']);
    ok('E1.3 period distance = typed distance', '1500.00', $r['d']);
    ok('E1.4 cumulative since lease start', '1500.00', $r['c']);
    ok('E1.5 source = manual, no fetch stamp', 'manual|', $r['src'] . '|' . ($r['f'] ?? ''));
    ok('E1.6 one mileage_usage line: 1,500 km × $0.25 = $375.00', '1500.00|375.00', q2($ml) . '|' . ($ml['amount'] ?? ''));

    $i2 = bill_month($L1, 2, ['period_distance_km' => '800']);
    $r  = inv($i2);
    ok('E2.1 next month counts from the previous end reading', '1500.00|2300.00|800.00', "{$r['s']}|{$r['e']}|{$r['d']}");

    db_execute("UPDATE invoices SET status = 'void' WHERE id = ?", [$i2]);
    $i3 = bill_month($L1, 2, ['period_distance_km' => '900']);
    $r  = inv($i3);
    ok('E3.1 void-and-redo: the voided month\'s reading (2,300) is skipped', '1500.00|2400.00|900.00', "{$r['s']}|{$r['e']}|{$r['d']}");

    $i4 = bill_month($L1, 3, ['odometer_at_period_start_km' => '2400.00', 'period_distance_km' => '1000']);
    $r  = inv($i4);
    ok('E4.1 caller start (the form\'s "Counted from") + distance', '2400.00|3400.00|1000.00', "{$r['s']}|{$r['e']}|{$r['d']}");

    $i5 = bill_month($L1, 4, ['odometer_at_period_start_km' => '3400.00', 'odometer_at_period_end_km' => '3500.00', 'period_distance_km' => '9999']);
    $r  = inv($i5);
    ok('E5.1 an end reading wins over a distance', '3500.00|100.00', "{$r['e']}|{$r['d']}");

    // ── E6/E7: samsara lease — typed distance replaces GPS ──────────────────
    $L2 = make_lease('L2', 'samsara');
    $i7 = bill_month($L2, 1);
    $r  = inv($i7);
    ok('E7.1 samsara, no distance → GPS distance (regression)', '1234.56|gps', "{$r['d']}|{$r['src']}");
    $i6 = bill_month($L2, 2, ['period_distance_km' => '500']);
    $r  = inv($i6); $ml = line($i6, 'mileage_usage');
    ok('E6.1 samsara + distance → the typed distance, not GPS', '500.00', $r['d']);
    ok('E6.2 no invented readings on a Samsara lease', 'null|null', ($r['s'] ?? 'null') . '|' . ($r['e'] ?? 'null'));
    ok('E6.3 source manual, no fetch stamp', 'manual|', $r['src'] . '|' . ($r['f'] ?? ''));
    ok('E6.4 billed: 500 km × $0.25 = $125.00', '500.00|125.00', q2($ml) . '|' . ($ml['amount'] ?? ''));

    // ── E8: off lease ───────────────────────────────────────────────────────
    $L3 = make_lease('L3', 'off', ['mileage_rate_km' => '0.0000', 'mileage_rate' => '0.0000', 'mileage_rate_miles' => '0.0000']);
    $i8 = bill_month($L3, 1, ['period_distance_km' => '700']);
    $r  = inv($i8);
    ok('E8.1 off lease: distance ignored, no mileage line', 'null|null|none',
        ($r['d'] ?? 'null') . '|' . ($r['e'] ?? 'null') . '|' . (line($i8, 'mileage_usage') ? 'line' : 'none'));

    // ── E9: miles lease, browser-rounded start ──────────────────────────────
    $L4 = make_lease('L4', 'manual', ['mileage_unit' => 'miles', 'mileage_rate' => '0.4000', 'mileage_rate_miles' => '0.4000', 'mileage_rate_km' => '0.2486']);
    // The form sends 1,000 mi as 1609.3440 km and a start that went km→mi→km (804.672).
    $i9 = bill_month($L4, 1, ['odometer_at_period_start_km' => '804.672', 'period_distance_km' => '1609.3440']);
    $r  = inv($i9); $ml = line($i9, 'mileage_usage');
    ok('E9.1 start snapped to 2dp; end − start = exactly the typed km', '804.67|2414.01|1609.34', "{$r['s']}|{$r['e']}|{$r['d']}");
    ok('E9.2 the line shows the 1,000.00 mi that were typed', '1000.00|miles', q2($ml) . '|' . ($ml['unit'] ?? ''));

    // 6,231.48 mi is the first value plain 2dp-km rounding read back 0.01 short.
    $i9b = bill_month($L4, 2, ['period_distance_km' => sprintf('%.4f', 6231.48 * 1.609344)]);
    ok('E9.3 6,231.48 mi typed → 6,231.48 mi on the line (miles snap)', '6231.48', q2(line($i9b, 'mileage_usage')));

    // ── R1: InvoiceGenerator::typedDistanceKm — miles typed → miles shown ───
    // Every 0.07 mi from 0.01 to 30,000 mi (~430k values): what the form sends
    // (toKm(…).toFixed(4)) through the engine's km choice must read back exactly.
    $sweepLease = ['mileage_unit' => 'miles', 'km_to_miles_conversion' => '0.621371', 'mileage_rate' => '0.4000'];
    $bad = []; $n = 0;
    for ($c = 1; $c <= 3000000; $c += 7) {
        $mi = bcdiv((string) $c, '100', 2);
        $kmSent = sprintf('%.4f', (float) $mi * 1.609344);
        $shown  = ff_mileage_line_display($sweepLease, InvoiceGenerator::typedDistanceKm($kmSent, $sweepLease), '0')['distance'];
        $n++;
        if (bccomp($shown, $mi, 2) !== 0 && count($bad) < 5) $bad[] = "{$mi}→{$shown}";
    }
    ok("R1.1 all {$n} sampled miles values (0.01–30,000 mi) read back exactly", '', implode(', ', $bad));
    $kmLease = ['mileage_unit' => 'km'];
    ok('R1.2 km lease: plain 2dp rounding, no nudge', '804.67|0.00',
        InvoiceGenerator::typedDistanceKm('804.6720', $kmLease) . '|' . InvoiceGenerator::typedDistanceKm('-3', $kmLease));

    // ── E12: estimate lease — the true-up settles to typed distance × rate ──
    $L5 = make_lease('L5', 'manual', ['estimated_mileage_per_day' => '40.00', 'estimated_mileage_per_day_km' => '40.0000']);
    $i12 = bill_month($L5, 1, ['period_distance_km' => '1500']);
    $net = db_row(
        "SELECT COALESCE(SUM(CASE WHEN is_credit = 1 THEN -amount ELSE amount END), 0) AS n
           FROM invoice_line_items WHERE invoice_id = ?
            AND item_type IN ('mileage_estimate','mileage_adjustment','mileage_credit','mileage_usage','mileage')",
        [$i12]
    )['n'];
    ok('E12.1 estimate + true-up net to 1,500 km × $0.25 = $375.00', '375.00', bcadd((string) $net, '0', 2));
    ok('E12.2 an estimate line was billed (estimate model active)', true, line($i12, 'mileage_estimate') !== null);

    // ── E10/E11: generateForLease ───────────────────────────────────────────
    $L6 = make_lease('L6', 'manual');
    $b  = (new InvoiceGenerator())->generateForLease([
        'lease_id' => $L6, 'period_start' => '2025-01-01', 'period_end' => '2025-01-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'single_segment' => true,
        'created_by' => 1, 'generation_source' => 'manual', 'period_distance_km' => '650',
    ]);
    $r = inv((int) $b['invoices'][0]['invoice_id']);
    ok('E10.1 single-segment generation passes the distance through', '0.00|650.00|650.00', "{$r['s']}|{$r['e']}|{$r['d']}");

    $L7 = make_lease('L7', 'manual');
    $threw = '';
    try {
        (new InvoiceGenerator())->generateForLease([
            'lease_id' => $L7, 'period_start' => '2025-01-01', 'period_end' => '2025-03-31',
            'billing_type' => 'full_month', 'invoice_type' => 'regular', 'single_segment' => false,
            'created_by' => 1, 'generation_source' => 'manual', 'period_distance_km' => '3000',
        ]);
    } catch (\RuntimeException $e) {
        $threw = $e->getMessage();
    }
    ok('E11.1 a 3-month fan-out refuses a distance', true, str_contains($threw, 'one month at a time'));
    ok('E11.2 …and writes no invoice', 0, (int) db_row("SELECT COUNT(*) n FROM invoices WHERE lease_id = ?", [$L7])['n']);

    // ── E13: nothing to count from → refused (never an invented 0 chain) ────
    $L11 = make_lease('L11', 'manual', ['odometer_start_km' => null, 'odometer_start_source' => null]);
    $threw = '';
    try {
        bill_month($L11, 1, ['period_distance_km' => '900']);
    } catch (\RuntimeException $e) {
        $threw = $e->getMessage();
    }
    ok('E13.1 no starting odometer + no reading → distance refused', true, str_contains($threw, 'nothing to count from'));
    ok('E13.2 …and writes no invoice', 0, (int) db_row("SELECT COUNT(*) n FROM invoices WHERE lease_id = ?", [$L11])['n']);

    // ── HTTP: api/v1/invoices/create ────────────────────────────────────────
    $probe = http_call('GET', "$baseUrl/api/v1/leases/show?id={$L1}");
    if ($probe['http_code'] !== 200) {
        throw new RuntimeException("HTTP preview not reachable at {$baseUrl} (code {$probe['http_code']}). "
            . 'Start it (launch.json "fleetforge") or set FF_SMOKE_BASE_URL / FF_SMOKE_SESS_DIR.');
    }

    $L8 = make_lease('L8', 'manual');
    $h1 = http_call('POST', "$baseUrl/api/v1/invoices/create", [
        'lease_id' => $L8, 'period_start' => '2025-01-01', 'period_end' => '2025-01-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'single_segment' => true,
        'odometer_at_period_start_km' => '0', 'period_distance_km' => '1200.0000',
    ]);
    ok('H1.1 create with a distance → 201', 201, $h1['http_code']);
    if ($h1['http_code'] !== 201) echo "        body: {$h1['body']}\n";
    $h1Id = (int) ($h1['json']['data']['id'] ?? 0);
    $r = $h1Id ? inv($h1Id) : [];
    ok('H1.2 stored 0 → 1,200 km, distance 1,200', '0.00|1200.00|1200.00', ($r['s'] ?? '') . '|' . ($r['e'] ?? '') . '|' . ($r['d'] ?? ''));
    $note = db_row("SELECT notes FROM audit_log WHERE entity_type = 'invoice' AND entity_id = ? AND action = 'create' ORDER BY id DESC LIMIT 1", [$h1Id]);
    ok('H1.3 audit note records the distance entry', true, str_contains((string) ($note['notes'] ?? ''), 'entered as distance driven: 1200.0000 km'));

    $h2 = http_call('POST', "$baseUrl/api/v1/invoices/create", [
        'lease_id' => $L8, 'period_start' => '2025-02-01', 'period_end' => '2025-02-28',
        'billing_type' => 'full_month', 'single_segment' => true,
        'odometer_at_period_end_km' => '2000', 'period_distance_km' => '500',
    ]);
    ok('H2.1 distance + end reading → 422 on period_distance_km', '422|yes',
        $h2['http_code'] . '|' . (isset($h2['json']['error']['fields']['period_distance_km']) ? 'yes' : 'no'));

    $h3 = http_call('POST', "$baseUrl/api/v1/invoices/create", [
        'lease_id' => $L8, 'period_start' => '2025-02-01', 'period_end' => '2025-02-28',
        'billing_type' => 'full_month', 'single_segment' => true, 'period_distance_km' => '-5',
    ]);
    ok('H3.1 negative distance → 422', 422, $h3['http_code']);

    $h4 = http_call('POST', "$baseUrl/api/v1/invoices/create", [
        'lease_id' => $L3, 'period_start' => '2025-02-01', 'period_end' => '2025-02-28',
        'billing_type' => 'full_month', 'single_segment' => true, 'period_distance_km' => '100',
    ]);
    ok('H4.1 off lease + distance → 422 naming tracking Off', '422|yes',
        $h4['http_code'] . '|' . (str_contains((string) ($h4['json']['error']['fields']['period_distance_km'] ?? ''), 'Off') ? 'yes' : 'no'));

    $L9 = make_lease('L9', 'manual');
    $h5 = http_call('POST', "$baseUrl/api/v1/invoices/create", [
        'lease_id' => $L9, 'period_start' => '2025-01-01', 'period_end' => '2025-03-31',
        'billing_type' => 'full_month', 'single_segment' => false, 'period_distance_km' => '3000',
    ]);
    ok('H5.1 "Generate all due" fan-out + distance → 422 DISTANCE_SPANS_MONTHS', '422|DISTANCE_SPANS_MONTHS',
        $h5['http_code'] . '|' . ($h5['json']['error']['code'] ?? ''));
    ok('H5.2 …and no invoice was written', 0, (int) db_row("SELECT COUNT(*) n FROM invoices WHERE lease_id = ?", [$L9])['n']);

    $h10 = http_call('POST', "$baseUrl/api/v1/invoices/create", [
        'lease_id' => $L9, 'period_start' => '2025-01-01', 'period_end' => '2025-01-31',
        'billing_type' => 'full_month', 'single_segment' => true, 'period_distance_km' => '1000000',
    ]);
    ok('H10.1 a 7-digit "distance" (an odometer reading) → 422, not a 500', '422|yes',
        $h10['http_code'] . '|' . (isset($h10['json']['error']['fields']['period_distance_km']) ? 'yes' : 'no'));

    $h11 = http_call('POST', "$baseUrl/api/v1/invoices/create", [
        'lease_id' => $L11, 'period_start' => '2025-01-01', 'period_end' => '2025-01-31',
        'billing_type' => 'full_month', 'single_segment' => true, 'period_distance_km' => '900',
    ]);
    ok('H11.1 no start anywhere → 422 DISTANCE_NO_START on period_distance_km', '422|DISTANCE_NO_START|yes',
        $h11['http_code'] . '|' . ($h11['json']['error']['code'] ?? '') . '|' . (isset($h11['json']['error']['fields']['period_distance_km']) ? 'yes' : 'no'));

    // ── HTTP: api/v1/invoices/regenerate ────────────────────────────────────
    $h6 = http_call('POST', "$baseUrl/api/v1/invoices/regenerate", ['id' => $i6]);
    ok('H6.1 regenerate the Samsara typed-distance draft → 200', 200, $h6['http_code']);
    if ($h6['http_code'] !== 200) echo "        body: {$h6['body']}\n";
    $h6Id = (int) ($h6['json']['data']['id'] ?? 0);
    if (!$h6Id) echo "        body: " . substr(strip_tags($h6["body"]), 0, 1500) . "\n";
    $r = $h6Id ? inv($h6Id) : [];
    ok('H6.2 the typed 500 km survives (not re-fetched from GPS)', '500.00|manual', ($r['d'] ?? '') . '|' . ($r['src'] ?? ''));

    $h7 = http_call('POST', "$baseUrl/api/v1/invoices/regenerate", ['id' => $h1Id]);
    ok('H7.1 regenerate the manual typed-distance draft → 200', 200, $h7['http_code']);
    $h7Id = (int) ($h7['json']['data']['id'] ?? 0);
    $r = $h7Id ? inv($h7Id) : [];
    ok('H7.2 its derived reading pair survives', '0.00|1200.00|1200.00', ($r['s'] ?? '') . '|' . ($r['e'] ?? '') . '|' . ($r['d'] ?? ''));

    // 12,245.50 mi is the first value a second miles nudge on regenerate moved.
    $L12 = make_lease('L12', 'samsara', ['mileage_unit' => 'miles', 'mileage_rate' => '0.4000', 'mileage_rate_miles' => '0.4000', 'mileage_rate_km' => '0.2486']);
    $i12b = bill_month($L12, 1, ['period_distance_km' => sprintf('%.4f', 12245.50 * 1.609344)]);
    ok('H12.0 12,245.50 mi typed on a Samsara miles lease reads back exactly', '12245.50', q2(line($i12b, 'mileage_usage')));
    $h12 = http_call('POST', "$baseUrl/api/v1/invoices/regenerate", ['id' => $i12b]);
    $h12Id = (int) ($h12['json']['data']['id'] ?? 0);
    ok('H12.1 …and still 12,245.50 mi after Regenerate (stored km kept exactly)', '200|12245.50',
        $h12['http_code'] . '|' . ($h12Id ? q2(line($h12Id, 'mileage_usage')) : ''));

    // ── HTTP: api/v1/leases/show — latest reading skips void ────────────────
    $L10 = make_lease('L10', 'manual');
    bill_month($L10, 1, ['odometer_at_period_start_km' => '0', 'odometer_at_period_end_km' => '1000']);
    $v2 = bill_month($L10, 2, ['odometer_at_period_start_km' => '1000', 'odometer_at_period_end_km' => '2000']);
    db_execute("UPDATE invoices SET status = 'void' WHERE id = ?", [$v2]);
    $h8 = http_call('GET', "$baseUrl/api/v1/leases/show?id={$L10}");
    ok('H8.1 leases/show latest_invoice_odometer_km = 1000 (void Feb skipped)', 1000.0,
        isset($h8['json']['data']['latest_invoice_odometer_km']) ? (float) $h8['json']['data']['latest_invoice_odometer_km'] : null);
    $readings = $h8['json']['data']['odometer_readings'] ?? null;
    ok('H8.2 odometer_readings = live readings oldest-first (Jan only; void Feb skipped)', '2025-01-31:1000',
        is_array($readings) ? implode(',', array_map(static fn ($x) => $x['period_end'] . ':' . (float) $x['km'], $readings)) : 'missing');

    // ── HTTP: api/v1/leases/close — final invoice counts from the last LIVE reading
    $h9 = http_call('POST', "$baseUrl/api/v1/leases/close", [
        'id' => $L10, 'actual_return_date' => '2025-03-15',
        'odometer_at_close_km' => '2600.00', 'odometer_source' => 'manual', 'mileage_at_end' => 2600,
    ]);
    ok('H9.1 close → 200', 200, $h9['http_code']);
    if ($h9['http_code'] !== 200) echo "        body: {$h9['body']}\n";
    $fin = db_row(
        "SELECT odometer_at_period_start_km AS s FROM invoices
          WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' AND odometer_at_period_end_km = '2600.00'
          ORDER BY id DESC LIMIT 1",
        [$L10]
    );
    ok('H9.2 final invoice starts at 1,000 (not the void month\'s 2,000)', '1000.00', $fin['s'] ?? null);
} catch (\Throwable $e) {
    $FAILURES++;
    echo "\n  [FAIL] aborted: " . get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 78) . "\n";
printf("%d tests, %d failures\n", $TESTS, $FAILURES);
exit($FAILURES === 0 ? 0 : 1);
