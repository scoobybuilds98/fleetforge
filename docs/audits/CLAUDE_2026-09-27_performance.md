# FleetForge — Production Performance Audit #2 (S-PERF-3)

**Date:** 2026-09-27 / 28
**Auditor:** Claude Code (Opus 5.5) — 11 dimension investigators, 41 findings, one adversarial
re-measuring verifier per finding (53 agents), then 8 implementation batches each with an independent
reviewer.
**Target:** live Mainland box `mainlandrentals.com` (Lightsail 2 vCPU / 3.8 GB, Ubuntu 22.04, nginx 1.18,
php-fpm 8.2, MySQL 8.0.46), prod HEAD = `28d3dc9` at audit time. **Prod was READ-ONLY** — every
server change below is an operator step (F103/F104). Predecessor: `CLAUDE_2026-08-15_performance.md`
(S-PERF-1/2).

---

## Headline

**The server is not slow.** Prod PHP floor is ~2–9 ms per request, the InnoDB buffer-pool hit ratio is
99.99%, load average ~0.2, and performance_schema shows no application query over 250 ms in 26 days.
What users actually feel comes from elsewhere:

1. **TCP slow-start after idle (~570 ms per click for the owner).** `net.ipv4.tcp_slow_start_after_idle=1`
   resets the congestion window after ~1 s idle, so every navigation to a 43–101 KB (gz) HTML page takes
   3 RTT instead of 1. Measured on a persistent TLS connection at 285 ms RTT: 0.6 s gap → 296 ms,
   3 s gap → 857 ms, fresh connection 846 ms. 744 of 757 real admin navigations in 14 days were in that
   size bucket; ~49% of them came from the owner on ~290 ms RTT links. One sysctl (F104 step 1).
2. **Sessions were purged after 24 minutes, not 8 hours (bug).** Ubuntu's `phpsessionclean` timer deletes
   session files using the *ini file* `session.gc_maxlifetime` (1440 s); the app only raised it via
   `ini_set()`, which the cleaner can't see. Any tab backgrounded 24–54 min lost its session. Observed on
   prod 24 Sep: three 401s after 81, 90 and 389 min idle. Exposure grows now that every staff poll skips
   hidden tabs.
3. **The Mainland S3 bucket expires LIVE objects 90 days after upload (data loss, urgent).** Both branding
   objects return `x-amz-expiration: … rule-id="expire-old-versions"` (logo expires 2026-11-24, favicon
   2026-12-24); S3 only emits that header for a *current-version* Expiration action. The intended rule was
   `NoncurrentVersionExpiration` (PREDEPLOY_CHECKLIST D4). Every uploaded document/PDF gets a delete marker
   on day 90. Northland's bucket does NOT have the problem. → **F103.**
4. Serial client waterfalls and a handful of correctness bugs found while measuring (below).

## Measured on prod (read-only, 2026-09-27)

| Item | Value |
|---|---|
| MySQL COMMIT | 1,220,464 in 26.4 d, avg 6.93 ms = 8,458 s — ≈ one write txn per unit per 5-min `samsara_sync` (160 × 288/day); cron-only, `row_lock_waits=0` |
| Prepared statements | 1.9 M execute (1,280 s) + 1.9 M prepare (542 s) |
| Buffer pool | 128 MB vs 150 MB data; hit ratio 99.99% (raise rejected) |
| Traffic (14 d) | 148 k requests; 70 k were the two 5-s badge polls removed by S-CHAT-REBUILD; ~46 k were SentryUptimeBot (GET / + full login render, 3 regions × 1/min); real users = 1 office IP + the owner |
| `samsara_sync` | 30.4 s per run, 160 units one by one, 7 dead trailers retried every tick |
| Page HTML | 257–347 KB raw / 54–69 KB gz, never cached |
| Session files | `gc_maxlifetime`=1440 for fpm + cli as the cleaner reads it; 0 files older than 60 min |

## What shipped in code (S-PERF-3, local-verified; deploy = F104)

| Batch | Change | Measured |
|---|---|---|
| **A** session | `require_auth_api()` releases the session lock after auth for **GET/HEAD** (`_ff_release_session_lock_for_read()`); dev-only tripwire logs any late `$_SESSION` write; `session.gc_maxlifetime = max(SESSION_LIFETIME, 86400)`; new static+live guard `_smoke_session_get_close.php` | 6-XHR dashboard burst 19.1 → 12.0 ms; count behind a PDF 30.1 → 4.1 ms; 225/225 GET responses byte-identical (users 54–58) |
| **B** health | `api/v1/health?strict=1` returns **503** when not ok (default stays 200 for the deploy gate); no session/cookie for anonymous callers (`FF_SKIP_ANON_SESSION`); skips migration checks when DB is down | DB-down response 2.82 → 1.42 s; `_smoke_health_strict.php` |
| **C** dashboard charts | month windows anchored on the 1st (fixes duplicated/skipped months on the 29th–31st); forecast/expiry as grouped queries; one cache read + one multi-row write; `payment_speed` measured by `paid_date` (was `updated_at` — a PDF regen moved the month) | cold 11-chart call 69 → 22 queries; identical output on every day ≤ 28 (8,764-day sweep); `_smoke_dashboard_month_windows.php` |
| **D** bell + theme | `updates_unread` is an EXISTS flag + per-request memo; **theme toggle bug**: `FF_Theme.set()` posted to a non-existent `/api/v1/account/theme` (404 on 17/17 prod attempts) → now `users/save_preference.php` | dashboard 13 → 11 queries; unread probe 5.78 → 0.38 ms at prod-shaped backlog; toggle verified in browser (1 POST, DB saved, survives reload) |
| **E** schema | `notifications`: drop `idx_deleted`/`idx_read`, widen `idx_user_unread (user_id,is_read,deleted_at)`, add `idx_user_live_recent`, `idx_entity` — one ALTER, idempotent, INPLACE/LOCK=NONE | 110 query shapes benched before/after at 75 k rows: 0 regressions |
| **F** record pages | Activity tab lazy on lease/equipment/customer/billing-cycle pages; **Activity timestamps showed +7 h** (UTC read as local) → `FF_parseUtc`; **equipment/show re-registered its watchers on every reload** (3× fetches) → moved to `init()`; deep-link tab fetches run in parallel with the record | audit/history at load 1 → 0; status-log fetches 3 → 1; `#invoices` start 553 → 166 ms |
| **G** equipment list | new `api/v1/equipment/units/kpis` (one GROUP BY) replaces 4 `per_page=1` calls; list no longer waits on tiles/templates; a failed tiles call no longer leaves the table loading forever; bulk actions refresh tiles | 16 → 3 queries; list ready 43.7 → 12.9 ms; `_smoke_equipment_units_kpis.php` 25/25 |
| **H** assets | `asset_v()` per-file `?v=` (mtime+size) — a deploy that doesn't touch a file no longer busts it (~72% of deploys change no asset); favicon served by `storage/logo?kind=favicon` (was a fresh S3 presign per render — never cacheable); logo drops `Pragma: no-cache`, adds ETag/304; dead login presigns removed; unused GeistMono preload removed from login pages | second navigation: logo/favicon transfer 0 B; login page 0 S3 URLs |
| **I** samsara cron | trailers read from ONE bulk trailer-stats call per tick (vehicles unchanged); missing trailers skipped and not stamped; audit row only on failure / incomplete fetch / hourly heartbeat; `cache_cleanup` + `notification_digest` skip no-op audit rows | mock API calls per run 167 → 4; gps.log lines per tick 327 → 6; DB effects byte-identical; `_smoke_samsara_sync_exec.php` 48/48 |

## Rejected / new dead ends (measured — do not re-audit)

| Idea | Why not |
|---|---|
| Merge the two auth `users` SELECTs | < 0.4 ms/request, ~1 s DB/day |
| DB over unix socket | 0.16 ms per connect on prod Linux |
| Idle backoff for pollers | overnight-tab pattern gone since S-CHAT-REBUILD; bell must stay live |
| Move per-page Alpine factories to external JS | no page crosses a slow-start flight after F104; version-skew risk |
| Embed record payload / dashboard data in HTML | BC users (most views) are low-RTT; adds a redaction surface |
| Narrow chart-cache wipe | ~0.4 cold builds/day avoided — made the cold path cheap instead (C) |
| binlog MINIMAL / disable binlog / relax `sync_binlog`, `flush_log_at_trx_commit` | imperceptible; binlog is the only PITR path |
| Raise `initcwnd` | needs a netplan route override on a DHCP route (lockout risk) |
| MySQL slow log | performance_schema already shows nothing > 250 ms |
| Emulated prepares | 7 `LIMIT ?` sites break |
| ETag/304 or SSE for polls; `read_and_close`; merging badges; APCu; buffer-pool raise; gzip level; minification; SVG sprite; bfcache; lazy ApexCharts | all measured as not worth it (see findings) |

## Deferred (real, owner decision or later)

- `samsara_location_history` retention (341 k rows, ~no pruning) — owner decision (data loss).
- PITR / backups and `app/legal/security.php` claims (RDS, ca-central-1, HSTS, 7-day PITR) that don't match the Lightsail box.
- Chrome diet (−16 KB gz/page), `asset_v()` sweep of page-level files, samsara write reduction, FF_Api CSRF refresh.
- `chart_revenue_forecast` sums rates without CAD conversion — check against the reporting policy.
- `invalidate_analytics_cache()` can lose a deadlock to a concurrent cold build (pre-existing; retry on 1213).
- With the DB down, a request carrying `ff_remember` dies in `auth_check_remember_me()` before the API handler exists (pre-existing).
- `api/v1/leases/amend_rate.php:448` writes Pacific `date()` into `lease_amendments.created_at` (S-UTC-STAMPS pattern).
- Stale pre-inbox unread notifications (~45 k) — F101 switch-over.

## Process incidents (disclosed)

- During the read-only audit an investigator ran `FLUSH STATUS` on the prod MySQL (2026-09-27 16:47:50 UTC).
  It resets status counters only (`Max_used_connections`, per-account aggregates) — no data or config
  change — but it was outside the read-only rule.
- During verification the lead's full smoke sweep included `tests/_smoke_golive_reset.php`, which wiped
  436,105 rows from the **dev** DB (never prod). It was reversed exactly from the local binlog
  (ROW/FULL) — 435,733 rows re-inserted, 353 updates reverted, per-table counts equal; 378,436 rows
  byte-identical to the 2026-09-23 prod snapshot on every column. Sweeps now hard-exclude that smoke.

---

## Results on production (deployed 2026-09-28 ~19:13 UTC, measured before and after on the live box)

| What | Before | After |
|---|---|---|
| A click after a 3 s pause (64 KB page, 285 ms RTT link, median of 6) | **888 ms** | **311 ms** |
| A click after a 10 s pause with a background poll | 884 ms | 286 ms |
| Brand-new connection (unchanged — TLS handshake) | 841 ms | 838 ms |
| Session lifetime as Ubuntu's cleaner reads it | 24 min | 24 h (app still enforces 8 h idle) |
| GPS sync per run (every 5 min) | 29–50 s | **2.5 s** (same 160 processed / 7 skipped / 0 failed) |
| Bell unread check, heaviest user (74 k-row table) | 23.4 ms (COUNT) | **0.03 ms** (EXISTS + index) |
| Updates list, first 20 | 42.3 ms (filesort) | **0.04 ms** (index, no sort) |
| Logo revalidation | full 48 KB + `Pragma: no-cache` | 304, 0 B |
| Favicon | fresh S3 URL every page (never cached) | same-origin, immutable |
| Login page S3 presigns | 2 | 1 (login logo — deferred) |
| `health?strict=1` | 200 always + Set-Cookie | 503 when unhealthy, no cookie |
| Theme toggle endpoint | 404 | saves (POST save_preference) |
| php-fpm reload | killed in-flight requests | drains up to 10 s; 600 s cap; 3 s slow log |
| Request latency visibility | none | `rt=` / `urt=` / `rtt=` on every nginx line |
| Live S3 files | expiring at 90 d (15 already hidden) | no expiry; 15 restored |
