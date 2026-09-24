<?php
declare(strict_types=1);

/**
 * lib/Attention/NotificationRouter.php
 *
 * Decides what a notify() call becomes (S-ATTENTION-INBOX):
 *   - a "Needs attention" item (raised or re-checked) — no per-person rows;
 *   - an update (the activity feed), optionally grouped with a burst of the
 *     same update;
 *   - and, for updates that fix something (a payment, a re-close), a
 *     re-check that closes the matching item right away.
 *
 * The rules live in config/notification_types.php. The ~60 existing
 * notify() call sites don't change: this runs inside
 * NotificationService::notify(), which falls back to the plain update path
 * if routing ever throws — a routing bug must never make an event vanish.
 *
 * Runs inside the caller's transaction (notify() is usually called mid-
 * transaction), so a re-check sees the caller's own uncommitted writes
 * (the invoice it just marked paid) and rolls back with it.
 *
 * Required by: lib/Notifications/NotificationService.php
 * Defines:     FleetForge\Attention\NotificationRouter
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention;

final class NotificationRouter
{
    /** @var array<string, array>|null */
    private static ?array $rules = null;

    /**
     * Route one event.
     *
     * @param  string     $type
     * @param  string     $title
     * @param  string     $message
     * @param  string     $entityType
     * @param  int|null   $entityId
     * @param  string     $url
     * @param  array|null $specificUserIds
     * @return array{handled: bool, group: ?array}
     *         handled = true → it became a Needs attention item; don't write
     *         update rows. group = grouping rule for the update path.
     */
    public static function route(
        string $type,
        string $title,
        string $message,
        string $entityType,
        ?int $entityId,
        string $url,
        ?array $specificUserIds
    ): array {
        $rule = self::ruleFor($type);

        // 1. Updates that fix something re-check the item they affect.
        foreach ($rule['refresh'] ?? [] as [$kindKey, $resolver]) {
            $kind = KindRegistry::get((string) $kindKey);
            $id   = self::resolve((string) $resolver, $entityId);
            if ($kind !== null && $id !== null) {
                AttentionService::refresh($kind, $id);
            }
        }

        // 2. Needs attention.
        $handled = false;
        if (!empty($rule['attention'])) {
            $kind = KindRegistry::get((string) $rule['attention']);
            if ($kind !== null) {
                $resolver = (string) ($rule['entity'] ?? 'self');
                $audience = $specificUserIds ?? [];

                if ($resolver === 'sweep' && $kind->hasTruth()) {
                    $stats   = AttentionService::sweepKind($kind);
                    $handled = $stats['open'] > 0;
                } elseif ($kind->hasTruth()) {
                    $id = self::resolve($resolver, $entityId);
                    if ($id !== null) {
                        $handled = AttentionService::refresh($kind, $id, $audience) === 'raised';
                    }
                } else {
                    $id = self::resolve($resolver, $entityId) ?? 0;
                    AttentionService::raise($kind, $id, [
                        'title' => $title,
                        'facts' => self::factsFromMessage($message),
                        'url'   => $url,
                    ], $audience);
                    $handled = true;
                }
            }
        }

        return ['handled' => $handled, 'group' => $rule['group'] ?? null];
    }

    /**
     * The rule for a type: exact match, else the longest ".*" prefix, else [].
     *
     * @param  string $type
     * @return array
     */
    public static function ruleFor(string $type): array
    {
        $rules = self::rules();
        if (isset($rules[$type])) {
            return $rules[$type];
        }
        $best = null;
        foreach ($rules as $pattern => $rule) {
            if (!str_ends_with($pattern, '.*')) {
                continue;
            }
            $prefix = substr($pattern, 0, -1); // keep the dot: "service_request."
            if (str_starts_with($type, $prefix) && ($best === null || strlen($prefix) > strlen($best[0]))) {
                $best = [$prefix, $rule];
            }
        }
        return $best[1] ?? [];
    }

    /**
     * Is this type routed to Needs attention? (used by the Settings page and
     * smokes to list what's what)
     *
     * @param  string $type
     * @return string|null kind key
     */
    public static function attentionKindFor(string $type): ?string
    {
        $r = self::ruleFor($type);
        return isset($r['attention']) ? (string) $r['attention'] : null;
    }

    /**
     * All rules (loaded once per process).
     *
     * @return array<string, array>
     */
    private static function rules(): array
    {
        if (self::$rules === null) {
            $file = dirname(__DIR__, 2) . '/config/notification_types.php';
            $r = is_file($file) ? require $file : [];
            self::$rules = is_array($r) ? $r : [];
        }
        return self::$rules;
    }

    /**
     * Turn an event's entity into the kind's entity id.
     *
     * @param  string   $resolver
     * @param  int|null $entityId
     * @return int|null  null = can't tell (skip)
     */
    private static function resolve(string $resolver, ?int $entityId): ?int
    {
        switch ($resolver) {
            case 'system':
                return 0;
            case 'self':
                return $entityId !== null && $entityId > 0 ? $entityId : null;
            case 'invoice_customer':
                $sql = 'SELECT customer_id AS id FROM invoices WHERE id = ?';
                break;
            case 'payment_customer':
                $sql = 'SELECT customer_id AS id FROM payments WHERE id = ?';
                break;
            case 'promise_customer':
                $sql = 'SELECT customer_id AS id FROM acc_promise_to_pay WHERE id = ?';
                break;
            default:
                return null;
        }
        if ($entityId === null || $entityId <= 0) {
            return null;
        }
        $row = \db_row($sql, [$entityId]);
        return isset($row['id']) ? (int) $row['id'] : null;
    }

    /**
     * Facts for an event-only item from the event's message: one per line,
     * money lines flagged so they're hidden from roles without payments:view.
     *
     * @param  string $message
     * @return array<int, array{t: string, m: int}>
     */
    public static function factsFromMessage(string $message): array
    {
        $out = [];
        foreach (preg_split('/\R/', $message) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $out[] = [
                't' => mb_substr($line, 0, 200),
                'm' => preg_match('/(\$\s?-?\d|\b(CAD|USD)\s?-?\d)/', $line) ? 1 : 0,
            ];
            if (count($out) >= 4) {
                break;
            }
        }
        return $out;
    }

    /**
     * Drop the cached rules (tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$rules = null;
    }
}
