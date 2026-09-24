<?php
declare(strict_types=1);

/**
 * lib/Attention/AttentionService.php
 *
 * The "Needs attention" engine (S-ATTENTION-INBOX): one shared item per
 * problem, kept until the problem is fixed.
 *
 * Lifecycle of an item (attention_items):
 *
 *   raise()  — a check or event says the problem exists.
 *              No live item for the key  → INSERT (opened).
 *              Live + open/snoozed       → refresh text; "worse" if the
 *                                          priority or stage went up (a
 *                                          snoozed item wakes when it gets
 *                                          worse).
 *              Live + done (a person closed it but the problem is still
 *              there)                    → stays done unless WORSE, then it
 *                                          reopens. This is what stops the
 *                                          old every-other-day repeats while
 *                                          still catching "it got worse".
 *   clear()  — the problem is gone → resolved (or, for a done item, just
 *              marked cleared so the next occurrence opens a fresh item).
 *   act()    — people: take / release / give to / snooze / wake / done /
 *              reopen / note. Shared: everyone in the audience sees the
 *              result and the history.
 *   sweepAll() — hourly cron: re-check every truth-backed kind, resolve
 *              stale event-only items, wake snoozed items, escalate urgent
 *              items nobody has taken.
 *
 * "At most one live item per problem" is enforced by the database
 * (UNIQUE live_key) — raise() treats a duplicate-key error as "someone else
 * just inserted it" and falls through to the update path.
 *
 * Times: every DATETIME is UTC (ff_now_utc / UTC_TIMESTAMP); labels shown to
 * people are converted to the business timezone.
 *
 * Nothing here throws into a caller's business transaction from the
 * notification path — NotificationRouter wraps calls in try/catch.
 *
 * Required by: lib/Attention/NotificationRouter.php, api/v1/attention/*,
 *              cron/attention_sweep.php, includes/topbar.php,
 *              app/admin/notifications/index.php, app/admin/dashboard/index.php
 * Defines:     FleetForge\Attention\AttentionService
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention;

use FleetForge\Attention\Kinds\Kind;

final class AttentionService
{
    /** Rank for "got worse" comparisons. */
    private const PRIORITY_RANK = ['todo' => 1, 'urgent' => 2];

    /** Actions a person can take on an item (act()). */
    public const ACTIONS = ['take', 'release', 'assign', 'snooze', 'wake', 'done', 'reopen', 'note'];

    /**
     * Callbacks run when an item opens, reopens, becomes urgent or escalates.
     * Delivery channels (WhatsApp) subscribe here so this engine doesn't
     * depend on them. Signature: fn(string $event, array $itemRow, Kind $kind).
     *
     * @var callable[]
     */
    private static array $listeners = [];

    // ────────────────────────────────────────────────────────────────────────
    // Listeners
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Subscribe to item events ('opened', 'reopened', 'urgent', 'escalated').
     *
     * @param  callable $fn fn(string $event, array $itemRow, Kind $kind): void
     * @return void
     */
    public static function listen(callable $fn): void
    {
        self::$listeners[] = $fn;
    }

    /**
     * Drop every listener (tests).
     *
     * @return void
     */
    public static function resetListeners(): void
    {
        self::$listeners = [];
    }

    /**
     * Run listeners; a failing listener never breaks the item write.
     *
     * @param  string $event
     * @param  array  $row
     * @param  Kind   $kind
     * @return void
     */
    private static function fire(string $event, array $row, Kind $kind): void
    {
        foreach (self::$listeners as $fn) {
            try {
                $fn($event, $row, $kind);
            } catch (\Throwable $e) {
                error_log('[Attention] listener failed on ' . $event . ' #' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Raise / clear / refresh
    // ────────────────────────────────────────────────────────────────────────

    /**
     * The unique key of a problem: "<kind>:<entity_type>:<entity_id>".
     *
     * @param  Kind     $kind
     * @param  int|null $entityId  null/0 for system-wide problems
     * @return string
     */
    public static function itemKey(Kind $kind, ?int $entityId): string
    {
        return $kind->key() . ':' . $kind->entityType() . ':' . (int) ($entityId ?? 0);
    }

    /**
     * Record that a problem exists (create or refresh its one live item).
     *
     * @param  Kind     $kind
     * @param  int|null $entityId
     * @param  array    $data      Item data (see Kind docblock)
     * @param  int[]    $audience  Specific users who should also see it
     * @return array{id: int, event: string}  event: opened|reopened|worse|seen|unchanged
     */
    public static function raise(Kind $kind, ?int $entityId, array $data, array $audience = []): array
    {
        $key      = self::itemKey($kind, $entityId);
        $title    = mb_substr(trim((string) ($data['title'] ?? $kind->label())), 0, 300);
        $facts    = self::normalizeFacts($data['facts'] ?? []);
        $url      = mb_substr(trim((string) ($data['url'] ?? '')), 0, 500);
        $stage    = isset($data['stage']) && $data['stage'] !== '' ? (string) $data['stage'] : null;
        $priority = KindRegistry::priorityFor($kind, isset($data['priority']) ? (string) $data['priority'] : null);
        // A kind can name its own audience (e.g. customer requests use the
        // Portal Requests routing), merged with any the event supplied.
        $audience = self::cleanIds(array_merge($audience, is_array($data['audience'] ?? null) ? $data['audience'] : []));
        $now      = \ff_now_utc();

        $row = self::liveRow($key);

        if ($row === null) {
            try {
                $id = \db_insert('attention_items', [
                    'item_key'          => $key,
                    'kind'              => $kind->key(),
                    'priority'          => $priority,
                    'stage'             => $stage,
                    'entity_type'       => $kind->entityType(),
                    'entity_id'         => $entityId ?: null,
                    'title'             => $title,
                    'facts'             => json_encode($facts, JSON_UNESCAPED_UNICODE),
                    'url'               => $url !== '' ? $url : null,
                    'audience_user_ids' => $audience ? json_encode($audience) : null,
                    'status'            => 'open',
                    'first_seen_at'     => $now,
                    'last_seen_at'      => $now,
                    'urgent_since'      => $priority === 'urgent' ? $now : null,
                ]);
                self::log($id, 'opened', null, null);
                self::fire('opened', self::row($id) ?? [], $kind);
                return ['id' => $id, 'event' => 'opened'];
            } catch (\PDOException $e) {
                // WHY: two writers (e.g. the hourly sweep and a live event)
                // can race to open the same problem. The UNIQUE live_key
                // makes the second INSERT fail; it then updates the row the
                // first one created instead of opening a duplicate.
                if (!self::isDuplicate($e)) {
                    throw $e;
                }
                $row = self::liveRow($key);
                if ($row === null) {
                    throw $e;
                }
            }
        }

        $id        = (int) $row['id'];
        $worse     = self::isWorse($kind, $row, $priority, $stage);
        $becameUrg = $priority === 'urgent' && $row['priority'] !== 'urgent';
        $merged    = self::cleanIds(array_merge(self::decodeIds($row['audience_user_ids']), $audience));

        $update = [
            'title'             => $title,
            'facts'             => json_encode($facts, JSON_UNESCAPED_UNICODE),
            'url'               => $url !== '' ? $url : $row['url'],
            'stage'             => $stage,
            'priority'          => $priority,
            'audience_user_ids' => $merged ? json_encode($merged) : null,
            'last_seen_at'      => $now,
        ];
        // Escalation clock: runs only while urgent, restarts when an item
        // (re)becomes urgent so a long-open to-do that turns urgent gets the
        // full grace period before it escalates.
        if ($priority !== 'urgent') {
            $update['urgent_since'] = null;
            $update['escalated_at'] = null;
        } elseif ($becameUrg) {
            $update['urgent_since'] = $now;
            $update['escalated_at'] = null;
        }

        $event = 'seen';

        if ($row['status'] === 'done') {
            if (!$worse) {
                // Still there, but a person already dealt with it — keep it
                // closed and quiet; just keep its text current.
                \db_update('attention_items', $update, 'id = ?', [$id]);
                return ['id' => $id, 'event' => 'unchanged'];
            }
            $update += [
                'status'            => 'open',
                'closed_at'         => null,
                'closed_by_user_id' => null,
                'close_note'        => null,
                'snoozed_until'     => null,
            ];
            if ($priority === 'urgent') {
                $update['urgent_since'] = $now;
                $update['escalated_at'] = null;
            }
            $event = 'reopened';
        } elseif ($worse) {
            // A snoozed item that gets worse comes back now rather than on
            // its snooze date — "snoozed" must never hide a new emergency.
            if ($row['status'] === 'snoozed') {
                $update['status']        = 'open';
                $update['snoozed_until'] = null;
            }
            $event = 'worse';
        }

        \db_update('attention_items', $update, 'id = ?', [$id]);

        if ($event === 'reopened' || $event === 'worse') {
            self::log($id, $event, null, self::worseNote($kind, $row, $priority, $stage));
        }

        if ($event === 'reopened' || ($event === 'worse' && $becameUrg)) {
            self::fire($event === 'reopened' ? 'reopened' : 'urgent', self::row($id) ?? [], $kind);
        }

        return ['id' => $id, 'event' => $event];
    }

    /**
     * Record that a problem is gone.
     *
     * @param  Kind     $kind
     * @param  int|null $entityId
     * @param  string   $why   History note ("Invoice paid", "Fixed")
     * @return bool            True when a live item was closed/cleared
     */
    public static function clear(Kind $kind, ?int $entityId, string $why = 'Fixed'): bool
    {
        $row = self::liveRow(self::itemKey($kind, $entityId));
        if ($row === null) {
            return false;
        }
        $now = \ff_now_utc();
        $id  = (int) $row['id'];

        if ($row['status'] === 'done') {
            // Already closed by a person; now the problem itself is gone, so
            // the NEXT occurrence opens a fresh item instead of staying muted.
            \db_update('attention_items', ['cleared_at' => $now], 'id = ?', [$id]);
            return true;
        }

        \db_update('attention_items', [
            'status'            => 'resolved',
            'closed_at'         => $now,
            'closed_by_user_id' => null,
            'close_note'        => mb_substr($why, 0, 500),
            'cleared_at'        => $now,
            'snoozed_until'     => null,
        ], 'id = ?', [$id]);
        self::log($id, 'resolved', null, $why);
        return true;
    }

    /**
     * Re-check one entity for a truth-backed kind and raise or clear.
     * Event-only kinds are ignored (nothing to check).
     *
     * @param  Kind  $kind
     * @param  int   $entityId  0 for system-wide kinds (QuickBooks)
     * @param  int[] $audience  Extra audience to merge when raising
     * @return string           'raised'|'cleared'|'none'
     */
    public static function refresh(Kind $kind, int $entityId, array $audience = []): string
    {
        if (!$kind->hasTruth() || $entityId < 0) {
            return 'none';
        }
        $data = $kind->evaluate($entityId);
        if ($data !== null) {
            self::raise($kind, $entityId, $data, $audience);
            return 'raised';
        }
        return self::clear($kind, $entityId, 'Fixed') ? 'cleared' : 'none';
    }

    /**
     * Fire-and-forget re-check for screens that FIX a problem without firing
     * a notification (reviewing a credit application, replying to a customer
     * request, switching a customer's emails back on, editing a unit's
     * document dates). The item closes the moment the fix is saved instead
     * of at the next hourly sweep. Never throws — the fix itself must never
     * fail because of the attention list.
     *
     * @param  string $kindKey
     * @param  int    $entityId
     * @return void
     */
    public static function recheck(string $kindKey, int $entityId): void
    {
        try {
            $kind = KindRegistry::get($kindKey);
            if ($kind !== null) {
                self::refresh($kind, $entityId);
            }
        } catch (\Throwable $e) {
            error_log('[Attention] recheck ' . $kindKey . ' #' . $entityId . ' failed: ' . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Sweep (cron)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Full re-check of one truth-backed kind: raise every current problem,
     * resolve every live item whose problem is gone.
     *
     * @param  Kind $kind
     * @return array{open: int, changed: int, cleared: int}
     *         open = problems that exist now; changed = items that opened,
     *         reopened or got worse on this pass; cleared = items closed
     */
    public static function sweepKind(Kind $kind): array
    {
        $open = 0;
        $changed = 0;
        $cleared = 0;
        if (!$kind->hasTruth()) {
            return compact('open', 'changed', 'cleared');
        }

        $current = $kind->evaluateAll();
        foreach ($current as $entityId => $data) {
            $r = self::raise($kind, (int) $entityId, $data);
            $open++;
            if (in_array($r['event'], ['opened', 'reopened', 'worse'], true)) {
                $changed++;
            }
        }

        $live = \db_select(
            'SELECT entity_id FROM attention_items WHERE kind = ? AND cleared_at IS NULL',
            [$kind->key()]
        );
        foreach ($live as $r) {
            $eid = (int) $r['entity_id'];
            if (!array_key_exists($eid, $current) && self::clear($kind, $eid, 'Fixed')) {
                $cleared++;
            }
        }
        return compact('open', 'changed', 'cleared');
    }

    /**
     * Resolve event-only items that stopped recurring (Kind::staleAfterDays).
     *
     * @param  Kind $kind
     * @return int  Items resolved
     */
    public static function expireStale(Kind $kind): int
    {
        $days = $kind->staleAfterDays();
        if ($days === null || $kind->hasTruth()) {
            return 0;
        }
        $rows = \db_select(
            'SELECT entity_id FROM attention_items
              WHERE kind = ? AND cleared_at IS NULL AND last_seen_at < ?',
            [$kind->key(), \ff_now_utc('-' . $days . ' days')]
        );
        $n = 0;
        foreach ($rows as $r) {
            $eid = $r['entity_id'] === null ? null : (int) $r['entity_id'];
            if (self::clear($kind, $eid, 'Not seen again for ' . $days . ' days')) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Snoozed items whose date has come: back to open.
     *
     * @return int
     */
    public static function wakeSnoozed(): int
    {
        $rows = \db_select(
            "SELECT id FROM attention_items
              WHERE status = 'snoozed' AND snoozed_until IS NOT NULL AND snoozed_until <= UTC_TIMESTAMP()"
        );
        foreach ($rows as $r) {
            \db_update('attention_items', ['status' => 'open', 'snoozed_until' => null], 'id = ?', [(int) $r['id']]);
            self::log((int) $r['id'], 'woke', null, 'Snooze ended');
        }
        return count($rows);
    }

    /**
     * Urgent items that nobody has taken for escalate_after_hours (default 24)
     * get escalated ONCE: flagged in the list and handed to listeners
     * (WhatsApp to the owner). Snoozed items are left alone — snoozing is a
     * deliberate decision.
     *
     * @return array[] The escalated item rows
     */
    public static function escalate(): array
    {
        $hours = max(1, (int) \settings_get('notifications.escalate_after_hours', '24'));
        $rows = \db_select(
            "SELECT * FROM attention_items
              WHERE status = 'open' AND priority = 'urgent'
                AND assigned_user_id IS NULL AND escalated_at IS NULL
                AND urgent_since IS NOT NULL AND urgent_since <= ?",
            [\ff_now_utc('-' . $hours . ' hours')]
        );
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            \db_update('attention_items', ['escalated_at' => \ff_now_utc()], 'id = ?', [$id]);
            self::log($id, 'escalated', null, 'Nobody took this within ' . $hours . ' hours');
            $row  = self::row($id) ?? $r;
            $kind = KindRegistry::get((string) $r['kind']);
            if ($kind) {
                self::fire('escalated', $row, $kind);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Everything the hourly cron does, each step isolated so one failing kind
     * can't stop the rest.
     *
     * @return array<string, mixed> Per-step stats / errors for the cron log
     */
    public static function sweepAll(): array
    {
        $stats = ['kinds' => [], 'errors' => []];
        foreach (KindRegistry::all() as $kind) {
            try {
                if ($kind->hasTruth()) {
                    $stats['kinds'][$kind->key()] = self::sweepKind($kind);
                } elseif ($kind->staleAfterDays() !== null) {
                    $stats['kinds'][$kind->key()] = ['expired' => self::expireStale($kind)];
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = $kind->key() . ': ' . $e->getMessage();
                error_log('[Attention] sweep ' . $kind->key() . ' failed: ' . $e->getMessage());
            }
        }
        try {
            $stats['woke'] = self::wakeSnoozed();
        } catch (\Throwable $e) {
            $stats['errors'][] = 'wake: ' . $e->getMessage();
        }
        try {
            $stats['escalated'] = count(self::escalate());
        } catch (\Throwable $e) {
            $stats['errors'][] = 'escalate: ' . $e->getMessage();
        }
        return $stats;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Reading (per user)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Role slug for a user id (for cron/CLI contexts without a session).
     *
     * @param  int $userId
     * @return string  '' when unknown
     */
    public static function roleSlugFor(int $userId): string
    {
        $r = \db_row(
            'SELECT r.slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = ?',
            [$userId]
        );
        return (string) ($r['slug'] ?? '');
    }

    /**
     * SQL condition limiting items to the ones a user may see: their role is
     * in the kind's audience, or they're named in audience_user_ids.
     * super_admin sees everything.
     *
     * @param  int    $userId
     * @param  string $roleSlug
     * @param  array  $params  Bound parameters are appended here
     * @param  string $alias   Table alias
     * @return string
     */
    public static function visibilitySql(int $userId, string $roleSlug, array &$params, string $alias = 'ai'): string
    {
        if ($roleSlug === 'super_admin') {
            return '1=1';
        }
        $parts = [];
        $kinds = KindRegistry::kindsForRole($roleSlug);
        if ($kinds) {
            $parts[] = "{$alias}.kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ')';
            array_push($params, ...$kinds);
        }
        $parts[] = "JSON_CONTAINS(COALESCE({$alias}.audience_user_ids, JSON_ARRAY()), CAST(? AS JSON))";
        $params[] = (string) $userId;
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * SQL condition for "open right now": open, or snoozed past its date (the
     * hourly cron flips those back; reading them as open avoids an hour of lag).
     *
     * @param  string $alias
     * @return string
     */
    public static function effectiveOpenSql(string $alias = 'ai'): string
    {
        return "({$alias}.status = 'open' OR ({$alias}.status = 'snoozed' AND {$alias}.snoozed_until <= UTC_TIMESTAMP()))";
    }

    /**
     * Badge numbers for one user.
     *
     * @param  int    $userId
     * @param  string $roleSlug
     * @return array{total: int, urgent: int, mine: int}
     */
    public static function counts(int $userId, string $roleSlug): array
    {
        $visParams = [];
        $vis = self::visibilitySql($userId, $roleSlug, $visParams);
        // Placeholder order: the SELECT's "assigned = ?" comes first.
        $r = \db_row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(ai.priority = 'urgent'), 0) AS urgent,
                    COALESCE(SUM(ai.assigned_user_id = ?), 0) AS mine
               FROM attention_items ai
              WHERE " . self::effectiveOpenSql() . " AND {$vis}",
            array_merge([$userId], $visParams)
        );
        return [
            'total'  => (int) ($r['total'] ?? 0),
            'urgent' => (int) ($r['urgent'] ?? 0),
            'mine'   => (int) ($r['mine'] ?? 0),
        ];
    }

    /**
     * Everything the bell needs: Needs attention counts + unread updates.
     * Never throws (the bell must never break a page).
     *
     * @param  int    $userId
     * @param  string $roleSlug
     * @return array{total: int, urgent: int, mine: int, updates_unread: int}
     */
    public static function badge(int $userId, string $roleSlug): array
    {
        $out = ['total' => 0, 'urgent' => 0, 'mine' => 0, 'updates_unread' => 0];
        try {
            $out = array_merge($out, self::counts($userId, $roleSlug));
        } catch (\Throwable $e) {
            error_log('[Attention] badge counts failed: ' . $e->getMessage());
        }
        try {
            $out['updates_unread'] = \db_count(
                'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL',
                [$userId]
            );
        } catch (\Throwable) {
            // leave 0
        }
        return $out;
    }

    /**
     * Items for one user.
     *
     * @param  int    $userId
     * @param  string $roleSlug
     * @param  array  $f  view: open|snoozed|closed; owner: all|mine|free;
     *                    kind: key|''; priority: urgent|todo|''; q: search;
     *                    limit (1-200), offset
     * @return array{items: array[], total: int}
     */
    public static function listFor(int $userId, string $roleSlug, array $f = []): array
    {
        $view   = (string) ($f['view'] ?? 'open');
        $view   = in_array($view, ['open', 'snoozed', 'closed'], true) ? $view : 'open';
        $owner  = (string) ($f['owner'] ?? 'all');
        $owner  = in_array($owner, ['all', 'mine', 'free'], true) ? $owner : 'all';
        $limit  = max(1, min(200, (int) ($f['limit'] ?? 50)));
        $offset = max(0, (int) ($f['offset'] ?? 0));

        $params = [];
        $where  = [self::visibilitySql($userId, $roleSlug, $params)];

        if ($view === 'open') {
            $where[] = self::effectiveOpenSql();
        } elseif ($view === 'snoozed') {
            $where[] = "ai.status = 'snoozed' AND ai.snoozed_until > UTC_TIMESTAMP()";
        } else {
            $where[] = "ai.status IN ('done','resolved') AND ai.closed_at >= ?";
            $params[] = \ff_now_utc('-60 days');
        }
        if ($owner === 'mine') {
            $where[] = 'ai.assigned_user_id = ?';
            $params[] = $userId;
        } elseif ($owner === 'free') {
            $where[] = 'ai.assigned_user_id IS NULL';
        }
        if (!empty($f['kind']) && KindRegistry::get((string) $f['kind'])) {
            $where[] = 'ai.kind = ?';
            $params[] = (string) $f['kind'];
        }
        if (in_array($f['priority'] ?? '', ['urgent', 'todo'], true)) {
            $where[] = 'ai.priority = ?';
            $params[] = $f['priority'];
        }
        if (trim((string) ($f['q'] ?? '')) !== '') {
            $where[] = 'ai.title LIKE ?';
            $params[] = '%' . trim((string) $f['q']) . '%';
        }

        $whereSql = implode(' AND ', $where);
        $order = $view === 'closed'
            ? 'ai.closed_at DESC, ai.id DESC'
            : "(ai.priority = 'urgent') DESC, (ai.escalated_at IS NOT NULL) DESC,
               COALESCE(ai.urgent_since, ai.first_seen_at) ASC, ai.id ASC";

        $total = \db_count("SELECT COUNT(*) FROM attention_items ai WHERE {$whereSql}", $params);
        $rows  = \db_select(
            "SELECT ai.*, ua.name AS assigned_name, uc.name AS closed_by_name
               FROM attention_items ai
               LEFT JOIN users ua ON ua.id = ai.assigned_user_id
               LEFT JOIN users uc ON uc.id = ai.closed_by_user_id
              WHERE {$whereSql}
              ORDER BY {$order}
              LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return ['items' => $rows, 'total' => $total];
    }

    /**
     * One item row with names, if the user may see it.
     *
     * @param  int    $itemId
     * @param  int    $userId
     * @param  string $roleSlug
     * @return array|null
     */
    public static function findFor(int $itemId, int $userId, string $roleSlug): ?array
    {
        $params = [];
        $vis = self::visibilitySql($userId, $roleSlug, $params);
        return \db_row(
            "SELECT ai.*, ua.name AS assigned_name, uc.name AS closed_by_name
               FROM attention_items ai
               LEFT JOIN users ua ON ua.id = ai.assigned_user_id
               LEFT JOIN users uc ON uc.id = ai.closed_by_user_id
              WHERE ai.id = ? AND {$vis}",
            array_merge([$itemId], $params)
        );
    }

    /**
     * An item's history, oldest first.
     *
     * @param  int $itemId
     * @return array[] {action, note, who, at (UTC), at_label}
     */
    public static function events(int $itemId): array
    {
        $rows = \db_select(
            'SELECT e.action, e.note, e.created_at, u.name AS who
               FROM attention_item_events e
               LEFT JOIN users u ON u.id = e.user_id
              WHERE e.item_id = ?
              ORDER BY e.id ASC',
            [$itemId]
        );
        return array_map(static fn(array $e) => [
            'action'   => $e['action'],
            'note'     => $e['note'],
            'who'      => $e['who'] ?? 'FleetForge',
            'at'       => $e['created_at'],
            'at_label' => self::localLabel((string) $e['created_at']),
        ], $rows);
    }

    /**
     * Shape one row for the API/UI. Money facts are dropped here, at serve
     * time, for users without payments:view.
     *
     * @param  array $row       attention_items row (+ assigned_name, closed_by_name)
     * @param  int   $userId    Viewer
     * @param  bool  $canMoney  Viewer may see money
     * @return array
     */
    public static function present(array $row, int $userId, bool $canMoney): array
    {
        $kind  = KindRegistry::get((string) $row['kind']);
        $facts = [];
        foreach (json_decode((string) ($row['facts'] ?? '[]'), true) ?: [] as $f) {
            if (!is_array($f) || !isset($f['t'])) {
                continue;
            }
            if (!empty($f['m']) && !$canMoney) {
                continue;
            }
            $facts[] = (string) $f['t'];
        }

        $status = (string) $row['status'];
        if ($status === 'snoozed' && $row['snoozed_until'] !== null && strtotime($row['snoozed_until'] . ' UTC') <= time()) {
            $status = 'open';
        }
        $live = $row['cleared_at'] === null;

        return [
            'id'              => (int) $row['id'],
            'kind'            => (string) $row['kind'],
            'kind_label'      => $kind ? $kind->label() : (string) $row['kind'],
            'area'            => $kind ? $kind->area() : 'system',
            'priority'        => (string) $row['priority'],
            'title'           => (string) $row['title'],
            'facts'           => $facts,
            'url'             => $row['url'],
            'status'          => $status,
            'is_live'         => $live,
            'assigned'        => $row['assigned_user_id'] !== null ? [
                'id'    => (int) $row['assigned_user_id'],
                'name'  => (string) ($row['assigned_name'] ?? 'Someone'),
                'is_me' => (int) $row['assigned_user_id'] === $userId,
            ] : null,
            'snoozed_until'   => $status === 'snoozed' ? $row['snoozed_until'] : null,
            'snoozed_label'   => $status === 'snoozed' && $row['snoozed_until'] ? self::localLabel((string) $row['snoozed_until'], 'D j M') : null,
            'first_seen_at'   => $row['first_seen_at'],
            'age'             => self::ageLabel((string) $row['first_seen_at']),
            'escalated'       => $row['escalated_at'] !== null && $status === 'open',
            'closed_at'       => $row['closed_at'],
            'closed_label'    => $row['closed_at'] ? self::localLabel((string) $row['closed_at']) : null,
            'closed_by'       => $row['closed_by_user_id'] !== null ? (string) ($row['closed_by_name'] ?? 'Someone') : null,
            'close_note'      => $row['close_note'],
            'done_needs_note' => $kind !== null && $kind->doneNeedsNote() && $live,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // People acting on items
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Apply a person's action to an item they can see.
     *
     * @param  int    $itemId
     * @param  int    $userId
     * @param  string $roleSlug
     * @param  string $action  One of ACTIONS
     * @param  array  $opts    snooze: until = tomorrow|monday|week|YYYY-MM-DD;
     *                         done/note: note; assign: user_id
     * @return array  The updated item row (+ names)
     * @throws \InvalidArgumentException  Bad action/input (message is user-facing)
     * @throws \DomainException           Item missing, not visible or wrong state
     */
    public static function act(int $itemId, int $userId, string $roleSlug, string $action, array $opts = []): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown action.');
        }
        $row = self::findFor($itemId, $userId, $roleSlug);
        if ($row === null) {
            throw new \DomainException('This item no longer exists or you can\'t see it.');
        }
        $kind   = KindRegistry::get((string) $row['kind']);
        $now    = \ff_now_utc();
        $closed = in_array($row['status'], ['done', 'resolved'], true);
        $note   = mb_substr(trim((string) ($opts['note'] ?? '')), 0, 500);

        switch ($action) {
            case 'take':
                if ($closed) {
                    throw new \DomainException('This item is already closed.');
                }
                \db_update('attention_items', ['assigned_user_id' => $userId, 'assigned_at' => $now], 'id = ?', [$itemId]);
                self::log($itemId, 'taken', $userId, null);
                break;

            case 'release':
                if ($row['assigned_user_id'] === null) {
                    break;
                }
                \db_update('attention_items', ['assigned_user_id' => null, 'assigned_at' => null], 'id = ?', [$itemId]);
                self::log($itemId, 'released', $userId, null);
                break;

            case 'assign':
                if ($closed) {
                    throw new \DomainException('This item is already closed.');
                }
                $target = (int) ($opts['user_id'] ?? 0);
                $tRole  = $target > 0 ? self::activeRoleSlug($target) : '';
                if ($tRole === '' || self::findFor($itemId, $target, $tRole) === null) {
                    throw new \InvalidArgumentException('That person can\'t see this item.');
                }
                \db_update('attention_items', ['assigned_user_id' => $target, 'assigned_at' => $now], 'id = ?', [$itemId]);
                $name = (string) (\db_row('SELECT name FROM users WHERE id = ?', [$target])['name'] ?? 'someone');
                self::log($itemId, 'taken', $userId, 'Given to ' . $name);
                break;

            case 'snooze':
                if ($closed) {
                    throw new \DomainException('This item is already closed.');
                }
                $until = self::snoozeUntil((string) ($opts['until'] ?? ''));
                \db_update('attention_items', ['status' => 'snoozed', 'snoozed_until' => $until], 'id = ?', [$itemId]);
                self::log($itemId, 'snoozed', $userId, 'Until ' . self::localLabel($until, 'D j M'));
                break;

            case 'wake':
                if ($row['status'] !== 'snoozed') {
                    break;
                }
                \db_update('attention_items', ['status' => 'open', 'snoozed_until' => null], 'id = ?', [$itemId]);
                self::log($itemId, 'woke', $userId, null);
                break;

            case 'done':
                if ($closed) {
                    throw new \DomainException('This item is already closed.');
                }
                $live = $row['cleared_at'] === null;
                if ($kind !== null && $kind->doneNeedsNote() && $live && $note === '') {
                    throw new \InvalidArgumentException('Add a short note: the problem is still there, so say what was done.');
                }
                $upd = [
                    'status'            => 'done',
                    'closed_at'         => $now,
                    'closed_by_user_id' => $userId,
                    'close_note'        => $note !== '' ? $note : null,
                    'snoozed_until'     => null,
                ];
                // Event-only kinds have no check that could later confirm the
                // problem is gone, so "done" IS the end of it: clear it now so
                // the same thing happening again opens a fresh item.
                if ($kind === null || !$kind->hasTruth()) {
                    $upd['cleared_at'] = $now;
                }
                \db_update('attention_items', $upd, 'id = ?', [$itemId]);
                self::log($itemId, 'done', $userId, $note !== '' ? $note : null);
                break;

            case 'reopen':
                if ($row['status'] !== 'done' || $row['cleared_at'] !== null) {
                    throw new \DomainException('Only a done item whose problem is still there can be reopened.');
                }
                $upd = [
                    'status'            => 'open',
                    'closed_at'         => null,
                    'closed_by_user_id' => null,
                    'close_note'        => null,
                ];
                if ($row['priority'] === 'urgent') {
                    $upd['urgent_since'] = $now;
                    $upd['escalated_at'] = null;
                }
                \db_update('attention_items', $upd, 'id = ?', [$itemId]);
                self::log($itemId, 'reopened', $userId, $note !== '' ? $note : null);
                break;

            case 'note':
                if ($note === '') {
                    throw new \InvalidArgumentException('Write a note first.');
                }
                self::log($itemId, 'note', $userId, $note);
                break;
        }

        return self::findFor($itemId, $userId, $roleSlug) ?? $row;
    }

    /**
     * UTC DATETIME for a snooze choice, at 08:00 business time.
     *
     * @param  string $choice tomorrow|monday|week|YYYY-MM-DD
     * @return string UTC 'Y-m-d H:i:s'
     * @throws \InvalidArgumentException
     */
    public static function snoozeUntil(string $choice): string
    {
        $tz    = \ff_business_timezone();
        $today = new \DateTimeImmutable(\ff_today() . ' 08:00:00', $tz);
        $at = match (true) {
            $choice === 'tomorrow' => $today->modify('+1 day'),
            $choice === 'monday'   => $today->modify('next monday'),
            $choice === 'week'     => $today->modify('+7 days'),
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $choice) => new \DateTimeImmutable($choice . ' 08:00:00', $tz),
            default => throw new \InvalidArgumentException('Pick when it should come back.'),
        };
        $days = (int) $today->diff($at)->format('%r%a');
        if ($days < 1) {
            throw new \InvalidArgumentException('Pick a day after today.');
        }
        if ($days > 90) {
            throw new \InvalidArgumentException('Snooze for 90 days at most — close it instead if it no longer matters.');
        }
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────────────────

    /**
     * The live row for a key, if any.
     *
     * @param  string $key
     * @return array|null
     */
    private static function liveRow(string $key): ?array
    {
        return \db_row('SELECT * FROM attention_items WHERE live_key = ?', [$key]);
    }

    /**
     * Plain row by id.
     *
     * @param  int $id
     * @return array|null
     */
    public static function row(int $id): ?array
    {
        return \db_row('SELECT * FROM attention_items WHERE id = ?', [$id]);
    }

    /**
     * Append a history event.
     *
     * @param  int         $itemId
     * @param  string      $action
     * @param  int|null    $userId  null = system
     * @param  string|null $note
     * @return void
     */
    private static function log(int $itemId, string $action, ?int $userId, ?string $note): void
    {
        \db_insert('attention_item_events', [
            'item_id' => $itemId,
            'user_id' => $userId,
            'action'  => $action,
            'note'    => $note !== null ? mb_substr($note, 0, 500) : null,
        ]);
    }

    /**
     * Did the problem get worse (higher priority or a later stage)?
     *
     * @param  Kind        $kind
     * @param  array       $row
     * @param  string      $priority
     * @param  string|null $stage
     * @return bool
     */
    private static function isWorse(Kind $kind, array $row, string $priority, ?string $stage): bool
    {
        if ((self::PRIORITY_RANK[$priority] ?? 1) > (self::PRIORITY_RANK[$row['priority']] ?? 1)) {
            return true;
        }
        $stages = $kind->stages();
        if ($stages && $stage !== null) {
            $old = $row['stage'] === null ? false : array_search($row['stage'], $stages, true);
            $new = array_search($stage, $stages, true);
            if ($new !== false && ($old === false || $new > $old)) {
                return true;
            }
        }
        return false;
    }

    /**
     * History note for a worse/reopened event.
     *
     * @param  Kind        $kind
     * @param  array       $row
     * @param  string      $priority
     * @param  string|null $stage
     * @return string
     */
    private static function worseNote(Kind $kind, array $row, string $priority, ?string $stage): string
    {
        if ($stage !== null && $stage !== $row['stage']) {
            return 'Got worse: ' . $kind->stageLabel($stage);
        }
        return $priority === 'urgent' ? 'Now urgent' : 'Got worse';
    }

    /**
     * Keep facts as a clean list of {t, m}.
     *
     * @param  mixed $facts
     * @return array<int, array{t: string, m: int}>
     */
    private static function normalizeFacts(mixed $facts): array
    {
        $out = [];
        foreach (is_array($facts) ? $facts : [] as $f) {
            if (is_string($f)) {
                $f = ['t' => $f, 'm' => 0];
            }
            if (!is_array($f) || trim((string) ($f['t'] ?? '')) === '') {
                continue;
            }
            $out[] = ['t' => mb_substr(trim((string) $f['t']), 0, 200), 'm' => empty($f['m']) ? 0 : 1];
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    /**
     * Unique positive ints.
     *
     * @param  array $ids
     * @return int[]
     */
    private static function cleanIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        sort($ids);
        return $ids;
    }

    /**
     * Decode an audience_user_ids JSON value.
     *
     * @param  mixed $json
     * @return int[]
     */
    private static function decodeIds(mixed $json): array
    {
        $d = is_string($json) ? json_decode($json, true) : null;
        return is_array($d) ? array_map('intval', $d) : [];
    }

    /**
     * MySQL duplicate-key (1062 / SQLSTATE 23000)?
     *
     * @param  \PDOException $e
     * @return bool
     */
    private static function isDuplicate(\PDOException $e): bool
    {
        return (string) $e->getCode() === '23000' && str_contains($e->getMessage(), '1062');
    }

    /**
     * Role slug of an ACTIVE, non-deleted user ('' otherwise).
     *
     * @param  int $userId
     * @return string
     */
    private static function activeRoleSlug(int $userId): string
    {
        $r = \db_row(
            "SELECT r.slug FROM users u JOIN user_roles r ON r.id = u.role_id
              WHERE u.id = ? AND u.status = 'active' AND u.deleted_at IS NULL",
            [$userId]
        );
        return (string) ($r['slug'] ?? '');
    }

    /**
     * "12m" / "5h" / "3 days" since a UTC timestamp.
     *
     * @param  string $utc
     * @return string
     */
    public static function ageLabel(string $utc): string
    {
        $secs = max(0, time() - (int) strtotime($utc . ' UTC'));
        if ($secs < 3600) {
            return max(1, intdiv($secs, 60)) . 'm';
        }
        if ($secs < 86400) {
            return intdiv($secs, 3600) . 'h';
        }
        $d = intdiv($secs, 86400);
        return $d . ' day' . ($d === 1 ? '' : 's');
    }

    /**
     * UTC DATETIME → business-time label ("Mon 29 Sep, 08:00" by default).
     *
     * @param  string $utc
     * @param  string $format  date() format
     * @return string
     */
    public static function localLabel(string $utc, string $format = 'D j M, H:i'): string
    {
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $dt->setTimezone(\ff_business_timezone())->format($format);
        } catch (\Throwable) {
            return $utc;
        }
    }
}
