<?php
declare(strict_types=1);

namespace FleetForge\Notifications;

/**
 * lib/Notifications/PortalRequestNotifier.php
 *
 * Dispatch in-app notifications when a customer submits a portal service
 * request via app/portal/requests/create.php.
 *
 * Routing config lives in settings keys (D-PORTAL-REQUEST-ROUTING-1):
 *   - portal_requests.routing.{type}.role_slugs — JSON array of user_roles.slug
 *   - portal_requests.routing.{type}.user_ids   — JSON array of users.id
 *   - portal_requests.routing.default.* — fallback bucket
 *   - portal_requests.routing.always_include_super_admin — safety-net toggle
 *
 * Resolution algorithm:
 *   1. Load request row (request_type, subject, customer ref, etc.)
 *   2. Read {type}.role_slugs + {type}.user_ids; if BOTH empty, fall back to
 *      default.role_slugs + default.user_ids (D-PORTAL-REQUEST-ROUTING-4)
 *   3. Resolve role_slugs → user_ids via SELECT u.id FROM users JOIN user_roles
 *   4. Union with explicit user_ids from settings
 *   5. If always_include_super_admin='1' (default), add ALL active super_admin
 *      users to the set (D-PORTAL-REQUEST-ROUTING-3 safety-net)
 *   6. NotificationService::notify() does the final active-user filter +
 *      per-user insert + per-row try/catch (it never throws)
 *
 * Best-effort: this class's notify() returns int (recipient count) and never
 * throws — failures are error_log'd, the originating INSERT in create.php
 * remains atomic.
 *
 * @session  S-PORTAL-REQUEST-ROUTING
 * @decision D-PORTAL-REQUEST-ROUTING-1 (settings-keys storage; mirrors
 *               ai.briefing_recipient_roles JSON array convention),
 *           D-PORTAL-REQUEST-ROUTING-2 (role_slugs + user_ids separate;
 *               union at dispatch),
 *           D-PORTAL-REQUEST-ROUTING-3 (always-include-super_admin safety
 *               net; default '1' to prevent silent-drop bug class),
 *           D-PORTAL-REQUEST-ROUTING-4 (default fallback bucket when
 *               type-specific routing empty)
 */
class PortalRequestNotifier
{
    /**
     * Request types accepted by the portal create form (mirror of
     * $validTypes in app/portal/requests/create.php).
     */
    public const REQUEST_TYPES = [
        'lease_extension',
        'early_return',
        'damage_report',
        'billing_inquiry',
        'document_request',
        'new_lease_inquiry',
        'general',
    ];

    /**
     * Human-readable labels for notification title + admin UI rendering.
     */
    public const REQUEST_TYPE_LABELS = [
        'lease_extension'   => 'Lease Extension Request',
        'early_return'      => 'Early Return Notice',
        'damage_report'     => 'Damage Report',
        'billing_inquiry'   => 'Billing Inquiry',
        'document_request'  => 'Document Request',
        'new_lease_inquiry' => 'New Lease Inquiry',
        'general'           => 'General Question',
    ];

    /**
     * Dispatch notifications for a freshly-submitted portal service request.
     * Best-effort: never throws. Returns count of users notified (0 on
     * any failure path; check error_log for diagnostics).
     *
     * @param int $requestId portal_service_requests.id
     * @return int           recipient count
     */
    public static function notify(int $requestId): int
    {
        try {
            $req = \db_row(
                "SELECT psr.id, psr.request_type, psr.subject, psr.message,
                        psr.customer_id, psr.portal_user_id, psr.lease_id,
                        psr.equipment_unit_id,
                        c.company_name AS customer_company,
                        pu.name AS submitted_by_name, pu.email AS submitted_by_email
                   FROM portal_service_requests psr
                   LEFT JOIN customers c ON c.id = psr.customer_id
                   LEFT JOIN portal_users pu ON pu.id = psr.portal_user_id
                  WHERE psr.id = ?",
                [$requestId]
            );
            if ($req === null) {
                error_log("[PortalRequestNotifier] notify({$requestId}): request not found");
                return 0;
            }

            $userIds = self::resolveRecipients((string) $req['request_type']);
            if (empty($userIds)) {
                error_log("[PortalRequestNotifier] notify({$requestId}): no recipients resolved — check portal_requests.routing.* settings");
                return 0;
            }

            $type = 'service_request.' . (string) $req['request_type'];
            $label = self::REQUEST_TYPE_LABELS[$req['request_type']] ?? 'Service Request';
            $customer = trim((string) ($req['customer_company'] ?? '')) ?: 'Unknown customer';
            $submittedBy = trim((string) ($req['submitted_by_name'] ?? '')) ?: 'Portal user';

            $title = "{$label} from {$customer}";
            $messageBody = "From {$submittedBy} ({$req['submitted_by_email']})\n"
                         . "Subject: {$req['subject']}\n\n"
                         . (string) $req['message'];

            // Drill-down URL — admin-side viewer at /requests/view
            // (built in same commit per scope answer). The router maps any
            // path not matching /api, /portal, /auth, /webhooks, /legal
            // directly to app/admin/, so /requests/view → app/admin/requests/view.php.
            // Use base_url() so the /fleetforge subpath prefix (D7) is included —
            // the bell renders this raw via Alpine :href so the URL must be
            // resolvable from the root domain, not relative to the current page.
            $url = \base_url('requests/view?id=' . $requestId);

            $severity = self::severityForType((string) $req['request_type']);

            // NotificationService::notify is best-effort itself; filters to
            // active users + inserts per recipient with per-row try/catch.
            NotificationService::notify(
                $type,
                $title,
                $messageBody,
                'service_request',
                $requestId,
                $url,
                $userIds,
                $severity
            );

            return count($userIds);
        } catch (\Throwable $e) {
            error_log("[PortalRequestNotifier] notify({$requestId}) failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Resolve the effective recipient user_id list for a request_type.
     * Public-static for smoke testability.
     *
     * @param string $requestType
     * @return int[] active user IDs (deduplicated)
     */
    public static function resolveRecipients(string $requestType): array
    {
        try {
            // Normalize unknown types to 'general' rather than dropping them.
            if (!in_array($requestType, self::REQUEST_TYPES, true)) {
                error_log("[PortalRequestNotifier] unknown request_type '{$requestType}'; routing as 'general'");
                $requestType = 'general';
            }

            $roleSlugs = self::readJsonSetting("portal_requests.routing.{$requestType}.role_slugs");
            $userIds   = self::readJsonSetting("portal_requests.routing.{$requestType}.user_ids");

            // Fallback to default bucket when type-specific routing is empty
            // (D-PORTAL-REQUEST-ROUTING-4).
            if (empty($roleSlugs) && empty($userIds)) {
                $roleSlugs = self::readJsonSetting('portal_requests.routing.default.role_slugs');
                $userIds   = self::readJsonSetting('portal_requests.routing.default.user_ids');
            }

            $resolved = [];

            // Add user IDs from role_slugs.
            if (!empty($roleSlugs)) {
                $fromRoles = self::resolveRoleSlugsToUserIds($roleSlugs);
                foreach ($fromRoles as $uid) {
                    $resolved[(int) $uid] = true;
                }
            }

            // Add explicit user IDs.
            foreach ($userIds as $uid) {
                if (is_numeric($uid)) {
                    $resolved[(int) $uid] = true;
                }
            }

            // Safety net (D-PORTAL-REQUEST-ROUTING-3).
            if ((string) \settings_get('portal_requests.routing.always_include_super_admin', '1') === '1') {
                $superAdmins = self::resolveRoleSlugsToUserIds(['super_admin']);
                foreach ($superAdmins as $uid) {
                    $resolved[(int) $uid] = true;
                }
            }

            // Active filter happens inside NotificationService::notify() via
            // filterActiveUsers, but we filter here too so smoke tests can
            // assert the resolution layer independently.
            return self::filterActiveUsers(array_keys($resolved));
        } catch (\Throwable $e) {
            error_log("[PortalRequestNotifier] resolveRecipients failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Map role slugs → user IDs (all active, non-deleted users).
     *
     * @param string[] $roleSlugs
     * @return int[]
     */
    public static function resolveRoleSlugsToUserIds(array $roleSlugs): array
    {
        $clean = array_values(array_unique(array_filter(array_map('strval', $roleSlugs))));
        if (empty($clean)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $rows = \db_select(
                "SELECT u.id
                   FROM users u
                   JOIN user_roles r ON r.id = u.role_id
                  WHERE r.slug IN ({$placeholders})
                    AND u.deleted_at IS NULL
                    AND u.status = 'active'",
                $clean
            );
            return array_map(static fn($r) => (int) $r['id'], $rows);
        } catch (\Throwable $e) {
            error_log("[PortalRequestNotifier] resolveRoleSlugsToUserIds failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Read a settings JSON value, decode to array, return empty array on
     * any failure mode (missing key, malformed JSON, non-array decoded).
     */
    private static function readJsonSetting(string $key): array
    {
        $raw = (string) \settings_get($key, '[]');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Severity heuristic — damage_report + early_return surface as warning
     * (operations needs to react fast); rest are info.
     */
    private static function severityForType(string $requestType): string
    {
        return in_array($requestType, ['damage_report', 'early_return'], true) ? 'warning' : 'info';
    }

    /**
     * Filter user IDs to active + non-deleted. Mirror of
     * NotificationService::filterActiveUsers but exposed here so smoke
     * tests can assert the resolved set independently of the dispatch.
     *
     * @param int[] $userIds
     * @return int[]
     */
    public static function filterActiveUsers(array $userIds): array
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($clean)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $rows = \db_select(
                "SELECT id FROM users
                  WHERE id IN ({$placeholders})
                    AND deleted_at IS NULL
                    AND status = 'active'",
                $clean
            );
            return array_map(static fn($r) => (int) $r['id'], $rows);
        } catch (\Throwable $e) {
            error_log("[PortalRequestNotifier] filterActiveUsers failed: " . $e->getMessage());
            return [];
        }
    }
}
