# FleetForge — Cron Jobs

All cron jobs use MySQL advisory locks (`GET_LOCK`) per D21 to prevent overlapping runs.
Every cron writes to `audit_log` on start, completion, and failure.
Test locally by running directly: `php /Users/avi/Documents/fleetforge/cron/<script>.php`

---

## Crontab (production — /var/www/fleetforge)

```crontab
# FleetForge cron jobs
# All output appended to logs/cron.log

# ── Data Protection (S-CRON-1) ──────────────────────────────────────────────
# DB backup every 6 hours (00:00, 06:00, 12:00, 18:00 UTC)
0 */6 * * *   /usr/bin/php /var/www/fleetforge/cron/backup_db.php >> /var/www/fleetforge/logs/cron.log 2>&1

# Storage tarball every Sunday at 03:00 UTC (low-traffic window)
0 3 * * 0     /usr/bin/php /var/www/fleetforge/cron/backup_storage.php >> /var/www/fleetforge/logs/cron.log 2>&1

# ── Billing ───────────────────────────────────────────────────────────────────
# Monthly invoice generation — 1st of each month at 06:00 UTC
0 6 1 * *     /usr/bin/php /var/www/fleetforge/cron/invoice_generate_monthly.php >> /var/www/fleetforge/logs/cron.log 2>&1

# Overdue invoice detection — daily at 07:00 UTC
0 7 * * *     /usr/bin/php /var/www/fleetforge/cron/invoice_overdue.php >> /var/www/fleetforge/logs/cron.log 2>&1

# Late fee application — daily at 07:30 UTC
30 7 * * *    /usr/bin/php /var/www/fleetforge/cron/late_fee_apply.php >> /var/www/fleetforge/logs/cron.log 2>&1

# ── Collections ───────────────────────────────────────────────────────────────
# Collections auto-escalation — daily at 08:00 UTC
0 8 * * *     /usr/bin/php /var/www/fleetforge/cron/collections_auto_escalate.php >> /var/www/fleetforge/logs/cron.log 2>&1

# Promise-to-pay check — daily at 09:00 UTC
0 9 * * *     /usr/bin/php /var/www/fleetforge/cron/promise_to_pay_check.php >> /var/www/fleetforge/logs/cron.log 2>&1

# ── GPS / Samsara ──────────────────────────────────────────────────────────────
# Samsara GPS sync — every 5 minutes
*/5 * * * *   /usr/bin/php /var/www/fleetforge/cron/samsara_sync.php >> /var/www/fleetforge/logs/gps.log 2>&1

# GPS mileage sync — daily at 02:00 UTC
0 2 * * *     /usr/bin/php /var/www/fleetforge/cron/gps_mileage_sync.php >> /var/www/fleetforge/logs/cron.log 2>&1

# ── Compliance ────────────────────────────────────────────────────────────────
# Compliance alerts — daily at 06:30 UTC
30 6 * * *    /usr/bin/php /var/www/fleetforge/cron/compliance_alerts.php >> /var/www/fleetforge/logs/cron.log 2>&1

# ── AI / Analytics ────────────────────────────────────────────────────────────
# AI anomaly scan — daily at 04:00 UTC
0 4 * * *     /usr/bin/php /var/www/fleetforge/cron/ai_anomaly_scan.php >> /var/www/fleetforge/logs/cron.log 2>&1

# ── Integrity & Cleanup (S-CRON-2) ────────────────────────────────────────────
# Counter drift detection — nightly at 02:00 UTC (after monthly invoice cron at 01:00)
0 2 * * *     /usr/bin/php /var/www/fleetforge/cron/reconcile_counters.php >> /var/www/fleetforge/logs/cron.log 2>&1

# Stale reservation cleanup — daily at 04:30 UTC
30 4 * * *    /usr/bin/php /var/www/fleetforge/cron/stale_reservations.php >> /var/www/fleetforge/logs/cron.log 2>&1

# Monthly data archive — 1st of month at 04:00 UTC
0 4 1 * *     /usr/bin/php /var/www/fleetforge/cron/archive_old_data.php >> /var/www/fleetforge/logs/cron.log 2>&1

# Cache expiry sweep — every hour
0 * * * *     /usr/bin/php /var/www/fleetforge/cron/cache_cleanup.php >> /var/www/fleetforge/logs/cron.log 2>&1
```

---

## Cron job reference

| Script | Schedule | Lock | Purpose |
|---|---|---|---|
| `backup_db.php` | Every 6 hours | `ff_cron_backup_db` | mysqldump → S3, tiered retention |
| `backup_storage.php` | Sunday 03:00 UTC | `ff_cron_backup_storage` | storage/uploads/ tarball → S3 |
| `invoice_generate_monthly.php` | 1st of month 06:00 | `ff_cron_invoice_generate_monthly` | Monthly invoice generation |
| `invoice_overdue.php` | Daily 07:00 | `ff_cron_invoice_overdue` | Mark overdue invoices |
| `late_fee_apply.php` | Daily 07:30 | `ff_cron_late_fee` | Apply late fees |
| `collections_auto_escalate.php` | Daily 08:00 | `ff_cron_collections` | Auto-escalate collections |
| `promise_to_pay_check.php` | Daily 09:00 | `ff_cron_ptp_check` | Check promise-to-pay dates |
| `samsara_sync.php` | Every 5 min | `ff_cron_samsara_sync` | Live GPS telemetry sync |
| `gps_mileage_sync.php` | Daily 02:00 | `ff_cron_gps_mileage` | Mileage reconciliation |
| `compliance_alerts.php` | Daily 06:30 | `ff_cron_compliance` | Compliance deadline alerts |
| `ai_anomaly_scan.php` | Daily 04:00 | `ff_cron_ai_anomaly` | AI-driven anomaly detection |
| `reconcile_counters.php` | Daily 02:00 | `ff_cron_reconcile_counters` | Counter drift detection — read-only |
| `stale_reservations.php` | Daily 04:30 | `ff_cron_stale_reservations` | Auto-cancel pending reservations past threshold |
| `archive_old_data.php` | 1st of month 04:00 | `ff_cron_archive_old_data` | Archive audit_log + notification_log |
| `cache_cleanup.php` | Hourly | `ff_cron_cache_cleanup` | Delete expired report_cache + ai_summaries |

---

## Integrity & cleanup settings (S-CRON-2)

| Setting key | Default | Description |
|---|---|---|
| `reservations.stale_after_days` | `14` | Days a pending reservation can sit before auto-cancel |
| `archive.audit_log_retention_days` | `365` | Days before audit_log rows are archived (raise to 2190 for 6-year CRA retention) |
| `archive.notification_log_retention_days` | `90` | Days before notification_log rows are archived |

Reconciliation log files: `logs/reconciliation/{YYYY-MM-DD}.log`
State file (spike detection): `logs/reconciliation/last_run.json`

---

## Backup env vars

| Variable | Default | Description |
|---|---|---|
| `BACKUP_DB_RETENTION_DAYS` | `90` | Days to retain DB backups (tier 3 cutoff) |
| `BACKUP_STORAGE_RETENTION_MONTHS` | `12` | Months to retain storage tarballs |

Retention tiers for DB backups:
- Days 0–7: keep **all** backups
- Days 8–30: keep **one per day** (newest)
- Days 31–N: keep **one per ISO week** (newest)
- Beyond N days: **delete**

---

## Restore script (admin-run, not cron)

```bash
# List available backups
php scripts/restore_db.php --list

# Restore to test DB (safe — never touches live DB by default)
php scripts/restore_db.php \
  --restore=backups/db/YYYY-MM-DD/HH/fleetforge_TIMESTAMP.sql.gz \
  --target-db=fleetforge_restore_test

# Dry-run: verify backup integrity without restoring
php scripts/restore_db.php --restore=<key> --dry-run

# Retention dry-run: see what would be cleaned up without deleting
php cron/backup_db.php --dry-run
```

See `docs/runbooks/restore_drill.md` for the full restore runbook.

---

## Local testing

Crontab is NOT set up locally (Herd dev environment). Run crons manually:

```bash
# Trigger a DB backup now
php /Users/avi/Documents/fleetforge/cron/backup_db.php

# Check what retention would delete (dry-run)
php /Users/avi/Documents/fleetforge/cron/backup_db.php --dry-run

# Trigger a storage tarball now
php /Users/avi/Documents/fleetforge/cron/backup_storage.php

# Verify the backup exists and list all backups
php /Users/avi/Documents/fleetforge/scripts/restore_db.php --list
```

Backup files land in `storage/backups/` when `STORAGE_DRIVER=local`.
