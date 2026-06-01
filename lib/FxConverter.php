<?php
declare(strict_types=1);

/**
 * lib/FxConverter.php
 *
 * Multi-currency display helper (F15 / S-QBO-FX-RECON-FOLLOWUP). Converts a
 * foreign-currency amount to the FF home currency using a FROZEN pull-time
 * exchange rate (the `qbo_exchange_rate_snapshot` captured by S-QBO-20's bank
 * CDC pull). This is the buildable, deterministic half of F15:
 *
 *   - DONE here: display-time conversion at the snapshot rate + a currency
 *     badge, so a USD-denominated bank-account mirror row stops showing a bare
 *     USD figure on a CAD-context page (the operator-confusion F15 flagged).
 *   - DEFERRED (needs a live FX feed → verify-at-cutover): true REVALUATION —
 *     recomputing unrealized FX gain/loss as rates move. Frozen-rate display
 *     never moves, so it can't mislead; it just labels + converts what QBO
 *     already pulled.
 *
 * QBO ExchangeRate convention: home-currency units per ONE unit of the
 * transaction currency → home = foreign × rate.
 *
 * @session F15 (S-QBO-FX-RECON-FOLLOWUP)
 */

namespace FleetForge;

class FxConverter
{
    /** FF home currency (QBO CompanyInfo home currency; default CAD). */
    public static function homeCurrency(): string
    {
        $c = strtoupper((string) settings_get('quickbooks.home_currency', 'CAD'));
        return $c !== '' ? $c : 'CAD';
    }

    /** True when a currency is set and differs from home. */
    public static function isForeign(?string $currency): bool
    {
        $c = strtoupper((string) ($currency ?? ''));
        return $c !== '' && $c !== self::homeCurrency();
    }

    /**
     * Home-currency equivalent of a foreign amount at a frozen rate (home units
     * per 1 foreign unit). Returns null when the rate is missing or non-positive
     * (cannot convert — caller shows the foreign amount only).
     */
    public static function homeEquivalent(string $foreignAmount, ?string $rate): ?string
    {
        if ($rate === null || $rate === '') {
            return null;
        }
        if (bccomp((string) $rate, '0', 6) <= 0) {
            return null;
        }
        return bcmul(bcadd($foreignAmount, '0', 6), (string) $rate, 2);
    }

    /**
     * Render-ready label: e.g. "≈ CAD 1,234.56" for a foreign row, or '' when
     * not foreign / not convertible. Caller pairs it with a currency badge.
     */
    public static function homeEquivalentLabel(string $foreignAmount, ?string $currency, ?string $rate): string
    {
        if (!self::isForeign($currency)) {
            return '';
        }
        $home = self::homeEquivalent($foreignAmount, $rate);
        if ($home === null) {
            return '';
        }
        $neg = bccomp($home, '0', 2) < 0;
        return '≈ ' . self::homeCurrency() . ' ' . ($neg ? '-' : '') . number_format(abs((float) $home), 2);
    }
}
