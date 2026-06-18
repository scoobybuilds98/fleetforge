-- S-AI-WRITE-1: AI-driven write actions — pending change proposals.
--
-- The AI never mutates data directly. When the user asks for a change
-- ("set unit ABCD category to chassis"), the plan_* tools VALIDATE and
-- compute the exact diff, then persist a PENDING proposal here. The chat
-- reply renders a confirm card; only an explicit click on Apply hits
-- api/v1/ai/apply-change.php, which executes the change through the same
-- write path the manual UI uses, records a before/after diff for undo,
-- and flips status to 'applied'.
--
-- payload      JSON  — the validated change spec (targets, columns, old/new).
-- applied_diff JSON  — before/after captured AT APPLY TIME (for undo + audit).
-- expires_at         — proposals go stale after a window so an old card in a
--                      reopened tab can't apply against drifted data.
-- INFORMATION_SCHEMA guard: idempotent if re-run.

SET @schema = DATABASE();

SET @tbl_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'ai_pending_changes'
);

SET @stmt = IF(
    @tbl_exists = 0,
    'CREATE TABLE `ai_pending_changes` (
       `id`             int unsigned NOT NULL AUTO_INCREMENT,
       `user_id`        int unsigned NOT NULL COMMENT ''User who requested the change (proposer).'',
       `session_id`     int unsigned DEFAULT NULL COMMENT ''ai_chat_sessions.id the proposal came from.'',
       `change_type`    varchar(64)  NOT NULL COMMENT ''e.g. update_equipment, bulk_update_equipment.'',
       `entity_type`    varchar(64)  NOT NULL COMMENT ''e.g. equipment_unit.'',
       `summary`        varchar(500) NOT NULL COMMENT ''Human-readable one-line description of the change.'',
       `payload`        json         NOT NULL COMMENT ''Validated change spec: targets, columns, old/new values.'',
       `affected_count` int unsigned NOT NULL DEFAULT 1 COMMENT ''Number of rows this change will touch.'',
       `status`         enum(''pending'',''applied'',''undone'',''cancelled'',''expired'') NOT NULL DEFAULT ''pending'',
       `applied_diff`   json         DEFAULT NULL COMMENT ''before/after per row, captured at apply time for undo.'',
       `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       `expires_at`     datetime     DEFAULT NULL COMMENT ''After this, the proposal cannot be applied.'',
       `applied_at`     datetime     DEFAULT NULL,
       `applied_by`     int unsigned DEFAULT NULL COMMENT ''User who clicked Apply (re-checked for edit permission).'',
       PRIMARY KEY (`id`),
       KEY `idx_user_status` (`user_id`,`status`),
       KEY `idx_session` (`session_id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''ai_pending_changes already exists'' AS info'
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
