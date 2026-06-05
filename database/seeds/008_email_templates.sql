-- ============================================================
-- FleetForge — Seed Email Templates
-- Session EMAIL-1 (initial) + S-EMAIL-TEMPLATES-REDESIGN (2026-05-21)
--
-- Inserts the 10 default email templates used by the Customer
-- Email System. Templates are addressed by their unique slug
-- (NEVER by id) so they can be safely reseeded across environments.
--
-- TEMPLATE PHILOSOPHY (post-S-EMAIL-TEMPLATES-REDESIGN):
--   - body_html stores ONLY the inner body content (no <html>,
--     <body>, no shell). The shell is wrapped at send-time by
--     EmailService::renderEmailHtml() — that gives us logo +
--     orange accent bar + full contact footer in ONE place.
--   - All inline styles use system fonts, fixed px sizes, and
--     table-based layouts. Email clients (Outlook, Gmail) strip
--     <style> blocks and ignore most CSS3, so this is the only
--     reliable rendering path.
--   - Brand color: #F97316 (FleetForge orange) for CTAs and
--     accents; #111827 for primary text; #6b7280 for muted.
--
-- Variables placeholder format: {customer_name}, {invoice_number}, etc.
-- (Single brace — see EmailService::substitute at line 347.)
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
  'Invoice {invoice_number} from {company_name} — {amount_due} due {due_date}',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Invoice {invoice_number}</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">Issued {invoice_date}</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Thank you for your business. Your invoice <strong>{invoice_number}</strong> is ready for your review. Please find the details below.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Invoice Number</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{invoice_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Invoice Date</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{invoice_date}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Due Date</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{due_date}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:12px 0 6px;border-top:1px solid #e5e7eb;">Amount Due</td>
        <td style="font-size:20px;font-weight:700;color:#F97316;text-align:right;padding:12px 0 6px;border-top:1px solid #e5e7eb;">{amount_due}</td>
      </tr>
    </table>
  </td></tr>
</table>

<h2 style="margin:24px 0 8px;font-size:13px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.05em;">Payment Information</h2>
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">Payment by cheque or wire transfer. Please reference invoice <strong style="color:#374151;">{invoice_number}</strong> when remitting payment. Contact us at {company_phone} for wire transfer details.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">
  <tr><td style="border-left:4px solid #F97316;background:#fff7ed;padding:12px 16px;border-radius:0 6px 6px 0;">
    <p style="margin:0;font-size:14px;color:#7c2d12;">Questions about this invoice? Reply to this email or call us at <strong>{company_phone}</strong>.</p>
  </td></tr>
</table>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Your invoice {invoice_number} is ready for your review.

  Invoice Number:  {invoice_number}
  Invoice Date:    {invoice_date}
  Due Date:        {due_date}
  Amount Due:      {amount_due}

PAYMENT INFORMATION
Payment by cheque or wire transfer. Please reference invoice {invoice_number} when remitting payment. Contact us at {company_phone} for wire transfer details.

Questions about this invoice? Reply to this email or call us at {company_phone}.

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
  'Reminder: Invoice {invoice_number} is due in 7 days',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Payment Reminder</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">A friendly reminder about an upcoming invoice</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">This is a friendly reminder that invoice <strong>{invoice_number}</strong> will be due in <strong>7 days</strong>.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Invoice Number</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{invoice_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Due Date</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{due_date}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:12px 0 6px;border-top:1px solid #e5e7eb;">Amount Due</td>
        <td style="font-size:20px;font-weight:700;color:#F97316;text-align:right;padding:12px 0 6px;border-top:1px solid #e5e7eb;">{amount_due}</td>
      </tr>
    </table>
  </td></tr>
</table>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">If you have already submitted payment, please disregard this notice — payments may take a few business days to post to your account. Otherwise, please ensure your payment is received before the due date.</p>

<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#6b7280;">Thank you for your continued business.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

This is a friendly reminder that invoice {invoice_number} will be due in 7 days.

  Invoice Number:  {invoice_number}
  Due Date:        {due_date}
  Amount Due:      {amount_due}

If you have already submitted payment, please disregard this notice. Otherwise, please ensure your payment is received before the due date.

Thank you for your continued business.

Sincerely,
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
  'Reminder: Invoice {invoice_number} is due in 3 days',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Payment Reminder</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">Your invoice is due soon</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">This is a reminder that invoice <strong>{invoice_number}</strong> is due in <strong>3 days</strong> on <strong>{due_date}</strong>.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Invoice Number</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{invoice_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Due Date</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{due_date}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:12px 0 6px;border-top:1px solid #e5e7eb;">Amount Due</td>
        <td style="font-size:20px;font-weight:700;color:#F97316;text-align:right;padding:12px 0 6px;border-top:1px solid #e5e7eb;">{amount_due}</td>
      </tr>
    </table>
  </td></tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
  <tr><td style="border-left:4px solid #F97316;background:#fff7ed;padding:12px 16px;border-radius:0 6px 6px 0;">
    <p style="margin:0;font-size:14px;color:#7c2d12;">To avoid late fees, please ensure payment is received by <strong>{due_date}</strong>.</p>
  </td></tr>
</table>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">If you have any questions or need to discuss payment arrangements, please contact us at <strong>{company_phone}</strong>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

This is a reminder that invoice {invoice_number} is due in 3 days on {due_date}.

  Invoice Number:  {invoice_number}
  Due Date:        {due_date}
  Amount Due:      {amount_due}

To avoid late fees, please ensure payment is received by {due_date}.

If you have any questions or need to discuss payment arrangements, please contact us at {company_phone}.

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
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#991b1b;line-height:1.3;">Invoice Overdue</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">Immediate attention required</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
  <tr><td style="border-left:4px solid #dc2626;background:#fef2f2;padding:14px 18px;border-radius:0 6px 6px 0;">
    <p style="margin:0;font-size:14px;color:#991b1b;font-weight:600;">Invoice {invoice_number} is now {days_overdue} days past its due date.</p>
  </td></tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Invoice Number</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{invoice_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Original Due Date</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{due_date}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Days Overdue</td>
        <td style="font-size:14px;font-weight:600;color:#dc2626;text-align:right;padding:6px 0;">{days_overdue} days</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:12px 0 6px;border-top:1px solid #e5e7eb;">Amount Due</td>
        <td style="font-size:20px;font-weight:700;color:#dc2626;text-align:right;padding:12px 0 6px;border-top:1px solid #e5e7eb;">{amount_due}</td>
      </tr>
    </table>
  </td></tr>
</table>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Please remit payment immediately to avoid additional late fees and possible service interruption. If you have already submitted payment, please contact us so we can update our records.</p>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">If you are experiencing difficulty making payment, please contact us at <strong style="color:#374151;">{company_phone}</strong> as soon as possible to discuss payment arrangements.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Our records indicate that invoice {invoice_number} is now {days_overdue} days overdue.

  Invoice Number:    {invoice_number}
  Original Due Date: {due_date}
  Days Overdue:      {days_overdue} days
  Amount Due:        {amount_due}

Please remit payment immediately to avoid additional late fees and possible service interruption. If you have already submitted payment, please contact us so we can update our records.

If you are experiencing difficulty making payment, please contact us at {company_phone} as soon as possible to discuss payment arrangements.

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
  'Payment received for invoice {invoice_number} — thank you',
  '<table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
  <tr><td style="background-color:#dcfce7;border-radius:50%;width:56px;height:56px;text-align:center;vertical-align:middle;">
    <span style="font-size:32px;color:#16a34a;font-weight:700;line-height:56px;">&#10003;</span>
  </td></tr>
</table>

<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Payment Received</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">Your account is up to date</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Thank you for your payment. We have received your payment in full for invoice <strong>{invoice_number}</strong>.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Invoice Number</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{invoice_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Payment Date</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{payment_date}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:12px 0 6px;border-top:1px solid #bbf7d0;">Amount Received</td>
        <td style="font-size:20px;font-weight:700;color:#16a34a;text-align:right;padding:12px 0 6px;border-top:1px solid #bbf7d0;">{payment_amount}</td>
      </tr>
    </table>
  </td></tr>
</table>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Your account is now up to date. We appreciate your prompt payment and continued business.</p>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">Please retain this email as your receipt. If you have any questions, please contact us at <strong style="color:#374151;">{company_phone}</strong> or <a href="mailto:{company_email}" style="color:#F97316;text-decoration:none;">{company_email}</a>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Thank you for your payment. We have received your payment in full for invoice {invoice_number}.

  Invoice Number:    {invoice_number}
  Payment Date:      {payment_date}
  Amount Received:   {payment_amount}

Your account is now up to date. We appreciate your prompt payment and continued business.

Please retain this email as your receipt. If you have any questions, please contact us at {company_phone} or {company_email}.

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
  'Lease {contract_number} is now active — unit {unit_number}',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Your Lease is Active</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">Welcome aboard — your equipment is ready</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Your equipment lease with {company_name} is now active and ready to go. We are pleased to have you as a customer.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Contract Number</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{contract_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Equipment Unit</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{unit_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Lease Start</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{lease_start}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Lease End</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{lease_end}</td>
      </tr>
    </table>
  </td></tr>
</table>

<h2 style="margin:24px 0 8px;font-size:13px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.05em;">What Happens Next</h2>
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">Your invoices will be generated according to the billing schedule outlined in your contract. We will send each invoice to this email address. Please retain this email and your signed contract for your records.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;">
  <tr><td style="border-left:4px solid #F97316;background:#fff7ed;padding:12px 16px;border-radius:0 6px 6px 0;">
    <p style="margin:0;font-size:14px;color:#7c2d12;">In case of breakdown or any issue with the equipment, please call us immediately at <strong>{company_phone}</strong>.</p>
  </td></tr>
</table>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Your equipment lease with {company_name} is now active and ready to go. We are pleased to have you as a customer.

  Contract Number:  {contract_number}
  Equipment Unit:   {unit_number}
  Lease Start:      {lease_start}
  Lease End:        {lease_end}

WHAT HAPPENS NEXT
Your invoices will be generated according to the billing schedule outlined in your contract. We will send each invoice to this email address. Please retain this email and your signed contract for your records.

In case of breakdown or any issue with the equipment, please call us immediately at {company_phone}.

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
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Lease Closed</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">Thank you for choosing {company_name}</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Your lease contract <strong>{contract_number}</strong> has been closed and the equipment has been returned. Thank you for your business.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Contract Number</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{contract_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Equipment Unit</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{unit_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Lease Start</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{lease_start}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Lease End</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{lease_end}</td>
      </tr>
    </table>
  </td></tr>
</table>

<h2 style="margin:24px 0 8px;font-size:13px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.05em;">Final Billing</h2>
<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">A final invoice will be issued shortly for any outstanding charges, including mileage and any applicable fees. Please ensure all final invoices are paid in full to close your account.</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">We hope you were satisfied with our service and look forward to working with you again. If you would like to discuss future rentals or lease arrangements, please contact us at <strong>{company_phone}</strong>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Your lease contract {contract_number} has been closed and the equipment has been returned. Thank you for your business.

  Contract Number:  {contract_number}
  Equipment Unit:   {unit_number}
  Lease Start:      {lease_start}
  Lease End:        {lease_end}

FINAL BILLING
A final invoice will be issued shortly for any outstanding charges, including mileage and any applicable fees. Please ensure all final invoices are paid in full to close your account.

We hope you were satisfied with our service and look forward to working with you again. If you would like to discuss future rentals or lease arrangements, please contact us at {company_phone}.

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
  'Action required: {document_type} for unit {unit_number} expires {expiry_date}',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Document Expiring Soon</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">Action required to maintain compliance</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">This is to notify you that an important compliance document for one of your leased units is expiring soon.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Equipment Unit</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{unit_number}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:6px 0;">Document Type</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{document_type}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:12px 0 6px;border-top:1px solid #fcd34d;">Expiry Date</td>
        <td style="font-size:18px;font-weight:700;color:#b45309;text-align:right;padding:12px 0 6px;border-top:1px solid #fcd34d;">{expiry_date}</td>
      </tr>
    </table>
  </td></tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
  <tr><td style="border-left:4px solid #F97316;background:#fff7ed;padding:12px 16px;border-radius:0 6px 6px 0;">
    <p style="margin:0;font-size:14px;color:#7c2d12;">Please renew this document before the expiry date to avoid any service interruption or compliance violations.</p>
  </td></tr>
</table>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">If you need assistance with renewal or have questions, please contact us at <strong style="color:#374151;">{company_phone}</strong>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

This is to notify you that an important compliance document for one of your leased units is expiring soon.

  Equipment Unit:  {unit_number}
  Document Type:   {document_type}
  Expiry Date:     {expiry_date}

Please renew this document before the expiry date to avoid any service interruption or compliance violations.

If you need assistance with renewal or have questions, please contact us at {company_phone}.

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
  '<h1 style="margin:0 0 24px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">{subject}</h1>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">[Type your message here]</p>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">If you have any questions, please contact us at <strong style="color:#374151;">{company_phone}</strong> or <a href="mailto:{company_email}" style="color:#F97316;text-decoration:none;">{company_email}</a>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
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
  'Account Statement — {month} {year}',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Account Statement</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">{month} {year}</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Please find your account statement for <strong>{month} {year}</strong> attached to this email. The statement summarizes all activity on your account for this period.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;border-radius:6px;overflow:hidden;">
  <tr><td style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="font-size:13px;color:#6b7280;width:50%;padding:6px 0;">Statement Period</td>
        <td style="font-size:14px;font-weight:600;color:#111827;text-align:right;padding:6px 0;">{month} {year}</td>
      </tr>
      <tr>
        <td style="font-size:13px;color:#6b7280;padding:12px 0 6px;border-top:1px solid #e5e7eb;">Outstanding Balance</td>
        <td style="font-size:20px;font-weight:700;color:#F97316;text-align:right;padding:12px 0 6px;border-top:1px solid #e5e7eb;">{amount_due}</td>
      </tr>
    </table>
  </td></tr>
</table>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">Please review the statement carefully and contact us if you have any questions or notice any discrepancies.</p>

<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">For payment, please reference your account number when remitting funds. If you have any questions, please contact us at <strong style="color:#374151;">{company_phone}</strong> or <a href="mailto:{company_email}" style="color:#F97316;text-decoration:none;">{company_email}</a>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Please find your account statement for {month} {year} attached to this email. The statement summarizes all activity on your account for this period.

  Statement Period:    {month} {year}
  Outstanding Balance: {amount_due}

Please review the statement carefully and contact us if you have any questions or notice any discrepancies.

For payment, please reference your account number when remitting funds. If you have any questions, please contact us at {company_phone} or {company_email}.

Sincerely,
{sender_name}
{company_name}',
  'invoice',
  '["customer_name","company_name","month","year","amount_due","company_phone","company_email","sender_name"]',
  1
),

-- ============================================================
-- 11. CREDIT APPLICATION INVITE — public tokenized credit-app link (S-CCA-1)
--     body-only; {minimum_requirements_html} is raw HTML injected verbatim by
--     EmailService::substitute. category 'general' (no 'customer' enum value).
-- ============================================================
(
  'Credit Application Invite',
  'credit_application_invite',
  'Credit Application from {company_name}',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Credit Application</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">{company_name}</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Thank you for your interest in establishing a credit account with <strong>{company_name}</strong>. Please complete our secure online credit application using the button below. The form takes only a few minutes and lets you sign electronically.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
  <tr><td align="center">
    <table cellpadding="0" cellspacing="0" border="0">
      <tr><td style="border-radius:6px;background-color:#F97316;">
        <a href="{credit_application_url}" target="_blank" style="display:inline-block;padding:14px 32px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;">Start Your Credit Application</a>
      </td></tr>
    </table>
  </td></tr>
</table>

<p style="margin:0 0 24px;font-size:13px;line-height:1.6;color:#6b7280;">If the button does not work, copy and paste this link into your browser:<br><a href="{credit_application_url}" target="_blank" style="color:#F97316;word-break:break-all;">{credit_application_url}</a></p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
  <tr><td style="border-left:4px solid #F97316;background:#fff7ed;padding:16px 20px;border-radius:0 6px 6px 0;">
    {minimum_requirements_html}
  </td></tr>
</table>

<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#6b7280;">Questions? Reply to this email or call us at <strong>{company_phone}</strong>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Thank you for your interest in establishing a credit account with {company_name}.

Please complete our secure online credit application here:
{credit_application_url}

The form takes only a few minutes and lets you sign electronically.

Questions? Reply to this email or call us at {company_phone}.

Sincerely,
{sender_name}
{company_name}',
  'general',
  '["company_name","customer_name","credit_application_url","sender_name","minimum_requirements_html","company_phone"]',
  1
)

ON DUPLICATE KEY UPDATE
  name      = VALUES(name),
  subject   = VALUES(subject),
  body_html = VALUES(body_html),
  body_text = VALUES(body_text),
  category  = VALUES(category),
  variables = VALUES(variables);
