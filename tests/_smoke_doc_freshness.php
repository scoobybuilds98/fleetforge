<?php
declare(strict_types=1);

/**
 * tests/_smoke_doc_freshness.php
 *
 * S-DOC-FRESHNESS-DISCIPLINE — runtime invariant smoke test for the
 * documentation discipline. Detects three classes of staleness/divergence
 * across the canonical FleetForge doc surface, plus one IN-FLIGHT register
 * discipline rule.
 *
 * Class 1 — Canonical file existence + readability + non-empty.
 *           Catches accidental deletion / truncation / move-without-update.
 *
 * Class 2 — SESSION LOG / CURRENT_SESSIONS cross-consistency.
 *           For every SHIPPED **S-LABEL** entry in CURRENT_SESSIONS.md,
 *           the label must appear somewhere in PROGRESS.md (typically as a
 *           SESSION LOG row, though bundled-session references count).
 *           Catches the "session marked SHIPPED but PROGRESS.md row never
 *           written" gap that S-DOC-STATUS-RECONCILE-CLOSE diagnosed
 *           manually on 2026-05-07.
 *
 * Class 3 — Tool-call markup leak scan. Any line in a canonical .md file
 *           whose TRIMMED content equals a standalone tool-call closing
 *           or opening tag fragment ("</content>", "</invoke>", "<invoke>",
 *           "<content>", "</invoke></content>", "<function_calls>",
 *           "</function_calls>") is flagged. References to the same patterns
 *           inside table rows, code blocks, or prose sentences are not
 *           flagged because they don't appear standalone on their own line.
 *           This is the automated form of the manual fix that
 *           S-TEMPLATE-MILEAGE-DEFAULTS C3 side-fixed at PROGRESS.md:167-170
 *           (and S-MILEAGE-3-SPEC-WRITE surfaced at PROGRESS.md:160-163
 *           originally).
 *
 * Class 4 — CURRENT_SESSIONS IN-FLIGHT discipline (D136 / single write
 *           session). At most one write-mode IN-FLIGHT entry may be
 *           registered at a time (IN-FLIGHT-RO entries may coexist and do
 *           not count toward the limit).
 *
 * Exit 0 on all pass, exit 1 with the failing checks on any fail.
 *
 * D131 gate: every doc-touching session must run this smoke alongside
 * tests/_smoke_master_schema_parity.php + tests/_smoke_billing_invariants.php
 * + bin/migrate.php --verify before the final commit.
 *
 * Decisions: D131 (smoke gate, extended 2026-05-13 via
 *            S-DOC-FRESHNESS-DISCIPLINE to include doc-freshness smoke),
 *            D136 (multi-agent discipline / IN-FLIGHT discipline).
 * Spec ref:  S-DOC-FRESHNESS-DISCIPLINE
 */

define('REPO_ROOT', dirname(__DIR__));
define('DOCS_ROOT', REPO_ROOT . '/docs');

/**
 * Canonical document list. Paths are relative to REPO_ROOT.
 * FLEETFORGE_DATABASE_MASTER.sql lives at repo root (NOT in docs/) per
 * the SEVEN FILES convention in FLEETFORGE_CLAUDE_CODE_REFERENCE.md §1.
 */
const CANONICAL_DOCS = [
    'docs/FLEETFORGE_SPEC_FINAL.md',
    'docs/FLEETFORGE_PROGRESS.md',
    'docs/FLEETFORGE_CURRENT_SESSIONS.md',
    'docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md',
    'docs/FLEETFORGE_ACCOUNTING_SPEC.md',
    'docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md',
    'FLEETFORGE_DATABASE_MASTER.sql',
];

/**
 * Standalone tool-call markup fragments that should NEVER appear as the
 * sole content of a line. Each is compared against the trimmed line.
 */
const LEAK_PATTERNS = [
    '</content>',
    '</invoke>',
    '<invoke>',
    '<content>',
    '</invoke></content>',
    '</content></invoke>',
    '<function_calls>',
    '</function_calls>',
];

$failures = [];
$passed   = 0;

/**
 * Record a check outcome and increment the appropriate counter.
 * On failure, the detail is captured for the post-run report.
 */
function record(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failures[] = ['label' => $label, 'detail' => $detail];
        echo "  ✗ {$label}\n";
        if ($detail !== '') {
            echo "      {$detail}\n";
        }
    }
}

echo "S-DOC-FRESHNESS-DISCIPLINE — doc freshness smoke test\n";
echo str_repeat('═', 78) . "\n\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 1 — Canonical file existence + readability + non-empty
//
// Each canonical file gets one check. Failure: missing OR unreadable OR empty.
// ────────────────────────────────────────────────────────────────────────────

echo "Class 1 — Canonical file existence + readability\n";
echo str_repeat('─', 78) . "\n";

foreach (CANONICAL_DOCS as $rel) {
    $abs = REPO_ROOT . '/' . $rel;
    $label = "C1: {$rel} exists, readable, non-empty";

    if (!file_exists($abs)) {
        record($label, false, "file_exists() returned false for {$abs}");
        continue;
    }
    if (!is_readable($abs)) {
        record($label, false, "is_readable() returned false for {$abs}");
        continue;
    }
    $size = filesize($abs);
    if ($size === false || $size === 0) {
        record($label, false, "filesize() returned " . var_export($size, true) . " for {$abs}");
        continue;
    }

    record($label, true);
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 2 — SESSION LOG / CURRENT_SESSIONS cross-consistency
//
// Parse CURRENT_SESSIONS.md for **S-LABEL** entries with status SHIPPED.
// For each label, search PROGRESS.md for the label string. Substring match
// (not row-strict) accommodates bundled sessions whose row lives under the
// bundle name (e.g. S-D135-REFERENCE-PROMOTE bundled into
// S-DOCS-CLUSTER-2026-05-11 — the bundle's PROGRESS row references
// S-D135-REFERENCE-PROMOTE by name).
// ────────────────────────────────────────────────────────────────────────────

echo "Class 2 — SHIPPED-in-CURRENT_SESSIONS / appears-in-PROGRESS cross-check\n";
echo str_repeat('─', 78) . "\n";

$currentSessionsPath = REPO_ROOT . '/docs/FLEETFORGE_CURRENT_SESSIONS.md';
$progressPath        = REPO_ROOT . '/docs/FLEETFORGE_PROGRESS.md';

$currentSessionsRaw = @file_get_contents($currentSessionsPath);
$progressRaw        = @file_get_contents($progressPath);

if ($currentSessionsRaw === false || $progressRaw === false) {
    record(
        'C2: source files readable',
        false,
        'Cannot read CURRENT_SESSIONS.md or PROGRESS.md — Class 2 cannot run.'
    );
} else {
    // Extract **S-LABEL** — SHIPPED entries.
    // Match pattern: **S-LABEL** — SHIPPED (label includes letters, digits,
    // hyphens; the SHIPPED keyword separates from the rest of the line).
    $shippedLabels = [];
    if (preg_match_all('/^\*\*(S-[A-Z0-9][A-Z0-9\-]*)\*\*\s+—\s+SHIPPED\b/m', $currentSessionsRaw, $matches)) {
        $shippedLabels = array_values(array_unique($matches[1]));
    }

    if (count($shippedLabels) === 0) {
        record(
            'C2: SHIPPED labels extractable from CURRENT_SESSIONS',
            false,
            'Zero **S-LABEL** — SHIPPED entries found — regex may be broken or queue is empty.'
        );
    } else {
        record(
            'C2: SHIPPED labels extractable from CURRENT_SESSIONS',
            true,
            ''
        );

        $missing = [];
        foreach ($shippedLabels as $label) {
            // Look for label as a substring in PROGRESS.md.
            // strpos is sufficient — we just want any reference.
            if (strpos($progressRaw, $label) === false) {
                $missing[] = $label;
            }
        }

        $count = count($shippedLabels);
        if (count($missing) === 0) {
            record(
                "C2: all {$count} SHIPPED labels appear in PROGRESS.md",
                true,
                ''
            );
        } else {
            record(
                "C2: all {$count} SHIPPED labels appear in PROGRESS.md",
                false,
                'Missing from PROGRESS.md: ' . implode(', ', $missing)
            );
        }
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 3 — Tool-call markup leak scan
//
// Scan each canonical .md file line by line, tracking code-fence state to
// exclude legitimate inclusions of these patterns inside ``` … ``` blocks.
// A leak is a line whose TRIMMED content equals (exactly) one of the
// known fragments in LEAK_PATTERNS.
// .sql files are not scanned — SQL has no Markdown code-fence semantics
// and the patterns wouldn't appear there anyway.
// ────────────────────────────────────────────────────────────────────────────

echo "Class 3 — Tool-call markup leak scan (standalone-line, outside code fences)\n";
echo str_repeat('─', 78) . "\n";

foreach (CANONICAL_DOCS as $rel) {
    if (substr($rel, -3) !== '.md') {
        continue;
    }
    $abs = REPO_ROOT . '/' . $rel;
    if (!is_readable($abs)) {
        record("C3: {$rel} scanned for tool-call leaks", false, 'unreadable');
        continue;
    }

    $contents = file_get_contents($abs);
    if ($contents === false) {
        record("C3: {$rel} scanned for tool-call leaks", false, 'file_get_contents returned false');
        continue;
    }

    $lines = explode("\n", $contents);
    $insideFence = false;
    $hits = [];

    foreach ($lines as $i => $rawLine) {
        $trimmed = trim($rawLine);

        // Track code-fence state. A fence opens/closes when the LINE starts
        // with ``` (with optional language). We toggle state on each fence line.
        if (preg_match('/^```/', $trimmed)) {
            $insideFence = !$insideFence;
            continue;
        }
        if ($insideFence) {
            continue;
        }

        // Outside a fence — check the trimmed line against known leaks.
        if (in_array($trimmed, LEAK_PATTERNS, true)) {
            $hits[] = ($i + 1) . ': ' . $trimmed;
        }
    }

    $label = "C3: {$rel} — no standalone-line tool-call leaks";
    if (count($hits) === 0) {
        record($label, true);
    } else {
        record($label, false, 'Leaks found at: ' . implode(' | ', $hits));
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 4 — IN-FLIGHT discipline (D136)
//
// Parse CURRENT_SESSIONS.md ### IN-FLIGHT block. Count **S-LABEL** — IN-FLIGHT
// write-mode entries (IN-FLIGHT-RO does not count toward the limit).
// At most one write-mode IN-FLIGHT may be registered concurrently.
// ────────────────────────────────────────────────────────────────────────────

echo "Class 4 — IN-FLIGHT write-mode discipline (D136: ≤1 IN-FLIGHT)\n";
echo str_repeat('─', 78) . "\n";

if (!is_readable($currentSessionsPath)) {
    record('C4: CURRENT_SESSIONS.md readable for IN-FLIGHT block parse', false, 'unreadable');
} else {
    $raw = file_get_contents($currentSessionsPath);

    // Extract the IN-FLIGHT block: from `### IN-FLIGHT` to the next `###` or
    // `---` separator. This isolates the active-IN-FLIGHT section from any
    // pre-block format-documentation that also uses the word IN-FLIGHT.
    $inFlightBlock = '';
    if (preg_match('/^### IN-FLIGHT\s*\n(.*?)(?=^###\s|^---\s*$)/ms', $raw, $m)) {
        $inFlightBlock = $m[1];
    }

    if ($inFlightBlock === '') {
        record(
            'C4: IN-FLIGHT block locatable in CURRENT_SESSIONS',
            false,
            '### IN-FLIGHT heading not found or block empty.'
        );
    } else {
        record('C4: IN-FLIGHT block locatable in CURRENT_SESSIONS', true);

        // Match **S-LABEL** — IN-FLIGHT entries (NOT IN-FLIGHT-RO).
        // The negative-lookahead (?!-RO) excludes IN-FLIGHT-RO matches.
        $inFlightCount = 0;
        $inFlightLabels = [];
        if (preg_match_all(
            '/^\*\*(S-[A-Z0-9][A-Z0-9\-]*)\*\*\s+—\s+IN-FLIGHT(?!-RO)\b/m',
            $inFlightBlock,
            $matches
        )) {
            $inFlightCount = count($matches[1]);
            $inFlightLabels = $matches[1];
        }

        $label = "C4: ≤1 write-mode IN-FLIGHT session (D136 single-agent serialization)";
        if ($inFlightCount <= 1) {
            $detail = $inFlightCount === 0
                ? '0 IN-FLIGHT entries (queue empty)'
                : "1 IN-FLIGHT entry: {$inFlightLabels[0]}";
            record($label . " [{$detail}]", true);
        } else {
            record(
                $label,
                false,
                "{$inFlightCount} IN-FLIGHT entries found: " . implode(', ', $inFlightLabels)
            );
        }
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// Summary + exit
// ────────────────────────────────────────────────────────────────────────────

$total = $passed + count($failures);
echo str_repeat('═', 78) . "\n";

if (count($failures) === 0) {
    echo "DOC FRESHNESS OK — {$passed}/{$total} checks passed\n";
    exit(0);
}

echo "DOC FRESHNESS FAIL — {$passed}/{$total} checks passed; " . count($failures) . " failure(s):\n";
foreach ($failures as $f) {
    echo "  - {$f['label']}\n";
    if ($f['detail'] !== '') {
        echo "    {$f['detail']}\n";
    }
}
exit(1);
