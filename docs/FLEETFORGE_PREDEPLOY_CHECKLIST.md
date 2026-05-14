# FleetForge — Pre-deploy Checklist

**Canonical pre-deploy operations file.** Lives alongside `FLEETFORGE_PROGRESS.md` (history), `FLEETFORGE_CURRENT_SESSIONS.md` (queue), and `FLEETFORGE_CLAUDE_CODE_REFERENCE.md` (lookups). Created 2026-05-12 via session **S-PREDEPLOY-CHECKLIST-CREATE**; discipline locked as **K-14** in PROGRESS.md KEY LEARNINGS.

---

## Handoff status (HANDOFF-PREP 2026-05-13)

**Last updated:** 2026-05-13 by HANDOFF-PREP — pre-cutover-chat preparation.

**Platform state at handoff:**
- **Stack:** PHP 8.2 / MySQL 8.0 / Alpine.js / ApexCharts v3 / mPDF / AWS SDK (per D24 composer.json carries BOTH `mpdf/mpdf` AND `aws/aws-sdk-php`).
- **Local dev:** Laravel Herd at `http://fleetforge.test/fleetforge/`; MySQL via Homebrew at `127.0.0.1:3306`.
- **Migrations:** 17 migration files in `db_migrations/`, **17 applied / 0 drift / 0 missing** per `bin/migrate.php --verify`.
- **FF_ASSET_VERSION:** 1.0.28 in dev `.env` (gitignored). **Filed under A2 in this checklist — bump on prod before deploy.**
- **SHIPPED sessions:** 23 labels currently in `CURRENT_SESSIONS.md` (Tier 1/2/3 feature work complete). Doc freshness smoke 17/17 PASS exit 0.
- **Model B mileage arc closed:** engine (S-MILEAGE-1 → -2A → -2B → -3 → -3-FIX-0 → -5) + portal + helper retirement (S-PORTAL-MILEAGE-MODEL-B) all SHIPPED 2026-05-13. Final retirement of `Mileage::monthlyAllowance` complete.
- **Pending non-blocking:** `S-MILEAGE-3-ACCT-SPEC` is CPA-blocked on 5 enumerated questions per D-I (A) / D176 — independent of cutover; can ship before, during, or after deploy without coupling.
- **Pending optional:** `S-MILEAGE-HELPERS-CLEANUP` (3 orphan helpers in `lib/Billing/Mileage.php` per S-PORTAL-MILEAGE-MODEL-B D-E) + `S-PROD-3` (self-host CDN deps) — both hygiene-grade, neither blocks deploy.
- **Repo:** `/Users/avi/Documents/fleetforge` on `origin/main`, working tree clean post-HANDOFF-PREP.
- **Canonical docs:** all `FLEETFORGE_*.md` files in `docs/` subfolder per DOCS-REORG (2026-05-13); `FLEETFORGE_DATABASE_MASTER.sql` at repo root per SEVEN FILES convention (REFERENCE.md §1).

**Smoke-gate state (D131 four-gate suite):**
- `php tests/_smoke_doc_freshness.php` → 17/17 PASS exit 0
- `php tests/_smoke_master_schema_parity.php` → PARITY OK (master matches live DB)
- `php tests/_smoke_billing_invariants.php` → INVARIANTS OK I1-I10 (10/10 PASS exit 0)
- `php bin/migrate.php --verify` → 17 ok / 0 drift / 0 missing

**Bidirectional cross-refs:**
- See `FLEETFORGE_PROGRESS.md` → KEY LEARNINGS → **K-14** for the discipline rule that mandates this file's existence.
- See `FLEETFORGE_PROGRESS.md` → SESSION LOG → **S-PREDEPLOY-CHECKLIST-CREATE** (2026-05-12) for creation context + backfill inventory.
- See `FLEETFORGE_CLAUDE_CODE_REFERENCE.md` → Pre-flight check section + §0 LOCKED DECISIONS pointer for the file's role in the tracking-file taxonomy.
- See `FLEETFORGE_CURRENT_SESSIONS.md` → AWS Lightsail cutover entry for the parent queued multi-session that consumes most items in this file.

---

## Purpose

This file is the single home for **pre-deploy obligations** — work items that must be done **before** the production deploy to AWS Lightsail (or any prod cutover), but that are **not** sessionable feature work and **not** bugs against shipped code.

Examples of what belongs here:
- `.env` keys that exist as placeholders in dev but need real values in prod (AWS credentials, Sentry DSN, SES SMTP, etc.).
- Asset-version bumps that need to be reflected on prod's `.env` because dev `.env` is gitignored.
- DNS records, AWS infra resources, IAM roles, monitoring dashboards — anything that lives outside the repo.
- Data-state preconditions (e.g. AR drift remediation) that must be reconciled before cutover.
- Smoke + verification procedures that run against prod after deploy.
- Rollback procedures that must be in place before deploy is attempted.

Examples of what does **NOT** belong here:
- Bugs in shipped code → `FLEETFORGE_PROGRESS.md` → KNOWN ISSUES (`#NNN`).
- Active session work → `FLEETFORGE_CURRENT_SESSIONS.md` → IN-FLIGHT or queue.
- Historical decisions → `FLEETFORGE_PROGRESS.md` → DECISIONS (`DNNN`).
- Architectural lookups → `FLEETFORGE_CLAUDE_CODE_REFERENCE.md`.

---

## How this file works (K-14 discipline)

**Category separation rule:** When a session surfaces a pre-deploy obligation as a side-effect of its primary work, the obligation is filed **here**, in the appropriate category, with bidirectional cross-references to the originating session. Filing it under KNOWN ISSUES (bug-shaped) or CURRENT_SESSIONS (queue-shaped) is a category error and constitutes documentation divergence (K-12 class).

**Discovery flow:**
1. Session in progress notices a pre-deploy obligation (e.g. a new env key was introduced).
2. Session adds a new ITEM in the appropriate category here, citing its originating commit.
3. Session SESSION LOG entry in PROGRESS.md mentions the surfacing.
4. At deploy time, operator works through every PENDING item in category order, flips Status to ✅ COMPLETE with the prod commit/snapshot ref.
5. ✅ COMPLETE items remain in place until cutover is verified end-to-end, then move to **Completed items (rolling log)** at the bottom.

**Item format:**

```
ITEM <X#> | <UTC date surfaced> | <category> | <one-line description>
  Originating session: <S-name + commit-ref where the obligation was created or discovered>
  Surfaced into checklist: <S-name + commit-ref of the file edit that added the item here>
  Detail: <multi-line context. Cite "Original source: <SPEC L#>" / "Original source: <runbook>" if applicable.>
  Action: <what specifically needs to happen at deploy time — operator-actionable, not vague>
  Owner: Operator | Claude Code | Deferred (<reason>)
  Status: PENDING | IN-FLIGHT | ✅ COMPLETE (<commit-ref or snapshot-ref>)
```

The **Originating session** vs **Surfaced into checklist** split (K-14 / S-PREDEPLOY-CHECKLIST-CREATE D-B refinement) preserves both lineages: when an obligation was *created* (e.g. `FF_ASSET_VERSION` bumped) vs when the checklist *learned about it*. For pre-existing obligations backfilled in this file's creation, "Surfaced into checklist" = S-PREDEPLOY-CHECKLIST-CREATE.

---

## Categories

- **A — Asset cache invalidation** (CSS/JS version bumps, browser cache busters)
- **B — Production `.env` keys** (placeholders that need real values)
- **C — DNS** (A/AAAA records, MX, TXT for SES, SPF/DKIM/DMARC)
- **D — AWS infrastructure** (Lightsail, S3, SES, IAM, CloudWatch, SNS)
- **E — Data migrations** (one-time prod data backfills / corrections)
- **F — Accounting state** (AR drift, opening balances, QBO reconciliation prep)
- **G — Smoke + verification procedures** (must run post-deploy before cutover)
- **H — Rollback procedures** (must exist before deploy is attempted)
- **I — Post-deploy monitoring** (alarms, dashboards, on-call setup)
- **J — References** (links to runbooks, SPEC sections, decision rows)

---

## Active items

### A — Asset cache invalidation

```
ITEM A1 | 2026-05-11 | A — Asset cache | Bump FF_ASSET_VERSION on prod .env to 1.0.26
  Originating session: S-INVOICE-DISPLAY-COMPREHENSIVE (commit f9130a2 — C3-T1-FIX print overlay fix in app.css)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE (this file's creation commit)
  Detail: Dev .env was bumped 1.0.25 → 1.0.26 in C3-T1-FIX because app.css changed (extended @media print
    hide list with .search-modal, .search-overlay, .modal-overlay, .modal-backdrop, .sidebar-overlay).
    The ?v=X.X.X query string on <link href="/assets/css/app.css?v=<?= FF_ASSET_VERSION ?>"> is the
    browser cache buster — without bumping prod's .env, returning visitors will load stale CSS on first
    page-load post-deploy and the dark print overlay will reappear.
    Original source: .env line 16 (gitignored — see also FLEETFORGE_PROGRESS.md S-INVOICE-DISPLAY-COMPREHENSIVE
    SESSION LOG row for the ×→bump rationale).
  Action: On prod .env (Lightsail instance), set FF_ASSET_VERSION=1.0.26. Restart php-fpm if asset version is
    cached in opcache; verify with curl -I https://<prod>/assets/css/app.css?v=1.0.26 returns 200.
  Owner: Operator
  Status: PENDING

ITEM A2 | 2026-05-12 | A — Asset cache | Bump FF_ASSET_VERSION on prod .env to 1.0.27
  Originating session: S-MILEAGE-2B (C6 — D-K Odometer card rewrite + D-L Drawdown Reconciliation panel
    + D-F Financial Summary drawdown breakdown in app/admin/invoices/show.php)
  Surfaced into checklist: S-MILEAGE-2B C6 (this file's edit commit, post-C6 push)
  Detail: C6 changed app/admin/invoices/show.php substantially — rewrote the Odometer card to include
    period_charge + Samsara warnings banner, converted the retired Mileage Review card into a Drawdown
    Reconciliation panel, added Model B drawdown breakdown rows to the Financial Summary block. Page-scoped
    <style> changes via inline styles only; no app.css edit in C6 (so the cache buster is showing
    new markup against an unchanged stylesheet — still worth a bump so returning operators see the new
    layout immediately rather than waiting for a hard refresh). Operator should bump dev .env (1.0.26 → 1.0.27)
    + apply same to prod .env at cutover.
    Original source: .env (gitignored — bumped by operator at session ship).
  Action: On prod .env (Lightsail instance), set FF_ASSET_VERSION=1.0.27. Restart php-fpm if asset version is
    cached in opcache; verify with curl -I https://<prod>/assets/css/app.css?v=1.0.27 returns 200.
  Owner: Operator
  Status: PENDING

ITEM A3 | 2026-05-13 | A — Asset cache | Bump FF_ASSET_VERSION on prod .env to 1.0.28
  Originating session: S-MILEAGE-3 (C4 — D-A + D-K close UI precharge refund picker + "Mark Refund Settled"
    button in app/admin/leases/show.php)
  Surfaced into checklist: S-MILEAGE-3 C4 (this file's edit commit, post-C4 push)
  Detail: C4 changed app/admin/leases/show.php substantially — added the Precharge Refund picker section
    inside the close modal (between Mileage Reconciliation panel and Close Notes; renders only when
    precharge_enabled=1 AND precharge_balance > 0) with cash/credit radio inputs + manager-notes textarea;
    added the "Mark Refund Settled" btn-warning to the precharge display row (renders only when
    status='completed' AND precharge_refund_method='cash' AND precharge_refund_settled_at IS NULL);
    extended closeForm state with precharge_refund_method (default 'credit') + precharge_refund_notes;
    closeLease() payload assembly carries the new precharge_refund block; new markRefundSettled() Alpine
    method POSTs to api/v1/leases/mark_refund_settled. Page-scoped <style> changes via inline styles only;
    no app.css edit in C4. Operator should bump dev .env (1.0.27 → 1.0.28) + apply same to prod .env at
    cutover.
    Original source: .env (gitignored — bumped by operator at session ship).
  Action: On prod .env (Lightsail instance), set FF_ASSET_VERSION=1.0.28. Restart php-fpm if asset version is
    cached in opcache; verify with curl -I https://<prod>/assets/css/app.css?v=1.0.28 returns 200.
  Owner: Operator
  Status: PENDING

ITEM A4 | 2026-05-14 | A — Asset cache | Bump FF_ASSET_VERSION on prod .env to 1.0.29
  Originating session: S-PROD-3 C2 — Google Fonts self-hosted (DM Sans + DM Mono via @font-face in app.css)
  Surfaced into checklist: S-PROD-3 C2 (this file's edit commit)
  Detail: C2 added 12 @font-face declarations to public/assets/css/app.css (new "00. Self-hosted Fonts"
    section at lines ~28-160) pointing at 12 woff2 files under public/assets/vendor/fonts/dm-sans/ +
    dm-mono/ via relative paths (../vendor/fonts/...). Removed the corresponding <link rel="preconnect">
    + <link rel="stylesheet"> Google Fonts blocks from 17 PHP templates across admin auth, portal auth,
    portal includes, admin includes, error pages, and the global error handler — replaced each with a
    single-line HTML comment marker pointing back to the new app.css section. Eliminates ~3 HTTP
    requests to fonts.googleapis.com + fonts.gstatic.com on every page load. Operator bumped dev .env
    (1.0.28 → 1.0.29) at session ship.
    Original source: .env (gitignored — bumped by operator at session ship).
  Action: On prod .env (Lightsail instance), set FF_ASSET_VERSION=1.0.29. Restart php-fpm if asset
    version is cached in opcache; verify with curl -I https://<prod>/assets/css/app.css?v=1.0.29
    returns 200 + verify DevTools Network shows zero requests to fonts.googleapis.com /
    fonts.gstatic.com on any page.
  Owner: Operator
  Status: PENDING

ITEM A5 | 2026-05-14 | A — Asset cache | Bump FF_ASSET_VERSION on prod .env to 1.0.30
  Originating session: S-DISPLAY-REVAMP C2 — collapsed sidebar nav-badge layout fix in app.css
  Surfaced into checklist: S-DISPLAY-REVAMP C2 (this file's edit commit)
  Detail: C2 added a 5-line CSS block under the existing "Collapsed sidebar (desktop)" media query in
    public/assets/css/app.css (after line 927) that zeroes min-width / padding / margin and adds
    overflow:hidden on `.sidebar:not(.is-open) .nav-badge`. The existing rule already set
    opacity:0 + max-width:0 on the same selector but `.nav-badge` carries `min-width: 20px` at line
    788 which beats max-width per CSS spec — the invisible badge stayed in flex flow at 20px wide and
    pushed nav-item-icons sideways in icon-only sidebar mode. The fix removes the badge from layout
    entirely while keeping it invisible + non-interactive. Operator bumped dev .env (1.0.29 → 1.0.30)
    at session ship.
    Original source: .env (gitignored — bumped by operator at session ship).
  Action: On prod .env (Lightsail instance), set FF_ASSET_VERSION=1.0.30. Restart php-fpm if asset
    version is cached in opcache; verify with curl -I https://<prod>/assets/css/app.css?v=1.0.30
    returns 200 + collapse the desktop sidebar (≥1024px viewport) on any admin page that has a
    badged nav item (e.g. /invoices when overdue_invoices count > 0) and confirm icons align
    vertically with no sideways shift.
  Owner: Operator
  Status: PENDING
```

### B — Production `.env` keys

```
ITEM B1 | 2026-05-12 | B — Prod .env | Populate AWS_ACCESS_KEY_ID + AWS_SECRET_ACCESS_KEY on prod .env
  Originating session: D9 storage abstraction (StorageClient — see PROGRESS.md DECISIONS D9) + D24 composer dual-keep
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Dev .env lines 48-49 are intentionally blank (STORAGE_DRIVER=local in dev → never hits S3).
    Prod must use STORAGE_DRIVER=s3 against bucket fleetforge-mainland in us-west-2 (.env line 50-51).
    Without these keys, every storage write (PDF uploads, invoice attachments, mileage docs) will fail
    once STORAGE_DRIVER is flipped to s3.
    Original source: .env lines 47-51; FLEETFORGE_PROGRESS.md DECISIONS D9 (StorageClient abstraction).
  Action: Create IAM user `fleetforge-prod` with PutObject/GetObject/DeleteObject on
    arn:aws:s3:::fleetforge-mainland/*. Generate access key. Set both keys on prod .env. Flip
    STORAGE_DRIVER=local → STORAGE_DRIVER=s3 on prod .env.
  Owner: Operator
  Status: PENDING
```

```
ITEM B2 | 2026-05-12 | B — Prod .env | Populate AWS_SES_SMTP_USER + AWS_SES_SMTP_PASS on prod .env
  Originating session: D10 mailer abstraction (Mailer — see PROGRESS.md DECISIONS D10)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Dev .env lines 56-57 are blank (dev sends to log file, not real SMTP). Prod must populate SES
    SMTP credentials against email-smtp.us-west-2.amazonaws.com:587 (.env lines 54-55 already point at
    the correct endpoint). Without these credentials, every transactional send (invoice email,
    password reset, MFA email) will fail.
    Original source: .env lines 53-57; FLEETFORGE_PROGRESS.md DECISIONS D10 (Mailer abstraction).
  Action: Create SES SMTP credentials (separate from IAM access key). Set both values on prod .env.
    Confirm SMTP_FROM_EMAIL is updated from noreply@yourdomain.com (placeholder) to the actual
    verified sender on the prod SES identity.
  Owner: Operator
  Status: PENDING
```

```
ITEM B3 | 2026-05-12 | B — Prod .env | Set SENTRY_DSN on prod .env
  Originating session: S-PROD-2 (SHIPPED 2026-05-02 — Sentry + SES bounce handler + key rotation runbook)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: S-PROD-2 (SHIPPED 2026-05-02 per PROGRESS.md SESSION LOG line 104) added `SENTRY_DSN`,
    `SENTRY_ENVIRONMENT`, and `SENTRY_TRACES_SAMPLE_RATE` to `.env.example` and wired the integration
    at `lib/Observability/Sentry.php`. Sentry::init() is no-op when DSN is blank (D-A / D75 — dev
    machines never send events without explicit opt-in). Deploy must populate the real DSN.
    Original source: lib/Observability/Sentry.php; FLEETFORGE_PROGRESS.md S-PROD-2 SESSION LOG row.
  Action: Create Sentry project `fleetforge-prod`, copy DSN, set `SENTRY_DSN` on prod .env (plus
    `SENTRY_ENVIRONMENT=production` and `SENTRY_TRACES_SAMPLE_RATE=0.1` if not already present).
    Verify by triggering a test exception and confirming it lands in Sentry.
  Owner: Operator
  Status: PENDING (ready — S-PROD-2 SHIPPED 2026-05-02; only operator-side Sentry-console + .env step remains)
```

```
ITEM B4 | 2026-05-12 | B — Prod .env | Set AWS_SNS_TOPIC_ARN on prod .env
  Originating session: S-PROD-2 (SHIPPED 2026-05-02 — SES bounce handler routes through SNS)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: S-PROD-2 (SHIPPED 2026-05-02) added `AWS_SNS_TOPIC_ARN` to `.env.example` and wired the
    handler at `api/v1/webhooks/ses_notifications.php` using `Aws\Sns\MessageValidator` for signature
    verification (rejects 403 on mismatch). Permanent bounces + complaints → set `email_disabled=1`
    on `customers` + `portal_users` (NOT a separate `email_suppressions` table — directly-on-row
    flag pattern per D77 design choice). Transient bounces → `email_bounces` audit row only.
    Note key name: existing dev `.env.example` uses `AWS_SNS_TOPIC_ARN` (not `AWS_SNS_BOUNCE_TOPIC_ARN`
    as the original checklist item title suggested).
    Original source: api/v1/webhooks/ses_notifications.php; FLEETFORGE_PROGRESS.md S-PROD-2 SESSION LOG row.
  Action: Create SNS topic `fleetforge-ses-bounces` in us-west-2, subscribe SES identity to it (Bounce
    + Complaint destinations), copy topic ARN, set `AWS_SNS_TOPIC_ARN` on prod .env. Verify by sending
    to a known-bouncing AWS SES test address (e.g. `bounce@simulator.amazonses.com`) and confirming
    the webhook fires + `email_disabled=1` is set on the affected row + `email_bounces` row created.
  Owner: Operator
  Status: PENDING (ready — S-PROD-2 SHIPPED 2026-05-02; only operator-side AWS SNS topic + .env step remains)
```

```
ITEM B5 | 2026-05-12 | B — Prod .env | Rotate APP_SECRET + FF_MFA_SECRET_KEY for prod (do not reuse dev)
  Originating session: S-PROD-2 (SHIPPED 2026-05-02 — key rotation runbook)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Dev .env lines 20 + 83 contain dev-only values. Prod must use freshly-generated keys
    (`openssl rand -hex 32`) to avoid CSRF token forgery / MFA secret decryption against leaked dev
    values. Rotation runbook at `docs/runbooks/key_rotation.md` (S-PROD-2 SHIPPED 2026-05-02) covers
    procedure for 5 secrets including `FF_MFA_SECRET_KEY` AES-256-CBC rotation and the `scripts/
    rotate_mfa_secret_key.php` follow-up flag (script not yet drafted per runbook §1 note).
    Original source: .env lines 18-20 + line 82-83; docs/runbooks/key_rotation.md.
  Action: Generate two new 32-byte hex values. Set APP_SECRET + FF_MFA_SECRET_KEY on prod .env.
    BEFORE first prod login, confirm no users have mfa_secret set (re-enrolment is required if dev
    DB is being migrated). Document the rotation date in docs/runbooks/key_rotation.md.
  Owner: Operator
  Status: PENDING (ready — S-PROD-2 SHIPPED 2026-05-02; runbook at docs/runbooks/key_rotation.md)
```

### C — DNS

```
ITEM C1 | 2026-05-12 | C — DNS | Create A record pointing fleetforge.<domain> at Lightsail static IP
  Originating session: AWS Lightsail cutover (queued multi-session — see CURRENT_SESSIONS.md)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Currently dev runs at http://fleetforge.test (.env line 10 — local /etc/hosts entry). Prod
    needs a real domain pointed at the Lightsail Oregon instance's static IP. Cloudflare is the
    expected DNS provider per AWS Lightsail cutover scoping notes; A record + AAAA optional + proxy
    mode "DNS only" (not orange-cloud) so Let's Encrypt can verify on the origin.
    Original source: FLEETFORGE_CURRENT_SESSIONS.md AWS Lightsail cutover entry.
  Action: Once Lightsail instance is provisioned (D1) + static IP attached: create Cloudflare A record
    fleetforge.<domain> → <static-IP>, proxy=DNS only, TTL auto. Update APP_URL on prod .env to
    https://<that-hostname>. Set up Let's Encrypt via certbot on the instance.
  Owner: Operator
  Status: PENDING
```

### D — AWS infrastructure

```
ITEM D1 | 2026-05-12 | D — AWS infra | Provision Lightsail instance in Oregon (us-west-2)
  Originating session: AWS Lightsail cutover (queued)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Per AWS Lightsail cutover entry, prod target is us-west-2 (matches .env AWS_REGION line 50).
    Instance sizing TBD as part of that session; checklist tracks the obligation only.
    Original source: FLEETFORGE_CURRENT_SESSIONS.md AWS Lightsail cutover entry.
  Action: Provision Lightsail instance (size + bundle to be decided in AWS Lightsail cutover session),
    OS = Ubuntu LTS, attach static IP, configure firewall (80/443/SSH only). Install PHP 8.2 + MySQL
    8.0 + nginx per FleetForge stack.
  Owner: Operator (in AWS Lightsail cutover session)
  Status: PENDING
```

```
ITEM D2 | 2026-05-12 | D — AWS infra | Enable Lightsail automatic snapshots (3-layer backup layer 1)
  Originating session: AWS Lightsail cutover (queued) — 3-layer backup architecture
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Layer 1 of the 3-layer backup architecture (Lightsail snapshots + S3 mysqldump every 6h
    + S3 versioning). Lightsail auto-snapshots are point-in-time disk snapshots — fast restore,
    full-instance granularity, 7-day retention default.
    Original source: FLEETFORGE_CURRENT_SESSIONS.md AWS Lightsail cutover entry; 3-layer backup
    architecture (memory entry).
  Action: In Lightsail console → instance → Snapshots tab → enable Automatic snapshots; set time
    window to off-peak (e.g. 09:00 UTC = 01:00 PST). Verify first snapshot completes within 24h.
  Owner: Operator
  Status: PENDING
```

```
ITEM D3 | 2026-05-12 | D — AWS infra | Create S3 bucket fleetforge-mainland in us-west-2
  Originating session: D9 storage abstraction
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Bucket name + region already declared in dev .env lines 50-51 — operator just needs to
    create it in prod AWS account. STORAGE_DRIVER=s3 (set in B1 action) reads bucket from this var.
    Original source: .env line 51; FLEETFORGE_PROGRESS.md DECISIONS D9.
  Action: aws s3 mb s3://fleetforge-mainland --region us-west-2. Block all public access (Public
    Access Block on). Default encryption = SSE-S3 (AES-256). Tag: app=fleetforge, env=prod.
  Owner: Operator
  Status: PENDING
```

```
ITEM D4 | 2026-05-12 | D — AWS infra | Enable S3 bucket versioning (3-layer backup layer 3)
  Originating session: AWS Lightsail cutover — 3-layer backup architecture
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Layer 3 of 3-layer backup — protects against destructive overwrites of uploaded artifacts
    (invoice PDFs, mileage docs, etc.). Versioning + a lifecycle policy that expires non-current
    versions after 90 days = recoverable accidental delete window without unbounded storage cost.
    Original source: 3-layer backup architecture (memory entry).
  Action: aws s3api put-bucket-versioning --bucket fleetforge-mainland --versioning-configuration
    Status=Enabled. Then attach lifecycle policy: NoncurrentVersionExpiration={NoncurrentDays:90}.
  Owner: Operator
  Status: PENDING (depends on D3)
```

```
ITEM D5 | 2026-05-12 | D — AWS infra | Set up mysqldump cron + S3 upload every 6h (3-layer backup layer 2)
  Originating session: AWS Lightsail cutover — 3-layer backup architecture
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Layer 2 of 3-layer backup — logical database backup, separate from Lightsail's
    block-device snapshots. Every 6h: mysqldump → gzip → s3://fleetforge-mainland/db-backups/
    <YYYY-MM-DD>/<HH>.sql.gz. Lifecycle: keep daily for 30 days, then drop to weekly for 90.
    Original source: 3-layer backup architecture (memory entry).
  Action: On Lightsail instance: install AWS CLI + configure with the IAM user from B1 scoped to
    PutObject on db-backups/* prefix. Add cron entry */6h running mysqldump --single-transaction
    --routines --triggers fleetforge | gzip | aws s3 cp - s3://.../<dated-path>. First run: manual
    invoke + verify object lands in S3 + downloadable + sql restorable to a scratch DB.
  Owner: Operator
  Status: PENDING (depends on D1, D3)
```

```
ITEM D6 | 2026-05-12 | D — AWS infra | Exit SES sandbox + verify production sending identity
  Originating session: D10 mailer abstraction
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: SES sandbox mode caps sends to verified recipients only. Prod cutover requires sandbox exit
    request + a verified domain identity (DKIM + SPF on the sending domain). SES SMTP creds (B2)
    don't work end-to-end until this lands.
    Original source: .env lines 54-60; FLEETFORGE_PROGRESS.md DECISIONS D10.
  Action: AWS Console → SES → Account dashboard → Request production access (include FleetForge
    use-case description). Verify the sending domain (DKIM records → Cloudflare per C1). Set up
    DMARC TXT record (p=quarantine, rua=mailto:postmaster@<domain>).
  Owner: Operator
  Status: PENDING (depends on C1 for DNS records)
```

```
ITEM D7 | 2026-05-12 | D — AWS infra | Create SNS topic fleetforge-ses-bounces + subscribe SES identity
  Originating session: S-PROD-2 (SHIPPED 2026-05-02)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: SNS topic that SES publishes bounce/complaint events into. Webhook handler at
    api/v1/webhooks/ses_notifications.php (S-PROD-2 SHIPPED 2026-05-02) subscribes; SubscriptionConfirmation
    auto-confirms by curl-fetching the SubscribeURL on first delivery. Pairs with B4 (env key) +
    I2 (CloudWatch alarm on bounce rate).
    Original source: api/v1/webhooks/ses_notifications.php; FLEETFORGE_PROGRESS.md S-PROD-2 SESSION LOG row.
  Action: aws sns create-topic --name fleetforge-ses-bounces --region us-west-2. In SES Console →
    Verified identity → Notifications → set Bounce + Complaint destinations to this SNS topic.
    Subscribe HTTPS endpoint https://<prod>/webhooks/ses_notifications (mapped from
    api/v1/webhooks/ses_notifications.php via public/index.php router). Confirm subscription via
    the auto-confirm callback handled by the shipped endpoint.
  Owner: Operator
  Status: PENDING (ready — S-PROD-2 SHIPPED 2026-05-02; only operator-side AWS SNS + SES wiring remains)
```

```
ITEM D8 | 2026-05-12 | D — AWS infra | Provision IAM users + scoped policies (S3 read/write, SES send)
  Originating session: D9 + D10 + AWS Lightsail cutover
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Single consolidated IAM provisioning step covering all access keys referenced in B1, B2, D5.
    Principle of least privilege: app-side user can PutObject/GetObject/DeleteObject on
    fleetforge-mainland/* + SendRawEmail via SES; backup-side user can PutObject on
    db-backups/* only.
    Original source: aggregated from D9 + D10 + B1 + B2 + D5.
  Action: Create two IAM users: `fleetforge-app` (S3 R/W on bucket prefix uploads/*, SES SendRawEmail
    on verified identity), `fleetforge-backup` (S3 PutObject on bucket prefix db-backups/*). Inline
    policies, no console access, generate programmatic access keys for each. Rotate quarterly per
    S-PROD-2 key rotation runbook.
  Owner: Operator
  Status: PENDING (informs B1, B2, D5)
```

```
ITEM D9 | 2026-05-12 | D — AWS infra | Set up CloudWatch billing alarm + SES bounce rate alarm
  Originating session: AWS Lightsail cutover + S-PROD-2
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Two minimum baseline alarms: (1) AWS billing alarm at $50/mo to catch cost runaway from
    misconfigured backup retention or attack-driven traffic; (2) SES bounce rate ≥ 5% to catch
    reputation-damaging send patterns before SES auto-pauses the account.
    Original source: AWS Lightsail cutover + S-PROD-2 monitoring scope.
  Action: CloudWatch → Alarms → create: (1) AWS/Billing EstimatedCharges > 50 → SNS notify
    operator@<domain>. (2) AWS/SES Reputation.BounceRate > 5% over 1h → SNS same. Note SES alarm
    requires SES → Reputation tab → enable CloudWatch publication first.
  Owner: Operator
  Status: PENDING
```

### E — Data migrations

*(No items currently — placeholder. Add here for any one-time prod-only SQL backfill or correction.)*

### F — Accounting state

```
ITEM F1 | 2026-05-02 | F — Accounting | AR drift remediation: idempotency confirmed; no further action pre-cutover
  Originating session: S-FIX-2 (commit ref in PROGRESS.md SESSION LOG, 2026-05-02) +
    scripts/fix_counter_drift_2026_05_02.php
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: $61,844.80 of customers.outstanding_balance drift + $75,995.60 of lease drift was
    reconciled in S-FIX-2 against the canonical Path B semantics (sent-only counter, locked as D45).
    Idempotency was confirmed — re-running the script is a no-op. For prod cutover this item is
    DEFERRED-TO-QBO: once QuickBooks Online integration ships (S-QBO arc), QBO becomes source of
    truth for customer-side AR and the FleetForge-side counters become a cached projection. No
    pre-deploy action is required for the cutover itself; this item is here to document the deferral.
    Original source: FLEETFORGE_PROGRESS.md SESSION LOG S-FIX-2 row (2026-05-02); memory entry
    "project_path_b_counter_semantics.md"; memory entry "project_drift_remediation_history.md".
  Action: No pre-deploy action. On QBO cutover (S-QBO-4 / S-QBO-5), revisit: confirm sent-invoice
    counter on FleetForge matches QBO's invoice ledger; if drift surfaces again, fix script is
    idempotent and can be re-run.
  Owner: Deferred (to QBO sync — S-QBO arc)
  Status: ✅ COMPLETE (S-FIX-2 / 2026-05-02 — pre-cutover state is correct; QBO will subsume)
```

### G — Smoke + verification procedures

```
ITEM G1 | 2026-05-12 | G — Smoke | Run tests/_smoke_master_schema_parity.php against prod DB
  Originating session: D131 smoke gate discipline
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: PARITY smoke verifies that prod's MySQL schema exactly matches database/master_schema.sql.
    A divergence here means a migration was skipped or applied differently than dev — must be
    reconciled BEFORE invariants smoke (G2) and before opening prod traffic.
    Original source: FLEETFORGE_PROGRESS.md DECISIONS D131; tests/_smoke_master_schema_parity.php.
  Action: After D1+D5 land + DB is migrated from latest mysqldump or via fresh schema apply:
    php tests/_smoke_master_schema_parity.php on the prod instance. Expect "PARITY OK". Any
    "EXTRA" / "MISSING" / "TYPE MISMATCH" output blocks cutover.
  Owner: Operator (post-deploy, pre-traffic)
  Status: PENDING
```

```
ITEM G2 | 2026-05-12 | G — Smoke | Run tests/_smoke_billing_invariants.php against prod DB (I1-I10)
  Originating session: D131 smoke gate discipline
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: INVARIANTS smoke validates the 10 cross-cutting billing invariants (I1-I10) that the
    full-system money math depends on (Path B sent-only counter consistency, invoice-vs-payment
    sign agreement, etc.). I7 was added in S-MILEAGE-2A C6 (precharge tier D132 extension);
    I8 in S-MILEAGE-2B C7 (drawdown emit shape); I9 in S-MILEAGE-3 C6 (close-refund state
    machine); I10 in S-MILEAGE-5 C2 (credit/cash branch precharge_balance cross-check). Any
    I-failure means data is in an inconsistent state and prod traffic must not open.
    Original source: FLEETFORGE_PROGRESS.md DECISIONS D131; tests/_smoke_billing_invariants.php.
  Action: After G1 PASS: php tests/_smoke_billing_invariants.php on prod. Expect "INVARIANTS OK"
    with all of I1-I10 PASS. Any I-fail blocks cutover.
  Owner: Operator (post-deploy, pre-traffic)
  Status: PENDING
```

```
ITEM G3 | 2026-05-12 | G — Smoke | E2E: login → dashboard → invoice send → email receipt
  Originating session: S-PROD-1A (FF_TEST_* credentials introduced for SC8 sweep)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: End-to-end manual smoke: log in as FF_TEST_ADMIN (.env lines 91-92), navigate dashboard,
    create + send an invoice to a real test address, verify the SES email arrives + render is
    correct + PDF attaches. Catches the slice of integrations that smoke-PHP can't (SES delivery,
    PDF generation under prod mpdf config, browser-side rendering against prod CSS post-FF_ASSET_VERSION
    bump A1).
    Original source: .env lines 86-96 (test credentials block, S-PROD-1A-FIX-5).
  Action: After G2 PASS: from a real browser against prod URL, complete the flow above. Verify
    every step. If FF_TEST_ADMIN doesn't exist in prod DB, see G4.
  Owner: Operator (post-deploy, pre-traffic)
  Status: PENDING
```

```
ITEM G4 | 2026-05-12 | G — Smoke | Confirm FF_TEST_* users exist in prod DB OR run seed script
  Originating session: S-PROD-1A-FIX-5
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: The three FF_TEST_* accounts (sc8_test, sc8_mfa, portal/john@apexfreight.com) were seeded
    in dev for the SC8 automated sweep. Prod DB needs the same accounts to run G3 end-to-end + any
    future automated regression sweep. Decision pending: (a) seed them in prod (treats them as
    real ops accounts), or (b) skip + replace G3 with a real operator-account login (cleaner prod
    DB, manual smoke only).
    Original source: .env lines 86-96; S-PROD-1A-FIX-5 reference in same.
  Action: Operator decision: (a) port the SC8 seed script to prod + run once, OR (b) document G3
    as manual-login with a real operator account and skip seeding. Flip Status to ✅ COMPLETE once
    decided + executed.
  Owner: Operator (decision pending)
  Status: PENDING
```

### H — Rollback procedures

```
ITEM H1 | 2026-05-12 | H — Rollback | Document + dry-run Lightsail snapshot restore procedure
  Originating session: AWS Lightsail cutover — 3-layer backup architecture
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Layer-1 rollback path: restore from Lightsail automatic snapshot (D2). Dry-run procedure:
    create snapshot, provision a temporary instance from it, confirm app boots + DB intact, destroy
    the temporary instance. Operator must walk through this AT LEAST ONCE before cutover so the
    procedure is rehearsed, not improvised under pressure.
    Original source: 3-layer backup architecture (memory entry).
  Action: After D2 has produced at least one snapshot: in Lightsail console → snapshot → Create
    new instance from snapshot (use 1-CPU bundle). SSH in, run G1 smoke against the restored DB,
    confirm app responds on its temp IP. Destroy the temp instance + document the steps + timing
    in a RUNBOOK_ROLLBACK.md (or equivalent section in this checklist's J references).
  Owner: Operator
  Status: PENDING (depends on D2 producing a snapshot)
```

```
ITEM H2 | 2026-05-12 | H — Rollback | Document + dry-run mysqldump restore from S3
  Originating session: AWS Lightsail cutover — 3-layer backup architecture (layer 2)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Layer-2 rollback path: replay a specific mysqldump archive from S3 (D5) onto a clean
    MySQL instance. This is the granular-DB rollback — useful when Lightsail snapshot is too
    coarse (e.g. recovering a single corrupted table without rolling back app code or file uploads).
    Dry-run validates the most recent backup archive is actually restorable (catches gzip
    corruption or schema drift between dump time and restore time).
    Original source: 3-layer backup architecture (memory entry).
  Action: After D5 has produced at least one backup: on a scratch MySQL 8.0 instance, aws s3 cp
    s3://fleetforge-mainland/db-backups/<latest>.sql.gz - | gunzip | mysql scratch_fleetforge.
    Run G1 smoke against scratch DB. Document timing (full restore should be < 10 min for current
    data volume) + steps in RUNBOOK_ROLLBACK.md.
  Owner: Operator
  Status: PENDING (depends on D5 producing a backup)
```

### I — Post-deploy monitoring

```
ITEM I1 | 2026-05-12 | I — Monitoring | Sentry alert routing to operator email/SMS
  Originating session: S-PROD-2 (SHIPPED 2026-05-02)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: Sentry SDK integrated at lib/Observability/Sentry.php (S-PROD-2 SHIPPED 2026-05-02) and
    wired into api/bootstrap.php + includes/header.php + app/portal/includes/header.php + 19 cron
    files. Project + alert routing are operator-side Sentry-console steps post-B3. Default new-issue
    email is OK as a baseline; consider PagerDuty/SMS for production-level severity later.
    Original source: lib/Observability/Sentry.php; FLEETFORGE_PROGRESS.md S-PROD-2 SESSION LOG row.
  Action: In Sentry project Settings → Alerts → create rule: "New issue in environment=production"
    → action: email operator@<domain>. Trigger a test exception (e.g. throw new \RuntimeException
    in a dev branch on prod) to verify routing end-to-end. Disable test path before flipping
    cutover.
  Owner: Operator
  Status: PENDING (ready — S-PROD-2 SHIPPED 2026-05-02; only operator-side Sentry-console alert rule + SENTRY_DSN prod .env step remains)
```

```
ITEM I2 | 2026-05-12 | I — Monitoring | SES bounce/complaint rate dashboard
  Originating session: S-PROD-2 (queued)
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: SES has built-in reputation metrics (Bounce Rate, Complaint Rate, Delivery Rate) — but
    they're buried in the SES console. Pinning to a CloudWatch dashboard makes them at-a-glance.
    Pairs with D9 alarm (bounce > 5%) for proactive notification.
    Original source: D7 + S-PROD-2.
  Action: CloudWatch → Dashboards → Create dashboard "fleetforge-prod-email"; add widgets for
    AWS/SES Reputation.BounceRate, Reputation.ComplaintRate, Send, Bounce, Complaint over 1h
    + 24h windows. Bookmark URL for operator.
  Owner: Operator
  Status: PENDING
```

```
ITEM I3 | 2026-05-12 | I — Monitoring | Lightsail CPU/RAM/disk baseline + alarm
  Originating session: AWS Lightsail cutover
  Surfaced into checklist: S-PREDEPLOY-CHECKLIST-CREATE
  Detail: After 7-14 days of prod traffic, observe baseline CPU/RAM/disk and set alarms 1.5×-2×
    baseline for early-warning of resource exhaustion. Disk especially matters because mysqldump
    + asset uploads grow over time; a disk-full event takes the whole app down.
    Original source: AWS Lightsail cutover monitoring scope.
  Action: 7-14 days post-cutover: pull Lightsail metrics → set CPU > 70% sustained 5min → SNS
    alarm; Disk > 80% → SNS alarm; RAM > 80% sustained 5min → SNS alarm. Document baseline
    numbers in RUNBOOK_MONITORING.md for future tuning.
  Owner: Operator (post-cutover)
  Status: PENDING
```

---

## J — References

- `FLEETFORGE_PROGRESS.md` → DECISIONS table → **D9** (StorageClient S3 abstraction), **D10** (Mailer SES abstraction), **D24** (composer keeps both mpdf + aws-sdk-php), **D131** (smoke gate discipline), **D45** (Path B counter semantics — F1 context).
- `FLEETFORGE_PROGRESS.md` → KEY LEARNINGS → **K-12** (documentation divergence as bug class), **K-14** (category separation discipline — this file's basis).
- `FLEETFORGE_PROGRESS.md` → SESSION LOG → **S-PROD-1A** (FF_TEST_* credentials introduced — G3 context), **S-FIX-2** (AR drift remediation — F1 context), **S-INVOICE-DISPLAY-COMPREHENSIVE** (A1 origin).
- `FLEETFORGE_CURRENT_SESSIONS.md` → **AWS Lightsail cutover** (parent queued multi-session — consumes D1-D9, H1-H2, I1-I3), **S-PROD-2** (Sentry + SES bounce handler + key rotation runbook — provides B3, B4, B5, D7, I1, I2), **S-PROD-3** (self-host CDN deps — adjacent prod-prep, separate file).
- `FLEETFORGE_CLAUDE_CODE_REFERENCE.md` → §0 LOCKED DECISIONS for pointer to this file's role; Pre-flight check section for the discipline rule.
- Memory entries: `project_path_b_counter_semantics.md` (F1), `project_drift_remediation_history.md` (F1).
- 3-layer backup architecture: Lightsail snapshots (D2/H1) + S3 mysqldump every 6h (D5/H2) + S3 versioning (D4) — see memory entries for original framing.

---

## Completed items (rolling log)

*(Items move here once cutover is end-to-end verified. Format: same as Active, with the ✅ COMPLETE line tail-anchored.)*

— *no items yet —*

---

*Last touched: 2026-05-14 (S-DISPLAY-REVAMP C2 — A5 entry added for FF_ASSET_VERSION 1.0.29 → 1.0.30 collapsed sidebar nav-badge layout fix in app.css). Prior touches: 2026-05-14 (S-CHECKLIST-WORDING-FIX — I1 Status annotation added + Owner "(depends on B3)" qualifier removed; G2 heading parenthetical I1-I6 → I1-I10; Last-touched stamp refresh. Surfaced by S-PREDEPLOY-FULL-VERIFY 2026-05-13); 2026-05-14 (S-PROD-3 C2 — A4 entry added for FF_ASSET_VERSION 1.0.28 → 1.0.29 self-hosted Google Fonts CSS change); 2026-05-13 (S-PROD-2-DOCS-RECONCILE — B3/B4/B5/D7/I1 detail-line + status-line flips from "blocked on S-PROD-2" → "ready, S-PROD-2 SHIPPED 2026-05-02"; corrects the S-CHECKLIST-DRIFT-FIX C2 phantom-QUEUED drift); 2026-05-13 (S-CHECKLIST-DRIFT-FIX — G2 invariant range bump I1-I6 → I1-I10 with origin-session citations; S-PROD-2 explicit queue reference, since corrected).*
