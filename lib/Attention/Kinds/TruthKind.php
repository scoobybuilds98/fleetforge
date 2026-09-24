<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/TruthKind.php
 *
 * Base for kinds the system can re-check in the database (S-ATTENTION-INBOX).
 *
 * A subclass implements two things:
 *   fetch(?int $id)  → rows describing CURRENT problems, keyed by entity id
 *                      (one entity when $id is given, all when null). The SAME
 *                      query serves both, so a single re-check and the hourly
 *                      sweep can never disagree about what counts as a problem.
 *   build(array $r)  → item data for one row (title, facts, url, priority,
 *                      stage), or null to skip it.
 *
 * Required by: every lib/Attention/Kinds/*Kind.php with a database check
 * Defines:     FleetForge\Attention\Kinds\TruthKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

abstract class TruthKind extends Kind
{
    /**
     * Current problems.
     *
     * @param  int|null $entityId  Limit to one entity, or null for all
     * @return array<int, array>   entityId => row
     */
    abstract protected function fetch(?int $entityId): array;

    /**
     * Item data for one problem row.
     *
     * @param  array $row
     * @return array|null
     */
    abstract protected function build(array $row): ?array;

    /** @return bool */
    public function hasTruth(): bool
    {
        return true;
    }

    /**
     * @param  int $entityId
     * @return array|null
     */
    public function evaluate(int $entityId): ?array
    {
        $rows = $this->fetch($entityId);
        return isset($rows[$entityId]) ? $this->build($rows[$entityId]) : null;
    }

    /**
     * @return array<int, array>
     */
    public function evaluateAll(): array
    {
        $out = [];
        foreach ($this->fetch(null) as $id => $row) {
            $data = $this->build($row);
            if ($data !== null) {
                $out[(int) $id] = $data;
            }
        }
        return $out;
    }

    /**
     * Key a result set by a column (first row wins — e.g. a unit on two
     * overlapping leases).
     *
     * @param  array[] $rows
     * @param  string  $col
     * @return array<int, array>
     */
    protected static function keyBy(array $rows, string $col = 'id'): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (int) $r[$col];
            if (!isset($out[$k])) {
                $out[$k] = $r;
            }
        }
        return $out;
    }

    /**
     * "AND alias.id = ?" fragment + param when narrowing to one entity.
     *
     * @param  int|null $entityId
     * @param  string   $col     Qualified column
     * @param  array    $params  Appended to
     * @return string
     */
    protected static function only(?int $entityId, string $col, array &$params): string
    {
        if ($entityId === null) {
            return '';
        }
        $params[] = $entityId;
        return " AND {$col} = ?";
    }
}
