-- S-NORTHLAND-P0: add `company.short_name` — a space-constrained display name.
--
-- WHY: chat sender_display_name (api/v1/chat/messages/create.php,
-- api/v1/chat/customer/create.php) previously hardcoded the tenant literal
-- 'Mainland Truck & Trailer'. That value is PERSISTED into chat_messages at
-- insert time, not resolved at render, so a second deployment would have
-- stamped the wrong company's name permanently onto its own customer chats.
--
-- Tokenizing it to `company.name` alone would have been a visible regression:
-- company.name is the full legal name ('… Sales & Leasing'), which is too long
-- for a chat sender chip. Hence a dedicated short-name key.
--
-- Readers MUST fall back to company.name when this is empty:
--   settings_get('company.short_name') ?: settings_get('company.name')
-- so a deployment that never sets it still renders something correct.
--
-- Idempotent: ON DUPLICATE KEY UPDATE refreshes only METADATA, never `value`,
-- so re-running cannot clobber an operator's chosen short name.

INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `updated_at`)
VALUES
  ('company.short_name', '', 'string', 'company', 'Short Name',
   'Compact company name for space-constrained UI (e.g. the chat sender chip). Falls back to Company Name when blank.',
   1, NOW())
ON DUPLICATE KEY UPDATE
  `value_type`  = VALUES(`value_type`),
  `group_name`  = VALUES(`group_name`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `is_public`   = VALUES(`is_public`);

-- Backfill ONLY the deployment this literal actually belongs to.
--
-- This migration also runs on a fresh Northland database, so the backfill is
-- guarded on Mainland's exact company.name. On any other deployment the guard
-- does not match, the row stays empty, and the code falls back to that
-- deployment's own company.name. Do NOT relax this WHERE clause — an
-- unguarded UPDATE here is precisely how one tenant's identity leaks into
-- another's database.
UPDATE settings s
   JOIN settings c ON c.`key` = 'company.name'
   SET s.`value` = 'Mainland Truck & Trailer'
 WHERE s.`key`   = 'company.short_name'
   AND s.`value` = ''
   AND c.`value` = 'Mainland Truck & Trailer Sales & Leasing';
