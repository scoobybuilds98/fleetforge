-- ============================================================================
-- S-QBO-17 — Refund Receipt push (FF → QBO) (Phase QBO-7 / 2 of 2, CLOSES QBO-7)
-- ============================================================================
--
-- The customer-side cash-refund counterpart to S-QBO-16 credit memos. When an
-- FF cash precharge-refund is physically disbursed (operator clicks "Mark
-- Refund Settled" → api/v1/leases/mark_refund_settled.php), FF pushes a QBO
-- RefundReceipt entity. Spec §8.7.
--
-- SCOPE (D-QBO-17-2): CASH refunds only. CREDIT-method refunds already create
-- a credit_notes row at lease close (S-MILEAGE-3 D-C) and push to QBO via the
-- shipped CreditMemo path (S-QBO-16) + apply→LinkedTxn (S-QBO-CREDIT-MEMO-APPLY,
-- F25) — so they are NOT re-pushed as RefundReceipts here.
--
-- KEYING (D-QBO-17-5): a precharge refund has NO dedicated row — it lives on
-- the `leases` table (precharge_refund_method='cash', precharge_balance = the
-- refund amount, precharge_refund_settled_at = disbursement timestamp). One
-- cash refund per lease → the queue + map key on `lease_id`. This makes the
-- push naturally idempotent (one RefundReceipt per lease).
--
-- TRIGGER (D-QBO-17-1): enqueue on mark_refund_settled.php (settle-time, when
-- money actually moves), NOT at lease close — cash refunds are deferred-settle
-- per S-MILEAGE-3 D-B(i) (settled_at=NULL at close, stamped later).
--
-- PAYLOAD (D-QBO-17-3): per spec §8.7 — TotalAmt = precharge_balance; single
-- SalesItemLineDetail line; PaymentMethodRef + DepositToAccountRef + ItemRef +
-- TaxCodeRef=NON. Tax-override per D-QBO-CORE-6 (refund carries no tax).
--   * TaxCodeRef=NON is a DOCUMENTED ASSUMPTION pending accountant confirmation
--     of refund tax treatment — tracked as a live-verify follow-up (F28), the
--     same F16/F19 pattern. Safe to ship because sync_enabled='0' until the
--     S-QBO-30 cutover means nothing posts to QBO before the verify.
--
-- REFS via SETTINGS (D-QBO-17-6): there is no QBO PaymentMethod map/matcher in
-- the codebase, and the refund event captures no per-refund bank account. So
-- both refs come from operator-configured settings (mirrors tax_override_code_id):
--   * quickbooks.refund.deposit_account_id  = QBO Account.Id the cash left from
--   * quickbooks.refund.payment_method_id   = QBO PaymentMethod.Id (cheque)
-- Both are preflight gates (empty → failed_preflight with actionable reason).
--
-- THREE motions:
--   1. ALTER acc_qbo_sync_queue.entity_type ENUM += 'refund_receipt'.
--   2. CREATE acc_qbo_refund_receipt_map (mirrors acc_qbo_credit_memo_map
--      shape; keyed on ff_lease_id ↔ qbo_refund_receipt_id; FK CASCADE on leases).
--   3. INSERT 3 settings: sync_mode.refund_receipt='sync' + the two refund refs
--      (empty defaults — operator populates at cutover, like tax_override_code_id).
--
-- MIGRATE COUNT: 83 → 84.
--
-- @session  S-QBO-17
-- @closes   Phase QBO-7 (2/2)
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.7 (Refund Receipt)
-- @decision D-QBO-17-1 (trigger=mark_refund_settled.php settle-time),
--           D-QBO-17-2 (cash-branch only; credit refunds ride CreditMemo path),
--           D-QBO-17-3 (payload §8.7; TaxCodeRef=NON assumption → F28 live-verify),
--           D-QBO-17-4 (DepositToAccountRef from setting; no per-refund cheque num),
--           D-QBO-17-5 (key on lease_id; refund lives on leases, 1 per lease),
--           D-QBO-17-6 (PaymentMethodRef + DepositToAccountRef from settings;
--                       no QBO PaymentMethod map exists)

-- ─── Motion 1: queue ENUM ────────────────────────────────────────────────
ALTER TABLE `acc_qbo_sync_queue`
    MODIFY COLUMN `entity_type` ENUM(
        'customer','vendor','invoice','payment','credit_memo','refund_receipt',
        'bill','bill_payment','journal_entry','item','account','tax_code',
        'credit_application'
    ) COLLATE utf8mb4_unicode_ci NOT NULL;

-- ─── Motion 2: refund receipt map ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_qbo_refund_receipt_map` (
    `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_lease_id`                 INT UNSIGNED NOT NULL COMMENT 'leases.id — the lease whose cash precharge refund this RefundReceipt mirrors. One cash refund per lease (D-QBO-17-5).',
    `ff_customer_id_snapshot`     INT UNSIGNED DEFAULT NULL COMMENT 'leases.customer_id snapshot — forensic trail',
    `ff_contract_number_snapshot` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'leases.contract_number snapshot — DocNumber forensic',
    `qbo_refund_receipt_id`       VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit RefundReceipt.Id; NULL until first successful push',
    `qbo_sync_token`              VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token',
    `qbo_doc_number`              VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO DocNumber snapshot (synthesized REF-<contract>)',
    `qbo_total_amt`               DECIMAL(15,2) DEFAULT NULL COMMENT 'QBO TotalAmt snapshot (= precharge_balance) — drift baseline',
    `qbo_currency`                VARCHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value snapshot',
    `qbo_exchange_rate`           DECIMAL(10,6) DEFAULT NULL COMMENT 'QBO ExchangeRate pinned at push time',
    `qbo_txn_date`                DATE DEFAULT NULL COMMENT 'QBO TxnDate snapshot (= precharge_refund_settled_at date)',
    `qbo_deposit_account_id`      VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Account.Id used as DepositToAccountRef (from settings) — forensic',
    `qbo_payment_method_id`       VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO PaymentMethod.Id used as PaymentMethodRef (from settings) — forensic',
    `ff_refund_amount_snapshot`   DECIMAL(15,2) DEFAULT NULL COMMENT 'FF leases.precharge_balance snapshot at push time — drift baseline',
    `push_status`                 ENUM(
                                      'pending',
                                      'pushed',
                                      'failed',
                                      'skipped_by_mode',
                                      'failed_preflight',
                                      'failed_preflight_field_too_long'
                                  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
                                  COMMENT 'Slimmer than credit_memo_map — refund has no void path in v1 (a re-refund is a fresh event) and no currency-mismatch probe (settle-time refund currency = lease currency). field_too_long retained for the synthesized DocNumber gate.',
    `push_error`                  TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Last error for failed/failed_preflight states',
    `pushed_at`                   DATETIME DEFAULT NULL COMMENT 'Most recent successful push timestamp',
    `last_synced_at`              DATETIME DEFAULT NULL COMMENT 'Most recent state mutation (push, gate fail, skip)',
    `created_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_lease` (`ff_lease_id`) COMMENT 'One RefundReceipt mapping per lease; enforces idempotency of pushCreate',
    UNIQUE KEY `uq_qbo_refund_receipt` (`qbo_refund_receipt_id`) COMMENT 'No two leases share a QBO RefundReceipt.Id; NULL-multi-OK per InnoDB',
    KEY `idx_status` (`push_status`),
    KEY `idx_pushed_at` (`pushed_at`),
    CONSTRAINT `fk_qbo_refund_receipt_map_ff` FOREIGN KEY (`ff_lease_id`) REFERENCES `leases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-7 S-QBO-17 (CLOSES QBO-7): FF→QBO cash refund-receipt push state. One row per lease cash precharge refund; pushes as a QBO RefundReceipt entity. Mirrors acc_qbo_credit_memo_map shape with refund deltas (deposit account + payment method forensic cols).';

-- ─── Motion 3: settings ──────────────────────────────────────────────────
-- sync_mode.refund_receipt was NOT seeded by S-QBO-3 (the 13 seeded modes did
-- not include it — refund_receipt was a planned-only entity). Seed it here.
-- The two refund refs default EMPTY; operator populates at cutover (like
-- tax_override_code_id). INSERT IGNORE keeps the migration idempotent.
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`) VALUES
    ('quickbooks.sync_mode.refund_receipt', 'sync', 'string', 'quickbooks',
     'QBO sync mode — refund receipts',
     'sync (default) → FF cash-refund→QBO RefundReceipt push enabled; disabled → not pushed; qbo_to_ff → reserved.'),
    ('quickbooks.refund.deposit_account_id', '', 'string', 'quickbooks',
     'QBO deposit account for cash refunds',
     'QBO Account.Id (a bank/cash account) used as RefundReceipt.DepositToAccountRef — the account the refund cash left from. Empty until operator maps it at cutover; RefundReceiptPusher preflight gate blocks push while empty (D-QBO-17-6).'),
    ('quickbooks.refund.payment_method_id', '', 'string', 'quickbooks',
     'QBO payment method for cash refunds (cheque)',
     'QBO PaymentMethod.Id used as RefundReceipt.PaymentMethodRef. D-QBO-17-3 picks cheque. Empty until operator maps it at cutover; preflight gate blocks push while empty (D-QBO-17-6).');
