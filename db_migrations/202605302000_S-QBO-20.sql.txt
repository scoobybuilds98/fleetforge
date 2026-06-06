-- ============================================================================
-- S-QBO-20 — Bank Account Mapping + Read-Only CDC Mirror (Phase QBO-9 / 1 of 1)
-- ============================================================================
--
-- Closes Phase QBO-9 (Banking). Exception #2 per D-QBO-CORE-2 — bank
-- reconciliation lives in QBO; FF mirrors transactions read-only for
-- in-FF drift detection + audit trail.
--
-- Direction: QBO → FF only. Rows tagged source='qbo_cdc' + is_readonly=1
-- are observational copies; they are EXCLUDED from FF-side reconciliation
-- UI flows (which would double-count if they tried to reconcile a mirror
-- row that QBO is already reconciling on its side).
--
-- Four schema changes:
--   1. CREATE acc_qbo_bank_account_map       — FF acc_bank_accounts ↔ QBO bank
--                                              account (one-time-import per
--                                              AccountMatcher / S-QBO-8 pattern).
--                                              Direct mapping; alternate path to
--                                              the gl_account_id chain pivot used
--                                              by S-QBO-19 BillPaymentPusher.
--   2. CREATE acc_qbo_bank_transaction_map   — per-transaction mapping FF mirror
--                                              row ↔ QBO transaction; composite
--                                              {entity}:{id} key handles QBO ID
--                                              entity-scoping (Purchase #5 !=
--                                              Deposit #5).
--   3. ALTER acc_bank_transactions:
--        - source ENUM extended with 'qbo_cdc' (existing values preserved).
--        - is_readonly TINYINT(1) added; qbo_cdc rows MUST set is_readonly=1.
--        - qbo_bank_txn_id VARCHAR(64) cross-reference column + idx.
--   4. INSERT 4 quickbooks.banking.* settings (cron config + first-run safety).
--
-- Per D-QBO-20-1: one-time-import pattern mirrors AccountMatcher / S-QBO-8.
--   FF acc_bank_accounts.id is the FF side (FF-native + survives QBO account
--   remapping); QBO Account.Id with AccountType IN ('Bank','CreditCard') is
--   the QBO side. Direct 1:1 mapping enforced by uq_ff + uq_qbo.
-- Per D-QBO-20-2: per-transaction mapping uses composite key {entity}:{id}
--   because QBO IDs are entity-scoped; raw qbo_entity_id stored alongside
--   for direct QBO API lookup via getEntity.
-- Per D-QBO-20-3: acc_bank_transactions.source ENUM EXTENDED with 'qbo_cdc'
--   via MODIFY COLUMN (not replacement); existing 'manual','import','system'
--   rows untouched + default preserved.
-- Per D-QBO-20-4: rows with source='qbo_cdc' MUST have is_readonly=1; FF
--   reconciliation UI flows + bank-rec match endpoints reject is_readonly=1
--   rows. BankTransactionPuller enforces on insert; admin UI gates on user
--   match attempts.
-- Per D-QBO-20-5: QBO Transfer entity has two BankAccountRefs (from/to); CDC
--   pull captures only the side that matches the mapped pulling account —
--   the other side surfaces as a separate row only if its account is also
--   mapped (mirror semantics; both sides read-only).
-- Per D-QBO-20-6: cdc_lookback_days first-run safety net (90 days default)
--   prevents pulling years of QBO history on first cron run; subsequent runs
--   use last_bank_cdc_at as upper bound.
-- Per D-QBO-20-7: cron_skipped_unmapped='1' (default) skips QBO transactions
--   whose BankAccountRef is not yet mapped; pull_status=skipped_unmapped_account.
--   Operator maps the account + re-runs to pick up the skipped transactions.
--   '0' fails the run (strict-mode for environments wanting all-mapped invariant).
--
-- @session  S-QBO-20
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.11 (Bank Account),
--           §8.12 (Bank Transaction read-only mirror)
-- @decision D-QBO-20-1 (one-time-import pattern mirrors S-QBO-8),
--           D-QBO-20-2 (composite {entity}:{id} key for QBO transaction ID),
--           D-QBO-20-3 (source ENUM extended; no replacement),
--           D-QBO-20-4 (qbo_cdc ⇒ is_readonly=1 invariant; FF rec UI rejects),
--           D-QBO-20-5 (Transfer captured from one side per pulling account),
--           D-QBO-20-6 (cdc_lookback_days first-run safety; 90-day default),
--           D-QBO-20-7 (cron_skipped_unmapped='1' default; per-account skip)


CREATE TABLE IF NOT EXISTS `acc_qbo_bank_account_map` (
    `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_bank_account_id`          INT UNSIGNED NOT NULL COMMENT 'FK acc_bank_accounts.id; CASCADE on FF-side delete; one-to-one (uq_ff)',
    `qbo_bank_account_id`         VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Intuit Account.Id where AccountType IN ("Bank","CreditCard"); one-to-one per FF (uq_qbo)',
    `qbo_account_name_snapshot`   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Account.Name at mapping time; verifyMappingStillValid compares + flags name_drift',
    `qbo_currency_snapshot`       ENUM('CAD','USD') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value at mapping time; verifyMappingStillValid compares + flags currency_drift (D-QBO-20-1)',
    `qbo_active_snapshot`         TINYINT(1) DEFAULT NULL COMMENT 'QBO Account.Active at mapping time; verifyMappingStillValid compares + flags became_inactive',
    `qbo_account_type_snapshot`   VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO AccountType snapshot ("Bank" / "CreditCard") for drift detection',
    `mapping_status`              ENUM('mapped','unmapped','conflict') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mapped' COMMENT 'mapped = live link; unmapped = operator unlinked (row preserved for forensic trail); conflict = drift detected by verifyMappingStillValid',
    `last_synced_at`              DATETIME DEFAULT NULL COMMENT 'Last verifyMappingStillValid run; also touched on snapshot refresh',
    `mapped_by`                   INT UNSIGNED DEFAULT NULL COMMENT 'FK users.id; SET NULL on user delete',
    `mapped_at`                   DATETIME DEFAULT NULL COMMENT 'When operator linked this mapping (distinct from created_at = row creation)',
    `created_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_bank_account` (`ff_bank_account_id`) COMMENT 'One mapping per FF bank account; enforces 1:1',
    UNIQUE KEY `uq_qbo_bank_account` (`qbo_bank_account_id`) COMMENT 'No two FF bank accounts share a QBO Account.Id',
    KEY `idx_status` (`mapping_status`),
    KEY `idx_mapped_by` (`mapped_by`),
    CONSTRAINT `fk_qbo_bank_account_map_ff` FOREIGN KEY (`ff_bank_account_id`) REFERENCES `acc_bank_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qbo_bank_account_map_user` FOREIGN KEY (`mapped_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-9 S-QBO-20: FF↔QBO bank account mapping (Exception #2 / D-QBO-CORE-2). One-time-import pattern mirroring AccountMatcher (S-QBO-8). Direct mapping path; alternate to gl_account_id pivot used by S-QBO-19 BillPaymentPusher. Snapshot columns drive verifyMappingStillValid drift detection.';


CREATE TABLE IF NOT EXISTS `acc_qbo_bank_transaction_map` (
    `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_bank_transaction_id`      INT UNSIGNED NOT NULL COMMENT 'FK acc_bank_transactions.id; CASCADE on FF-side delete',
    `qbo_bank_txn_id`             VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Composite key {entity_type}:{entity_id} per D-QBO-20-2 — QBO IDs are entity-scoped (Purchase #5 != Deposit #5)',
    `qbo_entity_type`             ENUM('Purchase','Deposit','Transfer','JournalEntry') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'QBO entity type per spec §8.12 CDC entities list',
    `qbo_entity_id`               VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Raw Intuit entity id (without prefix) for direct QBO API lookup via getEntity',
    `qbo_account_id`              VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'BankAccountRef.value snapshot — QBO bank account that this transaction belongs to from the pulling perspective (Transfer captured from one side only per D-QBO-20-5)',
    `qbo_txn_date`                DATE NOT NULL COMMENT 'QBO TxnDate snapshot — drift comparison + ordering',
    `qbo_amount`                  DECIMAL(15,2) NOT NULL COMMENT 'QBO transaction signed amount snapshot at last pull (per QBO convention; FF acc_bank_transactions.amount uses transaction_type for sign normalization)',
    `qbo_currency_snapshot`       VARCHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value snapshot; multi-currency surface — FX revaluation deferred to S-QBO-FX-RECON-FOLLOWUP',
    `qbo_exchange_rate_snapshot`  DECIMAL(10,6) DEFAULT NULL COMMENT 'QBO ExchangeRate snapshot at pull time; informational only in v1',
    `qbo_description_snapshot`    VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO description snapshot at last pull (Memo OR PrivateNote OR Line[0].Description fallback chain)',
    `pull_status`                 ENUM('pending','pulled','superseded','failed','skipped_unmapped_account','skipped_zero_amount') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pulled = current; superseded = QBO-side deletion noticed via markStale (FF row preserved with note suffix); failed = pull error; skipped_unmapped_account = transaction for an unmapped QBO bank account per cron_skipped_unmapped=1 default; skipped_zero_amount = $0 transaction (no economic significance; filter out mirror clutter)',
    `pull_error`                  TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Last error for failed state',
    `last_pulled_at`              DATETIME NOT NULL COMMENT 'Most recent pull attempt (regardless of outcome)',
    `first_seen_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When FF first observed this QBO transaction — preserved across re-pulls',
    `created_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_qbo_bank_txn` (`qbo_bank_txn_id`) COMMENT 'Composite key uniqueness — idempotency baseline for re-pulls',
    KEY `idx_ff_txn` (`ff_bank_transaction_id`) COMMENT 'NOT UNIQUE — multiple map rows may carry the same FF id in edge cases (superseded retained next to active pull)',
    KEY `idx_status` (`pull_status`),
    KEY `idx_txn_date` (`qbo_txn_date`),
    KEY `idx_entity_type` (`qbo_entity_type`),
    CONSTRAINT `fk_qbo_bank_txn_map_ff` FOREIGN KEY (`ff_bank_transaction_id`) REFERENCES `acc_bank_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-9 S-QBO-20: per-transaction mapping FF↔QBO. Composite {entity}:{id} key handles QBO ID entity-scoping. pull_status taxonomy includes skipped_unmapped_account (cron_skipped_unmapped=1 path) + skipped_zero_amount (mirror noise filter) + superseded (markStale path).';


-- ── ALTER acc_bank_transactions ────────────────────────────────────────────
-- K-22: existing source ENUM is ('manual','import','system'). MODIFY COLUMN
-- with the extended ENUM preserves DEFAULT + existing rows per InnoDB.

ALTER TABLE `acc_bank_transactions`
    MODIFY COLUMN `source` ENUM('manual','import','system','qbo_cdc')
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual'
        COMMENT 'Per D-QBO-20-3: qbo_cdc tag added for read-only mirror rows pulled via cron/qbo_bank_cdc.php; existing rows preserve manual/import/system values (ENUM extension, not replacement).',
    ADD COLUMN `is_readonly` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Per D-QBO-20-4: rows with source=qbo_cdc MUST set is_readonly=1; FF reconciliation UI rejects is_readonly=1 rows (double-count guard). BankTransactionPuller enforces on insert; admin bank-rec match endpoints enforce on user-initiated match attempts.'
        AFTER `source`,
    ADD COLUMN `qbo_bank_txn_id` VARCHAR(64)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Composite {entity}:{id} cross-reference back to acc_qbo_bank_transaction_map.qbo_bank_txn_id for forensic drill-down (FK relationship lives on the map side for cleaner CASCADE semantics).'
        AFTER `is_readonly`,
    ADD KEY `idx_qbo_bank_txn` (`qbo_bank_txn_id`);


-- ── Seed 4 quickbooks.banking.* settings ────────────────────────────────────

INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `description`) VALUES
    ('quickbooks.banking.last_bank_cdc_at', '', 'string', 'quickbooks', 0, 0, 'Last successful qbo_bank_cdc.php cron run timestamp (ISO 8601). Empty = never run; first run uses cdc_lookback_days as upper bound. Per D-QBO-20-6.'),
    ('quickbooks.banking.cdc_lookback_days', '90', 'integer', 'quickbooks', 0, 0, 'First-run safety net: pull at most this many days of QBO history on first cron run (when last_bank_cdc_at is empty). Default 90 days per D-QBO-20-6. Subsequent runs use last_bank_cdc_at as upper bound.'),
    ('quickbooks.banking.cdc_enabled', '1', 'boolean', 'quickbooks', 0, 0, 'Master toggle for the bank CDC cron. 1 = cron runs; 0 = cron exits silently. Defaults to 1 — set to 0 during emergency to halt mirror pull without disabling other QBO sync surfaces.'),
    ('quickbooks.banking.cron_skipped_unmapped', '1', 'boolean', 'quickbooks', 0, 0, 'Per D-QBO-20-7: when 1 (default), QBO transactions whose BankAccountRef is not yet mapped via acc_qbo_bank_account_map are skipped + recorded with pull_status=skipped_unmapped_account; operator maps the account + re-runs to pick up the skipped transactions. When 0, unmapped transactions log to sync_log + fail the run (strict-mode for environments wanting all-accounts-mapped invariant).')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
