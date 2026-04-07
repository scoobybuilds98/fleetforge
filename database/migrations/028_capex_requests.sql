-- ============================================================
-- 028_capex_requests.sql
--
-- Capital Expenditure (CapEx) request workflow.
-- Tracks proposed asset purchases through approval and completion,
-- linking to a fixed asset record once acquired.
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §8 (Capital expenditure review)
-- Created in S028 — Fixed Assets module build-out.
-- ============================================================

CREATE TABLE IF NOT EXISTS acc_capex_requests (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number     VARCHAR(50)  NOT NULL UNIQUE,                -- CAPEX-2026-00001
    title              VARCHAR(255) NOT NULL,
    description        TEXT NULL,
    asset_class        ENUM('fleet_equipment','vehicles','office_equipment',
                            'leasehold_improvements','land','building','other') NOT NULL,
    -- Budget vs actual
    budget_amount      DECIMAL(15,2) NOT NULL,                       -- requested CAD
    actual_amount      DECIMAL(15,2) NULL,                           -- filled when completed
    -- Workflow
    status             ENUM('draft','pending_approval','approved','rejected',
                            'in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',
    -- Linkage
    work_order_id      INT UNSIGNED NULL,                            -- if originated from a maintenance WO
    asset_id           INT UNSIGNED NULL,                            -- set when completed → links to acc_fixed_assets
    vendor_id          INT UNSIGNED NULL,
    -- Approval audit
    requested_by       INT UNSIGNED NULL,
    requested_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by        INT UNSIGNED NULL,
    approved_at        DATETIME NULL,
    rejected_reason    TEXT NULL,
    completed_by       INT UNSIGNED NULL,
    completed_at       DATETIME NULL,
    -- Free-form
    justification      TEXT NULL,
    notes              TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_asset_class (asset_class),
    INDEX idx_work_order (work_order_id),
    FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (asset_id)      REFERENCES acc_fixed_assets(id)        ON DELETE SET NULL,
    FOREIGN KEY (vendor_id)     REFERENCES vendors(id)                  ON DELETE SET NULL,
    FOREIGN KEY (requested_by)  REFERENCES users(id)                    ON DELETE SET NULL,
    FOREIGN KEY (approved_by)   REFERENCES users(id)                    ON DELETE SET NULL,
    FOREIGN KEY (completed_by)  REFERENCES users(id)                    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings: capex sequence counter (year-based, same pattern as bills/JEs)
INSERT IGNORE INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
VALUES ('accounting.capex_next_number.2026', '1', 'integer', 'accounting', 'CapEx Counter', 'Auto-increment counter', 0);
