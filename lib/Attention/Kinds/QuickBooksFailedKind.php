<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/QuickBooksFailedKind.php
 *
 * "QuickBooks sync failures" — Records whose LATEST sync attempt failed (acc_qbo_sync_queue). One
 * system-wide item ("40 records failed to sync"), not one per record — the fix
 * happens on the Sync Queue page either way. Closes when they're retried
 * successfully or cleared.
 *
 * Single system item: entity id 0 (S-ATTENTION-INBOX).
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\QuickBooksFailedKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class QuickBooksFailedKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'qbo_failed';
    }

    /** @return string */
    public function label(): string
    {
        return 'QuickBooks sync failures';
    }

    /** @return string */
    public function description(): string
    {
        return 'Records that failed to sync to QuickBooks. One item for all of them; closes when they\'re retried or cleared.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'quickbooks';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['accountant'];
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        if ($entityId !== null && $entityId !== 0) {
            return [];
        }
        // WHY "latest row per record": a record retried successfully leaves
        // its old failed row behind; only a record whose MOST RECENT attempt
        // failed still needs someone.
        $rows = \db_select(
            "SELECT q.entity_type, COUNT(*) AS n, MIN(q.enqueued_at) AS oldest
               FROM acc_qbo_sync_queue q
              WHERE q.status = 'failed'
                AND q.id = (SELECT MAX(q2.id) FROM acc_qbo_sync_queue q2
                             WHERE q2.entity_type = q.entity_type AND q2.entity_id = q.entity_id)
              GROUP BY q.entity_type"
        );
        return $rows ? [0 => ['by_type' => $rows]] : [];
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $total  = 0;
        $parts  = [];
        $oldest = null;
        foreach ($r['by_type'] as $t) {
            $n = (int) $t['n'];
            $total += $n;
            $parts[] = self::plural($n, str_replace('_', ' ', (string) $t['entity_type']));
            if ($t['oldest'] !== null && ($oldest === null || $t['oldest'] < $oldest)) {
                $oldest = (string) $t['oldest'];
            }
        }
        $facts = [self::fact(implode(', ', $parts))];
        if ($oldest !== null) {
            $facts[] = self::fact('Oldest ' . \FleetForge\Attention\AttentionService::ageLabel($oldest) . ' ago');
        }
        return [
            'title' => 'QuickBooks: ' . self::plural($total, 'record') . ' failed to sync',
            'facts' => $facts,
            'url'   => '/fleetforge/quickbooks/sync_queue',
        ];
    }
}
