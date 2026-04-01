-- ============================================================
-- FleetForge — Seed: Tax Rates
-- File: database/seeds/006_tax_rates.sql
--
-- Seeds 3 tax rate records for Canadian provinces:
--   1. British Columbia — GST 5% + PST 7% (default, is_default=1)
--   2. Ontario          — HST 13%
--   3. Alberta          — GST 5% only (no PST in AB)
--
-- D11: Tax rates are looked up at invoice time from this table.
-- Rates are NEVER frozen on the lease — CRA compliance requires
-- the rate in effect at invoice generation time.
--
-- D22: gst_exempt and pst_exempt are independent booleans.
-- TaxCalculator checks each independently — a customer can be
-- GST-exempt but PST-liable (e.g. First Nations).
--
-- effective_from = 2025-01-01 (current rates as of installation).
-- effective_to = NULL means rate is open-ended (still in effect).
--
-- Spec ref: FLEETFORGE_SPEC_FINAL.md §5 (Tax Rates)
-- ============================================================

INSERT IGNORE INTO tax_rates (name, province, country, gst_rate, pst_rate, hst_rate, is_default, is_active, effective_from, effective_to, notes) VALUES

-- British Columbia: GST 5% + PST 7% — default jurisdiction
(
    'BC GST + PST',
    'BC',
    'CA',
    0.0500,   -- GST: 5%
    0.0700,   -- PST: 7%
    0.0000,   -- HST: n/a
    1,        -- is_default: yes — used when no province override on lease
    1,
    '2025-01-01',
    NULL,
    'British Columbia: 5% GST + 7% PST. Applied as separate line items on invoices.'
),

-- Ontario: HST 13% (replaces GST + PST as a single harmonized tax)
(
    'Ontario HST',
    'ON',
    'CA',
    0.0000,   -- GST: included in HST, not applied separately
    0.0000,   -- PST: included in HST, not applied separately
    0.1300,   -- HST: 13%
    0,
    1,
    '2025-01-01',
    NULL,
    'Ontario: 13% HST. Applied as a single line item on invoices.'
),

-- Alberta: GST 5% only — no provincial sales tax
(
    'Alberta GST',
    'AB',
    'CA',
    0.0500,   -- GST: 5%
    0.0000,   -- PST: Alberta has no PST
    0.0000,   -- HST: n/a
    0,
    1,
    '2025-01-01',
    NULL,
    'Alberta: 5% GST only. No provincial sales tax.'
);
