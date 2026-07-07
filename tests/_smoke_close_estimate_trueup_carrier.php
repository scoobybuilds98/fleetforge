<?php
declare(strict_types=1);

/**
 * tests/_smoke_close_estimate_trueup_carrier.php
 *
 * S-CLOSE-NO-ESTIMATE — close-path guarantee that the estimate-model mileage
 * true-up ALWAYS gets an engine run at close, driven through the REAL
 * api/v1/leases/close endpoint (HTTP, super_admin session) against the live
 * dev server — same harness as _smoke_legacy_close_overshoot.php.
 *
 * THE GAP: close.php's legacy path only ran InvoiceGenerator when it generated
 * the partial_end final invoice. Two shapes skip that call: (a) a last-day
 * close onto an existing cron full_month draft (append branch), and (b) a
 * close within an already-billed period (S-FIX-2 Bug #6 skip). The fold
 * helpers append raw lines without running the engine, so an estimate lease
 * with a closing reading ended those closes UN-reconciled — the estimates
 * stood and the actual reading was silently ignored.
 *
 * Scenarios:
 *   A  Last-day close, existing full_month draft (append branch): a standalone
 *      mileage_only carrier now settles the lease — actual 1300 km × $0.50 =
 *      $650 vs $600 estimated → +$50.00 mileage_adjustment, NO estimate line,
 *      original draft NOT voided, response action 'estimate_trueup_carrier'.
 *   B  Mid-month close (void branch → partial_end regenerates): the engine run
 *      already carries the true-up, so NO carrier is created (no duplicate
 *      settle). The voided draft's $600 estimate is excluded from billed-to-
 *      date, so the whole $650.00 bills as one mileage_adjustment on the
 *      partial_end — which also carries NO stub estimate (final-settlement
 *      suppression).
 *
 * Requires: local dev server at http://fleetforge.test/fleetforge (Herd).
 * Not hermetic (HTTP closes commit); creates SMOKE-ETC-* rows and soft-deletes
 * them on exit (best effort).
 *
 * Run: php tests/_smoke_close_estimate_trueup_carrier.php
 *
 * @session S-CLOSE-NO-ESTIMATE
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

$FAILURES = 0; $TESTS = 0;
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
function okTrue(string $label, bool $cond): void { ok($label, true, $cond); }

// ── Preflight: dev server must be up (mirror of the overshoot smoke) ───────
$baseUrl = 'http://fleetforge.test/fleetforge';
$ch = curl_init("$baseUrl/api/v1/health");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
curl_exec($ch);
$healthCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($healthCode !== 200) {
    fwrite(STDERR, "Dev server not reachable at $baseUrl (health={$healthCode}) — start Herd first.\n");
    exit(1);
}

// ── Session bootstrap for HTTP (super_admin) ───────────────────────────────
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = '/var/tmp/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_user|' . serialize([
        'id' => 1, 'name' => 'TrueUp Bot', 'email' => 'trueup@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ]) .
    'ff_last_activity|' . serialize(time()) .
    'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);
register_shutdown_function(static function () use ($sessFile) {
    if (is_file($sessFile)) @unlink($sessFile);
});

function http_post(string $url, array $body, string $sessId, string $csrf): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-Token: ' . $csrf,
            'Cookie: ff_session=' . $sessId,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'http_code' => (int) $code,
        'body'      => is_string($resp) ? $resp : '',
        'json'      => is_string($resp) ? json_decode($resp, true) : null,
    ];
}

// ── Fixtures ────────────────────────────────────────────────────────────────
$unitRow = db_row("SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL LIMIT 1", []);
if (!$unitRow) { fwrite(STDERR, "No equipment_units in dev DB.\n"); exit(1); }
$unitId     = (int) $unitRow['id'];
$unitNumber = (string) $unitRow['unit_number'];
$prefix     = 'SMOKE-ETC-' . date('YmdHis');

$customerId = db_insert('customers', [
    'company_name' => $prefix . ' Co', 'contact_name' => 'TrueUp',
    'email' => strtolower($prefix) . '@example.invalid', 'phone' => '555-0301',
    'province' => 'BC', 'currency' => 'CAD',
    'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0, 'outstanding_balance' => '0.00',
]);

$createdLeases = [];
register_shutdown_function(static function () use (&$createdLeases, $customerId) {
    // Best-effort tidy-up of the committed HTTP-close artifacts.
    foreach ($createdLeases as $lid) {
        db_execute("UPDATE invoices SET deleted_at = NOW() WHERE lease_id = ? AND deleted_at IS NULL", [$lid]);
        db_execute("UPDATE credit_notes SET deleted_at = NOW() WHERE lease_id = ? AND deleted_at IS NULL", [$lid]);
        db_execute("UPDATE leases SET deleted_at = NOW() WHERE id = ?", [$lid]);
    }
    db_execute("UPDATE customers SET deleted_at = NOW() WHERE id = ?", [$customerId]);
});

/** Estimate-model manual lease: per-day 40 km, $0.50/km, monthly cycle. */
function make_estimate_lease(int $customerId, int $unitId, string $unitNumber, string $prefix, string $tag, string $startDate): int
{
    return db_insert('leases', [
        'contract_number'         => $prefix . '-' . $tag,
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'unit_number_snapshot'    => $unitNumber,
        'company_name_snapshot'   => $prefix . ' Co',
        'customer_name_snapshot'  => 'TrueUp',
        'status'                  => 'active',
        'start_date'              => $startDate,
        'monthly_rate'            => '1000.00',
        'daily_rate'              => '40.00',
        'weekly_rate'             => '250.00',
        'currency'                => 'CAD',
        'billing_cycle'           => 'monthly',
        'advance_billing_periods' => 0,
        'gps_opt_in'              => 0,
        'gst_exempt'              => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'discount_type'           => 'none', 'discount_value' => '0.0000',
        'mileage_unit'            => 'km',
        'mileage_rate'            => '0.5000',
        'mileage_rate_km'         => '0.5000',
        'mileage_rate_miles'      => '0.8047',
        'mileage_tracking_mode'   => 'manual',
        'estimated_mileage_per_day'     => '40.00',
        'estimated_mileage_per_day_km'  => '40.0000',
        'km_to_miles_conversion'  => '0.621371',
        'miles_to_km_conversion'  => '1.609344',
        'total_invoiced'          => '0.00',
        'total_paid'              => '0.00',
        'outstanding_balance'     => '0.00',
        'next_billing_date'       => (new DateTimeImmutable($startDate))->modify('first day of next month')->format('Y-m-d'),
    ]);
}

$line = fn (int $iv, string $type) => db_row(
    "SELECT amount, description FROM invoice_line_items WHERE invoice_id = ? AND item_type = ?",
    [$iv, $type]
);

echo "S-CLOSE-NO-ESTIMATE — close-path estimate true-up carrier guarantee (HTTP)\n";
echo str_repeat('=', 78) . "\n\n";

// Fixed reference month: last month (past, so extents are unambiguous).
$monthStart = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthDays  = (int) date('t', strtotime($monthStart));
$estAmt     = bcmul((string) ($monthDays * 40), '0.50', 2); // days × 40 km × $0.50

$gen = new InvoiceGenerator();

try {

// ══════════════════════════════════════════════════════════════════════════
// CASE A — last-day close onto an existing full_month draft (append branch)
// ══════════════════════════════════════════════════════════════════════════
echo "CASE A — last-day close, existing full_month draft → mileage_only carrier\n";
$leaseA = make_estimate_lease($customerId, $unitId, $unitNumber, $prefix, 'A', $monthStart);
$createdLeases[] = $leaseA;
$draftA = $gen->createFromLease([ // what the monthly cron would have drafted
    'lease_id' => $leaseA, 'period_start' => $monthStart, 'period_end' => $monthEnd,
    'billing_type' => 'full_month', 'invoice_type' => 'regular',
    'created_by' => 1, 'auto_generated' => 1, 'generation_source' => 'cron',
]);
ok("A.0 cron draft carries the month estimate (\${$estAmt})", $estAmt,
    (string) ($line((int) $draftA['invoice_id'], 'mileage_estimate')['amount'] ?? 'none'));

$respA = http_post("$baseUrl/api/v1/leases/close", [
    'id' => $leaseA, 'actual_return_date' => $monthEnd,
    'mileage_at_end' => 1300, 'close_notes' => 'trueup carrier smoke A',
], $sessId, $csrf);
ok("A.1 close returned 200", 200, $respA['http_code']);

$actionsA = array_column($respA['json']['data']['advance_actions'] ?? $respA['json']['advance_actions'] ?? [], 'action');
okTrue("A.2 response actions include estimate_trueup_carrier", in_array('estimate_trueup_carrier', $actionsA, true));

$carrierA = db_row(
    "SELECT id, status, total_amount FROM invoices
      WHERE lease_id = ? AND billing_type = 'mileage_only' AND deleted_at IS NULL AND status <> 'void'",
    [$leaseA]
);
okTrue("A.3 mileage_only carrier invoice exists", $carrierA !== null);
// target 1300 × 0.50 = 650; billed = month estimate → true-up = 650 − est
$expTrueUpA = bcsub('650.00', $estAmt, 2);
ok("A.4 carrier true-up charge = \${$expTrueUpA} (1300 km × \$0.50 − \${$estAmt} estimated)",
    $expTrueUpA, (string) ($line((int) ($carrierA['id'] ?? 0), 'mileage_adjustment')['amount'] ?? 'none'));
okTrue("A.5 carrier has NO estimate line", $line((int) ($carrierA['id'] ?? 0), 'mileage_estimate') === null);
ok("A.6 original full_month draft NOT voided", 'draft',
    (string) (db_row("SELECT status FROM invoices WHERE id = ?", [(int) $draftA['invoice_id']])['status'] ?? 'gone'));

// ══════════════════════════════════════════════════════════════════════════
// CASE B — mid-month close (void branch → partial_end runs the engine):
//          the true-up rides the partial_end; NO duplicate carrier.
// ══════════════════════════════════════════════════════════════════════════
echo "\nCASE B — mid-month close → true-up on the partial_end, NO carrier\n";
$leaseB = make_estimate_lease($customerId, $unitId, $unitNumber, $prefix, 'B', $monthStart);
$createdLeases[] = $leaseB;
$draftB = $gen->createFromLease([
    'lease_id' => $leaseB, 'period_start' => $monthStart, 'period_end' => $monthEnd,
    'billing_type' => 'full_month', 'invoice_type' => 'regular',
    'created_by' => 1, 'auto_generated' => 1, 'generation_source' => 'cron',
]);
$midMonth = (new DateTimeImmutable($monthStart))->modify('+19 days')->format('Y-m-d'); // day 20

$respB = http_post("$baseUrl/api/v1/leases/close", [
    'id' => $leaseB, 'actual_return_date' => $midMonth,
    'mileage_at_end' => 1300, 'close_notes' => 'trueup carrier smoke B',
], $sessId, $csrf);
ok("B.1 close returned 200", 200, $respB['http_code']);

$actionsB = array_column($respB['json']['data']['advance_actions'] ?? $respB['json']['advance_actions'] ?? [], 'action');
okTrue("B.2 NO estimate_trueup_carrier action (partial_end carried the true-up)",
    !in_array('estimate_trueup_carrier', $actionsB, true));
okTrue("B.3 NO mileage_only invoice created", db_row(
    "SELECT id FROM invoices WHERE lease_id = ? AND billing_type = 'mileage_only' AND deleted_at IS NULL AND status <> 'void'",
    [$leaseB]
) === null);

$finalB = db_row(
    "SELECT id FROM invoices
      WHERE lease_id = ? AND billing_type = 'partial_end' AND deleted_at IS NULL AND status <> 'void'
      ORDER BY id DESC LIMIT 1",
    [$leaseB]
);
okTrue("B.4 partial_end final invoice exists (voided draft replaced)", $finalB !== null);
// Voided draft's estimate is excluded from billed-to-date → the whole actual
// bills as one adjustment: 1300 × 0.50 = 650.00. Final-settlement suppression
// keeps the stub estimate off it.
ok("B.5 partial_end true-up = \$650.00 (whole actual; voided estimate excluded)",
    '650.00', (string) ($line((int) ($finalB['id'] ?? 0), 'mileage_adjustment')['amount'] ?? 'none'));
okTrue("B.6 partial_end has NO stub estimate line (final-settlement suppression)",
    $line((int) ($finalB['id'] ?? 0), 'mileage_estimate') === null);

} catch (\Throwable $e) {
    $FAILURES++;
    echo "EXCEPTION: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

echo "\n" . str_repeat('=', 78) . "\n";
if ($FAILURES) { echo "FAILURES — {$TESTS} test(s), {$FAILURES} failure(s)\n"; exit(1); }
echo "ALL PASS — {$TESTS} test(s), 0 failure(s)\n";
exit(0);
