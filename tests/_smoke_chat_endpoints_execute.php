<?php
declare(strict_types=1);

/**
 * tests/_smoke_chat_endpoints_execute.php
 *
 * WAVE 4 [10] — Dead CHAT-1 endpoints SQL-fatal on dropped columns.
 *
 * Five "CHAT-1" generation endpoints were written against a schema that was
 * later changed and now reference columns that NO LONGER EXIST:
 *   - chat_channels.member_count  (join.php / leave.php / members.php)
 *       → real schema uses a computed (SELECT COUNT(*) …); the denormalized
 *         column was dropped. Any call 1054s.
 *   - chat_messages.is_archived   (search.php / unread/index.php)
 *       → real column is chat_messages.is_deleted. Any call 1054s.
 * All five have ZERO live callers (the UI uses the CHAT-2 siblings:
 * channels/members/{index,add,remove}.php, search/{records,messages}.php,
 * unread/count.php). They are directly-reachable authenticated URLs that 500
 * on every hit — a landmine, not an outage. Remediation: delete all five.
 *
 * This is a schema-real guard (information_schema is the source of truth):
 *   1. SCANNER — every SQL `alias.column` in every api/v1/chat/**.php whose
 *      alias resolves to a chat_* table must be a real column of that table.
 *      (catches cm.is_archived → chat_messages.)
 *   2. SCANNER — no chat endpoint references the dropped `member_count` token
 *      (it is absent from chat_channels and was the denormalized write).
 *   3. The five known-dead files are gone (deletion landed).
 *   4. The CHAT-2 replacements still exist (we deleted dead code, not live).
 *
 * PRE-FIX  : checks 1–3 fail (is_archived in 2 files, member_count in 3, 5 files present).
 * POST-FIX : all pass — the dead files are deleted, nothing references the dropped columns.
 *
 * Run:  php tests/_smoke_chat_endpoints_execute.php   Exit 0/1.
 *
 * @session WAVE-4-CHAT-DEAD-ENDPOINTS
 */

require_once dirname(__DIR__) . '/config/app.php';

$ROOT     = dirname(__DIR__);
$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "WAVE 4 [10] DEAD CHAT-1 ENDPOINTS — schema-real column guard\n";
echo str_repeat('─', 72) . "\n";

$CHAT_DIR = $ROOT . '/api/v1/chat';

// Real columns per chat_* table, straight from the live schema.
$schemaCols = [];
foreach (
    db_select(
        "SELECT TABLE_NAME, COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'chat\\_%'",
        []
    ) as $r
) {
    $schemaCols[$r['TABLE_NAME']][$r['COLUMN_NAME']] = true;
}

// Collect every PHP file under api/v1/chat/ (recursive).
$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($CHAT_DIR, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') $phpFiles[] = $f->getPathname();
}
sort($phpFiles);

/**
 * Pull double-quoted string literals that look like SQL out of a source blob.
 * The codebase writes SQL as multi-line double-quoted strings.
 */
$extractSql = static function (string $src): array {
    $out = [];
    if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $src, $m)) {
        foreach ($m[1] as $lit) {
            if (preg_match('/\b(SELECT|UPDATE|INSERT|DELETE|FROM|JOIN)\b/i', $lit)) $out[] = $lit;
        }
    }
    return $out;
};

/** Build alias → chat_* table map from FROM/JOIN/UPDATE/INTO clauses. */
$aliasMap = static function (string $sql): array {
    $map = [];
    if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO)\s+(chat_[a-z_]+)(?:\s+(?:AS\s+)?([a-z][a-z0-9_]*))?/i', $sql, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $set) {
            $table = strtolower($set[1]);
            $map[$table] = $table;                 // table name is its own alias
            if (!empty($set[2])) {
                $alias = strtolower($set[2]);
                // SQL keywords that can follow a table name without an AS — not aliases.
                if (!in_array($alias, ['on', 'set', 'where', 'using', 'left', 'inner', 'join', 'group', 'order', 'values', 'as'], true)) {
                    $map[$alias] = $table;
                }
            }
        }
    }
    return $map;
};

// ── CHECK 1: every alias.column on a chat_* table is a real column ──────────
$badRefs = [];
foreach ($phpFiles as $file) {
    $rel = str_replace($ROOT . '/', '', $file);
    foreach ($extractSql((string) file_get_contents($file)) as $sql) {
        $map = $aliasMap($sql);
        if (preg_match_all('/\b([a-z][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $sql, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $t) {
                $alias = strtolower($t[1]);
                $col   = strtolower($t[2]);
                if (!isset($map[$alias])) continue;          // alias not a chat_* table
                $table = $map[$alias];
                if (!isset($schemaCols[$table][$col])) {
                    $badRefs[] = "{$rel}: {$alias}.{$col} → {$table} has no such column";
                }
            }
        }
    }
}
if (!$badRefs) {
    $pass("1 column refs — no chat endpoint references a nonexistent chat_* column");
} else {
    $fail("1 column refs — " . count($badRefs) . " dangling reference(s):");
    foreach (array_unique($badRefs) as $b) echo "        • {$b}\n";
}

// ── CHECK 2: the dropped `member_count` column is read/written nowhere ──────
// `… AS member_count` is a legitimate COMPUTED output alias (the CHAT-2 read
// endpoints expose the count that way); only a bare read/write of the column
// (e.g. `SET member_count = member_count + 1`) is the dropped-column landmine.
$memberCountHits = [];
foreach ($phpFiles as $file) {
    $rel = str_replace($ROOT . '/', '', $file);
    $src = (string) file_get_contents($file);
    $stripped = preg_replace('/\bAS\s+member_count\b/i', '', $src);   // drop output aliases
    if (preg_match('/\bmember_count\b/', (string) $stripped)) $memberCountHits[] = $rel;
}
$hasMemberCountCol = isset($schemaCols['chat_channels']['member_count']);   // expected false
if (!$memberCountHits && !$hasMemberCountCol) {
    $pass("2 member_count — dropped from chat_channels AND read/written by no endpoint");
} else {
    $fail("2 member_count — col_present=" . ($hasMemberCountCol ? 'Y' : 'N')
        . " read/written by: " . (implode(', ', $memberCountHits) ?: 'none'));
}

// ── CHECK 3: the five known-dead files are deleted ─────────────────────────
$dead = [
    'api/v1/chat/channels/join.php',
    'api/v1/chat/channels/leave.php',
    'api/v1/chat/channels/members.php',
    'api/v1/chat/search.php',
    'api/v1/chat/unread/index.php',
];
$stillThere = array_values(array_filter($dead, static fn($p) => file_exists($ROOT . '/' . $p)));
if (!$stillThere) {
    $pass("3 deletion — all 5 dead CHAT-1 endpoints removed");
} else {
    $fail("3 deletion — still present: " . implode(', ', $stillThere));
}

// ── CHECK 4: the live CHAT-2 replacements survive ──────────────────────────
$live = [
    'api/v1/chat/channels/members/index.php',
    'api/v1/chat/channels/members/add.php',
    'api/v1/chat/channels/members/remove.php',
    'api/v1/chat/search/records.php',
    'api/v1/chat/search/messages.php',
    'api/v1/chat/unread/count.php',
];
$missing = array_values(array_filter($live, static fn($p) => !file_exists($ROOT . '/' . $p)));
if (!$missing) {
    $pass("4 replacements — all CHAT-2 sibling endpoints intact");
} else {
    $fail("4 replacements — MISSING (over-deleted!): " . implode(', ', $missing));
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("DEAD CHAT-1 ENDPOINTS — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
