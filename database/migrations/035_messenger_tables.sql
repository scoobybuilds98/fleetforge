-- database/migrations/035_messenger_tables.sql
--
-- MSGR-1 — Customer Messenger (Facebook-Messenger-style) schema.
--
-- This system is INTENTIONALLY SEPARATE from the existing `chat_*` tables
-- (CHAT-1, migration 034) because:
--
--   1. /chat is an internal admin-only product (Slack-style team chat).
--      It assumes every member/author is a row in `users`.
--   2. /messenger is an admin ↔ customer product. Every thread lives
--      on ONE customer, and messages can be authored by either an admin
--      (`users`) or a portal user (`portal_users`).
--
--   Keeping the data models separate avoids polymorphic NULL columns on
--   chat_channel_members / chat_messages and guarantees that CHAT-1
--   regressions are impossible while building this feature.
--
-- Tables:
--   messenger_threads        — one row per conversation, always tied to a customer
--   messenger_messages       — the messages themselves; author is either admin OR portal user
--   messenger_thread_reads   — per-reader read cursor (unread count driver)
--
-- Design notes:
--   * `scope='customer'`    → thread is visible to ALL portal users of
--                              that customer. Used when the admin picks a
--                              customer company as the recipient.
--   * `scope='portal_user'` → thread is visible to ONE specific portal user
--                              only. Used when the admin picks an individual
--                              contact.
--   * Admin visibility: any active admin user with customers.view permission
--     sees every thread — it behaves as a shared support inbox. This matches
--     how small teams actually handle customer messages.
--   * Soft delete: threads and messages use `is_archived`, NOT `deleted_at`,
--     matching the CHAT-1 convention.
--   * Polling: portal + admin pages refresh every 5s (same cadence as /chat).

CREATE TABLE IF NOT EXISTS messenger_threads (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id           INT UNSIGNED NOT NULL,
  scope                 ENUM('customer','portal_user') NOT NULL DEFAULT 'customer',
  portal_user_id        INT UNSIGNED NULL COMMENT 'Only set when scope=portal_user',
  subject               VARCHAR(255) NOT NULL DEFAULT '',
  created_by_user_id    INT UNSIGNED NOT NULL COMMENT 'Admin user who started the thread',
  last_message_at       DATETIME NULL,
  last_message_preview  VARCHAR(255) NULL,
  last_message_by       ENUM('admin','portal') NULL,
  unread_admin_count    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quick-lookup: messages from portal not yet read by ANY admin',
  is_archived           TINYINT(1) NOT NULL DEFAULT 0,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer (customer_id),
  INDEX idx_portal_user (portal_user_id),
  INDEX idx_last_message (last_message_at),
  INDEX idx_scope (scope),
  CONSTRAINT fk_msgr_threads_customer
      FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_msgr_threads_portal_user
      FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_msgr_threads_created_by
      FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messenger_messages (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id       INT UNSIGNED NOT NULL,
  sender_type     ENUM('admin','portal') NOT NULL,
  admin_user_id   INT UNSIGNED NULL COMMENT 'Set when sender_type=admin',
  portal_user_id  INT UNSIGNED NULL COMMENT 'Set when sender_type=portal',
  body            TEXT NOT NULL,
  is_archived     TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_thread (thread_id),
  INDEX idx_admin_user (admin_user_id),
  INDEX idx_portal_user (portal_user_id),
  INDEX idx_created (created_at),
  CONSTRAINT fk_msgr_msg_thread
      FOREIGN KEY (thread_id) REFERENCES messenger_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_msgr_msg_admin
      FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_msgr_msg_portal
      FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messenger_thread_reads (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id             INT UNSIGNED NOT NULL,
  admin_user_id         INT UNSIGNED NULL,
  portal_user_id        INT UNSIGNED NULL,
  last_read_message_id  INT UNSIGNED NULL,
  last_read_at          DATETIME NULL,
  UNIQUE KEY uq_admin_reader (thread_id, admin_user_id),
  UNIQUE KEY uq_portal_reader (thread_id, portal_user_id),
  INDEX idx_admin (admin_user_id),
  INDEX idx_portal (portal_user_id),
  CONSTRAINT fk_msgr_reads_thread
      FOREIGN KEY (thread_id) REFERENCES messenger_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_msgr_reads_admin
      FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_msgr_reads_portal
      FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
