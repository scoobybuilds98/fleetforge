<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/DamageClaimKind.php
 *
 * "Damage claim" — a newly reported damage claim that nobody has assessed yet
 * (S-ATTENTION-INBOX).
 *
 * WHY only 'reported': once a claim is assessed it moves through repair /
 * invoicing on the Damage Claims page, which is its own workflow. The
 * attention list's job is making sure a NEW report isn't missed.
 * Closes when the claim moves past 'reported' (damage.updated re-checks).
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\DamageClaimKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class DamageClaimKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'damage_claim';
    }

    /** @return string */
    public function label(): string
    {
        return 'Damage claim';
    }

    /** @return string */
    public function description(): string
    {
        return 'A damage claim was reported and needs assessing. Closes when the claim is assessed (or resolved).';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'damage_claim';
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
        $params = [];
        $only   = self::only($entityId, 'd.id', $params);
        return self::keyBy(\db_select(
            "SELECT d.id, d.claim_number, d.estimated_repair_cost, d.severity, d.created_at,
                    eu.unit_number, COALESCE(c.company_name, d.customer_name) AS customer_name
               FROM damage_claims d
               LEFT JOIN equipment_units eu ON eu.id = d.equipment_unit_id
               LEFT JOIN customers c ON c.id = d.customer_id
              WHERE d.status = 'reported' AND d.deleted_at IS NULL{$only}",
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
        $where = array_filter([$r['unit_number'] ? 'Unit ' . $r['unit_number'] : null, $r['customer_name'] ?: null]);
        if ($where) {
            $facts[] = self::fact(implode(' · ', $where));
        }
        if (!empty($r['severity'])) {
            $facts[] = self::fact('Severity: ' . str_replace('_', ' ', (string) $r['severity']));
        }
        if ($r['estimated_repair_cost'] !== null && (float) $r['estimated_repair_cost'] > 0) {
            $facts[] = self::money('Estimate ' . self::fmtMoney((string) $r['estimated_repair_cost']));
        }
        return [
            'title' => 'Damage claim ' . $r['claim_number'] . ' needs assessing',
            'facts' => $facts,
            'url'   => '/fleetforge/damage_claims/show?id=' . (int) $r['id'],
        ];
    }
}
