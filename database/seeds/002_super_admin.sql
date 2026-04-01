-- ============================================================
-- FleetForge — Seed: Super Admin User
--
-- Creates the initial super_admin account.
-- CHANGE THE PASSWORD IMMEDIATELY AFTER FIRST LOGIN.
--
-- Temp credentials:
--   Email:    admin@fleetforge.test
--   Password: FleetForge2025!
--
-- Password hash: bcrypt cost=12
-- ============================================================

INSERT IGNORE INTO users (
    name,
    email,
    password_hash,
    role_id,
    status,
    theme_preference,
    created_at,
    updated_at
) VALUES (
    'System Administrator',
    'admin@fleetforge.test',
    '$2y$12$QHrZBsVsSklnPR61F9YW4ONzmh7LueUHsWX72AbNqYmpYOCZjZjpy',
    (SELECT id FROM user_roles WHERE slug = 'super_admin'),
    'active',
    'dark',
    NOW(),
    NOW()
);
