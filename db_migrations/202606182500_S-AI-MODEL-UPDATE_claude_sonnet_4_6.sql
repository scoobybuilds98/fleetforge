-- S-AI-MODEL-UPDATE: Update ai.model setting from retired claude-sonnet-4-20250514
-- to the current claude-sonnet-4-6. The old model ID was causing every AI summary
-- and chat call to fail with a 400 API_ERROR from Anthropic.
-- Idempotent: only updates when the old value is present.

UPDATE settings
SET value = 'claude-sonnet-4-6'
WHERE `key` = 'ai.model'
  AND value = 'claude-sonnet-4-20250514';
