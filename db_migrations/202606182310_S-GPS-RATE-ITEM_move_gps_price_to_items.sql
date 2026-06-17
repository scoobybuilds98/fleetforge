-- S-GPS-RATE-ITEM: Move gps_price from card-level (rate_cards) to per-item (rate_card_items)
-- so each equipment category row on a card can carry its own GPS daily rate,
-- matching the pattern of daily/weekly/monthly/mileage/hourly rates.
--
-- Step 1: ADD rate_card_items.gps_price (INFORMATION_SCHEMA-guarded, idempotent)
-- Step 2: Backfill — copy the card's gps_price down to every item on that card
-- Step 3: DROP rate_cards.gps_price (guarded by column-exists check)

-- Step 1: Add gps_price to rate_card_items
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'rate_card_items'
      AND COLUMN_NAME  = 'gps_price'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE rate_card_items
       ADD COLUMN `gps_price` decimal(10,2) DEFAULT NULL
           COMMENT 'S-GPS-RATE-ITEM: per-item GPS daily rate ($/day). NULL = no GPS charge for this equipment type.'
       AFTER `hourly_rate`",
    "SELECT 'rate_card_items.gps_price already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Step 2: Backfill — propagate each card's existing gps_price to all its items
-- Only runs if both columns currently exist (safe no-op if already done).
SET @src_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'rate_cards'
      AND COLUMN_NAME  = 'gps_price'
);
SET @dst_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'rate_card_items'
      AND COLUMN_NAME  = 'gps_price'
);
SET @backfill = IF(@src_exists > 0 AND @dst_exists > 0,
    "UPDATE rate_card_items rci
       JOIN rate_cards rc ON rc.id = rci.rate_card_id
     SET rci.gps_price = rc.gps_price
     WHERE rc.gps_price IS NOT NULL
       AND rci.gps_price IS NULL",
    "SELECT 'backfill skipped — source or destination column absent'"
);
PREPARE _stmt FROM @backfill; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Step 3: Drop rate_cards.gps_price
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'rate_cards'
      AND COLUMN_NAME  = 'gps_price'
);
SET @ddl = IF(@col_exists > 0,
    "ALTER TABLE rate_cards DROP COLUMN `gps_price`",
    "SELECT 'rate_cards.gps_price already absent — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
