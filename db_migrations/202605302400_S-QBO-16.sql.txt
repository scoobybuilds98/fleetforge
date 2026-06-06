-- ============================================================================
-- S-QBO-16 — Credit Memo Push (FF → QBO) (Phase QBO-7 / 1 of 2)
-- ============================================================================
--
-- First entity Pusher since S-QBO-19. Sibling of S-QBO-11 invoice push:
-- FF credit_notes → QBO CreditMemo entity. Creates acc_qbo_credit_memo_map
-- to track FF→QBO push state (mirrors acc_qbo_invoice_map shape).
--
-- Spec §8.6: credit memo pushes as the native QBO CreditMemo entity; QBO
-- derives the JE from it (which is why source_type='credit_note' is in the
-- JournalEntryPusher BRIDGE_DERIVED_SOURCE_TYPES skip list — D-QBO-21-1 —
-- so the FF two-step credit JE does NOT double-push as a raw JE).
--
-- K-22 catches at pre-flight:
--   1. credit_notes is HEADER-ONLY (single amount; no credit_note_lines
--      table — only credit_notes + credit_note_applications). The QBO
--      CreditMemo therefore gets a SINGLE Line with SalesItemLineDetail.
--   2. credit_notes.source DB ENUM has 9 values
--      (mileage_overpayment, invoice_adjustment, damage_resolution,
--       goodwill, payment_returned, overpayment, other, precharge_refund,
--       base_rental_reconciliation_overflow) but api/v1/credit_notes/
--       create.php only validates 6 — the extra 3 (overpayment,
--       precharge_refund, base_rental_reconciliation_overflow) are created
--       by internal flows (InvoiceGenerator auto-create, base-rental
--       reconciliation). CreditMemoPusher::SOURCE_TO_ITEM_TYPE covers all 9.
--   3. credit_notes status flow is active/partially_used/fully_used/expired/
--      void (NOT draft/issued). Push trigger = creation (status='active').
--   4. quickbooks.sync_mode.credit_memo='sync' ALREADY seeded (S-QBO-3) —
--      no seed needed this migration.
--
-- Per D-QBO-16-1: SOURCE_TO_ITEM_TYPE map resolves the single line's
--   ItemRef via acc_qbo_item_map lookup. mileage_overpayment→mileage_credit,
--   precharge_refund→mileage_drawdown_credit, base_rental_reconciliation_
--   overflow→base_rental_reconciliation_credit, invoice_adjustment→
--   manual_adjustment, damage_resolution→damage, goodwill→discount,
--   overpayment→account_credit_applied, payment_returned/other→other.
-- Per D-QBO-16-2: pushCreate only in v1; pushUpdate (apply→LinkedTxn) +
--   pushVoid stubbed → S-QBO-16-UPDATE-FOLLOWUP (F20). Matches the
--   stub-then-implement cadence of S-QBO-11/14/18/19/21.
-- Per D-QBO-CORE-6: tax-override. Credit notes carry NO tax (no tax columns)
--   → TxnTaxDetail.TotalTax='0.00'; line TaxCodeRef = tax_override_code_id.
--
-- This migration: ONE motion — CREATE acc_qbo_credit_memo_map. No ENUM
-- ALTER, no settings seed (sync_mode.credit_memo already present).
--
-- @session  S-QBO-16
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.6 (Credit Memo), §9 (tax-override)
-- @decision D-QBO-16-1 (SOURCE_TO_ITEM_TYPE map → acc_qbo_item_map lookup
--                       for the single CreditMemo line ItemRef),
--           D-QBO-16-2 (pushCreate only; apply/void stubbed → F20),
--           D-QBO-16-3 (acc_qbo_credit_memo_map mirrors acc_qbo_invoice_map
--                       state-machine shape; FK CASCADE on credit_notes)

CREATE TABLE IF NOT EXISTS `acc_qbo_credit_memo_map` (
    `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_credit_note_id`           INT UNSIGNED NOT NULL COMMENT 'credit_notes.id; NOT NULL — credit memos are FF-origin only in S-QBO-16 v1. QBO-authored credit memos handled via S-QBO-26 manual sync.',
    `qbo_credit_memo_id`          VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit CreditMemo.Id; NULL until first successful push',
    `qbo_sync_token`              VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token; refreshed on every push (apply/void in S-QBO-16-UPDATE-FOLLOWUP)',
    `qbo_doc_number`              VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO DocNumber snapshot from FF credit_note_number (CN-CR-YYYY-NNNNN)',
    `qbo_total_amt`               DECIMAL(15,2) DEFAULT NULL COMMENT 'QBO TotalAmt snapshot — drift comparison baseline',
    `qbo_balance`                 DECIMAL(15,2) DEFAULT NULL COMMENT 'QBO Balance snapshot (unapplied credit remaining) at last sync',
    `qbo_status`                  VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO status snapshot',
    `qbo_currency`                VARCHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value (CAD/USD) snapshot',
    `qbo_exchange_rate`           DECIMAL(10,6) DEFAULT NULL COMMENT 'QBO ExchangeRate pinned at push time',
    `qbo_item_type_used`          VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'item_type resolved via SOURCE_TO_ITEM_TYPE map (D-QBO-16-1) for the single line ItemRef — forensic trail',
    `ff_credit_note_snapshot_total` DECIMAL(15,2) DEFAULT NULL COMMENT 'FF credit_notes.amount snapshot at push time — drift baseline',
    `push_status`                 ENUM(
                                      'pending',
                                      'pushed',
                                      'voided',
                                      'failed',
                                      'skipped_voided',
                                      'skipped_by_mode',
                                      'skipped_soft_deleted',
                                      'failed_preflight',
                                      'failed_preflight_field_too_long',
                                      'failed_preflight_currency_mismatch'
                                  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
                                  COMMENT 'Mirrors acc_qbo_invoice_map.push_status (S-QBO-12 shape) — typed preflight codes per D-QBO-FIXPACK-5. voided + skipped_voided reserved for S-QBO-16-UPDATE-FOLLOWUP apply/void path.',
    `push_error`                  TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Last error for failed/failed_preflight states',
    `pushed_at`                   DATETIME DEFAULT NULL COMMENT 'Most recent successful push timestamp',
    `last_synced_at`              DATETIME DEFAULT NULL COMMENT 'Most recent state mutation (push, gate fail, skip)',
    `created_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_credit_note` (`ff_credit_note_id`) COMMENT 'One mapping row per FF credit note; enforces idempotency of pushCreate',
    UNIQUE KEY `uq_qbo_credit_memo` (`qbo_credit_memo_id`) COMMENT 'No two FF credit notes share a QBO CreditMemo.Id; NULL-multi-OK per InnoDB',
    KEY `idx_status` (`push_status`),
    KEY `idx_pushed_at` (`pushed_at`),
    CONSTRAINT `fk_qbo_credit_memo_map_ff` FOREIGN KEY (`ff_credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-7 S-QBO-16: FF→QBO credit memo push state. Mirrors acc_qbo_invoice_map shape with credit-memo deltas (qbo_item_type_used forensic column for the SOURCE_TO_ITEM_TYPE resolution per D-QBO-16-1). sync_mode.credit_memo already seeded S-QBO-3.';
