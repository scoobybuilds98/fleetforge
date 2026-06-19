-- S-CRON-TOGGLES: seed the operator on/off switches for business-automation crons
-- (Settings → Intelligence → Scheduled Jobs). Keys mirror config/cron_jobs.php.
-- Each is a boolean settings row with a non-NULL label (required so the settings
-- save/render scope includes it). Defaults are ON ('1') EXCEPT the monthly invoice
-- generator, which ships OFF ('0') per the operator request.
--
-- Idempotent: ON DUPLICATE KEY UPDATE refreshes only the METADATA (label /
-- description / type / group), never the `value` — so re-running can't flip an
-- operator's chosen on/off state.

INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `updated_at`)
VALUES
  ('cron.invoice_generate_monthly_enabled',     '0', 'boolean', 'cron', 'Monthly invoice generation', 'Generates full-month draft invoices for due monthly leases.', 0, NOW()),
  ('cron.invoice_overdue_enabled',              '1', 'boolean', 'cron', 'Mark overdue invoices',       'Flags past-due invoices as overdue.', 0, NOW()),
  ('cron.late_fee_apply_enabled',               '1', 'boolean', 'cron', 'Apply late fees',             'Adds configured late fees to overdue invoices.', 0, NOW()),
  ('cron.accounting_recurring_entries_enabled', '1', 'boolean', 'cron', 'Recurring journal entries',   'Posts scheduled recurring accounting entries.', 0, NOW()),
  ('cron.collections_auto_escalate_enabled',    '1', 'boolean', 'cron', 'Collections auto-escalation', 'Escalates aging collections accounts.', 0, NOW()),
  ('cron.promise_to_pay_check_enabled',         '1', 'boolean', 'cron', 'Promise-to-pay checks',       'Reviews broken promise-to-pay arrangements.', 0, NOW()),
  ('cron.compliance_alerts_enabled',            '1', 'boolean', 'cron', 'Compliance expiry alerts',    'Alerts on expiring CVI / registration / MVI / insurance.', 0, NOW()),
  ('cron.stale_reservations_enabled',           '1', 'boolean', 'cron', 'Stale reservation cleanup',   'Expires abandoned/stale reservations.', 0, NOW()),
  ('cron.health_scores_enabled',                '1', 'boolean', 'cron', 'Customer health scores',      'Recomputes customer health scores.', 0, NOW()),
  ('cron.risk_scores_enabled',                  '1', 'boolean', 'cron', 'Customer risk scores',        'Recomputes customer risk scores.', 0, NOW()),
  ('cron.gps_mileage_sync_enabled',             '1', 'boolean', 'cron', 'GPS mileage sync',            'Syncs GPS odometer / mileage readings.', 0, NOW()),
  ('cron.samsara_sync_enabled',                 '1', 'boolean', 'cron', 'Samsara telematics sync',     'Pulls Samsara vehicle / trailer telematics.', 0, NOW())
ON DUPLICATE KEY UPDATE
  `value_type`  = VALUES(`value_type`),
  `group_name`  = VALUES(`group_name`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);
