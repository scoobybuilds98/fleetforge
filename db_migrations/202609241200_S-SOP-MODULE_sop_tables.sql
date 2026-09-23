-- ============================================================
-- S-SOP-MODULE — sop_chapter_reads + sop_checklist_ticks
--
-- The in-app SOP (/sop) is written in docs/sop/*.md (repo content, like the
-- Help Center). Two things about it are per-person or shared state and so
-- live in the database:
--
-- sop_chapter_reads — ONE row per (user, chapter). content_hash is the hash
--   of the chapter's markdown when the user pressed "Mark as read"; when the
--   chapter is later edited the hash no longer matches and the hub shows the
--   chapter as "Updated since you read it", so staff re-read changed
--   procedures instead of the old tick hiding them.
--
-- sop_checklist_ticks — the SHARED month-end checklist. One row per ticked
--   item per period ('YYYY-MM'); unticking deletes the row. Shared on
--   purpose: the office manager ticks the document steps and the accountant
--   sees them, with who ticked each and when. The item keys are defined in
--   the checklist block of docs/sop/10-month-end-close.md.
--
-- Rows cascade with the user: a reader record for a deleted user has no
-- meaning; a tick keeps nothing that the audit trail does not (the ticker
-- is gone), and leaving it would show "ticked by" with no name.
-- ============================================================

CREATE TABLE IF NOT EXISTS sop_chapter_reads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    chapter_slug VARCHAR(80) NOT NULL,
    content_hash CHAR(16) NOT NULL,
    read_at DATETIME NOT NULL,
    UNIQUE KEY uq_sop_read_user_chapter (user_id, chapter_slug),
    KEY idx_sop_read_chapter (chapter_slug),
    CONSTRAINT fk_sop_read_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sop_checklist_ticks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checklist_key VARCHAR(40) NOT NULL,
    period CHAR(7) NOT NULL,
    item_key VARCHAR(60) NOT NULL,
    checked_by INT UNSIGNED NOT NULL,
    checked_at DATETIME NOT NULL,
    UNIQUE KEY uq_sop_tick (checklist_key, period, item_key),
    KEY idx_sop_tick_user (checked_by),
    CONSTRAINT fk_sop_tick_user FOREIGN KEY (checked_by)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
