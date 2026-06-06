-- ============================================================
-- 202605170101_S-BILLING-HOLISTIC-ENGINE_line_item_type_enum.sql
--
-- S-BILLING-HOLISTIC-ENGINE — Add one new ENUM value to
-- `invoice_line_items.item_type` for the holistic engine's
-- reconciliation credit emission:
--
--   `base_rental_reconciliation_credit` — emitted when the
--     holistic engine's delta is NEGATIVE (cumulative_correct <
--     already_billed). The line carries a POSITIVE amount with
--     is_credit=1 per the K-16 convention (matches existing
--     mileage_drawdown_credit shape — aggregator subtracts on
--     is_credit=1, per-line tax negates via bcmul sign-propagation
--     in TaxCalculator). Financial result identical to "negative
--     base_rental"; the K-16 shape preserves the signed-aggregator
--     contract at lib/Billing/InvoiceGenerator.php:683-689.
--
-- ENUM grows from 16 → 17 values. New value appended at the end
-- of the value list per D126 (preserves existing ordinals; no row
-- rewrite for legacy data). Naturally idempotent — re-running
-- against an already-extended ENUM is a no-op.
--
-- ALL EXISTING ENUM VALUES PRESERVED IN ORIGINAL ORDER. Verified
-- against live schema 2026-05-17 via SHOW CREATE TABLE.
--
-- D128 trivial-data backup-skip: pre-migration SELECT COUNT(*)
-- FROM invoice_line_items WHERE item_type =
-- 'base_rental_reconciliation_credit' = 0 (cannot pre-exist by
-- definition — the value isn't in the ENUM yet).
--
-- S-BILLING-HOLISTIC-ENGINE's modifications to InvoiceGenerator
-- (createFromLease holistic branch) are the first writer of this
-- value. No backup table needed per D128 discipline; this comment
-- block documents the omission.
--
-- Mirrored in FLEETFORGE_DATABASE_MASTER.sql at line 1898 in the
-- same commit per D87 + D127 (parity smoke gate verifies).
--
-- Author:    S-BILLING-HOLISTIC-ENGINE
-- Date:      2026-05-17
-- Spec:      FleetForge_Holistic_Billing_Engine_Spec.docx §29.2
-- Decisions: D126 (append-at-end ENUM); see also K-16 (positive
--            amount + is_credit=1 convention).
-- ============================================================

-- ── invoice_line_items.item_type — append one value ─────────
-- ENUM extension is append-at-end so existing ENUM ordinals are
-- preserved (no row rewrite for legacy data). Naturally idempotent
-- because the target ENUM definition is the same on re-run.
ALTER TABLE invoice_line_items
  MODIFY COLUMN item_type ENUM(
    'base_rental',
    'mileage_precharge',
    'mileage_adjustment',
    'mileage_credit',
    'insurance',
    'warranty',
    'late_fee',
    'early_return_credit',
    'manual_adjustment',
    'damage',
    'discount',
    'account_credit_applied',
    'other',
    'gps',
    'mileage_usage',
    'mileage_drawdown_credit',
    'base_rental_reconciliation_credit'
  ) COLLATE utf8mb4_unicode_ci NOT NULL;
