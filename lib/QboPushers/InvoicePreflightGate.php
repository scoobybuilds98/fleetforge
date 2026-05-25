<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoicePreflightGate.php
 *
 * Encapsulates the pre-flight check pipeline for invoice push. Called by
 * InvoicePusher::pushImpl BEFORE any payload build / HTTP call. Returns a
 * structured result so the caller can record the gate failure into
 * acc_qbo_invoice_map without a try/catch round-trip.
 *
 * Gate order (fail-fast — each gate returns immediately on failure):
 *   1. Connection status — settings.quickbooks.connection_status === 'connected'
 *   2. Tax-override target — settings.quickbooks.tax_override_code_id non-empty
 *   3. Account validator — AccountValidator::assertReadyForInvoicePush()
 *      (requires ar_clearing + sales_revenue categories mapped per
 *      D-QBO-VALIDATOR-3/-4)
 *   4. Customer mapping — acc_qbo_customer_map for invoice.customer_id
 *      has mapping_status='mapped' AND non-null qbo_customer_id
 *   4.5 Currency-mismatch — FF invoice.currency must match QBO customer's
 *      CurrencyRef (D-QBO-FIXPACK-3). Single HTTP call per push pre-flight;
 *      falls through gracefully on any transient QBO API error (catch \Throwable).
 *   5. Item mappings — every distinct item_type on the invoice resolves to a
 *      mapped acc_qbo_item_map row (GPS lines check variant per
 *      customer.gps_revenue_presentation per D-QBO-10-2)
 *   6. Field-length pre-flight — DocNumber/CustomerMemo/PrivateNote/
 *      line descriptions checked against QboFieldLimits constants
 *      (D-QBO-FIXPACK-4). Fails with typed status_code so operator gets
 *      a clear remediation path without burning an HTTP round-trip on
 *      QBO error 2050.
 *
 * Return shape: array{ok: bool, reason: ?string, status_code?: string}
 *   - ok=true  → all gates passed
 *   - ok=false → reason is actionable; status_code is a typed
 *     push_status value (defaults to 'failed_preflight' when absent)
 *
 * @session  S-QBO-11
 * @updated  S-QBO-11-FIXPACK-1 — added gate 4.5 (currency-mismatch) +
 *           gate 6 (field-length) + typed status_code in return shape;
 *           inner SELECT broadened to SELECT * for gate 6 fields
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher pre-flight gates),
 *           §7.1 (chart-of-accounts validator)
 * @decision D-QBO-11-7 (customer mapping gate),
 *           D-QBO-11-8 (item mapping gate + GPS variant resolution),
 *           D-QBO-FIXPACK-3 (currency-mismatch gate; falls through on HTTP error),
 *           D-QBO-FIXPACK-4 (QboFieldLimits single-source constants),
 *           D-QBO-FIXPACK-5 (typed status codes: failed_preflight_field_too_long,
 *                            failed_preflight_currency_mismatch)
 */

namespace FleetForge\QboPushers;

use FleetForge\Exceptions\ChartOfAccountsIncompleteException;
use FleetForge\QuickBooksClient;

class InvoicePreflightGate
{
    /**
     * Run all pre-flight checks for an FF invoice push.
     *
     * @param  int $ffInvoiceId
     * @return array{ok: bool, reason: ?string, status_code?: string}
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
        // WHY: SELECT * so gate 6 (PrivateNote preview) and gate 4.5 (currency)
        // have the full invoice row without a second query.
        $invoice = db_row(
            "SELECT * FROM invoices WHERE id = ?",
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

        // 4.5 Currency-mismatch pre-flight (D-QBO-FIXPACK-3), gated on
        // quickbooks.multi_currency_enabled (D-QBO-FIXPACK-12).
        //
        // WHY gate on multi_currency_enabled: when multi-currency is disabled,
        // QBO enforces a single home currency for all entities — CurrencyRef
        // is irrelevant and the HTTP probe to read the customer's CurrencyRef
        // is a wasted round-trip (QBO won't have the field in single-currency
        // mode). Skipping the check when multi-currency is disabled is safe:
        // InvoicePusher also omits CurrencyRef+ExchangeRate in that mode, so
        // there is no currency to mismatch against.
        //
        // WHY the check exists when enabled (D-QBO-FIXPACK-3): InvoicePusher
        // emits CurrencyRef=FF.currency; QBO will accept an invoice in currency
        // X on a customer denominated in Y but the AR ledger ends up corrupt.
        //
        // FALLTHROUGH: Transient QBO API errors are swallowed — never block a
        // push on a read-only pre-flight probe connectivity issue.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            try {
                $client = new QuickBooksClient();
                $qboCustomerResp = $client->getEntity('customer', (string) $customerMap['qbo_customer_id']);
                $qboCustomer = $qboCustomerResp['Customer'] ?? null;

                if ($qboCustomer === null) {
                    // Mapping points to a QBO customer that no longer exists.
                    return [
                        'ok'          => false,
                        'reason'      => "QBO customer (id={$customerMap['qbo_customer_id']}) not found in sandbox; "
                                       . "the mapping may be stale. Re-pull customers via /quickbooks/customers to refresh.",
                        'status_code' => 'failed_preflight',
                    ];
                }

                $qboCurrency = strtoupper((string) ($qboCustomer['CurrencyRef']['value'] ?? ''));
                $ffCurrency  = strtoupper((string) ($invoice['currency'] ?? ''));

                if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                    $qboName = (string) ($qboCustomer['DisplayName'] ?? '(unnamed)');
                    return [
                        'ok'          => false,
                        'reason'      => "Currency mismatch: FF invoice is {$ffCurrency} but QBO customer "
                                       . "(id={$customerMap['qbo_customer_id']}, name='{$qboName}') is {$qboCurrency}. "
                                       . "Either re-map FF customer {$invoice['customer_id']} to a QBO customer with "
                                       . "matching currency, or change FF invoice.currency to match.",
                        'status_code' => 'failed_preflight_currency_mismatch',
                    ];
                }
            } catch (\Throwable $e) {
                // Transient QBO API issue — fall through gracefully.
                // WHY: Pre-flight should never block a push on a network/auth hiccup.
                // The actual createEntity call will surface connectivity issues.
                // Log for operator observability.
                error_log("[S-QBO-FIXPACK-3] Currency pre-flight QBO lookup failed for invoice {$ffInvoiceId} "
                    . "(qbo_customer={$customerMap['qbo_customer_id']}): " . $e->getMessage());
            }
        }
        // When multi_currency_enabled='0': skip currency-mismatch check (no CurrencyRef emitted).

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
            $variant  = null;
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

        // 6. Field-length pre-flight (D-QBO-FIXPACK-4).
        // WHY: QBO rejects pushes for field-length violations only AFTER the HTTP
        // call (QBO error 2050). These checks fail-fast before burning the round-
        // trip, and emit a typed status_code so the operator knows exactly which
        // field to shorten — rather than decoding QBO's 2050 XML fault.

        // DocNumber ← invoice.invoice_number (QBO max 21 chars)
        $err = QboFieldLimits::checkLength(
            'invoice.invoice_number → DocNumber',
            $invoice['invoice_number'] ?? null,
            QboFieldLimits::INVOICE_DOC_NUMBER_MAX
        );
        if ($err !== null) {
            return ['ok' => false, 'reason' => $err, 'status_code' => 'failed_preflight_field_too_long'];
        }

        // CustomerMemo ← invoice.notes (QBO max 1000 chars)
        $err = QboFieldLimits::checkLength(
            'invoice.notes → CustomerMemo',
            $invoice['notes'] ?? null,
            QboFieldLimits::INVOICE_CUSTOMER_MEMO_MAX
        );
        if ($err !== null) {
            return ['ok' => false, 'reason' => $err, 'status_code' => 'failed_preflight_field_too_long'];
        }

        // PrivateNote audit JSON (QBO max 4000 chars).
        // WHY: Build a preview of the JSON now (cheap — pure string ops) to catch
        // any edge case where an unusually long invoice_number or many audit fields
        // push the PrivateNote over QBO's 4000-char limit. Typical length ~312 chars.
        $previewNote = InvoicePusher::buildPrivateNoteJson($invoice);
        $err = QboFieldLimits::checkLength(
            'invoice PrivateNote (audit JSON)',
            $previewNote,
            QboFieldLimits::INVOICE_PRIVATE_NOTE_MAX
        );
        if ($err !== null) {
            return [
                'ok'          => false,
                'reason'      => $err . " Investigate FF invoice audit columns for this edge case.",
                'status_code' => 'failed_preflight_field_too_long',
            ];
        }

        // Line descriptions (QBO max 4000 chars each)
        $lines = db_select(
            "SELECT sort_order, description FROM invoice_line_items
              WHERE invoice_id = ?
              ORDER BY sort_order ASC, id ASC",
            [$ffInvoiceId]
        );
        foreach ($lines as $i => $line) {
            $err = QboFieldLimits::checkLength(
                "invoice_line_items[{$i}].description",
                $line['description'] ?? null,
                QboFieldLimits::INVOICE_LINE_DESCRIPTION_MAX
            );
            if ($err !== null) {
                return ['ok' => false, 'reason' => $err, 'status_code' => 'failed_preflight_field_too_long'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }
}
