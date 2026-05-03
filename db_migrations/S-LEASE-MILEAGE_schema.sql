-- ============================================================
-- S-LEASE-MILEAGE — schema additions for multi-month mileage
-- billing, manager review-and-approve gate, and lease-close
-- excess/underage adjustments.
--
-- Date:    2026-05-03
-- Session: S-LEASE-MILEAGE
-- Spec:    FLEETFORGE_SPEC_FINAL.md §lease lifecycle, §invoice
--          generation, §billing math, §Samsara integration
-- Decisions confirmed in D-A through D-H:
--   D-A  align new odometer columns to existing (10,2) precision
--   D-E  internal storage = km everywhere; display per lease.mileage_unit
--   D-F  monthly_allowance_km derived from estimated_mileage_km / lease_months
--   D-G  manager approve unlocks send (separate action)
--   D-H  manager picks credit_note | final_invoice_adjustment | waived
--
-- Idempotency: MySQL 8 does not support ADD COLUMN IF NOT EXISTS, so we
-- gate each ALTER on an INFORMATION_SCHEMA check via prepared statements.
-- Re-running this migration is a no-op once columns/tables exist.
-- ============================================================

-- ── Helper: add column only if missing ──────────────────────
DROP PROCEDURE IF EXISTS ff_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE ff_add_column_if_missing(
    IN tbl  VARCHAR(64),
    IN col  VARCHAR(64),
    IN ddl  TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = tbl
          AND COLUMN_NAME  = col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ── Helper: add index only if missing ──────────────────────
DROP PROCEDURE IF EXISTS ff_add_index_if_missing;
DELIMITER //
CREATE PROCEDURE ff_add_index_if_missing(
    IN tbl  VARCHAR(64),
    IN idx  VARCHAR(64),
    IN ddl  TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = tbl
          AND INDEX_NAME   = idx
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD ', ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ── leases: closing odometer + total distance ───────────────
CALL ff_add_column_if_missing('leases', 'odometer_end_km',
    "odometer_end_km DECIMAL(10,2) NULL COMMENT 'Final odometer at lease close (km, internal canonical unit)'");
CALL ff_add_column_if_missing('leases', 'odometer_end_source',
    "odometer_end_source ENUM('gps','manual') NULL COMMENT 'How the closing odometer was captured'");
CALL ff_add_column_if_missing('leases', 'odometer_end_fetched_at',
    "odometer_end_fetched_at DATETIME NULL COMMENT 'When the closing odometer was captured / Samsara timestamp'");
CALL ff_add_column_if_missing('leases', 'total_distance_km',
    "total_distance_km DECIMAL(10,2) NULL COMMENT 'Total km driven over lease lifetime (computed at close = end - start)'");

-- ── invoices: excess mileage + manager review fields ───────
-- mileage_review_status drives the manager-review UI AND the HARD send
-- guard in api/v1/invoices/send.php. No role exempts the gate (matches
-- S-PROD-1B invoice-immutability uniformity).
CALL ff_add_column_if_missing('invoices', 'excess_distance_km',
    "excess_distance_km DECIMAL(10,2) NULL DEFAULT 0 COMMENT 'Distance over monthly allowance for this period (km)'");
CALL ff_add_column_if_missing('invoices', 'excess_charge_amount',
    "excess_charge_amount DECIMAL(12,2) NULL DEFAULT 0 COMMENT 'Calculated excess mileage charge before manager override'");
CALL ff_add_column_if_missing('invoices', 'mileage_review_status',
    "mileage_review_status ENUM('not_required','pending','approved','overridden') NOT NULL DEFAULT 'not_required' COMMENT 'Manager review state for excess mileage charge — HARD send gate'");
CALL ff_add_column_if_missing('invoices', 'mileage_reviewed_by_user_id',
    "mileage_reviewed_by_user_id INT UNSIGNED NULL COMMENT 'User who approved/overrode the excess'");
CALL ff_add_column_if_missing('invoices', 'mileage_reviewed_at',
    "mileage_reviewed_at DATETIME NULL COMMENT 'When the manager review completed'");
CALL ff_add_column_if_missing('invoices', 'mileage_review_notes',
    "mileage_review_notes TEXT NULL COMMENT 'Manager notes — required for override or reject actions'");
CALL ff_add_column_if_missing('invoices', 'mileage_override_amount',
    "mileage_override_amount DECIMAL(12,2) NULL COMMENT 'If manager overrode the calculated excess, the actual amount applied'");

CALL ff_add_index_if_missing('invoices', 'idx_mileage_review',
    "INDEX idx_mileage_review (mileage_review_status, lease_id)");

-- ── lease_close_adjustments: per-close manager decision audit ───
CREATE TABLE IF NOT EXISTS lease_close_adjustments (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id                 INT UNSIGNED NOT NULL,
    adjustment_type          ENUM('excess_charge','underage_credit','no_adjustment') NOT NULL
        COMMENT 'Direction of the adjustment relative to allowance',
    calculated_distance_km   DECIMAL(10,2) NOT NULL
        COMMENT 'Absolute km over/under allowance at close (always positive)',
    calculated_amount        DECIMAL(12,2) NOT NULL
        COMMENT 'System-calculated charge or credit amount before override',
    final_amount             DECIMAL(12,2) NOT NULL
        COMMENT 'Amount actually applied after any manager override',
    decision                 ENUM('credit_note','final_invoice_adjustment','waived','no_adjustment') NOT NULL
        COMMENT 'How the manager chose to apply the adjustment',
    related_invoice_id       INT UNSIGNED NULL
        COMMENT 'Invoice that received the adjustment (final_invoice_adjustment)',
    related_credit_note_id   INT UNSIGNED NULL
        COMMENT 'Credit note created (credit_note decision)',
    approved_by_user_id      INT UNSIGNED NOT NULL
        COMMENT 'User who approved this close adjustment',
    approved_at              DATETIME NOT NULL,
    notes                    TEXT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lease (lease_id),
    KEY idx_invoice (related_invoice_id),
    KEY idx_credit_note (related_credit_note_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'S-LEASE-MILEAGE: per-lease-close manager review of excess/underage';

-- ── Cleanup: drop the helper procedures so they don't pollute the schema ───
DROP PROCEDURE IF EXISTS ff_add_column_if_missing;
DROP PROCEDURE IF EXISTS ff_add_index_if_missing;
