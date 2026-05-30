-- ============================================================================
-- S-QBO-25 — Drift Resolution Workflows (Phase QBO-12 / 2 of 3)
-- ============================================================================
--
-- Adds the per-event resolution state machine on top of the S-QBO-24
-- detection engine. Per spec §15.4 (per-event resolve/accept/suppress)
-- + §15.6 (bulk-accept slice; bulk force-resync/force-pull stay in
-- S-QBO-26's Manual Sync page).
--
-- D-QBO-25-1 (resolution_type column): the live acc_qbo_drift_events
--   table (created S-QBO-3, populated S-QBO-24) has NO `status` column.
--   Spec §15.4's "mark status='accepted'/'suppressed'" language predates
--   the as-built schema and was K-22-corrected in S-QBO-24 (D-QBO-24-2).
--   To distinguish accept + suppress + resolve as terminal states the
--   cron respects, add ENUM resolution_type AFTER resolved_at. State
--   machine: resolved_at IS NULL AND resolution_type IS NULL = OPEN;
--   resolved_at SET + 'resolved' = resolved (may re-open via a new
--   event if drift recurs); ='accepted' or ='suppressed' = terminal
--   (DriftChecker::recordDrift never re-creates an event whose
--   (entity_type, entity_id, category) is in a terminal state).
--   Backfill: rows with resolved_at NOT NULL → 'resolved' (they were
--   resolved under the prior binary model). Composes additively with
--   the existing resolved_at logic — no rewrite of S-QBO-4 endpoints.
-- D-QBO-25-2 (auto-resolve-on-parity): DriftChecker::runCheck tracks the
--   set of (entity_type, entity_id, category) keys detected THIS run.
--   At end of run, OPEN events with detection_source='drift_cron' NOT
--   in the detected set are auto-resolved (resolution_type='resolved',
--   resolution_note='auto-resolved: parity confirmed on <Y-m-d>').
--   Scope: drift_cron only — push_failure-sourced events resolve via
--   the operator's retry, not the parity cron. Skips accepted +
--   suppressed (terminal) per D-QBO-25-1.
-- D-QBO-25-3 (bulk scope): S-QBO-25 ships per-event
--   resolve/accept/suppress/reopen/resync + bulk-accept-by-category +
--   bulk-suppress-by-category (the §15.6 "mark all drifts of category X"
--   slice). S-QBO-26 (Manual Sync page) owns the bulk force-resync +
--   force-pull + SyncToken rewrite. The one sync action S-QBO-25 owns is
--   per-event "Resolve via FF re-sync" (re-enqueue a single failed entity
--   via its Enqueuer).
-- D-QBO-25-4 (reopen action): operator-undo for accept/suppress.
--   Clears resolved_at + resolution_type so the row re-enters the OPEN
--   pool; next parity check refreshes it if drift still present (and
--   auto-resolves if drift is gone — D-QBO-25-2). Not in spec §15.4 but
--   natural workflow ergonomics.
--
-- This migration: ALTER + backfill + index. Touches one column on one
-- table. The new column is NULLable so existing INSERTs (no
-- resolution_type) keep working without code change.
--
-- K-22 (spec vs repo):
--   - Spec §15.4 lines 2353-2354 still use `status='accepted'` /
--     `status='suppressed'` language; that prose is updated in this
--     session to reference resolution_type AS-BUILT.
--   - Spec §15.6 line 2380 says `resolution_notes` (plural); the
--     AS-BUILT column is `resolution_note` (singular). Minor field-name
--     drift in spec prose, corrected in this session.
--   - Spec §15 SHIPPED banner at line 2274 assigns BOTH §15.4 + §15.6
--     to S-QBO-25 collectively; the S-QBO-25 vs S-QBO-26 split is not
--     yet codified in §15.6 — this session codifies it.
--
-- @session  S-QBO-25
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §15.4 + §15.6
-- @decision D-QBO-25-1 (resolution_type ENUM column + state machine),
--           D-QBO-25-2 (auto-resolve-on-parity for drift_cron events),
--           D-QBO-25-3 (S-QBO-25/S-QBO-26 bulk-action scope split),
--           D-QBO-25-4 (reopen action for operator-undo)

ALTER TABLE `acc_qbo_drift_events`
    ADD COLUMN `resolution_type` ENUM('resolved','accepted','suppressed') NULL
        COMMENT 'S-QBO-25 resolution-state machine. NULL + resolved_at NULL = OPEN. resolved_at SET + ''resolved'' = resolved (may re-open via new event if drift recurs). ''accepted'' / ''suppressed'' = terminal — DriftChecker::recordDrift never re-flags these (per-event operator decision persists across cron runs).'
        AFTER `resolved_at`,
    ADD KEY `idx_resolution` (`resolution_type`);

-- Backfill: rows resolved under the prior binary model (resolved_at SET,
-- resolution_type column absent) are 'resolved' for state-machine
-- consistency. Idempotent — re-running this UPDATE is a no-op once the
-- column is populated.
UPDATE `acc_qbo_drift_events`
   SET `resolution_type` = 'resolved'
 WHERE `resolved_at` IS NOT NULL
   AND `resolution_type` IS NULL;
