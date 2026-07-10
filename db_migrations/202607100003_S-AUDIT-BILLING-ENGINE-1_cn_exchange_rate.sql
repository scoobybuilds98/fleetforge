-- ============================================================================
-- S-AUDIT-BILLING-ENGINE-1 — credit_notes.exchange_rate_to_cad
--
-- Part of the FX-correct GL work (#21, operator: "full spec — CAD + FX legs").
-- credit_notes carried a currency but NO frozen exchange rate, so the bridge
-- could not convert a USD credit note's 2060 liability to CAD at issue/apply/
-- void time. Mint sites now freeze the rate from the source document (invoice
-- or payment); AutoEntryBridge falls back through source_invoice → source_
-- payment → current exchange_rates for legacy rows (dev has ZERO USD CNs today
-- — no backfill needed).
--
-- Idempotent: INFORMATION_SCHEMA guard (MySQL 8 has no ADD COLUMN IF NOT EXISTS).
-- ============================================================================

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'credit_notes'
      AND COLUMN_NAME  = 'exchange_rate_to_cad'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `credit_notes` ADD COLUMN `exchange_rate_to_cad` DECIMAL(10,6) NULL COMMENT ''S-AUDIT-BILLING-ENGINE-1: frozen CAD conversion rate for USD credit notes (from the source invoice/payment at mint). NULL for CAD.'' AFTER `currency`',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
