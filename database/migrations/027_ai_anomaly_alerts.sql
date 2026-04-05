-- ============================================================
-- FleetForge Migration 027 — AI Anomaly Alerts Table
--
-- Stores anomaly alerts detected by the AI anomaly scanner.
-- Alerts are generated nightly by cron/ai_anomaly_scan.php
-- and displayed on the AI dashboard + notification bell.
--
-- @session S027
-- ============================================================

CREATE TABLE IF NOT EXISTS ai_anomaly_alerts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_type          VARCHAR(50)     NOT NULL COMMENT 'payment_anomaly, utilization_drop, revenue_anomaly, compliance_risk, maintenance_spike, customer_risk',
    severity            ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    title               VARCHAR(255)    NOT NULL,
    description         TEXT            NOT NULL,
    entity_type         VARCHAR(50)     NOT NULL COMMENT 'customer, equipment_unit, lease, fleet',
    entity_id           INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '0 for fleet-level alerts',
    data_snapshot       JSON            NULL     COMMENT 'Raw data that triggered the alert',
    generated_by        INT UNSIGNED    NULL     COMMENT 'User who triggered scan (NULL = cron)',
    acknowledged_at     DATETIME        NULL,
    acknowledged_by     INT UNSIGNED    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_type_created (alert_type, created_at),
    INDEX idx_severity (severity, created_at),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_unacknowledged (acknowledged_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
