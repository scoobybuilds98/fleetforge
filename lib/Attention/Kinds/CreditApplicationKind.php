<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/CreditApplicationKind.php
 *
 * "Credit application" — a customer submitted a credit application that
 * nobody has reviewed yet (S-ATTENTION-INBOX). Urgent: a new customer is
 * waiting on it.
 *
 * Problem: customer_credit_applications.status = 'submitted' (not deleted).
 * Closes when a reviewer records an outcome (api/v1/credit_applications/
 * review.php sets status 'reviewed' and re-checks this item).
 *
 * entity_type 'credit_application' matches the notify() call in
 * app/admin/credit-application.php, so the event and the sweep key the
 * same item.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\CreditApplicationKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class CreditApplicationKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'credit_application';
    }

    /** @return string */
    public function label(): string
    {
        return 'Credit application';
    }

    /** @return string */
    public function description(): string
    {
        return 'A customer submitted a credit application. Closes when someone records a review outcome.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'credit_application';
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
        $only   = self::only($entityId, 'a.id', $params);
        return self::keyBy(\db_select(
            "SELECT a.id, a.customer_id, a.submitted_at, a.print_name_first, a.print_name_last,
                    c.company_name
               FROM customer_credit_applications a
               LEFT JOIN customers c ON c.id = a.customer_id
              WHERE a.status = 'submitted' AND a.deleted_at IS NULL{$only}",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $who   = trim(($r['print_name_first'] ?? '') . ' ' . ($r['print_name_last'] ?? ''));
        $facts = [];
        if ($who !== '') {
            $facts[] = self::fact('Signed by ' . $who);
        }
        if (!empty($r['submitted_at'])) {
            $facts[] = self::fact('Submitted ' . \FleetForge\Attention\AttentionService::localLabel((string) $r['submitted_at'], 'D j M'));
        }
        return [
            'title' => 'Credit application to review: ' . ($r['company_name'] ?: 'customer #' . (int) $r['customer_id']),
            'facts' => $facts,
            'url'   => '/fleetforge/credit_applications/show?id=' . (int) $r['id'],
        ];
    }
}
