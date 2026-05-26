-- S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE Migration
-- Adds 'skipped_soft_deleted' to acc_qbo_invoice_map.push_status ENUM.
--
-- WHY: InvoicePusher::pushImpl gate 4 (line 148-150) handles the
-- soft-deleted-invoice case by returning success=true + status='skipped_soft_deleted'
-- without recording the skip — because the ENUM didn't include that value.
-- Per S-QBO-WORKER-FALSE-COMPLETE-DIAGNOSIS, this silent-success path was the
-- mechanism behind queue#180's `status='completed'` + no mapping row + no
-- sync_log entry. This session extends recordSkipped to write the typed value,
-- which requires the ENUM to actually accept it.
--
-- Existing 8 values preserved; default 'pending' unchanged. Customer/Vendor
-- map tables NOT touched in this session — they lack a push_status column
-- entirely (architectural decision queued for S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA).
-- 2026-05-27 | S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE

ALTER TABLE `acc_qbo_invoice_map`
  MODIFY COLUMN `push_status`
    ENUM(
      'pending',
      'pushed',
      'failed',
      'skipped_voided',
      'skipped_by_mode',
      'skipped_soft_deleted',
      'failed_preflight',
      'failed_preflight_field_too_long',
      'failed_preflight_currency_mismatch'
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'pending'
    COMMENT 'D-QBO-11-1 lifecycle states; typed preflight codes per D-QBO-FIXPACK-5; skipped_soft_deleted added in S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE';
