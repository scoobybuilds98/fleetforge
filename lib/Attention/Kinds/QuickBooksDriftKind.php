<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/QuickBooksDriftKind.php
 *
 * "QuickBooks differences" — Open differences between FleetForge and QuickBooks (acc_qbo_drift_events),
 * excluding the push_failure copies QuickBooksFailedKind already covers. One
 * system-wide item; closes when every difference is resolved or accepted.
 *
 * Single system item: entity id 0 (S-ATTENTION-INBOX).
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\QuickBooksDriftKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class QuickBooksDriftKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'qbo_drift';
    }

    /** @return string */
    public function label(): string
    {
        return 'QuickBooks differences';
    }

    /** @return string */
    public function description(): string
    {
        return 'Differences found between FleetForge and QuickBooks that need reviewing. Closes when they\'re all resolved or accepted.';
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
        $r = \db_row(
            "SELECT COUNT(*) AS n, MIN(detected_at) AS oldest
               FROM acc_qbo_drift_events
              WHERE resolved_at IS NULL AND resolution_type IS NULL
                AND detection_source <> 'push_failure'"
        );
        return (int) ($r['n'] ?? 0) > 0 ? [0 => $r] : [];
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $facts = [];
        if ($r['oldest'] !== null) {
            $facts[] = self::fact('Oldest found ' . \FleetForge\Attention\AttentionService::ageLabel((string) $r['oldest']) . ' ago');
        }
        return [
            'title' => 'QuickBooks: ' . self::plural((int) $r['n'], 'difference') . ' to review',
            'facts' => $facts,
            'url'   => '/fleetforge/quickbooks/drift',
        ];
    }
}
