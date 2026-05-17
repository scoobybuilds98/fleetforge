-- ============================================================
-- 202605170105_S-BILLING-HOLISTIC-ENGINE_settings_engine_flag.sql
--
-- S-BILLING-HOLISTIC-ENGINE — Add the `billing.engine_version`
-- settings row. This is a GLOBAL DEFAULT used as a fallback /
-- documentation marker; the per-lease dispatch is driven by
-- leases.engine_version (set per row at lease creation time).
--
-- The setting uses is_public=0 (admin-only — the column the
-- live `settings` table actually has; spec §29.4's "is_editable"
-- was a paper-spec field that doesn't exist on the live schema).
-- Switching the global default mid-flight would NOT retroactively
-- change existing leases (their engine_version is locked at
-- creation), so a hidden/admin-only flag is correct.
--
-- Value rationale: 'holistic' matches the new lease column default
-- from 202605170103. The flag documents the intent: "new leases
-- created from this point forward use the holistic engine unless
-- the application explicitly says otherwise."
--
-- INSERT IGNORE means re-running the migration is a no-op (the
-- unique key on `settings.key` blocks the duplicate).
--
-- Author:    S-BILLING-HOLISTIC-ENGINE
-- Date:      2026-05-17
-- Spec:      FleetForge_Holistic_Billing_Engine_Spec.docx §29.4
-- ============================================================

-- ── settings — billing.engine_version row ───────────────────
-- INSERT IGNORE: safe to re-run; the row is keyed by `key`.
-- Column set matches the live `settings` table schema (no
-- `is_editable` / `created_at` columns — those were paper-spec
-- artifacts).
INSERT IGNORE INTO settings
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `updated_at`)
VALUES
  ('billing.engine_version',
   'holistic',
   'string',
   'billing',
   'Billing Engine Version',
   'period_independent (OLD ProRateCalculator) or holistic (NEW HolisticLeaseEngine). This is the GLOBAL DEFAULT for new leases — actual per-lease engine choice is locked in leases.engine_version at lease creation.',
   0,
   NOW());
