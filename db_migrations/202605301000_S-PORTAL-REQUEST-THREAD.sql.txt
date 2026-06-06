-- ============================================================================
-- S-PORTAL-REQUEST-THREAD — multi-message reply thread for portal service requests
-- ============================================================================
--
-- Closes the structural limitation that portal_service_requests has only a
-- single `response` field — operator-caught after the customer had no way
-- to reply once admin replied (and vice-versa). The reply chain was capped
-- at one round-trip.
--
-- New shape: thread of messages keyed to the request. Each message is
-- senderTYPED (admin / portal) so the UI renders the appropriate
-- avatar/label. Both sides can append unlimited messages.
--
-- The existing `portal_service_requests.response` column is RETAINED for
-- backward compat (admin viewer + reports may still read the most-recent
-- admin reply). New behavior: every admin reply BOTH appends a message row
-- AND updates `response` to the latest admin body. Customer replies do NOT
-- touch `response` (the column was always "admin's reply" semantically).
--
-- Per D-PORTAL-REQUEST-THREAD-1: messages-table architecture (NOT
--   denormalize-into-JSON-array on the request row — proper rows enable
--   indexing, future internal-note flag, sender attribution, audit).
-- Per D-PORTAL-REQUEST-THREAD-2: sender_type ENUM('admin','portal') with
--   nullable sender_user_id + sender_portal_user_id. The pair semantics:
--   exactly one of them is non-NULL per row (asserted at app layer +
--   smoke). Allows soft-delete of user rows without orphaning messages
--   (FK SET NULL on both sender cols).
-- Per D-PORTAL-REQUEST-THREAD-3: is_internal flag on the row reserved for
--   future "internal admin note" feature (default 0; rendered to admin
--   only, never to portal — gated at fetch time in service layer).
--
-- @session  S-PORTAL-REQUEST-THREAD
-- @decision D-PORTAL-REQUEST-THREAD-1 (messages-table architecture),
--           D-PORTAL-REQUEST-THREAD-2 (sender_type ENUM + nullable FK
--               pair; exactly-one-NULL invariant at app layer),
--           D-PORTAL-REQUEST-THREAD-3 (is_internal flag reserved for
--               future internal-note feature; admin-only at fetch)

CREATE TABLE IF NOT EXISTS `portal_service_request_messages` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`             INT UNSIGNED NOT NULL COMMENT 'FK to portal_service_requests.id',
    `sender_type`            ENUM('admin', 'portal') NOT NULL COMMENT 'admin = response from staff (sender_user_id non-NULL); portal = reply from customer-side portal user (sender_portal_user_id non-NULL)',
    `sender_user_id`         INT UNSIGNED DEFAULT NULL COMMENT 'NOT NULL when sender_type=admin; FK SET NULL on user delete to preserve thread history',
    `sender_portal_user_id`  INT UNSIGNED DEFAULT NULL COMMENT 'NOT NULL when sender_type=portal; FK SET NULL on portal_user delete to preserve thread history',
    `body`                   TEXT NOT NULL COMMENT 'Message body (max 65535 chars). Plain text; no markdown rendering v1.',
    `is_internal`            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'D-PORTAL-REQUEST-THREAD-3: reserved for future internal-admin-note feature; v1 always 0',
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_request_thread` (`request_id`, `created_at`) COMMENT 'Thread fetch ordering',
    KEY `idx_sender_user`    (`sender_user_id`),
    KEY `idx_sender_portal`  (`sender_portal_user_id`),
    CONSTRAINT `fk_psrm_request` FOREIGN KEY (`request_id`) REFERENCES `portal_service_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_psrm_user`    FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_psrm_portal`  FOREIGN KEY (`sender_portal_user_id`) REFERENCES `portal_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='S-PORTAL-REQUEST-THREAD: multi-message reply chain. One row per message. portal_service_requests.response field RETAINED for backward compat — every admin message updates it to the latest admin body.';
