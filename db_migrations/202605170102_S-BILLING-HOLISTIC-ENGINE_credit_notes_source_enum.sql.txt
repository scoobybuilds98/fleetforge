-- ============================================================
-- 202605170102_S-BILLING-HOLISTIC-ENGINE_credit_notes_source_enum.sql
--
-- S-BILLING-HOLISTIC-ENGINE — Add one new ENUM value to
-- `credit_notes.source` for the holistic engine's negative-
-- subtotal overflow flow:
--
--   `base_rental_reconciliation_overflow` — when the holistic
--     engine emits a base_rental_reconciliation_credit line large
--     enough to drive the invoice subtotal below $0, the existing
--     S-FIX-2 Bug #3 overflow cap routes the residual to a
--     credit_notes row with this source. The customer keeps the
--     full credit value; the invoice subtotal floors at $0.
--
-- ENUM grows from 8 → 9 values. New value appended at the end of
-- the value list per D126 (preserves existing ordinals; no row
-- rewrite for legacy data). Naturally idempotent — re-running
-- against an already-extended ENUM is a no-op.
--
-- ALL EXISTING ENUM VALUES PRESERVED IN ORIGINAL ORDER. Verified
-- against live schema 2026-05-17 via SHOW CREATE TABLE.
--
-- D128 trivial-data backup-skip: pre-migration SELECT COUNT(*)
-- FROM credit_notes WHERE source =
-- 'base_rental_reconciliation_overflow' = 0 (cannot pre-exist by
-- definition — the value isn't in the ENUM yet). The InvoiceGenerator
-- overflow extension (S-BILLING-HOLISTIC-ENGINE Step 4) is the first
-- writer of this value.
--
-- Mirrored in FLEETFORGE_DATABASE_MASTER.sql at line 1247 in the
-- same commit per D87 + D127 (parity smoke gate verifies).
--
-- Author:    S-BILLING-HOLISTIC-ENGINE
-- Date:      2026-05-17
-- Spec:      FleetForge_Holistic_Billing_Engine_Spec.docx §19.2, §29.3
-- Decisions: D126 (append-at-end ENUM).
-- ============================================================

-- ── credit_notes.source — append one value ──────────────────
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
    'precharge_refund',
    'base_rental_reconciliation_overflow'
  ) COLLATE utf8mb4_unicode_ci NOT NULL;
