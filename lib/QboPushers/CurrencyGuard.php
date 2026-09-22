<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CurrencyGuard.php
 *
 * Pre-flight guard shared by every money-bearing Pusher: refuse to push a
 * foreign-currency record into a SINGLE-currency QuickBooks company.
 *
 * WHY: when quickbooks.multi_currency_enabled='0' every Pusher omits
 * CurrencyRef/ExchangeRate (D-QBO-FIXPACK-12 — QBO rejects them with 6000
 * on single-currency companies). QBO then books the payload's numbers in
 * the HOME currency, so a USD 1,000.00 invoice silently becomes CAD 1,000.00
 * — revenue and AR understated by the FX spread, with no error anywhere.
 * The existing currency-mismatch gates all ran ONLY when multi-currency was
 * ON, i.e. never in the one configuration where this corruption happens.
 * A brand-new QBO company ships with multicurrency OFF (and turning it on
 * is irreversible), so this is the default go-live state.
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

namespace FleetForge\QboPushers;

final class CurrencyGuard
{
    /**
     * Null when the record may be pushed; otherwise an operator-facing
     * reason. A missing/empty FF currency is treated as the home currency
     * (legacy rows predate the currency columns and were all CAD).
     *
     * @param string|null $ffCurrency FF record currency (e.g. 'USD')
     * @param string      $what       human label, e.g. "Invoice INV-2026-00012"
     */
    public static function blockReason(?string $ffCurrency, string $what): ?string
    {
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            return null;
        }
        $home = strtoupper(trim((string) settings_get('quickbooks.home_currency', 'CAD')));
        if ($home === '') {
            $home = 'CAD';
        }
        $currency = strtoupper(trim((string) ($ffCurrency ?? '')));
        if ($currency === '' || $currency === $home) {
            return null;
        }
        return "{$what} is in {$currency}, but the connected QuickBooks company is single-currency ({$home}). "
            . "Pushing it would record the {$currency} amounts as {$home}. Turn on Multicurrency in QuickBooks "
            . "(Settings → Account and settings → Advanced → Currency), then reconnect so FleetForge detects it.";
    }
}
