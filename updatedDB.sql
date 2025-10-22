-- FleetForge Enhanced Database Schema
-- PostgreSQL 14+ with comprehensive data collection and audit capabilities

-- ============================================================================
-- CORE SYSTEM TABLES
-- ============================================================================

-- Enhanced user management (integrates with Auth0)
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    auth0_user_id VARCHAR(100) UNIQUE NOT NULL, -- Auth0 user identifier
    email VARCHAR(255) UNIQUE NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role VARCHAR(50) NOT NULL DEFAULT 'user', -- admin, manager, yard_staff, accounting, read_only
    permissions JSONB DEFAULT '[]', -- Array of specific permissions
    last_login TIMESTAMP,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Multi-yard operations
CREATE TABLE yards (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    address VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(50),
    postal_code VARCHAR(20),
    country VARCHAR(100) DEFAULT 'Canada',
    phone VARCHAR(50),
    manager_name VARCHAR(100),
    manager_email VARCHAR(255),
    capacity_limit INTEGER, -- Maximum equipment units
    current_count INTEGER DEFAULT 0, -- Current equipment count
    geofence_coordinates JSONB, -- GPS boundaries for automated tracking
    timezone VARCHAR(50) DEFAULT 'America/Edmonton',
    is_active BOOLEAN DEFAULT true,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- EQUIPMENT MANAGEMENT
-- ============================================================================

-- Enhanced equipment templates with comprehensive specifications
CREATE TABLE equipment_templates (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    category VARCHAR(100) NOT NULL, -- 'Chassis', 'Dry Van', 'Reefer', 'Flatbed', 'Construction'
    subcategory VARCHAR(100), -- '40ft', '53ft', 'High Cube', etc.
    brand VARCHAR(100),
    model VARCHAR(100),
    
    -- Physical Specifications
    default_length_ft DECIMAL(5,2),
    default_width_ft DECIMAL(5,2),
    default_height_ft DECIMAL(5,2),
    default_weight_lbs INTEGER,
    default_payload_capacity_lbs INTEGER,
    
    -- Technical Specifications
    default_axle_count INTEGER,
    default_wheel_size VARCHAR(50),
    default_tire_size VARCHAR(50),
    has_engine BOOLEAN DEFAULT false, -- For powered equipment
    fuel_type VARCHAR(50), -- 'Diesel', 'Gas', 'Electric', 'Hybrid'
    
    -- Service Intervals (days)
    default_mvi_interval_days INTEGER DEFAULT 365, -- Motor Vehicle Inspection
    default_cvi_interval_days INTEGER DEFAULT 365, -- Commercial Vehicle Inspection
    default_registration_interval_days INTEGER DEFAULT 365,
    default_insurance_interval_days INTEGER DEFAULT 365,
    default_maintenance_interval_days INTEGER DEFAULT 90,
    default_maintenance_interval_miles INTEGER, -- For mileage-based maintenance
    default_maintenance_interval_hours INTEGER, -- For engine hour-based maintenance
    
    -- Defaults for new units
    default_ownership_type VARCHAR(50) DEFAULT 'owned', -- 'owned', 'leased', 'brokered'
    default_yard_id INTEGER REFERENCES yards(id),
    default_notes TEXT,
    
    -- Financial defaults
    estimated_purchase_price DECIMAL(12,2),
    estimated_monthly_depreciation DECIMAL(10,2),
    estimated_maintenance_cost_per_month DECIMAL(10,2),
    
    -- Metadata
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Physical equipment units with comprehensive tracking
CREATE TABLE equipment_units (
    id SERIAL PRIMARY KEY,
    template_id INTEGER NOT NULL REFERENCES equipment_templates(id),
    unit_number VARCHAR(100) UNIQUE NOT NULL,
    
    -- Identification
    vin VARCHAR(100) UNIQUE,
    serial_number VARCHAR(100),
    license_plate VARCHAR(50),
    license_state VARCHAR(50),
    
    -- GPS and Tracking
    gps_device_id VARCHAR(100), -- Samsara device ID
    tracking_provider VARCHAR(100) DEFAULT 'Samsara',
    last_known_location POINT, -- PostGIS point for GPS coordinates
    last_location_update TIMESTAMP,
    
    -- Ownership and Location
    ownership_type VARCHAR(50) NOT NULL DEFAULT 'owned',
    owner_company_id INTEGER REFERENCES customers(id), -- If leased from another company
    current_yard_id INTEGER REFERENCES yards(id),
    
    -- Status Management
    status VARCHAR(50) NOT NULL DEFAULT 'available', -- 'available', 'reserved', 'on_lease', 'maintenance', 'inactive'
    status_reason TEXT, -- Reason for current status
    status_changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_changed_by VARCHAR(100),
    
    -- Usage Tracking
    current_mileage INTEGER DEFAULT 0,
    engine_hours INTEGER DEFAULT 0, -- For powered equipment
    last_mileage_update TIMESTAMP,
    last_engine_hours_update TIMESTAMP,
    
    -- Physical Specifications (can override template defaults)
    length_ft DECIMAL(5,2),
    width_ft DECIMAL(5,2),
    height_ft DECIMAL(5,2),
    weight_lbs INTEGER,
    payload_capacity_lbs INTEGER,
    axle_count INTEGER,
    wheel_size VARCHAR(50),
    tire_size VARCHAR(50),
    
    -- Compliance and Documentation
    insurance_expiry DATE,
    cvi_expiry DATE,
    mvi_expiry DATE,
    registration_expiry DATE,
    cvi_document_url VARCHAR(500),
    registration_document_url VARCHAR(500),
    insurance_document_url VARCHAR(500),
    
    -- Condition and Maintenance
    condition_rating INTEGER CHECK (condition_rating >= 1 AND condition_rating <= 10), -- 1-10 scale
    last_inspection_date DATE,
    next_maintenance_due DATE,
    maintenance_notes TEXT,
    
    -- Business Data
    tags JSONB DEFAULT '[]', -- Flexible tagging system
    lease_count INTEGER DEFAULT 0, -- Total number of leases
    total_revenue DECIMAL(12,2) DEFAULT 0, -- Lifetime revenue
    notes TEXT,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    CONSTRAINT chk_condition_rating CHECK (condition_rating IS NULL OR (condition_rating >= 1 AND condition_rating <= 10))
);

-- Equipment financial tracking for ROI analysis
CREATE TABLE equipment_financials (
    id SERIAL PRIMARY KEY,
    equipment_unit_id INTEGER UNIQUE NOT NULL REFERENCES equipment_units(id),
    
    -- Purchase Information
    purchase_price DECIMAL(12,2) NOT NULL,
    purchase_date DATE NOT NULL,
    vendor_name VARCHAR(255),
    vendor_contact VARCHAR(255),
    
    -- Financing Details
    down_payment DECIMAL(12,2) DEFAULT 0,
    loan_amount DECIMAL(12,2) DEFAULT 0,
    interest_rate DECIMAL(5,4) DEFAULT 0, -- 5.25% = 0.0525
    loan_term_months INTEGER DEFAULT 0,
    monthly_payment DECIMAL(10,2) DEFAULT 0,
    loan_start_date DATE,
    loan_end_date DATE,
    remaining_balance DECIMAL(12,2) DEFAULT 0,
    
    -- Additional Costs
    delivery_cost DECIMAL(10,2) DEFAULT 0,
    modification_cost DECIMAL(10,2) DEFAULT 0,
    initial_inspection_cost DECIMAL(10,2) DEFAULT 0,
    licensing_cost DECIMAL(10,2) DEFAULT 0,
    other_initial_costs DECIMAL(10,2) DEFAULT 0,
    initial_cost_description TEXT,
    
    -- Depreciation Settings
    depreciation_method VARCHAR(50) DEFAULT 'straight-line', -- 'straight-line', 'declining-balance'
    depreciation_rate DECIMAL(5,4) DEFAULT 0.15, -- 15% per year
    depreciation_years INTEGER DEFAULT 7,
    salvage_value DECIMAL(12,2) DEFAULT 0,
    
    -- Calculated Financial Metrics (updated by triggers/jobs)
    total_initial_cost DECIMAL(12,2) GENERATED ALWAYS AS (
        purchase_price + delivery_cost + modification_cost + 
        initial_inspection_cost + licensing_cost + other_initial_costs
    ) STORED,
    
    current_book_value DECIMAL(12,2) DEFAULT 0,
    total_revenue DECIMAL(12,2) DEFAULT 0,
    total_operating_expenses DECIMAL(12,2) DEFAULT 0,
    total_maintenance_costs DECIMAL(12,2) DEFAULT 0,
    
    -- ROI Calculations
    net_profit DECIMAL(12,2) GENERATED ALWAYS AS (
        total_revenue - total_operating_expenses - total_maintenance_costs
    ) STORED,
    roi_percentage DECIMAL(8,4) DEFAULT 0,
    payback_period_months INTEGER DEFAULT 0,
    monthly_cash_flow DECIMAL(10,2) DEFAULT 0,
    
    -- Insurance
    insurance_provider VARCHAR(255),
    insurance_policy_number VARCHAR(100),
    insurance_annual_premium DECIMAL(10,2) DEFAULT 0,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Equipment status change log for complete audit trail
CREATE TABLE equipment_status_log (
    id SERIAL PRIMARY KEY,
    equipment_unit_id INTEGER NOT NULL REFERENCES equipment_units(id),
    old_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    reason TEXT,
    changed_by VARCHAR(100) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Additional context
    related_lease_id INTEGER REFERENCES leases(id),
    related_reservation_id INTEGER REFERENCES reservations(id),
    related_maintenance_id INTEGER REFERENCES maintenance_events(id),
    
    -- System context
    ip_address INET,
    user_agent TEXT,
    request_id VARCHAR(100)
);

-- ============================================================================
-- CUSTOMER MANAGEMENT
-- ============================================================================

-- Enhanced customer profiles with comprehensive business data
CREATE TABLE customers (
    id SERIAL PRIMARY KEY,
    
    -- Company Information
    company_name VARCHAR(255) NOT NULL,
    dba_name VARCHAR(255), -- Doing Business As name
    company_type VARCHAR(100), -- 'Corporation', 'LLC', 'Partnership', 'Sole Proprietorship'
    industry VARCHAR(100),
    website VARCHAR(255),
    
    -- Tax and Legal Identifiers
    tax_id VARCHAR(100),
    ein VARCHAR(20), -- Employer Identification Number
    dot_number VARCHAR(100), -- Department of Transportation number
    mc_number VARCHAR(100), -- Motor Carrier number
    duns_number VARCHAR(20), -- Dun & Bradstreet number
    
    -- Primary Contact
    contact_name VARCHAR(255),
    contact_title VARCHAR(100),
    email VARCHAR(255),
    phone VARCHAR(50),
    alt_phone VARCHAR(50),
    mobile_phone VARCHAR(50),
    
    -- Billing Contact (can be different from primary)
    billing_contact_name VARCHAR(255),
    billing_contact_title VARCHAR(100),
    billing_email VARCHAR(255),
    billing_phone VARCHAR(50),
    
    -- Addresses
    physical_address VARCHAR(255),
    physical_city VARCHAR(100),
    physical_state VARCHAR(100),
    physical_postal_code VARCHAR(20),
    physical_country VARCHAR(100) DEFAULT 'Canada',
    
    billing_address VARCHAR(255),
    billing_city VARCHAR(100),
    billing_state VARCHAR(100),
    billing_postal_code VARCHAR(20),
    billing_country VARCHAR(100) DEFAULT 'Canada',
    
    -- Financial Information
    credit_limit DECIMAL(12,2) DEFAULT 0,
    credit_rating VARCHAR(50), -- 'Excellent', 'Good', 'Fair', 'Poor'
    payment_terms VARCHAR(100) DEFAULT 'Net 30', -- 'Net 15', 'Net 30', 'COD', etc.
    payment_method_preference VARCHAR(50) DEFAULT 'Invoice', -- 'Invoice', 'Auto-Pay', 'Credit Card'
    discount_percentage DECIMAL(5,4) DEFAULT 0, -- Default discount for this customer
    
    -- Business Relationship
    customer_since DATE,
    customer_source VARCHAR(100), -- 'Referral', 'Website', 'Trade Show', 'Cold Call', etc.
    account_manager_id INTEGER REFERENCES users(id),
    relationship_status VARCHAR(50) DEFAULT 'active', -- 'active', 'inactive', 'pending', 'suspended'
    
    -- Operational Preferences
    preferred_yard_id INTEGER REFERENCES yards(id),
    preferred_pickup_time TIME DEFAULT '08:00:00',
    preferred_contact_method VARCHAR(50) DEFAULT 'Email', -- 'Email', 'Phone', 'SMS'
    special_instructions TEXT,
    
    -- Compliance and Documentation
    insurance_provider VARCHAR(255),
    insurance_policy_number VARCHAR(100),
    insurance_expiry DATE,
    safety_rating VARCHAR(50),
    
    -- Business Metrics (calculated fields updated by triggers)
    total_leases INTEGER DEFAULT 0,
    active_leases INTEGER DEFAULT 0,
    total_revenue DECIMAL(12,2) DEFAULT 0,
    ytd_revenue DECIMAL(12,2) DEFAULT 0,
    average_lease_duration_days INTEGER DEFAULT 0,
    last_lease_date DATE,
    last_payment_date DATE,
    current_balance DECIMAL(12,2) DEFAULT 0,
    
    -- Risk Assessment
    risk_score INTEGER CHECK (risk_score >= 1 AND risk_score <= 100), -- 1-100 risk score
    risk_factors JSONB DEFAULT '[]', -- Array of risk factors
    credit_hold BOOLEAN DEFAULT false,
    require_deposit BOOLEAN DEFAULT false,
    deposit_amount DECIMAL(10,2) DEFAULT 0,
    
    -- Communication Preferences
    email_invoices BOOLEAN DEFAULT true,
    email_reminders BOOLEAN DEFAULT true,
    email_marketing BOOLEAN DEFAULT false,
    sms_notifications BOOLEAN DEFAULT false,
    
    -- Additional Data
    notes TEXT,
    tags JSONB DEFAULT '[]', -- Flexible tagging: ['VIP', 'High Volume', 'Seasonal', etc.]
    custom_fields JSONB DEFAULT '{}', -- For additional custom data
    
    -- Soft Delete
    is_active BOOLEAN DEFAULT true,
    deleted_at TIMESTAMP,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Customer rate structures with comprehensive pricing
CREATE TABLE customer_rate_structures (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    equipment_template_id INTEGER NOT NULL REFERENCES equipment_templates(id),
    
    -- Base Rental Rates
    daily_rate DECIMAL(10,2),
    weekly_rate DECIMAL(10,2),
    monthly_rate DECIMAL(10,2),
    yearly_rate DECIMAL(10,2),
    
    -- Mileage Rates
    mileage_rate_per_mile DECIMAL(6,4), -- $0.40 per mile
    mileage_rate_per_km DECIMAL(6,4), -- For metric customers
    included_miles_daily INTEGER DEFAULT 0,
    included_miles_weekly INTEGER DEFAULT 0,
    included_miles_monthly INTEGER DEFAULT 0,
    
    -- Add-on Service Rates
    gps_tracking_fee DECIMAL(8,2) DEFAULT 0,
    insurance_fee DECIMAL(8,2) DEFAULT 0,
    damage_waiver_fee DECIMAL(8,2) DEFAULT 0,
    delivery_fee_per_mile DECIMAL(6,4) DEFAULT 0,
    pickup_fee DECIMAL(8,2) DEFAULT 0,
    after_hours_fee DECIMAL(8,2) DEFAULT 0,
    
    -- Billing Terms
    payment_terms_days INTEGER DEFAULT 30,
    late_fee_percentage DECIMAL(5,4) DEFAULT 0.015, -- 1.5% monthly
    early_payment_discount_percentage DECIMAL(5,4) DEFAULT 0,
    early_payment_discount_days INTEGER DEFAULT 10,
    
    -- Volume Discounts
    volume_discount_threshold INTEGER DEFAULT 0, -- Number of units for discount
    volume_discount_percentage DECIMAL(5,4) DEFAULT 0,
    long_term_discount_threshold_days INTEGER DEFAULT 0, -- Days for long-term discount
    long_term_discount_percentage DECIMAL(5,4) DEFAULT 0,
    
    -- Seasonal Pricing
    seasonal_rate_multiplier DECIMAL(5,4) DEFAULT 1.0, -- 1.2 for 20% increase
    seasonal_start_date DATE,
    seasonal_end_date DATE,
    
    -- Rate Validity
    effective_date DATE NOT NULL DEFAULT CURRENT_DATE,
    expiry_date DATE,
    is_active BOOLEAN DEFAULT true,
    
    -- Rate Change Tracking
    previous_rate_id INTEGER REFERENCES customer_rate_structures(id),
    rate_change_reason TEXT,
    approved_by VARCHAR(100),
    
    -- Additional Terms
    minimum_rental_period_days INTEGER DEFAULT 1,
    maximum_rental_period_days INTEGER,
    requires_deposit BOOLEAN DEFAULT false,
    deposit_amount DECIMAL(10,2) DEFAULT 0,
    
    -- Notes and Custom Terms
    notes TEXT,
    custom_terms JSONB DEFAULT '{}',
    
    -- Metadata
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(customer_id, equipment_template_id, effective_date)
);

-- Customer rate change history
CREATE TABLE customer_rate_history (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    equipment_template_id INTEGER NOT NULL REFERENCES equipment_templates(id),
    rate_structure_id INTEGER REFERENCES customer_rate_structures(id),
    lease_id INTEGER REFERENCES leases(id), -- If rate was used in a specific lease
    
    -- Historical Rate Data (snapshot)
    daily_rate DECIMAL(10,2),
    weekly_rate DECIMAL(10,2),
    monthly_rate DECIMAL(10,2),
    mileage_rate DECIMAL(6,4),
    
    -- Change Information
    change_type VARCHAR(50) NOT NULL, -- 'created', 'updated', 'deleted', 'used_in_lease'
    change_source VARCHAR(50) DEFAULT 'manual', -- 'manual', 'system', 'auto_renewal'
    change_reason TEXT,
    old_values JSONB,
    new_values JSONB,
    
    -- Context
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Customer documents with enhanced categorization
CREATE TABLE customer_documents (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    
    -- Document Information
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL, -- 'Contract', 'Insurance', 'Credit Application', 'ID', 'Tax Document'
    subcategory VARCHAR(100), -- 'Certificate of Insurance', 'W9', 'Driver License', etc.
    file_url VARCHAR(500) NOT NULL,
    file_size_bytes INTEGER,
    mime_type VARCHAR(100),
    original_filename VARCHAR(255),
    
    -- Document Status
    status VARCHAR(50) DEFAULT 'active', -- 'active', 'expired', 'superseded', 'deleted'
    is_required BOOLEAN DEFAULT false,
    
    -- Expiration and Reminders
    expiration_date DATE,
    reminder_days_before INTEGER DEFAULT 30,
    last_reminder_sent TIMESTAMP,
    
    -- Version Control
    version INTEGER DEFAULT 1,
    previous_version_id INTEGER REFERENCES customer_documents(id),
    
    -- Access Control
    is_confidential BOOLEAN DEFAULT false,
    access_roles JSONB DEFAULT '["admin", "manager"]', -- Roles that can access this document
    
    -- Metadata
    uploaded_by VARCHAR(100),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Customer tags for flexible categorization
CREATE TABLE customer_tags (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    tag VARCHAR(50) NOT NULL,
    tag_type VARCHAR(50) DEFAULT 'general', -- 'general', 'credit', 'operational', 'marketing'
    added_by VARCHAR(100),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(customer_id, tag)
);

-- ============================================================================
-- RESERVATION SYSTEM
-- ============================================================================

-- Enhanced reservations with comprehensive tracking
CREATE TABLE reservations (
    id SERIAL PRIMARY KEY,
    
    -- Status Management
    status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'confirmed', 'cancelled', 'completed'
    priority VARCHAR(50) DEFAULT 'medium', -- 'low', 'medium', 'high', 'urgent'
    
    -- Customer Information
    customer_id INTEGER REFERENCES customers(id),
    contact_name VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    contact_phone VARCHAR(50),
    contact_email VARCHAR(255),
    
    -- Equipment Requirements
    equipment_template_id INTEGER REFERENCES equipment_templates(id),
    quantity INTEGER NOT NULL DEFAULT 1,
    specific_requirements TEXT, -- Special requirements or preferences
    
    -- Scheduling
    pickup_date DATE NOT NULL,
    pickup_time TIME,
    estimated_return_date DATE,
    pickup_yard_id INTEGER REFERENCES yards(id),
    delivery_required BOOLEAN DEFAULT false,
    delivery_address TEXT,
    delivery_contact VARCHAR(255),
    delivery_phone VARCHAR(50),
    
    -- Purpose and Context
    purpose VARCHAR(255), -- 'Seasonal Job', 'Emergency Replacement', 'New Contract'
    project_name VARCHAR(255),
    po_number VARCHAR(100), -- Purchase Order number
    job_site_address TEXT,
    
    -- Financial Information
    estimated_daily_rate DECIMAL(10,2),
    estimated_duration_days INTEGER,
    estimated_total_cost DECIMAL(12,2),
    deposit_required BOOLEAN DEFAULT false,
    deposit_amount DECIMAL(10,2) DEFAULT 0,
    deposit_paid BOOLEAN DEFAULT false,
    
    -- Internal Information
    notes TEXT, -- Customer-visible notes
    internal_notes TEXT, -- Internal staff notes only
    special_instructions TEXT,
    staff_assigned VARCHAR(100), -- Staff member handling this reservation
    
    -- Source and Marketing
    reservation_source VARCHAR(100), -- 'Phone', 'Email', 'Website', 'Walk-in', 'Referral'
    marketing_campaign VARCHAR(100),
    
    -- Completion Tracking
    equipment_out_date DATE,
    equipment_out_by VARCHAR(100),
    equipment_out_notes TEXT,
    completion_notes TEXT,
    
    -- Related Records
    converted_to_lease_id INTEGER REFERENCES leases(id),
    cancellation_reason TEXT,
    cancelled_by VARCHAR(100),
    cancelled_at TIMESTAMP,
    
    -- Metadata
    created_by VARCHAR(100),
    updated_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Reservation units (many-to-many relationship)
CREATE TABLE reservation_units (
    id SERIAL PRIMARY KEY,
    reservation_id INTEGER NOT NULL REFERENCES reservations(id) ON DELETE CASCADE,
    equipment_unit_id INTEGER REFERENCES equipment_units(id) ON DELETE SET NULL,
    
    -- Snapshot data (preserved even if unit is deleted)
    unit_number_snapshot VARCHAR(100),
    template_name_snapshot VARCHAR(100),
    status_at_reservation VARCHAR(50),
    
    -- Assignment Information
    assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by VARCHAR(100),
    assignment_notes TEXT,
    
    -- Condition at Assignment
    condition_rating INTEGER CHECK (condition_rating >= 1 AND condition_rating <= 10),
    condition_notes TEXT,
    mileage_at_assignment INTEGER,
    
    -- Linked Records
    lease_id INTEGER REFERENCES leases(id), -- If this unit was converted to a lease
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- LEASE MANAGEMENT
-- ============================================================================

-- Comprehensive lease management with enhanced business logic
CREATE TABLE leases (
    id SERIAL PRIMARY KEY,
    
    -- Contract Information
    contract_number VARCHAR(100) UNIQUE NOT NULL,
    lease_type VARCHAR(50) DEFAULT 'standard', -- 'standard', 'long_term', 'trial', 'emergency'
    
    -- Parties
    customer_id INTEGER REFERENCES customers(id),
    equipment_unit_id INTEGER REFERENCES equipment_units(id),
    
    -- Dates and Duration
    start_date DATE NOT NULL,
    end_date DATE, -- NULL for open-ended leases
    actual_start_date DATE, -- When equipment actually went out
    actual_end_date DATE, -- When equipment actually returned
    
    -- Status Management
    status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'active', 'completed', 'cancelled', 'terminated'
    status_reason TEXT,
    
    -- Rate Structure
    daily_rate DECIMAL(10,2) DEFAULT 0,
    weekly_rate DECIMAL(10,2) DEFAULT 0,
    monthly_rate DECIMAL(10,2) DEFAULT 0,
    rate_period VARCHAR(20) DEFAULT 'daily', -- Which rate is primary: 'daily', 'weekly', 'monthly'
    rate_notes TEXT,
    
    -- Mileage and Usage
    estimated_mileage INTEGER DEFAULT 0,
    mileage_rate DECIMAL(6,4) DEFAULT 0, -- Per mile rate
    included_miles_per_period INTEGER DEFAULT 0,
    starting_mileage INTEGER DEFAULT 0,
    ending_mileage INTEGER,
    actual_mileage INTEGER GENERATED ALWAYS AS (
        CASE WHEN ending_mileage IS NOT NULL AND starting_mileage IS NOT NULL 
             THEN ending_mileage - starting_mileage 
             ELSE NULL END
    ) STORED,
    
    -- Engine Hours (for powered equipment)
    starting_engine_hours INTEGER DEFAULT 0,
    ending_engine_hours INTEGER,
    actual_engine_hours INTEGER GENERATED ALWAYS AS (
        CASE WHEN ending_engine_hours IS NOT NULL AND starting_engine_hours IS NOT NULL 
             THEN ending_engine_hours - starting_engine_hours 
             ELSE NULL END
    ) STORED,
    
    -- Add-on Services
    gps_tracking_enabled BOOLEAN DEFAULT true,
    gps_tracking_fee DECIMAL(8,2) DEFAULT 0,
    insurance_opt_in BOOLEAN DEFAULT false,
    insurance_cost DECIMAL(8,2) DEFAULT 0,
    damage_waiver_opt_in BOOLEAN DEFAULT false,
    damage_waiver_cost DECIMAL(8,2) DEFAULT 0,
    delivery_service BOOLEAN DEFAULT false,
    delivery_cost DECIMAL(8,2) DEFAULT 0,
    
    -- Financial Calculations
    base_rental_charge DECIMAL(12,2) DEFAULT 0,
    mileage_charge DECIMAL(12,2) DEFAULT 0,
    add_on_charges DECIMAL(12,2) DEFAULT 0,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    total_estimated_charge DECIMAL(12,2) DEFAULT 0,
    final_total_charge DECIMAL(12,2) DEFAULT 0,
    
    -- Adjustments
    early_return_credit DECIMAL(10,2) DEFAULT 0,
    late_return_penalty DECIMAL(10,2) DEFAULT 0,
    damage_charges DECIMAL(10,2) DEFAULT 0,
    cleaning_charges DECIMAL(10,2) DEFAULT 0,
    manual_adjustments DECIMAL(10,2) DEFAULT 0,
    adjustment_reason TEXT,
    
    -- Payment Terms
    payment_terms_days INTEGER DEFAULT 30,
    deposit_required BOOLEAN DEFAULT false,
    deposit_amount DECIMAL(10,2) DEFAULT 0,
    deposit_paid BOOLEAN DEFAULT false,
    deposit_payment_date DATE,
    
    -- Geographic and Operational
    primary_use_location TEXT,
    operating_radius_miles INTEGER,
    special_operating_conditions TEXT,
    
    -- Documents
    contract_document_url VARCHAR(500),
    inspection_in_document_url VARCHAR(500),
    inspection_out_document_url VARCHAR(500),
    additional_documents JSONB DEFAULT '[]',
    
    -- Performance Metrics
    utilization_score DECIMAL(5,2), -- Calculated utilization percentage
    profitability_score DECIMAL(12,2), -- Revenue minus costs
    customer_satisfaction_rating INTEGER CHECK (customer_satisfaction_rating >= 1 AND customer_satisfaction_rating <= 5),
    
    -- Snapshots (preserved historical data)
    customer_name_snapshot VARCHAR(255),
    company_name_snapshot VARCHAR(255),
    customer_address_snapshot TEXT,
    unit_number_snapshot VARCHAR(100),
    template_name_snapshot VARCHAR(100),
    
    -- Renewal and Extension
    renewable BOOLEAN DEFAULT true,
    auto_renewal BOOLEAN DEFAULT false,
    renewal_notice_days INTEGER DEFAULT 30,
    extension_requests JSONB DEFAULT '[]', -- Array of extension request records
    
    -- Integration Data
    quickbooks_invoice_id VARCHAR(100),
    samsara_trip_ids JSONB DEFAULT '[]',
    
    -- Notes and Communication
    notes TEXT,
    internal_notes TEXT,
    customer_communication_log JSONB DEFAULT '[]',
    
    -- Metadata
    created_by VARCHAR(100),
    updated_by VARCHAR(100),
    approved_by VARCHAR(100),
    approval_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- BILLING AND FINANCIAL MANAGEMENT
-- ============================================================================

-- Detailed billing periods for accurate pro-rating
CREATE TABLE lease_billing_periods (
    id SERIAL PRIMARY KEY,
    lease_id INTEGER NOT NULL REFERENCES leases(id),
    
    -- Period Definition
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    period_type VARCHAR(20) NOT NULL, -- 'daily', 'weekly', 'monthly', 'custom'
    billing_days INTEGER NOT NULL, -- Actual billable days in period
    
    -- Base Rental Calculations
    daily_rate DECIMAL(10,2) NOT NULL,
    weekly_rate DECIMAL(10,2),
    monthly_rate DECIMAL(10,2),
    pro_rating_method VARCHAR(50) NOT NULL, -- 'daily', 'calendar_month', 'billing_month'
    base_charge DECIMAL(12,2) NOT NULL,
    
    -- Mileage Calculations
    period_start_mileage INTEGER DEFAULT 0,
    period_end_mileage INTEGER DEFAULT 0,
    total_miles INTEGER GENERATED ALWAYS AS (
        period_end_mileage - period_start_mileage
    ) STORED,
    included_miles INTEGER DEFAULT 0,
    billable_miles INTEGER GENERATED ALWAYS AS (
        GREATEST(total_miles - included_miles, 0)
    ) STORED,
    mileage_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
    mileage_charge DECIMAL(12,2) GENERATED ALWAYS AS (
        billable_miles * mileage_rate
    ) STORED,
    
    -- Add-on Charges
    gps_fee DECIMAL(8,2) DEFAULT 0,
    insurance_fee DECIMAL(8,2) DEFAULT 0,
    damage_waiver_fee DECIMAL(8,2) DEFAULT 0,
    fuel_surcharge DECIMAL(8,2) DEFAULT 0,
    environmental_fee DECIMAL(8,2) DEFAULT 0,
    
    -- Adjustments and Credits
    early_return_credit DECIMAL(10,2) DEFAULT 0,
    late_penalty DECIMAL(10,2) DEFAULT 0,
    damage_charge DECIMAL(10,2) DEFAULT 0,
    cleaning_charge DECIMAL(10,2) DEFAULT 0,
    manual_adjustment DECIMAL(10,2) DEFAULT 0,
    adjustment_description TEXT,
    
    -- Tax Calculations
    subtotal DECIMAL(12,2) GENERATED ALWAYS AS (
        base_charge + mileage_charge + gps_fee + insurance_fee + 
        damage_waiver_fee + fuel_surcharge + environmental_fee + 
        late_penalty + damage_charge + cleaning_charge + manual_adjustment - early_return_credit
    ) STORED,
    
    tax_jurisdiction VARCHAR(100),
    tax_rate DECIMAL(5,4) DEFAULT 0,
    tax_amount DECIMAL(12,2) GENERATED ALWAYS AS (subtotal * tax_rate) STORED,
    total_amount DECIMAL(12,2) GENERATED ALWAYS AS (subtotal + tax_amount) STORED,
    
    -- Status and Processing
    status VARCHAR(50) DEFAULT 'draft', -- 'draft', 'calculated', 'invoiced', 'paid', 'disputed'
    calculation_date TIMESTAMP,
    invoice_id INTEGER REFERENCES invoices(id),
    
    -- Metadata
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Enhanced invoice management
CREATE TABLE invoices (
    id SERIAL PRIMARY KEY,
    
    -- Invoice Identification
    invoice_number VARCHAR(100) UNIQUE NOT NULL,
    invoice_type VARCHAR(50) DEFAULT 'standard', -- 'standard', 'credit_note', 'debit_note', 'pro_forma'
    
    -- Related Records
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    lease_id INTEGER REFERENCES leases(id), -- NULL for multi-lease or non-lease invoices
    billing_period_ids JSONB DEFAULT '[]', -- Array of billing period IDs
    
    -- Dates
    invoice_date DATE NOT NULL DEFAULT CURRENT_DATE,
    due_date DATE NOT NULL,
    service_period_start DATE,
    service_period_end DATE,
    
    -- Financial Summary
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) DEFAULT 0,
    balance_due DECIMAL(12,2) GENERATED ALWAYS AS (total_amount - amount_paid) STORED,
    
    -- Status Management
    status VARCHAR(50) DEFAULT 'draft', -- 'draft', 'sent', 'paid', 'partial_paid', 'overdue', 'void', 'disputed'
    payment_status VARCHAR(50) DEFAULT 'unpaid', -- 'unpaid', 'partial', 'paid', 'overpaid'
    
    -- Document Management
    pdf_document_url VARCHAR(500),
    pdf_generated_at TIMESTAMP,
    
    -- Delivery Tracking
    sent_date DATE,
    sent_method VARCHAR(50), -- 'email', 'mail', 'portal', 'hand_delivery'
    sent_to_email VARCHAR(255),
    delivery_confirmation BOOLEAN DEFAULT false,
    
    -- Payment Terms and Penalties
    payment_terms_days INTEGER DEFAULT 30,
    late_fee_percentage DECIMAL(5,4) DEFAULT 0,
    early_payment_discount_percentage DECIMAL(5,4) DEFAULT 0,
    early_payment_discount_days INTEGER DEFAULT 10,
    
    -- External System Integration
    quickbooks_invoice_id VARCHAR(100),
    quickbooks_sync_status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'synced', 'error'
    quickbooks_sync_date TIMESTAMP,
    quickbooks_error_message TEXT,
    
    -- Customer Communication
    reminder_count INTEGER DEFAULT 0,
    last_reminder_date DATE,
    dispute_reason TEXT,
    dispute_date DATE,
    dispute_resolved_date DATE,
    
    -- Snapshot Data
    customer_name_snapshot VARCHAR(255),
    customer_address_snapshot TEXT,
    customer_contact_snapshot VARCHAR(255),
    
    -- Additional Information
    po_number VARCHAR(100), -- Customer's Purchase Order number
    reference_number VARCHAR(100),
    notes TEXT,
    internal_notes TEXT,
    
    -- Metadata
    created_by VARCHAR(100),
    approved_by VARCHAR(100),
    approval_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Invoice line items for detailed billing breakdown
CREATE TABLE invoice_line_items (
    id SERIAL PRIMARY KEY,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    
    -- Line Item Details
    line_number INTEGER NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(100) NOT NULL, -- 'rental', 'mileage', 'add_on', 'adjustment', 'tax'
    subcategory VARCHAR(100), -- 'base_rent', 'gps_fee', 'late_penalty', etc.
    
    -- Quantity and Rates
    quantity DECIMAL(10,4) DEFAULT 1,
    unit_of_measure VARCHAR(50) DEFAULT 'each', -- 'days', 'miles', 'hours', 'each'
    unit_price DECIMAL(10,4) NOT NULL,
    
    -- Calculations
    line_total DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    
    -- Tax Information
    taxable BOOLEAN DEFAULT true,
    tax_rate DECIMAL(5,4) DEFAULT 0,
    tax_amount DECIMAL(12,2) GENERATED ALWAYS AS (
        CASE WHEN taxable THEN line_total * tax_rate ELSE 0 END
    ) STORED,
    
    -- Reference Data
    lease_id INTEGER REFERENCES leases(id),
    billing_period_id INTEGER REFERENCES lease_billing_periods(id),
    equipment_unit_id INTEGER REFERENCES equipment_units(id),
    
    -- Date Information
    service_date DATE,
    service_period_start DATE,
    service_period_end DATE,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Payment tracking and allocation
CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    
    -- Payment Information
    payment_number VARCHAR(100) UNIQUE,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL DEFAULT CURRENT_DATE,
    
    -- Payment Method
    payment_method VARCHAR(50) NOT NULL, -- 'check', 'ach', 'wire', 'credit_card', 'cash', 'online'
    reference_number VARCHAR(100), -- Check number, transaction ID, etc.
    
    -- Bank Information
    bank_name VARCHAR(255),
    bank_account_last_four VARCHAR(4),
    
    -- Processing Information
    processed_by VARCHAR(100),
    processing_fee DECIMAL(8,2) DEFAULT 0,
    net_amount DECIMAL(12,2) GENERATED ALWAYS AS (amount - processing_fee) STORED,
    
    -- Status
    status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'cleared', 'bounced', 'disputed'
    cleared_date DATE,
    
    -- External Integration
    quickbooks_payment_id VARCHAR(100),
    quickbooks_sync_status VARCHAR(50) DEFAULT 'pending',
    quickbooks_sync_date TIMESTAMP,
    
    -- Additional Information
    notes TEXT,
    deposit_slip_url VARCHAR(500),
    
    -- Metadata
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Payment allocation to invoices
CREATE TABLE payment_allocations (
    id SERIAL PRIMARY KEY,
    payment_id INTEGER NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id),
    
    -- Allocation Details
    amount_allocated DECIMAL(12,2) NOT NULL,
    allocation_date DATE NOT NULL DEFAULT CURRENT_DATE,
    
    -- Type of allocation
    allocation_type VARCHAR(50) DEFAULT 'payment', -- 'payment', 'credit', 'adjustment', 'writeoff'
    
    -- Metadata
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- MAINTENANCE AND INSPECTIONS
-- ============================================================================

-- Enhanced maintenance event tracking
CREATE TABLE maintenance_events (
    id SERIAL PRIMARY KEY,
    equipment_unit_id INTEGER NOT NULL REFERENCES equipment_units(id),
    
    -- Maintenance Details
    maintenance_type VARCHAR(100) NOT NULL, -- 'preventive', 'corrective', 'emergency', 'inspection'
    category VARCHAR(100), -- 'engine', 'transmission', 'brakes', 'tires', 'body', 'electrical'
    description TEXT NOT NULL,
    work_performed TEXT,
    
    -- Scheduling
    scheduled_date DATE,
    started_date DATE,
    completed_date DATE,
    next_due_date DATE,
    next_due_mileage INTEGER,
    next_due_engine_hours INTEGER,
    
    -- Vendor and Cost Information
    vendor_type VARCHAR(50) DEFAULT 'external', -- 'internal', 'external', 'warranty'
    vendor_name VARCHAR(255),
    vendor_contact VARCHAR(255),
    vendor_phone VARCHAR(50),
    
    -- Cost Breakdown
    labor_cost DECIMAL(10,2) DEFAULT 0,
    parts_cost DECIMAL(10,2) DEFAULT 0,
    other_costs DECIMAL(10,2) DEFAULT 0,
    total_cost DECIMAL(10,2) GENERATED ALWAYS AS (labor_cost + parts_cost + other_costs) STORED,
    
    -- Equipment Condition
    condition_before INTEGER CHECK (condition_before >= 1 AND condition_before <= 10),
    condition_after INTEGER CHECK (condition_after >= 1 AND condition_after <= 10),
    mileage_at_service INTEGER,
    engine_hours_at_service INTEGER,
    
    -- Status and Priority
    status VARCHAR(50) DEFAULT 'scheduled', -- 'scheduled', 'in_progress', 'completed', 'cancelled', 'deferred'
    priority VARCHAR(50) DEFAULT 'medium', -- 'low', 'medium', 'high', 'critical'
    urgency_reason TEXT,
    
    -- Impact Assessment
    downtime_hours DECIMAL(8,2) DEFAULT 0,
    affects_safety BOOLEAN DEFAULT false,
    affects_operation BOOLEAN DEFAULT false,
    warranty_work BOOLEAN DEFAULT false,
    warranty_claim_number VARCHAR(100),
    
    -- Documentation
    work_order_number VARCHAR(100),
    invoice_number VARCHAR(100),
    invoice_document_url VARCHAR(500),
    photos JSONB DEFAULT '[]', -- Array of photo URLs
    documents JSONB DEFAULT '[]', -- Array of document URLs
    
    -- Parts Used
    parts_used JSONB DEFAULT '[]', -- Array of part objects with details
    
    -- Quality and Follow-up
    work_quality_rating INTEGER CHECK (work_quality_rating >= 1 AND work_quality_rating <= 5),
    follow_up_required BOOLEAN DEFAULT false,
    follow_up_date DATE,
    follow_up_notes TEXT,
    
    -- Integration Data
    samsara_maintenance_event_id VARCHAR(100),
    
    -- Notes
    notes TEXT,
    internal_notes TEXT,
    
    -- Metadata
    created_by VARCHAR(100),
    assigned_to VARCHAR(100),
    completed_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Digital inspection system
CREATE TABLE inspections (
    id SERIAL PRIMARY KEY,
    equipment_unit_id INTEGER NOT NULL REFERENCES equipment_units(id),
    lease_id INTEGER REFERENCES leases(id),
    
    -- Inspection Details
    inspection_type VARCHAR(50) NOT NULL, -- 'pre_lease', 'post_lease', 'periodic', 'damage', 'maintenance'
    inspection_date DATE NOT NULL DEFAULT CURRENT_DATE,
    inspection_time TIME DEFAULT CURRENT_TIME,
    
    -- Location and Context
    yard_location VARCHAR(100),
    inspector_name VARCHAR(100) NOT NULL,
    customer_representative VARCHAR(100),
    customer_signature_url VARCHAR(500),
    inspector_signature_url VARCHAR(500),
    
    -- Overall Condition
    overall_condition VARCHAR(50), -- 'excellent', 'good', 'fair', 'poor', 'damaged'
    overall_rating INTEGER CHECK (overall_rating >= 1 AND overall_rating <= 10),
    
    -- Detailed Inspection Items
    inspection_items JSONB NOT NULL DEFAULT '[]', -- Array of inspection checklist items
    
    -- Damage Documentation
    damages_found BOOLEAN DEFAULT false,
    damage_details JSONB DEFAULT '[]', -- Array of damage descriptions with photos
    
    -- Photos and Documentation
    photos JSONB DEFAULT '[]', -- Array of photo URLs with descriptions
    documents JSONB DEFAULT '[]', -- Array of document URLs
    
    -- Follow-up Actions
    maintenance_required BOOLEAN DEFAULT false,
    maintenance_priority VARCHAR(50), -- 'low', 'medium', 'high', 'critical'
    maintenance_description TEXT,
    repairs_needed JSONB DEFAULT '[]', -- Array of required repairs
    
    -- Financial Impact
    estimated_repair_cost DECIMAL(10,2) DEFAULT 0,
    damage_charges DECIMAL(10,2) DEFAULT 0,
    responsible_party VARCHAR(100), -- 'customer', 'company', 'vendor', 'unknown'
    
    -- Status and Approval
    status VARCHAR(50) DEFAULT 'draft', -- 'draft', 'completed', 'approved', 'disputed'
    approved_by VARCHAR(100),
    approval_date DATE,
    dispute_reason TEXT,
    
    -- Equipment Status Impact
    blocks_future_rental BOOLEAN DEFAULT false,
    status_change_required VARCHAR(50), -- New status if inspection requires change
    
    -- Generated Documents
    pdf_report_url VARCHAR(500),
    pdf_generated_at TIMESTAMP,
    
    -- Notes
    notes TEXT,
    internal_notes TEXT,
    recommendations TEXT,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- GPS AND MILEAGE TRACKING
-- ============================================================================

-- Samsara device management
CREATE TABLE samsara_devices (
    id SERIAL PRIMARY KEY,
    device_id VARCHAR(100) UNIQUE NOT NULL, -- Samsara device ID
    equipment_unit_id INTEGER REFERENCES equipment_units(id),
    
    -- Device Information
    device_name VARCHAR(255),
    device_type VARCHAR(100), -- 'AG24', 'AG26', 'AG46', etc.
    serial_number VARCHAR(100),
    firmware_version VARCHAR(50),
    
    -- Installation Details
    installation_date DATE,
    installed_by VARCHAR(100),
    installation_location VARCHAR(255), -- Where on equipment it's mounted
    
    -- Status
    status VARCHAR(50) DEFAULT 'active', -- 'active', 'inactive', 'maintenance', 'removed'
    last_contact TIMESTAMP,
    battery_voltage DECIMAL(4,2),
    signal_strength INTEGER,
    
    -- Settings
    reporting_interval_minutes INTEGER DEFAULT 5,
    geofence_enabled BOOLEAN DEFAULT true,
    speed_alert_enabled BOOLEAN DEFAULT true,
    speed_limit_mph INTEGER DEFAULT 70,
    
    -- Integration Data
    samsara_group_id VARCHAR(100),
    last_sync_timestamp TIMESTAMP,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Daily mileage tracking for billing
CREATE TABLE mileage_logs (
    id SERIAL PRIMARY KEY,
    equipment_unit_id INTEGER NOT NULL REFERENCES equipment_units(id),
    
    -- Date and Mileage
    log_date DATE NOT NULL,
    start_mileage INTEGER NOT NULL,
    end_mileage INTEGER NOT NULL,
    daily_miles INTEGER GENERATED ALWAYS AS (end_mileage - start_mileage) STORED,
    
    -- Engine Hours (for powered equipment)
    start_engine_hours INTEGER,
    end_engine_hours INTEGER,
    daily_engine_hours DECIMAL(6,2) GENERATED ALWAYS AS (
        CASE WHEN end_engine_hours IS NOT NULL AND start_engine_hours IS NOT NULL 
             THEN end_engine_hours - start_engine_hours 
             ELSE NULL END
    ) STORED,
    
    -- Data Source
    data_source VARCHAR(50) DEFAULT 'samsara', -- 'samsara', 'manual', 'estimated'
    confidence_level VARCHAR(50) DEFAULT 'high', -- 'high', 'medium', 'low'
    
    -- Location Information
    primary_location VARCHAR(255),
    start_location POINT, -- GPS coordinates
    end_location POINT,
    
    -- Business Context
    primary_lease_id INTEGER REFERENCES leases(id), -- Primary lease for the day
    operating_status VARCHAR(50) DEFAULT 'in_service', -- 'in_service', 'maintenance', 'idle'
    
    -- Data Quality
    anomaly_detected BOOLEAN DEFAULT false,
    anomaly_reason TEXT,
    manual_override BOOLEAN DEFAULT false,
    override_reason TEXT,
    
    -- Integration Data
    samsara_trip_ids JSONB DEFAULT '[]',
    last_samsara_sync TIMESTAMP,
    
    -- Metadata
    created_by VARCHAR(100),
    verified_by VARCHAR(100),
    verified_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(equipment_unit_id, log_date)
);

-- Mileage allocation to leases for billing
CREATE TABLE mileage_allocations (
    id SERIAL PRIMARY KEY,
    mileage_log_id INTEGER NOT NULL REFERENCES mileage_logs(id),
    lease_id INTEGER NOT NULL REFERENCES leases(id),
    
    -- Allocation Details
    allocated_miles INTEGER NOT NULL,
    allocation_percentage DECIMAL(5,4) NOT NULL, -- 0.2500 = 25%
    allocation_method VARCHAR(50) NOT NULL, -- 'full_day', 'time_based', 'manual'
    
    -- Billing Impact
    billable_miles INTEGER DEFAULT 0,
    mileage_rate DECIMAL(6,4) DEFAULT 0,
    mileage_charge DECIMAL(10,2) GENERATED ALWAYS AS (billable_miles * mileage_rate) STORED,
    
    -- Status
    status VARCHAR(50) DEFAULT 'calculated', -- 'calculated', 'invoiced', 'disputed', 'adjusted'
    invoiced_in_billing_period_id INTEGER REFERENCES lease_billing_periods(id),
    
    -- Notes
    notes TEXT,
    
    -- Metadata
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- DOCUMENT MANAGEMENT
-- ============================================================================

-- Centralized document storage and management
CREATE TABLE documents (
    id SERIAL PRIMARY KEY,
    
    -- Document Classification
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL, -- 'contract', 'invoice', 'inspection', 'maintenance', 'compliance', 'insurance'
    subcategory VARCHAR(100),
    document_type VARCHAR(100), -- 'pdf', 'image', 'spreadsheet', 'text'
    
    -- Related Entities
    entity_type VARCHAR(100), -- 'customer', 'equipment_unit', 'lease', 'maintenance_event', 'inspection'
    entity_id INTEGER,
    customer_id INTEGER REFERENCES customers(id), -- Denormalized for easy customer document queries
    equipment_unit_id INTEGER REFERENCES equipment_units(id),
    lease_id INTEGER REFERENCES leases(id),
    
    -- File Information
    file_url VARCHAR(500) NOT NULL,
    file_size_bytes BIGINT,
    mime_type VARCHAR(100),
    original_filename VARCHAR(255),
    file_hash VARCHAR(64), -- SHA-256 hash for duplicate detection
    
    -- Access Control
    visibility VARCHAR(50) DEFAULT 'internal', -- 'public', 'customer', 'internal', 'confidential'
    access_roles JSONB DEFAULT '["admin"]',
    password_protected BOOLEAN DEFAULT false,
    
    -- Version Control
    version INTEGER DEFAULT 1,
    is_current_version BOOLEAN DEFAULT true,
    previous_version_id INTEGER REFERENCES documents(id),
    version_notes TEXT,
    
    -- Expiration and Compliance
    expiration_date DATE,
    is_regulatory_required BOOLEAN DEFAULT false,
    compliance_type VARCHAR(100), -- 'DOT', 'Insurance', 'Tax', 'Environmental'
    
    -- Reminders and Notifications
    reminder_enabled BOOLEAN DEFAULT false,
    reminder_days_before INTEGER DEFAULT 30,
    last_reminder_sent TIMESTAMP,
    reminder_recipients JSONB DEFAULT '[]',
    
    -- Status and Workflow
    status VARCHAR(50) DEFAULT 'active', -- 'draft', 'active', 'expired', 'superseded', 'archived'
    requires_approval BOOLEAN DEFAULT false,
    approved_by VARCHAR(100),
    approval_date DATE,
    
    -- OCR and Search
    ocr_text TEXT, -- Extracted text for search
    ocr_processed BOOLEAN DEFAULT false,
    searchable_content TSVECTOR, -- Full-text search vector
    
    -- Usage Tracking
    download_count INTEGER DEFAULT 0,
    last_accessed TIMESTAMP,
    last_accessed_by VARCHAR(100),
    
    -- Integration
    external_system_id VARCHAR(100), -- ID in external system (QuickBooks, etc.)
    external_system_type VARCHAR(50),
    
    -- Metadata
    description TEXT,
    tags JSONB DEFAULT '[]',
    uploaded_by VARCHAR(100),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- REPORTING AND ANALYTICS
-- ============================================================================

-- Saved reports and dashboard configurations
CREATE TABLE saved_reports (
    id SERIAL PRIMARY KEY,
    
    -- Report Definition
    name VARCHAR(255) NOT NULL,
    description TEXT,
    report_type VARCHAR(100) NOT NULL, -- 'utilization', 'financial', 'maintenance', 'customer', 'custom'
    category VARCHAR(100),
    
    -- Configuration
    parameters JSONB NOT NULL DEFAULT '{}', -- Report parameters and filters
    chart_config JSONB DEFAULT '{}', -- Chart type, colors, etc.
    columns JSONB DEFAULT '[]', -- Selected columns and formatting
    
    -- Scheduling
    is_scheduled BOOLEAN DEFAULT false,
    schedule_frequency VARCHAR(50), -- 'daily', 'weekly', 'monthly', 'quarterly'
    schedule_day_of_week INTEGER, -- 1-7 for weekly reports
    schedule_day_of_month INTEGER, -- 1-31 for monthly reports
    schedule_time TIME DEFAULT '09:00:00',
    
    -- Distribution
    recipients JSONB DEFAULT '[]', -- Email addresses for scheduled reports
    delivery_format VARCHAR(50) DEFAULT 'pdf', -- 'pdf', 'excel', 'csv'
    
    -- Access Control
    created_by VARCHAR(100),
    is_public BOOLEAN DEFAULT false,
    shared_with_roles JSONB DEFAULT '[]',
    
    -- Usage Statistics
    run_count INTEGER DEFAULT 0,
    last_run_date TIMESTAMP,
    average_execution_time_ms INTEGER,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- SYSTEM CONFIGURATION AND AUDIT
-- ============================================================================

-- System settings and configuration
CREATE TABLE system_settings (
    id SERIAL PRIMARY KEY,
    category VARCHAR(100) NOT NULL, -- 'billing', 'notifications', 'integrations', 'security'
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    data_type VARCHAR(50) DEFAULT 'string', -- 'string', 'number', 'boolean', 'json'
    description TEXT,
    is_encrypted BOOLEAN DEFAULT false,
    requires_restart BOOLEAN DEFAULT false,
    
    -- Validation
    validation_rules JSONB DEFAULT '{}',
    
    -- Metadata
    updated_by VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(category, setting_key)
);

-- Comprehensive audit logging
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    
    -- Context Information
    table_name VARCHAR(100) NOT NULL,
    record_id INTEGER NOT NULL,
    action VARCHAR(20) NOT NULL, -- 'INSERT', 'UPDATE', 'DELETE'
    
    -- Change Details
    old_values JSONB,
    new_values JSONB,
    changed_columns TEXT[], -- Array of changed column names
    
    -- User Context
    user_id VARCHAR(100),
    user_email VARCHAR(255),
    user_role VARCHAR(50),
    
    -- Request Context
    request_id VARCHAR(100), -- For tracing related changes
    session_id VARCHAR(100),
    ip_address INET,
    user_agent TEXT,
    api_endpoint VARCHAR(255),
    http_method VARCHAR(10),
    
    -- Business Context
    reason TEXT, -- Required for sensitive operations
    business_impact VARCHAR(100), -- 'financial', 'operational', 'compliance', 'security'
    entity_type VARCHAR(100), -- High-level entity being affected
    
    -- Additional Metadata
    severity VARCHAR(50) DEFAULT 'info', -- 'info', 'warning', 'error', 'critical'
    tags JSONB DEFAULT '[]',
    
    -- Timestamp
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- INTEGRATION TABLES
-- ============================================================================

-- QuickBooks integration status tracking
CREATE TABLE quickbooks_sync_status (
    id SERIAL PRIMARY KEY,
    
    -- Entity Information
    entity_type VARCHAR(100) NOT NULL, -- 'customer', 'invoice', 'payment', 'item'
    local_id INTEGER NOT NULL,
    quickbooks_id VARCHAR(100), -- ID in QuickBooks
    
    -- Sync Status
    sync_status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'synced', 'error', 'conflict'
    last_sync_attempt TIMESTAMP,
    last_successful_sync TIMESTAMP,
    sync_direction VARCHAR(50) DEFAULT 'push', -- 'push', 'pull', 'bidirectional'
    
    -- Error Handling
    error_count INTEGER DEFAULT 0,
    last_error_message TEXT,
    last_error_code VARCHAR(100),
    retry_after TIMESTAMP,
    
    -- Data Snapshots
    local_data_hash VARCHAR(64), -- Hash of local data for change detection
    quickbooks_data_hash VARCHAR(64),
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE(entity_type, local_id)
);

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- ============================================================================

-- Core entity indexes
CREATE INDEX idx_equipment_units_status ON equipment_units(status);
CREATE INDEX idx_equipment_units_yard ON equipment_units(current_yard_id);
CREATE INDEX idx_equipment_units_template ON equipment_units(template_id);
CREATE INDEX idx_equipment_units_gps_device ON equipment_units(gps_device_id);

-- Customer indexes
CREATE INDEX idx_customers_company_name ON customers(company_name);
CREATE INDEX idx_customers_email ON customers(email);
CREATE INDEX idx_customers_status ON customers(relationship_status);
CREATE INDEX idx_customers_created_at ON customers(created_at);

-- Lease indexes
CREATE INDEX idx_leases_status ON leases(status);
CREATE INDEX idx_leases_customer ON leases(customer_id);
CREATE INDEX idx_leases_equipment ON leases(equipment_unit_id);
CREATE INDEX idx_leases_dates ON leases(start_date, end_date);
CREATE INDEX idx_leases_contract_number ON leases(contract_number);

-- Reservation indexes
CREATE INDEX idx_reservations_status ON reservations(status);
CREATE INDEX idx_reservations_pickup_date ON reservations(pickup_date);
CREATE INDEX idx_reservations_customer ON reservations(customer_id);

-- Financial indexes
CREATE INDEX idx_invoices_status ON invoices(status);
CREATE INDEX idx_invoices_customer ON invoices(customer_id);
CREATE INDEX idx_invoices_dates ON invoices(invoice_date, due_date);
CREATE INDEX idx_payments_customer ON payments(customer_id);
CREATE INDEX idx_payments_date ON payments(payment_date);

-- Mileage and tracking indexes
CREATE INDEX idx_mileage_logs_equipment_date ON mileage_logs(equipment_unit_id, log_date);
CREATE INDEX idx_mileage_logs_date ON mileage_logs(log_date);

-- Audit and system indexes
CREATE INDEX idx_audit_logs_table_record ON audit_logs(table_name, record_id);
CREATE INDEX idx_audit_logs_user ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_timestamp ON audit_logs(created_at);
CREATE INDEX idx_audit_logs_business_impact ON audit_logs(business_impact);

-- Document indexes
CREATE INDEX idx_documents_entity ON documents(entity_type, entity_id);
CREATE INDEX idx_documents_customer ON documents(customer_id);
CREATE INDEX idx_documents_category ON documents(category, subcategory);
CREATE INDEX idx_documents_expiration ON documents(expiration_date) WHERE expiration_date IS NOT NULL;

-- Full-text search index for documents
CREATE INDEX idx_documents_search ON documents USING gin(searchable_content);

-- ============================================================================
-- TRIGGERS FOR AUTOMATED BUSINESS LOGIC
-- ============================================================================

-- Update equipment unit status when lease status changes
CREATE OR REPLACE FUNCTION update_equipment_status_on_lease_change()
RETURNS TRIGGER AS $$
BEGIN
    -- Handle lease activation
    IF NEW.status = 'active' AND OLD.status = 'pending' THEN
        UPDATE equipment_units 
        SET status = 'on_lease', 
            status_changed_at = CURRENT_TIMESTAMP,
            status_changed_by = NEW.updated_by
        WHERE id = NEW.equipment_unit_id;
        
        -- Log the status change
        INSERT INTO equipment_status_log (
            equipment_unit_id, old_status, new_status, reason, changed_by, related_lease_id
        ) VALUES (
            NEW.equipment_unit_id, 'available', 'on_lease', 
            'Lease activated: ' || NEW.contract_number, NEW.updated_by, NEW.id
        );
    END IF;
    
    -- Handle lease completion
    IF NEW.status = 'completed' AND OLD.status = 'active' THEN
        UPDATE equipment_units 
        SET status = 'available',
            status_changed_at = CURRENT_TIMESTAMP,
            status_changed_by = NEW.updated_by,
            lease_count = lease_count + 1
        WHERE id = NEW.equipment_unit_id;
        
        -- Log the status change
        INSERT INTO equipment_status_log (
            equipment_unit_id, old_status, new_status, reason, changed_by, related_lease_id
        ) VALUES (
            NEW.equipment_unit_id, 'on_lease', 'available', 
            'Lease completed: ' || NEW.contract_number, NEW.updated_by, NEW.id
        );
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_lease_status_change
    AFTER UPDATE ON leases
    FOR EACH ROW
    WHEN (OLD.status IS DISTINCT FROM NEW.status)
    EXECUTE FUNCTION update_equipment_status_on_lease_change();

-- Update customer lease counts
CREATE OR REPLACE FUNCTION update_customer_lease_counts()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        UPDATE customers 
        SET total_leases = total_leases + 1,
            active_leases = active_leases + CASE WHEN NEW.status = 'active' THEN 1 ELSE 0 END,
            last_lease_date = GREATEST(last_lease_date, NEW.start_date)
        WHERE id = NEW.customer_id;
        RETURN NEW;
    ELSIF TG_OP = 'UPDATE' THEN
        -- Handle status changes
        IF OLD.status != NEW.status THEN
            UPDATE customers 
            SET active_leases = active_leases + 
                CASE WHEN NEW.status = 'active' THEN 1 ELSE 0 END -
                CASE WHEN OLD.status = 'active' THEN 1 ELSE 0 END
            WHERE id = NEW.customer_id;
        END IF;
        RETURN NEW;
    ELSIF TG_OP = 'DELETE' THEN
        UPDATE customers 
        SET total_leases = total_leases - 1,
            active_leases = active_leases - CASE WHEN OLD.status = 'active' THEN 1 ELSE 0 END
        WHERE id = OLD.customer_id;
        RETURN OLD;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_customer_lease_counts
    AFTER INSERT OR UPDATE OR DELETE ON leases
    FOR EACH ROW
    EXECUTE FUNCTION update_customer_lease_counts();

-- Auto-update timestamps
CREATE OR REPLACE FUNCTION update_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply timestamp trigger to all tables with updated_at column
DO $$
DECLARE
    t TEXT;
BEGIN
    FOR t IN 
        SELECT table_name 
        FROM information_schema.columns 
        WHERE column_name = 'updated_at' 
        AND table_schema = 'public'
    LOOP
        EXECUTE format('
            CREATE TRIGGER trg_%I_updated_at
                BEFORE UPDATE ON %I
                FOR EACH ROW
                EXECUTE FUNCTION update_timestamp()', t, t);
    END LOOP;
END $$;

-- Equipment financial metrics update trigger
CREATE OR REPLACE FUNCTION update_equipment_financial_metrics()
RETURNS TRIGGER AS $$
BEGIN
    -- Calculate current book value based on depreciation
    IF NEW.depreciation_method = 'straight-line' THEN
        NEW.current_book_value = GREATEST(
            NEW.total_initial_cost - (
                NEW.total_initial_cost - NEW.salvage_value
            ) * (
                EXTRACT(DAYS FROM CURRENT_DATE - NEW.purchase_date) / 365.0
            ) / NEW.depreciation_years,
            NEW.salvage_value
        );
    END IF;
    
    -- Calculate ROI percentage
    IF NEW.total_initial_cost > 0 THEN
        NEW.roi_percentage = (NEW.net_profit / NEW.total_initial_cost) * 100;
    END IF;
    
    -- Calculate payback period
    IF NEW.monthly_cash_flow > 0 THEN
        NEW.payback_period_months = NEW.total_initial_cost / NEW.monthly_cash_flow;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_equipment_financial_metrics
    BEFORE UPDATE ON equipment_financials
    FOR EACH ROW
    EXECUTE FUNCTION update_equipment_financial_metrics();

-- ============================================================================
-- INITIAL DATA
-- ============================================================================

-- Insert default system settings
INSERT INTO system_settings (category, setting_key, setting_value, description) VALUES
('billing', 'default_payment_terms_days', '30', 'Default payment terms in days'),
('billing', 'late_fee_percentage', '0.015', 'Monthly late fee percentage (1.5%)'),
('billing', 'tax_rate_default', '0.05', 'Default tax rate (5% GST)'),
('notifications', 'invoice_reminder_days', '7,14,30', 'Days before due date to send reminders'),
('notifications', 'document_expiry_reminder_days', '30,7', 'Days before expiry to send reminders'),
('maintenance', 'default_mvi_interval_days', '365', 'Default MVI interval in days'),
('maintenance', 'default_cvi_interval_days', '365', 'Default CVI interval in days'),
('integrations', 'samsara_sync_interval_minutes', '60', 'Samsara data sync interval'),
('integrations', 'quickbooks_sync_enabled', 'true', 'Enable QuickBooks synchronization'),
('security', 'password_min_length', '12', 'Minimum password length'),
('security', 'session_timeout_minutes', '480', 'Session timeout in minutes (8 hours)');

-- Insert default yard
INSERT INTO yards (name, city, state, country) VALUES 
('Main Yard', 'Lethbridge', 'Alberta', 'Canada');

-- Create indexes for JSONB columns
CREATE INDEX idx_customers_tags ON customers USING gin(tags);
CREATE INDEX idx_equipment_units_tags ON equipment_units USING gin(tags);
CREATE INDEX idx_customer_rate_structures_custom_terms ON customer_rate_structures USING gin(custom_terms);
CREATE INDEX idx_documents_tags ON documents USING gin(tags);
CREATE INDEX idx_audit_logs_tags ON audit_logs USING gin(tags);