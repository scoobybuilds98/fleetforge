-- ============================================================
-- S-QBO-11 — acc_qbo_invoice_map.
--
-- Invoice push mapping table. Persists the FF↔QBO Invoice linkage +
-- per-row push state (pending/pushed/failed/skipped/preflight) so the
-- worker can resume safely + the admin UI can surface QBO sync state
-- per invoice. CREATE path only this session; UPDATE/VOID push (and
-- their additional snapshot fields) deferred to S-QBO-12.
--
-- Mapping-row lifecycle (D-QBO-11-1 + D-QBO-11-9 + D-QBO-11-10):
--   pending          → enqueue inserted; worker hasn't dispatched yet
--   pushed           → QBO accepted POST /v3/.../invoice; qbo_invoice_id + qbo_sync_token persisted
--   failed           → QBO returned error; push_error captured; worker retries per D-QBO-3-1
--   failed_preflight → InvoicePreflightGate refused before HTTP call; operator must remediate
--   skipped_voided   → FF invoice.status='void' at push time; no QBO write attempted
--   skipped_by_mode  → settings.quickbooks.sync_mode.invoice rejects push direction
--
-- Tax-override architecture (D-QBO-11-2): every QBO Invoice.Line carries
-- TaxCodeRef.value = settings.quickbooks.tax_override_code_id (the NON
-- code per D-QBO-CORE-6); invoice header carries TxnTaxDetail.TotalTax
-- = bcadd(tax_gst, bcadd(tax_pst, tax_hst, 2), 2). FF tax math is
-- authoritative; QBO accepts override without recomputation.
--
-- K-22 silent resolutions per [[feedback_trust_file_over_prompt]] (AskUserQuestion at pre-flight):
--   - invoices.engine_version DOES NOT EXIST → ff_engine_version column
--     here exists for forward-compat; PrivateNote JSON emits 'unknown'
--     literal for now. Future migration may add invoices.engine_version
--     if/when the holistic engine introduces it.
--   - invoices.fx_rate DOES NOT EXIST → use exchange_rate_to_cad
--     (already on invoices). ff_exchange_rate snapshot here matches that
--     column's semantics (rate from invoice.currency to CAD).
--   - tax cols are tax_gst_amount/tax_pst_amount/tax_hst_amount (not
--     gst_amount/pst_amount/hst_amount).
--   - invoice_line_items.sort_order (not line_order), .amount (not subtotal).
--   - invoices.status enum value is 'void' (not 'voided').
--   - no invoices.memo column → use invoices.notes for QBO CustomerMemo
--     (customer-facing); invoices.internal_notes reserved for FF-internal use.
--
-- Append-at-end index ordering (K-22 Trap #66): all new indexes + FK
-- declared at end of constraint block to match MySQL ALTER append
-- behavior. Verified via master_schema_parity smoke pre-commit.
--
-- @session  S-QBO-11
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher Contract), §6.9 (Enqueuer Contract), §17/§18 (tax-override architecture)
-- @decision D-QBO-11-1 (queued path only — no synchronous push),
--           D-QBO-11-2 (tax-override at line + header levels),
--           D-QBO-11-3 (FX rate pinned at invoice creation; CurrencyRef + ExchangeRate when currency != CAD),
--           D-QBO-11-4 (UPDATE path stubbed; SyncToken pinning starts at '0'),
--           D-QBO-11-5 (engine-version dispatch — recon credit enforcement guards holistic-only emissions),
--           D-QBO-11-6 (PrivateNote JSON audit format with bcmath-precision strings),
--           D-QBO-11-7 (customer-mapping pre-flight gate),
--           D-QBO-11-8 (item-mapping pre-flight gate with GPS variant resolution),
--           D-QBO-11-9 (voided invoice skip semantics),
--           D-QBO-11-10 (D12 immutability + post-push freeze)
-- ============================================================

CREATE TABLE `acc_qbo_invoice_map` (
  `id`                         int unsigned NOT NULL AUTO_INCREMENT,
  `ff_invoice_id`              int unsigned NOT NULL COMMENT 'NOT NULL: invoices originate in FF only in S-QBO-11. QBO-authored invoices (D-CPA-4) handled in S-QBO-26.',
  `qbo_invoice_id`             varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit Invoice.Id; NULL until first successful push',
  `qbo_sync_token`             varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token; refreshed on every push/update',
  `qbo_doc_number`             varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO DocNumber snapshot at last sync',
  `qbo_total_amt`              decimal(15,2) DEFAULT NULL COMMENT 'QBO TotalAmt snapshot — for drift comparison',
  `qbo_balance`                decimal(15,2) DEFAULT NULL COMMENT 'QBO Balance snapshot at last sync',
  `qbo_status`                 varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO EmailStatus/PaymentStatus snapshot',
  `qbo_currency`               varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value (e.g. CAD/USD)',
  `qbo_exchange_rate`          decimal(10,6) DEFAULT NULL COMMENT 'QBO ExchangeRate pinned at push time',
  `ff_invoice_snapshot_total`  decimal(15,2) DEFAULT NULL COMMENT 'FF total_amount snapshot at push time — drift baseline',
  `ff_engine_version`          varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'period_independent | holistic | unknown. invoices.engine_version DOES NOT exist on disk today; column here is forward-compat. K-22 silent resolution per [[feedback_trust_file_over_prompt]].',
  `push_status`                enum('pending','pushed','failed','skipped_voided','skipped_by_mode','failed_preflight') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'D-QBO-11-1 lifecycle states',
  `push_error`                 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Last error message for failed/failed_preflight states',
  `pushed_at`                  datetime DEFAULT NULL COMMENT 'Most recent successful push timestamp',
  `last_synced_at`             datetime DEFAULT NULL COMMENT 'Most recent state mutation (push, gate fail, skip)',
  `created_at`                 datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                 datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_invoice` (`ff_invoice_id`) COMMENT 'One mapping row per FF invoice; enforces idempotency of pushCreate',
  UNIQUE KEY `uq_qbo_invoice` (`qbo_invoice_id`) COMMENT 'No two FF invoices share a QBO Invoice.Id; NULL-multi-OK per InnoDB',
  KEY `idx_status` (`push_status`),
  KEY `idx_pushed_at` (`pushed_at`),
  KEY `idx_engine_version` (`ff_engine_version`),
  CONSTRAINT `fk_qbo_invoice_map_ff` FOREIGN KEY (`ff_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
