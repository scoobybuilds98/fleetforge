-- ════════════════════════════════════════════════════════════════════════════
-- S-HOURS-EST-DAILY — estimated daily engine hours + running true-up.
--
-- The engine-hours parallel of S-MILEAGE-EST-DAILY (202607030001). Adds a
-- per-lease "estimated engine hours per day" input that bills an ESTIMATE line
-- (days × per-day × hourly_rate) on every rental invoice, then trues up against
-- the actual engine hours (engine_hours_at_end − engine_hours_at_start) at close.
--
-- Simpler than mileage: engine hours have no unit duality (no km/miles mirrors)
-- and no precharge — so it is a SINGLE decimal column plus three new line types.
--
-- All steps INFORMATION_SCHEMA-guarded → idempotent (re-runnable).
-- ════════════════════════════════════════════════════════════════════════════

SET @schema = DATABASE();

-- ── 1. invoice_line_items.item_type ENUM += hours_estimate/adjustment/credit ──
-- ENUM clamp trap (project_rate_method_enum_clamp_trap): the new line types MUST
-- be members of the enum at every write site (the estimate, the true-up charge,
-- and the true-up credit). Guard on the current COLUMN_TYPE so the MODIFY runs
-- once. Appended at the END so existing ordinal positions are preserved.
SET @has_hours_estimate = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'invoice_line_items'
      AND COLUMN_NAME = 'item_type'
      AND COLUMN_TYPE LIKE '%''hours_estimate''%'
);
SET @stmt = IF(
    @has_hours_estimate = 0,
    "ALTER TABLE invoice_line_items MODIFY COLUMN `item_type` enum('base_rental','mileage_precharge','mileage_adjustment','mileage_credit','insurance','warranty','late_fee','early_return_credit','manual_adjustment','damage','discount','account_credit_applied','other','gps','mileage_usage','mileage_drawdown_credit','base_rental_reconciliation_credit','mileage','hourly_usage','cartage','sweep','wash','fuel','mileage_estimate','hours_estimate','hours_adjustment','hours_credit') COLLATE utf8mb4_unicode_ci NOT NULL",
    "SELECT 'item_type already has hours_estimate' AS info"
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. leases.estimated_engine_hours_per_day ────────────────────────────────
-- decimal(10,2), NOT NULL DEFAULT 0.00 (0 = no estimated-hours billing, exactly
-- like estimated_mileage_per_day). Placed AFTER engine_hours_at_end so the
-- hourly billing columns stay grouped.
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='estimated_engine_hours_per_day');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `estimated_engine_hours_per_day` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'S-HOURS-EST-DAILY: estimated engine/reefer hours run per day. Billed as an estimate each period (days x per-day x hourly_rate) then trued-up against actual (engine_hours_at_end - engine_hours_at_start) at close. 0 = no estimated-hours billing.' AFTER `engine_hours_at_end`",
    "SELECT 'leases.estimated_engine_hours_per_day exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3. credit_notes.source ENUM += 'hours_overpayment' ──────────────────────
-- The credit-overflow cap (InvoiceGenerator) can route an over-estimated
-- hours_credit that exceeds the invoice subtotal to a credit_notes row, exactly
-- as it does for mileage_credit (source 'mileage_overpayment'). The hours true-up
-- subtracts these CNs from its billed-to-date so it never re-credits the overflow.
SET @has_hours_overpayment = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'credit_notes'
      AND COLUMN_NAME = 'source'
      AND COLUMN_TYPE LIKE '%''hours_overpayment''%'
);
SET @stmt = IF(
    @has_hours_overpayment = 0,
    "ALTER TABLE credit_notes MODIFY COLUMN `source` enum('mileage_overpayment','invoice_adjustment','damage_resolution','goodwill','payment_returned','overpayment','other','precharge_refund','base_rental_reconciliation_overflow','hours_overpayment') COLLATE utf8mb4_unicode_ci NOT NULL",
    "SELECT 'credit_notes.source already has hours_overpayment' AS info"
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
