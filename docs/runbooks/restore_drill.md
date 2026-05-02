# FleetForge — Database Restore Runbook

**Purpose:** Step-by-step guide for restoring a FleetForge database from a backup created
by `cron/backup_db.php`. Run this drill periodically to verify backup integrity and measure
recovery time (RTO).

**Audit findings resolved:** #9 (no restore drill), #22 (no documented runbook)

---

## 1. Prerequisites

Before starting a restore drill, confirm the following are in place:

| Requirement | Check |
|---|---|
| PHP 8.2+ with ext-zlib and ext-pdo_mysql | `php -r "echo phpversion();"` |
| MySQL client (`mysql` binary in PATH) | `mysql --version` |
| `mysqldump` binary in PATH | `mysqldump --version` |
| DB credentials readable from `.env` | `grep DB_ .env` |
| `STORAGE_DRIVER` set correctly in `.env` | `grep STORAGE_DRIVER .env` |
| AWS CLI configured (production only) | `aws s3 ls s3://$AWS_S3_BUCKET/backups/db/` |
| At least one backup exists | `php scripts/restore_db.php --list` |
| Enough disk space in `/tmp` for the dump | `df -h /tmp` |

**Development environment:** `STORAGE_DRIVER=local` — backups are stored under
`storage/backups/db/` relative to the project root. No AWS credentials needed.

**Production environment:** `STORAGE_DRIVER=s3` — backups are in the S3 bucket defined
by `AWS_S3_BUCKET`. Ensure IAM credentials have `s3:GetObject` and `s3:ListBucket` permissions.

---

## 2. Step-by-step Restore Drill (Test Database)

Record your start time before beginning.

**Start time: ___________**

### Step 1 — List available backups

```bash
cd /Users/avi/Documents/fleetforge   # or /var/www/fleetforge in production
php scripts/restore_db.php --list
```

Expected output:
```
FleetForge DB Backups (STORAGE_DRIVER=local)
========================================================================
  #   KEY                                                      SIZE     DATE (UTC)
  1   backups/db/2026-05-02/18/fleetforge_20260502180000.sql.gz  1.2MB  2026-05-02 18:00:00
  ...
```

**Choose a backup key to restore.** For the drill, choose the most recent backup.

---

### Step 2 — Dry-run verify (optional but recommended)

Verify the backup downloads and decompresses cleanly without restoring anything:

```bash
php scripts/restore_db.php \
  --restore=backups/db/YYYY-MM-DD/HH/fleetforge_TIMESTAMP.sql.gz \
  --dry-run
```

Expected: `Dry-run complete — backup downloaded and verified successfully.`

If this fails, do **not** proceed. See Section 5 (Troubleshooting).

---

### Step 3 — Restore to test database

```bash
php scripts/restore_db.php \
  --restore=backups/db/YYYY-MM-DD/HH/fleetforge_TIMESTAMP.sql.gz \
  --target-db=fleetforge_restore_test
```

The script will:
1. Check the backup exists in storage
2. Download to `/tmp/`
3. Decompress and verify the MySQL dump header
4. Create `fleetforge_restore_test` database if it does not exist
5. Restore via the `mysql` binary
6. Run sample row counts and print a comparison

**Record restore duration (from script output):** ___________

---

### Step 4 — Manual verification queries

Connect directly to the restored database and confirm data looks correct:

```bash
mysql -u root -p fleetforge_restore_test
```

```sql
SELECT COUNT(*) AS customers FROM customers WHERE deleted_at IS NULL;
SELECT COUNT(*) AS invoices  FROM invoices  WHERE deleted_at IS NULL;
SELECT COUNT(*) AS leases    FROM leases    WHERE deleted_at IS NULL;
SELECT COUNT(*) AS payments  FROM payments  WHERE deleted_at IS NULL;

-- Spot-check a customer record
SELECT id, company_name, status, outstanding_balance
FROM customers WHERE deleted_at IS NULL LIMIT 3;

-- Spot-check a recent invoice
SELECT id, invoice_number, status, total_amount, created_at
FROM invoices ORDER BY created_at DESC LIMIT 3;
```

Confirm counts roughly match the live database values printed by the restore script.
Document any anomalies in Section 6 (Drill Execution Log).

**Exit mysql:** `exit`

---

### Step 5 — Drop the test database

```bash
mysql -u root -p -e 'DROP DATABASE IF EXISTS fleetforge_restore_test;'
```

Confirm: `Query OK, 0 rows affected`

**End time: ___________**

**Total RTO = End time − Start time = ___________ minutes**

---

## 3. RTO Measurement Breakdown

| Phase | Duration (fill in) |
|---|---|
| List backups (Step 1) | ___ s |
| Dry-run download + verify (Step 2) | ___ s |
| Restore to test DB (Step 3) | ___ s |
| Manual verification queries (Step 4) | ___ s |
| Drop test DB (Step 5) | ___ s |
| **Total RTO** | **___ min** |

---

## 4. Production Restore (EMERGENCY ONLY)

> **DO NOT RUN UNLESS APPROVED BY OWNER**
>
> This section describes how to restore the live `fleetforge` database.
> It will **WIPE ALL DATA** in the production database and replace it with
> the backup contents. Perform this step only after data loss is confirmed
> and after obtaining explicit approval from the system owner.

**Pre-conditions before a production restore:**
- [ ] Data loss confirmed (not just a user error)
- [ ] Owner has approved the restore in writing
- [ ] Application traffic halted (take site down / maintenance mode)
- [ ] A final backup of the current (possibly corrupt) state attempted
- [ ] Target backup key identified and verified with `--dry-run`

**Production restore command:**

```bash
php scripts/restore_db.php \
  --restore=backups/db/YYYY-MM-DD/HH/fleetforge_TIMESTAMP.sql.gz \
  --target-db=fleetforge \
  --confirm-prod
```

You will be prompted to type **exactly**:
```
yes I want to wipe production
```

Any other input aborts the restore immediately.

**Post-restore checks:**
1. Confirm application loads: `curl -I http://fleetforge.test/fleetforge/auth/login`
2. Log in and verify recent data is present
3. Check `audit_log` for any restore entries
4. Resume application traffic

---

## 5. Troubleshooting

| Error | Likely Cause | Resolution |
|---|---|---|
| `Backup not found at storage key` | Key typo, or backup was deleted by retention | Re-run `--list` to get the correct key |
| `mysqldump exited 1: Access denied` | Wrong DB credentials in `.env` | Check `DB_USERNAME` / `DB_PASSWORD` in `.env` |
| `gzopen failed — ext-zlib may not be loaded` | PHP zlib extension missing | `php -m | grep zlib`; install php8.2-xml package |
| `Header verification failed` | Corrupt or truncated backup | Choose a different backup; investigate backup cron logs |
| `mysql restore exited 1: ERROR 1044 (42000)` | DB user lacks CREATE privilege | Grant privileges: `GRANT ALL ON fleetforge_restore_test.* TO 'user'@'host';` |
| `proc_open: failed to start mysql` | mysql binary not in PATH | `which mysql`; install mysql-client package |
| S3: `403 Forbidden` | IAM credentials lack `s3:GetObject` | Add `s3:GetObject` and `s3:ListBucket` to the IAM policy |
| S3: `NoSuchKey` | Backup was deleted or wrong bucket | Check `AWS_S3_BUCKET` in `.env`; verify key with AWS CLI |
| `Disk space exhausted in /tmp` | Large database + small `/tmp` | `df -h /tmp`; free space or set `TMPDIR` to a larger partition |

---

## 6. Drill Execution Log

Fill in after each drill. Keep at least the last three drill records.

| Field | Value |
|---|---|
| **Last drill executed** | 2026-05-02 |
| **RTO** | 0.03 min (2 s) — restore script execution time (dev/local storage) |
| **Backup used** | backups/db/2026-05-02/07/fleetforge_20260502071811.sql.gz |
| **Backup size** | 0.13 MB compressed / 1.36 MB uncompressed |
| **Executed by** | scoobybuilds98 (dev environment, STORAGE_DRIVER=local) |
| **Customers count** | 5 (matched source) |
| **Invoices count** | 60 (matched source) |
| **Leases count** | 31 (matched source) |
| **Anomalies** | None — all row counts matched source DB exactly |
| **Audit entries** | Confirmed: backup_db start/complete/retention + restore initiated/step4/complete all in audit_log |

---

*Previous drills:*

| Date | RTO | Notes |
|---|---|---|
| 2026-05-02 | 2 s | First drill — dev environment, local storage driver, 134KB backup, all counts matched |

---

*Runbook version: 1.0.0 — Last updated: 2026-05-02*
