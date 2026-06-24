<?php
declare(strict_types=1);

namespace FleetForge\Billing;

/**
 * FleetForge — Invoice total recompute
 *
 * @file        lib/Billing/InvoiceRecalc.php
 * @description Recomputes an invoice's stored money columns (subtotal, discount,
 *              per-tax + tax_total, total_amount, balance_due) FROM its current
 *              line items, and persists them. This is the single authoritative
 *              recompute shared by the draft line-item editor
 *              (api/v1/invoices/update_lines.php) and the regenerate-from-lease
 *              path — so a manually-edited or regenerated draft can never carry
 *              a hand-typed total that disagrees with its lines.
 *
 *              IMMUTABILITY (D14 / CRA): it applies the invoice's FROZEN tax-rate
 *              snapshots (tax_gst_rate / tax_pst_rate / tax_hst_rate) and frozen
 *              exemption snapshots (gst_exempt_snapshot / pst_exempt_snapshot) —
 *              it never re-derives live province rates — so editing a draft never
 *              silently re-taxes it at a rate that changed since creation.
 *
 *              CONSISTENCY: invoice tax_total is the SUM of the per-line taxes it
 *              writes, so total_amount = subtotal_after_discount + Σ(line tax)
 *              always reconciles to the lines. `is_credit` lines subtract from the
 *              subtotal and tax base (mirrors InvoiceGenerator); non-`taxable`
 *              lines contribute no tax. A discount is prorated across the taxable
 *              base so the tax follows the discounted amount.
 *
 *              The caller owns the transaction, the draft-only / permission gate,
 *              and the audit_log entry. This method only does math + persistence.
 *
 * @depends     includes/db.php (db_row, db_select, db_update — bcmath helpers)
 * @session     S-INVOICE-DRAFT-EDIT
 */
final class InvoiceRecalc
{
    /**
     * Recompute + persist totals for $invoiceId from its line items.
     *
     * @return array{subtotal:string,discount_amount:string,subtotal_after_discount:string,tax_gst_amount:string,tax_pst_amount:string,tax_hst_amount:string,tax_total:string,total_amount:string,balance_due:string}
     */
    public static function recalc(int $invoiceId): array
    {
        $inv = db_row(
            "SELECT id, tax_gst_rate, tax_pst_rate, tax_hst_rate,
                    gst_exempt_snapshot, pst_exempt_snapshot,
                    discount_type, discount_value,
                    amount_paid, credits_applied
               FROM invoices WHERE id = ?",
            [$invoiceId]
        );
        if (!$inv) {
            throw new \RuntimeException("InvoiceRecalc: invoice {$invoiceId} not found");
        }

        $lines = db_select(
            "SELECT id, amount, is_credit, taxable
               FROM invoice_line_items
              WHERE invoice_id = ?
              ORDER BY sort_order ASC, id ASC",
            [$invoiceId]
        );

        $gstRate = (string) ($inv['tax_gst_rate'] ?? '0.0000');
        $pstRate = (string) ($inv['tax_pst_rate'] ?? '0.0000');
        $hstRate = (string) ($inv['tax_hst_rate'] ?? '0.0000');
        // GST exemption suppresses both GST and HST (both federal) — mirrors
        // TaxCalculator::calculate(). PST exemption is independent (D22).
        $gstExempt = !empty($inv['gst_exempt_snapshot']);
        $pstExempt = !empty($inv['pst_exempt_snapshot']);

        // ── Pass 1: subtotal (credit-aware), mirroring InvoiceGenerator ──
        $subtotal = '0.00';
        $signedByLine = [];
        foreach ($lines as $ln) {
            $signed = !empty($ln['is_credit'])
                ? bcsub('0', (string) $ln['amount'], 2)
                : (string) $ln['amount'];
            $signedByLine[(int) $ln['id']] = $signed;
            $subtotal = bcadd($subtotal, $signed, 2);
        }

        // ── Discount (mirror InvoiceGenerator step 6) ──
        $discountType  = $inv['discount_type'] ?? 'none';
        $discountValue = (string) ($inv['discount_value'] ?? '0.0000');
        $discountAmount = '0.00';
        if ($discountType === 'percentage' && bccomp($discountValue, '0', 4) > 0) {
            $discountDecimal = bcdiv($discountValue, '100', 6);
            $discountAmount  = bcround(bcmul($subtotal, $discountDecimal, 6), 2);
        } elseif ($discountType === 'flat' && bccomp($discountValue, '0', 4) > 0) {
            $discountAmount = bcround($discountValue, 2);
        }
        $subtotalAfterDiscount = bcsub($subtotal, $discountAmount, 2);

        // Proration factor: spread the discount across each line's tax base so
        // tax follows the discounted amount. factor = after/subtotal (1 when no
        // discount or zero subtotal).
        $factor = '1';
        if (bccomp($subtotal, '0', 2) !== 0 && bccomp($discountAmount, '0', 2) > 0) {
            $factor = bcdiv($subtotalAfterDiscount, $subtotal, 10);
        }

        // ── Pass 2: per-line tax on the (discounted) taxable base ──
        $gstTotal = '0.00';
        $pstTotal = '0.00';
        $hstTotal = '0.00';
        foreach ($lines as $ln) {
            $id     = (int) $ln['id'];
            $signed = $signedByLine[$id];

            $lineGst = '0.00';
            $linePst = '0.00';
            $lineHst = '0.00';
            if (!empty($ln['taxable'])) {
                $taxBase = bccomp($factor, '1', 10) === 0
                    ? $signed
                    : bcround(bcmul($signed, $factor, 10), 2);
                if (!$gstExempt && bccomp($gstRate, '0', 4) > 0) {
                    $lineGst = bcround(bcmul($taxBase, $gstRate, 6), 2);
                }
                if (!$gstExempt && bccomp($hstRate, '0', 4) > 0) {
                    $lineHst = bcround(bcmul($taxBase, $hstRate, 6), 2);
                }
                if (!$pstExempt && bccomp($pstRate, '0', 4) > 0) {
                    $linePst = bcround(bcmul($taxBase, $pstRate, 6), 2);
                }
            }

            db_update('invoice_line_items', [
                'tax_gst_amount' => $lineGst,
                'tax_pst_amount' => $linePst,
                'tax_hst_amount' => $lineHst,
            ], 'id = ?', [$id]);

            $gstTotal = bcadd($gstTotal, $lineGst, 2);
            $pstTotal = bcadd($pstTotal, $linePst, 2);
            $hstTotal = bcadd($hstTotal, $lineHst, 2);
        }

        $taxTotal    = bcadd(bcadd($gstTotal, $pstTotal, 2), $hstTotal, 2);
        $totalAmount = bcadd($subtotalAfterDiscount, $taxTotal, 2);

        $amountPaid     = (string) ($inv['amount_paid'] ?? '0.00');
        $creditsApplied = (string) ($inv['credits_applied'] ?? '0.00');
        $balanceDue     = bcsub(bcsub($totalAmount, $amountPaid, 2), $creditsApplied, 2);

        $totals = [
            'subtotal'                => $subtotal,
            'discount_amount'         => $discountAmount,
            'subtotal_after_discount' => $subtotalAfterDiscount,
            'tax_gst_amount'          => $gstTotal,
            'tax_pst_amount'          => $pstTotal,
            'tax_hst_amount'          => $hstTotal,
            'tax_total'               => $taxTotal,
            'total_amount'            => $totalAmount,
            'balance_due'             => $balanceDue,
        ];

        db_update('invoices', $totals, 'id = ?', [$invoiceId]);

        return $totals;
    }
}
