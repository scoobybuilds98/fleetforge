-- ============================================================================
-- S-QBO-BILL-ITC-TAX-RATE — ITC tax-rate mapping for bill push (closes F9)
-- ============================================================================
--
-- v1 BillPusher emits the tax-override pattern (every line TaxCodeRef='NON' +
-- header TxnTaxDetail.TotalTax via bcmath) — consistent + correct, but it does
-- NOT expose the recoverable-GST/HST Input Tax Credit (ITC) as a per-RATE tax
-- line in QBO (spec §8.8 shows TxnTaxDetail.TaxLine[].TaxLineDetail.TaxRateRef).
--
-- F9 adds the OPT-IN per-rate path, GATED default-off so the proven override
-- emission is completely untouched (D-QBO-BILL-ITC-1):
--   - quickbooks.bill.tax_mode = 'override' (default) | 'per_rate'
--   - acc_qbo_tax_rate_map maps an FF tax component (gst/pst/hst) → a QBO
--     TaxRate.Id (operator-configured, like quickbooks.tax_override_code_id).
--
-- BillPusher::buildBillTaxDetail() returns the EXACT current override shape
-- when tax_mode!='per_rate'. Under 'per_rate' it emits TaxLine[] per non-zero
-- mapped component — and FALLS BACK to override if any non-zero component is
-- unmapped (never ships a partial/incorrect tax detail).
--
-- The QBO TaxRate PULL (to discover the real TaxRate.Ids) needs a live QBO
-- connection, so it is verified at cutover — until then the operator enters the
-- ids manually (the map is populated like tax_override).
--
-- TWO motions: CREATE acc_qbo_tax_rate_map + seed quickbooks.bill.tax_mode.
-- MIGRATE COUNT: 86 → 87.
--
-- @session  S-QBO-BILL-ITC-TAX-RATE
-- @closes   F9
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.8 (Bill tax / ITC)
-- @decision D-QBO-BILL-ITC-1 (gated default-off; override path untouched; per-rate
--               opt-in falls back to override on any unmapped non-zero component)

CREATE TABLE IF NOT EXISTS `acc_qbo_tax_rate_map` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_tax_component`  ENUM('gst','pst','hst') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FF tax component this QBO TaxRate represents (the recoverable ITC rate for GST/HST; PST where applicable).',
    `qbo_tax_rate_id`   VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit TaxRate.Id — operator-mapped (manual entry until the cutover TaxRate pull). NULL = unmapped.',
    `qbo_tax_rate_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO TaxRate.Name snapshot for operator legibility.',
    `qbo_tax_percent`   DECIMAL(7,4) DEFAULT NULL COMMENT 'QBO TaxRate.RateValue snapshot (e.g. 5.0000 for GST 5%).',
    `mapping_status`    ENUM('mapped','unmapped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unmapped' COMMENT 'mapped = qbo_tax_rate_id set + usable by the per_rate bill-tax path.',
    `notes`             VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_tax_component` (`ff_tax_component`) COMMENT 'One QBO TaxRate mapping per FF tax component.',
    KEY `idx_status` (`mapping_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='F9 S-QBO-BILL-ITC-TAX-RATE: FF tax component → QBO TaxRate.Id for the opt-in per-rate bill-tax (ITC) emission. Default-off (quickbooks.bill.tax_mode=override).';

INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`) VALUES
    ('quickbooks.bill.tax_mode', 'override', 'string', 'quickbooks',
     'Bill tax emission mode',
     'override (default) = every bill line TaxCodeRef=NON + header TotalTax via bcmath (the proven path). per_rate = emit TxnTaxDetail.TaxLine[] per mapped FF tax component (exposes the GST/HST ITC as a QBO tax rate line). per_rate falls back to override on any unmapped non-zero component. D-QBO-BILL-ITC-1.');
