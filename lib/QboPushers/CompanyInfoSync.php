<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CompanyInfoSync.php
 *
 * Queries QBO CompanyInfo at OAuth connect time and at token refresh
 * time, then caches the multi-currency flag + home currency into the
 * settings table. These cached values gate CurrencyRef emission across
 * all Pushers (D-QBO-FIXPACK-12) so we never send CurrencyRef to a
 * single-currency QBO company (which would trigger error 6000:
 * "Multi Currency should be enabled to perform this operation").
 *
 * WHY a dedicated sync call (D-QBO-FIXPACK-11):
 *   FIXPACK-1 + FIXPACK-2 established "always emit CurrencyRef" as a
 *   class-of-entity principle, but QBO rejects CurrencyRef entirely when
 *   multi-currency is disabled — even for home-currency entities. The
 *   fix is to query CompanyInfo once per connect/refresh and store the
 *   flag so every Pusher can consult it without a live QBO round-trip.
 *
 * Call sites (D-QBO-FIXPACK-11 locked):
 *   1. app/admin/oauth/qbo/callback.php — success branch, after tokens stored
 *   2. cron/qbo_token_refresh.php       — success branch, after tokens stored
 *
 * Safe to call when QBO is offline: the method is wrapped in try/catch
 * at both call sites. A failed CompanyInfo sync is non-fatal; the
 * previously-cached value (conservative default: '0') stays in effect.
 *
 * K-22 awareness:
 *   QBO CompanyInfo field name for multi-currency flag varies between
 *   documentation versions. Three known variants are tried in order:
 *   'MultiCurrencyEnabled', 'MultiCurrency', 'IsMultiCurrencyEnabled'.
 *   Country → currency mapping covers CA, US, GB, AU; unknown countries
 *   fall back to 'CAD' with a warning log.
 *
 * @session  S-QBO-FIXPACK-3
 * @decision D-QBO-FIXPACK-11 (CompanyInfo auto-detection call sites),
 *           D-QBO-FIXPACK-12 (CurrencyRef emission gated on cached setting),
 *           D-QBO-FIXPACK-13 (safe conservative defaults)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class CompanyInfoSync
{
    /**
     * Country-code → ISO 4217 currency mapping.
     * Extend here as Mainland adds international operators.
     */
    private const COUNTRY_CURRENCY_MAP = [
        'CA' => 'CAD',
        'US' => 'USD',
        'GB' => 'GBP',
        'AU' => 'AUD',
        'NZ' => 'NZD',
        'EU' => 'EUR',
    ];

    /**
     * Query QBO CompanyInfo, parse multi-currency state + home currency,
     * and cache results into the settings table via settings_write_qbo().
     *
     * Called at: (a) OAuth connect completion (callback.php success branch),
     *            (b) token refresh success (qbo_token_refresh.php).
     *
     * Returns the parsed values so call sites can log them if needed.
     * Returns [] on any parse failure (settings remain unchanged; caller
     * should log the empty return as an advisory gap, not an error).
     *
     * @param  QuickBooksClient $client Authenticated client (ensureValidToken
     *                                  will be called internally).
     * @return array{
     *   multi_currency_enabled: bool,
     *   home_currency: string,
     *   company_country: string,
     * }|array{} Empty on failure.
     */
    public static function syncFromQbo(QuickBooksClient $client): array
    {
        // CompanyInfo is available via QBO SQL query. The normalizeQueryResponse
        // helper in QuickBooksClient wraps single-object results into a
        // single-element array (K-22 Trap #64), so CompanyInfo always lands
        // under QueryResponse.CompanyInfo[0] after the call.
        $resp = $client->query(
            "SELECT * FROM CompanyInfo",
            ['entity_type' => 'companyinfo', 'operation' => 'query']
        );

        // Extract the CompanyInfo row, handling both normalised (array[0])
        // and un-normalised (bare assoc) shapes.
        $info = $resp['QueryResponse']['CompanyInfo'] ?? null;
        if (is_array($info) && isset($info[0])) {
            $info = $info[0];
        }

        if (!is_array($info) || empty($info)) {
            error_log('S-QBO-FIXPACK-3 CompanyInfoSync: CompanyInfo query returned no data; settings left unchanged.');
            return [];
        }

        // ── Multi-currency flag ────────────────────────────────────────
        // QBO field name varies between API versions. Try known variants;
        // default to false when none are present (conservative — omit
        // CurrencyRef rather than risk error 6000).
        $multiCurrencyEnabled = false;
        foreach (['MultiCurrencyEnabled', 'MultiCurrency', 'IsMultiCurrencyEnabled'] as $field) {
            if (isset($info[$field])) {
                // QBO may return boolean true/false or string 'true'/'false'.
                $raw = $info[$field];
                $multiCurrencyEnabled = ($raw === true || $raw === 'true' || $raw === 1 || $raw === '1');
                break;
            }
        }

        // ── Country → home currency ────────────────────────────────────
        $country      = strtoupper(trim((string) ($info['Country'] ?? '')));
        $homeCurrency = self::countryToCurrency($country);

        // ── Persist to settings ────────────────────────────────────────
        // settings_write_qbo() uses ON DUPLICATE KEY UPDATE so existing
        // rows are updated atomically. The short keys map to the
        // 'quickbooks.X' full key automatically.
        // S-QBO-GOLIVE-AUDIT: the company's PREFERENCES are the authoritative
        // source for multicurrency + home currency (Preferences.CurrencyPrefs)
        // — CompanyInfo does not carry them, so the variants tried above
        // normally never matched and multicurrency always read OFF. The same
        // call also yields class / location tracking, "Custom transaction
        // numbers" and the books closing date, which the pushers need.
        $prefs = self::syncPreferences($client);
        if (isset($prefs['multi_currency_enabled'])) {
            $multiCurrencyEnabled = $prefs['multi_currency_enabled'];
        }
        if (!empty($prefs['home_currency'])) {
            $homeCurrency = $prefs['home_currency'];
        }

        QuickBooksClient::settings_write_qbo(
            'multi_currency_enabled',
            $multiCurrencyEnabled ? '1' : '0'
        );
        QuickBooksClient::settings_write_qbo('home_currency',    $homeCurrency);
        QuickBooksClient::settings_write_qbo('company_country',  $country);

        error_log(
            'S-QBO-FIXPACK-3 CompanyInfoSync: synced — ' .
            'multi_currency_enabled=' . ($multiCurrencyEnabled ? 'true' : 'false') . ', ' .
            "home_currency={$homeCurrency}, country={$country}"
        );

        return [
            'multi_currency_enabled' => $multiCurrencyEnabled,
            'home_currency'          => $homeCurrency,
            'company_country'        => $country,
        ];
    }

    /**
     * Read the company's Preferences and cache what FleetForge acts on:
     *
     *   quickbooks.multi_currency_enabled   CurrencyPrefs.MultiCurrencyEnabled
     *   quickbooks.home_currency            CurrencyPrefs.HomeCurrency
     *   quickbooks.pref.class_tracking      'none' | 'txn' | 'line' — where a
     *                                       ClassRef goes (header vs line)
     *   quickbooks.pref.track_locations     '1' when Location (Department)
     *                                       tracking is on
     *   quickbooks.pref.custom_txn_numbers  '1' | '0' — FF sends its own
     *                                       DocNumber only when '1'
     *   quickbooks.pref.book_close_date     books closing date ('' if none) —
     *                                       pushes dated before it are refused
     *
     * Best-effort: any failure leaves the previous values and returns [].
     *
     * @return array{multi_currency_enabled?: bool, home_currency?: string}
     * @session S-QBO-GOLIVE-AUDIT
     */
    public static function syncPreferences(QuickBooksClient $client): array
    {
        try {
            $resp = $client->get('preferences', [], ['entity_type' => 'companyinfo', 'operation' => 'get']);
        } catch (\Throwable $e) {
            error_log('CompanyInfoSync::syncPreferences: ' . $e->getMessage());
            return [];
        }
        $p = $resp['Preferences'] ?? null;
        if (!is_array($p)) {
            return [];
        }
        $bool = static fn($v): bool => $v === true || $v === 'true' || $v === 1 || $v === '1';
        $out  = [];

        $cur = $p['CurrencyPrefs'] ?? [];
        if (array_key_exists('MultiCurrencyEnabled', $cur)) {
            $out['multi_currency_enabled'] = $bool($cur['MultiCurrencyEnabled']);
        }
        $home = strtoupper(trim((string) ($cur['HomeCurrency']['value'] ?? '')));
        if ($home !== '') {
            $out['home_currency'] = $home;
        }

        $acct    = $p['AccountingInfoPrefs'] ?? [];
        $perTxn  = $bool($acct['ClassTrackingPerTxn'] ?? false);
        $perLine = $bool($acct['ClassTrackingPerTxnLine'] ?? false);
        QuickBooksClient::settings_write_qbo('pref.class_tracking', $perLine ? 'line' : ($perTxn ? 'txn' : 'none'));
        QuickBooksClient::settings_write_qbo('pref.track_locations', $bool($acct['TrackDepartments'] ?? false) ? '1' : '0');
        QuickBooksClient::settings_write_qbo('pref.book_close_date', substr((string) ($acct['BookCloseDate'] ?? ''), 0, 10));

        $sales = $p['SalesFormsPrefs'] ?? [];
        if (array_key_exists('CustomTxnNumbers', $sales)) {
            QuickBooksClient::settings_write_qbo('pref.custom_txn_numbers', $bool($sales['CustomTxnNumbers']) ? '1' : '0');
        }
        QuickBooksClient::settings_write_qbo('pref.synced_at', gmdate('c'));
        return $out;
    }

    /**
     * Map a QBO Country code to an ISO 4217 currency code.
     * Unknown countries default to 'CAD' (Mainland is Canadian per spec §0)
     * and emit a warning log so an operator adding a non-CA company notices.
     *
     * Public-static so the smoke can exercise it directly without
     * going through the QBO HTTP boundary.
     *
     * @param  string $country QBO Country code (e.g. 'CA', 'US'). Case-insensitive.
     * @return string ISO 4217 currency code (e.g. 'CAD', 'USD').
     */
    public static function countryToCurrency(string $country): string
    {
        $upper = strtoupper(trim($country));
        if (isset(self::COUNTRY_CURRENCY_MAP[$upper])) {
            return self::COUNTRY_CURRENCY_MAP[$upper];
        }
        if ($upper !== '') {
            error_log(
                "S-QBO-FIXPACK-3 CompanyInfoSync: unknown QBO country '{$country}'; " .
                "defaulting home_currency to CAD. Extend COUNTRY_CURRENCY_MAP if needed."
            );
        }
        return 'CAD';
    }
}
