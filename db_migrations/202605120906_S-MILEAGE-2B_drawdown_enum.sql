-- ============================================================
-- 202605120906_S-MILEAGE-2B_drawdown_enum.sql
--
-- S-MILEAGE-2B C2 — Add two new ENUM values to
-- `invoice_line_items.item_type` for Model B drawdown emit:
--
--   `mileage_usage`            — per-km usage line (positive, taxable)
--   `mileage_drawdown_credit`  — precharge balance drawdown credit
--                                 (positive amount + is_credit=1 per
--                                 InvoiceGenerator's signed-aggregator
--                                 convention; K-16 spec clarification
--                                 vs the locked D-B "negative amount"
--                                 wording — the existing aggregator at
--                                 lib/Billing/InvoiceGenerator.php:357-362
--                                 subtracts on is_credit=1, so the
--                                 financial result is identical with
--                                 positive amount + is_credit=1).
--
-- ENUM grows from 14 → 16 values. Both new values appended at the
-- end of the value list per D126 (preserves existing ordinals;
-- no row rewrite for legacy data). Naturally idempotent — re-running
-- against an already-extended ENUM is a no-op.
--
-- D128 trivial-data backup-skip: pre-migration SELECT COUNT(*) FROM
-- invoice_line_items WHERE item_type IN
-- ('mileage_drawdown_credit','mileage_usage') = 0 (verified pre-edit
-- 2026-05-12). S-MILEAGE-2B's C3 InvoiceGenerator drawdown emission
-- is the first writer of either value. No backup table needed per
-- D128 discipline; this comment block documents the omission.
--
-- Mirrored in FLEETFORGE_DATABASE_MASTER.sql at line 1898 in the
-- same commit per D87 + D127 (parity smoke gate verifies).
--
-- Author:   S-MILEAGE-2B
-- Date:     2026-05-12 (UTC)
-- Spec:     FLEETFORGE_CURRENT_SESSIONS.md S-MILEAGE-2B spec block
--           (locked by S-MILEAGE-2B-SPEC-LOCK commits 82e8ea6 +
--            b3c2e0a + 2df344a — D-A locked).
-- Decisions: D-A (ENUM additions); see also D-B (drawdown emit
--           shape) + D-E (show.php dispatch additions).
-- ============================================================

-- ── invoice_line_items.item_type — append two values ────────
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
    'mileage_drawdown_credit'
  ) COLLATE utf8mb4_unicode_ci NOT NULL;
