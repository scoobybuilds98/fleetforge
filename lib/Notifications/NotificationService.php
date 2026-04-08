<?php
declare(strict_types=1);

namespace FleetForge\Notifications;

/**
 * lib/Notifications/NotificationService.php
 *
 * Central in-app notification dispatcher.
 *
 * Every business event in FleetForge that should reach a human user goes
 * through NotificationService::notify(). The service:
 *
 *   1. Looks up which users to notify based on the type prefix
 *      (e.g. "lease.activated" → all users with leases.view permission +
 *      all super_admin users — overrides applied via user_permission_overrides
 *      if that table exists).
 *   2. Excludes inactive / soft-deleted users.
 *   3. Inserts one row per recipient into the notifications table.
 *   4. Catches every exception so a notification failure NEVER rolls back
 *      the originating business transaction.
 *
 * For portal users, pass an explicit portal_user_id list via notifyPortal().
 *
 * Schema columns used (NOTIF-1 ALTER):
 *   id, user_id, portal_user_id, title, message, type, category, url,
 *   entity_type, entity_id, severity, is_read, read_at, created_at, deleted_at
 *
 * @session NOTIF-1
 * @depends includes/db.php (db_select, db_insert, db_count, db_update, db_execute)
 *          includes/auth.php (current_user_id — only when called from a request)
 */
class NotificationService
{
    /**
     * Map of type prefix → permission module name. Used by getModuleFromType().
     * "system.*" maps to null which means: notify ALL active users.
     */
    private const TYPE_TO_MODULE = [
        'lease'       => 'leases',
        'invoice'     => 'invoices',
        'payment'     => 'payments',
        'customer'    => 'customers',
        'equipment'   => 'equipment',
        'compliance'  => 'compliance',
        'maintenance' => 'maintenance',
        'damage'      => 'maintenance',  // damage_claims module not in user_permissions seed → fall back to maintenance
        'reservation' => 'reservations',
        'samsara'     => 'equipment',    // GPS is part of equipment
        'accounting'  => 'reports',      // accounting module not in user_permissions seed → accountant has reports view
        'system'      => null,           // null = all active users
        'chat'        => null,           // CHAT-1: always targeted via $specificUserIds
    ];

    /**
     * Map of type prefix → notification category (used for UI icon + color).
     */
    private const TYPE_TO_CATEGORY = [
        'lease'       => 'leases',
        'invoice'     => 'invoices',
        'payment'     => 'payments',
        'customer'    => 'customers',
        'equipment'   => 'equipment',
        'compliance'  => 'compliance',
        'maintenance' => 'maintenance',
        'damage'      => 'damage',
        'reservation' => 'reservations',
        'samsara'     => 'samsara',
        'accounting'  => 'accounting',
        'system'      => 'system',
        'chat'        => 'system',       // CHAT-1: chat notifications use system icon
    ];

    /**
     * notify() — create one notification per target user.
     *
     * If $specificUserIds is provided, only those users are notified.
     * Otherwise, all users with view permission for the module derived
     * from the type prefix are notified, plus all super_admin users.
     *
     * Failures are logged and swallowed — this method NEVER throws.
     *
     * @param string     $type           e.g. "lease.activated"
     * @param string     $title          Short heading (max 500 chars)
     * @param string     $message        Full description
     * @param string     $entityType     e.g. "lease", "invoice"
     * @param int|null   $entityId       PK of the related record (nullable for system events)
     * @param string     $url            Click-through link (relative or absolute)
     * @param array|null $specificUserIds  Override targeting; null = use module rules
     * @param string     $severity       'info' | 'warning' | 'critical'
     */
    public static function notify(
        string $type,
        string $title,
        string $message,
        string $entityType,
        ?int $entityId,
        string $url,
        ?array $specificUserIds = null,
        string $severity = 'info'
    ): void {
        try {
            // Resolve targets
            $userIds = $specificUserIds !== null
                ? self::filterActiveUsers($specificUserIds)
                : self::resolveUsersForType($type);

            if (empty($userIds)) {
                return;
            }

            $category = self::getCategoryFromType($type);
            $title    = mb_substr(trim($title), 0, 500);
            $message  = trim($message);
            $url      = mb_substr(trim($url), 0, 500);

            // Insert one row per recipient. We do NOT wrap in a transaction
            // here because the caller is typically already inside one — and
            // we want individual insert failures (rare) not to abort the
            // others. Each insert is its own statement.
            foreach ($userIds as $uid) {
                try {
                    \db_insert('notifications', [
                        'user_id'        => (int) $uid,
                        'portal_user_id' => null,
                        'title'          => $title,
                        'message'        => $message,
                        'type'           => $type,
                        'category'       => $category,
                        'url'            => $url,
                        'entity_type'    => $entityType,
                        'entity_id'      => $entityId,
                        'severity'       => self::normalizeSeverity($severity),
                    ]);
                } catch (\Throwable $perRowError) {
                    error_log(
                        '[NotificationService] insert failed for user ' . $uid
                        . ' type=' . $type . ' — ' . $perRowError->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            // Catch-all so notification failures never propagate
            error_log('[NotificationService] notify() failed: ' . $e->getMessage());
        }
    }

    /**
     * notifyPortal() — create notifications for portal (customer) users.
     *
     * Portal notifications target specific customer accounts directly.
     * They live in the same notifications table with portal_user_id set
     * (and user_id NULL).
     *
     * @param string $type        e.g. "invoice.sent"
     * @param int    $customerId  Customer to notify (resolves to all active portal users for that customer)
     * @param string $title
     * @param string $message
     * @param string $entityType
     * @param int|null $entityId
     * @param string $url         Should be a /portal/... path
     * @param string $severity
     */
    public static function notifyPortal(
        string $type,
        int $customerId,
        string $title,
        string $message,
        string $entityType,
        ?int $entityId,
        string $url,
        string $severity = 'info'
    ): void {
        try {
            $portalUsers = \db_select(
                'SELECT id FROM portal_users WHERE customer_id = ? AND status = ?',
                [$customerId, 'active']
            );
            if (empty($portalUsers)) {
                return;
            }

            $category = self::getCategoryFromType($type);
            $title    = mb_substr(trim($title), 0, 500);
            $message  = trim($message);
            $url      = mb_substr(trim($url), 0, 500);

            foreach ($portalUsers as $row) {
                try {
                    \db_insert('notifications', [
                        'user_id'        => null,
                        'portal_user_id' => (int) $row['id'],
                        'title'          => $title,
                        'message'        => $message,
                        'type'           => $type,
                        'category'       => $category,
                        'url'            => $url,
                        'entity_type'    => $entityType,
                        'entity_id'      => $entityId,
                        'severity'       => self::normalizeSeverity($severity),
                    ]);
                } catch (\Throwable $perRowError) {
                    error_log(
                        '[NotificationService] portal insert failed for portal_user '
                        . $row['id'] . ' type=' . $type . ' — ' . $perRowError->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[NotificationService] notifyPortal() failed: ' . $e->getMessage());
        }
    }

    /**
     * getModuleFromType() — public helper, exposed for tests / debugging.
     */
    public static function getModuleFromType(string $type): ?string
    {
        $prefix = self::extractPrefix($type);
        if (!array_key_exists($prefix, self::TYPE_TO_MODULE)) {
            return null;
        }
        return self::TYPE_TO_MODULE[$prefix];
    }

    /**
     * getCategoryFromType() — derives the UI category for icon/color.
     */
    public static function getCategoryFromType(string $type): string
    {
        $prefix = self::extractPrefix($type);
        return self::TYPE_TO_CATEGORY[$prefix] ?? 'system';
    }

    /**
     * markRead() — mark a single notification as read (only by its owner).
     *
     * Accepts EITHER an admin user_id OR a portal_user_id (caller decides).
     */
    public static function markRead(int $notificationId, int $userId, bool $isPortal = false): void
    {
        $col = $isPortal ? 'portal_user_id' : 'user_id';
        try {
            \db_execute(
                "UPDATE notifications
                    SET is_read = 1, read_at = UTC_TIMESTAMP()
                  WHERE id = ? AND {$col} = ? AND deleted_at IS NULL AND is_read = 0",
                [$notificationId, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[NotificationService] markRead failed: ' . $e->getMessage());
        }
    }

    /**
     * markAllRead() — mark every unread notification for a user as read.
     */
    public static function markAllRead(int $userId, bool $isPortal = false): int
    {
        $col = $isPortal ? 'portal_user_id' : 'user_id';
        try {
            return \db_execute(
                "UPDATE notifications
                    SET is_read = 1, read_at = UTC_TIMESTAMP()
                  WHERE {$col} = ? AND is_read = 0 AND deleted_at IS NULL",
                [$userId]
            );
        } catch (\Throwable $e) {
            error_log('[NotificationService] markAllRead failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * getUnreadCount() — single COUNT query, used by the bell badge.
     *
     * Always returns 0 on error (the bell should never crash a page).
     */
    public static function getUnreadCount(int $userId, bool $isPortal = false): int
    {
        $col = $isPortal ? 'portal_user_id' : 'user_id';
        try {
            return \db_count(
                "SELECT COUNT(*) FROM notifications
                  WHERE {$col} = ? AND is_read = 0 AND deleted_at IS NULL",
                [$userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * getRecent() — latest N notifications for the dropdown.
     *
     * Sort: unread first (is_read ASC), then newest first.
     */
    public static function getRecent(int $userId, int $limit = 10, bool $isPortal = false): array
    {
        $col = $isPortal ? 'portal_user_id' : 'user_id';
        $limit = max(1, min(50, $limit));
        try {
            return \db_select(
                "SELECT id, title, message, type, category, url, entity_type, entity_id,
                        severity, is_read, read_at, created_at
                   FROM notifications
                  WHERE {$col} = ? AND deleted_at IS NULL
               ORDER BY is_read ASC, created_at DESC
                  LIMIT $limit",
                [$userId]
            );
        } catch (\Throwable $e) {
            error_log('[NotificationService] getRecent failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * deleteNotification() — soft-delete a single notification owned by the caller.
     */
    public static function deleteNotification(int $notificationId, int $userId, bool $isPortal = false): bool
    {
        $col = $isPortal ? 'portal_user_id' : 'user_id';
        try {
            $rows = \db_execute(
                "UPDATE notifications
                    SET deleted_at = UTC_TIMESTAMP()
                  WHERE id = ? AND {$col} = ? AND deleted_at IS NULL",
                [$notificationId, $userId]
            );
            return $rows > 0;
        } catch (\Throwable $e) {
            error_log('[NotificationService] deleteNotification failed: ' . $e->getMessage());
            return false;
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Extract the prefix before the dot in a type string.
     * "lease.activated" → "lease"; "lease" → "lease".
     */
    private static function extractPrefix(string $type): string
    {
        $pos = strpos($type, '.');
        return $pos === false ? $type : substr($type, 0, $pos);
    }

    /**
     * Resolve which user_ids should receive a notification of the given type.
     *
     * Strategy:
     *   - If module is null (system events), return ALL active users.
     *   - Otherwise, return: union of (users with view permission for the module)
     *     + (all super_admin users), filtered to active + non-deleted only.
     *
     * @return int[]
     */
    private static function resolveUsersForType(string $type): array
    {
        $module = self::getModuleFromType($type);

        try {
            if ($module === null) {
                $rows = \db_select(
                    "SELECT id FROM users
                      WHERE deleted_at IS NULL AND status = 'active'"
                );
            } else {
                // Users with view permission via their role + always-include super_admin
                $rows = \db_select(
                    "SELECT DISTINCT u.id
                       FROM users u
                       LEFT JOIN user_roles r ON r.id = u.role_id
                       LEFT JOIN user_permissions p
                              ON p.role_id = u.role_id
                             AND p.module = ?
                             AND p.can_view = 1
                      WHERE u.deleted_at IS NULL
                        AND u.status = 'active'
                        AND (p.id IS NOT NULL OR r.slug = 'super_admin')",
                    [$module]
                );
            }

            return array_map(static fn($r) => (int) $r['id'], $rows);
        } catch (\Throwable $e) {
            error_log('[NotificationService] resolveUsersForType failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Filter a caller-supplied list of user_ids down to active, non-deleted users.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private static function filterActiveUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $clean = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($clean)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $rows = \db_select(
                "SELECT id FROM users
                  WHERE id IN ($placeholders)
                    AND deleted_at IS NULL
                    AND status = 'active'",
                $clean
            );
            return array_map(static fn($r) => (int) $r['id'], $rows);
        } catch (\Throwable $e) {
            error_log('[NotificationService] filterActiveUsers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Coerce any input to one of the allowed severity ENUM values.
     */
    private static function normalizeSeverity(string $severity): string
    {
        $s = strtolower($severity);
        return in_array($s, ['info', 'warning', 'critical'], true) ? $s : 'info';
    }
}
