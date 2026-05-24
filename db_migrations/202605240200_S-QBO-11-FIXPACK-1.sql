-- S-QBO-11-FIXPACK-1 Migration
-- Adds two new typed preflight failure statuses to acc_qbo_invoice_map.push_status
-- per D-QBO-FIXPACK-5 (typed preflight status codes).
--
-- New values:
--   failed_preflight_field_too_long    — field-length pre-flight rejection (F3)
--   failed_preflight_currency_mismatch — FF↔QBO currency mismatch (F2)
--
-- Existing values preserved; default 'pending' unchanged.
-- 2026-05-24 | S-QBO-11-FIXPACK-1

ALTER TABLE `acc_qbo_invoice_map`
  MODIFY COLUMN `push_status`
    ENUM(
      'pending',
      'pushed',
      'failed',
      'skipped_voided',
      'skipped_by_mode',
      'failed_preflight',
      'failed_preflight_field_too_long',
      'failed_preflight_currency_mismatch'
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'pending'
    COMMENT 'D-QBO-11-1 lifecycle states; typed preflight codes per D-QBO-FIXPACK-5';
