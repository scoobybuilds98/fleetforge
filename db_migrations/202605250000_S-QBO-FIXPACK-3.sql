-- ============================================================
-- S-QBO-FIXPACK-3 — Seed multi-currency detection settings.
--
-- Adds 3 auto-detected settings that CompanyInfoSync populates
-- at OAuth connect time and at token refresh time:
--
--   quickbooks.multi_currency_enabled
--     '0' (default) or '1'. Controls CurrencyRef emission across
--     all Pushers (D-QBO-FIXPACK-12). Conservative default: no
--     CurrencyRef until CompanyInfo is queried post-connect, to
--     avoid QBO error 6000 ("Multi Currency should be enabled")
--     on single-currency companies.
--
--   quickbooks.home_currency
--     'CAD' (default) or 'USD' etc. Auto-detected from QBO
--     CompanyInfo.Country mapping. Mainland is Canadian (spec §0)
--     so default is 'CAD'.
--
--   quickbooks.company_country
--     Raw Country field from QBO CompanyInfo (e.g. 'CA', 'US').
--     Empty until first CompanyInfo sync.
--
-- Safe to re-run: INSERT IGNORE skips rows that already exist
-- (settings.key is UNIQUE). CompanyInfoSync overwrites these via
-- ON DUPLICATE KEY UPDATE at first connect/refresh, so seeded
-- defaults are only ever operative before the first OAuth connect.
--
-- @session  S-QBO-FIXPACK-3
-- @decision D-QBO-FIXPACK-11 (CompanyInfo auto-detection),
--           D-QBO-FIXPACK-12 (CurrencyRef gate on setting),
--           D-QBO-FIXPACK-13 (safe defaults)
-- ============================================================

INSERT IGNORE INTO settings (`key`, `value`, `value_type`, `group_name`, `description`, `updated_at`)
VALUES
    (
        'quickbooks.multi_currency_enabled',
        '0',
        'boolean',
        'quickbooks',
        'Auto-detected from QBO CompanyInfo at OAuth connect and token refresh time. 1=multi-currency enabled; 0=disabled. Controls CurrencyRef emission in CustomerPusher, VendorPusher, InvoicePusher, and InvoicePreflightGate (D-QBO-FIXPACK-12). Conservative default: emit no CurrencyRef until CompanyInfo is queried to avoid QBO error 6000.',
        NOW()
    ),
    (
        'quickbooks.home_currency',
        'CAD',
        'string',
        'quickbooks',
        'Auto-detected from QBO CompanyInfo Country field. Home currency of the connected QBO company. Defaults to CAD (Mainland is Canadian per spec §0); overwritten by CompanyInfoSync on first connect/refresh.',
        NOW()
    ),
    (
        'quickbooks.company_country',
        '',
        'string',
        'quickbooks',
        'Auto-detected from QBO CompanyInfo. Raw Country field (e.g. CA, US). Empty until first CompanyInfo sync at OAuth connect or token refresh.',
        NOW()
    );
