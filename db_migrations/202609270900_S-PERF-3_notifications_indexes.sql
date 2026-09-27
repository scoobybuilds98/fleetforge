-- ════════════════════════════════════════════════════════════════════════════
-- S-PERF-3 (batch E) — notifications: replace three single-purpose indexes with
-- three composites. Index-only change: no column, data, or result changes.
-- Date: 2026-09-27. Findings: idx-notifications-intersect-75k,
-- poll-notif-unread-covering-index.
--
-- WHY: prod notifications holds ~75.9k rows (~47.5k live). No index covered
-- `deleted_at`, so every "live rows for user X" query (every query in the app
-- filters `deleted_at IS NULL`) was planned as an index_merge INTERSECT of a
-- user index with idx_deleted — which walks the WHOLE idx_deleted NULL range
-- (~47.5k entries) no matter whose rows are wanted. Measured on prod: the bell
-- badge COUNT (topbar on EVERY admin page, dashboard strip, 60 s attention
-- poll) ~20 ms for the managers with ~7k unread rows; the Updates list/total
-- ~34-75 ms; user 1 paid 18 ms per list query with ZERO live rows.
--
--   DROP idx_deleted (deleted_at)
--       The intersect partner. Its only sole-predicate reader is the one-off
--       scripts/notifications_switchover.php type summary, which stays fast
--       enough (full scan of a 76k-row table, operator-run once).
--   DROP idx_read (is_read)
--       1-bit selectivity. Only ever chosen as an intersect partner (the chat
--       notify() pending check: ~36 ms walking 113k entries). Every is_read
--       filter also filters user_id / portal_user_id / entity, all indexed.
--   idx_user_unread (user_id, is_read) → (user_id, is_read, deleted_at)
--       Makes the badge COUNT a covering `ref` ("Using index"): O(unread rows)
--       with no clustered lookups. Same leftmost prefix, so mark_all, clear-read
--       and the unread/read list filters keep using it.
--   ADD idx_user_live_recent (user_id, deleted_at, created_at)
--       The Updates feed / bell list / totals / WhatsApp summary / date-range
--       filters: `ref` on (user_id, deleted_at IS NULL) read newest-first by a
--       backward index scan — no intersect, no filesort.
--   ADD idx_entity (entity_type, entity_id)
--       Chat read-clear UPDATE + notify() pending check (Conversations.php) and
--       the tax-filing reminder cron idempotency probe (was a FULL table scan).
--
-- SHIP AS ONE ALTER. Partial variants were measured unsafe: extending
-- idx_user_unread while keeping idx_read/idx_deleted fixes the badge but leaves
-- the list/total queries on the intersect AND regresses the chat-read UPDATE
-- (optimizer switches to idx_user ∩ idx_read). Benchmarked on a prod-shaped
-- 75.9k-row replica across every notifications query shape in api/ lib/
-- includes/ app/ cron/ scripts/: no shape got slower.
--
-- FKs stay covered: notifications_ibfk_1 (user_id) by idx_user (and the new
-- idx_user_unread prefix); fk_notif_portal_user by idx_portal_user; rule_id by
-- its own key. No code names these indexes (no FORCE/USE INDEX anywhere).
--
-- ONLINE + FAIL-FAST: ALGORITHM=INPLACE, LOCK=NONE (0.2-0.3 s on 76k rows
-- locally; reads/writes continue). The ALTER still needs a brief exclusive
-- metadata lock at start/end. cron/backup_db.php (mysqldump, 0 */6 * * *)
-- holds a shared MDL on notifications inside its consistent-snapshot
-- transaction; an ALTER queued behind it would block EVERY notifications query
-- (= every admin page via the topbar badge) until the dump finished. So:
--   * lock_wait_timeout = 5 s — if the MDL isn't granted fast, the ALTER fails
--     (mysql exits non-zero, the runner does NOT record the file) and nothing
--     changed; just re-run `php bin/migrate.php --apply` a minute later.
--   * OPERATOR: run it away from :00 of hours 00/06/12/18 (server time).
--   * Apply on BOTH deployments (Mainland + Northland).
--
-- IDEMPOTENT: every clause is INFORMATION_SCHEMA-guarded and the surviving
-- clauses are assembled into a single ALTER, so a re-run (or a DB where some of
-- this was already done by hand) only does what is still missing; a DB already
-- in the target shape runs a no-op SELECT.
--
-- Post-deploy check (prod, read-only):
--   EXPLAIN SELECT COUNT(*) FROM notifications
--    WHERE user_id = 28 AND is_read = 0 AND deleted_at IS NULL;
--   → type=ref, key=idx_user_unread, Extra "Using where; Using index" (no intersect)
-- ════════════════════════════════════════════════════════════════════════════

SET SESSION lock_wait_timeout = 5;
SET @schema = DATABASE();

-- Current column list of each index involved ('' when the index is absent).
SET @ix_deleted = (SELECT IFNULL(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), '') FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='notifications' AND INDEX_NAME='idx_deleted');
SET @ix_read = (SELECT IFNULL(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), '') FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='notifications' AND INDEX_NAME='idx_read');
SET @ix_unread = (SELECT IFNULL(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), '') FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='notifications' AND INDEX_NAME='idx_user_unread');
SET @ix_live = (SELECT IFNULL(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), '') FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='notifications' AND INDEX_NAME='idx_user_live_recent');
SET @ix_entity = (SELECT IFNULL(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), '') FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='notifications' AND INDEX_NAME='idx_entity');

-- Build the clause list; CONCAT_WS skips the NULL (already-done) clauses.
-- A target index that exists with the WRONG columns is dropped and re-added.
SET @clauses = CONCAT_WS(', ',
    IF(@ix_deleted <> '', 'DROP INDEX `idx_deleted`', NULL),
    IF(@ix_read    <> '', 'DROP INDEX `idx_read`',    NULL),
    IF(@ix_unread  NOT IN ('', 'user_id,is_read,deleted_at'), 'DROP INDEX `idx_user_unread`', NULL),
    IF(@ix_unread  <> 'user_id,is_read,deleted_at', 'ADD KEY `idx_user_unread` (`user_id`,`is_read`,`deleted_at`)', NULL),
    IF(@ix_live    NOT IN ('', 'user_id,deleted_at,created_at'), 'DROP INDEX `idx_user_live_recent`', NULL),
    IF(@ix_live    <> 'user_id,deleted_at,created_at', 'ADD KEY `idx_user_live_recent` (`user_id`,`deleted_at`,`created_at`)', NULL),
    IF(@ix_entity  NOT IN ('', 'entity_type,entity_id'), 'DROP INDEX `idx_entity`', NULL),
    IF(@ix_entity  <> 'entity_type,entity_id', 'ADD KEY `idx_entity` (`entity_type`,`entity_id`)', NULL)
);

SET @stmt = IF(@clauses = '',
    "SELECT 'notifications indexes already in S-PERF-3 shape' AS info",
    CONCAT('ALTER TABLE `notifications` ', @clauses, ', ALGORITHM=INPLACE, LOCK=NONE'));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
