<?php
declare(strict_types=1);

/**
 * lib/Support/LocalStampMigrator.php
 *
 * One-time data repair for S-UTC-STAMPS: shift DATETIME values that were written
 * as company-LOCAL wall time (PHP `date('Y-m-d H:i:s')`) to UTC, the convention
 * every DATETIME column now follows (includes/db.php pins the session to
 * '+00:00'; format_datetime() and FF_parseUtc() read stored values as UTC).
 *
 * WHY A CASE OVER DST TRANSITIONS: a Pacific wall-time value is 7h (PDT) or 8h
 * (PST) behind UTC depending on the date of the value itself, and named MySQL time
 * zones are not loaded on dev or prod (CONVERT_TZ(…,'America/Vancouver') is NULL).
 * The offset for every wall-time range is computed here in PHP from
 * ff_business_timezone()'s real transitions and emitted as ONE
 * `CASE WHEN col < boundary THEN seconds …` expression, so each row is shifted
 * exactly once from its ORIGINAL value inside a single UPDATE (a range-by-range
 * sequence of UPDATEs could shift a row twice after it moved into a later range).
 * Ambiguous fall-back wall times (01:00–02:00 happens twice) resolve to the first
 * (daylight) occurrence; spring-forward gap times resolve to daylight time.
 *
 * WHY A CUTOVER: the code change ships first, so rows written after deploy are
 * already UTC. Only values strictly below the cutover — the local wall time at
 * (or just before) the deploy — are pre-deploy local stamps. A UTC value written
 * after the deploy is always later than the local cutover wall time (UTC runs
 * ahead of Pacific), so it can never be caught. Future-dated local values (e.g.
 * an expiry written as now+30 days before deploy) are above the cutover and need a
 * companion filter (`$extraWhere`, e.g. "sent_at < ?") — convert such columns
 * BEFORE their companion column.
 *
 * IDEMPOTENT: the UPDATE and a `settings` marker row
 * (data_migration.utc_stamps.<table>.<column>) commit in one transaction; a
 * marked column is skipped. ON UPDATE CURRENT_TIMESTAMP columns on the same table
 * are pinned to themselves so the repair does not rewrite updated_at.
 *
 * Used by: scripts/migrate_local_stamps_to_utc.php, tests/_smoke_utc_stamps.php
 * Requires: config/app.php (db_*, ff_business_timezone)
 */

namespace FleetForge\Support;

final class LocalStampMigrator
{
    /** settings.key prefix for the per-column completion marker. */
    public const MARKER_PREFIX = 'data_migration.utc_stamps.';

    /**
     * Has this column already been converted?
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public static function isDone(string $table, string $column): bool
    {
        return \db_row("SELECT 1 FROM settings WHERE `key` = ?", [self::MARKER_PREFIX . $table . '.' . $column]) !== null;
    }

    /**
     * Build the per-row "seconds to add" CASE expression for local wall-time
     * values of $col between $minLocal and $maxLocal in $tz.
     *
     * @param string        $colSql   already-quoted column reference, e.g. `created_at`
     * @param \DateTimeZone $tz       business timezone
     * @param string        $minLocal earliest value present ('Y-m-d H:i:s')
     * @param string        $maxLocal latest value to convert ('Y-m-d H:i:s')
     * @return string SQL expression (no user input embedded — boundaries are generated)
     */
    public static function offsetCaseSql(string $colSql, \DateTimeZone $tz, string $minLocal, string $maxLocal): string
    {
        $utc   = new \DateTimeZone('UTC');
        $start = (new \DateTimeImmutable($minLocal, $utc))->modify('-2 days')->getTimestamp();
        $end   = (new \DateTimeImmutable($maxLocal, $utc))->modify('+2 days')->getTimestamp();
        $transitions = $tz->getTransitions($start, $end) ?: [['ts' => $start, 'offset' => $tz->getOffset(new \DateTimeImmutable('@' . $start))]];

        $offset = (int) $transitions[0]['offset'];          // offset in force at $start
        $sql    = 'CASE';
        for ($i = 1, $n = count($transitions); $i < $n; $i++) {
            // The change happens at UTC instant ts; in the OLD offset's wall clock
            // that is ts + old offset. Values below that wall time used the old offset.
            $boundaryWall = gmdate('Y-m-d H:i:s', (int) $transitions[$i]['ts'] + $offset);
            $sql .= sprintf(" WHEN %s < '%s' THEN %d", $colSql, $boundaryWall, -$offset);
            $offset = (int) $transitions[$i]['offset'];
        }
        // No transition inside the value range → one constant offset for every row
        // ("CASE ELSE n END" without a WHEN branch is not valid SQL).
        return $sql === 'CASE' ? (string) -$offset : $sql . sprintf(' ELSE %d END', -$offset);
    }

    /**
     * Convert one column's pre-cutover local values to UTC (or preview it).
     *
     * @param string      $table        table name (validated against information_schema)
     * @param string      $column       DATETIME/TIMESTAMP column (validated)
     * @param string      $cutoverLocal local wall time 'Y-m-d H:i:s' — only values below it convert
     * @param string|null $extraWhere   optional additional SQL predicate (generated by the caller's registry,
     *                                  never user input), may contain '?' placeholders
     * @param array       $extraParams  params for $extraWhere, in order
     * @param bool        $apply        false = count + sample only
     * @param bool        $valueBelowCutover true (default): only values below the local cutover convert.
     *                                  false: FUTURE-DATED columns (expiries, lock-until) whose pre-deploy
     *                                  values can sit above the cutover — rows are then selected by
     *                                  $extraWhere alone (e.g. "updated_at < ?" with the UTC cutover;
     *                                  updated_at is ON UPDATE and pinned by this repair, so it keeps
     *                                  identifying rows last written before the deploy)
     * @return array{table:string,column:string,status:string,rows:int,sample:array}
     * @throws \InvalidArgumentException on an unknown table/column, malformed cutover, or a row-filter
     *                                   conversion without $extraWhere
     */
    public static function convert(string $table, string $column, string $cutoverLocal, ?string $extraWhere = null, array $extraParams = [], bool $apply = false, bool $valueBelowCutover = true): array
    {
        if (!$valueBelowCutover && ($extraWhere === null || trim($extraWhere) === '')) {
            throw new \InvalidArgumentException("{$table}.{$column}: a row-filter conversion needs an extraWhere predicate");
        }
        if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException("Bad identifier {$table}.{$column}");
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cutoverLocal);
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $cutoverLocal) {
            throw new \InvalidArgumentException("Cutover must be 'Y-m-d H:i:s' local wall time, got '{$cutoverLocal}'");
        }
        $colInfo = \db_row(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if (!$colInfo || !in_array(strtolower((string) $colInfo['DATA_TYPE']), ['datetime', 'timestamp'], true)) {
            throw new \InvalidArgumentException("{$table}.{$column} is not an existing DATETIME/TIMESTAMP column");
        }

        $result = ['table' => $table, 'column' => $column, 'status' => 'pending', 'rows' => 0, 'sample' => []];
        if (self::isDone($table, $column)) {
            $result['status'] = 'already_done';
            return $result;
        }

        $colSql = '`' . $column . '`';
        if ($valueBelowCutover) {
            $where  = "{$colSql} IS NOT NULL AND {$colSql} < ?" . ($extraWhere !== null && $extraWhere !== '' ? " AND ({$extraWhere})" : '');
            $params = array_merge([$cutoverLocal], $extraParams);
        } else {
            $where  = "{$colSql} IS NOT NULL AND ({$extraWhere})";
            $params = $extraParams;
        }

        $range = \db_row("SELECT COUNT(*) AS n, MIN({$colSql}) AS lo, MAX({$colSql}) AS hi FROM `{$table}` WHERE {$where}", $params);
        $result['rows'] = (int) ($range['n'] ?? 0);
        $case = $result['rows'] > 0
            ? self::offsetCaseSql($colSql, \ff_business_timezone(), (string) $range['lo'], (string) $range['hi'])
            : '0';

        if ($result['rows'] > 0) {
            $result['sample'] = \db_select(
                "SELECT {$colSql} AS before_value, DATE_ADD({$colSql}, INTERVAL ({$case}) SECOND) AS after_value
                   FROM `{$table}` WHERE {$where} ORDER BY {$colSql} DESC LIMIT 3",
                $params
            );
        }
        if (!$apply) {
            $result['status'] = $result['rows'] > 0 ? 'would_convert' : 'nothing_to_convert';
            return $result;
        }

        // Keep ON UPDATE CURRENT_TIMESTAMP columns (updated_at) untouched by this repair.
        $pins = '';
        foreach (\db_select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND LOWER(EXTRA) LIKE '%on update%' AND COLUMN_NAME <> ?",
            [$table, $column]
        ) as $c) {
            $pins .= ', `' . $c['COLUMN_NAME'] . '` = `' . $c['COLUMN_NAME'] . '`';
        }

        \db_transaction(function () use ($table, $column, $colSql, $case, $pins, $where, $params, $cutoverLocal, &$result): void {
            if (self::isDone($table, $column)) {                 // re-check inside the txn
                $result['status'] = 'already_done';
                return;
            }
            $affected = \db_execute(
                "UPDATE `{$table}` SET {$colSql} = DATE_ADD({$colSql}, INTERVAL ({$case}) SECOND){$pins} WHERE {$where}",
                $params
            );
            \db_insert('settings', [
                'key'         => self::MARKER_PREFIX . $table . '.' . $column,
                'value'       => json_encode(['cutover_local' => $cutoverLocal, 'rows' => $affected, 'converted_at_utc' => gmdate('Y-m-d H:i:s')]),
                'value_type'  => 'json',
                'group_name'  => 'system',
                'label'       => "UTC stamp migration: {$table}.{$column}",
                'description' => 'S-UTC-STAMPS one-time repair marker (local wall time → UTC). Do not delete: the migrator skips columns that have this row.',
            ]);
            $result['rows']   = (int) $affected;
            $result['status'] = 'converted';
        });
        return $result;
    }
}
