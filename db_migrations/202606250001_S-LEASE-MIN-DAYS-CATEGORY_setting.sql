-- ============================================================================
-- 202606250001_S-LEASE-MIN-DAYS-CATEGORY_setting.sql
--
-- S-LEASE-MIN-DAYS-CATEGORY — restrict the short-lease minimum to specific
-- equipment categories (operator request: the 3-day minimum should apply ONLY
-- to chassis, not dry vans / reefers / etc.).
--
-- Seeds a single config row: settings 'lease.minimum_billing_days_categories'
-- = a comma-separated list of equipment_templates.category values the minimum
-- applies to. Default 'chassis'. Read at BILLING time in
-- lib/Billing/InvoiceGenerator::createFromLease — when a lease's equipment
-- category is NOT in this set, the minimum_billing_days floor is forced to 0
-- (the engine's >=2 binding guard then makes it a no-op), so a non-chassis
-- short lease bills its actual days. Resolving at billing time means existing
-- backfilled DRAFT leases auto-correct on regenerate/reclose.
--
-- DATA-ONLY (no DDL): the InvoiceGenerator code already defaults to 'chassis'
-- via settings_get(...,'chassis'), so behaviour is correct even before this
-- runs; this row just makes the category list explicit + operator-editable.
--
-- value_type='string' / group_name='billing' (NOT type/group — Trap 71). is_public=0.
-- ON DUPLICATE KEY UPDATE touches only label/description so a re-apply NEVER
-- clobbers an operator-tuned `value`. Idempotent.
-- ============================================================================

INSERT INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `label`, `description`)
VALUES
  ('lease.minimum_billing_days_categories', 'chassis', 'string', 'billing', 0, 0,
   'Minimum Billing Days — applies to categories',
   'Comma-separated equipment categories (e.g. chassis,combo) the short-lease minimum-billing-days floor applies to. A lease whose equipment category is not listed bills its actual days regardless of the minimum. Blank = the minimum applies to nothing. Default: chassis.')
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);
