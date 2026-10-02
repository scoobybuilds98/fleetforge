<?php
declare(strict_types=1);

/**
 * lib/Billing/OverflowCreditNoteBlockedException.php
 *
 * Thrown when an invoice cannot be voided because the overflow credit note
 * auto-created from it (OverflowCreditNotes::SOURCES) has already been applied
 * to other invoices. Unwinding a spent credit silently would corrupt the
 * customer's credit trail, so the operation is refused instead — the same rule
 * void.php / delete / regenerate enforce with OverflowCreditNotes::findBlockers().
 *
 * WHY a typed exception (S-CLOSE-VOID-OVERFLOW-CN): lease close voids invoices
 * deep inside its transaction (adv_void_invoice()). close.php turns this into a
 * 422 "lease not closed" response; bulk_close.php records it as that one
 * lease's failure and carries on with the batch — json_error() there would end
 * the whole batch response.
 *
 * Thrown by:  api/v1/leases/_close_reconciliation.php adv_void_invoice()
 * Caught by:  api/v1/leases/close.php, api/v1/leases/bulk_close.php
 *
 * @session S-CLOSE-VOID-OVERFLOW-CN
 */

namespace FleetForge\Billing;

class OverflowCreditNoteBlockedException extends \RuntimeException
{
    /**
     * @param string                           $invoiceNumber The invoice the close needed to void.
     * @param array<int, array<string,mixed>>  $blockers      From OverflowCreditNotes::findBlockers().
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly array $blockers
    ) {
        $labels = array_map(
            static fn (array $cn): string => "{$cn['credit_note_number']} (\${$cn['amount']}, \${$cn['amount_remaining']} remaining)",
            $blockers
        );
        parent::__construct(
            "The lease was not closed: closing it must void invoice {$invoiceNumber}, but that invoice's "
            . 'auto-created account credit ' . implode(', ', $labels)
            . ' has already been applied to other invoices. Unapply the credit note, then close again.'
        );
    }
}
