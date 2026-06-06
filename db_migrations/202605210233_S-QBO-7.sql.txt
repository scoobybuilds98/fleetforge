-- ============================================================
-- S-QBO-7 — acc_qbo_vendor_map.
--
-- Second mapping table of Phase QBO-2 (after acc_qbo_customer_map
-- from S-QBO-5). Mirrors the customer_map state-machine schema
-- exactly with vendor-specific column adjustments:
--
--   qbo_balance     REMOVED  — vendors have AP balance, not AR;
--                              deferred to S-QBO-18 Bills session
--                              when bill/payment pipeline lands.
--   qbo_given_name  ADDED    — QBO Vendor.GivenName (from
--                              vendors.contact_name split on first
--                              space per D-QBO-7-3)
--   qbo_family_name ADDED    — QBO Vendor.FamilyName (split tail)
--   qbo_v4v_status  ADDED    — V4V (1099) status; informational
--                              snapshot only, NOT used for routing
--                              per D-QBO-7-1 (1099 out of scope v1)
--
-- Four-state lifecycle (same as customer_map):
--   mapped     — both sides linked (1:1 UNIQUE on ff/qbo ids)
--   ff_only    — FF vendor exists, no QBO counterpart yet.
--                Eligible for S-QBO-7 push (this session).
--   qbo_only   — QBO vendor exists, no FF counterpart.
--                Eligible for future cutover-import session.
--   ignored    — operator marked the row as intentionally
--                unmapped. Preserved for audit.
--
-- UNIQUE keys on nullable columns: InnoDB allows multiple NULLs
-- in a UNIQUE column, so we can have many ff_only rows and many
-- qbo_only rows. UNIQUE enforces 1:1 only when both sides linked.
--
-- Field snapshots (qbo_display_name, qbo_company_name, qbo_given_name,
-- qbo_family_name, qbo_email, qbo_phone, qbo_active, qbo_v4v_status)
-- are written at pull time. Feed the UI's side-by-side comparison
-- + serve as drift-detection baseline for future S-QBO-24 work.
--
-- @session   S-QBO-7
-- @date      2026-05-21
-- @decisions D-QBO-7-1 (1099 handling out of scope v1; is_1099 column
--                       on FF side absent today, qbo_v4v_status stored
--                       as informational snapshot only),
--            D-QBO-7-2 (vendor_type ENUM NOT mapped to QBO — no clean
--                       analog on QBO Vendor; FF retains as internal
--                       classification only),
--            D-QBO-7-3 (vendors.contact_name → split on FIRST space
--                       into GivenName + FamilyName; no space → all
--                       to GivenName, FamilyName empty),
--            D-QBO-7-4 (mapping table state-machine pattern from
--                       acc_qbo_customer_map per D-QBO-5)
-- ============================================================

START TRANSACTION;

CREATE TABLE `acc_qbo_vendor_map` (
  `id`                   int unsigned                  NOT NULL AUTO_INCREMENT,
  `ff_vendor_id`         int unsigned                           DEFAULT NULL COMMENT 'NULL = qbo_only state (QBO vendor with no FF link)',
  `qbo_vendor_id`        varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit Vendor.Id; NULL = ff_only state (FF vendor not yet pushed)',
  `qbo_sync_token`       varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token; refreshed on every pull/push round-trip',
  `qbo_display_name`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Snapshot at last pull; drives drift detection in S-QBO-24',
  `qbo_company_name`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qbo_given_name`       varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'D-QBO-7-3: from vendors.contact_name pre-space split',
  `qbo_family_name`      varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'D-QBO-7-3: from vendors.contact_name post-space split',
  `qbo_email`            varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qbo_phone`            varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qbo_active`           tinyint(1)                             DEFAULT NULL COMMENT 'Mirror of QBO Vendor.Active flag',
  `qbo_v4v_status`       varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'D-QBO-7-1: QBO Vendor.V4VStatus (1099 status) — informational only, not used for routing',
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
  UNIQUE KEY `uq_ff_vendor`    (`ff_vendor_id`),
  UNIQUE KEY `uq_qbo_vendor`   (`qbo_vendor_id`),
  KEY `idx_status`             (`mapping_status`),
  KEY `idx_last_synced`        (`last_synced_at`),
  CONSTRAINT `fk_qbo_vend_map_ff`
    FOREIGN KEY (`ff_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qbo_vend_map_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
