-- ============================================================
-- S-QBO-10 — acc_qbo_item_map.
--
-- Item / Product mapping table. Pulls QBO Item entities via /query
-- and links them to FF invoice_line_items.item_type ENUM values.
-- Bidirectional: FF item_types are the canonical line-item taxonomy
-- (17 ENUM values fixed in code), QBO Items are reference data
-- created or linked per the operator's COA. Different from
-- customer/vendor/account/tax_code maps because the FF "source" side
-- is an ENUM column (not a table) — there is no ff_item_id FK, just
-- ff_item_type VARCHAR plus an optional variant suffix for the GPS
-- net/gross presentation case (D-QBO-10-2).
--
-- K-22 catches surfaced at pre-flight (silently applied):
--   - invoice_line_items.item_type ENUM has 17 values verified at
--     pre-flight, but DIFFERS from the v1.1 roadmap §9.4 hypothetical
--     list. Actual ENUM:
--       base_rental, base_rental_reconciliation_credit, gps,
--       mileage_precharge, mileage_adjustment, mileage_credit,
--       mileage_usage, mileage_drawdown_credit, damage, late_fee,
--       early_return_credit, insurance, warranty, manual_adjustment,
--       discount, account_credit_applied, other
--     Roadmap referenced mileage_overage / damage_recovery /
--     early_termination_fee / setup_fee / delivery_fee / manual /
--     tax_adjustment / prepayment — none present. Per K-22
--     file-over-prompt: matcher iterates the ACTUAL ENUM via schema
--     introspection (ItemMatcher::ffItemTypes()), not a hardcoded list.
--   - acc_qbo_account_map column name is qbo_name (not
--     qbo_account_name) — ItemCreator JOIN uses qbo_name AS
--     qbo_account_name alias.
--   - customers.gps_revenue_presentation ENUM('net','gross')
--     verified — D-QBO-10-2 GPS variant logic viable.
--   - 0/7 critical S-QBO-8 accounts mapped at pre-flight time —
--     ItemCreator falls back to any mapped revenue account
--     (FF 4110 'Other Revenue' was mapped at pre-flight); throws
--     ChartOfAccountsIncompleteException if no revenue mapped.
--
-- Schema mirrors customer/vendor/account/tax_code mapping
-- state-machine pattern:
--   - mapping_status ENUM 4-state (mapped/ff_only/qbo_only/ignored)
--   - UNIQUE on nullable qbo_item_id (InnoDB multi-NULL semantics —
--     many ff_only rows allowed before pull pairs them up)
--   - match_confidence ENUM with item-specific values (includes
--     auto_created for the D-QBO-10-4 operator-confirmed ItemCreator
--     pattern — these mappings carry "FF authored the QBO Item" semantic)
--
-- Item-specific additions:
--   - ff_item_type VARCHAR(60) — the ENUM value (validated at app
--     layer via INFORMATION_SCHEMA introspection; no FK because
--     MySQL doesn't FK ENUMs)
--   - ff_item_type_variant VARCHAR(60) NULL — D-QBO-10-2 GPS net/gross
--     differentiation. NULL for non-variant item_types. UNIQUE is on
--     (ff_item_type, ff_item_type_variant) so the same item_type can
--     have multiple variants (gps+NULL, gps+net, gps+gross) without
--     conflict; the InnoDB multi-NULL UNIQUE behavior allows multiple
--     ff_item_type rows whose variant is NULL only if their
--     ff_item_type differs — exactly what we need.
--   - is_credit_variant TINYINT(1) — D-QBO-10-1 option B: marks the
--     dedicated "Rental Reconciliation Credit" Item row.
--   - presentation_variant ENUM('default','net','gross') NULL —
--     denormalized echo of ff_item_type_variant for index-friendly
--     filtering in the UI; populated only when variant is in the
--     net/gross domain.
--   - qbo_income_account_id / qbo_expense_account_id — snapshots of
--     the QBO Item's IncomeAccountRef / ExpenseAccountRef so the UI
--     can show "this Item posts to <account>" without re-querying QBO.
--
-- @session   S-QBO-10
-- @date      2026-05-21
-- @decisions D-QBO-10-1 (base_rental_reconciliation_credit
--                         representation: option B — dedicated
--                         'Rental Reconciliation Credit' QBO Item;
--                         cleanest drill-down + no FF refactor),
--            D-QBO-10-2 (GPS Item account mapping: TWO QBO Items —
--                         'GPS Service (Net)' + 'GPS Service (Gross)' —
--                         with different IncomeAccountRef per customer
--                         presentation flag; matcher selects variant
--                         at invoice line emission in S-QBO-11),
--            D-QBO-10-3 (ItemCreator pattern: POST /item via
--                         QuickBooksClient::createEntity('item', ...);
--                         Type=Service for all FF item_types — none
--                         are inventory),
--            D-QBO-10-4 (operator-confirmation gate — UI presents
--                         per-row "Create QBO Item" button; never
--                         auto-creates without explicit click;
--                         create_qbo_item.php endpoint gated by
--                         quickbooks.edit_credentials),
--            D-QBO-10-5 (active-only filter — Inactive=false QBO
--                         Items hidden from default UI; "Show inactive
--                         QBO Items" toggle reveals them)
-- ============================================================

START TRANSACTION;

CREATE TABLE `acc_qbo_item_map` (
  `id`                       int unsigned                            NOT NULL AUTO_INCREMENT,
  `ff_item_type`             varchar(60)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'invoice_line_items.item_type ENUM value; NULL = qbo_only state',
  `ff_item_type_variant`     varchar(60)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'D-QBO-10-2: for variant Items like gps+net vs gps+gross. NULL for non-variant types.',
  `qbo_item_id`              varchar(50)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'Intuit Item.Id; NULL = ff_only state',
  `qbo_sync_token`           varchar(20)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL,
  `qbo_name`                 varchar(255) COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'QBO Item.Name snapshot',
  `qbo_fully_qualified_name` varchar(500) COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'QBO Item.FullyQualifiedName (includes parent path for sub-items)',
  `qbo_description`          varchar(500) COLLATE utf8mb4_unicode_ci          DEFAULT NULL,
  `qbo_type`                 varchar(50)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'QBO Item.Type — Service/Inventory/NonInventory/Bundle/Group',
  `qbo_active`               tinyint(1)                                       DEFAULT NULL,
  `qbo_income_account_id`    varchar(50)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'QBO Item.IncomeAccountRef.value snapshot',
  `qbo_income_account_name`  varchar(255) COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT 'QBO Item.IncomeAccountRef.name snapshot',
  `qbo_expense_account_id`   varchar(50)  COLLATE utf8mb4_unicode_ci          DEFAULT NULL,
  `qbo_expense_account_name` varchar(255) COLLATE utf8mb4_unicode_ci          DEFAULT NULL,
  `mapping_status`           enum('mapped','ff_only','qbo_only','ignored')    NOT NULL DEFAULT 'qbo_only',
  `match_confidence`         enum('exact_name','high','medium','manual','auto_created') DEFAULT NULL COMMENT 'auto_created = QBO Item created by FF via ItemCreator (D-QBO-10-4)',
  `is_credit_variant`        tinyint(1)                              NOT NULL DEFAULT 0     COMMENT 'D-QBO-10-1 option B: 1 for the dedicated Rental Reconciliation Credit Item',
  `presentation_variant`     enum('default','net','gross')                    DEFAULT NULL  COMMENT 'D-QBO-10-2: denormalized echo of ff_item_type_variant for index-friendly filtering',
  `match_notes`              text         COLLATE utf8mb4_unicode_ci          DEFAULT NULL,
  `last_synced_at`           datetime                                         DEFAULT NULL,
  `last_pull_at`             datetime                                         DEFAULT NULL,
  `created_at`               datetime                                NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               datetime                                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by_user_id`       int unsigned                                     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_item_type_variant` (`ff_item_type`, `ff_item_type_variant`) COMMENT 'Multi-column unique allows variants — e.g. (gps,NULL), (gps,net), (gps,gross) all coexist',
  UNIQUE KEY `uq_qbo_item`             (`qbo_item_id`),
  KEY `idx_status`                     (`mapping_status`),
  KEY `idx_ff_item_type`               (`ff_item_type`),
  KEY `idx_last_synced`                (`last_synced_at`),
  CONSTRAINT `fk_qbo_item_map_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
