<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoiceTaxPerRate.php
 *
 * Per-rate sales tax for invoices pushed to a CANADIAN QuickBooks company
 * (S-QBO-GOLIVE-AUDIT, F80). Opt-in: quickbooks.invoice.tax_mode='per_rate'
 * (default 'override' keeps InvoiceTaxOverride).
 *
 * WHY: the override pattern (every line on one no-rate code + a header
 * TotalTax) comes from the US engine. QuickBooks Canada has no "NON" code —
 * the closest (Exempt / Out of Scope) records the sale as tax-free, so
 * QuickBooks either drops the header tax (the invoice total — and the
 * pay-online amount — comes out short by the GST/PST) or books tax that is
 * not tied to the GST/PST agency, and the GST return QuickBooks prepares is
 * wrong. Almost every FF invoice is BC 5% GST + 7% PST.
 *
 * Per-rate instead gives QuickBooks what it needs to account for the tax:
 *   - each taxable line carries the QuickBooks TaxCode mapped to the FF
 *     tax rate the invoice used (QuickBooks → Tax Codes mapping, keyed by
 *     tax_rates.id — e.g. "GST/PST BC"); non-taxable lines carry the code
 *     chosen for tax-free sales (quickbooks.invoice.tax_code_exempt);
 *   - GlobalTaxCalculation = TaxExcluded;
 *   - TxnTaxDetail carries one TaxLine per FF component (GST / PST / HST /
 *     QST) with FF's exact amount, so QuickBooks books FF's figures to the
 *     right agency instead of re-rounding — built only when each non-zero
 *     component resolves to exactly one sales TaxRate of the code;
 *     otherwise QuickBooks computes from the codes, and InvoicePusher
 *     compares its TotalAmt with FF's afterwards (drift on any difference).
 *
 * FF is authoritative for tax (D-QBO-CORE-1); this only changes how the
 * figures are presented to QuickBooks. bcmath throughout (D16).
 *
 * @session S-QBO-GOLIVE-AUDIT
 * @spec    FLEETFORGE_QUICKBOOKS_SPEC.md §9.1 (TaxLine design), §17
 */

namespace FleetForge\QboPushers;

class InvoiceTaxPerRate
{
    /** Name patterns that identify a sales TaxRate's component. */
    private const COMPONENT_PATTERNS = [
        'hst' => '/\bHST\b/i',
        'gst' => '/\bGST\b(?!\s*\/?\s*HST)/i',
        'pst' => '/\b(PST|QST|RST|TVQ)\b/i',
    ];

    public static function enabled(): bool
    {
        return (string) settings_get('quickbooks.invoice.tax_mode', 'override') === 'per_rate';
    }

    /**
     * Resolve the QuickBooks tax treatment of an FF invoice.
     *
     * @param array<string,mixed> $invoice invoices row (tax_*_amount, tax_*_rate, province_snapshot, subtotal_after_discount)
     * @return array{ok: bool, reason?: string, taxable_code?: ?string, exempt_code?: string,
     *               txn_tax_detail?: ?array, total_tax?: string}
     */
    public static function resolve(array $invoice): array
    {
        $amounts = [
            'gst' => bcadd((string) ($invoice['tax_gst_amount'] ?? '0'), '0', 2),
            'pst' => bcadd((string) ($invoice['tax_pst_amount'] ?? '0'), '0', 2),
            'hst' => bcadd((string) ($invoice['tax_hst_amount'] ?? '0'), '0', 2),
        ];
        $totalTax = bcadd(bcadd($amounts['gst'], $amounts['pst'], 2), $amounts['hst'], 2);

        $exempt = trim((string) settings_get('quickbooks.invoice.tax_code_exempt', ''));
        if ($exempt === '') {
            return ['ok' => false, 'reason' => 'Per-rate invoice tax is on, but no QuickBooks code is chosen for tax-free lines. '
                . 'Pick it on QuickBooks → Tax Codes → Invoice tax (usually "Exempt" or "Zero-rated").'];
        }
        if (bccomp($totalTax, '0', 2) === 0) {
            return ['ok' => true, 'taxable_code' => null, 'exempt_code' => $exempt, 'txn_tax_detail' => null, 'total_tax' => '0.00'];
        }

        // Which FF tax rate did this invoice use? Components with no tax on
        // this invoice count as 0% (a GST-exempt customer in BC is a
        // PST-only sale and needs a PST-only code).
        $eff = [];
        foreach (['gst', 'pst', 'hst'] as $c) {
            $eff[$c] = bccomp($amounts[$c], '0', 2) === 0 ? '0' : (string) ($invoice["tax_{$c}_rate"] ?? '0');
        }
        $province = strtoupper(trim((string) ($invoice['province_snapshot'] ?? '')));
        $rateSql = "SELECT t.id, t.name, m.qbo_tax_code_id, m.qbo_name, m.qbo_sales_rate_refs
                      FROM tax_rates t
                      LEFT JOIN acc_qbo_tax_code_map m
                             ON m.ff_tax_rate_id = t.id AND m.mapping_status = 'mapped' AND m.qbo_tax_code_id IS NOT NULL
                     WHERE ABS(t.gst_rate - ?) < 0.00001 AND ABS(t.pst_rate - ?) < 0.00001 AND ABS(t.hst_rate - ?) < 0.00001";
        $rateArgs = [$eff['gst'], $eff['pst'], $eff['hst']];
        // Same province first. A partly exempt sale has no row of its own —
        // a PST-exempt BC customer (≈20% of prod invoices) is a GST-only
        // 5% sale, which is the same QuickBooks "GST" code as Alberta's —
        // so fall back to any MAPPED rate with exactly these percentages.
        $rate = db_row($rateSql . " AND UPPER(t.province) = ? ORDER BY (m.qbo_tax_code_id IS NOT NULL) DESC, t.is_active DESC, t.effective_from DESC LIMIT 1",
                       array_merge($rateArgs, [$province]));
        if ($rate === null || $rate['qbo_tax_code_id'] === null) {
            $rate = db_row($rateSql . " AND m.qbo_tax_code_id IS NOT NULL ORDER BY t.is_active DESC, t.effective_from DESC LIMIT 1", $rateArgs) ?? $rate;
        }
        if ($rate === null) {
            return ['ok' => false, 'reason' => sprintf(
                'No FleetForge tax rate matches this invoice (province %s, GST %s, PST %s, HST %s) — add it under Settings → Tax rates '
                . '(e.g. a PST-only rate for GST-exempt customers) and map it on QuickBooks → Tax Codes.',
                $province !== '' ? $province : '?', $eff['gst'], $eff['pst'], $eff['hst']
            )];
        }
        if ($rate['qbo_tax_code_id'] === null) {
            return ['ok' => false, 'reason' => "FleetForge tax rate \"{$rate['name']}\" is not mapped to a QuickBooks tax code — map it on QuickBooks → Tax Codes."];
        }
        $map = $rate;

        return [
            'ok'             => true,
            'taxable_code'   => (string) $map['qbo_tax_code_id'],
            'exempt_code'    => $exempt,
            'txn_tax_detail' => self::taxDetail($amounts, $totalTax, (string) ($map['qbo_sales_rate_refs'] ?? ''), $invoice),
            'total_tax'      => $totalTax,
        ];
    }

    /**
     * TxnTaxDetail with FF's per-component amounts, or null when the code's
     * sales rates cannot be matched one-to-one to FF's non-zero components
     * (QuickBooks then computes from the line codes).
     *
     * @param array<string,string> $amounts gst/pst/hst
     */
    public static function taxDetail(array $amounts, string $totalTax, string $salesRateRefsJson, array $invoice): ?array
    {
        $refs = json_decode($salesRateRefsJson, true);
        if (!is_array($refs) || $refs === []) {
            return null;
        }
        // Accept both the raw TaxRateDetail list and {TaxRateDetail: [...]}.
        $refs = $refs['TaxRateDetail'] ?? $refs;
        $base = bcadd((string) ($invoice['subtotal_after_discount'] ?? $invoice['subtotal'] ?? '0'), '0', 2);

        $lines = [];
        foreach ($amounts as $component => $amount) {
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }
            $hits = [];
            foreach ($refs as $r) {
                $name = (string) ($r['TaxRateRef']['name'] ?? $r['name'] ?? '');
                $id   = (string) ($r['TaxRateRef']['value'] ?? $r['value'] ?? '');
                if ($id !== '' && preg_match(self::COMPONENT_PATTERNS[$component], $name) === 1) {
                    $hits[$id] = true;
                }
            }
            if (count($hits) !== 1) {
                return null;
            }
            $lines[] = [
                'Amount'        => (float) $amount,
                'DetailType'    => 'TaxLineDetail',
                'TaxLineDetail' => [
                    'TaxRateRef'       => ['value' => (string) array_key_first($hits)],
                    'PercentBased'     => true,
                    'NetAmountTaxable' => (float) $base,
                ],
            ];
        }
        return $lines === [] ? null : ['TotalTax' => (float) $totalTax, 'TaxLine' => $lines];
    }
}
