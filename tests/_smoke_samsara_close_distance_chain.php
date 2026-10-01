<?php
/**
 * tests/_smoke_samsara_close_distance_chain.php
 *
 * S-SAMSARA-CLOSE-DISTANCE-CHAIN regression (D-ODOMETER-CHAIN-1) — the next
 * reading must count from where the odometer STANDS, not from the last real
 * reading.
 *
 * THE BUG: a Samsara lease's months are usually billed as a DISTANCE with no
 * end reading (the GPS fallback, or a distance typed on Generate Invoice).
 * Every "previous reading" lookup skipped those months, so the next reading —
 * the closing odometer at lease close, a reading typed on Generate Invoice,
 * the monthly cron's cached odometer — billed their driving a second time:
 * start 120,000 km, three distance-only months (4,000 + 4,000 + 1,234.56 GPS),
 * closed at 130,734.56 → the final invoice billed 10,734.56 km, not 1,500.
 *
 * Coverage (per function):
 *   OdometerChain::positionsBefore   U1 lease start + distance-only months
 *                                    U2 a real reading resets, later distance adds
 *                                    U3 void skipped   U4 exclusive date bound
 *                                    U5 no start + no reading → absent
 *                                    U6 batch of several leases
 *                                    U9 a LATE-captured start (odometer_start_fetched_at)
 *                                       already holds the GPS months before it
 *                                    U10 an adjustment invoice's GPS distance is ignored
 *                                    U11 floor_km = the last real reading under a derived position
 *   OdometerChain::chain             U7 full running chain, derived flags
 *   CycleReadings::previousReadings  U8 odometer = position, "from" says so;
 *                                    hours unchanged; U8.4 odometer_floor_km
 *   CycleReadings::sheet             U12 prev_odometer_floor_km (save()'s hard floor)
 *   InvoiceGenerator::generateForLease F1 a "Generate all due" fan-out with an
 *                                    end reading counts the last month from start +
 *                                    the GPS months it just wrote (no re-bill)
 *   api/v1/leases/close (HTTP)       H1 Samsara lease: final invoice bills only
 *                                    the unbilled 1,500 km (was 10,734.56)
 *                                    H2 manual mid-month close: counts from the
 *                                    previous month, not the closing month's
 *                                    draft the close voids
 *                                    H4 last-day close folding onto a GPS March
 *                                    draft stamps that draft's start from BEFORE
 *                                    its own period (pair agrees with its distance)
 *   api/v1/leases/show (HTTP)        H3 odometer_readings carry the derived
 *                                    positions Generate Invoice counts from
 *   cron/invoice_generate_monthly    NOT executed (it bills every due lease on
 *                                    the shared dev DB); its start lookup is one
 *                                    positionsBefore() call — covered by U1–U6.
 *
 * Fixtures are COMMITTED (HTTP can't share a transaction) and removed on
 * shutdown; the Samsara fixture-mode flag and the borrowed unit's Samsara link
 * are restored to their prior values.
 *
 * USAGE: php tests/_smoke_samsara_close_distance_chain.php
 *        FF_SMOKE_BASE_URL=http://fleetforge.test/fleetforge FF_SMOKE_SESS_DIR=/var/tmp php tests/…
 *        (defaults: the `php -S` preview at http://localhost:8899/fleetforge + the CLI temp dir)
 * EXIT:  0 = all pass, 1 = any failure.
 *
 * @session S-SAMSARA-CLOSE-DISTANCE-CHAIN
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\OdometerChain;
use FleetForge\Billing\Cycle\CycleReadings;

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
 * @param array|null $body
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

// ── Session bootstrap for HTTP (super_admin) ───────────────────────────────
$baseUrl  = rtrim(getenv('FF_SMOKE_BASE_URL') ?: 'http://localhost:8899/fleetforge', '/');
$sessDir  = rtrim(getenv('FF_SMOKE_SESS_DIR') ?: sys_get_temp_dir(), '/');
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = $sessDir . '/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_user|' . serialize([
        'id' => 1, 'name' => 'Odometer Chain Bot', 'email' => 'odometer-chain@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ]) .
    'ff_last_activity|' . serialize(time()) .
    'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);

// ── Fixtures (committed; removed on shutdown) ──────────────────────────────
$unitRow = db_row("SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
if (!$unitRow) { fwrite(STDERR, "No equipment_units in dev DB — cannot run smoke.\n"); exit(1); }
$unitId      = (int) $unitRow['id'];
$unitNumber  = (string) $unitRow['unit_number'];
$prefix      = 'SMOKE-ODOCHAIN-' . date('YmdHis');
$origFixture = (string) (settings_get('samsara.fixture_mode') ?? '0');
$origSamsara = [$unitRow['samsara_vehicle_id'], $unitRow['samsara_entity_type']];

$customerId = db_insert('customers', [
    'company_name' => $prefix . ' Co', 'contact_name' => 'Odometer Chain',
    'email' => strtolower($prefix) . '@example.invalid', 'phone' => '555-0404',
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
 * An active monthly lease starting 2025-01-01.
 *
 * @param string $tag
 * @param string $mode  mileage_tracking_mode
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
        'customer_name_snapshot'  => 'Odometer Chain',
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
        'odometer_start_km'       => '120000.00',
        'odometer_start_source'   => 'gps',
        'total_invoiced'          => '0.00', 'total_paid' => '0.00', 'outstanding_balance' => '0.00',
        'next_billing_date'       => '2025-02-01',
        'created_by'              => 1, 'updated_by' => 1,
    ], $over));
}

/**
 * Bill one calendar month of 2025 through the engine.
 *
 * @param int   $leaseId
 * @param int   $month
 * @param array $extra
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

/**
 * Sum of mileage_usage quantities on one invoice, 2dp ('' when none).
 *
 * @param int $invoiceId
 * @return string
 */
function usage_qty(int $invoiceId): string {
    $r = db_row("SELECT SUM(quantity) q, COUNT(*) n FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_usage'", [$invoiceId]);
    return ((int) ($r['n'] ?? 0)) > 0 ? bcadd((string) $r['q'], '0', 2) : '';
}

echo "S-SAMSARA-CLOSE-DISTANCE-CHAIN — count from where the odometer stands\n";
echo str_repeat('=', 78) . "\n\n";

try {
    // ── U1/U4/U6/U7: Samsara lease, three distance-only months ──────────────
    $LS = make_lease('S', 'samsara');
    $j  = bill_month($LS, 1, ['period_distance_km' => '4000']);   // typed
    $f  = bill_month($LS, 2, ['period_distance_km' => '4000']);   // typed
    $m  = bill_month($LS, 3);                                       // GPS fallback (fixture 1234.56)
    $mRow = db_row("SELECT period_distance_km d, odometer_at_period_end_km e, odometer_source s FROM invoices WHERE id = ?", [$m]);
    ok('U0 setup: March billed by the GPS fallback (distance only)', '1234.56|null|gps',
        $mRow['d'] . '|' . ($mRow['e'] ?? 'null') . '|' . $mRow['s']);

    $p = OdometerChain::positionsBefore([$LS], '2025-04-01')[$LS] ?? null;
    ok('U1.1 position = 120,000 start + 4,000 + 4,000 + 1,234.56', '129234.56', $p['km'] ?? null);
    ok('U1.2 …derived, from the last contributing invoice', true,
        ($p['derived'] ?? false) === true && str_contains((string) ($p['from'] ?? ''), '2025-03-31'));
    $p4 = OdometerChain::positionsBefore([$LS], '2025-03-31')[$LS] ?? null;
    ok('U4.1 exclusive bound: before 2025-03-31 stops after February', '128000.00', $p4['km'] ?? null);

    $chain = OdometerChain::chain($LS);
    ok('U7.1 chain(): one derived entry per distance-only month, oldest first',
        '2025-01-31:124000.00:1|2025-02-28:128000.00:1|2025-03-31:129234.56:1',
        implode('|', array_map(static fn ($e) => "{$e['period_end']}:{$e['km']}:" . (int) $e['derived'], $chain)));

    // ── U2/U3: a real reading resets; void is skipped ───────────────────────
    $LR = make_lease('R', 'samsara');
    bill_month($LR, 1, ['period_distance_km' => '4000']);
    bill_month($LR, 2, ['odometer_at_period_start_km' => '124000', 'odometer_at_period_end_km' => '125000.00', 'odometer_source' => 'manual']);
    $mr = bill_month($LR, 3, ['period_distance_km' => '3000']);
    $pr = OdometerChain::positionsBefore([$LR], '2025-04-01')[$LR] ?? null;
    ok('U2.1 real Feb reading 125,000 resets, March distance adds → 128,000', '128000.00|1', ($pr['km'] ?? '') . '|' . (int) ($pr['derived'] ?? 0));
    db_execute("UPDATE invoices SET status = 'void' WHERE id = ?", [$mr]);
    $pv = OdometerChain::positionsBefore([$LR], '2025-04-01')[$LR] ?? null;
    ok('U3.1 voided March distance skipped → the real 125,000, not derived', '125000.00|0', ($pv['km'] ?? '') . '|' . (int) ($pv['derived'] ?? 0));

    // ── U5: no starting odometer and no reading → absent ────────────────────
    $LN = make_lease('N', 'samsara', ['odometer_start_km' => null, 'odometer_start_source' => null]);
    bill_month($LN, 1, ['period_distance_km' => '4000']);
    ok('U5.1 distance-only months with no start and no reading → no position', false,
        array_key_exists($LN, OdometerChain::positionsBefore([$LN], '2025-04-01')));

    // ── U6: batch ───────────────────────────────────────────────────────────
    $batch = OdometerChain::positionsBefore([$LS, $LR, $LN], '2025-04-01');
    ok('U6.1 one call, several leases', '129234.56|125000.00|absent',
        ($batch[$LS]['km'] ?? '') . '|' . ($batch[$LR]['km'] ?? '') . '|' . (isset($batch[$LN]) ? 'present' : 'absent'));

    // ── U8: CycleReadings::previousReadings uses the position ───────────────
    $LH = make_lease('H', 'manual', ['odometer_start_km' => '0.00', 'odometer_start_source' => 'manual', 'hourly_rate' => '5.00', 'engine_hours_at_start' => '100.00']);
    bill_month($LH, 1, ['odometer_at_period_start_km' => '0', 'odometer_at_period_end_km' => '1000.00', 'odometer_source' => 'manual',
                         'engine_hours_at_period_start' => '100', 'engine_hours_at_period_end' => '150']);
    // A distance-only month on a manual lease (e.g. billed by GPS while it was a Samsara lease).
    db_execute("UPDATE leases SET mileage_tracking_mode = 'samsara' WHERE id = ?", [$LH]);
    bill_month($LH, 2, ['period_distance_km' => '700']);
    db_execute("UPDATE leases SET mileage_tracking_mode = 'manual' WHERE id = ?", [$LH]);
    $prev = CycleReadings::previousReadings([$LH], '2025-03-01')[$LH] ?? [];
    ok('U8.1 previousReadings odometer = 1,000 reading + 700 distance', '1700.00', $prev['odometer_km'] ?? null);
    ok('U8.2 …and says it was derived', true, str_contains((string) ($prev['odometer_from'] ?? ''), 'distance billed since'));
    ok('U8.3 engine hours unchanged (latest hours reading)', '150.00', isset($prev['hours']) ? bcadd((string) $prev['hours'], '0', 2) : null);

    ok('U8.4 previousReadings odometer_floor_km = the last REAL reading (1,000)', '1000.00', $prev['odometer_floor_km'] ?? null);

    // ── U9: a late-captured start already contains the GPS months before it ─
    $LL = make_lease('L', 'samsara');
    bill_month($LL, 1, ['period_distance_km' => '4000']);
    bill_month($LL, 2, ['period_distance_km' => '4000']);
    bill_month($LL, 3, ['period_distance_km' => '3000']);
    // Start read off the live odometer on 2025-03-05 (a back-dated activation /
    // "Fetch from Samsara"): Jan + Feb driving is already inside the 120,000.
    db_execute("UPDATE leases SET odometer_start_fetched_at = '2025-03-05 18:00:00' WHERE id = ?", [$LL]);
    $pl = OdometerChain::positionsBefore([$LL], '2025-04-01')[$LL] ?? null;
    ok('U9.1 Jan/Feb (ended before the 2025-03-05 capture) skipped; March added → 123,000', '123000.00', $pl['km'] ?? null);

    // ── U10: an adjustment invoice's GPS distance never billed → ignored ────
    $LA = make_lease('A', 'samsara');
    bill_month($LA, 1, ['period_distance_km' => '4000']);
    (new InvoiceGenerator())->createFromLease([
        'lease_id' => $LA, 'period_start' => '2025-02-01', 'period_end' => '2025-02-01',
        'billing_type' => 'adjustment', 'invoice_type' => 'adjustment', 'created_by' => 1, 'generation_source' => 'manual',
        'extra_lines' => [['item_type' => 'fuel', 'description' => 'Fuel', 'quantity' => '1', 'unit_price' => '10.00', 'amount' => '10.00', 'is_credit' => 0, 'taxable' => 1]],
    ]);
    $adjD = db_row("SELECT period_distance_km d FROM invoices WHERE lease_id = ? AND billing_type = 'adjustment'", [$LA]);
    $pa = OdometerChain::positionsBefore([$LA], '2025-03-01')[$LA] ?? null;
    ok('U10.1 setup: the adjustment carries a GPS distance (1,234.56)', '1234.56', $adjD['d'] ?? null);
    ok('U10.2 …which the chain ignores → 124,000', '124000.00', $pa['km'] ?? null);

    // ── U11: floor under a derived position ─────────────────────────────────
    ok('U11.1 floor_km under 129,234.56 is the 120,000 start (no real reading yet)', '120000.00', $p['floor_km'] ?? null);

    // ── U12: CycleReadings::sheet exposes the floor save() checks against ───
    $sheetRow = null;
    foreach (CycleReadings::sheet(['id' => 0, 'period_start' => '2025-03-01', 'period_end' => '2025-03-31']) as $row) {
        if ((int) $row['lease_id'] === $LH) { $sheetRow = $row; }
    }
    ok('U12.1 Readings sheet: previous 1,700 (derived), floor 1,000 (real)', '1700.00|1000.00',
        ($sheetRow['prev_odometer_km'] ?? '') . '|' . ($sheetRow['prev_odometer_floor_km'] ?? ''));

    // ── F1: "Generate all due" with an end reading on a Samsara lease ───────
    $LF = make_lease('F', 'samsara');
    $fan = (new InvoiceGenerator())->generateForLease([
        'lease_id' => $LF, 'period_start' => '2025-01-01', 'period_end' => '2025-03-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'single_segment' => false,
        'created_by' => 1, 'generation_source' => 'manual',
        'odometer_at_period_start_km' => '120000.00', 'odometer_at_period_end_km' => '130000.00', 'odometer_source' => 'manual',
    ]);
    $fanRows = db_select(
        "SELECT billing_period_start, odometer_at_period_start_km s, odometer_at_period_end_km e, period_distance_km d
           FROM invoices WHERE lease_id = ? AND deleted_at IS NULL ORDER BY billing_period_start", [$LF]);
    $fanSum = '0';
    foreach ($fanRows as $fr) { $fanSum = bcadd($fanSum, (string) ($fr['d'] ?? '0'), 2); }
    ok('F1.1 three months written (Jan/Feb by GPS, March with the readings)', 3, count($fanRows));
    ok('F1.2 March counts from 120,000 + 2 × 1,234.56 = 122,469.12', '122469.12', $fanRows[2]['s'] ?? null);
    ok('F1.3 lifetime billed distance = 130,000 − 120,000 exactly (was 12,469.12)', '10000.00', $fanSum);

    // ── HTTP ────────────────────────────────────────────────────────────────
    $probe = http_call('GET', "$baseUrl/api/v1/leases/show?id={$LS}");
    if ($probe['http_code'] !== 200) {
        throw new RuntimeException("HTTP preview not reachable at {$baseUrl} (code {$probe['http_code']}). "
            . 'Start it (launch.json "fleetforge") or set FF_SMOKE_BASE_URL / FF_SMOKE_SESS_DIR.');
    }

    // H3 first (before the close mutates the lease)
    $ro = $probe['json']['data']['odometer_readings'] ?? null;
    ok('H3.1 leases/show odometer_readings = the derived chain Generate Invoice counts from',
        '2025-01-31:124000:1|2025-02-28:128000:1|2025-03-31:129234.56:1',
        is_array($ro) ? implode('|', array_map(static fn ($e) => $e['period_end'] . ':' . (float) $e['km'] . ':' . (int) $e['derived'], $ro)) : 'missing');

    // H1 — the reported bug: close a Samsara lease after three distance-only months.
    $h1 = http_call('POST', "$baseUrl/api/v1/leases/close", [
        'id' => $LS, 'actual_return_date' => '2025-04-15',
        'odometer_at_close_km' => '130734.56', 'odometer_source' => 'manual', 'mileage_at_end' => 10734,
    ]);
    ok('H1.1 close → 200', 200, $h1['http_code']);
    if ($h1['http_code'] !== 200) echo "        body: " . substr($h1['body'], 0, 600) . "\n";
    $fin = db_row(
        "SELECT id, odometer_at_period_start_km s, period_distance_km d FROM invoices
          WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' AND odometer_at_period_end_km = '130734.56'
          ORDER BY id DESC LIMIT 1",
        [$LS]
    );
    ok('H1.2 final invoice counts from 129,234.56 (where the odometer stood), not 120,000', '129234.56', $fin['s'] ?? null);
    ok('H1.3 …so it bills the unbilled 1,500 km once (was 10,734.56)', '1500.00|1500.00',
        ($fin['d'] ?? '') . '|' . ($fin ? usage_qty((int) $fin['id']) : ''));
    $all = db_row(
        "SELECT SUM(li.quantity) q FROM invoice_line_items li JOIN invoices i ON i.id = li.invoice_id
          WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void' AND li.item_type IN ('mileage_usage','mileage')",
        [$LS]
    );
    ok('H1.4 lifetime billed distance = closing − start = 10,734.56 km exactly', '10734.56', bcadd((string) ($all['q'] ?? '0'), '0', 2));

    // H2 — manual lease, mid-month close: the closing month's full_month draft
    // (whose reading is AFTER the return) is voided by the close; the final
    // period must count from January's reading, not that draft's.
    $LM = make_lease('M', 'manual', ['odometer_start_km' => '0.00', 'odometer_start_source' => 'manual']);
    bill_month($LM, 1, ['odometer_at_period_start_km' => '0', 'odometer_at_period_end_km' => '1000.00', 'odometer_source' => 'manual']);
    bill_month($LM, 2, ['odometer_at_period_start_km' => '1000', 'odometer_at_period_end_km' => '2000.00', 'odometer_source' => 'manual']);
    $h2 = http_call('POST', "$baseUrl/api/v1/leases/close", [
        'id' => $LM, 'actual_return_date' => '2025-02-15',
        'odometer_at_close_km' => '1600.00', 'odometer_source' => 'manual', 'mileage_at_end' => 1600,
    ]);
    ok('H2.1 close → 200', 200, $h2['http_code']);
    if ($h2['http_code'] !== 200) echo "        body: " . substr($h2['body'], 0, 600) . "\n";
    $fin2 = db_row(
        "SELECT id, odometer_at_period_start_km s, period_distance_km d FROM invoices
          WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' AND odometer_at_period_end_km = '1600.00'
          ORDER BY id DESC LIMIT 1",
        [$LM]
    );
    ok('H2.2 final Feb 1–15 invoice counts from January\'s 1,000 → bills 600 km (was 0: counted from the voided draft\'s 2,000)',
        '1000.00|600.00|600.00', ($fin2['s'] ?? '') . '|' . ($fin2['d'] ?? '') . '|' . ($fin2 ? usage_qty((int) $fin2['id']) : ''));

    // H4 — last-day close (return 2025-03-31) with a sweep charge folds onto the
    // March GPS full_month draft. Its stamped start must be where the odometer
    // stood BEFORE March (128,000), not after it (129,234.56).
    $L4 = make_lease('C4', 'samsara');
    bill_month($L4, 1, ['period_distance_km' => '4000']);
    bill_month($L4, 2, ['period_distance_km' => '4000']);
    $mar4 = bill_month($L4, 3);   // GPS 1,234.56
    $h4 = http_call('POST', "$baseUrl/api/v1/leases/close", [
        'id' => $L4, 'actual_return_date' => '2025-03-31',
        'odometer_at_close_km' => '129234.56', 'odometer_source' => 'manual', 'mileage_at_end' => 9234,
        'sweep_amount' => '25.00',
    ]);
    ok('H4.1 close → 200', 200, $h4['http_code']);
    if ($h4['http_code'] !== 200) echo "        body: " . substr($h4['body'], 0, 600) . "\n";
    $m4 = db_row("SELECT odometer_at_period_start_km s, odometer_at_period_end_km e, period_distance_km d, status FROM invoices WHERE id = ?", [$mar4]);
    ok('H4.2 March draft (fold target) stamped 128,000 → 129,234.56, its own 1,234.56 kept', '128000.00|129234.56|1234.56|draft',
        ($m4['s'] ?? '') . '|' . ($m4['e'] ?? '') . '|' . ($m4['d'] ?? '') . '|' . ($m4['status'] ?? ''));
    $sw4 = db_row("SELECT COUNT(*) n FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'sweep'", [$mar4]);
    ok('H4.3 …and the sweep charge was folded onto it (the fold path ran)', 1, (int) ($sw4['n'] ?? 0));
} catch (\Throwable $e) {
    $FAILURES++;
    echo "\n  [FAIL] aborted: " . get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 78) . "\n";
printf("%d tests, %d failures\n", $TESTS, $FAILURES);
exit($FAILURES === 0 ? 0 : 1);
