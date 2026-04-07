-- ============================================================
-- 029_tax_remit_source.sql
--
-- S035 — Tax Management migration.
--
-- (1) Add 'tax_remittance' to acc_journal_entries.source_type ENUM
--     so the consolidated CRA / tax-authority remittance JE
--     (DR 2030 / CR 1010) can be tagged for downstream filtering.
--
-- (2) Add updated_at to acc_tax_filing_periods and acc_tax_remittances
--     so D19 optimistic locking works on the tax management endpoints.
--
-- WHY ALTER vs CREATE: Both tables already exist (created in S028
-- foundation seed). We are only adding columns / extending an ENUM,
-- never dropping data. The ALTER on source_type uses the FULL existing
-- ENUM value list verbatim plus the new value — dropping any existing
-- value would corrupt historical JEs that reference it.
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §2.9 (schema), §9 (workflow)
-- Created in S035 — Tax Management module.
-- ============================================================

-- (1) Extend the source_type ENUM with 'tax_remittance'.
--     The ORDER of values does not matter for storage but we keep
--     them grouped logically: subledger sources first, then system /
--     period-end / manual entries.
ALTER TABLE acc_journal_entries
    MODIFY COLUMN source_type ENUM(
        'invoice',
        'payment',
        'credit_note',
        'ap_bill',
        'ap_payment',
        'bank_transaction',
        'depreciation',
        'asset_disposal',
        'tax_remittance',
        'fx_revaluation',
        'manual',
        'year_end',
        'recurring'
    ) NULL;

-- (2) Add updated_at to acc_tax_filing_periods.
--     D19 optimistic locking: every user-editable update endpoint
--     compares updated_at and rejects with 409 STALE_DATA on conflict.
ALTER TABLE acc_tax_filing_periods
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
        AFTER created_at;

-- (3) Add updated_at to acc_tax_remittances.
--     Same reason as above; remittance rows can theoretically be edited
--     (notes, reference number) before the JE is locked into a closed
--     period.
ALTER TABLE acc_tax_remittances
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
        AFTER created_at;
