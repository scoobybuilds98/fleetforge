-- ============================================================
-- S-FA-IMPORT — acc_fixed_assets.is_opening_balance
--
-- The fleet's 166 units were acquired over roughly two decades (model
-- years 2006-2025) and were never tracked in FleetForge. Bringing them
-- onto the books needs a single acquisition_date, and the operator chose
-- 2025-07-01. That date is when the assets ENTERED THE SYSTEM, not when
-- cash left the bank.
--
-- That distinction matters because ReportingService::sumAssetAcquisitions()
-- builds the cash-flow investing section straight off
-- acc_fixed_assets.acquisition_date — it never consults the GL. Without a
-- way to tell "opening balance" apart from "bought this period", the
-- import would report a $5.8M investing outflow in FY2025 for which no
-- cash ever moved, and the statement would stop tying out (production
-- ties out at $0.00 drift today).
--
-- So: is_opening_balance = 1 means "this asset predates the system; its
-- acquisition_date is a bookkeeping convenience, not a cash event."
-- Cash flow excludes these. Everything else — the balance sheet, the
-- payoff analysis, depreciation — treats them as ordinary assets,
-- because for every other purpose they ARE ordinary assets.
--
-- Defaults to 0 so nothing created through the normal UI/API flow is
-- affected, and so existing rows keep their current meaning.
-- ============================================================

ALTER TABLE `acc_fixed_assets`
  ADD COLUMN `is_opening_balance` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'S-FA-IMPORT: 1 = asset predates the system; acquisition_date is a book-entry date, not a cash event. Excluded from cash-flow asset acquisitions.'
    AFTER `acquisition_bill_id`;

-- Cash flow filters on this column across a date range, and the vast
-- majority of rows will be 0, so the flag leads the index.
ALTER TABLE `acc_fixed_assets`
  ADD KEY `idx_fa_opening_balance` (`is_opening_balance`, `acquisition_date`);
