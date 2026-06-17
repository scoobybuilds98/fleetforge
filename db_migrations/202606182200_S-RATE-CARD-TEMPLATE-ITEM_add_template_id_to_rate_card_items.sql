-- S-RATE-CARD-TEMPLATE-ITEM: Allow multiple rate items per equipment category on a
-- rate card by optionally pinning a row to a specific equipment_template.
--
-- Before: UNIQUE KEY uq_card_type (rate_card_id, equipment_type) — one rate per category per card.
-- After:  equipment_template_id = NULL  = category-level row (applies to all equipment of that type);
--         equipment_template_id = X     = template-specific row (applies ONLY to that template);
--         Lookup priority: template-specific > category fallback, customer card > global card.
--
-- MySQL NULL uniqueness: two rows with template_id=NULL do NOT conflict through uq_card_template
-- (NULL != NULL in UNIQUE indexes) — category-level uniqueness enforced at application layer.
-- uq_card_template DOES enforce no two template-specific rows for the same template on one card.
--
-- ORDER MATTERS: add uq_card_template BEFORE dropping uq_card_type.
-- uq_card_type starts with rate_card_id and backs the FK rate_card_items_ibfk_1.
-- Dropping it first would fail "needed in a foreign key constraint" (MySQL ERROR 1553).
-- Adding uq_card_template first gives MySQL an alternative FK-backing index, then
-- the DROP INDEX succeeds.

ALTER TABLE rate_card_items
    ADD COLUMN `equipment_template_id` int unsigned DEFAULT NULL
        COMMENT 'S-RATE-CARD-TEMPLATE-ITEM: NULL=category-level row; non-NULL=specific template override'
    AFTER `equipment_type`;

ALTER TABLE rate_card_items
    ADD UNIQUE KEY `uq_card_template` (`rate_card_id`, `equipment_template_id`),
    ADD KEY `idx_rci_template_id` (`equipment_template_id`),
    ADD CONSTRAINT `rci_ibfk_2` FOREIGN KEY (`equipment_template_id`)
        REFERENCES `equipment_templates` (`id`) ON DELETE RESTRICT;

ALTER TABLE rate_card_items
    DROP INDEX `uq_card_type`;
