-- ============================================================================
-- S-AUDIT-LIFECYCLE-1 — align leases.gps_cost schema DEFAULT with the app
--
-- S-GPS-RATE-CARD (2026-06-18) moved GPS pricing to rate cards and changed the
-- API-path default to $0.00 ("no rate-card GPS price → no phantom charges"),
-- but the COLUMN default stayed at the legacy '1.00'. Every INSERT that omits
-- the column (seeders, direct SQL, future callers) therefore inherited a
-- $1.00/day CHARGE the operator never configured. Align the schema default to
-- the app's 0.00 so the column can never silently re-introduce phantom GPS
-- billing. Existing rows are untouched — this changes the DEFAULT only.
--
-- Idempotent: re-running the ALTER sets the same default again (no-op).
-- ============================================================================

ALTER TABLE `leases`
  ALTER COLUMN `gps_cost` SET DEFAULT '0.00';

ALTER TABLE `leases`
  MODIFY COLUMN `gps_cost` decimal(10,2) NOT NULL DEFAULT '0.00'
  COMMENT 'S-LEASE-GPS-COST + S-AUDIT-LIFECYCLE-1: GPS service rate per billing day. Default $0.00 (rate-card gps_price is copied in at lease creation; no card price = no charge). Engine multiplies by billing-window day count when gps_opt_in=1 AND gps_cost>0.';
