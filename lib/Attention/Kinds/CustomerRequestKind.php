<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/CustomerRequestKind.php
 *
 * "Customer request" — a customer's portal request (or their latest reply)
 * is waiting for us (S-ATTENTION-INBOX). Urgent: a customer is waiting.
 *
 * Problem: portal_service_requests.status is open/in_review AND the latest
 * non-internal message isn't ours — either nobody has replied yet, or the
 * customer wrote last. A staff reply clears it (RequestMessageService re-
 * checks this item after appending the admin message); a customer reply
 * raises it again as a fresh item.
 *
 * Audience: the same people Settings → Portal Requests routes that request
 * type to (PortalRequestNotifier::resolveRecipients — roles + named users +
 * super admins). The kind itself defaults to no extra roles, so routing
 * stays the single place that decides who handles which request.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\CustomerRequestKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

use FleetForge\Notifications\PortalRequestNotifier;

final class CustomerRequestKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'customer_request';
    }

    /** @return string */
    public function label(): string
    {
        return 'Customer request';
    }

    /** @return string */
    public function description(): string
    {
        return 'A customer\'s portal request or reply is waiting for us. Goes to the people Portal Requests routing names; closes when we reply or close it.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'service_request';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return [];
    }

    /** @return string */
    public function area(): string
    {
        return 'customers';
    }

    /** @return string */
    public function defaultPriority(): string
    {
        return 'urgent';
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $params = [];
        $only   = self::only($entityId, 'r.id', $params);

        // WHY the correlated subquery: "waiting on us" depends on who wrote
        // LAST in the public thread (internal notes don't count — the
        // customer never sees them).
        return self::keyBy(\db_select(
            "SELECT r.id, r.request_type, r.subject, r.status, r.created_at, r.customer_id,
                    c.company_name, pu.name AS sender_name,
                    (SELECT m.sender_type FROM portal_service_request_messages m
                      WHERE m.request_id = r.id AND m.is_internal = 0
                      ORDER BY m.id DESC LIMIT 1) AS last_sender,
                    (SELECT m.created_at FROM portal_service_request_messages m
                      WHERE m.request_id = r.id AND m.is_internal = 0
                      ORDER BY m.id DESC LIMIT 1) AS last_at
               FROM portal_service_requests r
               LEFT JOIN customers c ON c.id = r.customer_id
               LEFT JOIN portal_users pu ON pu.id = r.portal_user_id
              WHERE r.status IN ('open', 'in_review'){$only}
             HAVING last_sender IS NULL OR last_sender = 'portal'",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $type    = (string) $r['request_type'];
        $label   = PortalRequestNotifier::REQUEST_TYPE_LABELS[$type] ?? 'Request';
        $company = $r['company_name'] ?: 'a customer';
        $replied = $r['last_sender'] === 'portal';
        $subject = trim((string) $r['subject']);

        $facts = [self::fact($label . ' from ' . $company . (!empty($r['sender_name']) ? ' (' . $r['sender_name'] . ')' : ''))];
        if ($subject !== '') {
            $facts[] = self::fact('“' . mb_substr($subject, 0, 90) . '”');
        }
        if ($replied) {
            $facts[] = self::fact('They replied ' . \FleetForge\Attention\AttentionService::localLabel((string) $r['last_at'], 'D j M, H:i'));
        }

        return [
            'title'    => ($replied ? 'Customer replied: ' : 'Customer request: ') . ($subject !== '' ? mb_substr($subject, 0, 80) : $label),
            'facts'    => $facts,
            'url'      => '/fleetforge/requests/view?id=' . (int) $r['id'],
            'audience' => PortalRequestNotifier::resolveRecipients($type),
        ];
    }
}
