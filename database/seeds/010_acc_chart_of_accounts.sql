-- ============================================================
-- FleetForge Accounting — Chart of Accounts Seed
-- Reference: FLEETFORGE_ACCOUNTING_SPEC.md §3
--
-- Canadian rental/fleet company COA structure.
-- Header accounts (is_header=1) cannot receive postings.
-- System accounts (is_system=1) cannot be deleted/deactivated.
-- Normal balance: debit for assets/expenses, credit for liab/equity/revenue.
-- ============================================================

-- 1000 Current Assets [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('1000', 'Current Assets', 'asset', 'current_asset', NULL, 1, 'debit', 0, 0, 'Current Assets', 100);

SET @parent_1000 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('1010', 'Cash — Operating Account (CAD)', 'asset', 'current_asset', @parent_1000, 0, 'debit', 1, 1, 'Current Assets', 110),
('1020', 'Cash — USD Account', 'asset', 'current_asset', @parent_1000, 0, 'debit', 0, 1, 'Current Assets', 120),
('1030', 'Accounts Receivable', 'asset', 'current_asset', @parent_1000, 0, 'debit', 1, 0, 'Current Assets', 130),
('1040', 'Allowance for Doubtful Accounts', 'asset', 'current_asset', @parent_1000, 0, 'credit', 0, 0, 'Current Assets', 140),
('1050', 'GST/HST Receivable (Input Tax Credits)', 'asset', 'current_asset', @parent_1000, 0, 'debit', 1, 0, 'Current Assets', 150),
('1060', 'PST Receivable', 'asset', 'current_asset', @parent_1000, 0, 'debit', 0, 0, 'Current Assets', 160),
('1065', 'Prepaid Insurance', 'asset', 'current_asset', @parent_1000, 0, 'debit', 0, 0, 'Current Assets', 165),
('1070', 'Prepaid Expenses — Other', 'asset', 'current_asset', @parent_1000, 0, 'debit', 0, 0, 'Current Assets', 170),
('1080', 'Security Deposits Held (Asset)', 'asset', 'current_asset', @parent_1000, 0, 'debit', 0, 0, 'Current Assets', 180);

-- 1200 Fixed Assets [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('1200', 'Fixed Assets', 'asset', 'fixed_asset', NULL, 1, 'debit', 0, 0, 'Fixed Assets', 200);

SET @parent_1200 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('1210', 'Fleet Equipment — Cost', 'asset', 'fixed_asset', @parent_1200, 0, 'debit', 0, 0, 'Fixed Assets', 210),
('1220', 'Fleet Equipment — Accumulated Depreciation', 'asset', 'fixed_asset', @parent_1200, 0, 'credit', 0, 0, 'Fixed Assets', 220),
('1230', 'Vehicles — Cost', 'asset', 'fixed_asset', @parent_1200, 0, 'debit', 0, 0, 'Fixed Assets', 230),
('1240', 'Vehicles — Accumulated Depreciation', 'asset', 'fixed_asset', @parent_1200, 0, 'credit', 0, 0, 'Fixed Assets', 240),
('1250', 'Office Equipment — Cost', 'asset', 'fixed_asset', @parent_1200, 0, 'debit', 0, 0, 'Fixed Assets', 250),
('1260', 'Office Equipment — Accumulated Depreciation', 'asset', 'fixed_asset', @parent_1200, 0, 'credit', 0, 0, 'Fixed Assets', 260),
('1270', 'Leasehold Improvements — Cost', 'asset', 'fixed_asset', @parent_1200, 0, 'debit', 0, 0, 'Fixed Assets', 270),
('1280', 'Leasehold Improvements — Accumulated Amortization', 'asset', 'fixed_asset', @parent_1200, 0, 'credit', 0, 0, 'Fixed Assets', 280);

-- 2000 Current Liabilities [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('2000', 'Current Liabilities', 'liability', 'current_liability', NULL, 1, 'credit', 0, 0, 'Current Liabilities', 300);

SET @parent_2000 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('2010', 'Accounts Payable', 'liability', 'current_liability', @parent_2000, 0, 'credit', 1, 0, 'Current Liabilities', 310),
('2020', 'Accrued Liabilities', 'liability', 'current_liability', @parent_2000, 0, 'credit', 0, 0, 'Current Liabilities', 320),
('2030', 'GST/HST Payable', 'liability', 'current_liability', @parent_2000, 0, 'credit', 1, 0, 'Current Liabilities', 330),
('2040', 'PST Payable', 'liability', 'current_liability', @parent_2000, 0, 'credit', 1, 0, 'Current Liabilities', 340),
('2050', 'Customer Deposits — Security', 'liability', 'current_liability', @parent_2000, 0, 'credit', 0, 0, 'Current Liabilities', 350),
('2060', 'Customer Credits Liability', 'liability', 'current_liability', @parent_2000, 0, 'credit', 1, 0, 'Current Liabilities', 360),
('2070', 'Current Portion of Long-Term Debt', 'liability', 'current_liability', @parent_2000, 0, 'credit', 0, 0, 'Current Liabilities', 370),
('2080', 'Income Tax Payable', 'liability', 'current_liability', @parent_2000, 0, 'credit', 0, 0, 'Current Liabilities', 380);

-- 2200 Long-Term Liabilities [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('2200', 'Long-Term Liabilities', 'liability', 'long_term_liability', NULL, 1, 'credit', 0, 0, 'Long-Term Liabilities', 400);

SET @parent_2200 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('2210', 'Equipment Loans Payable', 'liability', 'long_term_liability', @parent_2200, 0, 'credit', 0, 0, 'Long-Term Liabilities', 410),
('2220', 'Line of Credit', 'liability', 'long_term_liability', @parent_2200, 0, 'credit', 0, 0, 'Long-Term Liabilities', 420);

-- 3000 Equity [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('3000', 'Equity', 'equity', 'equity', NULL, 1, 'credit', 0, 0, 'Equity', 500);

SET @parent_3000 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('3010', 'Common Shares', 'equity', 'equity', @parent_3000, 0, 'credit', 0, 0, 'Equity', 510),
('3020', 'Retained Earnings', 'equity', 'equity', @parent_3000, 0, 'credit', 1, 0, 'Equity', 520),
('3030', 'Owner Drawings', 'equity', 'equity', @parent_3000, 0, 'debit', 0, 0, 'Equity', 530),
('3040', 'Current Year Net Income', 'equity', 'equity', @parent_3000, 0, 'credit', 1, 0, 'Equity', 540);

-- 4000 Revenue [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('4000', 'Revenue', 'revenue', 'revenue', NULL, 1, 'credit', 0, 0, 'Revenue', 600);

SET @parent_4000 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('4010', 'Chassis Rental Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 610),
('4020', 'Dry Van Rental Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 620),
('4030', 'Reefer Rental Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 630),
('4040', 'Flatbed Rental Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 640),
('4050', 'Other Equipment Rental Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 650),
('4060', 'Mileage Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 660),
('4070', 'Insurance Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 670),
('4080', 'Warranty Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 680),
('4090', 'Late Fee Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 690),
('4100', 'Damage Recovery Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 700),
('4110', 'Other Revenue', 'revenue', 'revenue', @parent_4000, 0, 'credit', 0, 0, 'Revenue', 710);

-- 5000 Cost of Revenue [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('5000', 'Cost of Revenue', 'cost_of_revenue', 'cost_of_revenue', NULL, 1, 'debit', 0, 0, 'Cost of Revenue', 800);

SET @parent_5000 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('5010', 'Depreciation — Rental Fleet', 'cost_of_revenue', 'cost_of_revenue', @parent_5000, 0, 'debit', 0, 0, 'Cost of Revenue', 810),
('5020', 'Fleet Insurance — Direct', 'cost_of_revenue', 'cost_of_revenue', @parent_5000, 0, 'debit', 0, 0, 'Cost of Revenue', 820),
('5030', 'Registration & Licensing — Fleet', 'cost_of_revenue', 'cost_of_revenue', @parent_5000, 0, 'debit', 0, 0, 'Cost of Revenue', 830),
('5040', 'GPS & Telematics Costs', 'cost_of_revenue', 'cost_of_revenue', @parent_5000, 0, 'debit', 0, 0, 'Cost of Revenue', 840);

-- 6000 Operating Expenses [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('6000', 'Operating Expenses', 'operating_expense', 'operating_expense', NULL, 1, 'debit', 0, 0, 'Operating Expenses', 900);

SET @parent_6000 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('6010', 'Maintenance & Repairs — Labour', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 910),
('6020', 'Maintenance & Repairs — Parts', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 920),
('6030', 'Tires', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 930),
('6040', 'Fuel', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 940),
('6050', 'Yard Rent / Lease', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 950),
('6060', 'Utilities', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 960),
('6070', 'Salaries & Wages', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 970),
('6080', 'Employee Benefits & Payroll Costs', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 980),
('6090', 'Office Supplies', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 990),
('6100', 'Software & Subscriptions', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1000),
('6110', 'Professional Fees — Legal', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1010),
('6120', 'Professional Fees — Accounting', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1020),
('6130', 'Insurance — General & Liability', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1030),
('6140', 'Advertising & Marketing', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1040),
('6150', 'Travel & Entertainment', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1050),
('6160', 'Bad Debt Expense', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1060),
('6170', 'Bank Charges', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1070),
('6180', 'Interest Expense', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1080),
('6190', 'Depreciation — Non-Fleet Assets', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1090),
('6200', 'Amortization — Leasehold Improvements', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1100),
('6210', 'Other Operating Expenses', 'operating_expense', 'operating_expense', @parent_6000, 0, 'debit', 0, 0, 'Operating Expenses', 1110);

-- 7000 Other Income / Expense [HEADER]
INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('7000', 'Other Income / Expense', 'other_income', 'other', NULL, 1, 'credit', 0, 0, 'Other Income / Expense', 1200);

SET @parent_7000 = LAST_INSERT_ID();

INSERT INTO acc_accounts (code, name, account_type, account_subtype, parent_id, is_header, normal_balance, is_system, is_bank_account, coa_group, sort_order) VALUES
('7010', 'Gain on Disposal of Assets', 'other_income', 'other', @parent_7000, 0, 'credit', 0, 0, 'Other Income / Expense', 1210),
('7020', 'Loss on Disposal of Assets', 'other_expense', 'other', @parent_7000, 0, 'debit', 0, 0, 'Other Income / Expense', 1220),
('7030', 'Foreign Exchange Gain', 'other_income', 'other', @parent_7000, 0, 'credit', 0, 0, 'Other Income / Expense', 1230),
('7040', 'Foreign Exchange Loss', 'other_expense', 'other', @parent_7000, 0, 'debit', 0, 0, 'Other Income / Expense', 1240),
('7050', 'Interest Income', 'other_income', 'other', @parent_7000, 0, 'credit', 0, 0, 'Other Income / Expense', 1250);
