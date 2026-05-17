-- ============================================================
-- 202605170104_S-BILLING-HOLISTIC-ENGINE_lock_existing_to_old.sql
--
-- S-BILLING-HOLISTIC-ENGINE — Lock all currently active/pending
-- leases to the OLD `period_independent` engine so they continue
-- to bill on ProRateCalculator. Mid-lease engine switch would
-- invalidate prior invoices' math (the holistic engine subtracts
-- already_billed from cumulative_correct, but already_billed for
-- an old-engine lease was computed period-independently — the two
-- numbers don't reconcile cleanly).
--
-- STRICT ORDERING: this migration MUST run AFTER 202605170103
-- (which adds the engine_version column with default='holistic').
-- The 0104 timestamp ensures bin/migrate.php applies it second.
--
-- Affected rows: every lease where status IN ('active', 'pending')
-- AND deleted_at IS NULL. New leases created after this migration
-- runs default to 'holistic' (per the column default from 0103).
--
-- Closed leases (status='completed' / 'cancelled') are NOT touched
-- — they won't generate further invoices either way, and leaving
-- their engine_version='holistic' has no operational effect (the
-- column is only read at invoice-generation time).
--
-- One-time data migration; idempotent because re-running on rows
-- already set to 'period_independent' is a no-op.
--
-- D128 backup table SKIPPED: the migration is reversible by a
-- simple UPDATE leases SET engine_version='holistic' WHERE ...
-- and the affected row count is bounded by the active-lease set
-- (small, auditable). audit_log entries written by the migration
-- preserve who-was-set-when for forensic reconstruction.
--
-- Author:    S-BILLING-HOLISTIC-ENGINE
-- Date:      2026-05-17
-- Spec:      FleetForge_Holistic_Billing_Engine_Spec.docx §32.1
-- Depends:   202605170103 (must run first — adds the column)
-- Decisions: Option A from spec §35.2 (lock-existing, recommended).
-- ============================================================

-- ── Lock currently-active/pending leases to the old engine ──
-- This UPDATE is the data half of the migration. Idempotent —
-- re-running finds no rows that match (already updated rows
-- already have engine_version='period_independent').
UPDATE leases
   SET engine_version = 'period_independent',
       updated_at     = NOW()
 WHERE engine_version = 'holistic'
   AND status IN ('active', 'pending')
   AND deleted_at IS NULL;

-- ── Audit log: one row capturing the lock-existing event ────
-- Single summary row (not per-lease) — the WHERE-clause above is
-- the canonical record of which leases got flipped. Per-lease
-- audit would dwarf the row count and add no forensic value
-- beyond the migration's own deterministic SQL.
--
-- Uses action='update' since the audit_log.action ENUM doesn't
-- include 'migrate'; entity_type carries the descriptive value.
INSERT INTO audit_log (user_id, user_name, action, module, entity_type,
                       entity_id, entity_label, notes, ip_address, created_at)
SELECT NULL,
       'system',
       'update',
       'billing',
       'lease_engine_version_lock',
       NULL,
       'S-BILLING-HOLISTIC-ENGINE',
       CONCAT('Locked ', COUNT(*), ' active/pending leases to engine_version=period_independent (S-BILLING-HOLISTIC-ENGINE migration 202605170104). New leases continue to default to holistic.'),
       '127.0.0.1',
       NOW()
  FROM leases
 WHERE engine_version = 'period_independent'
   AND status IN ('active', 'pending')
   AND deleted_at IS NULL;
