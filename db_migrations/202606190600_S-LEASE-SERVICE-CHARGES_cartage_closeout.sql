-- S-LEASE-SERVICE-CHARGES: one-time service charges on leases.
--   cartage — entered at lease creation (delivery charge); bills once on the
--             first invoice. Manual amount, no global default.
--   sweep / wash / fuel — entered at lease close; bill on the final invoice.
--             sweep/wash are flat (defaults $30 / $120); fuel is gallons ×
--             a per-gallon rate (default $13.00/gal). All operator-adjustable.
-- All steps INFORMATION_SCHEMA / ON-DUPLICATE guarded → idempotent on re-run.

SET @schema = DATABASE();

-- ── 1. invoice_line_items.item_type ENUM += the 4 charge types ──────────
-- ENUM clamp trap (project_rate_method_enum_clamp_trap): every new line type
-- must be an enum member at the write site. Guard on the current COLUMN_TYPE.
SET @needs_types = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'invoice_line_items'
      AND COLUMN_NAME = 'item_type'
      AND COLUMN_TYPE LIKE '%''cartage''%'
);
SET @stmt = IF(
    @needs_types = 0,
    "ALTER TABLE invoice_line_items MODIFY COLUMN `item_type` enum('base_rental','mileage_precharge','mileage_adjustment','mileage_credit','insurance','warranty','late_fee','early_return_credit','manual_adjustment','damage','discount','account_credit_applied','other','gps','mileage_usage','mileage_drawdown_credit','base_rental_reconciliation_credit','mileage','hourly_usage','cartage','sweep','wash','fuel') COLLATE utf8mb4_unicode_ci NOT NULL",
    "SELECT 'item_type already has service-charge types' AS info"
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. leases.cartage_amount + cartage_billed_at ───────────────────────
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='cartage_amount');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `cartage_amount` decimal(10,2) DEFAULT NULL COMMENT 'S-LEASE-SERVICE-CHARGES: one-time delivery (cartage) charge entered at lease creation. Bills once on the first invoice. NULL/0 = none.' AFTER `engine_hours_at_end`",
    "SELECT 'leases.cartage_amount exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='cartage_billed_at');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `cartage_billed_at` datetime DEFAULT NULL COMMENT 'S-LEASE-SERVICE-CHARGES: stamped when the cartage line is emitted, so cartage bills exactly once (on the first invoice).' AFTER `cartage_amount`",
    "SELECT 'leases.cartage_billed_at exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3. Global default settings (group 'billing') ───────────────────────
-- ON DUPLICATE touches only label/description (NEVER `value`) so re-apply never
-- clobbers an operator-tuned amount (same idiom as lease.minimum_billing_days).
INSERT INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `label`, `description`)
VALUES
  ('lease.sweep_charge_default', '30.00', 'decimal', 'billing', 0, 0,
   'Sweep Charge (default)',
   'Default flat sweep-out charge pre-filled on the lease close form. Operator-adjustable or skippable per close.'),
  ('lease.wash_charge_default', '120.00', 'decimal', 'billing', 0, 0,
   'Wash Charge (default)',
   'Default flat wash-out charge pre-filled on the lease close form. Operator-adjustable or skippable per close.'),
  ('lease.fuel_rate_per_gallon', '13.00', 'decimal', 'billing', 0, 0,
   'Fuel Charge ($/gallon)',
   'Per-gallon fuel rate. At close the operator enters gallons; the invoice bills gallons × this rate.')
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);
