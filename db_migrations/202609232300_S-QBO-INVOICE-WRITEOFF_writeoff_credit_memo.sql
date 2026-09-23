-- ============================================================
-- S-QBO-INVOICE-WRITEOFF — invoice write-offs reach QuickBooks as a
-- credit memo applied to the invoice
-- 2026-09-23
--
-- The pre-go-live audit (docs/audits/CLAUDE_2026-09-23_quickbooks.md,
-- residual "Write-offs") found a damage-claim write-off reached
-- QuickBooks only as a JournalEntry crediting A/R: QuickBooks' total A/R
-- was right, but the invoice itself stayed open, so invoice-level aging
-- was wrong. The AR bad-debt write-off (api/v1/accounting/ar/
-- bad_debt_writeoff.php) never reached QuickBooks at all. Operator
-- decision (2026-09-23): push a write-off as a QuickBooks CreditMemo on
-- a "Bad debt" item, applied to the invoice ($0 Payment linking both),
-- so the invoice closes in QuickBooks.
--
-- Both FF write-off paths now go through lib/Accounting/InvoiceWriteOff
-- and record one acc_bad_debt_writeoffs row — the entity the new
-- InvoiceWriteoffPusher pushes:
--
--   acc_bad_debt_writeoffs.damage_claim_id — which damage claim (if any)
--     the write-off came from (the damage path used to write nothing
--     here, and left the invoice open in FF too);
--   acc_qbo_invoice_writeoff_map — the QuickBooks CreditMemo + the $0
--     apply Payment for each write-off (two creates per push: the memo id
--     is stored first so a failed apply step never re-creates the memo);
--   acc_qbo_sync_queue.entity_type + 'invoice_writeoff'.
-- ============================================================

ALTER TABLE `acc_bad_debt_writeoffs`
  ADD COLUMN `damage_claim_id` int unsigned DEFAULT NULL
    COMMENT 'S-QBO-INVOICE-WRITEOFF: damage claim written off (NULL = AR bad-debt write-off)'
    AFTER `customer_id`,
  ADD KEY `idx_damage_claim` (`damage_claim_id`),
  ADD CONSTRAINT `acc_bad_debt_writeoffs_ibfk_6` FOREIGN KEY (`damage_claim_id`) REFERENCES `damage_claims` (`id`) ON DELETE SET NULL;

CREATE TABLE `acc_qbo_invoice_writeoff_map` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ff_writeoff_id` int unsigned NOT NULL COMMENT 'acc_bad_debt_writeoffs.id; one row per FF write-off; FK CASCADE',
  `ff_invoice_id_snapshot` int unsigned NOT NULL COMMENT 'acc_bad_debt_writeoffs.invoice_id snapshot — forensic trail',
  `qbo_credit_memo_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CreditMemo.Id (Bad-debt item line); stored as soon as it is created',
  `qbo_payment_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Payment.Id — the $0 Payment applying the CreditMemo to the invoice',
  `qbo_invoice_id_ref` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Invoice.Id the credit was applied to',
  `amount_snapshot` decimal(15,2) DEFAULT NULL COMMENT 'Amount written off, at push time',
  `push_status` enum('pending','pushed','failed','failed_preflight','skipped_by_mode') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pushed = CreditMemo created AND applied',
  `push_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Last error for failed/failed_preflight states',
  `pushed_at` datetime DEFAULT NULL COMMENT 'UTC time the write-off was fully pushed',
  `last_synced_at` datetime DEFAULT NULL COMMENT 'UTC time of the most recent state change',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_writeoff` (`ff_writeoff_id`) COMMENT 'One mapping row per FF write-off; idempotency of pushCreate',
  UNIQUE KEY `uq_qbo_writeoff_credit_memo` (`qbo_credit_memo_id`),
  UNIQUE KEY `uq_qbo_writeoff_payment` (`qbo_payment_id`),
  KEY `idx_status` (`push_status`),
  CONSTRAINT `fk_qbo_invoice_writeoff_map_ff` FOREIGN KEY (`ff_writeoff_id`) REFERENCES `acc_bad_debt_writeoffs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='S-QBO-INVOICE-WRITEOFF: FF invoice write-off → QBO CreditMemo (Bad-debt item) + $0 Payment applying it to the invoice.';

ALTER TABLE `acc_qbo_sync_queue`
  MODIFY COLUMN `entity_type` enum('customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code','credit_application','invoice_writeoff') COLLATE utf8mb4_unicode_ci NOT NULL;
