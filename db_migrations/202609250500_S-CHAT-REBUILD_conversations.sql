-- S-CHAT-REBUILD: one simple messaging system — Team + Customers — with
-- live record cards (lease / invoice / payment / unit / …) inside messages.
--
-- WHY: FleetForge had THREE overlapping messaging systems — Discord-style
-- team channels (chat_channels + reactions/mentions/replies), a second
-- customer inbox (messenger_threads, MSGR-1) and a customer channel type
-- inside the first one (CHAT-2). Prod held one message across all of them
-- (2026-09-24). They are replaced by:
--
--   conversations                  kind = direct | group | customer
--                                  customer: ONE thread per customer, shared
--                                  by staff (customers.view) and every portal
--                                  user of that customer — like a text thread.
--   conversation_members           who is IN a team conversation (direct/group)
--   conversation_reads             per-reader high-water mark (staff OR portal)
--   conversation_messages          the texts
--   conversation_message_records   records attached to a message. Stored as
--                                  (type, id) ONLY — title/status/amount are
--                                  resolved live at read time by
--                                  lib/Chat/RecordRefs.php, so a card always
--                                  shows the record's CURRENT state and money
--                                  follows can_view_financials(). The old
--                                  chat_attachments trusted client-supplied
--                                  preview text/URLs; that is gone.
--
-- Carry-over: existing staff DMs + their text messages are copied (the only
-- data prod has). Group channels / customer channels / messenger threads had
-- no rows on prod or dev, so they are not carried. Then the 8 old tables are
-- dropped. Works on empty tables too (migrations_reproduce_master replays
-- from zero).

CREATE TABLE `conversations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('direct','group','customer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Group name; NULL for direct/customer (derived at read)',
  `customer_id` int unsigned DEFAULT NULL COMMENT 'kind=customer: one thread per customer',
  `direct_key` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'kind=direct: "<lowUserId>:<highUserId>" — one DM per pair',
  `created_by_user_id` int unsigned DEFAULT NULL,
  `last_message_id` int unsigned DEFAULT NULL,
  `last_message_at` datetime DEFAULT NULL,
  `last_message_preview` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conv_customer` (`customer_id`),
  UNIQUE KEY `uq_conv_direct` (`direct_key`),
  KEY `idx_conv_kind_last` (`kind`,`last_message_at`),
  KEY `fk_conv_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_conv_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conversation_members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conv_member` (`conversation_id`,`user_id`),
  KEY `idx_conv_member_user` (`user_id`),
  CONSTRAINT `fk_conv_member_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_member_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conversation_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int unsigned NOT NULL,
  `sender_type` enum('staff','customer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL COMMENT 'sender_type=staff',
  `portal_user_id` int unsigned DEFAULT NULL COMMENT 'sender_type=customer',
  `body` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL COMMENT 'Unsent by its author — body is blanked, row kept for ordering',
  PRIMARY KEY (`id`),
  KEY `idx_conv_msg_conv` (`conversation_id`,`id`),
  KEY `fk_conv_msg_user` (`user_id`),
  KEY `fk_conv_msg_portal_user` (`portal_user_id`),
  CONSTRAINT `fk_conv_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_msg_portal_user` FOREIGN KEY (`portal_user_id`) REFERENCES `portal_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conv_msg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conversation_message_records` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int unsigned NOT NULL,
  `record_type` enum('lease','invoice','payment','customer','equipment','reservation','work_order','damage_claim') COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conv_msg_record` (`message_id`,`record_type`,`record_id`),
  KEY `idx_conv_record` (`record_type`,`record_id`),
  CONSTRAINT `fk_conv_record_msg` FOREIGN KEY (`message_id`) REFERENCES `conversation_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conversation_reads` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `portal_user_id` int unsigned DEFAULT NULL,
  `last_read_message_id` int unsigned NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conv_read_user` (`conversation_id`,`user_id`),
  UNIQUE KEY `uq_conv_read_portal` (`conversation_id`,`portal_user_id`),
  KEY `idx_conv_read_user` (`user_id`),
  KEY `idx_conv_read_portal` (`portal_user_id`),
  CONSTRAINT `fk_conv_read_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_read_portal_user` FOREIGN KEY (`portal_user_id`) REFERENCES `portal_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_read_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Carry over staff DMs (exactly two staff members) ──────────────────────
INSERT IGNORE INTO conversations (kind, direct_key, created_by_user_id, last_message_at, last_message_preview, created_at)
SELECT 'direct',
       CONCAT(MIN(m.user_id), ':', MAX(m.user_id)),
       c.created_by, c.last_message_at, LEFT(c.last_message_preview, 160), c.created_at
  FROM chat_channels c
  JOIN chat_channel_members m ON m.channel_id = c.id AND m.user_id IS NOT NULL
 WHERE c.type = 'direct'
 GROUP BY c.id, c.created_by, c.last_message_at, c.last_message_preview, c.created_at
HAVING COUNT(DISTINCT m.user_id) = 2;

INSERT IGNORE INTO conversation_members (conversation_id, user_id, joined_at)
SELECT cv.id, m.user_id, m.joined_at
  FROM chat_channels c
  JOIN chat_channel_members m ON m.channel_id = c.id AND m.user_id IS NOT NULL
  JOIN (SELECT channel_id, CONCAT(MIN(user_id), ':', MAX(user_id)) AS k
          FROM chat_channel_members WHERE user_id IS NOT NULL
         GROUP BY channel_id HAVING COUNT(DISTINCT user_id) = 2) dk ON dk.channel_id = c.id
  JOIN conversations cv ON cv.direct_key = dk.k
 WHERE c.type = 'direct';

INSERT INTO conversation_messages (conversation_id, sender_type, user_id, body, created_at, deleted_at)
SELECT cv.id, 'staff', cm.user_id, cm.message, cm.created_at, IF(cm.is_deleted = 1, COALESCE(cm.deleted_at, cm.created_at), NULL)
  FROM chat_messages cm
  JOIN chat_channels c ON c.id = cm.channel_id AND c.type = 'direct'
  JOIN (SELECT channel_id, CONCAT(MIN(user_id), ':', MAX(user_id)) AS k
          FROM chat_channel_members WHERE user_id IS NOT NULL
         GROUP BY channel_id HAVING COUNT(DISTINCT user_id) = 2) dk ON dk.channel_id = c.id
  JOIN conversations cv ON cv.direct_key = dk.k
 WHERE cm.user_id IS NOT NULL AND cm.message IS NOT NULL AND cm.message <> ''
 ORDER BY cm.id;

UPDATE conversations cv
   SET cv.last_message_id = (SELECT MAX(x.id) FROM conversation_messages x WHERE x.conversation_id = cv.id);

-- Everyone who was in a carried DM has read it (no phantom unread badges).
INSERT IGNORE INTO conversation_reads (conversation_id, user_id, last_read_message_id)
SELECT m.conversation_id, m.user_id, COALESCE(cv.last_message_id, 0)
  FROM conversation_members m
  JOIN conversations cv ON cv.id = m.conversation_id;

-- ── Retire the old systems ────────────────────────────────────────────────
DROP TABLE IF EXISTS `chat_reactions`;
DROP TABLE IF EXISTS `chat_attachments`;
DROP TABLE IF EXISTS `chat_channel_members`;
DROP TABLE IF EXISTS `chat_messages`;
DROP TABLE IF EXISTS `chat_channels`;
DROP TABLE IF EXISTS `messenger_thread_reads`;
DROP TABLE IF EXISTS `messenger_messages`;
DROP TABLE IF EXISTS `messenger_threads`;
