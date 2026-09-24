<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/EmailBounceKind.php
 *
 * "Customer emails off" — a customer's email address hard-bounced or
 * complained, so FleetForge switched their emails off (invoices, reminders)
 * (S-ATTENTION-INBOX).
 *
 * Problem: customers.email_disabled = 1. Closes when someone fixes the
 * address and switches emails back on (api/v1/customers/reenable_email.php).
 *
 * Before this session the SES webhook's staff alert called a method that
 * never existed (NotificationService::notifyRole), so nobody was ever told.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\EmailBounceKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class EmailBounceKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'email_bounce';
    }

    /** @return string */
    public function label(): string
    {
        return 'Customer emails off';
    }

    /** @return string */
    public function description(): string
    {
        return 'A customer\'s email bounced, so their invoices and reminders stopped sending. Closes when emails are switched back on.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'customer';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['manager', 'accountant'];
    }

    /** @return string */
    public function area(): string
    {
        return 'customers';
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $params = [];
        $only   = self::only($entityId, 'c.id', $params);
        return self::keyBy(\db_select(
            "SELECT c.id, c.company_name, c.email, c.email_disabled_reason, c.email_disabled_at
               FROM customers c
              WHERE c.email_disabled = 1 AND c.deleted_at IS NULL{$only}",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $facts = [];
        if (!empty($r['email'])) {
            $facts[] = self::fact((string) $r['email']);
        }
        if (!empty($r['email_disabled_reason'])) {
            $facts[] = self::fact(mb_substr((string) $r['email_disabled_reason'], 0, 120));
        }
        $facts[] = self::fact('Invoices and reminders aren\'t reaching them');
        return [
            'title' => 'Emails to ' . $r['company_name'] . ' are switched off',
            'facts' => $facts,
            'url'   => '/fleetforge/customers/show?id=' . (int) $r['id'],
        ];
    }
}
