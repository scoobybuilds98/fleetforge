<?php
declare(strict_types=1);

/**
 * tests/_stress_invariant_i6.php
 *
 * S-MILEAGE-ALLOWANCE-ZERO-FIX C2 — fault-injection stress test for I6.
 * Proves the silent-skip-class invariant fires on the exact bug shape it
 * was designed to catch:
 *
 *   draft invoice + status='draft' + period_distance>0
 *     + lease.mileage_rate_km > 0 + lease.estimated_mileage_km = 0
 *     + mileage_review_status = 'not_required' + no mileage line
 *
 * Strategy: run inside an outer BEGIN/ROLLBACK transaction. Insert a
 * temp Model B Lite lease, generate an invoice via InvoiceGenerator
 * (post-C1, this will set review='pending'), then UPDATE the invoice
 * to set review='not_required' (simulating the pre-C1 silent-skip
 * state). Run the I6 SQL directly and assert the row appears. ROLLBACK.
 *
 * Run:    php tests/_stress_invariant_i6.php
 * Exit:   0 on PASS, 1 on FAIL.
 *
 * @session   S-MILEAGE-ALLOWANCE-ZERO-FIX C2 (2026-05-07)
 * @decisions D-B (I6 invariant)
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

$pass = 0;
$fail = 0;
$messages = [];

$report = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail, &$messages) {
    if ($ok) { $pass++; $messages[] = "PASS  {$name}" . ($detail ? "  — {$detail}" : ''); }
    else     { $fail++; $messages[] = "FAIL  {$name}" . ($detail ? "  — {$detail}" : ''); }
};

echo "FleetForge — I6 invariant fault-injection stress test\n";
echo str_repeat('═', 78), "\n";

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    $cust = db_row("SELECT id, currency, gst_exempt FROM customers WHERE id = 9");
    $unit = db_row("SELECT id, template_id, unit_number FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$cust || !$unit) {
        throw new RuntimeException("Stress setup: missing customer 9 or any equipment_unit.");
    }
    $tmpl = db_row("SELECT name FROM equipment_templates WHERE id = ?", [$unit['template_id']]);

    // ── Insert a Model B Lite temp lease ────────────────────────────────
    $leaseId = db_insert('leases', [
        'contract_number'         => 'STRESS-I6-' . substr(uniqid(), -6),
        'customer_id'             => $cust['id'],
        'customer_name_snapshot'  => 'I6 Stress Co',
        'company_name_snapshot'   => 'I6 Stress Co',
        'equipment_unit_id'       => $unit['id'],
        'unit_number_snapshot'    => $unit['unit_number'],
        'template_name_snapshot'  => $tmpl['name'] ?? 'STRESS',
        'start_date'              => '2026-04-01',
        'end_date'                => '2026-04-30',
        'status'                  => 'active',
        'billing_cycle'           => 'monthly',
        'daily_rate'              => '125.00',
        'weekly_rate'             => '750.00',
        'monthly_rate'            => '2200.00',
        'mileage_unit'            => 'km',
        'mileage_rate'            => '0.1800',
        'mileage_rate_km'         => '0.1800',
        'mileage_rate_miles'      => '0.2897',
        'estimated_mileage'       => '0.000',
        'estimated_mileage_km'    => '0.000',  // Model B Lite shape
        'estimated_mileage_miles' => '0.000',
        'currency'                => $cust['currency'] ?? 'CAD',
        'tax_rate_gst'            => '5.00',
        'tax_rate_pst'            => '0.00',
        'tax_rate_hst'            => '0.00',
        'gst_exempt'              => (int) ($cust['gst_exempt'] ?? 0),
        'pst_exempt'              => 0,
        'created_by'              => 1,
    ]);

    // ── Generate invoice via InvoiceGenerator (post-C1: review='pending') ──
    $generator = new InvoiceGenerator();
    $result = $generator->createFromLease([
        'lease_id'                    => $leaseId,
        'period_start'                => '2026-04-01',
        'period_end'                  => '2026-04-30',
        'billing_type'                => 'full_month',
        'invoice_type'                => 'regular',
        'odometer_at_period_start_km' => '1000.00',
        'odometer_at_period_end_km'   => '1500.00',
    ]);
    $invoiceId = $result['invoice_id'] ?? 0;

    // Sanity: post-C1 engine should have set review='pending'.
    $invBefore = db_row("SELECT mileage_review_status FROM invoices WHERE id = ?", [$invoiceId]);
    $report(
        'A1 Post-C1 engine sets review=pending for Model B Lite + distance>0',
        $invBefore['mileage_review_status'] === 'pending',
        "review={$invBefore['mileage_review_status']} (want pending)"
    );

    // ── Fault-inject pre-C1 state: review='not_required' on draft ───────────
    db_execute(
        "UPDATE invoices SET mileage_review_status = 'not_required' WHERE id = ?",
        [$invoiceId]
    );

    // ── Run the I6 SQL directly and assert the row appears ─────────────────
    $i6Rows = db_select(
        "SELECT i.id AS invoice_id, l.contract_number, l.mileage_rate_km, l.estimated_mileage_km, i.mileage_review_status
         FROM invoices i
         JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
         WHERE i.deleted_at IS NULL
           AND i.status IN ('draft','sent')
           AND i.invoice_type != 'mileage_only'
           AND COALESCE(i.period_distance_km, 0) > 0
           AND COALESCE(l.mileage_rate_km,        0) > 0
           AND COALESCE(l.estimated_mileage_km,   0) = 0
           AND i.mileage_review_status = 'not_required'
           AND NOT EXISTS (
             SELECT 1 FROM invoice_line_items ili
             WHERE ili.invoice_id = i.id
               AND ili.item_type IN ('mileage_adjustment','mileage_precharge','mileage_credit')
           )
           AND i.id = ?",
        [$invoiceId]
    );
    $report(
        'A2 I6 fires on fault-injected silent-skip',
        count($i6Rows) === 1,
        sprintf('found %d row(s) for fault-injected invoice (want 1)', count($i6Rows))
    );

    // ── Now restore review='pending' and confirm I6 stops firing ─────────────
    db_execute("UPDATE invoices SET mileage_review_status = 'pending' WHERE id = ?", [$invoiceId]);

    $i6RowsAfterFix = db_select(
        "SELECT i.id FROM invoices i
         JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
         WHERE i.deleted_at IS NULL
           AND i.status IN ('draft','sent')
           AND i.invoice_type != 'mileage_only'
           AND COALESCE(i.period_distance_km, 0) > 0
           AND COALESCE(l.mileage_rate_km,        0) > 0
           AND COALESCE(l.estimated_mileage_km,   0) = 0
           AND i.mileage_review_status = 'not_required'
           AND i.id = ?",
        [$invoiceId]
    );
    $report(
        'A3 I6 stops firing once review=pending (post-C1 engine state)',
        count($i6RowsAfterFix) === 0,
        sprintf('found %d row(s) (want 0)', count($i6RowsAfterFix))
    );

    // ── Negative case: Model C lease (allowance>0) with under-allowance distance ──
    // I6 should NOT fire (Model C-under-allowance is legitimate not_required).
    $modelCLeaseId = db_insert('leases', [
        'contract_number'         => 'STRESS-I6-MC-' . substr(uniqid(), -6),
        'customer_id'             => $cust['id'],
        'customer_name_snapshot'  => 'I6 Stress Co',
        'company_name_snapshot'   => 'I6 Stress Co',
        'equipment_unit_id'       => $unit['id'],
        'unit_number_snapshot'    => $unit['unit_number'],
        'template_name_snapshot'  => $tmpl['name'] ?? 'STRESS',
        'start_date'              => '2026-04-01',
        'end_date'                => '2026-04-30',
        'status'                  => 'active',
        'billing_cycle'           => 'monthly',
        'daily_rate'              => '125.00',
        'weekly_rate'             => '750.00',
        'monthly_rate'            => '2200.00',
        'mileage_unit'            => 'km',
        'mileage_rate'            => '0.1800',
        'mileage_rate_km'         => '0.1800',
        'mileage_rate_miles'      => '0.2897',
        'estimated_mileage'       => '2000.000',
        'estimated_mileage_km'    => '2000.000',  // Model C — allowance>0
        'estimated_mileage_miles' => '1242.742',
        'currency'                => $cust['currency'] ?? 'CAD',
        'tax_rate_gst'            => '5.00',
        'tax_rate_pst'            => '0.00',
        'tax_rate_hst'            => '0.00',
        'gst_exempt'              => (int) ($cust['gst_exempt'] ?? 0),
        'pst_exempt'              => 0,
        'created_by'              => 1,
    ]);
    $modelCResult = $generator->createFromLease([
        'lease_id'                    => $modelCLeaseId,
        'period_start'                => '2026-04-01',
        'period_end'                  => '2026-04-30',
        'billing_type'                => 'full_month',
        'invoice_type'                => 'regular',
        'odometer_at_period_start_km' => '1000.00',
        'odometer_at_period_end_km'   => '1500.00',  // 500 km < 2000 allowance
    ]);
    $modelCInvoiceId = $modelCResult['invoice_id'] ?? 0;

    $i6ModelCRows = db_select(
        "SELECT i.id FROM invoices i
         JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
         WHERE i.deleted_at IS NULL
           AND i.status IN ('draft','sent')
           AND i.invoice_type != 'mileage_only'
           AND COALESCE(i.period_distance_km, 0) > 0
           AND COALESCE(l.mileage_rate_km,        0) > 0
           AND COALESCE(l.estimated_mileage_km,   0) = 0
           AND i.mileage_review_status = 'not_required'
           AND i.id = ?",
        [$modelCInvoiceId]
    );
    $report(
        'A4 I6 ignores Model C-under-allowance (legitimate not_required)',
        count($i6ModelCRows) === 0,
        sprintf('found %d row(s) for Model C invoice (want 0)', count($i6ModelCRows))
    );

} finally {
    $pdo->rollBack();
}

echo "\n";
foreach ($messages as $m) echo "  {$m}\n";
echo "\n";
echo str_repeat('═', 78), "\n";
echo "{$pass} passed, {$fail} failed\n";
echo "(All test data rolled back — no DB pollution.)\n";
exit($fail === 0 ? 0 : 1);
