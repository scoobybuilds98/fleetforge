-- ============================================================================
-- S-QBO-CREDIT-APP-UNAPPLY — credit-application un-apply (closes F27)
-- ============================================================================
--
-- Adds the explicit reversal path for a credit-note application (the inverse of
-- api/v1/credit_notes/apply.php). Carved out of S-QBO-CREDIT-MEMO-APPLY as F27.
--
-- WHY: apply.php applies a credit to an invoice (5 counters + a credit_note_
-- applications row + a DR-2060/CR-1030 GL JE + a QBO zero-dollar Payment). There
-- was no way to UNDO a mistaken application. credit_notes/void.php voids only the
-- REMAINING balance of a credit (it leaves already-applied portions intact by
-- design), so un-applying a specific application needs its own action.
--
-- TWO motions:
--   1. credit_note_applications: append the reversal state (status +
--      reversed_at + reversed_by). Append-only history is preserved — a
--      reversed application is NOT deleted; status flips 'applied'→'reversed'
--      so the audit trail + the QBO map row (for the void) survive.
--   2. acc_qbo_credit_application_map.push_status ENUM += 'voided' — so the
--      QBO Payment void (CreditApplicationPusher::pushVoid) has a terminal
--      state to record (mirrors the pushVoid trio map ENUMs).
--
-- MIGRATE COUNT: 85 → 86.
--
-- @session  S-QBO-CREDIT-APP-UNAPPLY
-- @closes   F27
-- @decision D-QBO-UNAPPLY-1 (un-apply is an explicit reversal of one
--               credit_note_applications row; append-only — status flips, row
--               kept for audit + QBO void), D-QBO-UNAPPLY-2 (the reversal math
--               lives in a testable lib/CreditApplicationReversal service so the
--               apply→reverse counter round-trip is smoke-verified; apply.php
--               untouched), D-QBO-UNAPPLY-3 (QBO side voids the apply Payment via
--               CreditApplicationPusher::pushVoid; push_status ENUM += 'voided')

-- ─── Motion 1: reversal state on credit_note_applications ─────────────────
ALTER TABLE `credit_note_applications`
    ADD COLUMN `status` ENUM('applied','reversed') NOT NULL DEFAULT 'applied'
        COMMENT 'applied = live; reversed = un-applied via credit_notes/unapply.php (F27). Append-only — reversed rows are kept for audit + to drive the QBO Payment void.'
        AFTER `amount_applied`,
    ADD COLUMN `reversed_at` DATETIME DEFAULT NULL COMMENT 'When the application was un-applied (F27).' AFTER `applied_at`,
    ADD COLUMN `reversed_by` INT UNSIGNED DEFAULT NULL COMMENT 'users.id who un-applied (F27).' AFTER `reversed_at`,
    ADD KEY `idx_status` (`status`),
    ADD KEY `idx_reversed_by` (`reversed_by`),
    ADD CONSTRAINT `fk_credit_note_app_reversed_by` FOREIGN KEY (`reversed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ─── Motion 2: 'voided' terminal state on the QBO apply map ───────────────
ALTER TABLE `acc_qbo_credit_application_map`
    MODIFY COLUMN `push_status` ENUM(
        'pending','pushed','failed','skipped_by_mode','failed_preflight','voided'
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
      COMMENT 'Slimmer than credit_memo_map. voided added F27 — terminal state after CreditApplicationPusher::pushVoid deletes the QBO apply Payment.';
