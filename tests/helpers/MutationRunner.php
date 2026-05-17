<?php
declare(strict_types=1);

/**
 * tests/helpers/MutationRunner.php
 *
 * Minimal mutation testing tool per spec §4.4 + §30.
 *
 * Each mutation:
 *   1. Reads the target file
 *   2. Applies a string replacement (the "mutation")
 *   3. Writes the mutated file
 *   4. Runs a chosen kill-test set (Tier 1 subset by default)
 *   5. Restores the original
 *   6. Returns true if the test set FAILED on the mutated code
 *      ("killed" the mutant) — that's the desired outcome.
 *
 * The kill-test set is intentionally small (subset of Tier 1) so that
 * 25 mutants finish in under a minute.
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 * @spec    FleetForge_Holistic_Billing_Engine_Test_Spec.docx §4.4, §30
 */

namespace FleetForge\Tests;

class MutationRunner
{
    private string $rootDir;

    public function __construct(?string $rootDir = null)
    {
        $this->rootDir = $rootDir ?? dirname(__DIR__, 2);
    }

    /**
     * Apply a string replacement to a file and run the kill-test set.
     *
     * @param string $file     Absolute path
     * @param string $search   Substring to replace (must be unique in file)
     * @param string $replace  Replacement substring
     * @param array $killTests Optional array of test IDs (e.g. ['TF-001','TF-004']);
     *                         if empty, uses a default Tier 1 sampler.
     * @return array{killed: bool, output: string, file: string}
     */
    public function applyMutation(string $file, string $search, string $replace, array $killTests = []): array
    {
        if (!is_readable($file)) {
            return ['killed' => false, 'output' => "FILE NOT FOUND: $file", 'file' => $file];
        }
        $original = file_get_contents($file);
        if ($original === false) {
            return ['killed' => false, 'output' => "READ FAIL: $file", 'file' => $file];
        }
        // Count occurrences — refuse if not exactly one (to avoid silent wrong mutations).
        if (substr_count($original, $search) !== 1) {
            return [
                'killed' => false,
                'output' => "SEARCH NOT UNIQUE (count=" . substr_count($original, $search) . "): " . substr($search, 0, 60) . '...',
                'file'   => $file,
            ];
        }
        $mutated = str_replace($search, $replace, $original);
        file_put_contents($file, $mutated);

        try {
            $output = $this->runKillTests($killTests);
            $killed = $this->detectFailure($output);
        } finally {
            // ALWAYS restore the original — never leave engine code mutated.
            file_put_contents($file, $original);
        }

        return ['killed' => $killed, 'output' => $output, 'file' => $file];
    }

    /**
     * Run the chosen kill test set. Returns the captured stdout.
     *
     * Implementation: invoke a subprocess `php tests/runner.php --tier=1`
     * (or filtered by test IDs) and capture output. We don't use Symfony
     * Process; raw shell_exec is sufficient.
     */
    private function runKillTests(array $killTests = []): string
    {
        $runner = $this->rootDir . '/tests/runner.php';
        if (empty($killTests)) {
            // Default: run Tier 1 subset (TF + RR are most sensitive to engine mutations).
            $cmd = "php " . escapeshellarg($runner) . " --tier=1 2>&1";
        } else {
            // Run specific tests. The runner supports --test=ID for one test;
            // for multiple, run tier 1 and grep the output. Cleaner: run all of
            // tier 1 and detect any FAIL line.
            $cmd = "php " . escapeshellarg($runner) . " --tier=1 2>&1";
        }
        $out = shell_exec($cmd);
        return (string)$out;
    }

    /**
     * "Killed" = the test suite REPORTED at least one FAIL on the mutated code.
     * If the output contains "FAIL" lines, the mutation was detected.
     */
    private function detectFailure(string $output): bool
    {
        // The runner emits "Failed:         N" — if N > 0, the mutation was caught.
        if (preg_match('/Failed:\s+(\d+)/', $output, $m)) {
            return (int)$m[1] > 0;
        }
        // Fallback: presence of any FAIL line.
        return strpos($output, 'FAIL ') !== false || strpos($output, 'FAIL  ') !== false;
    }
}
