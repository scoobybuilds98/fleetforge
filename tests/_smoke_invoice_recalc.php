<?php
declare(strict_types=1);

/**
 * Smoke — InvoiceRecalc::recalc() math (S-INVOICE-DRAFT-EDIT)
 *
 * Hermetic: inserts a draft invoice + line items inside a single transaction,
 * runs the recompute, asserts the stored totals, then ROLLS BACK (no residue).
 * Proves: credit-aware subtotal, snapshot-rate per-line tax, non-taxable lines
 * excluded, GST/PST exemption suppression, flat + percentage discount,
 * balance_due net of amount_paid/credits, and invoice tax_total == Σ(line tax).
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceRecalc;

$pdo = db_pdo();
$pass = 0; $fail = 0;
function check(string $label, string $got, string $want): void {
    global $pass, $fail;
    if (bccomp($got, $want, 2) === 0) { echo "  \033[32m✓\033[0m {$label} = {$got}\n"; $pass++; }
    else { echo "  \033[31m✗\033[0m {$label} = {$got} (want {$want})\n"; $fail++; }
}

// Minimal draft-invoice factory. Rates: GST 5% + PST 7% (BC-like).
function mkInvoice(array $over = []): int {
    static $n = 0; $n++;
    return db_insert('invoices', array_merge([
        'invoice_number'   => 'ZRECALC-TEST-' . $n . '-' . substr(md5((string)$n), 0, 6),
        'invoice_type'     => 'regular',
        'customer_id'      => null,
        'lease_id'         => null,
        'billing_type'     => 'single_period',
        'billing_period_start' => '2026-06-24',
        'billing_period_end'   => '2026-07-23',
        'billing_period_days'  => 30,
        'invoice_date'     => '2026-06-24',
        'due_date'         => '2026-07-24',
        'status'           => 'draft',
        'tax_gst_rate'     => '0.0500',
        'tax_pst_rate'     => '0.0700',
        'tax_hst_rate'     => '0.0000',
        'gst_exempt_snapshot' => 0,
        'pst_exempt_snapshot' => 0,
        'discount_type'    => 'none',
        'discount_value'   => '0.0000',
        'amount_paid'      => '0.00',
        'credits_applied'  => '0.00',
    ], $over));
}
function mkLine(int $invId, string $amount, array $over = []): void {
    static $s = 0; $s++;
    db_insert('invoice_line_items', array_merge([
        'invoice_id' => $invId,
        'sort_order' => $s,
        'item_type'  => 'manual_adjustment',
        'description'=> 'test line',
        'quantity'   => '1.0000',
        'unit_price' => $amount,
        'amount'     => $amount,
        'is_credit'  => 0,
        'taxable'    => 1,
    ], $over));
}

$pdo->beginTransaction();
try {
    // ── T1: credit-aware subtotal + GST+PST on full taxable base ──
    echo "T1: 100 + 50 − 20(credit), all taxable, GST5/PST7\n";
    $i1 = mkInvoice();
    mkLine($i1, '100.00');
    mkLine($i1, '50.00');
    mkLine($i1, '20.00', ['is_credit' => 1, 'item_type' => 'discount']);
    $t1 = InvoiceRecalc::recalc($i1);
    check('subtotal', $t1['subtotal'], '130.00');
    check('gst (130*.05)', $t1['tax_gst_amount'], '6.50');
    check('pst (130*.07)', $t1['tax_pst_amount'], '9.10');
    check('tax_total', $t1['tax_total'], '15.60');
    check('total', $t1['total_amount'], '145.60');
    check('balance_due', $t1['balance_due'], '145.60');
    // invoice tax_total reconciles to Σ per-line tax
    $lineTax = db_row("SELECT COALESCE(SUM(tax_gst_amount+tax_pst_amount+tax_hst_amount),0) s FROM invoice_line_items WHERE invoice_id=?", [$i1]);
    check('Σ line tax == invoice tax_total', (string)$lineTax['s'], $t1['tax_total']);

    // ── T2: non-taxable line excluded from tax ──
    echo "T2: 100 taxable + 40 NON-taxable\n";
    $i2 = mkInvoice();
    mkLine($i2, '100.00');
    mkLine($i2, '40.00', ['taxable' => 0]);
    $t2 = InvoiceRecalc::recalc($i2);
    check('subtotal', $t2['subtotal'], '140.00');
    check('gst (only 100 taxed)', $t2['tax_gst_amount'], '5.00');
    check('pst (only 100 taxed)', $t2['tax_pst_amount'], '7.00');
    check('total', $t2['total_amount'], '152.00');

    // ── T3: GST exemption suppresses GST (+HST), PST stays ──
    echo "T3: 200 taxable, gst_exempt_snapshot=1\n";
    $i3 = mkInvoice(['gst_exempt_snapshot' => 1]);
    mkLine($i3, '200.00');
    $t3 = InvoiceRecalc::recalc($i3);
    check('gst suppressed', $t3['tax_gst_amount'], '0.00');
    check('pst still applies', $t3['tax_pst_amount'], '14.00');
    check('total', $t3['total_amount'], '214.00');

    // ── T4: flat discount reduces subtotal + taxes the discounted base ──
    echo "T4: 100 taxable, flat $10 discount\n";
    $i4 = mkInvoice(['discount_type' => 'flat', 'discount_value' => '10.0000']);
    mkLine($i4, '100.00');
    $t4 = InvoiceRecalc::recalc($i4);
    check('subtotal', $t4['subtotal'], '100.00');
    check('discount_amount', $t4['discount_amount'], '10.00');
    check('subtotal_after_discount', $t4['subtotal_after_discount'], '90.00');
    check('gst (90*.05)', $t4['tax_gst_amount'], '4.50');
    check('pst (90*.07)', $t4['tax_pst_amount'], '6.30');
    check('total (90+10.80)', $t4['total_amount'], '100.80');

    // ── T5: percentage discount + balance net of a payment ──
    echo "T5: 100 taxable, 10% discount, amount_paid 5.00\n";
    $i5 = mkInvoice(['discount_type' => 'percentage', 'discount_value' => '10.0000', 'amount_paid' => '5.00']);
    mkLine($i5, '100.00');
    $t5 = InvoiceRecalc::recalc($i5);
    check('discount_amount (10%)', $t5['discount_amount'], '10.00');
    check('subtotal_after_discount', $t5['subtotal_after_discount'], '90.00');
    check('total (90 + 90*.12)', $t5['total_amount'], '100.80');
    check('balance_due (total-5)', $t5['balance_due'], '95.80');

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n────────────────────────────────────────────\n";
echo "INVOICE RECALC — {$pass} passed, {$fail} failed\n";
echo $fail === 0 ? "\033[32m✓ ALL PASSED\033[0m\n" : "\033[31m✗ FAILURES\033[0m\n";
exit($fail === 0 ? 0 : 1);
