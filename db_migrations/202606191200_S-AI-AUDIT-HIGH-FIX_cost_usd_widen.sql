-- S-AI-AUDIT-HIGH-FIX: widen ai_query_log.cost_usd from decimal(8,6) (which caps
-- a single row at $99.999999) to decimal(12,6), so a future higher-priced model
-- or a coarser-grained batched cost row can't 1264-overflow under STRICT mode.
-- Lossless widening — no data is changed.

ALTER TABLE `ai_query_log`
  MODIFY COLUMN `cost_usd` decimal(12,6) DEFAULT NULL;
