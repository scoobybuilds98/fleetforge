-- S-QBO-13 Migration: QBO Payment pull via webhook (Phase QBO-6 / 1 of 3)
--
-- Exception #1 per D-QBO-CORE-2: QBO Payments processes money first;
-- FF mirrors via webhook → pull → create FF payment row.
--
-- Three schema changes:
--   1. acc_qbo_payment_map   — bidirectional sync map; ff_payment_id NULLABLE
--                              because webhook can arrive before FF match.
--   2. acc_qbo_webhook_events — audit + idempotency for inbound webhooks
--                               (unique on webhook_event_id; replays are no-op).
--   3. payments.origin        — ENUM tagging payment provenance for bidirectional
--                               dedup (D-QBO-13-X — locked in this session).
--
-- 2026-05-27 | S-QBO-13

-- Map table: per QBO_SPEC §7.7. ff_payment_id is NULLABLE (different from
-- invoice_map) because a webhook can land BEFORE we resolve which FF payment
-- it corresponds to. The PaymentWebhookHandler creates the FF payment + the
-- map row in the same transaction; until then the map row may exist with
-- ff_payment_id=NULL (rare error case; not expected on happy path).
CREATE TABLE `acc_qbo_payment_map` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ff_payment_id` int unsigned DEFAULT NULL COMMENT 'NULLABLE: QBO webhook may arrive before FF payment exists (e.g. invoice not mapped yet). Different from acc_qbo_invoice_map where ff_invoice_id is NOT NULL.',
  `qbo_payment_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit Payment.Id; NULL when FF payment not yet pushed',
  `qbo_sync_token` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token',
  `qbo_total_amt` decimal(15,2) DEFAULT NULL COMMENT 'QBO TotalAmt snapshot — drift comparison baseline',
  `qbo_currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value snapshot',
  `qbo_txn_date` date DEFAULT NULL COMMENT 'QBO TxnDate snapshot',
  `qbo_linked_invoice_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO LinkedTxn[0].TxnId when payment allocated to an invoice; NULL if unallocated',
  `origin` enum('ff_native','qbo_payments_webhook','qbo_other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Per D-QBO-13-1: ff_native = created in FF first (pushed to QBO in S-QBO-14); qbo_payments_webhook = received via webhook then pulled (S-QBO-13); qbo_other = pulled manually or via S-QBO-26',
  `webhook_event_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit-Webhook-Event-Id from webhook header; links map row back to acc_qbo_webhook_events for audit trail',
  `realm_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO realm id at sync time — guards against cross-realm pollution',
  `push_status` enum('pending','pushed','voided','failed','skipped_voided','skipped_by_mode','failed_preflight','pulled_from_qbo') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'Lifecycle state. pulled_from_qbo is unique to payment_map — webhook-originated rows skip the push pipeline entirely (D-QBO-13-2).',
  `push_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Last error for failed/failed_preflight states',
  `pushed_at` datetime DEFAULT NULL COMMENT 'Most recent successful FF→QBO push (S-QBO-14 territory)',
  `pulled_at` datetime DEFAULT NULL COMMENT 'Most recent QBO→FF pull via webhook (S-QBO-13)',
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_payment` (`ff_payment_id`) COMMENT 'One mapping per FF payment when matched; multi-NULL allowed per InnoDB',
  UNIQUE KEY `uq_qbo_payment` (`qbo_payment_id`) COMMENT 'No two FF payments share a QBO Payment.Id; multi-NULL allowed',
  KEY `idx_status` (`push_status`),
  KEY `idx_origin` (`origin`),
  KEY `idx_webhook` (`webhook_event_id`),
  KEY `idx_pulled_at` (`pulled_at`),
  CONSTRAINT `fk_qbo_payment_map_ff` FOREIGN KEY (`ff_payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-6 S-QBO-13: bidirectional FF↔QBO payment sync state. ff_payment_id NULLABLE because webhooks can arrive before FF match resolves. origin column tags provenance per D-QBO-13-1. pulled_from_qbo push_status state unique to this map (webhooks skip push pipeline).';

-- Webhook events table: per QBO_SPEC §12.4. Idempotency-enforced by
-- UNIQUE on webhook_event_id. Stores raw payload for forensic replay.
-- Signature verification result stored so the smoke + drift cron can
-- audit later. processing_result tracks whether the event was acted on
-- (created FF payment, skipped, errored).
CREATE TABLE `acc_qbo_webhook_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `webhook_event_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Intuit-Webhook-Event-Id header value (or synthesized random hex when header missing); UNIQUE for idempotency',
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown' COMMENT 'Payment | Invoice | Customer | unknown — extracted from eventNotifications[].dataChangeEvent.entities[0].name',
  `realm_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit realm id from webhook payload — drift guard against cross-realm webhooks',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Raw webhook body for forensic replay + signature re-verification',
  `signature_verified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = HMAC-SHA256 signature passed verification; 0 = rejected (logged + 403 returned)',
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When FF first saw the webhook',
  `processed_at` datetime DEFAULT NULL COMMENT 'When the handler finished processing (null = still processing or unstarted)',
  `processing_result` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Handler outcome: payment_created | wrong_realm | no_linked_invoice | no_ff_invoice_mapped | already_mapped | error | skipped',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Handler error detail when processing_result=error',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event_id` (`webhook_event_id`) COMMENT 'Idempotency: replay of same webhook is a no-op',
  KEY `idx_event_type` (`event_type`),
  KEY `idx_received` (`received_at`),
  KEY `idx_processed` (`processed_at`),
  KEY `idx_realm` (`realm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-6 S-QBO-13: inbound webhook event log for Exception #1 (QBO Payments). Idempotency enforced by uq_webhook_event_id. payload stored as longtext for forensic replay.';

-- payments.origin column: tags FF-native vs QBO-webhook-originated payments
-- for bidirectional dedup (D-QBO-13-1). Default 'ff_native' so all existing
-- rows + future api/v1/payments/create.php calls default correctly. Webhook
-- handler explicitly sets 'qbo_payments_webhook'.
ALTER TABLE `payments`
  ADD COLUMN `origin` ENUM('ff_native','qbo_payments_webhook','qbo_other')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ff_native'
    COMMENT 'Per D-QBO-13-1: ff_native = created in FF directly via api/v1/payments/create.php; qbo_payments_webhook = pulled from QBO Payments webhook per S-QBO-13; qbo_other = pulled manually or via S-QBO-26. Default ff_native backfills existing rows correctly (all pre-S-QBO-13 payments were FF-native by definition).'
    AFTER `payment_method`;

-- Seed additional webhook-related settings (S-QBO-1 already created
-- quickbooks.webhook_verifier_token; S-QBO-13 adds the replay window).
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES
  ('quickbooks.webhook.replay_window_hours', '24', 'integer', 'quickbooks', 0, 0);
