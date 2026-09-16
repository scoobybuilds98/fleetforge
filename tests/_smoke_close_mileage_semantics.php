<?php
/**
 * tests/_smoke_close_mileage_semantics.php
 *
 * S-CLOSE-MILEAGE-SEMANTICS regression — the Close dialog's "Actual Mileage (for
 * billing)" is the DISTANCE driven, and close.php must validate + bill it as one.
 *
 * THE BUGS (found scripting the staff-training videos, confirmed on prod data):
 *   1. api/v1/leases/create.php auto-derives the legacy `mileage_at_start` from
 *      `odometer_start_km`, so close.php compared the dialog's distance (2,280)
 *      to the starting READING (45,000) → 422 "End mileage cannot be less than
 *      start mileage". Every lease that did not start at 0 km could not close.
 *   2. When the close generated a partial_end final invoice, the engine emitted a
 *      per-period `mileage_usage` line AND close.php added a `mileage` overage
 *      line for the same distance — a same-invoice double bill (prod drafts
 *      INV-2026-00795 / INV-2026-02131).
 *   3. The overage line billed the LIFETIME distance even when earlier invoices
 *      had already billed monthly odometer mileage (31 prod leases bill that way).
 *
 * Drives the REAL close + reopen endpoints over HTTP.
 *   A  fold path, start 45,000 km: close accepted, ONE mileage line = 2,280 km.
 *   B  partial_end path with a prior monthly mileage_usage (1,000 km billed):
 *      final invoice carries ONE mileage line for the unbilled 1,280 km, no
 *      engine mileage_usage beside it, odometer snapshot still written.
 *   C  reopen → reclose lease A with a corrected 2,500 km: lease-wide billed
 *      mileage is exactly 2,500 km (replace, not stack).
 *   D  true legacy lease (mileage_at_start, no odometer): a reading below the
 *      start is still rejected; a valid reading bills reading − start.
 *   U  pure helper cases.
 *
 * USAGE: php tests/_smoke_close_mileage_semantics.php
 *        FF_SMOKE_BASE_URL=http://localhost:8899/fleetforge FF_SMOKE_SESS_DIR=/tmp php tests/…
 * EXIT:  0 = all pass, 1 = any failure.
 *
 * @session S-CLOSE-MILEAGE-SEMANTICS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/api/v1/leases/_close_reconciliation.php';

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
    $e = is_bool($expected) ? ($expected ? 'true' : 'false') : (string) $expected;
    $a = is_bool($actual)   ? ($actual   ? 'true' : 'false') : (string) $actual;
    printf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label);
    if (!$pass) printf("        expected: %s\n        actual:   %s\n", $e, $a);
}

/**
 * POST JSON to an app endpoint with the smoke's session + CSRF token.
 *
 * @param string $url
 * @param array  $body
 * @param string $sessId
 * @param string $csrf
 * @return array{http_code:int, body:string}
 */
function http_post(string $url, array $body, string $sessId, string $csrf): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $csrf,
            'Cookie: ff_session=' . $sessId,
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => (int) $code, 'body' => is_string($resp) ? $resp : ''];
}

/**
 * Mileage-type lines ('mileage' overage + 'mileage_usage') on a lease's live invoices.
 *
 * @param int      $leaseId
 * @param int|null $invoiceId  restrict to one invoice
 * @return array<int, array>
 */
function mileage_lines(int $leaseId, ?int $invoiceId = null): array {
    $sql = "SELECT li.item_type, li.quantity, li.amount, i.id AS invoice_id, i.billing_type
              FROM invoice_line_items li JOIN invoices i ON i.id = li.invoice_id
             WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
               AND li.item_type IN ('mileage', 'mileage_usage')";
    $params = [$leaseId];
    if ($invoiceId !== null) { $sql .= " AND i.id = ?"; $params[] = $invoiceId; }
    return db_select($sql . " ORDER BY i.id, li.sort_order", $params);
}

/**
 * Sum a column of mileage_lines() rows as a 2-dp bcmath string.
 *
 * @param array  $rows
 * @param string $col
 * @return string
 */
function sum_col(array $rows, string $col): string {
    $t = '0';
    foreach ($rows as $r) { $t = bcadd($t, (string) $r[$col], 4); }
    return bcadd($t, '0', 2);
}

// ── Session bootstrap for HTTP (super_admin) ───────────────────────────────
// Same file-session pattern as _smoke_close_reconciliation_hours.php. Herd's
// php-fpm reads /var/tmp; a `php -S` dev server reads the CLI temp dir.
$baseUrl  = getenv('FF_SMOKE_BASE_URL') ?: 'http://fleetforge.test/fleetforge';
$sessDir  = rtrim(getenv('FF_SMOKE_SESS_DIR') ?: '/var/tmp', '/');
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = $sessDir . '/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_user|' . serialize([
        'id' => 1, 'name' => 'Mileage Semantics Bot', 'email' => 'mileage-semantics@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ]) .
    'ff_last_activity|' . serialize(time()) .
    'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);

// ── Fixtures (committed — HTTP can't share a txn; cleaned on shutdown) ─────
$unitRow = db_row("SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL LIMIT 1", []);
if (!$unitRow) { fwrite(STDERR, "No equipment_units in dev DB — cannot run smoke.\n"); exit(1); }
$unitId     = (int) $unitRow['id'];
$unitNumber = (string) $unitRow['unit_number'];
$prefix     = 'SMOKE-MSEM-' . date('YmdHis');

$customerId = db_insert('customers', [
    'company_name' => $prefix . ' Co', 'contact_name' => 'Mileage Semantics',
    'email' => strtolower($prefix) . '@example.invalid', 'phone' => '555-0401',
    'province' => 'BC', 'currency' => 'CAD',
    'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0, 'outstanding_balance' => '0.00',
]);

register_shutdown_function(static function () use ($sessFile, $customerId) {
    if (is_file($sessFile)) @unlink($sessFile);
    foreach (array_column(db_select("SELECT id FROM leases WHERE customer_id = ?", [$customerId]), 'id') as $lid) {
        foreach (array_column(db_select("SELECT id FROM invoices WHERE lease_id = ?", [$lid]), 'id') as $iid) {
            db_execute("DELETE FROM invoice_line_items    WHERE invoice_id = ?", [$iid]);
            db_execute("DELETE FROM lease_billing_periods WHERE invoice_id = ?", [$iid]);
            db_execute("DELETE FROM credit_notes          WHERE source_invoice_id = ?", [$iid]);
        }
        db_execute("DELETE FROM invoices              WHERE lease_id = ?", [$lid]);
        db_execute("DELETE FROM lease_billing_periods WHERE lease_id = ?", [$lid]);
        db_execute("DELETE FROM lease_status_log      WHERE lease_id = ?", [$lid]);
    }
    db_execute("DELETE FROM leases    WHERE customer_id = ?", [$customerId]);
    db_execute("DELETE FROM customers WHERE id = ?",          [$customerId]);
});

/**
 * Active manual-odometer lease shaped exactly like api/v1/leases/create.php writes
 * it (legacy mileage_at_start DERIVED from odometer_start_km), plus Invoice 1
 * (partial_start → month-end, like activate.php) optionally with an odometer
 * reading so it bills a per-period mileage_usage line.
 *
 * @param string      $tag
 * @param string      $startDate
 * @param string|null $odoStartKm     null = true legacy lease (no decimal odometer)
 * @param int         $mileageAtStart legacy integer start reading
 * @param string|null $inv1OdoEndKm   Invoice 1 closing reading (null = no mileage on Invoice 1)
 * @return array{0:int, 1:int}        [leaseId, invoice1Id]
 */
function make_lease(string $tag, string $startDate, ?string $odoStartKm, int $mileageAtStart, ?string $inv1OdoEndKm = null): array {
    global $customerId, $unitId, $unitNumber, $prefix;
    $leaseId = db_insert('leases', [
        'contract_number'         => $prefix . '-' . $tag,
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'unit_number_snapshot'    => $unitNumber,
        'company_name_snapshot'   => $prefix . ' Co',
        'customer_name_snapshot'  => 'Mileage Semantics',
        'status'                  => 'active',
        'start_date'              => $startDate,
        'monthly_rate'            => '1000.00', 'daily_rate' => '40.00', 'weekly_rate' => '250.00',
        'currency'                => 'CAD', 'billing_cycle' => 'monthly', 'advance_billing_periods' => 0,
        'gps_opt_in'              => 0, 'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'discount_type'           => 'none', 'discount_value' => '0.0000',
        'mileage_tracking_mode'   => 'manual',
        'mileage_rate'            => '0.2500', 'mileage_rate_km' => '0.2500', 'mileage_unit' => 'km',
        'estimated_mileage'       => '0.00',
        'mileage_at_start'        => $mileageAtStart,
        'odometer_start_km'       => $odoStartKm,
        'odometer_start_source'   => $odoStartKm !== null ? 'manual' : null,
        'total_invoiced'          => '0.00', 'total_paid' => '0.00', 'outstanding_balance' => '0.00',
        'next_billing_date'       => (new DateTimeImmutable($startDate))->modify('first day of next month')->format('Y-m-d'),
    ]);
    $params = [
        'lease_id' => $leaseId, 'period_start' => $startDate, 'period_end' => date('Y-m-t', strtotime($startDate)),
        'billing_type' => 'partial_start', 'invoice_type' => 'regular', 'created_by' => 1,
        'auto_generated' => 1, 'generation_source' => 'manual',
    ];
    if ($inv1OdoEndKm !== null) {
        $params += ['odometer_at_period_start_km' => $odoStartKm, 'odometer_at_period_end_km' => $inv1OdoEndKm, 'odometer_source' => 'manual'];
    }
    $inv = (new InvoiceGenerator())->createFromLease($params);
    return [$leaseId, (int) $inv['invoice_id']];
}

echo "S-CLOSE-MILEAGE-SEMANTICS — close mileage is a distance, billed exactly once\n";
echo str_repeat('=', 78) . "\n\n";

$lastMonth  = (new DateTimeImmutable('first day of last month'));
$twoMonths  = (new DateTimeImmutable('first day of -2 months'));
$day = static fn(DateTimeImmutable $m, int $d) => $m->modify('+' . ($d - 1) . ' days')->format('Y-m-d');

try {
    // ── U: pure helper cases ────────────────────────────────────────────────
    ok('U.1 odometer lease → mileage_at_end is a distance', true,
        ff_close_mileage_at_end_is_distance(['mileage_at_start' => 45000, 'odometer_start_km' => '45000.00']));
    ok('U.2 true legacy lease (start reading, no odometer) → end reading', false,
        ff_close_mileage_at_end_is_distance(['mileage_at_start' => 45000, 'odometer_start_km' => null]));
    ok('U.3 no start captured at all → distance (manual bridge semantics)', true,
        ff_close_mileage_at_end_is_distance(['mileage_at_start' => null, 'odometer_start_km' => null]));
    ok('U.4 lifetime distance of a legacy reading = reading − start', 2280,
        ff_close_lifetime_distance(['mileage_at_start' => 45000, 'odometer_start_km' => null], 47280));

    // ── A: fold path (return inside the activation invoice's month) ─────────
    [$leaseA] = make_lease('A', $day($lastMonth, 5), '45000.00', 45000);
    $rA = http_post("$baseUrl/api/v1/leases/close", [
        'id' => $leaseA, 'actual_return_date' => $day($lastMonth, 15),
        'odometer_at_close_km' => '47280.00', 'odometer_source' => 'manual', 'mileage_at_end' => 2280,
    ], $sessId, $csrf);
    ok('A.1 close of a 45,000 km-start lease returns 200 (was 422 MILEAGE_DATA_ERROR)', 200, $rA['http_code']);
    if ($rA['http_code'] !== 200) { echo "    body: {$rA['body']}\n"; throw new RuntimeException('close A failed'); }
    $mlA = mileage_lines($leaseA);
    ok('A.2 exactly ONE mileage line on the lease', 1, count($mlA));
    ok('A.3 it bills 2,280 km × $0.25 = $570.00', '570.00', sum_col($mlA, 'amount'));
    ok('A.4 leases.actual_mileage = 2280.00 (distance, not distance − start)', '2280.00',
        (string) db_row("SELECT actual_mileage FROM leases WHERE id = ?", [$leaseA])['actual_mileage']);

    // ── B: partial_end path after a month already billed 1,000 km ───────────
    [$leaseB, $inv1B] = make_lease('B', $day($twoMonths, 5), '45000.00', 45000, '46000.00');
    ok('B.0 Invoice 1 billed 1,000 km of per-period mileage_usage', '1000.00', sum_col(mileage_lines($leaseB, $inv1B), 'quantity'));
    $rB = http_post("$baseUrl/api/v1/leases/close", [
        'id' => $leaseB, 'actual_return_date' => $day($lastMonth, 15),
        'odometer_at_close_km' => '47280.00', 'odometer_source' => 'manual', 'mileage_at_end' => 2280,
    ], $sessId, $csrf);
    ok('B.1 close returned 200', 200, $rB['http_code']);
    if ($rB['http_code'] !== 200) { echo "    body: {$rB['body']}\n"; throw new RuntimeException('close B failed'); }
    $finalB = db_row(
        "SELECT id, odometer_at_period_end_km FROM invoices
          WHERE lease_id = ? AND billing_type = 'partial_end' AND deleted_at IS NULL AND status <> 'void'
          ORDER BY id DESC LIMIT 1", [$leaseB]);
    ok('B.2 a partial_end final invoice was generated (engine path exercised)', true, (bool) $finalB);
    $mlFinal = $finalB ? mileage_lines($leaseB, (int) $finalB['id']) : [];
    ok('B.3 final invoice carries ONE mileage line (was mileage_usage + mileage)', 1, count($mlFinal));
    ok('B.4 it bills only the unbilled 1,280 km (2,280 lifetime − 1,000 billed)', '1280.00', sum_col($mlFinal, 'quantity'));
    ok('B.5 lease-wide billed mileage = 2,280 km, $570.00', '2280.00|570.00',
        sum_col(mileage_lines($leaseB), 'quantity') . '|' . sum_col(mileage_lines($leaseB), 'amount'));
    ok('B.6 odometer snapshot still written on the final invoice', '47280.00',
        (string) ($finalB['odometer_at_period_end_km'] ?? ''));

    // ── C: reopen → reclose lease A with a corrected reading ────────────────
    $rCo = http_post("$baseUrl/api/v1/leases/reopen", ['id' => $leaseA, 'reopen_reason' => 'mileage correction smoke'], $sessId, $csrf);
    ok('C.1 reopen returned 200', 200, $rCo['http_code']);
    $rC = http_post("$baseUrl/api/v1/leases/close", [
        'id' => $leaseA, 'actual_return_date' => $day($lastMonth, 15),
        'odometer_at_close_km' => '47500.00', 'odometer_source' => 'manual', 'mileage_at_end' => 2500,
    ], $sessId, $csrf);
    ok('C.2 reclose returned 200', 200, $rC['http_code']);
    if ($rC['http_code'] !== 200) { echo "    body: {$rC['body']}\n"; throw new RuntimeException('reclose C failed'); }
    $mlC = mileage_lines($leaseA);
    ok('C.3 lease-wide billed mileage = corrected 2,500 km, $625.00 (not 2,280 + 2,500)', '2500.00|625.00',
        sum_col($mlC, 'quantity') . '|' . sum_col($mlC, 'amount'));

    // ── D: true legacy lease — end READING semantics preserved ─────────────
    [$leaseD] = make_lease('D', $day($lastMonth, 5), null, 45000);
    $rD1 = http_post("$baseUrl/api/v1/leases/close", [
        'id' => $leaseD, 'actual_return_date' => $day($lastMonth, 15), 'mileage_at_end' => 44000,
    ], $sessId, $csrf);
    ok('D.1 legacy reading below the start reading is still rejected (422)', 422, $rD1['http_code']);
    $rD2 = http_post("$baseUrl/api/v1/leases/close", [
        'id' => $leaseD, 'actual_return_date' => $day($lastMonth, 15), 'mileage_at_end' => 47280,
    ], $sessId, $csrf);
    ok('D.2 legacy reading 47,280 closes (200)', 200, $rD2['http_code']);
    if ($rD2['http_code'] !== 200) { echo "    body: {$rD2['body']}\n"; }
    ok('D.3 bills reading − start = 2,280 km, $570.00', '2280.00|570.00',
        sum_col(mileage_lines($leaseD), 'quantity') . '|' . sum_col(mileage_lines($leaseD), 'amount'));
} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $FAILURES++;
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%s — %d test(s), %d failure(s)\n", $FAILURES === 0 ? 'ALL PASS' : 'FAILURES', $TESTS, $FAILURES);
exit($FAILURES === 0 ? 0 : 1);
