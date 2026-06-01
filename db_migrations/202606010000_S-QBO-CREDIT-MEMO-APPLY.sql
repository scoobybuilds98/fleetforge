-- ============================================================================
-- S-QBO-CREDIT-MEMO-APPLY — Credit-memo APPLICATION push (FF → QBO)
-- ============================================================================
--
-- Closes OPERATOR_FOLLOWUPS F25 (the last open QBO update-debt item) and the
-- F20 carve-out from S-QBO-CREDIT-MEMO-UPDATE (D-QBO-CREDIT-MEMO-UPDATE-1).
--
-- WHAT: When FF applies a credit note to an invoice (api/v1/credit_notes/
-- apply.php), propagate the application to QBO. In QBO the apply-credit
-- operation is NOT a CreditMemo update — it is a zero-dollar Payment entity
-- carrying TWO LinkedTxns on a single Line: one referencing the QBO
-- CreditMemo + one referencing the QBO Invoice; TotalAmt=0.
--
-- WHY a NEW entity (D-QBO-CREDIT-MEMO-APPLY-1, D1 in session prompt):
--   One credit_note can be applied to N invoices (one credit_notes row → N
--   credit_note_applications rows → N distinct QBO Payments). Reusing
--   'credit_memo' with a new 'apply' op cannot identify which application
--   is being pushed (entity_id is the credit_note_id, not the application
--   id). Therefore we add a NEW queue entity_type='credit_application',
--   keyed on credit_note_applications.id, dispatched by the existing
--   convention-based QboPusherDispatcher to a new CreditApplicationPusher.
--
-- WHY a NEW map table (D-QBO-CREDIT-MEMO-APPLY-2, D2 in session prompt):
--   acc_qbo_credit_memo_map is keyed on ff_credit_note_id (one row per
--   credit memo). Applications are 1:N to the parent credit. A new
--   acc_qbo_credit_application_map table mirrors the acc_qbo_credit_memo_map
--   shape but is keyed on ff_credit_application_id ↔ qbo_payment_id. CASCADE
--   on credit_note_applications matches the parent table's CASCADE behavior.
--
-- THREE motions in this migration:
--   1. ALTER acc_qbo_sync_queue: add 'credit_application' to entity_type ENUM
--      (existing 12 values + 1).
--   2. CREATE acc_qbo_credit_application_map (mirrors credit_memo_map
--      state-machine shape; idempotency on ff_credit_application_id).
--   3. INSERT settings 'quickbooks.sync_mode.credit_application' = 'sync'.
--      Separate from sync_mode.credit_memo so an operator can disable
--      application propagation without disabling credit-memo creation. The
--      Enqueuer reads this key in gate-2.
--
-- AUTO-APPLY PRE-REQ (D-QBO-CREDIT-MEMO-APPLY-3, D3 in session prompt):
--   QBO's company-level "Automatically apply credits" setting (Account &
--   Settings → Advanced → Automation) would DOUBLE-APPLY if left ON: QBO
--   would auto-apply the CreditMemo to the Invoice the moment they share a
--   CustomerRef, and our explicit zero-dollar Payment would then attempt a
--   second application. Operator pre-req: ensure this setting is OFF
--   before live cutover. Tracked in OPERATOR_FOLLOWUPS as a new F item.
--   No runtime probe — matches existing pre-flight-as-doc decisions
--   (tax_override_code_id, sync_enabled).
--
-- V1 SCOPE (D-QBO-CREDIT-MEMO-APPLY-4, D4 in session prompt):
--   Forward-apply only (op='create'). Un-apply / void-after-apply requires
--   DELETE on the QBO Payment + reversal of credit_note_applications rows;
--   no FF endpoint un-applies today (credit_notes/void.php only voids the
--   parent credit, not individual applications). Tracked as a new
--   OPERATOR_FOLLOWUPS F item.
--
-- ADMIN UI (D-QBO-CREDIT-MEMO-APPLY-5, D5 in session prompt):
--   CreditApplicationPusher is allowlisted in _smoke_doc_freshness.php
--   CLASS 12 to share app/admin/quickbooks/credit_memos.php (operationally
--   a sub-view of its parent credit memo). credit_memos.php gains an
--   "Applications" section listing pending/failed applications + retry
--   endpoint. Mirrors the PaymentWebhookHandler↔payments.php precedent.
--
-- MIGRATE COUNT: 82 → 83.
--
-- @session  S-QBO-CREDIT-MEMO-APPLY
-- @closes   F25 (last QBO update-debt item)
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.6 (credit application → LinkedTxn)
-- @decision D-QBO-CREDIT-MEMO-APPLY-1 (new entity_type=credit_application,
--                                      keyed on credit_note_applications.id),
--           D-QBO-CREDIT-MEMO-APPLY-2 (new acc_qbo_credit_application_map),
--           D-QBO-CREDIT-MEMO-APPLY-3 (auto-apply pre-req documented, not probed),
--           D-QBO-CREDIT-MEMO-APPLY-4 (v1 forward-apply only; un-apply → follow-up),
--           D-QBO-CREDIT-MEMO-APPLY-5 (UI shares credit_memos.php via CLASS 12 allowlist)

-- ─── Motion 1: queue ENUM ────────────────────────────────────────────────
ALTER TABLE `acc_qbo_sync_queue`
    MODIFY COLUMN `entity_type` ENUM(
        'customer','vendor','invoice','payment','credit_memo','refund_receipt',
        'bill','bill_payment','journal_entry','item','account','tax_code',
        'credit_application'
    ) COLLATE utf8mb4_unicode_ci NOT NULL;

-- ─── Motion 2: application map ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_qbo_credit_application_map` (
    `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_credit_application_id`        INT UNSIGNED NOT NULL COMMENT 'credit_note_applications.id; one row per FF application; FK CASCADE',
    `ff_credit_note_id_snapshot`      INT UNSIGNED NOT NULL COMMENT 'credit_note_applications.credit_note_id snapshot — forensic trail; lets the row survive after FK CASCADE deletion of the application table is impossible (we cascade both)',
    `ff_invoice_id_snapshot`          INT UNSIGNED NOT NULL COMMENT 'credit_note_applications.invoice_id snapshot — forensic trail',
    `qbo_payment_id`                  VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit Payment.Id (zero-dollar Payment carrying 2 LinkedTxns); NULL until first successful push',
    `qbo_sync_token`                  VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token',
    `qbo_credit_memo_id_ref`          VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CreditMemo.Id referenced by Line[0].LinkedTxn[CreditMemo]',
    `qbo_invoice_id_ref`              VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Invoice.Id referenced by Line[0].LinkedTxn[Invoice]',
    `qbo_total_amt`                   DECIMAL(15,2) DEFAULT NULL COMMENT 'QBO TotalAmt snapshot — always 0.00 for apply Payments (it is the LINE Amount that carries amount_applied, not the header)',
    `qbo_currency`                    VARCHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value snapshot',
    `qbo_exchange_rate`               DECIMAL(10,6) DEFAULT NULL COMMENT 'QBO ExchangeRate pinned at push time',
    `qbo_txn_date`                    DATE DEFAULT NULL COMMENT 'QBO TxnDate snapshot (mirrors FF credit_note_applications.applied_at)',
    `amount_applied_snapshot`         DECIMAL(15,2) DEFAULT NULL COMMENT 'FF credit_note_applications.amount_applied snapshot at push time — drift baseline',
    `push_status`                     ENUM(
                                          'pending',
                                          'pushed',
                                          'failed',
                                          'skipped_by_mode',
                                          'failed_preflight'
                                      ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
                                      COMMENT 'Slimmer than credit_memo_map.push_status — apply has no soft-delete / no field_too_long / no currency-mismatch path (currencies are guaranteed equal by api/v1/credit_notes/apply.php D18 CURRENCY_MISMATCH gate). Reverse-apply (voided state) is a future-extension follow-up per D-QBO-CREDIT-MEMO-APPLY-4.',
    `push_error`                      TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Last error for failed/failed_preflight states',
    `pushed_at`                       DATETIME DEFAULT NULL COMMENT 'Most recent successful push timestamp',
    `last_synced_at`                  DATETIME DEFAULT NULL COMMENT 'Most recent state mutation (push, gate fail, skip)',
    `created_at`                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_credit_application` (`ff_credit_application_id`) COMMENT 'One mapping row per FF application; enforces idempotency of pushCreate',
    UNIQUE KEY `uq_qbo_payment_apply` (`qbo_payment_id`) COMMENT 'No two FF applications share a QBO Payment.Id; NULL-multi-OK per InnoDB',
    KEY `idx_status` (`push_status`),
    KEY `idx_pushed_at` (`pushed_at`),
    KEY `idx_ff_credit_note` (`ff_credit_note_id_snapshot`) COMMENT 'List-by-parent-credit lookups for the credit_memos.php admin Applications section',
    CONSTRAINT `fk_qbo_credit_application_map_ff` FOREIGN KEY (`ff_credit_application_id`) REFERENCES `credit_note_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='S-QBO-CREDIT-MEMO-APPLY: FF→QBO credit-application push state. One row per credit_note_applications row; pushes as a zero-dollar QBO Payment carrying 2 LinkedTxns (CreditMemo + Invoice).';

-- ─── Motion 3: sync mode setting ─────────────────────────────────────────
-- INSERT IGNORE so re-running the migration is idempotent. 'sync' default
-- matches the sync_mode.credit_memo default seeded in S-QBO-3.
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`)
    VALUES (
        'quickbooks.sync_mode.credit_application',
        'sync',
        'string',
        'quickbooks',
        'QBO sync mode — credit-memo applications',
        'sync (default) → FF apply→QBO Payment LinkedTxn propagation enabled; disabled → applications NOT propagated; qbo_to_ff → reserved for future un-apply path. Separate from sync_mode.credit_memo so apply propagation can be paused without disabling credit-memo creation.'
    );
