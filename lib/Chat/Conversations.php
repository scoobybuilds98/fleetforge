<?php
declare(strict_types=1);

namespace FleetForge\Chat;

use FleetForge\Notifications\NotificationService;

/**
 * lib/Chat/Conversations.php
 *
 * Messaging core for staff (/chat) and customers (/portal/chat). Every chat
 * endpoint is a thin wrapper around this class so the access rules live in
 * one place.
 *
 * Model (S-CHAT-REBUILD):
 *   Team      — kind 'direct' (exactly two staff, one per pair via direct_key)
 *               or 'group' (named, 2+ staff). Visible to MEMBERS only.
 *   Customers — kind 'customer', ONE thread per customer, like a text thread
 *               with the company. Visible to every staff user with
 *               customers.view (shared inbox) and every active portal user of
 *               that customer. Staff never need to "join" it.
 *
 * Read state: conversation_reads holds a high-water message id per reader.
 * Unread = messages past it that someone ELSE sent. In customer threads a
 * staff reader only counts CUSTOMER messages (a colleague's reply isn't news)
 * and never anything from before their account existed.
 *
 * Seen: receipt() under the newest message, from the same read marks.
 *
 * Delete chat: per reader (conversation_reads.cleared_message_id) — hides
 * my history + drops the chat from my list until something newer arrives;
 * groups are left instead. Nobody else's copy changes (S-CHAT-DELETE).
 *
 * Notifications (bell): coalesced — at most ONE unread "new messages"
 * notification per reader per conversation, so a burst of texts is one
 * bell item, not twenty.
 *
 * Dependencies: includes/db.php, includes/auth.php (can, can_view_financials),
 *               lib/Chat/RecordRefs.php, lib/Notifications/NotificationService.php
 * @session S-CHAT-REBUILD
 */
final class Conversations
{
    public const MAX_BODY  = 4000;
    public const PAGE_SIZE = 40;

    // ── Viewers ──────────────────────────────────────────────────────────

    /** Staff viewer for the logged-in admin session. */
    public static function staffViewer(): array
    {
        $perms = [];
        foreach (RecordRefs::TYPES as $type => [, $module]) {
            $perms[$type] = \can($module, 'view');
        }
        return [
            'side'      => 'staff',
            'user_id'   => (int) \current_user_id(),
            'money'     => \can_view_financials(),
            'perms'     => $perms,
            'customers' => \can('customers', 'view'),
        ];
    }

    public static function portalViewer(int $portalUserId, int $customerId): array
    {
        return ['side' => 'portal', 'portal_user_id' => $portalUserId, 'customer_id' => $customerId];
    }

    // ── Access ───────────────────────────────────────────────────────────

    /** The conversation row if this viewer may open it, else null. */
    public static function find(int $id, array $viewer): ?array
    {
        $cv = \db_row('SELECT * FROM conversations WHERE id = ?', [$id]);
        if (!$cv) return null;

        if ($viewer['side'] === 'portal') {
            return ($cv['kind'] === 'customer' && (int) $cv['customer_id'] === (int) $viewer['customer_id']) ? $cv : null;
        }
        if ($cv['kind'] === 'customer') {
            return $viewer['customers'] ? $cv : null;
        }
        $member = \db_count(
            'SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND user_id = ?',
            [$id, $viewer['user_id']]
        );
        return $member ? $cv : null;
    }

    // ── Lists ────────────────────────────────────────────────────────────

    /**
     * Staff inbox: team conversations I'm in + (with customers.view) every
     * customer thread that has messages. Newest activity first. Chats I
     * deleted stay out until a message newer than my delete arrives.
     *
     * @return array{team: list<array>, customers: list<array>}
     */
    public static function listForStaff(array $viewer): array
    {
        $uid  = $viewer['user_id'];
        $rows = \db_select(
            "SELECT cv.id, cv.kind, cv.title, cv.customer_id, cv.last_message_at, cv.last_message_preview,
                    c.company_name,
                    COALESCE(r.last_read_message_id, 0) AS read_mark,
                    (SELECT COUNT(*) FROM conversation_messages m
                      WHERE m.conversation_id = cv.id
                        AND m.id > GREATEST(COALESCE(r.last_read_message_id, 0), COALESCE(r.cleared_message_id, 0))
                        AND m.deleted_at IS NULL
                        AND (   (cv.kind <> 'customer' AND NOT (m.sender_type = 'staff' AND m.user_id <=> ?))
                             OR (cv.kind = 'customer' AND m.sender_type = 'customer' AND m.created_at >= me.created_at))
                    ) AS unread
               FROM conversations cv
               JOIN users me ON me.id = ?
               LEFT JOIN customers c ON c.id = cv.customer_id
               LEFT JOIN conversation_reads r ON r.conversation_id = cv.id AND r.user_id = ?
              WHERE (   (cv.kind IN ('direct','group')
                         AND EXISTS (SELECT 1 FROM conversation_members cm WHERE cm.conversation_id = cv.id AND cm.user_id = ?))
                     OR (cv.kind = 'customer' AND ? = 1 AND cv.last_message_id IS NOT NULL))
                -- Deleted by me: hidden until someone writes again.
                AND (r.cleared_message_id IS NULL OR COALESCE(cv.last_message_id, 0) > r.cleared_message_id)
              ORDER BY COALESCE(cv.last_message_at, cv.created_at) DESC, cv.id DESC
              LIMIT 300",
            [$uid, $uid, $uid, $uid, $viewer['customers'] ? 1 : 0]
        );

        $members = self::membersFor(array_column(array_filter($rows, fn($r) => $r['kind'] !== 'customer'), 'id'));
        $out = ['team' => [], 'customers' => []];
        foreach ($rows as $r) {
            $item = self::summary($r, $members[(int) $r['id']] ?? [], $uid);
            $out[$r['kind'] === 'customer' ? 'customers' : 'team'][] = $item;
        }
        return $out;
    }

    /** Portal: the customer's single thread (null until anyone has written). */
    public static function portalThread(array $viewer): ?array
    {
        return \db_row(
            "SELECT * FROM conversations WHERE kind = 'customer' AND customer_id = ?",
            [$viewer['customer_id']]
        );
    }

    /** Header info for one conversation (title, members) as the list shows it. */
    public static function header(array $cv, array $viewer): array
    {
        if ($cv['kind'] === 'customer') {
            $c = \db_row('SELECT company_name, contact_name, phone, email FROM customers WHERE id = ?', [$cv['customer_id']]);
            $people = \db_select(
                "SELECT name, email FROM portal_users WHERE customer_id = ? AND status = 'active' ORDER BY is_primary DESC, name",
                [$cv['customer_id']]
            );
            return [
                'id'          => (int) $cv['id'],
                'kind'        => 'customer',
                'title'       => $c['company_name'] ?? 'Customer',
                'subtitle'    => $people
                    ? count($people) . ' portal ' . (count($people) === 1 ? 'user' : 'users') . ' · ' . implode(', ', array_column($people, 'name'))
                    : 'No portal users yet — they\'ll see this when invited',
                'customer_id' => (int) $cv['customer_id'],
                'customer_url'=> \base_url('customers/show?id=' . (int) $cv['customer_id']),
                'members'     => [],
            ];
        }
        $members = self::membersFor([(int) $cv['id']])[(int) $cv['id']] ?? [];
        $s = self::summary($cv + ['company_name' => null, 'unread' => 0], $members, $viewer['user_id']);
        return [
            'id'       => (int) $cv['id'],
            'kind'     => $cv['kind'],
            'title'    => $s['title'],
            'subtitle' => $cv['kind'] === 'group'
                ? count($members) . ' people · ' . implode(', ', array_column($members, 'name'))
                : 'Direct message',
            'members'  => array_values($members),
        ];
    }

    // ── Messages ─────────────────────────────────────────────────────────

    /**
     * Messages in a conversation, oldest → newest. $afterId for polling new
     * ones, $beforeId for "load earlier". Records resolved for THIS viewer.
     *
     * @return array{messages: list<array>, has_more: bool}
     */
    public static function messages(array $cv, array $viewer, int $afterId = 0, int $beforeId = 0): array
    {
        $params = [(int) $cv['id'], self::clearedMark($cv, $viewer)];
        $where  = 'm.conversation_id = ? AND m.id > ?';   // "Delete chat" hides everything up to the reader's mark
        if ($afterId > 0) {
            $where .= ' AND m.id > ?';
            $params[] = $afterId;
        } elseif ($beforeId > 0) {
            $where .= ' AND m.id < ?';
            $params[] = $beforeId;
        }
        $limit = self::PAGE_SIZE + 1;
        $rows  = \db_select(
            "SELECT m.id, m.sender_type, m.user_id, m.portal_user_id, m.body, m.created_at, m.deleted_at,
                    u.name AS staff_name, pu.name AS portal_name
               FROM conversation_messages m
               LEFT JOIN users u ON u.id = m.user_id
               LEFT JOIN portal_users pu ON pu.id = m.portal_user_id
              WHERE {$where}
              ORDER BY m.id " . ($afterId > 0 ? 'ASC' : 'DESC') . " LIMIT {$limit}",
            $params
        );
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);
        if ($afterId <= 0) $rows = array_reverse($rows);

        // Batch-resolve every record on the page (one query per type).
        $refsByMsg = [];
        if ($rows) {
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            foreach (\db_select(
                "SELECT message_id, record_type, record_id FROM conversation_message_records
                  WHERE message_id IN ({$in}) ORDER BY id",
                $ids
            ) as $ref) {
                $refsByMsg[(int) $ref['message_id']][] = ['type' => $ref['record_type'], 'id' => (int) $ref['record_id']];
            }
        }
        $cards = $refsByMsg ? RecordRefs::resolve(array_merge(...array_values($refsByMsg)), $viewer) : [];

        $company = \ff_company_short_name();
        $out = [];
        foreach ($rows as $r) {
            $mine = $viewer['side'] === 'staff'
                ? ($r['sender_type'] === 'staff' && (int) $r['user_id'] === $viewer['user_id'])
                : ($r['sender_type'] === 'customer' && (int) $r['portal_user_id'] === $viewer['portal_user_id']);
            $name = $r['sender_type'] === 'staff'
                ? ($r['staff_name'] ?: 'Former staff')
                : ($r['portal_name'] ?: 'Customer');
            $deleted = $r['deleted_at'] !== null;
            $out[] = [
                'id'          => (int) $r['id'],
                'mine'        => $mine,
                'side'        => $r['sender_type'],
                'sender'      => $name,
                // Customers see which company a staff reply came from.
                'sender_note' => ($viewer['side'] === 'portal' && $r['sender_type'] === 'staff') ? $company : '',
                'initials'    => self::initials($name),
                'body'        => $deleted ? '' : (string) $r['body'],
                'deleted'     => $deleted,
                'created_at'  => (string) $r['created_at'],
                'records'     => $deleted ? [] : array_values(array_map(
                    fn($ref) => $cards["{$ref['type']}:{$ref['id']}"],
                    $refsByMsg[(int) $r['id']] ?? []
                )),
            ];
        }
        return ['messages' => $out, 'has_more' => $afterId > 0 ? false : $hasMore];
    }

    /**
     * Send a message. $refs are validated for this viewer + conversation
     * scope first; returns the new message id or throws \InvalidArgumentException
     * with a user-facing reason.
     */
    public static function send(array $cv, array $viewer, string $body, array $rawRefs): int
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('That message is too long (max ' . self::MAX_BODY . ' characters).');
        }
        $scope = $cv['kind'] === 'customer' ? (int) $cv['customer_id'] : null;
        $refs  = RecordRefs::validateForSend($rawRefs, $viewer, $scope);
        if ($refs === null) {
            throw new \InvalidArgumentException('One of the attached records can\'t be shared here.');
        }
        if ($body === '' && !$refs) {
            throw new \InvalidArgumentException('Type a message or attach a record.');
        }

        $staff   = $viewer['side'] === 'staff';
        $preview = $body !== ''
            ? mb_substr(preg_replace('/\s+/', ' ', $body), 0, 160)
            : self::refsPreview($refs);

        $msgId = \db_transaction(function () use ($cv, $viewer, $staff, $body, $refs, $preview) {
            $id = \db_insert('conversation_messages', [
                'conversation_id' => (int) $cv['id'],
                'sender_type'     => $staff ? 'staff' : 'customer',
                'user_id'         => $staff ? $viewer['user_id'] : null,
                'portal_user_id'  => $staff ? null : $viewer['portal_user_id'],
                'body'            => $body !== '' ? $body : null,
            ]);
            foreach ($refs as $ref) {
                \db_insert('conversation_message_records', [
                    'message_id'  => $id,
                    'record_type' => $ref['type'],
                    'record_id'   => $ref['id'],
                ]);
            }
            // NOW() — the DB session is UTC, same clock as created_at.
            \db_execute(
                'UPDATE conversations SET last_message_id = ?, last_message_at = NOW(), last_message_preview = ? WHERE id = ?',
                [$id, $preview, (int) $cv['id']]
            );
            return $id;
        });

        self::markRead($cv, $viewer, $msgId);
        self::notify($cv, $viewer, $preview);
        return $msgId;
    }

    /** "Unsend" my own message: body + records removed, a placeholder stays. */
    public static function unsend(int $messageId, array $viewer): bool
    {
        $m = \db_row('SELECT * FROM conversation_messages WHERE id = ? AND deleted_at IS NULL', [$messageId]);
        if (!$m) return false;
        $mine = $viewer['side'] === 'staff'
            ? ($m['sender_type'] === 'staff' && (int) $m['user_id'] === $viewer['user_id'])
            : ($m['sender_type'] === 'customer' && (int) $m['portal_user_id'] === $viewer['portal_user_id']);
        if (!$mine || !self::find((int) $m['conversation_id'], $viewer)) return false;

        \db_transaction(function () use ($m) {
            \db_execute('UPDATE conversation_messages SET body = NULL, deleted_at = NOW() WHERE id = ?', [(int) $m['id']]);
            \db_execute('DELETE FROM conversation_message_records WHERE message_id = ?', [(int) $m['id']]);
            \db_execute(
                "UPDATE conversations SET last_message_preview = 'Message unsent' WHERE id = ? AND last_message_id = ?",
                [(int) $m['conversation_id'], (int) $m['id']]
            );
        });
        return true;
    }

    /**
     * "Delete chat" for THIS reader only — like deleting a thread on a phone.
     *   direct / customer: history up to now is hidden from me and the chat
     *     leaves my list; the other side (teammate, customer, colleagues
     *     sharing the customer thread) keeps everything. A newer message
     *     brings it back holding only the new messages.
     *   group: I leave (membership removed, so no access and no more
     *     notifications); the last member to leave deletes the group.
     *
     * @return string 'deleted' | 'left' | 'removed' (group had no one left)
     */
    public static function deleteForViewer(array $cv, array $viewer): string
    {
        $convId = (int) $cv['id'];
        $col    = $viewer['side'] === 'staff' ? 'user_id' : 'portal_user_id';
        $who    = $viewer['side'] === 'staff' ? $viewer['user_id'] : $viewer['portal_user_id'];

        $result = \db_transaction(function () use ($cv, $convId, $col, $who) {
            if ($cv['kind'] === 'group') {
                \db_execute('DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$convId, $who]);
                if (\db_count('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?', [$convId]) === 0) {
                    \db_execute('DELETE FROM conversations WHERE id = ?', [$convId]);   // cascades messages/records/reads
                    return 'removed';
                }
                \db_execute("DELETE FROM conversation_reads WHERE conversation_id = ? AND {$col} = ?", [$convId, $who]);
                return 'left';
            }
            // Read the newest id inside the transaction so a message landing
            // mid-delete stays visible rather than vanishing unseen.
            $last = (int) (\db_row('SELECT last_message_id AS m FROM conversations WHERE id = ? FOR UPDATE', [$convId])['m'] ?? 0);
            // last_read_message_id is left alone: deleting a chat is not reading
            // it, so the sender's "Seen" stays honest (unread counting already
            // uses GREATEST(read, cleared)).
            \db_execute(
                "INSERT INTO conversation_reads (conversation_id, {$col}, last_read_message_id, cleared_message_id) VALUES (?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE
                    cleared_message_id = GREATEST(COALESCE(cleared_message_id, 0), VALUES(cleared_message_id))",
                [$convId, $who, $last]
            );
            return 'deleted';
        });

        \db_execute(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE {$col} = ? AND entity_type = 'conversation' AND entity_id = ? AND is_read = 0",
            [$who, $convId]
        );
        return $result;
    }

    /**
     * "Seen" receipt for the newest message, when it's on the viewer's side —
     * like a phone, it sits under the last message only and disappears once
     * the other side replies. Built from conversation_reads (no extra state):
     *   direct            "Seen" / "Sent"
     *   group             "Seen by Mike, Sara" / "Seen by everyone" / "Sent"
     *   customer (staff)  "Seen by Dana" (the customer's portal users) / "Sent"
     *                     — shown under a colleague's reply too: it's our side
     *   customer (portal) "Seen" (any staff read it) / "Sent" — staff unnamed
     * Unsent (deleted) last message → no receipt.
     *
     * @return array{message_id:int, text:string, seen:bool}|null
     */
    public static function receipt(array $cv, array $viewer): ?array
    {
        $convId = (int) $cv['id'];
        $last = \db_row(
            'SELECT id, sender_type, user_id, portal_user_id, deleted_at
               FROM conversation_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1',
            [$convId]
        );
        if (!$last || $last['deleted_at'] !== null) return null;
        $msgId = (int) $last['id'];

        if ($viewer['side'] === 'portal') {
            if ($last['sender_type'] !== 'customer') return null;
            $seen = \db_count(
                'SELECT COUNT(*) FROM conversation_reads WHERE conversation_id = ? AND user_id IS NOT NULL AND last_read_message_id >= ?',
                [$convId, $msgId]
            ) > 0;
            return ['message_id' => $msgId, 'text' => $seen ? 'Seen' : 'Sent', 'seen' => $seen];
        }

        if ($cv['kind'] === 'customer') {
            if ($last['sender_type'] !== 'staff') return null;
            $readers = \db_select(
                "SELECT pu.name FROM conversation_reads r
                   JOIN portal_users pu ON pu.id = r.portal_user_id AND pu.customer_id = ?
                  WHERE r.conversation_id = ? AND r.last_read_message_id >= ?
                  ORDER BY pu.name",
                [(int) $cv['customer_id'], $convId, $msgId]
            );
            $names = array_column($readers, 'name');
            return ['message_id' => $msgId, 'text' => $names ? 'Seen by ' . self::nameList($names) : 'Sent', 'seen' => (bool) $names];
        }

        // Team: only my own newest message gets a receipt.
        if ($last['sender_type'] !== 'staff' || (int) $last['user_id'] !== $viewer['user_id']) return null;
        $others = \db_select(
            "SELECT u.name, (COALESCE(r.last_read_message_id, 0) >= ?) AS seen
               FROM conversation_members cm
               JOIN users u ON u.id = cm.user_id
               LEFT JOIN conversation_reads r ON r.conversation_id = cm.conversation_id AND r.user_id = cm.user_id
              WHERE cm.conversation_id = ? AND cm.user_id <> ?
              ORDER BY u.name",
            [$msgId, $convId, $viewer['user_id']]
        );
        $seenBy = array_column(array_filter($others, fn($o) => (int) $o['seen'] === 1), 'name');
        if (!$seenBy) return ['message_id' => $msgId, 'text' => 'Sent', 'seen' => false];
        if ($cv['kind'] === 'direct') return ['message_id' => $msgId, 'text' => 'Seen', 'seen' => true];
        $text = count($seenBy) === count($others) ? 'Seen by everyone' : 'Seen by ' . self::nameList($seenBy);
        return ['message_id' => $msgId, 'text' => $text, 'seen' => true];
    }

    /** "Mike", "Mike and Sara", "Mike, Sara and 2 others" — first names keep it short. */
    private static function nameList(array $names): string
    {
        $first = array_map(fn($n) => (string) (preg_split('/\s+/', trim((string) $n))[0] ?? $n), $names);
        if (count($first) === 1) return $first[0];
        if (count($first) <= 3) return implode(', ', array_slice($first, 0, -1)) . ' and ' . end($first);
        return implode(', ', array_slice($first, 0, 2)) . ' and ' . (count($first) - 2) . ' others';
    }

    /** Highest message id this reader deleted (0 = never). */
    private static function clearedMark(array $cv, array $viewer): int
    {
        $col = $viewer['side'] === 'staff' ? 'user_id' : 'portal_user_id';
        $who = $viewer['side'] === 'staff' ? $viewer['user_id'] : $viewer['portal_user_id'];
        $row = \db_row(
            "SELECT cleared_message_id AS c FROM conversation_reads WHERE conversation_id = ? AND {$col} = ?",
            [(int) $cv['id'], $who]
        );
        return (int) ($row['c'] ?? 0);
    }

    /** Advance my read mark (never moves backwards). */
    public static function markRead(array $cv, array $viewer, int $messageId): void
    {
        if ($messageId <= 0) return;
        $col = $viewer['side'] === 'staff' ? 'user_id' : 'portal_user_id';
        $who = $viewer['side'] === 'staff' ? $viewer['user_id'] : $viewer['portal_user_id'];
        \db_execute(
            "INSERT INTO conversation_reads (conversation_id, {$col}, last_read_message_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))",
            [(int) $cv['id'], $who, $messageId]
        );
        // Reading the thread clears its bell item too.
        \db_execute(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE {$col} = ? AND entity_type = 'conversation' AND entity_id = ? AND is_read = 0",
            [$who, (int) $cv['id']]
        );
    }

    // ── Unread totals (badges) ───────────────────────────────────────────

    /** @return array{team:int, customers:int, total:int} */
    public static function staffUnread(array $viewer): array
    {
        $team = $cust = 0;
        foreach (self::listForStaff($viewer) as $bucket => $items) {
            foreach ($items as $i) {
                if ($bucket === 'team') $team += $i['unread']; else $cust += $i['unread'];
            }
        }
        return ['team' => $team, 'customers' => $cust, 'total' => $team + $cust];
    }

    public static function portalUnread(array $viewer): int
    {
        return \db_count(
            "SELECT COUNT(*) FROM conversation_messages m
               JOIN conversations cv ON cv.id = m.conversation_id AND cv.kind = 'customer' AND cv.customer_id = ?
               LEFT JOIN conversation_reads r ON r.conversation_id = cv.id AND r.portal_user_id = ?
              WHERE m.sender_type = 'staff' AND m.deleted_at IS NULL
                AND m.id > GREATEST(COALESCE(r.last_read_message_id, 0), COALESCE(r.cleared_message_id, 0))",
            [$viewer['customer_id'], $viewer['portal_user_id']]
        );
    }

    // ── Starting conversations ───────────────────────────────────────────

    /** Get-or-create the DM between two staff users. */
    public static function openDirect(int $me, int $other): int
    {
        $key = min($me, $other) . ':' . max($me, $other);
        $id  = \db_row('SELECT id FROM conversations WHERE direct_key = ?', [$key]);
        if ($id) return (int) $id['id'];
        try {
            return \db_transaction(function () use ($me, $other, $key) {
                $id = \db_insert('conversations', ['kind' => 'direct', 'direct_key' => $key, 'created_by_user_id' => $me]);
                \db_insert('conversation_members', ['conversation_id' => $id, 'user_id' => $me]);
                \db_insert('conversation_members', ['conversation_id' => $id, 'user_id' => $other]);
                return $id;
            });
        } catch (\PDOException $e) {
            // Two tabs raced to open the same DM — the UNIQUE key picked a winner.
            if ($e->getCode() !== '23000') throw $e;
            return (int) \db_row('SELECT id FROM conversations WHERE direct_key = ?', [$key])['id'];
        }
    }

    /** @param list<int> $memberIds other members (creator added automatically) */
    public static function createGroup(int $me, string $title, array $memberIds): int
    {
        $ids = array_values(array_unique(array_merge([$me], $memberIds)));
        return \db_transaction(function () use ($me, $title, $ids) {
            $id = \db_insert('conversations', ['kind' => 'group', 'title' => $title, 'created_by_user_id' => $me]);
            foreach ($ids as $uid) {
                \db_insert('conversation_members', ['conversation_id' => $id, 'user_id' => $uid]);
            }
            return $id;
        });
    }

    /** Get-or-create a customer's single thread. */
    public static function openCustomer(int $customerId, ?int $createdBy): int
    {
        $row = \db_row("SELECT id FROM conversations WHERE kind = 'customer' AND customer_id = ?", [$customerId]);
        if ($row) return (int) $row['id'];
        try {
            return \db_insert('conversations', ['kind' => 'customer', 'customer_id' => $customerId, 'created_by_user_id' => $createdBy]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            return (int) \db_row("SELECT id FROM conversations WHERE kind = 'customer' AND customer_id = ?", [$customerId])['id'];
        }
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @return array<int, array<int, array{id:int,name:string}>> conversation id → members */
    private static function membersFor(array $convIds): array
    {
        $convIds = array_values(array_filter(array_map('intval', $convIds)));
        if (!$convIds) return [];
        $in  = implode(',', array_fill(0, count($convIds), '?'));
        $out = [];
        foreach (\db_select(
            "SELECT cm.conversation_id, u.id, u.name FROM conversation_members cm
               JOIN users u ON u.id = cm.user_id
              WHERE cm.conversation_id IN ({$in}) ORDER BY u.name",
            $convIds
        ) as $m) {
            $out[(int) $m['conversation_id']][(int) $m['id']] = ['id' => (int) $m['id'], 'name' => (string) $m['name']];
        }
        return $out;
    }

    private static function summary(array $r, array $members, int $me): array
    {
        $others = array_values(array_filter($members, fn($m) => $m['id'] !== $me));
        $title  = match ($r['kind']) {
            'customer' => (string) ($r['company_name'] ?? 'Customer'),
            'group'    => (string) ($r['title'] ?: implode(', ', array_column($others, 'name'))),
            default    => (string) ($others[0]['name'] ?? 'Just you'),
        };
        return [
            'id'       => (int) $r['id'],
            'kind'     => $r['kind'],
            'title'    => $title,
            'initials' => $r['kind'] === 'group' ? '#' : self::initials($title),
            'preview'  => (string) ($r['last_message_preview'] ?? ''),
            'last_at'  => $r['last_message_at'],
            'unread'   => (int) ($r['unread'] ?? 0),
            'customer_id' => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
        ];
    }

    private static function refsPreview(array $refs): string
    {
        $kinds = array_map(fn($r) => RecordRefs::TYPES[$r['type']][0], $refs);
        return count($refs) === 1 ? 'Shared a ' . strtolower($kinds[0]) : 'Shared ' . count($refs) . ' records';
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $i = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1));
        if (count($parts) > 1) $i .= mb_strtoupper(mb_substr((string) end($parts), 0, 1));
        return $i !== '' ? $i : '·';
    }

    /**
     * Bell notifications, coalesced to one unread item per reader per
     * conversation. Never throws — a notification failure must not fail
     * the send.
     */
    private static function notify(array $cv, array $viewer, string $preview): void
    {
        try {
            require_once \FF_ROOT . '/lib/Notifications/NotificationService.php';
            $convId = (int) $cv['id'];
            $pending = fn(string $col, array $ids) => $ids ? array_map('intval', array_column(\db_select(
                "SELECT DISTINCT {$col} AS id FROM notifications
                  WHERE {$col} IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                    AND entity_type = 'conversation' AND entity_id = ? AND is_read = 0 AND deleted_at IS NULL",
                array_merge($ids, [$convId])
            ), 'id')) : [];

            if ($viewer['side'] === 'staff' && $cv['kind'] === 'customer') {
                // Staff → customer: the customer's portal users (unless one is already waiting).
                $pu = array_map('intval', array_column(\db_select(
                    "SELECT id FROM portal_users WHERE customer_id = ? AND status = 'active'",
                    [(int) $cv['customer_id']]
                ), 'id'));
                if ($pu && !$pending('portal_user_id', $pu)) {
                    NotificationService::notifyPortal(
                        'chat.message', (int) $cv['customer_id'],
                        'New message from ' . \ff_company_short_name(), $preview,
                        'conversation', $convId, \base_url('portal/chat')
                    );
                }
                return;
            }

            $sender = $viewer['side'] === 'staff'
                ? (string) (\current_user()['name'] ?? 'A teammate')
                : (string) (\db_row('SELECT name FROM portal_users WHERE id = ?', [$viewer['portal_user_id']])['name'] ?? 'Customer');

            if ($cv['kind'] === 'customer') {
                // Customer → staff: whoever has replied in this thread before.
                // Everyone else with access still sees the Chat badge.
                $targets = array_map('intval', array_column(\db_select(
                    "SELECT DISTINCT user_id FROM conversation_messages
                      WHERE conversation_id = ? AND sender_type = 'staff' AND user_id IS NOT NULL",
                    [$convId]
                ), 'user_id'));
                $company = (string) (\db_row('SELECT company_name FROM customers WHERE id = ?', [(int) $cv['customer_id']])['company_name'] ?? 'a customer');
                $title   = "{$sender} ({$company})";
            } else {
                $targets = array_map('intval', array_column(\db_select(
                    'SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id <> ?',
                    [$convId, $viewer['user_id']]
                ), 'user_id'));
                $title = $cv['kind'] === 'group' ? "{$sender} in " . ($cv['title'] ?: 'a group') : $sender;
            }
            $targets = array_values(array_diff($targets, $pending('user_id', $targets)));
            if ($targets) {
                NotificationService::notify(
                    'chat.message', 'New message: ' . $title, $preview,
                    'conversation', $convId, \base_url('chat?c=' . $convId), $targets
                );
            }
        } catch (\Throwable $e) {
            error_log('[Chat] notify failed for conversation ' . ($cv['id'] ?? '?') . ': ' . $e->getMessage());
        }
    }
}
