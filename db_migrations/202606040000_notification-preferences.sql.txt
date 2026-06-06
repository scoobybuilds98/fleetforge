-- ============================================================
-- Migration: 202606040000 — notification_preferences per user
--
-- Adds a JSON column to users so each admin user can opt out
-- of specific notification categories (leases, invoices, QBO, etc.)
-- Default NULL = receive all notifications (backward compatible).
-- Stored as a JSON array of OPTED-OUT category slugs.
--
-- Example: '["samsara","accounting"]' = suppress GPS + accounting alerts.
-- ============================================================

ALTER TABLE users
    ADD COLUMN notification_preferences JSON NULL DEFAULT NULL
        COMMENT 'JSON array of category slugs the user has opted OUT of. NULL = receive all.';
