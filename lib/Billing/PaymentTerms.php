<?php
declare(strict_types=1);

/**
 * lib/Billing/PaymentTerms.php
 *
 * S-QBO-INVOICE-PAYNOW — a customer's Payment Terms decide when its invoices
 * are due.
 *
 * Until now every invoice was due `invoice.due_days_default` (30) days after
 * its invoice date whatever customers.payment_terms said, and the terms were
 * only a label. Operator decision (2026-09-24): the due date follows the
 * customer's terms — "Net 15" → 15 days, "Due on receipt" → same day — so
 * the FleetForge due date and the term QuickBooks shows on the pushed
 * invoice (InvoicePusher → SalesTermRef) always agree.
 *
 * customers.payment_terms is free text. Terms that can't be read as a number
 * of days (blank, "2% 10 Net 30", "15th of next month") fall back to the
 * company default — never guessed.
 *
 * Existing invoices keep their dates: this applies when an invoice is
 * generated.
 *
 * @session  S-QBO-INVOICE-PAYNOW
 * @decision D-QBO-INVOICE-PAYNOW-1
 */

namespace FleetForge\Billing;

final class PaymentTerms
{
    /**
     * Days until due for free-text terms: "Net 30" / "net30" / "30 days" /
     * "N30" → 30; "Due on receipt" / "Due upon receipt" / "COD" /
     * "Immediate" → 0; anything else (including blank) → null.
     */
    public static function parseDays(?string $terms): ?int
    {
        $t = strtolower(trim((string) $terms));
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(?:due\s+(?:on|upon)\s+receipt|on\s+receipt|cod|c\.o\.d\.?|immediate(?:ly)?)$/', $t)) {
            return 0;
        }
        if (preg_match('/^(?:net\s*|n)?(\d{1,3})(?:\s*days?)?$/', $t, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** The company default (Settings → invoice due days), 30 when unset. */
    public static function defaultDays(): int
    {
        $d = (int) (settings_get('invoice.due_days_default', '30') ?? 30);
        return $d >= 0 ? $d : 30;
    }

    /** Days until an invoice for a customer with these terms is due. */
    public static function dueDays(?string $customerTerms): int
    {
        return self::parseDays($customerTerms) ?? self::defaultDays();
    }

    /** Due date (Y-m-d) for an invoice dated $invoiceDate. */
    public static function dueDate(string $invoiceDate, ?string $customerTerms): string
    {
        $days = self::dueDays($customerTerms);
        return (new \DateTimeImmutable($invoiceDate))->modify("+{$days} days")->format('Y-m-d');
    }
}
