-- ════════════════════════════════════════════════════════════════════
-- S-SETTINGS-AUDIT-3 — Add 'manual_trigger' to audit_log.action enum
-- ════════════════════════════════════════════════════════════════════
--
-- BUG: api/v1/cron/trigger.php writes action='manual_trigger' when a
-- super_admin clicks "Run Now" on a cron job in Settings → System tab.
-- The enum did not include that value, so every manual trigger failed
-- with SQLSTATE[01000] Data truncated for column 'action'.
--
-- Discovered via tests/_smoke_settings_endpoints.php roundtrip audit
-- (12 endpoint failures surfaced — this was 5 of them, one per cron
-- job).
--
-- FIX: Append 'manual_trigger' to the enum. Safe: ENUM column additions
-- are non-destructive and don't require row rewrites in InnoDB when
-- added to the end of the list.
-- ════════════════════════════════════════════════════════════════════

ALTER TABLE audit_log
    MODIFY action ENUM(
        'create','update','delete','restore',
        'login','logout','export','status_change',
        'view','bulk_action',
        'payment_recorded','invoice_sent','invoice_voided','lease_closed',
        'cron','manual_trigger'
    ) NOT NULL;
