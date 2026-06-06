-- S036 — Extend ai_summaries.summary_type ENUM with 4 accounting narrative
-- types. New values appended at end per D126 (no ENUM reordering).
-- Existing values (preserved verbatim, in order):
--   lease_summary, customer_insights, fleet_health, unit_analysis,
--   payment_risk, forecast, anomaly, accounting_overview
-- New values:
--   pl_narrative       — AI narrative on a P&L statement
--   bs_narrative       — AI narrative on a balance sheet
--   cashflow_narrative — AI narrative on a cash flow statement
--   budget_variance    — AI narrative on a budget variance report
--
-- Idempotent: ALTER will be a no-op if the new values are already present.

ALTER TABLE `ai_summaries`
    MODIFY COLUMN `summary_type` ENUM(
        'lease_summary',
        'customer_insights',
        'fleet_health',
        'unit_analysis',
        'payment_risk',
        'forecast',
        'anomaly',
        'accounting_overview',
        'pl_narrative',
        'bs_narrative',
        'cashflow_narrative',
        'budget_variance'
    ) COLLATE utf8mb4_unicode_ci NOT NULL;
