-- ============================================================
-- 033_samsara_entity_type.sql
--
-- SAMSARA-2: Add `samsara_entity_type` column so a unit can be
-- linked to either a Samsara *vehicle* (powered, OBD-driven) or a
-- Samsara *trailer* (unpowered asset gateway). The two entity
-- types live behind different Samsara API paths:
--
--   vehicle  → GET /fleet/vehicles + /fleet/vehicles/stats
--   trailer  → GET /fleet/trailers + /fleet/trailers/stats
--
-- Without this column the sync code can't tell which path to call
-- after the unit is linked. Existing rows default to 'vehicle' so
-- nothing already linked breaks.
--
-- Two tables get the column:
--   1. equipment_units            — drives sync + UI dispatch
--   2. samsara_location_history   — preserved on every breadcrumb
--                                    so we know what the row was at
--                                    the time it was written even
--                                    if a unit later gets re-typed
--
-- @session SAMSARA-2
-- ============================================================

ALTER TABLE equipment_units
    ADD COLUMN samsara_entity_type ENUM('vehicle', 'trailer') NOT NULL DEFAULT 'vehicle'
        COMMENT 'SAMSARA-2: which Samsara API path is used to sync this unit'
        AFTER samsara_vehicle_id;

ALTER TABLE samsara_location_history
    ADD COLUMN samsara_entity_type ENUM('vehicle', 'trailer') NOT NULL DEFAULT 'vehicle'
        COMMENT 'SAMSARA-2: snapshot of entity type at breadcrumb write time'
        AFTER samsara_vehicle_id;
