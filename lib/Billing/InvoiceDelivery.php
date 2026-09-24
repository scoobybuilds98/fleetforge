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
     * ONE email carrying several invoices of the same customer (the month's
     * invoices) — template 'invoice_bundle', one PDF per invoice, a summary
     * table with each invoice's Pay now link when QuickBooks Payments is on.
     *
     * Delivery tracking stays per invoice: the email is logged once
     * (email_logs, entity = the first invoice) and every other invoice in the
     * bundle gets a short 'sent' row pointing at that log, so the cycle's
     * Delivery tab and each customer's Emails tab show every invoice as emailed.
     *
     * Drafts are refused — send them first (the caller does, via
     * FinancialActions::sendInvoice).
     *
     * @param int[] $invoiceIds
     * @return array{success: bool, to: ?string, error: ?string, log_id: ?int, count: int}
     */
    public static function emailBundle(array $invoiceIds, ?string $override, bool $attachPdf, ?int $userId, string $periodLabel): array
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if (!$invoiceIds) {
            return ['success' => false, 'to' => null, 'error' => 'No invoices to send.', 'log_id' => null, 'count' => 0];
        }
        try {
            $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
            $invs = \db_select(
                "SELECT i.id, i.invoice_number, i.status, i.customer_id, i.currency, i.total_amount, i.balance_due,
                        i.due_date, i.billing_period_start, i.billing_period_end,
                        COALESCE(i.unit_number_invoice_snapshot, '') AS unit_number,
                        COALESCE(i.contract_number_snapshot, '') AS contract_number
                   FROM invoices i
                  WHERE i.id IN ({$ph}) AND i.deleted_at IS NULL
                  ORDER BY i.invoice_number",
                $invoiceIds
            );
            if (!$invs) {
                return ['success' => false, 'to' => null, 'error' => 'Invoices not found.', 'log_id' => null, 'count' => 0];
            }
            $customers = array_unique(array_map(static fn($r) => (int) $r['customer_id'], $invs));
            if (count($customers) !== 1) {
                return ['success' => false, 'to' => null, 'error' => 'A combined email can only carry one customer\'s invoices.', 'log_id' => null, 'count' => 0];
            }
            foreach ($invs as $r) {
                if (in_array($r['status'], ['draft', 'void'], true)) {
                    return ['success' => false, 'to' => null, 'error' => "{$r['invoice_number']} is {$r['status']} — only sent invoices can be emailed.", 'log_id' => null, 'count' => 0];
                }
            }

            $first = (int) $invs[0]['id'];
            $rcpt  = self::recipient($first, $override);
            if (!$rcpt['to']) {
                return ['success' => false, 'to' => null, 'error' => 'No recipient email could be resolved for this customer.', 'log_id' => null, 'count' => 0];
            }

            $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $money = static fn(string $amt, string $cur) => $cur . ' ' . number_format((float) $amt, 2);
            $rowsHtml = '';
            $rowsText = [];
            $due = [];
            foreach ($invs as $r) {
                $pay = \FleetForge\QboPushers\PayLink::payableUrl((int) $r['id']);
                $due[$r['currency']] = bcadd($due[$r['currency']] ?? '0.00', (string) $r['balance_due'], 2);
                $rowsHtml .= '<tr>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;">' . $h($r['invoice_number']) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">' . $h(trim($r['unit_number'] . ' ' . $r['contract_number'])) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">' . $h(date('M j', strtotime((string) $r['billing_period_start'])) . ' – ' . date('M j, Y', strtotime((string) $r['billing_period_end']))) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">' . $h(date('M j, Y', strtotime((string) $r['due_date']))) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#111827;text-align:right;white-space:nowrap;">' . $h($money((string) $r['balance_due'], (string) $r['currency'])) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;text-align:right;">' . ($pay !== '' ? '<a href="' . $h($pay) . '" style="color:#2563eb;">Pay now</a>' : '') . '</td>'
                    . '</tr>';
                $rowsText[] = sprintf('%s  %s  due %s  %s%s', $r['invoice_number'], trim($r['unit_number'] . ' ' . $r['contract_number']),
                    date('M j, Y', strtotime((string) $r['due_date'])), $money((string) $r['balance_due'], (string) $r['currency']),
                    $pay !== '' ? '  Pay: ' . $pay : '');
            }
            $th = 'style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#6b7280;text-align:left;"';
            $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 8px;">'
                . '<tr><th ' . $th . '>Invoice</th><th ' . $th . '>Unit / lease</th><th ' . $th . '>Period</th><th ' . $th . '>Due</th>'
                . '<th ' . $th . ' style="text-align:right;">Amount due</th><th ' . $th . '></th></tr>'
                . $rowsHtml . '</table>';
            $totalDue = implode(' + ', array_map(static fn($cur, $amt) => $cur . ' ' . number_format((float) $amt, 2), array_keys($due), $due));

            $variables = array_merge(
                $rcpt['customer_id'] ? EmailService::resolveCustomerVariables($rcpt['customer_id']) : [],
                [
                    'period_label'      => $h($periodLabel),
                    'invoice_count'     => (string) count($invs),
                    'invoice_table'     => $table,
                    'invoice_list_text' => implode("\n", $rowsText),
                    'total_due'         => $h($totalDue),
                ]
            );

            $attachments = [];
            if ($attachPdf) {
                foreach ($invs as $r) {
                    try {
                        $pdf = InvoicePdfGenerator::generate((int) $r['id']);
                        $attachments[] = [
                            'path'        => $pdf['pdf_path'],
                            'name'        => $r['invoice_number'] . '.pdf',
                            'type'        => 'application/pdf',
                            'source_type' => 'invoice_pdf',
                            'source_id'   => (int) $r['id'],
                        ];
                    } catch (\Throwable $e) {
                        error_log("[InvoiceDelivery] bundle PDF for #{$r['id']} failed (sending without it): " . $e->getMessage());
                    }
                }
            }

            $res = EmailService::sendFromTemplate('invoice_bundle', $rcpt['to'], $rcpt['name'], $variables, [
                'customer_id' => $rcpt['customer_id'],
                'entity_type' => 'invoice',
                'entity_id'   => $first,
                'sent_by'     => $userId,
                'attachments' => $attachments,
            ]);
            if (!$res['success']) {
                return ['success' => false, 'to' => $rcpt['to'], 'error' => $res['error'] ?? 'Email could not be sent.', 'log_id' => $res['log_id'] ?? null, 'count' => 0];
            }

            // Per-invoice delivery rows for the rest of the bundle.
            $main = $res['log_id'] ? \db_row("SELECT from_email, from_name, subject, sent_at FROM email_logs WHERE id = ?", [$res['log_id']]) : null;
            foreach ($invs as $r) {
                if ((int) $r['id'] === $first || !$main) continue;
                \db_insert('email_logs', [
                    'to_email'    => $rcpt['to'],
                    'to_name'     => $rcpt['name'] ?: null,
                    'from_email'  => $main['from_email'],
                    'from_name'   => $main['from_name'],
                    'subject'     => $main['subject'],
                    'body_html'   => '<p>Sent in one email together with the other ' . $h($periodLabel) . ' invoices (email log #' . (int) $res['log_id'] . ').</p>',
                    'body_text'   => 'Sent in one email with the other ' . $periodLabel . ' invoices (email log #' . (int) $res['log_id'] . ').',
                    'status'      => 'sent',
                    'sent_at'     => $main['sent_at'] ?? \ff_now_utc(),
                    'customer_id' => $rcpt['customer_id'],
                    'entity_type' => 'invoice',
                    'entity_id'   => (int) $r['id'],
                    'sent_by'     => $userId,
                ]);
            }
            return ['success' => true, 'to' => $rcpt['to'], 'error' => null, 'log_id' => $res['log_id'] ?? null, 'count' => count($invs)];
        } catch (\Throwable $e) {
            error_log('[InvoiceDelivery] bundle failed: ' . $e->getMessage());
            return ['success' => false, 'to' => null, 'error' => $e->getMessage(), 'log_id' => null, 'count' => 0];
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

    /**
     * Record how SENT invoices reached a customer who is not billed by email:
     * 'manual' (printed and mailed / handed over) or 'portal' (left on the
     * customer portal). Sets invoices.delivery_method — the column the send
     * path writes 'email' to — and audits each change. Drafts and voids are
     * refused per invoice. (S-BILLING-MODULE-2)
     *
     * @param int[] $invoiceIds
     * @return array{updated:int, errors: array<int, array{id:int, reason:string}>}
     * @throws \InvalidArgumentException on an unknown method
     */
    public static function markDelivered(array $invoiceIds, string $method, ?string $note, ?int $userId, string $userName): array
    {
        if (!in_array($method, ['manual', 'portal'], true)) {
            throw new \InvalidArgumentException('Unknown delivery method.');
        }
        $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
        if (!$ids) return ['updated' => 0, 'errors' => []];

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = [];
        foreach (\db_select("SELECT id, invoice_number, status, delivery_method FROM invoices WHERE id IN ({$ph}) AND deleted_at IS NULL", $ids) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $label   = $method === 'manual' ? 'printed and mailed / handed over' : 'left on the customer portal';
        $updated = 0;
        $errors  = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            if (!$r) { $errors[] = ['id' => $id, 'reason' => 'Invoice not found.']; continue; }
            if (in_array($r['status'], ['draft', 'void'], true)) {
                $errors[] = ['id' => $id, 'reason' => "{$r['invoice_number']} is {$r['status']} — send it first."];
                continue;
            }
            \db_execute("UPDATE invoices SET delivery_method = ?, updated_by = ? WHERE id = ?", [$method, $userId, $id]);
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'update',
                'module'       => 'billing',
                'entity_type'  => 'invoice',
                'entity_id'    => $id,
                'entity_label' => $r['invoice_number'],
                'old_values'   => json_encode(['delivery_method' => $r['delivery_method']]),
                'new_values'   => json_encode(['delivery_method' => $method]),
                'notes'        => "{$r['invoice_number']} {$label}" . ($note ? ": {$note}" : '.'),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            $updated++;
        }
        return ['updated' => $updated, 'errors' => $errors];
    }
}
