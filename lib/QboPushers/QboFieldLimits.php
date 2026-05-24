<?php
declare(strict_types=1);

/**
 * lib/QboPushers/QboFieldLimits.php
 *
 * Centralised QBO API field-length constants (S-QBO-11-FIXPACK-1 / F3).
 *
 * Used by Pre-flight Gates to fail-fast *before* wasting an HTTP round-trip
 * on QBO error 2050 ("String length is either shorter or longer than supported
 * by specification"). Source: Intuit QBO API v3 Invoice entity documentation.
 *
 * SINGLE SOURCE OF TRUTH — if a QBO limit changes, update here only.
 * All PreflightGates pick up the change automatically via self::checkLength().
 *
 * Limits verified against:
 *   https://developer.intuit.com/app/developer/qbo/docs/api/accounting/most-commonly-used/invoice
 * (observed in live sandbox via QBO error 2050 on DocNumber=22 chars, 2026-05-23)
 *
 * @session  S-QBO-11-FIXPACK-1
 * @decision D-QBO-FIXPACK-4 (single-source field limits; Invoice constants first)
 */

namespace FleetForge\QboPushers;

class QboFieldLimits
{
    // ── Invoice entity ────────────────────────────────────────────────────
    // DocNumber: observed QBO error 2050 Min:0 Max:21 (live sandbox, 2026-05-23)
    public const INVOICE_DOC_NUMBER_MAX       = 21;
    // PrivateNote: QBO API spec 4000 chars
    public const INVOICE_PRIVATE_NOTE_MAX     = 4000;
    // CustomerMemo: QBO API spec 1000 chars
    public const INVOICE_CUSTOMER_MEMO_MAX    = 1000;
    // Line Description: QBO API spec 4000 chars
    public const INVOICE_LINE_DESCRIPTION_MAX = 4000;

    // ── Future gates (placeholder comments; fill in when ported) ─────────
    // CustomerPusher / VendorPusher field limits will be added here per
    // D-QBO-FIXPACK-4: single-source policy.
    //
    // public const CUSTOMER_DISPLAY_NAME_MAX  = 500;   // TODO: verify
    // public const CUSTOMER_COMPANY_NAME_MAX  = 50;    // TODO: verify
    // public const VENDOR_DISPLAY_NAME_MAX    = 100;   // TODO: verify

    /**
     * Check a value against a QBO API length limit.
     *
     * Returns null when the value is within limit (or null — NULL values
     * are allowed; QBO only validates non-null strings). Returns an
     * actionable error message when the limit is violated.
     *
     * @param string      $fieldLabel  Human-readable label for error message
     *                                 (e.g. "invoice.invoice_number → DocNumber")
     * @param string|null $value       The value to check (null passes through)
     * @param int         $maxLength   The QBO API character limit
     * @return string|null  null = OK; non-null = actionable error message
     */
    public static function checkLength(string $fieldLabel, ?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;  // null fields pass; QBO checks length of non-null strings only
        }
        $actualLength = strlen($value);
        if ($actualLength > $maxLength) {
            return "Field '{$fieldLabel}' is {$actualLength} characters; QBO limit is {$maxLength}. "
                 . "Shorten the value before push (QBO error 2050 if sent as-is).";
        }
        return null;
    }
}
