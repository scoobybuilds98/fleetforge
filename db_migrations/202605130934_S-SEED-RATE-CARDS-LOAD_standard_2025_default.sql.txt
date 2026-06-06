-- ============================================================
-- 202605130934_S-SEED-RATE-CARDS-LOAD_standard_2025_default.sql
--
-- S-SEED-RATE-CARDS-LOAD C2 — load the "Standard 2025" rate
-- card as the is_default=1 system-wide fallback in rate_cards,
-- with full rate_card_items rows for the 5 main equipment
-- categories per BASE_RATES in scripts/seed_rate_cards.php.
--
-- Pre-condition (verified in pre-work scan P1, 2026-05-13):
--   SELECT COUNT(*) FROM rate_cards WHERE is_default = 1 → 0
--   (4 existing customer-named cards all have is_default=0;
--   no system-wide fallback row currently exists.)
--
-- Rate-lookup fallback chain (api/v1/leases/lookup_rates.php
-- Priority 2): ORDER BY rc.is_default DESC, rc.effective_from
-- DESC LIMIT 1 — so an is_default=1 card is preferred over
-- is_default=0 cards. With this migration applied, customers
-- without a customer_equipment_rates row will resolve to the
-- Standard 2025 rates for the 5 covered categories
-- (dry_van / reefer / flatbed / container / chassis) instead
-- of falling through to equipment_templates defaults.
--
-- Rate values — Standard 2025 (CAD):
--   dry_van:   daily $125, weekly $800,  monthly $2200, mileage $0.18
--   reefer:    daily $145, weekly $950,  monthly $3200, mileage $0.18  ← live rate per S-REEFER-RATE-AUDIT
--   flatbed:   daily $120, weekly $780,  monthly $2100, mileage $0.17
--   container: daily  $95, weekly $620,  monthly $1700, mileage $0.15
--   chassis:   daily  $80, weekly $520,  monthly $1400, mileage $0.13
--
-- Reefer mileage uses $0.18 (live production default per
-- S-REEFER-RATE-AUDIT disposition C, 2026-05-13) — NOT the
-- $0.22 rubric value in scripts/seed_rate_cards.php BASE_RATES
-- (which carries an inline comment explaining the divergence).
-- All other rates match the seed-script rubric exactly.
--
-- Specialty types (lowboy / step_deck / tanker / dump / other):
-- intentionally NOT populated — these are negotiated-rate-only
-- per current pricing policy. Customers leasing those types
-- need an explicit customer_equipment_rates row or rate card.
-- The lookup chain falls through to equipment_templates
-- defaults (Priority 3) for these categories, which is the
-- behavior preserved by omission.
--
-- D134 category convention: equipment_type stores the
-- equipment_templates.category enum value (e.g., 'dry_van',
-- 'reefer'), NOT the template name. All rows use category
-- strings per D134.
--
-- Idempotency: rate_cards INSERT guarded by NOT EXISTS check
-- on name + is_default + deleted_at; rate_card_items inserts
-- guarded by NOT EXISTS check on rate_card_id + equipment_type.
-- Re-running this migration against an already-loaded DB is a
-- no-op (the cards + items exist; guards skip every INSERT).
--
-- effective_from: 2025-01-01 (start of the rate-year the card
-- represents — operates retroactively for any 2025 lookup).
-- The 4 existing customer-named cards use 2025-10-09; the
-- Standard 2025 fallback opens earlier so it covers any
-- pre-October 2025 lookup that would otherwise fall through.
--
-- created_by: user_id 1 (Avi / system seed account) — matches
-- the convention used by the 4 existing rate cards.
--
-- NO master file schema change. This is a DATA migration only.
-- A documentation comment was added to FLEETFORGE_DATABASE_
-- MASTER.sql at the rate_cards CREATE TABLE block noting the
-- existence of the is_default=1 seed row loaded by this
-- migration; the comment is informational, not structural,
-- and is preserved by mysqldump as table-level metadata
-- (parity smoke passes).
--
-- Author:   S-SEED-RATE-CARDS-LOAD
-- Date:     2026-05-13 (local) / 2026-05-13 09:34 (UTC)
-- Spec:     FLEETFORGE_CURRENT_SESSIONS.md S-SEED-RATE-CARDS-
--           LOAD QUEUED entry + this session prompt
-- Decisions: D134 (rate_card_items.equipment_type stores
--           templates.category), S-REEFER-RATE-AUDIT
--           disposition C (reefer $0.18 live, not rubric $0.22).
-- ============================================================

-- ── 1. Insert rate_cards row (idempotent via NOT EXISTS) ───
INSERT INTO rate_cards
    (name, description, is_default, effective_from, effective_to,
     created_by, created_at, updated_at)
SELECT
    'Standard 2025',
    'Mainland T&T standard base rates (CAD, 2025). System-wide fallback for customers without a negotiated customer_equipment_rates row or named rate card. Covers 5 main equipment categories: dry_van, reefer, flatbed, container, chassis. Specialty types (lowboy / step_deck / tanker / dump / other) fall through to equipment_templates defaults at Priority 3. Loaded via S-SEED-RATE-CARDS-LOAD 2026-05-13. Reefer mileage uses live $0.18 per S-REEFER-RATE-AUDIT disposition (C), NOT the seed-script rubric $0.22.',
    1,
    '2025-01-01',
    NULL,
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM rate_cards
    WHERE name        = 'Standard 2025'
      AND is_default  = 1
      AND deleted_at IS NULL
);

-- ── 2. Capture the rate_card_id for the items inserts ──────
-- Re-resolve via SELECT so idempotency works on re-run (when
-- the row was inserted on a prior run). LIMIT 1 + deterministic
-- name match prevents collision with any future test card.
SET @ff_standard_2025_id = (
    SELECT id FROM rate_cards
    WHERE name        = 'Standard 2025'
      AND is_default  = 1
      AND deleted_at IS NULL
    ORDER BY id ASC
    LIMIT 1
);

-- Defensive assertion: if @ff_standard_2025_id is NULL after
-- the INSERT above, something went wrong (e.g. row was soft-
-- deleted between steps). SIGNAL halts the migration with an
-- operator-facing message rather than silently inserting items
-- against a NULL rate_card_id (which would FK-violate).
DROP PROCEDURE IF EXISTS ff_assert_standard_2025_id;
DELIMITER //
CREATE PROCEDURE ff_assert_standard_2025_id()
BEGIN
    IF @ff_standard_2025_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'S-SEED-RATE-CARDS-LOAD: Standard 2025 is_default=1 rate_cards row not found after INSERT. Check soft-delete state before retry.';
    END IF;
END //
DELIMITER ;
CALL ff_assert_standard_2025_id();
DROP PROCEDURE IF EXISTS ff_assert_standard_2025_id;

-- ── 3. Insert rate_card_items rows (one per category) ──────
-- Each INSERT guarded by NOT EXISTS on (rate_card_id,
-- equipment_type) so re-running is a no-op. Full rate set
-- (daily/weekly/monthly/mileage) populated per BASE_RATES
-- in scripts/seed_rate_cards.php (with reefer mileage
-- overridden to live $0.18 per S-REEFER-RATE-AUDIT).
INSERT INTO rate_card_items
    (rate_card_id, equipment_type, daily_rate, weekly_rate,
     monthly_rate, mileage_rate, mileage_unit, currency, notes,
     created_at, updated_at)
SELECT @ff_standard_2025_id, 'dry_van', 125.00, 800.00, 2200.00, 0.1800, 'km', 'CAD',
       'Standard 2025 base rate (S-SEED-RATE-CARDS-LOAD 2026-05-13).',
       NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM rate_card_items
    WHERE rate_card_id  = @ff_standard_2025_id
      AND equipment_type = 'dry_van'
);

INSERT INTO rate_card_items
    (rate_card_id, equipment_type, daily_rate, weekly_rate,
     monthly_rate, mileage_rate, mileage_unit, currency, notes,
     created_at, updated_at)
SELECT @ff_standard_2025_id, 'reefer', 145.00, 950.00, 3200.00, 0.1800, 'km', 'CAD',
       'Standard 2025 base rate (S-SEED-RATE-CARDS-LOAD 2026-05-13). Mileage uses live $0.18 per S-REEFER-RATE-AUDIT disposition (C), not seed-script rubric $0.22.',
       NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM rate_card_items
    WHERE rate_card_id  = @ff_standard_2025_id
      AND equipment_type = 'reefer'
);

INSERT INTO rate_card_items
    (rate_card_id, equipment_type, daily_rate, weekly_rate,
     monthly_rate, mileage_rate, mileage_unit, currency, notes,
     created_at, updated_at)
SELECT @ff_standard_2025_id, 'flatbed', 120.00, 780.00, 2100.00, 0.1700, 'km', 'CAD',
       'Standard 2025 base rate (S-SEED-RATE-CARDS-LOAD 2026-05-13).',
       NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM rate_card_items
    WHERE rate_card_id  = @ff_standard_2025_id
      AND equipment_type = 'flatbed'
);

INSERT INTO rate_card_items
    (rate_card_id, equipment_type, daily_rate, weekly_rate,
     monthly_rate, mileage_rate, mileage_unit, currency, notes,
     created_at, updated_at)
SELECT @ff_standard_2025_id, 'container', 95.00, 620.00, 1700.00, 0.1500, 'km', 'CAD',
       'Standard 2025 base rate (S-SEED-RATE-CARDS-LOAD 2026-05-13).',
       NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM rate_card_items
    WHERE rate_card_id  = @ff_standard_2025_id
      AND equipment_type = 'container'
);

INSERT INTO rate_card_items
    (rate_card_id, equipment_type, daily_rate, weekly_rate,
     monthly_rate, mileage_rate, mileage_unit, currency, notes,
     created_at, updated_at)
SELECT @ff_standard_2025_id, 'chassis', 80.00, 520.00, 1400.00, 0.1300, 'km', 'CAD',
       'Standard 2025 base rate (S-SEED-RATE-CARDS-LOAD 2026-05-13).',
       NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM rate_card_items
    WHERE rate_card_id  = @ff_standard_2025_id
      AND equipment_type = 'chassis'
);
