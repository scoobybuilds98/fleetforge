# FleetForge — Log Rotation Setup Runbook

**Purpose:** Configure and verify logrotate for all FleetForge application and system logs
on the production Lightsail instance. Without rotation, logs grow unbounded until disk fills
and the server stops accepting writes.

---

## 1. Why Log Rotation Is Required

Production web applications generate continuous log output — PHP-FPM, Nginx, MySQL, cron
jobs, and application-level scripts all write to disk without any built-in size cap. On a
Lightsail instance with a fixed disk (160 GB), unmanaged logs routinely consume multiple
gigabytes per month at production traffic levels.

When disk usage reaches 100 %:

- Nginx stops accepting new connections (cannot write access.log)
- PHP-FPM halts request processing (cannot write error.log)
- MySQL may crash or corrupt InnoDB tables mid-write
- Cron jobs silently fail if they cannot open their log file for appending
- SSH logins may fail because /var/log/auth.log cannot be written

The result is a full production outage that is entirely preventable. Logrotate runs daily via
the system cron, compresses and cycles old log files, and sends the right reload signals so
running processes open fresh file handles on the new file — no restart required.

---

## 2. FleetForge Application Logs to Rotate

All application logs live under the `logs/` directory in the project root
(`/var/www/fleetforge/logs/`).

| Log file | Written by |
|---|---|
| `logs/cron.log` | All cron jobs (backup_db, send_invoices, reconciliation, etc.) |
| `logs/mail.log` | Outbound SES / SMTP email delivery results |
| `logs/error.log` | Application-level PHP errors caught by the error handler |
| `logs/reconciliation/*.log` | Per-run reconciliation reports (one file per execution) |
| Any other `logs/` contents | Future scripts that append to this directory |

The `logs/reconciliation/` glob pattern (`logs/reconciliation/*.log`) is included in the
logrotate config below so new per-run files are automatically covered without updating the
config.

---

## 3. System Logs (Lightsail)

The following system-level log files are written by root-owned daemons. They require the
`postrotate` directive to send reload signals so each process closes its old file handle and
opens the rotated file.

| Log file | Written by |
|---|---|
| `/var/log/nginx/access.log` | Nginx HTTP access log |
| `/var/log/nginx/error.log` | Nginx error log |
| `/var/log/php8.2-fpm.log` | PHP-FPM master process log (worker errors, pool reloads) |
| `/var/log/mysql/error.log` | MySQL / MariaDB error log |

> **Note:** MySQL error log rotation is typically handled by the `mysql-server` package's
> own logrotate config at `/etc/logrotate.d/mysql-server`. Verify it exists before adding
> a duplicate entry. If it is absent or does not compress, add MySQL to the FleetForge
> config snippet below.

---

## 4. Logrotate Config Snippet

Create the file `/etc/logrotate.d/fleetforge` with the following content. This is
copy-pastable as-is; adjust `/var/www/fleetforge` if the project root differs.

```
# /etc/logrotate.d/fleetforge
# FleetForge application and system log rotation
# Managed by runbook: docs/runbooks/logrotate_setup.md

/var/www/fleetforge/logs/cron.log
/var/www/fleetforge/logs/mail.log
/var/www/fleetforge/logs/error.log
/var/www/fleetforge/logs/reconciliation/*.log
/var/log/nginx/access.log
/var/log/nginx/error.log
/var/log/php8.2-fpm.log
{
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 644 ubuntu ubuntu
    sharedscripts

    postrotate
        # Tell PHP-FPM to reopen its log file handles after rotation.
        # Without this, PHP-FPM keeps writing to the rotated (now renamed) file.
        if [ -f /run/php/php8.2-fpm.pid ]; then
            kill -USR1 $(cat /run/php/php8.2-fpm.pid)
        fi

        # Tell Nginx to reopen its log file handles.
        if [ -f /var/run/nginx.pid ]; then
            kill -USR1 $(cat /var/run/nginx.pid)
        fi
    endscript
}
```

**Config option explanations:**

| Option | Effect |
|---|---|
| `daily` | Rotate once per day (triggered by `/etc/cron.daily/logrotate`) |
| `rotate 30` | Keep 30 days of rotated archives before deleting the oldest |
| `compress` | Compress rotated files with gzip (`.gz` extension) |
| `delaycompress` | Do not compress the most-recently rotated file — leaves it readable for one day in case a process still has it open |
| `missingok` | Do not error if a log file is absent (e.g., cron has not run yet) |
| `notifempty` | Skip rotation if the log file is empty — avoids accumulating zero-byte archives |
| `create 644 ubuntu ubuntu` | After rotation, create a fresh empty log file owned by `ubuntu:ubuntu` with permissions `644` so application scripts can append without sudo |
| `sharedscripts` | Run `postrotate` once for all matched files, not once per file |
| `postrotate` / `kill -USR1` | SIGUSR1 tells PHP-FPM and Nginx to reopen their log file handles on the new (empty) file without dropping connections |

> **Why `delaycompress`?** If a long-running PHP-FPM worker still has the previous log
> file open at rotation time, it will keep writing to it for up to one request cycle.
> Delaying compression by one day means the file is still human-readable if you need to
> inspect it before the next rotation compresses it.

---

## 5. Verification Commands

**Dry-run (no files changed):**

```bash
sudo logrotate -d /etc/logrotate.d/fleetforge
```

Logrotate prints what it would do for each log file without actually rotating anything.
Look for lines like `rotating log ... forcing rotation` or `log does not need rotating`.
Any `error:` lines indicate a config syntax problem or a permission issue.

**Force rotation immediately (for testing):**

```bash
sudo logrotate -f /etc/logrotate.d/fleetforge
```

Forces rotation regardless of whether the file has grown since last rotation. Use this
after initial setup to confirm that compressed archives are created, file handles are
reopened, and new empty log files appear with the correct ownership.

**Check the logrotate state file:**

```bash
cat /var/lib/logrotate/status
```

Each managed log file has a line showing the date of its last rotation. Verify that the
timestamp updates after a forced run. If a log file is missing from this file, logrotate
has never processed it — re-check the path in the config.

**Confirm PHP-FPM is writing to the new file:**

```bash
ls -la /var/www/fleetforge/logs/
ls -la /var/log/php8.2-fpm.log*
```

After a forced rotation you should see `php8.2-fpm.log` (new, empty or small) and
`php8.2-fpm.log.1` (yesterday's log, uncompressed due to `delaycompress`) and
`php8.2-fpm.log.2.gz` (day before, compressed).

**Confirm Nginx is writing to the new file:**

```bash
ls -la /var/log/nginx/
```

Same pattern: `access.log` (new), `access.log.1` (yesterday), `access.log.2.gz`, etc.

---

## 6. Disk Space Monitoring

**Manual check:**

```bash
df -h
```

The root partition (`/`) should show at least 20 % free space at all times. If free space
drops below 20 %, investigate which directory is growing fastest:

```bash
du -sh /var/www/fleetforge/logs/*
du -sh /var/log/*
sudo du -sh /var/lib/mysql/
```

**Automated low-disk alerting — two options:**

Option A — AWS CloudWatch alarm (recommended for production Lightsail):
1. In the Lightsail console, navigate to the instance → Monitoring tab.
2. Create a metric alarm on `DiskSpaceUtilization` for the root partition.
3. Set threshold: alarm when disk usage exceeds 80 %.
4. Notify via SNS email or the existing FleetForge SNS topic.

Option B — Application-level alert via Sentry:
Add the following to a daily health-check cron job (e.g., `cron/health_check.php`):

```php
$diskFreePercent = round(disk_free_space('/') / disk_total_space('/') * 100, 1);
if ($diskFreePercent < 20) {
    // Captures a Sentry message so the on-call engineer is notified immediately.
    \Sentry\captureMessage(
        "Low disk space on production: {$diskFreePercent}% free",
        \Sentry\Severity::warning()
    );
}
```

The 20 % threshold gives enough headroom to diagnose and remediate before a complete fill.
At typical log volume, a 20 % buffer on a 160 GB disk represents ~32 GB — roughly 30+ days
of runway even if logrotate stopped working entirely.

---

*Runbook version: 1.0.0 — Last updated: 2026-05-02*
