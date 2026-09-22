-- ============================================================
-- S-QBO-GOLIVE-AUDIT — cutover links on the invoice + credit-memo maps
-- 2026-09-23
--
-- FleetForge goes live after a year of invoicing done directly in
-- QuickBooks. Many FF invoices (and a few credit notes) describe documents
-- QuickBooks ALREADY holds — typed in by the accountant, often already
-- paid. Pushing them would create duplicates in a company file that also
-- carries other businesses' books.
--
-- The cutover linker (lib/QboPushers/InvoiceLinker.php) instead LINKS an
-- FF document to the existing QuickBooks one: it writes the map row a
-- successful push would have written, without pushing. From then on the
-- create path is a no-op (already_mapped) and QuickBooks payments against
-- the document mirror into FF.
--
-- `origin` records which of the two a map row came from, because the two
-- must be treated differently afterwards: FleetForge owns what it pushed
-- and may update / void it in QuickBooks, but it never rewrites a document
-- the accountant created — an FF-side edit or void of a linked document
-- raises a drift event for a human instead (InvoicePusher /
-- CreditMemoPusher gate on origin = 'cutover_link').
--
-- `link_method` is the forensic trail of HOW the link was decided
-- (exact doc-number match, same amount + the same unit named in the
-- memo, amount+date match, or a person choosing).
--
-- Defaults keep every existing row's meaning ('ff_push').
-- ============================================================

ALTER TABLE `acc_qbo_invoice_map`
  ADD COLUMN `origin` ENUM('ff_push','cutover_link') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ff_push'
    COMMENT 'S-QBO-GOLIVE-AUDIT: ff_push = FF created the QBO invoice; cutover_link = linked to an invoice QBO already had (never updated/voided by FF)'
    AFTER `ff_engine_version`,
  ADD COLUMN `link_method` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'S-QBO-GOLIVE-AUDIT: exact | doc_number | amount_unit | amount_date | manual (a link) or push_new (pre-go-live doc released to push)'
    AFTER `origin`,
  ADD COLUMN `linked_at` DATETIME DEFAULT NULL
    COMMENT 'S-QBO-GOLIVE-AUDIT: UTC time of the cutover link'
    AFTER `link_method`;

ALTER TABLE `acc_qbo_credit_memo_map`
  ADD COLUMN `origin` ENUM('ff_push','cutover_link') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ff_push'
    COMMENT 'S-QBO-GOLIVE-AUDIT: ff_push = FF created the QBO credit memo; cutover_link = linked to one QBO already had'
    AFTER `ff_credit_note_snapshot_total`,
  ADD COLUMN `link_method` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'S-QBO-GOLIVE-AUDIT: exact | doc_number | amount_unit | amount_date | manual (a link) or push_new (released to push)'
    AFTER `origin`,
  ADD COLUMN `linked_at` DATETIME DEFAULT NULL
    COMMENT 'S-QBO-GOLIVE-AUDIT: UTC time of the cutover link'
    AFTER `link_method`;

-- Bills too: operators will enter the business's bills in FleetForge for
-- per-unit costing, and the ones dated before go-live are already in
-- QuickBooks (the accountant entered them) — they are linked, not pushed.
ALTER TABLE `acc_qbo_bill_map`
  ADD COLUMN `origin` ENUM('ff_push','cutover_link') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ff_push'
    COMMENT 'S-QBO-GOLIVE-AUDIT: ff_push = FF created the QBO bill; cutover_link = linked to one QBO already had'
    AFTER `ff_bill_snapshot_total`,
  ADD COLUMN `link_method` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'S-QBO-GOLIVE-AUDIT: exact | doc_number | amount_unit | amount_date | manual | push_new'
    AFTER `origin`,
  ADD COLUMN `linked_at` DATETIME DEFAULT NULL
    COMMENT 'S-QBO-GOLIVE-AUDIT: UTC time of the cutover link'
    AFTER `link_method`;
