# Runbook — Deploy (`bin/deploy.sh`) + Rollback

**Session:** S-DEPLOY-SAFETY-1 (2026-06-05) · **Decisions:** D-DEPLOY-1..4
**Companion:** `docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md` (E-DEPLOY-RUNBOOK), `docs/runbooks/restore_drill.md`, `docs/runbooks/staging_setup.md`

---

## What it is

`bin/deploy.sh` is the ONE atomic, gated, **abort-on-failure** deploy command. It runs `set -euo pipefail` and aborts on any failed step — it never serves traffic on a half-applied deploy. It replaces ad-hoc `git pull`-only deploys, which is how the 2026-06-05 schema-lag outage reached production undetected.

### The 10 steps

1. **clean-tree precheck** — abort if tracked files have uncommitted changes (untracked runtime files are ignored).
2. **git fetch origin**.
3. **show incoming** `git log HEAD..origin/main` + **interactive y/N confirm** (gates *which commits ship* — stops blind-shipping unfinished work). `--yes` skips it.
4. **git pull --ff-only** — abort on non-fast-forward / divergence.
5. **DB backup** via the existing `cron/backup_db.php` (the pre-migrate rollback point — reused, never hand-rolled).
6. **composer install --no-dev** (retained, idempotent).
7. **migrate --apply**, then **`migrate --assert-applied`** — abort if any migration is still PENDING (a schema lag).
8. **systemctl reload php8.2-fpm** (clears opcache; restart fallback if reload leaves it inactive).
9. **health gate** — curl `/api/v1/health` and require HTTP 200 + `db:true` + `migrations.pending == 0` + `schema.ok == true`; curl `/auth/login` → 200; scan the php error log for fresh `PHP Fatal`/`Uncaught` lines. Abort on any miss.
10. **deploy-log** — append `from-HEAD → to-HEAD`, migrations shipped, operator, timestamp to `logs/deploy.log`.

> **Why a confirm AND always-run-all-steps?** The 2026-05-20 E-DEPLOY-RUNBOOK lesson forbids *skipping a step* (an operator skipped `migrate` + reload → site-wide 500s). The step-3 confirm is orthogonal: it gates *which commits ship*, never *which steps run*. Both are honored.

---

## Usage

```sh
# Production (interactive — review incoming commits, confirm to ship):
sudo /var/www/fleetforge/bin/deploy.sh

# Skip the ship confirmation (unattended / automation):
sudo /var/www/fleetforge/bin/deploy.sh --yes

# Preview the full sequence, execute NOTHING destructive (no root needed):
/var/www/fleetforge/bin/deploy.sh --dry-run
```

### Staging / parameterization (D-DEPLOY-3)

Every path / URL / service name is env-overridable, so the same script serves staging and prod:

| Env var | Default (prod) |
|---|---|
| `FF_DEPLOY_REPO_DIR` | `/var/www/fleetforge` |
| `FF_DEPLOY_WEB_USER` | `www-data` |
| `FF_DEPLOY_FPM_UNIT` | `php8.2-fpm` |
| `FF_DEPLOY_BASE_URL` | `https://mainlandrentals.com/fleetforge` |
| `FF_DEPLOY_HEALTH_URL` | `${BASE_URL}/api/v1/health` |
| `FF_DEPLOY_LOGIN_URL` | `${BASE_URL}/auth/login` |
| `FF_DEPLOY_ERROR_LOG` | `/var/log/php8.2-fpm.log` |
| `FF_DEPLOY_LOG` | `${REPO_DIR}/logs/deploy.log` |

```sh
# Staging example:
FF_DEPLOY_BASE_URL=https://staging.mainlandrentals.com/fleetforge sudo -E bin/deploy.sh
```

> **⚠ Validate on STAGING before the first production run.** This script cannot be end-to-end tested from a dev box (no `systemctl` / `sudo` / prod paths). `bash -n` + `--dry-run` are the only dev-side checks. Per `staging_setup.md`, run it on staging first so the team has muscle memory and a broken deploy fails on staging, not in production under pressure.

---

## The health migrate-state assertion (D-DEPLOY-2)

`api/v1/health.php` reports (publicly — counts/booleans only):

```json
{ "data": { "status": "ok|degraded", "db": true,
            "migrations": { "pending": 0, "ok": true },
            "schema": { "ok": true }, "time": "..." } }
```

- `migrations.pending > 0` **or** a missing critical table (`users`, `user_roles`, `role_permission_overrides`, `user_permission_overrides`, `settings`, `schema_migrations`, `customers`, `leases`, `invoices`) → `status: "degraded"`.
- A **schema lag is now a one-curl check** any monitor or the deploy gate can read. Authenticated callers additionally get `schema.missing[]` (the public payload hides which table is gone, to avoid fingerprinting).
- The **default** response is HTTP **200 in every state** (`ok` and `degraded`). Step 9 above (`curl -fsS`) depends on that: it needs a parseable 200 body so it can print *why* the gate failed. Never change the default status code.

### Uptime monitor (strict health) — S-PERF-3

`GET /api/v1/health?strict=1` returns the **same body**, but HTTP **503** whenever `status != "ok"` (DB down, pending migration, missing critical table, disk < 0.5 GB). Only the exact value `strict=1` switches it on.

- **Strict mode is for uptime monitors only.** Sentry uptime treats non-2xx as down and never reads the JSON. The previous monitor polled `/` → the login page, which still renders 200 with MySQL down, so the recurring `[2002] Connection refused` outage never alerted.
- **Never** point the deploy gate (`FF_DEPLOY_HEALTH_URL`) or a load balancer at `?strict=1`. The gate must keep getting a 200 plus the JSON it parses.
- Expect a strict alert during a deploy window with pending migrations. That is intended. The deploy's maintenance window also answers 503 on every URL, health included (`public/index.php`, strict or not), so a slow deploy can page too. Sentry needs consecutive failures before it opens an issue.
- When the DB is down, the endpoint skips the migration-state check (it would only repeat the connect-retry loop). A DB-down answer takes about one retry cycle (~1.4 s locally), not two.
- Anonymous callers (no `ff_session` / `ff_remember` cookie) get **no session file and no Set-Cookie**. Authenticated callers are unchanged and still get `version` / `disk` / `schema.missing`.

Operator setup (per deployment; Mainland **and** Northland):

```sh
curl -s -w '\n%{http_code}\n' 'https://mainlandrentals.com/fleetforge/api/v1/health?strict=1'   # expect 200 + "status":"ok"
```

Then, in Sentry: create an uptime monitor for that URL and disable the auto-detected `/` monitor. The next day, `access.log` should show ~1,440 `health?strict=1` hits and no more `/` + `/auth/login` pairs from `SentryUptimeBot`.

---

## Server config the repo cannot carry: PHP session GC (S-PERF-3 / OP-2)

`config/app.php` raises `session.gc_maxlifetime` with `ini_set()` (to `max(SESSION_LIFETIME, 86400)`, which covers the 8 h admin and 24 h portal sessions). **On Debian/Ubuntu that is not enough.** The distro's `phpsessionclean.timer` (`:09` and `:39`) runs `/usr/lib/php/sessionclean`, which reads `gc_maxlifetime` from `php.ini` plus `conf.d` only. It never sees the app's runtime `ini_set()`. With the stock `1440` it deletes every session after 24–54 min idle. Users see silent logouts, and saves from a tab opened before a remember-me restore fail with 403 `CSRF_INVALID`.

Every server (and any rebuild) must carry:

```sh
printf '%s\n' '; FleetForge: phpsessionclean reads THIS ini, not config/app.php ini_set. Must be >= SESSION_LIFETIME and portal 24h.' 'session.gc_maxlifetime = 86400' \
 | sudo tee /etc/php/8.2/fpm/conf.d/99-fleetforge-session.ini
PHP_INI_SCAN_DIR=/etc/php/8.2/fpm/conf.d/ php8.2 -c /etc/php/8.2/fpm/php.ini -r 'echo ini_get("session.gc_maxlifetime"),"\n";'   # 86400
# after >70 min:
sudo find /var/lib/php/sessions -name 'sess_*' -cmin +60 | wc -l     # > 0
# rollback: sudo rm /etc/php/8.2/fpm/conf.d/99-fleetforge-session.ini
```

No FPM reload is needed, because the cleaner spawns a fresh `php` on each run. This does **not** lengthen any login. The in-app inactivity checks (admin 8 h in `includes/auth.php`, portal 24 h in `app/portal/includes/auth.php`) still sign people out on time. The setting only stops the OS deleting a still-valid session file. If `SESSION_LIFETIME` is ever raised above 86400, raise this ini to match.

---

## Rollback (D-DEPLOY-4)

If the deploy aborts mid-way, or post-deploy verification fails:

### Code-only rollback (covers most deploys — migrations are usually additive)

```sh
cd /var/www/fleetforge
sudo -u www-data git reset --hard <BEFORE_SHA>     # the HEAD-pre printed by deploy.sh / deploy.log
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
sudo systemctl reload php8.2-fpm
curl -s https://mainlandrentals.com/fleetforge/api/v1/health   # expect status:ok
```

An **additive** migration (new table / new nullable column) left in place after a code revert is harmless — the reverted code simply ignores it. Reverting the code is sufficient.

### Full restore (only for a DESTRUCTIVE migration — dropped/renamed column, data transform)

The pre-deploy dump from **step 5** is the rollback point. Restore it per `docs/runbooks/restore_drill.md`:

```sh
php scripts/restore_db.php --list                                   # find the pre-deploy backup key (newest before the deploy)
php scripts/restore_db.php --restore=backups/db/YYYY-MM-DD/HH/fleetforge_TIMESTAMP.sql.gz --dry-run   # verify it downloads + decompresses
# then restore to the live DB per restore_drill.md (confirm the target-db carefully)
```

Then code-revert as above. After any rollback, re-run the health gate and confirm `status:ok` + `migrations.pending:0` before declaring recovery.

---

## See also

- `docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md` — ITEM E-DEPLOY-RUNBOOK (the 2026-05-20 incident this codifies)
- `docs/runbooks/restore_drill.md` — DB restore drill (the rollback path)
- `docs/runbooks/staging_setup.md` — staging provisioning + why deploys validate there first
- `docs/runbooks/nginx_config.md` — prod nginx / php-fpm layout
