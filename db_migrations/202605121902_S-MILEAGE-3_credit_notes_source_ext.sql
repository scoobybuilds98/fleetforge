-- ============================================================
-- 202605121902_S-MILEAGE-3_credit_notes_source_ext.sql
--
-- S-MILEAGE-3 C2 — Add one new ENUM value to
-- `credit_notes.source` for the Model B precharge-refund flow:
--
--   `precharge_refund` — credit_notes row issued at lease close
--                         when the operator selects "Apply as Credit"
--                         for the residual `leases.precharge_balance`.
--                         The credit is consumable against any future
--                         invoice for that customer via the existing
--                         api/v1/credit_notes/apply.php flow.
--
-- ENUM grows from 7 → 8 values. New value appended at the end of
-- the value list per D126 (preserves existing ordinals; no row
-- rewrite for legacy data). Naturally idempotent — re-running
-- against an already-extended ENUM is a no-op.
--
-- D128 trivial-data backup-skip: pre-migration SELECT COUNT(*) FROM
-- credit_notes WHERE source = 'precharge_refund' = 0 (verified
-- pre-edit 2026-05-12 via SPEC-WRITE pre-work scan F).
-- S-MILEAGE-3's C3 close.php credit-branch dispatch is the first
-- writer of this value. No backup table needed per D128 discipline;
-- this comment block documents the omission.
--
-- Mirrored in FLEETFORGE_DATABASE_MASTER.sql at line 1247 in the
-- same commit per D87 + D127 (parity smoke gate verifies).
--
-- NO payments table change in this migration — D-B (i) "manual
-- deferred-settle" lock means cash refunds are tracked via
-- audit_log + leases.precharge_refund_method='cash' +
-- precharge_refund_settled_at stamp (no outbound payments row).
--
-- Author:   S-MILEAGE-3
-- Date:     2026-05-12 (UTC) / 2026-05-13 (local)
-- Spec:     FLEETFORGE_CURRENT_SESSIONS.md S-MILEAGE-3 spec block
--           (locked by S-MILEAGE-3-SPEC-WRITE caac041 +
--            S-MILEAGE-3-SPEC-LOCK 3102e39 — D-J locked).
-- Decisions: D-J (ENUM addition); see also D-C (credit refund
--           execution flow) + D-D (state machine).
-- ============================================================

-- ── credit_notes.source — append one value ────────────────
-- ENUM extension is append-at-end so existing ENUM ordinals are
-- preserved (no row rewrite for legacy data). Naturally idempotent
-- because the target ENUM definition is the same on re-run.
ALTER TABLE credit_notes
  MODIFY COLUMN source ENUM(
    'mileage_overpayment',
    'invoice_adjustment',
    'damage_resolution',
    'goodwill',
    'payment_returned',
    'overpayment',
    'other',
    'precharge_refund'
  ) COLLATE utf8mb4_unicode_ci NOT NULL;
