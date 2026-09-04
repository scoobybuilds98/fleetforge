-- ============================================================
-- S-USER-LOCKOUT — users.locked_at / locked_by / lock_reason
--
-- users.status already has a 'locked' enum value (alongside active/
-- inactive/invited/suspended) and every access-control checkpoint that
-- gates on status = 'active' -- login.php, the self-service and
-- admin-triggered password-reset lookups, auth_check_remember_me(), and
-- the mid-session freshness re-check in _ff_check_permission_freshness()
-- -- already treats 'locked' exactly like 'suspended'/'inactive': login
-- fails, password-reset requests silently no-op, and an already-open
-- session is force-ended on the user's very next authenticated request.
-- Nothing in the codebase, however, could ever WRITE status = 'locked':
-- api/v1/users/update_status.php explicitly refuses it, and the
-- deprecated settings/users.php status dropdown never listed it either.
--
-- This migration adds the accountability columns a deliberate,
-- super-admin-triggered lockout needs (who locked the account, when, and
-- why) so the new Settings -> Lockout tab can turn 'locked' from a dead
-- enum value into a real, audited admin action -- distinct from the
-- softer, self-service-adjacent 'suspended' status used elsewhere.
--
-- locked_by is nullable with ON DELETE SET NULL so this never blocks
-- deleting the locking admin's own users row.
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN `locked_at` DATETIME NULL
    COMMENT 'S-USER-LOCKOUT: when a super_admin set status=locked. NULL unless currently locked.'
    AFTER `locked_until`,
  ADD COLUMN `locked_by` INT UNSIGNED NULL
    COMMENT 'S-USER-LOCKOUT: users.id of the super_admin who locked this account.'
    AFTER `locked_at`,
  ADD COLUMN `lock_reason` VARCHAR(500) NULL
    COMMENT 'S-USER-LOCKOUT: mandatory reason captured when the account was locked.'
    AFTER `locked_by`;

-- Supports the FK below and any future "who has this admin locked" lookup.
ALTER TABLE `users`
  ADD KEY `idx_users_locked_by` (`locked_by`);

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
