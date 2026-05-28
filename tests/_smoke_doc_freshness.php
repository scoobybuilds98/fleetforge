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
// CLASS 5 — Recent SESSION LOG → CURRENT_SESSIONS.md reverse cross-check
//
// CLASS 2 checks: SHIPPED in CURRENT_SESSIONS appears in PROGRESS.
// CLASS 5 checks: every row in PROGRESS.md SESSION LOG appears in
// CURRENT_SESSIONS.md (any section — SHIPPED, IN-FLIGHT, QUEUED).
//
// Catches the failure mode where a session row gets added to PROGRESS.md
// SESSION LOG but the CURRENT_SESSIONS.md entry is forgotten — the operator
// then loses the high-level "what shipped recently" trail.
//
// SCOPE: last DOC_FRESHNESS_SESSION_TAIL session-log rows (deliberately
// bounded — older rows correctly archive out of CURRENT_SESSIONS per its
// rolling-archive convention).
// ────────────────────────────────────────────────────────────────────────────

const DOC_FRESHNESS_SESSION_TAIL = 10;

echo "Class 5 — recent SESSION LOG rows → CURRENT_SESSIONS.md presence\n";
echo str_repeat('─', 78) . "\n";

if ($currentSessionsRaw === false || $progressRaw === false) {
    record('C5: source files readable', false, 'reused state from CLASS 2 — unreadable');
} else {
    // Match SESSION LOG rows: | S-LABEL | YYYY-MM-DD | ...
    // Capture label and date in parallel arrays.
    $labels = [];
    $dates  = [];
    if (preg_match_all(
        '/^\|\s+(S-[A-Z0-9][A-Z0-9\-]*)\s+\|\s+(\d{4}-\d{2}-\d{2})\s+\|/m',
        $progressRaw,
        $m
    )) {
        $labels = $m[1];
        $dates  = $m[2];
    }

    if (count($labels) === 0) {
        record(
            'C5: SESSION LOG rows extractable from PROGRESS',
            false,
            'Zero | S-LABEL | YYYY-MM-DD | rows matched — regex may be broken or log empty.'
        );
    } else {
        record('C5: SESSION LOG rows extractable from PROGRESS', true);

        $tail = array_slice($labels, -DOC_FRESHNESS_SESSION_TAIL);
        $tailDates = array_slice($dates, -DOC_FRESHNESS_SESSION_TAIL);

        $missing = [];
        foreach ($tail as $i => $label) {
            if (strpos($currentSessionsRaw, $label) === false) {
                $missing[] = "{$label} (" . $tailDates[$i] . ')';
            }
        }

        $n = count($tail);
        if (count($missing) === 0) {
            record(
                "C5: last {$n} SESSION LOG entries present in CURRENT_SESSIONS.md",
                true
            );
        } else {
            record(
                "C5: last {$n} SESSION LOG entries present in CURRENT_SESSIONS.md",
                false,
                'Missing from CURRENT_SESSIONS.md: ' . implode(', ', $missing)
            );
        }
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 6 — Recent QBO sessions → QUICKBOOKS_PROGRESS.md SESSION LOG presence
//
// For every session in PROGRESS.md SESSION LOG (last N) whose ROW TEXT
// references QBO (S-QBO-*, quickbooks/, D-QBO-*, acc_qbo_*), verify the
// session label also appears in QUICKBOOKS_PROGRESS.md (any section — most
// importantly §2 SESSION LOG, but presence anywhere counts).
//
// Catches the failure mode where a QBO-relevant session ships its PROGRESS.md
// row + CURRENT_SESSIONS.md entry but forgets to update QUICKBOOKS_PROGRESS.md
// (which lost track of S-VENDOR-CURRENCY-COLUMN initially on 2026-05-27).
//
// HEURISTIC: session is QBO-relevant if its full row text in PROGRESS contains
// "S-QBO-", "quickbooks/", "D-QBO-", "acc_qbo_", or starts with "S-VENDOR-"
// (the FIXPACK-8 backlog item naming convention).
// ────────────────────────────────────────────────────────────────────────────

$qboProgressPath = REPO_ROOT . '/docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md';
$qboProgressRaw  = @file_get_contents($qboProgressPath);

echo "Class 6 — recent QBO-relevant sessions → QUICKBOOKS_PROGRESS.md presence\n";
echo str_repeat('─', 78) . "\n";

if ($qboProgressRaw === false) {
    record('C6: QUICKBOOKS_PROGRESS.md readable', false, "missing or unreadable: {$qboProgressPath}");
} elseif (count($labels) === 0) {
    record('C6: SESSION LOG rows available', false, 'CLASS 5 found no rows; skipping cross-check.');
} else {
    record('C6: QUICKBOOKS_PROGRESS.md readable', true);

    // Re-parse PROGRESS to capture full row text per label (need the whole row
    // body to apply the QBO-relevance heuristic). Each row spans from the
    // | S-LABEL | YYYY-MM-DD | start to the next row start or end of table.
    $rowPattern = '/^\|\s+(S-[A-Z0-9][A-Z0-9\-]*)\s+\|\s+\d{4}-\d{2}-\d{2}\s+\|.*$/m';
    if (preg_match_all($rowPattern, $progressRaw, $rowMatches)) {
        $rows = array_combine(array_slice($rowMatches[1], -DOC_FRESHNESS_SESSION_TAIL), array_slice($rowMatches[0], -DOC_FRESHNESS_SESSION_TAIL));

        // QBO-relevance heuristic: LABEL prefix only (not body text). Body-text
        // matching over-fires for meta-sessions that discuss QBO without being
        // QBO sessions themselves (e.g. S-DOC-FRESHNESS-EXPAND, S-D131-BASELINE-
        // RESTORE — both mention QBO sessions in their narrative but aren't
        // themselves QBO build/paydown sessions and don't belong in the QBO
        // log). False-negative possible for vendor-side work not prefixed
        // S-VENDOR-* but those would surface in operator review.
        $qboRelevant = [];
        foreach ($rows as $label => $rowText) {
            $isQbo = strpos($label, 'S-QBO-') === 0
                  || strpos($label, 'S-VENDOR-') === 0;
            if ($isQbo) {
                $qboRelevant[$label] = $rowText;
            }
        }

        if (count($qboRelevant) === 0) {
            record('C6: recent SESSION LOG has no QBO-relevant rows (skipped)', true);
        } else {
            $missing = [];
            foreach ($qboRelevant as $label => $_) {
                if (strpos($qboProgressRaw, $label) === false) {
                    $missing[] = $label;
                }
            }

            $n = count($qboRelevant);
            if (count($missing) === 0) {
                record("C6: all {$n} recent QBO-relevant labels appear in QUICKBOOKS_PROGRESS.md", true);
            } else {
                record(
                    "C6: all {$n} recent QBO-relevant labels appear in QUICKBOOKS_PROGRESS.md",
                    false,
                    'Missing from QUICKBOOKS_PROGRESS.md: ' . implode(', ', $missing)
                );
            }
        }
    } else {
        record('C6: row extraction', false, 'regex match failed');
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 7 — D-* DECISIONS with "via S-LABEL" attribution (ADVISORY)
//
// For every row in PROGRESS.md DECISIONS table containing "via S-LABEL"
// attribution, verify the referenced session label appears somewhere in
// PROGRESS.md SESSION LOG.
//
// ADVISORY: this check always PASSES — it surfaces orphan attributions for
// operator awareness but does not hard-fail because the SESSION LOG has a
// rolling-archive convention and older attributed sessions legitimately age
// out of the in-table log (without losing their docs trail elsewhere).
//
// Catches: a D-* decision documented as locked via a session whose SESSION
// LOG row was forgotten in the same commit. Orphans flagged but smoke passes.
// ────────────────────────────────────────────────────────────────────────────

echo "Class 7 — D-* attribution links → SESSION LOG presence (advisory)\n";
echo str_repeat('─', 78) . "\n";

if ($progressRaw === false) {
    record('C7: PROGRESS.md readable', false, 'reused state — unreadable');
} else {
    if (preg_match_all(
        '/\bvia\s+(S-[A-Z0-9][A-Z0-9\-]*)\b/',
        $progressRaw,
        $vm
    )) {
        $attributedSessions = array_values(array_unique($vm[1]));
        $sessionLogLabels   = array_values(array_unique($labels ?? []));

        $orphans = [];
        foreach ($attributedSessions as $label) {
            if (!in_array($label, $sessionLogLabels, true)) {
                $orphans[] = $label;
            }
        }

        $n = count($attributedSessions);
        $resolved = $n - count($orphans);
        // ALWAYS PASS — advisory only. Detail line shows orphan count for
        // operator awareness. Operator can investigate when convenient.
        $detail = count($orphans) === 0
            ? "all {$n} attribution links resolved"
            : "{$resolved}/{$n} resolved; " . count($orphans) . " orphans (likely archived): " . implode(', ', $orphans);
        record("C7: D-* attribution advisory ({$detail})", true);
    } else {
        record('C7: D-* attribution advisory (0 links found)', true);
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 8 — QUICKBOOKS_PROGRESS.md "Next session up" sanity check
//
// The §1 header has a "**Next session up:**" line that names the next session
// to fire. After that session ships, the §1 header must be flipped to name a
// new "next up" — otherwise readers tomorrow see a stale claim that the
// already-shipped session is "next."
//
// CHECK: parse the "**Next session up:** **S-LABEL**" pattern from
// QUICKBOOKS_PROGRESS.md §1. Verify S-LABEL does NOT appear as a shipped
// row in PROGRESS.md SESSION LOG. (If it does, the §1 header is stale.)
// ────────────────────────────────────────────────────────────────────────────

echo "Class 8 — QUICKBOOKS_PROGRESS.md §1 'Next session up' freshness\n";
echo str_repeat('─', 78) . "\n";

if ($qboProgressRaw === false) {
    record('C8: QUICKBOOKS_PROGRESS.md readable', false, 'reused state — unreadable');
} else {
    // Match: "**Next session up:** **S-LABEL**" or "**Next session up:**\n\n**S-LABEL**"
    // The label is bold-wrapped; allow optional surrounding markdown.
    if (preg_match(
        '/\*\*Next session up:\*\*[^*]*\*\*(S-[A-Z0-9][A-Z0-9\-]*)\*\*/',
        $qboProgressRaw,
        $nm
    )) {
        $nextUp = $nm[1];

        // Stale if this label appears as a SESSION LOG row in PROGRESS.
        $sessionLogLabels = array_values(array_unique($labels ?? []));
        $isShipped = in_array($nextUp, $sessionLogLabels, true);

        if (!$isShipped) {
            record("C8: 'Next session up' ({$nextUp}) is NOT already shipped", true);
        } else {
            record(
                "C8: 'Next session up' ({$nextUp}) is NOT already shipped",
                false,
                "QUICKBOOKS_PROGRESS.md §1 names {$nextUp} as next-up, but it appears in PROGRESS.md SESSION LOG as DONE. Flip §1 to a new next-up."
            );
        }
    } else {
        record('C8: Next session up pattern locatable in QUICKBOOKS_PROGRESS', false, "regex didn't match — §1 header may have changed format");
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 9 — K-22 Trap catalog entries (Trap #50+) have session attribution
//
// Every "### Trap #N: <title>" block in REFERENCE.md §11 with N ≥ 50 should
// have "Detected:" / "via S-" / similar session attribution somewhere in the
// block body, so future readers can find the originating session.
//
// CUTOFF: Trap #1-49 predate the attribution discipline (D-K22-CATALOG-2
// formalized the catalog at Trap #52 in S-QBO-1 docs-lock 2026-05-20).
// Older traps lack attribution by design — checking them produces noise.
//
// Catches: a new Trap added in REFERENCE.md without naming the session that
// surfaced it, breaking the docs-trail for future planning chats.
// ────────────────────────────────────────────────────────────────────────────

const DOC_FRESHNESS_TRAP_ATTRIBUTION_CUTOFF = 50;

$referencePath = REPO_ROOT . '/docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md';
$referenceRaw  = @file_get_contents($referencePath);

echo "Class 9 — K-22 Trap catalog (#" . DOC_FRESHNESS_TRAP_ATTRIBUTION_CUTOFF . "+) → session attribution\n";
echo str_repeat('─', 78) . "\n";

if ($referenceRaw === false) {
    record('C9: REFERENCE.md readable', false, "missing or unreadable: {$referencePath}");
} else {
    if (preg_match_all(
        '/^### Trap #?(\d+)(?::[^\n]*)?\n(.*?)(?=^###\s|^##\s|\z)/ms',
        $referenceRaw,
        $tm
    )) {
        $orphans = [];
        $checked = 0;
        $skipped = 0;
        foreach ($tm[1] as $i => $trapNum) {
            $num = (int) $trapNum;
            if ($num < DOC_FRESHNESS_TRAP_ATTRIBUTION_CUTOFF) {
                $skipped++;
                continue;
            }
            $body = $tm[2][$i];
            $checked++;

            $hasAttribution =
                preg_match('/\bDetected[:\s]/i', $body) === 1
                || preg_match('/\bSurfaced\b/i', $body) === 1
                || preg_match('/\bvia\s+S-[A-Z0-9]/', $body) === 1
                || preg_match('/\bS-[A-Z0-9][A-Z0-9\-]*\b/', $body) === 1;

            if (!$hasAttribution) {
                $orphans[] = "#{$trapNum}";
            }
        }

        if ($checked === 0 && $skipped > 0) {
            record("C9: 0 Trap entries ≥#" . DOC_FRESHNESS_TRAP_ATTRIBUTION_CUTOFF . " ({$skipped} pre-cutoff legacy traps skipped)", true);
        } elseif (count($orphans) === 0) {
            record("C9: all {$checked} recent Traps (#" . DOC_FRESHNESS_TRAP_ATTRIBUTION_CUTOFF . "+) have session attribution ({$skipped} pre-cutoff legacy traps skipped)", true);
        } else {
            record(
                "C9: all {$checked} recent Traps (#" . DOC_FRESHNESS_TRAP_ATTRIBUTION_CUTOFF . "+) have session attribution",
                false,
                'Traps without session attribution: ' . implode(', ', $orphans) . " ({$skipped} pre-cutoff legacy traps skipped)"
            );
        }
    } else {
        record('C9: Trap catalog regex matched', false, "no 'Trap #N' blocks found");
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 10 — Recent git commits → PROGRESS.md SESSION LOG anchor check
//
// CLASSES 5/6 check from SESSION LOG outward. They have an anchor blind
// spot: if a session ships with NO SESSION LOG row, there's nothing to
// anchor on — those classes silently pass.
//
// CLASS 10 closes the blind spot by reading recent git commit subjects,
// extracting S-XXX session labels, and verifying each appears in
// PROGRESS.md SESSION LOG. Catches S-QBO-BILL-SYNC-UI class (committed
// but no SESSION LOG row was added in the original commit; operator
// caught it manually in audit).
//
// SCOPE: last DOC_FRESHNESS_GIT_COMMIT_TAIL commits. Tuned to roughly
// match the SESSION_TAIL window for CLASSES 5/6 — a session that's
// older than this window legitimately may have moved out of in-table
// SESSION LOG retention.
// ────────────────────────────────────────────────────────────────────────────

const DOC_FRESHNESS_GIT_COMMIT_TAIL = 30;

echo "Class 10 — recent git commits → SESSION LOG anchor check\n";
echo str_repeat('─', 78) . "\n";

if ($progressRaw === false) {
    record('C10: PROGRESS.md readable', false, 'reused state — unreadable');
} else {
    $commitsOutput = @shell_exec(
        sprintf('cd %s && git log --pretty=format:%%s -%d 2>/dev/null',
            escapeshellarg(REPO_ROOT),
            DOC_FRESHNESS_GIT_COMMIT_TAIL
        )
    );

    if ($commitsOutput === null || $commitsOutput === '') {
        record('C10: git log readable (skipped if no git)', true, 'git log returned empty — non-repo environment');
    } else {
        // Extract S-XXX tokens. Session names follow S-[A-Z0-9][A-Z0-9-]+
        // pattern. The K-22 pattern (Kxx) and decision (D-XXX) are NOT
        // session names — only S- prefix counts.
        preg_match_all('/\bS-[A-Z][A-Z0-9][A-Z0-9-]*\b/', $commitsOutput, $cm);
        $commitSessions = array_values(array_unique($cm[0] ?? []));

        // Filter: drop tokens that are obviously NOT session names (e.g.
        // S-3 alone, S-X just generic placeholders). Real session names
        // have ≥6 chars (e.g. S-QBO-7 = 7 chars; shortest real is roughly
        // S-FIX-2 = 7 chars).
        $commitSessions = array_filter($commitSessions, static fn($s) => strlen($s) >= 6);
        $commitSessions = array_values($commitSessions);

        // Anchor check: each session in commit log should appear in
        // PROGRESS.md (either as a SESSION LOG row OR referenced in
        // another row's body — strpos is sufficient because cross-
        // references ALSO satisfy the "this session is documented"
        // requirement, just from the other direction).
        $orphans = [];
        foreach ($commitSessions as $label) {
            if (strpos($progressRaw, $label) === false) {
                $orphans[] = $label;
            }
        }

        $n = count($commitSessions);
        if (count($orphans) === 0) {
            record(
                "C10: all {$n} sessions from last " . DOC_FRESHNESS_GIT_COMMIT_TAIL . " commits appear in PROGRESS.md",
                true
            );
        } else {
            record(
                "C10: all {$n} sessions from last " . DOC_FRESHNESS_GIT_COMMIT_TAIL . " commits appear in PROGRESS.md",
                false,
                'Missing from PROGRESS.md (committed but no SESSION LOG row): ' . implode(', ', $orphans)
            );
        }
    }
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 11 — Recent SESSION LOG rows → REFERENCE.md + INVENTORY presence
//
// Extends the cross-presence check to two more canonical docs:
//   - CLAUDE_CODE_REFERENCE.md — D131 extension notes for sessions that
//     bump smoke count or add sub-checks
//   - QBO_REMAINING_WORK_2026-05-26.md — progress markers for any
//     QBO-relevant session shipped after the inventory snapshot
//
// ADVISORY: lists orphans but always passes. False-positive risk is
// real because not every session NEEDS to be in REFERENCE.md (only
// D131-affecting + K-22-trap-adding sessions). Operator awareness is
// the value here, not hard enforcement.
// ────────────────────────────────────────────────────────────────────────────

echo "Class 11 — recent SESSION LOG rows → REFERENCE.md + INVENTORY presence (advisory)\n";
echo str_repeat('─', 78) . "\n";

$inventoryPath = REPO_ROOT . '/docs/FLEETFORGE_QBO_REMAINING_WORK_2026-05-26.md';
$inventoryRaw  = @file_get_contents($inventoryPath);

if ($referenceRaw === false || $inventoryRaw === false) {
    record('C11: REFERENCE.md + INVENTORY doc readable', false, 'one or both unreadable');
} elseif (count($labels ?? []) === 0) {
    record('C11: SESSION LOG rows available', false, 'CLASS 5 found no rows; skipping cross-check.');
} else {
    $tail = array_slice($labels, -DOC_FRESHNESS_SESSION_TAIL);

    // Heuristic from CLASS 6: label-prefix-only for QBO-relevance.
    $qboLabels = array_values(array_filter($tail, static function ($l) {
        return strpos($l, 'S-QBO-') === 0 || strpos($l, 'S-VENDOR-') === 0;
    }));

    $refOrphans = [];
    foreach ($tail as $label) {
        if (strpos($referenceRaw, $label) === false) {
            $refOrphans[] = $label;
        }
    }

    $invOrphans = [];
    foreach ($qboLabels as $label) {
        if (strpos($inventoryRaw, $label) === false) {
            $invOrphans[] = $label;
        }
    }

    $refResolved = count($tail) - count($refOrphans);
    $invResolved = count($qboLabels) - count($invOrphans);

    $refDetail = count($refOrphans) === 0
        ? "all {$refResolved}/{$refResolved} in REFERENCE"
        : "{$refResolved}/" . count($tail) . " in REFERENCE; missing: " . implode(', ', $refOrphans);
    $invDetail = count($invOrphans) === 0
        ? "all {$invResolved}/{$invResolved} QBO labels in inventory"
        : "{$invResolved}/" . count($qboLabels) . " QBO labels in inventory; missing: " . implode(', ', $invOrphans);

    // ALWAYS PASS — advisory only. Not every session needs to be in
    // REFERENCE.md (only D131-affecting/trap-adding); but operator
    // awareness of the gap is the value.
    record("C11a: REFERENCE.md cross-presence advisory ({$refDetail})", true);
    record("C11b: INVENTORY doc cross-presence advisory ({$invDetail})", true);
}

echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// CLASS 12 — Pusher ↔ admin UI presence check
//
// Every QBO Pusher class (lib/QboPushers/{Entity}Pusher.php) MUST have a
// matching admin UI surface (app/admin/quickbooks/{entity}.php) shipped
// in the same commit. Backend-only ships create operator-babysitting
// cleanup arcs (S-QBO-18 → S-QBO-BILL-SYNC-UI, S-QBO-14 →
// S-QBO-PAYMENT-SYNC-UI — both operator-caught).
//
// Locked 2026-05-29 via S-QBO-PAYMENT-SYNC-UI / D-UI-COMPLETENESS-1
// after operator escalation: "why are you forgetting about stuff?"
//
// Pattern: Pusher class basename → admin page slug (plural form). Hard-
// coded mapping table since plural rules are irregular (Bill→bills,
// Invoice→invoices, Payment→payments — all naive +s, but future
// CreditMemo→credit_memos and RefundReceipt→refund_receipts will need
// the snake_case conversion + pluralization explicitly).
//
// Allowlist: some Pushers share an admin UI surface with their
// counterpart Puller/Handler (e.g. PaymentWebhookHandler shares
// payments.php with PaymentPusher — the surface is bidirectional by
// design). Document each allowlist entry inline.
//
// Companion: memory/feedback_ui_completeness_with_backend.md (the
// discipline narrative; survives context wipes).
// ────────────────────────────────────────────────────────────────────────────

echo "Class 12 — Pusher ↔ admin UI presence check\n";
echo str_repeat('─', 78) . "\n";

// Hardcoded mapping: Pusher class basename → admin page filename.
// Update when a new Pusher ships. NULL = exempted (allowlist with reason).
$pusherToAdminPage = [
    'CustomerPusher'    => 'customers.php',
    'VendorPusher'      => 'vendors.php',
    'InvoicePusher'     => 'invoices.php',
    'BillPusher'        => 'bills.php',
    'PaymentPusher'     => 'payments.php',
    'BillPaymentPusher' => 'bill_payments.php',
    // Future: CreditMemoPusher → credit_memos.php (S-QBO-16)
    //         RefundReceiptPusher → refund_receipts.php (S-QBO-17)
    //         JournalEntryPusher → journal_entries.php (S-QBO-21)
];

$pushersDir = REPO_ROOT . '/lib/QboPushers';
if (!is_dir($pushersDir)) {
    record('C12: Pushers dir readable', false, "lib/QboPushers missing");
} else {
    $pusherFiles = glob($pushersDir . '/*Pusher.php');
    $missingUi   = [];
    $unmapped    = [];

    foreach ($pusherFiles as $file) {
        $basename = basename($file, '.php');  // e.g. "PaymentPusher"

        if (!isset($pusherToAdminPage[$basename])) {
            // New Pusher shipped without updating the mapping table.
            // Strict-fail — operator needs to choose: add to mapping OR
            // explicitly allowlist with reason.
            $unmapped[] = $basename;
            continue;
        }

        $adminPageFile = $pusherToAdminPage[$basename];
        if ($adminPageFile === null) {
            // Allowlisted (shared admin surface) — pass.
            continue;
        }

        $adminPagePath = REPO_ROOT . '/app/admin/quickbooks/' . $adminPageFile;
        if (!is_file($adminPagePath)) {
            $missingUi[] = "{$basename} → app/admin/quickbooks/{$adminPageFile}";
        }
    }

    $errors = [];
    if (!empty($missingUi)) {
        $errors[] = 'Backend-only Pusher(s) — admin UI missing: ' . implode('; ', $missingUi);
    }
    if (!empty($unmapped)) {
        $errors[] = 'Pusher(s) not in mapping table (update CLASS 12 or add allowlist entry): ' . implode(', ', $unmapped);
    }

    if (empty($errors)) {
        $n = count($pusherFiles);
        record("C12: all {$n} QBO Pushers have matching admin UI surfaces", true);
    } else {
        record('C12: all QBO Pushers have matching admin UI surfaces', false, implode(' | ', $errors));
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
