# FleetForge → Northland Equipment: Clone Engineering Brief

**Scope:** stand up a second, fully independent FleetForge deployment for Northland Equipment, sharing code lineage with Mainland Truck & Trailer Sales & Leasing but sharing no data, no credentials, no infrastructure.
**Basis:** ten-dimension read-only audit of `/Users/avi/Documents/fleetforge` @ `f5171c7`, with lead-architect verification of every load-bearing claim (line numbers below are verified against HEAD, not inherited from the audit).

---

## 1. Verdict on approach

### Recommendation: **fork the repo — but only after a tokenization commit lands on Mainland `main`.**

Not multi-tenant. Not shared-code/separate-config. A hard fork into a separate git repository, preceded by one mandatory prep commit on Mainland.

**Why fork (3 strongest reasons)**

1. **The operator has stated intent to diverge.** Multi-tenancy is a bet that the two businesses stay behaviourally identical. Nothing in this codebase is tenant-scoped — not one `WHERE tenant_id = ?`, not one namespaced S3 key, not one namespaced advisory lock. Retrofitting tenancy means touching `settings`, all 23 `acc_qbo_*_map` tables, every storage key prefix (`documents/`, `branding/`, `dunning/`, `credit_applications/`, `backups/db|storage|manual/`), 58 `GET_LOCK()` call sites across `cron/` and `lib/Accounting/`, and the session/remember cookie triple. That is a multi-month rewrite of a live billing system to serve two tenants who will not stay identical.
2. **The blast radius of a shared codebase is financial.** `lib/Billing/HolisticLeaseEngine`, `lib/Accounting/AutoEntryBridge`, `PlaceOfSupplyService` and the rate-card ladder encode Mainland's commercial and tax policy. A shared deployment means every Northland pricing change is a regression risk to Mainland's invoicing, and vice versa. Fork isolates that.
3. **The schema story is already solved for a from-zero build.** `bin/migrate.php` + `db_migrations/` (verified: 37 runner-visible `*.sql` = `000_baseline.sql` + 36 deltas; 96 archived `*.sql.txt` the runner never reads) reproduces `FLEETFORGE_DATABASE_MASTER.sql` exactly — verified: master is **162 `CREATE TABLE`, 0 `INSERT`**. A virgin Northland DB is one command away and provably schema-identical, guarded by `tests/_smoke_migrations_reproduce_master.php`.

**Two regrets you will have with fork**

1. **Every bug fixed once must be fixed twice**, and the divergence compounds. `lib/Migrations/Runner.php:46` checksums each migration file — once a `db_migrations/*.sql` diverges between forks, cherry-picking trips drift detection and `bin/deploy.sh --assert-applied` blocks the deploy. Budget for "Mainland-only" and "Northland-only" fixes becoming the norm within 6 months.
2. **Security fixes get forgotten on the quieter deployment.** The SNS webhook topic gap (`api/v1/webhooks/ses_notifications.php`) and the `db_exists()` soft-delete blind spot are the class of thing that gets patched on the box someone is actively working on.

**Two regrets with multi-tenant (if you ignore the recommendation)**

1. One `GET_LOCK` namespace, one bucket, one SES identity, one QBO webhook endpoint — every hazard in §4 becomes a permanent architectural property rather than a provisioning choice.
2. Every future feature carries a tenant-scoping tax forever, and a single missed `WHERE` clause is a cross-company financial data leak.

**Two regrets with shared-code/separate-config**

1. `bin/deploy.sh` does `git pull` on the deploy host. Both boxes tracking the same branch means **every Mainland commit that touches a tenant literal re-contaminates Northland on the next deploy** — including the git-tracked brand binaries `media/login-logo.jpeg` and `media/video1.mp4`. Silent, recurring, and it looks like a mystery until someone diffs the checkout.
2. The four OAuth redirect URIs and `bin/deploy.sh:55`'s default cannot both be correct in one tree without tokenization work you'd have to do anyway — so you pay the tokenization cost *and* keep the coupling.

### The non-negotiable rider: Phase 0 tokenization on Mainland `main` first

Fork *from a cleaned tree*, not from HEAD. Before branching, land one commit on Mainland `main` that:

- Replaces the four hardcoded OAuth redirect URIs with `base_url('oauth/qbo/callback.php')` / `base_url('oauth/dropbox/callback.php')` (verified literals at `app/admin/oauth/qbo/init.php:50`, `app/admin/oauth/qbo/callback.php:119`, `app/admin/oauth/dropbox/init.php:59`, `app/admin/oauth/dropbox/callback.php:86`). This is a **bug fix for Mainland too** — the current code hardcodes what `base_url()` already computes.
- Makes `bin/deploy.sh:52,55` fail-closed when `FF_DEPLOY_REPO_DIR` / `FF_DEPLOY_BASE_URL` are unset, instead of defaulting to `/var/www/fleetforge` and `https://mainlandrentals.com/fleetforge`.
- Tokenizes the AI system prompt at `lib/AI/SummaryEngine.php:867` to `settings_get('company.name')` (it is a nowdoc — convert to heredoc or concatenate).
- `git rm --cached` the tracked Mainland customer artifacts: `storage/dunning/2/dunning_2_reminder_30_20260502_012236.pdf`, `storage/inspections/2/2_1_photo_1775190248.jpg`, and `storage/tmp/**` (verified tracked: 40+ files including the mPDF `ttfontdata` cache and three stray scratch scripts). Extend `.gitignore` to `storage/dunning/` and `storage/inspections/`.
- Fixes `scripts/_prod_schema_dump.php:15` (`require_once '/var/www/fleetforge/config/app.php'`) and the `getenv('FF_APP_ROOT') ?: '/var/www/fleetforge'` fallbacks in `scripts/bill_missing_hours_2026_06_28.php:50`, `scripts/dedupe_invoice_duplicates_2026_06_28.php:35`, `scripts/fix_mileage_rate_conversion_2026_06_30.php:29` to `dirname(__DIR__)`.

Cost: ~half a day. It converts six permanent re-contamination channels into one-time settings edits, and every item is independently a defect on Mainland today.

---

## 2. Blocker list — decide/provision before writing code

| # | Blocker | Why it blocks | Default recommendation |
|---|---|---|---|
| B1 | **Separate Lightsail instance, or co-host on the Mainland box?** | Co-hosting activates ~12 blockers at once: instance-global `GET_LOCK` names, shared `php8.2-fpm` reload, shared `/var/log`, shared `www-data` crontab, `FF_BASE_PATH='/fleetforge'` (`config/app.php:94`) claimed twice, cross-readable `.env` files, and the three cookie names. | **Separate instance.** Non-negotiable. It removes more work than it costs. |
| B2 | **Separate MySQL server instance, or second schema on Mainland's mysqld?** | `lib/Migrations/Runner.php:46` uses `GET_LOCK('ff_migrations')`; 58 more `GET_LOCK` call sites across `cron/` and `lib/Accounting/` use unprefixed names, several keyed on bare `lease_id`/`period_id`. MySQL named locks are **server-scoped, not schema-scoped**. Collisions manifest as *silent skips* (`exit 0`, "Already running"), including `invoice_generate_monthly`. | **Separate instance.** A second schema on one mysqld silently stops one company's billing. |
| B3 | **From-migrations build, or `mysqldump` of Mainland prod?** | A dump carries live QBO access/refresh tokens + `realm_id`, the Samsara token, Dropbox refresh token, AWS IAM keys, Slack webhook URL, Twilio auth token, Mainland's bank account, verbatim legal credit-application copy, negotiated rate cards, all customers/leases/invoices/GL, and 23 `acc_qbo_*_map` tables of live Mainland QBO object IDs. Neither `scripts/demo_wipe.php` nor `scripts/golive_reset_transactions.php` cleans it — both **preserve `settings` and `users`**; `golive` also preserves `customers` and `equipment_units`. | **From migrations + re-authored seeds.** Rule it out in writing. |
| B4 | **Northland's legal entity name, address, jurisdiction, and counsel-reviewed credit-application copy.** | `database/seeds/004_settings.sql:106-138` contains verbatim legal text naming *Mainland Truck and Trailer Sales Ltd.* four times as ADDITIONAL INSURED / LOSS PAYEE, with a physical-damage-waiver schedule and a 30-day cancellation clause. The seed's own comment says do **not** tokenize. `credit_application.terms_url` points at a PDF on Mainland's WordPress. `credit_application.disclaimer_html` (archived migration `202606060004`) grants credit-bureau pull authorization to Mainland. These must be *rewritten by counsel*, not find-replaced. | Operator + lawyer deliverable. **Gates go-live of the public credit-application form.** |
| B5 | **Own Intuit app + own Dropbox app, or reuse Mainland's client_id?** | A shared Intuit app = one webhook verifier token and one redirect allow-list serving two realms; each deployment would validate the other's signed webhooks as authentic. | **Own apps.** Separate `client_id`/`secret`/verifier token/redirect URI. |
| B6 | **Own S3 bucket.** | Verified `.env.example:51` ships `AWS_S3_BUCKET=fleetforge-mainland`. Every storage key is untenanted, and the backup retention sweeps **delete by prefix** (`cron/backup_db.php`, `backup_storage.php`, `backup_manual_worker.php`). | `fleetforge-northland`, own IAM users. Blank the `.env.example` default in the fork. |
| B7 | **Own SES verified domain + own SNS topic; same AWS account or new?** | Same account = shared sending quota, shared account-level bounce/complaint reputation (AWS suspends at account level), shared sandbox-exit status. New account = fresh sandbox-exit request, **24-72h lead time** — a cutover scheduling dependency, not a code task. | New identity minimum; new account preferred. Start the sandbox-exit request in week 1. |
| B8 | **Is Northland in British Columbia? Does it lease trailers?** | Three independent BC couplings (`database/seeds/006_tax_rates.sql` `is_default=1` BC GST+PST row; `accounting.pos_default_province`; the `'BC'` in-code fallback at `lib/Accounting/PlaceOfSupplyService.php:83`) and twelve AI prompts asserting "trailer leasing" (verified: `lib/AI/SummaryEngine.php:556,649,691,718,739,768,867` + `api/v1/ai/{chat.php:373,stream.php:315,report.php:80,generate-visual.php:88,analyze-document.php:147}`). If not BC, tax determination becomes a workstream, not a string edit. | Answer before Phase 3. |
| B9 | **`FF_BASE_PATH` stays `/fleetforge`?** | `config/app.php:94`, marked "LOCKED". It is embedded in all four external callback registrations and in ~15 hardcoded JS/PHP path literals (`public/assets/js/app.js` entity deep-links, `app/admin/chat/index.php:739`, `app/portal/api/messenger/send.php:117`, `app/auth/accept_invite.php:156`, `cron/notification_digest.php:527`, `cron/invoice_overdue.php:102`). Customers will see `northlandequipment.com/fleetforge/...`. | Keep `/fleetforge` unless the operator objects. Changing it is ~15 literals + one constant, and must be decided **before** third-party callback registration. |
| B10 | **`FF_MFA_SECRET_KEY` and `APP_SECRET` regenerated?** | `FF_MFA_SECRET_KEY` derives the AES-256-CBC key for `ENC:`-prefixed settings (`dropbox.app_secret`, `dropbox.refresh_token`) and all `users.mfa_secret`. `APP_SECRET` signs CSRF tokens **and** storage-serve HMAC URLs (`api/v1/storage/serve.php`). | `openssl rand -hex 32` for each. Never copy. |

---

## 3. The rebrand surface

Deduplicated across all ten audits. Ordered by risk within each group.

### 3a. Settings-row changes — cheapest tier

All read via `settings_get()` (`includes/functions.php:759`). **Caveats:** it has **no caching** (one query per call) and **swallows `Throwable`, returning the default** — a missing row fails silently. There is a second reader with different casting semantics at `lib/Accounting/AccountingService.php:34-45`, plus direct `SELECT ... FOR UPDATE` reads in `AccountingService.php:185,279`, `FixedAssetService.php:49,73`, `InvoiceGenerator.php:3201`, `PaymentWebhookHandler.php:264`.

| Risk | Key(s) | Seed source (verified) | Consumed by |
|---|---|---|---|
| **Blocker** | `credit_application.minimum_requirements_html`, `credit_application.disclaimer_html` | `database/seeds/004_settings.sql:106-138`; `db_migrations/202606060002_S-CCA-1_*.sql.txt`; `db_migrations/202606060004_S-CCA-2_*.sql.txt` | Public form + invite email + `rendered_html` snapshot. **Counsel rewrite, not tokenization.** Three seeding paths — fix all three. |
| **Blocker** | `credit_application.terms_url`, `.insurance_email`, `.references_email` | `004_settings.sql:111-113` | `app/admin/credit-application.php:56-58`. terms_url is snapshotted per submission for the legal trail. |
| **Blocker** | `company.name` | `004_settings.sql:22` **and** `db_migrations/202605170200_*.sql.txt:37` (different spelling) | Everything: topbar, `<title>`, PDFs, email shell, AI prompt (post-tokenization). |
| **Blocker** | `company.email`, `company.phone`, `company.logo_url` | `004_settings.sql:28-31` | `lib/Email/EmailService.php:731-746`, invoice/statement/dunning letterheads. `company.logo_url` is an **absolute `https://mainlandrentals.com/...` URL** hotlinked in every outbound email. |
| **Blocker** | `company.bank_name`, `.bank_account`, `.check_payable_to`, `.payment_instructions` | `004_settings.sql:37-40` — seeded **empty** | Invoice remittance block. Empty is correct for a fresh build; catastrophic if cloned from a prod dump. |
| **High** | `accounting.entity_legal_name`, `.entity_province`, `.entity_fiscal_year_end`, `.cpa_firm_name`, `.cpa_city`, `.cpa_designation`, `.engagement_type` | `db_migrations/202605190900_S-ACCT-DISC.sql.txt:68+` — **archived, will not run** | `lib/Accounting/DisclosureService.php:195`, note-pack PDF headers. **Fourth distinct company-name string.** |
| **High** | `company.address`, `.city`, `.province`, `.postal_code`, `.country` | `004_settings.sql:23-27` | `app/admin/invoices/show.php:516-525`, `api/v1/accounting/ar/statement.php:44-53`, `lib/Accounting/DunningLetterGenerator.php:256-263`, `EmailService.php:358` |
| **High** | `lease.prefix` | `004_settings.sql:51` seeds `'CN'` — **live Mainland value is `MTTS`** (evidence: `MTTS-9JJ3A-2026` in `public/assets/css/app.css:5002`, `scripts/fix_mtts68_2026_06_23.php`, `api/v1/leases/close.php:643`) | Stamped into `leases.contract_number`. **Not retroactively fixable.** Set before the first lease. |
| **High** | `app.url` | **VERIFIED: seeded NOWHERE runnable.** Absent from `database/seeds/`, absent from all 37 live `db_migrations/*.sql`; only in archived `db_migrations/202605032052_S-CLEANUP-BATCH-1_settings_seeds.sql.txt`. **And** `'app'` is not in the Settings-UI group whitelist (`app/admin/settings/index.php:492`) — it is invisible and uneditable in the UI. | `cron/compliance_alerts.php:339,403`, `lib/Notifications/MorningBriefingRenderer.php:189,376`, `lib/AI/TokenBudgetMonitor.php:162`, `lib/Notifications/SmsClient.php:92`. Missing row ⇒ relative hrefs in emails ⇒ dead links. **Must be inserted by hand.** |
| **High** | `email.from_email`, `email.from_name` | **VERIFIED: seeded only in `scripts/archive/legacy_database_migrations/032_integration_settings.sql.txt`** — never runs | `EmailService.php:114-115`, `Mailer.php:107-112`. Missing ⇒ falls through to `SMTP_FROM_EMAIL` ⇒ `noreply@fleetforge.test`, unverified in SES. **Latent defect on Mainland too.** |
| **High** | `brand.primary_color`, `.primary_hover`, `.primary_light`, `.logo_path`, `.favicon_path` | **VERIFIED: zero `brand.*` rows in `database/seeds/*.sql` and zero in live `db_migrations/*.sql`.** Only `db_migrations/202605170200_*.sql.txt` (archived). | `includes/header.php:126-139` emits `<style id="ff-brand-override">`. With no rows it emits `--color-primary:;` ⇒ admin stays orange while both login pages fall back to teal `#2596be`. **Northland boots two-toned.** |
| **High** | `accounting.pos_default_province` | `db_migrations/202605190700_S-ACCT-POS.sql.txt:62` — archived | `lib/Accounting/PlaceOfSupplyService.php:83` also hardcodes `'BC'` as the in-code fallback. Missing row ⇒ everything taxed as BC, silently. |
| **High** | `accounting.ar_account_id`=4, `.ap_account_id`=21, `.default_cash_account_id`=2, `.gst_payable_account_id`=23, `.pst_payable_account_id`=24, `.gst_receivable_account_id`=6, `.bad_debt_expense_account_id`=70, `.fx_gain_account_id`=79, `.fx_loss_account_id`=80 | **VERIFIED** `database/seeds/012_acc_settings.sql:22-30` | These are raw `acc_accounts.id` AUTO_INCREMENT integers transcribed from Mainland's live DB. Nothing resolves by `code`. If Northland's ids shift by one, **every AR/AP/GST/FX journal entry posts to the wrong GL account with no error.** Rewrite from an actual `SELECT id, code FROM acc_accounts`. |
| **Medium** | `company.timezone`, `regional.timezone`, plus `.env` `APP_TIMEZONE` | `004_settings.sql:32`; `202605170200_*.sql.txt:47` (archived); `config/app.php:98` | **Three sources.** `cron/notification_digest.php:56` and `cron/ai_weekly_brief.php:46` read `company.timezone` with a hardcoded `'America/Vancouver'` default. All three must agree. |
| **Medium** | `yard.default` | `004_settings.sql:101` = `'surrey'` | Must match the slug in `database/seeds/005_yard.sql`. Mismatch ⇒ default-yard dropdown silently pre-selects nothing. |
| **Medium** | `company.gst_number`, `.pst_number`, `accounting.cra_business_number` | seeded empty | Invoice/statement tax-registration block silently omitted; GST34 XML falls back to placeholder `000000000RT0001` (`lib/Accounting/Gst34Service.php:559-566`). **Go-live blocker for compliant invoicing.** |
| **Medium** | Settings `description` values naming Mainland | `db_migrations/202605191000_S-ACCT-DMG.sql.txt:59` ("Mainland COA places repair under..."), `202605250000_S-QBO-FIXPACK-3.sql.txt:49`, `202605200500_S-QBO-1.sql.txt:94` | These are stored DB values rendered in the Settings UI, not code comments. |
| **Low** | `company.currency`, `.currency_symbol`, `regional.currency_symbol` | `004_settings.sql:33-34`; `202605170200_*.sql.txt:53` | Two competing symbol keys; and 18 of ~20 PDFs ignore both (see 3b). |
| **Not coupled** | `notifications.smtp_*` (6 rows, `004_settings.sql:90-96`) | — | **Dead keys.** No code reads them, but group `'notifications'` **is** in the Settings whitelist, so they render as editable "From Address"/"From Name" fields that do nothing. Expect a false bug report. Consider deleting the rows in the fork. |
| **Not coupled** | `notifications.email_enabled` (`004_settings.sql:90`) | — | Described as "Master toggle for all outbound email" — **`Mailer::send()` never reads it.** Not a kill switch. |

### 3b. File edits — hardcoded, must be changed in the fork

| Risk | File:line | Literal | Fix |
|---|---|---|---|
| **Blocker** | `app/admin/oauth/qbo/init.php:50` | `'https://mainlandrentals.com/fleetforge/oauth/qbo/callback.php'` | `base_url('oauth/qbo/callback.php')`. Phase 0. |
| **Blocker** | `app/admin/oauth/qbo/callback.php:119` | same literal | Must match init byte-for-byte or Intuit returns `invalid_grant`. |
| **Blocker** | `app/admin/oauth/dropbox/init.php:59` (+ docblock :21) | `'redirect_uri' => 'https://mainlandrentals.com/.../dropbox/callback.php'` | No settings escape hatch exists for Dropbox (QBO at least has `quickbooks.sandbox_redirect_uri` on the non-production branch). |
| **Blocker** | `app/admin/oauth/dropbox/callback.php:86` (+ docblock :12) | same literal | — |
| **Blocker** | `lib/AI/SummaryEngine.php:867` | `You are FleetForge AI — the built-in fleet intelligence assistant for Mainland Truck & Trailer Sales & Leasing...` | Nowdoc `<<<'PROMPT'` — no interpolation. Convert and read `settings_get('company.name')`. AI output is persisted and emailed. |
| **High** | `bin/deploy.sh:55` (+ usage comment :39) | `BASE_URL="${FF_DEPLOY_BASE_URL:-https://mainlandrentals.com/fleetforge}"` | Fail closed. A Northland deploy without the env var health-checks Mainland's live site and reports green. |
| **High** | `bin/deploy.sh:52` | `REPO_DIR="${FF_DEPLOY_REPO_DIR:-/var/www/fleetforge}"` | Same. Note deploy.sh `sed`-edits `$REPO_DIR/.env` for `MAINTENANCE_MODE` — a wrong `REPO_DIR` 503s the other company. |
| **High** | `api/v1/chat/messages/create.php:61` | `'customer' => 'Mainland Truck & Trailer'` | **Persisted** into `chat_messages.sender_display_name` — permanent, not render-time. |
| **High** | `api/v1/chat/customer/create.php:83` | `'sender_display_name' => 'Mainland Truck & Trailer'` | First message of every customer conversation. |
| **High** | `lib/Email/EmailService.php:828` | `background-color:#F97316;height:4px` — brand accent bar on **every** email | Inline style; no CSS var can reach it. Also `:763,770,782` (orange footer links). |
| **High** | `includes/partials/credit_application_render.php:72` | `$brandColor = '#f97316';` (used at :93 accent bar, :97 section borders) | The credit-application **PDF** ignores `brand.primary_color`, while the HTML form at `app/admin/credit-application.php:39` honours it. Inconsistent pair. |
| **High** | `database/seeds/008_email_templates.sql` (15 occurrences of `#F97316`) | Inline orange in the **stored template bodies** | These are DB rows. Fixing `EmailService.php:828` does not fix them. Edit the seed before seeding Northland. |
| **High** | `api/v1/accounting/reports/book-tax-differences.php:139` (+ docblock :11) | `'disclosure_note' => 'Mainland uses the taxes-payable method...'` | Ships in the accountant-facing T2 package. Also re-confirm the policy assumption holds for Northland. |
| **High** | `tests/_verify_email_templates_redesign.php:130,139,142,145,148,151` | Six assertions hardcoding Mainland's name, `mailto:info@mainlandtts.ca`, `https://mainlandrentals.com`, the logo URL, and the copyright line | Fails red on day one **and actively resists** the `EmailService.php:828` de-branding fix. Parameterize against `settings_get()`. |
| **Medium** | `api/v1/ai/chat.php:373`, `stream.php:315`, `report.php:80`, `generate-visual.php:88`, `analyze-document.php:147` | `"...for a trailer and equipment leasing company"` | Business-model assertion. `chat.php`/`stream.php` must change in lockstep. |
| **Medium** | `lib/AI/SummaryEngine.php:556,649,691,718,739,768` | six more `"trailer leasing company"` prompt literals | Only matters if Northland's mix is not trailers — see B8. |
| **Medium** | `app/admin/accounting/tax/gst34.php:105`; `app/admin/accounting/tax/index.php:106` | `"Mainland likely exceeds the $400K Quick Method ceiling"` | Visible warning banner on Northland's tax-filing screen. |
| **Medium** | `app/admin/customers/show.php:470-471` | `"...when Mainland is primarily arranging GPS access via Samsara"` ×2 | Inline revenue-recognition help text. |
| **Medium** | `scripts/dropbox_configure.php:20,70` | prints `https://mainlandrentals.com/fleetforge/oauth/dropbox/init.php` to stdout | The operator will follow the printed URL to the wrong host. |
| **Medium** | `api/v1/accounting/ar/statement.php:271,299` | `#2563eb` statement accent | Matches neither brand colour. |
| **Medium** | `app/auth/forgot_password.php:106`; `app/portal/auth/forgot_password.php:76` | `background:#2563eb` CTA button in **outbound reset emails** | The portal one reaches customers. |
| **Medium** | `app/admin/tracking/index.php:628` | `center: [49.10, -122.66], // Surrey, BC default` | Default map centre. Should read the default yard's lat/lng. |
| **Medium** | `lib/Accounting/YearEndService.php` `wrapHtml()` | `{$company}` interpolated into a heredoc **without `htmlspecialchars`** (contrast `ReportPdfRenderer::headerHtml()` which escapes) | A company name containing `&`/`<`/`'` is injected raw into all 8 year-end PDFs. Current Mainland name already contains bare `&`. |
| **Medium** | `public/assets/css/app.css:324-328` | `[data-theme="light"] { --color-primary: #ea6f00; ... }` | The runtime `ff-brand-override` rebinds only `:root`, so **light mode is out-specified and stays Mainland-orange** regardless of `brand.primary_color`. Real white-label hole. |
| **Medium** | `public/assets/css/app.css:155-158, 240, 317` + ~14 raw `rgba(249,115,22,…)` sites | `--color-primary: #f97316`, `--gradient-brand`, `--shadow-glow-primary` | Defaults + non-tokenized residue that survives the override. Also inline in `includes/partials/ai-chat-widget.php:237,245`, `app/admin/invoices/index.php:296`, `app/admin/leases/index.php:281`, `app/admin/customers/show.php:183` (+3 siblings), `app/admin/users/index.php:761` (+2 siblings). |
| **Medium** | `app/admin/settings/design.php:41,70-73,710,773` | default + reset colour `#2596be` (teal), six preset swatches | Disagrees with `app.css`'s orange. Clicking "reset" gives teal chrome on orange defaults. |
| **Medium** | `database/seeds/015_acc_cca_classes.sql:20` | `'Freight trucks > 11,788 kg GVWR — Mainland primary class'` and `'Mainland Rentals primary class...'` | Stored label + description shown in the accounting UI. |
| **Medium** | `app/admin/settings/design.php:56-58` + `api/v1/settings/brand.php:366-380` | `pdf.show_logo`, `pdf.accent_color`, `pdf.invoice_footer_text` | **Wired end-to-end in the UI, read by ZERO renderer.** Setting them appears to work and changes nothing. Either implement or remove from the Design tab before handing Northland the app. |
| **Low** | `lib/Accounting/ReportPdfRenderer.php` `money()`; `lib/Accounting/YearEndService.php` `money()` | hardcoded `'$'` | 18 of ~20 PDFs ignore `company.currency_symbol` while AR statements and dunning letters honour it. |
| **Low** | `api/v1/accounting/ar/statement.php:417`; `lib/Accounting/DunningLetterGenerator.php:349`; `app/admin/invoices/show.php:3329`; `api/v1/accounting/cca/export.php:50` | "Powered by / Generated by FleetForge" | White-label decision, customer-facing. |
| **Low** | 6 auth pages hardcode `FleetForge` in `<title>` and wordmark: `app/auth/{forgot_password,reset_password,mfa_challenge,accept_invite,mfa_required}.php`, `app/admin/account/mfa_setup.php` | — | `includes/header.php:101` does this correctly via `company.name`; these do not. |
| **Low** | `includes/success_overlay.php:133-136` | `<text ...>FLEETFORGE</text>` painted on a blue trailer SVG | — |
| **Low** | `public/assets/js/app.js:4598` | `const company = (window.FF_COMPANY_NAME) \|\| 'FleetForge';` | **`window.FF_COMPANY_NAME` is never assigned anywhere.** Latent bug affecting both deployments. |
| **Low** | `tests/_smoke_cca3.php:101` | `'company_name' => 'Mainland Truck & Trailer'` | Fixture data. Cosmetic. |
| **Not coupled** | `config/legal.php:20-41`; `app/legal/{privacy,dpa,terms}.php` | `Avi Technologies Inc.`, `Surrey, British Columbia`, `@avitechnologies.ca` | **Vendor identity, not the tenant's.** Identical for both deployments. The "Surrey" here is the vendor's address. **Do not mass-replace.** Sole exception: `app/legal/cookies.php:71` lists cookie names and must be updated if the cookie rename (§4) happens. |
| **Not coupled** | `scripts/seed_demo.php`, `seed_dataset.php`, `seed_marketing_demo.php`, `database/seed_reports_data.php` etc. — "Lower Mainland Bulk Carriers", "Surrey Yard", `604-555-xxxx` | — | Fake demo data; "Lower Mainland" is the BC region, not the company. **Do not busywork-rename.** |
| **Not coupled** | `app/admin/reports/index.php:708,757,790,873` — `#f97316` in chart palettes | — | Semantic/categorical (aging buckets, utilization ramp), not brand accent. A blanket orange sweep would **harm** these. Same for `app/admin/chat/index.php:251`. |
| **Not coupled** | `tests/_smoke_lease_contractnum_honor_input.php:37-39,98` — `'MTTS'` | — | Arbitrary uniqueness token; the cleanup `LIKE` depends on it. A careless partial replace breaks the guard. |

### 3c. Asset replacements

| Risk | Path | Note |
|---|---|---|
| **Blocker** | `media/login-logo.jpeg` (43 KB) | **Verified git-tracked.** Reached through the `public/media -> ../media` symlink (verified). Resolved by filename in **five** places: `app/auth/login.php:330-339`, `app/portal/auth/login.php:118,134-137`, `app/portal/includes/footer.php:19,26-34`, `includes/legal_header.php:84-96`, `app/admin/credit-application.php:43-54`. Extension probe order is svg→png→jpg→jpeg — dropping a Northland `.png` wins without deleting the `.jpeg`, which leaves a latent Mainland fallback. **Delete the old file.** |
| **High** | `public/assets/img/logo-email.png` (164 KB) | Git-tracked. The file served at whatever `company.logo_url` points to. Replacing it is useless unless the settings row is also repointed. |
| **High** | `media/video1.mp4` (7.0 MB) | Git-tracked login background video, hardcoded by filename at `app/auth/login.php:866` and `app/portal/auth/login.php:592`. No settings key exists. Confirm the stock footage licence permits a second commercial site, or supply Northland footage / delete the `<source>` blocks. |
| **High** | `storage/branding/logo_1779019322.png`, `logo_1779036156.png` | Mainland's uploaded brand assets, present in the working tree (gitignored, so a `git clone` fork is clean — an **rsync/tar fork is not**). Do not carry over. |
| **Medium** | `public/assets/icons/favicon.svg` | Generic Heroicons truck in hardcoded `#2563eb` — matches neither company. Default when `brand.favicon_path` is unset. Note the four error pages (`app/errors/{404,403,500,maintenance}.php`) bypass `ff_favicon_tags()` and hardcode this SVG, so an uploaded Northland favicon is ignored there. |
| **Medium** | `public/media` symlink | Verified `lrwxr-xr-x public/media -> ../media`. Git preserves it; `rsync --copy-links`, `git archive`, or a Windows checkout will not. If it breaks, login logo + video + notification sound all 404 silently. nginx must follow symlinks. |
| **Low** | `media/background-image.webp` (187 KB, added in `f5171c7`) | **Zero code references found.** Orphaned committed asset. Decide whether to carry it. |
| **Not coupled** | `media/notification.mp3`, `public/assets/fonts/Geist*.woff2` (SIL OFL), 64 Heroicons `.svg`, `public/assets/vendor/leaflet/images/*`, `public/assets/icons/quickbooks.jpg` | Generic / open-licence / third-party marks. Copy as-is. `public/assets/logo/` is an empty vestigial directory — not a drop point. |

### 3d. Infrastructure / environment

| Risk | Item | Action |
|---|---|---|
| **Blocker** | `.env.example:51` `AWS_S3_BUCKET=fleetforge-mainland` (verified) | Blank it in the fork so a missing value fails loudly. |
| **Blocker** | `.env.example:61` `SMTP_FROM_NAME=Mainland Truck & Trailer` (verified) | Neutralize. |
| **Blocker** | `lib/Storage/StorageClient.php:190-196, 386-397, 411-417` | **Settings rows outrank `.env`** for `storage.driver`, `aws.region`, `aws.access_key_id`, `aws.secret_access_key`, `aws.s3_bucket`. Same precedence in `lib/Notifications/Mailer.php:565-597` (SES) and `lib/GPS/SamsaraClient.php:61-70` (Samsara, a **four**-level chain: `samsara.api_token` → `gps.samsara_api_key` → `SAMSARA_API_TOKEN` → `GPS_SAMSARA_API_KEY`). A cloned settings table silently overrides a correct `.env`. |
| **Blocker** | nginx vhost | `docs/runbooks/nginx_config.md` hardcodes `server_name mainlandrentals.com`, `root /var/www/fleetforge/public`, and an **absolute** `fastcgi_param SCRIPT_FILENAME /var/www/fleetforge/public/index.php`. Copying it verbatim makes Northland's hostname serve Mainland's data. |
| **Blocker** | TLS | `/etc/letsencrypt/live/mainlandrentals.com/` — new certbot cert for Northland's domain. |
| **Blocker** | Crontab | `docs/runbooks/crontab_accounting.md` + ~30 `cron/*.php` docblocks hardcode `/var/www/fleetforge/cron/*.php` and `/var/log/fleetforge-cron.log`. Nineteen of those docblocks **also** carry the operator's laptop path `/Users/avi/Documents/fleetforge/cron/...` as a "Local test" line. |
| **High** | `scripts/_prod_schema_dump.php:15` | A **real** hardcoded `require_once '/var/www/fleetforge/config/app.php'` — not a comment. Bootstraps Mainland's `.env`/DB from a Northland checkout. |
| **High** | `scripts/bill_missing_hours_2026_06_28.php:50`, `dedupe_invoice_duplicates_2026_06_28.php:35`, `fix_mileage_rate_conversion_2026_06_30.php:29` | `getenv('FF_APP_ROOT') ?: '/var/www/fleetforge'` — all three are **write** scripts. |
| **High** | Root credential files (verified present, gitignored) | `./claude-fleetforge-api-key` (108 B), `./samsara api` (42 B), `./user-credentials` (64 B). A `git clone` is clean; an **rsync/scp of the working tree carries live Mainland Anthropic + Samsara keys and the plaintext super-admin login** to the Northland box. Delete explicitly. |
| **High** | `storage/.htaccess` | Apache-only. Prod is nginx + php8.2-fpm and never reads it. Locally-generated PDFs (`storage/year_end_packages/` always) are unprotected unless the nginx vhost has an explicit `location /storage/ { deny all; }`. **Verify on Mainland too.** |
| **Medium** | Directory provisioning | `storage/{tmp,year_end_packages,dunning,credit_applications,branding,uploads,documents,generated,acc_documents,inspections}` and `logs/` must exist and be writable by the fpm user, or all 8 PDF generators throw. |
| **Medium** | `storage/tmp/mpdf/ttfontdata/**` tracked in git | Checked-out cache files owned by the deploying user; mPDF running as `www-data` may fail to rewrite them ⇒ every PDF generator throws on the new box. `git rm -r --cached storage/tmp` in Phase 0. |
| **Medium** | `composer.json:8-12` | `ext-bcmath`, `ext-gd`, `ext-mbstring`, `ext-pdo` required. Install `php8.2-{gd,mbstring,bcmath}` before `composer install` or the credit-application PDF fails at submit — and the failure is swallowed into `error_log` at `app/admin/credit-application.php:549`, so the applicant sees success with no PDF. |
| **Medium** | `.env.example` is incomplete and partly dead | **Undocumented but read by code:** `AI_MODEL`, `BACKUP_DB_RETENTION_DAYS`, `BACKUP_STORAGE_RETENTION_MONTHS`, `MANUAL_BACKUP_RETENTION_COPIES`, `DROPBOX_DB_RETENTION_DAYS`, `DROPBOX_STORAGE_RETENTION_COPIES`, `MAINTENANCE_MODE`, `FF_APP_ROOT`, `FF_MYSQL_BINARY`, `FF_OPTIMISTIC_LOCKING`, `SAMSARA_API_TOKEN`, `SAMSARA_ORG_ID`, plus `FF_TEST_*`/`FF_SMOKE_*`/`FF_CRON_*`. **Documented but dead:** `AWS_SES_SMTP_HOST/PORT/USER/PASS` (mail goes via the SES **API**, `SesClient`, not SMTP — do not provision SMTP creds) and `AWS_SNS_TOPIC_ARN` (read by zero lines). |
| **Medium** | `lib/Migrations/Runner.php:435-456` | Shells out to the `mysql` CLI over `--protocol=TCP` (needed for `DELIMITER`/`CREATE PROCEDURE` in `202606161200_S-LINEITEM-ENUM-PROD-PARITY.sql`). The box needs the mysql client, TCP reachability, and the DB user needs `CREATE ROUTINE`. Schema also uses 6 InnoDB FULLTEXT indexes — match `sql_mode` (STRICT) and `innodb_ft_min_token_size` to Mainland. |
| **Medium** | Operator shell aliases `ff-deploy` and ssh alias `fleetforge` | Defined outside the repo (shell rc / `~/.ssh/config`). Highest-probability human-error path. Northland needs unambiguously distinct names before the second box exists. |
| **High** | Runbooks that must be forked, not copied | `docs/runbooks/{nginx_config,deploy,crontab_accounting,restore_drill,stage_demo_to_production,key_rotation,logrotate_setup,baseline_reconcile,qbo_realm_change}.md`. `key_rotation.md:139-269` names IAM user `fleetforge-prod-user` and `mysql -u fleetforge_user -p fleetforge` — following the Northland copy unchanged **revokes Mainland's live S3/SES access**. `baseline_reconcile.md:51,77,117` connects directly to Mainland's schema. `docs/runbooks/staging_setup.md` references `scripts/seed.php`, **which does not exist**. |
| **High** | `scripts/stage_demo_to_production.php` + its runbook | Destructive, SSH-targets `ubuntu@mainlandrentals.com`, path `/var/www/fleetforge`. **Delete from the Northland fork.** |
| **Medium** | `scripts/seed_marketing_demo.php:50-80` | Hard-refuses unless `APP_URL` host is exactly `fleetforge.test`. Four smokes (`tests/_smoke_legacy_close_overshoot.php:70`, `_smoke_valid2.php:69`, `_smoke_session4_regression.php:94`, `_smoke_close_estimate_trueup_carrier.php:59`) hardcode `http://fleetforge.test/fleetforge` with no env override — on a shared dev machine they test the **wrong checkout**. Contrast `_smoke_close_reconciliation_hours.php:74`, which correctly reads `FF_SMOKE_BASE_URL`. |

---

## 4. Cross-contamination hazards

Ordered by severity. Each is a way the two deployments corrupt or leak into each other.

| # | Hazard | Mechanism | Mitigation |
|---|---|---|---|
| **X1** | **Northland writes into Mainland's QuickBooks company file** | A DB dump carries `quickbooks.realm_id` + `access_token` + `refresh_token`. `cron/qbo_token_refresh.php` keeps them alive unattended; `cron/qbo_sync_worker.php` then pushes Northland invoices/customers/JEs into Mainland's books. 23 `acc_qbo_*_map` tables of Mainland object IDs make the pushers issue *sparse updates* against live Mainland records. | Build from migrations. If a dump is ever used: blank all `quickbooks.*` rows and `TRUNCATE` all 23 `acc_qbo_*` tables + NULL every `qbo_*` column on business rows. Note `scripts/demo_wipe.php` does **not** clear the map tables. |
| **X2** | **One deployment deletes the other's database backups** | `cron/backup_db.php` writes `backups/db/{date}/{hour}/fleetforge_{ts}.sql.gz` — the prefix **and the `fleetforge_` filename** are tenant-blind. The retention pruner does `StorageClient::listByPrefix('backups/db/')` and deletes by date. Same in `backup_storage.php` (`backups/storage/`) and `backup_manual_worker.php` (`backups/manual/`). | **Separate S3 bucket.** No exceptions. |
| **X3** | **`scripts/restore_db.php` restores the wrong company** | It lists everything under `backups/db/` in the configured bucket with no tenant tag and will restore any of it — including Mainland's full production DB with live credentials in `settings`. | Separate bucket (X2 mitigation covers this). Also rename the default scratch DB `fleetforge_restore_test`. |
| **X4** | **Cross-tenant document read via shared S3** | Keys are untenanted: `documents/{entityType}/{entityId}/`, `credit_applications/{app_id}/credit_application_{Ymd}.pdf` (**no time component — deterministic same-day collision**), `dunning/{customer_id}/`, `branding/logo_{ts}.{ext}`, `email_attachments/`, `inspections/`, `acc_documents/`. Entity IDs restart at 1 in a fresh DB, so collisions are certain, not theoretical. `api/v1/storage/serve.php:56-62` additionally exempts every `branding/*` key from the auth-session gate. | Separate bucket. If ever shared, a tenant key prefix must be added in `StorageClient` first. |
| **X5** | **Silent cron starvation on a shared MySQL server** | 58 verified `GET_LOCK()` call sites across `cron/` and `lib/Accounting/`, all unprefixed, plus `Runner.php:46` `'ff_migrations'`. Locks are **server-scoped**. Accounting locks are keyed on bare ids (`ff_lease_amort_{id}`, `ff_lease_period_{id}_{n}`, `fx_rev_{periodId}`, `year_end_close_2026`) that collide across tenants. The loser exits **0** with a log line — `invoice_generate_monthly` silently never bills. | **Separate MySQL instance.** Otherwise prefix every lock with `env('DB_DATABASE')`. |
| **X6** | **Cross-tenant OAuth code delivery** | Northland's QBO/Dropbox connect flow sends Mainland's `redirect_uri`; on consent, Intuit/Dropbox redirects the browser to Mainland's host, which exchanges the code and overwrites its own tokens with Northland's realm. | Tokenize the four literals (Phase 0) **and** register separate Intuit/Dropbox apps. |
| **X7** | **Shared Intuit app ⇒ mutual webhook acceptance** | One app = one webhook verifier token. `api/v1/webhooks/qbo_payment_notifications.php:67-70` would validate the *other* tenant's payloads as authentic and enqueue sync work against foreign QBO IDs. | Separate Intuit app per deployment. |
| **X8** | **SES bounce cross-suppression** | `api/v1/webhooks/ses_notifications.php:59-71` verifies the AWS SNS **signature** but never checks `TopicArn` — and `:73-90` **auto-confirms** any subscription whose `SubscribeURL` starts with `https://sns.`. `AWS_SNS_TOPIC_ARN` is read by **zero lines of code** (verified), so there is nothing to compare against. A hard bounce sets `email_disabled=1` on any matching `customers`/`portal_users` row. One console mis-click (identical endpoint path on both hosts) silently blacklists the other company's customers. | Separate SNS topic **and** add a `TopicArn` allowlist + its reader. **Fix this on Mainland `main` first**, in Phase 0. |
| **X9** | **Shared SES identity/account ⇒ shared reputation** | Northland mail sent From a `mainlandtts.ca` address; bounce/complaint spikes from one tenant degrade or pause sending for **both**. AWS suspends at the account level. Sandbox exit is per-account+region. | Separate verified domain identity minimum; separate AWS account preferred. |
| **X10** | **Northland's emails hotlink Mainland's web server** | `company.logo_url` is seeded to `https://mainlandrentals.com/assets/img/logo-email.png` and rendered as `<img src>` in every email (`lib/Email/EmailService.php:731-746`). Mainland's access logs record every Northland email open (IP + timing side channel); Mainland taking the file down breaks Northland's branding. | Set `company.logo_url` to a Northland-hosted URL **and** replace `public/assets/img/logo-email.png`. |
| **X11** | **Northland applicants insure Mainland** | `credit_application.minimum_requirements_html` names *Mainland Truck and Trailer Sales Ltd.* as ADDITIONAL INSURED and LOSS PAYEE; `disclaimer_html` grants Mainland credit-bureau pull authorization; `terms_url` serves Mainland's carrier agreement. All three are snapshotted onto each submission. Applicant insurance certificates and trade references are routed to `Rentals@mainlandtts.ca` / `Sales@mainlandtts.ca`. | Counsel-rewritten copy (B4). Fix **all three** seeding paths: `database/seeds/004_settings.sql:106-138`, `db_migrations/202606060002_*.sql.txt`, `db_migrations/202606060004_*.sql.txt`. |
| **X12** | **Shared Samsara token ⇒ wrong fleet, wrong billing** | `SamsaraClient` has **write** methods (`apiWrite` POST/PATCH to `/fleet/trailers`), so `api/v1/samsara/import.php` could mutate Mainland's Samsara org. Read side: odometer feeds `mileage_usage`/`mileage_adjustment` billing lines — a wrong odometer is a silent revenue error, not a failure. Four-level credential chain means blanking `.env` alone is insufficient. | Own Samsara org + token. Clear `samsara.api_token`, `gps.samsara_api_key`, `SAMSARA_API_TOKEN`, `GPS_SAMSARA_API_KEY`. NULL the 20 `equipment_units.samsara_*` columns on any copied unit. |
| **X13** | **Shared Dropbox app/account** | Backup basenames are identical (`fleetforge_YYYYMMDDHHMMSS.sql.gz`); `ff_dropbox_retention()` (`cron/backup_dropbox.php:157-184`) lists the folder and deletes the oldest. | Own Dropbox app (app-folder mode gives automatic isolation). |
| **X14** | **Shared cookies on a shared host/parent domain** | **Three** cookie names, all path `/fleetforge`, no `Domain` attribute: `ff_session` (`config/app.php:174`, deliberately shared between admin and portal per `app/portal/includes/auth.php:24`), `ff_remember` (30-day, `includes/auth.php:302,326,444`), `ff_portal_remember` (30-day, `app/portal/includes/auth.php:209,231,263,305`, value is `portal_user_id:token` and the ids overlap between deployments). Note the RFC-6265 exact-path-match footgun documented at `app/auth/logout.php:44-52`: a partial rename leaves a live 30-day token logout cannot kill. Cookie names are also published at `app/legal/cookies.php:71`. | **A distinct hostname makes all three moot.** That is the mitigation — take it. |
| **X15** | **Shared `APP_SECRET`** | Signs CSRF tokens **and** storage-serve HMAC URLs. Combined with a shared bucket it is a complete cross-tenant document-read primitive. | Regenerate. |
| **X16** | **Shared `FF_MFA_SECRET_KEY`** | Encrypts `users.mfa_secret` and the `ENC:` Dropbox credentials. One `.env` compromise decrypts both companies' second factors. | Regenerate — and accept that copied `ENC:` values become permanently undecryptable (`DropboxClient::decrypt()` returns null silently ⇒ confusing "not connected"). Blank them. |
| **X17** | **Shared Sentry project** | Event payloads carry request data, user context and breadcrumbs; `Mailer` exceptions embed recipient addresses (`[Mailer] SES send failed to {toEmail}`). The `SCRUB_KEYS` list scrubs secrets, not business data. Issue fingerprints collapse identical traces across tenants. | Separate Sentry **project** (not just `SENTRY_ENVIRONMENT`). |
| **X18** | **Shared Slack webhook / Twilio account** | A cloned `slack.webhook_url` posts Northland's AR aging and revenue into Mainland's Slack channel — incoming webhooks are bearer URLs with no per-message auth. Shared Twilio = customers see the wrong company's number and replies route wrong. | Own workspace webhook, own Twilio account/subaccount + from-number. Both ship disabled (`'0'`), so a fresh build is safe by default. |
| **X19** | **Wrong-target deploy** | `bin/deploy.sh:52,55` defaults. Running it on the Northland box without env vars does `cd /var/www/fleetforge`, `git pull`, `migrate --apply`, `backup_db.php`, and `sed`-edits Mainland's `.env` for `MAINTENANCE_MODE` — i.e. it deploys to and can 503 the wrong company, then health-checks Mainland and reports green. | Fail-closed defaults (Phase 0) + distinct operator aliases. |
| **X20** | **Shared git remote re-contaminates on every pull** | `package.json:14` → `github.com/scoobybuilds98/fleetforge.git`; `bin/deploy.sh` pulls from origin. Every Mainland commit touching a tenant literal — the four OAuth URIs, `deploy.sh` defaults, `SummaryEngine.php:867`, **and the tracked brand binaries `media/login-logo.jpeg` / `media/video1.mp4`** — silently overwrites Northland's identity. | Separate repository. This is the decisive argument against shared-code/separate-config. |
| **X21** | **Mainland customer PII travels in the git clone** | Verified tracked: `storage/dunning/2/dunning_2_reminder_30_20260502_012236.pdf` (a real customer's dunning letter with AR balance and collections language) and `storage/inspections/2/2_1_photo_1775190248.jpg`. `.gitignore` covers `storage/uploads|generated|documents|branding|credit_applications|tmp` but **not** `storage/dunning/` or `storage/inspections/`. | `git rm` + `.gitignore` extension in Phase 0. |
| **X22** | **Working-tree credential leak on a file-copy fork** | `./claude-fleetforge-api-key`, `./samsara api`, `./user-credentials` are gitignored but present on disk. | Fork by `git clone`, never `rsync`/`cp -r`/`scp` of the working directory. |
| **X23** | **Shared `/tmp` backup filenames on a co-hosted box** | `cron/backup_db.php` uses `sys_get_temp_dir()."/ff_backup_{$timestamp}.sql.gz"` and `backup_storage.php` uses `/ff_storage_{ts}.tar.gz` — **no PID**, unlike `backup_manual_worker.php` which does include `getmypid()`. Same-second cron starts interleave two mysqldump streams; the truncated file uploads and reports success. | Separate instance (B1). |
| **X24** | **Shared Anthropic key** | `lib/AI/TokenTracker.php` enforces `ai.daily_token_limit` against the **local** `ai_request_log`, so each tenant independently permits its full budget and neither monitor sees real account spend. Prompt content from two companies commingles in Anthropic-side logs. | Separate API key (separate workspace preferred). |
| **X25** | **Shared logrotate / `/var/log`** | `docs/runbooks/logrotate_setup.md:71-107` covers `/var/www/fleetforge/logs/*` **and** `/var/log/nginx/*.log` + `/var/log/php8.2-fpm.log`. Duplicating the shared system-log stanzas in a second config **breaks rotation for both** — the disk-full failure the runbook itself warns about. | Second stanza covering only Northland's app paths, never the shared system logs. |

---

## 5. Database standup — exact ordered sequence

**Method:** virgin DB → `bin/migrate.php --apply` → re-authored seeds → archived-settings backfill. **Not** a `mysqldump` clone (B3).

**Verified facts this rests on:** `FLEETFORGE_DATABASE_MASTER.sql` = 162 `CREATE TABLE`, **0 `INSERT`** — pure DDL parity mirror, *not* a provisioning tool. `db_migrations/` holds **37** runner-visible `*.sql` and **96** `*.sql.txt` archives the runner skips (`Runner.php:47` regex `/^[A-Za-z0-9_\-]+\.sql$/`). `000_baseline.sql` `CREATE TABLE IF NOT EXISTS`-es 156 tables then `INSERT IGNORE`s 96 rows into `schema_migrations`, pre-marking the archives applied **without running them** — that is the source of the data gaps below. **12** of the 37 live files carry DML (verified: `000_baseline`, `202606060100_S-BACKUP-1`, `202606070100_S-AI-CREDIT-TILE`, `202606161600_S-MILEAGE-UNIT-SIMPLIFY_settings`, `202606161700_S-LEASE-RENTAL-DAY-TIME`, `202606161800_S-PRUNE-STALE-LEASE-DEFAULTS`, `202606182500_S-AI-MODEL-UPDATE`, `202606190400_S-LEASE-MIN-DAYS`, `202606190600_S-LEASE-SERVICE-CHARGES`, `202606191300_S-CRON-TOGGLES`, `202606250001_S-LEASE-MIN-DAYS-CATEGORY`, `202606260001_S-EQTAX-1`) and **none of the 37 contains the string "Mainland"** (verified).

```bash
# ── 0. Provision (as MySQL root, on Northland's OWN mysqld) ──────────────
CREATE DATABASE northland CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'northland_user'@'localhost' IDENTIFIED BY '<strong-unique-pw>';
GRANT ALL PRIVILEGES ON northland.* TO 'northland_user'@'localhost';
FLUSH PRIVILEGES;
# northland_user MUST have CREATE ROUTINE (202606161200 uses DELIMITER/CREATE PROCEDURE)
# Confirm: mysql client installed, TCP reachable, sql_mode STRICT, InnoDB FTS available.

# ── 1. .env authored on the Northland box from .env.example (never copied) ─
#   DB_DATABASE=northland  DB_USERNAME=northland_user  DB_PASSWORD=...
#   APP_URL=https://<northland-domain>   (no trailing slash)
#   APP_SECRET=$(openssl rand -hex 32)        # NEW
#   FF_MFA_SECRET_KEY=$(openssl rand -hex 32) # NEW
#   AWS_S3_BUCKET=fleetforge-northland  AWS_ACCESS_KEY_ID/SECRET = new IAM user
#   SMTP_FROM_EMAIL=noreply@<northland-domain>  SMTP_FROM_NAME=Northland Equipment
#   SENTRY_DSN=<new project>  SENTRY_ENVIRONMENT=northland-production
#   AI_ANTHROPIC_API_KEY=<new key>
#   GPS_SAMSARA_API_KEY / _ORG_ID = Northland's own, or BLANK
#   FF_APP_ROOT=/var/www/northland   FF_MYSQL_BINARY=/usr/bin/mysql
composer install --no-dev

# ── 2. Schema to HEAD.  DO NOT use bin/deploy.sh for this first run — ─────
#      its D-BASELINE-3 guard (bin/deploy.sh:248-258) aborts when
#      000_baseline.sql exists but is unmarked, which is exactly a virgin DB.
php bin/migrate.php --dry-run     # expect: applied 0 / pending 37 / drift 0
php bin/migrate.php --apply
php bin/migrate.php --status      # expect: applied 133 / pending 0 / drift 0
php bin/migrate.php --verify      # expect: 0 drift / 0 missing
# Do NOT run --backfill.

# ── 3. Prove schema identity with Mainland ────────────────────────────────
php tests/_smoke_migrations_reproduce_master.php   # expect MIGRATE-PARITY OK
php tests/_smoke_no_stray_schema_sql.php           # expect NO-STRAY-SCHEMA OK

# ── 4. Reference seeds, IN THIS ORDER ─────────────────────────────────────
# Ordering constraints: 001 before 003 (roles resolved by slug JOIN; the table
# is `user_roles`, and users.role_id FK is ON DELETE RESTRICT so 002-before-001
# hard-fails).  010 before 012 and before 014.
mysql -u northland_user -p northland < database/seeds/001_roles.sql
mysql ... < database/seeds/002_super_admin.sql   # ONLY after re-authoring — see below
mysql ... < database/seeds/003_permissions.sql
mysql ... < database/seeds/004_settings.sql      # ONLY after re-authoring — see below
mysql ... < database/seeds/005_yard.sql          # ONLY after re-authoring — see below
mysql ... < database/seeds/006_tax_rates.sql     # move is_default=1 if not BC
mysql ... < database/seeds/008_email_templates.sql
# --- verify acc_accounts is EMPTY before the next line ---
mysql ... -e "SELECT COUNT(*) FROM acc_accounts;"          # must be 0
mysql ... < database/seeds/010_acc_chart_of_accounts.sql   # NOT idempotent — see below
mysql ... < database/seeds/011_acc_periods.sql
# --- rewrite 012's nine *_account_id values FIRST — see below ---
mysql ... < database/seeds/012_acc_settings.sql
mysql -u ... northland -e "SET @fy=2026; SOURCE database/seeds/013_acc_year_end_checklist.sql;"
mysql ... < database/seeds/014_acc_lead_schedules.sql
mysql ... < database/seeds/015_acc_cca_classes.sql   # after scrubbing 'Mainland' from class 16
mysql ... < database/seeds/016_tax_rates_pos.sql
# SKIP: database/seeds/run_chat_seed.php, database/seed_reports_data.php

# ── 5. Archived-settings backfill — THIS ARTIFACT DOES NOT EXIST YET ──────
# Author database/seeds/017_northland_archived_settings.sql.
# Method: for each of the 96 db_migrations/*.sql.txt archives, extract ONLY the
#   `INSERT ... INTO settings` blocks, then SUBTRACT every key already planted
#   by one of the 12 live DML migrations (diff, don't extract blind).
# Must include, at minimum:
#   app.url, email.from_email, email.from_name,
#   brand.primary_color / .primary_hover / .primary_light / .logo_path / .favicon_path,
#   defaults.*, regional.timezone / .date_format / .time_format / .distance_unit,
#   accounting.entity_legal_name / .entity_province / .entity_fiscal_year_end /
#     .cpa_* / .engagement_type / .tickmark_legend / .pos_default_province,
#   quickbooks.* (BLANK credentials), portal_requests.routing.*, pdf.*,
#   ai.weekly_brief_* / .briefing_* / .anomaly_scan / .budget_alert_*,
#   credit_application.disclaimer_html (Northland's counsel text),
#   security/MFA labels.
# Scrub every Mainland literal.  Blank every credential.  Skip ALL DDL —
# tests/_smoke_no_stray_schema_sql.php (D-GUARD-2) is absolute with no
# whitelist: one CREATE/ALTER line under database/seeds/ fails CI.

# ── 6. Rows with NO seed anywhere — must be authored by hand ──────────────
#   exchange_rates       — EMPTY.  No seed, no UI, no cron.  Only writers are
#                          scripts/demo_seed.php:71 and tests/_interaction/tier6_currency.php.
#                          lib/Billing/CurrencyConverter.php:58-60 returns the
#                          amount UNCHANGED when empty ⇒ USD books into the
#                          CAD-canonical GL at par, silently, and the D-R2-2
#                          realized-FX 7030/7040 logic runs on an implicit 1.0.
#                          Seed USD→CAD and CAD→USD (source='manual') before any
#                          non-CAD lease exists.
#   rate_cards / rate_card_items  — EMPTY.  No lease can price.
#   equipment_categories / equipment_templates — Northland's own taxonomy.
#   notification_rules   — EMPTY ⇒ alert/digest pipeline emits nothing (no error).
#   late_fee_rules       — EMPTY ⇒ cron/late_fee_apply.php never charges (no error).
#   contract_templates   — EMPTY ⇒ contract generation impossible (no error).
#   additional yards, real users.

# ── 7. Verify before traffic ──────────────────────────────────────────────
php bin/migrate.php --assert-applied    # expect PENDING: 0 / DRIFT: 0, exit 0
curl -s https://<northland-domain>/fleetforge/api/v1/health
php tests/_smoke_cca3.php               # proves mPDF + storage/tmp + GD work
```

### Seed disposition summary

| Seed | Disposition | Why |
|---|---|---|
| `001_roles.sql` | **Run verbatim** | Company-neutral. Table is `user_roles`. Must precede 003. |
| `002_super_admin.sql` | **RE-AUTHOR** | Ships `admin@fleetforge.test` with a committed bcrypt hash whose **plaintext is in the file header**. Also a `.test` address hard-bounces in SES ⇒ self-inflicted `email_disabled=1`. Real email + fresh hash. |
| `003_permissions.sql` | **Run verbatim** | Stale (70 rows = 5 roles × 14 modules; `config/permissions.php` now declares ~35) but not authoritative — `can()` resolves from config with `role_permission_overrides` as layer 3. |
| `004_settings.sql` | **RE-AUTHOR lines 22-41, 51, 101, 106-138** | See §3a. |
| `005_yard.sql` | **RE-AUTHOR** | `'Surrey Yard'/'surrey'/9045 King George Blvd`. Slug must match `yard.default`. At least one yard must exist before any `equipment_unit`. |
| `006_tax_rates.sql` | **Run; move `is_default=1` if not BC** | Statutory rates are law; only the default jurisdiction is a choice. |
| `008_email_templates.sql` | **Run — verbatim text, but fix the 15 inline `#F97316`** | Verified: zero Mainland literals; all identity via `{company_name}`/`{company_phone}`/`{company_email}`/`{sender_name}`. Note `companyVariables()` (`EmailService.php:352-360`) provides **no** `{company_website}` or `{company_logo_url}`, and `substitute()` leaves unknown placeholders **in place** — an operator adding `{company_website}` ships that literal to customers. |
| `010_acc_chart_of_accounts.sql` | **Run ONCE against a provably empty table** | **Not idempotent**: plain `INSERT` (not `INSERT IGNORE`) against `UNIQUE(code)`, with parent ids chained via `LAST_INSERT_ID()`. A partial run leaves shifted auto-increment ids and silently invalidates 012. If it errors, `TRUNCATE` and restart. |
| `011_acc_periods.sql` | **Run; extend immediately** | Horizon ends 2026-12. Enable `cron/accounting_generate_periods.php` (`0 4 1 * *`) or hand-extend. |
| `012_acc_settings.sql` | **REWRITE the nine `*_account_id` values** | Verified lines 22-30 hold Mainland's raw `acc_accounts.id` integers (4/21/2/23/24/6/70/79/80). Nothing resolves by `code`. Highest silent-wrong-answer risk in the build. |
| `013_acc_year_end_checklist.sql` | **Run with `SET @fy=2026;`** | Idempotent via `uq_year_item`. |
| `014_acc_lead_schedules.sql` | **Run verbatim, after 010** | Pure `UPDATE`s keyed on COA `code`. |
| `015_acc_cca_classes.sql` | **Scrub line 20, then run** | CRA classes/rates are law; two free-text strings name Mainland. |
| `016_tax_rates_pos.sql` | **Run verbatim** (optionally scrub the comment at :45) | Statutory. |
| `run_chat_seed.php`, `database/seed_reports_data.php` | **SKIP** | Demo chatter and fabricated customers/yards. |
| `scripts/seed_demo.php`, `seed_dataset.php`, `seed_marketing_demo.php`, `rehearsal_seed.php`, `demo_accounting.php`, `demo_backdate_invoices.php`, `seed_presentation_extras.php`, `seed_revenue_topup.php`, `seed_payoff_*.php`, `seed_reservations.php`, `seed_dummy_reservations_2026_06_02.php`, `seed_portal_accounts.php` | **SKIP ALL** | Dev-only. `seed_portal_accounts.php` creates 16 publicly-reachable logins sharing `Portal123!`. |
| `scripts/seed_rate_cards.php` | **Rubric template only** | Clears all `rate_card_items` and soft-deletes every non-deleted `rate_card` on each run. Its own header says it has never run against prod. Replace the numbers with Northland's pricing. |
| `scripts/stage_demo_to_production.php` | **DELETE from the fork** | Destructive; SSH-targets Mainland. |
| `FLEETFORGE_DATABASE_MASTER.sql` | **Do not use to provision** | Loading it leaves `schema_migrations` empty ⇒ the next `--apply` tries to re-run everything. It is a parity mirror only. |

---

## 6. Provisioning checklist

### New infrastructure

| Resource | Spec | Notes |
|---|---|---|
| **Lightsail instance** | New instance, Ubuntu, static IP | Resolves B1/B2 and ~12 blockers at once. |
| **DNS** | Northland's own zone: `A` → new static IP; DKIM `CNAME`s ×3; SPF `include:amazonses.com`; DMARC TXT (`p=quarantine`, `rua=mailto:postmaster@<domain>`) | Must **not** be a subdomain of a Mainland-owned zone (X14). Note the app vhost covers **one** hostname — `mainlandtts.ca`/`.com` are a separate marketing site, not this deployment. Do not over-scope to three certs. |
| **TLS** | `certbot` for `<northland-domain>` + `www.` | New `/etc/letsencrypt/live/<northland-domain>/`. |
| **nginx vhost** | `/etc/nginx/sites-available/northland` | Fork `docs/runbooks/nginx_config.md`; change `server_name`, `root`, the **absolute** `fastcgi_param SCRIPT_FILENAME`, `DOCUMENT_ROOT`, the `/assets/` alias, both `$host` 301 blocks, and the cert paths. **Add `location /storage/ { deny all; }`** — `storage/.htaccess` is Apache-only and prod is nginx. Ensure symlink following for `public/media`. |
| **php-fpm** | `php8.2-fpm` + `php8.2-{gd,mbstring,bcmath,mysql}` | On a dedicated instance no pool separation is needed. |
| **MySQL** | Own mysqld; `northland` schema; `northland_user` scoped to `northland.*` only; `CREATE ROUTINE` granted | See §5 step 0. |
| **App directory** | `/var/www/northland` (not `/var/www/fleetforge`) | Create `storage/{tmp,year_end_packages,dunning,credit_applications,branding,uploads,documents,generated,acc_documents,inspections}` and `logs/`, all writable by the fpm user. |
| **Crontab** | Under Northland's web user; every line `/var/www/northland/cron/*.php`; log to `/var/log/northland-cron.log` (create + chown) | **Do not install `notification_digest` or `ai_weekly_brief` until SES is verified** — see below. |
| **logrotate** | `/etc/logrotate.d/northland` covering **only** `/var/www/northland/logs/*` | Do **not** duplicate the `/var/log/nginx/*` or `/var/log/php8.2-fpm.log` stanzas (X25). |
| **S3 bucket** | `fleetforge-northland`, Public Access Block **on**, SSE-S3 default encryption, versioning + `NoncurrentVersionExpiration` 90d | Per `docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md` D3/D4. |
| **IAM users** | `fleetforge-northland-app` (S3 R/W on the Northland bucket ARN + `ses:SendEmail`/`ses:SendRawEmail` on the Northland identity ARN), `fleetforge-northland-backup` (S3 `PutObject`) | **Do not provision SES SMTP credentials** — mail goes via the SES **API** (`SesClient`); `AWS_SES_SMTP_*` is dead config and the PREDEPLOY checklist's B2/B6 instruction is stale. |
| **SES** | New verified **domain** identity + DKIM; sandbox-exit request | Per-account+region — a new AWS account needs a fresh request (24-72h). |
| **SNS** | New topic (e.g. `northland-ses-bounces`), attached as Bounce+Complaint destination on Northland's SES identity **only**, HTTPS-subscribed to `https://<northland-domain>/fleetforge/api/v1/webhooks/ses_notifications.php` | The endpoint path is identical on both hosts — the hostname in the subscription is the *only* thing distinguishing them (X8). |
| **CloudWatch alarms** | Bounce/complaint rate scoped to Northland's SES identity; billing alarm | Notify a **Northland** operator address, not `mainlandtts@gmail.com`. |
| **Sentry** | New project + DSN; `SENTRY_ENVIRONMENT=northland-production` | Separate project, not just a different environment tag (X17). |
| **git** | New repository (or a fresh squashed repo) | Not a branch of `github.com/scoobybuilds98/fleetforge.git` (X20). |
| **Operator aliases** | Distinct ssh alias (e.g. `northland`) and deploy alias (e.g. `nl-deploy`) | `ff-deploy` / `fleetforge` are defined outside the repo and are the highest-probability human-error path (X19). |

### New third-party accounts/apps

| Service | Create new | Because |
|---|---|---|
| **Intuit developer app** | Yes — own `client_id`/`client_secret`/webhook verifier token, redirect URI `https://<northland-domain>/fleetforge/oauth/qbo/callback.php`, webhook endpoint `.../api/v1/webhooks/qbo_payment_notifications.php` | X6, X7 |
| **QuickBooks Online company** | Yes — own realm | X1 |
| **Dropbox app** | Yes — app-folder mode, redirect URI `https://<northland-domain>/fleetforge/oauth/dropbox/callback.php` | X13 |
| **Samsara org + API token** | Yes, or leave blank if Northland has no telematics | X12 |
| **Anthropic API key** | Yes — own workspace | X24 |
| **Slack incoming webhook** | Yes | X18 |
| **Twilio account/subaccount + from-number** | Yes, if SMS is used | X18 |
| **Carrier-agreement PDF hosting** | Yes — Northland's own URL for `credit_application.terms_url` | X11 |

### Credentials that must **NOT** be reused — explicit deny list

`APP_SECRET` · `FF_MFA_SECRET_KEY` · `DB_PASSWORD` / `fleetforge_user` · AWS access keys (`fleetforge-prod-user` / `fleetforge-app` / `fleetforge-backup`) · the `fleetforge-mainland` S3 bucket · Mainland's SES verified identity · Mainland's SNS topic ARN · `quickbooks.client_id` / `client_secret` / `refresh_token` / `access_token` / `realm_id` / `webhook_verifier_token` · `dropbox.app_key` / `app_secret` / `refresh_token` · `samsara.api_token` + `gps.samsara_api_key` (+ both env aliases) · `ai.anthropic_api_key` · `slack.webhook_url` · `twilio.account_sid` / `auth_token` · the `fleetforge-prod` Sentry DSN · the seeded super-admin hash in `database/seeds/002_super_admin.sql` · the three root credential files (`./claude-fleetforge-api-key`, `./samsara api`, `./user-credentials`).

**And the trap that defeats a correct `.env`:** `settings` rows outrank environment variables for `storage.driver`, `aws.region`, `aws.access_key_id`, `aws.secret_access_key`, `aws.s3_bucket` (`StorageClient.php:190-196,386-397`), for SES credentials (`Mailer.php:565-597`), and for Samsara (`SamsaraClient.php:61-70`). If any of those rows ever carry Mainland values, a perfectly correct Northland `.env` is silently ignored.

---

## 7. Phased execution plan

### Phase 0 — Tokenize Mainland `main` (before any fork)
Work in the existing repo, commit directly to `main` per house rule.
- Replace the four OAuth redirect literals with `base_url(...)`.
- Make `bin/deploy.sh:52,55` fail-closed on unset `FF_DEPLOY_REPO_DIR`/`FF_DEPLOY_BASE_URL`.
- Tokenize `lib/AI/SummaryEngine.php:867` to `settings_get('company.name')`.
- Add a `TopicArn` allowlist (and its reader) to `api/v1/webhooks/ses_notifications.php`.
- Fix the four absolute-path bootstraps in `scripts/`.
- `git rm --cached storage/dunning/2/*.pdf storage/inspections/2/*.jpg` and `git rm -r --cached storage/tmp`; extend `.gitignore`.
- Blank `.env.example:51` and neutralize `:61`.
- Complete `.env.example` with the 12 undocumented live keys; delete the 4 dead `AWS_SES_SMTP_*` and `AWS_SNS_TOPIC_ARN` lines (or wire the latter).

**Done when:** `php bin/migrate.php --assert-applied` exits 0 on Mainland dev; `grep -rn "mainlandrentals\.com" --include="*.php" app/ api/ lib/ bin/ scripts/` returns **only** docblock lines; `git ls-files storage/` returns only `.gitkeep` and `.htaccess`; the full smoke suite is green; Mainland's live QBO and Dropbox connections still refresh (verify `cron/qbo_token_refresh.php` manually) — this is the regression risk of Phase 0 and it must be proven before the fork.

### Phase 1 — Decisions and account creation
Answer B1-B10. Create the Lightsail instance, DNS zone, S3 bucket, IAM users, SES identity + DKIM, SNS topic, Sentry project, Intuit app, Dropbox app, Anthropic key. **File the SES sandbox-exit request on day one** if a new AWS account is used.

**Done when:** every row in §6 has an identifier written down; `aws ses get-identity-verification-attributes` reports `Success` for Northland's domain; `dig CNAME` returns all three DKIM records; the Intuit app lists Northland's redirect URI.

### Phase 2 — Fork and strip
`git clone` (never rsync) into `northland-equipment`. Delete `scripts/stage_demo_to_production.php` and its runbook. Fork the nine runbooks in `docs/runbooks/` with Northland paths/hosts/DB names. Replace `media/login-logo.jpeg`, `media/video1.mp4`, `public/assets/img/logo-email.png`. Verify `public/media` is still a symlink after checkout. Confirm the three root credential files are absent.

**Done when:** `grep -rn "mainland\|Mainland" --include="*.php" --include="*.sh" --include="*.md" .` in the Northland repo returns zero hits outside `vendor/` and the intentional vendor-identity files (`config/legal.php`, `app/legal/*`); `ls -la public/media` shows a symlink; `ls | grep -iE "credential|samsara api"` is empty.

### Phase 3 — Rebrand the source
Apply every §3b file edit and re-author the seed files: `002_super_admin.sql`, `004_settings.sql` (lines 22-41, 51, 101, **106-138 with counsel's text**), `005_yard.sql`, `006_tax_rates.sql` default row, `008_email_templates.sql` colours, `012_acc_settings.sql` account ids (placeholder — finalized in Phase 4), `015_acc_cca_classes.sql:20`. Parameterize `tests/_verify_email_templates_redesign.php:130-151` against `settings_get()`. Fix `app.css:324-328` so the brand override wins in light mode. Author `database/seeds/017_northland_archived_settings.sql`.

**Done when:** `php -l` clean across every edited file; `grep -rn "Mainland" database/seeds/ db_migrations/` returns zero; `tests/_verify_email_templates_redesign.php` passes against a Northland-settings fixture; `tests/_smoke_no_stray_schema_sql.php` passes (proves 017 contains no DDL).

### Phase 4 — Local Northland dev DB
Run §5 steps 0-6 against a local DB. Immediately after step 4's `010_acc_chart_of_accounts.sql`, run `SELECT id, code FROM acc_accounts ORDER BY id;` and write the real ids into `012_acc_settings.sql` before loading it. Seed `exchange_rates`. Author `rate_cards`, `equipment_templates`, `notification_rules`, `late_fee_rules`, `contract_templates`.

**Done when:** `php bin/migrate.php --status` = applied 133 / pending 0 / drift 0; `tests/_smoke_migrations_reproduce_master.php` prints `MIGRATE-PARITY OK`; a `SELECT` proves each of the nine `accounting.*_account_id` values maps to the intended COA `code`; you can create a customer → lease → invoice end-to-end and the invoice posts a balanced JE to the correct GL accounts; `SELECT COUNT(*) FROM exchange_rates` > 0.

### Phase 5 — Provision the box
Install nginx vhost, TLS, php-fpm extensions, MySQL, app directory, storage dirs, logrotate. `composer install --no-dev`. Author `.env` on the box from `.env.example`. Run §5 against the production DB. **Do not install the crontab yet.**

**Done when:** `curl -s https://<northland-domain>/fleetforge/api/v1/health` returns green with migrate-state clean; the login page renders Northland's logo and colours in **both** light and dark mode; `curl https://<northland-domain>/storage/` returns 403; `php tests/_smoke_cca3.php` passes on the box (proves mPDF + GD + `storage/tmp` writability).

### Phase 6 — Connect integrations
Settings → Integrations: AWS keys, SES from-address. Send a test via `api/v1/admin/integrations/test_email.php`, then `api/v1/admin/intelligence/test_briefing.php` (the latter exercises the full `renderEmailHtml` brand shell — logo, footer, address, accent bar). Connect QBO (sandbox first via `quickbooks.sandbox_redirect_uri`, then production). Connect Dropbox. Connect Samsara if applicable.

**Done when:** a test email arrives from Northland's domain with Northland branding and passes DKIM+SPF+DMARC (check the raw headers); QBO shows `connection_status=connected` against Northland's realm and `CompanyInfoSync` has written `quickbooks.home_currency`; a manual `cron/backup_db.php` run lands an object in `s3://fleetforge-northland/backups/db/...` and **nothing** appears in `fleetforge-mainland`; a forced SNS test notification is accepted by Northland's webhook and a spoofed one from a foreign topic is **rejected** (proves the Phase 0 allowlist).

### Phase 7 — Enable automation
Install the crontab under Northland's user with `/var/www/northland` paths. Set per-cron toggles in Settings → Intelligence → Scheduled Jobs. **Note `notification_digest` and `ai_weekly_brief` are NOT in `config/cron_jobs.php` (verified) — they have no in-app off switch, and `notification_digest` is what sends dunning letters. Install those two lines last, only after SES is verified and `credit_application.*` is counsel-approved.** Leave `invoice_generate_monthly` off (its registry default is `'0'`).

**Done when:** `sudo -u <northland-user> crontab -l` shows only `/var/www/northland` paths; 24h of `/var/log/northland-cron.log` shows every enabled job running with no lock-skip lines; Mainland's `/var/log/fleetforge-cron.log` shows no change in frequency; a dunning letter generated on Northland carries Northland's letterhead and lands under `dunning/` in Northland's bucket.

### Phase 8 — Go-live gate
Populate `company.gst_number`, `company.pst_number`, `accounting.cra_business_number`, `company.bank_*`. Set `lease.prefix` **before the first lease**. Publish Northland's carrier-agreement PDF and set `credit_application.terms_url`. Run one `bin/deploy.sh` with both env vars exported to prove the deploy path.

**Done when:** an invoice PDF prints Northland's GST/PST registration and correct remittance details; the public credit-application form at `<northland-domain>/fleetforge/credit-application` shows Northland's legal entity throughout and the submitted PDF snapshot matches; `bin/deploy.sh` health-checks Northland's URL (not Mainland's) and completes; `bin/migrate.php --assert-applied` exits 0 on both boxes.

---

## 8. Open questions for the operator

Only the ones that change the plan.

1. **Separate Lightsail instance and separate MySQL server — confirmed?** If co-hosting is forced for cost, this brief changes materially: budget the `GET_LOCK` namespacing of 58 call sites, env-driving `FF_BASE_PATH`, separate fpm pools+users, renaming three cookies across five files plus `app/legal/cookies.php:71`, and PID-tagging the `/tmp` backup filenames.
2. **Is Northland in British Columbia?** If not, tax determination is a workstream (`006_tax_rates.sql` default row, `accounting.pos_default_province`, and the hardcoded `'BC'` fallback at `PlaceOfSupplyService.php:83`), not a string edit.
3. **Does Northland lease trailers, or general equipment?** Twelve AI prompts and the seeded equipment taxonomy assume trailers. "Northland Equipment" suggests otherwise.
4. **Northland's exact legal entity name — and who reviews the credit-application copy?** There are four distinct company-name strings today (`company.name` ×2 spellings, `accounting.entity_legal_name`, and the verbatim `Mainland Truck and Trailer Sales Ltd.` in the legal HTML). The last cannot be find-replaced; it needs counsel. This gates the public credit-application form.
5. **What is the LIVE value of `lease.prefix` in Mainland production, and what should Northland's be?** The seed says `'CN'`; every artifact in the repo shows `MTTS`. Confirm with a read-only `SELECT value FROM settings WHERE \`key\`='lease.prefix'`. **Not retroactively fixable** once leases exist.
6. **Same AWS account or new?** Same = shared SES quota and shared account-level reputation (AWS suspends at account level). New = a 24-72h sandbox-exit lead time that must go in the schedule now.
7. **Does Northland need QuickBooks / Dropbox / Samsara at all?** If no, four OAuth blockers become dead code and several crontab lines are omitted entirely.
8. **`FF_BASE_PATH` stays `/fleetforge`?** Customers will see `northlandequipment.com/fleetforge/...`. Changing it is ~15 literals + one constant, and must be settled **before** callback URLs are registered with Intuit and Dropbox.
9. **Does Northland transact in USD?** If yes, someone must own `exchange_rates` — no seed, no UI, no cron, and `CurrencyConverter` fails to 1:1 **silently**.
10. **Keep or remove "Powered by FleetForge" / "A software by Avi Technologies" on Northland's customer-facing documents and footers?** Three PDF footers, the email footer, six auth-page wordmarks, and the success-overlay SVG. `app/legal/terms.php:71` explicitly contemplates white-label configuration, so either answer is supported.
11. **Is the login background video (`media/video1.mp4`, 7 MB) licensed for a second commercial site?** If not, supply Northland footage or delete the `<source>` blocks at `app/auth/login.php:866` and `app/portal/auth/login.php:592`.
12. **Should the dead `pdf.show_logo` / `pdf.accent_color` / `pdf.invoice_footer_text` settings be implemented or removed?** They are wired end-to-end in Settings and read by no renderer. With a second tenant arriving, implementing is the cheaper long-term answer — and it is the only way to get a logo onto any PDF (today **no PDF in the app renders a logo at all**).