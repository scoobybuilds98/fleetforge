-- ============================================================
-- S-UNIT-DISTANCE-SECTION — equipment_distance_logs table
--
-- Stores saved Samsara distance-period query results for an
-- equipment unit. Users can calculate a distance window via
-- the Distance Travelled card on the unit profile, then
-- optionally save it here with an optional label. Manual edits
-- before save set source='manual'.
--
-- Hard delete (informational telemetry log, not a financial
-- record). Delete writes an audit_log row for traceability.
-- NOT added to SOFT_DELETE_TABLES constant.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.
--
-- Date:    2026-06-16
-- Session: S-UNIT-DISTANCE-SECTION
-- ============================================================

CREATE TABLE IF NOT EXISTS `equipment_distance_logs` (
  `id`               int unsigned NOT NULL AUTO_INCREMENT,
  `equipment_unit_id` int unsigned NOT NULL COMMENT 'FK → equipment_units.id',
  `period_start`     datetime NOT NULL COMMENT 'Start of distance measurement window (UTC)',
  `period_end`       datetime NOT NULL COMMENT 'End of distance measurement window (UTC)',
  `distance`         decimal(12,2) NOT NULL COMMENT 'Distance in the stored unit',
  `unit`             enum('km','miles') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'km',
  `source`           enum('obd','gps','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gps'
                     COMMENT 'obd=ECU odometer, gps=gateway GPS, manual=user-edited before save',
  `reading_count`    int unsigned DEFAULT NULL COMMENT 'Number of Samsara readings in the window',
  `warnings`         json DEFAULT NULL COMMENT 'Array of warning strings from getDistanceForPeriod',
  `first_reading_at` datetime DEFAULT NULL COMMENT 'Timestamp of the first bookend reading (UTC)',
  `last_reading_at`  datetime DEFAULT NULL COMMENT 'Timestamp of the last bookend reading (UTC)',
  `label`            varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional user-supplied label',
  `queried_at`       datetime DEFAULT NULL COMMENT 'When the Samsara query was made (UTC)',
  `created_by`       int unsigned DEFAULT NULL COMMENT 'FK → users.id',
  `created_at`       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_edl_unit` (`equipment_unit_id`),
  KEY `idx_edl_period` (`equipment_unit_id`, `period_start`, `period_end`),
  KEY `idx_edl_created` (`created_at`),
  CONSTRAINT `fk_edl_unit` FOREIGN KEY (`equipment_unit_id`)
    REFERENCES `equipment_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Saved Samsara distance-period query results per unit (S-UNIT-DISTANCE-SECTION). Hard delete + audit_log for traceability.';
