<?php
declare(strict_types=1);

/**
 * tests/helpers/Assert.php
 *
 * Minimal assertion library for the S-BILLING-TESTS-ADVERSARIAL suite.
 *
 * Each assert returns void on pass and throws AssertionFailed on fail.
 * The runner catches AssertionFailed and records the test as failed.
 *
 * Signatures match spec Appendix D exactly.
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 * @spec    FleetForge_Holistic_Billing_Engine_Test_Spec.docx Appendix D
 */

namespace FleetForge\Tests;

class AssertionFailed extends \RuntimeException {}

class Assert
{
    /** Asserts $a === $b. String/int/array. */
    public static function equal($expected, $actual, string $msg = ''): void
    {
        if ($expected === $actual) return;
        $e = self::dump($expected);
        $a = self::dump($actual);
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "expected $e, got $a");
    }

    /** Asserts bcmath equality at 2 decimals. Convention: (expected, actual). */
    public static function bcequal(string $expected, string $actual, string $msg = ''): void
    {
        if (bccomp($expected, $actual, 2) === 0) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "bcmath expected $expected, got $actual");
    }

    /** Asserts abs(a - b) <= tolerance. For precision tests where $0.02 slop is OK. */
    public static function near(string $a, string $b, string $tolerance = '0.05', string $msg = ''): void
    {
        $diff = bcsub($a, $b, 6);
        if (bccomp($diff, '0', 6) < 0) $diff = bcmul($diff, '-1', 6);
        if (bccomp($diff, $tolerance, 6) <= 0) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "expected near $b (±$tolerance), got $a (diff=$diff)");
    }

    /** Asserts $callable() throws the expected exception class. */
    public static function throws(string $exceptionClass, callable $callable, string $msg = ''): void
    {
        try {
            $callable();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) return;
            throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "expected $exceptionClass, got " . get_class($e) . ': ' . $e->getMessage());
        }
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "expected $exceptionClass, no exception thrown");
    }

    /** Asserts $callable() does NOT throw — useful for sanity checks. */
    public static function noThrow(callable $callable, string $msg = ''): void
    {
        try {
            $callable();
        } catch (\Throwable $e) {
            throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "unexpected " . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** Asserts value is true (boolean or truthy). */
    public static function true($actual, string $msg = ''): void
    {
        if ($actual) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "expected true, got " . self::dump($actual));
    }

    /** Asserts value is false (or falsy). */
    public static function false($actual, string $msg = ''): void
    {
        if (!$actual) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "expected false, got " . self::dump($actual));
    }

    /** Asserts numeric value is in [min, max] (inclusive). */
    public static function between(string $val, string $min, string $max, string $msg = ''): void
    {
        if (bccomp($val, $min, 6) >= 0 && bccomp($val, $max, 6) <= 0) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "expected $min..$max, got $val");
    }

    /** Asserts invoice has at least one line of given item_type. */
    public static function lineExists(int $invoiceId, string $itemType, string $msg = ''): void
    {
        $row = db_row(
            "SELECT COUNT(*) AS n FROM invoice_line_items WHERE invoice_id = ? AND item_type = ?",
            [$invoiceId, $itemType]
        );
        if ($row && (int)$row['n'] > 0) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "invoice #$invoiceId missing line type '$itemType'");
    }

    /** Asserts invoice has EXACTLY $count lines of given item_type. */
    public static function lineCount(int $invoiceId, string $itemType, int $count, string $msg = ''): void
    {
        $row = db_row(
            "SELECT COUNT(*) AS n FROM invoice_line_items WHERE invoice_id = ? AND item_type = ?",
            [$invoiceId, $itemType]
        );
        $got = $row ? (int)$row['n'] : 0;
        if ($got === $count) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "invoice #$invoiceId expected $count '$itemType' lines, got $got");
    }

    /** Asserts audit_log has at least one row for the entity. */
    public static function auditLogged(string $entityType, int $entityId, string $msg = ''): void
    {
        $row = db_row(
            "SELECT COUNT(*) AS n FROM audit_log WHERE entity_type = ? AND entity_id = ?",
            [$entityType, $entityId]
        );
        if ($row && (int)$row['n'] > 0) return;
        throw new AssertionFailed(($msg !== '' ? "$msg — " : '') . "no audit_log entry for $entityType #$entityId");
    }

    /** Compact value dump for assertion messages. */
    private static function dump($v): string
    {
        if (is_string($v)) return "'$v'";
        if (is_null($v))   return 'null';
        if (is_bool($v))   return $v ? 'true' : 'false';
        if (is_array($v))  return 'array(' . count($v) . ')';
        return (string)$v;
    }
}
