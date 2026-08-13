-- ============================================================
-- S-BATCH-APPROVAL — invoice_batch_runs
--
-- A batch run is a FROZEN, reviewable proposal to bill a set of
-- leases for a period. A biller submits one; a manager who is not
-- doing the billing opens its own URL, reviews the exact figures,
-- and approves or rejects; only an approved run can be generated.
--
-- WHY the snapshot is stored rather than recomputed on view:
-- the whole point of an approval is that the approver signs off on
-- SPECIFIC numbers. Recomputing on each page load would mean the
-- figures could silently change between approval and generation
-- (a rate edit, an invoice created elsewhere for the same period),
-- so what was approved would not be what ships. `snapshot` is
-- therefore immutable once written — generation re-verifies against
-- live data and reports drift rather than mutating the snapshot.
--
-- Money is NOT summed into a scalar column: a run can span CAD and
-- USD leases, so totals are kept per-currency in `total_by_currency`
-- (D-R2-2 keeps money currency-tagged; a single DECIMAL total would
-- silently add USD to CAD).
-- ============================================================

CREATE TABLE IF NOT EXISTS invoice_batch_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Human-facing reference (BR-2026-000007). Not gap-free — unlike
    -- invoice_number this is an internal handle, so it is derived from
    -- the auto-increment id after insert rather than a locked counter.
    reference VARCHAR(40) NOT NULL,

    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,

    status ENUM('pending','approved','rejected','generated','cancelled')
        NOT NULL DEFAULT 'pending',

    -- The selection, so the run can be re-verified at generate time.
    lease_ids JSON NOT NULL,

    -- The frozen dry-run output (previews + totals) exactly as
    -- BatchPreviewService produced it. Immutable once written.
    snapshot JSON NOT NULL,

    -- Denormalised headline figures for list views without decoding
    -- the whole snapshot.
    invoice_count     INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count     INT UNSIGNED NOT NULL DEFAULT 0,
    total_by_currency JSON NULL,

    note          TEXT NULL COMMENT 'Submitter note to the approver',
    decision_note TEXT NULL COMMENT 'Approver note on approve/reject',

    submitted_by INT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    decided_by   INT UNSIGNED NULL,
    decided_at   DATETIME NULL,
    generated_by INT UNSIGNED NULL,
    generated_at DATETIME NULL,

    -- Invoice ids actually created when the run was generated, plus the
    -- per-lease outcome (created / skipped-because-now-billed / failed)
    -- so the run stays a complete audit record after the fact.
    generated_invoice_ids JSON NULL,
    generation_result     JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    UNIQUE KEY uq_batch_run_reference (reference),
    KEY idx_status (status, deleted_at),
    KEY idx_period (period_start, period_end),
    KEY idx_submitted_by (submitted_by),
    CONSTRAINT fk_batch_run_submitted_by FOREIGN KEY (submitted_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_batch_run_decided_by FOREIGN KEY (decided_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_batch_run_generated_by FOREIGN KEY (generated_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
