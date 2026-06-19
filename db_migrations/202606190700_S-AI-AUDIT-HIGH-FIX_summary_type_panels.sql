-- S-AI-AUDIT-HIGH-FIX: add the 4 detail-page summary panel types to the
-- ai_summaries.summary_type ENUM.
--
-- The streaming summary endpoint (api/v1/ai/summary-stream.php) accepts
-- invoice_analysis / reservation_summary / vendor_summary / payment_summary
-- (wired to the invoices/reservations/vendors/payments show pages) and calls
-- SummaryEngine::cacheSummary() with them — but those 4 values were NOT in the
-- ENUM, so every cache insert raised SQLSTATE 1265 "Data truncated" under STRICT
-- mode and was silently swallowed. The panels streamed fine but never cached, so
-- each open re-billed Claude tokens. (S-AI-AUDIT-HIGH-FIX MEDIUM finding.)
--
-- ENUM clamp trap (project_rate_method_enum_clamp_trap): a value outside the ENUM
-- is the bug; the fix is to make these 4 ENUM members. Guarded on the current
-- COLUMN_TYPE so re-apply is a no-op (idempotent).

SET @schema = DATABASE();

SET @needs = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'ai_summaries'
      AND COLUMN_NAME = 'summary_type'
      AND COLUMN_TYPE LIKE '%''vendor_summary''%'
);
SET @stmt = IF(
    @needs = 0,
    "ALTER TABLE ai_summaries MODIFY COLUMN `summary_type` enum('lease_summary','customer_insights','fleet_health','unit_analysis','payment_risk','forecast','anomaly','accounting_overview','pl_narrative','bs_narrative','cashflow_narrative','budget_variance','invoice_analysis','reservation_summary','vendor_summary','payment_summary') COLLATE utf8mb4_unicode_ci NOT NULL",
    "SELECT 'ai_summaries.summary_type already has the 4 panel types' AS info"
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
