-- ============================================================
-- 202605140907_S-SETTINGS-CLEANUP_security_mfa_labels.sql
--
-- S-SETTINGS-CLEANUP C2 — Backfill `label` + `description` on the 3
-- `security.mfa.*` settings rows so they pass the `label IS NOT NULL`
-- filter in `app/admin/settings/index.php` and appear in the Settings
-- → Integrations → Security card (C3 wires the render side).
--
-- Originally seeded by `db_migrations/S-PROD-1A_security_hardening.sql`
-- with label = NULL because there was no settings UI surface for them
-- at that time. MFA was operator-configured via direct SQL only.
-- S-ISSUES-AUDIT 2026-05-14 surfaced this as a gap — operators have no
-- way to discover or modify the 3 keys without DBA access.
--
-- This is a row-data UPDATE only. No schema motion → settings table
-- DDL is unchanged → master_schema_parity remains OK on the same
-- assertion that compares DDL (not row data). migrate count moves
-- 18 → 19.
--
-- D-C this session: the 13 `security.rate_limit.*` rows intentionally
-- stay NULL-labelled — they're operator-tuning knobs, too noisy for the
-- standard settings UI, deferred to a future surface if needed.
--
-- Idempotent: re-running these UPDATEs is a no-op once the rows carry
-- the new labels. The migrate harness's checksum guard prevents this
-- file from being re-applied regardless.
-- ============================================================

UPDATE `settings` SET
    `label`       = 'MFA Required Roles',
    `description` = 'Role slugs whose users must enrol in MFA (JSON array). Stored as e.g. ["super_admin","manager"]. Users with these roles are forced through MFA setup at next login if not yet enrolled.'
WHERE `key` = 'security.mfa.required_roles';

UPDATE `settings` SET
    `label`       = 'TOTP Time Window',
    `description` = 'Number of 30-second TOTP steps to accept on either side of the current window (1 = ±30s tolerance for clock skew between server and authenticator app). Increase only if users repeatedly report "invalid code" errors despite correct codes.'
WHERE `key` = 'security.mfa.totp_window';

UPDATE `settings` SET
    `label`       = 'Backup Code Count',
    `description` = 'Number of single-use backup codes generated when a user enrols in MFA. Each code can be redeemed once if the authenticator app is unavailable. Changing this value only affects new enrolments; existing users keep their previously-generated code set.'
WHERE `key` = 'security.mfa.backup_code_count';
