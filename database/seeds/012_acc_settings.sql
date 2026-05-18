-- ---------------------------------------------------------------------------
-- 012_acc_settings.sql
--
-- Canonical seed: 18 accounting settings per FLEETFORGE_ACCOUNTING_SPEC.md §17.
--
-- These are the base keys only. Year-suffixed counters (e.g.
-- accounting.je_next_number.2025) are auto-created by AccountingService at
-- runtime, so a fresh DB only needs the 18 baseline rows here. Extension
-- keys added by later sessions (S036 budgets, S037-FX revaluation, etc.)
-- are also out of scope for this baseline seed.
--
-- Idempotent via INSERT IGNORE against the UNIQUE KEY on settings.key.
--
-- Re-creation context: S037-CRUD (Phase B CRUD completion / spec §22.5).
-- Original file referenced in S028 PROGRESS.md log but never committed to
-- repo; restored 2026-05-19 by transcribing live DB values for the 18
-- canonical keys listed in spec §17.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
('accounting.enabled',                     'true',          'boolean', 'accounting', 'Accounting Module Enabled',     'Enable or disable the accounting module', 0),
('accounting.ar_account_id',               '4',             'integer', 'accounting', 'AR Account',                    'GL account for Accounts Receivable', 0),
('accounting.ap_account_id',               '21',            'integer', 'accounting', 'AP Account',                    'GL account for Accounts Payable', 0),
('accounting.default_cash_account_id',     '2',             'integer', 'accounting', 'Default Cash Account',          'Default cash account for payments', 0),
('accounting.gst_payable_account_id',      '23',            'integer', 'accounting', 'GST Payable Account',           'GL account for GST/HST collected', 0),
('accounting.pst_payable_account_id',      '24',            'integer', 'accounting', 'PST Payable Account',           'GL account for PST collected', 0),
('accounting.gst_receivable_account_id',   '6',             'integer', 'accounting', 'GST Receivable (ITC)',          'GL account for GST/HST input tax credits', 0),
('accounting.bad_debt_expense_account_id', '70',            'integer', 'accounting', 'Bad Debt Expense',              'GL account for bad debt write-offs', 0),
('accounting.fx_gain_account_id',          '79',            'integer', 'accounting', 'FX Gain Account',               'GL account for foreign exchange gains', 0),
('accounting.fx_loss_account_id',          '80',            'integer', 'accounting', 'FX Loss Account',               'GL account for foreign exchange losses', 0),
('accounting.capex_threshold_cad',         '2500',          'decimal', 'accounting', 'CapEx Threshold (CAD)',         'Work orders above this prompt capitalization review', 0),
('accounting.default_depreciation_method', 'straight_line', 'string',  'accounting', 'Default Depreciation Method',   'Default for new assets', 0),
('accounting.default_useful_life_years',   '10',            'integer', 'accounting', 'Default Useful Life (Years)',   'Default for fleet assets', 0),
('accounting.default_salvage_pct',         '0.10',          'decimal', 'accounting', 'Default Salvage %',             'Default salvage value as fraction of cost', 0),
('accounting.gst_filing_frequency',        'quarterly',     'string',  'accounting', 'GST Filing Frequency',          'monthly, quarterly, or annually', 0),
('accounting.pst_filing_frequency',        'quarterly',     'string',  'accounting', 'PST Filing Frequency',          'monthly, quarterly, or annually', 0),
('accounting.revenue_account_map',
    '{"base_rental_chassis":"4010","base_rental_dry_van":"4020","base_rental_reefer":"4030","base_rental_flatbed":"4040","base_rental_other":"4050","mileage_precharge":"4060","mileage_adjustment":"4060","mileage_credit":"4060","insurance":"4070","warranty":"4080","late_fee":"4090","damage":"4100","manual_adjustment":"4110","other":"4110"}',
    'json', 'accounting', 'Revenue Account Map', 'Maps invoice line types to GL revenue accounts', 0),
('accounting.expense_account_map',
    '{"maintenance":"6010","parts":"6020","tires":"6030","fuel":"6040","insurance":"6130","professional":"6110","software":"6100","office":"6090","other":"6210"}',
    'json', 'accounting', 'Expense Account Map', 'Maps vendor types to default expense accounts', 0);
