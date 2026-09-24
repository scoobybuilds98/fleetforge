-- S-CHAT-DELETE: "Delete chat" / "Leave group" in Messages.
--
-- WHY: deleting a conversation works the way it does on a phone — it
-- disappears from YOUR Messages only; everyone else (a teammate, the
-- customer, colleagues sharing a customer thread) keeps their copy. If
-- someone writes again it comes back holding only the new messages.
--
-- Stored per reader on conversation_reads (already one row per staff user /
-- portal user per conversation):
--   cleared_message_id NULL  = never deleted
--   cleared_message_id N     = hide messages with id <= N from this reader,
--                              and hide the conversation from their list
--                              until last_message_id > N (0 = deleted while empty)
-- Groups are LEFT instead (conversation_members row removed); the last member
-- to leave deletes the group (lib/Chat/Conversations::deleteForViewer).

ALTER TABLE `conversation_reads`
  ADD COLUMN `cleared_message_id` int unsigned DEFAULT NULL
    COMMENT 'Delete chat: hide messages with id <= this from this reader (NULL = never deleted)'
    AFTER `last_read_message_id`;
