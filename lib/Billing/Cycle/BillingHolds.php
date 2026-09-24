<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

/**
 * lib/Billing/Cycle/BillingHolds.php
 *
 * S-BILLING-MODULE — standing "do not bill" instructions.
 *
 * A hold covers one lease, or every lease of a customer, from starts_on
 * until it is released (or until ends_on). It exists for the cases where
 * billing is paused on purpose for longer than one run: a disputed account,
 * paperwork the customer has to send first, a unit off-road under warranty.
 *
 * How it differs from "Hold for review" in the workbench's dry run: that is
 * a one-off flag on a single lease + period that lands in the exceptions
 * queue to be resolved. A billing hold is an instruction that stays in force
 * across months and is deliberately NOT an exception — nothing is broken,
 * so it never clutters the Needs Attention queue.
 *
 * Who honours it:
 *   - batch_eligible     shows the lease as "On hold" and never auto-selects it
 *   - batch_generate, batch_runs/create + generate, BatchPreviewService
 *                        refuse it with the hold reason
 *   - cron/invoice_generate_monthly
 *                        skips it (and does not advance the lease's pointer,
 *                        so releasing the hold lets the cron catch up)
 *   - a lease's own Generate Invoice / close is NOT blocked — billing one
 *     lease by hand is a deliberate act; the cycle's Review flags it instead.
 *
 * A period is held when the hold's window overlaps it:
 *   starts_on <= period_end AND (ends_on IS NULL OR ends_on >= period_start)
 *
 * @session S-BILLING-MODULE
 */
final class BillingHolds
{
    private function __construct() {}

    private const WINDOW_SQL = "h.released_at IS NULL
        AND h.starts_on <= ? AND (h.ends_on IS NULL OR h.ends_on >= ?)";

    /**
     * The hold (if any) that stops a lease being billed for a period.
     * Lease-level holds win over customer-level ones (more specific reason).
     */
    public static function activeFor(int $leaseId, string $periodStart, string $periodEnd): ?array
    {
        try {
            $row = \db_row(
                "SELECT h.*
                   FROM billing_holds h
                   JOIN leases l ON l.id = ?
                  WHERE " . self::WINDOW_SQL . "
                    AND ((h.scope = 'lease' AND h.lease_id = l.id)
                      OR (h.scope = 'customer' AND h.customer_id = l.customer_id))
                  ORDER BY (h.scope = 'lease') DESC, h.id DESC
                  LIMIT 1",
                [$leaseId, $periodEnd, $periodStart]
            );
        } catch (\Throwable $e) {
            // A missing table (pre-migration deploy) must never stop billing.
            error_log('[BillingHolds] activeFor failed: ' . $e->getMessage());
            return null;
        }
        return $row ?: null;
    }

    /** Human sentence for refusals: "On billing hold since 2026-09-01: disputed rate". */
    public static function describe(array $hold): string
    {
        $scope = $hold['scope'] === 'customer' ? 'Customer on billing hold' : 'Lease on billing hold';
        $until = $hold['ends_on'] ? ' until ' . $hold['ends_on'] : '';
        return "{$scope} since {$hold['starts_on']}{$until}: {$hold['reason']}";
    }

    /**
     * leaseId => hold for every held lease in $leaseIds for the period
     * (one query; lease holds preferred over customer holds).
     *
     * @param int[] $leaseIds
     * @return array<int, array>
     */
    public static function heldMap(array $leaseIds, string $periodStart, string $periodEnd): array
    {
        if (!$leaseIds) return [];
        $ph = implode(',', array_fill(0, count($leaseIds), '?'));
        try {
            $rows = \db_select(
                "SELECT l.id AS held_lease_id, h.*
                   FROM leases l
                   JOIN billing_holds h
                     ON ((h.scope = 'lease' AND h.lease_id = l.id)
                      OR (h.scope = 'customer' AND h.customer_id = l.customer_id))
                  WHERE l.id IN ({$ph}) AND " . self::WINDOW_SQL . "
                  ORDER BY (h.scope = 'lease') ASC, h.id ASC",
                array_merge($leaseIds, [$periodEnd, $periodStart])
            );
        } catch (\Throwable $e) {
            error_log('[BillingHolds] heldMap failed: ' . $e->getMessage());
            return [];
        }
        $map = [];
        foreach ($rows as $r) {
            // ASC order + overwrite = the lease-scoped (then newest) hold wins.
            $map[(int) $r['held_lease_id']] = $r;
        }
        return $map;
    }

    /**
     * List holds for the Holds tab.
     *
     * @param string $state active | released | all
     */
    public static function list(string $state = 'active', ?int $customerId = null, ?int $leaseId = null): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($state === 'active') {
            $where[] = 'h.released_at IS NULL AND (h.ends_on IS NULL OR h.ends_on >= ?)';
            $params[] = \ff_today();
        } elseif ($state === 'released') {
            $where[] = '(h.released_at IS NOT NULL OR (h.ends_on IS NOT NULL AND h.ends_on < ?))';
            $params[] = \ff_today();
        }
        if ($customerId) {
            $where[] = 'h.customer_id = ?';
            $params[] = $customerId;
        }
        if ($leaseId) {
            $where[] = "(h.lease_id = ? OR (h.scope = 'customer' AND h.customer_id = (SELECT customer_id FROM leases WHERE id = ?)))";
            $params[] = $leaseId;
            $params[] = $leaseId;
        }
        return \db_select(
            "SELECT h.*, c.company_name, l.contract_number,
                    COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number,
                    cu.name AS created_by_name, ru.name AS released_by_name,
                    (SELECT COUNT(*) FROM leases lc
                      WHERE lc.customer_id = h.customer_id AND lc.status = 'active' AND lc.deleted_at IS NULL) AS customer_active_leases
               FROM billing_holds h
               JOIN customers c ON c.id = h.customer_id
               LEFT JOIN leases l ON l.id = h.lease_id
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
               LEFT JOIN users cu ON cu.id = h.created_by
               LEFT JOIN users ru ON ru.id = h.released_by
              WHERE " . implode(' AND ', $where) . "
              ORDER BY h.released_at IS NULL DESC, h.created_at DESC
              LIMIT 500",
            $params
        );
    }

    /**
     * Place a hold. Returns the new id. Validation errors throw
     * \InvalidArgumentException with a field => message array in getMessage()
     * JSON so the endpoint can surface them per field.
     */
    public static function create(
        string $scope,
        ?int $leaseId,
        ?int $customerId,
        string $reason,
        string $startsOn,
        ?string $endsOn,
        ?int $userId
    ): int {
        $fields = [];
        if (!in_array($scope, ['lease', 'customer'], true)) {
            $fields['scope'] = 'Choose a lease or a whole customer.';
        }
        if ($scope === 'lease') {
            $lease = $leaseId ? \db_row(
                "SELECT id, customer_id, contract_number, status FROM leases WHERE id = ? AND deleted_at IS NULL",
                [$leaseId]
            ) : null;
            if (!$lease) {
                $fields['lease_id'] = 'Pick the lease to hold.';
            } else {
                $customerId = (int) $lease['customer_id'];
            }
        } else {
            $leaseId = null;
            if (!$customerId || !\db_row("SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId])) {
                $fields['customer_id'] = 'Pick the customer to hold.';
            }
        }
        $reason = trim($reason);
        if ($reason === '') {
            $fields['reason'] = 'Say why billing is on hold — it is shown wherever the hold stops billing.';
        } elseif (mb_strlen($reason) > 500) {
            $fields['reason'] = 'Keep the reason under 500 characters.';
        }
        if ($endsOn !== null && $endsOn < $startsOn) {
            $fields['ends_on'] = 'The end date cannot be before the start date.';
        }
        if ($fields) {
            throw new \InvalidArgumentException(json_encode($fields));
        }

        $dup = \db_row(
            "SELECT id FROM billing_holds
              WHERE released_at IS NULL AND scope = ? AND customer_id = ?
                AND " . ($leaseId ? 'lease_id = ?' : 'lease_id IS NULL') . "
                AND (ends_on IS NULL OR ends_on >= ?)",
            $leaseId ? [$scope, $customerId, $leaseId, $startsOn] : [$scope, $customerId, $startsOn]
        );
        if ($dup) {
            throw new \InvalidArgumentException(json_encode([
                $scope === 'lease' ? 'lease_id' : 'customer_id' => 'This one is already on hold — release that hold or edit its dates instead.',
            ]));
        }

        return (int) \db_transaction(static function () use ($scope, $leaseId, $customerId, $reason, $startsOn, $endsOn, $userId) {
            $id = (int) \db_insert('billing_holds', [
                'scope'       => $scope,
                'lease_id'    => $leaseId,
                'customer_id' => $customerId,
                'reason'      => $reason,
                'starts_on'   => $startsOn,
                'ends_on'     => $endsOn,
                'created_by'  => $userId,
            ]);
            $label = self::label($scope, $leaseId, (int) $customerId);
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => BillingCycles::actor()['name'],
                'action'       => 'create',
                'module'       => 'billing',
                'entity_type'  => 'billing_hold',
                'entity_id'    => $id,
                'entity_label' => $label,
                'new_values'   => json_encode(compact('scope', 'leaseId', 'customerId', 'reason', 'startsOn', 'endsOn')),
                'notes'        => "Billing hold placed on {$label} from {$startsOn}" . ($endsOn ? " to {$endsOn}" : ' until released') . ": {$reason}",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            return $id;
        });
    }

    /** Release an active hold. Returns false when it was not active. */
    public static function release(int $id, string $note, ?int $userId): bool
    {
        return (bool) \db_transaction(static function () use ($id, $note, $userId) {
            $hold = \db_row("SELECT * FROM billing_holds WHERE id = ? FOR UPDATE", [$id]);
            if (!$hold || $hold['released_at'] !== null) {
                return false;
            }
            \db_execute(
                "UPDATE billing_holds SET released_at = ?, released_by = ?, release_note = ?
                  WHERE id = ? AND released_at IS NULL",
                [\ff_now_utc(), $userId, $note !== '' ? mb_substr($note, 0, 500) : null, $id]
            );
            $label = self::label((string) $hold['scope'], $hold['lease_id'] ? (int) $hold['lease_id'] : null, (int) $hold['customer_id']);
            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => BillingCycles::actor()['name'],
                'action'       => 'status_change',
                'module'       => 'billing',
                'entity_type'  => 'billing_hold',
                'entity_id'    => $id,
                'entity_label' => $label,
                'notes'        => "Billing hold on {$label} released" . ($note !== '' ? ": {$note}" : '.'),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            return true;
        });
    }

    private static function label(string $scope, ?int $leaseId, int $customerId): string
    {
        if ($scope === 'lease' && $leaseId) {
            $l = \db_row("SELECT contract_number FROM leases WHERE id = ?", [$leaseId]);
            return 'lease ' . ($l['contract_number'] ?? "#{$leaseId}");
        }
        $c = \db_row("SELECT company_name FROM customers WHERE id = ?", [$customerId]);
        return 'customer ' . ($c['company_name'] ?? "#{$customerId}");
    }
}
