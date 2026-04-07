-- ============================================================
-- FleetForge — SAMSARA-1 schema migration
--
-- Adds 16 columns to equipment_units for Samsara integration
-- and creates samsara_location_history for GPS breadcrumbs.
--
-- All statements are IF NOT EXISTS / idempotent-safe via the
-- information_schema guard in tools/apply_samsara1_schema.php.
-- Run that wrapper, not this file directly.
-- ============================================================

-- Manual mapping key — the Samsara internal vehicle ID.
-- Set once, on link; cleared on unlink. All syncs key off this.
ALTER TABLE equipment_units ADD COLUMN
  samsara_vehicle_id VARCHAR(100) NULL
  COMMENT 'Samsara internal vehicle ID — set by manual mapping';

-- Vehicle name in Samsara at time of mapping. Snapshot only —
-- we do not chase renames in Samsara to avoid confusion.
ALTER TABLE equipment_units ADD COLUMN
  samsara_vehicle_name VARCHAR(255) NULL
  COMMENT 'Vehicle name in Samsara at time of mapping';

-- Static identifiers synced from Samsara
ALTER TABLE equipment_units ADD COLUMN
  samsara_vin VARCHAR(50) NULL
  COMMENT 'VIN from Samsara — synced automatically';

ALTER TABLE equipment_units ADD COLUMN
  samsara_serial_number VARCHAR(100) NULL
  COMMENT 'Asset serial number from Samsara';

ALTER TABLE equipment_units ADD COLUMN
  samsara_gateway_id VARCHAR(100) NULL
  COMMENT 'Gateway device ID from Samsara';

-- Live battery + power telemetry. Nullable — only set when the
-- gateway is reachable and reports these fields.
ALTER TABLE equipment_units ADD COLUMN
  samsara_battery_pct TINYINT UNSIGNED NULL
  COMMENT 'Battery percentage 0-100';

ALTER TABLE equipment_units ADD COLUMN
  samsara_battery_charging TINYINT(1) NULL
  COMMENT '1=charging, 0=not charging, NULL=unknown';

ALTER TABLE equipment_units ADD COLUMN
  samsara_power_source VARCHAR(50) NULL
  COMMENT 'Battery, External, etc.';

ALTER TABLE equipment_units ADD COLUMN
  samsara_check_in_mode VARCHAR(100) NULL
  COMMENT 'Unpowered mode, etc.';

-- Last-known location snapshot (for dashboard + lease show page)
ALTER TABLE equipment_units ADD COLUMN
  samsara_last_location_lat DECIMAL(10,7) NULL;
ALTER TABLE equipment_units ADD COLUMN
  samsara_last_location_lng DECIMAL(10,7) NULL;
ALTER TABLE equipment_units ADD COLUMN
  samsara_last_location_address TEXT NULL;

-- Speed (km/h). Speed is Samsara's speedMilesPerHour * 1.60934.
ALTER TABLE equipment_units ADD COLUMN
  samsara_last_speed_kph DECIMAL(6,2) NULL;

-- When Samsara last heard from the gateway (not when we synced)
ALTER TABLE equipment_units ADD COLUMN
  samsara_last_connected_at DATETIME NULL;

-- When FleetForge last successfully pulled data for this unit
ALTER TABLE equipment_units ADD COLUMN
  samsara_last_synced_at DATETIME NULL
  COMMENT 'Last time FleetForge synced with Samsara';

-- Current odometer reading from Samsara, in km.
ALTER TABLE equipment_units ADD COLUMN
  samsara_odometer_km DECIMAL(10,2) NULL
  COMMENT 'Current odometer from Samsara in km';

-- ============================================================
-- samsara_location_history — GPS breadcrumb trail
--
-- One row per sync where the location CHANGED from the previous
-- point (see cron/samsara_sync.php). Used by future route replay
-- features and by the map-view playback.
-- ============================================================
CREATE TABLE IF NOT EXISTS samsara_location_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_unit_id INT UNSIGNED NOT NULL,
    samsara_vehicle_id VARCHAR(100) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    speed_kph DECIMAL(6,2) NULL,
    heading SMALLINT UNSIGNED NULL,
    address TEXT NULL,
    recorded_at DATETIME NOT NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (equipment_unit_id),
    INDEX idx_recorded (recorded_at),
    CONSTRAINT fk_samsara_loc_unit FOREIGN KEY (equipment_unit_id)
        REFERENCES equipment_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
