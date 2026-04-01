-- ============================================================
-- FleetForge — Seed: User Permissions
-- File: database/seeds/003_permissions.sql
--
-- 70 rows: 5 system roles × 14 core modules.
-- Accounting modules (chart_of_accounts, journal_entries, etc.)
-- are Phase 13+ and seeded separately. Do NOT add them here.
--
-- Role IDs resolved by slug JOIN — never hardcoded. Safe to run
-- after 001_roles.sql regardless of auto-increment start value.
--
-- Source of truth: config/permissions.php + REFERENCE.md §12
-- Spec ref: FLEETFORGE_SPEC_FINAL.md §8 (Users & Roles)
-- ============================================================

-- Remove existing core-module rows before re-seeding so this
-- file is idempotent (re-runnable without duplicates).
DELETE FROM user_permissions
WHERE module IN (
    'customers','equipment','leases','reservations',
    'invoices','payments','rates','maintenance',
    'compliance','reports','analytics',
    'users','settings','audit'
);

-- ----------------------------------------------------------------
-- super_admin — full access to everything (VCEDS → all 5 = true)
-- ----------------------------------------------------------------
INSERT INTO user_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
SELECT r.id, m.module, m.v, m.c, m.e, m.d, m.x
FROM user_roles r
JOIN (
    SELECT 'customers'    AS module, 1 v, 1 c, 1 e, 1 d, 1 x UNION ALL
    SELECT 'equipment',             1,   1,   1,   1,   1     UNION ALL
    SELECT 'leases',                1,   1,   1,   1,   1     UNION ALL
    SELECT 'reservations',          1,   1,   1,   1,   1     UNION ALL
    SELECT 'invoices',              1,   1,   1,   1,   1     UNION ALL
    SELECT 'payments',              1,   1,   1,   1,   1     UNION ALL
    SELECT 'rates',                 1,   1,   1,   1,   1     UNION ALL
    SELECT 'maintenance',           1,   1,   1,   1,   1     UNION ALL
    SELECT 'compliance',            1,   1,   1,   1,   1     UNION ALL
    SELECT 'reports',               1,   1,   1,   1,   1     UNION ALL
    SELECT 'analytics',             1,   1,   1,   1,   1     UNION ALL
    SELECT 'users',                 1,   1,   1,   1,   1     UNION ALL
    SELECT 'settings',              1,   1,   1,   1,   1     UNION ALL
    SELECT 'audit',                 1,   1,   1,   1,   1
) m ON 1=1
WHERE r.slug = 'super_admin';

-- ----------------------------------------------------------------
-- manager — broad access, no export, limited admin modules
-- customers/equipment/leases/reservations/invoices/payments: VCED
-- rates/maintenance/compliance: VCE (no delete)
-- reports/analytics: VCE
-- users/settings/audit: V only
-- ----------------------------------------------------------------
INSERT INTO user_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
SELECT r.id, m.module, m.v, m.c, m.e, m.d, m.x
FROM user_roles r
JOIN (
    SELECT 'customers'    AS module, 1 v, 1 c, 1 e, 1 d, 0 x UNION ALL
    SELECT 'equipment',             1,   1,   1,   1,   0     UNION ALL
    SELECT 'leases',                1,   1,   1,   1,   0     UNION ALL
    SELECT 'reservations',          1,   1,   1,   1,   0     UNION ALL
    SELECT 'invoices',              1,   1,   1,   1,   0     UNION ALL
    SELECT 'payments',              1,   1,   1,   1,   0     UNION ALL
    SELECT 'rates',                 1,   1,   1,   0,   0     UNION ALL
    SELECT 'maintenance',           1,   1,   1,   1,   0     UNION ALL
    SELECT 'compliance',            1,   1,   1,   1,   0     UNION ALL
    SELECT 'reports',               1,   1,   1,   0,   0     UNION ALL
    SELECT 'analytics',             1,   1,   1,   0,   0     UNION ALL
    SELECT 'users',                 1,   0,   0,   0,   0     UNION ALL
    SELECT 'settings',              1,   0,   0,   0,   0     UNION ALL
    SELECT 'audit',                 1,   0,   0,   0,   0
) m ON 1=1
WHERE r.slug = 'manager';

-- ----------------------------------------------------------------
-- dispatcher — operational access only, no financial data
-- customers: VC (create but not edit/delete)
-- equipment/leases/maintenance: VCE
-- reservations: VCED
-- invoices: V only (API strips dollar amounts — see REFERENCE.md §12)
-- payments/rates/reports/analytics/users/settings/audit: NONE
-- compliance: VE (view + edit, no create/delete)
-- ----------------------------------------------------------------
INSERT INTO user_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
SELECT r.id, m.module, m.v, m.c, m.e, m.d, m.x
FROM user_roles r
JOIN (
    SELECT 'customers'    AS module, 1 v, 1 c, 0 e, 0 d, 0 x UNION ALL
    SELECT 'equipment',             1,   1,   1,   0,   0     UNION ALL
    SELECT 'leases',                1,   1,   1,   0,   0     UNION ALL
    SELECT 'reservations',          1,   1,   1,   1,   0     UNION ALL
    SELECT 'invoices',              1,   0,   0,   0,   0     UNION ALL
    SELECT 'payments',              0,   0,   0,   0,   0     UNION ALL
    SELECT 'rates',                 0,   0,   0,   0,   0     UNION ALL
    SELECT 'maintenance',           1,   1,   1,   0,   0     UNION ALL
    SELECT 'compliance',            1,   0,   1,   0,   0     UNION ALL
    SELECT 'reports',               0,   0,   0,   0,   0     UNION ALL
    SELECT 'analytics',             0,   0,   0,   0,   0     UNION ALL
    SELECT 'users',                 0,   0,   0,   0,   0     UNION ALL
    SELECT 'settings',              0,   0,   0,   0,   0     UNION ALL
    SELECT 'audit',                 0,   0,   0,   0,   0
) m ON 1=1
WHERE r.slug = 'dispatcher';

-- ----------------------------------------------------------------
-- accountant — full financial access, limited operational
-- invoices/payments: VCED
-- customers/equipment/leases/reservations/rates/maintenance/compliance/analytics: V only
-- reports: VCE
-- users/settings: NONE
-- audit: V
-- ----------------------------------------------------------------
INSERT INTO user_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
SELECT r.id, m.module, m.v, m.c, m.e, m.d, m.x
FROM user_roles r
JOIN (
    SELECT 'customers'    AS module, 1 v, 0 c, 0 e, 0 d, 0 x UNION ALL
    SELECT 'equipment',             1,   0,   0,   0,   0     UNION ALL
    SELECT 'leases',                1,   0,   0,   0,   0     UNION ALL
    SELECT 'reservations',          1,   0,   0,   0,   0     UNION ALL
    SELECT 'invoices',              1,   1,   1,   1,   0     UNION ALL
    SELECT 'payments',              1,   1,   1,   1,   0     UNION ALL
    SELECT 'rates',                 1,   0,   0,   0,   0     UNION ALL
    SELECT 'maintenance',           1,   0,   0,   0,   0     UNION ALL
    SELECT 'compliance',            1,   0,   0,   0,   0     UNION ALL
    SELECT 'reports',               1,   1,   1,   0,   0     UNION ALL
    SELECT 'analytics',             1,   0,   0,   0,   0     UNION ALL
    SELECT 'users',                 0,   0,   0,   0,   0     UNION ALL
    SELECT 'settings',              0,   0,   0,   0,   0     UNION ALL
    SELECT 'audit',                 1,   0,   0,   0,   0
) m ON 1=1
WHERE r.slug = 'accountant';

-- ----------------------------------------------------------------
-- read_only — view everything except users/settings (no write anywhere)
-- ----------------------------------------------------------------
INSERT INTO user_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
SELECT r.id, m.module, m.v, m.c, m.e, m.d, m.x
FROM user_roles r
JOIN (
    SELECT 'customers'    AS module, 1 v, 0 c, 0 e, 0 d, 0 x UNION ALL
    SELECT 'equipment',             1,   0,   0,   0,   0     UNION ALL
    SELECT 'leases',                1,   0,   0,   0,   0     UNION ALL
    SELECT 'reservations',          1,   0,   0,   0,   0     UNION ALL
    SELECT 'invoices',              1,   0,   0,   0,   0     UNION ALL
    SELECT 'payments',              1,   0,   0,   0,   0     UNION ALL
    SELECT 'rates',                 1,   0,   0,   0,   0     UNION ALL
    SELECT 'maintenance',           1,   0,   0,   0,   0     UNION ALL
    SELECT 'compliance',            1,   0,   0,   0,   0     UNION ALL
    SELECT 'reports',               1,   0,   0,   0,   0     UNION ALL
    SELECT 'analytics',             1,   0,   0,   0,   0     UNION ALL
    SELECT 'users',                 0,   0,   0,   0,   0     UNION ALL
    SELECT 'settings',              0,   0,   0,   0,   0     UNION ALL
    SELECT 'audit',                 1,   0,   0,   0,   0
) m ON 1=1
WHERE r.slug = 'read_only';
