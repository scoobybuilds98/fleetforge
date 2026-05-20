-- ============================================================
-- S-QBO-1 — QuickBooks OAuth scaffolding migration.
--
-- Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §4 (locked decisions),
--           §5.1-§5.5 (OAuth + credential management),
--           §24.1 (settings keys canonical map)
-- Progress: FLEETFORGE_QUICKBOOKS_PROGRESS.md §6 (Settings keys)
--
-- THREE THINGS, ATOMICALLY:
--
-- 1. ALTER TABLE settings — add `is_sensitive` column.
--    Pre-flight surfaced that the live schema has no `is_sensitive`
--    column. The QBO spec §5.2 + D-QBO-CORE-8 require it for OAuth
--    credential masking + audit-log redaction. The existing
--    `is_public` column is semantically distinct (portal-vs-admin
--    visibility), so we add a separate flag rather than overload it.
--    Decision locked as D-QBO-1-1.
--
-- 2. Backfill `is_sensitive=1` on the 6 existing credential-grade
--    rows discovered via the pre-flight grep
--    (key LIKE '%password%|%secret%|%token%|%api_key%|%pass%'
--     filtered to actual credential strings):
--      - ai.anthropic_api_key
--      - aws.secret_access_key
--      - email.smtp_pass
--      - gps.geotab_password
--      - gps.samsara_api_key
--      - notifications.smtp_pass
--    EXCLUDED (matched filter but not credentials):
--      - ai.daily_token_limit            (numeric limit)
--      - security.rate_limit.forgot_password_ip_*  (numeric rate config)
--      - company.bank_account            (currently empty string, used
--                                         as label not account number)
--
-- 3. Seed 18 quickbooks.* settings keys per the S-QBO-1 prompt
--    Part A spec. is_sensitive map is taken VERBATIM from the
--    session prompt (which matches FLEETFORGE_QUICKBOOKS_SPEC.md
--    §24.1 canonical table — the §5.2 heading is misleadingly broad
--    and will be fixed in a post-session docs commit). The 6 keys
--    flagged is_sensitive=1: client_id, client_secret, access_token,
--    refresh_token, webhook_verifier_token, AND realm_id is is_sensitive=0
--    per the prompt (operator-readable since it surfaces in support
--    diagnostics; not a credential).
--
--    Per D-CPA-5: quickbooks.sync_enabled defaults '0' — master
--    kill-switch stays OFF until S-QBO-30 production cutover.
--
-- Decisions locked (in PROGRESS.md DECISIONS table):
--   D-QBO-1-1 — settings.is_sensitive column added (TINYINT(1) NOT NULL
--     DEFAULT 0, positioned AFTER is_public). is_sensitive is
--     semantically distinct from is_public — is_public controls
--     portal-vs-admin visibility, is_sensitive controls UI masking
--     and audit-log redaction of credential-grade values.
--
-- @session  S-QBO-1
-- @date     2026-05-20
-- ============================================================

START TRANSACTION;

-- ── 1. ALTER settings — add is_sensitive column ───────────────────
ALTER TABLE `settings`
    ADD COLUMN `is_sensitive` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `is_public`;

-- ── 2. Backfill existing credential-grade rows ────────────────────
UPDATE `settings` SET `is_sensitive` = 1
 WHERE `key` IN (
   'ai.anthropic_api_key',
   'aws.secret_access_key',
   'email.smtp_pass',
   'gps.geotab_password',
   'gps.samsara_api_key',
   'notifications.smtp_pass'
 );

-- ── 3. Seed 18 quickbooks.* settings keys ─────────────────────────
-- Connection / OAuth
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`) VALUES
  ('quickbooks.environment',              'sandbox',      'string', 'quickbooks', 'QBO environment',                'Either sandbox or production. Determines API base URL and which credentials are used.', 0, 0),
  ('quickbooks.client_id',                '',             'string', 'quickbooks', 'QBO Client ID',                  'Intuit Developer Client ID for the active environment. Masked in UI to last 4 chars.', 0, 1),
  ('quickbooks.client_secret',            '',             'string', 'quickbooks', 'QBO Client Secret',              'Intuit Developer Client Secret for the active environment. Masked in UI to last 4 chars.', 0, 1),
  ('quickbooks.realm_id',                 '',             'string', 'quickbooks', 'QBO Realm ID',                   'QBO company file ID captured at OAuth callback. Stable for the life of the QBO file.', 0, 0),
  ('quickbooks.access_token',             '',             'string', 'quickbooks', 'QBO Access Token',               'OAuth bearer token; refreshes hourly. Masked in UI to last 4 chars.', 0, 1),
  ('quickbooks.refresh_token',            '',             'string', 'quickbooks', 'QBO Refresh Token',              'OAuth refresh token; rotated on every refresh; 101-day expiry. Masked in UI to last 4 chars.', 0, 1),
  ('quickbooks.access_token_expires_at',  NULL,           'string', 'quickbooks', 'QBO Access Token Expires At',    'UTC timestamp at which the current access token expires. NULL = never connected.', 0, 0),
  ('quickbooks.refresh_token_expires_at', NULL,           'string', 'quickbooks', 'QBO Refresh Token Expires At',   'UTC timestamp at which the current refresh token expires. Pinger cron alerts at T-14 days.', 0, 0),
  ('quickbooks.last_connected_at',        NULL,           'string', 'quickbooks', 'QBO Last Connected At',          'UTC timestamp of the most recent successful OAuth callback. NULL = never connected.', 0, 0),
  ('quickbooks.last_token_refresh_at',    NULL,           'string', 'quickbooks', 'QBO Last Token Refresh At',      'UTC timestamp of the most recent successful token refresh. Diagnostic.', 0, 0),
  ('quickbooks.connection_status',        'disconnected', 'string', 'quickbooks', 'QBO Connection Status',          'One of: connected | disconnected | expired | error. Drives Settings UI badge and connect/disconnect button visibility.', 0, 0),
  ('quickbooks.connection_error',         '',             'text',   'quickbooks', 'QBO Connection Error',           'Last error message captured when connection_status flipped to error. Cleared on successful reconnect.', 0, 0),
  ('quickbooks.webhook_verifier_token',   '',             'string', 'quickbooks', 'QBO Webhook Verifier Token',     'Intuit webhook signing secret used to verify inbound payment webhooks (HMAC-SHA256). Masked in UI to last 4 chars.', 0, 1),
  ('quickbooks.tax_override_code_id',     '',             'string', 'quickbooks', 'QBO Tax Override Code ID',       'QBO TaxCode entity ID for the "NON" override code used by the FF-computed-tax push pattern (D-QBO-CORE-6). Resolved during S-QBO-9 tax-code mapping.', 0, 0),
  ('quickbooks.sandbox_redirect_uri',     '',             'string', 'quickbooks', 'QBO Sandbox Redirect URI',       'Override redirect URI used during sandbox development (typically an ngrok tunnel). Empty in production — production uses https://mainlandrentals.com/fleetforge/oauth/qbo/callback.php.', 0, 0);

-- Master controls (all is_sensitive=0)
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`) VALUES
  ('quickbooks.sync_enabled',     '0', 'boolean', 'quickbooks', 'QBO Master Sync Kill-Switch', 'Per D-CPA-5: master kill-switch. Stays 0 until S-QBO-30 production cutover. While 0, no FF-side change pushes to QBO and no QBO-originated event is processed.', 0, 0),
  ('quickbooks.payments_enabled', '0', 'boolean', 'quickbooks', 'QBO Payments Enabled',        'Enables the "Pay Online" button in the customer portal once the QBO Payments hosted page is configured (S-QBO-15).',                                       0, 0),
  ('quickbooks.dry_run_mode',     '0', 'boolean', 'quickbooks', 'QBO Dry Run Mode',            'When 1, push operations are logged to acc_qbo_sync_log but no API call is made to QBO. Used during S-QBO-30 production cutover validation.',          0, 0);

COMMIT;
