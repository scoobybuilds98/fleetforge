-- ============================================================================
-- S-QBO-27 — Historical Backfill MACHINERY (Phase QBO-13) — DRY-RUN scaffold
-- ============================================================================
--
-- The one-time inbound migration that pulls Mainland's existing QBO history
-- into FleetForge (spec §16). This migration ships the MACHINERY ONLY:
-- run/checkpoint state + dry-run gating. The LIVE runs (phases 27.A–E) and the
-- H5/H6 AR-drift GL remediation require a real accountant-pre-seeded QBO
-- sandbox + a live OAuth connection — neither exists yet (realm=SMOKE-REALM,
-- sync_enabled='0'). Those are a live-verify follow-up (F29), the same
-- build-now / verify-at-cutover pattern as F16/F19/F28.
--
-- WHY a run-state table: the pull is checkpoint-based (spec §16.5) — every
-- batch of 100 entities commits independently + the last successful pull
-- timestamp per entity type is recorded so a failed run resumes from
-- MAX(pushed_at) per map (D-QBO-27-2). A run row tracks mode (dry_run|live),
-- phase, status, per-entity counts, checkpoints, AR-drift before/after, and
-- the remediation state machine.
--
-- SAFETY (D-QBO-27-3 + the locked scope decisions):
--   * `quickbooks.historical_pull.dry_run` defaults '1' — the orchestrator
--     refuses to write FF business rows or post remediation JEs while '1'.
--     Flipping to '0' is an explicit operator action gated behind the
--     super-admin force_full_resync permission AND a live connection.
--   * H5/H6 remediation is HARD-STOP-AND-REPORT (D-QBO-27-5): detection +
--     a proposed compensating-JE plan are computed, but nothing posts without
--     an explicit operator approval action. `remediation_status` tracks this.
--
-- TWO motions:
--   1. CREATE acc_qbo_historical_pull_runs (run/checkpoint/remediation state).
--   2. INSERT settings: historical_pull.dry_run='1' + historical_pull.batch_size='100'.
--
-- MIGRATE COUNT: 84 → 85.
--
-- @session  S-QBO-27
-- @phase    QBO-13 (historical backfill)
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §16 (historical backfill), §16.3 (H5/H6)
-- @decision D-QBO-27-1 (batch size 100), D-QBO-27-2 (resume via MAX(pushed_at)),
--           D-QBO-27-3 (dry-run gating; live writes gated), D-QBO-27-4 (H5
--           reconstruction = compensating DR-AR JE matching QBO amounts),
--           D-QBO-27-5 (H6 stop-gate: hard-stop-and-report, operator approves;
--           never auto-post), D-QBO-27-6 (AR verification = drift $0 ±$1),
--           D-QBO-27-7 (UI shares /quickbooks/manual_sync — operational sibling)

-- ─── Motion 1: run-state table ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_qbo_historical_pull_runs` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `realm_id`              VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'QBO realm the pull targets — scopes checkpoints (D-QBO-27-2).',
    `mode`                  ENUM('dry_run','live') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dry_run' COMMENT 'dry_run = no FF business-row writes, no JE posting (default; safe). live = real writes — gated behind dry_run=0 + live connection.',
    `phase`                 VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reference' COMMENT 'Coarse phase: reference | transactional | remediation | verify (maps to spec 27.A–E operational phases).',
    `status`                ENUM('pending','running','paused','completed','failed','stopped_gate') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'stopped_gate = the H5/H6 hard stop-gate fired (D-QBO-27-5).',
    `entity_counts`         JSON DEFAULT NULL COMMENT 'Per-entity tally: {"customer":{"pulled":N,"inserted":N,"updated":N,"skipped":N}, ...}.',
    `checkpoints`           JSON DEFAULT NULL COMMENT 'Per-entity resume timestamps: {"invoice":"2026-05-01 12:00:00", ...} — MAX(pushed_at) snapshots (D-QBO-27-2).',
    `ar_drift_before`       DECIMAL(15,2) DEFAULT NULL COMMENT 'AR subledger-vs-GL drift measured before remediation (spec §16.3 baseline; expected ~$17,064.62).',
    `ar_drift_after`        DECIMAL(15,2) DEFAULT NULL COMMENT 'AR drift after remediation; verification target $0.00 ±$1 (D-QBO-27-6).',
    `remediation_status`    ENUM('not_run','detected','reported','approved','posted','stopped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_run' COMMENT 'H5/H6 state machine. detected→reported = plan computed, awaiting operator. approved→posted only via explicit operator action (D-QBO-27-5). stopped = hard stop-gate.',
    `remediation_plan`      JSON DEFAULT NULL COMMENT 'Proposed compensating JEs: [{"case":"H5","invoice_id":N,"tag":"[A1-FIX-invoice-N]","dr_account":"1030","amount":"..."}, ...]. Computed, NOT posted, until approved.',
    `error_message`         TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Failure / stop-gate detail.',
    `started_by`            INT UNSIGNED DEFAULT NULL COMMENT 'users.id who launched the run.',
    `started_at`            DATETIME DEFAULT NULL,
    `finished_at`           DATETIME DEFAULT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_realm_status` (`realm_id`,`status`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_qbo_histpull_started_by` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-13 S-QBO-27: historical-backfill run + checkpoint + H5/H6 remediation state. Machinery-only ship (dry-run default); live runs + GL remediation gated to the accountant-seeded sandbox (F29).';

-- ─── Motion 2: settings ──────────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`) VALUES
    ('quickbooks.historical_pull.dry_run', '1', 'string', 'quickbooks',
     'Historical pull dry-run gate',
     '1 (default) = HistoricalPuller refuses FF business-row writes + JE posting; only reference-data mapping + drift detection/report run. 0 = live writes enabled (operator action; requires a live connection + super-admin). D-QBO-27-3.'),
    ('quickbooks.historical_pull.batch_size', '100', 'string', 'quickbooks',
     'Historical pull batch size',
     'Entities committed per checkpoint batch (spec §16.5; D-QBO-27-1). 100 default.');
