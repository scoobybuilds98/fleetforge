-- ============================================================
-- Migration 037 — Advance Billing
-- Session: ADV-BILL-1
--
-- Adds optional multi-period prepayment at lease activation. When a
-- lease is created with advance_billing_periods > 0, activation
-- generates Invoice 1 PLUS N additional future-period invoices in a
-- single transaction. The lease's next_billing_date jumps past the
-- final advance invoice so the monthly cron skips the period range
-- automatically.
--
-- Schema changes:
--   1. leases.advance_billing_periods   (TINYINT UNSIGNED, default 0)
--      0 = legacy single-period activation; >0 = generate Invoice 1 + N more.
--      Capped at the billing.max_advance_periods setting (default 24)
--      and only allowed when billing_cycle = 'monthly' (D-E).
--
--   2. invoices.generation_source — add 'advance' value (D-A).
--      Distinguishes pre-paid future-period invoices created by the
--      activation batch from cron-generated, manually-generated, and
--      lease-close invoices. Used by the close handler to detect
--      remaining advance invoices for refund/no-refund reconciliation,
--      and by the invoice update guard (D-C) to lock period/financial
--      fields regardless of status.
--
--   3. settings.billing.max_advance_periods (default 24).
--      Per-install cap on advance_billing_periods. UI hides the field
--      when billing_cycle != 'monthly'; API rejects with VALIDATION_ERROR
--      when violated.
--
-- Business rules locked in this migration:
--   - All N+1 advance invoices share the same invoice_date, tax rate,
--     FX snapshot, and currency_markup_pct (CRA-correct: prepayments
--     are taxed at the rate in force when the customer pays, not when
--     the period falls).
--   - Sequential invoice numbers preserved via the existing FOR UPDATE
--     counter — generateInvoiceNumber() is called N+1 times in one
--     transaction (D15 still holds; gap-free).
--   - Advance invoices are NOT mileage-pre-charged. Mileage reconciles
--     at lease close per the existing flow.
--   - Close reconciliation: refund_unused (default) | no_refund (D-H).
-- ============================================================

ALTER TABLE leases
    ADD COLUMN advance_billing_periods TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Number of additional pre-paid future periods generated at activation (0 = legacy single-invoice activation). Cap from billing.max_advance_periods. Monthly billing only.'
    AFTER billing_cycle;

ALTER TABLE invoices
    MODIFY COLUMN generation_source ENUM('cron','manual','lease_close','late_fee_cron','advance') NULL
    COMMENT 'How this invoice was created. advance = pre-paid future period invoice generated at lease activation by InvoiceGenerator::generateAdvanceBatch().';

-- Settings seed (idempotent)
INSERT IGNORE INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
VALUES (
    'billing.max_advance_periods',
    '24',
    'integer',
    'invoices',
    'Max Advance Billing Periods',
    'Hard cap on advance_billing_periods at lease creation. Lease APIs reject values above this, and the lease form clamps the input. Set to 0 to disable advance billing entirely.',
    0
);
