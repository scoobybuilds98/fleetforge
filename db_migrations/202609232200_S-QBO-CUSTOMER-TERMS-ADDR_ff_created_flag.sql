-- ============================================================
-- S-QBO-CUSTOMER-TERMS-ADDR — who owns a QuickBooks customer's
-- terms and billing address
-- 2026-09-23
--
-- The pre-go-live audit (docs/audits/CLAUDE_2026-09-23_quickbooks.md,
-- B7) found customers pushed to QuickBooks without payment terms and
-- with the main address instead of the billing address. Operator
-- decision (2026-09-23): FleetForge sends terms + billing address only
-- for customers IT creates in QuickBooks; customers linked to the
-- accountant's existing records keep whatever the accountant set (the
-- company file is shared with other businesses).
--
-- A sparse customer update used to send BillAddr for every mapped
-- customer — overwriting the accountant's address on linked records.
-- CustomerPusher now sends address fields on update only when FF
-- created the QuickBooks customer, which this flag records.
--
-- Default 0 = linked / unknown: existing rows are left alone (the safe
-- reading — never overwrite what might be the accountant's record).
-- ============================================================

ALTER TABLE `acc_qbo_customer_map`
  ADD COLUMN `ff_created_in_qbo` tinyint(1) NOT NULL DEFAULT '0'
    COMMENT 'S-QBO-CUSTOMER-TERMS-ADDR: 1 = FleetForge created this QuickBooks customer (FF keeps its address in sync); 0 = linked to an existing QuickBooks record (the accountant''s — FF never overwrites its address or terms)'
    AFTER `match_notes`;
