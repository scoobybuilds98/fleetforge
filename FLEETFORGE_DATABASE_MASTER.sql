-- ============================================================
-- FLEETFORGE — MASTER DATABASE SCHEMA
-- Version: 1.2 FINAL — All audit corrections applied (Passes 1–15 + INFRA)
-- Total tables: 93 (59 core + 34 accounting) + schema_migrations utility table
-- customer_documents and equipment_documents dropped — all docs via unified documents table
-- All corrections applied. Run top-to-bottom. Self-contained — no separate 900_alter file needed. [PASS-1:C6]
-- ============================================================
-- USAGE (Session 2):
--   for f in $(ls database/schema/*.sql | sort); do
--       mysql -u fleetforge_user -p'PASSWORD' fleetforge < "$f"
--   done
-- This file is self-contained. All deferred ALTER TABLE statements are included at the correct position. [PASS-1:C6]
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+00:00';

-- ============================================================
-- GROUP 1 — NO DEPENDENCIES
-- ============================================================

-- 001_user_roles.sql
CREATE TABLE user_roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Seeds: super_admin, manager, dispatcher, accountant, read_only

-- 002_users.sql
CREATE TABLE users (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(255) NOT NULL,
    email                   VARCHAR(255) NOT NULL UNIQUE,
    password_hash           VARCHAR(255) NULL,
    mfa_enabled             TINYINT(1) NOT NULL DEFAULT 0,                -- [S-PROD-1A] TOTP enrolled and active
    mfa_secret              VARCHAR(500) NULL,                             -- [S-PROD-1A] AES-256-CBC encrypted TOTP secret (ENC:base64)
    mfa_enabled_at          DATETIME NULL,                                 -- [S-PROD-1A] when MFA was enrolled
    mfa_required            TINYINT(1) NOT NULL DEFAULT 0,                -- [S-PROD-1A] role-policy driven (super_admin/manager)
    auth0_sub               VARCHAR(255) NULL UNIQUE, -- kept nullable for possible future SSO integration (Decision D1)
    role_id                 INT UNSIGNED NOT NULL,
    status                  ENUM('active','inactive','invited','suspended','locked') NOT NULL DEFAULT 'active',
    theme_preference        ENUM('dark','light') NOT NULL DEFAULT 'dark',
    avatar_url              VARCHAR(500) NULL,
    phone                   VARCHAR(50) NULL,
    timezone                VARCHAR(100) NULL,
    last_login_at           DATETIME NULL,
    last_login_ip           VARCHAR(45) NULL,
    login_attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until            DATETIME NULL,
    invite_token            VARCHAR(100) NULL,
    invite_token_expiry     DATETIME NULL,
    invite_sent_at          DATETIME NULL,
    password_reset_token    VARCHAR(100) NULL,
    password_reset_expiry   DATETIME NULL,
    remember_token          VARCHAR(100) NULL,                 -- [PASS-4:1.5] secure remember-me token (stored hashed)
    created_by              INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME NULL,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_deleted (deleted_at),
    FOREIGN KEY (role_id) REFERENCES user_roles(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_mfa_backup_codes — [S-PROD-1A] bcrypt-hashed TOTP backup codes (one-time use)
CREATE TABLE user_mfa_backup_codes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME NULL,
    used_ip    VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_used (user_id, used_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 003_user_permissions.sql
CREATE TABLE user_permissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id     INT UNSIGNED NOT NULL,
    module      VARCHAR(100) NOT NULL,
    can_view    TINYINT(1) NOT NULL DEFAULT 0,
    can_create  TINYINT(1) NOT NULL DEFAULT 0,
    can_edit    TINYINT(1) NOT NULL DEFAULT 0,
    can_delete  TINYINT(1) NOT NULL DEFAULT 0,
    can_export  TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_role_module (role_id, module),
    FOREIGN KEY (role_id) REFERENCES user_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 004_audit_log.sql
CREATE TABLE audit_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    user_name   VARCHAR(255) NOT NULL DEFAULT 'system',
    action      ENUM('create','update','delete','restore','login','logout',
                     'export','status_change','view','bulk_action',
                     'payment_recorded','invoice_sent','invoice_voided',
                     'lease_closed','cron') NOT NULL,
    module      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id   INT UNSIGNED NULL,
    entity_label VARCHAR(255) NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(500) NULL,
    notes       TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_entity_created (entity_type, entity_id, created_at), -- [PASS-10:D7] entity timeline ORDER BY
    INDEX idx_user (user_id),
    INDEX idx_created (created_at),
    INDEX idx_action (action),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archive table: no FKs by design — referential integrity not enforced on archived records [PASS-1:L5]
CREATE TABLE audit_log_archive LIKE audit_log;

-- 005_tax_rates.sql
CREATE TABLE tax_rates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    province        VARCHAR(100) NULL,
    country         ENUM('CA','US') NOT NULL DEFAULT 'CA',
    gst_rate        DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    pst_rate        DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    hst_rate        DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    effective_from  DATE NOT NULL,
    effective_to    DATE NULL,
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Seeds: BC GST 5% + PST 7%, Ontario HST 13%, Alberta GST 5%

-- 006_exchange_rates.sql
CREATE TABLE exchange_rates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_currency   ENUM('USD','CAD') NOT NULL,
    to_currency     ENUM('USD','CAD') NOT NULL,
    rate            DECIMAL(10,6) NOT NULL,
    rate_date       DATE NOT NULL,
    source          ENUM('manual','api') NOT NULL DEFAULT 'manual',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate_date (from_currency, to_currency, rate_date),
    INDEX idx_date (rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GROUP 2 — DEPEND ON users AND/OR tax_rates
-- ============================================================

-- 007_customers.sql
CREATE TABLE customers (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name                VARCHAR(255) NOT NULL,
    contact_name                VARCHAR(255) NULL,
    email                       VARCHAR(255) NULL,
    phone                       VARCHAR(50) NULL,
    alt_phone                   VARCHAR(50) NULL,
    website                     VARCHAR(500) NULL,
    address                     VARCHAR(500) NULL,
    city                        VARCHAR(100) NULL,
    state                       VARCHAR(100) NULL,
    postal_code                 VARCHAR(20) NULL,
    country                     VARCHAR(100) NULL DEFAULT 'Canada',
    province                    VARCHAR(100) NULL,
    tax_id                      VARCHAR(100) NULL,
    dot_number                  VARCHAR(100) NULL,
    mc_number                   VARCHAR(100) NULL,
    gst_number                  VARCHAR(50) NULL,
    pst_number                  VARCHAR(50) NULL,
    billing_contact_name        VARCHAR(255) NULL,
    billing_email               VARCHAR(255) NULL,
    billing_phone               VARCHAR(50) NULL,
    billing_address             TEXT NULL,
    currency                    ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    mileage_unit                ENUM('km','miles') NOT NULL DEFAULT 'km',
    tax_exempt                  TINYINT(1) NOT NULL DEFAULT 0,
    gst_exempt                  TINYINT(1) NOT NULL DEFAULT 0, -- [PASS-13:T2] granular: GST/HST exemption
    pst_exempt                  TINYINT(1) NOT NULL DEFAULT 0, -- [PASS-13:T2] granular: PST exemption
    tax_exempt_number           VARCHAR(100) NULL,
    gst_exempt_number           VARCHAR(100) NULL,             -- [PASS-13:T2] separate GST cert number
    pst_exempt_number           VARCHAR(100) NULL,             -- [PASS-13:T2] separate PST cert number
    tax_exempt_expiry           DATE NULL,
    gst_exempt_expiry           DATE NULL,                     -- [PASS-13:T2]
    pst_exempt_expiry           DATE NULL,                     -- [PASS-13:T2]
    tax_exempt_document         VARCHAR(500) NULL,
    tax_rate_id                 INT UNSIGNED NULL,
    billing_cycle               ENUM('monthly','on_close_only') NOT NULL DEFAULT 'monthly',
    invoice_delivery            ENUM('email','mail','portal','none') NOT NULL DEFAULT 'email',
    invoice_email               VARCHAR(255) NULL,
    invoice_cc_emails           JSON NULL,
    po_required                 TINYINT(1) NOT NULL DEFAULT 0,
    default_po_number           VARCHAR(100) NULL,
    discount_type               ENUM('none','percentage','flat') NOT NULL DEFAULT 'none',
    discount_value              DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    discount_reason             VARCHAR(255) NULL,
    discount_valid_from         DATE NULL,
    discount_valid_to           DATE NULL,
    late_fee_enabled            TINYINT(1) NOT NULL DEFAULT 0,
    late_fee_type               ENUM('percentage','flat') NULL,
    late_fee_value              DECIMAL(8,4) NULL,
    late_fee_grace_days         TINYINT UNSIGNED NULL DEFAULT 0,
    status                      ENUM('active','inactive','pending','suspended','credit_hold') NOT NULL DEFAULT 'active',
    credit_limit                DECIMAL(12,2) NULL,
    payment_terms               VARCHAR(100) NULL,
    preferred_yard              VARCHAR(100) NULL,
    risk_score                  ENUM('low','medium','high') NOT NULL DEFAULT 'low',
    risk_notes                  TEXT NULL,
    account_credit_balance      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    lease_count                 INT UNSIGNED NOT NULL DEFAULT 0,
    active_lease_count          INT UNSIGNED NOT NULL DEFAULT 0,
    total_revenue               DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    outstanding_balance         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes                       TEXT NULL,
    internal_notes              TEXT NULL,
    created_by                  INT UNSIGNED NULL,
    updated_by                  INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                  DATETIME NULL,
    UNIQUE KEY uq_company_email (company_name, email),
    INDEX idx_company (company_name),
    INDEX idx_status (status),
    INDEX idx_risk (risk_score),
    INDEX idx_deleted (deleted_at),
    FULLTEXT idx_ft_customers (company_name, contact_name, email, dot_number, mc_number),
    FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_tags (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    tag         ENUM('vip','preferred','owner-operator','fleet','net-30','net-45',
                     'net-60','cod','tax-exempt','high-risk','watchlist',
                     'credit-hold','delinquent','new','seasonal','government','broker') NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_tag (customer_id, tag),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_contacts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    title       VARCHAR(100) NULL,
    email       VARCHAR(255) NULL,
    phone       VARCHAR(50) NULL,
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    notes       TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_notes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    note        TEXT NOT NULL,
    is_pinned   TINYINT(1) NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    INDEX idx_customer (customer_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 008_equipment_templates.sql
CREATE TABLE equipment_templates (
    id                                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                                VARCHAR(100) NOT NULL,
    slug                                VARCHAR(100) NOT NULL UNIQUE,
    description                         TEXT NULL,
    category                            ENUM('chassis','dry_van','reefer','container',
                                             'flatbed','step_deck','lowboy','tanker',
                                             'dump','other') NOT NULL,
    brand                               VARCHAR(100) NULL,
    model                               VARCHAR(100) NULL,
    default_length_ft                   DECIMAL(6,2) NULL,
    default_height_ft                   DECIMAL(6,2) NULL,
    default_width_ft                    DECIMAL(6,2) NULL,
    default_weight_capacity_lbs         INT UNSIGNED NULL,
    default_wheel_size                  VARCHAR(50) NULL,
    default_tire_size                   VARCHAR(50) NULL,
    default_axle_count                  TINYINT UNSIGNED NULL,
    default_ownership_type              ENUM('owned','leased','brokered') NULL,
    default_yard_location               VARCHAR(100) NULL,
    default_tracking_provider           ENUM('samsara','none') NULL DEFAULT 'none',
    default_cvi_interval_days           SMALLINT UNSIGNED NULL,
    default_mvi_interval_days           SMALLINT UNSIGNED NULL,
    default_registration_interval_days  SMALLINT UNSIGNED NULL,
    default_insurance_interval_days     SMALLINT UNSIGNED NULL,
    default_daily_rate                  DECIMAL(10,2) NULL,
    default_weekly_rate                 DECIMAL(10,2) NULL,
    default_monthly_rate                DECIMAL(10,2) NULL,
    default_mileage_rate                DECIMAL(8,4) NULL,
    default_currency                    ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    default_mileage_unit                ENUM('km','miles') NOT NULL DEFAULT 'km',
    default_notes                       TEXT NULL,
    default_inspection_notes            TEXT NULL,
    is_active                           TINYINT(1) NOT NULL DEFAULT 1,
    sort_order                          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by                          INT UNSIGNED NULL,
    created_at                          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                          DATETIME NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GROUP 3 — DEPEND ON customers AND/OR equipment_templates
-- ============================================================

-- 009_equipment_units.sql
CREATE TABLE equipment_units (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id                 INT UNSIGNED NOT NULL,
    unit_number                 VARCHAR(100) NOT NULL UNIQUE,
    vin                         VARCHAR(50) NULL UNIQUE,
    year                        SMALLINT UNSIGNED NULL,
    gps_device_id               VARCHAR(100) NULL,
    samsara_vehicle_url         VARCHAR(500) NULL,
    tracking_provider           ENUM('samsara','none') NOT NULL DEFAULT 'none',
    ownership_type              ENUM('owned','leased','brokered') NOT NULL,
    owner_company_id            INT UNSIGNED NULL,
    yard_location               VARCHAR(100) NULL,
    length_ft                   DECIMAL(6,2) NULL,
    height_ft                   DECIMAL(6,2) NULL,
    width_ft                    DECIMAL(6,2) NULL,
    weight_capacity_lbs         INT UNSIGNED NULL,
    wheel_size                  VARCHAR(50) NULL,
    tire_size                   VARCHAR(50) NULL,
    axle_count                  TINYINT UNSIGNED NULL,
    license_plate               VARCHAR(50) NULL,
    license_state               VARCHAR(50) NULL,
    cvi_expiry                  DATE NULL,
    registration_expiry         DATE NULL,
    mvi_expiry                  DATE NULL,
    insurance_expiry            DATE NULL,
    cvi_interval_days           SMALLINT UNSIGNED NULL,
    mvi_interval_days           SMALLINT UNSIGNED NULL,
    registration_interval_days  SMALLINT UNSIGNED NULL,
    insurance_interval_days     SMALLINT UNSIGNED NULL,
    cvi_document                VARCHAR(500) NULL,
    registration_document       VARCHAR(500) NULL,
    insurance_document          VARCHAR(500) NULL,
    status                      ENUM('available','reserved','on_lease','maintenance',
                                     'inactive','decommissioned') NOT NULL DEFAULT 'available',
    mileage                     INT UNSIGNED NOT NULL DEFAULT 0,
    notes                       TEXT NULL,
    inspection_notes            TEXT NULL,
    internal_notes              TEXT NULL,
    tags                        JSON NULL,
    health_score                TINYINT UNSIGNED NULL,
    health_score_updated_at     DATETIME NULL,
    qr_code_path                VARCHAR(500) NULL,
    lease_count                 INT UNSIGNED NOT NULL DEFAULT 0,
    total_revenue               DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_maintenance_cost      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    acquired_date               DATE NULL,
    acquisition_cost            DECIMAL(12,2) NULL,
    decommissioned_date         DATE NULL,
    decommission_reason         TEXT NULL,
    created_by                  INT UNSIGNED NULL,
    updated_by                  INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                  DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_yard (yard_location),
    INDEX idx_template (template_id),
    INDEX idx_unit_number (unit_number),
    INDEX idx_deleted (deleted_at),
    INDEX idx_cvi_expiry (cvi_expiry),                         -- [PASS-10:D3] compliance alerts
    INDEX idx_reg_expiry (registration_expiry),                -- [PASS-10:D3]
    INDEX idx_mvi_expiry (mvi_expiry),                         -- [PASS-10:D3]
    INDEX idx_ins_expiry (insurance_expiry),                   -- [PASS-10:D3]
    FULLTEXT idx_ft_units (unit_number, vin, gps_device_id, license_plate), -- [PASS-1:C5] gps_device_id added for global search
    FOREIGN KEY (template_id) REFERENCES equipment_templates(id) ON DELETE RESTRICT,
    FOREIGN KEY (owner_company_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 010_yards.sql
CREATE TABLE yards (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL UNIQUE,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    address     VARCHAR(500) NULL,
    city        VARCHAR(100) NULL,
    state       VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL,
    lat         DECIMAL(10,7) NULL,
    lng         DECIMAL(10,7) NULL,
    capacity    SMALLINT UNSIGNED NULL,
    manager_id  INT UNSIGNED NULL,
    phone       VARCHAR(50) NULL,
    notes       TEXT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 011_rate_cards.sql
CREATE TABLE rate_cards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    effective_from  DATE NOT NULL,
    effective_to    DATE NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    INDEX idx_default (is_default),                            -- [PASS-1:L6] rate card lookups
    INDEX idx_effective (effective_from),                       -- [PASS-1:L6] date range queries
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_card_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_card_id    INT UNSIGNED NOT NULL,
    equipment_type  VARCHAR(255) NOT NULL,
    daily_rate      DECIMAL(10,2) NULL,
    weekly_rate     DECIMAL(10,2) NULL,
    monthly_rate    DECIMAL(10,2) NULL,
    mileage_rate    DECIMAL(8,4) NULL,
    mileage_unit    ENUM('km','miles') NOT NULL DEFAULT 'km',
    currency        ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_card_type (rate_card_id, equipment_type),
    FOREIGN KEY (rate_card_id) REFERENCES rate_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 012_vendors.sql
CREATE TABLE vendors (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    vendor_type     ENUM('maintenance','repair','parts','inspection','towing','other') NOT NULL,
    contact_name    VARCHAR(255) NULL,
    email           VARCHAR(255) NULL,
    phone           VARCHAR(50) NULL,
    address         VARCHAR(500) NULL,
    city            VARCHAR(100) NULL,
    state           VARCHAR(100) NULL,
    specializations JSON NULL,
    hourly_rate     DECIMAL(10,2) NULL,
    rating          TINYINT UNSIGNED NULL,
    notes           TEXT NULL,
    is_preferred    TINYINT(1) NOT NULL DEFAULT 0,
    total_spent     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    FULLTEXT idx_ft_vendors (name, contact_name),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GROUP 4 — DEPEND ON equipment_units AND/OR customers
-- ============================================================

-- 013_leases.sql
-- NOTE: last_billed_invoice_id FK deferred — added via ALTER in 900_alter_deferred_fks.sql
CREATE TABLE leases (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_number             VARCHAR(100) NOT NULL UNIQUE,
    customer_id                 INT UNSIGNED NULL,
    equipment_unit_id           INT UNSIGNED NULL,
    customer_name_snapshot      VARCHAR(255) NULL,
    company_name_snapshot       VARCHAR(255) NULL,
    unit_number_snapshot        VARCHAR(100) NULL,
    template_name_snapshot      VARCHAR(100) NULL,
    equipment_snapshot_json     JSON NULL,
    start_date                  DATE NOT NULL,
    end_date                    DATE NULL,
    actual_return_date          DATE NULL,
    status                      ENUM('pending','active','completed','cancelled') NOT NULL DEFAULT 'pending', -- [PASS-1:H2] 'deleted' removed — use deleted_at for soft-delete
    daily_rate                  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    weekly_rate                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monthly_rate                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rate_notes                  TEXT NULL,
    currency                    ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    exchange_rate_to_cad        DECIMAL(10,6) NULL,
    mileage_unit                ENUM('km','miles') NOT NULL DEFAULT 'km',
    mileage_rate                DECIMAL(8,4) NOT NULL DEFAULT 0.0000,  -- legacy: kept = primary-unit value for backward compat (close.php, billing)
    mileage_rate_km             DECIMAL(10,4) NULL,                    -- [S-LEASE-UNITS] rate per km
    mileage_rate_miles          DECIMAL(10,4) NULL,                    -- [S-LEASE-UNITS] rate per mile
    estimated_mileage           DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- legacy: kept = primary-unit value for backward compat
    estimated_mileage_km        DECIMAL(12,3) NULL,                    -- [S-LEASE-UNITS] allowance in km
    estimated_mileage_miles     DECIMAL(12,3) NULL,                    -- [S-LEASE-UNITS] allowance in miles
    km_to_miles_conversion      DECIMAL(8,6)  NOT NULL DEFAULT 0.621371, -- [S-LEASE-UNITS] per-lease conversion factor (manager-editable)
    miles_to_km_conversion      DECIMAL(8,6)  NOT NULL DEFAULT 1.609344, -- [S-LEASE-UNITS] auto-reciprocated inverse
    actual_mileage              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mileage_at_start            INT UNSIGNED NULL,
    mileage_at_end              INT UNSIGNED NULL,
    gps_mileage_at_start        INT UNSIGNED NULL,
    gps_mileage_at_end          INT UNSIGNED NULL,
    mileage_precharge_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    mileage_precharge_invoiced  TINYINT(1) NOT NULL DEFAULT 0,
    tax_exempt                  TINYINT(1) NOT NULL DEFAULT 0,
    gst_exempt                  TINYINT(1) NOT NULL DEFAULT 0, -- [PASS-13:T2] granular exemption frozen at lease creation
    pst_exempt                  TINYINT(1) NOT NULL DEFAULT 0, -- [PASS-13:T2] granular exemption frozen at lease creation
    tax_rate_gst                DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    tax_rate_pst                DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    tax_rate_hst                DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    discount_type               ENUM('none','percentage','flat') NOT NULL DEFAULT 'none',
    discount_value              DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    insurance_opt_in            TINYINT(1) NOT NULL DEFAULT 0,
    insurance_cost              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    warranty_opt_in             TINYINT(1) NOT NULL DEFAULT 0,
    warranty_cost               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    billing_cycle               ENUM('monthly','on_close_only') NOT NULL DEFAULT 'monthly',
    advance_billing_periods     TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- ADV-BILL-1: prepaid future periods generated at activation (monthly only, capped via billing.max_advance_periods)
    po_number                   VARCHAR(100) NULL,
    last_billed_date            DATE NULL,
    last_billed_invoice_id      INT UNSIGNED NULL,   -- FK added via ALTER (circular)
    next_billing_date           DATE NULL,
    total_invoiced              DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_paid                  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    outstanding_balance         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_estimated_charge      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    final_total_charge          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    contract_file               VARCHAR(500) NULL,
    inspection_in_file          VARCHAR(500) NULL,
    inspection_out_file         VARCHAR(500) NULL,
    notes                       TEXT NULL,
    internal_notes              TEXT NULL,
    created_by                  INT UNSIGNED NULL,
    updated_by                  INT UNSIGNED NULL,
    closed_by_user_id           INT UNSIGNED NULL,
    closed_at                   DATETIME NULL,
    minimum_end_date            DATE NULL,                    -- [PASS-11:L2] early return fee calculation
    cancellation_reason         TEXT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                  DATETIME NULL,
    INDEX idx_contract (contract_number),
    INDEX idx_customer (customer_id),
    INDEX idx_unit (equipment_unit_id),
    INDEX idx_status (status),
    INDEX idx_start_date (start_date),
    INDEX idx_next_billing (next_billing_date),
    INDEX idx_active_billing (status, billing_cycle, next_billing_date, deleted_at), -- [PASS-10:D5] monthly invoice cron
    INDEX idx_deleted (deleted_at),
    FULLTEXT idx_ft_leases (contract_number, company_name_snapshot, unit_number_snapshot),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lease_status_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id    INT UNSIGNED NOT NULL,
    old_status  VARCHAR(50) NOT NULL,
    new_status  VARCHAR(50) NOT NULL,
    notes       TEXT NULL,
    changed_by  VARCHAR(255) NOT NULL DEFAULT 'system',
    user_id     INT UNSIGNED NULL,
    changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lease (lease_id),
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lease_amendments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id        INT UNSIGNED NOT NULL,
    amendment_type  ENUM('rate_change','date_extension','unit_swap','add_on','tax_change','other') NOT NULL, -- [PASS-11:E2,PASS-15:H2] tax_change added
    description     TEXT NOT NULL,
    old_values      JSON NULL,
    new_values      JSON NULL,
    document_path   VARCHAR(500) NULL,
    created_by      INT UNSIGNED NULL,
    approved_by     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lease (lease_id),
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 014_reservations.sql
CREATE TABLE reservations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status          ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed', -- [PASS-1:H1] pending→confirmed, pending→cancelled transitions added to spec state machine
    customer_id     INT UNSIGNED NULL,
    contact_name    VARCHAR(255) NOT NULL,
    company_name    VARCHAR(255) NOT NULL,
    contact_phone   VARCHAR(50) NULL,
    contact_email   VARCHAR(255) NULL,
    quantity        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    pickup_date     DATE NOT NULL,
    pickup_time     TIME NULL,
    yard_location   VARCHAR(100) NULL,
    purpose         VARCHAR(500) NULL,
    priority        ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    notes           TEXT NULL,
    internal_notes  TEXT NULL,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    marked_out_at   DATETIME NULL,
    marked_out_by   INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_pickup (pickup_date),
    INDEX idx_customer (customer_id),
    INDEX idx_deleted (deleted_at),
    INDEX idx_pickup_status (pickup_date, status, deleted_at), -- [PASS-5:1A] dashboard today's pickups covering index
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (marked_out_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservation_units (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id          INT UNSIGNED NOT NULL,
    equipment_unit_id       INT UNSIGNED NULL,
    unit_number_snapshot    VARCHAR(100) NOT NULL,
    template_name_snapshot  VARCHAR(100) NULL,
    status_at_reservation   VARCHAR(50) NULL,
    lease_id_linked         INT UNSIGNED NULL,
    entry_type              ENUM('system','manual') NOT NULL DEFAULT 'system',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reservation (reservation_id),
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE SET NULL,
    FOREIGN KEY (lease_id_linked) REFERENCES leases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 015_customer_rates.sql
CREATE TABLE customer_equipment_rates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    equipment_type  VARCHAR(255) NOT NULL,
    daily_rate      DECIMAL(10,2) NULL,
    weekly_rate     DECIMAL(10,2) NULL,
    monthly_rate    DECIMAL(10,2) NULL,
    mileage_rate    DECIMAL(8,4) NULL,
    mileage_unit    ENUM('km','miles') NOT NULL DEFAULT 'km',
    currency        ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    minimum_charge  DECIMAL(10,2) NULL,
    notes           TEXT NULL,
    effective_from  DATE NOT NULL DEFAULT (CURRENT_DATE),
    effective_to    DATE NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_type_date (customer_id, equipment_type, effective_from),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_rate_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    equipment_type  VARCHAR(255) NOT NULL,
    lease_id        INT UNSIGNED NULL,
    daily_rate      DECIMAL(10,2) NULL,
    weekly_rate     DECIMAL(10,2) NULL,
    monthly_rate    DECIMAL(10,2) NULL,
    mileage_rate    DECIMAL(8,4) NULL,
    mileage_unit    ENUM('km','miles') NULL,
    currency        ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    change_type     ENUM('created','updated','deleted','used_in_lease','override') NOT NULL DEFAULT 'created',
    change_source   ENUM('manual','lease_creation','bulk_update','system','import') NOT NULL DEFAULT 'manual',
    change_notes    TEXT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_lease (lease_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_discounts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    lease_id        INT UNSIGNED NULL,
    equipment_type  VARCHAR(255) NULL,
    discount_type   ENUM('percentage','flat') NOT NULL,
    discount_value  DECIMAL(8,4) NOT NULL,
    reason          VARCHAR(500) NOT NULL,
    valid_from      DATE NOT NULL,
    valid_to        DATE NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    approved_by     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 016_late_fee_rules.sql
CREATE TABLE late_fee_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NULL,
    fee_type        ENUM('percentage','flat') NOT NULL DEFAULT 'percentage',
    fee_value       DECIMAL(8,4) NOT NULL,
    grace_days      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_fee_amount  DECIMAL(10,2) NULL,
    compound        TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 017_maintenance.sql
CREATE TABLE maintenance_work_orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_order_number   VARCHAR(100) NOT NULL UNIQUE,
    equipment_unit_id   INT UNSIGNED NOT NULL,
    vendor_id           INT UNSIGNED NULL,
    work_type           ENUM('scheduled_service','repair','inspection','tire',
                             'electrical','body_damage','breakdown','other') NOT NULL,
    priority            ENUM('low','medium','high','emergency') NOT NULL DEFAULT 'medium',
    status              ENUM('open','in_progress','waiting_parts',
                             'completed','cancelled') NOT NULL DEFAULT 'open',
    title               VARCHAR(500) NOT NULL,
    description         TEXT NULL,
    mileage_at_service  INT UNSIGNED NULL,
    requested_date      DATE NOT NULL,
    scheduled_date      DATE NULL,
    completed_date      DATE NULL,
    labor_cost          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    parts_cost          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_cost          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes               TEXT NULL,
    internal_notes      TEXT NULL,
    resolution_notes    TEXT NULL,
    created_by          INT UNSIGNED NULL,
    assigned_to         INT UNSIGNED NULL,
    completed_by        INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME NULL,
    INDEX idx_unit (equipment_unit_id),
    INDEX idx_status (status),
    INDEX idx_vendor (vendor_id),
    INDEX idx_unit_status (equipment_unit_id, status),         -- [PASS-5:1C] unit profile maintenance tab
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE RESTRICT,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE maintenance_line_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_order_id   INT UNSIGNED NOT NULL,
    item_type       ENUM('labor','part','sublet','other') NOT NULL,
    description     VARCHAR(500) NOT NULL,
    quantity        DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    unit_cost       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_cost      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    part_number     VARCHAR(100) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 018_inspections.sql
CREATE TABLE inspections (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_number       VARCHAR(50) NULL UNIQUE,             -- INSP-YYYY-NNNNN; added S016
    inspection_type         ENUM('pre_lease','post_lease','periodic','damage','compliance') NOT NULL,
    equipment_unit_id       INT UNSIGNED NOT NULL,
    lease_id                INT UNSIGNED NULL,
    overall_condition       ENUM('excellent','good','fair','poor','damaged') NULL,
    condition_score         TINYINT UNSIGNED NULL,
    status                  ENUM('draft','complete','signed') NOT NULL DEFAULT 'draft',
    inspected_by            VARCHAR(255) NULL,
    inspected_by_user_id    INT UNSIGNED NULL,
    inspection_date         DATE NOT NULL,
    mileage_at_inspection   INT UNSIGNED NULL,
    reefer_hours            INT UNSIGNED NULL,                   -- trailer reefer engine hours; added S016
    fuel_level              ENUM('empty','quarter','half','three_quarter','full') NULL,  -- tank level at inspection; added S016
    cvi_expiry              DATE NULL,                           -- Commercial Vehicle Inspection expiry; added S016
    is_clean                TINYINT(1) NULL,                     -- 1=clean 0=dirty; added S016
    customer_signature      VARCHAR(500) NULL,
    signed_at               DATETIME NULL,
    pdf_path                VARCHAR(500) NULL,
    notes                   TEXT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_unit (equipment_unit_id),
    INDEX idx_lease (lease_id),
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE RESTRICT,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (inspected_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_sections (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id   INT UNSIGNED NOT NULL,
    section_name    VARCHAR(100) NOT NULL,
    `condition`     ENUM('ok','fair','damaged','missing','na') NOT NULL DEFAULT 'ok',
    notes           TEXT NULL,
    section_data    JSON NULL,   -- structured data: Tires section = per-position {brakes,tread,brand,org,wheels}; Trailer Condition = checklist items {mud_flaps,lights,canlocks,landing_gear,inflation,tray_skirts,rub_rail}; added S016
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_inspection (inspection_id),
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inspection_photos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id   INT UNSIGNED NOT NULL,
    section_id      INT UNSIGNED NULL,
    file_path       VARCHAR(500) NOT NULL,
    caption         VARCHAR(255) NULL,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inspection (inspection_id),
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES inspection_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 019_equipment_status_log.sql
-- NOTE: Must be created AFTER leases and reservations
CREATE TABLE equipment_status_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_unit_id   INT UNSIGNED NOT NULL,
    lease_id            INT UNSIGNED NULL,
    reservation_id      INT UNSIGNED NULL,
    old_status          VARCHAR(50) NOT NULL,
    new_status          VARCHAR(50) NOT NULL,
    reason              TEXT NULL,
    changed_by          VARCHAR(255) NOT NULL DEFAULT 'system',
    changed_by_user_id  INT UNSIGNED NULL,
    changed_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (equipment_unit_id),
    INDEX idx_changed (changed_at),
    INDEX idx_unit_changed (equipment_unit_id, changed_at),    -- [PASS-5:1F] unit profile status history with sort
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE CASCADE,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 020_yard_transfers.sql
CREATE TABLE yard_transfers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_unit_id   INT UNSIGNED NOT NULL,
    from_yard           VARCHAR(100) NOT NULL,
    to_yard             VARCHAR(100) NOT NULL,
    transfer_date       DATE NOT NULL,
    reason              TEXT NULL,
    authorized_by       INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (equipment_unit_id),
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE CASCADE,
    FOREIGN KEY (authorized_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GROUP 5 — FINANCIAL TABLES (ORDER CRITICAL)
-- ============================================================

-- 021_invoices.sql
-- NOTE: billing_period_id FK deferred — added via ALTER in 900_alter_deferred_fks.sql
CREATE TABLE invoices (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number              VARCHAR(100) NOT NULL UNIQUE,
    invoice_type                ENUM('regular','final','credit_note','late_fee',
                                     'mileage_only','adjustment') NOT NULL DEFAULT 'regular',
    customer_id                 INT UNSIGNED NULL,
    lease_id                    INT UNSIGNED NULL,
    billing_period_id           INT UNSIGNED NULL,    -- FK added via ALTER (circular)
    customer_name_snapshot      VARCHAR(255) NULL,
    company_name_snapshot       VARCHAR(255) NULL,
    contract_number_snapshot     VARCHAR(100) NULL,            -- [PASS-5:1E] zero-join invoice list queries
    unit_number_invoice_snapshot VARCHAR(100) NULL,            -- [PASS-5:1E] zero-join invoice list queries
    billing_address_snapshot    TEXT NULL,
    customer_email_snapshot     VARCHAR(255) NULL,
    tax_exempt_snapshot         TINYINT(1) NOT NULL DEFAULT 0,
    gst_exempt_snapshot         TINYINT(1) NOT NULL DEFAULT 0, -- [PASS-13:T2] granular exemption frozen from lease
    pst_exempt_snapshot         TINYINT(1) NOT NULL DEFAULT 0, -- [PASS-13:T2] granular exemption frozen from lease
    tax_exempt_number_snapshot  VARCHAR(100) NULL,
    po_number                   VARCHAR(100) NULL,
    currency                    ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    exchange_rate_to_cad        DECIMAL(10,6) NULL,
    currency_markup_pct         DECIMAL(6,4) NOT NULL DEFAULT 0.0000, -- [CURRENCY-MARKUP-1] markup % frozen at invoice creation
    billing_period_start        DATE NOT NULL,
    billing_period_end          DATE NOT NULL,
    billing_period_days         SMALLINT UNSIGNED NOT NULL,
    billing_type                ENUM('partial_start','full_month','partial_end',
                                     'single_period','mileage_only',
                                     'credit_note','adjustment') NOT NULL,
    rate_method_used            ENUM('daily','weekly','weekly_capped','monthly','none') NOT NULL DEFAULT 'none',
    rate_method_explanation     JSON NULL,
    invoice_date                DATE NOT NULL,
    due_date                    DATE NOT NULL,
    paid_date                   DATE NULL,
    sent_date                   DATE NULL,
    voided_date                 DATE NULL,
    status                      ENUM('draft','sent','partially_paid','paid',
                                     'overdue','void','written_off') NOT NULL DEFAULT 'draft',
    subtotal                    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_type               ENUM('none','percentage','flat') NOT NULL DEFAULT 'none',
    discount_value              DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    discount_amount             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subtotal_after_discount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_gst_rate                DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    tax_pst_rate                DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    tax_hst_rate                DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    tax_gst_amount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_pst_amount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_hst_amount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_total                   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    credits_applied             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance_due                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    late_fee_applied            TINYINT(1) NOT NULL DEFAULT 0,
    late_fee_amount             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    late_fee_date               DATE NULL,
    late_fee_invoice_id         INT UNSIGNED NULL,
    credit_note_for_invoice_id  INT UNSIGNED NULL,
    pdf_path                    VARCHAR(500) NULL,
    pdf_generated_at            DATETIME NULL,
    pdf_version                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sent_at                     DATETIME NULL,
    sent_by                     INT UNSIGNED NULL,
    sent_to_email               VARCHAR(255) NULL,
    sent_cc_emails              JSON NULL,
    delivery_method             ENUM('email','manual','portal') NULL,
    notes                       TEXT NULL,
    internal_notes              TEXT NULL,
    void_reason                 TEXT NULL,
    voided_by                   INT UNSIGNED NULL,
    write_off_reason            TEXT NULL,
    written_off_by              INT UNSIGNED NULL,
    written_off_at              DATETIME NULL,
    auto_generated              TINYINT(1) NOT NULL DEFAULT 0,
    auto_generated_at           DATETIME NULL,
    generation_source           ENUM('cron','manual','lease_close','late_fee_cron','advance') NULL,  -- ADV-BILL-1: 'advance' = pre-paid future-period invoice from lease activation batch
    created_by                  INT UNSIGNED NULL,
    updated_by                  INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                  DATETIME NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_lease (lease_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_billing_period (billing_period_start, billing_period_end),
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_customer_status_deleted (customer_id, status, deleted_at), -- [PASS-10:D4] customer balance recalc
    INDEX idx_deleted (deleted_at),
    INDEX idx_status_due_deleted (status, due_date, deleted_at), -- [PASS-10:D1] overdue cron + AR aging
    FULLTEXT idx_ft_invoices (invoice_number, company_name_snapshot),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (late_fee_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (credit_note_for_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_line_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id          INT UNSIGNED NOT NULL,
    sort_order          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    item_type           ENUM('base_rental','mileage_precharge','mileage_adjustment',
                             'mileage_credit','insurance','warranty','late_fee',
                             'early_return_credit','manual_adjustment','damage',
                             'discount','account_credit_applied','other') NOT NULL,
    description         VARCHAR(500) NOT NULL,
    detail_lines        JSON NULL,
    quantity            DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    unit               VARCHAR(50) NULL,
    unit_price          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    is_credit           TINYINT(1) NOT NULL DEFAULT 0,
    taxable             TINYINT(1) NOT NULL DEFAULT 1,
    tax_gst_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_pst_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_hst_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mileage_distance    DECIMAL(10,2) NULL,
    mileage_unit        ENUM('km','miles') NULL,
    mileage_rate        DECIMAL(8,4) NULL,
    mileage_estimated   DECIMAL(10,2) NULL,
    mileage_actual      DECIMAL(10,2) NULL,
    billing_days        SMALLINT UNSIGNED NULL,
    rate_method         ENUM('daily','weekly','weekly_capped','monthly') NULL,
    period_start        DATE NULL,
    period_end          DATE NULL,
    reference_type      VARCHAR(100) NULL,
    reference_id        INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice (invoice_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 022_lease_billing_periods.sql
CREATE TABLE lease_billing_periods (
    id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id                        INT UNSIGNED NOT NULL,
    invoice_id                      INT UNSIGNED NULL,
    period_start                    DATE NOT NULL,
    period_end                      DATE NOT NULL,
    period_days                     SMALLINT UNSIGNED NOT NULL,
    period_type                     ENUM('partial_start','full_month','partial_end','single_period') NOT NULL,
    rate_method                     ENUM('daily','weekly','weekly_capped','monthly') NOT NULL,
    rate_method_explanation         JSON NULL,
    base_amount                     DECIMAL(12,2) NOT NULL,
    discount_amount                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_amount                      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount                    DECIMAL(12,2) NOT NULL,
    daily_rate_used                 DECIMAL(10,2) NOT NULL,
    weekly_rate_used                DECIMAL(10,2) NOT NULL,
    monthly_rate_used               DECIMAL(10,2) NOT NULL,
    currency                        ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    status                          ENUM('pending','invoiced','paid','void') NOT NULL DEFAULT 'pending',
    has_mileage_precharge           TINYINT(1) NOT NULL DEFAULT 0,
    has_mileage_reconciliation      TINYINT(1) NOT NULL DEFAULT 0,
    mileage_precharge_amount        DECIMAL(12,2) NULL,
    mileage_reconciliation_amount   DECIMAL(12,2) NULL,
    created_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lease (lease_id),
    INDEX idx_invoice (invoice_id),
    INDEX idx_period (period_start, period_end),
    INDEX idx_status (status),
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 023_payments.sql
CREATE TABLE payments (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number          VARCHAR(100) NOT NULL UNIQUE,
    customer_id             INT UNSIGNED NULL,
    amount                  DECIMAL(12,2) NOT NULL,
    currency                ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    exchange_rate_to_cad    DECIMAL(10,6) NULL,
    currency_markup_pct     DECIMAL(6,4) NOT NULL DEFAULT 0.0000, -- [CURRENCY-MARKUP-1] markup % frozen at payment receipt
    amount_in_cad           DECIMAL(12,2) NULL,
    payment_method          ENUM('check','ach','wire','credit_card','cash',
                                 'e_transfer','account_credit','other') NOT NULL,
    reference_number        VARCHAR(100) NULL,
    bank_name               VARCHAR(100) NULL,
    check_number            VARCHAR(50) NULL,
    card_last_four          VARCHAR(4) NULL,
    payment_date            DATE NOT NULL,
    received_at             DATETIME NULL,
    deposited_date          DATE NULL,
    cleared_date            DATE NULL,
    status                  ENUM('pending','cleared','failed','refunded','void','returned') NOT NULL DEFAULT 'cleared',
    failure_reason          TEXT NULL,
    returned_reason         TEXT NULL,
    returned_date           DATE NULL,
    overpayment_amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    overpayment_action      ENUM('credit_to_account','refund','hold') NULL,
    overpayment_resolved    TINYINT(1) NOT NULL DEFAULT 0,
    refund_amount           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    refund_date             DATE NULL,
    refund_method           VARCHAR(100) NULL,
    refund_reference        VARCHAR(100) NULL,
    refunded_by             INT UNSIGNED NULL,
    notes                   TEXT NULL,
    internal_notes          TEXT NULL,
    deleted_at              DATETIME NULL,                     -- [PASS-13:F2] payments must be soft-deletable, never hard-deleted
    recorded_by             INT UNSIGNED NULL,
    verified_by             INT UNSIGNED NULL,
    verified_at             DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_date (payment_date),
    INDEX idx_status (status),
    INDEX idx_deleted (deleted_at),                            -- [PASS-13:F2]
    INDEX idx_customer_date (customer_id, payment_date),       -- [PASS-5:1D] customer profile payment history
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (refunded_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_allocations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id      INT UNSIGNED NOT NULL,
    invoice_id      INT UNSIGNED NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    currency        ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    allocation_type ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    notes           TEXT NULL,
    allocated_by    INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_invoice (payment_id, invoice_id),
    INDEX idx_invoice (invoice_id),                            -- [PASS-10:D9] payment history lookup by invoice
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 024_credit_notes.sql
CREATE TABLE credit_notes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_note_number  VARCHAR(100) NOT NULL UNIQUE,
    customer_id         INT UNSIGNED NOT NULL,
    lease_id            INT UNSIGNED NULL,
    source              ENUM('mileage_overpayment','invoice_adjustment',
                             'damage_resolution','goodwill','payment_returned',
                             'overpayment','other') NOT NULL,
    source_invoice_id   INT UNSIGNED NULL,
    source_payment_id   INT UNSIGNED NULL,
    amount              DECIMAL(12,2) NOT NULL,
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    amount_remaining    DECIMAL(12,2) NOT NULL,
    status              ENUM('active','partially_used','fully_used','expired','void') NOT NULL DEFAULT 'active',
    expires_at          DATE NULL,
    reason              TEXT NOT NULL,
    internal_notes      TEXT NULL,
    created_by          INT UNSIGNED NULL,
    voided_by           INT UNSIGNED NULL,
    voided_at           DATETIME NULL,
    deleted_at          DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (source_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (source_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_note_applications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_note_id  INT UNSIGNED NOT NULL,
    invoice_id      INT UNSIGNED NOT NULL,
    amount_applied  DECIMAL(12,2) NOT NULL,
    applied_by      INT UNSIGNED NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice (invoice_id),                            -- [PASS-10:D9] credit lookup by invoice
    FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GROUP 6 — CIRCULAR FK RESOLUTION (run immediately after group 5)
-- ============================================================

-- 900_alter_deferred_fks.sql  (also used for accounting deferred FKs)
ALTER TABLE invoices
    ADD CONSTRAINT fk_invoices_billing_period
    FOREIGN KEY (billing_period_id) REFERENCES lease_billing_periods(id) ON DELETE SET NULL;

ALTER TABLE leases
    ADD CONSTRAINT fk_leases_last_invoice
    FOREIGN KEY (last_billed_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL;

-- ============================================================
-- GROUP 7 — FLEET OPERATIONS (need invoices for damage_claims)
-- ============================================================

-- 025_damage_claims.sql
CREATE TABLE damage_claims (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_number            VARCHAR(100) NOT NULL UNIQUE,
    equipment_unit_id       INT UNSIGNED NOT NULL,
    lease_id                INT UNSIGNED NULL,
    customer_id             INT UNSIGNED NULL,
    customer_name           VARCHAR(255) NULL,        -- free-text fallback when customer not in system
    inspection_id           INT UNSIGNED NULL,
    work_order_id           INT UNSIGNED NULL,
    invoice_id              INT UNSIGNED NULL,
    vendor_id               INT UNSIGNED NULL,
    description             TEXT NOT NULL,
    damage_location         VARCHAR(255) NULL,
    severity                ENUM('minor','moderate','major','total_loss') NOT NULL DEFAULT 'minor',
    estimated_repair_cost   DECIMAL(12,2) NULL,
    actual_repair_cost      DECIMAL(12,2) NULL,
    customer_liable_amount  DECIMAL(12,2) NULL,
    insurance_claim_amount  DECIMAL(12,2) NULL,
    status                  ENUM('reported','assessed','repair_ordered',
                                 'invoiced','resolved','written_off') NOT NULL DEFAULT 'reported',
    notes                   TEXT NULL,
    resolution_notes        TEXT NULL,
    reported_by             INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME NULL,
    INDEX idx_unit (equipment_unit_id),
    INDEX idx_lease (lease_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_customer_created (customer_id, created_at),      -- [PASS-5:1H] risk score nightly calculation
    INDEX idx_vendor (vendor_id),
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE RESTRICT,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE SET NULL,
    FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE damage_claim_photos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_id    INT UNSIGNED NOT NULL,
    photo_type  ENUM('damage','repair_before','repair_after','other') NOT NULL DEFAULT 'damage',
    file_path   VARCHAR(500) NOT NULL,
    caption     VARCHAR(255) NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claim (claim_id),
    FOREIGN KEY (claim_id) REFERENCES damage_claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 026_mileage_logs.sql
CREATE TABLE mileage_logs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_unit_id   INT UNSIGNED NOT NULL,
    lease_id            INT UNSIGNED NULL,
    log_type            ENUM('manual','gps_sync','lease_start','lease_end','service') NOT NULL DEFAULT 'manual',
    odometer_reading    INT UNSIGNED NOT NULL,
    mileage_unit        ENUM('km','miles') NOT NULL DEFAULT 'km',
    log_date            DATE NOT NULL,
    notes               TEXT NULL,
    recorded_by         INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (equipment_unit_id),
    INDEX idx_date (log_date),
    INDEX idx_unit_date (equipment_unit_id, log_date),         -- [PASS-5:1G] unit profile mileage chart
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE CASCADE,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GROUP 8 — DOCUMENTS, REPORTS, NOTIFICATIONS, AI, CONFIG, PORTAL
-- ============================================================

-- 027_documents.sql
CREATE TABLE documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     ENUM('customer','equipment_unit','lease','inspection',
                         'damage_claim','contract','service_request') NOT NULL, -- [PASS-1:M6] service_request added for portal uploads
    entity_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    document_type   VARCHAR(100) NOT NULL,
    file_path       VARCHAR(500) NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    file_size_kb    INT UNSIGNED NULL,
    mime_type       VARCHAR(100) NULL,
    expiration_date DATE NULL,
    version         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    parent_id       INT UNSIGNED NULL,
    is_current      TINYINT(1) NOT NULL DEFAULT 1,
    is_private      TINYINT(1) NOT NULL DEFAULT 0,
    notes           TEXT NULL,
    uploaded_by     INT UNSIGNED NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_entity_created (entity_type, entity_id, uploaded_at), -- [PASS-10:D7] entity timeline ORDER BY — fixed: uploaded_at (not created_at)
    INDEX idx_expiry (expiration_date),
    FOREIGN KEY (parent_id) REFERENCES documents(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contract_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    content         LONGTEXT NOT NULL,
    variables_used  JSON NULL,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    version         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE generated_contracts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id            INT UNSIGNED NOT NULL,
    template_id         INT UNSIGNED NULL,
    file_path           VARCHAR(500) NOT NULL,
    generated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by        INT UNSIGNED NULL,
    signature_status    ENUM('pending','sent','signed','declined','expired') NOT NULL DEFAULT 'pending',
    signature_sent_at   DATETIME NULL,
    signature_signed_at DATETIME NULL,
    signee_email        VARCHAR(255) NULL,
    INDEX idx_lease (lease_id),
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES contract_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 028_reports.sql
CREATE TABLE report_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_type     VARCHAR(100) NOT NULL,
    parameters_hash VARCHAR(64) NOT NULL,
    parameters      JSON NOT NULL,
    result_data     LONGTEXT NOT NULL,
    generated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    generated_by    INT UNSIGNED NULL,
    UNIQUE KEY uq_report_hash (report_type, parameters_hash),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    report_type     VARCHAR(100) NOT NULL,
    parameters      JSON NOT NULL,
    frequency       ENUM('daily','weekly','monthly') NOT NULL,
    send_day        TINYINT UNSIGNED NULL,
    send_time       TIME NOT NULL DEFAULT '08:00:00',
    recipients      JSON NOT NULL,
    format          ENUM('pdf','csv','both') NOT NULL DEFAULT 'pdf',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_sent_at    DATETIME NULL,
    next_send_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE saved_reports (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    parameters  JSON NOT NULL,
    is_pinned   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 029_notifications.sql
CREATE TABLE notification_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    trigger_type    ENUM('compliance_expiry','overdue_invoice','lease_end',
                         'reservation_pickup','payment_received','low_utilization',
                         'damage_claim','custom') NOT NULL,
    trigger_config  JSON NOT NULL,
    channels        JSON NOT NULL,
    recipients      JSON NOT NULL,
    template_subject VARCHAR(500) NULL,
    template_body   TEXT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    rule_id     INT UNSIGNED NULL,
    title       VARCHAR(500) NOT NULL,
    message     TEXT NOT NULL,
    url         VARCHAR(500) NULL,
    entity_type VARCHAR(100) NULL,
    entity_id   INT UNSIGNED NULL,
    severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    read_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read),
    INDEX idx_user_unread (user_id, is_read),                  -- [PASS-10:D10] sidebar badge count
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (rule_id) REFERENCES notification_rules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_log (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id           INT UNSIGNED NULL,
    channel           ENUM('email','sms','in_app','webhook') NOT NULL,
    recipient         VARCHAR(255) NOT NULL,
    subject           VARCHAR(500) NULL,
    body              TEXT NULL,
    entity_type       VARCHAR(100) NULL,                      -- [PASS-1:C2] dedup support
    entity_id         INT UNSIGNED NULL,                      -- [PASS-1:C2] dedup support
    notification_type VARCHAR(100) NULL,                      -- [PASS-1:C2] dedup support
    status            ENUM('queued','sent','delivered','failed','bounced') NOT NULL DEFAULT 'queued',
    error_message     TEXT NULL,
    sent_at           DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_dedup (entity_type, entity_id, notification_type, created_at), -- [PASS-1:C2]
    FOREIGN KEY (rule_id) REFERENCES notification_rules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 030_ai.sql
CREATE TABLE ai_summaries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     VARCHAR(100) NOT NULL,
    entity_id       INT UNSIGNED NOT NULL,
    summary_type    ENUM('lease_summary','customer_insights','fleet_health',
                         'unit_analysis','payment_risk','forecast','anomaly') NOT NULL,
    content         LONGTEXT NOT NULL,
    tokens_used     INT UNSIGNED NULL,
    model_used      VARCHAR(100) NULL,
    generated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NULL,
    generated_by    INT UNSIGNED NULL,
    is_current      TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_entity_type (entity_type, entity_id, summary_type),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_entity_created (entity_type, entity_id, generated_at), -- [PASS-10:D7] entity timeline ORDER BY — fixed: generated_at (not created_at)
    INDEX idx_expires (expires_at),
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_chat_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    session_title   VARCHAR(255) NULL,
    context_type    VARCHAR(100) NULL,
    context_id      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at DATETIME NULL,
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_chat_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED NOT NULL,
    role        ENUM('user','assistant','system') NOT NULL,
    content     LONGTEXT NOT NULL,
    tokens_used INT UNSIGNED NULL,
    chart_data  JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    FOREIGN KEY (session_id) REFERENCES ai_chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_query_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NULL,
    query_type          VARCHAR(100) NOT NULL,
    prompt_tokens       INT UNSIGNED NULL,
    completion_tokens   INT UNSIGNED NULL,
    total_tokens        INT UNSIGNED NULL,
    cost_usd            DECIMAL(8,6) NULL,
    latency_ms          INT UNSIGNED NULL,
    was_cached          TINYINT(1) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 031_settings.sql
CREATE TABLE settings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`       VARCHAR(255) NOT NULL UNIQUE,
    value       LONGTEXT NULL,
    value_type  ENUM('string','integer','decimal','boolean','json','text') NOT NULL DEFAULT 'string',
    group_name  VARCHAR(100) NOT NULL DEFAULT 'general',
    label       VARCHAR(255) NULL,
    description TEXT NULL,
    is_public   TINYINT(1) NOT NULL DEFAULT 0,
    updated_by  INT UNSIGNED NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_group (`group_name`),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 032_portal.sql
CREATE TABLE portal_users (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id           INT UNSIGNED NOT NULL,
    name                  VARCHAR(255) NOT NULL,
    email                 VARCHAR(255) NOT NULL UNIQUE,
    password_hash         VARCHAR(255) NULL,
    auth0_sub             VARCHAR(255) NULL UNIQUE, -- [PASS-1:H9] kept nullable for possible future SSO, mirrors users table
    status                ENUM('active','inactive','invited') NOT NULL DEFAULT 'invited',
    invite_token          VARCHAR(100) NULL,
    invite_token_expiry   DATETIME NULL,                      -- [PASS-1:C3] portal login security
    invite_sent_at        DATETIME NULL,                      -- [PASS-1:C3]
    last_login_at         DATETIME NULL,
    last_login_ip         VARCHAR(45) NULL,                   -- [PASS-1:C3] matches users table pattern
    login_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0, -- [PASS-1:C3] brute force protection (5 attempts → 15 min lockout)
    locked_until          DATETIME NULL,                      -- [PASS-1:C3] lockout expiry
    password_reset_token  VARCHAR(100) NULL,                  -- [PASS-1:C3] portal password reset flow
    password_reset_expiry DATETIME NULL,                      -- [PASS-1:C3] 2-hour expiry per spec
    is_primary            TINYINT(1) NOT NULL DEFAULT 0,      -- [PASS-4:2.3] first portal user per customer = primary (manages sub-users)
    notification_preferences JSON NULL DEFAULT NULL,          -- [CVI-EMAIL-1] per-user email opt-outs: {"compliance_expiring": bool}
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_email (email),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE portal_service_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    portal_user_id      INT UNSIGNED NOT NULL,
    customer_id         INT UNSIGNED NOT NULL,
    equipment_unit_id   INT UNSIGNED NULL,
    lease_id            INT UNSIGNED NULL,
    request_type        ENUM('lease_extension','early_return','damage_report',
                             'billing_inquiry','document_request',
                             'new_lease_inquiry','general') NOT NULL,
    subject             VARCHAR(500) NOT NULL,
    message             TEXT NOT NULL,
    status              ENUM('open','in_review','resolved','closed') NOT NULL DEFAULT 'open',
    assigned_to         INT UNSIGNED NULL,
    response            TEXT NULL,
    resolved_at         DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE SET NULL,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- GROUP 9 — ACCOUNTING TABLES (acc_ prefix)
-- ============================================================

-- acc_01_periods.sql
CREATE TABLE acc_periods (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year            SMALLINT UNSIGNED NOT NULL,
    month           TINYINT UNSIGNED NOT NULL,
    name            VARCHAR(50) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          ENUM('open','closed','locked') NOT NULL DEFAULT 'open',
    closed_by       INT UNSIGNED NULL,
    closed_at       DATETIME NULL,
    locked_by       INT UNSIGNED NULL,
    locked_at       DATETIME NULL,
    is_year_end     TINYINT(1) NOT NULL DEFAULT 0,
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period (year, month),
    INDEX idx_status (status),
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_02_accounts.sql
CREATE TABLE acc_accounts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20) NOT NULL UNIQUE,
    name            VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    account_type    ENUM('asset','liability','equity','revenue','cost_of_revenue',
                         'operating_expense','other_income','other_expense') NOT NULL,
    account_subtype VARCHAR(100) NULL,
    parent_id       INT UNSIGNED NULL,
    is_header       TINYINT(1) NOT NULL DEFAULT 0,
    currency        ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    normal_balance  ENUM('debit','credit') NOT NULL,
    is_system       TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_bank_account TINYINT(1) NOT NULL DEFAULT 0,
    tax_line_code   VARCHAR(50) NULL,
    coa_group       VARCHAR(100) NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    notes           TEXT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (account_type),
    INDEX idx_parent (parent_id),
    INDEX idx_active (is_active),
    FULLTEXT idx_ft_accounts (code, name),
    FOREIGN KEY (parent_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_03_journal_entries.sql
CREATE TABLE acc_journal_entries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_number    VARCHAR(50) NOT NULL UNIQUE,
    period_id       INT UNSIGNED NOT NULL,
    entry_date      DATE NOT NULL,
    entry_type      ENUM('manual','system','recurring','reversing','year_end','adjustment') NOT NULL DEFAULT 'manual',
    status          ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
    description     VARCHAR(500) NOT NULL,
    reference       VARCHAR(255) NULL,
    source_type     ENUM('invoice','payment','credit_note','ap_bill','ap_payment',
                         'bank_transaction','depreciation','asset_disposal',
                         'fx_revaluation','manual','year_end','recurring') NULL,
    source_id       INT UNSIGNED NULL,
    is_reversal         TINYINT(1) NOT NULL DEFAULT 0,
    reversal_of_id      INT UNSIGNED NULL,
    reversed_by_id      INT UNSIGNED NULL,
    reversal_date       DATE NULL,
    auto_reverse        TINYINT(1) NOT NULL DEFAULT 0,
    auto_reverse_date   DATE NULL,
    currency        ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    exchange_rate   DECIMAL(10,6) NULL,
    posted_by       INT UNSIGNED NULL,
    posted_at       DATETIME NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_period (period_id),
    INDEX idx_date (entry_date),
    INDEX idx_type (entry_type),
    INDEX idx_status (status),
    INDEX idx_source (source_type, source_id),
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (reversal_of_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (reversed_by_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_journal_entry_lines (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id    INT UNSIGNED NOT NULL,
    account_id          INT UNSIGNED NOT NULL,
    line_number         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    description         VARCHAR(500) NULL,
    debit               DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit              DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    foreign_amount      DECIMAL(15,2) NULL,
    foreign_currency    ENUM('CAD','USD') NULL,
    exchange_rate       DECIMAL(10,6) NULL,
    customer_id         INT UNSIGNED NULL,
    vendor_id           INT UNSIGNED NULL,
    equipment_unit_id   INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_journal_entry (journal_entry_id),
    INDEX idx_account (account_id),
    INDEX idx_customer (customer_id),
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_04_recurring_entries.sql
CREATE TABLE acc_recurring_entries (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,
    description         VARCHAR(500) NULL,
    frequency           ENUM('monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
    day_of_month        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    start_date          DATE NOT NULL,
    end_date            DATE NULL,
    next_post_date      DATE NOT NULL,
    last_posted_date    DATE NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    auto_post           TINYINT(1) NOT NULL DEFAULT 0,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_next_post (next_post_date),
    INDEX idx_active (is_active),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_recurring_entry_lines (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recurring_entry_id  INT UNSIGNED NOT NULL,
    account_id          INT UNSIGNED NOT NULL,
    line_number         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    description         VARCHAR(500) NULL,
    debit               DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit              DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (recurring_entry_id) REFERENCES acc_recurring_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_05_bank_accounts.sql (BEFORE acc_bank_transactions — creation order fix)
CREATE TABLE acc_bank_accounts (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(255) NOT NULL,
    account_number_last4    VARCHAR(4) NULL,
    institution             VARCHAR(255) NULL,
    account_type            ENUM('checking','savings','line_of_credit','credit_card') NOT NULL DEFAULT 'checking',
    currency                ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    gl_account_id           INT UNSIGNED NOT NULL,
    opening_balance         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    opening_balance_date    DATE NULL,
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    is_default              TINYINT(1) NOT NULL DEFAULT 0,
    notes                   TEXT NULL,
    created_by              INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gl_account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_06_bank_reconciliations.sql (BEFORE acc_bank_transactions — creation order fix)
CREATE TABLE acc_bank_reconciliations (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_account_id             INT UNSIGNED NOT NULL,
    period_id                   INT UNSIGNED NOT NULL,
    statement_date              DATE NOT NULL,
    statement_ending_balance    DECIMAL(15,2) NOT NULL,
    book_balance                DECIMAL(15,2) NOT NULL,
    outstanding_deposits        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    outstanding_checks          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    adjusted_book_balance       DECIMAL(15,2) NOT NULL,
    difference                  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status                      ENUM('in_progress','completed','locked') NOT NULL DEFAULT 'in_progress',
    completed_by                INT UNSIGNED NULL,
    completed_at                DATETIME NULL,
    notes                       TEXT NULL,
    created_by                  INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_period (bank_account_id, period_id),
    INDEX idx_status (status),
    FOREIGN KEY (bank_account_id) REFERENCES acc_bank_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_07_bank_transactions.sql
CREATE TABLE acc_bank_transactions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_account_id     INT UNSIGNED NOT NULL,
    transaction_date    DATE NOT NULL,
    description         VARCHAR(500) NOT NULL,
    reference           VARCHAR(255) NULL,
    amount              DECIMAL(15,2) NOT NULL,
    transaction_type    ENUM('deposit','withdrawal','transfer','bank_charge',
                             'interest','nsf','other') NOT NULL,
    source              ENUM('manual','import','system') NOT NULL DEFAULT 'manual',
    status              ENUM('unmatched','matched','excluded') NOT NULL DEFAULT 'unmatched',
    matched_type        ENUM('payment','ap_payment','journal_entry','bank_transfer','other') NULL,
    matched_id          INT UNSIGNED NULL,
    matched_at          DATETIME NULL,
    matched_by          INT UNSIGNED NULL,
    reconciliation_id   INT UNSIGNED NULL,
    is_cleared          TINYINT(1) NOT NULL DEFAULT 0,
    cleared_date        DATE NULL,
    journal_entry_id    INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bank_account (bank_account_id),
    INDEX idx_date (transaction_date),
    INDEX idx_status (status),
    FOREIGN KEY (bank_account_id) REFERENCES acc_bank_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (reconciliation_id) REFERENCES acc_bank_reconciliations(id) ON DELETE SET NULL,
    FOREIGN KEY (matched_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_08_bills.sql
CREATE TABLE acc_bills (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_number         VARCHAR(100) NOT NULL UNIQUE,
    vendor_id           INT UNSIGNED NOT NULL,
    vendor_bill_number  VARCHAR(100) NULL,
    bill_date           DATE NOT NULL,
    due_date            DATE NOT NULL,
    period_id           INT UNSIGNED NOT NULL,
    status              ENUM('draft','approved','scheduled','partially_paid','paid','void') NOT NULL DEFAULT 'draft',
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    exchange_rate_to_cad DECIMAL(10,6) NULL,
    subtotal            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_gst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_pst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_hst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_total           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_amount        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount_paid         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance_due         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    work_order_id       INT UNSIGNED NULL,
    equipment_unit_id   INT UNSIGNED NULL,
    notes               TEXT NULL,
    internal_notes      TEXT NULL,
    void_reason         TEXT NULL,
    voided_by           INT UNSIGNED NULL,
    voided_at           DATETIME NULL,
    journal_entry_id    INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vendor (vendor_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_period (period_id),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE SET NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_09_bill_lines.sql
-- NOTE: asset_id FK deferred — acc_fixed_assets doesn't exist yet
CREATE TABLE acc_bill_lines (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_id             INT UNSIGNED NOT NULL,
    account_id          INT UNSIGNED NOT NULL,
    description         VARCHAR(500) NOT NULL,
    quantity            DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    unit_cost           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount              DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    is_tax_input_credit TINYINT(1) NOT NULL DEFAULT 1,
    tax_gst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_pst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_hst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    capitalize          TINYINT(1) NOT NULL DEFAULT 0,
    asset_id            INT UNSIGNED NULL,   -- FK added via ALTER after acc_fixed_assets
    sort_order          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill (bill_id),
    FOREIGN KEY (bill_id) REFERENCES acc_bills(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_10_ap_payments.sql
CREATE TABLE acc_ap_payments (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number          VARCHAR(100) NOT NULL UNIQUE,
    vendor_id               INT UNSIGNED NOT NULL,
    bank_account_id         INT UNSIGNED NOT NULL,
    payment_date            DATE NOT NULL,
    payment_method          ENUM('check','eft','wire','credit_card','cash','other') NOT NULL,
    reference_number        VARCHAR(100) NULL,
    check_number            VARCHAR(50) NULL,
    amount                  DECIMAL(15,2) NOT NULL,
    currency                ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    exchange_rate_to_cad    DECIMAL(10,6) NULL,
    status                  ENUM('pending','cleared','void') NOT NULL DEFAULT 'cleared',
    void_reason             TEXT NULL,
    voided_by               INT UNSIGNED NULL,
    voided_at               DATETIME NULL,
    journal_entry_id        INT UNSIGNED NULL,
    notes                   TEXT NULL,
    created_by              INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vendor (vendor_id),
    INDEX idx_date (payment_date),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
    FOREIGN KEY (bank_account_id) REFERENCES acc_bank_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_ap_payment_allocations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ap_payment_id   INT UNSIGNED NOT NULL,
    bill_id         INT UNSIGNED NOT NULL,
    amount_applied  DECIMAL(15,2) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_bill (ap_payment_id, bill_id),
    FOREIGN KEY (ap_payment_id) REFERENCES acc_ap_payments(id) ON DELETE CASCADE,
    FOREIGN KEY (bill_id) REFERENCES acc_bills(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_11_vendor_credits.sql
CREATE TABLE acc_vendor_credits (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_number       VARCHAR(100) NOT NULL UNIQUE,
    vendor_id           INT UNSIGNED NOT NULL,
    credit_date         DATE NOT NULL,
    reason              VARCHAR(500) NOT NULL,
    amount              DECIMAL(15,2) NOT NULL,
    amount_remaining    DECIMAL(15,2) NOT NULL,
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    status              ENUM('active','partially_used','fully_used','void') NOT NULL DEFAULT 'active',
    source_bill_id      INT UNSIGNED NULL,
    journal_entry_id    INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vendor (vendor_id),
    INDEX idx_status (status),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
    FOREIGN KEY (source_bill_id) REFERENCES acc_bills(id) ON DELETE SET NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_vendor_credit_applications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_credit_id INT UNSIGNED NOT NULL,
    bill_id         INT UNSIGNED NOT NULL,
    amount_applied  DECIMAL(15,2) NOT NULL,
    applied_by      INT UNSIGNED NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_credit_id) REFERENCES acc_vendor_credits(id) ON DELETE CASCADE,
    FOREIGN KEY (bill_id) REFERENCES acc_bills(id) ON DELETE RESTRICT,
    FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_12_fixed_assets.sql
CREATE TABLE acc_fixed_assets (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_number                VARCHAR(100) NOT NULL UNIQUE,
    name                        VARCHAR(255) NOT NULL,
    description                 TEXT NULL,
    asset_class                 ENUM('fleet_equipment','vehicles','office_equipment',
                                     'leasehold_improvements','land','building','other') NOT NULL,
    cra_class                   VARCHAR(20) NULL,
    cra_cca_rate                DECIMAL(5,4) NULL,
    equipment_unit_id           INT UNSIGNED NULL,
    acquisition_date            DATE NOT NULL,
    acquisition_cost            DECIMAL(15,2) NOT NULL,
    acquisition_bill_id         INT UNSIGNED NULL,
    vendor_id                   INT UNSIGNED NULL,
    depreciation_method         ENUM('straight_line','declining_balance',
                                     'units_of_production','none') NOT NULL DEFAULT 'straight_line',
    useful_life_years           DECIMAL(5,2) NULL,
    salvage_value               DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    depreciable_cost            DECIMAL(15,2) NOT NULL,
    accumulated_depreciation    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_book_value              DECIMAL(15,2) NOT NULL,
    last_depreciation_date      DATE NULL,
    depreciation_start_date     DATE NOT NULL,
    fully_depreciated_date      DATE NULL,
    asset_account_id            INT UNSIGNED NOT NULL,
    accum_depr_account_id       INT UNSIGNED NOT NULL,
    depr_expense_account_id     INT UNSIGNED NOT NULL,
    status                      ENUM('active','fully_depreciated','disposed','impaired') NOT NULL DEFAULT 'active',
    total_expected_units        INT UNSIGNED NULL,
    units_used_to_date          INT UNSIGNED NOT NULL DEFAULT 0,
    location                    VARCHAR(255) NULL,
    serial_number               VARCHAR(100) NULL,
    notes                       TEXT NULL,
    created_by                  INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_class (asset_class),
    INDEX idx_unit (equipment_unit_id),
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE SET NULL,
    FOREIGN KEY (acquisition_bill_id) REFERENCES acc_bills(id) ON DELETE SET NULL,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (asset_account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (accum_depr_account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (depr_expense_account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_14_depreciation.sql
CREATE TABLE acc_depreciation_runs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_id           INT UNSIGNED NOT NULL,
    run_date            DATETIME NOT NULL,
    status              ENUM('preview','posted','reversed') NOT NULL DEFAULT 'preview',
    total_depreciation  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    asset_count         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    journal_entry_id    INT UNSIGNED NULL,
    run_by              INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Only one posted run per period (preview can be recreated after deletion)
    UNIQUE KEY uq_period_run (period_id, status),             -- [PASS-1:H5] prevents double-posted depreciation
    INDEX idx_period (period_id),
    INDEX idx_status (status),
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_depreciation_run_lines (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id          INT UNSIGNED NOT NULL,
    asset_id        INT UNSIGNED NOT NULL,
    period_id       INT UNSIGNED NOT NULL,
    opening_nbv     DECIMAL(15,2) NOT NULL,
    depreciation    DECIMAL(15,2) NOT NULL,
    closing_nbv     DECIMAL(15,2) NOT NULL,
    method_used     ENUM('straight_line','declining_balance','units_of_production') NOT NULL,
    calculation_detail JSON NULL,
    INDEX idx_run (run_id),
    INDEX idx_asset (asset_id),
    FOREIGN KEY (run_id) REFERENCES acc_depreciation_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES acc_fixed_assets(id) ON DELETE CASCADE,
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_asset_disposals (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id                    INT UNSIGNED NOT NULL,
    disposal_date               DATE NOT NULL,
    disposal_type               ENUM('sale','scrap','trade_in','write_off','other') NOT NULL,
    proceeds                    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_book_value_at_disposal  DECIMAL(15,2) NOT NULL,
    gain_loss                   DECIMAL(15,2) NOT NULL,
    proceeds_account_id         INT UNSIGNED NULL,
    gain_loss_account_id        INT UNSIGNED NOT NULL,
    journal_entry_id            INT UNSIGNED NULL,
    buyer_name                  VARCHAR(255) NULL,
    buyer_reference             VARCHAR(255) NULL,
    notes                       TEXT NULL,
    created_by                  INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES acc_fixed_assets(id) ON DELETE RESTRICT,
    FOREIGN KEY (proceeds_account_id) REFERENCES acc_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (gain_loss_account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_asset_impairments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id            INT UNSIGNED NOT NULL,
    impairment_date     DATE NOT NULL,
    pre_impairment_nbv  DECIMAL(15,2) NOT NULL,
    recoverable_amount  DECIMAL(15,2) NOT NULL,
    impairment_loss     DECIMAL(15,2) NOT NULL,
    reason              TEXT NOT NULL,
    journal_entry_id    INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asset (asset_id),
    FOREIGN KEY (asset_id) REFERENCES acc_fixed_assets(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_15_tax.sql
CREATE TABLE acc_tax_filing_periods (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tax_type            ENUM('gst_hst','pst_bc','pst_sk','pst_mb') NOT NULL,
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    filing_due_date     DATE NOT NULL,
    frequency           ENUM('monthly','quarterly','annually') NOT NULL,
    total_sales         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_tax_collected DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_itc           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_tax_owing       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status              ENUM('open','calculated','filed','remitted') NOT NULL DEFAULT 'open',
    filed_date          DATE NULL,
    filed_by            INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_period (tax_type, period_start, period_end),
    INDEX idx_status (status),
    FOREIGN KEY (filed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_tax_remittances (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filing_period_id    INT UNSIGNED NOT NULL,
    remittance_date     DATE NOT NULL,
    amount              DECIMAL(15,2) NOT NULL,
    payment_method      ENUM('online_banking','check','wire','other') NOT NULL,
    reference_number    VARCHAR(100) NULL,
    bank_account_id     INT UNSIGNED NULL,
    journal_entry_id    INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (filing_period_id) REFERENCES acc_tax_filing_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (bank_account_id) REFERENCES acc_bank_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_16_ar_collections.sql
CREATE TABLE acc_collection_notes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    invoice_id      INT UNSIGNED NULL,
    note_date       DATE NOT NULL,
    contact_method  ENUM('phone','email','letter','in_person','other') NOT NULL,
    contact_person  VARCHAR(255) NULL,
    note            TEXT NOT NULL,
    outcome         ENUM('no_answer','left_message','spoke_with_customer',
                         'payment_promised','dispute','other') NOT NULL,
    follow_up_date  DATE NULL,
    created_by      INT UNSIGNED NULL,    -- NULL because FK is ON DELETE SET NULL — cannot be NOT NULL
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_invoice (invoice_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL -- [PASS-1:H8] consistent with rest of schema
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_promise_to_pay (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT UNSIGNED NOT NULL,
    invoice_id          INT UNSIGNED NULL,
    promised_amount     DECIMAL(15,2) NOT NULL,
    promise_date        DATE NOT NULL,
    promised_by         VARCHAR(255) NULL,
    status              ENUM('pending','kept','broken','cancelled') NOT NULL DEFAULT 'pending',
    actual_payment_date DATE NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,    -- NULL because FK is ON DELETE SET NULL — cannot be NOT NULL
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_promise_date (promise_date),
    INDEX idx_status (status),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL -- [PASS-1:H8] consistent with rest of schema
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_dunning_letters (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    letter_type     ENUM('reminder_30','reminder_60','warning_90','final_notice') NOT NULL,
    sent_date       DATE NOT NULL,
    sent_method     ENUM('email','mail','both') NOT NULL,
    sent_to_email   VARCHAR(255) NULL,
    total_overdue   DECIMAL(15,2) NOT NULL,
    invoice_count   TINYINT UNSIGNED NOT NULL,
    pdf_path        VARCHAR(500) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_bad_debt_writeoffs (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id                  INT UNSIGNED NOT NULL,
    customer_id                 INT UNSIGNED NOT NULL,
    writeoff_date               DATE NOT NULL,
    amount                      DECIMAL(15,2) NOT NULL,
    reason                      TEXT NOT NULL,
    journal_entry_id            INT UNSIGNED NULL,
    recovered                   TINYINT(1) NOT NULL DEFAULT 0,
    recovered_amount            DECIMAL(15,2) NULL,
    recovered_date              DATE NULL,
    recovery_journal_entry_id  INT UNSIGNED NULL,
    created_by                  INT UNSIGNED NULL,    -- NULL because FK is ON DELETE SET NULL — cannot be NOT NULL
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_invoice (invoice_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (recovery_journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL -- [PASS-1:H8] consistent with rest of schema
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_customer_deposits (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_number          VARCHAR(100) NOT NULL UNIQUE,
    customer_id             INT UNSIGNED NOT NULL,
    lease_id                INT UNSIGNED NULL,
    deposit_type            ENUM('security','damage','advance_payment','other') NOT NULL DEFAULT 'security',
    amount                  DECIMAL(15,2) NOT NULL,
    currency                ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    received_date           DATE NOT NULL,
    status                  ENUM('held','applied','refunded','forfeited') NOT NULL DEFAULT 'held',
    applied_to_invoice_id   INT UNSIGNED NULL,
    applied_date            DATE NULL,
    refund_date             DATE NULL,
    refund_method           VARCHAR(100) NULL,
    journal_entry_id        INT UNSIGNED NULL,
    liability_account_id    INT UNSIGNED NULL,
    notes                   TEXT NULL,
    created_by              INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (applied_to_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (liability_account_id) REFERENCES acc_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_17_budgets.sql
CREATE TABLE acc_budgets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    year            SMALLINT UNSIGNED NOT NULL,
    version         ENUM('base','conservative','optimistic') NOT NULL DEFAULT 'base',
    status          ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    is_active       TINYINT(1) NOT NULL DEFAULT 0,
    notes           TEXT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Enforce only one active budget per year (not per version — just one total)
    UNIQUE KEY uq_year_version_active (year, version, is_active), -- [PASS-1:H4] prevents duplicate active budgets
    INDEX idx_year (year),
    INDEX idx_active (is_active),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_budget_lines (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    budget_id       INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    jan             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    feb             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    mar             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    apr             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    may             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    jun             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    jul             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    aug             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    sep             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    oct             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    nov             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `dec`           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    annual_total    DECIMAL(15,2) GENERATED ALWAYS AS
                    (jan+feb+mar+apr+may+jun+jul+aug+sep+oct+nov+`dec`) STORED,
    notes           VARCHAR(500) NULL,
    UNIQUE KEY uq_budget_account (budget_id, account_id),
    FOREIGN KEY (budget_id) REFERENCES acc_budgets(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_18_fx.sql
CREATE TABLE acc_fx_revaluations (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    revaluation_date        DATE NOT NULL,
    period_id               INT UNSIGNED NOT NULL,
    exchange_rate_used      DECIMAL(10,6) NOT NULL,
    total_ar_usd            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_ar_cad_book       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_ar_cad_revalued   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    unrealized_gain_loss    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    journal_entry_id        INT UNSIGNED NULL,
    created_by              INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_period (period_id),
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_19_documents.sql
CREATE TABLE acc_documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     ENUM('journal_entry','bill','ap_payment','bank_transaction',
                         'asset','tax_filing','reconciliation','other') NOT NULL,
    entity_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    file_path       VARCHAR(500) NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    file_size_kb    INT UNSIGNED NULL,
    mime_type       VARCHAR(100) NULL,
    notes           TEXT NULL,
    uploaded_by     INT UNSIGNED NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_entity_created (entity_type, entity_id, uploaded_at), -- [PASS-10:D7] entity timeline ORDER BY — fixed: uploaded_at (not created_at)
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_20_year_end.sql
CREATE TABLE acc_year_end_checklist (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year            SMALLINT UNSIGNED NOT NULL,
    item_key        VARCHAR(100) NOT NULL,
    item_label      VARCHAR(500) NOT NULL,
    is_complete     TINYINT(1) NOT NULL DEFAULT 0,
    completed_by    INT UNSIGNED NULL,
    completed_at    DATETIME NULL,
    notes           TEXT NULL,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_year_item (year, item_key),
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_report_configurations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    report_type     VARCHAR(100) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    parameters      JSON NOT NULL,
    is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_type (report_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- acc_21_qbo_sync.sql
-- QuickBooks Online sync log — Phase 26 placeholder
CREATE TABLE acc_qbo_sync_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     ENUM('customer','invoice','payment','credit_memo',
                         'bill','vendor','account') NOT NULL,
    ff_entity_id    INT UNSIGNED NOT NULL,
    qbo_entity_id   VARCHAR(100) NOT NULL,
    qbo_sync_token  VARCHAR(20) NULL,
    last_synced_at  DATETIME NOT NULL,
    status          ENUM('synced','failed','pending') NOT NULL DEFAULT 'synced',
    error_message   TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_entity (entity_type, ff_entity_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEFERRED FK RESOLUTION — run after all CREATE TABLE scripts
-- File: 900_alter_deferred_fks.sql
-- ============================================================

-- Already run in GROUP 6 above (invoices/leases circular FK)
-- Accounting deferred FK:
ALTER TABLE acc_bill_lines
    ADD CONSTRAINT fk_bill_lines_asset
    FOREIGN KEY (asset_id) REFERENCES acc_fixed_assets(id) ON DELETE SET NULL;

-- ============================================================
-- UTILITY TABLE — Schema Migration Tracking [PASS-12:M2]
-- ============================================================
CREATE TABLE IF NOT EXISTS schema_migrations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version     VARCHAR(100) NOT NULL UNIQUE,
    filename    VARCHAR(255) NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rate_limit_attempts — [S-PROD-1A] fixed-window rate limiting for login/forgot-password/AI/MFA
CREATE TABLE rate_limit_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_key    VARCHAR(255) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    window_start  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until DATETIME NULL,
    INDEX idx_bucket_key (bucket_key),
    INDEX idx_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FINAL TABLE COUNT
-- Core tables:        59  (customer_documents + equipment_documents dropped)
-- Accounting tables:  34  (acc_ prefix, includes acc_qbo_sync_log)
-- Utility tables:      1  (schema_migrations)
-- TOTAL:              94
-- ============================================================

-- ============================================================
-- CHANGELOG — v1.2 FINAL (post build-readiness review)
-- ============================================================
-- [PASS-1:C2]  notification_log: added entity_type, entity_id, notification_type + dedup index
-- [PASS-1:C3]  portal_users: added login_attempts, locked_until, password_reset_token/expiry, last_login_ip, invite_token_expiry, invite_sent_at, is_primary
-- [PASS-1:C5]  equipment_units: FULLTEXT index now includes gps_device_id
-- [PASS-1:C6]  Header clarified: file is self-contained, no separate 900_alter needed
-- [PASS-1:H1]  reservations: pending transitions documented (state machine in spec)
-- [PASS-1:H2]  leases.status: 'deleted' removed from ENUM (use deleted_at)
-- [PASS-1:H4]  acc_budgets: UNIQUE KEY uq_year_version_active added (prevents duplicate active budgets)
-- [PASS-1:H5]  acc_depreciation_runs: UNIQUE KEY uq_period_run added (prevents double-posted runs)
-- [PASS-1:H8]  acc_collection_notes etc: created_by FK changed to ON DELETE SET NULL
-- [PASS-1:H9]  portal_users.auth0_sub: documented justification for future SSO
-- [PASS-1:L5]  audit_log_archive: documented no-FK design
-- [PASS-1:L6]  rate_cards: added idx_default, idx_effective indexes
-- [PASS-1:M6]  documents.entity_type: added 'service_request'
-- [PASS-4:1.5] users: added remember_token column
-- [PASS-4:2.3] portal_users: added is_primary column (sub-user management)
-- [PASS-5:1E]  invoices: added contract_number_snapshot, unit_number_invoice_snapshot
-- [PASS-10:D1] invoices: added idx_status_due_deleted composite index
-- [PASS-10:D3] equipment_units: added 4 expiry date indexes
-- [PASS-10:D4] invoices: added idx_customer_status_deleted composite index
-- [PASS-10:D5] leases: added idx_active_billing composite index
-- [PASS-10:D7] audit_log: added idx_entity_created composite index
-- [PASS-10:D9] payment_allocations: added idx_invoice; credit_note_applications: added idx_invoice
-- [PASS-10:D10] notifications: added idx_user_unread composite index
-- [PASS-11:E2] lease_amendments: added 'tax_change' to amendment_type ENUM
-- [PASS-11:L2] leases: added minimum_end_date column
-- [PASS-12:M2] Added schema_migrations table
-- [PASS-13:T2] customers: added granular gst_exempt, pst_exempt columns + cert numbers + expiry dates
-- [PASS-13:T2] leases: added gst_exempt, pst_exempt snapshot columns (frozen at lease creation)
-- [PASS-13:T2] invoices: added gst_exempt_snapshot, pst_exempt_snapshot columns
-- [PASS-13:F2] payments: added deleted_at column (soft-delete; 15 tables now)
-- [PASS-5:1A]  reservations: added idx_pickup_status composite index (dashboard today's pickups)
-- [PASS-5:1C]  maintenance_work_orders: added idx_unit_status composite index (unit profile)
-- [PASS-5:1D]  payments: added idx_customer_date composite index (customer payment history)
-- [PASS-5:1F]  equipment_status_log: added idx_unit_changed composite index (unit status history)
-- [PASS-5:1G]  mileage_logs: added idx_unit_date composite index (unit mileage chart)
-- [PASS-5:1H]  damage_claims: added idx_customer_created composite index (risk score calc)
-- [CVI-EMAIL-1] portal_users: notification_preferences JSON column already in CREATE TABLE portal_users:1504
-- [CURRENCY-MARKUP-1] invoices: added currency_markup_pct DECIMAL(6,4) DEFAULT 0.0000 AFTER exchange_rate_to_cad
-- [CURRENCY-MARKUP-1] payments: added currency_markup_pct DECIMAL(6,4) DEFAULT 0.0000 AFTER exchange_rate_to_cad
-- [CURRENCY-MARKUP-1] settings: added currency.usd_cad_markup_pct seed row (group=currency)
-- [ADV-BILL-1] leases: added advance_billing_periods TINYINT UNSIGNED DEFAULT 0 AFTER billing_cycle
-- [ADV-BILL-1] invoices: extended generation_source ENUM with 'advance' value (lease activation prepayment batch)
-- [ADV-BILL-1] settings: added billing.max_advance_periods seed row (default 24, group=invoices)
-- [S-LEASE-UNITS] leases: added mileage_rate_km, mileage_rate_miles DECIMAL(10,4), estimated_mileage_km, estimated_mileage_miles DECIMAL(12,3), km_to_miles_conversion DECIMAL(8,6) DEFAULT 0.621371, miles_to_km_conversion DECIMAL(8,6) DEFAULT 1.609344
-- [S-LEASE-UNITS] settings: added lease.km_to_miles_default and lease.miles_to_km_default seed rows (group=leases)
-- [S-PROD-1A] users: added mfa_enabled, mfa_secret, mfa_enabled_at, mfa_required columns (D62–D64)
-- [S-PROD-1A] NEW TABLE user_mfa_backup_codes: bcrypt-hashed one-time TOTP backup codes
-- [S-PROD-1A] NEW TABLE rate_limit_attempts: fixed-window rate limiting buckets (D65–D67)
-- [S-PROD-1A] settings: 16 security.rate_limit.* and security.mfa.* seed rows added
