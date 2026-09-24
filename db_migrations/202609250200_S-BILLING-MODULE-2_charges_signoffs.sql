-- ============================================================
-- S-BILLING-MODULE-2 — charges queue, step sign-offs, due-date basis,
-- the monthly-bundle email, and the cron exception source.
--
-- billing_charges — charges queued for a lease's NEXT invoice (a one-off:
--   damage, extra wash, admin fee…) or billed EVERY month (a standing fee:
--   yard parking, a second GPS…). InvoiceGenerator::createFromLease() adds
--   every due charge to a rental invoice as a line that references the
--   charge (invoice_line_items.reference_type = 'billing_charge'). "Billed"
--   is never stored: a charge is billed when a LIVE (non-void, non-deleted)
--   invoice carries its line — so voiding / deleting / regenerating a draft
--   puts the charge back in the queue by itself, exactly like cartage.
--   Positive charges only: credits belong to credit notes (and a credit line
--   could push a subtotal negative, which the generator refuses).
--
-- billing_cycles.step_signoffs — optional sign-off per step
--   {step_key: {by, by_id, at, note}} so the cycle shows who finished what.
--
-- invoice_billing_exceptions.source += 'cron' — the monthly invoice job now
--   records the leases it could not bill in the same queue as the workbench.
--
-- Settings (group billing_cycle):
--   billing_cycle.due_date_basis  send_date | invoice_date. send_date: when
--     an invoice is SENT its due date becomes the later of the stored due
--     date and send date + the customer's terms, so a month billed in arrears
--     is not already overdue the day it goes out (KNOWN ISSUE #113).
--
-- email_templates 'invoice_bundle' — one email per customer carrying all of
--   the month's invoices (Billing → cycle → Customers). INSERT IGNORE so an
--   operator's edits are never overwritten.
-- ============================================================

CREATE TABLE IF NOT EXISTS billing_charges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    item_type ENUM('other','damage','fuel','wash','sweep','manual_adjustment') NOT NULL DEFAULT 'other'
        COMMENT 'invoice_line_items.item_type the line is billed as',
    quantity DECIMAL(10,4) NOT NULL DEFAULT '1.0000',
    unit_price DECIMAL(12,2) NOT NULL,
    amount DECIMAL(12,2) NOT NULL COMMENT 'quantity x unit_price, in the lease currency',
    taxable TINYINT(1) NOT NULL DEFAULT 1,
    recurrence ENUM('once','monthly') NOT NULL DEFAULT 'once',
    bill_from DATE NOT NULL COMMENT 'Bills on the first invoice whose period ends on/after this day',
    bill_until DATE DEFAULT NULL COMMENT 'monthly only: last month it bills (NULL = until cancelled)',
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    notes VARCHAR(500) DEFAULT NULL,
    cancelled_by INT UNSIGNED DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancel_reason VARCHAR(500) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_billing_charge_lease (lease_id, status),
    KEY idx_billing_charge_customer (customer_id),
    CONSTRAINT fk_billing_charge_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_charge_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_charge_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_charge_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_cycles' AND COLUMN_NAME = 'step_signoffs');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE billing_cycles ADD COLUMN step_signoffs JSON DEFAULT NULL COMMENT ''Per-step sign-off {step:{by,by_id,at,note}}''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE invoice_billing_exceptions
    MODIFY COLUMN `source` ENUM('batch_generate','batch_run','manual','cron') NOT NULL DEFAULT 'batch_generate';

INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`)
VALUES
  ('billing_cycle.due_date_basis', 'send_date', 'string', 'billing_cycle',
   'Payment terms run from',
   'send_date = when an invoice is sent, its due date becomes send date + the customer''s terms if that is later (an invoice billed in arrears is never overdue the day it goes out). invoice_date = due date stays invoice date + terms.',
   0, 0)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `value_type`  = VALUES(`value_type`),
  `group_name`  = VALUES(`group_name`);

INSERT IGNORE INTO email_templates (`name`, `slug`, `subject`, `body_html`, `body_text`, `category`, `variables`, `is_active`)
VALUES (
  'Monthly invoices (one email per customer)',
  'invoice_bundle',
  'Your {period_label} invoices from {company_name} — {invoice_count} invoice(s), {total_due} due',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Your invoices for {period_label}</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">{invoice_count} invoice(s)</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>
<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Thank you for your business. Your invoices for {period_label} are attached, one PDF per unit. A summary is below.</p>
{invoice_table}
<p style="margin:24px 0 0;font-size:15px;line-height:1.6;color:#374151;">Total due: <strong>{total_due}</strong></p>
<p style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#374151;">If you have any questions, reply to this email or call us at {company_phone}.</p>
<p style="margin:24px 0 0;font-size:15px;line-height:1.6;color:#374151;">{sender_name}<br>{company_name}</p>',
  'Your invoices for {period_label}

Dear {customer_name},

Thank you for your business. Your {invoice_count} invoice(s) for {period_label} are attached.

{invoice_list_text}

Total due: {total_due}

Questions? Reply to this email or call {company_phone}.

{sender_name}
{company_name}',
  'invoice',
  '["customer_name","period_label","invoice_count","invoice_table","invoice_list_text","total_due","company_name","company_phone","sender_name"]',
  1
);
