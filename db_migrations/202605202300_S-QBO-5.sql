-- ============================================================
-- S-QBO-5 — acc_qbo_customer_map.
--
-- First mapping table of Phase QBO-2. Persists the bidirectional
-- link between FleetForge customers and QuickBooks customers + the
-- four-state lifecycle the Customers Sync UI surfaces:
--
--   mapped     — both sides linked (1:1 by UNIQUE(ff_customer_id)
--                + UNIQUE(qbo_customer_id))
--   ff_only    — FF customer exists, no QBO counterpart found
--                (auto-match passed without finding a Levenshtein
--                or email/phone hit, OR FF customer is new since
--                last pull). Eligible for S-QBO-6 push.
--   qbo_only   — QBO customer exists, no FF counterpart found.
--                Eligible for S-QBO-CUTOVER-IMPORT (creates new
--                FF customer from QBO data). NOT auto-created
--                this session per D-CPA-3.
--   ignored    — operator marked the row as intentionally
--                unmapped (e.g., a deprecated QBO customer
--                that's not coming over). Preserved for audit.
--
-- Schema EXTENDS spec §7.4 (which only describes the 'mapped'
-- state). Extension locked as D-QBO-5 — the spec assumed all
-- rows would be `mapped` and didn't accommodate the pull-before-
-- match workflow this session implements. Spec §7.4 updated in
-- the same commit to match the extended schema.
--
-- UNIQUE keys on nullable columns: InnoDB allows multiple NULL
-- values in a UNIQUE column, so we can have many ff_only rows
-- (qbo_customer_id NULL) and many qbo_only rows
-- (ff_customer_id NULL). The UNIQUE constraints only enforce
-- 1:1-ness when both sides are linked.
--
-- Field snapshots (qbo_display_name, qbo_company_name, qbo_email,
-- qbo_phone, qbo_active, qbo_balance) are written at pull time.
-- They feed the UI's side-by-side comparison view AND serve as
-- the drift-detection baseline for S-QBO-24 (compare snapshot vs
-- live QBO state, flag deltas).
--
-- @session  S-QBO-5
-- @date     2026-05-20
-- @decisions D-QBO-5 (mapping table state-machine extension),
--            D-QBO-5-1 (FleetForge\QboPushers namespace shared
--                       between Pushers + Pullers),
--            D-QBO-5-2 (auto-match algorithm: normalize → exact
--                       → Levenshtein ≤ 3 → email → phone last-7)
-- ============================================================

START TRANSACTION;

CREATE TABLE `acc_qbo_customer_map` (
  `id`                   int unsigned                  NOT NULL AUTO_INCREMENT,
  `ff_customer_id`       int unsigned                           DEFAULT NULL COMMENT 'NULL = qbo_only state (QBO customer with no FF link)',
  `qbo_customer_id`      varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit Customer.Id; NULL = ff_only state (FF customer not yet pushed)',
  `qbo_sync_token`       varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token; refreshed on every pull/push round-trip',
  `qbo_display_name`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Snapshot at last pull; drives drift detection in S-QBO-24',
  `qbo_company_name`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qbo_email`            varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qbo_phone`            varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qbo_active`           tinyint(1)                             DEFAULT NULL COMMENT 'Mirror of QBO Customer.Active flag',
  `qbo_balance`          decimal(15,2)                          DEFAULT NULL COMMENT 'QBO AR balance at last pull (display only — FF AR is canonical)',
  `mapping_status`       enum('mapped','ff_only','qbo_only','ignored') NOT NULL DEFAULT 'qbo_only',
  `match_confidence`     enum('exact','high','medium','low','manual')  DEFAULT NULL COMMENT 'How the link was determined (exact=normalized name, high=Levenshtein≤3, medium=email, low=phone, manual=operator override)',
  `match_notes`          text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_synced_at`       datetime                               DEFAULT NULL COMMENT 'Most recent successful round-trip with QBO',
  `last_pull_at`         datetime                               DEFAULT NULL,
  `last_push_at`         datetime                               DEFAULT NULL,
  `created_at`           datetime                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           datetime                      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by_user_id`   int unsigned                           DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_customer`   (`ff_customer_id`),
  UNIQUE KEY `uq_qbo_customer`  (`qbo_customer_id`),
  KEY `idx_status`              (`mapping_status`),
  KEY `idx_last_synced`         (`last_synced_at`),
  CONSTRAINT `fk_qbo_cust_map_ff`
    FOREIGN KEY (`ff_customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qbo_cust_map_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
