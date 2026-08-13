-- ============================================================
-- S-MILEAGE-COLS — backfill invoice_line_items.mileage_*
--
-- invoice_line_items has dedicated mileage_distance / mileage_rate /
-- mileage_unit columns, but InvoiceGenerator's line-item INSERT is an
-- explicit column whitelist and those three were missing from it. The
-- engine computed them (ff_mileage_line_display) and they were dropped
-- on the way to the DB, so every historical mileage line has NULLs.
--
-- Nothing visibly broke because the SAME three values also reach the
-- whitelisted quantity / unit_price / unit columns, and the customer-
-- facing text is baked into `description`. But the dedicated columns
-- were dead for reporting, and for anything wanting to re-render a
-- distance in the other unit.
--
-- The code fix (whitelist) covers new lines; this backfills old ones
-- FROM THE EXISTING STRUCTURED COLUMNS — deliberately not by regexing
-- the description string, which is a formatted, localised, human label.
--
-- Row selection is self-limiting: `unit IN ('km','miles')` matches only
-- true distance lines (mileage_usage, mileage_estimate). It excludes
--   - mileage_adjustment (unit='adjustment', quantity=1 — a money
--     correction, not a distance), and
--   - mileage_precharge  (unit NULL, quantity is a dollar precharge),
-- either of which would otherwise be recorded as a bogus distance.
--
-- NO AMOUNT IS TOUCHED. This only fills metadata columns that were
-- always meant to hold these values, so it does not alter any invoice
-- total, tax, or balance — D12 immutability is about the money, and the
-- money is untouched.
--
-- Idempotent: the mileage_unit IS NULL guard means re-running is a
-- no-op, and it never overwrites a value the fixed code path wrote.
-- ============================================================

UPDATE invoice_line_items
   SET mileage_distance = quantity,
       mileage_rate     = unit_price,
       mileage_unit     = unit
 WHERE item_type LIKE 'mileage%'
   AND unit IN ('km', 'miles')
   AND mileage_unit IS NULL;
