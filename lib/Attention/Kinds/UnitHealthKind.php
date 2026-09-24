<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/UnitHealthKind.php
 *
 * "Unit health red" — a unit's nightly health score fell into red
 * (< 20, equipment_health_color()) (S-ATTENTION-INBOX).
 *
 * Orange stays an update: it's a trend to watch, not a job. Closes when the
 * nightly recompute lifts the score back to 20+.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\UnitHealthKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class UnitHealthKind extends TruthKind
{
    /** Red threshold (mirrors equipment_health_color() in includes/functions.php). */
    private const RED_BELOW = 20;

    /** @return string */
    public function key(): string
    {
        return 'unit_health';
    }

    /** @return string */
    public function label(): string
    {
        return 'Unit health red';
    }

    /** @return string */
    public function description(): string
    {
        return 'A unit\'s health score is red. Closes when the nightly score recovers.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'equipment_unit';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['manager', 'dispatcher'];
    }

    /** @return string */
    public function area(): string
    {
        return 'fleet';
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $params = [self::RED_BELOW];
        $only   = self::only($entityId, 'eu.id', $params);
        return self::keyBy(\db_select(
            "SELECT eu.id, eu.unit_number, eu.health_score, eu.status
               FROM equipment_units eu
              WHERE eu.deleted_at IS NULL AND eu.status NOT IN ('inactive', 'decommissioned')
                AND eu.health_score IS NOT NULL AND eu.health_score < ?{$only}",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        return [
            'title' => 'Unit ' . $r['unit_number'] . ' health is red',
            'facts' => [
                self::fact('Health score ' . (int) $r['health_score'] . ' / 100'),
                self::fact('Status: ' . str_replace('_', ' ', (string) $r['status'])),
            ],
            'url'   => '/fleetforge/equipment/show?id=' . (int) $r['id'],
        ];
    }
}
