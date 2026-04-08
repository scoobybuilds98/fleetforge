-- database/migrations/034_chat_tables.sql
--
-- CHAT-1 — Internal Team Chat System schema.
-- Creates 5 tables: chat_channels, chat_channel_members,
-- chat_messages, chat_attachments, chat_reactions.
-- Messages are never hard-deleted — soft archive only (is_archived flag).
-- Spec: CHAT-1 session contract.

CREATE TABLE IF NOT EXISTS chat_channels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  description TEXT NULL,
  type ENUM('channel','direct') NOT NULL DEFAULT 'channel',
  is_private TINYINT(1) NOT NULL DEFAULT 0,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  last_message_at DATETIME NULL,
  last_message_preview VARCHAR(255) NULL,
  member_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_type (type),
  INDEX idx_last_message (last_message_at),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_channel_members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('admin','member') NOT NULL DEFAULT 'member',
  last_read_at DATETIME NULL,
  last_read_message_id INT UNSIGNED NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_muted TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY unique_member (channel_id, user_id),
  INDEX idx_user (user_id),
  FOREIGN KEY (channel_id) REFERENCES chat_channels(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  message TEXT NULL,
  type ENUM('text','system','attachment') NOT NULL DEFAULT 'text',
  is_edited TINYINT(1) NOT NULL DEFAULT 0,
  edited_at DATETIME NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  reply_to_id INT UNSIGNED NULL COMMENT 'Message being replied to',
  mentions JSON NULL COMMENT 'Array of mentioned user IDs',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_channel (channel_id),
  INDEX idx_user (user_id),
  INDEX idx_created (created_at),
  FOREIGN KEY (channel_id) REFERENCES chat_channels(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (reply_to_id) REFERENCES chat_messages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED NOT NULL,
  type ENUM('invoice','lease','customer','payment','work_order','damage_claim','document','reservation','equipment','file') NOT NULL,
  entity_id INT UNSIGNED NULL COMMENT 'ID of linked FleetForge record',
  file_path VARCHAR(500) NULL COMMENT 'For uploaded files',
  file_name VARCHAR(255) NULL,
  file_size INT UNSIGNED NULL,
  mime_type VARCHAR(100) NULL,
  preview_data JSON NULL COMMENT 'Cached preview: title, subtitle, badge',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_message (message_id),
  FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_reactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  emoji VARCHAR(10) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_reaction (message_id, user_id, emoji),
  FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
