-- ============================================================
-- 202605132211_S-AUTH-FIX_mfa_verified_until.sql
--
-- S-AUTH-FIX C2 — Add `users.mfa_verified_until` so that "Keep me
-- signed in for 30 days" covers both the password factor AND the
-- MFA factor for the same 30-day window. Stamped at MFA-challenge
-- success in app/auth/mfa_challenge.php; checked on remember-me
-- restoration in includes/auth.php (auth_check_remember_me).
--
-- Semantics (locked as D-A this session):
--   NULL      → MFA has never been blessed for this user via a
--               remember-me cycle. Re-prompt MFA on session restore.
--   NOT NULL  → datetime when the MFA blessing expires. If NOW() <
--               mfa_verified_until, the remember-me cycle skips the
--               MFA challenge. If expired, the remember-me cycle
--               still restores the session (password factor still
--               valid) but sets ff_mfa_pending so the next request
--               is gated on MFA re-verification.
--
-- Column position: after `remember_token` (D-B). The two columns are
-- conceptually paired — one stores the password-factor token hash,
-- the other stores the MFA-factor expiry. Keeping them adjacent in
-- the schema mirrors that pairing in the code (auth_check_remember_me
-- reads both off the same user row).
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN `mfa_verified_until` DATETIME NULL DEFAULT NULL
  AFTER `remember_token`;
