-- ============================================================
-- 202605040430_S-MILEAGE-1B_samsara_fixture_setting.sql
--
-- S-MILEAGE-1B — settings row for the new `samsara.fixture_mode`
-- toggle. When set to '1' (string, the value_type is 'boolean'),
-- SamsaraClient::getDistanceForPeriod skips the real HTTP call
-- and dispatches to lib/Samsara/FixtureProvider.php instead.
--
-- Default: '0' (off). Production must NEVER silently run in
-- fixture mode — the row is explicit + visible in Settings UI.
--
-- Per Avi's D-G clarification (2026-05-04): all Samsara settings
-- now live under the `samsara.` prefix going forward (NOT
-- `gps.samsara_*`). The legacy `gps.samsara_api_key` /
-- `gps.samsara_org_id` rows remain in place — SamsaraClient's
-- constructor already prefers the new prefix and falls back to
-- the legacy keys (S-MILEAGE-1B / D-I), so production keeps
-- working without ops migration. Future cleanup session can
-- migrate the values when convenient.
--
-- Idempotent: INSERT IGNORE on the UNIQUE `key` constraint —
-- re-running this migration is a no-op once the row exists.
--
-- Author:   S-MILEAGE-1B
-- Date:     2026-05-04 (UTC)
-- Spec:     S-MILEAGE-1B brief — D-G fixture mode
-- ============================================================

INSERT IGNORE INTO settings (`key`, value, value_type, group_name, label, description, is_public)
VALUES (
    'samsara.fixture_mode',
    '0',
    'boolean',
    'samsara',
    'Samsara fixture mode',
    'When enabled, SamsaraClient::getDistanceForPeriod skips real HTTP calls and dispatches to lib/Samsara/FixtureProvider.php for hermetic testing. Production must keep this off. Default: off.',
    0
);
