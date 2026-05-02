-- ============================================================
-- S-PROD-2_ses_bounce_handler.sql
--
-- Adds email bounce tracking and the email_disabled flag.
-- Applied by: php scripts/run_migration.php S-PROD-2_ses_bounce_handler
--
-- Tables / columns added:
--   email_bounces            — SNS bounce/complaint event log
--   customers.email_disabled — hard-bounce / complaint auto-disable
--   portal_users.email_disabled — same for portal users
-- ============================================================

-- ── email_bounces ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `email_bounces` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `customer_id`      INT UNSIGNED    NULL,
    `user_id`          INT UNSIGNED    NULL,
    `recipient_email`  VARCHAR(320)    NOT NULL,
    `bounce_type`      ENUM('Permanent','Transient','Complaint','Undetermined') NOT NULL DEFAULT 'Undetermined',
    `bounce_subtype`   VARCHAR(100)    NOT NULL DEFAULT '',
    `diagnostic_code`  TEXT            NULL,
    `action_taken`     VARCHAR(100)    NOT NULL DEFAULT 'none',
    `raw_payload`      JSON            NULL,
    `processed_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_bounces_recipient`   (`recipient_email`),
    KEY `idx_email_bounces_customer`    (`customer_id`),
    KEY `idx_email_bounces_type`        (`bounce_type`),
    KEY `idx_email_bounces_processed`   (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── customers: email_disabled columns ────────────────────────
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `email_disabled`        TINYINT(1)   NOT NULL DEFAULT 0    AFTER `email`,
    ADD COLUMN IF NOT EXISTS `email_disabled_reason` VARCHAR(255) NULL                  AFTER `email_disabled`,
    ADD COLUMN IF NOT EXISTS `email_disabled_at`     DATETIME     NULL                  AFTER `email_disabled_reason`;

-- ── portal_users: email_disabled columns ─────────────────────
ALTER TABLE `portal_users`
    ADD COLUMN IF NOT EXISTS `email_disabled`        TINYINT(1)   NOT NULL DEFAULT 0    AFTER `email`,
    ADD COLUMN IF NOT EXISTS `email_disabled_reason` VARCHAR(255) NULL                  AFTER `email_disabled`,
    ADD COLUMN IF NOT EXISTS `email_disabled_at`     DATETIME     NULL                  AFTER `email_disabled_reason`;

-- ── FLEETFORGE_DATABASE_MASTER.sql changelog ─────────────────
-- [S-PROD-2] email_bounces table added.
-- [S-PROD-2] customers.email_disabled / email_disabled_reason / email_disabled_at added.
-- [S-PROD-2] portal_users.email_disabled / email_disabled_reason / email_disabled_at added.
