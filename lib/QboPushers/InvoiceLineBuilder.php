<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoiceLineBuilder.php
 *
 * Single-responsibility helper that converts an FF invoice's line items
 * into a QBO Invoice.Line array. Handles:
 *   - per-line QBO Item resolution via acc_qbo_item_map (S-QBO-10)
 *   - GPS net/gross variant selection per customer.gps_revenue_presentation
 *     (D-QBO-10-2)
 *   - engine-version dispatch enforcement (D-QBO-11-5 — recon credit lines
 *     must only emit from holistic engine; throws on integrity violation)
 *   - line-level TaxCodeRef='NON' via InvoiceTaxOverride (D-QBO-11-2)
 *
 * K-22 silent resolutions per [[feedback_trust_file_over_prompt]] +
 * S-QBO-11 pre-flight AskUserQuestion:
 *   - invoice_line_items.sort_order (NOT line_order)
 *   - invoice_line_items.amount (NOT subtotal)
 *   - invoices.engine_version DOES NOT exist on disk → degrade to 'unknown'
 *     literal. D-QBO-11-5 recon-credit enforcement compiles but never
 *     triggers period_independent path until the column is added.
 *
 * @session  S-QBO-11
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher Contract — payload build)
 * @decision D-QBO-10-1 (recon credit dedicated Item),
 *           D-QBO-10-2 (GPS net/gross variant per customer flag),
 *           D-QBO-11-2 (line-level TaxCodeRef='NON'),
 *           D-QBO-11-5 (engine-version recon-credit enforcement)
 */

namespace FleetForge\QboPushers;

use FleetForge\Exceptions\QuickBooksException;

class InvoiceLineBuilder
{
    /**
     * Build QBO Invoice.Line array from FF invoice + customer + line items.
     *
     * @param  array<string, mixed> $invoice  FF invoices row
     * @param  array<string, mixed> $customer FF customers row (for gps_revenue_presentation)
     * @param  array<int, array<string, mixed>> $lines  FF invoice_line_items rows, ordered by sort_order ASC, id ASC
     * @param  array|null $perRate InvoiceTaxPerRate::resolve() result in per-rate mode; null = override mode
     * @return array<int, array<string, mixed>> QBO Line entries
     * @throws QuickBooksException on data integrity violation or unmapped item_type
     */
    public static function build(array $invoice, array $customer, array $lines, ?array $perRate = null): array
    {
        $qboLines = [];
        $lineNum = 1;

        foreach ($lines as $line) {
            $itemType = (string) $line['item_type'];
            $variant = null;

            // GPS variant resolution per D-QBO-10-2. customers.gps_revenue_presentation
            // is ENUM('net','gross') with DEFAULT 'net' (verified at session pre-flight).
            if ($itemType === 'gps') {
                $variant = (string) ($customer['gps_revenue_presentation'] ?? 'net');
                if (!in_array($variant, ['net', 'gross'], true)) {
                    throw new QuickBooksException(
                        "Invalid gps_revenue_presentation '{$variant}' for customer "
                        . ($customer['id'] ?? '?') . "; expected 'net' or 'gross'."
                    );
                }
            }

            // Engine-version dispatch enforcement per D-QBO-11-5.
            // Reconciliation credit lines should ONLY emit from the holistic
            // engine — appearing on a period_independent invoice indicates
            // a data integrity issue in the billing engine and we refuse
            // to push (operator must investigate). engine_version column
            // does NOT exist on disk today (K-22); falling back to 'unknown'
            // means this guard never actually trips until the column is
            // added. Code preserved for forward-compatibility.
            $engineVersion = (string) ($invoice['engine_version'] ?? 'unknown');
            if ($itemType === 'base_rental_reconciliation_credit'
                && $engineVersion === 'period_independent') {
                throw new QuickBooksException(
                    "Data integrity error: base_rental_reconciliation_credit line "
                    . "found on period_independent engine invoice "
                    . ($invoice['id'] ?? '?') . ". Reconciliation credits should only "
                    . "emit from holistic engine. Investigate billing engine emission "
                    . "before pushing this invoice to QBO."
                );
            }

            // Resolve QBO Item via acc_qbo_item_map (S-QBO-10).
            $itemMap = self::resolveItemMap($itemType, $variant);
            if ($itemMap === null) {
                $variantSuffix = $variant !== null ? " variant='{$variant}'" : '';
                throw new QuickBooksException(
                    "No QBO Item mapped for ff_item_type='{$itemType}'{$variantSuffix}. "
                    . "Map via /quickbooks/items first (S-QBO-10)."
                );
            }

            // Line amount: invoice_line_items.amount (NOT subtotal per K-22).
            // Unit price defaults to amount if absent (e.g. single-unit lines).
            $amount = (string) ($line['amount'] ?? '0');
            $unitPrice = (string) ($line['unit_price'] ?? $amount);
            $qtyStr = (string) ($line['quantity'] ?? '1');

            // S-QBO-GOLIVE-AUDIT: K-16 convention — FF stores a credit line as a
            // POSITIVE amount + is_credit=1 and every FF total subtracts it
            // (mileage true-up credits, base-rental reconciliation credits…).
            // This builder used to send the positive amount, so QBO CHARGED the
            // customer for every credit: the invoice came out 2× the credit too
            // high (20 such lines on prod). QBO takes a negative line amount on
            // an invoice as long as the invoice total stays ≥ 0.
            if (!empty($line['is_credit'])) {
                // bccomp guard: older bcmath renders -1 × 0 as "-0.00".
                $amount    = bccomp($amount, '0', 2) === 0 ? '0.00' : bcmul($amount, '-1', 2);
                $unitPrice = bccomp($unitPrice, '0', 2) === 0 ? '0.00' : bcmul($unitPrice, '-1', 2);
            }

            $detail = [
                'ItemRef'    => [
                    'value' => (string) $itemMap['qbo_item_id'],
                    'name'  => (string) $itemMap['qbo_name'],
                ],
                // S-QBO-GOLIVE-AUDIT (F80): per-rate mode — a taxable line
                // carries the QBO code mapped to the invoice's FF tax rate, a
                // non-taxable one the tax-free code. Override mode: the one
                // no-rate code on every line (D-QBO-11-2).
                'TaxCodeRef' => $perRate === null
                    ? InvoiceTaxOverride::lineLevelTaxCodeRef()
                    : ['value' => (!empty($line['taxable']) && ($perRate['taxable_code'] ?? null) !== null)
                        ? (string) $perRate['taxable_code']
                        : (string) $perRate['exempt_code']],
            ];
            // S-QBO-GOLIVE-AUDIT: QBO rejects the WHOLE invoice when a line's
            // Amount ≠ Qty × UnitPrice. FF stores quantity at 4dp and
            // unit_price at 2dp while amount is the engine's exact figure
            // (prorated days, km at a converted rate, etc.), so the product
            // often misses by a cent. Send Qty/UnitPrice only when they
            // reconcile exactly; otherwise Amount alone — QBO accepts that,
            // and the FF description still carries the quantity detail.
            if (self::qtyPriceReconciles($qtyStr, $unitPrice, $amount)) {
                $detail['Qty']       = (float) $qtyStr;
                $detail['UnitPrice'] = $unitPrice;
            }
            // S-QBO-GOLIVE-AUDIT: the billing-period start as QBO's per-line
            // Service Date (shown on the invoice and in sales-by-date reports).
            $serviceDate = (string) ($line['period_start'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate) === 1) {
                $detail['ServiceDate'] = $serviceDate;
            }

            $qboLines[] = [
                'LineNum'             => $lineNum++,
                'DetailType'          => 'SalesItemLineDetail',
                'Amount'              => $amount,
                'Description'         => (string) ($line['description'] ?? ''),
                'SalesItemLineDetail' => $detail,
            ];
        }

        return $qboLines;
    }

    /**
     * True when round(qty × unit_price, 2) == amount exactly — the check QBO
     * applies to a SalesItemLineDetail line. bcmath throughout (D16).
     *
     * @session S-QBO-GOLIVE-AUDIT
     */
    public static function qtyPriceReconciles(string $qty, string $unitPrice, string $amount): bool
    {
        if (!is_numeric($qty) || !is_numeric($unitPrice) || !is_numeric($amount)) {
            return false;
        }
        return bccomp(bcround(bcmul($qty, $unitPrice, 6), 2), bcround($amount, 2), 2) === 0;
    }

    /**
     * Look up acc_qbo_item_map row for given ff_item_type + optional variant.
     * Returns mapped row [qbo_item_id, qbo_name] or null if no mapping exists
     * (or mapping_status != 'mapped', or qbo_item_id is NULL).
     *
     * Variant=null matches rows where ff_item_type_variant IS NULL (most
     * item_types). Variant non-null matches rows where ff_item_type_variant
     * equals the variant string (GPS net/gross per D-QBO-10-2).
     *
     * @param  string $ffItemType
     * @param  ?string $variant
     * @return array{qbo_item_id: string, qbo_name: string}|null
     */
    private static function resolveItemMap(string $ffItemType, ?string $variant = null): ?array
    {
        if ($variant === null) {
            return db_row(
                "SELECT qbo_item_id, qbo_name
                   FROM acc_qbo_item_map
                  WHERE ff_item_type = ?
                    AND ff_item_type_variant IS NULL
                    AND mapping_status = 'mapped'
                    AND qbo_item_id IS NOT NULL
                  LIMIT 1",
                [$ffItemType]
            );
        }
        return db_row(
            "SELECT qbo_item_id, qbo_name
               FROM acc_qbo_item_map
              WHERE ff_item_type = ?
                AND ff_item_type_variant = ?
                AND mapping_status = 'mapped'
                AND qbo_item_id IS NOT NULL
              LIMIT 1",
            [$ffItemType, $variant]
        );
    }
}
