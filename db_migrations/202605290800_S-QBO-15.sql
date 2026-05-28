-- ============================================================================
-- S-QBO-15 — QBO Payments embed in customer portal (Phase QBO-6 / 3 of 3)
-- ============================================================================
--
-- Creates acc_qbo_payment_initiations to track outbound QBO Payments hosted-
-- page URL generation events. Pairs with the existing acc_qbo_payment_map
-- (S-QBO-13) + payments.origin column (S-QBO-13) + PaymentWebhookHandler
-- handshake (extended this session) to close the customer-portal payment loop.
--
-- Architecture per D-QBO-15-1 (match by qbo_invoice_id + latest pending):
--   - One ACTIVE pending row per qbo_invoice_id at a time
--   - PaymentInitiator atomically expires any prior pending row for the same
--     qbo_invoice_id BEFORE INSERT (race-safe via FOR UPDATE on the row)
--   - PaymentWebhookHandler extension looks up latest pending row by
--     qbo_invoice_id when webhook fires; marks status='completed' + sets
--     qbo_payment_id; continues existing webhook flow
--
-- Seed settings (4 keys) for the QBO Payments feature gate + URL config:
--   - quickbooks.payments_enabled ('0' default — D-CPA-5 master gate)
--   - quickbooks.payments.success_url (relative; resolves to base_url())
--   - quickbooks.payments.cancel_url
--   - quickbooks.payments.url_ttl_minutes (30 — Intuit-typical)
--
-- Note: quickbooks.payments_enabled already seeded by S-QBO-1 settings seed
-- (verified via SELECT at pre-flight: value='0'); ON DUPLICATE KEY UPDATE
-- keeps existing values. Other 3 keys are new this session.
--
-- @session  S-QBO-15
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §11 (QBO Payments embed)
-- @decision D-QBO-15-1 (match by qbo_invoice_id + latest pending row;
--               UNIQUE constraint workaround via app-level expire-before-insert
--               since MySQL lacks partial unique indexes),
--           D-QBO-15-2 (PaymentInitiator generates hosted URL via Intuit
--               Payments API; persists initiation row before redirect),
--           D-QBO-15-3 (portal button visibility gates: invoice.status IN
--               (sent/overdue/partially_paid) AND payments_enabled='1' AND
--               qbo invoice mapped AND no currency mismatch),
--           D-QBO-15-4 (PaymentWebhookHandler extension handles initiation
--               handshake BEFORE existing handler flow — match → mark complete
--               → continue webhook handler unchanged),
--           D-QBO-15-5 (idempotency: existing pending+unexpired row returns
--               same URL; existing expired+pending row gets re-generated)

CREATE TABLE IF NOT EXISTS `acc_qbo_payment_initiations` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_invoice_id`         INT UNSIGNED NOT NULL COMMENT 'FF invoice this URL was generated for',
    `ff_portal_user_id`     INT UNSIGNED DEFAULT NULL COMMENT 'Portal user who clicked Pay Online; FK SET NULL on user delete',
    `qbo_invoice_id`        VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'QBO Invoice.Id (denormalized from acc_qbo_invoice_map at generation time for webhook lookup)',
    `qbo_hosted_url`        TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Intuit-generated hosted-page URL for this initiation',
    `initiation_token`      VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '32-byte hex token for return-URL state matching (?initiation_id=X)',
    `amount`                DECIMAL(12,2) NOT NULL COMMENT 'Invoice balance_due at generation time (audit; QBO may charge less if partial)',
    `currency`              ENUM('CAD','USD') NOT NULL DEFAULT 'CAD' COMMENT 'Frozen at generation time; matches invoice.currency',
    `realm_id`              VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'QBO realm at generation time',
    `generated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When URL was generated (and link sent to customer)',
    `expires_at`            DATETIME NOT NULL COMMENT 'Computed from generated_at + url_ttl_minutes setting; past this point, URL is dead and a new one must be generated',
    `status`                ENUM('pending','completed','cancelled','expired','failed') NOT NULL DEFAULT 'pending' COMMENT 'Lifecycle: pending (URL live) → completed (webhook handshook) | cancelled (customer cancelled at QBO) | expired (TTL exceeded; cleared by cron or next initiation attempt) | failed (URL generation failed at Intuit)',
    `qbo_payment_id`        VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Filled when webhook handshook successfully; cross-reference to acc_qbo_payment_map.qbo_payment_id',
    `error_message`         TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'When status=failed, the Intuit error response',
    `completed_at`          DATETIME DEFAULT NULL COMMENT 'When webhook handshook (status=completed) OR when URL was cancelled/expired',
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_initiation_token` (`initiation_token`) COMMENT 'Return URL carries this token; lookup must be O(1) + collision-free',
    KEY `idx_ff_invoice_pending` (`ff_invoice_id`, `status`) COMMENT 'PaymentInitiator::generate looks up existing pending row by ff_invoice_id',
    KEY `idx_qbo_invoice_pending` (`qbo_invoice_id`, `status`) COMMENT 'Webhook handshake lookup: WHERE qbo_invoice_id=? AND status=pending ORDER BY generated_at DESC LIMIT 1 (D-QBO-15-1)',
    KEY `idx_status` (`status`) COMMENT 'Admin UI filter + cron expiry sweep',
    KEY `idx_portal_user` (`ff_portal_user_id`) COMMENT 'Admin UI Initiated By column',
    KEY `idx_expires_at` (`expires_at`) COMMENT 'Cron expiry sweep',
    CONSTRAINT `fk_qbo_payment_init_invoice` FOREIGN KEY (`ff_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qbo_payment_init_user` FOREIGN KEY (`ff_portal_user_id`) REFERENCES `portal_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-6 S-QBO-15: outbound QBO Payments hosted-page initiation tracking. Each row = one Pay Online click. Match-back via qbo_invoice_id + latest pending (D-QBO-15-1; UNIQUE pending constraint enforced at app layer via expire-before-insert because MySQL lacks partial unique indexes).';

-- Seed settings — quickbooks.payments_enabled already exists from S-QBO-1
-- seed (verified pre-flight; value='0'); 3 new keys this session.
INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `description`) VALUES
    ('quickbooks.payments.success_url', 'portal/payments/payment_success', 'string', 'quickbooks', 0, 0, 'Relative URL (resolved via base_url()) where QBO Payments redirects after successful payment. Receives ?token=X for initiation lookup.'),
    ('quickbooks.payments.cancel_url',  'portal/payments/payment_cancel',  'string', 'quickbooks', 0, 0, 'Relative URL where QBO Payments redirects on customer cancellation.'),
    ('quickbooks.payments.url_ttl_minutes', '30', 'string', 'quickbooks', 0, 0, 'How long a generated hosted-page URL remains valid (Intuit-typical; matches Intuit API behavior). Past this, the URL must be re-generated by clicking Pay Online again.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- NOTE: migrate.php auto-records into schema_migrations (version + filename
-- + checksum + execution_ms columns); manual INSERT removed after K-22 catch
-- against schema_migrations column names (was: `migration_name` which doesn't
-- exist; canonical columns are `version` + `filename`).
