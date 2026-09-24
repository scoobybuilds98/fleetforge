<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/BillingCycleKind.php
 *
 * "Billing behind" — this month's billing cycle is still open past its
 * bill-by or send-by date (S-ATTENTION-INBOX).
 *
 * Problem: billing_cycles.status = 'open' AND (bill_by_date < today OR
 * send_by_date < today). Closes when the cycle is closed
 * (invoice.billing_cycle_closed re-checks). The "cycle opened" nudge from
 * cron/billing_cycle_open.php is just an update, not an item.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\BillingCycleKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class BillingCycleKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'billing_cycle';
    }

    /** @return string */
    public function label(): string
    {
        return 'Billing behind';
    }

    /** @return string */
    public function description(): string
    {
        return 'The month\'s billing cycle is past its bill-by or send-by date. Closes when the cycle is closed.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'billing_cycle';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['manager', 'accountant'];
    }

    /** @return string */
    public function area(): string
    {
        return 'money';
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $today  = \ff_today();
        $params = [$today, $today];
        $only   = self::only($entityId, 'b.id', $params);
        return self::keyBy(\db_select(
            "SELECT b.id, b.reference, b.period_start, b.bill_by_date, b.send_by_date
               FROM billing_cycles b
              WHERE b.status = 'open'
                AND ((b.bill_by_date IS NOT NULL AND b.bill_by_date < ?)
                  OR (b.send_by_date IS NOT NULL AND b.send_by_date < ?)){$only}",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $month = date('F Y', (int) strtotime((string) $r['period_start']));
        $facts = [];
        if ($r['bill_by_date']) {
            $facts[] = self::fact('Bill by ' . self::fmtDate((string) $r['bill_by_date']));
        }
        if ($r['send_by_date']) {
            $facts[] = self::fact('Send by ' . self::fmtDate((string) $r['send_by_date']));
        }
        return [
            'title' => "Billing for {$month} is behind",
            'facts' => $facts,
            'url'   => '/fleetforge/billing/cycle?id=' . (int) $r['id'],
        ];
    }
}
