## [MEDIUM] — Soft-delete enforcement registry is out of sync with schema
File: includes/db.php:19
Code:
```php
const SOFT_DELETE_TABLES = [
    'users',
    'customers',
    'customer_notes',
    // ...
    'payments',
    'notifications',
];
```
Why it's a problem: `db_exists()` relies on `SOFT_DELETE_TABLES` to auto-append `AND deleted_at IS NULL`, but schema tables `chat_messages` and `email_templates` both have a `deleted_at` column and are missing from this list (`FLEETFORGE_DATABASE_MASTER.sql:1677`, `FLEETFORGE_DATABASE_MASTER.sql:2127`). This creates drift where helper-level semantics are inconsistent by table, and soft-deleted rows can be treated as existing when callers rely on `db_exists()` for guard checks.
Proposed fix: Make the registry authoritative and complete for all soft-delete tables (add at least `chat_messages`, `email_templates`) and add a CI-time drift check against schema.

## [LOW] — Core DB helpers interpolate raw table/WHERE fragments without identifier sanitization
File: includes/db.php:107
Code:
```php
$sql  = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})";
// ...
$sql    = "UPDATE {$table} SET {$setSQL} WHERE {$where}";
// ...
$sql = "SELECT COUNT(*) FROM {$table} WHERE {$condition}{$softDeleteClause}";
```
Why it's a problem: Column names are sanitized, but table names and WHERE/condition fragments are interpolated verbatim. Today’s call sites mostly pass literals, but this leaves a sharp edge where any future endpoint that forwards request-derived table/condition fragments into these helpers will become SQL-injection-prone immediately.
Proposed fix: Add `db_sanitize_table()` for identifier validation/backtick quoting and provide constrained helper variants (e.g., structured condition builders) for common existence/update patterns.

Summary: 0 CRITICAL / 1 MEDIUM / 1 LOW.
