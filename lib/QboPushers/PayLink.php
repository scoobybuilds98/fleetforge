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
 * S-QBO-INVOICE-PAYNOW: the link now also comes as a "Pay now" BUTTON that
 * FleetForge adds by itself — to every email about a payable invoice
 * (EmailService::withPayNow), the due-soon / overdue reminders, dunning
 * letters and the invoice PDF (with a QR code) — instead of relying on
 * someone adding {pay_online_link} to a template. Shown only while
 * QuickBooks Payments is on (Master Controls) and the invoice can take a
 * payment; the pay page re-checks everything at click time.
 *
 * @session S-QBO-GOLIVE-AUDIT, S-QBO-INVOICE-PAYNOW
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

    /** Statuses an invoice can be paid in (the pay page enforces the same). */
    public const PAYABLE_STATUSES = ['sent', 'partially_paid', 'overdue'];

    /** True when the invoice row can take an online payment right now. */
    public static function isPayable(array $invoice): bool
    {
        return in_array((string) ($invoice['status'] ?? ''), self::PAYABLE_STATUSES, true)
            && empty($invoice['deleted_at'])
            && bccomp((string) ($invoice['balance_due'] ?? '0'), '0', 2) > 0;
    }

    /**
     * Pay link for an invoice that can be paid now, else ''.
     *
     * @param array<string,mixed>|null $invoice the row, when the caller has it
     */
    public static function payableUrl(int $invoiceId, ?array $invoice = null): string
    {
        $url = self::url($invoiceId);
        if ($url === '') {
            return '';
        }
        $invoice ??= db_row("SELECT id, status, balance_due, deleted_at FROM invoices WHERE id = ?", [$invoiceId]);
        return $invoice && self::isPayable($invoice) ? $url : '';
    }

    /**
     * Email-safe "Pay now" block (table layout + inline styles, so Outlook
     * and Gmail draw it as a button), or '' when the invoice can't be paid
     * online. Carries data-ff-pay-now so it is never added twice.
     */
    public static function buttonHtml(int $invoiceId): string
    {
        $inv = db_row(
            "SELECT id, invoice_number, status, balance_due, currency, due_date, deleted_at FROM invoices WHERE id = ?",
            [$invoiceId]
        );
        $url = $inv ? self::payableUrl($invoiceId, $inv) : '';
        if ($url === '') {
            return '';
        }
        $h      = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $color  = self::brandColor();
        $font   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $amount = format_currency($inv['balance_due']) . ' ' . (string) $inv['currency'];
        $due    = !empty($inv['due_date']) ? ' · due ' . format_date($inv['due_date']) : '';

        return '<table role="presentation" data-ff-pay-now="1" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%;margin:22px 0 8px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;border-collapse:separate;">'
            . '<tr><td style="padding:18px 20px;font-family:' . $font . ';">'
            . '<div style="font-size:13px;color:#6b7280;margin:0 0 4px;">Invoice ' . $h((string) $inv['invoice_number']) . $h($due) . '</div>'
            . '<div style="font-size:22px;font-weight:700;color:#111827;margin:0 0 14px;">' . $h($amount) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="center" bgcolor="' . $h($color) . '" style="border-radius:7px;background:' . $h($color) . ';">'
            . '<a href="' . $h($url) . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;padding:13px 30px;font-family:' . $font . ';font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:7px;">'
            . 'Pay now</a></td></tr></table>'
            . '<div style="font-size:12px;line-height:1.5;color:#6b7280;margin:12px 0 0;">Secure online payment through QuickBooks — card or bank transfer. Your payment is recorded on your account automatically.</div>'
            . '<div style="font-size:11px;line-height:1.5;color:#9ca3af;margin:6px 0 0;word-break:break-all;">Button not working? Open this link: '
            . '<a href="' . $h($url) . '" style="color:#6b7280;">' . $h($url) . '</a></div>'
            . '</td></tr></table>';
    }

    /** The same offer as one plain-text line (text/plain part), or ''. */
    public static function buttonText(int $invoiceId): string
    {
        $inv = db_row("SELECT id, invoice_number, status, balance_due, currency, deleted_at FROM invoices WHERE id = ?", [$invoiceId]);
        $url = $inv ? self::payableUrl($invoiceId, $inv) : '';
        if ($url === '') {
            return '';
        }
        return 'Pay invoice ' . $inv['invoice_number'] . ' online (' . format_currency($inv['balance_due']) . ' ' . $inv['currency'] . ' due): ' . $url;
    }

    /**
     * Button colour: the brand colour from Settings → Design when it is a
     * plain hex value, else the email shell's orange. Emails can't read CSS
     * variables, so this is the one place a hex is chosen.
     */
    private static function brandColor(): string
    {
        $c = trim((string) settings_get('brand.primary_color', ''));
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#F97316';
    }

    private static function token(int $invoiceId): string
    {
        if (!defined('APP_SECRET') || APP_SECRET === '') {
            return '';
        }
        return substr(hash_hmac('sha256', 'ff-pay-invoice|' . $invoiceId, APP_SECRET), 0, 32);
    }
}
