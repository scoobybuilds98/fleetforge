# FleetForge — Accounting Module Specification
**Version:** 1.2 FINAL — Build-readiness fixes applied | **Owner:** Avi | **Business:** Mainland Truck & Trailer Sales
**Status:** LOCKED — Read this file for all accounting module sessions
**Depends on:** FLEETFORGE_SPEC_FINAL.md (v2.5) must be read first

---

## DECISIONS LOCKED

| # | Decision |
|---|----------|
| A1 | Accountant role has full authority — no bill approval workflow |
| A2 | Straight-line depreciation as system default. All methods available: straight-line, declining balance (CRA CCA rates), units of production. Accountant selects per asset. |
| A3 | Single entity — no cost center or profit center split |
| A4 | Fiscal year: Calendar year January 1 – December 31 |
| A5 | No payroll processing. No CRA e-filing. Reports only. |
| A6 | Accounting module tables are 62–100 in the combined schema. Prefixed `acc_` to distinguish from core FleetForge tables. |
| A7 | All monetary values in accounting tables use DECIMAL(15,2) — larger than core tables to accommodate GL aggregations |
| A8 | Journal entries are the backbone. Every financial event — invoice, payment, bill, depreciation — posts a journal entry. Nothing bypasses the GL. |
| A9 | The AR subledger is the FleetForge billing module. The GL AR account balance must always reconcile to the sum of open invoice balances. Any discrepancy is flagged as an error. |
| A10 | QuickBooks Online integration is Phase 17 (placeholder). The accounting module is standalone. |

---

## TABLE OF CONTENTS

1. Architecture & Integration with FleetForge
2. Database Schema — 34 New Tables
3. Chart of Accounts
4. General Ledger & Journal Entries
5. Accounts Receivable (Accounting Layer)
6. Accounts Payable
7. Bank & Cash Management
8. Fixed Assets & Depreciation
9. Tax Management
10. Financial Statements & Reports
11. Budgeting
12. Multi-Currency Accounting
13. Audit & Compliance
14. API Conventions (Accounting)
15. Module Pages & UI
16. Automatic Journal Entry Rules
17. Build Order (Sessions)

---

## 1. ARCHITECTURE & INTEGRATION WITH FLEETFORGE

### How the two systems connect

FleetForge handles operations. The accounting module handles the financial record of those operations. They share the same database but are logically separated by the `acc_` table prefix and a dedicated accounting section in the UI.

**Data flows one way: FleetForge → Accounting**

When a FleetForge event occurs, an automatic journal entry is posted:

| FleetForge Event | Journal Entry Posted |
|-----------------|---------------------|
| Invoice status → `sent` | DR Accounts Receivable / CR Revenue + Tax accounts |
| Payment recorded | DR Cash (bank account) / CR Accounts Receivable |
| Invoice voided | Reverse the original invoice JE |
| Credit note created | DR Revenue / CR 2060 Customer Credits Liability [PASS-6:G2] |
| Late fee invoice created | DR AR / CR Late Fee Revenue |
| Lease closed (mileage adj.) | DR AR / CR Mileage Revenue (or reverse if credit) |

The accounting module never writes back to FleetForge billing tables. It reads from them to generate entries and stores entries in its own tables.

### Shared reference data (no duplication)

| Accounting module uses | From FleetForge table |
|-----------------------|-----------------------|
| Customers | `customers` |
| Vendors (AP) | `vendors` |
| Equipment units (fixed assets) | `equipment_units` |
| Invoices (AR subledger) | `invoices` |
| Payments | `payments` |
| Credit notes | `credit_notes` |
| Exchange rates | `exchange_rates` |
| Users & permissions | `users`, `user_permissions` |
| Settings | `settings` |

### Roles & permissions (accounting-specific)

Extends the existing permission matrix with new modules:

| Module | accountant | manager | super_admin | read_only |
|--------|-----------|---------|-------------|-----------|
| chart_of_accounts | VCE | V | VCEDS | V |
| journal_entries | VCED | V | VCEDS | V |
| accounts_payable | VCED | V | VCEDS | V |
| bank_accounts | VCED | V | VCEDS | V |
| fixed_assets | VCED | V | VCEDS | V |
| tax_management | VCED | V | VCEDS | V |
| financial_reports | VCE | VCE | VCEDS | V |
| budgets | VCED | VCE | VCEDS | V |
| period_management | VCE | V | VCEDS | V |

Key: V=view, C=create, E=edit, D=delete, S=settings

---

## 2. DATABASE SCHEMA — 34 NEW TABLES -- [PASS-1:M1] corrected from 38

**Global rules (same as core FleetForge):**
- Engine: InnoDB | Charset: utf8mb4_unicode_ci
- All monetary: DECIMAL(15,2) — NEVER FLOAT
- All datetimes: stored UTC
- All status: ENUM — NEVER VARCHAR
- All IDs: INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- Prefix: `acc_` on all new tables

---

### 2.1 ACCOUNTING PERIODS

```sql
CREATE TABLE acc_periods (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year            SMALLINT UNSIGNED NOT NULL,
    month           TINYINT UNSIGNED NOT NULL,     -- 1-12
    name            VARCHAR(50) NOT NULL,           -- "January 2025"
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
-- Seeds: auto-generate periods for current year on install
```

---

### 2.2 CHART OF ACCOUNTS

```sql
CREATE TABLE acc_accounts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(20) NOT NULL UNIQUE,    -- "4010", "6100"
    name                VARCHAR(255) NOT NULL,
    description         TEXT NULL,
    account_type        ENUM('asset','liability','equity',
                             'revenue','cost_of_revenue',
                             'operating_expense','other_income',
                             'other_expense') NOT NULL,
    account_subtype     VARCHAR(100) NULL,              -- "current_asset", "fixed_asset", etc.
    parent_id           INT UNSIGNED NULL,              -- for hierarchy
    is_header           TINYINT(1) NOT NULL DEFAULT 0,  -- header accounts can't be posted to
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    normal_balance      ENUM('debit','credit') NOT NULL, -- debit for assets/expenses, credit for liabilities/equity/revenue
    is_system           TINYINT(1) NOT NULL DEFAULT 0,  -- system accounts locked (AR, AP, Cash, etc.)
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    is_bank_account     TINYINT(1) NOT NULL DEFAULT 0,  -- links to acc_bank_accounts
    tax_line_code       VARCHAR(50) NULL,               -- CRA tax line mapping
    coa_group           VARCHAR(100) NULL,              -- display grouping on financial statements
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (account_type),
    INDEX idx_parent (parent_id),
    INDEX idx_active (is_active),
    FOREIGN KEY (parent_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.3 JOURNAL ENTRIES

```sql
CREATE TABLE acc_journal_entries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_number    VARCHAR(50) NOT NULL UNIQUE,       -- JE-2025-00001
    period_id       INT UNSIGNED NOT NULL,
    entry_date      DATE NOT NULL,
    entry_type      ENUM('manual','system','recurring',
                         'reversing','year_end','adjustment') NOT NULL DEFAULT 'manual',
    status          ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
    description     VARCHAR(500) NOT NULL,
    reference       VARCHAR(255) NULL,                 -- invoice #, bill #, etc.
    -- Source linking (what FleetForge event generated this)
    source_type     ENUM('invoice','payment','credit_note','ap_bill',
                         'ap_payment','bank_transaction','depreciation',
                         'asset_disposal','fx_revaluation','manual',
                         'year_end','recurring') NULL,
    source_id       INT UNSIGNED NULL,
    -- Reversal tracking
    is_reversal         TINYINT(1) NOT NULL DEFAULT 0,
    reversal_of_id      INT UNSIGNED NULL,
    reversed_by_id      INT UNSIGNED NULL,
    reversal_date       DATE NULL,
    -- Auto-reverse
    auto_reverse        TINYINT(1) NOT NULL DEFAULT 0,
    auto_reverse_date   DATE NULL,
    -- Metadata
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
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    line_number     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    description     VARCHAR(500) NULL,
    debit           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    -- Foreign currency
    foreign_amount  DECIMAL(15,2) NULL,
    foreign_currency ENUM('CAD','USD') NULL,
    exchange_rate   DECIMAL(10,6) NULL,
    -- Linking
    customer_id     INT UNSIGNED NULL,
    vendor_id       INT UNSIGNED NULL,
    equipment_unit_id INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_journal_entry (journal_entry_id),
    INDEX idx_account (account_id),
    INDEX idx_customer (customer_id),
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.4 RECURRING JOURNAL ENTRIES

```sql
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
    auto_post           TINYINT(1) NOT NULL DEFAULT 0,  -- if 0, creates draft for review
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
```

---

### 2.5 ACCOUNTS PAYABLE — BILLS

```sql
CREATE TABLE acc_bills (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_number         VARCHAR(100) NOT NULL UNIQUE,  -- BILL-2025-00001
    vendor_id           INT UNSIGNED NOT NULL,
    vendor_bill_number  VARCHAR(100) NULL,             -- vendor's own invoice number
    bill_date           DATE NOT NULL,
    due_date            DATE NOT NULL,
    period_id           INT UNSIGNED NOT NULL,
    status              ENUM('draft','approved','scheduled',
                             'partially_paid','paid','void') NOT NULL DEFAULT 'draft',
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    exchange_rate_to_cad DECIMAL(10,6) NULL,
    -- Amounts
    subtotal            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_gst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_pst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_hst_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_total           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_amount        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount_paid         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance_due         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    -- Linking
    work_order_id       INT UNSIGNED NULL,             -- links to maintenance work order
    equipment_unit_id   INT UNSIGNED NULL,             -- which unit this bill relates to
    -- Notes
    notes               TEXT NULL,
    internal_notes      TEXT NULL,
    -- Void
    void_reason         TEXT NULL,
    voided_by           INT UNSIGNED NULL,
    voided_at           DATETIME NULL,
    -- GL
    journal_entry_id    INT UNSIGNED NULL,
    -- Metadata
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

CREATE TABLE acc_bill_lines (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_id         INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    description     VARCHAR(500) NOT NULL,
    quantity        DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    unit_cost       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    is_tax_input_credit TINYINT(1) NOT NULL DEFAULT 1,  -- is GST on this line an ITC?
    tax_gst_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_pst_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_hst_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    -- Asset capitalization
    capitalize      TINYINT(1) NOT NULL DEFAULT 0,     -- should this be capitalized?
    asset_id        INT UNSIGNED NULL,                  -- if capitalized, which asset
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bill_id) REFERENCES acc_bills(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.6 ACCOUNTS PAYABLE — PAYMENTS & VENDOR CREDITS

```sql
CREATE TABLE acc_ap_payments (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number          VARCHAR(100) NOT NULL UNIQUE,  -- APAY-2025-00001
    vendor_id               INT UNSIGNED NOT NULL,
    bank_account_id         INT UNSIGNED NOT NULL,
    payment_date            DATE NOT NULL,
    payment_method          ENUM('check','eft','wire','credit_card',
                                 'cash','other') NOT NULL,
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

CREATE TABLE acc_vendor_credits (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_number       VARCHAR(100) NOT NULL UNIQUE,
    vendor_id           INT UNSIGNED NOT NULL,
    credit_date         DATE NOT NULL,
    reason              VARCHAR(500) NOT NULL,
    amount              DECIMAL(15,2) NOT NULL,
    amount_remaining    DECIMAL(15,2) NOT NULL,
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    status              ENUM('active','partially_used',
                             'fully_used','void') NOT NULL DEFAULT 'active',
    source_bill_id      INT UNSIGNED NULL,
    journal_entry_id    INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
```

---

### 2.7 — NOTE: Master SQL governs creation order (accounts → reconciliations → transactions) [PASS-1:H7]
### 2.7 BANK ACCOUNTS & RECONCILIATION

```sql
CREATE TABLE acc_bank_accounts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,
    account_number_last4 VARCHAR(4) NULL,
    institution         VARCHAR(255) NULL,
    account_type        ENUM('checking','savings','line_of_credit',
                             'credit_card') NOT NULL DEFAULT 'checking',
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    gl_account_id       INT UNSIGNED NOT NULL,     -- links to acc_accounts (Cash account)
    opening_balance     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    opening_balance_date DATE NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    is_default          TINYINT(1) NOT NULL DEFAULT 0,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gl_account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_bank_transactions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_account_id     INT UNSIGNED NOT NULL,
    transaction_date    DATE NOT NULL,
    description         VARCHAR(500) NOT NULL,
    reference           VARCHAR(255) NULL,
    amount              DECIMAL(15,2) NOT NULL,    -- positive = deposit, negative = withdrawal
    transaction_type    ENUM('deposit','withdrawal','transfer',
                             'bank_charge','interest','nsf',
                             'other') NOT NULL,
    source              ENUM('manual','import','system') NOT NULL DEFAULT 'manual',
    -- Matching
    status              ENUM('unmatched','matched','excluded') NOT NULL DEFAULT 'unmatched',
    matched_type        ENUM('payment','ap_payment','journal_entry',
                             'bank_transfer','other') NULL,
    matched_id          INT UNSIGNED NULL,
    matched_at          DATETIME NULL,
    matched_by          INT UNSIGNED NULL,
    -- Reconciliation
    reconciliation_id   INT UNSIGNED NULL,
    is_cleared          TINYINT(1) NOT NULL DEFAULT 0,
    cleared_date        DATE NULL,
    -- GL
    journal_entry_id    INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bank_account (bank_account_id),
    INDEX idx_date (transaction_date),
    INDEX idx_status (status),
    FOREIGN KEY (bank_account_id) REFERENCES acc_bank_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (matched_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_bank_reconciliations (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_account_id         INT UNSIGNED NOT NULL,
    period_id               INT UNSIGNED NOT NULL,
    statement_date          DATE NOT NULL,
    statement_ending_balance DECIMAL(15,2) NOT NULL,
    book_balance            DECIMAL(15,2) NOT NULL,
    outstanding_deposits    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    outstanding_checks      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    adjusted_book_balance   DECIMAL(15,2) NOT NULL,
    difference              DECIMAL(15,2) NOT NULL DEFAULT 0.00,  -- must be 0 to complete
    status                  ENUM('in_progress','completed','locked') NOT NULL DEFAULT 'in_progress',
    completed_by            INT UNSIGNED NULL,
    completed_at            DATETIME NULL,
    notes                   TEXT NULL,
    created_by              INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_period (bank_account_id, period_id),
    FOREIGN KEY (bank_account_id) REFERENCES acc_bank_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.8 FIXED ASSETS

```sql
CREATE TABLE acc_fixed_assets (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_number            VARCHAR(100) NOT NULL UNIQUE,  -- FA-2025-001
    name                    VARCHAR(255) NOT NULL,
    description             TEXT NULL,
    asset_class             ENUM('fleet_equipment','vehicles','office_equipment',
                                 'leasehold_improvements','land','building',
                                 'other') NOT NULL,
    -- CRA Class (for declining balance)
    cra_class               VARCHAR(20) NULL,              -- "Class 10", "Class 8", etc.
    cra_cca_rate            DECIMAL(5,4) NULL,             -- 0.3000 for 30%
    -- FleetForge link
    equipment_unit_id       INT UNSIGNED NULL,             -- null for non-fleet assets
    -- Acquisition
    acquisition_date        DATE NOT NULL,
    acquisition_cost        DECIMAL(15,2) NOT NULL,
    acquisition_bill_id     INT UNSIGNED NULL,             -- AP bill that purchased it
    vendor_id               INT UNSIGNED NULL,
    -- Depreciation
    depreciation_method     ENUM('straight_line','declining_balance',
                                 'units_of_production',
                                 'none') NOT NULL DEFAULT 'straight_line',
    useful_life_years       DECIMAL(5,2) NULL,             -- for straight-line
    salvage_value           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    depreciable_cost        DECIMAL(15,2) NOT NULL,        -- acquisition_cost - salvage_value
    accumulated_depreciation DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_book_value          DECIMAL(15,2) NOT NULL,        -- acquisition_cost - accumulated_depreciation
    last_depreciation_date  DATE NULL,
    depreciation_start_date DATE NOT NULL,
    fully_depreciated_date  DATE NULL,                     -- calculated
    -- GL accounts
    asset_account_id        INT UNSIGNED NOT NULL,         -- e.g. 1210 Fleet Equipment Cost
    accum_depr_account_id   INT UNSIGNED NOT NULL,         -- e.g. 1220 Accumulated Depreciation
    depr_expense_account_id INT UNSIGNED NOT NULL,         -- e.g. 5010 Depreciation Expense
    -- Status
    status                  ENUM('active','fully_depreciated',
                                 'disposed','impaired') NOT NULL DEFAULT 'active',
    -- Units of production (for fleet)
    total_expected_units    INT UNSIGNED NULL,              -- total expected miles/hours
    units_used_to_date      INT UNSIGNED NOT NULL DEFAULT 0,
    -- Location
    location                VARCHAR(255) NULL,
    serial_number           VARCHAR(100) NULL,
    -- Notes
    notes                   TEXT NULL,
    created_by              INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    UNIQUE KEY uq_period_run (period_id, status),
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
    method_used     ENUM('straight_line','declining_balance',
                         'units_of_production') NOT NULL,
    calculation_detail JSON NULL,                  -- shows the math
    FOREIGN KEY (run_id) REFERENCES acc_depreciation_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES acc_fixed_assets(id) ON DELETE CASCADE,
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_asset_disposals (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id            INT UNSIGNED NOT NULL,
    disposal_date       DATE NOT NULL,
    disposal_type       ENUM('sale','scrap','trade_in',
                             'write_off','other') NOT NULL,
    proceeds            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_book_value_at_disposal DECIMAL(15,2) NOT NULL,
    gain_loss           DECIMAL(15,2) NOT NULL,  -- positive = gain, negative = loss
    -- GL
    proceeds_account_id     INT UNSIGNED NULL,
    gain_loss_account_id    INT UNSIGNED NOT NULL,
    journal_entry_id        INT UNSIGNED NULL,
    -- Buyer info (if sold)
    buyer_name          VARCHAR(255) NULL,
    buyer_reference     VARCHAR(255) NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    FOREIGN KEY (asset_id) REFERENCES acc_fixed_assets(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.9 TAX MANAGEMENT

```sql
CREATE TABLE acc_tax_filing_periods (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tax_type        ENUM('gst_hst','pst_bc','pst_sk','pst_mb') NOT NULL,
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    filing_due_date DATE NOT NULL,
    frequency       ENUM('monthly','quarterly','annually') NOT NULL,
    -- Calculated amounts
    total_sales             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_tax_collected     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_itc               DECIMAL(15,2) NOT NULL DEFAULT 0.00,  -- input tax credits
    net_tax_owing           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    -- Status
    status          ENUM('open','calculated','filed','remitted') NOT NULL DEFAULT 'open',
    filed_date      DATE NULL,
    filed_by        INT UNSIGNED NULL,
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_period (tax_type, period_start, period_end),
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
```

---

### 2.10 AR — COLLECTIONS & DEPOSITS

```sql
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
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_promise_to_pay (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT UNSIGNED NOT NULL,
    invoice_id          INT UNSIGNED NULL,
    promised_amount     DECIMAL(15,2) NOT NULL,
    promise_date        DATE NOT NULL,        -- when they said they'd pay
    promised_by         VARCHAR(255) NULL,    -- customer contact name who promised
    status              ENUM('pending','kept','broken','cancelled') NOT NULL DEFAULT 'pending',
    actual_payment_date DATE NULL,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_promise_date (promise_date),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_dunning_letters (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT UNSIGNED NOT NULL,
    letter_type     ENUM('reminder_30','reminder_60',
                         'warning_90','final_notice') NOT NULL,
    sent_date       DATE NOT NULL,
    sent_method     ENUM('email','mail','both') NOT NULL,
    sent_to_email   VARCHAR(255) NULL,
    total_overdue   DECIMAL(15,2) NOT NULL,
    invoice_count   TINYINT UNSIGNED NOT NULL,
    pdf_path        VARCHAR(500) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_bad_debt_writeoffs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id          INT UNSIGNED NOT NULL,
    customer_id         INT UNSIGNED NOT NULL,
    writeoff_date       DATE NOT NULL,
    amount              DECIMAL(15,2) NOT NULL,
    reason              TEXT NOT NULL,
    journal_entry_id    INT UNSIGNED NULL,
    -- Recovery
    recovered           TINYINT(1) NOT NULL DEFAULT 0,
    recovered_amount    DECIMAL(15,2) NULL,
    recovered_date      DATE NULL,
    recovery_journal_entry_id INT UNSIGNED NULL,
    created_by          INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (recovery_journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE acc_customer_deposits (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_number      VARCHAR(100) NOT NULL UNIQUE,
    customer_id         INT UNSIGNED NOT NULL,
    lease_id            INT UNSIGNED NULL,
    deposit_type        ENUM('security','damage','advance_payment',
                             'other') NOT NULL DEFAULT 'security',
    amount              DECIMAL(15,2) NOT NULL,
    currency            ENUM('CAD','USD') NOT NULL DEFAULT 'CAD',
    received_date       DATE NOT NULL,
    status              ENUM('held','applied','refunded',
                             'forfeited') NOT NULL DEFAULT 'held',
    -- Applied or refunded details
    applied_to_invoice_id   INT UNSIGNED NULL,
    applied_date            DATE NULL,
    refund_date             DATE NULL,
    refund_method           VARCHAR(100) NULL,
    -- GL
    journal_entry_id        INT UNSIGNED NULL,
    liability_account_id    INT UNSIGNED NULL,   -- Customer Deposits liability
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
```

---

### 2.11 BUDGETS

```sql
CREATE TABLE acc_budgets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    year            SMALLINT UNSIGNED NOT NULL,
    version         ENUM('base','conservative','optimistic') NOT NULL DEFAULT 'base',
    status          ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    is_active       TINYINT(1) NOT NULL DEFAULT 0,   -- only one active budget per year
    notes           TEXT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_year_version_active (year, version, is_active),
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
    `dec`           DECIMAL(15,2) NOT NULL DEFAULT 0.00, -- [PASS-1:M2] reserved word, must be backtick-quoted
    annual_total    DECIMAL(15,2) GENERATED ALWAYS AS
                    (jan+feb+mar+apr+may+jun+jul+aug+sep+oct+nov+`dec`) STORED,
    notes           VARCHAR(500) NULL,
    UNIQUE KEY uq_budget_account (budget_id, account_id),
    FOREIGN KEY (budget_id) REFERENCES acc_budgets(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES acc_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.12 FX REVALUATIONS

```sql
CREATE TABLE acc_fx_revaluations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    revaluation_date    DATE NOT NULL,
    period_id           INT UNSIGNED NOT NULL,
    exchange_rate_used  DECIMAL(10,6) NOT NULL,
    -- Summary
    total_ar_usd        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_ar_cad_book   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_ar_cad_revalued DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    unrealized_gain_loss DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    journal_entry_id    INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (period_id) REFERENCES acc_periods(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_entry_id) REFERENCES acc_journal_entries(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.13 ACCOUNTING DOCUMENTS & YEAR-END

```sql
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
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### COMPLETE TABLE COUNT — ACCOUNTING MODULE

| # | Table | Module |
|---|-------|--------|
| 62 | acc_periods | GL |
| 63 | acc_accounts | COA |
| 64 | acc_journal_entries | GL |
| 65 | acc_journal_entry_lines | GL |
| 66 | acc_recurring_entries | GL |
| 67 | acc_recurring_entry_lines | GL |
| 68 | acc_bills | AP |
| 69 | acc_bill_lines | AP |
| 70 | acc_ap_payments | AP |
| 71 | acc_ap_payment_allocations | AP |
| 72 | acc_vendor_credits | AP |
| 73 | acc_vendor_credit_applications | AP |
| 74 | acc_bank_accounts | Bank |
| 75 | acc_bank_transactions | Bank |
| 76 | acc_bank_reconciliations | Bank |
| 77 | acc_fixed_assets | Assets |
| 78 | acc_depreciation_runs | Assets |
| 79 | acc_depreciation_run_lines | Assets |
| 80 | acc_asset_disposals | Assets |
| 81 | acc_asset_impairments | Assets |
| 82 | acc_tax_filing_periods | Tax |
| 83 | acc_tax_remittances | Tax |
| 84 | acc_collection_notes | AR |
| 85 | acc_promise_to_pay | AR |
| 86 | acc_dunning_letters | AR |
| 87 | acc_bad_debt_writeoffs | AR |
| 88 | acc_customer_deposits | AR |
| 89 | acc_budgets | Budget |
| 90 | acc_budget_lines | Budget |
| 91 | acc_fx_revaluations | FX |
| 92 | acc_documents | Docs |
| 93 | acc_year_end_checklist | Year-End |
| 94 | acc_report_configurations | Reports |

**Total tables across entire platform: 94 (59 core + 34 accounting + 1 utility)**
*(customer_documents and equipment_documents dropped from core in v2.3; acc_qbo_sync_log added to accounting)*

---

## 3. CHART OF ACCOUNTS

### Default seeded COA (Canadian rental/fleet company)

```
1000  Current Assets                          [HEADER]
  1010  Cash — Operating Account (CAD)
  1020  Cash — USD Account
  1030  Accounts Receivable
  1040  Allowance for Doubtful Accounts
  1050  GST/HST Receivable (Input Tax Credits)
  1060  PST Receivable
  1065  Prepaid Insurance
  1070  Prepaid Expenses — Other
  1080  Security Deposits Held (Asset)

1200  Fixed Assets                            [HEADER]
  1210  Fleet Equipment — Cost
  1220  Fleet Equipment — Accumulated Depreciation
  1230  Vehicles — Cost
  1240  Vehicles — Accumulated Depreciation
  1250  Office Equipment — Cost
  1260  Office Equipment — Accumulated Depreciation
  1270  Leasehold Improvements — Cost
  1280  Leasehold Improvements — Accumulated Amortization

2000  Current Liabilities                     [HEADER]
  2010  Accounts Payable
  2020  Accrued Liabilities
  2030  GST/HST Payable
  2040  PST Payable
  2050  Customer Deposits — Security (Deferred Revenue)
  2060  Customer Deposits — Advance Payments
  2070  Current Portion of Long-Term Debt
  2080  Income Tax Payable

2200  Long-Term Liabilities                   [HEADER]
  2210  Equipment Loans Payable
  2220  Line of Credit

3000  Equity                                  [HEADER]
  3010  Common Shares
  3020  Retained Earnings
  3030  Owner Drawings
  3040  Current Year Net Income               [system — auto-calculated]

4000  Revenue                                 [HEADER]
  4010  Chassis Rental Revenue
  4020  Dry Van Rental Revenue
  4030  Reefer Rental Revenue
  4040  Flatbed Rental Revenue
  4050  Other Equipment Rental Revenue
  4060  Mileage Revenue
  4070  Insurance Revenue
  4080  Warranty Revenue
  4090  Late Fee Revenue
  4100  Damage Recovery Revenue
  4110  Other Revenue

5000  Cost of Revenue                         [HEADER]
  5010  Depreciation — Rental Fleet
  5020  Fleet Insurance — Direct
  5030  Registration & Licensing — Fleet
  5040  GPS & Telematics Costs

6000  Operating Expenses                      [HEADER]
  6010  Maintenance & Repairs — Labour
  6020  Maintenance & Repairs — Parts
  6030  Tires
  6040  Fuel
  6050  Yard Rent / Lease
  6060  Utilities
  6070  Salaries & Wages
  6080  Employee Benefits & Payroll Costs
  6090  Office Supplies
  6100  Software & Subscriptions
  6110  Professional Fees — Legal
  6120  Professional Fees — Accounting
  6130  Insurance — General & Liability
  6140  Advertising & Marketing
  6150  Travel & Entertainment
  6160  Bad Debt Expense
  6170  Bank Charges
  6180  Interest Expense
  6190  Depreciation — Non-Fleet Assets
  6200  Amortization — Leasehold Improvements
  6210  Other Operating Expenses

7000  Other Income / Expense                  [HEADER]
  7010  Gain on Disposal of Assets
  7020  Loss on Disposal of Assets
  7030  Foreign Exchange Gain
  7040  Foreign Exchange Loss
  7050  Interest Income
```

### Business rules
- Header accounts (`is_header = 1`) cannot receive journal entry postings — blocked at API
- System accounts (`is_system = 1`): AR (1030), AP (2010), GST Payable (2030), PST Payable (2040), HST Payable (included in 2030 or separate) — cannot be deleted or deactivated
- Account cannot be deactivated if it has a non-zero balance
- Account codes must be unique — no two accounts share a code
- Parent account must be a header account
- Normal balance: Asset, COGS, Expense = Debit. Liability, Equity, Revenue = Credit. Used for display (show red if balance on wrong side)

---

## 4. GENERAL LEDGER & JOURNAL ENTRIES

### Journal entry rules
- Every posted entry: sum of all debit lines must equal sum of all credit lines — enforced server-side, save blocked if unbalanced
- Minimum 2 lines per entry (one debit, one credit)
- Maximum 50 lines per entry
- Cannot post to a closed or locked period — returns 409 with period status
- Cannot post to a header account — returns 422
- Cannot post to an inactive account — returns 422
- `entry_number` auto-generated: `JE-YYYY-NNNNN` (e.g. JE-2025-00047)
- Draft entries: visible only in GL drafts view, do not affect account balances
- Posted entries: immediately affect account balances and appear in all reports
- Posted entries cannot be edited — only reversed
- Reversal: creates a new entry with identical lines but debits and credits swapped, links both with `reversal_of_id` / `reversed_by_id`

### Auto-reversing entries
For accruals that should be reversed at the start of next period:
- Set `auto_reverse = 1` and `auto_reverse_date` on any posted entry
- `cron/accounting_auto_reverse.php` runs nightly — checks for entries where `auto_reverse = 1` AND `auto_reverse_date <= TODAY` AND no reversal yet exists — posts the reversal automatically

### Recurring entries
- Template stored in `acc_recurring_entries` + `acc_recurring_entry_lines`
- `cron/accounting_recurring_entries.php` runs on the 1st of each month
- If `auto_post = 1`: posts directly. If `auto_post = 0`: creates a draft for accountant review
- Updates `next_post_date` and `last_posted_date` after each run

### Period management rules
- **Open:** Normal posting allowed
- **Closed:** No new postings. Adjusting entries require Super Admin override with reason logged
- **Locked:** Nothing can post. Not even Super Admin. Used after year-end filing
- Warning if posting to a date more than 60 days in the past (allows but logs a warning)
- Year-end close process:
  1. All periods for the year must be closed
  2. Run depreciation for December
  3. Post any remaining accruals
  4. Run GL reconciliation check (AR balance = open invoices, AP balance = open bills)
  5. Post retained earnings entry: DR Current Year Net Income / CR Retained Earnings
  6. Lock all periods for the year
  7. Auto-create periods for the new year

---

## 5. ACCOUNTS RECEIVABLE (ACCOUNTING LAYER)

### AR subledger reconciliation
The AR subledger IS the FleetForge billing module. The GL AR account (1030) must always equal the sum of all open invoice balances:
```
GL AR balance = SUM(invoices.balance_due) WHERE status NOT IN ('paid','void','written_off') AND deleted_at IS NULL
```
Any discrepancy triggers a reconciliation warning banner in the accounting dashboard. A `POST api/v1/accounting/ar/reconcile_check.php` endpoint performs this check on demand.

### Automatic journal entries from FleetForge billing events

**Invoice sent (status → sent):**
```
DR  1030  Accounts Receivable        [invoice total_amount]
  CR  4010-4100  Revenue accounts    [subtotal by line item type]
  CR  2030  GST/HST Payable          [tax_gst_amount + tax_hst_amount]
  CR  2040  PST Payable              [tax_pst_amount]
```

**Payment received:**
```
DR  1010  Cash — Operating Account   [payment amount]
  CR  1030  Accounts Receivable      [payment amount]
```

**Invoice voided:**
```
-- Exact reversal of the original invoice journal entry
DR  4010-4100  Revenue (reversal)
DR  2030  GST Payable (reversal)
DR  2040  PST Payable (reversal)
  CR  1030  Accounts Receivable (reversal)
```

**Credit note created:** [PASS-6:G2 — posts to liability, NOT directly to AR]
```
DR  4010-4100  Revenue               [credit note amount]
  CR  2060  Customer Credits Liability  [credit note amount]
-- AR is reduced only when the credit is APPLIED to a specific invoice:
-- DR 2060 Customer Credits Liability / CR 1030 AR [applied amount]
```

**Bad debt write-off:**
```
DR  6160  Bad Debt Expense           [invoice balance]
  CR  1030  Accounts Receivable      [invoice balance]
```

### Customer statements
Generated on demand from `api/v1/accounting/ar/statement.php`:
- All transactions for the customer in the selected date range
- Opening balance, each invoice, each payment, each credit note, each write-off
- Closing balance due
- Aged summary at bottom (current, 1-30, 31-60, 61-90, 90+)
- PDF generated via mPDF, company header, page numbers

### Collections workflow
- Collection status per customer: current, watch, collections, legal, written_off
- Updated manually or auto-escalated by system:
  - 15 days overdue → `watch`
  - 45 days overdue → `collections` (auto, with notification to manager)
  - 90 days overdue → notification only (legal status set manually)
- Collection notes log: every call/email/letter logged in `acc_collection_notes`
- Promise to pay tracking: record promises in `acc_promise_to_pay`, cron checks daily for broken promises and alerts
- Dunning letters:
  - 30 days overdue: Friendly reminder (auto-generate PDF, send email)
  - 60 days overdue: Second notice
  - 90 days overdue: Final warning before collections
  - Letters stored in `acc_dunning_letters`, PDF in storage

---

## 6. ACCOUNTS PAYABLE

### Bill lifecycle
```
draft → approved → (scheduled) → partially_paid → paid
draft → void
approved → void
```

Since accountant has full authority (Decision A1), `approved` happens immediately on save — no separate approval step.

### Automatic journal entry on bill approval
```
DR  [expense accounts from bill lines]  [line amounts]
DR  1050  GST/HST Receivable (ITC)      [tax amounts where is_tax_input_credit = 1]
  CR  2010  Accounts Payable            [bill total]
```

### AP payment journal entry
```
DR  2010  Accounts Payable              [payment amount]
  CR  1010  Cash — Operating Account    [payment amount]
```

### AP subledger reconciliation
Same principle as AR:
```
GL AP balance (2010) = SUM(acc_bills.balance_due) WHERE status NOT IN ('paid','void')
```
Discrepancy triggers reconciliation warning.

### Cash requirements report
Shows what's due in the next 7, 14, and 30 days:
- By vendor
- By due date
- Total outflow required
- Compared against current bank balance
- Flagged if projected outflow > current balance

### Vendor aging
| Bucket | Days past due |
|--------|--------------|
| Current | Not yet due |
| 1–30 days | 1–30 |
| 31–60 days | 31–60 |
| 60+ days | Over 60 |

---

## 7. BANK & CASH MANAGEMENT

### Bank statement import
Supported formats: CSV from RBC, TD, Scotiabank, BMO, CIBC (configurable column mapping)

Import process:
1. Upload CSV
2. System shows preview of parsed transactions
3. Auto-match against existing uncleared payments and AP payments (match by amount + date ±3 days)
4. Accountant reviews matches, confirms or manually matches remainder
5. Any unmatched items can be posted as new transactions directly from import
6. Matched items marked as cleared

### Bank reconciliation process
1. Open reconciliation for account + period
2. Enter statement ending balance
3. Review all transactions — check off cleared items
4. System calculates:
   ```
   Adjusted book balance = Book balance + deposits in transit - outstanding checks ± bank errors
   Difference = Adjusted book balance - Statement ending balance
   ```
5. Difference must = $0.00 to mark complete
6. If not zero: look for unrecorded bank charges, unmatched transactions
7. On complete: lock all cleared transactions for this reconciliation, generate PDF report

### NSF / returned payments
When a customer payment is returned NSF:
1. Mark payment as `returned` in FleetForge billing module
2. System auto-generates journal entry:
   ```
   DR  1030  Accounts Receivable        [original payment amount]
   DR  6170  Bank Charges               [NSF fee, if any]
     CR  1010  Cash                     [total]
   ```
3. Invoice reopens to `overdue`
4. Customer risk score flagged
5. NSF fee optionally billed to customer

### Bank transfers
Transfer between CAD and USD accounts:
- Enter: from account, to account, CAD amount, USD amount, exchange rate
- System posts:
  ```
  DR  1020  Cash USD Account            [USD amount]
    CR  1010  Cash CAD Account          [CAD amount]
  ```
- If exchange rate produces a gain/loss:
  ```
  DR/CR  7030/7040  FX Gain/Loss        [difference]
  ```

---

## 8. FIXED ASSETS & DEPRECIATION

### Asset creation
When a new equipment unit is created in FleetForge, the accounting module prompts to create a corresponding fixed asset record. The link is stored in `acc_fixed_assets.equipment_unit_id`.

Non-fleet assets (office equipment, vehicles, leasehold improvements) are created directly in the accounting module.

### Depreciation calculation

**Straight-line (default):**
```
Monthly depreciation = (Acquisition cost - Salvage value) / (Useful life years × 12)
```

**Declining balance (CRA CCA):**
```
Year 1: Cost × (CCA rate / 2)     [half-year rule]
Year 2+: Opening UCC × CCA rate
```

**Units of production:**
```
Monthly depreciation = (Cost - Salvage) × (Units used this period / Total expected units)
For fleet: units = miles driven (from mileage_logs table)
```

### Monthly depreciation run
1. Accountant clicks "Run Depreciation" for the current period
2. System calculates depreciation for every active asset using its method
3. Preview screen: shows each asset, opening NBV, depreciation amount, closing NBV
4. Accountant reviews, confirms
5. System posts one consolidated journal entry:
   ```
   DR  5010  Depreciation — Fleet        [total fleet depreciation]
   DR  6190  Depreciation — Non-Fleet    [total other depreciation]
     CR  1220  Accum. Depr. Fleet        [fleet accumulated]
     CR  1260  Accum. Depr. Other        [other accumulated]
   ```
6. Updates `acc_fixed_assets.accumulated_depreciation` and `net_book_value` for each asset
7. Marks run as posted — cannot be re-run for same period (reverse first)

### Asset disposal
When a FleetForge unit is set to `decommissioned`, the accounting module prompts to record the disposal.

Disposal journal entry (example — asset sold):
```
DR  Cash / AR                      [proceeds]
DR  1220  Accumulated Depreciation [total accumulated to date]
  CR  1210  Fleet Equipment Cost   [original acquisition cost]
  CR/DR  7010/7020  Gain/Loss      [proceeds - NBV]
```

### Capital expenditure review
When a maintenance work order is completed with total cost > configurable threshold (default $2,500 CAD):
- Flag appears in Fixed Assets: "Work order WO-2025-00047 may qualify for capitalization ($4,200)"
- Accountant reviews: capitalize or expense
- If capitalize: adds to asset cost, recalculates depreciation schedule from that month forward

---

## 9. TAX MANAGEMENT

### How tax flows
Every invoice posted to GL:
- GST/HST collected → CR 2030 (system tracks which invoices make up this balance)
- PST collected → CR 2040

Every vendor bill posted with tax:
- GST/HST paid → DR 1050 (input tax credit)

### Filing period calculation
At end of filing period, system calculates:
```
Line 101: Total sales (sum of all revenue)
Line 105: GST/HST collected
Line 108: Input tax credits (GST/HST paid on expenses)
Line 109: Net tax owing = Line 105 - Line 108
```

Report shows every transaction making up each line — full drill-down.

### Remittance
Record CRA remittance payment:
```
DR  2030  GST/HST Payable            [amount remitted]
  CR  1010  Cash                     [amount remitted]
```

Mark filing period as remitted. Locks the transactions in that period.

### Tax codes per transaction line
| Code | Meaning |
|------|---------|
| T | Taxable (full rate) |
| Z | Zero-rated |
| E | Exempt |
| O | Out of scope |
| ITC | Input tax credit (on purchases) |

---

## 10. FINANCIAL STATEMENTS & REPORTS

All statements auto-generated from the GL. No manual assembly.

### Profit & Loss
```
Revenue
  Chassis Rental Revenue              $XXX,XXX
  Dry Van Rental Revenue              $XXX,XXX
  [all revenue accounts]
  ─────────────────────────────────
  Total Revenue                       $XXX,XXX

Cost of Revenue
  Depreciation — Fleet                $XX,XXX
  [all COGS accounts]
  ─────────────────────────────────
  Total Cost of Revenue               $XX,XXX

  Gross Profit                        $XXX,XXX
  Gross Margin %                      XX.X%

Operating Expenses
  [all operating expense accounts]
  ─────────────────────────────────
  Total Operating Expenses            $XX,XXX

  Operating Income (EBIT)             $XXX,XXX

Other Income / Expense
  [FX, gain/loss, interest]
  ─────────────────────────────────

  Net Income Before Tax               $XXX,XXX
```

Columns available: current period, prior period, variance ($), variance (%), YTD, prior YTD, budget, budget variance.

### Balance Sheet
```
Assets
  Current Assets
    Cash — Operating Account          $XXX,XXX
    Cash — USD Account                $XX,XXX
    Accounts Receivable               $XXX,XXX
    Less: Allowance for Doubtful Accts ($X,XXX)
    [...]
    Total Current Assets              $XXX,XXX

  Fixed Assets
    Fleet Equipment                   $X,XXX,XXX
    Less: Accumulated Depreciation    ($XXX,XXX)
    Net Fleet Equipment               $XXX,XXX
    [...]
    Total Fixed Assets                $XXX,XXX

  Total Assets                        $X,XXX,XXX

Liabilities
  [...]
  Total Liabilities                   $XXX,XXX

Equity
  [...]
  Total Equity                        $XXX,XXX

  Total Liabilities + Equity          $X,XXX,XXX  ← must equal Total Assets
```

Comparative: current date vs prior year same date. Difference flagged if Assets ≠ L+E.

### Cash Flow Statement (Indirect method)
```
Operating Activities
  Net Income                          $XXX,XXX
  Adjustments:
    Depreciation                      $XX,XXX
    Change in AR                      ($XX,XXX)
    Change in AP                      $X,XXX
    [other working capital changes]
  Net Cash from Operations            $XXX,XXX

Investing Activities
  Purchase of equipment               ($XXX,XXX)
  Proceeds from asset sales           $XX,XXX
  Net Cash from Investing             ($XXX,XXX)

Financing Activities
  Loan repayments                     ($XX,XXX)
  Owner drawings                      ($XX,XXX)
  Net Cash from Financing             ($XX,XXX)

  Net Change in Cash                  $XX,XXX
  Opening Cash Balance                $XXX,XXX
  Closing Cash Balance                $XXX,XXX
```

### Trial Balance
Every account, debit balance, credit balance, as of any date.
Totals: Debits = Credits (if not, something is wrong — alert shown).

### AR Aging Summary & Detail
Summary: total per aging bucket per customer.
Detail: every open invoice per customer with days outstanding, bucket, amount.

### AP Aging Summary & Detail
Same structure for vendor bills.

### All statements:
- Date range selector with presets (This Month, Last Month, This Quarter, YTD, Last Year, etc.)
- Print: hides sidebar/topbar, shows company header, page numbers, "Prepared [date]"
- Export: PDF (formatted, mPDF) + CSV (raw numbers, Excel-compatible)
- Drill-down: click any line amount → see transactions making up that total

---

## 11. BUDGETING

### Budget setup
- Create budget for a year: enter monthly amounts per account
- Shortcut: "Spread annual total evenly" button fills 12 equal months
- Shortcut: "Copy last year actuals" button pulls prior year GL amounts as starting point
- Multiple versions: base, conservative, optimistic (only one can be `is_active`)

### Budget vs actual reporting
Available on P&L: add "Budget" and "Budget Variance" columns.
Any account more than 10% over budget flagged in orange, 20%+ in red.

### Re-forecast
Each month, update remaining months based on current trends.
Re-forecast = Actual YTD + Updated remaining months budget.
Prior versions preserved for comparison.

---

## 12. MULTI-CURRENCY ACCOUNTING

### Functional currency: CAD
All reporting in CAD. USD transactions translated at the exchange rate frozen at transaction date.

### USD AR revaluation (end of period)
At period-end, USD-denominated invoices still open are revalued to the current exchange rate:
```
USD AR balance × (period-end rate - original invoice rate) = Unrealized FX gain/loss
DR/CR  1030  Accounts Receivable
  CR/DR  7030/7040  FX Gain/Loss
```
Reversed at start of next period (auto-reversing entry).

### Realized FX gain/loss
When a USD invoice is paid and the payment exchange rate differs from the invoice rate:
```
Example: Invoice $1,000 USD at 1.32 = $1,320 CAD booked
         Payment received at 1.35 = $1,350 CAD
         Realized gain: $30 CAD
DR  1010  Cash                      $1,350
  CR  1030  AR                      $1,320
  CR  7030  FX Gain                 $30
```
Posted automatically when payment is recorded against a USD invoice.

---

## 13. AUDIT & COMPLIANCE

### What gets logged (in existing FleetForge `audit_log` table)

Every accounting action logged with `module = 'accounting'`:

| Action | Logged detail |
|--------|--------------|
| Journal entry posted | entry_number, total debit, posted_by |
| Journal entry reversed | original entry_number, reversal entry_number |
| Period closed | period name, closed_by |
| Period locked | period name, locked_by |
| Bill created/approved | bill_number, vendor, amount |
| Bill voided | bill_number, void_reason |
| AP payment made | payment_number, vendor, amount, bank account |
| Depreciation run posted | period, total depreciation, asset count |
| Asset disposed | asset_number, proceeds, gain/loss |
| Bank reconciliation completed | account, period, statement balance |
| Tax period filed | tax_type, period, net tax |
| Bad debt written off | invoice_number, customer, amount |
| Budget created/updated | budget name, year |
| Year-end close | year, completed_by |

### Document attachment
Every bill, journal entry, bank transaction, asset record can have documents attached via `acc_documents`. Files stored at `storage/uploads/accounting/{entity_type}/{entity_id}/`. Served through the existing `api/v1/documents/serve.php` handler (auth + permission check).

### Year-end checklist items (seeded)
```
1.  All bank accounts reconciled for December
2.  All December invoices posted (check for unposted drafts)
3.  All vendor bills entered and approved
4.  Depreciation run completed for December
5.  Prepaid expense amortization posted
6.  Accrued liabilities reviewed and posted
7.  Allowance for doubtful accounts updated
8.  USD AR revaluation posted
9.  Intercompany transactions reconciled (if applicable)
10. GST/HST return filed for final period
11. PST return filed (if applicable)
12. All periods for the year closed
13. Trial balance reviewed — debits equal credits
14. Financial statements reviewed by owner/accountant
15. Retained earnings journal entry posted
16. All year periods locked
17. New fiscal year periods created
```

---

## 14. API CONVENTIONS (ACCOUNTING)

Same conventions as core FleetForge API (Section 11 of main spec).

**Base path:** `api/v1/accounting/`

**Bootstrap:** Every accounting API file starts with:
```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_auth_api();
require_permission('accounting', 'view'); // or 'create', 'edit' as appropriate
```

### All accounting endpoints

```
api/v1/accounting/
├── periods/
│   ├── index.php           GET    List all periods with status
│   ├── show.php            GET    Single period + GL summary
│   ├── close.php           POST   Close a period
│   ├── lock.php            POST   Lock a period (Super Admin)
│   └── year_end.php        POST   Run year-end close
│
├── accounts/
│   ├── index.php           GET    Full chart of accounts (hierarchical)
│   ├── show.php            GET    Single account + balance + transactions
│   ├── create.php          POST   Create new account
│   ├── update.php          POST   Update account
│   ├── deactivate.php      POST   Deactivate account (blocks if balance)
│   └── import.php          POST   Import COA from CSV
│
├── journal_entries/
│   ├── index.php           GET    List entries (filters: period, type, status)
│   ├── show.php            GET    Entry + all lines
│   ├── create.php          POST   Create draft entry
│   ├── post.php            POST   Post a draft entry
│   ├── reverse.php         POST   Reverse a posted entry
│   └── recurring/
│       ├── index.php       GET
│       ├── create.php      POST
│       ├── update.php      POST
│       └── delete.php      POST
│
├── gl/
│   ├── account_ledger.php  GET    All transactions for one account
│   ├── trial_balance.php   GET    Full trial balance at date
│   └── reconcile_check.php POST   Check AR/AP subledger vs GL
│
├── ar/
│   ├── aging.php           GET    AR aging summary + detail
│   ├── statement.php       GET    Customer statement PDF
│   ├── collection_notes/   CRUD
│   ├── promise_to_pay/     CRUD
│   ├── dunning_letter.php  POST   Generate + send dunning letter
│   ├── writeoff.php        POST   Write off invoice as bad debt
│   ├── writeoff_recover.php POST  Recover a written-off amount
│   └── deposits/           CRUD
│
├── ap/
│   ├── bills/              CRUD + void
│   ├── payments/           CRUD + void
│   ├── vendor_credits/     CRUD + apply
│   ├── aging.php           GET    AP aging
│   └── cash_requirements.php GET  Next 7/14/30 day outflows
│
├── bank/
│   ├── accounts/           CRUD
│   ├── transactions/       CRUD + import
│   ├── reconciliations/    CRUD + complete + lock
│   ├── transfer.php        POST   Interbank transfer
│   └── nsf.php             POST   Mark payment as NSF
│
├── assets/
│   ├── index.php           GET    Asset register
│   ├── show.php            GET    Asset detail + depreciation schedule
│   ├── create.php          POST
│   ├── update.php          POST
│   ├── depreciation_run.php POST  Preview or post depreciation run
│   ├── disposal.php        POST   Record asset disposal
│   ├── impairment.php      POST   Record impairment
│   └── capex_review.php    GET    Work orders flagged for capitalization
│
├── tax/
│   ├── periods/            CRUD
│   ├── calculate.php       POST   Calculate GST/HST for a period
│   ├── remittance.php      POST   Record CRA remittance
│   └── summary.php         GET    Tax summary for filing
│
├── reports/
│   ├── profit_loss.php     GET
│   ├── balance_sheet.php   GET
│   ├── cash_flow.php       GET
│   ├── trial_balance.php   GET
│   ├── ar_aging.php        GET
│   ├── ap_aging.php        GET
│   ├── asset_schedule.php  GET    Fixed asset depreciation schedule
│   ├── budget_variance.php GET
│   └── configurations/     CRUD   Saved report configs
│
├── budget/
│   ├── index.php           GET
│   ├── create.php          POST
│   ├── update.php          POST   (monthly line values)
│   └── copy_actuals.php    POST   Seed budget from prior year actuals
│
└── fx/
    ├── revaluations/       CRUD
    └── revalue.php         POST   Run period-end FX revaluation
```

---

## 15. MODULE PAGES & UI

### Navigation
Accounting module appears in the sidebar under its own section (permission-gated to accountant role and above):

```
ACCOUNTING
  ├── Dashboard
  ├── Chart of Accounts
  ├── Journal Entries
  ├── General Ledger
  ├── Accounts Receivable
  │     ├── AR Aging
  │     ├── Customer Statements
  │     ├── Collections
  │     └── Customer Deposits
  ├── Accounts Payable
  │     ├── Bills
  │     ├── Payments
  │     └── Vendor Credits
  ├── Bank Accounts
  │     ├── Transactions
  │     └── Reconciliation
  ├── Fixed Assets
  │     ├── Asset Register
  │     └── Depreciation
  ├── Tax
  │     ├── GST/HST Filing
  │     └── Remittances
  ├── Reports
  │     ├── Profit & Loss
  │     ├── Balance Sheet
  │     ├── Cash Flow
  │     └── Trial Balance
  ├── Budget
  └── Settings (Accounting)
```

### Accounting Dashboard
Top row — 6 KPI tiles:
- Cash Balance (total across all bank accounts in CAD)
- Accounts Receivable (total open invoices)
- Accounts Payable (total open bills)
- Net Income MTD
- Overdue AR (30+ days)
- Bills Due This Week

Sections below:
- Recent journal entries (last 10, with post button for drafts)
- AP bills due in next 14 days
- Open bank reconciliations (which accounts not yet reconciled for current period)
- Period status bar (all months of current year — green=closed, yellow=open, gray=not started)
- Alerts: AR/AP subledger out of sync, depreciation not run for current period, tax filing due within 7 days

### Chart of Accounts page
- Hierarchical tree view — expand/collapse parent accounts
- Each row: code, name, type, balance (current period), normal balance indicator
- Balance shown in red if on wrong side of normal balance (e.g. negative AR = customer overpaid)
- Click account → account ledger (all transactions, running balance)
- Create account, edit account, deactivate (greyed out if balance ≠ 0)
- Import from CSV button

### Journal Entries list
- Tabs: All | Drafts | Posted | Reversed
- Filters: period, entry type, date range, search (entry #, description, reference)
- Table: entry #, date, description, reference, debit total, status, posted by
- Click row → entry detail (all lines, source document link)
- "New Journal Entry" button → creates draft
- Bulk post drafts

### Journal Entry create/edit form
- Entry date, description, reference
- Lines table: account (dropdown), description, debit, credit
- Add line button
- Debit/credit totals shown live — balance indicator (green = balanced, red = unbalanced)
- Post button disabled until balanced
- Save as Draft / Post buttons

### General Ledger page
- Account selector dropdown
- Date range selector
- Table: date, entry #, description, source, debit, credit, running balance
- Running balance colors: normal side = black, wrong side = red
- Export CSV
- Print (full ledger, no pagination)

### AP Bills list
- Tabs: All | Draft | Approved | Scheduled | Partially Paid | Paid | Overdue | Void
- KPI tiles: Total Outstanding AP, Due This Week, Overdue
- Filters: vendor, date range, status
- Table: bill #, vendor, bill date, due date, amount, balance, status
- Row click → bill detail
- "New Bill" button
- Bulk pay: select multiple bills → payment form

### AP Bill create/edit form
- Vendor dropdown (from vendors table)
- Bill date, due date (auto-calculates from vendor payment terms)
- Vendor's own invoice number field
- Link to work order (optional — dropdown of completed work orders without bills)
- Link to equipment unit (optional)
- Lines table: account, description, quantity, unit cost, amount, GST/PST/HST
- Totals: subtotal, tax breakdown, total
- Attachments: drag & drop bill scan
- Save as Draft / Approve buttons

### Bank Reconciliation page
- Select bank account + period
- Two columns: Book side | Bank statement side
- Book: all transactions in the period, check off cleared items
- Bank: enter statement ending balance
- Running totals update as items are cleared
- Difference widget: large, prominent — green $0.00 means balanced
- "Complete Reconciliation" button (disabled if difference ≠ $0.00)
- Print reconciliation report

### Fixed Assets register
- Table: asset #, name, class, acquisition date, cost, accumulated depreciation, NBV, status
- Filter: class, status, fully depreciated flag
- Click row → asset detail
- Asset detail: all fields, depreciation schedule table (every period, opening NBV, depreciation, closing NBV), disposal button, impairment button
- "Run Depreciation" button (period selector, preview then post)

### Financial reports
All have:
- Date range picker with presets
- Comparison column toggle (prior period, prior year, budget)
- Drill-down (click any dollar amount → transactions list)
- Print button (triggers window.print(), print CSS hides UI chrome)
- Export PDF button (mPDF)
- Export CSV button

---

## 16. AUTOMATIC JOURNAL ENTRY RULES

This section defines every automatic entry the system posts without manual intervention. Each rule maps a FleetForge event or accounting action to the exact journal entry created.

### Rule table

| Trigger | DR Account | CR Account | Amount |
|---------|-----------|-----------|--------|
| Invoice status → sent | 1030 AR | 4xxx Revenue (by type) + 2030 GST + 2040 PST | Invoice total |
| Payment received | 1010 Cash | 1030 AR | Payment amount |
| Invoice voided | Reversal of invoice entry | | |
| Credit note issued | 4xxx Revenue | 2060 Customer Credits Liability | Credit amount | -- [PASS-6:G2] posts to liability, not AR
| Bad debt write-off | 6160 Bad Debt Exp | 1030 AR | Invoice balance |
| Bad debt recovered | 1030 AR | 6160 Bad Debt Exp | Recovery amount |
| Bill approved | Expense accounts + 1050 GST ITC | 2010 AP | Bill total |
| AP payment made | 2010 AP | 1010 Cash | Payment amount |
| Bill voided | Reversal of bill entry | | |
| Vendor credit | 2010 AP | Expense account | Credit amount |
| Bank charge recorded | 6170 Bank Charges | 1010 Cash | Charge amount |
| NSF payment | 1030 AR + 6170 Bank Charges | 1010 Cash | Original + fee |
| Bank transfer CAD→USD | 1020 Cash USD | 1010 Cash CAD | At exchange rate |
| FX gain on transfer | 1010/1020 Cash | 7030 FX Gain | Rate difference |
| FX loss on transfer | 7040 FX Loss | 1010/1020 Cash | Rate difference |
| Depreciation run posted | 5010/6190 Depr Expense | 1220/1260 Accum Depr | Per asset calculation |
| Asset disposal — gain | 1010 Cash + 1220 Accum Depr | 1210 Equipment Cost + 7010 Gain | Per disposal calc |
| Asset disposal — loss | 1010 Cash + 1220 Accum Depr + 7020 Loss | 1210 Equipment Cost | Per disposal calc |
| Asset impairment | 7020 Impairment Loss | 1220 Accum Depr | Impairment amount |
| CRA GST remittance | 2030 GST Payable | 1010 Cash | Remittance amount |
| CRA PST remittance | 2040 PST Payable | 1010 Cash | Remittance amount |
| USD AR revaluation (gain) | 1030 AR | 7030 FX Gain | Unrealized gain |
| USD AR revaluation (loss) | 7040 FX Loss | 1030 AR | Unrealized loss |
| Realized FX gain on payment | 1010 Cash | 1030 AR + 7030 FX Gain | Per rate diff |
| Realized FX loss on payment | 1010 Cash + 7040 FX Loss | 1030 AR | Per rate diff |
| Year-end retained earnings | 3040 Current Year NI | 3020 Retained Earnings | Net income |
| Customer deposit received | 1010 Cash | 2050 Customer Deposits | Deposit amount |
| Deposit applied to invoice | 2050 Customer Deposits | 1030 AR | Applied amount |
| Deposit refunded | 2050 Customer Deposits | 1010 Cash | Refund amount |
| Payment refunded | 1030 AR | 1010 Cash | Refund amount | -- [PASS-6:G1]
| Credit note applied to invoice | 2060 Customer Credits Liability | 1030 AR | Applied amount | -- [PASS-6:G2]
| Overpayment credited to account | 1010 Cash [full] | 1030 AR [allocated] + 2060 Customer Advances [overpayment] | Per allocation | -- [PASS-6:G4]
| Account credit applied to invoice | 2060 Customer Advances | 1030 AR | Applied amount | -- [PASS-6:G4]
| Customer deposit forfeited | 2050 Customer Deposits | 4110 Other Revenue | Forfeited amount | -- [PASS-6:G5]

### Credit Note JE Timing [PASS-6:G2]
Credit note creation posts: DR Revenue / CR 2060 Customer Credits Liability (NOT AR directly).
Credit note APPLICATION posts: DR 2060 Customer Credits Liability / CR 1030 AR.
This keeps GL AR in sync with the sum of open invoice balances at all times.

### Mileage Credit Line Items in Invoice JE [PASS-6:G3]
For credit line items (is_credit = 1) on an invoice, the Revenue line is a DEBIT (reducing revenue), not a credit. The total AR debit equals invoice.total_amount. Revenue debits sum to the absolute value of negative line items.

### Entry generation rules
- Every auto-entry references `source_type` and `source_id` in `acc_journal_entries`
- If an auto-entry fails (e.g. no GL account mapped to a revenue type), system blocks the FleetForge action and shows an error: "Cannot complete — accounting configuration incomplete. Go to Accounting → Settings to map revenue accounts."
- All auto-entries post to the period matching the transaction date. If that period is closed, entry posts to earliest open period with a warning.

---

## 17. ACCOUNTING SETTINGS

Stored in the existing `settings` table with `group_name = 'accounting'`:

| Key | Default | Description |
|-----|---------|-------------|
| `accounting.enabled` | false | Enable the accounting module |
| `accounting.ar_account_id` | null | GL account ID for Accounts Receivable (1030) |
| `accounting.ap_account_id` | null | GL account ID for Accounts Payable (2010) |
| `accounting.default_cash_account_id` | null | Default cash account for payments |
| `accounting.gst_payable_account_id` | null | GL account for GST/HST collected |
| `accounting.pst_payable_account_id` | null | GL account for PST collected |
| `accounting.gst_receivable_account_id` | null | GL account for GST/HST input tax credits |
| `accounting.bad_debt_expense_account_id` | null | |
| `accounting.fx_gain_account_id` | null | |
| `accounting.fx_loss_account_id` | null | |
| `accounting.capex_threshold_cad` | 2500 | Work orders above this prompt capitalization review |
| `accounting.default_depreciation_method` | straight_line | Default for new assets |
| `accounting.default_useful_life_years` | 10 | Default for fleet assets |
| `accounting.default_salvage_pct` | 0.10 | Default salvage value as % of cost |
| `accounting.gst_filing_frequency` | quarterly | monthly, quarterly, annually |
| `accounting.pst_filing_frequency` | quarterly | |
| `accounting.revenue_account_map` | JSON | Maps FleetForge invoice line item types to GL revenue accounts |
| `accounting.expense_account_map` | JSON | Maps vendor types to default expense accounts |

### Revenue account mapping
Critical configuration — maps FleetForge invoice line item types to revenue GL accounts:
```json
{
  "base_rental_chassis": 4010,
  "base_rental_dry_van": 4020,
  "base_rental_reefer": 4030,
  "base_rental_flatbed": 4040,
  "base_rental_other": 4050,
  "mileage_precharge": 4060,
  "mileage_adjustment": 4060,
  "mileage_credit": 4060,
  "insurance": 4070,
  "warranty": 4080,
  "late_fee": 4090,
  "damage": 4100,
  "manual_adjustment": 4110,
  "other": 4110
}
```

---

## 18. CRON JOBS (ACCOUNTING)

Add to existing crontab:

```bash
# Accounting cron jobs
0  9  1 * *  php /var/www/fleetforge/cron/accounting_generate_periods.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  1  * * *  php /var/www/fleetforge/cron/accounting_auto_reverse.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  7  1 * *  php /var/www/fleetforge/cron/accounting_recurring_entries.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  8  * * *  php /var/www/fleetforge/cron/accounting_collection_alerts.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  8  * * *  php /var/www/fleetforge/cron/accounting_promise_to_pay_check.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  8  * * *  php /var/www/fleetforge/cron/accounting_tax_filing_reminders.php >> /var/www/fleetforge/logs/cron.log 2>&1
```

| Cron | Schedule | Purpose |
|------|----------|---------|
| `accounting_generate_periods.php` | 1st of month, 9AM | Create new month period if it doesn't exist |
| `accounting_auto_reverse.php` | Nightly 1AM | Post auto-reversals due today |
| `accounting_recurring_entries.php` | 1st of month, 7AM | Post or draft recurring entries |
| `accounting_collection_alerts.php` | Daily 8AM | Check for broken promises to pay, escalate overdue accounts |
| `accounting_promise_to_pay_check.php` | Daily 8AM | Alert on broken promises |
| `accounting_tax_filing_reminders.php` | Daily 8AM | Alert if GST/PST filing due within 7 days |

---

## 19. BUILD ORDER

**Phase 18 — Accounting Foundation (Sessions 29–30)**
- All 33 accounting tables + seeds (default COA, default periods for current year)
- Accounting settings page + revenue account mapping
- Chart of accounts page (create, edit, deactivate, hierarchical view)
- Accounting periods list + close/lock functions
- Basic journal entry create, post, reverse

**Phase 19 — GL & AR Accounting Layer (Sessions 31–32)**
- General ledger account ledger view
- Trial balance
- Auto-entry posting for FleetForge invoice/payment events (the bridge)
- AR aging page
- Customer statements
- Collections (notes, promise to pay, dunning letters)
- Bad debt write-off + recovery
- Customer deposits

**Phase 20 — Accounts Payable (Session 33)**
- Bill list, create, edit, void
- AP payment recording + allocations
- Vendor credits
- AP aging
- Cash requirements report

**Phase 21 — Bank Management (Session 34)**
- Bank accounts setup
- Manual transaction recording
- Bank statement CSV import
- Bank reconciliation workflow
- NSF handling, bank transfers

**Phase 22 — Fixed Assets (Session 35)**
- Asset register (create, edit, view)
- Depreciation run (preview + post, all three methods)
- Asset disposal
- Asset impairment
- CapEx review workflow

**Phase 23 — Tax Management (Session 36)**
- GST/HST filing period management + calculation
- PST filing
- CRA remittance recording
- Tax summary reports

**Phase 24 — Financial Reports & Budgeting (Session 37)**
- P&L with drill-down
- Balance Sheet with drill-down
- Cash Flow statement
- Budget create + budget vs actual
- Saved report configurations

**Phase 25 — Polish & Integration (Session 38)**
- Accounting dashboard with all KPIs
- Year-end checklist + close workflow
- FX revaluation
- Document attachments on all accounting records
- Accounting audit trail in main audit log
- All empty states, error states, print CSS for accounting pages
- AR/AP subledger reconciliation check + alerts
- All cron jobs

**Phase 26 — QuickBooks Online Sync (Session 39 — placeholder)**
- `lib/Integrations/QuickBooksClient.php`
- OAuth 2.0 flow + token storage
- One-way push: customers, invoices, payments, credit memos
- `acc_qbo_sync_log` table (entity_type, ff_entity_id, qbo_entity_id, qbo_sync_token, last_synced_at, status, error_message)
- Settings: QBO Client ID, Secret, Realm ID, OAuth tokens
- Manual re-sync button per entity
- Sync error log + retry

---

---

## CHANGELOG — v1.2 FINAL
- [BUILD-READINESS] Depends-on version updated to v2.5
- [BUILD-READINESS] TOC section 2 heading corrected: 34 tables (was still 38 in TOC)
- [BUILD-READINESS] Credit note JE body text corrected: CR goes to 2060 Customer Credits Liability, NOT directly to 1030 AR (consistent with PASS-6:G2 rule added in v1.1)
- [BUILD-READINESS] Credit note entries in bridge table (Section 1) and rule table (Section 16) updated to match
- [BUILD-READINESS] Total platform table count corrected: 94 (was 93, missing schema_migrations)
- [BUILD-READINESS] `dec` column SQL fixed: comment no longer swallows NOT NULL DEFAULT; `annual_total` GENERATED references `dec` with backticks

## CHANGELOG — v1.1
- [PASS-1:M1] Section 2 heading corrected: 34 tables, not 38
- [PASS-1:M2] `dec` column backtick-quoted in spec SQL blocks
- [PASS-1:H7] Bank table creation order note added (master SQL governs)
- [PASS-6:G1] Payment refunded JE rule added
- [PASS-6:G2] Credit note JE timing: posts at application, not creation; uses liability account
- [PASS-6:G3] Mileage credit line items: debit Revenue, not credit
- [PASS-6:G4] Overpayment → account credit JE rules added (2060 Customer Advances)
- [PASS-6:G5] Deposit forfeited JE rule added (DR 2050 / CR 4110)

*End of FleetForge Accounting Module Specification v1.2 FINAL*
*34 new tables (acc_ prefix, corrected [PASS-1:M1]) | 8 build phases (Sessions 29–38) | QBO sync Phase 26*
*Total platform tables after accounting module: 94 (59 core + 34 accounting + 1 utility)*
*Owner: Avi — Mainland Truck & Trailer Sales*
