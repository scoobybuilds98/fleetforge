<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/LeaseReopenedKind.php
 *
 * "Reopened lease" — a closed lease was reopened (usually to fix a mileage
 * reading) and still needs closing again (S-ATTENTION-INBOX).
 *
 * Problem: the lease is active and its LATEST status change was
 * completed → active (api/v1/leases/reopen.php writes that row). Re-closing
 * (close.php fires lease.closed → re-check) or cancelling ends it.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\LeaseReopenedKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class LeaseReopenedKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'lease_reopened';
    }

    /** @return string */
    public function label(): string
    {
        return 'Reopened lease';
    }

    /** @return string */
    public function description(): string
    {
        return 'A closed lease was reopened and needs closing again. Closes when the lease is closed again.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'lease';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['manager'];
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
        $only   = self::only($entityId, 'l.id', $params);
        return self::keyBy(\db_select(
            "SELECT l.id, l.contract_number, eu.unit_number, c.company_name,
                    s.notes, s.changed_by, s.changed_at
               FROM leases l
               JOIN lease_status_log s
                 ON s.id = (SELECT MAX(s2.id) FROM lease_status_log s2 WHERE s2.lease_id = l.id)
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
               LEFT JOIN customers c ON c.id = l.customer_id
              WHERE l.status = 'active' AND l.deleted_at IS NULL
                AND s.old_status = 'completed' AND s.new_status = 'active'{$only}",
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
        $where = array_filter([$r['unit_number'] ? 'Unit ' . $r['unit_number'] : null, $r['company_name'] ?: null]);
        if ($where) {
            $facts[] = self::fact(implode(' · ', $where));
        }
        $facts[] = self::fact('Reopened by ' . ($r['changed_by'] ?: 'someone') . ' ' . \FleetForge\Attention\AttentionService::localLabel((string) $r['changed_at'], 'D j M'));
        $why = trim((string) ($r['notes'] ?? ''));
        if ($why !== '') {
            $facts[] = self::fact(mb_substr($why, 0, 120));
        }
        return [
            'title' => 'Lease ' . $r['contract_number'] . ' reopened: close it again',
            'facts' => $facts,
            'url'   => '/fleetforge/leases/show?id=' . (int) $r['id'],
        ];
    }
}
