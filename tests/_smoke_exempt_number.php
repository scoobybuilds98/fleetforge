<?php
declare(strict_types=1);
/**
 * tests/_smoke_exempt_number.php
 *
 * Smoke: PST/GST exemption certificate numbers flow from customer → lease → invoice.
 * Run via: php tests/_smoke_exempt_number.php
 */
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/helpers/Assert.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Tests\Assert;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

$pass = 0;
$fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label\n"; $pass++; }
    else        { echo "  FAIL: $label\n"; $fail++; }
}

echo "\n=== PST/GST Exemption Certificate Number End-to-End ===\n\n";

// ── TEST 1: Both exempt with certificate numbers ─────────────────────────────
echo "TEST 1: Both GST + PST exempt with cert numbers (BC)\n";
DbState::inTransaction(function () {
    $custId = db_insert('customers', [
        'contact_name'      => 'Exempt Corp ' . uniqid(),
        'company_name'      => 'Exempt Corp Ltd',
        'email'             => uniqid('e-') . '@exempt.test',
        'province'          => 'BC',
        'country'           => 'Canada',
        'gst_exempt'        => 1,
        'pst_exempt'        => 1,
        'gst_exempt_number' => 'GST-CERT-12345',
        'pst_exempt_number' => 'PST-CERT-67890',
    ]);

    // Fixtures::createLease returns int (lease_id); pass exemptions explicitly
    $leaseId = Fixtures::createLease($custId, [
        'start_date'     => '2026-06-01',
        'engine_version' => 'holistic',
        'daily_rate'     => '200.00',
        'monthly_rate'   => '4000.00',
        'gst_exempt'     => 1,
        'pst_exempt'     => 1,
        'tax_exempt'     => 1,
    ]);
    $leaseRow = db_row("SELECT gst_exempt, pst_exempt FROM leases WHERE id=?", [$leaseId]);

    ok('lease.gst_exempt = 1', (int)$leaseRow['gst_exempt'] === 1);
    ok('lease.pst_exempt = 1', (int)$leaseRow['pst_exempt'] === 1);

    $inv = Fixtures::generateInvoice($leaseId, '2026-06-01', '2026-06-30', 'full_month');
    $row = db_row(
        "SELECT gst_exempt_snapshot, pst_exempt_snapshot, tax_exempt_snapshot,
                gst_exempt_number_snapshot, pst_exempt_number_snapshot, tax_total
         FROM invoices WHERE id=?",
        [$inv['invoice_id']]
    );

    ok('gst_exempt_snapshot = 1',           (int)$row['gst_exempt_snapshot'] === 1);
    ok('pst_exempt_snapshot = 1',           (int)$row['pst_exempt_snapshot'] === 1);
    ok('tax_exempt_snapshot = 1',           (int)$row['tax_exempt_snapshot'] === 1);
    ok('gst_exempt_number_snapshot saved',  $row['gst_exempt_number_snapshot'] === 'GST-CERT-12345');
    ok('pst_exempt_number_snapshot saved',  $row['pst_exempt_number_snapshot'] === 'PST-CERT-67890');
    ok('tax_total = 0 (no tax charged)',    bccomp((string)$row['tax_total'], '0', 2) === 0);
});

// ── TEST 2: GST-only exempt ───────────────────────────────────────────────────
echo "\nTEST 2: GST-only exempt — cert number stored, PST still charged (BC)\n";
DbState::inTransaction(function () {
    $custId = db_insert('customers', [
        'contact_name'      => 'GST Only ' . uniqid(),
        'company_name'      => 'GST Only Inc',
        'email'             => uniqid('e-') . '@gstonly.test',
        'province'          => 'BC',
        'country'           => 'Canada',
        'gst_exempt'        => 1,
        'pst_exempt'        => 0,
        'gst_exempt_number' => 'GST-FN-99999',
    ]);

    $leaseId = Fixtures::createLease($custId, [
        'start_date'     => '2026-06-01',
        'engine_version' => 'holistic',
        'daily_rate'     => '200.00',
        'monthly_rate'   => '4000.00',
        'gst_exempt'     => 1,
        'pst_exempt'     => 0,
        'tax_exempt'     => 0,
    ]);

    $inv = Fixtures::generateInvoice($leaseId, '2026-06-01', '2026-06-30', 'full_month');
    $row = db_row(
        "SELECT gst_exempt_snapshot, pst_exempt_snapshot, tax_exempt_snapshot,
                gst_exempt_number_snapshot, pst_exempt_number_snapshot, tax_total
         FROM invoices WHERE id=?",
        [$inv['invoice_id']]
    );

    ok('gst_exempt_snapshot = 1',               (int)$row['gst_exempt_snapshot'] === 1);
    ok('pst_exempt_snapshot = 0',               (int)$row['pst_exempt_snapshot'] === 0);
    ok('tax_exempt_snapshot = 0 (not fully)',   (int)$row['tax_exempt_snapshot'] === 0);
    ok('gst_exempt_number_snapshot saved',      $row['gst_exempt_number_snapshot'] === 'GST-FN-99999');
    ok('pst_exempt_number_snapshot = null',     $row['pst_exempt_number_snapshot'] === null);
    ok('tax_total > 0 (PST still charged)',     bccomp((string)$row['tax_total'], '0', 2) > 0);
});

// ── TEST 3: No exemption ──────────────────────────────────────────────────────
echo "\nTEST 3: Non-exempt customer — no snapshots, full tax\n";
DbState::inTransaction(function () {
    $custId = db_insert('customers', [
        'contact_name' => 'Taxable ' . uniqid(),
        'company_name' => 'Taxable Corp',
        'email'        => uniqid('e-') . '@taxable.test',
        'province'     => 'BC',
        'country'      => 'Canada',
        'gst_exempt'   => 0,
        'pst_exempt'   => 0,
    ]);

    $leaseId = Fixtures::createLease($custId, [
        'start_date'     => '2026-06-01',
        'engine_version' => 'holistic',
        'daily_rate'     => '200.00',
        'monthly_rate'   => '4000.00',
    ]);

    $inv = Fixtures::generateInvoice($leaseId, '2026-06-01', '2026-06-30', 'full_month');
    $row = db_row(
        "SELECT gst_exempt_snapshot, pst_exempt_snapshot,
                gst_exempt_number_snapshot, pst_exempt_number_snapshot, tax_total
         FROM invoices WHERE id=?",
        [$inv['invoice_id']]
    );

    ok('gst_exempt_snapshot = 0',           (int)$row['gst_exempt_snapshot'] === 0);
    ok('pst_exempt_snapshot = 0',           (int)$row['pst_exempt_snapshot'] === 0);
    ok('gst_exempt_number_snapshot = null', $row['gst_exempt_number_snapshot'] === null);
    ok('pst_exempt_number_snapshot = null', $row['pst_exempt_number_snapshot'] === null);
    ok('tax_total > 0 (full tax)',          bccomp((string)$row['tax_total'], '0', 2) > 0);
});

// ── TEST 4: Exempt with no cert number — null handled gracefully ─────────────
echo "\nTEST 4: Exempt but no cert number — null snapshots (not an error)\n";
DbState::inTransaction(function () {
    $custId = db_insert('customers', [
        'contact_name' => 'NoCert ' . uniqid(),
        'company_name' => 'NoCert Co',
        'email'        => uniqid('e-') . '@nocert.test',
        'province'     => 'BC',
        'country'      => 'Canada',
        'gst_exempt'   => 1,
        'pst_exempt'   => 1,
        // no gst_exempt_number / pst_exempt_number
    ]);

    $leaseId = Fixtures::createLease($custId, [
        'start_date'     => '2026-06-01',
        'engine_version' => 'holistic',
        'gst_exempt'     => 1,
        'pst_exempt'     => 1,
    ]);
    $inv = Fixtures::generateInvoice($leaseId, '2026-06-01', '2026-06-30', 'full_month');
    $row = db_row(
        "SELECT gst_exempt_snapshot, gst_exempt_number_snapshot, pst_exempt_number_snapshot
         FROM invoices WHERE id=?",
        [$inv['invoice_id']]
    );

    ok('gst_exempt_snapshot = 1',                    (int)$row['gst_exempt_snapshot'] === 1);
    ok('gst_exempt_number_snapshot = null (no cert)', $row['gst_exempt_number_snapshot'] === null);
    ok('pst_exempt_number_snapshot = null (no cert)', $row['pst_exempt_number_snapshot'] === null);
});

echo "\n=== RESULTS: $pass passed | $fail failed ===\n";
if ($fail > 0) exit(1);
