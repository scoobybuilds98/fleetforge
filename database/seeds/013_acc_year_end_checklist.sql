-- ---------------------------------------------------------------------------
-- 013_acc_year_end_checklist.sql
--
-- Canonical seed: 17 year-end checklist items per
-- FLEETFORGE_ACCOUNTING_SPEC.md §22.2.
--
-- Item rows are scoped to a fiscal year via acc_year_end_checklist.year.
-- Set @fy to the target year before running. The default below uses the
-- current calendar year, matching the seed expectation that a fresh DB
-- gets a checklist for the year of installation.
--
-- Idempotent via INSERT IGNORE against the UNIQUE KEY uq_year_item
-- (year, item_key).
--
-- Re-creation context: S037-CRUD (Phase B CRUD completion / spec §22.5).
-- Original file referenced in S028 PROGRESS.md log but never committed to
-- repo. The live DB grew from 15 → 17 items via S037-YE migration; the 17
-- canonical items are transcribed below.
--
-- To seed a specific year:
--   SET @fy = 2027; SOURCE database/seeds/013_acc_year_end_checklist.sql;
-- ---------------------------------------------------------------------------

SET @fy := COALESCE(@fy, YEAR(CURDATE()));

INSERT IGNORE INTO `acc_year_end_checklist` (`year`, `item_key`, `item_label`, `is_complete`, `sort_order`) VALUES
(@fy, 'bank_recon_dec',            CONCAT('Reconcile all bank accounts for December ', @fy),                       0,  10),
(@fy, 'ap_review',                 'Review all outstanding A/P bills and ensure posted',                           0,  20),
(@fy, 'ar_review',                 'Review A/R aging and write off bad debts',                                     0,  30),
(@fy, 'depreciation_q4',           'Run Q4 depreciation for all fixed assets',                                     0,  40),
(@fy, 'physical_inv',              'Physical inventory count for fleet + spare parts',                             0,  50),
(@fy, 'inventory_adjust',          'Post inventory adjustments from physical count',                               0,  60),
(@fy, 'accruals',                  'Post year-end accruals (unpaid maintenance, insurance)',                       0,  70),
(@fy, 'prepaid_amortize',          'Amortize prepaid expenses (insurance, registrations)',                         0,  80),
(@fy, 'tax_provision',             'Calculate and post corporate tax provision',                                   0,  90),
(@fy, 'close_periods',             CONCAT('Close all ', @fy, ' periods (lock against editing)'),                   0, 100),
(@fy, 'gst_return',                CONCAT('File final GST/HST return for ', @fy),                                  0, 110),
(@fy, 'gifi_export',               'Export GIFI-formatted financials for accountant',                              0, 120),
(@fy, 'retained_earnings',         'Post year-end close entry to retained earnings',                               0, 130),
(@fy, 'annual_reports',            'Generate annual financial statements',                                         0, 140),
(@fy, 'archive',                   CONCAT('Archive ', @fy, ' supporting documents'),                               0, 150),
(@fy, 'fx_revaluation_ye',         'Run FX revaluation for year-end USD balances',                                 0, 160),
(@fy, 'lease_amortization_review', 'Review lease amortization schedule completeness for fiscal year',              0, 170);
