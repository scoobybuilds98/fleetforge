-- S-CHAT-SEEN-TIME: show WHEN a message was seen ("Seen 3:42 PM").
--
-- WHY a new column: conversation_reads.updated_at can't be used — it also
-- moves when a reader deletes a chat (cleared_message_id) and on any other
-- write to the row, so it would show the wrong time. last_read_at is set by
-- Conversations::markRead() ONLY when last_read_message_id actually advances
-- (a poll re-reading the same message leaves it alone), in the DB's UTC clock.
--
-- Existing rows stay NULL: their read time was never recorded, so those
-- receipts read plain "Seen" rather than a made-up time.

ALTER TABLE `conversation_reads`
  ADD COLUMN `last_read_at` datetime DEFAULT NULL
    COMMENT 'UTC time last_read_message_id last advanced (drives "Seen <time>")'
    AFTER `last_read_message_id`;
