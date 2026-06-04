-- ============================================================
-- Migration: 202606050000 — add 'combo' to equipment_templates.category enum
--
-- Adds 'combo' (combination unit — tractor + trailer together) as a
-- selectable category on equipment templates.
-- ============================================================

ALTER TABLE equipment_templates
    MODIFY COLUMN category
        ENUM('chassis','dry_van','reefer','container','flatbed','step_deck','lowboy','tanker','dump','combo','other')
        NOT NULL;
