<?php
declare(strict_types=1);

/**
 * lib/QboPushers/TermResolver.php
 *
 * FF customer payment terms → QuickBooks Term (SalesTermRef).
 *
 * WHY: customers.payment_terms is free text ("Net 30" on 37 of 43 prod
 * customers), but a QuickBooks Customer takes terms only as a reference to
 * one of the company's Term records. The pre-go-live audit (B7) found FF
 * never sent terms at all, so every customer FF created in QuickBooks got
 * the company default instead of its real terms.
 *
 * Matching, most specific first:
 *   1. a QuickBooks term with the same name (case / spacing ignored);
 *   2. otherwise the ONLY active standard term with the same number of
 *      days ("Net 30" ↔ "30 days"); "Due on receipt" = 0 days.
 * No confident match → no SalesTermRef (QuickBooks applies its default)
 * and a log line — never a guessed term.
 *
 * Customers: only when FleetForge CREATES the customer (operator decision
 * 2026-09-23) — customers linked to the accountant's records keep the
 * accountant's terms.
 *
 * Invoices (S-QBO-INVOICE-PAYNOW): every pushed invoice carries the term
 * matching ITS OWN days (due date − invoice date), preferring the customer's
 * named term — so the term and DueDate QuickBooks shows always agree, even
 * on an invoice generated before due dates followed terms.
 *
 * @session S-QBO-CUSTOMER-TERMS-ADDR, S-QBO-INVOICE-PAYNOW
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

final class TermResolver
{
    /** @var list<array{id:string, name:string, days:?int}>|null per-process cache of the company's active terms */
    private static ?array $terms = null;

    /**
     * QuickBooks Term id for FF payment terms, or null when there is no
     * confident match (or the terms list can't be read).
     */
    public static function qboTermId(?string $ffTerms, ?QuickBooksClient $client = null): ?string
    {
        $ffTerms = trim((string) $ffTerms);
        if ($ffTerms === '') {
            return null;
        }
        $terms = self::terms($client);
        if ($terms === null) {
            return null;
        }
        $id = self::match($ffTerms, $terms);
        if ($id === null) {
            error_log("[TermResolver] no QuickBooks term matches FF payment terms '{$ffTerms}' — customer created without terms (add the term in QuickBooks, or fix the FF value)");
        }
        return $id;
    }

    /**
     * Pure matcher (the smoke drives it): name first, then a UNIQUE
     * same-days standard term.
     *
     * @param list<array{id:string, name:string, days:?int}> $qboTerms
     */
    public static function match(string $ffTerms, array $qboTerms): ?string
    {
        $want = self::norm($ffTerms);
        foreach ($qboTerms as $t) {
            if (self::norm($t['name']) === $want) {
                return $t['id'];
            }
        }
        $days = self::dueDays($ffTerms);
        if ($days === null) {
            return null;
        }
        $hits = array_values(array_filter($qboTerms, static fn($t) => $t['days'] === $days));
        return count($hits) === 1 ? $hits[0]['id'] : null;
    }

    /**
     * Days until due for free-text terms: "Net 30" / "net30" / "30 days" /
     * "N30" → 30; "Due on receipt" / "Due upon receipt" / "COD" /
     * "Immediate" → 0; anything else → null (not guessed).
     */
    public static function dueDays(string $ffTerms): ?int
    {
        // One parser for both the due date FleetForge sets and the term it
        // sends (S-QBO-INVOICE-PAYNOW) — they can never read "Net 15" apart.
        return \FleetForge\Billing\PaymentTerms::parseDays($ffTerms);
    }

    /**
     * QuickBooks Term id for an INVOICE due $days after its date, or null
     * (no SalesTermRef — the explicit DueDate still rules).
     */
    public static function qboTermIdForInvoice(int $days, ?string $customerTerms, ?QuickBooksClient $client = null): ?string
    {
        $terms = self::terms($client);
        if ($terms === null) {
            return null;
        }
        $id = self::matchDays($days, $customerTerms, $terms);
        if ($id === null) {
            error_log("[TermResolver] no QuickBooks term for an invoice due in {$days} day(s) — pushed without a term (add a \"Net {$days}\" term in QuickBooks)");
        }
        return $id;
    }

    /**
     * Pure matcher for invoices: the customer's named term when its days
     * equal the invoice's; otherwise the ONLY active standard term with
     * those days; otherwise null.
     *
     * @param list<array{id:string, name:string, days:?int}> $qboTerms
     */
    public static function matchDays(int $days, ?string $customerTerms, array $qboTerms): ?string
    {
        $want = self::norm((string) $customerTerms);
        if ($want !== '') {
            foreach ($qboTerms as $t) {
                if (self::norm($t['name']) === $want && ($t['days'] === $days || $t['days'] === null && self::dueDays($t['name']) === $days)) {
                    return $t['id'];
                }
            }
        }
        $hits = array_values(array_filter($qboTerms, static fn($t) => $t['days'] === $days));
        return count($hits) === 1 ? $hits[0]['id'] : null;
    }

    /**
     * The company's active terms (cached per process), or null when they
     * can't be read — not cached, so the next caller retries.
     *
     * @return list<array{id:string, name:string, days:?int}>|null
     */
    private static function terms(?QuickBooksClient $client): ?array
    {
        if (self::$terms !== null) {
            return self::$terms;
        }
        try {
            $client ??= new QuickBooksClient();
            $resp = $client->query('SELECT * FROM Term WHERE Active = true MAXRESULTS 1000', ['entity_type' => 'term']);
        } catch (\Throwable $e) {
            error_log('[TermResolver] could not read QuickBooks terms: ' . $e->getMessage());
            return null;
        }
        $terms = [];
        foreach ($resp['QueryResponse']['Term'] ?? [] as $t) {
            if (empty($t['Id'])) {
                continue;
            }
            // Date-driven terms ("15th of next month") have no DueDays.
            $days = isset($t['DueDays']) && ($t['Type'] ?? 'STANDARD') === 'STANDARD' ? (int) $t['DueDays'] : null;
            $terms[] = ['id' => (string) $t['Id'], 'name' => (string) ($t['Name'] ?? ''), 'days' => $days];
        }
        return self::$terms = $terms;
    }

    /**
     * Test hook: use this list instead of reading QuickBooks.
     *
     * @param list<array{id:string, name:string, days:?int}> $terms
     */
    public static function setTermsForTesting(array $terms): void
    {
        self::$terms = $terms;
    }

    /** Test hook: forget the cached QuickBooks terms. */
    public static function resetCache(): void
    {
        self::$terms = null;
    }

    private static function norm(string $s): string
    {
        return (string) preg_replace('/\s+/', '', strtolower(trim($s)));
    }
}
