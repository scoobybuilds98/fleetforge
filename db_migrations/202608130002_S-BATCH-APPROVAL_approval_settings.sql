-- ============================================================
-- S-BATCH-APPROVAL — approval workflow settings
--
-- These land in the EXISTING 'invoices' settings group, which
-- app/admin/settings/index.php already renders generically
-- (Settings → General → "Invoices & Billing"): any row with a
-- label gets a labelled control and a working save form. No new
-- settings tab, and therefore no new settings_* permission module
-- that every role would need a default for (D-PERM-EXPAND-4) and
-- that would force yet another re-login.
--
-- Idempotent: ON DUPLICATE KEY UPDATE touches only label/
-- description/value_type so re-running never clobbers an operator's
-- chosen value.
-- ============================================================

INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`)
VALUES
  ('invoices.approval_required', '0', 'boolean', 'invoices',
   'Require approval before batch billing',
   'When enabled, the Batch Invoicing page cannot generate invoices directly — a run must be submitted and approved first. Single-invoice creation and the monthly cron are unaffected.',
   0, 0),

  ('invoices.approval_allow_self', '1', 'boolean', 'invoices',
   'Allow self-approval of batch runs',
   'When disabled, whoever submitted a batch run cannot approve it — a second person holding the Invoices "approve" permission must sign off (two-eyes). Super admins are NOT exempt.',
   0, 0)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `value_type`  = VALUES(`value_type`),
  `group_name`  = VALUES(`group_name`);
