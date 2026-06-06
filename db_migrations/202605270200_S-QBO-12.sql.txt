-- S-QBO-12 Migration — Invoice modification + void semantics
-- Adds 'voided' to acc_qbo_invoice_map.push_status ENUM.
--
-- WHY: InvoicePusher::pushVoid (new in S-QBO-12) records the post-void
-- mapping state. Without a dedicated 'voided' ENUM value the void path
-- would overload 'pushed' (loses semantic distinction between in-sync-
-- as-created/updated and in-sync-as-voided) or 'skipped_voided' (which
-- means "FF voided; we did NOT push the void" — opposite of what we want).
--
-- Semantic distinction:
--   pushed         — entity exists in QBO, mirrors current FF state (create or update)
--   voided         — entity exists in QBO and is voided (pushed FF void to QBO)
--   skipped_voided — FF entity is voided but we skipped the QBO push (pre-S-QBO-12 behavior)
--
-- Existing 9 values preserved; default 'pending' unchanged. Customer/Vendor
-- map ENUMs intentionally NOT extended — they have no void semantics
-- (per D-CV-ENUM-SCOPE locked in S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA).
-- 2026-05-27 | S-QBO-12

ALTER TABLE `acc_qbo_invoice_map`
  MODIFY COLUMN `push_status`
    ENUM(
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
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'pending'
    COMMENT 'D-QBO-11-1 lifecycle states; typed preflight codes per D-QBO-FIXPACK-5; skipped_soft_deleted added in S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE; voided added in S-QBO-12';
