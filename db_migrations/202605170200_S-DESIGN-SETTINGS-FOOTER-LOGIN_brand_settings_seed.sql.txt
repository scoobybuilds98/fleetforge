-- ============================================================
-- 202605170200_S-DESIGN-SETTINGS-FOOTER-LOGIN_brand_settings_seed.sql
--
-- S-DESIGN-SETTINGS-FOOTER-LOGIN — Seed all rows backing the new
-- Design tab in Settings: brand identity (color, hover, light,
-- logo, favicon), new-user defaults, regional formatting,
-- PDF/invoice presentation, and UI behaviour.
--
-- Each row uses `INSERT IGNORE` keyed by `settings.key` (UNIQUE) —
-- safe to re-run, and pre-existing values (e.g. company.name from
-- the S003 seed) are preserved verbatim, not overwritten.
--
-- value_type values must match the live enum:
--   ('string','integer','decimal','boolean','json','text')
--
-- Author: S-DESIGN-SETTINGS-FOOTER-LOGIN
-- Date:   2026-05-17
-- Notes:  Reads of these values use settings_get() and tolerate
--         missing rows (returns the supplied default).
-- ============================================================

-- ── BRAND IDENTITY ─────────────────────────────────────────────
-- primary_color is the user-pickable hex; hover/light are derived
-- server-side in api/v1/settings/brand.php and stored alongside so
-- runtime CSS injection is a flat read (no per-request math).
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
  ('brand.primary_color', '#2596be', 'string',  'brand', 'Brand primary color', 'Hex color applied as --color-primary across the admin UI.', 0),
  ('brand.primary_hover', '#1e7ea0', 'string',  'brand', 'Brand primary hover', 'Derived 12% darker variant used for button hover. Auto-recomputed on save.', 0),
  ('brand.primary_light', '#e0f4fb', 'string',  'brand', 'Brand primary light', 'Derived 90%-white mix used for badge/tint backgrounds. Auto-recomputed on save.', 0),
  ('brand.logo_path',     '',        'string',  'brand', 'Brand logo path',     'Relative storage path (under branding/) — empty falls back to the built-in mark.', 0),
  ('brand.favicon_path',  '',        'string',  'brand', 'Brand favicon path',  'Relative storage path (under branding/) — empty falls back to assets/icons/favicon.svg.', 0);

-- ── COMPANY (tagline; name already seeded by S003) ────────────
-- INSERT IGNORE keeps the existing company.name row from S003 —
-- this line only fills the row if a fresh DB never had S003 run.
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
  ('company.name',    'Mainland Truck & Trailer', 'string', 'company', 'Company name',    'Displayed on login page, footer, PDFs, and emails.', 0),
  ('company.tagline', '',                          'string', 'company', 'Company tagline', 'Optional short line shown under the company name on the login page.', 0);

-- ── DEFAULTS (applied at user invite; existing users keep prefs) ──
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
  ('defaults.theme',         'dark',        'string',  'defaults', 'Default theme',          'Theme assigned to newly invited users.',         0),
  ('defaults.density',       'comfortable', 'string',  'defaults', 'Default density',        'UI density assigned to newly invited users.',    0),
  ('defaults.font_size',     '100',         'integer', 'defaults', 'Default font size (%)',  'Display font size (85-115) assigned to new users.', 0),
  ('defaults.rows_per_page', '25',          'integer', 'defaults', 'Default rows per page',  'Default page size on list/table views.',         0);

-- ── REGIONAL ──────────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
  ('regional.date_format',     'M/D/YYYY',          'string', 'regional', 'Date format',     'Display format for dates across the UI and PDFs.',  0),
  ('regional.time_format',     '12h',               'string', 'regional', 'Time format',     '12-hour or 24-hour clock display.',                  0),
  ('regional.currency_symbol', '$',                 'string', 'regional', 'Currency symbol', 'Symbol prefixed to monetary values.',                0),
  ('regional.timezone',        'America/Vancouver', 'string', 'regional', 'Timezone',        'IANA timezone for server-side date math + display.', 0),
  ('regional.distance_unit',   'km',                'string', 'regional', 'Distance unit',   'Default mileage unit for new leases (km or miles).', 0);

-- ── PDF / INVOICES ────────────────────────────────────────────
-- pdf.accent_color empty → renderers fall back to brand.primary_color.
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
  ('pdf.invoice_footer_text', 'Thank you for your business.', 'string',  'pdf', 'Invoice footer text', 'Text printed at the bottom of every invoice PDF (max 200 chars).', 0),
  ('pdf.show_logo',           '1',                              'boolean', 'pdf', 'Show logo on PDFs',    'Render the brand logo at the top of generated PDFs.',              0),
  ('pdf.accent_color',        '',                               'string',  'pdf', 'PDF accent color',     'Optional hex override for PDF accent lines/headers (empty = use brand.primary_color).', 0);

-- ── UI BEHAVIOUR ──────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
  ('ui.sidebar_collapsed_default', '0',   'boolean', 'ui', 'Sidebar collapsed by default', 'Render the sidebar in icons-only mode on first visit.', 0),
  ('ui.session_timeout_minutes',   '480', 'integer', 'ui', 'Session timeout (minutes)',     'Idle timeout for admin sessions.',                       0);
