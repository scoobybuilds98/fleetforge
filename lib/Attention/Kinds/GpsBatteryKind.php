<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/GpsBatteryKind.php
 *
 * "GPS battery low" — a unit's Samsara tracker battery is under 20%
 * (S-ATTENTION-INBOX).
 *
 * Problem: equipment_units.samsara_battery_pct < 20 (critical < 10). The
 * Samsara sync writes the value; a later sync with a higher reading closes
 * the item. cron/samsara_sync.php groups several units into one notice with
 * no entity id, so that event re-checks the whole kind instead.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\GpsBatteryKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class GpsBatteryKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'gps_battery';
    }

    /** @return string */
    public function label(): string
    {
        return 'GPS battery low';
    }

    /** @return string */
    public function description(): string
    {
        return 'A unit\'s GPS tracker battery is under 20%. Closes when a later reading is back up.';
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

    /** @return string[] */
    public function stages(): array
    {
        return ['low', 'critical'];
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $params = [];
        $only   = self::only($entityId, 'eu.id', $params);
        return self::keyBy(\db_select(
            "SELECT eu.id, eu.unit_number, eu.samsara_battery_pct
               FROM equipment_units eu
              WHERE eu.deleted_at IS NULL AND eu.status NOT IN ('inactive', 'decommissioned')
                AND eu.samsara_battery_pct IS NOT NULL AND eu.samsara_battery_pct < 20{$only}",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $pct = (int) $r['samsara_battery_pct'];
        return [
            'title' => 'GPS battery ' . ($pct < 10 ? 'critical' : 'low') . ': unit ' . $r['unit_number'] . " ({$pct}%)",
            'facts' => [self::fact('Tracker battery at ' . $pct . '%')],
            'url'   => '/fleetforge/equipment/show?id=' . (int) $r['id'],
            'stage' => $pct < 10 ? 'critical' : 'low',
        ];
    }
}
