-- ============================================================
-- S-AI-CREDIT-TILE: seed AI credit-balance settings keys
-- ============================================================
-- Adds two config keys backing the internal "Claude credit remaining"
-- estimate (baseline − spend-since-baseline):
--
--   ai.credit_balance        decimal string  — the Console balance the operator
--                                               last entered (USD). Empty = unset.
--   ai.credit_balance_as_of  datetime (UTC)  — when that balance was entered;
--                                               spend is summed from this instant.
--
-- Both seed EMPTY so the UI shows the "Set your Claude balance" CTA until the
-- operator enters a real number. value_type / group_name (NOT type/group — Trap 71).
-- label=NULL keeps them off the generic settings index (D196); they're rendered
-- explicitly in the AI settings card.
--
-- Idempotent: ON DUPLICATE KEY UPDATE touches only `description`, so re-applying
-- NEVER clobbers an operator-set balance/as-of value (`value` is left intact).
-- No schema change — data-only seed (settings rows are not mirrored into
-- FLEETFORGE_DATABASE_MASTER.sql, matching the S-BACKUP-1 dropbox.* convention).
--
-- Session: S-AI-CREDIT-TILE
-- ============================================================

START TRANSACTION;

INSERT INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `label`, `description`)
VALUES
  ('ai.credit_balance',       '', 'decimal', 'ai', 0, 0, NULL, 'Operator-entered Anthropic Console credit balance (USD). Baseline for the internal "credit remaining" estimate. Empty = not set.'),
  ('ai.credit_balance_as_of', '', 'string',  'ai', 0, 0, NULL, 'UTC datetime (Y-m-d H:i:s) the credit_balance was entered. Spend is summed from this instant. Empty = not set.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

COMMIT;
