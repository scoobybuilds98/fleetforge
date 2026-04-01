-- ============================================================
-- FleetForge — Seed: Default Yard
-- File: database/seeds/005_yard.sql
--
-- Seeds 1 default yard (Surrey, BC) matching the company address
-- seeded in 004_settings.sql. Slug 'surrey' matches yard.default
-- in settings.
--
-- At minimum one yard must exist before equipment units can be
-- added (spec: §6 Yards — guided setup step 2).
--
-- Spec ref: FLEETFORGE_SPEC_FINAL.md §6 (Yards)
-- ============================================================

INSERT IGNORE INTO yards (name, slug, address, city, state, postal_code, capacity, is_active) VALUES
(
    'Surrey Yard',
    'surrey',
    '9045 King George Blvd',
    'Surrey',
    'BC',
    'V3W 3X2',
    100,
    1
);
