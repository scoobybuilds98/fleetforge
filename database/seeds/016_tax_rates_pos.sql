-- ---------------------------------------------------------------------------
-- 016_tax_rates_pos.sql
--
-- S-ACCT-POS — seed NS HST historic row + acc_place_of_supply_rules
-- per spec §23.6. Idempotent: NS historic row keyed by unique
-- (province, effective_from, effective_to) tuple; POS rule rows
-- INSERT IGNORE against UNIQUE(rule_type, province_code).
--
-- K-22 catches applied (locked alongside this seed):
--   - tax_rates wide-row design (one province row → gst_rate / pst_rate /
--     hst_rate columns; not tax_type+rate tall rows).
--   - tax_rates.province (varchar(100), holds the 2-letter code in practice).
--   - applicable_tax_rate_ids JSON stores a single tax_rates.id per province —
--     the resolver pulls all 3 rate columns from that single row.
--   - Province codes: ISO-3166-2 short codes (NT not NWT) — matches what's
--     on disk in tax_rates.province.
-- ---------------------------------------------------------------------------

-- ── 1. NS HST historic row (15% pre-2025-04-01) ────────────────────────────
-- Inserts the historic row only when no NS row with that effective window
-- exists already. The current 14% row (id=7, effective_from=2025-04-01)
-- stays as-is — effective_to NULL means "current" going forward.
INSERT INTO `tax_rates`
    (`name`, `province`, `country`, `gst_rate`, `pst_rate`, `hst_rate`,
     `is_default`, `is_active`, `effective_from`, `effective_to`, `notes`)
SELECT 'Nova Scotia (historic)', 'NS', 'CA',
       0.000000, 0.000000, 0.150000,
       0, 1, '2000-01-01', '2025-03-31',
       'Historic NS HST 15% pre-April-2025. Replaced by NS HST 14% on 2025-04-01.'
  FROM dual
 WHERE NOT EXISTS (
    SELECT 1 FROM `tax_rates`
     WHERE `province` = 'NS'
       AND `effective_from` <= '2025-03-31'
       AND (`effective_to` IS NULL OR `effective_to` >= '2025-03-31')
       AND `hst_rate` = 0.150000
 );

-- ── 2. acc_place_of_supply_rules — 13 provinces × 4 rule_types = 52 rows ──
-- Each row stores the current (effective_to IS NULL) tax_rates.id for the
-- province as a single-element JSON array. The resolver loads that row +
-- filters by transaction_date at query time.
--
-- specified_motor_vehicle rule_type is not seeded yet — added on demand
-- when Mainland starts selling/transferring fleet between provinces.

INSERT IGNORE INTO `acc_place_of_supply_rules`
    (`rule_type`, `province_code`, `applicable_tax_rate_ids`, `notes`)
SELECT 'short_lease',     `province`, JSON_ARRAY(`id`),
       CONCAT('Short lease ≤ 3 months — POS = delivery province (', `province`, ').')
  FROM `tax_rates`
 WHERE `country` = 'CA' AND `is_active` = 1 AND `effective_to` IS NULL
 ORDER BY `province`;

INSERT IGNORE INTO `acc_place_of_supply_rules`
    (`rule_type`, `province_code`, `applicable_tax_rate_ids`, `notes`)
SELECT 'long_lease',      `province`, JSON_ARRAY(`id`),
       CONCAT('Long lease > 3 months — POS = ordinarily-located province (', `province`, '). Falls back to customer.province (leases table has no on-disk ordinarily_located_province column).')
  FROM `tax_rates`
 WHERE `country` = 'CA' AND `is_active` = 1 AND `effective_to` IS NULL
 ORDER BY `province`;

INSERT IGNORE INTO `acc_place_of_supply_rules`
    (`rule_type`, `province_code`, `applicable_tax_rate_ids`, `notes`)
SELECT 'service',         `province`, JSON_ARRAY(`id`),
       CONCAT('Service — POS = customer billing province (', `province`, ').')
  FROM `tax_rates`
 WHERE `country` = 'CA' AND `is_active` = 1 AND `effective_to` IS NULL
 ORDER BY `province`;

INSERT IGNORE INTO `acc_place_of_supply_rules`
    (`rule_type`, `province_code`, `applicable_tax_rate_ids`, `notes`)
SELECT 'goods_delivered', `province`, JSON_ARRAY(`id`),
       CONCAT('Goods delivered to recipient — POS = delivery province (', `province`, ').')
  FROM `tax_rates`
 WHERE `country` = 'CA' AND `is_active` = 1 AND `effective_to` IS NULL
 ORDER BY `province`;
