-- ============================================================================
-- 202608070001_S-CUSTOMER-NOTIFICATIONS_audience_and_settings.sql
--
-- S-CUSTOMER-NOTIFICATIONS — the customer email / reminder control centre
-- (Settings → Customer Emails) + Task 1 (stop customer compliance emails).
--
-- Two things:
--   1. customer_notification_audience — per-reminder customer targeting. One row
--      per (reminder_key, customer_id): mode='include' is the allow-list used by
--      audience_mode='selected'; mode='exclude' is the skip-list used by
--      audience_mode='all_except'; and reminder_key='*' with mode='exclude' is
--      the GLOBAL do-not-email suppression list applied on top of every type.
--   2. Seed the settings rows that drive the module + the Scheduled Jobs toggle
--      for the new dispatcher cron.
--
-- SAFETY / Task 1: every customer reminder ships DISABLED. The engine
-- (lib/Notifications/CustomerReminders.php) falls back to the OFF defaults in
-- config/customer_notifications.php even if these rows are absent, so Task 1
-- (compliance_expiry.enabled='0' → customers stop getting expiry emails incl.
-- insurance) holds from code alone; these rows just make the state explicit and
-- editable in the UI.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE that refreshes
-- only the METADATA (value_type / group_name / label / description), never the
-- `value` — re-running can never flip an operator's chosen settings.
--
-- @session  S-CUSTOMER-NOTIFICATIONS
-- @decision D-GUARD-1 (table added to FLEETFORGE_DATABASE_MASTER.sql)
-- ============================================================================

-- 1. Per-reminder customer audience / suppression.
CREATE TABLE IF NOT EXISTS `customer_notification_audience` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `reminder_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `mode` enum('include','exclude') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'include',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cna_key_customer` (`reminder_key`,`customer_id`),
  KEY `idx_cna_key_mode` (`reminder_key`,`mode`),
  KEY `idx_cna_customer` (`customer_id`),
  CONSTRAINT `fk_cna_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2a. Scheduled Jobs toggle for the new dispatcher cron (mirrors config/cron_jobs.php).
INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `updated_at`)
VALUES
  ('cron.customer_reminders_enabled', '1', 'boolean', 'cron', 'Customer email reminders', 'Dispatcher for customer reminder emails (each type is toggled in Settings → Customer Emails).', 0, NOW())
ON DUPLICATE KEY UPDATE
  `value_type` = VALUES(`value_type`), `group_name` = VALUES(`group_name`),
  `label` = VALUES(`label`), `description` = VALUES(`description`);

-- 2b. Module global settings.
INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `updated_at`)
VALUES
  ('customer_notifications.master_enabled',        '1',                                          'boolean', 'customer_notifications', 'Send customer emails',      'Global master switch for all customer reminder emails.', 0, NOW()),
  ('customer_notifications.respect_portal_optout', '1',                                          'boolean', 'customer_notifications', 'Honor portal opt-outs',     'Skip customers who opted out in their portal notification preferences.', 0, NOW()),
  ('customer_notifications.reply_to',              '',                                           'string',  'customer_notifications', 'Reply-to address',          'Optional Reply-To for customer reminder emails.', 0, NOW()),
  ('customer_notifications.bcc',                   '',                                           'string',  'customer_notifications', 'BCC (operator copy)',       'Optional address(es) BCC''d on every customer reminder.', 0, NOW()),
  ('customer_notifications.send_hour',             '8',                                          'integer', 'customer_notifications', 'Send hour (0-23)',          'Local hour the reminder dispatcher runs.', 0, NOW()),
  ('customer_notifications.send_days',             '["mon","tue","wed","thu","fri","sat","sun"]','json',    'customer_notifications', 'Sending days',              'Weekdays on which reminders may be sent.', 0, NOW()),
  ('customer_notifications.footer_note',           '',                                           'text',    'customer_notifications', 'Email footer note',         'Optional extra line appended to every reminder email.', 0, NOW())
ON DUPLICATE KEY UPDATE
  `value_type` = VALUES(`value_type`), `group_name` = VALUES(`group_name`),
  `label` = VALUES(`label`), `description` = VALUES(`description`);

-- 2c. Per-type enable + audience-mode rows (all OFF; audience defaults to 'all').
--     compliance_expiry.enabled='0' is Task 1 made explicit.
INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `updated_at`)
VALUES
  ('customer_notifications.compliance_expiry.enabled',        '0', 'boolean', 'customer_notifications', 'Compliance expiry — enabled',        'Email customers about expiring compliance documents.', 0, NOW()),
  ('customer_notifications.compliance_expiry.audience_mode',  'all','string', 'customer_notifications', 'Compliance expiry — audience',       'all | selected | all_except', 0, NOW()),
  ('customer_notifications.compliance_expiry.docs',           '{"cvi":true,"registration":true,"mvi":true,"insurance":true}', 'json', 'customer_notifications', 'Compliance expiry — documents', 'Per-document toggles (cvi/registration/mvi/insurance).', 0, NOW()),
  ('customer_notifications.invoice_due_soon.enabled',         '0', 'boolean', 'customer_notifications', 'Invoice due soon — enabled',         'Remind customers before an invoice is due.', 0, NOW()),
  ('customer_notifications.invoice_due_soon.audience_mode',   'all','string', 'customer_notifications', 'Invoice due soon — audience',        'all | selected | all_except', 0, NOW()),
  ('customer_notifications.invoice_overdue.enabled',          '0', 'boolean', 'customer_notifications', 'Overdue reminder — enabled',         'Chase unpaid invoices after the due date.', 0, NOW()),
  ('customer_notifications.invoice_overdue.audience_mode',    'all','string', 'customer_notifications', 'Overdue reminder — audience',        'all | selected | all_except', 0, NOW()),
  ('customer_notifications.payment_receipt.enabled',          '0', 'boolean', 'customer_notifications', 'Payment receipt — enabled',          'Email a receipt when a payment is recorded.', 0, NOW()),
  ('customer_notifications.payment_receipt.audience_mode',    'all','string', 'customer_notifications', 'Payment receipt — audience',         'all | selected | all_except', 0, NOW()),
  ('customer_notifications.statement.enabled',                '0', 'boolean', 'customer_notifications', 'Monthly statement — enabled',        'Email a monthly account statement.', 0, NOW()),
  ('customer_notifications.statement.audience_mode',          'all','string', 'customer_notifications', 'Monthly statement — audience',       'all | selected | all_except', 0, NOW()),
  ('customer_notifications.lease_ending_soon.enabled',        '0', 'boolean', 'customer_notifications', 'Lease ending — enabled',             'Remind customers before a lease ends.', 0, NOW()),
  ('customer_notifications.lease_ending_soon.audience_mode',  'all','string', 'customer_notifications', 'Lease ending — audience',            'all | selected | all_except', 0, NOW()),
  ('customer_notifications.reservation_pickup.enabled',       '0', 'boolean', 'customer_notifications', 'Reservation pickup — enabled',       'Remind customers before a reservation pickup.', 0, NOW()),
  ('customer_notifications.reservation_pickup.audience_mode', 'all','string', 'customer_notifications', 'Reservation pickup — audience',      'all | selected | all_except', 0, NOW())
ON DUPLICATE KEY UPDATE
  `value_type` = VALUES(`value_type`), `group_name` = VALUES(`group_name`),
  `label` = VALUES(`label`), `description` = VALUES(`description`);
