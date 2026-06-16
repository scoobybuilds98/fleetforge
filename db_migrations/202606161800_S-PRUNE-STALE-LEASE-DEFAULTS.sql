-- ============================================================
-- Prune stale lease conversion-factor *_default settings rows.
--
-- After S-MILEAGE-UNIT-SIMPLIFY (202606161600) the API reads
-- 'lease.km_to_miles_conversion' / 'lease.miles_to_km_conversion'
-- (group_name='lease', custom card). The old *_default keys
-- (group_name='leases', generic card) are no longer read by any
-- live code and were showing up as misleading "KM to Miles Default
-- Conversion" / "Miles to KM Default Conversion" fields in the
-- Leases & Contracts settings card alongside Lease Contract Prefix.
--
-- scripts/mileage_rate_zero_fix_2026_05_06.php reads these keys
-- but has hardcoded fallbacks (0.621371 / 1.609344) — one-time
-- script that will never run again in normal operation.
--
-- Idempotent: DELETE WHERE key IN (...) is a no-op on second run.
--
-- Date:    2026-06-16
-- Session: S-PRUNE-STALE-LEASE-DEFAULTS
-- ============================================================

DELETE FROM `settings`
WHERE `key` IN ('lease.km_to_miles_default', 'lease.miles_to_km_default');

COMMIT;
