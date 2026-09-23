-- ============================================================
-- S-QBO-BILLPAY-MIRROR — bill payments made in QuickBooks mirror into FF
-- 2026-09-23
--
-- Operators enter the business's bills in FleetForge (per-unit costing);
-- the accountant pays them in QuickBooks. Until now FleetForge never
-- learned about those payments: a BillPayment webhook was dropped and the
-- payment catch-up only looked at invoices, so every bill paid in
-- QuickBooks stayed "approved" in FleetForge and FF's AP / bank GL drifted
-- from the books (S-QBO-GOLIVE-AUDIT open item "QBO-side bill payments
-- are not mirrored").
--
-- lib/QboPushers/BillPaymentWebhookHandler.php now mirrors them the way
-- PaymentWebhookHandler mirrors customer payments. Two tables need to
-- carry where an AP payment came from:
--
--   acc_ap_payments.origin — same vocabulary as payments.origin
--     (D-QBO-13-1): ff_native = recorded in FleetForge (pushed to QBO);
--     qbo_payments_webhook = arrived by webhook; qbo_other = imported by
--     the go-live link / "Check QuickBooks for payments" catch-up.
--     BillPaymentEnqueuer pushes ff_native only, so a QuickBooks-owned
--     copy is never pushed back as a duplicate.
--
--   acc_qbo_bill_payment_map — gains the same pull-side columns
--     acc_qbo_payment_map has (origin, webhook_event_id, realm_id,
--     pulled_at) and the terminal push_status 'pulled_from_qbo'
--     (D-QBO-13-2 pattern: pulled rows skip the push pipeline).
--
-- Defaults keep every existing row's meaning ('ff_native').
-- ============================================================

ALTER TABLE `acc_ap_payments`
  ADD COLUMN `origin` ENUM('ff_native','qbo_payments_webhook','qbo_other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ff_native'
    COMMENT 'S-QBO-BILLPAY-MIRROR: ff_native = recorded in FF (pushed to QBO); qbo_payments_webhook / qbo_other = mirrored from a QuickBooks BillPayment (never pushed back)'
    AFTER `status`;

ALTER TABLE `acc_qbo_bill_payment_map`
  MODIFY COLUMN `ff_ap_payment_id` int unsigned NOT NULL
    COMMENT 'FK acc_ap_payments.id. FF-recorded payments get a row on push; QuickBooks-side payments get one when mirrored (S-QBO-BILLPAY-MIRROR, origin <> ff_native).',
  MODIFY COLUMN `push_status` enum('pending','pushed','voided','failed','skipped_voided','skipped_unmapped_void','skipped_by_mode','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long','pulled_from_qbo') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
    COMMENT 'Mirrors acc_qbo_bill_map.push_status (S-QBO-18 + S-QBO-BILL-GOTCHAS-PAYDOWN). pulled_from_qbo = mirrored from QuickBooks (S-QBO-BILLPAY-MIRROR) — terminal, never pushed.',
  ADD COLUMN `origin` ENUM('ff_native','qbo_payments_webhook','qbo_other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ff_native'
    COMMENT 'S-QBO-BILLPAY-MIRROR: provenance, same vocabulary as acc_qbo_payment_map.origin'
    AFTER `ff_payment_snapshot_total`,
  ADD COLUMN `webhook_event_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'S-QBO-BILLPAY-MIRROR: webhook event (or cutover-import) that mirrored the QuickBooks BillPayment'
    AFTER `origin`,
  ADD COLUMN `realm_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'S-QBO-BILLPAY-MIRROR: QBO realm at mirror time'
    AFTER `webhook_event_id`,
  ADD COLUMN `pulled_at` datetime DEFAULT NULL
    COMMENT 'S-QBO-BILLPAY-MIRROR: UTC time the QuickBooks BillPayment was last mirrored into FF'
    AFTER `pushed_at`,
  ADD KEY `idx_origin` (`origin`);
