-- ============================================================
-- S-QBO-VALIDATOR-SCOPE-SPLIT — acc_qbo_account_map.critical_category column.
--
-- Adds a category tag to each is_critical=1 row so AccountValidator can
-- gate Pusher sessions per-category instead of all-or-nothing. The
-- existing assertReadyForInvoicePush() required ALL 7 critical accounts
-- mapped before invoice push; in reality S-QBO-11 invoice push only
-- needs AR + Sales Revenue, while full compliance (JE push S-QBO-21,
-- bill push S-QBO-18, bill payment S-QBO-19) needs the full set.
--
-- Categories (D-QBO-VALIDATOR-2):
--   'ar_clearing'         → required by S-QBO-11 (invoice), S-QBO-13/14 (payments)
--   'ap_clearing'         → required by S-QBO-18/19 (bills + bill payments)
--   'undeposited_funds'   → required by S-QBO-13/14 (payments). No FF account
--                           fits the current chart; operator extends later via
--                           SQL or future UI extension. Gate throws actionable
--                           error per D-QBO-VALIDATOR-3 if requested but absent.
--   'tax_receivable'      → required by S-QBO-21 (JE push for tax remit)
--   'tax_payable'         → required by S-QBO-21 (same)
--   'sales_revenue'       → required by S-QBO-11 (invoice line emission)
--
-- K-22 silent resolution (per [[project_qbo_subtype_taxonomy]] + AskUserQuestion):
--   The session prompt's PART B/C heuristic listed FF.acct_subtype values
--   'AccountsReceivable'/'AccountsPayable'/'UndepositedFunds' (QBO
--   PascalCase). FF.acct_subtype is lowercase snake_case (current_asset,
--   current_liability, etc.); those PascalCase values would never match.
--   This migration uses FF code patterns instead — the same heuristic
--   AccountValidator::identifyCriticalFfAccounts() already uses to flag
--   is_critical=1. Operator confirmed via question 1.
--
-- Schema impact: 1 new column + 1 index. Backfill populates the 7
-- already-identified critical accounts (1030/1050/1060/2010/2030/2040/
-- 4xxx is_system=1) with their categories. undeposited_funds left
-- unpopulated by design.
--
-- @session  S-QBO-VALIDATOR-SCOPE-SPLIT
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher pre-flight gates)
-- @decision D-QBO-VALIDATOR-1/2/3 (per-session gates), D-QBO-VALIDATOR-4
--           (assertReadyForInvoicePush semantics narrow to ar_clearing +
--           sales_revenue), D-QBO-VALIDATOR-5 (exception message names
--           the blocking categories + FF accounts)
-- ============================================================

ALTER TABLE `acc_qbo_account_map`
  ADD COLUMN `critical_category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'D-QBO-VALIDATOR-2: per-session-category tag for is_critical=1 rows. Values: ar_clearing / ap_clearing / undeposited_funds / tax_receivable / tax_payable / sales_revenue. NULL for non-critical rows. Populated by AccountValidator::markCriticalAccounts() via FF code-pattern heuristic.'
    AFTER `critical_reason`,
  ADD INDEX `idx_critical_category` (`critical_category`, `mapping_status`)
    COMMENT 'Per-session validator gate lookup (assertReadyForInvoicePush etc.)';

-- Backfill the 7 already-identified critical accounts. Uses FF code
-- patterns (not subtype) per the K-22 resolution above. CASE matches
-- AccountValidator::identifyCriticalFfAccounts() heuristic so the
-- column is consistent whether populated by this backfill or by a
-- future markCriticalAccounts() run.
--
-- After this runs, expected distribution among is_critical=1 rows:
--   ar_clearing      → 1 row (1030)
--   ap_clearing      → 1 row (2010)
--   tax_receivable   → 2 rows (1050, 1060)
--   tax_payable      → 2 rows (2030, 2040)
--   sales_revenue    → 1 row (4122 in current chart; future 4xxx is_system
--                              accounts also covered by the LIKE '4%' branch)
--   undeposited_funds → 0 rows (no FF account fits; by design)
UPDATE `acc_qbo_account_map` m
  JOIN `acc_accounts` a ON a.id = m.ff_account_id
   SET m.critical_category = CASE
     WHEN a.code = '1030'                            THEN 'ar_clearing'
     WHEN a.code = '2010'                            THEN 'ap_clearing'
     WHEN a.code IN ('1050', '1060')                 THEN 'tax_receivable'
     WHEN a.code IN ('2030', '2040')                 THEN 'tax_payable'
     WHEN a.code LIKE '4%'
       AND a.is_system = 1
       AND a.account_type = 'revenue'                THEN 'sales_revenue'
     ELSE NULL
   END
 WHERE m.is_critical = 1;
