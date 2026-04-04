<?php
declare(strict_types=1);
/**
 * lib/Reports/ReportBuilder.php
 *
 * Central helper for all Reports Module queries.
 * Handles: 15-minute result cache (report_cache table), CSV output with
 * UTF-8 BOM for Excel compatibility, date-range preset resolution,
 * and shared utilities used by all four report API endpoints.
 *
 * Required by: api/v1/reports/revenue.php, fleet.php, customer.php, compliance.php
 * Defines: FleetForge\Reports\ReportBuilder (static utility class)
 *
 * Spec ref: §7.10 Reports, PROGRESS.md S021
 * Decisions: D16 (bcmath strings — all monetary values), D17 (PSR-4 autoload)
 */

namespace FleetForge\Reports;

class ReportBuilder
{
    /**
     * Resolve a date-preset name into a [date_from, date_to] pair (Y-m-d strings).
     * Implements all 14 presets from spec §4.3. 'custom' passes through raw values.
     *
     * @param string      $preset     One of the 14 spec preset names
     * @param string|null $customFrom Y-m-d, used only when $preset === 'custom'
     * @param string|null $customTo   Y-m-d, used only when $preset === 'custom'
     * @return array{0: string, 1: string} [$from, $to]
     */
    public static function resolvePreset(
        string  $preset,
        ?string $customFrom = null,
        ?string $customTo   = null
    ): array {
        $today = date('Y-m-d');
        $now   = new \DateTimeImmutable($today);

        return match ($preset) {
            'today'        => [$today, $today],
            'yesterday'    => [
                $now->modify('-1 day')->format('Y-m-d'),
                $now->modify('-1 day')->format('Y-m-d'),
            ],
            'this_week'    => [
                $now->modify('monday this week')->format('Y-m-d'),
                $now->modify('sunday this week')->format('Y-m-d'),
            ],
            'last_week'    => [
                $now->modify('monday last week')->format('Y-m-d'),
                $now->modify('sunday last week')->format('Y-m-d'),
            ],
            'this_month'   => [$now->format('Y-m-01'), $now->format('Y-m-t')],
            'last_month'   => [
                $now->modify('first day of last month')->format('Y-m-d'),
                $now->modify('last day of last month')->format('Y-m-d'),
            ],
            'last_30'      => [$now->modify('-30 days')->format('Y-m-d'), $today],
            'last_90'      => [$now->modify('-90 days')->format('Y-m-d'), $today],
            'this_quarter' => self::thisQuarter($now),
            'last_quarter' => self::lastQuarter($now),
            'this_year'    => [$now->format('Y-01-01'), $now->format('Y-12-31')],
            'last_year'    => [
                ($now->format('Y') - 1) . '-01-01',
                ($now->format('Y') - 1) . '-12-31',
            ],
            'all_time'     => ['2020-01-01', $today],
            'custom'       => [
                $customFrom ?? $now->format('Y-m-01'),
                $customTo   ?? $today,
            ],
            // Unknown preset defaults to this_month
            default        => [$now->format('Y-m-01'), $today],
        };
    }

    /**
     * Current quarter: first day of Q start month → last day of Q end month.
     */
    private static function thisQuarter(\DateTimeImmutable $now): array
    {
        $month  = (int) $now->format('n');
        $year   = (int) $now->format('Y');
        $qStart = (int) ceil($month / 3) * 3 - 2; // 1, 4, 7, or 10
        $qEnd   = $qStart + 2;
        return [
            sprintf('%04d-%02d-01', $year, $qStart),
            (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $qEnd)))->format('Y-m-t'),
        ];
    }

    /**
     * Last quarter: same logic shifted back one quarter.
     */
    private static function lastQuarter(\DateTimeImmutable $now): array
    {
        $month = (int) $now->format('n');
        $year  = (int) $now->format('Y');
        $q     = (int) ceil($month / 3);
        if ($q === 1) {
            $q = 4;
            $year--;
        } else {
            $q--;
        }
        $qStart = ($q - 1) * 3 + 1;
        $qEnd   = $qStart + 2;
        return [
            sprintf('%04d-%02d-01', $year, $qStart),
            (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $qEnd)))->format('Y-m-t'),
        ];
    }

    /**
     * Ensure from <= to. Swaps if inverted so callers never have to worry.
     *
     * @return array{0: string, 1: string} [$from, $to]
     */
    public static function clampDates(string $from, string $to): array
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    /**
     * Generate a deterministic SHA-256 cache key.
     * ksort ensures stable ordering regardless of param insertion order.
     */
    public static function cacheKey(string $type, array $params): string
    {
        ksort($params);
        return hash('sha256', $type . '|' . json_encode($params));
    }

    /**
     * Attempt a cache read. Returns null on miss or when the row is expired.
     * Expiry is double-checked at read time (column index handles cleanup).
     */
    public static function getCached(string $type, array $params): ?array
    {
        $hash = self::cacheKey($type, $params);
        $row  = db_row(
            "SELECT result_data FROM report_cache
             WHERE report_type = ? AND parameters_hash = ? AND expires_at > NOW()",
            [$type, $hash]
        );
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string) $row['result_data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write (or replace) a cache entry. Uses INSERT … ON DUPLICATE KEY UPDATE
     * to avoid race conditions on the unique (report_type, parameters_hash) key.
     *
     * @param int $ttlMinutes Default 15 per spec §7.10
     */
    public static function setCached(
        string $type,
        array  $params,
        array  $data,
        int    $ttlMinutes = 15
    ): void {
        $hash    = self::cacheKey($type, $params);
        $encoded = json_encode($data);

        // Use MySQL DATE_ADD(NOW(), INTERVAL ? MINUTE) so expires_at is always
        // computed in the DB server's timezone — avoids PHP vs MySQL TZ mismatch
        // that causes cache entries to appear permanently expired.
        db_execute(
            "INSERT INTO report_cache
                (report_type, parameters_hash, parameters, result_data, generated_at, expires_at, generated_by)
             VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)
             ON DUPLICATE KEY UPDATE
                result_data  = VALUES(result_data),
                generated_at = NOW(),
                expires_at   = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                generated_by = VALUES(generated_by)",
            [
                $type,
                $hash,
                json_encode($params),
                $encoded,
                $ttlMinutes,
                current_user_id(),
                $ttlMinutes,  // second bind for ON DUPLICATE KEY UPDATE clause
            ]
        );
    }

    /**
     * Output a CSV file and terminate the request.
     * Writes UTF-8 BOM so Excel opens without garbling accented characters.
     * Clears any output buffers first to prevent stray whitespace contaminating the file.
     *
     * @param string   $filename Suggested download filename WITHOUT the .csv extension
     * @param string[] $headers  Column header row
     * @param array[]  $rows     Data rows — each element is an ordered array matching $headers
     * @return never
     */
    public static function outputCsv(string $filename, array $headers, array $rows): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM — required for Excel to open .csv in correct encoding
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_values($row), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /**
     * Format a value as a plain decimal string for CSV export.
     * Avoids $ symbol which breaks numeric parsing in some spreadsheet tools.
     */
    public static function csvMoney(mixed $val): string
    {
        if ($val === null || $val === '') {
            return '0.00';
        }
        return number_format((float) $val, 2, '.', '');
    }

    /**
     * Return the number of calendar days in a date range (inclusive).
     * Mar 1 to Mar 31 = 31 days. Same as D14: (end - start) + 1.
     */
    public static function periodDays(string $from, string $to): int
    {
        $diff = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to));
        return $diff->days + 1;
    }

    /**
     * Safe bcmath division returning '0.00' instead of divide-by-zero.
     * Used when computing rates/percentages from potentially-zero denominators.
     */
    public static function safeDivide(string $numerator, string $denominator, int $scale = 2): string
    {
        if (bccomp($denominator, '0', 6) === 0) {
            return '0.' . str_repeat('0', $scale);
        }
        return bcdiv($numerator, $denominator, $scale);
    }

    /**
     * Compute a percentage: (part / total) * 100, rounded to $scale decimals.
     * Returns '0.0' safely when total is zero.
     */
    public static function pct(string $part, string $total, int $scale = 1): string
    {
        if (bccomp($total, '0', 6) === 0) {
            return '0.' . str_repeat('0', $scale);
        }
        return bcround(bcmul(bcdiv($part, $total, $scale + 4), '100', $scale + 4), $scale);
    }
}
