<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/ComplianceKind.php
 *
 * "Unit documents" — one item per unit whose CVI or registration is expired
 * or expiring (S-ATTENTION-INBOX).
 *
 * Replaces the staff side of cron/compliance_alerts.php as a to-do: that cron
 * re-alerted every affected unit every other day (its 24h de-dup window
 * lined up with its own run time), 31k rows since June, and labelled
 * documents 7 days from expiry as "expired". Here each unit has ONE item
 * that gets worse in place: expiring (≤ warning days, default 30) →
 * this week (≤ critical days, default 7) → expired (date passed = urgent).
 * It closes itself when the dates are updated.
 *
 * Only CVI + registration are checked, matching the Compliance page (MVI and
 * insurance were removed from every compliance UI — S-UNIT-COMPLIANCE-HIDE-
 * MVI-INS). Inactive/decommissioned units are ignored.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\ComplianceKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class ComplianceKind extends TruthKind
{
    /** Documents checked: column => label. */
    private const DOCS = [
        'cvi_expiry'          => 'CVI',
        'registration_expiry' => 'Registration',
    ];

    /** @return string */
    public function key(): string
    {
        return 'compliance';
    }

    /** @return string */
    public function label(): string
    {
        return 'Unit documents';
    }

    /** @return string */
    public function description(): string
    {
        return 'A unit\'s CVI or registration is expiring or expired. One item per unit; it gets more urgent as the date nears and closes when the date is updated.';
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

    /** @return bool */
    public function dynamicPriority(): bool
    {
        return true;
    }

    /** @return string */
    public function priorityRule(): string
    {
        return 'To do while expiring; urgent once a document has expired';
    }

    /** @return string */
    public function defaultWhatsApp(): string
    {
        return 'now';
    }

    /** @return string[] */
    public function stages(): array
    {
        return ['expiring', 'this_week', 'expired'];
    }

    /**
     * @param  string|null $stage
     * @return string
     */
    public function stageLabel(?string $stage): string
    {
        return match ($stage) {
            'expiring'  => 'expiring soon',
            'this_week' => 'expiring this week',
            'expired'   => 'expired',
            default     => (string) $stage,
        };
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        $warnDays = max(1, (int) \settings_get('alerts.compliance_warning_days', '30'));
        $horizon  = date('Y-m-d', strtotime(\ff_today() . " +{$warnDays} days"));
        $params   = [$horizon, $horizon];
        $only     = self::only($entityId, 'eu.id', $params);

        // WHY LEFT JOIN on active leases: the item says where the unit is
        // ("On rent to X") — an expired document on a unit out on rent is
        // what matters most. Overlapping leases can yield two rows per unit;
        // keyBy() keeps the first.
        return self::keyBy(\db_select(
            "SELECT eu.id, eu.unit_number, eu.status, eu.cvi_expiry, eu.registration_expiry,
                    c.company_name AS customer_name
               FROM equipment_units eu
               LEFT JOIN leases l ON l.equipment_unit_id = eu.id AND l.status = 'active' AND l.deleted_at IS NULL
               LEFT JOIN customers c ON c.id = l.customer_id
              WHERE eu.deleted_at IS NULL
                AND eu.status NOT IN ('inactive', 'decommissioned')
                AND ((eu.cvi_expiry IS NOT NULL AND eu.cvi_expiry <= ?)
                  OR (eu.registration_expiry IS NOT NULL AND eu.registration_expiry <= ?)){$only}
              ORDER BY eu.id, l.id",
            $params
        ));
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        $warnDays = max(1, (int) \settings_get('alerts.compliance_warning_days', '30'));
        $critDays = max(0, (int) \settings_get('alerts.compliance_critical_days', '7'));

        $docs  = [];
        $worst = -1;
        $stages = $this->stages();
        foreach (self::DOCS as $col => $label) {
            if (empty($r[$col])) {
                continue;
            }
            $days = self::daysUntil((string) $r[$col]);
            if ($days > $warnDays) {
                continue;
            }
            $stage = $days < 0 ? 'expired' : ($days <= $critDays ? 'this_week' : 'expiring');
            $worst = max($worst, (int) array_search($stage, $stages, true));
            $docs[] = ['label' => $label, 'date' => (string) $r[$col], 'days' => $days];
        }
        if (!$docs) {
            return null;
        }
        $stage = $stages[$worst];
        $unit  = (string) $r['unit_number'];

        if (count($docs) === 1) {
            $d = $docs[0];
            $title = $d['days'] < 0
                ? "{$d['label']} expired: unit {$unit}"
                : "{$d['label']} expires " . self::fmtDate($d['date']) . ": unit {$unit}";
        } else {
            $title = implode(' and ', array_column($docs, 'label')) . " need renewing: unit {$unit}";
        }

        $facts = [];
        foreach ($docs as $d) {
            $facts[] = self::fact(match (true) {
                $d['days'] < 0   => "{$d['label']} expired " . self::fmtDate($d['date']) . ' (' . self::plural(-$d['days'], 'day') . ' ago)',
                $d['days'] === 0 => "{$d['label']} expires today",
                default          => "{$d['label']} expires " . self::fmtDate($d['date']) . ' (in ' . self::plural($d['days'], 'day') . ')',
            });
        }
        $facts[] = self::fact(match (true) {
            !empty($r['customer_name'])    => 'On rent to ' . $r['customer_name'],
            $r['status'] === 'maintenance' => 'In maintenance',
            $r['status'] === 'reserved'    => 'Reserved',
            default                        => 'In the yard',
        });

        return [
            'title'    => $title,
            'facts'    => $facts,
            'url'      => '/fleetforge/compliance?q=' . rawurlencode($unit),
            'stage'    => $stage,
            'priority' => $stage === 'expired' ? 'urgent' : 'todo',
        ];
    }
}
