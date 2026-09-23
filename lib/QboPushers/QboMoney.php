<?php
declare(strict_types=1);

/**
 * lib/QboPushers/QboMoney.php
 *
 * S-QBO-MONEY-JSON — the one way a money amount enters a QuickBooks JSON
 * payload.
 *
 * FleetForge does money math in bcmath strings, never floats. QuickBooks'
 * JSON wants numbers, so every pusher cast its bcmath result with (float)
 * at the payload boundary — ~18 scattered casts, which the pre-go-live audit
 * flagged as "money as float" (exact only because PHP's serialize_precision
 * happens to be -1). This helper makes the rule explicit:
 *   - the value is rounded to cents IN BCMATH (a stray third decimal is
 *     rounded half-up, not truncated and not left for QuickBooks to reject);
 *   - it becomes a PHP float only here, at the JSON boundary;
 *   - QuickBooksClient::encodeBody() then prints it with the shortest exact
 *     representation whatever php.ini says (so 1234.56 is sent as 1234.56,
 *     never 1234.5599999999999).
 * A two-decimal amount below 10^13 survives the float step exactly.
 *
 * @session S-QBO-MONEY-JSON
 */

namespace FleetForge\QboPushers;

final class QboMoney
{
    /**
     * A money amount for a QuickBooks payload: rounded to cents in bcmath,
     * returned as the JSON number QuickBooks expects.
     *
     * @param string|int|float|null $value decimal string (FF money column / bcmath result), or a number
     * @throws \InvalidArgumentException on a non-numeric value
     */
    public static function amount(string|int|float|null $value): float
    {
        return (float) self::decimal($value);
    }

    /**
     * The same amount as a 2-decimal bcmath string — for arithmetic on a
     * value that may have come back from QuickBooks as a float (e.g. adding
     * to an existing Payment line's Amount).
     *
     * @param string|int|float|null $value
     * @throws \InvalidArgumentException on a non-numeric value
     */
    public static function decimal(string|int|float|null $value): string
    {
        if (is_float($value)) {
            // Round to cents first, then format: no binary digits leak in.
            return number_format(round($value, 2), 2, '.', '');
        }
        $s = trim((string) $value);
        if ($s === '') {
            $s = '0';
        }
        if (!is_numeric($s) || stripos($s, 'e') !== false) {
            throw new \InvalidArgumentException("Not a money amount: '{$s}'");
        }
        return bcround($s, 2);
    }
}
