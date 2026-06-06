-- ============================================================
-- S-QBO-3 — Sync infrastructure foundation.
--
-- Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §6.4 (queue table),
--           §6.7 (worker), §15 (drift detection), §24 (settings keys)
--
-- THREE THINGS, ATOMICALLY:
--
-- 1. CREATE TABLE acc_qbo_sync_queue per spec §6.4 verbatim.
--    One row per pending QBO push job. Worker (cron/qbo_sync_worker.php)
--    selects up to 10 due items per minute, dispatches via the Pusher
--    convention, and updates status/retry_count/next_retry_at in place.
--
-- 2. CREATE TABLE acc_qbo_drift_events per spec §15 derivation.
--    One row per detected drift event. Currently only push_failed
--    events are inserted (by the worker on permanent failure). S-QBO-24
--    will add the drift-check cron that performs per-entity comparison
--    and inserts count_mismatch / field_mismatch / etc. rows.
--
-- 3. Seed 13 quickbooks.sync_mode.* settings keys per spec §6.2 table.
--    Per-entity sync mode: 'sync' (synchronous push) | 'queue' (worker)
--    | 'off' (skip entirely).
--
-- @session  S-QBO-3
-- @date     2026-05-20
-- ============================================================

START TRANSACTION;

-- ── 1. acc_qbo_sync_queue ─────────────────────────────────────
CREATE TABLE `acc_qbo_sync_queue` (
  `id`               int unsigned                                       NOT NULL AUTO_INCREMENT,
  `entity_type`      enum('customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code')
                                                                        NOT NULL,
  `entity_id`        int unsigned                                       NOT NULL,
  `operation`        enum('create','update','void','delete')            NOT NULL,
  `status`           enum('queued','processing','completed','failed','skipped')
                                                                        NOT NULL DEFAULT 'queued',
  `priority`         tinyint                                            NOT NULL DEFAULT '5',
  `retry_count`      tinyint                                            NOT NULL DEFAULT '0',
  `max_retries`      tinyint                                            NOT NULL DEFAULT '5',
  `next_retry_at`    datetime                                                    DEFAULT NULL,
  `error_message`    text         COLLATE utf8mb4_unicode_ci,
  `error_code`       varchar(50)  COLLATE utf8mb4_unicode_ci                     DEFAULT NULL,
  `enqueued_at`      datetime                                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `picked_up_at`     datetime                                                    DEFAULT NULL,
  `completed_at`     datetime                                                    DEFAULT NULL,
  `worker_id`        varchar(50)  COLLATE utf8mb4_unicode_ci                     DEFAULT NULL,
  `payload_snapshot` json                                                        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`, `priority`, `enqueued_at`),
  KEY `idx_entity`          (`entity_type`, `entity_id`),
  KEY `idx_retry`           (`status`, `next_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. acc_qbo_drift_events ───────────────────────────────────
CREATE TABLE `acc_qbo_drift_events` (
  `id`                  int unsigned                                    NOT NULL AUTO_INCREMENT,
  `detected_at`         datetime                                        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `detection_source`    enum('drift_cron','push_failure','pull_failure','manual')
                                                                        NOT NULL,
  `category`            enum('count_mismatch','field_mismatch','missing_in_qbo','missing_in_ff','amount_drift','balance_drift','push_failed','pull_failed','stale_object_unresolved')
                                                                        NOT NULL,
  `entity_type`         varchar(50)  COLLATE utf8mb4_unicode_ci         NOT NULL,
  `entity_id`           int unsigned                                             DEFAULT NULL,
  `qbo_entity_id`       varchar(50)  COLLATE utf8mb4_unicode_ci                  DEFAULT NULL,
  `ff_value`            text         COLLATE utf8mb4_unicode_ci,
  `qbo_value`           text         COLLATE utf8mb4_unicode_ci,
  `drift_amount`        decimal(15,2)                                            DEFAULT NULL,
  `description`         text         COLLATE utf8mb4_unicode_ci,
  `queue_id`            int unsigned                                             DEFAULT NULL,
  `resolved_at`         datetime                                                 DEFAULT NULL,
  `resolved_by_user_id` int unsigned                                             DEFAULT NULL,
  `resolution_note`     text         COLLATE utf8mb4_unicode_ci,
  `realm_id`            varchar(50)  COLLATE utf8mb4_unicode_ci         NOT NULL,
  `environment`         enum('sandbox','production')                    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_detected` (`category`, `detected_at`),
  KEY `idx_entity`            (`entity_type`, `entity_id`),
  KEY `idx_unresolved`        (`resolved_at`, `category`, `detected_at`),
  KEY `idx_queue`             (`queue_id`),
  KEY `idx_resolver`          (`resolved_by_user_id`),
  CONSTRAINT `fk_qbo_drift_events_queue`
    FOREIGN KEY (`queue_id`) REFERENCES `acc_qbo_sync_queue` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_qbo_drift_events_resolver`
    FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Seed 13 sync_mode settings keys (spec §6.2) ────────────
-- 'sync'  = synchronous push in the same HTTP request (user waits)
-- 'queue' = async via worker cron
-- 'off'   = skip entirely (worker marks 'skipped' if a queue row appears)
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`) VALUES
  ('quickbooks.sync_mode.customer',            'sync',  'string', 'quickbooks', 'QBO Sync Mode — Customer',            'Per spec §6.2: customer create/update is synchronous by default (user waits for QBO confirmation). Override to queue/off if needed.',                                                                       0, 0),
  ('quickbooks.sync_mode.vendor',              'sync',  'string', 'quickbooks', 'QBO Sync Mode — Vendor',              'Per spec §6.2: vendor create/update is synchronous by default. Override to queue/off if needed.',                                                                                                            0, 0),
  ('quickbooks.sync_mode.invoice',             'sync',  'string', 'quickbooks', 'QBO Sync Mode — Invoice',             'Per spec §6.2: invoice send is synchronous when user-initiated (Send Invoice button); cron-generated invoices override to queue via call-site. Override default to queue/off if needed.',                  0, 0),
  ('quickbooks.sync_mode.payment',             'sync',  'string', 'quickbooks', 'QBO Sync Mode — Payment',             'Per spec §6.2: payment record is synchronous (also for QBO webhook handler — FF must respond quickly). Override to queue/off if needed.',                                                                  0, 0),
  ('quickbooks.sync_mode.credit_memo',         'sync',  'string', 'quickbooks', 'QBO Sync Mode — Credit Memo',         'Per spec §6.2: credit memo issuance is synchronous by default.',                                                                                                                                              0, 0),
  ('quickbooks.sync_mode.refund_receipt',      'sync',  'string', 'quickbooks', 'QBO Sync Mode — Refund Receipt',      'Per spec §6.2: refund receipt is synchronous by default. CPA-blocked on D-I (A) / D176 spec resolution — see S-MILEAGE-3-ACCT-SPEC.',                                                                          0, 0),
  ('quickbooks.sync_mode.bill',                'queue', 'string', 'quickbooks', 'QBO Sync Mode — Bill',                'Per spec §6.2: bill creation defaults to queue (no operator wait needed for AP entry).',                                                                                                                       0, 0),
  ('quickbooks.sync_mode.bill_payment',        'queue', 'string', 'quickbooks', 'QBO Sync Mode — Bill Payment',        'Per spec §6.2: bill payment defaults to queue.',                                                                                                                                                              0, 0),
  ('quickbooks.sync_mode.journal_entry',       'sync',  'string', 'quickbooks', 'QBO Sync Mode — Manual JE',           'Per spec §6.2: manual JEs are sync (operator submits and expects QBO confirmation).',                                                                                                                         0, 0),
  ('quickbooks.sync_mode.depreciation_je',     'queue', 'string', 'quickbooks', 'QBO Sync Mode — Depreciation JE',     'Per spec §6.2: depreciation runs are cron-generated (queue mode).',                                                                                                                                           0, 0),
  ('quickbooks.sync_mode.recurring_je',        'queue', 'string', 'quickbooks', 'QBO Sync Mode — Recurring JE',        'Per spec §6.2: recurring JEs post via cron — queue mode.',                                                                                                                                                    0, 0),
  ('quickbooks.sync_mode.tax_remittance_je',   'sync',  'string', 'quickbooks', 'QBO Sync Mode — Tax Remittance JE',   'Per spec §6.2: tax remittance JE is sync (accountant posts and expects QBO update).',                                                                                                                          0, 0),
  ('quickbooks.sync_mode.year_end_closing_je', 'sync',  'string', 'quickbooks', 'QBO Sync Mode — Year-End Closing JE', 'Per spec §6.2: year-end closing JE is sync.',                                                                                                                                                                 0, 0);

COMMIT;
