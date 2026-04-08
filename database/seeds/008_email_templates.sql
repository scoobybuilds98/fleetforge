-- ============================================================
-- FleetForge — Seed Email Templates
-- Session EMAIL-1
--
-- Inserts the 10 default email templates used by the Customer
-- Email System. Templates are addressed by their unique slug
-- (NEVER by id) so they can be safely reseeded across environments.
--
-- Variables placeholder format: {customer_name}, {invoice_number}, etc.
-- The list of allowed variables for each template is stored in the
-- variables JSON column so the compose UI can render the chips panel
-- without hard-coding them in JS.
--
-- Idempotent: ON DUPLICATE KEY UPDATE on slug — re-running this
-- file will refresh the templates without breaking existing email
-- logs (template_id is FK SET NULL on delete).
-- ============================================================

INSERT INTO email_templates
  (name, slug, subject, body_html, body_text, category, variables, is_active)
VALUES

-- ============================================================
-- 1. INVOICE READY — primary invoice delivery template
-- ============================================================
(
  'Invoice Ready',
  'invoice_ready',
  'Invoice {invoice_number} from {company_name} — {amount} due {due_date}',
  '<p>Dear {customer_name},</p>
<p>Your invoice <strong>{invoice_number}</strong> is ready for review. Please find the details below.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#f5f5f4;border:1px solid #e5e5e2;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Invoice Number:</td><td>{invoice_number}</td></tr>
  <tr><td style="font-weight:600;">Invoice Date:</td><td>{invoice_date}</td></tr>
  <tr><td style="font-weight:600;">Amount Due:</td><td><strong>{amount_due}</strong></td></tr>
  <tr><td style="font-weight:600;">Due Date:</td><td>{due_date}</td></tr>
</table>
<p><strong>Payment Instructions:</strong> Payment by cheque or wire transfer. Contact us at {company_phone} for details.</p>
<p>If you have any questions about this invoice, please reply to this email or call us at {company_phone}.</p>
<p>Thank you for your business.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

Your invoice {invoice_number} is ready for review.

Invoice Number: {invoice_number}
Invoice Date: {invoice_date}
Amount Due: {amount_due}
Due Date: {due_date}

Payment Instructions: Payment by cheque or wire transfer. Contact us at {company_phone} for details.

If you have any questions, please reply to this email or call us at {company_phone}.

Thank you for your business.

Sincerely,
{sender_name}
{company_name}',
  'invoice',
  '["customer_name","company_name","invoice_number","invoice_date","amount","amount_due","due_date","company_phone","company_email","sender_name"]',
  1
),

-- ============================================================
-- 2. PAYMENT REMINDER — 7 DAYS
-- ============================================================
(
  'Payment Reminder — 7 Days',
  'payment_reminder_7',
  'Reminder: Invoice {invoice_number} due in 7 days',
  '<p>Dear {customer_name},</p>
<p>This is a friendly reminder that invoice <strong>{invoice_number}</strong> will be due in <strong>7 days</strong> on <strong>{due_date}</strong>.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#f5f5f4;border:1px solid #e5e5e2;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Invoice Number:</td><td>{invoice_number}</td></tr>
  <tr><td style="font-weight:600;">Amount Due:</td><td><strong>{amount_due}</strong></td></tr>
  <tr><td style="font-weight:600;">Due Date:</td><td>{due_date}</td></tr>
</table>
<p>If you have already submitted payment, please disregard this notice. Otherwise, please ensure payment is received before the due date.</p>
<p>Thank you for your continued business.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

This is a friendly reminder that invoice {invoice_number} will be due in 7 days on {due_date}.

Invoice Number: {invoice_number}
Amount Due: {amount_due}
Due Date: {due_date}

If you have already submitted payment, please disregard this notice.

Thank you,
{sender_name}
{company_name}',
  'reminder',
  '["customer_name","company_name","invoice_number","amount_due","due_date","sender_name"]',
  1
),

-- ============================================================
-- 3. PAYMENT REMINDER — 3 DAYS
-- ============================================================
(
  'Payment Reminder — 3 Days',
  'payment_reminder_3',
  'Reminder: Invoice {invoice_number} due in 3 days',
  '<p>Dear {customer_name},</p>
<p>This is a reminder that invoice <strong>{invoice_number}</strong> is due in <strong>3 days</strong> on <strong>{due_date}</strong>.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Invoice Number:</td><td>{invoice_number}</td></tr>
  <tr><td style="font-weight:600;">Amount Due:</td><td><strong>{amount_due}</strong></td></tr>
  <tr><td style="font-weight:600;">Due Date:</td><td>{due_date}</td></tr>
</table>
<p>To avoid late fees, please ensure payment is received by <strong>{due_date}</strong>.</p>
<p>If you have any questions or need to discuss payment arrangements, please contact us at {company_phone}.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

This is a reminder that invoice {invoice_number} is due in 3 days on {due_date}.

Invoice Number: {invoice_number}
Amount Due: {amount_due}
Due Date: {due_date}

To avoid late fees, please ensure payment is received by {due_date}.

If you have questions, contact us at {company_phone}.

Sincerely,
{sender_name}
{company_name}',
  'reminder',
  '["customer_name","company_name","invoice_number","amount_due","due_date","company_phone","sender_name"]',
  1
),

-- ============================================================
-- 4. PAYMENT OVERDUE
-- ============================================================
(
  'Payment Overdue',
  'payment_overdue',
  'Overdue: Invoice {invoice_number} — {days_overdue} days past due',
  '<p>Dear {customer_name},</p>
<p>Our records indicate that invoice <strong>{invoice_number}</strong> is now <strong>{days_overdue} days overdue</strong>.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#fee2e2;border:1px solid #dc2626;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Invoice Number:</td><td>{invoice_number}</td></tr>
  <tr><td style="font-weight:600;">Original Due Date:</td><td>{due_date}</td></tr>
  <tr><td style="font-weight:600;">Days Overdue:</td><td><strong>{days_overdue}</strong></td></tr>
  <tr><td style="font-weight:600;">Amount Due:</td><td><strong>{amount_due}</strong></td></tr>
</table>
<p>Please remit payment immediately to avoid additional late fees and possible service interruption. If you have already submitted payment, please contact us so we can update our records.</p>
<p>If you are experiencing difficulty making payment, please contact us at {company_phone} to discuss payment arrangements.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

Our records indicate that invoice {invoice_number} is now {days_overdue} days overdue.

Invoice Number: {invoice_number}
Original Due Date: {due_date}
Days Overdue: {days_overdue}
Amount Due: {amount_due}

Please remit payment immediately to avoid additional late fees. If you are experiencing difficulty, contact us at {company_phone}.

Sincerely,
{sender_name}
{company_name}',
  'collection',
  '["customer_name","company_name","invoice_number","due_date","days_overdue","amount_due","company_phone","sender_name"]',
  1
),

-- ============================================================
-- 5. PAYMENT RECEIVED — Thank You
-- ============================================================
(
  'Payment Received — Thank You',
  'payment_received',
  'Payment received — Invoice {invoice_number} paid in full',
  '<p>Dear {customer_name},</p>
<p>Thank you for your payment. We have received your payment in full for invoice <strong>{invoice_number}</strong>.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#dcfce7;border:1px solid #16a34a;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Invoice Number:</td><td>{invoice_number}</td></tr>
  <tr><td style="font-weight:600;">Payment Date:</td><td>{payment_date}</td></tr>
  <tr><td style="font-weight:600;">Amount Received:</td><td><strong>{payment_amount}</strong></td></tr>
</table>
<p>Your account is now up to date. We appreciate your prompt payment and continued business.</p>
<p>If you have any questions, please contact us at {company_phone} or {company_email}.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

Thank you for your payment. We have received your payment in full for invoice {invoice_number}.

Invoice Number: {invoice_number}
Payment Date: {payment_date}
Amount Received: {payment_amount}

Your account is now up to date. We appreciate your prompt payment.

Sincerely,
{sender_name}
{company_name}',
  'payment',
  '["customer_name","company_name","invoice_number","payment_date","payment_amount","company_phone","company_email","sender_name"]',
  1
),

-- ============================================================
-- 6. LEASE ACTIVATION
-- ============================================================
(
  'Lease Activated',
  'lease_activation',
  'Lease {contract_number} is now active — {unit_number}',
  '<p>Dear {customer_name},</p>
<p>This confirms that your lease contract <strong>{contract_number}</strong> is now active.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#f5f5f4;border:1px solid #e5e5e2;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Contract Number:</td><td>{contract_number}</td></tr>
  <tr><td style="font-weight:600;">Equipment Unit:</td><td>{unit_number}</td></tr>
  <tr><td style="font-weight:600;">Start Date:</td><td>{lease_start}</td></tr>
  <tr><td style="font-weight:600;">End Date:</td><td>{lease_end}</td></tr>
</table>
<p>Your invoices will be generated according to the billing schedule outlined in your contract. Please retain this email for your records.</p>
<p>If you have any questions about your lease, please contact us at {company_phone}.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

This confirms that your lease contract {contract_number} is now active.

Contract Number: {contract_number}
Equipment Unit: {unit_number}
Start Date: {lease_start}
End Date: {lease_end}

Your invoices will be generated according to the billing schedule outlined in your contract.

Sincerely,
{sender_name}
{company_name}',
  'lease',
  '["customer_name","company_name","contract_number","unit_number","lease_start","lease_end","company_phone","sender_name"]',
  1
),

-- ============================================================
-- 7. LEASE CLOSING
-- ============================================================
(
  'Lease Closed',
  'lease_closing',
  'Lease {contract_number} closed — thank you for your business',
  '<p>Dear {customer_name},</p>
<p>Your lease contract <strong>{contract_number}</strong> has been closed and the equipment has been returned. Thank you for your business.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#f5f5f4;border:1px solid #e5e5e2;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Contract Number:</td><td>{contract_number}</td></tr>
  <tr><td style="font-weight:600;">Equipment Unit:</td><td>{unit_number}</td></tr>
  <tr><td style="font-weight:600;">Lease Start:</td><td>{lease_start}</td></tr>
  <tr><td style="font-weight:600;">Lease End:</td><td>{lease_end}</td></tr>
</table>
<p>A final invoice will be issued for any outstanding charges, including mileage and any applicable fees. Please ensure all final invoices are paid in full.</p>
<p>We hope you were satisfied with our service and look forward to working with you again. If you would like to discuss future rentals, please contact us at {company_phone}.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

Your lease contract {contract_number} has been closed and the equipment has been returned. Thank you for your business.

Contract Number: {contract_number}
Equipment Unit: {unit_number}
Lease Start: {lease_start}
Lease End: {lease_end}

A final invoice will be issued for any outstanding charges. We hope you were satisfied with our service.

Sincerely,
{sender_name}
{company_name}',
  'lease',
  '["customer_name","company_name","contract_number","unit_number","lease_start","lease_end","company_phone","sender_name"]',
  1
),

-- ============================================================
-- 8. COMPLIANCE EXPIRING
-- ============================================================
(
  'Document Expiring Soon',
  'compliance_expiring',
  'Action required: {document_type} expiring for unit {unit_number} on {expiry_date}',
  '<p>Dear {customer_name},</p>
<p>This is to notify you that an important compliance document for one of your leased units is expiring soon.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#fef3c7;border:1px solid #d97706;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Equipment Unit:</td><td>{unit_number}</td></tr>
  <tr><td style="font-weight:600;">Document Type:</td><td>{document_type}</td></tr>
  <tr><td style="font-weight:600;">Expiry Date:</td><td><strong>{expiry_date}</strong></td></tr>
</table>
<p>Please take action to renew this document before the expiry date to avoid any service interruption or compliance violations.</p>
<p>If you need assistance or have questions, please contact us at {company_phone}.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

This is to notify you that an important compliance document for one of your leased units is expiring soon.

Equipment Unit: {unit_number}
Document Type: {document_type}
Expiry Date: {expiry_date}

Please take action to renew this document before the expiry date.

Sincerely,
{sender_name}
{company_name}',
  'compliance',
  '["customer_name","company_name","unit_number","document_type","expiry_date","company_phone","sender_name"]',
  1
),

-- ============================================================
-- 9. GENERAL MESSAGE — free-form template
-- ============================================================
(
  'General Message',
  'general',
  '{subject}',
  '<p>Dear {customer_name},</p>
<p>[Type your message here]</p>
<p>If you have any questions, please contact us at {company_phone} or {company_email}.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

[Type your message here]

If you have any questions, please contact us at {company_phone} or {company_email}.

Sincerely,
{sender_name}
{company_name}',
  'general',
  '["customer_name","company_name","subject","company_phone","company_email","sender_name"]',
  1
),

-- ============================================================
-- 10. ACCOUNT STATEMENT
-- ============================================================
(
  'Account Statement',
  'statement',
  'Account Statement — {company_name} — {month} {year}',
  '<p>Dear {customer_name},</p>
<p>Please find attached your account statement for <strong>{month} {year}</strong>.</p>
<table cellpadding="8" cellspacing="0" border="0" style="background:#f5f5f4;border:1px solid #e5e5e2;border-radius:6px;width:100%;margin:16px 0;">
  <tr><td style="font-weight:600;width:35%;">Statement Period:</td><td>{month} {year}</td></tr>
  <tr><td style="font-weight:600;">Outstanding Balance:</td><td><strong>{amount_due}</strong></td></tr>
</table>
<p>Please review the statement and contact us if you have any questions or notice any discrepancies.</p>
<p>For payment, please reference your account when remitting funds. If you have any questions, please contact us at {company_phone} or {company_email}.</p>
<p>Sincerely,<br/>{sender_name}<br/>{company_name}</p>',
  'Dear {customer_name},

Please find attached your account statement for {month} {year}.

Statement Period: {month} {year}
Outstanding Balance: {amount_due}

Please review the statement and contact us if you have any questions.

For payment, please reference your account when remitting funds.

Sincerely,
{sender_name}
{company_name}',
  'invoice',
  '["customer_name","company_name","month","year","amount_due","company_phone","company_email","sender_name"]',
  1
)

ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  subject = VALUES(subject),
  body_html = VALUES(body_html),
  body_text = VALUES(body_text),
  category = VALUES(category),
  variables = VALUES(variables);
