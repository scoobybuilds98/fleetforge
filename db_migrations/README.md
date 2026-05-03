# FleetForge — Database Migrations

This directory holds every schema-mutating SQL file applied to the
FleetForge database. The `bin/migrate.php` CLI is the only sanctioned
way to apply migrations going forward.

## Filename convention

| Prefix style | When to use | Example |
|--------------|-------------|---------|
| `<SESSION-ID>_<desc>.sql` | The 5 historical files (pre-S-MIGRATIONS-RUNNER). Do **not** create new files in this style — they get backfilled, not re-run. | `S-PROD-2_ses_bounce_handler.sql` |
| `YYYYMMDDHHMM_<SESSION-ID>_<desc>.sql` | Every new migration from S-MIGRATIONS-RUNNER onward. UTC timestamp. | `202605051430_S-FIX-INVOICELINES_typo_fix.sql` |

The runner sorts apply candidates by filename ascending. Timestamp
prefixes guarantee chronological ordering for new files. The
historical S-* files are already recorded as applied (via
`--backfill`) and never re-enter the apply list, so their natural
alphabetic order does not matter.

Filenames must match `^[A-Za-z0-9_\-]+\.sql$`. Anything with spaces,
quotes, or shell metacharacters is rejected at scan time.

## Migration file authoring rules

1. **Idempotent where possible.** Use `IF NOT EXISTS`,
   `INFORMATION_SCHEMA` guards, or stored-procedure helpers (see
   `S-LEASE-MILEAGE_schema.sql` for the pattern). The runner enforces
   single-application via `schema_migrations`, but idempotent files
   are safer for ad-hoc replay.
2. **Header comment** with: session ID, date, decision tags,
   per-statement WHY explanations.
3. **No data writes** unless the data is structural seed (settings
   table, role permissions, etc.). Operational data goes through the
   normal API write path.
4. **DDL only auto-commits.** MySQL cannot roll back a partial
   `CREATE TABLE` / `ALTER TABLE` if a later statement in the same
   file fails. If you need transactional safety, split into multiple
   smaller files.
5. **`DELIMITER` is allowed** — the runner shells out to the `mysql`
   binary, so stored procedures and triggers work natively.

## Runner usage

```sh
# default: dry-run, prints plan, mutates nothing
php bin/migrate.php

# explicit dry-run
php bin/migrate.php --dry-run

# actually apply pending migrations
php bin/migrate.php --apply

# one-time bootstrap of the 5 historical files (refuses if non-empty)
php bin/migrate.php --backfill

# recompute checksums of every recorded migration
php bin/migrate.php --verify

# terse status (counts only, always exit 0)
php bin/migrate.php --status

# help
php bin/migrate.php --help
```

### Exit codes

| Code | Meaning |
|------|---------|
| 0 | Success / clean |
| 1 | Generic error (SQL failure, IO, etc.) |
| 2 | Bad CLI args |
| 3 | Another runner is in flight (`GET_LOCK('ff_migrations', 0)` failed) |
| 4 | Checksum drift detected on `--dry-run` / `--apply` / `--verify` |

## What the runner does

1. **Acquires** advisory lock `ff_migrations` (timeout 0 — fail fast,
   no blocking). Aligns with the `D21` cron-lock pattern.
2. **Scans** `db_migrations/` for `*.sql` files matching the safe
   filename regex.
3. **Computes** SHA-256 of each file.
4. **Diffs** against `schema_migrations` rows — files not yet
   recorded are the apply list.
5. **Reports drift** for any already-applied file whose current
   SHA-256 differs from the stored value. Refuses to apply anything
   else until drift is resolved.
6. **Applies** each pending file in filename-ascending order by
   shelling out to `mysql`. Each file gets its own connection.
7. **Records** every successful apply in `schema_migrations` with
   checksum, applied_by, and execution time.
8. **Audits** every apply via an `audit_log` row
   (`action='cron'`, `module='migrations'`).
9. **Releases** the advisory lock on exit (also auto-released by
   MySQL on connection close as a safety net).

### `schema_migrations` columns

| Column | Type | Notes |
|--------|------|-------|
| `id` | `INT UNSIGNED PK` | Auto-increment |
| `version` | `VARCHAR(100) UNIQUE` | Filename without `.sql` extension |
| `filename` | `VARCHAR(255)` | Full filename including `.sql` |
| `checksum` | `CHAR(64) NULL` | SHA-256 hex of file contents |
| `applied_at` | `DATETIME` | Defaults to `NOW()`; backfill sets file mtime |
| `applied_by` | `VARCHAR(100)` | Defaults to `cli`; runner sets `whoami`; backfill sets `backfill:<whoami>` |
| `execution_ms` | `INT UNSIGNED NULL` | Real apply time in ms; 0 for backfill |

## Failure handling

**A migration fails partway through (mysql exits non-zero):**

1. Runner stops, prints filename + exit code + stderr.
2. The file is **NOT** recorded in `schema_migrations`.
3. Because MySQL DDL auto-commits, partial state may exist in the DB.
4. Operator decision tree:
   - **Roll-forward:** fix the broken statement in the .sql file, then
     re-run `--apply`. If earlier statements in the file already
     committed, write the file to be idempotent (use
     `IF NOT EXISTS` / `INFORMATION_SCHEMA` guards) so the re-run
     skips them safely.
   - **Restore from backup:** if the partial state corrupts data,
     restore from the most recent `cron/backup_db.php` artifact and
     re-investigate before re-running.

**Checksum drift detected:**

Means a file was edited after being applied. Runner refuses any
`--apply` until resolved.

- **Edit was a typo / accidental:** revert the change so the file's
  SHA-256 returns to the stored value.
- **Edit was intentional and the DB already reflects it:** manually
  update `schema_migrations.checksum` to the new SHA-256 (one-line
  `UPDATE`). Going forward, prefer creating a *new* migration over
  editing an applied one.
- **Edit was intentional but the DB does NOT yet reflect it:** create
  a NEW migration file with the diff. Never re-run an edited file —
  the runner will refuse.

## Why a runner exists

Before `bin/migrate.php`, migrations were applied manually:

```sh
mysql ... < db_migrations/SAMSARA-1_schema.sql
```

This worked but had no guard against:

- Applying the same file twice on different servers.
- Skipping a file in the deploy pipeline.
- Silently editing a previously-applied file and re-running it.
- Knowing *who* applied *what* and *when* for audit/compliance.

The runner makes `schema_migrations` the source of truth and adds
SHA-256 drift detection. The 5 historical files are recorded as
already-applied via `--backfill`, so the runner and the live DB
agree from day one.

## History

- **S-MIGRATIONS-RUNNER (2026-05-04)** — Runner created. Five
  historical files backfilled with `applied_by = 'backfill:<whoami>'`.
  `schema_migrations` extended with `checksum`, `applied_by`,
  `execution_ms` columns.

## See also

- `bin/migrate.php` — CLI entry point
- `lib/Migrations/Runner.php` — implementation
- `FLEETFORGE_DATABASE_MASTER.sql` — canonical schema baseline (kept
  in sync with the live DB; reconciliation history in
  `FLEETFORGE_PROGRESS.md`)
- `FLEETFORGE_CLAUDE_CODE_REFERENCE.md` — § Migrations
