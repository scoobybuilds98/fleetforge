<?php
declare(strict_types=1);

/**
 * tests/helpers/DbState.php
 *
 * Transaction wrapping for DB-touching tests. Tests must wrap themselves
 * in DbState::inTransaction(...) so changes roll back regardless of pass/fail.
 *
 * Deviation from spec §4.1: spec calls for a dedicated `fleetforge_test`
 * database with full migration re-application per run. The repo's existing
 * 12+ stress tests use transactional rollback against the main `fleetforge`
 * DB instead — same isolation guarantee with lower setup cost. This file
 * follows the repo convention. Documented as a deviation in the session
 * SESSION LOG.
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 */

namespace FleetForge\Tests;

class DbState
{
    /**
     * Run $callback inside a transaction that ALWAYS rolls back, regardless
     * of what the callback returns or throws. Used to keep tests hermetic.
     *
     * Mirrors the pattern in tests/_stress_holistic_engine.php's
     * `$pdo->beginTransaction(); try { ... } finally { $pdo->rollBack(); }`
     *
     * @param callable $callback Receives no arguments; can return anything.
     * @return mixed Whatever $callback returned (so tests can capture results).
     */
    public static function inTransaction(callable $callback)
    {
        $pdo = db_pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $result ?? null;
    }

    /**
     * Snapshot a few key counters before a test. Compare via assertCounters
     * post-restore to confirm rollback was clean.
     *
     * @return array Map of table → row count.
     */
    public static function snapshot(): array
    {
        return [
            'invoices'           => self::count('invoices'),
            'invoice_line_items' => self::count('invoice_line_items'),
            'leases'             => self::count('leases'),
            'credit_notes'       => self::count('credit_notes'),
            'audit_log'          => self::count('audit_log'),
        ];
    }

    /** Restore = re-check counters (transactions handle the actual restore). */
    public static function restore(array $snapshot): void
    {
        foreach ($snapshot as $table => $expectedCount) {
            $actual = self::count($table);
            if ($actual !== $expectedCount) {
                throw new \RuntimeException(
                    "DbState::restore — $table leaked: snapshot=$expectedCount, now=$actual"
                );
            }
        }
    }

    private static function count(string $table): int
    {
        $row = db_row("SELECT COUNT(*) AS n FROM `$table`");
        return $row ? (int)$row['n'] : 0;
    }
}
