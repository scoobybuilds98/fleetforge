-- ============================================================
-- 031_user_display_settings.sql
--
-- PERM-1 — Per-user display settings (font size + density).
--
-- Adds two new columns to the users table to persist a user's
-- preferred main-content font size and density across devices.
-- Both settings apply ONLY to .page-content (the main content
-- area). Sidebar, topbar, footer, and modals are unaffected.
--
-- font_size: 70..130 in steps of 5 (percentage scaling factor)
-- density:   compact | comfortable | spacious
--
-- Both values are read at session bootstrap and:
--   1. Stored in $_SESSION['ff_user']['display_font_size'/'display_density']
--   2. Injected into header.php as inline <style> + <body data-density>
--   3. Updated via /api/v1/users/display_settings/update.php
--      which both writes to the DB and refreshes the session value
--      so the next page load reflects the change immediately.
--
-- Defaults match the conservative current look-and-feel so existing
-- users see no visual change unless they opt in.
--
-- Spec ref: Session PERM-1 brief — Feature 2.
-- ============================================================

ALTER TABLE users
    ADD COLUMN display_font_size TINYINT UNSIGNED NOT NULL DEFAULT 100
        COMMENT 'Content font size: 70-130 (percentage)'
        AFTER theme_preference;

ALTER TABLE users
    ADD COLUMN display_density ENUM('compact','comfortable','spacious')
        NOT NULL DEFAULT 'comfortable'
        COMMENT 'Main content density level'
        AFTER display_font_size;
