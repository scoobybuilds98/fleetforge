-- S037-FX — FX Revaluation Engine schema additions.
--
-- Three new columns required by the ASPE 1651 temporal-method
-- revaluation engine:
--
--   acc_fx_revaluations.status   ENUM('preview','posted','reversed')
--     Tracks the lifecycle of a revaluation. 'preview' rows record an
--     uncommitted preview snapshot (not currently emitted by the engine
--     — reserved for future write-after-confirm workflow). 'posted' is
--     the canonical entry. 'reversed' is set after the engine reverses
--     a posted revaluation (the original JE is reversed via
--     JournalEntryService::reverse() in the same transaction).
--
--   acc_fx_revaluations.run_at   DATETIME
--     Wall-clock timestamp when the revaluation was posted (distinct
--     from revaluation_date which is the GL period end date being
--     revalued). Used for audit / reporting "when did we run this".
--
--   acc_accounts.is_fx_monetary  TINYINT(1)
--     Marker flag identifying accounts that participate in FX
--     revaluation. The engine filters `WHERE is_fx_monetary = 1 AND
--     currency = 'USD'` so the CAD-side AR/AP control accounts can
--     also be marked without affecting engine behavior (they stay
--     out because their currency is CAD).
--
-- Seed: mark the standard set of monetary accounts per spec §22.1 —
--   AR (1030), AP (2010), and any acc_accounts row where currency='USD'
--   and account_type is asset/liability (USD bank accounts, USD
--   sub-ledgers if seeded). The combined engine filter then revalues
--   only the rows that are USD-currency.
--
-- All ALTERs are idempotent via INFORMATION_SCHEMA checks so the
-- migration is safe to re-apply on a partially-migrated DB.

-- ── acc_fx_revaluations.status ──────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'acc_fx_revaluations'
       AND COLUMN_NAME = 'status'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE acc_fx_revaluations
        ADD COLUMN `status` ENUM(''preview'',''posted'',''reversed'')
            NOT NULL DEFAULT ''preview''
            AFTER unrealized_gain_loss',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── acc_fx_revaluations.run_at ──────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'acc_fx_revaluations'
       AND COLUMN_NAME = 'run_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE acc_fx_revaluations
        ADD COLUMN `run_at` DATETIME NULL AFTER `status`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── acc_accounts.is_fx_monetary ─────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'acc_accounts'
       AND COLUMN_NAME = 'is_fx_monetary'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE acc_accounts
        ADD COLUMN `is_fx_monetary` TINYINT(1) NOT NULL DEFAULT 0
            AFTER is_bank_account',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Seed monetary flag (idempotent — UPDATE is safe to re-run) ──────────────
UPDATE acc_accounts
   SET is_fx_monetary = 1
 WHERE code IN ('1030','2010')
    OR (currency = 'USD' AND account_type IN ('asset','liability'));
