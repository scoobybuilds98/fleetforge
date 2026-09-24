<?php
declare(strict_types=1);

namespace FleetForge\Billing;

use FleetForge\Email\EmailService;

/**
 * lib/Billing/InvoiceDelivery.php
 *
 * S-BILLING-MODULE — THE one place an invoice is emailed to its customer.
 *
 * Extracted from api/v1/invoices/bulk_send.php (S-BATCH-INVOICING /
 * S-INVOICE-PDF) so the same code serves:
 *   - bulk_send.php            draft → sent, then email (the workbench)
 *   - api/v1/billing/deliver   email an ALREADY-SENT invoice again (the
 *                              cycle's Delivery tab, and the invoice page's
 *                              "Re-send Invoice", which used to call send.php
 *                              and always 409'd because send only moves drafts)
 *
 * Sending (status change, counters, ledger, QuickBooks) is NOT here — that
 * stays in FinancialActions::sendInvoice(). This class only delivers.
 *
 * Recipient, in order: the caller's override → customers.invoice_email →
 * billing_email → email → the invoice's frozen customer_email_snapshot.
 * The PDF is generated on demand; a PDF failure sends the email without the
 * attachment (logged), never blocks it. EmailService logs every attempt in
 * email_logs (entity_type 'invoice'), which is what the Delivery tab reads.
 *
 * @session S-BILLING-MODULE
 */
final class InvoiceDelivery
{
    private function __construct() {}

    /**
     * Resolve who an invoice would be emailed to.
     *
     * @return array{to: ?string, name: string, customer_id: ?int, invoice_number: string,
     *               email_disabled: bool, delivery: string}
     */
    public static function recipient(int $invoiceId, ?string $override = null): array
    {
        $inv = \db_row(
            "SELECT i.customer_id, i.invoice_number, i.customer_email_snapshot, i.customer_name_snapshot,
                    i.company_name_snapshot,
                    c.invoice_email, c.billing_email, c.email AS c_email, c.email_disabled,
                    c.contact_name, c.company_name, c.invoice_delivery
               FROM invoices i
               LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
              WHERE i.id = ?",
            [$invoiceId]
        ) ?: [];

        $to = $override
            ?: (($inv['invoice_email'] ?? null) ?: (($inv['billing_email'] ?? null) ?: (($inv['c_email'] ?? null) ?: ($inv['customer_email_snapshot'] ?? null))));
        $name = (string) (($inv['contact_name'] ?? null) ?: ($inv['company_name'] ?? null) ?: ($inv['customer_name_snapshot'] ?? '') ?: ($inv['company_name_snapshot'] ?? ''));

        return [
            'to'             => $to ?: null,
            'name'           => $name,
            'customer_id'    => isset($inv['customer_id']) ? (int) $inv['customer_id'] : null,
            'invoice_number' => (string) ($inv['invoice_number'] ?? ''),
            'email_disabled' => (int) ($inv['email_disabled'] ?? 0) === 1,
            'delivery'       => (string) ($inv['invoice_delivery'] ?? 'email'),
        ];
    }

    /**
     * Email the invoice (invoice_ready template, optional PDF attachment).
     *
     * @return array{success: bool, to: ?string, error: ?string}
     */
    public static function email(int $invoiceId, ?string $override, bool $attachPdf, ?int $userId): array
    {
        try {
            $rcpt = self::recipient($invoiceId, $override);
            if (!$rcpt['to']) {
                return ['success' => false, 'to' => null, 'error' => 'No recipient email could be resolved for this customer.'];
            }

            $variables = array_merge(
                $rcpt['customer_id'] ? EmailService::resolveCustomerVariables($rcpt['customer_id']) : [],
                EmailService::resolveEntityVariables('invoice', $invoiceId)
            );

            // EmailService::send() expects attachments PRE-RESOLVED to
            // {path, name, type, source_type, source_id} — the {invoice_id}
            // shorthand is only expanded by api/v1/email/send.php.
            $attachments = [];
            if ($attachPdf) {
                try {
                    $pdf = InvoicePdfGenerator::generate($invoiceId);
                    $attachments[] = [
                        'path'        => $pdf['pdf_path'],
                        'name'        => $rcpt['invoice_number'] . '.pdf',
                        'type'        => 'application/pdf',
                        'source_type' => 'invoice_pdf',
                        'source_id'   => $invoiceId,
                    ];
                } catch (\Throwable $e) {
                    error_log("[InvoiceDelivery] Invoice #{$invoiceId} PDF generation failed (sending without attachment): " . $e->getMessage());
                }
            }

            $res = EmailService::sendFromTemplate(
                'invoice_ready',
                $rcpt['to'],
                $rcpt['name'],
                $variables,
                [
                    'customer_id' => $rcpt['customer_id'],
                    'entity_type' => 'invoice',
                    'entity_id'   => $invoiceId,
                    'sent_by'     => $userId,
                    'attachments' => $attachments,
                ]
            );
            return $res['success']
                ? ['success' => true, 'to' => $rcpt['to'], 'error' => null]
                : ['success' => false, 'to' => $rcpt['to'], 'error' => $res['error'] ?? 'Email could not be sent.'];
        } catch (\Throwable $e) {
            error_log("[InvoiceDelivery] Invoice #{$invoiceId} email failed: " . $e->getMessage());
            return ['success' => false, 'to' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Latest email attempt per invoice, from email_logs.
     *
     * @param int[] $invoiceIds
     * @return array<int, array{status: string, to_email: string, at: ?string, error: ?string, attempts: int, sent_count: int}>
     */
    public static function lastEmails(array $invoiceIds): array
    {
        if (!$invoiceIds) return [];
        $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
        $out = [];
        foreach (\db_select(
            "SELECT el.entity_id, el.status, el.to_email, COALESCE(el.sent_at, el.created_at) AS at, el.error_message
               FROM email_logs el
              WHERE el.entity_type = 'invoice' AND el.entity_id IN ({$ph})
              ORDER BY el.id ASC",
            $invoiceIds
        ) as $r) {
            $id = (int) $r['entity_id'];
            $prev = $out[$id] ?? ['attempts' => 0, 'sent_count' => 0];
            $out[$id] = [
                'status'     => $r['status'],
                'to_email'   => $r['to_email'],
                'at'         => $r['at'],
                'error'      => $r['error_message'],
                'attempts'   => $prev['attempts'] + 1,
                'sent_count' => $prev['sent_count'] + ($r['status'] === 'sent' ? 1 : 0),
            ];
        }
        return $out;
    }
}
