-- ============================================================
-- FleetForge — Seed: User Roles
-- 5 system roles. is_system=1 prevents deletion via UI.
-- Matches config/permissions.php and FLEETFORGE_SPEC_FINAL.md §8 (Users)
-- ============================================================

INSERT IGNORE INTO user_roles (name, slug, description, is_system) VALUES
    ('Super Admin',  'super_admin',  'Full access to all modules and settings. Can manage users and roles.', 1),
    ('Manager',      'manager',      'Broad operational access. Cannot manage users or system settings.', 1),
    ('Dispatcher',   'dispatcher',   'Operational access: customers, equipment, leases, reservations, maintenance. No financial data.', 1),
    ('Accountant',   'accountant',   'Full financial access: invoices, payments, accounting modules. Limited operational access.', 1),
    ('Read Only',    'read_only',    'View-only access to all modules except user management and settings.', 1);
