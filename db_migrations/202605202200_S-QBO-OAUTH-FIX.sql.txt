-- ============================================================
-- S-QBO-OAUTH-FIX — DB-backed OAuth state tokens.
--
-- Replaces the S-QBO-1 session-based state pattern
-- ($_SESSION['qbo_oauth_state']) with a persisted state-token store.
-- Why: PHP sessions are per-origin; OAuth callbacks under ngrok (or
-- any cross-origin redirect) arrive on a different origin than the
-- session was established on, so $_SESSION is empty at the callback
-- and state verification + require_auth() both fail.
--
-- Per K-22 Trap #59 (catalogued 2026-05-20 in D-S-QBO-1-OAUTH-DOCS-LOCK):
-- the state token IS the authentication proof for an OAuth callback,
-- NOT the user session. This table is the storage backing that rule.
--
-- Lifecycle:
--   1. init.php calls StateManager::generate('quickbooks', 600,
--      current_user_id(), ip) which INSERTs a row with a 10-minute
--      expires_at + the initiating user_id captured for downstream
--      audit attribution.
--   2. User redirected to Intuit, authorizes, returns to callback.
--   3. callback.php calls StateManager::verifyAndConsume(token,
--      'quickbooks', ip) which atomically stamps used_at via
--      UPDATE-with-condition (single-use enforcement; even if the
--      token leaks it can only be replayed once within TTL).
--   4. callback uses the returned initiated_by_user_id to attribute
--      the audit_log row — no current_user_id() dependency on session.
--
-- Cleanup: opportunistic — StateManager::generate() deletes
-- expired-beyond-24h rows on every call. The 24h buffer keeps recent
-- expired rows for forensic audit ("operator X tried to connect Y
-- time, was that them?"). No dedicated cron needed.
--
-- @session  S-QBO-OAUTH-FIX
-- @date     2026-05-20
-- @decisions D-QBO-OAUTH-FIX-1 (FleetForge\OAuth\StateManager namespace),
--            D-QBO-OAUTH-FIX-2 (callback auth-context-free per OAuth 2.0),
--            D-QBO-OAUTH-FIX-3 (600s TTL + single-use + 24h cleanup buffer),
--            D-QBO-OAUTH-FIX-4 (initiated_by_user_id capture+resolve for audit)
-- ============================================================

START TRANSACTION;

CREATE TABLE `acc_oauth_states` (
  `id`                    int unsigned                  NOT NULL AUTO_INCREMENT,
  `state_token`           varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider`              enum('quickbooks')            NOT NULL DEFAULT 'quickbooks',
  `initiated_by_user_id`  int unsigned                           DEFAULT NULL,
  `initiated_ip`          varchar(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `initiated_at`          datetime                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`            datetime                      NOT NULL,
  `used_at`               datetime                               DEFAULT NULL,
  `consumed_ip`           varchar(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_state_token` (`state_token`),
  KEY `idx_expires`        (`expires_at`),
  KEY `idx_provider_used`  (`provider`, `used_at`),
  KEY `idx_user`           (`initiated_by_user_id`),
  CONSTRAINT `fk_oauth_states_user`
    FOREIGN KEY (`initiated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
