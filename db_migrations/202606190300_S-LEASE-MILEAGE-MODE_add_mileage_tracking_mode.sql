-- S-LEASE-MILEAGE-MODE: add a per-lease mileage/odometer data-source mode.
--   manual  = operator enters odometer/mileage by hand; Samsara is never
--             queried and a manual reading is never auto-overwritten.
--   off     = no mileage tracking at all (no odometer snapshot, no mileage
--             line items, Samsara never queried). DEFAULT for NEW leases.
--   samsara = legacy auto behavior (capture odometer at activation, period-end
--             from the 5-min Samsara cache, and the InvoiceGenerator D-C
--             distance fallback).
-- Fixes the bug where a manually-entered starting odometer was overwritten by
-- Samsara (or stored as 0) at activation / auto-billing.
-- INFORMATION_SCHEMA guard: idempotent if re-run.
-- Backfill runs ONCE (only when the column is freshly added) so it never
-- clobbers an operator's later mode choice: Samsara-linked unit -> 'samsara',
-- everything else -> 'manual' (NOT 'off' — we must not suppress mileage that
-- existing leases are already tracking).

SET @schema = DATABASE();

SET @col_mileage_mode = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'leases'
      AND COLUMN_NAME = 'mileage_tracking_mode'
);

SET @stmt = IF(
    @col_mileage_mode = 0,
    'ALTER TABLE leases ADD COLUMN `mileage_tracking_mode` enum(''manual'',''off'',''samsara'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''off'' COMMENT ''S-LEASE-MILEAGE-MODE: per-lease mileage data source. manual=operator enters readings, Samsara never queried & manual never auto-overwritten; off=no mileage tracking or billing (default for new leases); samsara=auto-capture at activation + Samsara distance in auto-billing.'' AFTER `engine_version`',
    'SELECT ''mileage_tracking_mode already exists on leases'' AS info'
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- One-time behavior-preserving backfill for pre-existing leases.
SET @backfill = IF(
    @col_mileage_mode = 0,
    'UPDATE leases l
        LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
        SET l.mileage_tracking_mode =
            CASE WHEN eu.samsara_vehicle_id IS NOT NULL AND eu.samsara_vehicle_id <> ''''
                 THEN ''samsara'' ELSE ''manual'' END',
    'SELECT ''mileage_tracking_mode backfill skipped (column already existed)'' AS info'
);
PREPARE b FROM @backfill; EXECUTE b; DEALLOCATE PREPARE b;
