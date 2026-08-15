# FleetForge — Production Load-Performance Audit

**Date:** 2026-08-15
**Auditor:** Claude Code (Opus 5) — 6 parallel investigators + 47 adversarial verifiers
**Target:** live production box `mainlandrentals.com` (AWS Lightsail, 2 vCPU / 3.8 GB)
**Method:** read-only inspection of the live server config + static analysis of the repo.
Every finding below was independently re-verified by a second agent instructed to refute it.
**Result:** 47 candidate findings → **35 confirmed, 12 refuted**. The 12 refuted are listed at the end
so they are not re-investigated later.

> **Production was treated as READ-ONLY throughout.** Nothing on the server was modified. The nginx /
> php-fpm / opcache changes in Tier 1 are prepared for an operator to apply.

---

## Measured on the live box (2026-08-15)

| Setting | Value | Note |
|---|---|---|
| nginx | 1.18.0, `--with-http_v2_module` | HTTP/2 available but **not enabled** |
| `gzip` | `on`, but `gzip_types` **commented out** | ⇒ only `text/html` compressed |
| `/assets/` Cache-Control | **three conflicting headers** | `31536000` + `3600, must-revalidate` + `immutable` |
| php-fpm | `pm.max_children=5`, workers ~37 MB RSS | 5 concurrent PHP requests, app-wide ceiling |
| opcache | 128 MB, 10000 files, `interned_strings_buffer=8`, JIT **off** | 5,283 PHP files on disk |
| MySQL | 8.0.46, buffer pool 128 MB, DB 97 MB | `slow_query_log=OFF` |
| RAM | 3,836 MB total, ~2,550 MB available | room for ~20 workers |

Live confirmation that compression is off:

```
$ curl -sSI -H 'Accept-Encoding: gzip' https://mainlandrentals.com/assets/css/app.css
Content-Type: text/css
Content-Length: 415682          <-- no Content-Encoding
Cache-Control: max-age=31536000
Cache-Control: max-age=3600, must-revalidate
Cache-Control: public, immutable
```

Measured over the real internet from a developer machine: `app.css` **2.25 s**,
`apexcharts.min.js` **2.28 s**, `app.js` **1.89 s**.

---

# FleetForge Load-Time Remediation Plan

**Scope:** admin app load performance. Production is a single Lightsail box (2 vCPU / 3.8 GB) running
nginx 1.18 + php-fpm 8.2 (`pm.max_children=5`) + MySQL 8.0. Everything here is ranked by
*user-facing win ÷ (effort × risk)*.

**One-line summary:** the top three items are nginx config edits that recover ~950 KB per cold page
load and cost about ten minutes. Everything in the application layer is worth 1–2 orders of magnitude
less, with two exceptions (the ApexCharts gate and the AI-stream session lock) that are worth doing.

---

## Tier 1 — Infra config. Minutes to apply. Huge win.

These are the only changes in this document that a user will *feel* on a cold load. Do them first,
measure, then decide whether Tier 2 is still worth the time.

### 1.1 Turn on `gzip_types` — recovers ~957 KB per cold page load

**What it is.** `gzip on;` is set but `gzip_types` is commented out. nginx's default `gzip_types` is
`text/html` only, so every CSS, JS, JSON and SVG response ships raw. Confirmed live: `app.css` returns
`Content-Length: 415682` with no `Content-Encoding`.

**Measured win** (raw → gzip, from the lead's own measurements):

| Asset | Raw | Gzip | Saved |
|---|---:|---:|---:|
| apexcharts.min.js | 522,342 | 133,877 | 388,465 |
| app.css | 415,682 | 90,426 | 325,256 |
| app.js | 231,541 | 53,808 | 177,733 |
| alpine.min.js | 46,346 | 16,705 | 29,641 |
| animations.css | 11,287 | 3,185 | 8,102 |
| ff-chart-theme.js | 10,670 | 3,880 | 6,790 |
| ff-animations.js | 8,907 | 2,847 | 6,060 |
| **Total** | **1,246,775** | **304,728** | **942,047** |

Plus every JSON API response (the badge polls, the KPI calls, the ~7 KB reservations KPI payloads) and
the inline SVG sprite. Call it **~950 KB per cold admin page load.**

**Exact change** — in the server or http block:

```nginx
gzip on;
gzip_vary on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_proxied any;
gzip_types
    text/plain text/css text/xml
    application/javascript application/x-javascript text/javascript
    application/json application/ld+json
    image/svg+xml application/rss+xml font/ttf font/otf;
```

Do **not** add `font/woff2` — woff2 is already compressed; gzipping it burns CPU for ~0 bytes.

**Effort:** trivial (one config block + `nginx -t && systemctl reload nginx`).
**Risk:** low. `gzip_vary on` is required so any intermediate cache keys on `Accept-Encoding`.

**Verify:**
```bash
curl -s -I -H 'Accept-Encoding: gzip' https://<host>/assets/css/app.css | grep -i -E 'content-(encoding|length)'
# expect: content-encoding: gzip  /  content-length: ~90426
curl -s -I -H 'Accept-Encoding: gzip' https://<host>/api/v1/notifications/count.php | grep -i content-encoding
```

---

### 1.2 Enable HTTP/2 — removes the 6-connection serialization on the critical path

**What it is.** `listen 443 ssl;` with no `http2`, though the module is compiled in. Under HTTP/1.1 the
browser opens at most 6 connections per origin, so the 5 render-blocking first-party assets, 2 preloaded
woff2 fonts, the logo and favicon all queue. It also caps the badge/KPI XHR bursts at 6 in flight —
the reservations page fires ~86 XHRs and the equipment page 6.

**Estimated win.** No single byte saved; ~1–3 RTT removed from the critical path (roughly 40–150 ms on
broadband, 150–450 ms on 4G) plus header compression on the poll traffic. I cannot measure this without
prod access, so treat it as an estimate, not a measurement.

**Exact change:**
```nginx
listen 443 ssl http2;
listen [::]:443 ssl http2;
```

**Effort:** trivial. **Risk:** low — nginx 1.18 supports `http2` on the `listen` directive directly.
Reload, don't restart. If TLS terminates anywhere upstream, verify there first.

**Verify:**
```bash
curl -s -I --http2 https://<host>/ | head -1   # expect HTTP/2 200
```
Then in DevTools → Network, the Protocol column should read `h2` for all assets.

---

### 1.3 Fix the three conflicting `Cache-Control` headers on `/assets/`

**What it is.** The `/assets/` location emits `max-age=31536000`, `max-age=3600, must-revalidate`, and
`public, immutable` as three separate headers. Which one a given client honours is undefined; a client
that picks `must-revalidate, max-age=3600` re-validates the whole ~1.25 MB bundle hourly.

**Estimated win.** For clients that currently land on the 3600 header: eliminates an hourly
revalidation round trip per asset (7 conditional GETs). For clients landing on `immutable`: nothing.
Unquantifiable without knowing the header ordering nginx actually emits — but it is free to fix and
removes a correctness landmine.

**Exact change** — collapse to one directive inside the `/assets/` location:
```nginx
location /assets/ {
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    access_log off;
}
```
Remove the other two `add_header Cache-Control` / `expires` lines. Note `add_header` in a nested block
**replaces** inherited headers, so check parent blocks too.

**Caveat that makes this safe:** `FF_ASSET_VERSION` (config/app.php:110-125) is the git HEAD short hash
and is appended to asset URLs, so `immutable` is correct — a deploy changes the URL. **Exception:**
`includes/footer.php:179` loads `apexcharts.min.js` with **no `?v=`**. Under `immutable` that file can
never be updated without renaming it. Add the cache-buster to line 179 in the same change.

**Effort:** trivial. **Risk:** low.

**Verify:** `curl -s -I https://<host>/assets/css/app.css | grep -ci cache-control` → must be `1`.

---

### 1.4 Add `request_terminate_timeout` to the php-fpm pool — availability guardrail

**What it is.** `api/v1/ai/chat.php` can occupy a worker for up to 2100 s in the pathological retry
case (5 iterations × (3 × 120 s curl + 2 × 30 s `Retry-After` sleep)), and produces no output, so PHP
never detects a client abort — closing the tab does not free the worker. `max_execution_time` cannot
fire during `curl_exec`. With `pm.max_children=5`, two of these is a site-wide outage.

**Exact change** — in the pool conf:
```ini
request_terminate_timeout = 180
```

**Effort:** trivial. **Risk:** low-to-medium — any legitimately long request (a large PDF batch, a big
export) now dies at 180 s with a 502. **Check your longest legitimate synchronous endpoint before
setting this**; if batch invoice generation or a report export can exceed 180 s, either raise the value
or give those paths their own location block with a higher `fastcgi_read_timeout` and move them to a
separate pool.

**Verify:** trip it deliberately on staging with a `sleep(200)` script; confirm a 502 at ~180 s and that
the worker returns to the pool (`systemctl status php8.2-fpm` / pool status page).

---

## Tier 2 — Application changes. Hours. Large win.

### 2.1 Gate ApexCharts (+ ff-chart-theme) behind a per-page flag — 533 KB off 155 of 163 pages

**What it is.** `includes/footer.php:179` loads `apexcharts.min.js` (522,342 B, **no `defer`**)
unconditionally, followed by `ff-chart-theme.js` (10,670 B). 155 of the 163 admin pages that render
footer.php never construct a chart. The tag sits at the end of `<body>` so it does not delay first
paint, but it *does* delay `DOMContentLoaded` and therefore the deferred Alpine boot that un-cloaks
`x-cloak` content — which is the user-visible moment on Alpine-driven pages.

**Measured win.**
- Cold cache: 533,012 B removed (133,877 B if Tier 1.1 shipped first — **do not add these two savings
  together**).
- Warm cache, every navigation: 8.1 ms parse+compile + 11.0 ms top-level execute = ~19 ms on an
  M-series Mac; realistically 40–80 ms on a typical office laptop, trending toward the execute-only
  figure once V8's code cache warms.

**Exact change.** Introduce a `$ffNeedsCharts` flag set before `header.php`:

```php
// includes/footer.php — replace the unconditional tags
<?php if (!empty($ffNeedsCharts)): ?>
<script src="<?= asset_url('assets/js/apexcharts.min.js') ?>" defer></script>
<script src="<?= asset_url('assets/js/ff-chart-theme.js') ?>" defer></script>
<?php endif; ?>
```

**Three things that will break it if skipped:**
1. `includes/footer_embed.php:25` is a **second** unconditional emit site — gate it identically.
2. There are **8** charting pages, not 7. `app/admin/ai/index.php` must set the flag, because
   `includes/partials/ai-report-generator.php:441` constructs `ApexCharts` with no availability guard.
3. Keep the emit position (before `app.js`) so `FF_CHART_THEME` and `patchApexChartsForResponsive`
   still see the constructor. If you add `defer` to apexcharts you must add it to `ff-chart-theme.js`
   and `app.js` too — defer preserves relative order, mixing deferred and non-deferred does not.

Precedent already in the repo: Leaflet is loaded per-page at `app/admin/tracking/index.php:54-55` and
`app/admin/equipment/show.php:2404-2405`.

**Effort:** medium (10 files). **Risk:** low, but the failure mode is a silently blank chart, so
click through all 8 chart pages plus the invoice embed iframe before shipping.

**Verify:** `grep -rn "new ApexCharts" app/ includes/` → every hit's page must set the flag. Then load
a non-chart page and confirm no apexcharts request in the Network tab and no console error.

---

### 2.2 Guard the topbar pollers against double-init — halves all background API traffic

**What it is.** Alpine 3.15 auto-calls `init()` from `x-data`, and `includes/topbar.php:493` and `:550`
*also* call `init()` from `x-init`. Both pollers register their `setInterval` twice and leak one.

**Measured win.** Per open admin tab: **50 API req/min → 25**, and 6 on-load XHRs → 3. Breakdown of the
waste: chat/unread/count.php 24/min (should be 12), messenger/unread.php 24/min (should be 12),
notifications/count.php 2/min (should be 1). Each request is a full PHP bootstrap (28 files / 494 KB),
2–3 DB queries, and — critically — an exclusive flock on the user's session file.

**Exact change** — pick one of two shapes, not both:

```html
<!-- includes/topbar.php:492-493 — drop init() from x-init, keep the seed -->
<div x-data="FF_ChatHubBadge()">
<!-- :549-550 -->
<div x-data="FF_Notifications()" x-init="unreadCount = <?= (int)$_unreadCount ?>; _initialized = true;">
```
or, defensively, at the top of both `init()`s in `app.js`:
```js
if (this._timer) return; this._timer = setInterval(...);
```

Setting `_initialized = true` alongside the seed matters — without it the first 60 s poll won't ding.

**Effort:** trivial. **Risk:** low.
**Verify:** DevTools Network, filter `count.php`, watch 60 s — expect 12 messenger + 12 chat + 1 bell,
not 24/24/2.

---

### 2.3 Pause pollers on `document.hidden` — near-zero background-tab load

**What it is.** The 5 s chat/messenger poll runs regardless of tab visibility. Most admin tabs sit
backgrounded. Browsers throttle background timers to ~1/min after 5 minutes, so this is not the 3×
multiplier it looks like, but the first 5 minutes of every backgrounded tab is full-rate.

**Measured win.** Combined with 2.2: 48 req/min/tab → ~0 for backgrounded tabs, 24 for the visible one.

**Exact change** — `public/assets/js/app.js:2862`, copying the pattern already at app.js:2926-2929:
```js
this._timer = setInterval(() => {
    if (document.hidden) return;
    this.fetchUnread();
}, 5000);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) this.fetchUnread();   // catch-up so the badge isn't stale on refocus
});
```
The `visibilitychange` catch-up is not optional — without it the badge shows a stale count for up to
5 s (or 60 s for the bell) after refocus.

**Effort:** trivial. **Risk:** low.

---

### 2.4 `session_write_close()` on the AI streaming endpoints — the biggest concurrency win in the app

**What it is.** PHP's default `files` session handler holds an exclusive lock for the whole request, and
nothing in the app calls `session_write_close()` except `app/auth/logout.php:112`. During a single AI
answer (5–25 s typical, 120 s per-call ceiling in `ClaudeClient.php:40`) the user's session file is
locked the entire time. The ungated badge poller then fires 2 requests every 5 s, each blocking inside
`session_start()` while pinning a php-fpm worker.

**Why this outranks the poll-endpoint version of the same fix.** A perfectly routine 15 s AI answer
stacks 2 blocked polls at t=5 s and 4 at t=10 s; with the AI request itself that is **all 5 of
`pm.max_children`**, so from t≈10 s to t≈15 s *every other user's request queues in the FPM backlog*.
One AI question = a ~5 s full-app stall for everyone. Two concurrent AI users = a continuous stall.
This needs no pathological case.

**Exact change** — three files, not two:
```php
// api/v1/ai/stream.php — after the can() gate at :32, before the SSE headers at :134
$uid = (int)($_SESSION['user_id'] ?? 0);      // :67-68 already captures these into locals
$uname = (string)($_SESSION['name'] ?? '');
session_write_close();
```
Same immediately before `api/v1/ai/summary-stream.php:71` (the later `can()` at :107 only reads), and
**critically** in `api/v1/ai/chat.php`, which is reachable from every admin page via the chat widget
and is worse than the SSE endpoints because it emits no output and so never detects a client abort.

Verified safe: every `$_SESSION` write completes inside `api/bootstrap.php` + `require_auth_api()`
before those points, and `lib/AI/*` never writes `$_SESSION`.

**Effort:** small. **Risk:** medium — if any code path after the close writes `$_SESSION`, the write is
silently dropped. Grep each file for `$_SESSION` past the insertion point before shipping.
**Verify:** start an AI answer in one tab, navigate in a second tab of the same session, confirm the
navigation returns immediately instead of hanging until the stream finishes.

---

### 2.5 Reservations page: guard `init()` and add an aggregate pickup-count endpoint

**What it is.** `app/admin/reservations/index.php` renders an empty shell and then fires **86 XHRs**
(1 HTML doc + 86 = 87 PHP-executed requests) — 56 of them a per-day heat-map loop, 16 a 7-day chart
loop, 2 a dead duplicate KPI request. All 86 are doubled by the same unguarded `init()` as 2.2
(`x-data` at :60 + `x-init` at :975), and `no-store` at `api/bootstrap.php:52` prevents browser dedupe.

**Measured win.** ~364 prepared-statement executions → ~50; 87 PHP requests → ~9. Roughly a 90%
reduction. Because the session flock serializes all 86 requests one-at-a-time, at a conservative
20–40 ms server time each that is **1.7–3.5 s of strictly sequential XHR after first paint** on the
page today, during which up to all 5 workers are held (one working, the rest parked in `session_start()`)
— so one dispatcher opening Reservations stalls the whole app for everyone else.

**Exact change, in priority order:**
1. `if (this._inited) return; this._inited = true;` at the top of `init()` (index.php:975). One line,
   halves everything: 87 → 44 requests. Audit the ~20 other admin pages using the same `x-data`+
   `x-init="init()"` shape while you're there.
2. Delete the dead duplicate KPI fetch at index.php:1128-1136.
3. Add one endpoint replacing both loops:
```php
// api/v1/reservations/pickup_counts.php?from=&to=
SELECT pickup_date, COUNT(*) AS n
FROM reservations
WHERE deleted_at IS NULL AND pickup_date BETWEEN ? AND ?
GROUP BY pickup_date
```
Window it `today-6 .. today+27`, keep it **status-agnostic** to preserve the current COUNT semantics,
zero-fill client-side, and have both `loadHeatmap()` (:1315) and `loadChartData()` (:1179) call it.

The individual queries are cheap (`idx_pickup_status` serves them); the cost is 86 PHP bootstraps plus
session-lock serialization, not SQL.

**Effort:** small–medium. **Risk:** low, provided the status-agnostic COUNT semantics are preserved —
otherwise displayed numbers change.
**Verify:** Network tab request count on a reservations load: expect ~9, not 87. Compare every heat-map
cell and chart bar against the current page before/after.

---

### 2.6 Login-page background video — 6.99 MB, `preload="auto"`, on the first page every user hits

**What it is.** `app/auth/login.php:876-882` and `app/portal/auth/login.php:591-593` load a
6,993,794 B (6.67 MiB) H.264 1920×1080 / 40 s clip with `preload="auto"` — ~93% of that page's bytes.
It includes a 276,360 B AAC track that can never be heard (the element is `muted`), and `moov` sits
after `mdat`, so it is not faststart. The element is blurred 10 px in CSS.

**Measured win.** ~2.8 s of sustained bandwidth on 20 Mbps, ~11 s on 5 Mbps, shared with app.css and
two woff2 fonts over HTTP/1.1. It does not block FCP or the (inline, ungated) login JS — only
`window.load` and bandwidth. Frequency is once per session start *and per deploy*, because the git-hash
cache-buster invalidates it on every commit. At 100 logins/day that is ~700 MB/day (~21 GB/month) of
Lightsail egress for decoration.

**Exact change:**
```bash
ffmpeg -i login-bg.mp4 -vf scale=640:360 -c:v libx264 -crf 30 -an \
       -movflags +faststart login-bg-640.mp4
```
Expect ~300–500 KB — visually identical at 10 px blur. A 93–96% reduction. Then:
- drop the `?v=` cache-buster from the video URL (version by filename instead),
- add a WebM/AV1 `<source>` before the MP4,
- best: render the static poster/gradient on first paint and lazy-attach the video after `load`.

Note `preload="none"` alone saves ~0 bytes while `autoplay` remains on the element.

**Effort:** trivial. **Risk:** none (cosmetic asset swap).
**Verify:** DevTools Network on a hard-reloaded login page — total transfer should drop from ~7.4 MB
to well under 1 MB.

---

### 2.7 Request-scoped cache in `settings_get()`

**What it is.** `includes/functions.php:824-837` issues a fresh SELECT on every call with no
in-request cache. The shared chrome alone makes 13–14 calls per render (3 of them for the identical
`company.name` key at header.php:71, sidebar.php:140, footer.php:30); the Settings page makes 41+.

**Measured win — small, and I am ranking it here only because it is nearly free.** 14 individual
lookups = 1.275 ms; one `SELECT key,value FROM settings` (355 rows, 21,370 B serialized) = 0.340 ms.
**Net ~0.9 ms per page render**, maybe 1–3 ms on the contended 2-vCPU box. Query count drops 21 → 8 per
typical page (65% of all page queries) and 93 → 52 on Settings. That query-count reduction matters more
than the millisecond does under `pm.max_children=5`, because it shortens the window each worker holds a
connection — but no user will perceive it. **This is ~0.1% of the Tier 1.1 win. Do not let it displace
Tier 1.**

**Exact change:**
```php
function settings_get(string $key, $default = null) {
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (db_all("SELECT `key`, `value` FROM settings") as $r) { $all[$r['key']] = $r['value']; }
    }
    return array_key_exists($key, $all) ? $all[$key] : $default;
}
function settings_cache_flush(): void { /* reset the static */ }
```
**Ship it only with write-invalidation.** `lib/QuickBooksClient.php:242-266` performs a token refresh
that writes then re-reads inside one request and will break without a flush. Call
`settings_cache_flush()` from the `api/v1/settings/*` write endpoints and from the QBO token writer.
598 call sites repo-wide get the win with zero call-site edits.

**Effort:** trivial. **Risk:** low *with* invalidation; medium without it.
**Verify:** QBO token refresh end-to-end; a settings save followed by an in-request read.

---

### 2.8 Merge the two auth SELECTs

**What it is.** `includes/auth.php:675` and `:590` both issue a primary-key SELECT against the same
`users` row on every authenticated non-super_admin request (22 of 26 local users).

**Measured win — small.** 0.10–0.12 ms per request on loopback; 0.2–0.4 ms on Lightsail. On a page
render it is 1 query of ~21. Where it is proportionally meaningful is the pollers: those endpoints run
only 3 queries total, so this removes 33% of their DB work — ~25 redundant queries/min/tab.
**Not a page-load win.** Take it as free hygiene bundled with other auth work.

**Exact change:**
```php
// auth.php:675
$live = db_row("SELECT status, deleted_at, role_id, permissions_updated_at FROM users WHERE id = ?", [$uid]);
// pass $live['permissions_updated_at'] into _ff_refresh_permission_overrides_if_stale()
```
**Do not use `null` as the "argument omitted" sentinel** — `permissions_updated_at` is legitimately NULL
for most users, which silently reinstates the second query for exactly those users. Use a distinct
sentinel (a default string constant, or a separate boolean flag).

**Effort:** small. **Risk:** low. **Verify:** `tests/_smoke_permissions_rigorous.php` (304 checks) must
still pass; log query counts on one page render before/after.

---

### 2.9 Delete the duplicated server-side KPI computations

Two independent one-shot deletions, both worth ~0 ms but fixing real correctness/security bugs:

**Invoices** — `app/admin/invoices/index.php:29-59` computes four AR-aging aggregates server-side, then
`loadKpis()` (:449-500) overwrites them from the API. Worth 1.24 ms. But the server-side values are
(a) **unredacted AR dollar totals embedded in the HTML at :453-460 for dispatchers**, who are explicitly
denied amounts by `kpis.php:78-79` and `config/permissions.php:198-205` — visible in view-source, not a
flash; and (b) **wrong by $8,409.90 and $6,264.12** on the two populated buckets, because the page-side
SQL omits the USD→CAD conversion the API applies. Delete :29-60, init the Alpine `kpis` object to
zeros, **and** add a failure branch to `loadKpis()` (:496-499) — it currently has no `catch` and no
`else`, so a failed fetch shows a permanent silent $0.00.

**Notifications bell** — `includes/topbar.php:37-47` runs the COUNT, then `app.js:1272` immediately
discards it with an XHR for the same number. Delete `await this.fetchCount()` from
`FF_Notifications.init()` and keep the seed (this is subsumed by 2.2 if you set `_initialized = true`
there).

**Effort:** trivial. **Risk:** none. **Verify:** log in as a dispatcher, view-source the invoices page,
confirm no dollar figures; kill the network and confirm the KPI row shows a dash, not $0.00.

---

### 2.10 Honour `per_page=1` on the "lightweight" count calls

**What it is.** `api/v1/leases/index.php:103` clamps `per_page` with `max(10, …)`, so calls that read
only `pagination.total` still run the full joined row SELECT and return 10 rows. Reservations does the
same, including a `JSON_ARRAYAGG` over four LEFT JOINs (index.php:109) whose result is discarded.

**Measured win — modest.** Leases page: 19.5 KB of JSON where 3.1 KB would do (~16 KB wasted,
uncompressed today). Reservations: ~7.8 KB. Total ~24 KB, ≈30–40 ms on a 5 Mbps link. DB-side the floor
costs ~0.6 ms per leases page — effectively zero.

**Exact change:** `max(10,` → `max(1,` in the 5 affected files. Precedent already exists:
`api/v1/customers/index.php:43` and `api/v1/equipment/units/index.php:43` already use `max(1, …)`.
Better long-term: add `&count_only=1` returning just `pagination.total` and skipping the row SELECT.

**Effort:** small. **Risk:** low. **Verify:** Network tab response sizes on the leases/reservations
KPI calls.

---

## Tier 3 — Deeper refactors. Days. Do only after Tier 1 is measured.

### 3.1 Collapse the dashboard charts monthly loops into `GROUP BY`

`api/v1/dashboard/charts.php` runs **112 queries cold**, 78 of them one-query-per-month PHP loops at
:338-361, :385-414, :543-561, :604-621, :713-731. Measured on the *dev* dataset (151 leases, 783
invoices — a fraction of prod): 48 of the 78 cost 28.3 ms vs 3.3 ms for the 4 equivalent GROUP BYs, an
8.6× reduction; all 78 ≈ 46 ms → ~7 ms.

Prod is materially worse, because `leases` has **no index on `created_at`, `updated_at` or `end_date`**
and `invoices` has none on `updated_at` or `invoice_date` (verified in FLEETFORGE_DATABASE_MASTER.sql) —
so each of the 78 is a filtered scan repeated 12× over the same rows, falling back to low-selectivity
`idx_status`/`idx_deleted`. **Estimated 150–500 ms of avoidable prod server time per cold request; I
could not measure prod and am not treating that range as measured.**

Frequency is the part that makes this worth doing: `api/bootstrap.php:180-187` wipes all 12 chart cache
rows on **every successful write API call app-wide** (531 `json_success` call sites under api/v1), so on
an active system most dashboard loads are cold. `chart_weekly_heatmap` (:483-498) already does the
one-query-GROUP-BY shape and is the in-repo template. Fixing it also removes a month-end `strtotime`
drift that duplicates/skips months on the 29th–31st.

Fold in: charts.php builds all 12 datasets including the 4 money charts and only *then* unsets them at
:120-122 for users without `can_view_financials()` — a dispatcher pays the full 112-query cold cost to
receive 8 charts. Skip building them.

**Effort:** medium. **Risk:** low (pure query-shape change, outputs must be diffed month-by-month).
**Verify:** capture the JSON response before/after and diff; count queries with the general log on a
cold cache.

---

### 3.2 Minify app.css / app.js

Verified with esbuild 0.24.0: app.css 415,682 → 222,880 and app.js 231,541 → 96,702 = **−327,641 B
(−50.6%)**. All five first-party render-blocking assets: 678,087 → 330,724 = −347,363 B.

**Critical sequencing:** that number holds only *while gzip is off*. After Tier 1.1, minified+gzip is
37,114 + 25,226 = 62,340 B vs 144,234 B gzipped-only — so the **marginal** win after gzip is
**−81,894 B** for the two files (−87,470 B for all five). Do gzip first; do not sell both numbers
additively.

Three required adjustments: (1) use esbuild or lightningcss, **never** a hand-rolled regex — a regex
minifier breaks 32 `calc()` expressions and corrupts 37 JS string literals in these files; (2) update
all **13** app.css and **4** app.js call sites, not just header.php:113 / footer.php:187, or the login
page ships unminified; (3) add a precommit guard that regenerates and verifies the `.min` artifacts —
`deploy.sh` is git-pull-only and `FF_ASSET_VERSION` is the HEAD hash, so a forgotten rebuild silently
ships stale CSS under a *fresh* cache-buster.

**Effort:** medium. **Risk:** low. **Verify:** byte-diff the rendered pages; smoke every major surface.

---

### 3.3 Index work — one migration, three changes

Bundle these into a single `db_migrations/` migration so `FLEETFORGE_DATABASE_MASTER.sql` stays in
parity. **Confirm the prod row count first** — the whole notifications item is scale-gated:

```sql
SELECT COUNT(*) FROM notifications;                    -- read-only, run this first
SELECT COUNT(*) FROM messenger_threads WHERE is_archived = 0;
```

**(a) notifications — the only one with per-page-render impact.** `idx_deleted` (cardinality 1) lures
the optimizer into an `index_merge intersect` on the unread count that runs on every admin page render
via topbar.php:37-41 and every 60 s per tab via count.php:29-33.
```sql
ALTER TABLE notifications
  DROP INDEX idx_deleted,
  DROP INDEX idx_read,
  ADD KEY idx_user_live_unread (user_id, is_read, deleted_at),
  ADD KEY idx_user_live_recent (user_id, deleted_at, is_read ASC, created_at DESC);
```
Measured: unread count 1.80 ms → 0.22 ms at 7,205 rows; **12.9 ms → 0.68 ms at 57,640 rows**. The
`DROP idx_deleted` is **required** — with the composite added but `idx_deleted` still present the
optimizer still chose the intersect (13.28 ms). The list query (notifications/index.php:109) measures
14.08 ms as-is → 3.15 ms with the drop alone → **0.19 ms with the drop plus the descending composite**;
the descending index without the drop buys **nothing** (14.05 ms). At 7,205 rows / 285 per user the
optimizer picks plain `idx_user` and none of this applies — hence the row-count check.

**(b) invoices list.** Today ~2.2 ms saved per invoices list view — invisible. This is a **scaling
hazard**, not a present win: measured 50 ms at ~24k live invoices and 213 ms at ~95k vs a flat ~0.2 ms
indexed, and a 213 ms query holds 1 of 5 workers for its whole duration.
```sql
ALTER TABLE invoices ADD KEY idx_live_recent (deleted_at, created_at, id);
```
**Invoices only** — leases (151 rows) and customers (34 rows) save <0.1 ms; skip them. **After adding,
re-EXPLAIN the `customer_id=` / `lease_id=` / `aging=` filtered variants:** a 14× regression
(0.38 ms → 5.4 ms) was reproduced on the customer-filtered list when the optimizer switched to the new
index, and those paths are reachable from customers/show.php:757 and leases/show.php:162,178.

**(c) messenger, only if the module is actually used.**
```sql
ALTER TABLE messenger_messages ADD KEY idx_thread_sender (thread_id, sender_type, is_archived, id);
ALTER TABLE messenger_messages DROP INDEX idx_thread;   -- now a redundant prefix
ALTER TABLE messenger_threads ADD KEY idx_archived_last (is_archived, last_message_at);
```
At 400 active threads / 10k messages this takes the 5 s poll from ~10–15 ms to ~5 ms. The dev DB has
**zero** messenger rows, so if prod's messenger is lightly used the real saving is 0. Skip the
de-correlated query rewrite as a *performance* measure — measured zero handler-read improvement.

**Not recommended:** dropping the eleven redundant leftmost-prefix indexes. Measured cost is
0.0020 ms/row on INSERT (2.1% of INSERT time, not the 18% claimed — that figure was the index *count*
ratio, and it only reproduces if you omit the FULLTEXT index from the replica). Total footprint 544 KB
against a 97 MB DB in a 128 MB buffer pool — the whole DB already fits, so there is no page contention
to relieve. Read-path benefit is provably zero (optimizer cost identical under `IGNORE INDEX`). This is
tech debt, not performance. And explicitly **do not** drop `leases.idx_status` — seven of ten
`/api/v1/dashboard/tables` queries plan on it.

**Effort:** small per migration. **Risk:** low, but see the ⚠️ section on live DDL below.

---

### 3.4 Server-render list-page KPIs instead of fetching them

`app/admin/equipment/index.php:473-500` renders an empty shell then makes 6 requests (4 of them
`per_page=1` status counts). Because of the session lock these execute **strictly serially on the
server regardless of `Promise.all`** — ~6 × 20 ms = ~120 ms of serialized server work plus 6 RTTs,
starting only after the render-blocking JS and deferred Alpine have booted. At a 40 ms RTT that is
~350–400 ms of dead time.

The better fix than the four-into-one counts endpoint: compute the four status counts **during the
existing PHP page render** (one `GROUP BY`, ~3 ms on a request that already exists) and emit them as
initial Alpine state instead of skeletons. That removes 5 of 6 requests and drops time-to-KPI to zero.
**~300–400 ms per equipment page load.**

Highest-value single line in this item, and it is not on equipment: **remove the
`/api/v1/dashboard/kpis` call from `leases/index.php:465`** — it costs 13 DB queries plus a full
bootstrap to populate *one* number (`active_revenue`), while holding the session lock the other four
leases KPI calls are queued behind.

**Effort:** medium. **Risk:** low. **Verify:** Network request count and time-to-first-KPI-number.

---

### 3.5 Move inline `<style>`/`<script>` out of the always-mounted modals

`includes/footer.php:155,161,170` mounts three partials into every admin page: measured **66.3 KB raw /
~14.2 KB gzipped**, i.e. 25–32% of the compressed HTML (dashboard 14,266 of 50,855 gz; customers 14,261
of 44,274; reports 14,226 of 57,898). HTML is never cached, so this repeats on every navigation.

With `ai.enabled=0` or no Anthropic key, `ai-chat-widget.php:31` already returns before output, so real
exposure may be only ~29.8 KB raw / ~6 KB gz.

**Lowest-risk, highest-value subset first:** move `ai-chat-widget.php:223-670` (`<style>`, 13,001 B),
`:678-935` (`<script>`, 11,406 B) and `help-drawer.php:122-254` (`<script>`, 5,260 B) into app.css /
app.js — **29.7 KB per page view converted from uncacheable HTML to cacheable assets, zero behaviour
change.** Only then consider lazy-mounting `email-compose-modal` (17.4 KB of pure markup, single
coupling at app.js:4915), and only with the opener rewritten to mount-then-dispatch. **Leave
help-drawer mounted eagerly** or its sessionStorage restore (help-drawer.php:143-152) breaks.

**Effort:** medium. **Risk:** low for the style/script move, medium for lazy-mounting.

---

### 3.6 Split app.css / app.js per surface — lowest value per unit of effort here

Single-surface CSS is ~86 KB of rule bytes (20.7%); single-surface JS is 84,211 B (36.4%). But the
**gzipped** saving — the number that matters after Tier 1.1 — is only ~10.8 KB CSS + ~21.7 KB JS =
**~33 KB per cold load**, and **~0 bytes on repeat views** within a deploy, since `FF_ASSET_VERSION`
already content-versions behind immutable caching. That is ~1/29th of the Tier 1.1 win for a
large, medium-risk refactor.

If done anyway, the safe subset is: (1) **delete `FF_ChatWidget`** (app.js:2659-2799, 5,454 B) and the
`.chat-widget*` CSS — `includes/partials/chat-widget.php` is orphaned; (2) extract portal CSS
(app.css:5629-6863, 33,348 B) into portal.css, **explicitly retaining** `.portal-create-grid` and
`.portal-user-show-grid` (app.css:12426/12444) in the admin bundle; (3) extract CHAT-1 + MSGR-1
(app.css:8706-**10196**, not 10351). **Do not use the `// 07x` markers as JS split boundaries** — 07g
and 07h are each duplicated, and `FF_RecordPicker` (3113-3321) and `FF_FormDraft` (3325-3641) are global
helpers sitting between the chat factories. Split by explicit ranges 1581-2656, 2899-3113, 3645-4171
only, and preserve cascade order — app.css relies on source order for its responsive overrides.

**Effort:** large. **Risk:** medium. **Rank: last.**

---

### 3.7 Cache the signed brand-logo URLs

`includes/sidebar.php:143` presigns the brand logo on every render. When `STORAGE_DRIVER=s3` (prod, per
docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md:484) **and** `brand.logo_path` is set, this pulls in 186 files /
135 AWS SDK classes: measured **22.2 ms S3Client construction + 3.3 ms first presign ≈ 25 ms warm-opcache
on an M-series Mac**, likely 30–60 ms on Lightsail. Per-request heap is +0.74 MB with a warm opcache
(**not** 2.6 MB — that only holds on an opcache miss, where it is +16.3 MB), plus ~143 of the 10,000
`max_accelerated_files` slots.

Driver-independently: the signed URL is regenerated fresh every render, so **the logo is re-downloaded
on every page load** instead of being served from browser cache.

Fix: store the generated URL plus expiry in `settings` (`brand.logo_signed_url` /
`brand.logo_signed_url_exp`) and regenerate only on expiry or when the upload endpoint writes a new
`brand.logo_path`. Also memoise `StorageClient::driver()` and `bucket()` in statics — they re-query
settings on every call. **Zero impact if `brand.logo_path` and `brand.favicon_path` are both empty on
prod — verify that before spending effort here.**

**Effort:** small. **Risk:** low.

---

### 3.8 The 10.6 KB SVG sprite — do NOT ship as a standalone change

`includes/footer.php:199-284` ships on all 163 pages; 24 reference it. That is **~2,595 B gzipped**
(0.2% of the 1,246,775 B cold payload) and 58 no-op DOM elements Alpine walks in microseconds. Tier 1.1
is ~370× larger for one config line. The maintenance risk (silent blank icons) exceeds the benefit.

Free wins only: delete the genuinely dead `icon-heart` symbol (footer.php:264, 0 references repo-wide),
and de-duplicate `icon-truck` / `icon-magnifying-glass` against the `heroicon()` files.

---

## BEFORE / AFTER — cold admin page load

**Assumptions.** Cold cache, dashboard-class admin page, `pm.max_children=5` not saturated. Server time
is TTFB for the HTML document. Transfer time uses effective throughput, not link rate: **broadband
20 Mbps ⇒ ~2.5 MB/s**, **4G 5 Mbps ⇒ ~625 KB/s**. HTTP/1.1 adds ~2 extra serialization rounds for
9 assets over 6 connections; HTTP/2 removes them. RTT: 40 ms broadband, 90 ms 4G.

### BEFORE (today)

| Component | Bytes |
|---|---:|
| apexcharts.min.js | 522,342 |
| app.css | 415,682 |
| app.js | 231,541 |
| alpine.min.js | 46,346 |
| animations.css | 11,287 |
| ff-chart-theme.js | 10,670 |
| ff-animations.js | 8,907 |
| HTML document (incl. 66.3 KB modals + 10.6 KB sprite) | ~51,000 |
| 2× woff2 fonts (already compressed) | ~60,000 |
| **Total** | **~1,357,775** |

**Broadband:** 1,357,775 ÷ 2,500,000 B/s = **543 ms** transfer + ~3 RTT connection/serialization
(3 × 40 = 120 ms) + ~60 ms server = **≈ 720 ms** to a usable page, plus ~19–80 ms of ApexCharts
parse/execute before Alpine un-cloaks ⇒ **≈ 750–800 ms**.

**4G:** 1,357,775 ÷ 625,000 = **2,172 ms** + ~4 RTT (4 × 90 = 360 ms) + ~60 ms server = **≈ 2,590 ms**,
plus 40–80 ms parse ⇒ **≈ 2.6–2.7 s**.

*(Login page adds 6,993,794 B on top: +2.8 s broadband, +11.2 s 4G.)*

### AFTER Tier 1 only (three nginx edits, ~10 minutes)

Gzip applies to the 7 CSS/JS assets and the HTML; the fonts stay as-is.

| Component | Bytes |
|---|---:|
| 7 CSS/JS assets, gzipped | 304,728 |
| HTML document, gzipped (~3.5:1) | ~14,600 |
| 2× woff2 | ~60,000 |
| **Total** | **~379,328** |

**Broadband:** 379,328 ÷ 2,500,000 = **152 ms** + ~1.5 RTT (HTTP/2, 60 ms) + 60 ms server =
**≈ 270 ms**, plus parse ⇒ **≈ 290–350 ms**. **A ~2.4× improvement.**

**4G:** 379,328 ÷ 625,000 = **607 ms** + ~1.5 RTT (135 ms) + 60 ms server = **≈ 800 ms**, plus parse
⇒ **≈ 840–880 ms**. **A ~3.1× improvement.**

### AFTER Tier 1 + Tier 2 (ApexCharts gate, modals, settings cache)

On the 155 non-chart pages, drop apexcharts (133,877 gz) + ff-chart-theme (3,880 gz); move ~29.7 KB raw
of inline style/script into the cached bundles (~6 KB gz off the HTML); server time −1 ms from the
settings cache and −0.1 ms from the auth merge.

| Component | Bytes |
|---|---:|
| 5 remaining CSS/JS assets, gzipped | 166,971 |
| HTML document, gzipped | ~8,600 |
| 2× woff2 | ~60,000 |
| **Total** | **~235,571** |

**Broadband:** 235,571 ÷ 2,500,000 = **94 ms** + 60 ms RTT + ~58 ms server = **≈ 212 ms**, and the
19–80 ms ApexCharts parse is gone entirely ⇒ **≈ 210 ms**. **~3.6× vs today.**

**4G:** 235,571 ÷ 625,000 = **377 ms** + 135 ms + 58 ms = **≈ 570 ms**. **~4.6× vs today.**

### Honest caveats on these numbers

- **Warm-cache navigations look nothing like this.** With `immutable` assets correctly cached, a repeat
  navigation fetches only the HTML document (~14.6 KB gz today, ~8.6 KB after Tier 2). There the win is
  server time and the ApexCharts parse/execute (19–80 ms), not bytes. Tier 3.6 (the CSS/JS split) is
  worth ~0 on warm navigations — that is why it ranks last.
- **Server time (~60 ms) is an estimate.** `slow_query_log` is OFF, so nothing on this box has been
  timed under real load. The settings-cache and auth-merge items move it by ~1 ms combined.
- **Reservations and equipment do not fit this model.** Their post-render XHR waves (1.7–3.5 s and
  ~350–400 ms respectively, serialized by the session lock) dominate everything above and are addressed
  by 2.5 and 3.4 independently.
- The 4G figures assume a good 4G connection. On a congested cell the transfer term grows and Tier 1's
  relative advantage grows with it.

---

## ⚠️ Risky on a live single-server box

**1. `request_terminate_timeout = 180` (Tier 1.4) can kill legitimate long requests.** Enumerate your
longest synchronous endpoint — batch invoice generation, ZIP/combined-PDF download, report exports —
before setting it. If any can exceed 180 s, give it its own pool or location block.

**2. Index DDL against InnoDB (Tier 3.3).** `ADD KEY` is online in MySQL 8.0, but on a 2 vCPU box with a
128 MB buffer pool it still consumes I/O and CPU. Run in a maintenance window, one `ALTER` at a time,
and take a snapshot first. **`DROP INDEX` is the dangerous half**: if the optimizer was quietly relying
on a dropped index somewhere unmeasured, a query silently becomes a table scan and — with only 5 workers
— queues the whole site. Capture `EXPLAIN` for every list/detail query on the affected tables *before*
dropping anything, and be ready to re-add. The `invoices.idx_live_recent` addition is specifically known
to cause a 14× regression on the customer-filtered variant; re-EXPLAIN those paths.

**3. `session_write_close()` (Tier 2.4).** If any code path after the close writes `$_SESSION`, the
write is silently dropped — no error, no log. Grep each touched file for `$_SESSION` past the insertion
point. **Do not build a generic `ff_session_release()` helper** that anyone can call: invoked before
`require_auth_api()`, it would silently stop persisting the refreshed permission-override map
(auth.php:585), turning a once-per-change refresh into a per-request re-query. Keep it as three explicit
inline calls.

**4. `settings_get()` caching without invalidation (Tier 2.7)** breaks the QBO token refresh at
`lib/QuickBooksClient.php:242-266`, which writes then re-reads inside one request. Ship the flush in the
same commit.

**5. `immutable` + the un-versioned apexcharts tag (Tier 1.3).** `footer.php:179` carries no `?v=`.
Under a corrected single `immutable` header that file becomes permanently uncacheable-to-update. Add the
cache-buster in the same change or you will not be able to update ApexCharts without renaming it.

**6. Minification without a build guard (Tier 3.2).** `deploy.sh` is git-pull-only and
`FF_ASSET_VERSION` is the HEAD hash — a forgotten rebuild ships *stale* CSS under a *fresh* cache-buster,
which is worse than no minification because it looks correct. Add a precommit check that regenerates and
verifies the `.min` artifacts before you point any call site at them.

**7. Everything here is a single box with no staging twin.** Apply Tier 1 one directive at a time with
`nginx -t` and `systemctl reload` (not `restart`) between each, and keep the previous config file to
hand for an instant rollback.

---

## Refuted findings — do not re-investigate

These were raised by an investigator and then **disproven** by an independent verifier. Recorded here so
the same ground is not covered again.

1. `x-cloak` on `.app-layout` makes the page invisible until every render-path script executes.
2. `config/navigation.php` is require'd and rebuilt twice per page render.
3. The sidebar inlines 62 full SVG icons / ~44 KB of repeated anchor markup per page.
4. Fixed per-request include cost of 25 files / 448 KB before any page code.
5. `Sentry::init()` runs eagerly on every request including pollers (+59 files / +329 KB).
6. Chart month loops use `YEAR()`/`MONTH()` on the filter column (non-sargable).
7. `notifications` (50,009 rows) has no retention or archival whatsoever.
8. `samsara_location_history` (204,898 rows / 36% of the DB) is read by nothing and never pruned.
9. `notification_log` has no leading `created_at` index, so the archive cron full-scans it.
10. Every API response is `no-store` with no ETag, so 304 is structurally impossible.
11. The table auto-labeller does a document-wide scan + permanent body MutationObserver.
12. `FF_Theme.init()` rewrites `data-theme` after DOMContentLoaded, forcing a full style recalc.

---

## Appendix — ready-to-apply production configs

### A. `/etc/nginx/conf.d/gzip.conf` (new file)

```nginx
gzip              on;
gzip_vary         on;
gzip_proxied      any;
gzip_comp_level   6;
gzip_min_length   1024;
gzip_buffers      16 8k;
gzip_http_version 1.1;

gzip_types
    text/plain text/css text/xml text/javascript
    application/javascript application/json
    application/xml application/xml+rss application/rss+xml
    application/manifest+json
    image/svg+xml;
# text/html is always gzipped by nginx — do NOT list it.
# woff2 is already compressed — do NOT add font/woff2.
```

### B. `/etc/nginx/sites-enabled/fleetforge`

```nginx
# was: listen 443 ssl;
listen 443 ssl http2;

# was: three stacked add_header/expires lines
location ^~ /assets/ {
    expires 1y;
    add_header Cache-Control "public, immutable" always;
    access_log off;
    try_files $uri =404;
}
location ^~ /media/ {
    expires 1y;
    add_header Cache-Control "public, immutable" always;
    access_log off;
    try_files $uri =404;
}
```

⚠️ Ship the `?v=<?= FF_ASSET_VERSION ?>` cache-buster on `includes/footer.php:179` in the **same**
change — that tag currently has none, and under a corrected `immutable` header ApexCharts could not be
updated without renaming the file.

### C. `/etc/php/8.2/fpm/pool.d/www.conf`

```ini
pm = dynamic
pm.max_children = 20        ; was 5. 20 x ~40MB = ~800MB on a 3.8GB box
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500
; request_terminate_timeout = 180   ; see Tier 1.4 — check your longest sync endpoint FIRST
```

### D. `/etc/php/8.2/fpm/conf.d/99-fleetforge-opcache.ini` (new file)

```ini
opcache.memory_consumption=256      ; was 128
opcache.interned_strings_buffer=32  ; was 8
opcache.max_accelerated_files=20000 ; was 10000 (5,283 app files + vendor)
opcache.validate_timestamps=1       ; keep ON — deploy is git-pull, not atomic symlink
opcache.revalidate_freq=60          ; was 2. NOTE: code changes take up to 60s unless
                                    ; deploy.sh ends with: systemctl reload php8.2-fpm
opcache.save_comments=1             ; REQUIRED — aws-sdk-php + sentry read annotations
opcache.jit=tracing
opcache.jit_buffer_size=64M         ; was off. Modest on a request/response app; revert if odd
```

### Apply order (one at a time, `nginx -t` between each)

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl reload php8.2-fpm
curl -sSI -H 'Accept-Encoding: gzip' https://mainlandrentals.com/assets/css/app.css \
  | grep -iE 'content-(encoding|length)|cache-control'
curl -sS -o /dev/null -w '%{http_version}\n' https://mainlandrentals.com/fleetforge/auth/login
```

Expect `content-encoding: gzip`, `content-length: ~90426`, exactly **one** `cache-control`, and `2`.
