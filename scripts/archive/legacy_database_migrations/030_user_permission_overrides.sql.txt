-- ============================================================
-- 030_user_permission_overrides.sql
--
-- PERM-1 — Per-user permission overrides.
--
-- Adds a new table that lets a super admin grant permissions
-- to a single user beyond what their role allows, or revoke
-- permissions that the role would normally grant.
--
-- Architecture:
--   - Roles remain the base permission set (user_permissions table).
--   - This table layers ON TOP of the role: a row here always wins.
--   - granted=1 → explicitly granted (even if role denies)
--   - granted=0 → explicitly denied (even if role grants)
--   - No row → fall through to role default (existing behaviour)
--
-- Action vocabulary mirrors the existing user_permissions schema:
--   view, create, edit, delete, export
-- (The session prompt says "settings" — that maps to "export"
--  per the comment in config/permissions.php where the 5th DB
--  column was historically used as the catch-all "full module
--  control" slot. We follow the existing schema convention so
--  override + role merge cleanly.)
--
-- Spec ref: Session PERM-1 brief, FLEETFORGE_CLAUDE_CODE_REFERENCE.md §12
-- Decisions: super_admin overrides are blocked at the API layer
--            (super_admin can() always returns true regardless).
-- ============================================================

CREATE TABLE user_permission_overrides (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    module      VARCHAR(50) NOT NULL,
    action      VARCHAR(50) NOT NULL,
    granted     TINYINT(1) NOT NULL,
        -- 1 = explicitly granted (even if role denies)
        -- 0 = explicitly denied  (even if role grants)
    granted_by  INT UNSIGNED NOT NULL,
    reason      TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_module_action (user_id, module, action),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
