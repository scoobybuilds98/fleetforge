<?php
declare(strict_types=1);

/**
 * tests/_smoke_schema_lib.php
 *
 * Shared, side-effect-free static-SQL parsing helpers for the schema-integrity
 * smoke gate. Required by BOTH new S-SCHEMA-GUARD-1 smokes so they do not fork
 * divergent CREATE/ALTER detection logic:
 *
 *   - tests/_smoke_migrations_reproduce_master.php  (D-GUARD-1 keystone)
 *   - tests/_smoke_no_stray_schema_sql.php          (D-GUARD-2)
 *
 * WHY static parsing (and not a dynamic from-zero `migrate` build): db_migrations/
 * holds INCREMENTAL deltas, not a full schema. 112 of the 153 master tables
 * (users, customers, leases, invoices, every acc_*) are created by NO migration —
 * they live only in FLEETFORGE_DATABASE_MASTER.sql. A from-zero migrate therefore
 * cannot reproduce master, and re-applying migrations onto a master-loaded DB
 * fails (40/90 migrations error — bare CREATE TABLE / ADD COLUMN are not
 * idempotent). So the feasible, hermetic invariant is the STATIC subset relation:
 * every table/column a migration introduces MUST be reflected in master. That is
 * exactly the drift that caused the 2026-06-05 outage (role_permission_overrides
 * created by a migration but absent from master + filed in an unscanned dir).
 *
 * WHY a `\w`-anchored regex is safe against the "dynamic DDL" migrations: the
 * idempotency-helper migrations build SQL via `CONCAT('ALTER TABLE ', tbl, ...)`
 * where `tbl` is a runtime variable — after "ALTER TABLE " comes a quote, not a
 * word char, so the skeleton never matches. The REAL identifiers those migrations
 * touch are passed as literal string args to a fixed helper vocabulary
 * (ff_add_column_if_missing('t','c'), ff_drop_column_if_present('t','c'),
 * ff_drop_table_if_present('t')) which this lib parses literally.
 *
 * @session  S-SCHEMA-GUARD-1
 * @decision D-GUARD-1, D-GUARD-2
 */

/**
 * Strip SQL comments so prose like "-- the CREATE TABLE block" or
 * "-- ENUM MODIFY is idempotent" cannot produce phantom CREATE/ALTER claims.
 *
 * Removes:
 *   - /​* ... *​/ block comments (incl. multi-line)
 *   - "-- " line comments (MySQL requires a space/control char after the dashes)
 *   - "#"  line comments
 *
 * Conservative by design: never touches identifiers. The only loss is that a
 * string literal containing "-- " (e.g. a COMMENT clause) is truncated to EOL —
 * harmless here because table/column names always precede the COMMENT clause.
 */
function ff_schema_strip_sql_comments(string $sql): string
{
    // Block comments first (covers /*!40101 ... */ executable-comment noise too).
    $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;

    $out = [];
    foreach (explode("\n", $sql) as $line) {
        // MySQL line comment: "--" followed by whitespace/control, to EOL.
        $line = preg_replace('/(^|[^-])--(\s.*)?$/', '$1', $line) ?? $line;
        // "#" line comment to EOL.
        $line = preg_replace('/#.*$/', '', $line) ?? $line;
        $out[] = $line;
    }
    return implode("\n", $out);
}

/**
 * True if the (comment-stripped) SQL contains any schema-defining DDL —
 * CREATE TABLE or ALTER TABLE. Used by D-GUARD-2 to decide whether a stray
 * *.sql file carries schema (forbidden outside db_migrations/ + master) vs is a
 * pure data seed (INSERT-only — allowed).
 */
function ff_schema_sql_has_table_ddl(string $sql): bool
{
    $clean = ff_schema_strip_sql_comments($sql);
    return (bool) preg_match('/\bCREATE\s+TABLE\b/i', $clean)
        || (bool) preg_match('/\bALTER\s+TABLE\b/i', $clean);
}

/**
 * Parse FLEETFORGE_DATABASE_MASTER.sql into a map: table => { column => true }.
 * Column names are lower-cased. Constraint/key lines (PRIMARY KEY, UNIQUE KEY,
 * KEY, CONSTRAINT, FOREIGN KEY) start with a keyword, never a backtick, so the
 * `^\s*` + backtick column rule excludes them cleanly.
 *
 * @return array<string, array<string, bool>>
 */
function ff_schema_parse_master_tables(string $masterSql): array
{
    $clean  = ff_schema_strip_sql_comments($masterSql);
    $tables = [];

    // Each table block: CREATE TABLE `x` ( <body> ) ENGINE=... ;
    // Non-greedy body up to the first ") ENGINE" closer so nested enum('a')/(10,7)
    // parens inside columns don't terminate the block early.
    if (preg_match_all('/CREATE\s+TABLE\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE=/is', $clean, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $blk) {
            $table = strtolower($blk[1]);
            $cols  = [];
            foreach (explode("\n", $blk[2]) as $bodyLine) {
                if (preg_match('/^\s*`(\w+)`\s/', $bodyLine, $cm)) {
                    $cols[strtolower($cm[1])] = true;
                }
            }
            $tables[$table] = $cols;
        }
    }
    return $tables;
}

/**
 * Parse a single migration file's SQL into the NET set of schema claims it makes
 * about tables and columns, folding CREATE/ADD against DROP within the file.
 *
 * Returns an associative structure (all identifiers lower-cased):
 *   [
 *     'created_tables' => [t => true, ...],   // CREATE TABLE `t`
 *     'dropped_tables' => [t => true, ...],   // DROP TABLE `t` | ff_drop_table_if_present('t')
 *     'added_cols'     => ['t.c' => [t,c], ...], // ADD COLUMN | ff_add_column_if_missing | CREATE-block col
 *     'dropped_cols'   => ['t.c' => true, ...],  // ff_drop_column_if_present('t','c')
 *   ]
 *
 * Net semantics (subset-relation, order-insensitive): a table/column that is ever
 * dropped is removed from the asserted set. This intentionally errs toward NOT
 * asserting a dropped object (avoids false RED on create-then-drop sequences such
 * as lease_close_adjustments) at the cost of not asserting a rare drop-then-re-add
 * — acceptable for a master-reflection guard whose primary job is catching
 * introduced-but-unreflected schema.
 *
 * @return array{created_tables:array<string,bool>,dropped_tables:array<string,bool>,added_cols:array<string,array{0:string,1:string}>,dropped_cols:array<string,bool>}
 */
function ff_schema_parse_migration_claims(string $sql): array
{
    $clean = ff_schema_strip_sql_comments($sql);

    $createdTables = [];
    $droppedTables = [];
    $addedCols     = [];
    $droppedCols   = [];

    // ── CREATE TABLE `t` ( body ) — table + its declared columns ──
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*ENGINE=/is', $clean, $ct, PREG_SET_ORDER)) {
        foreach ($ct as $blk) {
            $table = strtolower($blk[1]);
            $createdTables[$table] = true;
            foreach (explode("\n", $blk[2]) as $bodyLine) {
                if (preg_match('/^\s*`(\w+)`\s/', $bodyLine, $cm)) {
                    $col = strtolower($cm[1]);
                    $addedCols["{$table}.{$col}"] = [$table, $col];
                }
            }
        }
    }
    // Catch CREATE TABLE that does not end in ") ENGINE=" (table name only, no
    // body parse) so table-level coverage never silently misses one.
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/is', $clean, $ctn, PREG_SET_ORDER)) {
        foreach ($ctn as $blk) {
            $createdTables[strtolower($blk[1])] = true;
        }
    }

    // ── DROP TABLE [IF EXISTS] `t` ──
    if (preg_match_all('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/is', $clean, $dt, PREG_SET_ORDER)) {
        foreach ($dt as $blk) {
            $droppedTables[strtolower($blk[1])] = true;
        }
    }

    // ── ALTER TABLE `t` ... ADD COLUMN `c` (literal, multi-column, and CONCAT
    //    string-built forms all carry the literal identifiers). Window each
    //    ALTER to the next ';' and harvest every ADD COLUMN inside it. ──
    if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?(.*?);/is', $clean, $at, PREG_SET_ORDER)) {
        foreach ($at as $blk) {
            $table = strtolower($blk[1]);
            if (preg_match_all('/ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $blk[2], $cm)) {
                foreach ($cm[1] as $rawCol) {
                    $col = strtolower($rawCol);
                    // Skip ADD KEY/INDEX/CONSTRAINT/PRIMARY/UNIQUE/FOREIGN — those
                    // are not columns. The `(?:COLUMN\s+)?` optional means a bare
                    // "ADD `c`" matches, so filter index/constraint keywords.
                    if (in_array($col, ['key', 'index', 'constraint', 'primary', 'unique', 'foreign', 'fulltext', 'spatial'], true)) {
                        continue;
                    }
                    $addedCols["{$table}.{$col}"] = [$table, $col];
                }
            }
        }
    }

    // ── Idempotency-helper CALLs with literal string args ──
    // ff_add_column_if_missing('t','c', ...)
    if (preg_match_all("/ff_add_column_if_missing\s*\(\s*'(\w+)'\s*,\s*'(\w+)'/i", $clean, $ac, PREG_SET_ORDER)) {
        foreach ($ac as $blk) {
            $t = strtolower($blk[1]);
            $c = strtolower($blk[2]);
            $addedCols["{$t}.{$c}"] = [$t, $c];
        }
    }
    // ff_drop_column_if_present('t','c')
    if (preg_match_all("/ff_drop_column_if_present\s*\(\s*'(\w+)'\s*,\s*'(\w+)'/i", $clean, $dc, PREG_SET_ORDER)) {
        foreach ($dc as $blk) {
            $droppedCols[strtolower($blk[1]) . '.' . strtolower($blk[2])] = true;
        }
    }
    // ff_drop_table_if_present('t')
    if (preg_match_all("/ff_drop_table_if_present\s*\(\s*'(\w+)'/i", $clean, $dtp, PREG_SET_ORDER)) {
        foreach ($dtp as $blk) {
            $droppedTables[strtolower($blk[1])] = true;
        }
    }

    return [
        'created_tables' => $createdTables,
        'dropped_tables' => $droppedTables,
        'added_cols'     => $addedCols,
        'dropped_cols'   => $droppedCols,
    ];
}

/**
 * List the *.sql migration files under db_migrations/, in the order they are
 * conceptually applied: the five pre-runner HISTORICAL_FILES first (so any later
 * delta that depends on them is ordered after), then the remaining files sorted
 * ascending by filename — mirroring Runner::listFiles()/HISTORICAL_FILES.
 *
 * Net subset-relation parsing is order-insensitive, but applying the runner's
 * own ordering keeps this lib faithful to the runtime and future-proof.
 *
 * @return list<string> absolute paths
 */
function ff_schema_list_migration_files(string $migrationsDir): array
{
    $historical = [
        'SAMSARA-1_schema.sql',
        'S-FIX-2_credit_notes_source_overpayment.sql',
        'S-PROD-1A_security_hardening.sql',
        'S-PROD-2_ses_bounce_handler.sql',
        'S-LEASE-MILEAGE_schema.sql',
    ];

    $all = [];
    foreach (scandir($migrationsDir) ?: [] as $entry) {
        if (substr($entry, -4) === '.sql') {
            $all[$entry] = true;
        }
    }

    $ordered = [];
    foreach ($historical as $h) {
        if (isset($all[$h])) {
            $ordered[] = $migrationsDir . '/' . $h;
            unset($all[$h]);
        }
    }
    $rest = array_keys($all);
    sort($rest, SORT_STRING);
    foreach ($rest as $r) {
        $ordered[] = $migrationsDir . '/' . $r;
    }
    return $ordered;
}
