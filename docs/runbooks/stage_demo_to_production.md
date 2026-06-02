# Stage Demo Data to Production — Operator Runbook

**Session:** S-STAGE-DEMO-TO-PRODUCTION  
**Decision:** D-STAGE-PROD-TOOL  
**Tool:** `scripts/stage_demo_to_production.php`  
**Target:** AWS Lightsail Oregon (mainlandrentals.com)  
**Purpose:** Replace pre-launch production dummy data with the validated rehearsal
demo dataset (12 BC trucking customers, 4 vendors, 25 equipment, 6 leases, 14 invoices,
4 payments, 3 bills — S-DRESS-REHEARSAL-WIPE-AND-SEED).

---

## When to use this

Run this **once** before the first real customer goes live, to replace the old test/dummy
data inserted during initial server setup with the clean, production-shaped rehearsal
dataset. After this runs, the app will show the rehearsal data until a real customer
is added.

**Do not run this again** after real customer data has been entered. The wipe is
destructive and irreversible without the backup.

---

## Prerequisites

1. **No real customers** are in the production database yet. This tool assumes
   pre-launch state — all existing data is dummy/test data with nothing real to lose.
   If you have any doubt, **stop** and consult the backup before proceeding.
2. **SSH access** to the Lightsail server.
3. **Git access** — `git pull` to get this tool onto the server.

---

## Step 1 — SSH to Lightsail and pull the tool

```bash
ssh ubuntu@mainlandrentals.com   # or your SSH alias
cd /var/www/fleetforge
git pull origin main
```

Verify the script is present:
```bash
ls scripts/stage_demo_to_production.php
```

---

## Step 2 — Check and optionally disable QBO sync

The tool (G3 guard) will refuse if QuickBooks is connected to a **real** Intuit realm
with `sync_enabled='1'`. Seeding fake data while sync is on would push fake data to
real books.

Check the current QBO state:
```sql
mysql -u <user> -p <dbname> -e "
  SELECT \`key\`, value FROM settings
  WHERE \`key\` IN (
    'quickbooks.connection_status',
    'quickbooks.environment',
    'quickbooks.realm_id',
    'quickbooks.sync_enabled'
  );"
```

**If `sync_enabled='1'` and `environment` is not `sandbox`**, disable sync before running:
```sql
UPDATE settings SET value='0' WHERE \`key\`='quickbooks.sync_enabled';
```

You can re-enable it after the staging run if needed.

**Safe to proceed if any of these are true:**
- `connection_status` is not `'connected'`
- `environment` is `'sandbox'`
- `sync_enabled` is `'0'`

---

## Step 3 — Dry-run on production (review the plan)

```bash
php scripts/stage_demo_to_production.php \
    --dry-run \
    --confirm-db=<prod-db-name>
```

Replace `<prod-db-name>` with the actual production database name (from `.env` `DB_DATABASE`).

**Review the output carefully:**
- G1: confirms `APP_ENV='production'`
- G2: confirms DB name match
- G3: prints QBO state and confirms it is safe to proceed
- Blast-radius echo: lists current row counts (what will be wiped) and the dataset
  to be seeded

The dry-run does **not** take a backup or mutate any data.

---

## Step 4 — Real run (wipe + seed)

Only proceed after reviewing the dry-run output.

```bash
php scripts/stage_demo_to_production.php \
    --i-am-staging-demo-data-to-production \
    --confirm-db=<prod-db-name>
```

The tool will:
1. Verify all five guards (G1–G5) pass.
2. **Take a full gzipped backup** of the production database before touching anything.
   The backup path is printed — **copy it down** for the rollback command.
3. Print the blast-radius echo (current counts, dataset to seed, backup path).
4. Ask for typed confirmation: type `yes-wipe-and-seed` to proceed.
5. Run `ff_demo_wipe_execute()` — truncates all data tables, resets counters.
6. Run `ff_rehearsal_seed_execute()` — seeds 6 templates, 25 units, 12 customers,
   4 vendors, 6 leases, ~14 invoices, 4 payments, 3 bills.
7. Run post-verify checks and report PASS/FAIL.

**No QBO push:** the seed uses direct database inserts — no Enqueuers are called,
no rows are added to `acc_qbo_sync_queue`.

---

## Step 5 — Post-run checklist

After a successful run:

- [ ] Load the app in a browser and confirm the demo data renders on the Customers,
      Equipment, Leases, Invoices, and Payments pages.
- [ ] Confirm no QBO queue rows were added:
      ```sql
      SELECT COUNT(*) FROM acc_qbo_sync_queue;
      ```
      Should be 0 (or unchanged from before the run).
- [ ] Note the backup path from the run output in case rollback is needed.
- [ ] If you disabled QBO sync in Step 2 and want to re-enable it:
      ```sql
      UPDATE settings SET value='1' WHERE \`key\`='quickbooks.sync_enabled';
      ```
      Only do this when you are ready for QBO sync to resume.

---

## Step 6 — Rollback (if needed)

If the post-run checks show problems or you want to restore the previous state,
use the backup printed during Step 4:

```bash
zcat /var/www/fleetforge/storage/backups/staging/pre_staging_<TIMESTAMP>.sql.gz \
  | mysql --host=127.0.0.1 --port=3306 --user=<user> --password=<password> <dbname>
```

The exact restore command was printed during the real run — copy it from the output.

---

## Backup retention

Staging backups are written to:
```
/var/www/fleetforge/storage/backups/staging/
```

They are **not** covered by the automatic tiered retention policy in `cron/backup_db.php`
(which manages `backups/db/`). These staging backups persist until manually removed.

The regular `backup_db.php` cron backups (in `backups/db/`) follow the 90-day tiered
retention policy:
- Days 0–7: all backups kept
- Days 8–30: one per day (newest)
- Days 31–90: one per ISO week (newest)
- Beyond 90 days: deleted

Set `BACKUP_DB_RETENTION_DAYS=365` in `.env` if you need longer retention for CRA
compliance purposes (note: financial record exports satisfy the 6-year requirement
separately).

---

## Guard summary

| Guard | What it checks | How to unblock |
|-------|----------------|----------------|
| G1 | `APP_ENV='production'` | Run on the production server |
| G2 | Token + typed DB name | Pass both flags correctly |
| G3 | QBO not syncing to real realm | Disable sync or confirm sandbox |
| G4 | Backup must succeed and be non-empty | Fix mysqldump access / disk space |
| G5 | Blast-radius echo + typed confirmation | Type `yes-wipe-and-seed` |
