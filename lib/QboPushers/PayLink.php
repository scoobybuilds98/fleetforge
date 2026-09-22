<?php
declare(strict_types=1);

/**
 * lib/QboPushers/PayLink.php
 *
 * The "Pay online" link FleetForge puts in its own invoice emails
 * ({pay_online_link} template variable).
 *
 * WHY a FleetForge link and not the QuickBooks one directly: FF emails the
 * invoice at SEND time, but the QuickBooks copy (and so its pay-now URL,
 * Invoice.InvoiceLink) only exists once the sync worker has pushed it —
 * usually a minute later. The FF link is stable from the moment of send and
 * resolves the QuickBooks pay page when the customer CLICKS it
 * (app/admin/pay.php), so the email never carries a dead or missing link.
 * Payment itself happens on QuickBooks' page and comes back to FF through
 * the Payment webhook like any other QuickBooks payment.
 *
 * Token = HMAC-SHA256(APP_SECRET, invoice id), 32 hex chars: unguessable,
 * needs no table, and can't be re-pointed at another invoice.
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

namespace FleetForge\QboPushers;

final class PayLink
{
    /** Absolute pay URL for an invoice, or '' when online payment is off. */
    public static function url(int $invoiceId): string
    {
        if ($invoiceId <= 0 || (string) settings_get('quickbooks.payments_enabled', '0') !== '1') {
            return '';
        }
        $token = self::token($invoiceId);
        if ($token === '') {
            return '';
        }
        return base_url('pay') . '?i=' . $invoiceId . '&t=' . $token;
    }

    /** Constant-time check of a token presented on the pay page. */
    public static function verify(int $invoiceId, string $token): bool
    {
        $expected = self::token($invoiceId);
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    private static function token(int $invoiceId): string
    {
        if (!defined('APP_SECRET') || APP_SECRET === '') {
            return '';
        }
        return substr(hash_hmac('sha256', 'ff-pay-invoice|' . $invoiceId, APP_SECRET), 0, 32);
    }
}
