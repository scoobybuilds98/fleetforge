-- S-GPS-RATE-CARD: Move GPS price from lease creation form to rate cards.
-- GPS pricing per day now lives on rate_cards so each customer can have their
-- own GPS daily rate via their customer-specific rate card.
--
-- leases.gps_cost and leases.gps_opt_in are RETAINED (billing engine reads them).
-- At new lease creation the rate card's gps_price is copied into leases.gps_cost.
-- Existing leases are unaffected — their frozen gps_cost stays as-is.

ALTER TABLE rate_cards
    ADD COLUMN `gps_price` decimal(10,2) DEFAULT NULL
        COMMENT 'S-GPS-RATE-CARD: GPS tracking daily rate for leases on this card. NULL = no GPS rate defined.'
    AFTER `description`;
