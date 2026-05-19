-- S-ACCT-LESSOR-2 — Effective-Interest Amortization Engine + Newton-Raphson
-- Rate Solver (Phase D-2).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.3
-- Roadmap:  §11 row 21
--
-- 1. CREATE acc_lease_amortization_schedules — one row per (lease, period).
--    UNIQUE(lease_id, period_number) so a re-generate either no-ops
--    (when no posted rows exist) or trips the duplicate-key path
--    requiring an explicit DELETE-before-INSERT regeneration. Status
--    ENUM segregates 'scheduled' (built by the engine) from 'posted'
--    (LESSOR-3/4 will flip on JE post) and 'reversed' (operator-driven).
--    posted_je_id stays NULL until LESSOR-3 wires JE posting.
--
-- 2. INSERT IGNORE accounting.lessor_fallback_borrowing_rate setting
--    (default '0.0650' = 6.5% annual). Read by LeaseAmortizationService::
--    solveImplicitRate() when Newton-Raphson fails to converge in 100
--    iterations. Operator tunes to their actual incremental borrowing
--    rate after first capital lease ships through the bank.

START TRANSACTION;

-- ── 1. CREATE acc_lease_amortization_schedules ──────────────────────────
CREATE TABLE `acc_lease_amortization_schedules` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lease_id`               INT UNSIGNED NOT NULL,
    `period_number`          INT NOT NULL,
    `period_date`            DATE NOT NULL,
    `opening_net_investment` DECIMAL(15,2) NOT NULL,
    `cash_receipt`           DECIMAL(12,2) NOT NULL,
    `finance_income`         DECIMAL(12,2) NOT NULL,
    `principal_reduction`    DECIMAL(12,2) NOT NULL,
    `closing_net_investment` DECIMAL(15,2) NOT NULL,
    `posted_je_id`           INT UNSIGNED NULL,
    `status`                 ENUM('scheduled','posted','reversed')
                                COLLATE utf8mb4_unicode_ci
                                NOT NULL DEFAULT 'scheduled',
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_lease_period` (`lease_id`, `period_number`),
    KEY `idx_period_date` (`period_date`, `status`),
    KEY `idx_posted_je` (`posted_je_id`),
    CONSTRAINT `fk_las_lease` FOREIGN KEY (`lease_id`)
        REFERENCES `leases` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_las_je` FOREIGN KEY (`posted_je_id`)
        REFERENCES `acc_journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. INSERT IGNORE fallback borrowing rate setting ───────────────────
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
    ('accounting.lessor_fallback_borrowing_rate', '0.0650',
     'decimal', 'accounting',
     'Lessor Fallback Borrowing Rate',
     'Annual incremental borrowing rate used by the lease amortization Newton-Raphson solver when it fails to converge in 100 iterations. Decimal form (0.0650 = 6.5%). Operator tunes to actual cost of capital. S-ACCT-LESSOR-2 2026-05-19.',
     0);

COMMIT;
