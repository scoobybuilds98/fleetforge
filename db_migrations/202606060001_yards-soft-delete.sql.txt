-- ============================================================
-- Migration: 202606060001 — add deleted_at to yards (D5 soft-delete)
--
-- Adds standard soft-delete support to yards. Previously yards were
-- only deactivated (is_active = 0) with no way to fully remove them.
-- deleted_at IS NULL is the new "exists" guard in all yard queries.
-- Existing rows get deleted_at = NULL (active).
-- ============================================================

ALTER TABLE yards
    ADD COLUMN deleted_at datetime NULL DEFAULT NULL
    COMMENT 'Soft-delete timestamp (D5). NULL = not deleted.',
    ADD KEY idx_deleted (deleted_at);
