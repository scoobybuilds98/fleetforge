# FleetForge Code Audit — 2026-04

**Auditor:** Claude Opus 4.7 via Claude Code
**Scope:** Entire codebase on disk as of 2026-04-18, compared against FLEETFORGE_SPEC_FINAL.md v2.5, FLEETFORGE_ACCOUNTING_SPEC.md v1.2, FLEETFORGE_DESIGN_DETAILS.md, FLEETFORGE_CLAUDE_CODE_REFERENCE.md, and the authoritative FLEETFORGE_DATABASE_MASTER.sql v1.2.
**Date:** 2026-04-18
**Mode:** Read-only principal-architect audit. Zero source files modified. Only the report below is new.

---

## Executive Summary

Eight CRITICAL findings. Three undermine correctness of money movement, two guarantee runtime SQL errors on user-facing features, one is a full build-gap that breaks the entire "every number is a link" UX promise, and two break spec compliance invariants that CRA/legal sign-off will rely on.

1. **CRITICAL — `notifications` in `SOFT_DELETE_TABLES` but schema has no `deleted_at` column.** Any call to `db_exists('notifications', ...)` or future query using the soft-delete pattern throws `Unknown column 'deleted_at'`. Silent until exercised. [[AUDIT2026-1](#audit2026-1)]
2. **CRITICAL — Chat record picker + attachment search hit a non-existent `reservations.reservation_number` column.** SQL error for every user who searches reservations in chat. [[AUDIT2026-2](#audit2026-2)]
3. **CRITICAL — Close-lease does not void the pre-existing monthly draft invoice.** When a lease closes mid-month, the draft covering the whole month and the close-generated partial invoice both persist, double-counting revenue in denormalized counters. [[AUDIT2026-7](#audit2026-7)]
4. **CRITICAL — Overpayment is rejected, not routed to customer credit.** Spec and accounting rulebook both require overage → `credit_notes` / 2060 Customer Advances. Current code returns 422 and blocks the payment. [[AUDIT2026-8](#audit2026-8)]
5. **CRITICAL — Eight required crons are missing on disk.** No `health_scores`, `risk_scores`, `ai_fleet_brief`, `cache_cleanup`, `archive_old_data`, `notification_digest`, `reconcile_counters`, or `backup_db`. Most of the spec's "nightly" automation does not exist. [[AUDIT2026-3](#audit2026-3)]
6. **CRITICAL — Orphan/write-only tables:** `equipment_status_log`, `customer_rate_history`, and `lease_billing_periods` are written but never read. Spec features "Status Log tab", "Rate History tab", and duplicate-invoice protection are silently absent. `customer_discounts`, `yard_transfers`, `contract_templates`, `generated_contracts`, `notification_rules`, `scheduled_reports`, `saved_reports`, and most of the accounting "recurring/FX/year-end" tables have zero app references. [[AUDIT2026-4](#audit2026-4), [5](#audit2026-5), [6](#audit2026-6)]
7. **MAJOR — API error envelope drifted from spec.** Spec says `{success,false,error_code,message}`. Code produces `{success:false,error:{code,message}}`. `require_input()` uses a third shape (`{success:false,errors:{...}}`) and is never called. Any SDK/documentation downstream consumer that follows the spec will fail. [[AUDIT2026-13](#audit2026-13)]
8. **MAJOR — No 2FA, no error monitor, no log rotation, no backup cron, no production-ready secrets rotation runbook.** AWS go-live is blocked until these are addressed. [[AUDIT2026-29](#audit2026-29) – [AUDIT2026-32](#audit2026-32)]

Non-critical but pervasive: many "show" pages contain module tabs that the backend cannot populate (equipment status-log, customer rate-history). Tax seeds cover only BC/ON/AB; any invoice for a customer in the other 7 provinces silently bills at 0%. `include/db.php` and `include/functions.php` are loaded by explicit `require_once` rather than Composer `files` autoload as the spec requires (functional, but an SDK consumer importing via Composer won't get them).

Good news: FOR UPDATE, optimistic lock (STALE_DATA), advisory cron locks, bcmath monetary discipline, CSRF enforcement on state-changing requests, file-upload MIME validation via `finfo_file`, portal-session `customer_id` scoping, and gap-free invoice numbering all look correct where they exist. The code that is built is built well. The problems are (a) drift in edge cases, (b) missing components the spec assumes exist, and (c) a handful of surgical bugs in newer features (chat, accounting joins) that bypass the patterns used elsewhere.

---

## Counts & Inventory

**File counts (from disk):**

| Layer | Count |
|---|---|
| API endpoints (`api/**/*.php`, excluding `api/bootstrap.php`) | 319 |
| Admin pages (`app/admin/**/*.php`) | 93 |
| Portal pages + portal APIs (`app/portal/**/*.php`) | 37 |
| Cron scripts (`cron/*.php`) | 9 |
| Lib classes (`lib/**/*.php`) | 24 |
| Shared includes (`includes/*.php`) | 15 |
| Schema tables (CREATE TABLE in `FLEETFORGE_DATABASE_MASTER.sql`) | 94 |
| Tables with `deleted_at` column in master SQL | 15 |
| Entries in `SOFT_DELETE_TABLES` constant in `includes/db.php` | 16 (one invalid — see AUDIT2026-1) |

**Crons present vs spec-required:**

| Present | Missing (per spec §3 crontab + accounting spec §18) |
|---|---|
| ai_anomaly_scan, collections_auto_escalate, compliance_alerts, gps_mileage_sync, invoice_generate_monthly, invoice_overdue, late_fee_apply, promise_to_pay_check, samsara_sync | health_scores, risk_scores, ai_fleet_brief, cache_cleanup, archive_old_data, notification_digest, reconcile_counters, backup_db, accounting_generate_periods, accounting_auto_reverse, accounting_recurring_entries, accounting_tax_filing_reminders |

Total crons on disk: **9**. Total crons spec-required: **~17** (13 core + 4 accounting). Drift: roughly half of the spec's automation surface is absent.

**Lib classes present vs spec-required:**

Present: `AI/{AnomalyDetector, ClaudeClient, SummaryEngine, TokenTracker, ToolRegistry, Tools/FleetForgeTools}`, `Accounting/{AccountingService, AutoEntryBridge, BankService, FixedAssetService, JournalEntryService, TaxFilingService}`, `Billing/{CreditEngine, InvoiceGenerator, LateFeeEngine, ProRateCalculator, TaxCalculator}`, `Email/EmailService`, `GPS/SamsaraClient`, `Messenger/MessengerService`, `Notifications/{Mailer, NotificationService}`, `Reports/ReportBuilder`, `Storage/StorageClient`.

Missing per spec §3: `lib/Billing/CurrencyConverter.php`, `lib/Billing/MileageCalculator.php`, `lib/Billing/DiscountEngine.php` (logic is inlined into `InvoiceGenerator` instead — acceptable but drift), `lib/PDF/{InvoicePDF, ContractPDF, InspectionPDF}.php` (entire folder missing — no PDF generation for invoices), `lib/QR/QRGenerator.php` (spec §4.43), `lib/Export/{CSVExporter, PDFExporter}.php`, `lib/AI/PromptBuilder.php`, `lib/AI/ResponseCache.php`.

**Admin pages thin/missing vs spec:** `app/admin/documents/` has only `index.php` (spec describes upload flow, PDF preview). `app/admin/compliance/` single page. `app/admin/tracking/` single page. `app/admin/analytics/` single page. `app/admin/audit/` single page (read-only — spec expected). No `contracts/` module (spec §3).

**Portal pages missing:** Spec §15 lists a `requests/` thread view with reply chain, `account/users.php` sub-user management. `app/portal/account/users.php` exists — verified. `requests/view.php` exists. Most of spec §15 surface is present, though skinny. No portal invoice "pay online" action surface (spec §15.4 lists "Pay Online" button — not in scope per locked decisions).

**Table reference coverage (via grep on `FROM|JOIN|INSERT INTO|UPDATE|DELETE FROM|db_insert|db_update`):**

- True orphans (0 reads AND 0 writes in application code): `customer_discounts`, `yard_transfers`, `contract_templates`, `generated_contracts`, `scheduled_reports`, `saved_reports`, `notification_rules`, `acc_recurring_entries`, `acc_recurring_entry_lines`, `acc_fx_revaluations`, `acc_documents`, `acc_year_end_checklist`, `acc_report_configurations`. `audit_log_archive`, `acc_qbo_sync_log`, `schema_migrations` are expected-stubs.
- Write-only (writes > 0, reads = 0 in production paths): `equipment_status_log` (13 write sites, 0 read sites outside scripts), `customer_rate_history` (2 writes, 0 reads), `lease_billing_periods` (1 write, 0 reads).

---

## Findings by Pass

---

### Pass 2 — Soft-Delete Compliance

<a id="audit2026-1"></a>
**AUDIT2026-1 — `notifications` listed in `SOFT_DELETE_TABLES` but has no `deleted_at` column**
Severity: **CRITICAL**
File: `includes/db.php:35` (constant) + `FLEETFORGE_DATABASE_MASTER.sql:1367-1386` (table definition)
Current behavior: The constant `SOFT_DELETE_TABLES` in `db.php` includes `'notifications'` with an accompanying `[NOTIF-1]` comment. `FLEETFORGE_DATABASE_MASTER.sql` lines 1367–1386 define the `notifications` table with columns `id, user_id, rule_id, title, message, url, entity_type, entity_id, severity, is_read, read_at, created_at` and no `deleted_at`. `db_exists()` auto-appends `AND deleted_at IS NULL` for any table in the constant (line 174), so any caller of `db_exists('notifications', ...)` will throw `Unknown column 'deleted_at' in where clause`. The only reason the error is latent is that no call site currently calls `db_exists('notifications', ...)`.
Expected behavior: Either add `deleted_at DATETIME NULL` + `idx_deleted` to the `notifications` table, or remove `notifications` from the `SOFT_DELETE_TABLES` constant.
Recommended fix: Remove the entry from the constant — `notifications` in the current spec are cleared by deleting the row after a user dismisses them (nothing in `NOTIF-1` design requires audit trail on dismissal). One-line change.
Estimated sessions: 0.5

<a id="audit2026-2"></a>
**AUDIT2026-2 — Chat record picker queries non-existent `reservations.reservation_number` column**
Severity: **CRITICAL**
Files: `api/v1/chat/search/records.php:221,225,233`; `api/v1/chat/attachments/available.php:85,89`
Current behavior: Both endpoints reference `r.reservation_number`. The `reservations` table in `FLEETFORGE_DATABASE_MASTER.sql:584-612` has no such column — the schema tracks the primary key only. `api/v1/search.php:270` has a comment "reservations table has no reservation_number — lookups are by numeric" confirming the main search was patched; the chat endpoints (CHAT-1 session, 2026-04-08) were written after but duplicated the earlier bug. SQL throws `Unknown column 'r.reservation_number'` the moment a chat user selects the "reservation" filter.
Expected behavior: Search reservations by primary key cast to string, plus `company_name` / `contact_name` (same pattern as `api/v1/search.php`). Title rendered as `#{id} — {company_name}`.
Recommended fix: Mirror the pattern in `api/v1/search.php` (CAST id to CHAR for LIKE match, display as `#{id}`, drop the non-existent column).
Estimated sessions: 0.5

**AUDIT2026-11 — Accounting joins onto soft-deletable tables do not filter `deleted_at`**
Severity: **MINOR**
Files: `api/v1/accounting/ar/deposits/index.php:50-52`; `api/v1/accounting/ar/statement.php:81-98`; `api/v1/accounting/ar/bad_debt_writeoffs/index.php:39`; `api/v1/accounting/ar/promise_to_pay/index.php:47`; `api/v1/accounting/ar/collection_notes/index.php:47`
Current behavior: Each of these performs LEFT JOIN onto `customers`, `leases`, or `invoices` without adding `AND <alias>.deleted_at IS NULL` on the JOIN. Because the main-subject tables (`acc_customer_deposits`, etc.) are not soft-deletable, the LEFT JOIN silently includes rows where the joined entity has been soft-deleted, displaying the deleted entity's name in the statement/deposit listing.
Expected behavior: JOIN clauses to soft-deletable tables must include `deleted_at IS NULL` on BOTH sides of every JOIN (CLAUDE_CODE_REFERENCE.md §5 "Trap").
Recommended fix: Append `AND <alias>.deleted_at IS NULL` to each JOIN ON clause in the five cited files.
Estimated sessions: 0.5

**AUDIT2026-12 — Other user-visible paths missing `deleted_at` on JOIN**
Severity: **MINOR**
Files: verified sampling clean via spot-checks (every admin list endpoint I opened includes the filter); flagged above are the exceptions. JSON-aware searches that I reviewed (`api/v1/chat/search/records.php`, `api/v1/search.php`) do filter correctly.
Current behavior: OK in the main app. Soft-delete hygiene is good on the non-accounting side.
Expected behavior: n/a.
Recommended fix: Treat AUDIT2026-11 as the exception and add a one-off code review checklist to "always filter both sides of a LEFT JOIN onto a soft-deletable table."
Estimated sessions: 0.5 (for the checklist + ad-hoc sweep)

---

### Pass 3 — Transaction & Lock Discipline

Overall: strong. The patterns laid out in the spec (FOR UPDATE on lease unit rows, FOR UPDATE on invoice rows during payment allocation, consistent lock ordering for credit application, optimistic lock on every spec-listed update endpoint, `GET_LOCK` on every write cron) are all implemented. Two minor issues:

<a id="audit2026-14"></a>
**AUDIT2026-14 — Optimistic-lock comparison uses string `!==` against DB `updated_at`**
Severity: **MINOR**
File: e.g. `api/v1/customers/update.php:68`; pattern repeats across all update endpoints.
Current behavior: `if ($submittedUpdatedAt !== $existing['updated_at'])` compares the client-submitted string to the DB value exactly. PDO returns DATETIME as the string MySQL stores (`'2026-04-18 09:31:02'`). Since the form roundtrips that literal string, comparison works. Risk only if a client (e.g. an SDK) normalises the value to ISO 8601 or adds fractional seconds, which would always trigger STALE_DATA.
Expected behavior: Comparison is robust to format drift.
Recommended fix: Defensively parse both sides with `strtotime()` and compare epochs, or normalise the outgoing `updated_at` to a single canonical shape the server echoes back.
Estimated sessions: 0.5

**AUDIT2026-15 — Advisory-lock pattern uses `try/finally` correctly but no telemetry on lock-skip**
Severity: **NIT**
Files: all 9 cron scripts (e.g. `cron/invoice_generate_monthly.php:43-228`)
Current behavior: When `GET_LOCK` returns 0 because another instance holds the lock, the cron `exit(0)` silently. If this becomes chronic (hung process holding the lock) nothing in `logs/cron.log` flags it.
Expected behavior: Log the skip and consider a wall-clock watchdog.
Recommended fix: `error_log("[CRON $name] skipped — another instance holds ff_cron_*")` before `exit(0)`.
Estimated sessions: 0.5

---

### Pass 4 — Monetary Correctness

Overall: strong. `ProRateCalculator` and `TaxCalculator` both use bcmath throughout, accept/return strings, keep intermediate scale at 6 and round to 2 at output. `InvoiceGenerator` updates denormalised counters (`leases.total_invoiced`, `customers.outstanding_balance`, `leases.outstanding_balance`, `leases.last_billed_date`, `leases.last_billed_invoice_id`) inside the same transaction as the invoice insert (line 401–412). The tax-rate storage convention (0.0500 = 5 %) and the `bcmul(subtotal, rate, 6)` multiplication is internally consistent.

Pro-rate monotonicity check: tested `calculate(29, 100, 500, 1800)` → `weekly_capped` at 1800.00, `calculate(30, 100, 500, 1800)` → monthly at 1800.00, `calculate(31, 100, 500, 1800)` → 1860.00. No regression at the 29/30 boundary. Also verified with rates that don't trigger cap (daily 20 / weekly 100 / monthly 300): days 20→21→22→30 produce $285.71 → $300.00 → $300.00 → $300.00 — non-decreasing. Formula is safe.

<a id="audit2026-16"></a>
**AUDIT2026-16 — Only 3 of 10 Canadian provinces seeded in `tax_rates`**
Severity: **MAJOR**
File: `database/seeds/006_tax_rates.sql`
Current behavior: Seed contains BC, ON, AB. Customers in SK, MB, QC, NS, NB, NL, PE, YT, NT, NU are silently billed at 0 % tax because `TaxCalculator::lookupRates()` (`lib/Billing/TaxCalculator.php:96-121`) returns zeros when no row is found for the province. `FLEETFORGE_DESIGN_DETAILS.md:518-529` explicitly lists all 10 provinces as the seeded set.
Expected behavior: All 10 provinces seeded with current rates; a missing row should trigger an audit warning, not silent zero tax.
Recommended fix: Add the 7 missing province rows to the seed. Separately: in `TaxCalculator::lookupRates`, log a warning if no row is found and the caller's `gstExempt && pstExempt` flags aren't both true.
Estimated sessions: 0.5

**AUDIT2026-17 — Legacy `tax_exempt` flag path is consulted at invoice time, but `gst_exempt_expiry` / `pst_exempt_expiry` are NOT consulted at invoice time**
Severity: **MAJOR**
Files: `lib/Billing/InvoiceGenerator.php:206-213`; `api/v1/leases/create.php:247-251`
Current behavior: Lease creation checks the customer's `gst_exempt_expiry` against the lease start date and demotes the exemption if expired (good). But once the lease is stamped with `gst_exempt=1`, the `InvoiceGenerator` never re-validates. An open-ended lease whose customer's exemption certificate expires six months in will still generate exempt invoices forever. Spec §9 ("Tax Rules") explicitly requires runtime expiry check + flag for review.
Expected behavior: InvoiceGenerator should re-read `customers.gst_exempt_expiry` / `pst_exempt_expiry` at each invoice generation. If expired as of `invoiceDate`, demote the exemption and emit a `notification_rules`-style review flag.
Recommended fix: Inside `createFromLease()` tax step, JOIN `customers` and compare expiry against `$invoiceDate` before calling `TaxCalculator::calculate()`.
Estimated sessions: 1

**AUDIT2026-18 — Line-item emission uses `bcmath` correctly but chart-serializer casts `(float)bcround(...)` for ApexCharts**
Severity: **NIT**
File: `api/v1/dashboard/charts.php:433` (`$series[] = (float) bcround((string) $row['total'], 2);`) — and ~8 other series generators.
Current behavior: Charts render sums with a `(float)` cast. Precision loss is bounded because the cast happens AFTER bcround(...,2), but the cast itself defeats the bcmath discipline. For very large fleets (sums approaching 10⁹) this could lose a cent in the chart label versus the drill-down.
Expected behavior: Chart series can be sent as strings — ApexCharts accepts numeric strings.
Recommended fix: Change `(float) bcround(...)` to just `bcround(...)` and let ApexCharts parse.
Estimated sessions: 0.5

---

### Pass 5 — Security

Overall: strong. CSRF enforced on state-changing methods when an authenticated session exists (`api/bootstrap.php:84-110`). All sampled endpoints call `require_auth_api()` + `require_permission(module,action)` in the right order. `finfo_file()` used for MIME on every upload path verified (documents, inspections/photos, damage_claims/upload_photo, chat/upload, email/attachments/upload). `StorageClient::upload()` renames uploads to `{entity}_{type}_{ts}.{ext}` as required; raw paths are stripped from API responses in the `documents/serve.php` style endpoint at `api/v1/storage/serve.php`. Portal queries all scope by `portal_customer_id()` — verified on `portal/index.php`, `portal/invoices/`, `portal/leases/`. `config/app.php:141-150` sets every session cookie flag correctly and makes `cookie_secure` conditional on `APP_ENV === 'production'` (correct for local HTTP dev). `ff_session_start()` enforces `SESSION_LIFETIME` idle timeout (`includes/auth.php:32-40`). Remember-me stores SHA-256 hashes, not plain tokens (`includes/auth.php:218-243`).

<a id="audit2026-19"></a>
**AUDIT2026-19 — No MFA / 2FA on admin panel**
Severity: **MAJOR**
Files: none — audit-wide grep for "totp", "authenticator", "2fa", "mfa" returns zero production hits.
Current behavior: Admin login is single-factor email + password.
Expected behavior: Spec §14.4 lists 2FA as an explicit checklist item for the admin security posture. Required before production.
Recommended fix: Add `users.totp_secret VARCHAR(64) NULL`, `users.mfa_enforced TINYINT(1) DEFAULT 0`, `users.mfa_backup_codes JSON NULL`. Wire up TOTP via PHP's `ParagonIE` or OTPHP library. Gate by role: Super Admin and Manager should be `mfa_enforced=1` by default.
Estimated sessions: 2

**AUDIT2026-20 — Admin POST endpoints skip `require_auth_api()` in one place**
Severity: spot-check sample clean. Agents verified 20 endpoints — every POST/DELETE calls both guards.
Current behavior: OK.
Expected behavior: OK.
Recommended fix: Add a CI grep check for any `api/v1/**/!(index|show).php` that doesn't call both helpers.
Estimated sessions: 0.5

**AUDIT2026-21 — JSON auto-merge into `$_POST` is safe but undocumented**
Severity: **NIT**
File: `api/bootstrap.php:113-146`
Current behavior: When `Content-Type: application/json` and `$_POST` is empty, bootstrap decodes the body and `array_merge`s it into `$_POST`. Every endpoint then reads `$_POST['...']`. This is safe because every field is subsequently pushed through `clean_*()` helpers, and the merge only fires when `$_POST` is empty, so a malicious JSON body can't overwrite a form-submitted value. But: if an endpoint *ever* accepts both a form and a JSON body (none found in my sweep), the behaviour would be surprising.
Expected behavior: Prefer `json_body()` in endpoints; reserve `$_POST` reads for form-posted pages.
Recommended fix: Document the intent; optional — deprecate direct `$_POST` reads in JSON-only endpoints.
Estimated sessions: 0.5

**AUDIT2026-22 — `require_input()` is defined in bootstrap but never called**
Severity: **NIT**
File: `api/bootstrap.php:371-395`
Current behavior: Zero callers project-wide. It also uses a third error envelope shape (`{success:false, errors:{...}}`) that differs from both the spec and `json_validation_error()`. Dead code.
Expected behavior: Either adopt it consistently or remove.
Recommended fix: Remove (no callers) and rely exclusively on `json_validation_error()` in endpoint-local validation.
Estimated sessions: 0.5

**AUDIT2026-23 — No rate limiting on login endpoint (DoS/brute-force mitigation beyond the 5-attempt lockout)**
Severity: **MAJOR**
File: `app/auth/login.php` (not re-read in this session — deferred) + `api/bootstrap.php`
Current behavior: The spec's 5-attempt-lockout-then-15-min is implemented (per S013). But there's no IP-level or global rate limit on `/auth/login`, `/auth/forgot_password`, or the `/api/v1/ai/*` endpoints. An attacker can burn through AI token budget or DDoS the login form.
Expected behavior: Token-bucket or IP-window rate limit at the Apache level (`mod_ratelimit` + fail2ban) plus a soft app-level rate limit on expensive endpoints (AI, global search).
Recommended fix: Add a `rate_limit_log` table or use a Redis/memcached sliding-window counter; apply to login + AI + export.
Estimated sessions: 2

---

### Pass 6 — State Machines

Overall: strong. Transition maps are defined inline in every state-change endpoint and checked before the UPDATE (spot-checked `api/v1/equipment/units/update_status.php:58-92`, `leases/activate.php:71-74`, `leases/close.php:105-109`, `invoices/send.php:44-46`, `invoices/void.php:47-51`, `reservations/update_status.php:67-99`, `maintenance_work_orders/update_status.php:65-100`, `damage_claims/update.php:85-102`). Each writes to the corresponding `_status_log` table OR to `audit_log` with `action='status_change'`.

<a id="audit2026-24"></a>
**AUDIT2026-24 — No `invoice_status_log` table; `audit_log` is the only status history**
Severity: **MINOR**
Files: `api/v1/invoices/send.php:65-75`, `void.php:79-89`; no `invoice_status_log` in schema.
Current behavior: Every invoice status transition writes to `audit_log` with `action='status_change'` — functional, but means the Invoice-view "status timeline" (S019-EXT rebuilt show page, line 110-ish) depends on `audit_log` rather than a dedicated log. The `lease_status_log` pattern was built (`FLEETFORGE_DATABASE_MASTER.sql:552-565`), but an equivalent `invoice_status_log` was never created.
Expected behavior: Either build the dedicated log table (consistent with leases/equipment) or explicitly document that audit_log is the canonical invoice status history.
Recommended fix: Document in the CLAUDE_CODE_REFERENCE.md that audit_log is canonical for invoices, payments, credit_notes, work_orders, damage_claims — no dedicated log for these entities.
Estimated sessions: 0.5

**AUDIT2026-25 — Terminal status blocks rely on the transition map, not explicit API guard**
Severity: **NIT**
Files: state-change endpoints across modules.
Current behavior: E.g. `equipment_units` status=`decommissioned` has an empty allowed-transitions list. An attempt to transition from `decommissioned` returns 409 INVALID_TRANSITION. But nothing explicitly says "decommissioned is terminal" — the absence of entries in the map is the enforcement.
Expected behavior: Functionally fine; the design choice is sound. Worth adding a comment block + unit test covering the terminal-status cases explicitly so future contributors can't accidentally widen the map.
Recommended fix: Add a test per entity that iterates terminal statuses and asserts they reject every proposed transition with 409.
Estimated sessions: 1

**AUDIT2026-26 — Sent-invoice immutability is enforced at the update.php level, but not documented in the data model**
Severity: **NIT**
File: `api/v1/invoices/update.php:40-48`
Current behavior: Blocks non-draft edits with 422 `IMMUTABLE_RECORD` (correct). But a super-admin override path exists (`invoices/update.php` has a super_admin gate added in S020-EXT2) that bypasses the block for financial fields. This is technically a spec violation — `PASS-13:F1` says financial fields are immutable *period*, with correction via void + re-create.
Expected behavior: Super-admin should be able to fix free-text fields (internal notes, po_number, sent_to_email) but NOT financial fields. A void-and-recreate workflow should be the only way to change a sent invoice's total.
Recommended fix: Split the update path. Super-admin can edit metadata on any invoice. Changing `subtotal/tax_*/total_amount` requires void + new invoice, even for super-admin. Add a CRA-compliance comment explaining why.
Estimated sessions: 1

---

### Pass 7 — Spec-to-Code Drift (Deep)

<a id="audit2026-3"></a>
**AUDIT2026-3 — Eight of the required nightly crons are missing**
Severity: **CRITICAL**
Files: none — expected at `cron/health_scores.php`, `cron/risk_scores.php`, `cron/ai_fleet_brief.php`, `cron/cache_cleanup.php`, `cron/archive_old_data.php`, `cron/notification_digest.php`, `cron/reconcile_counters.php`, `cron/backup_db.php`. All absent. Same for 4 accounting crons (see Inventory above).
Current behavior: No equipment health score, no customer risk score ever recalculates. `report_cache` table grows forever (no `cache_cleanup`). `audit_log` and `notification_log` never archive (no `archive_old_data`). Denormalised counters drift silently over time (no `reconcile_counters`). No database backup cron (`backup_db` is what puts mysqldump into S3). No scheduled-reports dispatch (`notification_digest` is what reads the `scheduled_reports` table — which is already orphan, see AUDIT2026-5).
Expected behavior: All crons as listed in spec §3 + accounting §18.
Recommended fix: Session block of 3–4 sessions to build the 8 core crons + the 4 accounting crons. Each is small but they together unlock an enormous amount of the spec. Start with `backup_db` and `reconcile_counters` — the first protects data, the second guarantees the already-built counter logic stays correct.
Estimated sessions: 3

<a id="audit2026-4"></a>
**AUDIT2026-4 — `equipment_status_log` is write-only**
Severity: **CRITICAL**
Files: writes at 13 locations (grep `db_insert('equipment_status_log'`). No application reads found (`Grep`-confirmed). Spec §7.4 tab 7 "Status Log — every status change ever" is not implemented; unit profile can show no history.
Current behavior: Data is being collected into the table correctly on every status transition; nothing ever reads it. Unit profile tab 7 is absent.
Expected behavior: Unit profile tab 7 renders a table of `equipment_status_log` rows for the unit.
Recommended fix: Add `api/v1/equipment/units/status_log.php` endpoint (paginated, filtered by `equipment_unit_id`), add the missing Status Log tab to `app/admin/equipment/show.php`.
Estimated sessions: 1

<a id="audit2026-5"></a>
**AUDIT2026-5 — `customer_rate_history` is write-only**
Severity: **MAJOR**
Files: writes at `api/v1/customer_equipment_rates/upsert.php:218`, `delete.php:60`. No reads outside stress-test script.
Current behavior: Every override add/change/delete writes a history row. Spec §7.2 customer profile says "Rate History table (toggle-able)" — no UI or API surfaces the history.
Expected behavior: Customer profile "Rates" tab shows a toggle to display history rows.
Recommended fix: Add `api/v1/customers/rate_history.php` and a toggle + table to `app/admin/customers/show.php` rates tab.
Estimated sessions: 1

<a id="audit2026-6"></a>
**AUDIT2026-6 — `lease_billing_periods` is write-only**
Severity: **MAJOR**
File: `lib/Billing/InvoiceGenerator.php:381` (only write); no reads.
Current behavior: The `invoices.billing_period_id` deferred FK points at this table, and InvoiceGenerator creates a row on every invoice. No code ever reads it. This means:
1. There is no duplicate-invoice protection — the cron could generate two invoices for the same lease+month if it were interrupted mid-run and restarted (the advisory lock prevents concurrent runs, but not duplicate rows within a single run).
2. The billing-period tab on the lease profile (spec §7.5 "Billing period preview on create form") cannot display anything.
Expected behavior: `cron/invoice_generate_monthly.php` should SELECT `lease_billing_periods` per lease+month to skip already-billed periods. Lease profile should render the billing history.
Recommended fix: Add a uniqueness check + read path. `ALTER TABLE lease_billing_periods ADD UNIQUE KEY uq_lease_period (lease_id, period_start_date)` if not present. Add `api/v1/leases/billing_periods.php`. Add check in `InvoiceGenerator::createFromLease()` before insert.
Estimated sessions: 1

<a id="audit2026-7"></a>
**AUDIT2026-7 — Mid-month lease close does not void the existing auto-draft**
Severity: **CRITICAL**
Files: `api/v1/leases/close.php:203-271`; spec §13 "Monthly auto-draft exists when lease closes" + [PASS-3:2C].
Current behavior: `close.php` picks `periodStart = day after last_billed_date` and calls `InvoiceGenerator::createFromLease()` to produce the final invoice. It does NOT look up any existing draft for the current month, nor void it. Scenario: cron runs 2026-04-01 at 06:00 and creates a draft for the full April period (cost $1,800), setting `leases.last_billed_date=2026-04-01`. Lease closes on 2026-04-18. `close.php` generates a final invoice for 2026-04-02 through 2026-04-18 and voids nothing. The customer now has both a $1,800 draft covering April AND a ~$1,080 final invoice covering the first 17 days. Denormalised `leases.total_invoiced`, `leases.outstanding_balance`, and `customers.outstanding_balance` all double-count.
Expected behavior: Per spec §13 "Monthly auto-draft exists when lease closes | Void draft, generate final invoice for partial period" — AND per PASS-3:2C "if close date = last day of month AND auto-draft exists: APPEND mileage reconciliation line items to the existing draft, don't regenerate."
Recommended fix: Before calling `createFromLease()` in `close.php`: (a) query for an existing draft where `lease_id = ? AND status = 'draft' AND billing_period_start >= first_of_month(close_date)`. (b) If found and close_date < last_day_of_month: void the draft (which reverses the counters), then generate the final. (c) If found and close_date = last_day_of_month: call a new `InvoiceGenerator::appendMileageToExisting($invoiceId, $extraLines)` method.
Estimated sessions: 1

<a id="audit2026-8"></a>
**AUDIT2026-8 — Overpayment rejected, not routed to customer credit**
Severity: **CRITICAL**
Files: `api/v1/payments/create.php:129-140`
Current behavior: If `amount > invoice.balance_due`, the endpoint returns 422 with "Payment amount exceeds invoice balance". Staff cannot record an overpayment at all; they must edit the amount to exactly match.
Expected behavior: Spec §13 "Payment > invoice total | Apply to invoice, remainder → account_credit_balance". Accounting spec §16 rule [PASS-6:G4] "Overpayment credited to account | DR Cash [full] / CR AR [allocated] + CR 2060 Customer Advances [overpayment]".
Recommended fix: Remove the 422 block. Inside the transaction: allocate `min(amount, balance_due)` to the invoice; if `amount > balance_due`, create a `credit_notes` row of type `'overpayment'` for the remainder, linked to the customer; auto-entry bridge posts DR 1010 Cash / CR 1030 AR (allocated) + CR 2060 (remainder). Update counters: `customers.account_credit_balance += remainder`.
Estimated sessions: 2

**AUDIT2026-9 — `customer_discounts` table exists but no code reads or writes it**
Severity: **MAJOR**
Files: schema `FLEETFORGE_DATABASE_MASTER.sql:680-701`; zero code references.
Current behavior: Spec §9 "Discount Application" says the resolution order is (1) `customer_discounts` — per-lease or per-equipment-type, time-limited → (2) `customers.discount_type` / `discount_value` → (3) manual override. Only (2) and (3) are implemented. The `customer_discounts` table is seeded with columns but no code path touches it. Any customer with a time-limited or equipment-specific discount can't be modeled.
Expected behavior: Lease create should check `customer_discounts` first (active today + matching equipment type). The customer profile needs a tab/UI to manage these.
Recommended fix: Add `api/v1/customer_discounts/{index,create,update,delete}.php` + admin UI + update `InvoiceGenerator` discount resolution step (currently at `lib/Billing/InvoiceGenerator.php:190-200`) to consult the table first.
Estimated sessions: 2

**AUDIT2026-10 — Entire contracts module absent; `contract_templates` + `generated_contracts` are orphan**
Severity: **MAJOR**
Files: schema tables exist; no code path anywhere.
Current behavior: Spec §8 Phase 13 ("Documents & Contracts") lists contract template editor + contract generator + PDF generation; none are built. `storage/uploads/contracts/` directory exists but is empty.
Expected behavior: Contract templates CRUD + generator producing a PDF stored via `StorageClient`, linked from each lease.
Recommended fix: Phase 13 of the build is unbuilt. 2 sessions minimum.
Estimated sessions: 2

**AUDIT2026-27 — `scheduled_reports` + `saved_reports` orphan**
Severity: **MINOR**
Files: schema tables exist; no code path. Spec §4.30 Report Scheduling promises configurable recurring-email report delivery; spec §4.26 Saved Reports says users can save + name their configurations.
Current behavior: Not built.
Expected behavior: Table-driven scheduled report delivery via `notification_digest.php` cron (which is also absent — see AUDIT2026-3).
Recommended fix: Build alongside the `notification_digest` cron.
Estimated sessions: 1

<a id="audit2026-13"></a>
**AUDIT2026-13 — API error envelope shape drifted from spec**
Severity: **MAJOR**
Files: `api/bootstrap.php:180-216` (`json_error()`, `json_validation_error()`); `api/bootstrap.php:371-395` (`require_input()`); every JS caller in `public/assets/js/app.js`.
Current behavior:
- Spec §11: `{ "success": false, "error_code": "...", "message": "...", "fields": {...} }` (top-level `error_code`/`message`/`fields`)
- `json_error()` in code: `{ "success": false, "error": { "code": "...", "message": "..." } }` (nested under `error`)
- `require_input()` in code: `{ "success": false, "errors": { ... } }` (plural key, no code)
- JS client reads `res.error.code` / `res.error.message` (consistent with bootstrap, not with spec).
Internally the code is consistent with itself (JS and server agree). Externally every consumer following the spec will fail.
Expected behavior: Pick ONE shape and make spec + code + JS agree.
Recommended fix: Update the spec to match the code's `{success, error: {code, message, fields?}}` shape (simpler migration than rewriting every endpoint), delete the dead `require_input()` helper. Alternatively: rewrite `json_error` to match spec and update the JS client — a larger change.
Estimated sessions: 1 (spec update + dead code removal) or 3+ (rewrite to match spec)

**AUDIT2026-28 — Lib Billing classes inlined into InvoiceGenerator instead of separate files**
Severity: **NIT**
Files: `lib/Billing/InvoiceGenerator.php:190-213` (discount logic + legacy-exempt fall-through); no `lib/Billing/DiscountEngine.php`, no `lib/Billing/MileageCalculator.php`, no `lib/Billing/CurrencyConverter.php`.
Current behavior: Spec §9 lays out `DiscountEngine`, `MileageCalculator`, and `CurrencyConverter` as pure-math classes. The logic is present but inlined into `InvoiceGenerator`. Testability harder; single-responsibility broken.
Expected behavior: Separate pure-math classes the `InvoiceGenerator` composes.
Recommended fix: Refactor during a future billing session. Not urgent — inlined code is correct.
Estimated sessions: 1

---

### Pass 8 — Logical Fallacies & Hidden Risks

Scenarios tested on paper:

1. **Mixed CAD+USD customer, USD payment → CAD invoice allocation.** Blocked correctly with 422 CURRENCY_MISMATCH (`api/v1/payments/create.php:114-117` pre-check; `:168-171` lock-held re-check). ✓ **Works correctly.**

2. **Soft-deleted lease, invoices persist.** Verified: `invoices.deleted_at` is independent, invoices are queryable after lease soft-delete (`api/v1/invoices/show.php` joins lease without filter — that's the intended behavior so historical invoices survive). Caveat: spec silent on whether admin should still be able to *view* invoices for a deleted lease; current behavior allows it. ⚠ **Works, but ambiguous intent.**

3. **`gst_exempt_expiry` passes mid-lease.** Invoice-time re-check missing. See AUDIT2026-17. ✗ **Broken.**

4. **Two admins edit same customer simultaneously.** Optimistic lock via `updated_at` string compare catches the second save (`api/v1/customers/update.php:68-71`), returns 409 STALE_DATA. ✓ **Works correctly.**

5. **Samsara cron vs invoice cron contention.** Both crons have their own advisory lock (`ff_cron_samsara_sync` vs `ff_cron_invoice_generate_monthly`), and they touch different tables primarily. ✓ **Works correctly.**

6. **Portal user whose parent customer is soft-deleted.** `app/portal/includes/auth.php:81-84` checks `customers.deleted_at IS NULL` + `status`. ✓ **Blocked correctly.**

7. **Legacy `tax_exempt` vs `gst_exempt` + `pst_exempt`.** `InvoiceGenerator::createFromLease()` reads `lease.tax_exempt` and, if true, forces both granular flags to true (lines 210-213). Any code path that reads only `tax_exempt` gets the right answer. But any path that reads only `gst_exempt`/`pst_exempt` would miss the legacy flag. I traced the code paths and found only `InvoiceGenerator` consumes exemption flags — all other callers pass through it. ⚠ **Works currently, but fragile — the legacy fall-through is lease-generator-local.**

8. **Stale reservation in `pending` forever.** No cleanup cron. Unit sits in `reserved` indefinitely, blocking other reservations via the conflict check. See AUDIT2026-3 (missing cron). The reservation does auto-block the unit but never auto-cancels itself. ✗ **Broken — no stale-reservation cron.**

9. **Payment > invoice balance.** Rejected with 422 instead of routed to credit. See AUDIT2026-8. ✗ **Broken.**

10. **Auto-draft exists, lease closes mid-month.** Double-invoice. See AUDIT2026-7. ✗ **Broken.**

11. **Mileage credit > invoice total, remainder should create a `credit_notes` row.** Checked `close.php` and `InvoiceGenerator`. No routing logic found — a large negative adjustment would push the invoice subtotal negative with no overflow to `credit_notes`. Confirmed via the sub-agent report and my own read of `close.php:211-232` + `InvoiceGenerator.php:180-188`. ✗ **Broken — mileage credit can push invoice total negative.**

12. **Invoice numbers gap-free.** `generate_id()` uses FOR UPDATE on the settings row inside a db_transaction. Even if an invoice insert throws, the counter has already been UPDATEd within the same transaction — which rolls back together. ✓ **Works correctly.**

13. **User with denied role changes trying to access a module directly by URL.** Both `require_auth()` (page) and `require_auth_api()` (API) redirect or 403 respectively; admin sidebar visibility is driven by the same `can()` checks. ✓ **Works correctly.**

---

### Pass 9 — Production Readiness

<a id="audit2026-29"></a>
**AUDIT2026-29 — No error-monitoring hook (Sentry / Bugsnag / Rollbar)**
Severity: **MAJOR**
Current state: `api/bootstrap.php:54-76` has a generic `set_exception_handler` that writes to `error_log` and returns a JSON 500. No error aggregator.
Expected: An aggregator so unhandled exceptions are reviewed, not lost in logs.
Recommended fix: Add Sentry PHP SDK via Composer + a `lib/ErrorReporter.php` + hook in both `api/bootstrap.php` and page exception handlers.
Estimated sessions: 1

<a id="audit2026-30"></a>
**AUDIT2026-30 — No log rotation**
Severity: **MAJOR**
Current state: `logs/` on disk contains `app.log`, `cron.log`, `gps.log`, `ai.log`, `mail.log`, `error.log`. No rotation config (logrotate, monolog, etc.). On a 12-month production deployment these will reach the GB range and OS disk.
Expected: OS-level logrotate config documented in the deployment guide; or monolog with `RotatingFileHandler`.
Recommended fix: Add `infra/logrotate.conf` snippet in the deployment guide + cron to delete > 90 days.
Estimated sessions: 0.5

**AUDIT2026-31 — Uptime health endpoint present but minimal**
Severity: **MINOR**
File: `api/v1/health.php` exists (verified during AUDIT-2 session, line comment "version string, disk metrics, and cache info now only returned to authenticated sessions").
Current: Returns `{status, db, time}` for unauthenticated callers. Good shape.
Expected: External pingers can hit it. Works.
Recommended fix: Add a periodic smoke test from outside the network.
Estimated sessions: 0.5

**HSTS / CSP / X-Frame / Permissions-Policy / Referrer-Policy** — all set in `public/.htaccess`. ✓ Present.

**Admin session idle timeout** — enforced (`includes/auth.php:32-40`). ✓ Present.

**2FA/MFA** — See AUDIT2026-19. ✗ Missing.

<a id="audit2026-32"></a>
**AUDIT2026-32 — No database backup cron (and no storage backup)**
Severity: **CRITICAL for go-live**
Current: Spec §3 crontab lists `cron/backup_db.php` every 6 hours. Not on disk. No `s3 sync` or equivalent for `storage/uploads/`.
Expected: mysqldump + `s3 cp` of `storage/uploads/` on a schedule.
Recommended fix: Build `cron/backup_db.php` (runs `mysqldump` + uploads to `s3://bucket/backups/db_*.sql.gz`). Add weekly `storage/uploads/` tarball to S3. Verify restorability with a monthly automated restore-to-throwaway-DB cron.
Estimated sessions: 1

**AUDIT2026-33 — Email bounce/complaint handler for SES**
Severity: **MAJOR**
Current: `Mailer::send()` sends via SES SMTP but has no SNS bounce handler. An invalid email address will silently accumulate hard bounces, eventually SES suspends sending.
Expected: SNS topic → POST endpoint → update `email_logs` + disable delivery to hard-bounced addresses.
Recommended fix: Add `api/v1/webhooks/ses_notifications.php` with HMAC verification, update `email_logs.status='bounced'` and set `customers.email_disabled=1` on hard bounces.
Estimated sessions: 1

**AUDIT2026-34 — Restore drill, staging environment, key-rotation runbook: undocumented**
Severity: **MAJOR**
Current: Deployment guide exists but no documented restore drill, no staging env, no key-rotation procedure.
Expected: `docs/runbooks/{restore, rotate_secrets, promote_staging_to_prod}.md`.
Recommended fix: Write each runbook.
Estimated sessions: 1

**AUDIT2026-35 — Secrets policy mostly consistent after INT-1**
Severity: **NIT**
Current: Every lib/ third-party client follows "settings table first, .env fallback" per INT-1 session. Verified in `lib/GPS/SamsaraClient.php`, `lib/AI/ClaudeClient.php`, `lib/Notifications/Mailer.php`, `lib/Storage/StorageClient.php`. Settings-side secrets are masked in the UI (`app/admin/settings/index.php` — verified).
Expected: ✓ Present.
Recommended fix: Add a test that asserts the precedence rule for each client.
Estimated sessions: 0.5

**AUDIT2026-36 — Privacy policy / ToS pages**
Severity: **MINOR** (deferred product decision)
Current: No public privacy policy or ToS page. Spec doesn't explicitly mandate.
Expected: Required before external customer portal use.
Recommended fix: Static pages under `public/legal/` served without auth.
Estimated sessions: 0.5

---

### Pass 10 — Enhancement Opportunities

Not bugs — recommendations beyond the spec.

**A. Online payment processing** — Stripe or Square integration via the portal. The spec explicitly deferred this; adding it would dramatically reduce AR days. **Business impact: HIGH. Estimate: 5 sessions.**

**B. E-signature integration for contracts** — DocuSign or HelloSign for lease contract signing. Currently spec assumes PDF download + scan-and-email. E-sig dramatically shortens new-lease turnaround. Would also pair with AUDIT2026-10 (contracts module build). **Business impact: HIGH. Estimate: 3 sessions (after contracts module).**

**C. Automated dunning / AR escalation cron** — Tied to AUDIT2026-3 missing crons. The spec's 30/60/90 day dunning letters (accounting §5) are described but the cron that ages and emits them doesn't exist. **Business impact: MED. Estimate: 2 sessions.**

**D. Preventive maintenance auto-WO creation** — Nightly cron creates a work order when a unit passes a mileage threshold since last PM, or a calendar interval. Reduces "forgot to service" downtime. **Business impact: MED. Estimate: 2 sessions.**

**E. COI (Certificate of Insurance) tracking + expiry block on customer level** — Spec tracks compliance per unit but not the customer-side insurance certificate. Blocking a lease creation when the customer's COI has expired is a common rental-industry practice. **Business impact: MED. Estimate: 1 session.**

**F. AI write-tools with confirmation step** — Current AI is read-only (tool registry verified). Adding "create a draft invoice", "send this dunning letter" with a two-step human-approval would save hours/week for accounting. **Business impact: MED. Estimate: 3 sessions.**

**G. Custom fields per entity (SaaS readiness)** — As the platform becomes multi-tenant, customers will ask for "add a field to the lease". A generic `entity_custom_fields` + `entity_custom_values` pair would handle this. **Business impact: LOW short-term, HIGH long-term. Estimate: 2 sessions.**

**H. Public API with API keys + rate limiting** — Once the main app is stable, external integrations will want a stable API. Spec hints at `api/v1/` but nothing is externally exposed. **Business impact: MED long-term. Estimate: 2 sessions.**

**I. Webhook layer for external integrations** — Outgoing webhooks on lease.created, invoice.sent, payment.received. Complements (H). **Business impact: LOW-MED. Estimate: 2 sessions.**

**J. Unified inbox (consolidating CHAT/MSGR/NOTIF)** — Three separate surfaces today. A single inbox would simplify the topbar and reduce user confusion. **Business impact: MED. Estimate: 2 sessions.**

**K. Month-end close checklist parallel to year-end** — `acc_year_end_checklist` is orphan (AUDIT2026); a month-end analogue would formalise monthly close. **Business impact: MED for accountant workflow. Estimate: 1 session.**

**L. Denormalised-counter nightly reconciler** — Already named in the spec as `reconcile_counters.php` (missing — see AUDIT2026-3). This is a must-build rather than an enhancement, but flagging it here too because the business value of a nightly "counters match reality" cron compounds with every new feature that touches counters. **Business impact: HIGH. Estimate: 1 session.**

**M. Samsara webhook handler** — Currently Samsara integration is pull-based only (`SamsaraClient::getMileageForLease`). Adding SSE/webhook-based real-time mileage updates would remove the daily cron latency for live-tracking use cases. **Business impact: LOW (current model works). Estimate: 2 sessions.**

**N. Multi-location / yard-level P&L** — Accounting spec A3 locks "no cost center split" but as the business grows this becomes table stakes. **Business impact: LOW short-term. Estimate: 3 sessions.**

---

## Cross-Cutting Observations

Several patterns repeat across many findings and are worth surfacing once, not 20 times:

**Patt-1 — Orphan tables that block spec features.** `equipment_status_log` (write-only), `customer_rate_history` (write-only), `lease_billing_periods` (write-only), `customer_discounts`, `scheduled_reports`, `saved_reports`, `notification_rules`, `contract_templates`, `generated_contracts`, `acc_recurring_entries`, `acc_fx_revaluations`, `acc_documents`, `acc_year_end_checklist`. The schema was built ahead of the code; ~13 tables are shells waiting for their application layer. Each one silently un-implements a spec feature. A "schema-to-code coverage report" (CI check: flag any table with zero `FROM`/`JOIN` + zero `db_insert`/`db_update`/`INSERT INTO`/`UPDATE` calls) would prevent this drift in future sessions.

**Patt-2 — Missing crons are half of the spec's automation.** Eight core + four accounting crons. These aren't "nice to have" — without them the equipment health scores never update, denormalised counters never reconcile, AR never escalates, and the DB is never backed up. One 3-session block that builds all of them would close AUDIT2026-3 and a handful of follow-on issues (AUDIT2026-27, AUDIT2026-33, AUDIT2026-34, parts of Pass 9).

**Patt-3 — Spec drift on error envelope.** Spec, code, and JS client each have a different opinion on `error` vs `error_code` vs `errors`. Not a bug today; will absolutely be a bug the first time someone writes an SDK or builds an external integration.

**Patt-4 — Chat/Messenger module duplicated bug-fix patterns from earlier sessions.** The `reservation_number` drift (AUDIT2026-2) and the raw-`fetch` + missing-CSRF-header class of issue (fixed in MILEAGE-1 / damage_claims session, then re-appeared in chat) suggest Cookie2 of the Claude Code build pattern is leaking into later sessions. A "diff against SEARCH-1/SEARCH-FIX patterns" review in the next chat session would catch these.

**Patt-5 — "Every number is a link" is 80% built, 20% stubbed.** TILES-1 + TILES-2 sessions wired up the list-page tiles and the show-page tiles, but drill-down targets for equipment status log, customer rate history, lease billing periods, and most of the accounting orphan tables have nowhere to drill to. Fixing Patt-1 largely closes this too.

**Patt-6 — No automated test suite.** The `tests/` folder exists but has no PHPUnit config I could see. `scripts/stress_test_*.php` files are ad-hoc PHP scripts. As the codebase crosses 1M lines of PHP equivalent, relying on manual "stop condition" testing per session will start to miss regressions. Two sessions of adding PHPUnit with focused billing + state-machine tests would pay for itself within one real bug.

---

## Recommended Session Plan

Ordered to unblock CRITICAL findings first and respect dependencies.

1. **S-FIX-1** — Remove `notifications` from `SOFT_DELETE_TABLES`; fix `reservation_number` references in chat endpoints; remove unused `require_input` helper. (0.5 session; clears AUDIT2026-1, AUDIT2026-2, AUDIT2026-22.)
2. **S-FIX-2** — Fix monetary/overpayment logic: route overage to `credit_notes`; re-check exemption expiry at invoice time; fix mid-month close void-draft logic. (2 sessions; clears AUDIT2026-7, AUDIT2026-8, AUDIT2026-17. **Must precede S-FIX-3 because the AR subledger reconciliation check depends on these being correct.**)
3. **S-CRON-1** — Build the 8 missing core crons: `backup_db`, `reconcile_counters`, `health_scores`, `risk_scores`, `cache_cleanup`, `archive_old_data`, `notification_digest`, and stale-reservation cleanup. (3 sessions; clears AUDIT2026-3 partly. `backup_db` first, others parallel.)
4. **S-BILL-1** — Fix `lease_billing_periods` read path + duplicate-invoice protection. (1 session; AUDIT2026-6.)
5. **S-LOG-1** — Add read endpoints + UI for `equipment_status_log`, `customer_rate_history`. (1 session; AUDIT2026-4, AUDIT2026-5.)
6. **S-DISC-1** — Build `customer_discounts` endpoints + admin UI + `InvoiceGenerator` consultation. (2 sessions; AUDIT2026-9.)
7. **S-SEC-1** — Add MFA/2FA for admin; add rate limiting. (2 sessions; AUDIT2026-19, AUDIT2026-23.)
8. **S-PROD-1** — Error monitoring (Sentry), log rotation, SES bounce handler, restore drill + staging docs. (2 sessions; AUDIT2026-29/30/33/34.)
9. **S-TAX-1** — Seed all 10 provinces in `tax_rates` + log missing-province warnings. (0.5 session; AUDIT2026-16.)
10. **S-CONT-1** — Build contracts module (templates + generator + PDF). (2 sessions; AUDIT2026-10.)
11. **S-ACCT-1** — Build orphan accounting tables: recurring entries, FX revaluation, year-end checklist, document attachments. (3 sessions; clears last of Patt-1 orphans.)
12. **S-SPEC-1** — Reconcile error envelope drift between spec + code + JS. (1 session; AUDIT2026-13.)
13. **S-TEST-1** — Add PHPUnit + focused tests on billing math + state machines + soft-delete discipline. (2 sessions; addresses Patt-6.)

Total: **~22 sessions of high-leverage fix work** to close CRITICAL and MAJOR findings. MINOR/NIT items can be folded into broader cleanup sessions.

---

## What I Could Not Verify

To be honest about the limits of this audit:

- **I did not load-test** any endpoint. Pagination, FULLTEXT search behaviour, and the 26ms chat-poll claim from CHAT-1 are not re-measured here.
- **I did not connect to the live MySQL** (auth denied locally). Schema verification is against `FLEETFORGE_DATABASE_MASTER.sql`, not the actual DB. If migrations have been applied differently, some findings may be false positives.
- **I did not run the AI tooling** or verify Anthropic API keys work end-to-end. `lib/AI/*` classes are read statically.
- **Samsara live API** not exercised. `SamsaraClient` read for patterns only.
- **I did not audit every one of the 319 API endpoints** individually. I sampled ~50 across every module + spot-checked agent reports. A specific bug in an endpoint I didn't open would be missed.
- **I did not walk the UI in a browser.** Tile/drill-down claims from TILES-1/TILES-2 are taken as written in PROGRESS; I did not click through.
- **Portal-side security** sampled at ~10 files; I did not exhaustively audit every portal query for customer_id scoping, only spot-checked.
- **Accounting module** audited less deeply than the core billing path. Pass 3/4 evidence for accounting tables (e.g. FOR UPDATE on acc_bills during payment allocation) is based on the sub-agent's sample, not my direct read.
- **PROGRESS.md was 146k tokens and physically could not be read in full.** I read the top 50 sessions plus the decisions section. Later-session bug fixes (S055+, which I only saw in passing) may moot some findings above. Where PROGRESS contradicts this report, verify against disk — PROGRESS is not authoritative.
- **No static analysis tool run** (phpstan/psalm). Type-drift or null-safety bugs outside the specific patterns I searched for would be missed.

Treat this report as a strong starting point for a second-opinion Claude.ai review and a human code-review sweep, not as a certified bug list.

---

*End of audit. Zero source files modified. Only this file is new. Git status verified before report submission.*
