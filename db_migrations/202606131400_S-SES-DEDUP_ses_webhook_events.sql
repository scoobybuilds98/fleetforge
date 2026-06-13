-- MEDIUM [01e/08] — SES/SNS bounce webhook idempotency.
--
-- api/v1/webhooks/ses_notifications.php had no dedup, so an SNS redelivery
-- (SNS retries until it gets a 2xx, and can deliver more than once) reprocessed
-- the same bounce/complaint — duplicating email_bounces + audit_log rows and
-- re-disabling email. email_bounces can't be the dedup key (one SNS message
-- fans out to several bouncedRecipients → multiple rows), so this dedicated
-- table records each processed SNS MessageId once (mirrors acc_qbo_webhook_events,
-- which the QBO payment webhook already uses correctly).

CREATE TABLE `ses_webhook_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SNS top-level MessageId — UNIQUE for idempotency (replay = no-op)',
  `notification_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Bounce | Complaint | Delivery | ... from the SES payload notificationType',
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When FF first processed this SNS message',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ses_message_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
