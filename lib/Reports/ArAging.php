<?php
declare(strict_types=1);
/**
 * lib/Reports/ArAging.php
 *
 * THE accounts-receivable aging calculation, shared by Accounting → AR Aging
 * (api/v1/accounting/reports/ar-aging.php), Reports → Financial → AR Aging
 * (api/v1/reports/revenue.php?view=ar_aging) and the Dashboard AR aging chart
 * (api/v1/dashboard/charts.php), so the three can no longer disagree.
 *
 * TWO RULES THE OLD INLINE QUERIES BROKE
 *
 * 1. CAD IS CANONICAL. Each invoice's balance is converted with ITS OWN frozen
 *    invoices.exchange_rate_to_cad (USD rows; CAD rows pass through), in
 *    bcmath. The old code summed USD and CAD balances as if they were the same
 *    currency. A USD invoice with a NULL rate falls back to 1.0 — the same
 *    rule as ReportBuilder::cad() — and is counted in fx_rate_missing_count so
 *    a caller can surface it.
 *
 * 2. "AS OF" MEANS AS OF. An invoice is included only if it was issued on or
 *    before the as-of date, and its balance is the balance AS AT that date:
 *    payments / credits applied AFTER the as-of date do not reduce it.
 *    The old code only re-bucketed today's balances, so a 2025-12-31 report
 *    listed invoices issued in 2026 as "current".
 *
 * HOW THE AS-OF BALANCE IS DERIVED (roll-back from today's balance)
 *   as_of_balance = base
 *                 + payment allocations whose payment_date  > as-of (live payments)
 *                 - payment allocations whose payment_date <= as-of but whose
 *                   payment was voided (deleted_at) / bounced (returned_date)
 *                   AFTER the as-of date (the reversal already re-opened today's
 *                   balance, so it must come back off)
 *                 + credit-note applications applied after as-of (still applied)
 *                 - credit-note applications applied on/before as-of but
 *                   reversed after it
 *                 + customer deposits applied after as-of
 *   base = invoices.balance_due for sent / partially_paid / overdue / paid;
 *          the written-off amount for invoices written off after as-of;
 *          total − credits − paid for SENT invoices voided after as-of.
 *   Rolling back from today's stored balance (instead of rebuilding from
 *   total − every dated application) keeps rows that have no application
 *   history — e.g. imported/seeded invoices marked paid without payment rows —
 *   at their stored balance rather than resurrecting them as unpaid.
 *   The result is clamped to [0, total_amount]. With as-of >= today every
 *   add-back is empty and the balance is exactly today's balance_due.
 *
 * DATING CHOICES / LIMITATIONS (documented, not hidden)
 *   - A payment reduces AR on payments.payment_date (standard aging practice),
 *     even if it was allocated to the invoice later.
 *   - credit_note_applications.applied_at / reversed_at are UTC DATETIMEs
 *     (S-UTC-STAMPS). They are dated to the COMPANY-LOCAL day: "after as-of"
 *     means at/after 00:00 local on as-of + 1, compared as a UTC instant
 *     (sargable, DST-correct) — a 10pm-local application stays on its own day.
 *   - Invoice total_amount changes after sending (sent invoices are frozen by
 *     the billing engine) are not re-dated.
 *   - Bad-debt recoveries are not modelled (none exist; the write-off amount is
 *     used as the balance for a later write-off).
 *
 * Used by: api/v1/accounting/reports/ar-aging.php, api/v1/reports/revenue.php,
 *          api/v1/dashboard/charts.php
 * Requires: includes/db.php (db_select)
 */

namespace FleetForge\Reports;

final class ArAging
{
    /** Bucket keys in display order. */
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

    /**
     * Build the aging as at $asOf.
     *
     * @param string $asOf Y-m-d as-of date
     * @return array{
     *   as_of_date:string, currency:string, invoice_count:int,
     *   fx_rate_missing_count:int,
     *   totals: array<string,string>,
     *   native_totals: array<string,string>,
     *   invoices: list<array<string,mixed>>,
     *   customers: list<array<string,mixed>>
     * } all money values are 2dp strings; totals/customers are CAD
     */
    public static function asOf(string $asOf): array
    {
        // S-UTC-STAMPS: credit-note application stamps are UTC instants; the
        // end of the as-of LOCAL business day is 00:00 local on the next day.
        $asOfEndUtc = \ff_local_day_start_utc(\ff_local_date_add($asOf, 1));

        $rows = \db_select(
            "SELECT i.id, i.invoice_number, i.customer_id,
                    COALESCE(c.company_name, i.company_name_snapshot, 'Unknown') AS company_name,
                    i.invoice_date, i.due_date, i.status, i.currency, i.exchange_rate_to_cad,
                    i.total_amount, i.amount_paid, i.credits_applied, i.balance_due,
                    COALESCE(pa_after.amt, 0)  AS paid_after,
                    COALESCE(pa_undone.amt, 0) AS paid_undone_after,
                    COALESCE(cn_after.amt, 0)  AS credit_after,
                    COALESCE(cn_undone.amt, 0) AS credit_undone_after,
                    COALESCE(dep_after.amt, 0) AS deposit_after,
                    wo.amt                     AS writeoff_amount
               FROM invoices i
               LEFT JOIN customers c ON c.id = i.customer_id
               LEFT JOIN (
                    SELECT pa.invoice_id, SUM(pa.amount) AS amt
                      FROM payment_allocations pa
                      JOIN payments p ON p.id = pa.payment_id
                     WHERE p.payment_date > ?
                       AND p.deleted_at IS NULL
                       AND p.status NOT IN ('failed', 'returned')
                     GROUP BY pa.invoice_id
               ) pa_after ON pa_after.invoice_id = i.id
               LEFT JOIN (
                    SELECT pa.invoice_id, SUM(pa.amount) AS amt
                      FROM payment_allocations pa
                      JOIN payments p ON p.id = pa.payment_id
                     WHERE p.payment_date <= ?
                       AND (   (p.deleted_at IS NOT NULL AND p.deleted_at >= ?)
                            OR (p.deleted_at IS NULL AND p.status IN ('failed', 'returned') AND p.returned_date > ?))
                     GROUP BY pa.invoice_id
               ) pa_undone ON pa_undone.invoice_id = i.id
               LEFT JOIN (
                    SELECT invoice_id, SUM(amount_applied) AS amt
                      FROM credit_note_applications
                     WHERE status = 'applied' AND applied_at >= ?
                     GROUP BY invoice_id
               ) cn_after ON cn_after.invoice_id = i.id
               LEFT JOIN (
                    SELECT invoice_id, SUM(amount_applied) AS amt
                      FROM credit_note_applications
                     WHERE status = 'reversed' AND applied_at < ? AND reversed_at >= ?
                     GROUP BY invoice_id
               ) cn_undone ON cn_undone.invoice_id = i.id
               LEFT JOIN (
                    SELECT applied_to_invoice_id AS invoice_id, SUM(amount) AS amt
                      FROM acc_customer_deposits
                     WHERE status = 'applied' AND applied_date > ?
                     GROUP BY applied_to_invoice_id
               ) dep_after ON dep_after.invoice_id = i.id
               LEFT JOIN (
                    SELECT invoice_id, SUM(amount) AS amt
                      FROM acc_bad_debt_writeoffs
                     GROUP BY invoice_id
               ) wo ON wo.invoice_id = i.id
              WHERE i.deleted_at IS NULL
                AND i.invoice_date <= ?
                AND (
                        i.status IN ('sent', 'partially_paid', 'overdue')
                     -- a paid invoice only matters if money arrived after as-of
                     OR (i.status = 'paid'
                         AND (pa_after.amt IS NOT NULL OR cn_after.amt IS NOT NULL OR dep_after.amt IS NOT NULL))
                     OR (i.status = 'written_off' AND i.written_off_at >= ?)
                     -- only a SENT invoice was ever AR; a voided draft never was
                     OR (i.status = 'void' AND i.voided_date > ?
                         AND (i.sent_at IS NOT NULL OR i.sent_date IS NOT NULL))
                )
              ORDER BY company_name ASC, i.due_date ASC, i.id ASC",
            // Params in textual order. DATETIME stamps (payment void = p.deleted_at #3,
            // credit-note application/reversal #5–7, write-off #10) are UTC instants
            // compared with the UTC end of the as-of LOCAL day; payment_date,
            // returned_date, applied_date, invoice_date and voided_date are business
            // DATEs compared with $asOf itself (S-UTC-STAMPS).
            [$asOf, $asOf, $asOfEndUtc, $asOf, $asOfEndUtc, $asOfEndUtc, $asOfEndUtc, $asOf, $asOf, $asOfEndUtc, $asOf]
        );

        $totals       = array_fill_keys(array_merge(self::BUCKETS, ['total']), '0.00');
        $nativeTotals = ['CAD' => '0.00', 'USD' => '0.00'];
        $invoices     = [];
        $customers    = [];
        $missingRate  = 0;

        foreach ($rows as $r) {
            $balance = self::asOfBalance($r);
            if (bccomp($balance, '0', 2) <= 0) {
                continue;
            }

            $currency = (string) ($r['currency'] ?? 'CAD');
            $rate     = '1';
            if ($currency === 'USD') {
                if ($r['exchange_rate_to_cad'] === null || bccomp((string) $r['exchange_rate_to_cad'], '0', 6) <= 0) {
                    $missingRate++;   // same 1.0 fallback as ReportBuilder::cad()
                } else {
                    $rate = (string) $r['exchange_rate_to_cad'];
                }
            }
            $balanceCad = bcround(bcmul($balance, $rate, 8), 2);
            $totalCad   = bcround(bcmul((string) $r['total_amount'], $rate, 8), 2);

            $daysPastDue = self::daysPastDue($r['due_date'] ?? null, $asOf);
            $bucket      = self::bucketFor($daysPastDue);

            $inv = [
                'invoice_id'           => (int) $r['id'],
                'invoice_number'       => $r['invoice_number'],
                'customer_id'          => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
                'company_name'         => $r['company_name'],
                'invoice_date'         => $r['invoice_date'],
                'due_date'             => $r['due_date'],
                'status'               => $r['status'],
                'currency'             => $currency,
                'exchange_rate_to_cad' => $currency === 'USD' ? $rate : null,
                'total_amount_native'  => bcround((string) $r['total_amount'], 2),
                'total_amount'         => $totalCad,       // CAD
                'balance_due_native'   => $balance,
                'balance_due'          => $balanceCad,     // CAD
                'days_past_due'        => $daysPastDue,
                'bucket'               => $bucket,
            ];
            $invoices[] = $inv;

            $totals[$bucket]  = bcadd($totals[$bucket], $balanceCad, 2);
            $totals['total']  = bcadd($totals['total'], $balanceCad, 2);
            $nativeTotals[$currency] = bcadd($nativeTotals[$currency] ?? '0.00', $balance, 2);

            // Group per customer (null customer_id grouped under key 0).
            $ck = (int) ($r['customer_id'] ?? 0);
            if (!isset($customers[$ck])) {
                $customers[$ck] = array_merge(
                    ['customer_id' => $ck ?: null, 'company_name' => $r['company_name']],
                    array_fill_keys(array_merge(self::BUCKETS, ['total']), '0.00'),
                    ['invoices' => []]
                );
            }
            $customers[$ck][$bucket]  = bcadd($customers[$ck][$bucket], $balanceCad, 2);
            $customers[$ck]['total']  = bcadd($customers[$ck]['total'], $balanceCad, 2);
            $customers[$ck]['invoices'][] = $inv;
        }

        return [
            'as_of_date'            => $asOf,
            'currency'              => 'CAD',
            'invoice_count'         => count($invoices),
            'fx_rate_missing_count' => $missingRate,
            'totals'                => $totals,
            'native_totals'         => $nativeTotals,
            'invoices'              => $invoices,
            'customers'             => array_values($customers),
        ];
    }

    /**
     * Roll one candidate row's balance back to the as-of date (native currency).
     * See the class docblock for the formula.
     *
     * @param array<string,mixed> $r row from asOf()'s query
     * @return string 2dp, clamped to [0, total_amount]
     */
    public static function asOfBalance(array $r): string
    {
        $total = (string) $r['total_amount'];

        $base = match ((string) $r['status']) {
            'written_off' => $r['writeoff_amount'] !== null
                ? (string) $r['writeoff_amount']
                : bcsub(bcsub($total, (string) $r['credits_applied'], 2), (string) $r['amount_paid'], 2),
            'void'        => bcsub(bcsub($total, (string) $r['credits_applied'], 2), (string) $r['amount_paid'], 2),
            default       => (string) $r['balance_due'],
        };

        $bal = $base;
        $bal = bcadd($bal, (string) $r['paid_after'], 2);
        $bal = bcsub($bal, (string) $r['paid_undone_after'], 2);
        $bal = bcadd($bal, (string) $r['credit_after'], 2);
        $bal = bcsub($bal, (string) $r['credit_undone_after'], 2);
        $bal = bcadd($bal, (string) $r['deposit_after'], 2);

        if (bccomp($bal, '0', 2) < 0) {
            return '0.00';
        }
        if (bccomp($bal, $total, 2) > 0) {
            return bcround($total, 2);
        }
        return bcround($bal, 2);
    }

    /**
     * Whole days past due at the as-of date (0 when not yet due / no due date).
     *
     * @param string|null $dueDate Y-m-d
     * @param string      $asOf    Y-m-d
     * @return int
     */
    public static function daysPastDue(?string $dueDate, string $asOf): int
    {
        if ($dueDate === null || $dueDate === '' || $dueDate >= $asOf) {
            return 0;
        }
        return (int) (new \DateTimeImmutable($dueDate))->diff(new \DateTimeImmutable($asOf))->days;
    }

    /**
     * Bucket key for a days-past-due count.
     *
     * @param int $daysPastDue
     * @return string one of self::BUCKETS
     */
    public static function bucketFor(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0  => 'current',
            $daysPastDue <= 30 => 'days_1_30',
            $daysPastDue <= 60 => 'days_31_60',
            $daysPastDue <= 90 => 'days_61_90',
            default            => 'days_90_plus',
        };
    }
}
