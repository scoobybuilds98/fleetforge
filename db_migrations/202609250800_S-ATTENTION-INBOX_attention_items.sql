-- S-ATTENTION-INBOX: split notifications into "Needs attention" (one shared
-- item per problem, closed when fixed) and "Updates" (the activity feed).
--
-- WHY: production had ~7,000 unread notifications per manager and none were
-- ever opened. 70% were the same alert repeated (compliance re-alerted the
-- same units every other day; one nightly drift alert fired 699 times), 29%
-- were activity, and only 1.4% needed a person — buried under the rest.
--
-- attention_items
--   One row per PROBLEM, not per recipient and not per occurrence. item_key =
--   "<kind>:<entity_type>:<entity_id>". `live_key` makes "at most one live item
--   per key" a database guarantee: it equals item_key while the problem still
--   exists (cleared_at IS NULL) and becomes NULL once it's gone. Multiple NULLs
--   are allowed in a UNIQUE index, so closed history rows never collide and the
--   same problem coming back later opens a fresh row.
--
--   status:
--     open      — needs someone
--     snoozed   — hidden until snoozed_until (UTC), then back to open
--     done      — a person closed it (close_note says what they did). For a
--                 problem the system can still see (e.g. invoices still
--                 overdue) cleared_at stays NULL, so the hourly re-check does
--                 NOT open a duplicate — it only reopens this row if the
--                 problem gets WORSE (a later `stage`).
--     resolved  — the system saw the problem go away (invoice paid, document
--                 renewed, application reviewed…)
--
--   Shared by the team: assigned_user_id is who took it; done/resolved closes
--   it for everyone. Who can see an item is decided at read time from the
--   kind's roles (Settings → Notifications) plus audience_user_ids.
--
--   facts: JSON list of {"t": text, "m": 0|1}. m=1 marks a money fact, dropped
--   at serve time for users without payments:view (can_view_financials()), so
--   one stored row serves every role (redact at serve time, never store a
--   pre-redacted copy — same rule as the dashboard).
--
-- attention_item_events
--   The item's history (opened, got worse, taken, snoozed, done + note,
--   resolved, escalated…). user_id NULL = the system.
--
-- notifications.group_key / group_count
--   Updates grouping: a burst of the same update (e.g. a Batch Invoicing run
--   creating 42 invoices) collapses into ONE unread row per person, "42
--   invoices created", instead of 42 rows.
--
-- All DATETIMEs are UTC (includes/db.php pins the session to +00:00).

CREATE TABLE IF NOT EXISTS `attention_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'kind:entity_type:entity_id — one live item per key',
  `kind` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'lib/Attention/Kinds registry key',
  `priority` enum('urgent','todo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todo',
  `stage` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Kind-specific severity step; a later stage reopens a done item',
  `entity_type` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `title` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `facts` json DEFAULT NULL COMMENT '[{"t":"text","m":0|1}] — m=1 = money, hidden without payments:view',
  `url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audience_user_ids` json DEFAULT NULL COMMENT 'Specific users who also see it, beyond the kind roles',
  `status` enum('open','snoozed','done','resolved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `assigned_user_id` int unsigned DEFAULT NULL COMMENT 'Who took it (shared: visible to the whole audience)',
  `assigned_at` datetime DEFAULT NULL,
  `snoozed_until` datetime DEFAULT NULL COMMENT 'UTC; back to open after this',
  `first_seen_at` datetime NOT NULL COMMENT 'UTC; drives "open N days" and escalation',
  `last_seen_at` datetime NOT NULL COMMENT 'UTC; last time a check or event confirmed the problem',
  `urgent_since` datetime DEFAULT NULL COMMENT 'UTC; when it (last) became urgent — escalation clock. NULL while to-do',
  `escalated_at` datetime DEFAULT NULL COMMENT 'UTC; set once when an urgent item sat unowned too long',
  `closed_at` datetime DEFAULT NULL,
  `closed_by_user_id` int unsigned DEFAULT NULL COMMENT 'NULL on a system resolve',
  `close_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cleared_at` datetime DEFAULT NULL COMMENT 'UTC; when the problem itself went away. NULL = still live',
  `live_key` varchar(191) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if((`cleared_at` is null),`item_key`,NULL)) STORED,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attention_live_key` (`live_key`),
  KEY `idx_attention_status` (`status`,`priority`),
  KEY `idx_attention_kind_entity` (`kind`,`entity_type`,`entity_id`),
  KEY `idx_attention_assigned` (`assigned_user_id`),
  KEY `idx_attention_closed` (`closed_at`),
  KEY `idx_attention_closed_by` (`closed_by_user_id`),
  KEY `idx_attention_urgent` (`priority`,`status`,`urgent_since`),
  CONSTRAINT `fk_attention_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attention_closed_by_user` FOREIGN KEY (`closed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attention_item_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL COMMENT 'NULL = the system',
  `action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'opened|worse|taken|released|snoozed|woke|done|reopened|resolved|escalated|note',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attention_event_item` (`item_id`,`id`),
  KEY `idx_attention_event_user` (`user_id`),
  CONSTRAINT `fk_attention_event_item` FOREIGN KEY (`item_id`) REFERENCES `attention_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attention_event_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `notifications`
  ADD COLUMN `group_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'S-ATTENTION-INBOX: same-key unread updates collapse into one row'
    AFTER `entity_id`,
  ADD COLUMN `group_count` int unsigned NOT NULL DEFAULT '1'
    COMMENT 'How many updates this row stands for'
    AFTER `group_key`,
  ADD KEY `idx_notif_user_group` (`user_id`,`group_key`,`is_read`);
