-- ============================================================
-- S-QBO-2 — QuickBooksClient HTTP boundary support.
--
-- Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §6.5 (sync log table),
--           §13.2 (retry policy), §14.2 (rate-limit throttling),
--           §24 (settings keys)
--
-- THREE THINGS, ATOMICALLY:
--
-- 1. Seed 4 new settings keys for retry + rate-limit behaviour:
--      quickbooks.retry.max_attempts            → '5'
--      quickbooks.retry.backoff_base_seconds    → '60'
--      quickbooks.rate_limit.throttle_threshold → '10'
--      quickbooks.rate_limit.throttle_seconds   → '30'
--    All is_sensitive=0 (numeric configs, not credentials).
--
-- 2. DROP the legacy `acc_qbo_sync_log` table.
--    Pre-flight surfaced that this table existed in the live DB +
--    DATABASE_MASTER.sql line 1017 with the WRONG schema for spec
--    §6.5 — it was a mapping-style placeholder (entity_type ENUM +
--    ff_entity_id + qbo_entity_id + qbo_sync_token + last_synced_at
--    + status ENUM) instead of the API-call-audit shape the spec
--    requires (direction + http_method + endpoint + request_payload
--    + response_payload + duration_ms + error_code + queue_id +
--    realm_id + environment). Zero rows. Zero consumers in code.
--    The mapping concept it tried to capture actually lives in
--    per-entity acc_qbo_customer_map / acc_qbo_invoice_map / etc.
--    tables (created by S-QBO-5 + onward per spec §7).
--
--    Decision locked as D-QBO-2-1.
--
-- 3. CREATE the canonical `acc_qbo_sync_log` per spec §6.5.
--    One row per QBO API call. Every push + pull + retry + error
--    audits here. 365-day retention per §6.5 (the archive cron will
--    be added by S-QBO-N when retention starts mattering).
--
-- @session  S-QBO-2
-- @date     2026-05-20
-- ============================================================

START TRANSACTION;

-- ── 1. Seed retry + rate-limit settings ───────────────────────────
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`) VALUES
  ('quickbooks.retry.max_attempts',            '5',  'integer', 'quickbooks', 'QBO Retry Max Attempts',     'Maximum retry attempts for transient QBO API failures (5xx, 429, 408). Per spec §13.2 — exponential backoff schedule yields ~31 minutes total time at default max.', 0, 0),
  ('quickbooks.retry.backoff_base_seconds',    '60', 'integer', 'quickbooks', 'QBO Retry Backoff Base',     'Base seconds for exponential backoff between retries (60 → 120 → 240 → 480 → 960). Per spec §13.2. Overridden by Retry-After header on 429 responses.', 0, 0),
  ('quickbooks.rate_limit.throttle_threshold', '10', 'integer', 'quickbooks', 'QBO Rate Limit Threshold',   'When X-RateLimit-Remaining drops below this value, the client throttles (sleeps until window reset). Per spec §14.2.', 0, 0),
  ('quickbooks.rate_limit.throttle_seconds',   '30', 'integer', 'quickbooks', 'QBO Rate Limit Max Sleep',   'Maximum seconds the client will sleep when throttling near the rate-limit threshold. If the rate-limit reset is further away than this, the client logs a Sentry breadcrumb and proceeds without sleeping (the worker will pick up failures from the actual 429 response).', 0, 0);

-- ── 2. DROP the legacy (wrong-shape) sync log ─────────────────────
DROP TABLE IF EXISTS `acc_qbo_sync_log`;

-- ── 3. CREATE the canonical §6.5 sync log ─────────────────────────
CREATE TABLE `acc_qbo_sync_log` (
  `id`               int unsigned                            NOT NULL AUTO_INCREMENT,
  `direction`        enum('push','pull')                     NOT NULL,
  `entity_type`      varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id`        int unsigned                                     DEFAULT NULL,
  `qbo_entity_id`    varchar(50)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL,
  `operation`        varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `http_method`      varchar(10)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint`         varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_payload`  json                                             DEFAULT NULL,
  `response_status`  int                                              DEFAULT NULL,
  `response_payload` json                                             DEFAULT NULL,
  `duration_ms`      int                                              DEFAULT NULL,
  `error_code`       varchar(50)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL,
  `error_message`    text         COLLATE utf8mb4_unicode_ci,
  `user_id`          int unsigned                                     DEFAULT NULL,
  `queue_id`         int unsigned                                     DEFAULT NULL,
  `realm_id`         varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `environment`      enum('sandbox','production')            NOT NULL,
  `created_at`       datetime                                NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entity`      (`entity_type`, `entity_id`),
  KEY `idx_qbo`         (`entity_type`, `qbo_entity_id`),
  KEY `idx_errors`      (`error_code`, `created_at`),
  KEY `idx_created`     (`created_at`),
  KEY `idx_queue`       (`queue_id`),
  CONSTRAINT `fk_qbo_sync_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
