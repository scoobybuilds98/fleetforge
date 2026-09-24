-- S-ATTENTION-WHATSAPP: Needs attention on staff phones via the official
-- WhatsApp Business Platform (Meta Cloud API).
--
-- What goes out (lib/Notifications/WhatsApp/WhatsAppDeliveries.php):
--   * instantly — urgent Needs attention items for kinds set to "Right away"
--     (Settings → Notifications), to each person who can see the item and
--     chose "Urgent + morning summary";
--   * escalations — an urgent item nobody took for 24h, to super admins;
--   * one morning summary per person at their chosen hour;
--   * optional instant updates a person ticked (e.g. every new lease).
-- Everything is queued here and sent by cron/whatsapp_dispatch.php — never
-- from inside a business request/transaction (notify() usually runs inside
-- one; an HTTP call there would hold locks and could fail the save).
--
-- users.whatsapp_*
--   whatsapp_mode        off | summary | urgent (= urgent items + summary).
--                        Off by default: each person turns it on for
--                        themselves (Profile → Notifications), which is the
--                        opt-in Meta requires; whatsapp_opted_in_at records it.
--   whatsapp_summary_hour  local hour for the morning summary (NULL = 7).
--   whatsapp_quiet_start / _end  local hours; messages due inside the window
--                        wait until it ends (NULL = 21 → 7).
--   whatsapp_updates     update types also sent instantly (JSON list).
--   The number is the existing users.phone_e164.
--
-- notification_deliveries — the outbox + delivery log.
--   dedupe_key (UNIQUE) makes "send once" a database guarantee: the same
--   escalation / summary / alert can't be queued twice for the same person.
--   status: queued → sending → sent → delivered → read, or failed / skipped
--   (with the reason in `error`). provider_message_id = Meta's wamid, used by
--   the status webhook (api/v1/webhooks/whatsapp.php).
--
-- settings (group 'whatsapp', edited in Settings → Notifications, super
-- admin only; NOT in the generic settings loop). Secrets are stored
-- encrypted (ENC:, MfaService::encryptSecret) and never shown back.
--
-- All DATETIMEs are UTC.

ALTER TABLE `users`
  ADD COLUMN `whatsapp_mode` enum('off','summary','urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off'
    COMMENT 'S-ATTENTION-WHATSAPP: off | summary | urgent (urgent items + morning summary)'
    AFTER `phone_e164`,
  ADD COLUMN `whatsapp_summary_hour` tinyint unsigned DEFAULT NULL
    COMMENT 'Local hour 0-23 for the morning summary; NULL = 7'
    AFTER `whatsapp_mode`,
  ADD COLUMN `whatsapp_quiet_start` tinyint unsigned DEFAULT NULL
    COMMENT 'Local hour quiet time starts; NULL = 21'
    AFTER `whatsapp_summary_hour`,
  ADD COLUMN `whatsapp_quiet_end` tinyint unsigned DEFAULT NULL
    COMMENT 'Local hour quiet time ends; NULL = 7'
    AFTER `whatsapp_quiet_start`,
  ADD COLUMN `whatsapp_updates` json DEFAULT NULL
    COMMENT 'Update types also sent instantly on WhatsApp'
    AFTER `whatsapp_quiet_end`,
  ADD COLUMN `whatsapp_opted_in_at` datetime DEFAULT NULL
    COMMENT 'UTC; when the person turned WhatsApp on themselves (opt-in record)'
    AFTER `whatsapp_updates`;

CREATE TABLE IF NOT EXISTS `notification_deliveries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `channel` enum('whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'whatsapp',
  `user_id` int unsigned NOT NULL,
  `to_phone` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'E.164 at queue time',
  `purpose` enum('alert','escalation','summary','update','test') COLLATE utf8mb4_unicode_ci NOT NULL,
  `attention_item_id` int unsigned DEFAULT NULL,
  `notification_id` int unsigned DEFAULT NULL,
  `dedupe_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Send-once key per person',
  `template` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `params` json NOT NULL COMMENT 'Template body parameters, in order',
  `preview` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'What the person reads (log / support)',
  `status` enum('queued','sending','sent','delivered','read','failed','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `next_attempt_at` datetime NOT NULL COMMENT 'UTC; quiet hours and retries push this later',
  `provider_message_id` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Meta wamid',
  `error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_dedupe` (`dedupe_key`),
  KEY `idx_delivery_due` (`status`,`next_attempt_at`),
  KEY `idx_delivery_provider` (`provider_message_id`),
  KEY `idx_delivery_user` (`user_id`,`created_at`),
  KEY `idx_delivery_item` (`attention_item_id`),
  CONSTRAINT `fk_delivery_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delivery_item` FOREIGN KEY (`attention_item_id`) REFERENCES `attention_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_sensitive`) VALUES
  ('whatsapp.enabled',           '0',                  'boolean', 'whatsapp', 'WhatsApp on',               'Master switch for staff WhatsApp messages. Ships off.', 0),
  ('whatsapp.phone_number_id',   '',                   'string',  'whatsapp', 'Phone number ID',           'From Meta WhatsApp Manager → API setup (the business number that sends).', 0),
  ('whatsapp.access_token',      '',                   'string',  'whatsapp', 'Access token',              'Permanent System User token with whatsapp_business_messaging. Stored encrypted.', 1),
  ('whatsapp.app_secret',        '',                   'string',  'whatsapp', 'App secret',                'Meta app secret; verifies delivery-status webhooks. Stored encrypted.', 1),
  ('whatsapp.verify_token',      '',                   'string',  'whatsapp', 'Webhook verify token',      'Any random string; paste the same value in Meta when adding the webhook.', 0),
  ('whatsapp.api_version',       'v23.0',              'string',  'whatsapp', 'Graph API version',         'Meta Graph API version used for sending.', 0),
  ('whatsapp.template_alert',    'fleetforge_alert',   'string',  'whatsapp', 'Alert template name',       'Approved Utility template for urgent items, escalations and updates (4 parameters).', 0),
  ('whatsapp.template_summary',  'fleetforge_summary', 'string',  'whatsapp', 'Summary template name',     'Approved Utility template for the morning summary (5 parameters).', 0),
  ('whatsapp.template_language', 'en',                 'string',  'whatsapp', 'Template language',         'Language code the templates were approved in (e.g. en, en_US).', 0);
