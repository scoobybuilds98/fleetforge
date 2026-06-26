<?php
/**
 * tests/_smoke_close_reconciliation_hours.php
 *
 * S-LEASE-HOURLY-RECON regression — proves that closing a lease whose rental
 * invoice OVERSHOT the return date still bills the lease's engine/reefer hours.
 *
 * THE BUG (production, lease MTTS0262 / INV-2026-00466): an hourly lease
 * (hourly_rate>0, engine_hours_at_start set) had a draft partial_start invoice
 * billed to month-end (the activation over-bill). On close mid-month the close
 * overshoot-reconciliation VOIDED that invoice and reissued it clamped to the
 * return date — but the reissue's InvoiceGenerator::createFromLease call omitted
 * the engine-hours window, so the hourly_usage line silently vanished and the
 * partial_end final invoice (which would otherwise bill the hours) was skipped
 * because coverage already reached the extent. Net: the customer was never billed
 * for engine hours.
 *
 * THE FIX: close.php now forwards the engine-hours window into
 * reconcile_overshoot_invoices() → adv_partial_refund_containing(), so the clamped
 * reissue — which is the lease's FINAL rental invoice — carries the hourly_usage
 * line, exactly like the non-overshoot partial_end final invoice already does.
 * regenerate.php likewise carries an invoice's own hours snapshot forward.
 *
 * Drives the REAL close + regenerate endpoints over HTTP, building the activation
 * invoice the SAME way activate.php's legacy path does (partial_start → month-end).
 *
 *   R1  close mid-month → original overshoot invoice voided, one live replacement.
 *   R2  the replacement carries an hourly_usage line = (close-start) × rate.
 *   R3  the line amount = 31.00 hrs × $1.50 = $46.50 (the INV-466 numbers).
 *   R4  the replacement snapshots engine_hours_at_period_start/end.
 *   R5  regenerating the replacement preserves the hourly_usage line (no drop).
 *
 * USAGE: php tests/_smoke_close_reconciliation_hours.php
 * EXIT:  0 = all pass, 1 = any failure.
 *
 * @session S-LEASE-HOURLY-RECON
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

$FAILURES = 0;
$TESTS    = 0;

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

// ── Session bootstrap for HTTP (super_admin) ───────────────────────────────
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = '/var/tmp/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_user|' . serialize([
        'id' => 1, 'name' => 'Hourly Recon Bot', 'email' => 'hourly-recon@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ]) .
    'ff_last_activity|' . serialize(time()) .
    'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);

$baseUrl = getenv('FF_SMOKE_BASE_URL') ?: 'http://fleetforge.test/fleetforge';

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

function live_invoices(int $leaseId): array {
    return db_select(
        "SELECT id, status, billing_type, billing_period_start, billing_period_end,
                billing_period_days, total_amount, generation_source,
                engine_hours_at_period_start, engine_hours_at_period_end
           FROM invoices WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' ORDER BY id",
        [$leaseId]
    );
}
function hourly_line(int $invoiceId): ?array {
    return db_row(
        "SELECT amount, quantity, unit FROM invoice_line_items
          WHERE invoice_id = ? AND item_type = 'hourly_usage'",
        [$invoiceId]
    );
}

// ── Fixtures ───────────────────────────────────────────────────────────────
$unitRow = db_row("SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL LIMIT 1", []);
if (!$unitRow) { fwrite(STDERR, "No equipment_units available in dev DB — cannot run smoke.\n"); exit(1); }
$unitId     = (int) $unitRow['id'];
$unitNumber = (string) $unitRow['unit_number'];
$prefix     = 'SMOKE-HRECON-' . date('YmdHis');

$customerId = db_insert('customers', [
    'company_name' => $prefix . ' Co', 'contact_name' => 'Hourly Recon',
    'email' => strtolower($prefix) . '@example.invalid', 'phone' => '555-0400',
    'province' => 'BC', 'currency' => 'CAD',
    'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0, 'outstanding_balance' => '0.00',
]);

// Cleanup keyed on the test customer — committed fixtures (HTTP can't share a txn).
register_shutdown_function(static function () use ($sessFile, $customerId) {
    if (is_file($sessFile)) @unlink($sessFile);
    $leaseIds = array_column(db_select("SELECT id FROM leases WHERE customer_id = ?", [$customerId]), 'id');
    foreach ($leaseIds as $lid) {
        $invIds = array_column(db_select("SELECT id FROM invoices WHERE lease_id = ?", [$lid]), 'id');
        foreach ($invIds as $iid) {
            db_execute("DELETE FROM invoice_line_items   WHERE invoice_id = ?", [$iid]);
            db_execute("DELETE FROM lease_billing_periods WHERE invoice_id = ?", [$iid]);
            db_execute("DELETE FROM credit_notes          WHERE source_invoice_id = ?", [$iid]);
        }
        db_execute("DELETE FROM invoices             WHERE lease_id = ?", [$lid]);
        db_execute("DELETE FROM lease_billing_periods WHERE lease_id = ?", [$lid]);
    }
    db_execute("DELETE FROM leases    WHERE customer_id = ?", [$customerId]);
    db_execute("DELETE FROM customers WHERE id = ?",          [$customerId]);
});

/** Active hourly lease + activation Invoice 1 (partial_start → month-end), like INV-466. */
function make_hourly_lease_with_invoice1(
    int $customerId, int $unitId, string $unitNumber, string $prefix, string $tag,
    string $startDate, string $hourlyRate, string $hoursAtStart
): array {
    $leaseId = db_insert('leases', [
        'contract_number'         => $prefix . '-' . $tag,
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'unit_number_snapshot'    => $unitNumber,
        'company_name_snapshot'   => $prefix . ' Co',
        'customer_name_snapshot'  => 'Hourly Recon',
        'status'                  => 'active',
        'start_date'              => $startDate,
        'start_time'              => null,
        'monthly_rate'            => '1000.00',
        'daily_rate'              => '40.00',
        'weekly_rate'             => '250.00',
        'hourly_rate'             => $hourlyRate,
        'engine_hours_at_start'   => $hoursAtStart,
        'currency'                => 'CAD',
        'billing_cycle'           => 'monthly',
        'advance_billing_periods' => 0,
        'gps_opt_in'              => 0,
        'gst_exempt'              => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'discount_type'           => 'none', 'discount_value' => '0.0000',
        'mileage_rate'            => '0.0000', 'mileage_unit' => 'km',
        'total_invoiced'          => '0.00', 'total_paid' => '0.00', 'outstanding_balance' => '0.00',
        'next_billing_date'       => (new DateTimeImmutable($startDate))->modify('first day of next month')->format('Y-m-d'),
    ]);

    $monthEnd = date('Y-m-t', strtotime($startDate));
    $gen = new InvoiceGenerator();
    $inv = $gen->createFromLease([
        'lease_id'          => $leaseId,
        'period_start'      => $startDate,
        'period_end'        => $monthEnd,        // activate.php legacy path: bills to month-end (the overshoot)
        'billing_type'      => 'partial_start',
        'invoice_type'      => 'regular',
        'created_by'        => 1,
        'auto_generated'    => 1,
        'generation_source' => 'manual',
    ]);
    return [$leaseId, (int) $inv['invoice_id'], $monthEnd];
}

echo "S-LEASE-HOURLY-RECON — engine hours survive close overshoot-reconciliation\n";
echo str_repeat('=', 78) . "\n\n";

// Fixed reference month in the past so cron timing / month-length are irrelevant.
$refMonthStart = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
$day = static fn(int $d) => (new DateTimeImmutable($refMonthStart))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');

try {
    // INV-466 numbers: hourly_rate 1.50, hours 8967 → 8998 = 31.00 hrs = $46.50.
    $RATE = '1.5000'; $H_START = '8967.00'; $H_CLOSE = '8998.00';
    $EXPECT_HOURS = '31.00'; $EXPECT_AMT = '46.50';

    [$leaseR, $invR, $meR] = make_hourly_lease_with_invoice1(
        $customerId, $unitId, $unitNumber, $prefix, 'R', $day(5), $RATE, $H_START
    );

    $inv1 = db_row("SELECT billing_type, billing_period_end FROM invoices WHERE id = ?", [$invR]);
    ok('R.0 activation Invoice 1 is partial_start billed to month-end',
        'partial_start|' . $meR, $inv1['billing_type'] . '|' . $inv1['billing_period_end']);
    okTrue('R.0b activation invoice has NO hourly line yet (no close reading)',
        hourly_line($invR) === null);

    $returnR = $day(15);
    $resp = http_post("$baseUrl/api/v1/leases/close", [
        'id' => $leaseR, 'actual_return_date' => $returnR,
        'engine_hours_at_close' => $H_CLOSE,
        'close_notes' => 'hourly recon smoke',
    ], $sessId, $csrf);
    ok('R.1a close returned 200', 200, $resp['http_code']);
    if ($resp['http_code'] !== 200) { echo "    body: {$resp['body']}\n"; throw new RuntimeException('close failed'); }
    $data = $resp['json']['data'] ?? $resp['json'];

    $ov = $data['overshoot_actions'] ?? [];
    ok('R.1b overshoot reissued the straddle (void+regenerate)', 'replaced', (string) ($ov[0]['action'] ?? ''));
    ok('R.1c original Invoice 1 now void', 'void', (string) db_row("SELECT status FROM invoices WHERE id = ?", [$invR])['status']);

    $live = live_invoices($leaseR);
    ok('R.1d exactly one LIVE invoice remains (the clamped reissue)', 1, count($live));
    $repl = $live[0] ?? [];
    $replId = (int) ($repl['id'] ?? 0);
    ok('R.1e reissue period = lease start → return (clamped)',
        $day(5) . '|' . $returnR,
        (string) ($repl['billing_period_start'] ?? '') . '|' . (string) ($repl['billing_period_end'] ?? ''));

    // R2 — the bug: this line was missing before the fix.
    $ln = $replId ? hourly_line($replId) : null;
    okTrue('R.2 reissue carries an hourly_usage line (was dropped pre-fix)', $ln !== null);

    // R3 — the exact INV-466 numbers.
    ok('R.3 hourly_usage amount = 31.00 hrs × $1.50 = $46.50', $EXPECT_AMT, (string) ($ln['amount'] ?? 'none'));
    okTrue('R.3b hourly_usage quantity = 31 hrs (' . (string) ($ln['quantity'] ?? 'none') . ')',
        $ln !== null && bccomp((string) $ln['quantity'], $EXPECT_HOURS, 2) === 0);

    // R4 — the reissue snapshots the hours window for audit/regenerate.
    ok('R.4 reissue snapshots engine_hours_at_period_start/end',
        $H_START . '|' . $H_CLOSE,
        (string) ($repl['engine_hours_at_period_start'] ?? '') . '|' . (string) ($repl['engine_hours_at_period_end'] ?? ''));

    // ── R5 — regenerate the reissue → hours snapshot preserved (regenerate.php fix) ──
    $upd = (string) (db_row("SELECT updated_at FROM invoices WHERE id = ?", [$replId])['updated_at'] ?? '');
    $rg  = http_post("$baseUrl/api/v1/invoices/regenerate", ['id' => $replId, 'updated_at' => $upd], $sessId, $csrf);
    ok('R.5a regenerate returned 200', 200, $rg['http_code']);
    if ($rg['http_code'] !== 200) { echo "    body: {$rg['body']}\n"; }
    // regenerate reuses the same invoice number; re-resolve the live invoice id.
    $live2 = live_invoices($leaseR);
    $repl2Id = (int) ($live2[0]['id'] ?? 0);
    $ln2 = $repl2Id ? hourly_line($repl2Id) : null;
    okTrue('R.5b regenerated invoice STILL has the hourly_usage line', $ln2 !== null);
    ok('R.5c regenerated hourly_usage amount unchanged ($46.50)', $EXPECT_AMT, (string) ($ln2['amount'] ?? 'none'));

} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $FAILURES++;
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("%s — %d test(s), %d failure(s)\n", $FAILURES === 0 ? 'ALL PASS' : 'FAILURES', $TESTS, $FAILURES);
exit($FAILURES === 0 ? 0 : 1);
