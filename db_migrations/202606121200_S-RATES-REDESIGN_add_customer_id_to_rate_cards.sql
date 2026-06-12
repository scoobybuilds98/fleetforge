-- S-RATES-REDESIGN: add optional customer_id FK to rate_cards.
-- A NULL customer_id = global rate card (applies to all customers).
-- A non-NULL customer_id = customer-specific rate card visible on
-- that customer's profile and preferred in rate lookup over global cards.
--
-- lookup_rates.php Priority 2 updated separately to prefer customer-specific
-- active cards before falling back to global cards.

ALTER TABLE rate_cards
    ADD COLUMN customer_id INT UNSIGNED NULL DEFAULT NULL AFTER name,
    ADD INDEX  idx_rate_cards_customer_id (customer_id),
    ADD CONSTRAINT fk_rate_cards_customer_id
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
