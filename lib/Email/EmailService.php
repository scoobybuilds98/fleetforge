<?php
declare(strict_types=1);

namespace FleetForge\Email;

use FleetForge\Notifications\Mailer;
use FleetForge\Storage\StorageClient;
use Throwable;

/**
 * lib/Email/EmailService.php
 *
 * Customer-facing email orchestration service.
 *
 * Built in session EMAIL-1.
 *
 * Responsibilities (everything ABOVE the SMTP transport):
 *   1. Compile templates with {variable} substitution.
 *   2. Wrap raw HTML body in a professional email shell that
 *      injects the company logo, name, and footer block from
 *      the settings table — matches FLEETFORGE_DESIGN_DETAILS §8.
 *   3. Persist every send attempt (success OR failure) to
 *      email_logs so the customer email history tab can show
 *      a complete audit trail.
 *   4. Persist attachment metadata to email_attachments
 *      so the history view can list what was attached.
 *   5. Resolve "available attachments" for a given customer/entity
 *      so the Compose modal can render its picker UI without
 *      duplicating the SQL across multiple endpoints.
 *
 * Transport delegation:
 *   The actual SMTP / SES delivery is delegated to
 *   FleetForge\Notifications\Mailer::send(). EmailService never
 *   talks to SES or SMTP directly — that keeps a single transport
 *   choke-point so dev-mode (logs/mail.log) and prod-mode
 *   (real SES) work the same way.
 *
 * @session  EMAIL-1
 * @depends  includes/db.php (db_select, db_insert, db_update, db_row, db_transaction)
 *           includes/functions.php (settings_get, format_currency, format_date, e())
 *           lib/Notifications/Mailer.php (Mailer::send)
 * @schema   email_templates, email_logs, email_attachments
 */
class EmailService
{
    /**
     * send() — primary entry point for sending a single email.
     *
     * Behaviour:
     *   1. Logs row to email_logs with status='pending' BEFORE
     *      attempting send (so we always have an audit trail
     *      even if the SMTP call segfaults).
     *   2. Wraps body_html in the company shell.
     *   3. Calls Mailer::send() — which itself falls back to
     *      logs/mail.log when AWS keys are blank.
     *   4. Updates the email_logs row with sent/failed status.
     *   5. Persists each attachment row.
     *
     * Failures are returned in the result array — no exception is
     * thrown so the caller (an API endpoint) can build a clean
     * 500 response or surface the failure to the user.
     *
     * @param array $params  See keys below
     * @return array{success:bool, log_id:?int, error:?string}
     *
     * Required params:
     *   to_email   string  RFC-valid email address
     *   subject    string
     *   body_html  string
     *   sent_by    int     User ID
     *
     * Optional:
     *   to_name        string
     *   body_text      string  (auto strip-tags from html if missing)
     *   reply_to       string
     *   customer_id    int
     *   entity_type    string  (e.g. 'invoice', 'lease')
     *   entity_id      int
     *   template_id    int     (FK to email_templates)
     *   attachments    array   [{path, name, type, source_type, source_id}, ...]
     */
    public static function send(array $params): array
    {
        // ── Validate required params ─────────────────────────────
        $toEmail   = (string)($params['to_email']   ?? '');
        $subject   = (string)($params['subject']    ?? '');
        $bodyHtml  = (string)($params['body_html']  ?? '');
        $sentBy    = (int)   ($params['sent_by']    ?? 0);

        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'log_id' => null, 'error' => 'Invalid recipient email address.'];
        }
        if (trim($subject) === '') {
            return ['success' => false, 'log_id' => null, 'error' => 'Subject is required.'];
        }
        if (trim($bodyHtml) === '') {
            return ['success' => false, 'log_id' => null, 'error' => 'Email body is required.'];
        }
        if ($sentBy <= 0) {
            return ['success' => false, 'log_id' => null, 'error' => 'sent_by user ID is required.'];
        }

        // ── Optional params ──────────────────────────────────────
        $toName      = (string)($params['to_name']     ?? '');
        $bodyText    = (string)($params['body_text']   ?? '');
        $replyTo     = (string)($params['reply_to']    ?? '');
        $customerId  = (int)   ($params['customer_id'] ?? 0) ?: null;
        $entityType  = (string)($params['entity_type'] ?? '') ?: null;
        $entityId    = (int)   ($params['entity_id']   ?? 0) ?: null;
        $templateId  = (int)   ($params['template_id'] ?? 0) ?: null;
        $attachments = is_array($params['attachments'] ?? null) ? $params['attachments'] : [];

        // Resolve from-address from settings (INT-1: settings first)
        $fromEmail = (string) (settings_get('email.from_email') ?: env('SMTP_FROM_EMAIL', 'noreply@fleetforge.test'));
        $fromName  = (string) (settings_get('email.from_name')  ?: settings_get('company.name', 'FleetForge'));

        // Wrap raw body in the company shell (logo + footer)
        // WHY: separating the shell from the template body lets the
        // user-edited message stay focused on its own content while
        // still inheriting the global brand chrome.
        $wrappedHtml = self::renderEmailHtml($bodyHtml);

        // Auto-generate plain-text body if not provided
        if (trim($bodyText) === '') {
            $bodyText = self::stripHtml($bodyHtml);
        }

        // ── Persist log row BEFORE sending (audit-first strategy) ─
        // WHY: if the SMTP layer crashes mid-send we still have a
        // record of the attempt rather than silently losing it.
        try {
            $logId = db_insert('email_logs', [
                'to_email'      => $toEmail,
                'to_name'       => $toName ?: null,
                'from_email'    => $fromEmail,
                'from_name'     => $fromName ?: null,
                'reply_to'      => $replyTo ?: null,
                'subject'       => $subject,
                'body_html'     => $wrappedHtml,
                'body_text'     => $bodyText,
                'status'        => 'pending',
                'customer_id'   => $customerId,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'template_id'   => $templateId,
                'attachments'   => $attachments ? json_encode($attachments) : null,
                'sent_by'       => $sentBy,
            ]);
        } catch (Throwable $e) {
            error_log('[EmailService] failed to persist log row: ' . $e->getMessage());
            return ['success' => false, 'log_id' => null, 'error' => 'Could not log email send.'];
        }

        // Persist attachment metadata for the history view
        foreach ($attachments as $att) {
            $attPath = (string)($att['path'] ?? '');
            $attName = (string)($att['name'] ?? '');
            if ($attPath === '' || $attName === '') continue;

            try {
                db_insert('email_attachments', [
                    'email_log_id' => $logId,
                    'file_name'    => $attName,
                    'file_path'    => $attPath,
                    'file_size'    => isset($att['size']) ? (int)$att['size'] : null,
                    'mime_type'    => (string)($att['type'] ?? '') ?: null,
                    'source_type'  => (string)($att['source_type'] ?? 'document'),
                    'source_id'    => isset($att['source_id']) ? (int)$att['source_id'] : null,
                ]);
            } catch (Throwable $e) {
                error_log('[EmailService] attachment persist failed: ' . $e->getMessage());
                // Non-fatal — continue and try the actual send
            }
        }

        // ── Resolve attachment bytes for actual delivery (I14) ───
        // The metadata loop above records what was attached; here we
        // read the real file contents from storage so Mailer can build
        // a multipart/mixed MIME message via SES sendRawEmail.
        // WHY fail-closed: if a requested attachment cannot be read, we
        // must NOT deliver an email that the history tab claims carried
        // a PDF — that mismatch is the exact billing/audit lie I14 fixes.
        $mailAttachments = [];
        foreach ($attachments as $att) {
            $attPath = (string)($att['path'] ?? '');
            $attName = (string)($att['name'] ?? '');
            if ($attPath === '' || $attName === '') continue;

            try {
                $bytes = StorageClient::read($attPath);
            } catch (Throwable $e) {
                error_log('[EmailService] attachment read failed for ' . $attPath . ': ' . $e->getMessage());
                $bytes = null;
            }

            if ($bytes === null) {
                $msg = 'Attachment "' . $attName . '" could not be read from storage; email not sent.';
                error_log('[EmailService] ' . $msg . ' (key=' . $attPath . ')');
                db_execute(
                    "UPDATE email_logs SET status = 'failed', error_message = ? WHERE id = ?",
                    [$msg, $logId]
                );
                return ['success' => false, 'log_id' => $logId, 'error' => $msg];
            }

            $mailAttachments[] = [
                'content' => $bytes,
                'name'    => $attName,
                'type'    => (string)($att['type'] ?? '') ?: 'application/octet-stream',
            ];
        }

        // ── Attempt SMTP delivery via Mailer ─────────────────────
        $replyToArr = $replyTo
            ? [['email' => $replyTo, 'name' => '']]
            : [];

        $sent = false;
        $errorMsg = null;
        try {
            $sent = Mailer::send(
                toEmail:     $toEmail,
                toName:      $toName,
                subject:     $subject,
                htmlBody:    $wrappedHtml,
                textBody:    $bodyText,
                replyTo:     $replyToArr,
                attachments: $mailAttachments,
            );
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            error_log('[EmailService] Mailer threw: ' . $errorMsg);
        }

        // ── Update log row with final status ─────────────────────
        if ($sent) {
            db_execute(
                "UPDATE email_logs SET status = 'sent', sent_at = NOW() WHERE id = ?",
                [$logId]
            );
            return ['success' => true, 'log_id' => $logId, 'error' => null];
        }

        db_execute(
            "UPDATE email_logs SET status = 'failed', error_message = ? WHERE id = ?",
            [$errorMsg ?: 'SMTP send failed (see logs/mail.log or error log).', $logId]
        );

        return [
            'success' => false,
            'log_id'  => $logId,
            'error'   => $errorMsg ?: 'Could not deliver email. Check SMTP settings or error log.',
        ];
    }

    // =========================================================
    // sendFromTemplate() — convenience wrapper
    //
    // Loads a template by slug, hydrates the variables, and
    // dispatches via send().
    // =========================================================
    public static function sendFromTemplate(
        string $templateSlug,
        string $toEmail,
        string $toName,
        array  $variables,
        array  $extras = []
    ): array {
        $template = db_row(
            "SELECT * FROM email_templates WHERE slug = ? AND is_active = 1 AND deleted_at IS NULL",
            [$templateSlug]
        );

        if (!$template) {
            return ['success' => false, 'log_id' => null, 'error' => "Template '{$templateSlug}' not found or inactive."];
        }

        $compiled = self::compileTemplate((int)$template['id'], $variables);

        $params = array_merge([
            'to_email'    => $toEmail,
            'to_name'     => $toName,
            'subject'     => $compiled['subject'],
            'body_html'   => $compiled['body_html'],
            'body_text'   => $compiled['body_text'],
            'template_id' => (int)$template['id'],
        ], $extras);

        return self::send($params);
    }

    // =========================================================
    // compileTemplate() — variable substitution
    //
    // Replaces {placeholder} tokens in subject + body with the
    // values supplied in $variables. Missing variables remain
    // as the literal placeholder so the user can spot them in
    // the preview before sending.
    // =========================================================
    public static function compileTemplate(int $templateId, array $variables): array
    {
        $template = db_row(
            "SELECT * FROM email_templates WHERE id = ? AND deleted_at IS NULL",
            [$templateId]
        );

        if (!$template) {
            return [
                'subject'   => '',
                'body_html' => '',
                'body_text' => '',
                'variables' => [],
            ];
        }

        // Always merge in company-wide variables so every template
        // can rely on {company_name} / {sender_name} / etc. being
        // present without the caller having to know about them.
        $defaults = self::companyVariables();
        $vars = array_merge($defaults, $variables);

        $subject  = self::substitute((string)$template['subject'],  $vars);
        $bodyHtml = self::substitute((string)$template['body_html'], $vars);
        $bodyText = self::substitute((string)$template['body_text'], $vars);

        // Decode the variables JSON list once for the UI chips
        $varsList = [];
        if (!empty($template['variables'])) {
            $decoded = json_decode((string)$template['variables'], true);
            if (is_array($decoded)) $varsList = $decoded;
        }
        // Always expose the company-wide vars too so the UI chips
        // panel shows everything that can be inserted.
        foreach (array_keys($defaults) as $k) {
            if (!in_array($k, $varsList, true)) $varsList[] = $k;
        }

        return [
            'subject'   => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'variables' => $varsList,
        ];
    }

    // =========================================================
    // companyVariables() — vars available to every template
    //
    // Pulls the brand fields from the settings table so emails
    // automatically pick up signature changes without code edits.
    // =========================================================
    public static function companyVariables(?int $userId = null): array
    {
        $vars = [
            'company_name'    => (string) settings_get('company.name', 'FleetForge'),
            'company_phone'   => (string) settings_get('company.phone', ''),
            'company_email'   => (string) settings_get('company.email', ''),
            'company_address' => (string) settings_get('company.address', ''),
            'sender_name'     => '',
            'month'           => date('F'),
            'year'            => date('Y'),
        ];

        // Resolve sender name from current_user_id() if available
        if ($userId === null && function_exists('current_user_id')) {
            $userId = current_user_id();
        }
        if ($userId) {
            $u = db_row("SELECT name FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
            if ($u) $vars['sender_name'] = (string)$u['name'];
        }
        if ($vars['sender_name'] === '') {
            $vars['sender_name'] = $vars['company_name'];
        }

        return $vars;
    }

    // =========================================================
    // substitute() — replace {placeholder} tokens in a string
    //
    // Intentionally leaves unknown placeholders as-is rather
    // than emptying them — that way the preview shows what was
    // missing instead of producing a confusing blank.
    // =========================================================
    public static function substitute(string $template, array $vars): string
    {
        if ($template === '' || empty($vars)) return $template;

        $find    = [];
        $replace = [];
        foreach ($vars as $k => $v) {
            $find[]    = '{' . $k . '}';
            $replace[] = (string)$v;
        }
        return str_replace($find, $replace, $template);
    }

    // =========================================================
    // resolveCustomerVariables() — pull display vars for a customer
    //
    // Used by the API preview endpoint to render a realistic
    // preview before the user sends. Returns an array suitable
    // to pass into compileTemplate().
    // =========================================================
    public static function resolveCustomerVariables(int $customerId): array
    {
        $c = db_row(
            "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$customerId]
        );
        if (!$c) return [];

        return [
            'customer_name' => (string)($c['contact_name'] ?: $c['company_name']),
            'company_name'  => (string)($c['company_name'] ?? ''), // company NAME stays the customer name in compose context
            'contact_name'  => (string)($c['contact_name'] ?? ''),
        ];
    }

    // =========================================================
    // resolveEntityVariables() — pull display vars for an entity
    //
    // entityType ∈ {invoice, lease, payment, equipment_unit}
    // Returns an array of {key=>value} ready for substitution.
    // =========================================================
    public static function resolveEntityVariables(string $entityType, int $entityId): array
    {
        $vars = [];
        if ($entityType === '' || $entityId <= 0) return $vars;

        switch ($entityType) {
            case 'invoice':
                $row = db_row(
                    "SELECT i.*, c.company_name, c.contact_name
                     FROM invoices i
                     LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
                     WHERE i.id = ? AND i.deleted_at IS NULL",
                    [$entityId]
                );
                if (!$row) break;
                $vars['invoice_number'] = (string)$row['invoice_number'];
                $vars['invoice_date']   = $row['invoice_date'] ? format_date($row['invoice_date']) : '';
                $vars['due_date']       = $row['due_date'] ? format_date($row['due_date']) : '';
                $vars['amount']         = format_currency($row['total_amount']);
                $vars['amount_due']     = format_currency($row['balance_due']);
                $today = new \DateTime('today');
                if ($row['due_date']) {
                    $due = new \DateTime($row['due_date']);
                    $diff = (int)$today->diff($due)->format('%r%a');
                    $vars['days_overdue'] = $diff < 0 ? (string)abs($diff) : '0';
                } else {
                    $vars['days_overdue'] = '0';
                }
                if ($row['company_name']) {
                    $vars['customer_name'] = (string)($row['contact_name'] ?: $row['company_name']);
                }
                break;

            case 'lease':
                $row = db_row(
                    "SELECT l.*, eu.unit_number, c.company_name, c.contact_name
                     FROM leases l
                     LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
                     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
                     WHERE l.id = ? AND l.deleted_at IS NULL",
                    [$entityId]
                );
                if (!$row) break;
                $vars['contract_number'] = (string)$row['contract_number'];
                $vars['unit_number']     = (string)($row['unit_number'] ?? $row['unit_number_snapshot'] ?? '');
                $vars['lease_start']     = $row['start_date'] ? format_date($row['start_date']) : '';
                $vars['lease_end']       = $row['end_date'] ? format_date($row['end_date']) : '';
                if ($row['company_name']) {
                    $vars['customer_name'] = (string)($row['contact_name'] ?: $row['company_name']);
                }
                break;

            case 'payment':
                $row = db_row(
                    "SELECT p.*, c.company_name, c.contact_name
                     FROM payments p
                     LEFT JOIN customers c ON c.id = p.customer_id AND c.deleted_at IS NULL
                     WHERE p.id = ? AND p.deleted_at IS NULL",
                    [$entityId]
                );
                if (!$row) break;
                $vars['payment_amount'] = format_currency($row['amount']);
                $vars['payment_date']   = $row['payment_date'] ? format_date($row['payment_date']) : '';
                if ($row['company_name']) {
                    $vars['customer_name'] = (string)($row['contact_name'] ?: $row['company_name']);
                }
                // Try to attach the most recent invoice this payment was allocated to
                $inv = db_row(
                    "SELECT i.invoice_number, i.due_date
                     FROM payment_allocations a
                     JOIN invoices i ON i.id = a.invoice_id AND i.deleted_at IS NULL
                     WHERE a.payment_id = ?
                     ORDER BY a.id DESC LIMIT 1",
                    [$entityId]
                );
                if ($inv) {
                    $vars['invoice_number'] = (string)$inv['invoice_number'];
                    $vars['due_date']       = $inv['due_date'] ? format_date($inv['due_date']) : '';
                }
                break;

            case 'equipment_unit':
                $row = db_row(
                    "SELECT * FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
                    [$entityId]
                );
                if (!$row) break;
                $vars['unit_number'] = (string)$row['unit_number'];
                break;
        }

        return $vars;
    }

    // =========================================================
    // getAvailableAttachments() — for the picker UI
    //
    // Returns the documents the user can attach to an outgoing
    // email for a given customer (and optionally a specific
    // entity such as an invoice). Used by both the compose
    // modal and the API endpoint that backs it.
    //
    // Returned shape (per item):
    //   id, name, source_type, source_id, size, type
    // =========================================================
    public static function getAvailableAttachments(
        int $customerId,
        ?string $entityType = null,
        ?int $entityId = null
    ): array {
        $items = [];

        // ── Customer documents ───────────────────────────────────
        if ($customerId > 0) {
            $rows = db_select(
                "SELECT id, title, file_name, file_path, file_size_kb, mime_type, document_type
                 FROM documents
                 WHERE entity_type = 'customer'
                   AND entity_id = ?
                   AND deleted_at IS NULL
                   AND is_current = 1
                 ORDER BY uploaded_at DESC
                 LIMIT 50",
                [$customerId]
            );
            foreach ($rows as $r) {
                $items[] = [
                    'id'          => (int)$r['id'],
                    'name'        => (string)($r['title'] ?: $r['file_name']),
                    'source_type' => 'document',
                    'source_id'   => (int)$r['id'],
                    'size'        => $r['file_size_kb'] ? (int)$r['file_size_kb'] * 1024 : null,
                    'type'        => (string)($r['mime_type'] ?? ''),
                    'entity_label'=> 'Customer document — ' . (string)($r['document_type'] ?? 'misc'),
                ];
            }
        }

        // ── Entity-specific documents (lease, equipment_unit etc.) ─
        if ($entityType && $entityId && in_array($entityType, ['lease','equipment_unit','damage_claim','inspection','contract'], true)) {
            $rows = db_select(
                "SELECT id, title, file_name, file_path, file_size_kb, mime_type, document_type
                 FROM documents
                 WHERE entity_type = ?
                   AND entity_id = ?
                   AND deleted_at IS NULL
                   AND is_current = 1
                 ORDER BY uploaded_at DESC
                 LIMIT 50",
                [$entityType, $entityId]
            );
            foreach ($rows as $r) {
                $items[] = [
                    'id'          => (int)$r['id'],
                    'name'        => (string)($r['title'] ?: $r['file_name']),
                    'source_type' => 'document',
                    'source_id'   => (int)$r['id'],
                    'size'        => $r['file_size_kb'] ? (int)$r['file_size_kb'] * 1024 : null,
                    'type'        => (string)($r['mime_type'] ?? ''),
                    'entity_label'=> ucfirst(str_replace('_',' ',$entityType)) . ' document',
                ];
            }
        }

        return $items;
    }

    // =========================================================
    // getCustomerInvoices() — for the invoice PDF picker
    //
    // Lists invoices belonging to a customer that have a PDF
    // already generated. Returns up to 50 most recent.
    // =========================================================
    public static function getCustomerInvoices(int $customerId): array
    {
        if ($customerId <= 0) return [];

        $rows = db_select(
            "SELECT id, invoice_number, total_amount, status, invoice_date, pdf_path
             FROM invoices
             WHERE customer_id = ?
               AND deleted_at IS NULL
             ORDER BY invoice_date DESC, id DESC
             LIMIT 50",
            [$customerId]
        );

        $out = [];
        foreach ($rows as $r) {
            $statusClass = match((string)$r['status']) {
                'paid'           => 'success',
                'partially_paid' => 'warning',
                'overdue'        => 'danger',
                'void','written_off' => 'neutral',
                default          => 'info',
            };
            $out[] = [
                'id'                    => (int)$r['id'],
                'invoice_number'        => (string)$r['invoice_number'],
                'total_amount'          => (string)$r['total_amount'],
                'total_amount_formatted'=> format_currency($r['total_amount']),
                'status'                => (string)$r['status'],
                'status_class'          => $statusClass,
                'invoice_date'          => $r['invoice_date'] ? format_date($r['invoice_date']) : '',
                'has_pdf'               => !empty($r['pdf_path']),
                // INTENTIONALLY do not return pdf_path to the API client (Trap 7)
                // — only the id is needed; the back end re-resolves the path
                // when the email is sent.
            ];
        }
        return $out;
    }

    // =========================================================
    // getCustomerContacts() — for the To-field quick-fill chips
    // =========================================================
    public static function getCustomerContacts(int $customerId): array
    {
        if ($customerId <= 0) return [];

        $rows = db_select(
            "SELECT id, name, title, email, phone, is_primary
             FROM customer_contacts
             WHERE customer_id = ?
               AND email IS NOT NULL AND email != ''
             ORDER BY is_primary DESC, name ASC",
            [$customerId]
        );

        // Also include the primary customer contact + invoice email
        // as synthetic chips so the user does not have to type them.
        $cust = db_row(
            "SELECT contact_name, email, billing_contact_name, billing_email, invoice_email
             FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$customerId]
        );

        $out = [];
        if ($cust) {
            if (!empty($cust['email'])) {
                $out[] = [
                    'id'    => 'primary',
                    'name'  => (string)($cust['contact_name'] ?: 'Primary contact'),
                    'email' => (string)$cust['email'],
                    'label' => 'Primary',
                ];
            }
            if (!empty($cust['billing_email']) && $cust['billing_email'] !== ($cust['email'] ?? '')) {
                $out[] = [
                    'id'    => 'billing',
                    'name'  => (string)($cust['billing_contact_name'] ?: 'Billing contact'),
                    'email' => (string)$cust['billing_email'],
                    'label' => 'Billing',
                ];
            }
            if (!empty($cust['invoice_email'])
                && $cust['invoice_email'] !== ($cust['email'] ?? '')
                && $cust['invoice_email'] !== ($cust['billing_email'] ?? '')) {
                $out[] = [
                    'id'    => 'invoice',
                    'name'  => 'Invoice email',
                    'email' => (string)$cust['invoice_email'],
                    'label' => 'Invoice',
                ];
            }
        }
        foreach ($rows as $r) {
            $out[] = [
                'id'    => (int)$r['id'],
                'name'  => (string)$r['name'] . ($r['title'] ? ' (' . $r['title'] . ')' : ''),
                'email' => (string)$r['email'],
                'label' => $r['is_primary'] ? 'Primary' : '',
            ];
        }
        return $out;
    }

    // =========================================================
    // renderEmailHtml() — wrap raw body in the company shell
    //
    // Mirrors FLEETFORGE_DESIGN_DETAILS §8 — outer wrapper with
    // a 600px centered card, brand header, orange accent bar,
    // and full contact footer.
    //
    // Design (locked S-EMAIL-TEMPLATES-REDESIGN):
    //   - Single-column centered 600px card on #f4f4f5 page bg
    //   - HEADER: dark #1a1a1a, logo image (180px wide) OR
    //     stylized company-name text fallback when logo_url unset
    //   - Orange #F97316 accent bar (4px) between header and body
    //   - BODY: 40px 40px 32px padding, system fonts
    //   - FOOTER: full company contact info (name + address +
    //     phone + email + website + copyright + disclaimer),
    //     centered, with orange contact-line links
    //
    // Body content uses the helper-pattern documented in the
    // session prompt — H1, H2, P, CTA buttons, info boxes,
    // key-value rows, alert boxes — all inline-styled tables
    // for email-client compatibility (Gmail, Apple Mail, Outlook).
    //
    // Inline styles only — email clients (Outlook, Gmail) strip
    // or ignore <style> blocks, so no external CSS.
    // =========================================================
    public static function renderEmailHtml(string $body): string
    {
        $companyName    = (string) settings_get('company.name',    'FleetForge');
        $companyAddress = (string) settings_get('company.address', '');
        $companyPhone   = (string) settings_get('company.phone',   '');
        $companyEmail   = (string) settings_get('company.email',   '');
        $companyWebsite = (string) settings_get('company.website', '');
        $companyLogoUrl = (string) settings_get('company.logo_url','');

        // ── HEADER: logo image OR stylized company name ─────────
        // WHY: branded image is preferred for recognizability, but
        // text fallback keeps the email professional when the logo
        // setting is empty or the URL is unreachable.
        $companyNameEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
        if ($companyLogoUrl !== '') {
            $logoUrlEsc = htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8');
            $headerInner = '<img src="' . $logoUrlEsc . '" alt="' . $companyNameEsc . '" '
                . 'width="180" style="display:block;margin:0 auto;max-width:180px;height:auto;border:0;outline:none;text-decoration:none;">';
        } else {
            $headerInner = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;'
                . 'font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.02em;text-align:center;">'
                . $companyNameEsc
                . '</div>';
        }

        // ── FOOTER: contact line (phone · email · website) ──────
        // Build only with values that exist — silent skip if a
        // setting is blank, so we never render "·  ·  ·".
        $contactBits = [];
        if ($companyPhone !== '') {
            // strip non-digit chars for tel: link target — humans see
            // the original formatted phone, dialers get the digits
            $telDigits = preg_replace('/[^0-9+]/', '', $companyPhone) ?: $companyPhone;
            $contactBits[] = '<a href="tel:' . htmlspecialchars($telDigits, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="color:#F97316;text-decoration:none;">'
                . htmlspecialchars($companyPhone, ENT_QUOTES, 'UTF-8')
                . '</a>';
        }
        if ($companyEmail !== '') {
            $emailEsc = htmlspecialchars($companyEmail, ENT_QUOTES, 'UTF-8');
            $contactBits[] = '<a href="mailto:' . $emailEsc . '" '
                . 'style="color:#F97316;text-decoration:none;">' . $emailEsc . '</a>';
        }
        if ($companyWebsite !== '') {
            // Normalize the website URL for the href: prepend https://
            // only if no protocol is already present. The display label
            // keeps whatever the operator stored (often without scheme).
            $webDisplay = $companyWebsite;
            $webHref    = $companyWebsite;
            if (!preg_match('#^https?://#i', $webHref)) {
                $webHref = 'https://' . ltrim($webHref, '/');
            }
            $contactBits[] = '<a href="' . htmlspecialchars($webHref, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="color:#F97316;text-decoration:none;">'
                . htmlspecialchars($webDisplay, ENT_QUOTES, 'UTF-8')
                . '</a>';
        }
        $contactLine = $contactBits
            ? '<p style="margin:0 0 12px;font-size:12px;color:#6b7280;">'
                . implode(' &nbsp;·&nbsp; ', $contactBits)
                . '</p>'
            : '';

        $addressLine = $companyAddress !== ''
            ? '<p style="margin:0 0 4px;font-size:12px;color:#6b7280;">'
                . htmlspecialchars($companyAddress, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        $year = date('Y');

        // ── Assemble shell ──────────────────────────────────────
        // Heredoc-style assembly keeps the structure readable. All
        // styles are inline (email clients strip <style>). All
        // dimensions are in fixed px — fluid units break Outlook.
        $html  = '<!DOCTYPE html><html lang="en"><head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<meta name="x-apple-disable-message-reformatting">';
        $html .= '<title>' . $companyNameEsc . '</title>';
        $html .= '</head>';
        $html .= '<body style="margin:0;padding:0;background-color:#f4f4f5;'
              .  'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;'
              .  '-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">';

        // Outer wrapper — page-level grey background
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
              .  'style="background-color:#f4f4f5;padding:40px 20px;">';
        $html .= '<tr><td align="center">';

        // Email card — 600px max
        $html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" '
              .  'style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;'
              .  'overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';

        // HEADER — logo or stylized brand text on dark bg
        $html .= '<tr><td style="background-color:#1a1a1a;padding:32px 40px;text-align:center;">'
              .  $headerInner . '</td></tr>';

        // ORANGE ACCENT BAR — 4px brand stripe
        $html .= '<tr><td style="background-color:#F97316;height:4px;font-size:0;line-height:0;">&nbsp;</td></tr>';

        // BODY content slot — caller-provided HTML
        $html .= '<tr><td style="padding:40px 40px 32px;font-size:15px;line-height:1.6;color:#374151;">'
              .  $body . '</td></tr>';

        // DIVIDER — hairline between body and footer
        $html .= '<tr><td style="padding:0 40px;">'
              .  '<div style="border-top:1px solid #e5e7eb;font-size:0;line-height:0;">&nbsp;</div>'
              .  '</td></tr>';

        // FOOTER — company name + address + contact line + copyright
        $html .= '<tr><td style="padding:24px 40px 32px;text-align:center;">';
        $html .=   '<p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#374151;">'
              .    $companyNameEsc . '</p>';
        $html .=   $addressLine;
        $html .=   $contactLine;
        $html .=   '<p style="margin:0;font-size:11px;color:#9ca3af;line-height:1.5;">'
              .    'This email was sent by FleetForge on behalf of ' . $companyNameEsc . '.<br>'
              .    '&copy; ' . $year . ' ' . $companyNameEsc . '. All rights reserved.'
              .    '</p>';
        $html .= '</td></tr>';

        $html .= '</table>'; // /Email card
        $html .= '</td></tr></table>'; // /Outer wrapper
        $html .= '</body></html>';

        return $html;
    }

    // =========================================================
    // stripHtml() — produce plain-text body from html
    // =========================================================
    private static function stripHtml(string $html): string
    {
        // Convert <br>, </p>, </li> to newlines BEFORE strip_tags
        // so the resulting text has reasonable structure.
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/p>/i', "\n\n", (string)$text);
        $text = preg_replace('/<\/li>/i', "\n", (string)$text);
        $text = strip_tags((string)$text);
        $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);
        return trim((string)$text);
    }
}
