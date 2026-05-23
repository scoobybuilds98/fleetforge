<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoiceTaxOverride.php
 *
 * Single-responsibility helper producing QBO TxnTaxDetail payload from
 * an FF invoice row. Tax-override architecture per D-QBO-CORE-6 + D-QBO-11-2:
 * FF computes tax authoritatively (per D-QBO-CORE-1); QBO accepts the
 * pre-computed total via TxnTaxDetail.TotalTax + line-level
 * TaxCodeRef='NON' (the override code identified at S-QBO-9 Pull and
 * persisted into settings.quickbooks.tax_override_code_id).
 *
 * The NON code has no rate — QBO does NOT recompute tax when TaxCodeRef
 * resolves to it. This decouples FF's CRA-context multi-jurisdiction tax
 * math (GST/HST/PST simultaneous in BC etc.) from QBO's single-rate
 * per-line model.
 *
 * Uses bcmath for the GST+PST+HST sum per D16 (monetary arithmetic in
 * bcmath; never float operators). All values normalized to strings to
 * preserve precision through the JSON encode boundary.
 *
 * K-22 silent resolution per [[feedback_trust_file_over_prompt]] +
 * S-QBO-11 pre-flight AskUserQuestion: FF invoices columns are
 * tax_gst_amount/tax_pst_amount/tax_hst_amount (NOT gst_amount/pst_amount/hst_amount
 * as the prompt drafted). Verified against live `invoices` table during
 * session pre-flight.
 *
 * @session  S-QBO-11
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §17 (tax-override architecture), §18 (multi-jurisdiction CRA mapping)
 * @decision D-QBO-CORE-6 (tax authoritative FF-side; QBO accepts override),
 *           D-QBO-11-2 (NON code + TotalTax header + line TaxCodeRef='NON' on every line)
 */

namespace FleetForge\QboPushers;

use FleetForge\Exceptions\QuickBooksException;

class InvoiceTaxOverride
{
    /**
     * Compute the QBO TxnTaxDetail block for an FF invoice.
     *
     * Returned structure (suitable to splat into QBO Invoice payload):
     *   [
     *     'TxnTaxCodeRef' => ['value' => '<NON id>'],
     *     'TotalTax'      => '<bcmath sum as string>',
     *   ]
     *
     * @param  array<string, mixed> $invoice FF invoices row — at minimum tax_gst_amount, tax_pst_amount, tax_hst_amount
     * @return array{TxnTaxCodeRef: array{value: string}, TotalTax: string}
     * @throws QuickBooksException if settings.quickbooks.tax_override_code_id is empty
     */
    public static function buildTxnTaxDetail(array $invoice): array
    {
        $overrideCodeId = (string) settings_get('quickbooks.tax_override_code_id', '');
        if ($overrideCodeId === '') {
            throw new QuickBooksException(
                "Tax override code not configured (settings.quickbooks.tax_override_code_id is empty). " .
                "Run /quickbooks/tax_codes → Pull from QuickBooks first to identify the NON code (D-QBO-9-2)."
            );
        }

        $gst = (string) ($invoice['tax_gst_amount'] ?? '0');
        $pst = (string) ($invoice['tax_pst_amount'] ?? '0');
        $hst = (string) ($invoice['tax_hst_amount'] ?? '0');

        // bcmath scale=2 for currency precision; matches FF invoices.tax_*_amount
        // decimal(12,2) column type.
        $totalTax = bcadd(bcadd($gst, $pst, 2), $hst, 2);

        return [
            'TxnTaxCodeRef' => ['value' => $overrideCodeId],
            'TotalTax'      => $totalTax,
        ];
    }

    /**
     * Returns the line-level TaxCodeRef structure. Always the override
     * code in S-QBO-11 (D-QBO-11-2 — every line carries TaxCodeRef='NON'
     * regardless of FF taxable/non-taxable line state; FF aggregates
     * already account for non-taxable lines in the header totals).
     *
     * Returns empty array if override code not configured — caller
     * should treat absence as a hard error (matches buildTxnTaxDetail
     * throw behavior, but at line level we let the build proceed since
     * the header gate will trip first).
     */
    public static function lineLevelTaxCodeRef(): array
    {
        $overrideCodeId = (string) settings_get('quickbooks.tax_override_code_id', '');
        return ['value' => $overrideCodeId];
    }
}
