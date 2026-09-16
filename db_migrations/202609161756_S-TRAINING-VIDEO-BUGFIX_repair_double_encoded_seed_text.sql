-- ============================================================================
-- 202609161756_S-TRAINING-VIDEO-BUGFIX_repair_double_encoded_seed_text.sql
--
-- S-TRAINING-VIDEO-BUGFIX — repair seed text that was stored DOUBLE-ENCODED
-- ("â€”" instead of "—"), plus two settings descriptions that pointed at deleted
-- or wrong code.
--
-- WHY THE TEXT IS GARBLED: the seed files under database/seeds/ are valid UTF-8,
-- but they were loaded with `mysql db < file.sql` on a client whose default
-- connection charset is latin1. Each UTF-8 byte of "—" (E2 80 94) was read as a
-- cp1252 character and re-encoded to UTF-8, so the row holds C3A2 E282AC E2809D.
-- The seeds now start with SET NAMES utf8mb4 (same session); this migration
-- repairs rows already loaded that way. Found on the staff-training videos'
-- email templates; the same pattern exists in the other seeded reference tables.
--
-- THE REPAIR is the standard reverse transform, applied per column ONLY where it
-- is safe:
--   CONVERT(CAST(CONVERT(col USING latin1) AS BINARY) USING utf8mb4)
-- Guards (all must hold):
--   1. the value contains a UTF-8 lead sequence that only appears in mojibake:
--      C3 82 (Â), C3 83 (Ã) or C3 A2 (â) — checked on BINARY bytes, so this file
--      does not depend on the client charset it is applied with;
--   2. the transform yields valid UTF-8 (NOT NULL);
--   3. the transform is lossless — it must not introduce '?' (a character that
--      has no latin1 form, i.e. text that was already correct).
-- Idempotent: a repaired value no longer matches guard 1, so a re-run is a no-op.
-- Data-only (structural seed text); no schema change, no master-SQL change.
--
-- @session S-TRAINING-VIDEO-BUGFIX
-- ============================================================================

SET NAMES utf8mb4;

UPDATE `email_templates`
   SET `name` = CONVERT(CAST(CONVERT(`name` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `name` IS NOT NULL
   AND (INSTR(CAST(`name` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`name` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`name` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`name` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`name` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`name` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`name`) - CHAR_LENGTH(REPLACE(`name`, '?', '')));

UPDATE `email_templates`
   SET `subject` = CONVERT(CAST(CONVERT(`subject` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `subject` IS NOT NULL
   AND (INSTR(CAST(`subject` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`subject` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`subject` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`subject` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`subject` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`subject` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`subject`) - CHAR_LENGTH(REPLACE(`subject`, '?', '')));

UPDATE `email_templates`
   SET `body_html` = CONVERT(CAST(CONVERT(`body_html` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `body_html` IS NOT NULL
   AND (INSTR(CAST(`body_html` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`body_html` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`body_html` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`body_html` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`body_html` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`body_html` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`body_html`) - CHAR_LENGTH(REPLACE(`body_html`, '?', '')));

UPDATE `email_templates`
   SET `body_text` = CONVERT(CAST(CONVERT(`body_text` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `body_text` IS NOT NULL
   AND (INSTR(CAST(`body_text` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`body_text` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`body_text` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`body_text` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`body_text` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`body_text` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`body_text`) - CHAR_LENGTH(REPLACE(`body_text`, '?', '')));

UPDATE `acc_cca_classes`
   SET `description` = CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `description` IS NOT NULL
   AND (INSTR(CAST(`description` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`description` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`description` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`description`) - CHAR_LENGTH(REPLACE(`description`, '?', '')));

UPDATE `acc_cca_classes`
   SET `notes` = CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `notes` IS NOT NULL
   AND (INSTR(CAST(`notes` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`notes` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`notes` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`notes`) - CHAR_LENGTH(REPLACE(`notes`, '?', '')));

UPDATE `acc_place_of_supply_rules`
   SET `notes` = CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `notes` IS NOT NULL
   AND (INSTR(CAST(`notes` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`notes` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`notes` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`notes`) - CHAR_LENGTH(REPLACE(`notes`, '?', '')));

UPDATE `tax_rates`
   SET `notes` = CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `notes` IS NOT NULL
   AND (INSTR(CAST(`notes` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`notes` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`notes` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`notes`) - CHAR_LENGTH(REPLACE(`notes`, '?', '')));

UPDATE `settings`
   SET `description` = CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4)
 WHERE `description` IS NOT NULL
   AND (INSTR(CAST(`description` AS BINARY), UNHEX('C382')) > 0
        OR INSTR(CAST(`description` AS BINARY), UNHEX('C383')) > 0
        OR INSTR(CAST(`description` AS BINARY), UNHEX('C3A2')) > 0)
   AND CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4) IS NOT NULL
   AND (CHAR_LENGTH(CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4)) - CHAR_LENGTH(REPLACE(CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4), '?', '')))
     = (CHAR_LENGTH(`description`) - CHAR_LENGTH(REPLACE(`description`, '?', '')));

-- Settings descriptions that described code that no longer exists / the wrong key.
-- billing.engine_version: the ProRateCalculator / period_independent engine was
-- deleted (S-DELETE-LEGACY-ENGINE); nothing reads this value and the row is now
-- hidden from Settings.
UPDATE `settings`
   SET `description` = 'Vestigial. HolisticLeaseEngine is the only billing engine (the legacy period_independent / ProRateCalculator path was deleted); no code reads this value. Hidden from Settings.'
 WHERE `key` = 'billing.engine_version';

-- invoice.payment_instructions is now read (portal invoice + Payments pages and the
-- invoice PDF) and overrides the company-wide fallback.
UPDATE `settings`
   SET `description` = 'Shown on invoice PDFs and on the customer portal (invoice page and Payments). Overrides Company > Payment Instructions when set.'
 WHERE `key` = 'invoice.payment_instructions';

UPDATE `settings`
   SET `description` = 'Company-wide fallback: used on invoice PDFs and the customer portal when Invoices & Billing > Payment Instructions is blank.'
 WHERE `key` = 'company.payment_instructions';
