-- S-VENDOR-CURRENCY-COLUMN Migration
-- Add `currency` column to `vendors` table; closes D-QBO-FIXPACK-8 backlog.
--
-- WHY: VendorPusher::buildQboPayload (S-QBO-FIXPACK-2 → D-QBO-FIXPACK-8 Option
-- A) hardcoded `CurrencyRef.value='CAD'` for every vendor push because the
-- `vendors` table had no currency column. Mainland is Canadian so 'CAD' was
-- the universally correct default at the time, but per-row vendor currency
-- is necessary to support US-based vendors (bill payments in USD, FX
-- accounting parity with the customers.currency pattern).
--
-- Column shape mirrors `customers.currency`:
--   ENUM('CAD','USD') NOT NULL DEFAULT 'CAD'
--
-- DEFAULT 'CAD' is correct backfill behavior for all existing rows because:
--   (a) Mainland is Canadian; existing vendors are domestic by default.
--   (b) D-QBO-FIXPACK-8 already established this assumption — any vendor
--       pushed pre-this-migration was recorded in QBO as CAD.
--   (c) Operators can update individual vendor currencies post-migration
--       via the vendors API (create/update endpoints accept currency).
--
-- After this migration:
--   - VendorPusher::buildQboPayload emits `CurrencyRef.value = $ff['currency']`
--     (reads from the row, no more hardcode).
--   - VendorPusher::pushImpl idempotency-replay mismatch warning compares
--     QBO CurrencyRef against $ff['currency'] (not hardcoded 'CAD').
--   - api/v1/vendors/create.php + update.php accept `currency` ENUM input.
--
-- The vendor admin UI (create/edit forms) does NOT add a currency selector
-- in this session — deferred to S-VENDOR-UI-CURRENCY-SELECTOR follow-up.
-- Operators can set per-vendor currency via API or DB UPDATE in the interim.
-- 2026-05-27 | S-VENDOR-CURRENCY-COLUMN

-- MySQL ALTER syntax order: column_definition (NOT NULL / DEFAULT / COMMENT)
-- precedes the position specifier (AFTER / FIRST). Putting COMMENT after
-- AFTER is a syntax error.
ALTER TABLE `vendors`
  ADD COLUMN `currency` ENUM('CAD','USD')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'CAD'
    COMMENT 'Vendor billing currency (CAD or USD). Default CAD matches D-QBO-FIXPACK-8 pre-migration assumption. Mirrors customers.currency ENUM. Added in S-VENDOR-CURRENCY-COLUMN closing D-QBO-FIXPACK-8 backlog.'
    AFTER `notes`;
