-- ============================================================================
-- S-FIX-2 — add 'overpayment' to credit_notes.source enum
--
-- Audit #2 (Phase 0.5) introduces auto-routing of payment overage into a
-- credit_note row with source='overpayment'. The existing enum did not include
-- this value; the closest neighbours ('payment_returned', 'invoice_adjustment')
-- have different semantics. Add the value and document the JE pattern.
--
-- Idempotent: re-running this migration is a no-op because MySQL accepts the
-- same ALTER ... MODIFY without error if the resulting type matches.
--
-- Author:   S-FIX-2
-- Date:     2026-05-02
-- Spec:     §9 Billing — Overpayment routing (audit finding #2)
-- ============================================================================

ALTER TABLE credit_notes
    MODIFY COLUMN source
        ENUM('mileage_overpayment', 'invoice_adjustment',
             'damage_resolution', 'goodwill', 'payment_returned',
             'overpayment', 'other')
        NOT NULL;

-- ----------------------------------------------------------------------------
-- Update FLEETFORGE_DATABASE_MASTER.sql to keep the schema baseline aligned —
-- handled in the same commit as this migration.
-- ----------------------------------------------------------------------------
