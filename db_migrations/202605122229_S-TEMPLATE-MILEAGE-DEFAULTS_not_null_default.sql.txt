-- ============================================================
-- 202605122229_S-TEMPLATE-MILEAGE-DEFAULTS_not_null_default.sql
--
-- S-TEMPLATE-MILEAGE-DEFAULTS C2 — enforce NOT NULL DEFAULT 0
-- on equipment_templates.default_mileage_rate.
--
-- Pre-condition (verified in pre-work scan P1/P2, 2026-05-13):
--   SELECT COUNT(*) FROM equipment_templates
--     WHERE default_mileage_rate IS NULL  →  0
--   Production templates 1/3/4/5 backfilled by S-MILEAGE-RATE-
--   ZERO-FIX (2026-05-06 per S-REEFER-RATE-AUDIT finding); seven
--   seed templates with default_mileage_rate=0 are intentional
--   non-billable types (zero active leases per P3 — D135 config 3
--   rate=0 → mileage disabled).
--
-- Column shape change:
--   Before:  decimal(8,4)  DEFAULT NULL
--   After:   decimal(10,4) NOT NULL DEFAULT '0.0000'
--
-- Precision widening 8,4 → 10,4 brings this column into line with
-- the leases.mileage_rate_km / mileage_rate_miles convention
-- (S-LEASE-UNITS, FLEETFORGE_DATABASE_MASTER.sql lines 2171-2172).
-- Existing values (max 0.2897 on template 1) fit trivially in
-- either precision; widening is forward-compatible noise.
--
-- Pre-flight assertion (idempotent):
--   ff_assert_no_null_mileage_rates() raises SQLSTATE 45000 if any
--   NULL rows exist at execution time — forces operator backfill
--   per the per-category rubric in seed_rate_cards.php:52-67
--   (dry_van $0.18 / flatbed $0.17 / container $0.15 / chassis
--   $0.13 / reefer $0.18 live default per S-REEFER-RATE-AUDIT)
--   before retrying the migration. In the verified-current state
--   (zero NULLs), the assertion is a no-op.
--
-- Idempotency: the MODIFY COLUMN restates the target shape; MySQL
-- re-applies it without error if the column already matches.
-- Re-running this migration against an already-migrated DB is a
-- no-op (assertion passes, MODIFY confirms current shape).
--
-- Mirrored in FLEETFORGE_DATABASE_MASTER.sql line 1694 in the
-- same commit per D87 + D127 (parity smoke gate verifies).
--
-- Renders S-LOOKUP-RATES-PRODUCTION-INVARIANT (QUEUED optional)
-- redundant — the NOT NULL DEFAULT 0 schema guarantee makes the
-- "template default must be non-NULL" branch of the invariant
-- permanently trivially true. SUPERSEDED in CURRENT_SESSIONS.md
-- per C3 docs.
--
-- Author:   S-TEMPLATE-MILEAGE-DEFAULTS
-- Date:     2026-05-13 (local) / 2026-05-12 22:29 (UTC)
-- Spec:     FLEETFORGE_CURRENT_SESSIONS.md S-TEMPLATE-MILEAGE-
--           DEFAULTS QUEUED entry + this session prompt
-- Decisions: D135 (three valid configurations — rate=0 → mileage
--           disabled), D127 (master-file parity discipline).
-- ============================================================

-- ── Pre-flight assertion: zero NULL rows required ────────────
-- Belt-and-suspenders guard. Verified zero pre-edit, but this
-- assertion makes the migration self-validating on any replay
-- (e.g., scratch-DB schema parity smoke in D127, future restore
-- pipelines). SIGNAL halts the migration on violation rather
-- than letting MODIFY produce a misleading 1138 error.
DROP PROCEDURE IF EXISTS ff_assert_no_null_mileage_rates;
DELIMITER //
CREATE PROCEDURE ff_assert_no_null_mileage_rates()
BEGIN
    DECLARE null_count INT DEFAULT 0;

    SELECT COUNT(*) INTO null_count
      FROM equipment_templates
     WHERE default_mileage_rate IS NULL;

    IF null_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'S-TEMPLATE-MILEAGE-DEFAULTS: equipment_templates.default_mileage_rate has NULL rows; backfill per seed_rate_cards.php rubric before retry.';
    END IF;
END //
DELIMITER ;
CALL ff_assert_no_null_mileage_rates();
DROP PROCEDURE IF EXISTS ff_assert_no_null_mileage_rates;

-- ── MODIFY COLUMN: NOT NULL DEFAULT 0.0000 (decimal(10,4)) ───
ALTER TABLE equipment_templates
  MODIFY COLUMN default_mileage_rate
    decimal(10,4) NOT NULL DEFAULT '0.0000'
    COMMENT 'Default per-km mileage rate for new leases on this template. 0 = mileage disabled (D135 config 3). NOT NULL enforced post S-TEMPLATE-MILEAGE-DEFAULTS 2026-05-13.';
