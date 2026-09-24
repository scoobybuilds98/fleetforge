-- ============================================================
-- S-BILLING-MODULE — the monthly billing cycle as a first-class record
--
-- Billing (/billing) runs one calendar month from preparation to close:
-- readiness checks → period-end readings → generate → review → approve →
-- send → close. The invoices themselves are unchanged — a cycle's invoices
-- are DERIVED (lease invoices whose billing_period_start falls inside the
-- cycle's month), so every generation path (Billing's workbench, the monthly
-- cron, a lease's Generate Invoice, a lease close) lands in the right cycle
-- without the generator knowing cycles exist.
--
-- billing_cycles — ONE row per month (uq on period_start). Holds only what
--   cannot be derived: owner, target dates, readiness acknowledgements, the
--   close snapshot (frozen figures at close) and the reopen trail.
--
-- billing_cycle_readings — period-end odometer / engine-hours readings for
--   MANUAL-mileage and hourly leases, entered on the cycle's Readings sheet
--   before generating. Batch generation, the dry run and approved-run
--   generation pass them to InvoiceGenerator::createFromLease() exactly like
--   the single-invoice form does. Canonical km (odometer_km); entered_unit
--   records what the operator typed so the sheet redisplays it faithfully.
--
-- billing_cycle_reviews — per-invoice review sign-off inside a cycle
--   ('reviewed' = looked at and fine, 'query' = needs a second look).
--   Cascades with the cycle; an invoice deleted later just leaves a stale
--   row that the review list never joins to.
--
-- billing_holds — standing "do not bill" instructions for a lease or a
--   whole customer, from a date until released (or until ends_on). Held
--   leases are shown but not selected in the workbench, refused by batch /
--   approved-run generation and skipped by the monthly cron. A lease's own
--   Generate Invoice only warns — an operator billing one lease on purpose
--   is not overridden.
--
-- Settings: the batch-approval pair moves from Settings → General into
-- Billing → Settings (group_name 'billing_cycle'; keys unchanged so every
-- reader keeps working), plus the cycle schedule / review thresholds and the
-- billing_cycle_open scheduled-job toggle.
-- ============================================================

CREATE TABLE IF NOT EXISTS billing_cycles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL COMMENT 'BC-YYYY-MM',
    period_start DATE NOT NULL COMMENT 'First day of the billed calendar month',
    period_end DATE NOT NULL COMMENT 'Last day of the billed calendar month',
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    owner_user_id INT UNSIGNED DEFAULT NULL COMMENT 'Who runs this cycle; receives its reminders',
    bill_by_date DATE DEFAULT NULL COMMENT 'Target: drafts generated and reviewed by',
    send_by_date DATE DEFAULT NULL COMMENT 'Target: every invoice sent by',
    readiness_ack JSON DEFAULT NULL COMMENT 'Acknowledged readiness warnings {check_key:{by,at}}',
    readiness_checked_at DATETIME DEFAULT NULL,
    readiness_summary JSON DEFAULT NULL COMMENT 'Last readiness run {blocker,warning,info} counts',
    notes TEXT DEFAULT NULL,
    opened_by INT UNSIGNED DEFAULT NULL COMMENT 'NULL = opened by the scheduled job',
    closed_by INT UNSIGNED DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    close_note TEXT DEFAULT NULL,
    close_snapshot JSON DEFAULT NULL COMMENT 'Frozen cycle summary at close',
    reopened_by INT UNSIGNED DEFAULT NULL,
    reopened_at DATETIME DEFAULT NULL,
    reopen_reason TEXT DEFAULT NULL,
    last_nudged_at DATETIME DEFAULT NULL COMMENT 'Last overdue-step reminder from the scheduled job',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_cycle_period (period_start),
    UNIQUE KEY uq_billing_cycle_reference (reference),
    KEY idx_billing_cycle_status (status),
    CONSTRAINT fk_billing_cycle_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_cycle_opened_by FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_cycle_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_cycle_reopened_by FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_cycle_readings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT UNSIGNED NOT NULL,
    lease_id INT UNSIGNED NOT NULL,
    odometer_km DECIMAL(10,2) DEFAULT NULL COMMENT 'Period-end odometer, canonical km',
    entered_unit ENUM('km','miles') NOT NULL DEFAULT 'km' COMMENT 'Unit the operator typed the odometer in',
    engine_hours DECIMAL(10,2) DEFAULT NULL COMMENT 'Period-end engine/reefer hours',
    reading_date DATE DEFAULT NULL COMMENT 'Day the reading was taken',
    notes VARCHAR(500) DEFAULT NULL,
    entered_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cycle_reading_lease (cycle_id, lease_id),
    KEY idx_cycle_reading_lease (lease_id),
    CONSTRAINT fk_cycle_reading_cycle FOREIGN KEY (cycle_id) REFERENCES billing_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_cycle_reading_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    CONSTRAINT fk_cycle_reading_user FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_cycle_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    status ENUM('reviewed','query') NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME NOT NULL,
    UNIQUE KEY uq_cycle_review_invoice (cycle_id, invoice_id),
    KEY idx_cycle_review_invoice (invoice_id),
    CONSTRAINT fk_cycle_review_cycle FOREIGN KEY (cycle_id) REFERENCES billing_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_cycle_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_holds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope ENUM('lease','customer') NOT NULL,
    lease_id INT UNSIGNED DEFAULT NULL COMMENT 'Set when scope = lease',
    customer_id INT UNSIGNED NOT NULL COMMENT 'Always set (the lease customer for a lease hold)',
    reason VARCHAR(500) NOT NULL,
    starts_on DATE NOT NULL COMMENT 'Periods ending before this day are not held',
    ends_on DATE DEFAULT NULL COMMENT 'NULL = until released',
    released_at DATETIME DEFAULT NULL,
    released_by INT UNSIGNED DEFAULT NULL,
    release_note VARCHAR(500) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_billing_hold_lease (lease_id, released_at),
    KEY idx_billing_hold_customer (customer_id, released_at),
    CONSTRAINT fk_billing_hold_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_hold_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_hold_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_hold_released_by FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The approval pair leaves Settings → General: its home is now Billing →
-- Settings. Same keys, so batch_generate / batch_runs / the workbench read
-- them unchanged; only the group moves (General renders a fixed group list
-- that does not include 'billing_cycle', so they stop appearing there).
UPDATE settings SET group_name = 'billing_cycle'
 WHERE `key` IN ('invoices.approval_required', 'invoices.approval_allow_self');

INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`)
VALUES
  ('billing_cycle.mode', 'arrears', 'string', 'billing_cycle',
   'Which month a cycle bills',
   'arrears = on the open day, open the cycle for LAST month (bill what has happened). advance = open the cycle for THIS month (bill ahead).',
   0, 0),
  ('billing_cycle.open_day', '1', 'integer', 'billing_cycle',
   'Open the cycle on day',
   'Day of the month (1-28) the scheduled job opens the next cycle and tells the billing owner.',
   0, 0),
  ('billing_cycle.bill_by_days', '3', 'integer', 'billing_cycle',
   'Drafts reviewed within (days)',
   'Days after a cycle opens by which its drafts should be generated and reviewed.',
   0, 0),
  ('billing_cycle.send_by_days', '5', 'integer', 'billing_cycle',
   'Invoices sent within (days)',
   'Days after a cycle opens by which every invoice should be sent. After this the owner is reminded weekly while drafts remain.',
   0, 0),
  ('billing_cycle.variance_pct', '25', 'decimal', 'billing_cycle',
   'Flag a change larger than (%)',
   'Review flags an invoice whose total moved more than this percentage against the same lease last cycle...',
   0, 0),
  ('billing_cycle.variance_min_amount', '100', 'decimal', 'billing_cycle',
   '...and larger than ($)',
   '...and by more than this amount, so small leases do not flag on cents.',
   0, 0),
  ('billing_cycle.close_requires_review', '0', 'boolean', 'billing_cycle',
   'Closing requires every invoice reviewed',
   'When on, a cycle cannot close until every invoice in it is marked Reviewed.',
   0, 0),
  ('billing_cycle.owner_user_id', '', 'integer', 'billing_cycle',
   'Billing owner',
   'Default owner of new cycles; receives the cycle reminders. Blank = everyone who can see invoices.',
   0, 0),
  ('cron.billing_cycle_open_enabled', '1', 'boolean', 'cron',
   'Open billing cycles',
   'Opens the next monthly billing cycle on its open day and reminds the owner when a cycle is behind. Creates no invoices.',
   0, 0)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `value_type`  = VALUES(`value_type`),
  `group_name`  = VALUES(`group_name`);
