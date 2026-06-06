# FleetForge — Baseline Reconcile Runbook

**Session:** S-SCHEMA-BASELINE  
**Decisions:** D-BASELINE-1..D-BASELINE-6  
**When to use:** One-time, on the FIRST deploy that includes `db_migrations/000_baseline.sql`.

---

## Why This Runbook Exists

`000_baseline.sql` captures the full schema as of 2026-06-06 (all 156 tables, `CREATE
TABLE IF NOT EXISTS`). It is the foundation that makes "fresh DB from migrations == master"
true and enforceable. On an existing prod that already has the full schema, running
`000_baseline.sql` via `migrate --apply` is a **guaranteed no-op** (all CREATE IF NOT EXISTS
no-op, all INSERT IGNORE no-op) — but it MUST be **pre-marked applied** before `migrate
--apply` runs so `deploy.sh`'s baseline guard (step 7) doesn't abort the deploy.

**The key rule (operator Q2, Guard 4):** prod must already have the COMPLETE current schema
before this runbook runs. Never combine "catch prod up" and "introduce baseline" in one
motion. Verify prod is current first.

---

## Pre-conditions (verify before starting)

1. **Prod is fully caught up.** Run on prod:
   ```bash
   php bin/migrate.php --assert-applied
   ```
   Must exit 0 (PENDING: 0, DRIFT: 0). If not: apply the missing migrations first (normal
   deploy), verify health, then come back here.

2. **`000_baseline.sql` is in the repo.** After `git pull`:
   ```bash
   ls db_migrations/000_baseline.sql
   ```
   Must exist.

3. **No other session is IN-FLIGHT.** Check `docs/FLEETFORGE_CURRENT_SESSIONS.md`.

---

## Procedure (one-time, manual — do NOT use `bin/deploy.sh`)

This is a SPECIAL deploy. The normal `bin/deploy.sh` aborts if 000_baseline is pending and
unmarked (Guard D-BASELINE-3). Follow these steps exactly, on the server:

### Step 1 — Pull the code

```bash
cd /var/www/fleetforge
sudo -u www-data git pull --ff-only origin main
```

Confirm that `db_migrations/000_baseline.sql` now exists.

### Step 2 — Pre-mark 000_baseline as applied (WITHOUT running it)

This is the critical step. Run the INSERT **before** `migrate --apply`:

```sql
-- Run in mysql as the fleetforge DB user:
INSERT INTO schema_migrations (version, filename, checksum, applied_by, execution_ms, applied_at)
VALUES (
  '000_baseline',
  '000_baseline.sql',
  '',
  'operator:baseline-reconcile',
  0,
  NOW()
);
```

From the server command line:

```bash
mysql -u fleetforge_user -p fleetforge -e "
  INSERT INTO schema_migrations (version, filename, checksum, applied_by, execution_ms, applied_at)
  VALUES ('000_baseline', '000_baseline.sql', '', 'operator:baseline-reconcile', 0, NOW());
"
```

**Why `checksum = ''`:** Runner treats empty checksum as "pre-checksum baseline" and never
flags it as drift (same as the 5 HISTORICAL_FILES). The real sha256 of 000_baseline.sql is
`31915a8f3455c46fde7656fcbbd83090f95d5c90dc71963e988c1c95fd2cf772` — you may substitute
it if you prefer zero-special-case verification, but `''` is fully supported.

### Step 3 — Verify the pre-mark

```bash
php bin/migrate.php --status
# Expected: applied: N+1 (where N = prior count), pending: 0, drift: 0
```

The `+1` is the 000_baseline row you just inserted. Pending must be 0.

```bash
php bin/migrate.php --verify
# Expected: all ok / 0 drift / 0 missing
# (pre-baseline .sql.txt archived files report "archived ok" — not "missing_file")
```

### Step 4 — Run migrate --apply (no-op)

```bash
sudo -u www-data php bin/migrate.php --apply
# Expected: "✓ 0 migrations to apply (everything is up to date)"
```

Since 000_baseline is now marked applied and all pre-baseline files are archived to
`.sql.txt`, there is nothing to apply.

### Step 5 — Reload php-fpm (opcache) + health gate

```bash
sudo systemctl reload php8.2-fpm
curl -s https://mainlandrentals.com/fleetforge/api/v1/health | python3 -m json.tool
```

Expected: `status: "ok"`, `migrations.pending: 0`, `schema.ok: true`.

### Step 6 — Confirm deploy.sh works normally again

```bash
bin/deploy.sh --dry-run
# Step 7 should print: "✓ 000_baseline.sql pre-marked in schema_migrations"
# (no abort)
```

---

## What if I accidentally ran migrate --apply BEFORE the pre-mark?

`000_baseline.sql` is `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE` only — it is a
guaranteed no-op on any full-schema DB (SC4 verified hermetically). The only consequence:
`migrate --apply` recorded a `schema_migrations` row for it with the real sha256 checksum
(instead of ''). That is fine — verify with `php bin/migrate.php --verify` and confirm 0
drift. No rollback is needed.

---

## Go-forward workflow (D-BASELINE-6)

After this runbook runs once, the baseline is reconciled and normal deploys resume via
`bin/deploy.sh`. For new schema changes:

1. Write a new delta migration: `db_migrations/YYYYMMDDHHII_label.sql`
2. Update `FLEETFORGE_DATABASE_MASTER.sql` to reflect the change
3. Run the precommit gate: `bash scripts/precommit_doc_check.sh`
   - The upgraded `_smoke_migrations_reproduce_master.php` keystone enforces from-zero == master
4. Commit both files together

**NEVER edit `000_baseline.sql` for new schema.** It is a fixed 2026-06-06 snapshot.

---

*Runbook version: 1.0.0 — S-SCHEMA-BASELINE — 2026-06-06*
