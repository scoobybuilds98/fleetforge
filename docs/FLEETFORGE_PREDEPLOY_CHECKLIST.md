# FleetForge — Pre-deploy Checklist

**Canonical pre-deploy operations file.** Lives alongside `FLEETFORGE_PROGRESS.md` (history), `FLEETFORGE_CURRENT_SESSIONS.md` (queue), and `FLEETFORGE_CLAUDE_CODE_REFERENCE.md` (lookups). Created 2026-05-12 via session **S-PREDEPLOY-CHECKLIST-CREATE**; discipline locked as **K-14** in PROGRESS.md KEY LEARNINGS.

---

## Handoff status (HANDOFF-PREP 2026-05-13 + S-DOCS-RECONCILE-FULL refresh 2026-05-17)

**Last updated:** 2026-05-17 by S-DOCS-RECONCILE-FULL — migration count + asset version refreshed against on-disk reality.

**Platform state at handoff:**
- **Stack:** PHP 8.2 / MySQL 8.0 / Alpine.js / ApexCharts v3 / mPDF / AWS SDK (per D24 composer.json carries BOTH `mpdf/mpdf` AND `aws/aws-sdk-php`).
- **Local dev:** Laravel Herd at `http://fleetforge.test/fleetforge/`; MySQL via Homebrew at `127.0.0.1:3306`.
- **Migrations:** 26 migration files in `db_migrations/`, **26 applied / 0 drift / 0 missing** per `bin/migrate.php --verify`. (Bumped from 19 at HANDOFF-PREP via S-BILLING-HOLISTIC-ENGINE +6 migrations 0100-0105 on 2026-05-17, and S-DESIGN-SETTINGS-FOOTER-LOGIN +1 migration 202605170200.) Legacy `database/migrations/` directory (12 historical pre-runner manual applies, 2026-04-05 → 2026-05-03) audited + archived to `scripts/archive/legacy_database_migrations/` by S-MIGRATION-AUDIT (2026-05-17) — all 12 verified safe duplicates against live DB + master schema; not registered in `schema_migrations`.
- **FF_ASSET_VERSION:** 1.0.30 in dev `.env` (gitignored). **Filed under A5 in this checklist — bump on prod before deploy.** Prior level 1.0.28 stamp was from S-MILEAGE-3 (2026-05-13); subsequent bumps S-PROD-3 → 1.0.29 (A4) and S-DISPLAY-REVAMP → 1.0.30 (A5) on 2026-05-14.
- **SHIPPED sessions:** 30+ labels currently in `CURRENT_SESSIONS.md` Recent ship history (2026-05-13 → 2026-05-17). Tier 1/2/3 feature work complete, plus production-deployment (2026-05-16) + post-deployment fixes (2026-05-17). Doc freshness smoke status unknown post-2026-05-17 (not run as part of this docs reconcile).
- **Model B mileage arc closed:** engine (S-MILEAGE-1 → -2A → -2B → -3 → -3-FIX-0 → -5) + portal + helper retirement (S-PORTAL-MILEAGE-MODEL-B) all SHIPPED 2026-05-13. Final retirement of `Mileage::monthlyAllowance` complete.
- **Production deploy status (2026-05-16):** partially live at https://mainlandrentals.com/fleetforge — Lightsail Oregon us-west-2, static IP 44.226.100.133, SSL via Let's Encrypt, super admin verified. Open punch list: B6 + D10-D13 + E1 + G5-G6 + I4 (9 items below).
- **Holistic billing engine live (2026-05-17):** S-BILLING-HOLISTIC-ENGINE locked all 23 active/pending leases to `engine_version='period_independent'`; new leases default to `'holistic'`. Phased migration — old leases continue on ProRateCalculator until close, new leases use running-reconciliation `lib/Billing/HolisticLeaseEngine.php`.
- **Design + branding live (2026-05-17):** S-DESIGN-SETTINGS-FOOTER-LOGIN added Settings → Design tab (super_admin) with brand color picker + logo/favicon upload + 19 seeded settings rows. Follow-up arc (S-DESIGN-SETTINGS-DEBUG + S-DESIGN-LOGO-TOPBAR + -BIGGER + -WIDE) iterated logo sizing + relocated company name to topbar breadcrumb root. S-DASHBOARD-CHART-POLISH closed dashboard chart-reflow + grid + widget-cropping bugs in 3-commit arc.
- **Pending non-blocking:** `S-MILEAGE-3-ACCT-SPEC` (CPA-blocked, D-I (A) / D176); `S-COMPOSER-LOCK-FIX` + `S-RATE-CARDS-PROD-FIX` (server-side, queued from 2026-05-16 deployment).
- **Repo:** `/Users/avi/Documents/fleetforge` on `origin/main`. Untracked at audit time: `lib/Billing/FleetForge_Holistic_Billing_Engine_Spec.docx` (operator's reference spec, not committed) + `storage/branding/` (uploaded brand assets — gitignored class).
- **Canonical docs:** all `FLEETFORGE_*.md` files in `docs/` subfolder per DOCS-REORG (2026-05-13); `FLEETFORGE_DATABASE_MASTER.sql` at repo root per SEVEN FILES convention (REFERENCE.md §1).

**Smoke-gate state (D131 four-gate suite, as of 2026-05-14 last published — not refreshed in this docs reconcile):**
- `php tests/_smoke_doc_freshness.php` → was 17/17 PASS exit 0 at S-NOTIFICATIONS-FULL 2026-05-14
- `php tests/_smoke_master_schema_parity.php` → was PARITY OK at S-BILLING-HOLISTIC-ENGINE 2026-05-17 (after audit-column repositioning)
- `php tests/_smoke_billing_invariants.php` → was INVARIANTS OK I1-I10 at S-BILLING-HOLISTIC-ENGINE 2026-05-17
- `php bin/migrate.php --verify` → 26 ok / 0 drift / 0 missing (2026-05-17 reconcile)

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
  Status: DONE (2026-06-06 — SENTRY_DSN + SENTRY_ENVIRONMENT=production + SENTRY_TRACES_SAMPLE_RATE=0.1 on prod .env; verified live, event 3a4278ea ingested env=production. Surfaced+fixed latent before_send scrubber crash → S-SENTRY-SCRUBFIX.)
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

ITEM B6 | 2026-05-16 | B — Prod .env | SES SMTP credentials
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: SES sandbox exit request submitted 2026-05-16. Once AWS approves (email to
    mainlandtts@gmail.com, typically 24-72h): go to SES console → SMTP settings → Create SMTP
    credentials → copy username + password. SSH into server: sudo nano /var/www/fleetforge/.env
    → fill in AWS_SES_SMTP_USER and AWS_SES_SMTP_PASS. Reload PHP-FPM:
    sudo systemctl reload php8.2-fpm. Send a test email from FleetForge to verify delivery
    (e.g. invite a test user or trigger a password reset).
    Original source: 2026-05-16 deployment session notes.
  Action: Wait for AWS approval email → create SMTP credentials in SES console → SSH into
    server → update .env → reload PHP-FPM → send a test email and confirm receipt.
  Owner: Operator
  Status: PENDING (blocked on SES sandbox approval)
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

ITEM D10 | 2026-05-16 | D — AWS infrastructure | Set up cron jobs on prod (DB backup + app crons)
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: Two cron jobs needed on the Lightsail instance:
    (1) Database backup every 6 hours — mysqldump → gzip → push to S3
        s3://fleetforge-mainland/db-backups/. Requires AWS CLI configured with fleetforge-backup
        IAM credentials.
        Command: mysqldump -u fleetforge -p fleetforge | gzip |
          aws s3 cp - s3://fleetforge-mainland/db-backups/fleetforge-$(date +%Y%m%d-%H%M%S).sql.gz
          --region us-west-2
    (2) App crons every minute — php /var/www/fleetforge/bin/cron.php. Handles invoice overdue
        checks, Samsara sync, promise-to-pay checks, health/risk scores, and other scheduled tasks.
    Install: sudo nano /etc/cron.d/fleetforge
    Install AWS CLI first: sudo apt install awscli -y
    Configure backup credentials: aws configure (use fleetforge-backup IAM key/secret, region
    us-west-2).
    Overlaps with D5 (mysqldump cron) + D8 (fleetforge-backup IAM user) at architectural level;
    D10 is the concrete server-side install step.
    Original source: 2026-05-16 deployment session notes.
  Action: SSH into server → sudo apt install awscli -y → aws configure (fleetforge-backup creds)
    → create /etc/cron.d/fleetforge with both cron entries → verify with sudo crontab -l → test
    the mysqldump cron manually + confirm a backup appears in s3://fleetforge-mainland/db-backups/.
  Owner: Operator
  Status: PENDING

ITEM D11 | 2026-05-16 | D — AWS infrastructure | Test S3 storage end-to-end
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: STORAGE_DRIVER=s3 is set in prod .env and fleetforge-app IAM credentials are configured
    (B1 / D8). Need to verify end-to-end: upload a document in FleetForge (e.g. attach a file to a
    damage claim or upload a customer document) → confirm the file appears in
    s3://fleetforge-mainland/ in the AWS console under the expected prefix. If upload fails,
    check PHP error logs: sudo tail -f /var/log/nginx/error.log + sudo tail -f /var/log/php8.2-fpm.log.
    Original source: 2026-05-16 deployment session notes.
  Action: Log into FleetForge → upload any document → check S3 bucket in AWS console → confirm
    file is present. If failure: inspect server-side logs per I4.
  Owner: Operator
  Status: PENDING

ITEM D12 | 2026-05-16 | D — AWS infrastructure | CloudWatch billing alarm at $50/month
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: AWS billing alarm not yet configured. Should alert when estimated charges exceed
    $50/month. AWS Console → CloudWatch → Alarms → Create alarm → Billing → Total Estimated
    Charge → threshold > 50 → SNS topic → email mainlandtts@gmail.com.
    Conceptually the same target as D9 part (1), surfaced separately here because the operator
    is working through the deployment punch list and may want to action it independently from
    the SES bounce-rate alarm.
    Original source: 2026-05-16 deployment session notes.
  Action: AWS Console → CloudWatch → Alarms → Create alarm per above. Confirm the SNS topic
    subscription via the email confirmation link.
  Owner: Operator
  Status: PENDING

ITEM D13 | 2026-05-16 | D — AWS infrastructure | SNS topic + SES bounce webhook subscription
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: SNS topic for SES bounce/complaint handling not yet created. Steps:
    (1) AWS Console → SNS → Create topic → Standard → name: fleetforge-ses-bounces → note the ARN.
    (2) Create HTTPS subscription pointing to
        https://mainlandrentals.com/fleetforge/api/v1/webhooks/ses_notifications.php
        — the app auto-confirms the subscription via the SubscriptionConfirmation handshake.
    (3) SSH into server → sudo nano /var/www/fleetforge/.env → set
        AWS_SNS_BOUNCE_TOPIC_ARN=<ARN> → sudo systemctl reload php8.2-fpm.
    (4) AWS Console → SES → Verified identities → mainlandrentals.com → Notifications → Edit
        → set Bounces + Complaints to the fleetforge-ses-bounces topic.
    Overlaps with B4 (AWS_SNS_TOPIC_ARN env key) + D7 (SNS topic creation) at architectural
    level; D13 is the concrete server-side stitching step.
    Original source: 2026-05-16 deployment session notes.
  Action: Create SNS topic → subscribe webhook endpoint → update .env → configure SES to use the
    topic for bounce + complaint notifications.
  Owner: Operator
  Status: PENDING (also blocked on SES sandbox approval per B6)

ITEM D-YE-1 | 2026-05-19 | D — AWS infrastructure | Year-end packages to S3 via StorageClient
  Originating session: S037-YE (Year-End Close Workflow)
  Surfaced into checklist: S037-YE
  Detail: YearEndService::generatePackage() currently writes the year-end ZIP package
    + manifest.json + per-report PDFs to the local filesystem under
    storage/year_end_packages/{fiscal_year}/. Production storage MUST route through
    StorageClient::upload() per D9 so packages land in the S3 bucket and survive
    server replacement. Today: local dev storage works correctly; prod will lose
    packages on server rebuild. Wiring point: lib/Accounting/YearEndService.php
    generatePackage() — replace file_put_contents + mPDF::Output(FILE) with
    StorageClient calls and update acc_year_end_closures.package_path to the S3 key.
    Cross-check api/v1/accounting/year-end/package_download.php which uses
    FF_ROOT/storage/... — switch to StorageClient::url() signed-URL serving.
  Action: Refactor YearEndService::generatePackage() + package_download.php to use
    StorageClient before first production year-end close.
  Owner: Operator (refactor) + Code Desktop (implementation)
  Status: PENDING

ITEM D-YE-2 | 2026-05-19 | D — AWS infrastructure | Verify ZipArchive PHP extension on prod
  Originating session: S037-YE (Year-End Close Workflow)
  Surfaced into checklist: S037-YE
  Detail: YearEndService::generatePackage() requires the ZipArchive PHP extension to
    build the year-end ZIP. Most stock PHP builds ship with it enabled but verify on
    the production Lightsail server before first year-end close:
      php -m | grep -i zip   → should output "zip"
    If missing: sudo apt-get install php8.2-zip && sudo systemctl reload php8.2-fpm
  Action: SSH to prod server, run `php -m | grep -i zip`, install if missing.
  Owner: Operator
  Status: PENDING
```

### E — Data migrations

```
ITEM E1 | 2026-05-16 | E — Data | Seed Standard 2025 rate cards on prod
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: The Standard 2025 rate card migration
    (202605130934_S-SEED-RATE-CARDS-LOAD_standard_2025_default.sql) failed during deployment due
    to FK constraint (created_by user didn't exist yet on the fresh prod DB). Rate cards are
    currently EMPTY in production. S-RATE-CARDS-PROD-FIX (queued in CURRENT_SESSIONS.md) will
    fix the migration file to use NULL for created_by; this E1 item tracks the operator-side
    follow-up of running the fixed migration on prod once that session ships.
    Target rate values (per S-SEED-RATE-CARDS-LOAD precedent):
      dry_van  $125/$800/$2200/$0.18
      reefer   $145/$950/$3200/$0.18
      flatbed  $120/$780/$2100/$0.17
      container $95/$620/$1700/$0.15
      chassis   $80/$520/$1400/$0.13
    Original source: 2026-05-16 deployment session notes + db_migrations/202605130934_*.sql.
  Action: Wait for S-RATE-CARDS-PROD-FIX to ship → SSH into server → cd /var/www/fleetforge →
    sudo php bin/migrate.php --apply → verify rate cards appear in FleetForge UI under
    Settings → Rates (or via mysql: SELECT * FROM rate_cards WHERE is_default=1).
  Owner: Operator (after S-RATE-CARDS-PROD-FIX ships)
  Status: PENDING (blocked on S-RATE-CARDS-PROD-FIX)
```

```
ITEM E-DEPLOY-RUNBOOK | 2026-05-20 | E — Data | Post-push deploy sequence (migrate + php-fpm reload) — RECURRING
  Originating session: 2026-05-20 — post-S-PERM-SESSION-REFRESH live-prod incident
  Surfaced into checklist: 2026-05-20 — operator reported HTTP 500 ("An unexpected error occurred") on
    mainlandrentals.com when trying to grant Bob Manager accounting_settings.view, ~1 hour after the
    S-PERM-SESSION-REFRESH commit (c3684d4) was pushed to origin/main. Root cause was a deploy gap:
    git push delivered the new code to the Lightsail filesystem, but the operator never (a) ran
    `migrate.php --apply` to add the new `users.permissions_updated_at` column AND (b) reloaded
    php-fpm to clear opcache so the new `_ff_check_permission_freshness()` function in
    `includes/auth.php` would be visible to the running workers.
  Detail: Every git push to origin/main that includes EITHER a new migration in db_migrations/ OR
    code changes to widely-included PHP files (`includes/**.php`, `api/bootstrap.php`,
    `config/*.php`, `lib/Accounting/*.php`) MUST be followed by a coordinated 3-step sequence on the
    production server. Skipping any one step produces opaque "An unexpected error occurred"
    messages in the UI because:
      - Skipped migrate → SQL errors (column not found, table not found) at the next request that
        hits the new code path. The api/bootstrap.php exception handler catches the PDOException
        and returns the generic INTERNAL_ERROR envelope; the actual error message is hidden in
        production (FF_DEBUG is off).
      - Skipped php-fpm reload → opcache holds the pre-push bytecode. New top-level function
        declarations are invisible to workers; calls to them throw "Call to undefined function"
        which the same exception handler catches and hides behind the same generic message.
        See Trap 52 in docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md §11 for the full opcache-staleness
        explanation; production is affected ONLY when deploys skip step 3 below (systemd reload
        normally clears opcache automatically as a side effect of FPM master restart).
      - Skipped schema_quick_ref regen (F-SCHEMA-REF-1) → not a runtime failure, but causes K-22
        drift in future session prompts. Bundle into this same deploy sequence.
    Both steps must be coordinated within a few minutes of the git push; longer gaps mean every
    page load from authenticated users hits the same error and they assume the system is down.
    Original source: 2026-05-20 post-S-PERM-SESSION-REFRESH live-prod incident.
  Action — REQUIRED post-push deploy sequence on mainlandrentals.com:
    SSH to mainlandrentals.com, then ALWAYS run these 3 commands in order, every push
    (no decision-tree — see "Why always 3" below):

         cd /var/www/fleetforge
         sudo -u www-data git pull origin main
         sudo -u www-data php bin/migrate.php --apply
         sudo systemctl reload php8.2-fpm

    Then smoke-check in a browser: open https://mainlandrentals.com/fleetforge/dashboard,
    log in, exercise one path that touches the newly-deployed code (or any authenticated
    page). Expect HTTP 200, no "An unexpected error occurred" in the UI.

    Why always 3 (no decision-tree): each command is safe + cheap to run when "not strictly
    needed", and the cost of always running them (≈1.5 seconds total overhead per deploy)
    is far less than the cost of forgetting one. The 2026-05-19 S-PERM-SESSION-REFRESH
    incident happened because the operator skipped migrate + reload thinking the commit
    "looked small" — but it added a schema column AND a top-level function in
    includes/auth.php. With this "always 3" rule, the decision point that caused the
    incident is removed entirely.

      - `git pull` when already up to date → "Already up to date." no-op
      - `migrate.php --apply` when no pending migrations → verifies against ledger, exits
        in ~50ms. Idempotent.
      - `systemctl reload php8.2-fpm` → graceful reload (< 1 second, NOT a restart — no
        in-flight requests dropped). Worker processes recycled with new bytecode. Safe to
        run when nothing changed.

    Schema quick-ref regeneration is a SEPARATE step done on a workstation after the
    deploy (per F-SCHEMA-REF-1), NOT on the production server. Production deploy.sh
    intentionally does not touch the quick-ref file to avoid drift between prod's
    auto-regenerated copy and what's committed in the repo.

    Alternative — automated runner (optional): bin/deploy.sh is a one-command wrapper for
    the above sequence + a few safety checks. Requires sudo access on the Lightsail
    ubuntu user (currently has a password set; deploy.sh assumes you'll type it or have
    NOPASSWD configured). If you're typing commands manually anyway, the 3-line sequence
    above is fine.

  Owner: Operator (every git push to origin/main — no exceptions, no skipping)
  Status: ONGOING (recurring on every deploy)
  Related: F-SCHEMA-REF-1 (workstation-side schema quick-ref regen after migrations);
    F-SCHEMA-REF-2 (one-time catch-up commit, separate from this recurring sequence);
    Trap 52 in REFERENCE.md §11 (the opcache-staleness explanation that motivates the
    systemctl reload step); bin/deploy.sh (the automated wrapper for the same 3
    commands, optional alternative).
```

```
ITEM E2 | 2026-05-25 | E — Data | Apply S-SETTINGS-AUDIT-3 migration (audit_log.action ENUM extension)
  Originating session: S-SETTINGS-AUDIT-3 (2026-05-25)
  Surfaced into checklist: S-QBO-11-POSTVERIFY-FIXES (2026-05-25)
  Detail: db_migrations/202605260000_S-SETTINGS-AUDIT-3.sql adds 'manual_trigger' to
    the audit_log.action ENUM. This value is emitted by the Settings → System tab cron
    "Run Now" buttons. Without this migration applied in production, every manual cron
    trigger attempt silently fails with a MySQL "Data truncated for column 'action'" error
    — the INSERT into audit_log is rejected, and the cron run itself may appear to succeed
    but leaves no audit trail, or the endpoint returns a 500 if the error is not caught.
    Original source: S-SETTINGS-AUDIT-3 cron run-now feature + FLEETFORGE_PREDEPLOY_CHECKLIST.md
    gap surfaced during S-QBO-11-POSTVERIFY-FIXES post-verification audit.
  Action: On next production deploy that includes this migration file, run the standard
    3-step deploy sequence (E-DEPLOY-RUNBOOK):
      sudo -u www-data php bin/migrate.php --apply
    Verify with: SELECT migration_file FROM schema_migrations WHERE migration_file LIKE '%S-SETTINGS-AUDIT-3%';
    Expected result: 1 row returned (migration marked applied).
  Owner: Operator (next deploy after S-SETTINGS-AUDIT-3 commit reaches origin/main)
  Status: PENDING
```

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

ITEM F-CRONS-ACCT-1 | 2026-05-19 | F — Accounting | Install accounting crontab block on production
  Originating session: S037-CRONS (3 missing accounting crons + crontab runbook)
  Surfaced into checklist: S037-CRONS
  Detail: docs/runbooks/crontab_accounting.md ships the canonical crontab block for all 5
    accounting crons (S037-CRONS + S037-FX + S037-REC). It must be installed on the
    production Lightsail server via `sudo -u www-data crontab -e`. Use the exact block from
    the runbook §"Crontab block (copy-paste into production)". The crontab user MUST be
    www-data (or whichever PHP-FPM user) so the cron processes inherit the right filesystem
    permissions for storage/ + the right Sentry DSN from .env.
  Action: SSH to mainlandrentals.com → `sudo -u www-data crontab -e` → paste runbook block →
    save → `sudo -u www-data crontab -l` to verify.
  Owner: Operator
  Status: PENDING

ITEM F-LESSOR-NI-CRON | 2026-05-19 | F — Accounting | Install accounting_lease_ni_reclass.php cron on production
  Originating session: S-ACCT-LESSOR-5 (NI current/long-term reclass cron + annual residual review)
  Surfaced into checklist: S-ACCT-LESSOR-5
  Detail: cron/accounting_lease_ni_reclass.php is the monthly cron that reclassifies each
    active capital lease's net investment between 1090 NI Current and 1600 NI Long-Term
    per ASPE 3065.54. Schedule: `0 5 1 * *` (1st of month, 5am — runs AFTER the daily
    recurring-entries cron at 4am so any period JEs posted earlier the same day are
    reflected in the balance calculation). The cron is gated on
    `accounting.lessor_module_enabled='1'` and short-circuits with a logged audit row
    when disabled — safe to install before the operator activates the lessor module.
    The runbook block at docs/runbooks/crontab_accounting.md already includes this line;
    F-CRONS-ACCT-1 covers the parent install. This item is for the operator-side
    verification that the lease NI line is present in `crontab -l` post-install, and
    for the gate-flip readiness check before the first capital lease ships.
  Action: After F-CRONS-ACCT-1 install completes, `sudo -u www-data crontab -l | grep lease_ni_reclass`
    must show the line `0  5 1 * * php /var/www/fleetforge/cron/accounting_lease_ni_reclass.php …`.
    Pre-first-capital-lease: flip `accounting.lessor_module_enabled='1'` in Settings →
    Accounting → Lessor Module after the first sales-type / direct-financing lease is
    classified + ready to activate.
  Owner: Operator
  Status: PENDING
  Depends on: F-CRONS-ACCT-1 (parent install)

ITEM F-CRONS-ACCT-2 | 2026-05-19 | F — Accounting | Provision /var/log/fleetforge-cron.log
  Originating session: S037-CRONS
  Surfaced into checklist: S037-CRONS
  Detail: All accounting cron lines redirect both stdout + stderr to
    /var/log/fleetforge-cron.log. The log file must exist, be owned by the cron user
    (www-data), and be world-readable (0644) so the operator can `tail -f` for live
    diagnostics without sudo.
  Action: `sudo touch /var/log/fleetforge-cron.log && sudo chown www-data:www-data
    /var/log/fleetforge-cron.log && sudo chmod 0644 /var/log/fleetforge-cron.log`
  Owner: Operator
  Status: PENDING

ITEM F-CRONS-ACCT-3 | 2026-05-19 | F — Accounting | One-time manual run of accounting_generate_periods.php
  Originating session: S037-CRONS
  Surfaced into checklist: S037-CRONS
  Detail: After installing the crontab (F-CRONS-ACCT-1), run accounting_generate_periods.php
    ONCE manually so the period horizon is current immediately rather than waiting until the
    next 1st-of-month tick. Without this, validatePeriodForPosting() can fail intermittently
    if production was already past the existing horizon at deploy time.
  Action: `sudo -u www-data php /var/www/fleetforge/cron/accounting_generate_periods.php`.
    Expected output: "Generated N period(s). Horizon through YYYY-MM-DD." Verify N ≥ 0.
  Owner: Operator
  Status: PENDING

ITEM F-CRONS-ACCT-4 | 2026-05-19 | F — Accounting | Dry-run verify accounting_auto_reverse.php pre-go-live
  Originating session: S037-CRONS
  Surfaced into checklist: S037-CRONS
  Detail: Before the 1st cron tick post-deploy, run accounting_auto_reverse.php manually to
    confirm no JEs are unexpectedly pending reversal. Expected count = 0 unless an FX
    revaluation JE was posted in the days before cutover with auto_reverse_date <= today.
    If count > 0, review each JE before letting the cron auto-fire.
  Action: `sudo -u www-data php /var/www/fleetforge/cron/accounting_auto_reverse.php`.
    Expected output: "Summary YYYY-MM-DD: reversed=0 failed=0" on first manual run if
    no auto-reverse JEs are pending. If reversed > 0, verify the resulting JEs in
    /fleetforge/accounting/journal-entries are correct + match expected FX-revaluation
    reversal shape.
  Owner: Operator
  Status: PENDING

ITEM F-CCA-1 | 2026-05-19 | F — Accounting | Assign CCA classes to all 20 existing fixed assets
  Originating session: S-ACCT-CCA-1
  Surfaced into checklist: S-ACCT-CCA-1
  Detail: S-ACCT-CCA-1 introduces acc_fixed_assets.cca_class_id (FK to acc_cca_classes).
    20 existing assets have cca_class_id=NULL after the migration. Assets with NULL
    cca_class_id are EXCLUDED from T2 Schedule 8 — they will not appear in the CCA
    continuity engine's output, the CSV export, or the locked workpaper.
    The pre-existing legacy cra_class varchar field on 20 assets shows "10" — these
    should map to CCA class id 2 (Class 10 motor vehicles ≤ $30K). Verify each
    asset's intended class before assignment — some heavy trucks may belong in
    Class 16 (id 5) if GVWR exceeds 11,788 kg (≈ 25,990 lbs).
    NOTE: K-22 — checklist category labelled F (Accounting) on disk; prompt referred
    to category H but that's the rollback section. Items renamed to F-CCA-N.
  Action: For each asset in acc_fixed_assets (20 rows): UPDATE acc_fixed_assets
    SET cca_class_id = <correct_id> WHERE id = <asset_id>. Or via admin UI at
    /fleetforge/accounting/fixed-assets — open each asset, set CCA Class, Save.
    Verify via /fleetforge/accounting/cca → Compute → row count > 0.
  Owner: Operator (with accountant review)
  Status: PENDING

ITEM F-CCA-2 | 2026-05-19 | F — Accounting | Set available_for_use_date on all existing assets
  Originating session: S-ACCT-CCA-1
  Surfaced into checklist: S-ACCT-CCA-1
  Detail: S-ACCT-CCA-1 introduces acc_fixed_assets.available_for_use_date (DATE NULL).
    20 existing assets have available_for_use_date=NULL. The CCA engine falls back
    to acquisition_date with a logged warning in the continuity row's notes when
    this happens — engine result is still correct for assets that became available
    on their acquisition date, but the auditor should review each asset.
  Action: Review each asset on /fleetforge/accounting/fixed-assets. If the asset
    was available for use on a date different from its acquisition date (e.g.
    capital project commissioning, vehicle registration delay), set
    available_for_use_date explicitly. Otherwise the fallback is correct.
  Owner: Operator (with accountant review)
  Status: PENDING

ITEM F-SCHEMA-REF-1 | 2026-05-19 | F — Accounting | Regenerate SCHEMA_QUICK_REF.md after every production migration
  Originating session: S-SCHEMA-QUICK-REF
  Surfaced into checklist: S-SCHEMA-QUICK-REF
  Detail: docs/FLEETFORGE_SCHEMA_QUICK_REF.md is the authoritative quick-reference
    for actual on-disk column names across all 126 tables. It is regenerated from
    information_schema by scripts/generate_schema_ref.php. Spec files (SPEC_FINAL,
    ACCOUNTING_SPEC, etc.) use idealized column names that have repeatedly drifted
    from reality, producing K-22 catches across Phase B + C sessions. Every time
    a production migration adds, drops, or renames a column the quick-ref MUST be
    regenerated so future session prompts trust the right names. Skipping this
    re-introduces the K-22 risk the quick-ref was created to eliminate.
  Action: After every production migration is applied (post bin/migrate.php),
    SSH to mainlandrentals.com → `cd /var/www/fleetforge` →
    `sudo -u www-data php scripts/generate_schema_ref.php` → verify the
    "Tables: N (core: X, acc_: Y, other: Z)" line is sane → commit the
    regenerated docs/FLEETFORGE_SCHEMA_QUICK_REF.md to main on the same deploy.
    Add this step to the deploy runbook between "apply migrations" and "run
    smoke tests".
  Owner: Operator (every deploy that includes a migration)
  Status: PENDING

ITEM F-SCHEMA-REF-2 | 2026-05-19 | F — Accounting | One-time backfill: regenerate SCHEMA_QUICK_REF.md after Phase D
  Originating session: S-PHASE-D-INTEGRATION-TEST (2026-05-19)
  Surfaced into checklist: S-PHASE-D-INTEGRATION-TEST Part A1 finding
  Detail: The committed docs/FLEETFORGE_SCHEMA_QUICK_REF.md reports 126 tables /
    1943 columns, but the live DB has 130 tables / 2014 columns. The drift is
    exactly the 4 Phase D tables shipped 2026-05-19:
      - acc_lease_classifications (LESSOR-1)
      - acc_lease_amortization_schedules (LESSOR-2)
      - acc_lease_residual_reviews (LESSOR-5)
      - acc_impairment_tests (LESSOR-6)
    Plus the schema-column adds on `leases` (LESSOR-1 — classification +
    10 supporting cols) and `acc_journal_entries.source_type` ENUM extensions
    (LESSOR-3/-4/-5 + S-ACCT-DMG). F-SCHEMA-REF-1 covers the recurring
    deploy-step rule; F-SCHEMA-REF-2 is the one-time catch-up commit that
    closes the existing drift. Until this item is complete, future session
    prompts grep'ing the quick-ref for Phase D table names will get a false
    "table does not exist" signal and either (a) waste tokens re-deriving
    via DESCRIBE, or (b) re-introduce K-22 column-name drift.
    Note: doc_freshness smoke (17/17 PASS) does NOT currently assert
    table-count freshness — only column-name consistency on a sampled set.
    A possible follow-up extends _smoke_doc_freshness.php with a table-count
    line check; out of scope for this item.
  Action (5-minute commit; can be bundled into any next docs-touch session):
    1. `php scripts/generate_schema_ref.php` (regenerates the file in-place)
    2. `git add docs/FLEETFORGE_SCHEMA_QUICK_REF.md`
    3. `git commit -m "docs: regenerate schema quick-ref post-Phase-D (126→130 tables)"`
    4. `git push origin main`
    Verify the "Generated:" line + "Tables: 130 total · Columns: 2014" header
    in the regenerated file before committing.
  Owner: Code Desktop or Operator (whoever's next at the keyboard)
  Status: PENDING
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

ITEM G5 | 2026-05-16 | G — Smoke | Create second admin user in prod
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: Operator requested a second user account in production. Now that FleetForge is live,
    the cleanest path is to create the second user through the UI: FleetForge → Users → Team tab
    (S-USERS-CONSOLIDATE — SHIPPED 2026-05-14) → "+ Invite New User" → fill in name + email +
    role → send invite. The invited user receives an email with a password-set link (requires
    SES to be working first — see B6 + D13). Alternatively create via mysql directly using the
    same bcrypt hash approach used for the super admin account during deployment.
    Original source: 2026-05-16 deployment session notes.
  Action: Once SES is working (B6 + D13) → FleetForge UI → Users → Team → Invite. Or create via
    MySQL pre-SES if urgent (bcrypt hash + status='active').
  Owner: Operator
  Status: PENDING

ITEM G6 | 2026-05-16 | G — Smoke | Enable MFA for super admin
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: Super admin account is live but MFA is not enrolled. For production security, MFA
    should be enabled on the super admin account. FleetForge → top-right avatar → Profile →
    MFA section → scan QR code with Google Authenticator or Authy → verify 6-digit code →
    download backup codes to a secure location. MFA enforcement is already configured via
    security.mfa.required_roles (S-SETTINGS-CLEANUP D194 — multi-checkbox UI under Settings →
    Integrations → Security card); confirm super_admin is in the required-roles list (it is
    by default).
    Original source: 2026-05-16 deployment session notes.
  Action: Log into FleetForge → Profile → enable MFA → save backup codes securely.
  Owner: Operator
  Status: PENDING

ITEM G7 | 2026-05-16 | G — Smoke | Verify nginx config routes all requests through public/index.php before any prod deploy
  Originating session: S-NGINX-PROD-CONFIG (2026-05-16)
  Surfaced into checklist: S-NGINX-PROD-CONFIG (this item)
  Detail: Production is nginx — .htaccess is inert. SCRIPT_FILENAME must be hardcoded to
    /var/www/fleetforge/public/index.php. Using $realpath_root$fastcgi_script_name causes all
    API calls to 404 because API files live under /var/www/fleetforge/api/ not under public/.
    Canonical config + post-change verification: docs/runbooks/nginx_config.md. Decision: D202.
    Root cause locked from the 2026-05-16 initial-deploy AJAX 404 incident.
    Original source: 2026-05-16 Lightsail deployment debugging session.
  Action: Before any future deploy that touches nginx config — confirm
    /etc/nginx/sites-enabled/fleetforge has SCRIPT_FILENAME hardcoded to the absolute path of
    public/index.php (never $realpath_root$fastcgi_script_name). Run the post-change
    verification steps in docs/runbooks/nginx_config.md: nginx -t → reload → curl
    /api/v1/health.php → expect 200 with db:true.
  Owner: Operator
  Status: ✅ COMPLETE (2026-05-16 — deployed and verified: health.php 200 db:true,
    auth/login 200, dashboard 302)

ITEM G-MODAL-AUDIT | 2026-05-19 | G — UI/Polish | Run S-MODAL-AUDIT — codebase-wide modal-backdrop migration
  Originating session: S-PERM-EXPAND D' (2026-05-19) — second incident of the same root-cause bug
  Surfaced into checklist: S-PERM-EXPAND D' close
  Detail: Two incidents (COMPLIANCE-FIX-1 compliance grid edit modal 2026-05-19; S-PERM-EXPAND D'
    permissions admin modals × 3 on 2026-05-19) of the same root-cause bug — using
    `class="modal-backdrop"` for a modal's outer wrapper. `.modal-backdrop` (public/assets/css/app.css
    line 2968) provides only the dark backdrop layer (no flex centering); `.modal-overlay`
    (app.css:2958) is the centered-flex wrapper that has `display:flex; align-items:center;
    justify-content:center;`. Inline `display:flex` on `.modal-backdrop` gives a flex container with
    default `align-items:flex-start`, causing the `.modal` child to render top-left clipped against
    the viewport. Locked as **Trap 49** in `docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md` §11. Both
    incidents required swapping the class to `class="modal-overlay"` + preserving the dark backdrop
    via inline `style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);"`.
    A codebase-wide grep audit is needed to surface ANY remaining `class="modal-backdrop"` usages and
    migrate them to the canonical pattern BEFORE Phase E (Accountants Portal) lands — the portal is
    expected to add ~10+ new modals and a baseline of correct centering should be in place first.
    Scope: grep `class="modal-backdrop"` across `app/`, `includes/`, and any partials/templates; for
    each hit, either confirm it's a legitimate pure-backdrop usage (no `.modal` child — e.g. a
    full-page overlay spinner, or paired explicitly with a `.modal-overlay` sibling for proper
    z-stacking) OR migrate to the canonical `.modal-overlay` pattern. Verify each migration visually
    at desktop (1440×900) and mobile (<768px) viewport — mobile pattern uses `align-items:flex-end`
    at app.css:5973 for bottom-sheet behavior, which works correctly on `.modal-overlay`.
    Original source: docs/FLEETFORGE_PROGRESS.md DECISIONS D-PERM-EXPAND-6 (the D' supplementary
    scope that bundled the second incident's fix); docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md §11
    Trap 49 (the canonical lock).
  Action: Schedule an S-MODAL-AUDIT session before Phase E (Accountants Portal) starts. Output:
    per-modal report (migrate / leave-as-is rationale per file), commit with all migrations + per-file
    PHP lint + per-file visual verification via preview_start at desktop + mobile viewports.
    Update Trap 49 recurrence count in REFERENCE.md if additional in-the-wild incidents surface
    during the audit. If no further incidents found, lock the audit as complete with a "no further
    occurrences" footnote so Phase E modals follow the established canonical pattern from day one.
  Owner: Operator (schedule); Code Desktop (execute)
  Status: QUEUED
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
  Status: DONE (2026-06-06 — Sentry issue alert "Prod new issue" created: new issue in environment=production → email; end-to-end confirmed via event 3a4278ea.)
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

ITEM I4 | 2026-05-16 | I — Monitoring | Nginx + PHP error log monitoring
  Originating session: 2026-05-16 Lightsail deployment
  Surfaced into checklist: S-PROD-DEPLOYMENT-DOCS
  Detail: Error logs on the server should be checked after deployments and when users report
    issues. Key log files:
      - Nginx errors:    /var/log/nginx/error.log
      - Nginx access:    /var/log/nginx/access.log
      - PHP-FPM errors:  /var/log/php8.2-fpm.log
      - App cron output: /var/log/fleetforge-cron.log (once D10 ships)
    Quick-check commands:
      sudo tail -100 /var/log/nginx/error.log
      sudo tail -100 /var/log/php8.2-fpm.log
      sudo tail -100 /var/log/fleetforge-cron.log
    This is the immediate-recourse triage step; longer-term, Sentry (I1) handles application-
    level error capture and CloudWatch Logs (future) would handle centralized log retention.
    Original source: 2026-05-16 deployment session notes.
  Action: Bookmark the tail commands above. Check logs after each deploy + whenever a user
    reports an issue. Bake into post-deploy verification habit.
  Owner: Operator
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

*Last touched: 2026-06-06 (S-SENTRY-CHECKLIST-CLOSE — B3 + I1 flipped to DONE: Sentry prod verified live, event 3a4278ea ingested env=production, alert routing confirmed). Prior: 2026-05-17 (S-DOCS-RECONCILE-FULL — Handoff status refreshed: migrate count 17 → 26 (S-BILLING-HOLISTIC-ENGINE +6 + S-DESIGN-SETTINGS-FOOTER-LOGIN +1), FF_ASSET_VERSION reference 1.0.28 → 1.0.30 (A4 + A5 superseding A2), 2026-05-17 sessions enumerated, smoke-gate freshness flagged as not-refreshed-this-pass). Prior touches: 2026-05-16 (S-NGINX-PROD-CONFIG — G7 added COMPLETE: nginx config canonicalization, SCRIPT_FILENAME hardcoded to public/index.php, root cause of 2026-05-16 initial-deploy AJAX 404s locked as D202; canonical runbook at docs/runbooks/nginx_config.md). 2026-05-16 (S-PROD-DEPLOYMENT-DOCS — 8 new items added from 2026-05-16 Lightsail deployment discoveries: B6 SES SMTP credentials, D10 cron jobs, D11 S3 storage test, D12 CloudWatch billing alarm, D13 SNS topic + SES bounce webhook, E1 rate cards prod seed, G5 second admin user, G6 super admin MFA enrollment, I4 error log monitoring); 2026-05-14 (S-DISPLAY-REVAMP C2 — A5 entry added for FF_ASSET_VERSION 1.0.29 → 1.0.30 collapsed sidebar nav-badge layout fix in app.css); 2026-05-14 (S-CHECKLIST-WORDING-FIX — I1 Status annotation added + Owner "(depends on B3)" qualifier removed; G2 heading parenthetical I1-I6 → I1-I10; Last-touched stamp refresh. Surfaced by S-PREDEPLOY-FULL-VERIFY 2026-05-13); 2026-05-14 (S-PROD-3 C2 — A4 entry added for FF_ASSET_VERSION 1.0.28 → 1.0.29 self-hosted Google Fonts CSS change); 2026-05-13 (S-PROD-2-DOCS-RECONCILE — B3/B4/B5/D7/I1 detail-line + status-line flips from "blocked on S-PROD-2" → "ready, S-PROD-2 SHIPPED 2026-05-02"; corrects the S-CHECKLIST-DRIFT-FIX C2 phantom-QUEUED drift); 2026-05-13 (S-CHECKLIST-DRIFT-FIX — G2 invariant range bump I1-I6 → I1-I10 with origin-session citations; S-PROD-2 explicit queue reference, since corrected).*
