-- S-AI-WRITE-SWITCH: give the AI "propose changes" kill switch a place in
-- Settings → Intelligence → AI Core.
--
-- WHY: ai.write_enabled has gated every AI change proposal (plan_* tools in
-- lib/AI/Tools/FleetForgeTools.php + api/v1/ai/apply-change.php) since
-- S-AI-WRITE-1, but nothing ever seeded the row — so it was absent on prod
-- (settings_get default false = OFF) and had no screen. The AI Core card
-- renders every group 'ai' row with a non-NULL label, and the settings save
-- scope only includes labelled rows, so seeding the labelled row is all the
-- UI needs.
--
-- Ships OFF ('0') — turning it on is the operator's decision (F98).
-- Idempotent: ON DUPLICATE KEY UPDATE refreshes only the METADATA, never the
-- `value`, so re-running can't flip the operator's choice (and a dev row a
-- smoke created with a NULL label gets its label).

INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `updated_at`)
VALUES
  ('ai.write_enabled', '0', 'boolean', 'ai', 'AI can propose changes',
   'Lets the AI assistant propose edits and actions (a note, a unit or reservation status, sending or voiding an invoice, voiding a payment). Nothing changes until the person clicks Apply on the card. Off: the AI explains how to make the change instead.',
   0, NOW())
ON DUPLICATE KEY UPDATE
  `value_type`  = VALUES(`value_type`),
  `group_name`  = VALUES(`group_name`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);
