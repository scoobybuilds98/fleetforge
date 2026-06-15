-- ============================================================
-- S-MILEAGE-UNIT-SIMPLIFY — Promote lease conversion-factor
-- keys to canonical live settings entries.
--
-- Previously the API read 'lease.km_to_miles_default' and
-- 'lease.miles_to_km_default' (no label, never shown in UI).
-- This migration adds the live, user-editable keys
-- 'lease.km_to_miles_conversion' and 'lease.miles_to_km_conversion'
-- seeded from the *_default values when present, otherwise from
-- the international standard (0.621371 / 1.609344).
--
-- group_name='lease' (singular) keeps these separate from the
-- generic 'leases' group so a custom settings card can render
-- them with 6dp precision + auto-reciprocation logic.
--
-- Idempotent: ON DUPLICATE KEY UPDATE only refreshes metadata
-- (group, label, description, value_type) — never overwrites
-- an already-customised value.
--
-- Date:    2026-06-16
-- Session: S-MILEAGE-UNIT-SIMPLIFY
-- ============================================================

SET @km_val = COALESCE(
    (SELECT `value` FROM settings WHERE `key` = 'lease.km_to_miles_default' LIMIT 1),
    '0.621371'
);

SET @mi_val = COALESCE(
    (SELECT `value` FROM settings WHERE `key` = 'lease.miles_to_km_default' LIMIT 1),
    '1.609344'
);

INSERT INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`)
VALUES
  ('lease.km_to_miles_conversion', @km_val, 'decimal', 'lease',
   'KM → Miles conversion factor',
   'Global factor used when creating new leases to derive the counterpart unit rate and allowance. 1 km = this many miles. Editing this field auto-sets the miles→km field as 1/x unless the advanced unlink toggle is active.'),
  ('lease.miles_to_km_conversion', @mi_val, 'decimal', 'lease',
   'Miles → KM conversion factor',
   'Global factor. 1 mile = this many km. Normally auto-derived from the KM→miles field. Enable the advanced unlink toggle on this card to set independently.')
ON DUPLICATE KEY UPDATE
  `group_name`  = VALUES(`group_name`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `value_type`  = VALUES(`value_type`);

COMMIT;
