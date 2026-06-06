-- S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA Migration
-- Adds push state infrastructure to acc_qbo_customer_map +
-- acc_qbo_vendor_map mirroring acc_qbo_invoice_map's pattern.
--
-- WHY: Per S-QBO-WORKER-FALSE-COMPLETE-DIAGNOSIS, the silent
-- false-complete bug class on CustomerPusher + VendorPusher skip
-- branches couldn't be closed at the Pusher layer (S-QBO-PUSHER-SKIP-
-- RECORD-FIX-INVOICE) because neither map table had a push_status
-- column. This migration adds the columns; the matching Pusher refactor
-- in the same commit wires recordSkipped/recordSuccessfulPush/
-- recordPushFailure helpers through them.
--
-- ENUM scope per locked planning decision (D-CV-ENUM-SCOPE):
--   pending, pushed, failed, skipped_by_mode, skipped_soft_deleted,
--   failed_preflight, failed_preflight_field_too_long
-- Excluded vs invoice_map:
--   - skipped_voided (no void semantics for customer/vendor entities)
--   - failed_preflight_currency_mismatch (currency mismatch is
--     invoice-specific per D-QBO-11-3)
--
-- Column ordering mirrors invoice_map: push_status → push_error → pushed_at.
-- All 3 columns positioned AFTER mapping_status so the push-state
-- block sits next to its link-state cousin.
--
-- Existing rows default to push_status='pending' which is semantically
-- correct: no FF→QBO push has been attempted yet via the new recording
-- layer (any prior pushes happened via the mapping_status='mapped'
-- snapshot path which doesn't touch push_status).
-- 2026-05-27 | S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA

ALTER TABLE `acc_qbo_customer_map`
  ADD COLUMN `push_status`
    ENUM(
      'pending',
      'pushed',
      'failed',
      'skipped_by_mode',
      'skipped_soft_deleted',
      'failed_preflight',
      'failed_preflight_field_too_long'
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'pending'
    COMMENT 'D-QBO-11-1-pattern lifecycle states for customer push; D-CV-ENUM-SCOPE excludes skipped_voided + failed_preflight_currency_mismatch'
    AFTER `mapping_status`,
  ADD COLUMN `push_error` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    DEFAULT NULL
    COMMENT 'Last error message for failed/failed_preflight states'
    AFTER `push_status`,
  ADD COLUMN `pushed_at` DATETIME
    DEFAULT NULL
    COMMENT 'Most recent successful push timestamp'
    AFTER `push_error`,
  ADD KEY `idx_push_status` (`push_status`);

ALTER TABLE `acc_qbo_vendor_map`
  ADD COLUMN `push_status`
    ENUM(
      'pending',
      'pushed',
      'failed',
      'skipped_by_mode',
      'skipped_soft_deleted',
      'failed_preflight',
      'failed_preflight_field_too_long'
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'pending'
    COMMENT 'D-QBO-11-1-pattern lifecycle states for vendor push; D-CV-ENUM-SCOPE excludes skipped_voided + failed_preflight_currency_mismatch'
    AFTER `mapping_status`,
  ADD COLUMN `push_error` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    DEFAULT NULL
    COMMENT 'Last error message for failed/failed_preflight states'
    AFTER `push_status`,
  ADD COLUMN `pushed_at` DATETIME
    DEFAULT NULL
    COMMENT 'Most recent successful push timestamp'
    AFTER `push_error`,
  ADD KEY `idx_push_status` (`push_status`);
