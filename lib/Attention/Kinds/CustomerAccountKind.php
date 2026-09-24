<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/CustomerAccountKind.php
 *
 * "Customer overdue" — ONE item per customer with overdue invoices
 * (S-ATTENTION-INBOX).
 *
 * WHY per customer, not per invoice: production had 99 overdue invoices
 * across 14 customers, plus separate risk-HIGH and collections-step alerts
 * about the same customers. Chasing money happens per customer (one call
 * covers every invoice), so the risk rating, collections step and a broken
 * promise-to-pay become facts on the customer's single item instead of
 * extra alerts.
 *
 * Problem: the customer has at least one invoice with status 'overdue'
 * (set nightly by cron/invoice_overdue.php) and balance_due > 0.
 * Stages by the oldest overdue invoice: 1–30 → 31–60 → 61–90 → 90+ days.
 * A person can mark it done ("called — paying Friday"); it only comes back
 * if the account crosses into the next stage. 90+ days is urgent.
 * Closes itself when nothing is overdue any more (paid, voided, written off).
 *
 * Money (the overdue total) is a money fact: hidden from roles without
 * payments:view. Amounts are summed per currency in SQL (DECIMAL, D16 — no
 * float), never converted here.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\CustomerAccountKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class CustomerAccountKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'customer_account';
    }

    /** @return string */
    public function label(): string
    {
        return 'Customer overdue';
    }

    /** @return string */
    public function description(): string
    {
        return 'One item per customer with overdue invoices, including their risk rating, collections step and any broken promise to pay. Closes when nothing is overdue.';
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
        return 'money';
    }

    /** @return bool */
    public function dynamicPriority(): bool
    {
        return true;
    }

    /** @return string */
    public function priorityRule(): string
    {
        return 'To do; urgent once the oldest invoice is 90+ days overdue';
    }

    /** @return string */
    public function defaultWhatsApp(): string
    {
        return 'summary';
    }

    /** @return string[] */
    public function stages(): array
    {
        return ['1_30', '31_60', '61_90', '90_plus'];
    }

    /**
     * @param  string|null $stage
     * @return string
     */
    public function stageLabel(?string $stage): string
    {
        return match ($stage) {
            '1_30'    => 'up to 30 days overdue',
            '31_60'   => '31–60 days overdue',
            '61_90'   => '61–90 days overdue',
            '90_plus' => '90+ days overdue',
            default   => (string) $stage,
        };
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $params = [];
        $only   = self::only($entityId, 'c.id', $params);

        $rows = \db_select(
            "SELECT c.id, c.company_name, c.risk_score, c.collection_status,
                    COUNT(i.id) AS n, MIN(i.due_date) AS oldest_due
               FROM customers c
               JOIN invoices i ON i.customer_id = c.id
              WHERE i.status = 'overdue' AND i.balance_due > 0 AND i.deleted_at IS NULL
                AND c.deleted_at IS NULL{$only}
              GROUP BY c.id, c.company_name, c.risk_score, c.collection_status",
            $params
        );
        $out = self::keyBy($rows);
        if (!$out) {
            return [];
        }

        // Per-currency overdue totals (SUM of DECIMAL stays exact).
        $ids = array_keys($out);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        foreach (\db_select(
            "SELECT customer_id, COALESCE(currency, 'CAD') AS currency, SUM(balance_due) AS owed
               FROM invoices
              WHERE status = 'overdue' AND balance_due > 0 AND deleted_at IS NULL
                AND customer_id IN ({$ph})
              GROUP BY customer_id, COALESCE(currency, 'CAD')",
            $ids
        ) as $r) {
            $out[(int) $r['customer_id']]['owed'][$r['currency']] = (string) $r['owed'];
        }

        // A promise to pay that broke in the last 30 days is worth saying.
        foreach (\db_select(
            "SELECT DISTINCT customer_id FROM acc_promise_to_pay
              WHERE status = 'broken' AND promise_date >= ? AND customer_id IN ({$ph})",
            array_merge([date('Y-m-d', strtotime(\ff_today() . ' -30 days'))], $ids)
        ) as $r) {
            $out[(int) $r['customer_id']]['promise_broken'] = true;
        }

        return $out;
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $n       = (int) $r['n'];
        $days    = max(1, -self::daysUntil((string) $r['oldest_due']));
        $stage   = match (true) {
            $days > 90 => '90_plus',
            $days > 60 => '61_90',
            $days > 30 => '31_60',
            default    => '1_30',
        };

        $facts = [];
        $owed  = [];
        foreach ($r['owed'] ?? [] as $cur => $amt) {
            $owed[] = self::fmtMoney($amt, (string) $cur);
        }
        if ($owed) {
            $facts[] = self::money(implode(' + ', $owed) . ' overdue');
        }
        $facts[] = self::fact('Oldest ' . self::plural($days, 'day') . ' overdue');
        if ($r['risk_score'] === 'high') {
            $facts[] = self::fact('Risk: high');
        }
        if (!in_array($r['collection_status'], ['current', null, ''], true)) {
            $facts[] = self::fact('Collections: ' . str_replace('_', ' ', (string) $r['collection_status']));
        }
        if (!empty($r['promise_broken'])) {
            $facts[] = self::fact('Promise to pay broken');
        }

        return [
            'title'    => $r['company_name'] . ': ' . self::plural($n, 'invoice') . ' overdue',
            'facts'    => $facts,
            'url'      => '/fleetforge/customers/show?id=' . (int) $r['id'],
            'stage'    => $stage,
            'priority' => $stage === '90_plus' ? 'urgent' : 'todo',
        ];
    }
}
