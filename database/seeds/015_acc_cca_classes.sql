-- ---------------------------------------------------------------------------
-- 015_acc_cca_classes.sql
--
-- S-ACCT-CCA-1 — seed the 9 CCA classes relevant to a Canadian fleet
-- operation per ACCOUNTING_SPEC §23.3. Idempotent via UNIQUE(class_number)
-- INSERT IGNORE — re-running is a no-op once seeded.
--
-- Rate is expressed as DECIMAL(5,4) — e.g. 0.4000 = 40%.
-- All classes use the declining-balance method unless noted otherwise
-- (all 9 standard classes here are declining balance).
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `acc_cca_classes`
    (`class_number`, `description`, `rate`, `method`, `half_year_rule`, `aiip_eligible`, `recapture_applies`, `terminal_loss_applies`, `one_asset_per_class`, `notes`)
VALUES
('8',    'Office furniture, shop tools > $500',                                       0.2000, 'declining_balance', 1, 1, 1, 1, 0, 'Standard 20% class for fixtures + larger tools.'),
('10',   'Motor vehicles ≤ $30K',                                                     0.3000, 'declining_balance', 1, 1, 1, 1, 0, 'Standard motor vehicle class; passenger vehicles ≤ the prescribed price ceiling go here.'),
('10.1', 'Passenger vehicles > $38K ceiling (2025+); one asset per class',            0.3000, 'declining_balance', 1, 1, 1, 1, 1, 'Each Class 10.1 asset is its own class with its own UCC pool. Recapture/terminal loss do not apply. Half-year rule applies in a special form. one_asset_per_class=1 — engine treats each addition as its own class instance.'),
('12',   'Small tools < $500',                                                        1.0000, 'declining_balance', 0, 1, 1, 1, 0, '100% first-year write-off (no half-year). Includes small tools, kitchen utensils, dies, jigs, software <$500.'),
('16',   'Freight trucks > 11,788 kg GVWR — Mainland primary class',                  0.4000, 'declining_balance', 1, 1, 1, 1, 0, 'Mainland Rentals primary class — heavy freight trucks (GVWR threshold 11,788 kg ≈ 25,990 lbs). Validator hint surfaced on asset create form when equipment_units.weight_capacity_lbs > 25990.'),
('50',   'Computers + system software',                                               0.5500, 'declining_balance', 1, 1, 1, 1, 0, 'Computer hardware + operating system software acquired after Mar 18, 2007.'),
('53',   'M&P equipment 2016–2025',                                                   0.5000, 'declining_balance', 1, 1, 1, 1, 0, 'Manufacturing + processing machinery acquired after 2015 and before 2026. Successor class is Class 43 from 2026.'),
('54',   'Zero-emission passenger (cap $61K)',                                        0.3000, 'declining_balance', 1, 1, 1, 1, 0, 'ZEV passenger vehicles. Cost cap CRA-set ($61K for 2024+). AIIP applies.'),
('55',   'Zero-emission heavy trucks (Class 16 equivalent)',                          0.4000, 'declining_balance', 1, 1, 1, 1, 0, 'ZEV equivalents of Class 16 — heavy freight trucks. Same rate + AIIP profile as Class 16.');
