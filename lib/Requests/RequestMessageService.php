<?php
declare(strict_types=1);

namespace FleetForge\Requests;

use FleetForge\Notifications\NotificationService;
use FleetForge\Notifications\PortalRequestNotifier;

/**
 * lib/Requests/RequestMessageService.php
 *
 * Central service for the portal service request reply thread.
 * Replaces the prior single-`response`-field semantics with a true
 * multi-message thread: admin and portal-side users each append messages
 * which both sides see + can reply to indefinitely.
 *
 * Append + thread-fetch are the two primary operations. Notification
 * dispatch happens inline on append: admin → notifyPortal (portal users
 * for the customer); portal → notify (routed admin users via
 * PortalRequestNotifier::resolveRecipients so the same routing config
 * applies as for the original submission).
 *
 * Sender invariant (D-PORTAL-REQUEST-THREAD-2):
 *   - sender_type='admin'  → sender_user_id non-NULL, sender_portal_user_id NULL
 *   - sender_type='portal' → sender_portal_user_id non-NULL, sender_user_id NULL
 *
 * Best-effort discipline on notification: try/catch swallows; never throws.
 * The originating INSERT remains atomic.
 *
 * @session  S-PORTAL-REQUEST-THREAD
 * @decision D-PORTAL-REQUEST-THREAD-1 (messages-table architecture),
 *           D-PORTAL-REQUEST-THREAD-2 (sender_type ENUM + nullable FK pair),
 *           D-PORTAL-REQUEST-THREAD-3 (is_internal flag reserved for future)
 */
class RequestMessageService
{
    /**
     * Append an admin message to a request thread. Updates the legacy
     * portal_service_requests.response field with the latest admin body
     * for backward compat. Fires a portal notification (best-effort).
     *
     * @return int new message id (0 on failure)
     */
    public static function appendAdminMessage(
        int $requestId,
        int $userId,
        string $body,
        ?string $newStatus = null,
        bool $isInternal = false
    ): int {
        $body = trim($body);
        if ($body === '' && $newStatus === null) {
            return 0;
        }

        $req = \db_row(
            "SELECT id, customer_id, request_type, subject, status, response, resolved_at
               FROM portal_service_requests WHERE id = ?",
            [$requestId]
        );
        if ($req === null) {
            error_log("[RequestMessageService] appendAdminMessage: request {$requestId} not found");
            return 0;
        }

        $oldStatus = (string) $req['status'];
        $statusChanged = $newStatus !== null && $newStatus !== $oldStatus;
        $messageId = 0;

        try {
            if ($body !== '') {
                $messageId = (int) \db_insert('portal_service_request_messages', [
                    'request_id'    => $requestId,
                    'sender_type'   => 'admin',
                    'sender_user_id' => $userId,
                    'sender_portal_user_id' => null,
                    'body'          => $body,
                    'is_internal'   => $isInternal ? 1 : 0,
                ]);
            }

            // Update legacy fields + status flip.
            $fields = ['assigned_to' => $userId];
            if ($body !== '' && !$isInternal) {
                $fields['response'] = $body;
            }
            if ($newStatus !== null) {
                $fields['status'] = $newStatus;
                if (in_array($newStatus, ['resolved', 'closed'], true) && empty($req['resolved_at'])) {
                    $fields['resolved_at'] = date('Y-m-d H:i:s');
                } elseif ($newStatus === 'open') {
                    $fields['resolved_at'] = null;
                }
            }
            if (!empty($fields)) {
                \db_update('portal_service_requests', $fields, 'id = ?', [$requestId]);
            }

            // Audit log
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'action'       => 'update',
                'module'       => 'customers',
                'entity_type'  => 'portal_service_request',
                'entity_id'    => $requestId,
                'entity_label' => "Service request #{$requestId}",
                'notes'        => "Admin message appended" . ($statusChanged ? " (status: {$oldStatus} → {$newStatus})" : '')
                                  . ($isInternal ? ' [INTERNAL]' : ''),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (\Throwable $e) {
            error_log("[RequestMessageService] appendAdminMessage insert failed: " . $e->getMessage());
            return 0;
        }

        // Notify portal user(s) — best-effort, skip for internal admin notes.
        if (!$isInternal) {
            try {
                self::notifyPortalSide($req, $body, $oldStatus, $newStatus ?? $oldStatus, $statusChanged);
            } catch (\Throwable $e) {
                error_log("[RequestMessageService] portal notify failed for request {$requestId}: " . $e->getMessage());
            }
        }

        return $messageId;
    }

    /**
     * Append a portal-side message to a request thread. Fires admin
     * notification (best-effort) routed via PortalRequestNotifier so the
     * same role/user mapping applies as for the original submission.
     *
     * @return int new message id (0 on failure)
     */
    public static function appendPortalMessage(
        int $requestId,
        int $portalUserId,
        string $body
    ): int {
        $body = trim($body);
        if ($body === '') {
            return 0;
        }

        $req = \db_row(
            "SELECT id, customer_id, portal_user_id, request_type, subject, status
               FROM portal_service_requests WHERE id = ?",
            [$requestId]
        );
        if ($req === null) {
            error_log("[RequestMessageService] appendPortalMessage: request {$requestId} not found");
            return 0;
        }

        // Trap-8: portal user must own this request via customer_id.
        $owner = \db_row(
            "SELECT id, customer_id FROM portal_users WHERE id = ? AND status = 'active'",
            [$portalUserId]
        );
        if ($owner === null || (int) $owner['customer_id'] !== (int) $req['customer_id']) {
            error_log("[RequestMessageService] appendPortalMessage: portal_user {$portalUserId} cannot reply to request {$requestId} (ownership mismatch)");
            return 0;
        }

        $messageId = 0;
        try {
            $messageId = (int) \db_insert('portal_service_request_messages', [
                'request_id'             => $requestId,
                'sender_type'            => 'portal',
                'sender_user_id'         => null,
                'sender_portal_user_id'  => $portalUserId,
                'body'                   => $body,
                'is_internal'            => 0,
            ]);

            // Portal reply re-opens a closed/resolved request — operator
            // intent: customer follow-up means it needs another look.
            $oldStatus = (string) $req['status'];
            if (in_array($oldStatus, ['resolved', 'closed'], true)) {
                \db_update(
                    'portal_service_requests',
                    ['status' => 'open', 'resolved_at' => null],
                    'id = ?',
                    [$requestId]
                );
                error_log("[RequestMessageService] request {$requestId} re-opened by portal reply");
            }

            \db_insert('audit_log', [
                'user_id'      => null,
                'action'       => 'update',
                'module'       => 'customers',
                'entity_type'  => 'portal_service_request',
                'entity_id'    => $requestId,
                'entity_label' => "Service request #{$requestId}",
                'notes'        => "Portal reply from portal_user {$portalUserId}"
                                  . (in_array($oldStatus, ['resolved', 'closed'], true) ? ' (re-opened)' : ''),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (\Throwable $e) {
            error_log("[RequestMessageService] appendPortalMessage insert failed: " . $e->getMessage());
            return 0;
        }

        // Notify routed admins — best-effort via PortalRequestNotifier so
        // the same per-request-type routing config applies as for the
        // original submission.
        try {
            self::notifyAdminSide($req, $body, $portalUserId);
        } catch (\Throwable $e) {
            error_log("[RequestMessageService] admin notify failed for request {$requestId}: " . $e->getMessage());
        }

        return $messageId;
    }

    /**
     * Fetch the full thread for a request. Returns chronological message
     * list with sender display names resolved. Filters internal messages
     * for non-admin viewers.
     *
     * @param int  $requestId
     * @param bool $includeInternal pass true for admin viewers; false for portal
     * @return array<int, array{id:int,sender_type:string,sender_label:string,body:string,created_at:string,is_internal:int}>
     */
    public static function fetchThread(int $requestId, bool $includeInternal = false): array
    {
        $where = 'm.request_id = ?';
        $params = [$requestId];
        if (!$includeInternal) {
            $where .= ' AND m.is_internal = 0';
        }

        $rows = \db_select(
            "SELECT m.id, m.sender_type, m.body, m.is_internal, m.created_at,
                    u.name AS user_name,
                    pu.name AS portal_user_name, pu.email AS portal_user_email
               FROM portal_service_request_messages m
          LEFT JOIN users u           ON u.id  = m.sender_user_id
          LEFT JOIN portal_users pu   ON pu.id = m.sender_portal_user_id
              WHERE {$where}
              ORDER BY m.created_at ASC, m.id ASC",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $label = $r['sender_type'] === 'admin'
                ? ($r['user_name'] ?? 'Staff member')
                : ($r['portal_user_name'] ?? $r['portal_user_email'] ?? 'Customer');
            $out[] = [
                'id'           => (int) $r['id'],
                'sender_type'  => (string) $r['sender_type'],
                'sender_label' => (string) $label,
                'body'         => (string) $r['body'],
                'is_internal'  => (int) $r['is_internal'],
                'created_at'   => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Notify portal user(s) for an admin reply.
     */
    private static function notifyPortalSide(
        array $req,
        string $body,
        string $oldStatus,
        string $newStatus,
        bool $statusChanged
    ): void {
        $typeLabel = PortalRequestNotifier::REQUEST_TYPE_LABELS[$req['request_type']] ?? 'Service request';

        $titleParts = [];
        if ($body !== '')  $titleParts[] = 'New reply';
        if ($statusChanged) $titleParts[] = "Status: {$oldStatus} → {$newStatus}";
        $title = "{$typeLabel} #{$req['id']}: " . implode(' · ', $titleParts);

        $msgParts = ["Your request \"{$req['subject']}\" has an update."];
        if ($body !== '') {
            $excerpt = mb_substr($body, 0, 280);
            $msgParts[] = "Reply from support:\n{$excerpt}" . (mb_strlen($body) > 280 ? '…' : '');
        }
        if ($statusChanged) {
            $msgParts[] = "Status changed from '{$oldStatus}' to '{$newStatus}'.";
        }

        $severity = ($oldStatus === 'closed' && $newStatus === 'open') ? 'warning' : 'info';

        NotificationService::notifyPortal(
            'service_request.reply.' . $newStatus,
            (int) $req['customer_id'],
            $title,
            implode("\n\n", $msgParts),
            'service_request',
            (int) $req['id'],
            \base_url('portal/requests/view?id=' . (int) $req['id']),
            $severity
        );
    }

    /**
     * Notify routed admins for a portal-side reply. Uses
     * PortalRequestNotifier::resolveRecipients so the same routing config
     * applies as for the original submission (per-type role/user mapping
     * + safety-net super_admin).
     */
    private static function notifyAdminSide(array $req, string $body, int $portalUserId): void
    {
        $userIds = PortalRequestNotifier::resolveRecipients((string) $req['request_type']);
        if (empty($userIds)) {
            error_log("[RequestMessageService] notifyAdminSide: no recipients for request {$req['id']} type={$req['request_type']}");
            return;
        }

        $pu = \db_row(
            "SELECT name, email FROM portal_users WHERE id = ?",
            [$portalUserId]
        );
        $senderLabel = trim((string) ($pu['name'] ?? '')) ?: trim((string) ($pu['email'] ?? '')) ?: 'Portal user';

        $typeLabel = PortalRequestNotifier::REQUEST_TYPE_LABELS[$req['request_type']] ?? 'Service request';
        $title = "{$typeLabel} #{$req['id']}: Customer reply from {$senderLabel}";

        $excerpt = mb_substr($body, 0, 280);
        $message = "Subject: {$req['subject']}\n\nCustomer reply:\n{$excerpt}"
                 . (mb_strlen($body) > 280 ? '…' : '');

        NotificationService::notify(
            'service_request.customer_reply.' . (string) $req['status'],
            $title,
            $message,
            'service_request',
            (int) $req['id'],
            \base_url('requests/view?id=' . (int) $req['id']),
            $userIds,
            'info'
        );
    }
}
