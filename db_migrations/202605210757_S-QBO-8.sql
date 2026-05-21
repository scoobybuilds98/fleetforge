-- ============================================================
-- S-QBO-8 — acc_qbo_account_map.
--
-- Chart of Accounts mapping table. Pulls QBO Account entities via
-- /query and links them to FF acc_accounts rows. Unlike customer
-- and vendor mappings (S-QBO-5/6/7), this is PULLER-ONLY per
-- D-QBO-8-1 — accountant owns the COA structure in QBO, FF mirrors.
-- No corresponding Pusher/Enqueuer classes.
--
-- K-22 catches surfaced during pre-flight (silently applied):
--   - FF table is `acc_accounts` (NOT `chart_of_accounts` as the
--     prompt assumed). All FK + queries adjusted accordingly.
--   - FF column is `code` (NOT `account_code`).
--   - FF column is `account_type` (NOT `acct_type`).
--   - FF column is `account_subtype` (NOT `acct_subtype`).
--   - FF has no `deleted_at` on acc_accounts; uses `is_active`.
--   - account_type ENUM is 8 lowercase values:
--       asset / liability / equity / revenue / cost_of_revenue
--       / operating_expense / other_income / other_expense
--     (Prompt assumed 5-value Title-Case Asset/Liability/...
--      The richer 8-value granularity actually MATCHES QBO's
--      AccountType taxonomy 1:1 — see AccountMatcher type map.)
--
-- Schema mirrors the customer/vendor mapping table state-machine
-- pattern (D-QBO-5) with account-specific additions:
--   - qbo_account_type / qbo_account_subtype — QBO's taxonomy
--   - qbo_fully_qualified_name — QBO hierarchy path
--   - qbo_classification — QBO's optional Classification field
--   - qbo_current_balance — pulled for display only (FF GL is canon)
--   - is_critical + critical_reason — bridge-account flag (D-QBO-8-2)
--   - match_confidence ENUM extended with 'exact_code' + 'exact_name'
--     (code match is a stronger signal than name for accounts;
--      AcctNum / account_code is standardized while names vary)
--
-- Bridge-account validator (D-QBO-8-2): AccountValidator marks
-- critical accounts based on FF heuristics (is_system=1 +
-- code-pattern + account_type), then unmappedCritical() lists
-- bridge accounts that block downstream invoice push (S-QBO-11).
--
-- @session   S-QBO-8
-- @date      2026-05-21
-- @decisions D-QBO-8-1 (Puller-only — accountant owns COA in QBO,
--                       no AccountPusher/AccountEnqueuer/sync_mode.account),
--            D-QBO-8-2 (bridge-account validator with is_critical
--                       column + assertReadyForInvoicePush gate
--                       for future S-QBO-11),
--            D-QBO-8-3 (auto-match cascade respects acct_type
--                       compatibility — no cross-type matches),
--            D-QBO-8-4 (4-state mapping_status + is_critical
--                       independent column; bridge accounts can
--                       be flagged regardless of mapping state)
-- ============================================================

START TRANSACTION;

CREATE TABLE `acc_qbo_account_map` (
  `id`                       int unsigned                  NOT NULL AUTO_INCREMENT,
  `ff_account_id`            int unsigned                           DEFAULT NULL COMMENT 'NULL = qbo_only state (QBO account with no FF link)',
  `qbo_account_id`           varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit Account.Id; NULL = ff_only state',
  `qbo_sync_token`           varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token; refreshed on every pull',
  `qbo_name`                 varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Snapshot of QBO Account.Name at last sync',
  `qbo_fully_qualified_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO FullyQualifiedName — includes parent path',
  `qbo_account_type`         varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO AccountType (Asset/Liability/Income/Expense/etc.)',
  `qbo_account_subtype`      varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO AccountSubType (AccountsReceivable/Cash/etc.)',
  `qbo_classification`       varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Classification field (optional grouping)',
  `qbo_account_number`       varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO AcctNum — the QBO-side account code',
  `qbo_active`               tinyint(1)                             DEFAULT NULL COMMENT 'Mirror of QBO Account.Active flag',
  `qbo_current_balance`      decimal(15,2)                          DEFAULT NULL COMMENT 'QBO CurrentBalance at last pull (display only)',
  `mapping_status`           enum('mapped','ff_only','qbo_only','ignored') NOT NULL DEFAULT 'qbo_only',
  `match_confidence`         enum('exact_code','exact_name','high','medium','low','manual') DEFAULT NULL COMMENT 'D-QBO-8-3: exact_code=AcctNum match (highest signal); exact_name=normalized name+type match; high=Levenshtein+type; medium=subtype+token; low=singleton; manual=operator override',
  `is_critical`              tinyint(1)                    NOT NULL DEFAULT 0     COMMENT 'D-QBO-8-2: bridge-account flag — set by AccountValidator::markCriticalAccounts based on FF heuristics (is_system + code pattern + account_type)',
  `critical_reason`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Human-readable why this account is critical (e.g. "Accounts Receivable", "GST/HST Payable")',
  `match_notes`              text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_synced_at`           datetime                               DEFAULT NULL COMMENT 'Most recent successful round-trip with QBO',
  `last_pull_at`             datetime                               DEFAULT NULL,
  `created_at`               datetime                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               datetime                      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by_user_id`       int unsigned                           DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_account`     (`ff_account_id`),
  UNIQUE KEY `uq_qbo_account`    (`qbo_account_id`),
  KEY `idx_status`               (`mapping_status`),
  KEY `idx_critical`             (`is_critical`, `mapping_status`) COMMENT 'Bridge-account validator lookup — unmappedCritical()',
  KEY `idx_acct_type`            (`qbo_account_type`),
  KEY `idx_last_synced`          (`last_synced_at`),
  CONSTRAINT `fk_qbo_acct_map_ff`
    FOREIGN KEY (`ff_account_id`) REFERENCES `acc_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qbo_acct_map_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
