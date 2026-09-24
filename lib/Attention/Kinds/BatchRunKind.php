<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/BatchRunKind.php
 *
 * "Batch run approval" — a Batch Invoicing run was submitted and is waiting
 * for someone allowed to approve it (S-ATTENTION-INBOX).
 *
 * Problem: invoice_batch_runs.status = 'pending'. Closes on approve /
 * reject (decide.php fires invoice.batch_run_approved|rejected → re-check)
 * or cancel (swept hourly).
 *
 * Audience: managers (invoices/approve by default; super admins always).
 * Before, the submit notice went to everyone who could VIEW invoices.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\BatchRunKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class BatchRunKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'batch_run_approval';
    }

    /** @return string */
    public function label(): string
    {
        return 'Batch run approval';
    }

    /** @return string */
    public function description(): string
    {
        return 'A Batch Invoicing run is waiting for approval. Closes when it\'s approved, rejected or cancelled.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'batch_run';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['manager'];
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
        $params = [];
        $only   = self::only($entityId, 'b.id', $params);
        return self::keyBy(\db_select(
            "SELECT b.id, b.reference, b.invoice_count, b.total_by_currency, b.submitted_at,
                    u.name AS submitted_by_name
               FROM invoice_batch_runs b
               LEFT JOIN users u ON u.id = b.submitted_by
              WHERE b.status = 'pending' AND b.deleted_at IS NULL{$only}",
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
        if ($r['invoice_count'] !== null) {
            $facts[] = self::fact(self::plural((int) $r['invoice_count'], 'invoice'));
        }
        $totals = json_decode((string) ($r['total_by_currency'] ?? ''), true);
        if (is_array($totals) && $totals) {
            $parts = [];
            foreach ($totals as $cur => $amt) {
                $parts[] = self::fmtMoney((string) $amt, (string) $cur);
            }
            $facts[] = self::money(implode(' + ', $parts));
        }
        if (!empty($r['submitted_by_name'])) {
            $facts[] = self::fact('Submitted by ' . $r['submitted_by_name']);
        }
        return [
            'title' => 'Batch run ' . $r['reference'] . ' needs approval',
            'facts' => $facts,
            'url'   => '/fleetforge/billing/approval?id=' . (int) $r['id'],
        ];
    }
}
