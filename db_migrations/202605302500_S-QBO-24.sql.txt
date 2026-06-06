-- ============================================================================
-- S-QBO-24 — Drift Detection Cron (Phase QBO-12 / 1 of 3)
-- ============================================================================
--
-- The daily safety-net cron that surfaces FF↔QBO divergence into
-- acc_qbo_drift_events (already created S-QBO-3). Per spec §15.
--
-- D-QBO-24-1 (hybrid comparison): DriftChecker runs a SNAPSHOT layer always
--   (amount_drift on pushed map rows whose FF entity changed since push +
--   push_failed on failed/failed_preflight map rows) AND a LIVE-QBO layer
--   only when connected + sync_enabled='1' (missing_in_ff / missing_in_qbo /
--   live amount_drift per spec §15.2). Pre-cutover the snapshot layer gives
--   real value (push_failed retry signal) with no API calls; the live layer
--   activates at S-QBO-30.
-- D-QBO-24-2 (drift_events schema): reuse the LIVE acc_qbo_drift_events shape
--   (entity_id / category / detection_source / ff_value / qbo_value /
--   drift_amount / resolved_at IS NULL = open) built in S-QBO-3 and consumed
--   by the drift.php dashboard + drift_list.php (S-QBO-4). Spec §15.3's
--   earlier sketch (ff_entity_id / drift_category / status) is corrected to
--   match the as-built schema in the same session. NO table ALTER.
-- D-QBO-24-3 (GL-balance deferred): the per-mapped-account FF-balance vs
--   live-QBO-balance check (spec §15.2 step 4 + §15.5 row) is a distinct
--   sub-system → S-QBO-24-GL-BALANCE-FOLLOWUP. v1 covers the 8 entity maps.
--
-- This migration: settings seed ONLY (no schema motion — drift_events
-- already exists). Seeds: master toggle + last-check timestamp + notify
-- threshold + 8 per-entity tolerances (spec §15.5 defaults).
--
-- K-22 (spec vs repo):
--   - Cron advisory lock name is `ff_qbo_drift_check` (spec §15.2), NOT the
--     `ff_cron_qbo_*` form used by qbo_bank_cdc — followed spec.
--   - Resolution workflows (§15.4) + manual reconciliation (§15.6) are
--     S-QBO-25 / S-QBO-26 scope, NOT S-QBO-24. v1 = detection + notification
--     + Run-now button only. The existing drift_resolve.php (S-QBO-4) already
--     handles single-event resolve; bulk/accept/suppress is S-QBO-25.
--
-- @session  S-QBO-24
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §15 (drift detection)
-- @decision D-QBO-24-1 (hybrid snapshot + live comparison),
--           D-QBO-24-2 (reuse live acc_qbo_drift_events; fix spec §15.3 sketch),
--           D-QBO-24-3 (GL-balance check deferred → follow-up),
--           D-QBO-24-4 (per-entity tolerance config per spec §15.5)

INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `description`) VALUES
    ('quickbooks.drift.enabled', '1', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 master toggle for the drift-detection cron qbo_drift_check.php. 0 = cron no-ops (logs + exits).'),
    ('quickbooks.drift.last_check_at', '', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 timestamp of the last successful drift check run (Y-m-d H:i:s). Empty = never run. Stamped at whole-run level.'),
    ('quickbooks.drift.notify_threshold', '10.00', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.2 step 3: when per-entity aggregate amount drift exceeds this (CAD), dispatch a quickbooks notification to super_admin + accountant. Below = logged only.'),
    ('quickbooks.drift.tolerance.invoice', '0.05', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: invoice total drift below this (CAD) is logged but not flagged (sub-cent tax rounding).'),
    ('quickbooks.drift.tolerance.payment', '0.01', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: payment amount drift tolerance (CAD).'),
    ('quickbooks.drift.tolerance.bill', '0.05', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: bill total drift tolerance (CAD).'),
    ('quickbooks.drift.tolerance.bill_payment', '0.01', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: bill payment amount drift tolerance (CAD).'),
    ('quickbooks.drift.tolerance.credit_memo', '0.05', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: credit memo total drift tolerance (CAD).'),
    ('quickbooks.drift.tolerance.journal_entry', '0.01', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: journal entry balanced-total drift tolerance (CAD). JEs are immutable once posted so FF-side drift is structurally rare.'),
    ('quickbooks.drift.tolerance.customer', '0.00', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: customer is identity-only (no amount); 0.00 = any field divergence flagged (live layer).'),
    ('quickbooks.drift.tolerance.vendor', '0.00', 'string', 'quickbooks', 0, 0,
     'S-QBO-24 / spec §15.5: vendor is identity-only (no amount); 0.00 = any field divergence flagged (live layer).')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
