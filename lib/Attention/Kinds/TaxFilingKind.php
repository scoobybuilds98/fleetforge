<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/TaxFilingKind.php
 *
 * "Tax filing" — a GST/HST or PST return is due within 30 days (or late)
 * and not filed yet (S-ATTENTION-INBOX).
 *
 * Problem: acc_tax_filing_periods.status not filed/remitted and
 * filing_due_date ≤ today + 30. Urgent from 7 days out. Closes when the
 * period is marked filed (TaxFilingService). The reminder cron's 30/14/7/1
 * day notices now land on this one item instead of four separate alerts.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\TaxFilingKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class TaxFilingKind extends TruthKind
{
    /** tax_type → label (mirrors cron/accounting_tax_filing_reminders.php). */
    private const LABELS = [
        'gst_hst' => 'GST/HST',
        'pst_bc'  => 'PST (BC)',
        'pst_sk'  => 'PST (SK)',
        'pst_mb'  => 'PST (MB)',
    ];

    /** @return string */
    public function key(): string
    {
        return 'tax_filing';
    }

    /** @return string */
    public function label(): string
    {
        return 'Tax filing';
    }

    /** @return string */
    public function description(): string
    {
        return 'A sales-tax return is due within 30 days or late. Urgent from 7 days out; closes when the period is marked filed.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'tax_filing_period';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['accountant'];
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
        return 'To do from 30 days out; urgent from 7 days out or once late';
    }

    /** @return string[] */
    public function stages(): array
    {
        return ['due_soon', 'this_week', 'late'];
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $params = [date('Y-m-d', strtotime(\ff_today() . ' +30 days'))];
        $only   = self::only($entityId, 'p.id', $params);
        return self::keyBy(\db_select(
            "SELECT p.id, p.tax_type, p.period_start, p.period_end, p.filing_due_date, p.status
               FROM acc_tax_filing_periods p
              WHERE p.status NOT IN ('filed', 'remitted')
                AND p.filing_due_date IS NOT NULL AND p.filing_due_date <= ?{$only}",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $label = self::LABELS[$r['tax_type']] ?? strtoupper(str_replace('_', ' ', (string) $r['tax_type']));
        $days  = self::daysUntil((string) $r['filing_due_date']);
        $stage = $days < 0 ? 'late' : ($days <= 7 ? 'this_week' : 'due_soon');
        $due   = self::fmtDate((string) $r['filing_due_date']);

        return [
            'title'    => $days < 0 ? "{$label} return is late (was due {$due})" : "{$label} return due {$due}",
            'facts'    => [
                self::fact('Period ' . self::fmtDate((string) $r['period_start']) . ' – ' . self::fmtDate((string) $r['period_end'])),
                self::fact($days < 0 ? self::plural(-$days, 'day') . ' late' : ($days === 0 ? 'Due today' : 'Due in ' . self::plural($days, 'day'))),
            ],
            'url'      => '/fleetforge/accounting/tax',
            'stage'    => $stage,
            'priority' => $stage === 'due_soon' ? 'todo' : 'urgent',
        ];
    }
}
