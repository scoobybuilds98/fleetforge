-- ============================================================
-- Migration 036 — Currency Markup
-- Session: CURRENCY-MARKUP-1
--
-- Adds currency_markup_pct to invoices and payments so the
-- markup applied on top of the bank rate is frozen per-record
-- at creation time (same immutability as exchange_rate_to_cad).
--
-- Business rules:
--   1. exchange_rate_to_cad always stores the canonical bank rate — never modified.
--   2. currency_markup_pct stores the markup percentage frozen at record creation.
--   3. effective_rate = exchange_rate_to_cad × (1 + currency_markup_pct / 100).
--   4. CAD equivalents in reports/display use the effective rate, not the bank rate.
--   5. Existing rows default to 0.0000 — retroactively treated as no markup (correct).
-- ============================================================

ALTER TABLE invoices
    ADD COLUMN currency_markup_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000
    COMMENT 'Markup % frozen at invoice creation; applied on top of exchange_rate_to_cad'
    AFTER exchange_rate_to_cad;

ALTER TABLE payments
    ADD COLUMN currency_markup_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000
    COMMENT 'Markup % frozen at payment receipt; applied on top of exchange_rate_to_cad'
    AFTER exchange_rate_to_cad;

-- Settings seed (idempotent)
INSERT IGNORE INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
VALUES (
    'currency.usd_cad_markup_pct',
    '0.0000',
    'decimal',
    'currency',
    'USD → CAD Markup',
    'Markup % applied on top of the bank exchange rate when generating USD invoices. Frozen per invoice at creation. Visible on invoice and PDF. 0 = no markup (raw bank rate).',
    0
);
