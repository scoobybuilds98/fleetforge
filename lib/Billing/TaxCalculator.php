<?php
declare(strict_types=1);

/**
 * lib/Billing/TaxCalculator.php
 *
 * Tax calculation engine for invoices. Looks up current tax rates from
 * the tax_rates table at invoice time (D11 — never frozen on lease).
 * Respects independent gst_exempt and pst_exempt flags (D22).
 * All monetary values are bcmath strings (D16).
 *
 * Required by: lib/Billing/InvoiceGenerator.php
 * Requires: includes/db.php (db_row)
 * Defines: FleetForge\Billing\TaxCalculator
 *
 * Decisions: D11 (invoice-time tax lookup), D16 (bcmath), D22 (granular exemptions)
 * Spec ref: §9 Tax Rules, PASS-13:T1, PASS-13:T2
 */

namespace FleetForge\Billing;

class TaxCalculator
{
    /**
     * Calculate tax amounts for a given subtotal.
     *
     * Looks up tax rates from tax_rates table by province.
     * GST exemption suppresses both GST and HST (they are federal taxes).
     * PST exemption suppresses PST only.
     * Both flags are independent — a customer can be GST-exempt but PST-liable.
     *
     * @param string $subtotal   Pre-tax amount after discounts, as bcmath string
     * @param string $province   Two-letter province code (e.g. 'BC', 'ON', 'AB')
     * @param bool   $gstExempt  If true, GST and HST are zero
     * @param bool   $pstExempt  If true, PST is zero
     * @return array{
     *   gst_rate: string, pst_rate: string, hst_rate: string,
     *   gst: string, pst: string, hst: string, total: string,
     *   gst_exempt: bool, pst_exempt: bool
     * }
     */
    public function calculate(string $subtotal, string $province, bool $gstExempt, bool $pstExempt): array
    {
        // Look up current active tax rate for province (D11: at invoice time)
        $rates = $this->lookupRates($province);

        $gstRate = $rates['gst_rate'] ?? '0.0000';
        $pstRate = $rates['pst_rate'] ?? '0.0000';
        $hstRate = $rates['hst_rate'] ?? '0.0000';

        // Calculate each tax component
        // WHY: tax_rates stores rates as decimal fractions already (0.0500 = 5%),
        // so we multiply directly — no division by 100.
        // GST exemption suppresses both GST and HST (both are federal)
        $gstAmount = '0.00';
        if (!$gstExempt && bccomp($gstRate, '0', 4) > 0) {
            $gstAmount = bcround(bcmul($subtotal, $gstRate, 6), 2);
        }

        $hstAmount = '0.00';
        if (!$gstExempt && bccomp($hstRate, '0', 4) > 0) {
            $hstAmount = bcround(bcmul($subtotal, $hstRate, 6), 2);
        }

        // PST exemption is independent of GST (D22)
        $pstAmount = '0.00';
        if (!$pstExempt && bccomp($pstRate, '0', 4) > 0) {
            $pstAmount = bcround(bcmul($subtotal, $pstRate, 6), 2);
        }

        // Total tax
        $total = bcadd(bcadd($gstAmount, $pstAmount, 2), $hstAmount, 2);

        return [
            'gst_rate'   => $gstRate,
            'pst_rate'   => $pstRate,
            'hst_rate'   => $hstRate,
            'gst'        => $gstAmount,
            'pst'        => $pstAmount,
            'hst'        => $hstAmount,
            'total'      => $total,
            'gst_exempt' => $gstExempt,
            'pst_exempt' => $pstExempt,
        ];
    }

    /**
     * Look up active tax rates for a province from the database.
     *
     * Returns the most recently effective rate that is active.
     * Falls back to zero rates if no rate found.
     *
     * @param string $province Two-letter province code
     * @return array{gst_rate: string, pst_rate: string, hst_rate: string}
     */
    private function lookupRates(string $province): array
    {
        $row = db_row(
            "SELECT gst_rate, pst_rate, hst_rate
             FROM tax_rates
             WHERE province = ? AND is_active = 1 AND effective_from <= CURDATE()
             ORDER BY effective_from DESC
             LIMIT 1",
            [$province]
        );

        if (!$row) {
            // No rate found — return zeros (e.g., US customers)
            return [
                'gst_rate' => '0.0000',
                'pst_rate' => '0.0000',
                'hst_rate' => '0.0000',
            ];
        }

        return [
            'gst_rate' => (string)$row['gst_rate'],
            'pst_rate' => (string)$row['pst_rate'],
            'hst_rate' => (string)$row['hst_rate'],
        ];
    }
}
