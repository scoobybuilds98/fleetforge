<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Database Helpers
//
// Loaded automatically via Composer `files` autoload.
// config/app.php must be loaded first (defines FF_DB_* constants).
//
// Never call db_pdo() directly from business logic — use the
// typed helpers below. All queries use prepared statements.
// ============================================================

// ============================================================
// SOFT_DELETE_TABLES — D5 + D13 (payments added [PASS-13:F2])
// Every SELECT on these tables MUST include: AND t.deleted_at IS NULL
// db_exists() enforces this automatically.
// ============================================================
const SOFT_DELETE_TABLES = [
    'users',
    'customers',
    'customer_notes',
    'equipment_templates',
    'equipment_units',
    'leases',
    'damage_claims',
    'invoices',
    'maintenance_work_orders',
    'documents',
    'vendors',
    'credit_notes',
    'reservations',
    'rate_cards',
    'payments',
    'notifications',  // [NOTIF-1] in-app notifications support soft-delete so users can clear without losing audit
    'customer_credit_applications',  // [S-CCA-1] D-CCA-3 — re-send retains full history; rows soft-delete, never hard-delete
];

// ============================================================
// db_pdo() — PDO singleton [PASS-8:1]
//
// Uses function-static so the connection is created once and
// reused for the lifetime of the request.
// Do NOT call this directly from business logic.
// ============================================================
function db_pdo(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        FF_DB_HOST,
        FF_DB_PORT,
        FF_DB_NAME
    );

    $pdo = new PDO($dsn, FF_DB_USER, FF_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,       // true prepared statements (security)
        PDO::ATTR_STRINGIFY_FETCHES  => false,        // keep native PHP types where possible
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'", // all DATETIMEs stored UTC
    ]);

    return $pdo;
}

// ============================================================
// db_select() — return all matching rows
// ============================================================
function db_select(string $sql, array $params = []): array
{
    $stmt = db_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================================
// db_row() — return exactly one row, or null
// ============================================================
function db_row(string $sql, array $params = []): ?array
{
    $stmt = db_pdo()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// ============================================================
// db_insert() — INSERT a row, return last insert ID
//
// $data keys are column names — sanitized to prevent accidents.
// ============================================================
function db_insert(string $table, array $data): int
{
    if (empty($data)) {
        throw new InvalidArgumentException("db_insert: data array cannot be empty.");
    }

    $cols      = array_map('db_sanitize_column', array_keys($data));
    $colList   = implode(', ', $cols);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));

    $sql  = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})";
    $stmt = db_pdo()->prepare($sql);
    $stmt->execute(array_values($data));

    return (int) db_pdo()->lastInsertId();
}

// ============================================================
// db_update() — UPDATE rows matching $where, return affected count
//
// $data keys are column names (sanitized).
// $where is a raw SQL fragment written by the developer (e.g. "id = ?").
// $whereParams are the bound values for the WHERE clause.
// ============================================================
function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    if (empty($data)) {
        throw new InvalidArgumentException("db_update: data array cannot be empty.");
    }

    $setClauses = array_map(
        static fn(string $col): string => db_sanitize_column($col) . ' = ?',
        array_keys($data)
    );
    $setSQL = implode(', ', $setClauses);

    $sql    = "UPDATE {$table} SET {$setSQL} WHERE {$where}";
    $params = array_merge(array_values($data), $whereParams);

    $stmt = db_pdo()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

// ============================================================
// db_execute() — run any SQL, return affected row count
// Use for: DELETE, custom UPDATE, SET statements, etc.
// ============================================================
function db_execute(string $sql, array $params = []): int
{
    $stmt = db_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

// ============================================================
// db_count() — return a single integer count
// The SQL must SELECT a single COUNT(*) expression.
// ============================================================
function db_count(string $sql, array $params = []): int
{
    $stmt = db_pdo()->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int) $val;
}

// ============================================================
// db_exists() — return true if at least one matching row exists
//
// Automatically appends "AND deleted_at IS NULL" for any table
// in SOFT_DELETE_TABLES.
// ============================================================
function db_exists(string $table, string $condition, array $params = []): bool
{
    $softDeleteClause = in_array($table, SOFT_DELETE_TABLES, true)
        ? ' AND deleted_at IS NULL'
        : '';

    $sql = "SELECT COUNT(*) FROM {$table} WHERE {$condition}{$softDeleteClause}";
    return db_count($sql, $params) > 0;
}

// ============================================================
// db_transaction() — wrap a callable in BEGIN / COMMIT / ROLLBACK
//
// Returns whatever the callable returns.
// On exception: rolls back and re-throws — caller decides how to handle.
//
// Nesting: if already inside a transaction (e.g., InvoiceGenerator called
// from within activate.php's transaction), the callable runs in the outer
// transaction — no COMMIT/ROLLBACK is issued. This preserves atomicity:
// if the inner work throws, the outer transaction still rolls back.
//
// FOR UPDATE usage (D20):
//   db_transaction(function() use ($id) {
//       $row = db_row("SELECT * FROM table WHERE id = ? FOR UPDATE", [$id]);
//       // safe to read-then-write here
//   });
// ============================================================
function db_transaction(callable $fn): mixed
{
    $pdo    = db_pdo();
    $nested = $pdo->inTransaction(); // true when called inside an outer transaction

    if (!$nested) {
        $pdo->beginTransaction();
    }

    try {
        $result = $fn();
        if (!$nested) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if (!$nested) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ============================================================
// db_sanitize_column() — internal helper
//
// Column names used in db_insert/db_update come from developer
// code (array keys), not user input. This guard prevents
// accidents — any column name that is not a valid SQL identifier
// throws immediately so bugs are caught early.
// ============================================================
function db_sanitize_column(string $col): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
        throw new InvalidArgumentException(
            "db_sanitize_column: invalid column name '{$col}'."
        );
    }
    // Backtick-quote every column name so reserved words (e.g. `condition`) are safe.
    return "`{$col}`";
}

// ============================================================
// optimistic_lock_matches() — D19 optimistic locking (S-PROD-1B #36)
//
// Normalizes both timestamps to Unix seconds before comparing.
// Comparison normalized to Unix timestamp / second precision.
// Subsecond drift discarded — concurrent same-second edits are
// extremely rare; the SELECT-then-UPDATE pattern guards against
// actual lost-update scenarios.
//
// Guards against: DST transitions, microsecond precision differences
// (DATETIME(6) vs DATETIME), and timezone-format variations that
// would cause a raw string comparison to incorrectly fire the lock.
// Fails closed (returns false) on malformed input so a garbled
// token never silently passes the lock.
// ============================================================
function optimistic_lock_matches(string $client, string $db): bool
{
    try {
        $clientTs = (new DateTimeImmutable($client))->getTimestamp();
        $dbTs     = (new DateTimeImmutable($db))->getTimestamp();
        return $clientTs === $dbTs;
    } catch (\Exception $e) {
        return false; // Malformed input fails closed
    }
}
