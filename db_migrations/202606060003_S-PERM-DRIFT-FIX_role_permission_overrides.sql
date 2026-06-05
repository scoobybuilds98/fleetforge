-- ============================================================================
-- S-PERM-DRIFT-FIX — register role_permission_overrides in the migration runner
-- ============================================================================
--
-- DRIFT REMEDIATION (surfaced during S-CCA-1 pre-commit parity, operator-directed
-- via AskUserQuestion to fix in a standalone commit).
--
-- Commit 6cda088 ("feat(permissions): role-level permission editor + 3-layer
-- resolution") created `role_permission_overrides` in the live dev DB via a file
-- placed in the DEPRECATED `database/migrations/` directory — which `bin/migrate.php`
-- (the only sanctioned runner, scanning `db_migrations/`) never reads. Consequences:
--   1. `_smoke_master_schema_parity.php` went red (table in live, absent from
--      FLEETFORGE_DATABASE_MASTER.sql) — red since 6cda088.
--   2. A FRESH deploy via `bin/migrate.php` would be MISSING the table entirely,
--      breaking the role-level permission editor on a new install.
--
-- This migration registers the table in the sanctioned runner so fresh deploys
-- create it, and FLEETFORGE_DATABASE_MASTER.sql is updated in the same commit so
-- parity is restored. CREATE TABLE IF NOT EXISTS → no-op where the table already
-- exists (existing dev/prod DBs), creates it on fresh installs.
--
-- The misplaced `database/migrations/2026_06_05_role_permission_overrides.sql`
-- is removed in this same commit (it was never applied by any runtime path).
--
-- MIGRATE COUNT: 94 → 95.
--
-- @session  S-PERM-DRIFT-FIX (carved out of S-CCA-1 pre-flight)
-- @origin   6cda088 — table created in deprecated database/migrations/ dir
-- ============================================================================

CREATE TABLE IF NOT EXISTS `role_permission_overrides` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `role_id`    int unsigned NOT NULL,
  `module`     varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action`     varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `granted`    tinyint(1) NOT NULL COMMENT '1=allow 0=deny',
  `updated_by` int unsigned NOT NULL,
  `reason`     text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module_action` (`role_id`,`module`,`action`),
  KEY `idx_role` (`role_id`),
  KEY `rpo_user_fk` (`updated_by`),
  CONSTRAINT `rpo_role_fk` FOREIGN KEY (`role_id`) REFERENCES `user_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rpo_user_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin-editable role-level permission overrides. Sits between per-user overrides and config/permissions.php factory defaults.';
