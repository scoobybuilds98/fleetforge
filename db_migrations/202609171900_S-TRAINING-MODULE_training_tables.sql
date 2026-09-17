-- ============================================================
-- S-TRAINING-MODULE — training_videos + training_progress
--
-- training_videos is the chapter CATALOG for the in-app staff-training
-- course. The mp4 + captions live in object storage (S3 in prod, like
-- every other file) under training/<slug>.mp4 / .vtt; the row only holds
-- the storage keys, so re-publishing a chapter is an upsert by slug and
-- never touches anyone's progress.
--
-- training_progress is ONE row per (user, video). WHY two positions:
--   position_seconds      — where the viewer last was (resume point; can go
--                           backwards when they rewind)
--   max_position_seconds  — the furthest point reached, which is what the
--                           percent / completion is measured from, so
--                           rewinding to re-watch a section never lowers it.
-- completed_at is stamped once (≥90% reached) and never cleared, so the
-- team report can say WHEN someone finished even if they re-watch later.
-- Rows cascade with the video and the user: progress for a deleted user or
-- a withdrawn chapter has no meaning on its own.
-- ============================================================

CREATE TABLE IF NOT EXISTS training_videos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL,
    chapter_no SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    title VARCHAR(160) NOT NULL,
    description VARCHAR(500) NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    video_key VARCHAR(255) NOT NULL,
    captions_key VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_training_video_slug (slug),
    KEY idx_training_video_order (is_active, chapter_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    video_id INT UNSIGNED NOT NULL,
    position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    max_position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_watched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_training_progress_user_video (user_id, video_id),
    KEY idx_training_progress_video (video_id),
    CONSTRAINT fk_training_progress_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_training_progress_video FOREIGN KEY (video_id)
        REFERENCES training_videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
