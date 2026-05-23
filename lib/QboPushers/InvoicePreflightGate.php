<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoicePreflightGate.php
 *
 * Encapsulates the 5-step pre-flight check for invoice push. Called by
 * InvoicePusher::pushImpl BEFORE any payload build / HTTP call. Returns
 * a structured ['ok' => bool, 'reason' => string|null] result so the
 * caller can record the gate failure into acc_qbo_invoice_map without
 * a try/catch round-trip.
 *
 * Gate order (fail-fast):
 *   1. Connection status — settings.quickbooks.connection_status === 'connected'
 *   2. Tax-override target — settings.quickbooks.tax_override_code_id non-empty
 *   3. Account validator — AccountValidator::assertReadyForInvoicePush()
 *      (requires ar_clearing + sales_revenue categories mapped per
 *      D-QBO-VALIDATOR-3/-4)
 *   4. Customer mapping — acc_qbo_customer_map for invoice.customer_id
 *      has mapping_status='mapped' AND non-null qbo_customer_id
 *   5. Item mappings — every distinct item_type on the invoice resolves
 *      to a mapped acc_qbo_item_map row (GPS lines check variant per
 *      customer.gps_revenue_presentation per D-QBO-10-2)
 *
 * Gates 1-2 are settings checks; 3 is the validator; 4-5 are mapping
 * lookups. All failures return ['ok' => false, 'reason' => '<actionable>']
 * so the operator gets a clear remediation path.
 *
 * @session  S-QBO-11
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher pre-flight gates),
 *           §7.1 (chart-of-accounts validator)
 * @decision D-QBO-11-7 (customer mapping gate),
 *           D-QBO-11-8 (item mapping gate + GPS variant resolution),
 *           D-VALIDATOR-PER-SESSION (per-Pusher assertReady*Push gates)
 */

namespace FleetForge\QboPushers;

use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class InvoicePreflightGate
{
    /**
     * Run all 5 pre-flight checks for an FF invoice push.
     *
     * @param  int $ffInvoiceId
     * @return array{ok: bool, reason: ?string}
     */
    public static function check(int $ffInvoiceId): array
    {
        // 1. Connection
        $connStatus = (string) settings_get('quickbooks.connection_status', 'disconnected');
        if ($connStatus !== 'connected') {
            return [
                'ok'     => false,
                'reason' => "QuickBooks not connected (status='{$connStatus}'). Run /quickbooks/settings → Connect to QuickBooks.",
            ];
        }

        // 2. Tax override target
        $overrideCodeId = (string) settings_get('quickbooks.tax_override_code_id', '');
        if ($overrideCodeId === '') {
            return [
                'ok'     => false,
                'reason' => "Tax override code not configured (settings.quickbooks.tax_override_code_id empty). Run /quickbooks/tax_codes → Pull from QuickBooks first to identify the NON code.",
            ];
        }

        // 3. Account validator (D-VALIDATOR-PER-SESSION)
        try {
            AccountValidator::assertReadyForInvoicePush();
        } catch (ChartOfAccountsIncompleteException $e) {
            return [
                'ok'     => false,
                'reason' => "Account validator failed: " . $e->getMessage(),
            ];
        }

        // 4. Customer mapping (D-QBO-11-7)
        $invoice = db_row(
            "SELECT id, customer_id FROM invoices WHERE id = ?",
            [$ffInvoiceId]
        );
        if (!$invoice) {
            return [
                'ok'     => false,
                'reason' => "Invoice {$ffInvoiceId} not found.",
            ];
        }
        if (empty($invoice['customer_id'])) {
            return [
                'ok'     => false,
                'reason' => "Invoice {$ffInvoiceId} has no customer_id; cannot resolve QBO Customer.",
            ];
        }

        $customerMap = db_row(
            "SELECT qbo_customer_id, mapping_status
               FROM acc_qbo_customer_map
              WHERE ff_customer_id = ?",
            [(int) $invoice['customer_id']]
        );
        if (!$customerMap
            || $customerMap['mapping_status'] !== 'mapped'
            || empty($customerMap['qbo_customer_id'])) {
            return [
                'ok'     => false,
                'reason' => "Customer {$invoice['customer_id']} not mapped to QBO. Map via /quickbooks/customers first.",
            ];
        }

        // 5. Item mappings (D-QBO-11-8 + D-QBO-10-2 GPS variant resolution)
        $customer = db_row(
            "SELECT id, gps_revenue_presentation FROM customers WHERE id = ?",
            [(int) $invoice['customer_id']]
        );
        $itemTypes = db_select(
            "SELECT DISTINCT item_type FROM invoice_line_items WHERE invoice_id = ?",
            [$ffInvoiceId]
        );

        $unmappedTypes = [];
        foreach ($itemTypes as $row) {
            $itemType = (string) $row['item_type'];
            $variant = null;
            if ($itemType === 'gps') {
                $variant = (string) ($customer['gps_revenue_presentation'] ?? 'net');
            }

            $mapped = $variant === null
                ? db_row(
                    "SELECT qbo_item_id FROM acc_qbo_item_map
                      WHERE ff_item_type = ?
                        AND ff_item_type_variant IS NULL
                        AND mapping_status = 'mapped'
                        AND qbo_item_id IS NOT NULL
                      LIMIT 1",
                    [$itemType]
                )
                : db_row(
                    "SELECT qbo_item_id FROM acc_qbo_item_map
                      WHERE ff_item_type = ?
                        AND ff_item_type_variant = ?
                        AND mapping_status = 'mapped'
                        AND qbo_item_id IS NOT NULL
                      LIMIT 1",
                    [$itemType, $variant]
                );

            if (!$mapped) {
                $unmappedTypes[] = $itemType . ($variant !== null ? "/{$variant}" : '');
            }
        }

        if (!empty($unmappedTypes)) {
            return [
                'ok'     => false,
                'reason' => "Unmapped item types: " . implode(', ', $unmappedTypes)
                          . ". Map via /quickbooks/items first.",
            ];
        }

        return ['ok' => true, 'reason' => null];
    }
}
