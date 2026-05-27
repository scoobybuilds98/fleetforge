-- S-QBO-18 Migration
-- Bill push (FF → QBO) — Phase QBO-8 / 1 of 2
--
-- Creates `acc_qbo_bill_map` to track FF→QBO bill push state. Schema mirrors
-- `acc_qbo_invoice_map` (S-QBO-11) with bill-specific deltas:
--   - ff_bill_id (NOT NULL UNIQUE; FK to acc_bills ON DELETE CASCADE)
--   - qbo_bill_id (NULL until first push; UNIQUE; NULL-multi-OK per InnoDB)
--   - qbo_vendor_id snapshot (vendors aren't customers — different ref column
--     to match QBO Bill.VendorRef shape; useful for drift detection)
--   - No ff_engine_version (bills don't have engine-version dispatch like
--     invoices' period_independent/holistic split)
--   - push_status ENUM matches invoice_map's WITHOUT
--     failed_preflight_currency_mismatch (no customer to mismatch against
--     at bill-push time — bills are pushed to a vendor; vendor currency
--     handling is separate via CurrencyRef gating per D-QBO-FIXPACK-12)
--     and WITHOUT failed_preflight_field_too_long initially (gate not built
--     in S-QBO-18 v1; can ALTER ENUM later when gate ships)
--   - Adds skipped_voided + skipped_unmapped_void mirroring InvoicePusher
--     post-S-QBO-12 (void semantics)
--
-- Indexes mirror invoice_map: uq_ff_bill + uq_qbo_bill + idx_status +
-- idx_pushed_at. No FK on vendor_id (snapshot only; no integrity required).
--
-- 2026-05-27 | S-QBO-18

CREATE TABLE `acc_qbo_bill_map` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ff_bill_id` int unsigned NOT NULL
    COMMENT 'NOT NULL: bills originate in FF only in S-QBO-18 (D-CPA-5 workflow shift — bill entry moves QBO→FF). QBO-authored bills (rare) handled via S-QBO-26 manual sync.',
  `qbo_bill_id` varchar(50)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'Intuit Bill.Id; NULL until first successful push',
  `qbo_sync_token` varchar(20)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'QBO optimistic-lock token; refreshed on every push/update',
  `qbo_doc_number` varchar(100)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'QBO DocNumber snapshot — vendor''s bill number (vendor_bill_number) when set, else FF bill_number per D-QBO-18-7',
  `qbo_vendor_id` varchar(50)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'QBO VendorRef.value snapshot — drift detection across vendor remapping',
  `qbo_total_amt` decimal(15,2) DEFAULT NULL
    COMMENT 'QBO TotalAmt snapshot — for drift comparison',
  `qbo_balance` decimal(15,2) DEFAULT NULL
    COMMENT 'QBO Balance snapshot at last sync',
  `qbo_status` varchar(30)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'QBO Bill status snapshot (open/paid/etc)',
  `qbo_currency` varchar(3)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'QBO CurrencyRef.value (e.g. CAD/USD)',
  `qbo_exchange_rate` decimal(10,6) DEFAULT NULL
    COMMENT 'QBO ExchangeRate pinned at push time',
  `ff_bill_snapshot_total` decimal(15,2) DEFAULT NULL
    COMMENT 'FF total_amount snapshot at push time — drift baseline',
  `push_status` enum(
    'pending',
    'pushed',
    'voided',
    'failed',
    'skipped_voided',
    'skipped_unmapped_void',
    'skipped_by_mode',
    'failed_preflight'
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
    COMMENT 'D-QBO-18-* lifecycle states. skipped_voided fires when bill status=void and push attempted (D-QBO-18-4). skipped_unmapped_void mirrors InvoicePusher (D-QBO-12-5) when FF voided before first push. failed_preflight covers all preflight gate failures in v1; typed sub-states can be added via ALTER ENUM in follow-up sessions if needed.',
  `push_error` text
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    COMMENT 'Last error message for failed/failed_preflight states',
  `pushed_at` datetime DEFAULT NULL
    COMMENT 'Most recent successful push timestamp',
  `last_synced_at` datetime DEFAULT NULL
    COMMENT 'Most recent state mutation (push, gate fail, skip)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_bill` (`ff_bill_id`)
    COMMENT 'One mapping row per FF bill; enforces idempotency of pushCreate',
  UNIQUE KEY `uq_qbo_bill` (`qbo_bill_id`)
    COMMENT 'No two FF bills share a QBO Bill.Id; NULL-multi-OK per InnoDB',
  KEY `idx_status` (`push_status`),
  KEY `idx_pushed_at` (`pushed_at`),
  CONSTRAINT `fk_qbo_bill_map_ff`
    FOREIGN KEY (`ff_bill_id`) REFERENCES `acc_bills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-8 S-QBO-18: FF→QBO bill push state tracking. Mirrors acc_qbo_invoice_map (S-QBO-11) shape with bill-specific deltas (vendor instead of customer; no engine_version; reduced ENUM for absent preflight sub-states in v1).';
