-- ============================================================
-- S-BILLING-EXCEPTIONS — invoice_billing_exceptions
--
-- A batch run routinely comes out mixed: most leases bill cleanly and a
-- handful cannot (no rate configured, lease closed mid-period, already
-- billed elsewhere, an engine error). Those failures were reported in
-- the response and then LOST the moment the operator navigated away, so
-- the only way to find them again was to re-run the whole batch and read
-- the errors a second time. In practice that means they get forgotten
-- and the customer is never billed.
--
-- This table is the durable "look at these later" queue: generation
-- flags the leases it could not bill, and they stay flagged until
-- someone resolves them.
--
-- ONE ROW PER (lease, period) — enforced by uq_lease_period. Re-running
-- the same batch refreshes the reason rather than piling up duplicates,
-- and a lease that starts failing again after being resolved re-opens
-- the SAME row instead of leaving a stale 'resolved' record behind.
--
-- Deliberately NOT tied to invoice_batch_runs: an exception is about a
-- lease that has no invoice, so it must outlive any particular run and
-- be reachable when no approval workflow is in use. batch_run_id is a
-- nullable breadcrumb, not the owner.
-- ============================================================

CREATE TABLE IF NOT EXISTS invoice_billing_exceptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    lease_id     INT UNSIGNED NOT NULL,
    customer_id  INT UNSIGNED NULL,
    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,

    -- Why it could not be billed, verbatim from the generator.
    reason TEXT NOT NULL,

    source ENUM('batch_generate','batch_run','manual') NOT NULL DEFAULT 'batch_generate',
    batch_run_id INT UNSIGNED NULL COMMENT 'Breadcrumb only — exceptions outlive runs',

    status ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',

    -- Set when the flag is cleared. 'resolved' can be reached automatically
    -- (a later run billed the lease successfully) or by hand; 'ignored' is
    -- always a deliberate human call, hence the required note in the API.
    resolution_note TEXT NULL,
    resolved_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,

    flagged_count INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Times this lease/period has failed',
    last_flagged_at DATETIME NULL,

    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    UNIQUE KEY uq_lease_period (lease_id, period_start, period_end),
    KEY idx_status (status, deleted_at),
    KEY idx_customer (customer_id),
    CONSTRAINT fk_bex_lease FOREIGN KEY (lease_id)
        REFERENCES leases(id) ON DELETE CASCADE,
    CONSTRAINT fk_bex_resolved_by FOREIGN KEY (resolved_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
