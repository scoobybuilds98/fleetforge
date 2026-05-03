-- ============================================================
-- 038_lease_dual_units.sql
-- S-LEASE-UNITS (2026-05-03)
--
-- Adds dual-unit (km + miles) rate, allowance, and conversion
-- factor columns to the leases table.
--
-- New columns:
--   mileage_rate_km          DECIMAL(10,4) NULL  — rate per km
--   mileage_rate_miles       DECIMAL(10,4) NULL  — rate per mile
--   estimated_mileage_km     DECIMAL(12,3) NULL  — allowance in km
--   estimated_mileage_miles  DECIMAL(12,3) NULL  — allowance in miles
--   km_to_miles_conversion   DECIMAL(8,6)  NOT NULL DEFAULT 0.621371
--   miles_to_km_conversion   DECIMAL(8,6)  NOT NULL DEFAULT 1.609344
--
-- Backward-compat: legacy mileage_rate and estimated_mileage kept
-- in sync with the primary unit's value on every create/update.
-- close.php and all billing math reads continue unchanged.
-- ============================================================

ALTER TABLE leases
    ADD COLUMN mileage_rate_km          DECIMAL(10,4)  NULL
        COMMENT 'Rate per km (S-LEASE-UNITS). Billing uses the primary-unit column per mileage_unit.'
        AFTER mileage_rate,
    ADD COLUMN mileage_rate_miles       DECIMAL(10,4)  NULL
        COMMENT 'Rate per mile (S-LEASE-UNITS). Informational when mileage_unit=km.'
        AFTER mileage_rate_km,
    ADD COLUMN estimated_mileage_km     DECIMAL(12,3)  NULL
        COMMENT 'Contracted mileage allowance in km (S-LEASE-UNITS). Matches estimated_mileage when mileage_unit=km.'
        AFTER estimated_mileage,
    ADD COLUMN estimated_mileage_miles  DECIMAL(12,3)  NULL
        COMMENT 'Contracted mileage allowance in miles (S-LEASE-UNITS). Matches estimated_mileage when mileage_unit=miles.'
        AFTER estimated_mileage_km,
    ADD COLUMN km_to_miles_conversion   DECIMAL(8,6)   NOT NULL DEFAULT 0.621371
        COMMENT 'Per-lease km→miles factor (S-LEASE-UNITS). Manager-editable; default = international standard.'
        AFTER estimated_mileage_miles,
    ADD COLUMN miles_to_km_conversion   DECIMAL(8,6)   NOT NULL DEFAULT 1.609344
        COMMENT 'Per-lease miles→km factor (S-LEASE-UNITS). Auto-reciprocated from km_to_miles_conversion unless unlinked.'
        AFTER km_to_miles_conversion;

-- ── Backfill: populate dual-unit columns from legacy single-unit values ──
--
-- mileage_unit tells us which unit the existing mileage_rate is expressed in.
-- We compute the other direction using the international standard factors.
-- Rows with mileage_rate = 0 get 0.0000 in both directions (no billing).
-- Rows with estimated_mileage = 0 get 0.000 in both directions.

UPDATE leases
SET
    mileage_rate_km = CASE
        WHEN mileage_unit = 'km'    THEN ROUND(mileage_rate, 4)
        WHEN mileage_unit = 'miles' THEN ROUND(mileage_rate * 1.609344, 4)
        ELSE                             ROUND(mileage_rate, 4)
    END,
    mileage_rate_miles = CASE
        WHEN mileage_unit = 'miles' THEN ROUND(mileage_rate, 4)
        WHEN mileage_unit = 'km'    THEN ROUND(mileage_rate * 0.621371, 4)
        ELSE                             ROUND(mileage_rate * 0.621371, 4)
    END,
    estimated_mileage_km = CASE
        WHEN mileage_unit = 'km'    THEN ROUND(estimated_mileage, 3)
        WHEN mileage_unit = 'miles' THEN ROUND(estimated_mileage * 1.609344, 3)
        ELSE                             ROUND(estimated_mileage, 3)
    END,
    estimated_mileage_miles = CASE
        WHEN mileage_unit = 'miles' THEN ROUND(estimated_mileage, 3)
        WHEN mileage_unit = 'km'    THEN ROUND(estimated_mileage * 0.621371, 3)
        ELSE                             ROUND(estimated_mileage * 0.621371, 3)
    END,
    km_to_miles_conversion = 0.621371,
    miles_to_km_conversion = 1.609344;

-- ── Settings seed: system-wide default conversion factors ──
INSERT IGNORE INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
VALUES
    (
        'lease.km_to_miles_default',
        '0.621371',
        'decimal',
        'leases',
        'KM to Miles Default Conversion',
        'Default km→miles factor used on new lease creation. Individual leases can override per-record. (International standard: 0.621371)',
        0
    ),
    (
        'lease.miles_to_km_default',
        '1.609344',
        'decimal',
        'leases',
        'Miles to KM Default Conversion',
        'Default miles→km factor used on new lease creation. Auto-derived from km_to_miles_default when unlinked. (International standard: 1.609344)',
        0
    );
