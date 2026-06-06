-- ============================================================================
-- S-QBO-21 — Journal Entry Push (FF → QBO) (Phase QBO-10 / 1 of 1)
-- ============================================================================
--
-- Creates acc_qbo_journal_entry_map to track FF→QBO journal entry push state.
-- Closes Phase QBO-10 (Journal Entry catch-all sync surface).
--
-- Per QBO_SPEC §8.10: catch-all for any FF JE that doesn't have a higher-
-- level entity equivalent in QBO. Pushable JE classes:
--   - Depreciation runs (DR Depreciation Expense / CR Accumulated Depreciation)
--   - Tax remittance JEs
--   - Year-end closing JEs
--   - Recurring entries (insurance amortization, rent accruals, etc.)
--   - Adjusting JEs from accountant (S-ACCT-AJE workflow)
--   - Reversing entries (auto-generated companions)
--   - Manual JEs from operators
--
-- NON-pushable JEs (rejected at Pusher pushImpl step 2 + Enqueuer gate-0
-- per D-QBO-21-1 defense-in-depth):
--   - source_type IN ('invoice','payment','credit_note','ap_bill','ap_payment')
--     — these are bridge-derived from entity sync; QBO derives the JE itself
--     from those entities; pushing the FF JE would DOUBLE-COUNT.
--   - entry_status != 'posted' (draft / submitted / approved skip)
--
-- Schema mirrors acc_qbo_bill_payment_map (S-QBO-19) shape with JE-
-- specific deltas:
--   - ff_journal_entry_id (acc_journal_entries.id; NOT NULL — FF-origin only)
--   - qbo_journal_entry_id (Intuit JournalEntry.Id; NULL until first push)
--   - qbo_doc_number (DocNumber snapshot from JE-YYYY-NNNNN entry_number)
--   - qbo_total_amt (sum of debits = sum of credits; one side reflects total)
--   - qbo_currency, qbo_exchange_rate (FX comparison)
--   - qbo_private_note (FF source attribution audit drill-down)
--   - push_status ENUM identical to acc_qbo_bill_payment_map (includes
--     typed preflight sub-states for currency_mismatch + field_too_long)
--   - FK CASCADE on acc_journal_entries (defensive — JEs rarely hard-deleted
--     but reversal path is covered by reversal_of_id / reversed_by_id pointers)
--
-- Per D-QBO-21-1 (locked S-QBO-21): bridge-derived source_type filter list
--   per QBO_SPEC §8.10 verbatim: 5 FF-ENUM names that QBO derives JEs from
--   automatically when the parent entity syncs. K-22 trap: prompt-arc named
--   these QBO-style (bill / credit_memo / bill_payment) but FF ENUM uses
--   ap_bill / credit_note / ap_payment.
-- Per D-QBO-21-2: PostingType mapping — debit-line emits 'Debit'; credit-
--   line emits 'Credit'; Amount = abs(populated column).
-- Per D-QBO-21-3: Per-line AccountRef from acc_qbo_account_map lookup on
--   acc_journal_entry_lines.account_id; throws on unmapped line account.
-- Per D-QBO-21-4: DocNumber from acc_journal_entries.entry_number
--   (JE-YYYY-NNNNN format = 12 chars; well under QboFieldLimits::
--   INVOICE_DOC_NUMBER_MAX=21 shared limit; gate is defense-in-depth).
-- Per D-QBO-21-5: pushUpdate stubbed → S-QBO-21-UPDATE-FOLLOWUP matching
--   S-QBO-11/14/18/19 stub-then-implement pattern.
-- Per D-QBO-21-6: Header currency from acc_journal_entries.currency +
--   D-QBO-FIXPACK-12 multi_currency_enabled gating. No per-line currency
--   loop — JE is single-currency at header level by FF design.
-- Per D-QBO-21-7: PrivateNote includes FF entry_number + source attribution
--   (source_type=manual entry_id=X) for QBO-side audit drill-down.
--
-- @session  S-QBO-21
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.10 (Journal Entry)
-- @decision D-QBO-21-1 (bridge-derived filter per spec §8.10),
--           D-QBO-21-2 (PostingType mapping debit/credit columns),
--           D-QBO-21-3 (per-line AccountRef via acc_qbo_account_map),
--           D-QBO-21-4 (DocNumber from entry_number; 21-char gate),
--           D-QBO-21-5 (pushUpdate stubbed → S-QBO-21-UPDATE-FOLLOWUP),
--           D-QBO-21-6 (header currency + D-QBO-FIXPACK-12 gating),
--           D-QBO-21-7 (PrivateNote source attribution for audit)

CREATE TABLE IF NOT EXISTS `acc_qbo_journal_entry_map` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_journal_entry_id`      INT UNSIGNED NOT NULL COMMENT 'NOT NULL: JE pushes are FF-origin only in S-QBO-21 v1 (Phase QBO-10). QBO-authored JEs handled via S-QBO-26 manual sync or bank-CDC pull (§8.12 for bank-transaction-typed JEs).',
    `qbo_journal_entry_id`     VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit JournalEntry.Id; NULL until first successful push',
    `qbo_sync_token`           VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token; refreshed on every push',
    `qbo_doc_number`           VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO DocNumber snapshot from FF entry_number (JE-YYYY-NNNNN)',
    `qbo_total_amt`            DECIMAL(15,2) DEFAULT NULL COMMENT 'QBO TotalAmt snapshot (sum of debits = sum of credits; balanced) — drift comparison baseline',
    `qbo_currency`             VARCHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value (e.g. CAD/USD) snapshot',
    `qbo_exchange_rate`        DECIMAL(10,6) DEFAULT NULL COMMENT 'QBO ExchangeRate pinned at push time',
    `qbo_txn_date`             DATE DEFAULT NULL COMMENT 'QBO TxnDate snapshot (mirrors FF entry_date)',
    `qbo_private_note`         TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'QBO PrivateNote snapshot (FF entry_number + source attribution per D-QBO-21-7)',
    `ff_je_snapshot_total`     DECIMAL(15,2) DEFAULT NULL COMMENT 'FF balanced-total snapshot at push time (debit sum = credit sum) — drift baseline',
    `push_status`              ENUM('pending','pushed','voided','failed','skipped_voided','skipped_unmapped_void','skipped_by_mode','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'Mirrors acc_qbo_bill_payment_map.push_status (S-QBO-19) — typed sub-states for currency_mismatch + field_too_long applicable here too. Bridge-derived skips use the no-map-row pattern (mirror BillPusher skipped_unmapped_void / PaymentPusher skipped_non_ff_origin) — sync_log captures via error_code; NO map row written per D-QBO-21-1 defense-in-depth.',
    `push_error`               TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Last error for failed/failed_preflight states',
    `pushed_at`                DATETIME DEFAULT NULL COMMENT 'Most recent successful push timestamp',
    `last_synced_at`           DATETIME DEFAULT NULL COMMENT 'Most recent state mutation (push, gate fail, skip)',
    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_journal_entry` (`ff_journal_entry_id`) COMMENT 'One mapping per FF JE; enforces idempotency of pushCreate',
    UNIQUE KEY `uq_qbo_journal_entry` (`qbo_journal_entry_id`) COMMENT 'No two FF JEs share a QBO JournalEntry.Id; NULL-multi-OK per InnoDB',
    KEY `idx_status` (`push_status`),
    KEY `idx_pushed_at` (`pushed_at`),
    CONSTRAINT `fk_qbo_je_map_ff` FOREIGN KEY (`ff_journal_entry_id`) REFERENCES `acc_journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-10 S-QBO-21: FF→QBO journal entry push state tracking. Mirrors acc_qbo_bill_payment_map (S-QBO-19) shape with JE-specific deltas (no doc_number column; PrivateNote snapshot for audit drill-down; balanced-total snapshot). Bridge-derived JEs (source_type IN invoice/payment/credit_note/ap_bill/ap_payment per spec §8.10) skip BOTH Enqueuer gate-0 AND Pusher pushImpl step 2 — no map row written for bridge skips per D-QBO-21-1.';

-- Seed sync_mode setting for journal_entry if not already present
-- (S-QBO-3 seed at 202605202100_S-QBO-3.sql already includes
-- 'quickbooks.sync_mode.journal_entry' = 'queue'; ON DUPLICATE keeps it)
INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `description`) VALUES
    ('quickbooks.sync_mode.journal_entry', 'queue', 'string', 'quickbooks', 0, 0, 'Per D-QBO-3 sync mode for journal entry push: sync (immediate) / queue (worker picks up) / qbo_to_ff (no push) / disabled (skip). Default queue.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
