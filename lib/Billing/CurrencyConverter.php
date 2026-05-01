<?php
declare(strict_types=1);

namespace FleetForge\Billing;

/**
 * lib/Billing/CurrencyConverter.php
 *
 * CAD/USD conversion using frozen exchange rates from the exchange_rates table.
 * All monetary math uses bcmath exclusively — zero float operators.
 *
 * @spec    §9 Billing Engine — Currency
 * @session CURRENCY-MARKUP-1
 * @depends includes/db.php (db_row)
 */
class CurrencyConverter
{
    /**
     * Convert an amount between currencies using the exchange_rates table.
     *
     * Fetches the most recent rate on or before $date. If $date is null or no
     * row exists for that date, falls back to the most recent rate overall.
     * Returns the converted amount as a string with 2 decimal places.
     * If no rate row exists at all, returns the original amount unchanged.
     *
     * @param string      $amount  Source amount (bcmath-safe string)
     * @param string      $from    ISO currency code ('USD' or 'CAD')
     * @param string      $to      ISO currency code ('USD' or 'CAD')
     * @param string|null $date    Rate date in Y-m-d format; null = latest
     */
    public function convert(string $amount, string $from, string $to, ?string $date = null): string
    {
        if ($from === $to) {
            return bcadd($amount, '0', 2);
        }

        $row = null;

        if ($date !== null) {
            $row = \db_row(
                "SELECT rate FROM exchange_rates
                  WHERE from_currency = ? AND to_currency = ?
                    AND rate_date <= ?
                  ORDER BY rate_date DESC LIMIT 1",
                [$from, $to, $date]
            );
        }

        if (empty($row)) {
            $row = \db_row(
                "SELECT rate FROM exchange_rates
                  WHERE from_currency = ? AND to_currency = ?
                  ORDER BY rate_date DESC LIMIT 1",
                [$from, $to]
            );
        }

        if (empty($row)) {
            return bcadd($amount, '0', 2);
        }

        return bcmul($amount, (string) $row['rate'], 2);
    }

    /**
     * Compute the effective exchange rate after applying a markup percentage.
     *
     * effective_rate = bank_rate × (1 + markup_pct ÷ 100)
     *
     * EC7: when markup_pct is exactly 0, returns bank_rate unchanged (no
     * rounding drift).
     *
     * Returns a string with 6 decimal places (matching exchange_rate_to_cad
     * precision in the DB).
     *
     * @param string $bankRate   Raw bank exchange rate as string (e.g. '1.352000')
     * @param string $markupPct  Markup percentage as string (e.g. '1.5000')
     */
    public function getEffectiveRate(string $bankRate, string $markupPct): string
    {
        if (bccomp($markupPct, '0', 4) === 0) {
            return bcadd($bankRate, '0', 6);
        }

        // factor = 1 + (markup_pct / 100)
        $factor = bcadd('1', bcdiv($markupPct, '100', 10), 10);

        return bcmul($bankRate, $factor, 6);
    }

    /**
     * Convert a USD amount to CAD applying a markup on top of the bank rate.
     *
     * All math uses bcmath — no float operators.
     *
     * Business rule: the bank rate (exchange_rate_to_cad) is NEVER modified.
     * The markup is stored separately so the full breakdown is auditable.
     * The customer is charged at the effective rate; that is what hits revenue.
     *
     * D-B NOTE: accounting module FX gain/loss is currently computed against
     * journal-entry exchange_rate fields, not the invoice frozen rate. If that
     * module is updated to use the effective rate, this method provides the
     * canonical computation. Deferred to a separate accounting session.
     *
     * @param string $usdAmount   Amount in USD (bcmath string)
     * @param string $bankRate    Raw bank rate USD→CAD (6 decimal places)
     * @param string $markupPct   Markup percentage (e.g. '1.5000' = 1.5%)
     *
     * @return array{
     *   cad_amount: string,
     *   effective_rate: string,
     *   bank_rate: string,
     *   markup_pct: string,
     *   markup_cad_amount: string,
     * }
     */
    public function convertWithMarkup(string $usdAmount, string $bankRate, string $markupPct): array
    {
        $effectiveRate   = $this->getEffectiveRate($bankRate, $markupPct);
        $cadAmount       = bcmul($usdAmount, $effectiveRate, 2);
        $cadAtBankRate   = bcmul($usdAmount, $bankRate, 2);
        $markupCadAmount = bcsub($cadAmount, $cadAtBankRate, 2);

        return [
            'cad_amount'        => $cadAmount,
            'effective_rate'    => $effectiveRate,
            'bank_rate'         => bcadd($bankRate, '0', 6),
            'markup_pct'        => $markupPct,
            'markup_cad_amount' => $markupCadAmount,
        ];
    }
}
