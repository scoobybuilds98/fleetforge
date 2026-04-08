<?php
declare(strict_types=1);

namespace FleetForge\Messenger;

/**
 * lib/Messenger/MessengerService.php
 *
 * Shared helpers for the customer messenger feature. Endpoints under
 * /api/v1/messenger and /app/portal/api/messenger both call into this
 * class to keep read-cursor and thread-summary logic in one place.
 *
 * Schema:
 *   messenger_threads
 *   messenger_messages
 *   messenger_thread_reads
 *
 * Non-goals:
 *   - This class does NOT enforce permissions. Each endpoint calls
 *     require_auth_api()/require_portal_auth() + require_permission()
 *     before touching anything here.
 *   - This class does NOT send notifications — callers handle that
 *     so the notification type matches the surrounding business action.
 *
 * @session MSGR-1
 */
class MessengerService
{
    // ------------------------------------------------------------
    // READ CURSORS
    // ------------------------------------------------------------

    /**
     * Upsert the read cursor for an admin in a thread.
     *
     * If $lastReadMessageId is null, we look up the newest message in
     * the thread — handy for "mark read" requests from the UI when the
     * admin doesn't know which message id to pin to.
     */
    public static function markReadAdmin(int $threadId, int $adminId, ?int $lastReadMessageId = null): void
    {
        if ($lastReadMessageId === null) {
            $row = \db_row(
                "SELECT MAX(id) AS max_id FROM messenger_messages WHERE thread_id = ?",
                [$threadId]
            );
            $lastReadMessageId = $row && $row['max_id'] !== null ? (int) $row['max_id'] : 0;
        }

        $existing = \db_row(
            "SELECT id FROM messenger_thread_reads
              WHERE thread_id = ? AND admin_user_id = ?",
            [$threadId, $adminId]
        );
        if ($existing) {
            \db_update('messenger_thread_reads', [
                'last_read_message_id' => $lastReadMessageId,
                'last_read_at'         => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $existing['id']]);
        } else {
            \db_insert('messenger_thread_reads', [
                'thread_id'            => $threadId,
                'admin_user_id'        => $adminId,
                'portal_user_id'       => null,
                'last_read_message_id' => $lastReadMessageId,
                'last_read_at'         => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Upsert the read cursor for a portal user in a thread.
     */
    public static function markReadPortal(int $threadId, int $portalUserId, ?int $lastReadMessageId = null): void
    {
        if ($lastReadMessageId === null) {
            $row = \db_row(
                "SELECT MAX(id) AS max_id FROM messenger_messages WHERE thread_id = ?",
                [$threadId]
            );
            $lastReadMessageId = $row && $row['max_id'] !== null ? (int) $row['max_id'] : 0;
        }

        $existing = \db_row(
            "SELECT id FROM messenger_thread_reads
              WHERE thread_id = ? AND portal_user_id = ?",
            [$threadId, $portalUserId]
        );
        if ($existing) {
            \db_update('messenger_thread_reads', [
                'last_read_message_id' => $lastReadMessageId,
                'last_read_at'         => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $existing['id']]);
        } else {
            \db_insert('messenger_thread_reads', [
                'thread_id'            => $threadId,
                'admin_user_id'        => null,
                'portal_user_id'       => $portalUserId,
                'last_read_message_id' => $lastReadMessageId,
                'last_read_at'         => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ------------------------------------------------------------
    // THREAD UPDATES
    // ------------------------------------------------------------

    /**
     * Update the cached "last message" summary on a thread after a new
     * message is inserted. Keeps messenger_threads self-describing so
     * the list endpoint never has to hit messenger_messages to render.
     */
    public static function touchLastMessage(
        int $threadId,
        string $senderType,
        string $bodyPreview
    ): void {
        \db_execute(
            "UPDATE messenger_threads
                SET last_message_at = NOW(),
                    last_message_preview = ?,
                    last_message_by = ?
              WHERE id = ?",
            [mb_substr($bodyPreview, 0, 255), $senderType, $threadId]
        );
    }

    /**
     * Check whether a portal user has permission to read/write a thread.
     *
     * Two rules:
     *   - scope='customer'   → their customer_id must match thread.customer_id
     *   - scope='portal_user'→ thread.portal_user_id must equal the caller id
     *
     * Returns the thread row on success, or null on denial.
     */
    public static function portalCanAccessThread(int $threadId, int $portalUserId, int $customerId): ?array
    {
        $thread = \db_row(
            "SELECT id, customer_id, scope, portal_user_id, is_archived
               FROM messenger_threads
              WHERE id = ?",
            [$threadId]
        );
        if (!$thread) {
            return null;
        }
        if ((int) $thread['is_archived'] === 1) {
            return null;
        }
        if ((int) $thread['customer_id'] !== $customerId) {
            return null;
        }
        if ($thread['scope'] === 'portal_user'
            && (int) $thread['portal_user_id'] !== $portalUserId) {
            return null;
        }
        return $thread;
    }
}
