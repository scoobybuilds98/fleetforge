-- ============================================================
-- Migration: role_permission_overrides
-- Date:      2026-06-05
-- Purpose:   Adds a new table that stores admin-editable overrides
--            for role-level permissions, sitting between per-user
--            overrides (user_permission_overrides) and the factory
--            defaults in config/permissions.php.
--
-- Resolution order after this migration:
--   1. super_admin → always true
--   2. user_permission_overrides   (per-user override)
--   3. role_permission_overrides   (this table, role-level override)
--   4. config/permissions.php      (factory default)
-- ============================================================

CREATE TABLE IF NOT EXISTS `role_permission_overrides` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `role_id`     int unsigned NOT NULL,
  `module`      varchar(50)  NOT NULL,
  `action`      varchar(50)  NOT NULL,
  `granted`     tinyint(1)   NOT NULL COMMENT '1=allow 0=deny',
  `updated_by`  int unsigned NOT NULL,
  `reason`      text         NULL,
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module_action` (`role_id`, `module`, `action`),
  KEY `idx_role` (`role_id`),
  CONSTRAINT `rpo_role_fk` FOREIGN KEY (`role_id`)
      REFERENCES `user_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rpo_user_fk` FOREIGN KEY (`updated_by`)
      REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin-editable role-level permission overrides. Sits between per-user overrides and config/permissions.php factory defaults.';
