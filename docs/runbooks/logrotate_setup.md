# FleetForge — Log Rotation Setup Runbook

**Purpose:** Configure and verify logrotate for all FleetForge application and system logs
on the production Lightsail instance. Without rotation, logs grow unbounded until disk fills
and the server stops accepting writes.

---

## 1. Why Log Rotation Is Required

Production web applications generate continuous log output — PHP-FPM, Nginx, MySQL, cron
jobs, and application-level scripts all write to disk without any built-in size cap. On a
Lightsail instance with a fixed disk (78 GB on this host), unmanaged logs routinely consume multiple
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

| Log file | Written by | Typical volume |
|---|---|---|
| `logs/gps.log` | `cron/samsara_sync.php` — runs every 5 minutes over every linked unit | **Highest by far.** Reached 422 MB / 5.0 M lines in ~3.5 months before rotation existed |
| `logs/cron.log` | All other cron jobs (backup_db, invoice_*, late_fee_apply, etc.) | ~700 KB |
| `logs/ai.log` | AI digest / anomaly-scan jobs | ~20 KB |
| `logs/mail.log` | Outbound SES / SMTP email delivery results | ~300 B |
| `logs/reconciliation/*.log` | Per-run reconciliation reports (one file per execution) | ~600 KB total |
| Any other `logs/*.log` | Future scripts that append to this directory | — |

> **`gps.log` is the one that matters.** It is written by the 5-minute Samsara sync and is
> the reason this runbook exists. It was omitted from v1.0.0 of this config, which is why the
> file was still unrotated months after the runbook was written. The `logs/*.log` glob below
> now covers it — and any future log — automatically.
>
> Its growth was also cut at the source: `samsara_sync.php` used to log one line per unit per
> tick even when the unit had not moved (~48,000 lines/day, 83% of the file). That line is now
> gated behind `FF_SAMSARA_VERBOSE_LOG=1` and off by default.

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
# FleetForge application log rotation
# Managed by runbook: docs/runbooks/logrotate_setup.md

# Glob, not an explicit list: gps.log was missed by the explicit list in v1.0.0
# and grew to 422 MB unrotated. A glob cannot be forgotten by a future script.
/var/www/fleetforge/logs/*.log
/var/www/fleetforge/logs/reconciliation/*.log
{
    # REQUIRED. logs/ is group-writable and owned by www-data, so logrotate
    # refuses to touch it without an explicit su directive:
    #   "parent directory has insecure permissions"
    su www-data www-data

    daily
    rotate 14
    maxsize 100M
    compress
    delaycompress
    missingok
    notifempty

    # MUST be www-data:www-data 0664 -- php-fpm and every cron job run as
    # www-data and append via file_put_contents(). v1.0.0 of this runbook said
    # `create 644 ubuntu ubuntu`, which would have handed www-data a file it
    # cannot write: logging would have stopped silently after the first
    # rotation, with no error anywhere.
    create 0664 www-data www-data
}
```

> **No `postrotate` here, deliberately.** The application appends with
> `file_put_contents(..., FILE_APPEND)`, which opens and closes the file on every write, so it
> picks up the new file on its own with no signal needed. Nginx and PHP-FPM *do* need SIGUSR1,
> but they are already handled by their own distro-shipped configs — see the warning below.

> ### Do not add nginx / php-fpm / mysql paths to this file
>
> v1.0.0 of this runbook listed `/var/log/nginx/access.log`, `/var/log/nginx/error.log`, and
> `/var/log/php8.2-fpm.log` in the FleetForge config. Those paths are **already covered** by
> the distro-shipped configs that ship with the packages:
>
> | Path | Already handled by | Its policy |
> |---|---|---|
> | `/var/log/nginx/*.log` | `/etc/logrotate.d/nginx` | daily, rotate 14, `create 0640 www-data adm` |
> | `/var/log/php8.2-fpm.log` | `/etc/logrotate.d/php8.2-fpm` | weekly, rotate 12, reopens via `php8.2-fpm-reopenlogs` |
> | `/var/log/mysql/error.log` | `/etc/logrotate.d/mysql-server` | shipped with the mysql-server package |
>
> Listing them twice makes logrotate abort that run with
> `duplicate log entry for /var/log/nginx/access.log`, which would have silently disabled
> rotation for the FleetForge logs in the same config. This file covers **application logs
> only**.

**Config option explanations:**

| Option | Effect |
|---|---|
| `daily` | Rotate once per day (triggered by `/etc/cron.daily/logrotate`) |
| `rotate 14` | Keep 14 days of rotated archives before deleting the oldest |
| `compress` | Compress rotated files with gzip (`.gz` extension) |
| `delaycompress` | Do not compress the most-recently rotated file — leaves it readable for one day in case a process still has it open |
| `missingok` | Do not error if a log file is absent (e.g., cron has not run yet) |
| `notifempty` | Skip rotation if the log file is empty — avoids accumulating zero-byte archives |
| `create 0664 www-data www-data` | After rotation, create a fresh empty log file owned by `www-data:www-data` with permissions `0664`. **This must match the user php-fpm and cron run as**, or appends fail silently |
| `su www-data www-data` | Rotate as `www-data`. Required because `logs/` is group-writable; without it logrotate refuses the directory as insecure |
| `maxsize 100M` | Rotate early if a file hits 100 MB before the daily run — a backstop against a runaway loop filling the disk between rotations |

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
At typical log volume, a 20 % buffer on this 78 GB disk represents ~15 GB — many months
of runway even if logrotate stopped working entirely.

---

*Runbook version: 1.1.0 — Last updated: 2026-08-26*

**v1.1.0 changes** — corrections found when the config was finally applied to production:
1. Added `logs/gps.log` (and `ai.log`) via a `*.log` glob. The explicit v1.0.0 list omitted gps.log, the single largest log on the box at 422 MB.
2. Fixed `create 644 ubuntu ubuntu` → `create 0664 www-data www-data`. The old value would have stopped all application logging after the first rotation.
3. Added the required `su www-data www-data` directive.
4. Removed the nginx / php-fpm paths, which duplicate distro configs and would have aborted the run.
5. `rotate 30` → `rotate 14`, plus a `maxsize 100M` backstop.
