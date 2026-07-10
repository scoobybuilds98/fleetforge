# FleetForge — Master Project Specification
**Version:** 2.5 FINAL | **Owner:** Avi | **Business:** Mainland Truck & Trailer Sales
**Architect / Designer / Programmer:** Claude Sonnet 4.6
**Status:** LOCKED — Read this file at the start of every Claude Code session before writing a single line of code.
**Schema:** 59 core tables + 34 accounting tables + 1 utility table = 94 total. customer_documents and equipment_documents dropped (consolidated into documents table). gps_devices dropped. 28 schema corrections applied. FLEETFORGE_DATABASE_MASTER.sql is the authoritative schema source.

---

## CHANGELOG
- **v2.5 FINAL** — Comprehensive audit integration: 15 specialist audit passes applied. Infrastructure decisions locked (AWS S3 storage, SES email, Cloudflare DNS). Schema v1.2: 17 indexes added, portal_users security columns, notification_log dedup columns, payments soft-delete, granular tax exemption, remember_token, minimum_end_date, contract_number_snapshot on invoices, schema_migrations table. Invoice/equipment/lease state machines corrected. Pro-rating day-counting convention defined. Billing edge cases resolved. Security hardening (session strict mode, CSRF token separation, remember-me token, concurrent session limits). PHP architecture patterns locked (global exception handler, cron wrappers, PSR-4 autoloading, bcmath for monetary arithmetic). Performance: composite indexes for all high-frequency queries. Legal: CRA invoice requirements (supplier GST/HST number, sequential invoice numbering, sent invoice immutability). Business scenarios: customer credit checks, unit swap workflow, stale reservation handling, early return fees defined. All 15 SOFT_DELETE_TABLES updated (payments added). INFRA: StorageClient.php + Mailer.php required in Session 1 scope.
- **v2.4 FINAL** — Schema governance fix: all CREATE TABLE SQL blocks removed from spec. FLEETFORGE_DATABASE_MASTER.sql is now the sole schema source — spec carries table inventory and design decisions only, no SQL. Auth0 purged from all sections (tech stack, folder structure, .env, setup steps, lib/Auth/) — replaced with correct custom PHP auth documentation per D1. Spec reduced from 5,196 to 3,605 lines.
- **v2.3 FINAL** — Document table consolidation: `customer_documents` and `equipment_documents` dropped. All document storage unified through the `documents` table (entity_type polymorphic). Eliminates dual-storage ambiguity. SOFT_DELETE_TABLES corrected to definitive 14-table list via full schema scan (added equipment_templates, equipment_units, rate_cards — were missing). Core table count: 59. Total with accounting: 93. FLEETFORGE_DATABASE_MASTER.sql is now the single schema source of truth.
- **v2.2 FINAL** — Schema audit: 28 corrections applied. gps_devices table dropped (GPS fields stay on equipment_units). Billing math isolated into separate lib/Billing/ files (InvoiceGenerator is the only class that touches DB). SOFT_DELETE_TABLES constant corrected to 14 tables (credit_notes and reservations were missing). All missing FKs, indexes, and ENUM values added. Circular FK resolution via ALTER TABLE documented. Creation order locked. Auth confirmed as custom PHP (not Auth0).
- **v2.1 FINAL** — Complete merge: all 37 sections from v1.x restored (Module Specs, Charts, AI, API Conventions, Auth, Portal, Form Standards, Table Standards, Print/Export, Audit Log detail, Onboarding, Session Standards, Settings Config, Global Search, Backup, Testing Checklist, Data Retention, Timezone, Graceful Degradation, Maintenance Mode, Custom Errors, Report Scheduling, CSV Import, Asset Versioning, Webhook Security, Document Security, Permission UI, Auth Flows, Theme Persistence, QR Codes, and more)
- **v2.0 FINAL** — Complete rewrite incorporating all decisions: Auth0 confirmed, billing model fully designed (pro-rating, monthly invoices, mileage pre-charge/reconciliation, CAD/USD, tax, discounts, late fees, credits), GPS simplified to tracking URL + mileage only, exhaustive database schema for all 62 tables, folder structure finalized with webroot isolation, all UX/performance/security/accessibility standards locked
- **v1.5** — GPS simplified: removed live maps, geofences, webhooks, location history. Kept tracking URL + mileage API call only
- **v1.4** — Deployment guide, backup, multi-tenancy, testing checklist, data retention, timezone, graceful degradation, 25+ additional sections
- **v1.3** — Folder structure redesign: webroot isolation, cron/, lib/, webhooks/, api/v1/, database/seeds/
- **v1.2** — Loading/empty/error states, state machines, business logic formulas, edge cases, accessibility, performance, concurrency
- **v1.1** — Global UX interaction standards, clickable KPIs, drilldown behavior
- **v1.0** — Initial locked spec

---

## TABLE OF CONTENTS

1. Business Context
2. Technology Stack
3. Folder Structure
4. Design System
5. Global UX Standards
6. Coding Standards
7. Database — Complete Schema (59 core tables)
8. Module Specifications
9. Billing Engine — Complete Specification
10. GPS & Telematics Integration
11. Charts & Analytics Specification
12. Claude AI Integration
13. API Conventions
14. Security Standards
15. Performance Standards
16. Accessibility Standards
17. State Machines
18. Edge Cases
19. Deployment & Server Setup
20. Build Order

---

## 1. BUSINESS CONTEXT

**Company:** Mainland Truck & Trailer Sales
**Platform:** FleetForge — production-grade rental and leasing operations platform
**Industry:** Heavy equipment and trailer rental/leasing
**Location:** Surrey, BC, Canada
**Scale:** Multi-million dollar annual transaction volume
**Users:** Internal admin staff (dispatchers, managers, accountants, admins)
**Future:** SaaS product to license to other trucking/equipment companies

**Core operations:**
- Lease physical equipment (chassis, trailers, dry vans, reefers, flatbeds) to trucking customers
- Bill monthly with pro-rated partial periods at start and end of lease
- Pre-charge estimated mileage at lease start, reconcile at close
- Manage equipment across multiple yards
- Track compliance documents (CVI, registration, MVI, insurance) per unit
- Schedule reservations and pickups
- Monitor equipment via Samsara GPS (tracking link + mileage only)
- Invoice in CAD and USD depending on customer

**Non-negotiables:**
- Historical data is sacred — nothing hard-deletes, everything soft-deletes
- Lease rates locked at creation — never retroactively changed
- Equipment status reliable — a unit on lease cannot be double-leased (row-level locking)
- Every action auditable — who did what when
- Billing logic is exact — pro-rating formula is the law
- Capture more data than currently needed for future AI analysis

---

## 2. TECHNOLOGY STACK

| Layer | Technology | Reason |
|---|---|---|
| Backend | PHP 8.2 (`declare(strict_types=1)` everywhere) | Zero deployment friction — upload and run |
| Database | MySQL 8.0 | Proven, relational, FK enforcement on |
| Styling | Custom CSS design tokens (no Bootstrap, no Tailwind) | Full control, professional look |
| Reactivity | Alpine.js v3 (CDN) | Dropdowns, modals, tabs — no build step |
| Charts | ApexCharts v3 (CDN) | Best-in-class SaaS charts, download built in |
| Auth | Custom PHP auth (email/password/bcrypt/sessions) | Full control, no external dependency, invite flow built in. Auth0 rejected — see D1. |
| Config | Custom .env parser (no Composer required) | Simple, no dependencies |
| Icons | Heroicons (inline SVG) | Sharp, no font loading |
| Fonts | DM Sans (UI) + DM Mono (numbers/data) | Google Fonts CDN |
| PDF | mPDF | Invoice and contract PDF generation |
| AI | Anthropic API `claude-sonnet-4-20250514` | Added after platform has real data |
| GPS | Samsara API (tracking URL + mileage only) | Simple, no webhooks or live maps |

**CDN dependencies (loaded in footer.php):**
```
Alpine.js:    https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js
ApexCharts:   https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js
Google Fonts: DM Sans + DM Mono
```

**Auth system (Decision D1 — Custom PHP, NOT Auth0):**
- Email + password authentication with bcrypt (cost 12)
- Sessions: PHP native sessions, HttpOnly + SameSite=Lax + Secure cookies
- 8-hour inactivity timeout, 5-attempt lockout (15 min)
- Invite flow: token-based, 7-day expiry, single-use
- Password reset: token-based, 2-hour expiry -- [PASS-1:M8] standardized to 2 hours across all sections, single-use, stored hashed
- `auth0_sub` column kept nullable on users table for possible future use
- Customer portal uses separate session namespace (ff_portal_user)


### Infrastructure (finalized — infra.md) [INFRA]

| Layer | Technology | Details |
|---|---|---|
| Compute (Dev) | AWS Lightsail $20/mo | Ubuntu 22.04, 4GB RAM, 2 vCPU, 80GB SSD |
| Compute (Prod) | AWS Lightsail $40/mo | Upgrade before go-live |
| DNS | Cloudflare (free plan) | A record → Lightsail static IP |
| File Storage | Amazon S3 | Bucket per install: `fleetforge-{client-slug}`. All uploads, PDFs, QR codes, inspection photos → S3. Served via pre-signed URLs (not serve.php streaming). |
| Email | AWS SES (SMTP) | Used for: invoice delivery, password reset, invite flow, notifications, dunning, scheduled reports. From: noreply@yourdomain.com |
| DB Backups | 3 layers | L1: Lightsail snapshots (7 daily). L2: mysqldump to S3 every 6 hours (`cron/backup_db.php`). L3: S3 versioning on backup bucket. |
| SSL | Certbot (Let's Encrypt) | Auto-renews |

**Storage abstraction layer — built in Session 1 (Foundation):** [INFRA]
```
lib/Storage/StorageClient.php
  StorageClient::upload(file, path) → string (S3 key)
  StorageClient::url(key, expiry=3600) → signed URL
  StorageClient::delete(key) → bool

STORAGE_DRIVER=local  → files stored in storage/ (development)
STORAGE_DRIVER=s3     → files go to S3 (production)
Same interface, different implementation.
All code calls StorageClient only — NEVER move_uploaded_file() directly.
```

**Mailer — built in Session 1 (Foundation):** [INFRA]
```
lib/Notifications/Mailer.php — configured for AWS SES SMTP
```

**.env additions:** [INFRA]
```
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_REGION=us-west-2
AWS_S3_BUCKET=fleetforge-mainland
AWS_SES_SMTP_HOST=email-smtp.us-west-2.amazonaws.com
AWS_SES_SMTP_PORT=587
AWS_SES_SMTP_USER=
AWS_SES_SMTP_PASS=
STORAGE_DRIVER=s3
```

### Pending Business Decision — U19: Tax Rates [INFRA]
Question: If the BC PST rate changes from 7% to 8% mid-lease, charge the old rate (frozen on lease) or the new rate (CRA-compliant)?
Recommendation: Look up at invoice time for CRA compliance. Awaiting Avi's decision.

---

## 3. FOLDER STRUCTURE

**Core principle: Webroot isolation.** Apache DocumentRoot points ONLY to `public/`. Everything else is unreachable by HTTP.

**Lightsail:** Configure Apache virtual host: `DocumentRoot /var/www/fleetforge/public`

```
/var/www/fleetforge/                  ← project root (NOT webroot)
│
├── public/                           ← Apache DocumentRoot — ONLY this exposed
│   ├── index.php                     ← single entry point, routes to admin/portal/api/webhooks
│   ├── .htaccess                     ← security headers, CSP, all requests → index.php
│   └── assets/
│       ├── css/app.css               ← entire design system
│       ├── js/app.js                 ← theme, toast, modal, API client, charts, tables
│       ├── icons/favicon.svg
│       └── logo/logo.png
│
├── app/                              ← all PHP page files
│   ├── admin/                        ← internal staff dashboard
│   │   ├── dashboard/home.php
│   │   ├── customers/
│   │   │   ├── index.php
│   │   │   ├── create.php
│   │   │   ├── edit.php
│   │   │   └── view.php
│   │   ├── equipment/
│   │   │   ├── index.php
│   │   │   ├── templates/{create,edit,view}.php
│   │   │   └── units/{create,edit,view}.php
│   │   ├── leases/{index,create,edit,view}.php
│   │   ├── reservations/{index,create,edit,view}.php
│   │   ├── invoices/{index,create,edit,view,pdf}.php
│   │   ├── payments/{index,create,view}.php
│   │   ├── rates/index.php
│   │   ├── maintenance/{index,create,edit,view}.php
│   │   ├── inspections/{index,create,view}.php
│   │   ├── damage/{index,create,view}.php
│   │   ├── compliance/index.php
│   │   ├── mileage/index.php
│   │   ├── yard/index.php
│   │   ├── documents/index.php
│   │   ├── contracts/{index,generator}.php
│   │   ├── calendar/index.php
│   │   ├── reports/index.php
│   │   ├── analytics/index.php
│   │   ├── audit/index.php
│   │   ├── notifications/index.php
│   │   ├── vendors/{index,create,view}.php
│   │   ├── users/{index,create,edit}.php
│   │   └── settings/index.php
│   │
│   ├── portal/                       ← customer self-service (separate auth, separate layout)
│   │   ├── auth/{login,logout,reset}.php
│   │   ├── dashboard.php
│   │   ├── leases.php
│   │   ├── invoices.php
│   │   ├── documents.php
│   │   └── requests.php
│   │
│   ├── auth/                         ← custom PHP auth
│   │   ├── login.php
│   │   ├── logout.php
│   │   └── forgot_password.php
│   │
│   └── errors/
│       ├── 403.php
│       ├── 404.php
│       ├── 500.php
│       ├── 503.php
│       └── maintenance.php
│
├── api/                              ← JSON REST endpoints only
│   ├── bootstrap.php                 ← every API file includes this first
│   └── v1/
│       ├── customers/{index,show,update,delete,leases,documents,rate_history,stats,upload_document}.php
│       ├── equipment/
│       │   ├── templates/{index,show,create,update,delete}.php
│       │   └── units/{index,show,create,update,delete,available,lease_history,status_log,gps_location,upload_document,stats}.php
│       ├── leases/{index,show,create,update,delete,update_status,close,billing_periods}.php
│       ├── reservations/{index,show,create,update,delete,mark_out,reverse,units_by_customer}.php
│       ├── invoices/{index,show,create,update,generate_from_lease,generate_monthly,void,send}.php
│       ├── payments/{index,show,create,allocate,refund}.php
│       ├── billing/{calculate_period,preview_schedule,generate_all_monthly}.php
│       ├── credits/{index,create,apply}.php
│       ├── maintenance/{index,show,create,update}.php
│       ├── inspections/{show,create,upload_photo}.php
│       ├── damage/{index,create,update}.php
│       ├── compliance/fleet_status.php
│       ├── reports/{revenue,utilization,ar_aging,customer_profitability,fleet_roi}.php
│       ├── dashboard/{kpis,charts,activity_feed}.php
│       ├── gps/mileage.php           ← ONLY GPS endpoint
│       ├── documents/serve.php
│       ├── search/global.php
│       ├── notifications/{recent,mark_read}.php
│       ├── ai/{chat,lease_summary,customer_insights,fleet_health,forecast,anomaly_check}.php
│       └── health.php                ← GET, no auth — returns {status,db,disk,time} [PASS-15:I1]
│
├── webhooks/                         ← inbound webhooks (future integrations)
│
├── cron/                             ← scheduled jobs
│   ├── README.md                     ← exact crontab entries
│   ├── compliance_alerts.php         ← nightly 6AM
│   ├── invoice_generate_monthly.php  ← 1st of month 6AM — generates draft invoices
│   ├── invoice_overdue.php           ← nightly 6:15AM — flips invoices to overdue
│   ├── late_fee_apply.php            ← nightly 6:30AM — applies late fees where configured
│   ├── health_scores.php             ← nightly 2AM
│   ├── risk_scores.php               ← nightly 2:30AM
│   ├── ai_fleet_brief.php            ← nightly 5AM
│   ├── ai_anomaly_detection.php      ← nightly 3AM
│   ├── gps_mileage_sync.php          ← daily 7AM — calls Samsara for all active leases, updates equipment_units.mileage + mileage_logs [PASS-1:C4] spec added
│   ├── cache_cleanup.php             ← hourly
│   ├── archive_old_data.php          ← monthly 1st 4AM
│   ├── notification_digest.php       ← daily 8AM
│   ├── reconcile_counters.php       ← nightly 3AM — recalculates all denormalized counters, logs discrepancies [PASS-1:M5]
│   └── backup_db.php                ← every 6 hours — mysqldump to S3 [INFRA]
│
├── lib/                              ← reusable PHP service classes
│   ├── Billing/
│   │   ├── ProRateCalculator.php     ← pure math, no DB, fully testable
│   │   ├── InvoiceGenerator.php      ← builds invoices using calculator
│   │   ├── TaxCalculator.php         ← GST/PST/HST per customer province
│   │   ├── CurrencyConverter.php     ← CAD/USD using frozen exchange rates
│   │   ├── MileageCalculator.php     ← miles/km conversion and rate application
│   │   ├── DiscountEngine.php        ← applies discounts in correct order
│   │   ├── CreditEngine.php          ← applies credits and credit notes
│   │   └── LateFeeEngine.php         ← calculates and generates late fee invoices
│   ├── GPS/
│   │   └── SamsaraClient.php         ← getMileageForLease() only
│   ├── PDF/
│   │   ├── InvoicePDF.php
│   │   ├── ContractPDF.php
│   │   └── InspectionPDF.php
│   ├── AI/
│   │   ├── AnthropicClient.php
│   │   ├── PromptBuilder.php
│   │   └── ResponseCache.php
│   ├── Auth/
│   │   └── (no files — auth logic lives in includes/auth.php)
│   ├── Storage/                                             -- [INFRA] built in Session 1
│   │   └── StorageClient.php         ← upload/url/delete — local or S3 driver
│   ├── Notifications/
│   │   ├── Mailer.php                ← configured for AWS SES SMTP [INFRA]
│   │   ├── NotificationService.php
│   │   └── Templates/
│   ├── QR/
│   │   └── QRGenerator.php
│   ├── Reports/
│   │   └── ReportBuilder.php
│   └── Export/
│       ├── CSVExporter.php
│       └── PDFExporter.php
│
├── includes/                         ← shared page shell (procedural)
│   ├── db.php                        ← PDO singleton + typed query helpers
│   ├── functions.php                 ← pure global helpers
│   ├── auth.php                      ← session guard, require_auth(), require_permission(), can()
│   ├── header.php
│   ├── sidebar.php
│   ├── topbar.php
│   └── footer.php
│
├── config/
│   ├── app.php                       ← loads .env, defines FF_ constants
│   ├── permissions.php               ← role → module → permission matrix
│   └── navigation.php                ← sidebar nav — single source of truth
│
├── database/
│   ├── schema/                       ← one .sql file per table
│   ├── seeds/
│   │   ├── 001_user_roles.sql
│   │   ├── 002_permissions.sql
│   │   ├── 003_settings.sql
│   │   ├── 004_yards.sql
│   │   ├── 005_tax_rates.sql         ← BC GST+PST default
│   │   └── 006_sample_data.sql       ← optional demo data
│   └── migrations/README.md
│
├── storage/
│   ├── uploads/
│   │   ├── customers/{id}/
│   │   ├── equipment/{unit_id}/{cvi,registration,insurance}/
│   │   ├── leases/{lease_id}/
│   │   ├── inspections/{inspection_id}/
│   │   ├── damage/{claim_id}/
│   │   ├── contracts/{lease_id}/
│   │   └── branding/
│   ├── generated/{pdfs,exports,qrcodes}/
│   ├── maintenance.flag              ← exists = maintenance mode active
│   └── .htaccess                     ← blocks all PHP execution
│
├── logs/{app.log,cron.log,gps.log,ai.log}
├── cache/.gitkeep
├── vendor/                           ← Composer (mPDF only — no Auth0 SDK)
├── composer.json
├── .env                              ← NEVER commit
├── .env.example
├── .gitignore
├── FLEETFORGE_SPEC.md                ← THIS FILE
└── README.md
```

### Crontab entries
```bash
# FleetForge — install with: crontab -e
0 2 * * *   php /var/www/fleetforge/cron/health_scores.php >> /var/www/fleetforge/logs/cron.log 2>&1
30 2 * * *  php /var/www/fleetforge/cron/risk_scores.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 3 * * *   php /var/www/fleetforge/cron/ai_anomaly_detection.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 5 * * *   php /var/www/fleetforge/cron/ai_fleet_brief.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 6 * * *   php /var/www/fleetforge/cron/compliance_alerts.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 6 1 * *   php /var/www/fleetforge/cron/invoice_generate_monthly.php >> /var/www/fleetforge/logs/cron.log 2>&1
15 6 * * *  php /var/www/fleetforge/cron/invoice_overdue.php >> /var/www/fleetforge/logs/cron.log 2>&1
30 6 * * *  php /var/www/fleetforge/cron/late_fee_apply.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 7 * * *   php /var/www/fleetforge/cron/gps_mileage_sync.php >> /var/www/fleetforge/logs/gps.log 2>&1
0 8 * * *   php /var/www/fleetforge/cron/notification_digest.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 * * * *   php /var/www/fleetforge/cron/cache_cleanup.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 4 1 * *   php /var/www/fleetforge/cron/archive_old_data.php >> /var/www/fleetforge/logs/cron.log 2>&1
30 3 * * *  php /var/www/fleetforge/cron/reconcile_counters.php >> /var/www/fleetforge/logs/cron.log 2>&1
0 */6 * * * php /var/www/fleetforge/cron/backup_db.php >> /var/www/fleetforge/logs/cron.log 2>&1
```
---

## 4. DESIGN SYSTEM

### Theme
- Two themes: dark and light, toggled via button in topbar
- Persisted in `localStorage` AND `users.theme_preference` DB column (cross-device)
- Applied via `data-theme="dark|light"` on `<html>`
- Smooth 200ms transition on all color properties
- Flash prevention: inline `<script>` in `<head>` reads localStorage before render
- Sidebar: ALWAYS dark regardless of theme (industry standard)

### Typography
- UI font: DM Sans — all text, labels, navigation, buttons
- Data font: DM Mono — all numbers, contract numbers, unit numbers, amounts, dates
- Weights: 400 (body), 500 (medium), 600 (headings/bold) — never 700+
- Size scale: 11px (xs), 12px (sm), 13px (base), 14px (md), 16px (lg), 18px (xl), 20px (2xl)

### Color Tokens
All colors as CSS variables under `[data-theme="light"]` and `[data-theme="dark"]`.
Key: `--bg-page`, `--bg-card`, `--bg-muted`, `--border-color`, `--text-primary`, `--text-secondary`, `--text-muted`

Semantic (same both themes): Success `#16a34a` | Warning `#d97706` | Danger `#dc2626` | Accent `#3b82f6`
Chart palette: `#3b82f6, #10b981, #f59e0b, #8b5cf6, #ef4444, #06b6d4, #f97316, #84cc16`

### Components
KPI tiles, chart cards, data tables, modals, dropdowns, badges/pills, buttons (8 variants, 5 sizes), forms, toasts, search, tabs, section views, field grids, empty states, loading skeletons, pagination, billing breakdown blocks

---

## 5. GLOBAL UX STANDARDS

### Every Number Is a Link
Every number/count displayed anywhere is clickable and drills down to the full detail:
- KPI tiles → filtered module page
- Sidebar badges → module filtered to the alerted condition
- Profile page counts → scrolls to relevant tab
- Chart data points → filtered list via ApexCharts `dataPointSelection` event
- Inline text like "4 overdue" → invoices filtered to overdue

### Dashboard KPI Drilldowns
| KPI | Destination | Filter |
|---|---|---|
| Active Revenue | reports/index.php | current month |
| Fleet Utilization | equipment/index.php | status breakdown |
| Open Leases | leases/index.php | status=active,pending |
| Overdue Invoices | invoices/index.php | status=overdue |
| Compliance Alerts | compliance/index.php | expiring 30 days |
| Today's Pickups | reservations/index.php | pickup_date=today |

### URL-Persistent Filters
All filters reflected in URL query params. Back button restores state. Filtered views show a dismissible banner: "Showing: Overdue invoices only [× Clear filter]"

### Loading States
- Tables/lists: skeleton rows (5-8 rows matching column structure)
- Charts: skeleton rectangle matching chart height
- KPI tiles: skeleton bars
- Buttons triggering async: `.btn-loading` class (spinner, disabled)
- Page loads: 3px progress bar at top

### Empty States
Every empty table shows the `.empty-state` component with icon, primary message, secondary message, and action button. Never plain "No records found."

### Error States
- API fail: inline error alert with Retry button — never crash whole page
- 404: branded page, "Back to list" button
- 403: branded page, link to Dashboard
- Network offline: toast "No internet connection"
- Session expired: redirect to login, redirect back after

### Keyboard Shortcuts
| Shortcut | Action |
|---|---|
| ⌘K / Ctrl+K | Focus global search |
| Escape | Close modal/dropdown |
| ? | Keyboard shortcuts modal |
| ⌘N / Ctrl+N | New record (context-aware) |
| ⌘S / Ctrl+S | Save current form |
| G → D | Go to Dashboard |
| G → C | Go to Customers |
| G → L | Go to Leases |
| G → E | Go to Equipment |
| G → R | Go to Reservations |
| G → I | Go to Invoices |

### Table Standards
- Entire row clickable → view page
- Actions column: stops event propagation
- Bulk selection with bulk actions bar
- Default sorts: customers=created_at DESC, equipment=unit_number ASC, leases=created_at DESC, invoices=invoice_date DESC, reservations=pickup_date ASC
- Default per page: 25. Options: 10/25/50/100. Saved in localStorage per module
- Currency columns: right-aligned, DM Mono
- Status columns: centered, badge component

### Form Standards
- Required fields: red asterisk
- Optional: "(optional)" in muted text
- Client-side validation on submit before API call
- Server-side validation always runs independently
- Auto-population: customer → rates pre-fill; template → unit fields pre-fill
- Unsaved changes warning on navigate away
- File uploads: drag & drop supported everywhere
- Date inputs: native `<input type="date">`, no third-party picker

### Number & Currency Display
| Type | Format | Font |
|---|---|---|
| Currency CAD | $X,XXX.XX | DM Mono |
| Currency USD | US$X,XXX.XX | DM Mono |
| Percentage | XX.X% | DM Mono |
| Mileage (miles) | X,XXX mi | DM Mono |
| Mileage (km) | X,XXX km | DM Mono |
| Zero | $0.00 not blank | DM Mono |
| Null | — (em dash) | DM Mono |

### Date Range Presets (all reports/charts)
Today, Yesterday, This Week, Last Week, This Month, Last Month, Last 30/90 Days, This Quarter, Last Quarter, This Year, Last Year, All Time, Custom. Default: This Month.

### Print Standards
- Hide: sidebar, topbar, action buttons, filters, pagination, AI panel
- Show: all data expanded (no pagination)
- Company logo top-left
- Page numbers bottom-right
- Print timestamp bottom: "Printed: March 17, 2025 3:42 PM"
- Charts render as SVG via ApexCharts exportToSVG

### Accessibility
- All inputs: associated `<label>`
- Icon-only buttons: `title` and `aria-label`
- Status: never color alone — always text + color
- Focus: visible indicator always present
- Modal: focus trapping, returns to trigger on close
- Toasts: `role="status"` `aria-live="polite"`
- Tables: `<th scope="col">`
- Skip navigation link at top (visually hidden, visible on focus)
- Contrast: min 4.5:1 normal text, 3:1 large text

---

## 6. CODING STANDARDS

### PHP
```php
<?php
declare(strict_types=1);
// Every single PHP file starts with this
```

- All DB via helpers: `db_select()`, `db_row()`, `db_insert()`, `db_execute()`, `db_transaction()`
- NEVER raw `$pdo->query()` in modules or API files
- All output: `e()` helper
- API files: `require_once dirname(__DIR__, N) . '/api/bootstrap.php';`
- Page files: `require_once dirname(__DIR__, N) . '/includes/header.php';`
- Monetary: always `DECIMAL(12,2)` — NEVER float
- Dates in DB: `Y-m-d` format always
- Datetimes in DB: UTC always
- Input sanitization: `clean_string()`, `clean_decimal()`, `clean_date()`, `clean_int()`

### JavaScript
- All API calls via `API.get()`, `API.post()` — never raw `fetch()`
- User messages: `Toast.success/error/warning/info()` — never `alert()`
- Confirms: `Modal.confirm()` — never `confirm()`
- Numbers: `FF.currency()`, `FF.compact()`
- Chart instances: always `FF_Charts.destroy(id)` before re-creating

### Identifier Auto-Generation
| Field | Format | Example |
|---|---|---|
| contract_number | CN-XXXXXX-YYYY | CN-A3F9K2-2025 |
| invoice_number | INV-YYYY-NNNNN | INV-2025-00847 |
| payment_number | PAY-YYYY-NNNNN | PAY-2025-00124 |
| work_order_number | WO-YYYY-NNNNN | WO-2025-00091 |
| claim_number | DC-YYYY-NNNNN | DC-2025-00012 |
| credit_note_number | CN-CR-YYYY-NNNNN | CN-CR-2025-00003 |

Charset for random IDs: `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no confusable chars: 0,O,1,I)

### Autoloading [PASS-8:6]
All lib/ classes use PSR-4 autoloading via Composer. In `composer.json`:
```json
{"autoload": {"psr-4": {"FleetForge\\": "lib/"}, "files": ["includes/db.php","includes/functions.php"]}}
```
All lib/ classes use namespaces matching their directory path. `config/app.php` requires `vendor/autoload.php`.

### Monetary Arithmetic in PHP [PASS-10:6]
```
- Use bcmath functions (bcadd, bcsub, bcmul, bcdiv) for all monetary calculations
- Intermediate calculations: scale=6 (6 decimal places)
- Final rounding to 2 decimal places only at the last step
- NEVER use float operators (+, -, *, /) on monetary values
- Pass values as strings between functions: '1234.56' not 1234.56
- ProRateCalculator accepts and returns string-typed decimals
```

### Asset Versioning
`FF_ASSET_VERSION` constant in `config/app.php`. Append `?v=X.X.X` to all CSS/JS URLs. Increment on every deploy that changes assets.

### Soft Delete Enforcement
Tables with soft deletes (15 — verified by full schema scan, payments added per PASS-13:F2):
`users`, `customers`, `customer_notes`, `equipment_templates`, `equipment_units`, `leases`, `damage_claims`, `invoices`, `maintenance_work_orders`, `documents`, `vendors`, `credit_notes`, `reservations`, `rate_cards`, `payments`

Every query on these tables MUST include `AND {table}.deleted_at IS NULL`. No exceptions.

---
---

## 7. DATABASE — COMPLETE SCHEMA

> **AUTHORITATIVE SOURCE: `FLEETFORGE_DATABASE_MASTER.sql`**
>
> The complete, correct, verified schema for all 93 tables lives in one place:
> `FLEETFORGE_DATABASE_MASTER.sql` (Version 1.1 FINAL).
>
> **Do not use this section as a schema reference.** Use the master SQL file.
> This section documents the table inventory, design decisions, and relationships.
> The SQL blocks that previously lived here have been removed to eliminate drift.

---

### 7.0 SCHEMA GOVERNANCE RULES

```
1. FLEETFORGE_DATABASE_MASTER.sql is the single source of truth.
   If this spec and the master SQL disagree, the master SQL wins.

2. Never add a column or table to application code without first
   adding it to FLEETFORGE_DATABASE_MASTER.sql.

3. Never modify a column definition in this spec document.
   All schema changes go through the master SQL file only.

4. Session 2 runs the master SQL. Not this spec. The master SQL.
```

---

### 7.1 TABLE INVENTORY — 93 TABLES

**Core tables: 59**

| Group | Tables |
|-------|--------|
| Users & Access | user_roles, users, user_permissions |
| System | audit_log, audit_log_archive |
| Customers | customers, customer_tags, customer_contacts, customer_notes, customer_equipment_rates, customer_rate_history, customer_discounts |
| Equipment | equipment_templates, equipment_units, equipment_status_log |
| Rates | rate_cards, rate_card_items |
| Yards | yards, yard_transfers |
| Vendors | vendors |
| Leases | leases, lease_status_log, lease_amendments, lease_billing_periods |
| Reservations | reservations, reservation_units |
| Billing | tax_rates, exchange_rates, late_fee_rules, invoices, invoice_line_items, payments, payment_allocations, credit_notes, credit_note_applications |
| Fleet Operations | maintenance_work_orders, maintenance_line_items, inspections, inspection_sections, inspection_photos, damage_claims, damage_claim_photos, mileage_logs |
| Documents & Contracts | documents, contract_templates, generated_contracts |
| Reports | report_cache, scheduled_reports, saved_reports |
| Notifications | notification_rules, notifications, notification_log |
| AI | ai_summaries, ai_chat_sessions, ai_chat_messages, ai_query_log |
| Config | settings |
| Portal | portal_users, portal_service_requests |

**Accounting tables: 34 (acc_ prefix — see FLEETFORGE_ACCOUNTING_SPEC.md)**

| Group | Tables |
|-------|--------|
| GL | acc_periods, acc_accounts, acc_journal_entries, acc_journal_entry_lines, acc_recurring_entries, acc_recurring_entry_lines |
| AP | acc_bills, acc_bill_lines, acc_ap_payments, acc_ap_payment_allocations, acc_vendor_credits, acc_vendor_credit_applications |
| Bank | acc_bank_accounts, acc_bank_reconciliations, acc_bank_transactions |
| Assets | acc_fixed_assets, acc_depreciation_runs, acc_depreciation_run_lines, acc_asset_disposals, acc_asset_impairments |
| Tax | acc_tax_filing_periods, acc_tax_remittances |
| AR | acc_collection_notes, acc_promise_to_pay, acc_dunning_letters, acc_bad_debt_writeoffs, acc_customer_deposits |
| Budget | acc_budgets, acc_budget_lines |
| FX | acc_fx_revaluations |
| Misc | acc_documents, acc_year_end_checklist, acc_report_configurations, acc_qbo_sync_log |

---

### 7.2 DROPPED TABLES (documented for history)

| Table | Reason |
|-------|--------|
| `customer_documents` | Consolidated into `documents` table (entity_type='customer'). Eliminates dual-storage. |
| `equipment_documents` | Consolidated into `documents` table (entity_type='equipment_unit'). |
| `gps_devices` | GPS simplified to URL + mileage only. Fields on `equipment_units` directly. |

---

### 7.3 SOFT-DELETE TABLES (14)

Every query on these tables **MUST** include `AND {table}.deleted_at IS NULL`:

```php
const SOFT_DELETE_TABLES = [
    'users', 'customers', 'customer_notes',
    'equipment_templates', 'equipment_units', 'leases',
    'damage_claims', 'invoices', 'maintenance_work_orders',
    'documents', 'vendors', 'credit_notes',
    'reservations', 'rate_cards', 'payments', // [PASS-13:F2]
];
```

Verified by full schema scan. All 14 tables have `deleted_at DATETIME NULL` columns in the master SQL.

---

### 7.4 DEFERRED FOREIGN KEYS (3)

Three FKs cannot be created at table-creation time due to circular dependencies.
They are added via ALTER TABLE in `database/schema/900_alter_deferred_fks.sql`:

| ALTER | Reason |
|-------|--------|
| `invoices.billing_period_id` → `lease_billing_periods` | Circular: each table references the other |
| `leases.last_billed_invoice_id` → `invoices` | Circular: created before invoices exists |
| `acc_bill_lines.asset_id` → `acc_fixed_assets` | `acc_fixed_assets` created after `acc_bill_lines` |

---

### 7.5 KEY DESIGN DECISIONS

**All monetary values:** `DECIMAL(12,2)` — never FLOAT. Accounting tables use `DECIMAL(15,2)`.

**All datetimes:** stored UTC. Displayed in company timezone via `format_datetime()`.

**All status columns:** ENUM — never VARCHAR. Guarantees only valid values exist.

**All IDs:** `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`.

**Snapshots on leases:** `customer_name_snapshot`, `company_name_snapshot`, `unit_number_snapshot` etc. are frozen at lease creation. If the customer or unit is later deleted or renamed, lease history remains accurate.

**Denormalized counters:** `customers.outstanding_balance`, `customers.lease_count`, `equipment_units.total_revenue` etc. are cached for dashboard read performance. **They MUST be updated in the same transaction as the triggering event.** If an invoice is posted and the customer balance isn't updated in the same transaction, the UI falls out of sync.

**Documents unified:** All document storage goes through the `documents` table using `entity_type` + `entity_id`. Never create a new entity-specific document table.

**Equipment GPS fields:** `gps_device_id`, `samsara_vehicle_url`, `tracking_provider` live directly on `equipment_units`. The `cvi_document`, `registration_document`, `insurance_document` columns are cached file paths for compliance grid fast reads — kept in sync with the `documents` table on every upload.

---

### 7.6 SCHEMA CREATION ORDER

Run `FLEETFORGE_DATABASE_MASTER.sql` top-to-bottom. It is organized in dependency order:

```
Group 1: user_roles, users, user_permissions, audit_log, tax_rates, exchange_rates
Group 2: customers, customer_*, equipment_templates
Group 3: equipment_units, equipment_documents (dropped), yards, rate_cards, vendors
Group 4: leases, lease_*, reservations, customer_rates, maintenance_*, inspections,
         equipment_status_log, yard_transfers, late_fee_rules
Group 5: invoices, invoice_line_items, lease_billing_periods, payments, credit_notes
Group 6: ALTER TABLE — 2 deferred core FKs
Group 7: damage_claims, mileage_logs
Group 8: documents, contracts, reports, notifications, AI, settings, portal
Group 9: acc_periods through acc_qbo_sync_log (accounting tables)
         ALTER TABLE — acc_bill_lines.asset_id deferred FK
```

---

## 9. BILLING ENGINE — COMPLETE SPECIFICATION

### Overview
The billing engine is the most critical component in FleetForge. All billing logic lives in `lib/Billing/`. None of it touches the database directly — it receives data, computes results, and returns arrays. `InvoiceGenerator` orchestrates everything and handles DB writes.

### The Pro-Rating Formula (THE LAW)

> **SUPERSEDED (period-independent law).** This §9 formula described the deleted
> `ProRateCalculator` engine. Since S-BILLING-HOLISTIC-ENGINE every lease bills
> via `lib/Billing/HolisticLeaseEngine.php` (R2 running-reconciliation:
> cumulative-correct − already-billed), with these amendments to the sub-month
> ladder:
> - **≤7 days bills the cheaper of `days × daily` vs `weekly` (D-R2-2,
>   S-AUDIT-BILLING-ENGINE-1 2026-07-10).** A zero/absent rate means that tier
>   is "not offered" and the other tier applies. The fixed 1–5-daily /
>   6–7-weekly bands below are RETIRED.
> - 8+ days: weekly math (`full_weeks × weekly + rem × weekly/7`) capped at
>   monthly when `weekly_math > monthly` (rate-driven crossover — never a fixed
>   day count).
> - ≤1 calendar month spans bill FLAT monthly (D-R2-1 + S-MONTHLY-SHORT-FLAT).
> The block below is retained for historical context only — do not implement
> against it.

```
function calculate_period_charge(days, daily, weekly, monthly):

  IF days <= 0:   return 0, method='none'
  IF days <= 5:   return days × daily, method='daily'
  IF days <= 7:   return weekly, method='weekly'

  IF days >= 8:
    full_weeks    = floor(days / 7)
    remaining     = days mod 7
    weekly_math   = (full_weeks × weekly) + (remaining × (weekly / 7))

    IF days >= 30:
      full_months   = floor(days / 30)
      remaining     = days mod 30
      monthly_math  = (full_months × monthly) + (remaining × (monthly / 30))
      return monthly_math, method='monthly'

    ELSE (8–29 days):
      IF weekly_math > monthly:
        return monthly, method='weekly_capped'
      ELSE:
        return weekly_math, method='weekly'
```

### Monthly Invoicing Model
- Leases billed month by month
- Full months (1st → last day): monthly rate flat — no pro-rating
- Partial months (lease start/end mid-month): pro-rating formula
- Auto-draft invoices generated 1st of each month by cron (with MySQL advisory lock to prevent duplicate runs [PASS-15:C1])
- Drafts sit until manually reviewed and sent
- **Invoice send vs email delivery are separate steps** [PASS-15:E3]: marking as 'sent' always succeeds (DB write). Email delivery may fail — invoice stays 'sent' with a yellow warning and Resend button.
- Final reconciliation invoice at lease close

### Invoice Generation Flow (Model B — current, S-MILEAGE-1 + S-MILEAGE-2A + S-MILEAGE-2B SHIPPED 2026-05-12)

Model B mileage billing has three lifecycle phases — all SHIPPED. Phase 1 (precharge emit on Invoice 1; S-MILEAGE-2A) + Phase 2 (drawdown emit on Invoice 2..N; S-MILEAGE-2B) + Phase 3 (close-refund picker + state machine; S-MILEAGE-3) are documented below.

```
INVOICE 1 — generated on lease creation day (partial_start or single_period)
  Line items:
    1. Base rental — pro_rate(days from start to end of month)
    2. Mileage precharge — flat operator-set lease.precharge_amount  [ONLY on Invoice 1, gated]
       Emit gate (D138, S-MILEAGE-2A):
         lease.precharge_enabled = 1
         AND lease.precharge_invoiced_at IS NULL
         AND NOT EXISTS (prior non-void invoice on this lease w/ mileage_precharge line)
         AND billing_type NOT IN ('mileage_only','adjustment','credit_note')
       Stamps lease.precharge_invoiced_at = NOW() on Invoice 1 SEND (D140).
       Future edits to precharge_enabled / precharge_amount on the lease return
       409 PRECHARGE_LOCKED (D113) once the stamp lands.

INVOICES 2..N — auto-draft on 1st of each subsequent month while lease active
  Line items:
    1. Base rental — monthly_rate flat (full_month)
    2. Mileage usage — period_distance × mileage_rate_km (Model B drawdown emit, D-B)
       Emit gate (S-MILEAGE-2B D-B):
         period_distance_km > 0
         AND lease.mileage_rate_km > 0
         AND billing_type NOT IN ('mileage_only','adjustment','credit_note')
         AND (lease.precharge_invoiced_at IS NOT NULL OR lease.precharge_enabled = 0)
    3. Mileage drawdown credit — IF lease.precharge_balance > 0 at emit time
       amount = min(period_charge, precharge_balance)  (POSITIVE bcmath; is_credit=1)
       UPDATES lease.precharge_balance -= drawdown_amount inside same transaction
         (FOR UPDATE on lease row per D20; audit_log entity_type=
          'lease_precharge_balance_drawdown' captures pre/post/delta).
       Model B Lite (precharge_enabled=0): line 2 emits at per-km rate;
         no line 3 (no balance to draw down) — D135 three-config matrix.

  Distance source: caller-supplied (api/v1/invoices/create.php) takes
  precedence; otherwise SamsaraClient::getDistanceForPeriod fallback fetch
  fires when the lease's equipment_unit carries a samsara_vehicle_id
  (D-C, S-MILEAGE-2B C3). Distance is always manually editable in the
  invoice form ("Samsara is source-of-truth-by-default, never source-of-
  truth-by-force").

FINAL INVOICE — generated when lease is closed (S-MILEAGE-3 SHIPPED 2026-05-13)
  Model B close lifecycle. Final invoice generation handles the
  remaining billable period via the normal Invoice 2..N drawdown emit
  flow (mileage_usage + optional mileage_drawdown_credit). If a
  precharge_balance residual survives the final drawdown, the close
  transaction dispatches a refund per the operator-selected method
  (cash | credit) — see "Lease close + refund" section below for the
  full state machine. The priorExcessKm transitional safeguard from
  S-MILEAGE-FIX-0 (D98) + the Model C lease_close_adjustments table
  retired in S-MILEAGE-3 C5; close.php no longer carries those code
  paths.
```

### Lease close + refund (S-MILEAGE-3 SHIPPED 2026-05-13)

At lease close, when `lease.precharge_enabled=1 AND lease.precharge_balance > 0` (residual after the final drawdown emit), the close transaction MUST dispatch a refund. The operator selects the refund method via the close modal's "Precharge Refund" section:

- **Apply as Credit** *(default)* → `credit_notes` row created with `source='precharge_refund'`, `amount=precharge_balance`, customer_id from the lease, status='active'. `lease.precharge_refund_method='credit'` + `precharge_refund_settled_at=NOW()` stamped inside the close transaction (credit issuance IS settlement per D-E (ii)). `AutoEntryBridge::onCreditNoteIssued` fires via the existing S-FIX-2 pattern.
- **Cash Refund** → `lease.precharge_refund_method='cash'` stamped at close-commit with `precharge_refund_settled_at=NULL` (intent recorded, money not yet moved). The operator confirms physical disbursement (cheque issued, EFT sent) post-close via the "Mark Refund Settled" button on the lease show page; the `api/v1/leases/mark_refund_settled.php` endpoint stamps `precharge_refund_settled_at=NOW()`. No payments-table row written — operator records the cash dispatch externally; audit_log is the CRA-defensible paper trail (D-B (i) "manual deferred-settle" lock).

**State machine** (`lease.precharge_refund_method`):
- NULL at lease activation (first-emitter status; pre-S-MILEAGE-3 0 rows).
- Set to `'cash'` or `'credit'` at lease close, ONLY when `precharge_balance > 0`. If `precharge_balance == 0` at close (drawdown fully consumed), the column stays NULL (no refund needed — the picker UI conditional render skips entirely).
- **Immutable after set:** 409 `PRECHARGE_REFUND_LOCKED` returned from close endpoint on any subsequent attempt to change the value. Mirrors D113 `PRECHARGE_LOCKED` + D140 `PRECHARGE_ALREADY_BILLED` naming family.

**Validation gates:**
- 422 `PRECHARGE_REFUND_REQUIRED` — close request reaches the refund-needed gate without a `precharge_refund` block in the payload.
- 422 `PRECHARGE_REFUND_METHOD_MISMATCH` — `mark_refund_settled` called on a lease with `method != 'cash'` (only cash branch supports deferred-settle; credit branch stamps at close-commit).
- 409 `PRECHARGE_REFUND_ALREADY_SETTLED` — `mark_refund_settled` called when `settled_at IS NOT NULL` (idempotent).
- 422 `INVALID_LEASE_STATUS` — `mark_refund_settled` called on a lease that isn't `status='completed'`.

**audit_log entries** (D-L, action='update' per D102 ENUM workaround):
- `lease_precharge_refund_issued` — close-commit. new_values JSON captures method + amount + precharge_balance_at_close + related_credit_note_id (credit branch only) + notes + closed_by_user_id.
- `lease_precharge_refund_settled` — cash-branch settle stamp. new_values JSON captures method + settled_at + settled_by_user_id.

**D135 three-config matrix preserved across close:**
- **Model B (full)** — `precharge_enabled=1`, drawdown lifecycle through close, residual balance triggers refund picker.
- **Model B Lite** — `precharge_enabled=0 AND mileage_rate_km>0`, no precharge concept, refund picker doesn't render (silent skip).
- **Disabled** — `mileage_rate_km=0 AND precharge_enabled=0`, no mileage billing at all, no refund concept.

**Smoke invariant I9** (D-N): closed leases with residual balance must have non-NULL `precharge_refund_method` AND (for credit branch) non-NULL `precharge_refund_settled_at`. Cash-branch NULL settled_at is NOT a violation by design.

**Carry-forward:** `precharge_balance` post-refund retains the historical at-close value (NOT zeroed) — preserves audit trail of refund-vs-drawdown distinction; I9 invariant predicate keys on this. Portal display of refunded leases shipped via S-PORTAL-MILEAGE-MODEL-B 2026-05-13.

**Accounting JE pattern** — deferred to `S-MILEAGE-3-ACCT-SPEC` follow-up session pending CPA conversation. See FLEETFORGE_ACCOUNTING_SPEC.md (currently unchanged for this lifecycle phase) + S-MILEAGE-3 spec D-I in FLEETFORGE_CURRENT_SESSIONS.md SESSION LOG for the 5 enumerated CPA questions.

### Invoice Calculation Order (ALWAYS this sequence)
```
1. Base rental (ProRateCalculator)
2. Add mileage_precharge line on Invoice 1 (D138; gated per Model B emit)
3. Add mileage_usage + optional mileage_drawdown_credit lines on Invoice 2..N
   (D-B; Model B drawdown emit per S-MILEAGE-2B C3)
4. Add insurance/warranty/GPS if applicable
5. Add damage charges if applicable
6. subtotal = SUM(line_items.amount) RESPECTING is_credit (bcsub on credit lines)
7. Apply discount (% or flat) → discount_amount
8. subtotal_after_discount = subtotal - discount_amount
9. Apply tax (GST/PST/HST per lease tax rates — skip GST if gst_exempt, skip PST if pst_exempt) [PASS-13:T2]
   Tax computes on the NET subtotal (signed sum already netted credit lines).
   Per-line tax negates internally for is_credit=1 lines so tax_*_amount
   on the line items reflects the credit (D-D, S-MILEAGE-2B C3).
10. total_amount = subtotal_after_discount + tax_total
11. Apply account credits/credit notes if any
12. balance_due = total_amount - credits_applied - amount_paid
```

### Mileage Billing — Model B (current, post-S-MILEAGE-2B)
```
At lease CREATE:
  Operator sets:
    precharge_enabled (0/1, default 0)
    precharge_amount  (DECIMAL(12,2), required > 0 if precharge_enabled=1)

At lease ACTIVATION (status: pending → active, S-MILEAGE-2A D137):
  IF precharge_enabled = 1 AND precharge_balance IS NULL:
    UPDATE leases SET precharge_balance = precharge_amount
    (idempotent guard; FOR UPDATE on lease row per D20;
     audit_log entity_type='lease_precharge_balance_init')

At INVOICE 1 generation (S-MILEAGE-2A D138):
  Emit mileage_precharge line at lease.precharge_amount (flat operator-set)
  IF this is the first non-void mileage_precharge emission for the lease.
  Tax computes on the precharge amount per province + exemptions.

At INVOICE 1 SEND (S-MILEAGE-2A D140):
  UPDATE leases SET precharge_invoiced_at = NOW()
  (Idempotency via state machine + 409 PRECHARGE_ALREADY_BILLED if a
   different invoice tries to send a duplicate mileage_precharge line.)
  D113 PRECHARGE_LOCKED 409 activates for free on lease update post-stamp.

At INVOICE 2..N generation (S-MILEAGE-2B D-B):
  period_charge    = period_distance_km × mileage_rate_km  (bcmath)
  drawdown_amount  = min(period_charge, lease.precharge_balance)
  remaining_charge = period_charge - drawdown_amount  (informational)

  IF lease.precharge_balance > 0:
    Emit `mileage_usage` line (amount = period_charge, is_credit = 0)
    Emit `mileage_drawdown_credit` line
      (amount = drawdown_amount, POSITIVE bcmath; is_credit = 1
       → InvoiceGenerator aggregator subtracts at line 357-362).
    UPDATE lease.precharge_balance -= drawdown_amount
      (audit_log entity_type='lease_precharge_balance_drawdown').

  IF lease.precharge_balance == 0 (or NULL / Model B Lite):
    Emit `mileage_usage` line only (no drawdown credit; no balance update).

At lease CLOSE (S-MILEAGE-3 SHIPPED 2026-05-13, D-A through D-N):
  Final invoice generation per the same drawdown emit logic above.
  IF lease.precharge_enabled=1 AND lease.precharge_balance > 0 post-drawdown:
    Required: precharge_refund {method: 'cash'|'credit', notes?: str}
              in close request body (422 PRECHARGE_REFUND_REQUIRED).
    Credit branch:
      INSERT credit_notes row source='precharge_refund',
        amount=precharge_balance, status='active'.
      UPDATE lease.precharge_refund_method='credit',
             precharge_refund_settled_at=NOW().
      AutoEntryBridge::onCreditNoteIssued (existing S-FIX-2 pattern).
    Cash branch (D-B (i) manual deferred-settle):
      UPDATE lease.precharge_refund_method='cash',
             precharge_refund_settled_at=NULL  (intent only).
      Operator stamps settled_at later via
      api/v1/leases/mark_refund_settled.php when physical
      disbursement happens (cheque issued / EFT sent).
    audit_log entity_type='lease_precharge_refund_issued' at close;
      entity_type='lease_precharge_refund_settled' at cash settle.
    precharge_balance NOT zeroed — preserves historical at-close
      value for audit trail (refund-vs-drawdown distinction); I9
      smoke invariant predicate keys on the audit_log row amount.
  IF lease.precharge_balance == 0 OR precharge_enabled = 0:
    Refund picker doesn't render (silent skip); no refund step.
  See "Lease close + refund" section above for full spec.

Model B Lite (D135 three-config matrix, S-MILEAGE-ALLOWANCE-ZERO-FIX):
  Lease config: precharge_enabled=0 AND mileage_rate_km>0 AND
  estimated_mileage_km=0. Bills every km from km 0 at per-km rate.
  No precharge concept; no drawdown credit emitted. Same engine surface
  as Model B (full) — InvoiceGenerator doesn't branch on Model identity;
  the precharge_balance value determines whether a credit line emits.
```

### Mileage line item types (invoice_line_items.item_type ENUM)
```
Active (S-MILEAGE-2B SHIPPED):
  mileage_precharge       — Invoice 1 upfront commit (D139)
  mileage_usage           — per-km usage charge (Invoice 2..N, D-B)
  mileage_drawdown_credit — precharge balance applied (Invoice 2..N, D-B;
                            POSITIVE amount + is_credit=1 per K-16 convention)

Historical (closed categories — not emitted post-S-MILEAGE-2B / S-MILEAGE-3):
  mileage_adjustment      — Model C per-period excess approve flow
                            (api/v1/invoices/review_mileage.php retired in
                            S-MILEAGE-2B C5; legacy rows on INV-91 + INV-92
                            preserved for audit trail)
  mileage_credit          — Model C close-time underage refund line
                            (lease_close_adjustments table DROPPED in
                            S-MILEAGE-3 C5 migration 202605121925;
                            DELETED CATEGORY — zero production rows ever
                            emitted, table retired clean per K-15 scan)
```

### Rate Locking
Rates stored on lease at creation from customer_equipment_rates (or rate_cards, or manual). Once lease is created:
- Rates on the lease record are immutable
- Any system-wide rate changes do NOT affect this lease
- Rate changes require a lease_amendment record
- `ProRateCalculator` always uses the rates stored on the lease

### Discount Application
Check in this priority order:
1. `customer_discounts` — per-lease or per-equipment-type, time-limited
2. `customers.discount_type` / `customers.discount_value` — customer-level default
3. Manual override on invoice creation
Applied to subtotal BEFORE tax.

### Tax Rules
- Check `lease.gst_exempt` — if true, set gst_amount = 0.00, hst_amount = 0.00 [PASS-13:T2]
- Check `lease.pst_exempt` — if true, set pst_amount = 0.00 [PASS-13:T2]
- Both flags are independent — a customer can be GST-exempt but PST-liable (e.g. First Nations), or PST-exempt but GST-liable (e.g. provincial government entity in BC)
- Legacy `lease.tax_exempt` boolean remains for backward compatibility: if true, treat as both gst_exempt AND pst_exempt
- If not exempt: apply rates from `tax_rates` table for customer's province
- BC default: GST 5% + PST 7%
- Ontario: HST 13%
- Alberta: GST 5% only
- US customers: typically 0% (confirm per customer)
- `gst_exempt_expiry` / `pst_exempt_expiry` on customer — if expired, treat as NOT exempt for that tax type and flag for review [PASS-13:T2]
- Tax jurisdiction is determined at the LEASE level, defaulting to customer's province but overridable for cross-provincial equipment use [PASS-13:T1]

### Currency
- Invoice currency = customer's currency (CAD or USD)
- `exchange_rate_to_cad` frozen at invoice creation from `exchange_rates` table
- All internal reporting in CAD (convert USD invoices using frozen rate)
- Display in customer's currency in UI and on PDF

### Cross-Currency Payment Allocation [PASS-1:H3]
Payment currency MUST match invoice currency. A USD payment can only be allocated to USD invoices; a CAD payment can only be allocated to CAD invoices. If currencies differ, the API returns 422 `CURRENCY_MISMATCH`: "Payment currency (USD) does not match invoice currency (CAD). Record a currency-matched payment or use the FX conversion workflow in the accounting module." This prevents hidden FX gain/loss in the billing layer — all FX handling is explicit in the accounting module.


### Day-Counting Convention [PASS-3:1A]
```
Days in a billing period = (end_date - start_date) + 1 (inclusive of both start and end dates).
A lease that starts and ends on March 10 is 1 billable day.
The pro-rating formula receives days = 1, returns 1 × daily_rate, method='daily'.
```

### Full-Month Invoice Rule [PASS-3:2D]
```
Auto-generated monthly invoices (cron, 1st of each month) ALWAYS use the flat monthly rate 
regardless of calendar month length. A full February invoice = monthly_rate.
Only the first and final invoices (partial periods) use the pro-rating formula.
billing_type distinguishes: full_month = flat rate, partial_start/partial_end/single_period = formula.

If lease start_date is the 1st of the month, Invoice 1 is billing_type = full_month at flat monthly_rate.
```

### Invoice 1 Timing [PASS-3:1F]
```
Invoice 1 is generated when the lease status transitions to 'active', NOT when the lease record is 
created. A lease created with a future start_date stays in 'pending' with no invoice until activated.
```

### Final Invoice — Full Month Close Rule [PASS-3:2C]
```
When a lease closes on the last day of the month AND the auto-draft for that month exists:
do NOT void and regenerate. Instead, add mileage reconciliation line items to the existing 
draft invoice. Only void+regenerate when the return date is before the last day (true partial period).
```

### The PHP Classes

**Architecture rule (v2.2):** All billing math lives in pure functions — no DB access, no side effects, fully unit-testable. `InvoiceGenerator` is the ONLY class that reads/writes the database. Modify a billing formula by editing one file only.

**`lib/Billing/ProRateCalculator.php`** — Pure math. No DB. All monetary params/returns are strings for bcmath precision [PASS-10:6].
```php
class ProRateCalculator {
    public function calculate(int $days, string $daily, string $weekly, string $monthly): array
    // Returns: ['amount' => string, 'method' => string, 'explanation' => string[]]
    // Pure function — no DB, no dependencies, fully unit testable

    public function calculateMileage(string $actual, string $estimated, string $rate, string $unit): array
    // Returns: ['amount' => string, 'adjustment' => string, 'is_credit' => bool,
    //           'item_type' => string, 'explanation' => string[]]
}
```

**`lib/Billing/TaxCalculator.php`** — Pure math. One DB read allowed (fetch tax rate).
```php
class TaxCalculator {
    public function calculate(string $subtotal, int $customerId, bool $gstExempt, bool $pstExempt): array
    // $subtotal as string for bcmath precision [PASS-10:6]
    // $gstExempt / $pstExempt from lease snapshot [PASS-13:T2]
    // Returns: ['gst' => string, 'pst' => string, 'hst' => string, 'total' => string,
    //           'gst_exempt' => bool, 'pst_exempt' => bool]
    // If gstExempt: gst = '0.00', hst = '0.00'
    // If pstExempt: pst = '0.00'
    // Both can be true simultaneously (fully exempt)
}
```

**`lib/Billing/DiscountEngine.php`** — Pure math. No DB.
```php
class DiscountEngine {
    public function apply(string $subtotal, string $type, string $value): array
    // Returns: ['discount_amount' => string, 'subtotal_after' => string]
}
```

**`lib/Billing/CreditEngine.php`** — Pure math. No DB.
```php
class CreditEngine {
    public function apply(string $invoiceTotal, array $credits): array
    // Returns: ['credits_applied' => string, 'balance_due' => string]
}
```

**`lib/Billing/LateFeeEngine.php`** — Pure math. No DB.
```php
class LateFeeEngine {
    public function calculate(string $invoiceBalance, array $rule): array
    // Returns: ['fee_amount' => string, 'fee_type' => string]
}
```

**`lib/Billing/MileageCalculator.php`** — Pure math. No DB.
```php
class MileageCalculator {
    public function convert(string $distance, string $from, string $to): string
    public function applyRate(string $distance, string $rate): string
}
```

**`lib/Billing/CurrencyConverter.php`** — One DB read (exchange rate). Returns converted amount.
```php
class CurrencyConverter {
    public function convert(string $amount, string $from, string $to, ?string $date = null): string
    // Fetches rate from exchange_rates table. Uses most recent if date not found.
}
```

**`lib/Billing/InvoiceGenerator.php`** — THE ORCHESTRATOR. Only class that writes to DB.
```php
class InvoiceGenerator {
    public function generateFirstInvoice(int $leaseId): array
    public function generateMonthlyInvoice(int $leaseId, string $year, string $month): array
    public function generateFinalInvoice(int $leaseId, float $actualMileage): array
    public function generateLateFeeInvoice(int $invoiceId): array
    public function previewBillingSchedule(array $leaseData): array
    // All return invoice data arrays — caller decides what to do with them
}
```

### Customer-Facing Invoice PDF Layout
```
[COMPANY LOGO]          INVOICE
Mainland Truck & Trailer
9616 188 St, Surrey, BC          Invoice #: INV-2025-00284
+1 866-888-6887                  Date: March 17, 2025
                                 Due: April 16, 2025

Bill To:
ABC Trucking
John Smith
123 Main St, Vancouver BC
──────────────────────────────────────
Lease: CN-A3F9K2-2025 | Unit: #1042 — Chassis | Period: Jun 1–10, 2025
──────────────────────────────────────
DESCRIPTION                           AMOUNT
──────────────────────────────────────
Base Rental — Jun 1–10 (10 days)
  1 week × $300.00              $300.00
  3 days × $42.86/day           $128.57
  [Weekly rate — cheaper than monthly]  $428.57

Mileage Adjustment (+200 mi)
  Actual: 1,200 mi × $0.09      $108.00
  Pre-charged:                  ($90.00)
  Net adjustment:                 $18.00
──────────────────────────────────────
Subtotal:                        $446.57
GST (5%):                         $22.33
PST (7%):                         $31.26
──────────────────────────────────────
TOTAL DUE:                       $500.16
──────────────────────────────────────
Payment terms: Net 30
[PAYMENT INSTRUCTIONS]

Powered by FleetForge
```

---

## 10. GPS & TELEMATICS INTEGRATION

**Provider:** Samsara only.
**Architecture:** Two features only — no webhooks, no polling, no live maps, no geofences.

### Feature 1: Track in Samsara Button
- Equipment unit has `samsara_vehicle_url` field
- Admin enters Samsara vehicle URL once when creating/editing unit
- Button on unit profile: opens URL in new tab
- If URL not set: button hidden

### Feature 2: Mileage Auto-Fill at Lease Close
- `api/v1/gps/mileage.php` called when user opens "Close Lease" form
- Calls Samsara `getMileageForLease(vehicleId, startDate, endDate)`
- Returns miles driven for that period
- Pre-fills `actual_mileage` field — user can override
- If GPS fails: field empty, user enters manually, lease close not blocked

### `lib/GPS/SamsaraClient.php`
```php
class SamsaraClient {
    public function getMileageForLease(string $vehicleId, string $start, string $end): ?float
    // Returns miles as float, or null on any failure
    // 10-second timeout on all HTTP calls
    // Logs all failures to logs/gps.log
    // Converts meters → miles (or km if customer preference)
}
```

### Removed from original plan (NOT being built)
- Live map embed
- Fleet map view
- Geofences
- GPS events/behavior alerts
- Location history (`gps_location_history` table — not created)
- Trip history (`gps_trips` table — not created)
- `gps_devices` table (removed v2.2 — fields on equipment_units directly)
- Samsara webhook handler
- GPS polling cron

---

## 11. STATE MACHINES

### Equipment Status
```
available ──► reserved (reservation created)
available ──► on_lease (lease activated)
available ──► maintenance (work order opened)
available ──► inactive (soft removed)
reserved ───► on_lease (unit marked out)
reserved ───► available (reservation cancelled)
on_lease ───► available (lease completed/cancelled)
on_lease ───► maintenance (emergency — requires reason)
maintenance ► available (work order completed)
maintenance ► inactive (Manager + reason required) -- [PASS-1:M7]
inactive ───► available (manager + reason required)
decommissioned ► [TERMINAL — no transitions out]
```

Any transition NOT listed above → blocked at API, returns 409 Conflict.
All transitions → create row in `equipment_status_log`.

### Lease Status
```
pending ────► active (activated)
pending ────► cancelled (before activation)
active ─────► completed (lease closed)
active ─────► cancelled (Manager role + reason required)
completed ──► active (reopen — Manager role + reason required)
cancelled ──► [TERMINAL]
```

On active: unit → on_lease, rate history logged, next_billing_date set
On completed: unit → available, actual_return_date stamped, final invoice triggered

### Invoice Status
```
draft ──────► sent (delivered to customer)
draft ──────► void (before sending)
sent ───────► partially_paid (payment applied, balance > 0)
sent ───────► paid (balance = 0)
sent ───────► overdue (due_date passed, nightly cron)
sent ───────► void (Manager + reason)
partially_paid ► paid (final payment)
partially_paid ► overdue (due_date passed, nightly cron)
partially_paid ► written_off (Manager + reason required) -- [PASS-1:H6]
overdue ────► paid
overdue ────► partially_paid (payment applied, balance > 0) -- [PASS-1:C1]
overdue ────► written_off (Manager + reason)
paid ───────► [TERMINAL]
void ───────► [TERMINAL]
```

### Reservation Status
```
pending ────► confirmed (reservation reviewed) -- [PASS-1:H1]
pending ────► cancelled -- [PASS-1:H1]
confirmed ──► completed (unit marked out)
confirmed ──► cancelled
completed ──► confirmed (reversed)
cancelled ──► [TERMINAL]
```

### Work Order Status
```
open ───────► in_progress
open ───────► cancelled
in_progress ► waiting_parts
in_progress ► completed
waiting_parts ► in_progress
completed ──► [TERMINAL]
cancelled ──► [TERMINAL]
```

---

## 12. BUSINESS LOGIC FORMULAS

### Customer Risk Score (nightly recalculation)
```
Start: LOW

→ MEDIUM if any:
  - Any invoice overdue > 15 days
  - 2+ invoices overdue in last 12 months
  - outstanding_balance > 50% of credit_limit
  - Any damage claim in last 6 months

→ HIGH if any:
  - Any invoice overdue > 45 days
  - outstanding_balance > credit_limit
  - status = 'suspended' or 'credit_hold'
  - 4+ invoices overdue in last 12 months
  - 2+ damage claims in last 12 months
```

### Equipment Health Score (nightly, 0–100)
```
Start: 100

Deduct:
  -30 if any compliance doc EXPIRED
  -15 if any doc expires within 7 days
  -10 if any doc expires within 30 days
  -5  if any doc expires within 60 days
  -15 if open damage claim exists
  -10 if open work order > 14 days old
  -5  if any open work order
  -10 if mileage > average for template type × 1.5
  -5  if unit inactive > 90 days

Minimum: 0
Color: 80–100=green, 50–79=yellow, 20–49=orange, 0–19=red
```

### Fleet Utilization
```
utilization% = (units with status='on_lease') /
               (total units WHERE status NOT IN ('inactive','decommissioned'))
               × 100
Rounded to 1 decimal. Calculated in real-time on dashboard API call.
```

### Customer Outstanding Balance
```
outstanding_balance = SUM(invoices.balance_due)
WHERE customer_id = X
AND status NOT IN ('paid','void','written_off')
```
Denormalized on `customers.outstanding_balance`. Recalculated in every payment/invoice DB transaction.

---

## 12.5 FINANCIAL RECORD IMMUTABILITY [PASS-13:F1/F5]

Once a financial record is finalized, its financial fields are FROZEN:
- **Invoices (status != 'draft'):** All financial fields (subtotal, tax_*, total_amount, line items), all snapshot fields, invoice_date, due_date, billing_period — IMMUTABLE. Mutable: internal_notes, po_number, sent_to_email, delivery_method.
- **Payments (status != 'pending'):** All financial fields IMMUTABLE.
- **Journal entries (status = 'posted'):** All fields IMMUTABLE.
- **Credit notes (status = 'active'):** Amount IMMUTABLE.

To correct a finalized record: void + re-create (new number, full audit trail). The update API must reject changes to immutable fields with 422: "Cannot modify a finalized record. Use the void/reverse workflow instead." [PASS-13:F1]

**Invoice numbers MUST be strictly sequential within each year. No gaps. No reuse.** [PASS-13:I2] Use a dedicated counter in settings: `invoice.next_number.{YYYY}`. Increment atomically within the same transaction that creates the invoice. Voided invoices retain their number. Permanent deletion of invoices is NEVER allowed.

**Supplier GST/HST and PST registration numbers MUST appear on every invoice PDF, credit note, and customer statement.** [PASS-13:T3/I1] Stored in settings as `company.gst_number` and `company.pst_number`.

---

## 13. EDGE CASES — ALL HANDLED

| Situation | Handling |
|---|---|
| Lease starts AND ends same month | Single invoice at close with pre-charge + reconciliation |
| Monthly auto-draft exists when lease closes | Void draft, generate final invoice for partial period |
| Actual mileage < estimated | Negative adjustment = credit line item on final invoice |
| Credit > final invoice total | Remainder → credit_notes table as 'mileage_overpayment' |
| Mileage at end < start (data error) | Block lease close, show error, require manual confirmation |
| Open-ended lease (no end_date) | Monthly invoices forever until manually closed |
| Rates change mid-lease | Rates locked on lease — changes don't affect existing lease |
| Customer deleted mid-lease | Use company_name_snapshot, flag "[Customer deleted]" in UI |
| Unit deleted mid-lease | Use unit_number_snapshot, flag "[Unit deleted]" in UI |
| GPS unavailable at lease close | Mileage field empty — user enters manually, close not blocked |
| Tax exempt cert expired | Treat as NOT exempt, show warning in orange |
| Payment > invoice total | Apply to invoice, remainder → account_credit_balance |
| Two users lease same unit simultaneously | FOR UPDATE row lock in DB transaction — one wins, other gets 409 |
| Exchange rate not entered for today | Use most recent available rate, show warning |
| Very long company names | Truncate with ellipsis in tables, full name in tooltip |
| Template deleted with active units | BLOCK — "Cannot delete: X units use this template" |
| Customer with active lease deleted | BLOCK — "Cannot delete: X active leases" |
| Invoice total = $0 | Valid — show $0.00, allow send |
| Credit limit = NULL | No limit — never block lease creation |
| Customer status suspended | BLOCK lease creation — 422 error [PASS-11:C1] |
| Customer status credit_hold | WARN — Manager override required, Dispatcher BLOCKED [PASS-11:C1] |
| Customer at/over credit limit | WARN — informational yellow banner, all roles can proceed [PASS-11:C1] |
| Customer status inactive/pending | BLOCK lease creation — 422 error [PASS-11:C1] |

---

## 14. NOTIFICATION TRIGGERS

### Compliance (nightly cron 6AM)
- Expiring in 30 days → warning
- Expiring in 7 days → critical
- Expired today → urgent
- Multiple expiries on same unit → one grouped notification

### Lease Events
- Lease created → dispatcher + manager
- Lease activated → dispatcher
- end_date tomorrow → reminder to dispatcher
- end_date in 7 days → manager
- No end_date + active > 180 days → manager alert
- Lease closed → accountant (invoice ready)

### Invoice Events
- Invoice overdue → accountant
- Overdue > 30 days → escalate to manager
- Invoice paid → accountant

### Maintenance
- Work order > 7 days open → assigned user reminder
- Work order > 14 days → escalate to manager
- Work order completed → dispatcher (unit available)

### Deduplication
Never send same notification type for same entity within 24 hours. Check `notification_log`.

---

## 15. SECURITY STANDARDS

### Authentication & Session
- Custom PHP session verification on all requests (`require_auth_api()`)
- Session key: `$_SESSION['ff_user']` for admin, `$_SESSION['ff_portal_user']` for portal
- Session lifetime: 8 hours inactive
- Regenerate session on login/role change
- Login lockout: 5 failures in 60 min → lock 15 minutes
- No username enumeration (always: "Invalid email or password")

### Input Security
- All input through `clean_string()`, `clean_decimal()`, `clean_date()`, `clean_int()`
- All output through `e()` helper
- No raw SQL string interpolation — always parameterized queries
- File uploads: validate MIME type with `finfo_file()` — never trust `$_FILES['type']`
- File names: renamed to `{entity}_{type}_{timestamp}.{ext}` — original name never kept
- Document access: always through `api/v1/documents/serve.php` — never direct file URL

### HTTP Security Headers (public/.htaccess)
```apache
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://fonts.gstatic.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; frame-ancestors 'none'"
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
```

### Global Exception Handling [PASS-8:2]
```
API endpoints:  set_exception_handler → JSON {success:false, error_code:'SERVER_ERROR'} + error_log
Page files:     set_exception_handler → branded 500 page + error_log
Cron jobs:      try/catch wrapper → file log + audit_log + exit(1)
External APIs:  curl timeouts + null/exception return + domain-specific log
PDF generation: try/catch → user-friendly error + error_log
All errors:     write to logs/{module}.log with structured format
```
`api/bootstrap.php` must include a `set_exception_handler()`. Page files via `includes/header.php`. Every cron job uses the try/catch wrapper pattern.

### Concurrency (Critical)
```php
// Lease creation — prevents double-lease
db_transaction(function() use ($data) {
    $unit = db_row("SELECT id, status FROM equipment_units
                    WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                   [$data['equipment_unit_id']]);
    if ($unit['status'] !== 'available') {
        json_error('UNIT_UNAVAILABLE', "Unit is {$unit['status']}", 409);
    }
    // Safe to create lease + flip unit status
});
```

**Additional FOR UPDATE requirements [PASS-8:4]:** The same pattern MUST be used for:

```php
// Lease close — prevents race with monthly invoice cron
db_transaction(function() use ($leaseId) {
    $lease = db_row("SELECT * FROM leases WHERE id = ? FOR UPDATE", [$leaseId]);
    if ($lease['status'] !== 'active') json_error('LEASE_NOT_ACTIVE', ..., 409);
    // Void any draft invoices, generate final invoice, update status
});

// Payment allocation — prevents over-allocation
db_transaction(function() use ($invoiceId, $amount) {
    $invoice = db_row("SELECT * FROM invoices WHERE id = ? FOR UPDATE", [$invoiceId]);
    if ($invoice['balance_due'] < $amount) {
        json_error('ALLOCATION_EXCEEDS_BALANCE', ..., 422);
    }
    // Update amount_paid, balance_due, create payment_allocation
});

// Credit note application — same pattern as payment allocation
db_transaction(function() use ($invoiceId, $creditAmount) {
    $invoice = db_row("SELECT * FROM invoices WHERE id = ? FOR UPDATE", [$invoiceId]);
    if ($invoice['balance_due'] < $creditAmount) {
        json_error('CREDIT_EXCEEDS_BALANCE', ..., 422);
    }
    // Update credits_applied, balance_due, create credit_note_application
});
```

**Cron advisory locks [PASS-8:4B, PASS-15:C1]:** Every write-heavy cron MUST acquire a MySQL advisory lock at startup to prevent duplicate execution:
```php
$lock = db_row("SELECT GET_LOCK('ff_monthly_invoice', 0) AS ok", []);
if (!$lock || $lock['ok'] !== 1) { exit(0); } // already running
// ... cron logic ...
db_execute("SELECT RELEASE_LOCK('ff_monthly_invoice')", []);
```
Apply to: `invoice_generate_monthly`, `invoice_overdue`, `late_fee_apply`, `health_scores`, `risk_scores`, `compliance_alerts`, `reconcile_counters`.

### Optimistic Locking (Concurrent Edits) [PASS-8:4G]
All update endpoints for user-editable records MUST prevent silent last-write-wins data loss:
```php
// On form load: send updated_at to the frontend as a hidden field
// On save: verify record hasn't been modified since the form was loaded
$existing = db_row("SELECT updated_at FROM customers WHERE id = ?", [$id]);
if ($existing['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA',
        'This record was modified by another user. Please refresh and try again.', 409);
}
// Safe to update
```
Applies to: customers, equipment_units, equipment_templates, leases (draft only), vendors, rate_cards, maintenance_work_orders, portal_service_requests.

### Soft Delete Enforcement
Every query on soft-deletable tables MUST include `AND {table}.deleted_at IS NULL`.
Violation = bug. Must be fixed before merge.

---

## 16. PERFORMANCE STANDARDS

| Target | Requirement |
|---|---|
| Dashboard initial load | < 2 seconds |
| Module list pages | < 1.5 seconds |
| Profile pages | < 1 second |
| API endpoints (simple) | < 300ms |
| API endpoints (complex aggregations) | < 800ms |
| Chart rendering | < 500ms after data |

### Caching Strategy
| Data | TTL | Invalidation |
|---|---|---|
| Dashboard KPIs | 5 min | Any relevant record change |
| Chart data | 15 min | On data change |
| AI summaries | 24 hours | Entity updated |
| AI forecasts | 6 hours | Lease/payment change |
| Sidebar badge counts | 5 min | PHP session |
| Settings | Per-request static | Read once |

### Chart Granularity (auto-selected)
| Range | Granularity |
|---|---|
| 1–90 days | Daily |
| 91–365 days | Weekly |
| 365+ days | Monthly (max 24 points) |

---

## 17. DEPLOYMENT & SERVER SETUP

### Step-by-Step Lightsail Setup

**Step 1: Create Lightsail Instance**
- Go to aws.amazon.com → Lightsail → Create Instance
- Platform: Linux/Unix
- Blueprint: OS Only → Ubuntu 22.04 LTS
- Plan: $20/month (4GB RAM, 2 vCPUs, 80GB SSD) — minimum for production
- Instance name: `fleetforge-production`
- Create a static IP and attach it to the instance
- Create a snapshot plan: Daily, retain 7 snapshots

**Step 2: SSH Access**
```bash
# Download key pair from Lightsail console
chmod 400 ~/Downloads/your-key.pem
ssh -i ~/Downloads/your-key.pem ubuntu@YOUR_STATIC_IP
```

**Step 3: System Setup**
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.2 php8.2-mysql php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-gd php8.2-intl php8.2-opcache \
  php8.2-bcmath apache2 mysql-server git unzip curl
```

**Step 4: Configure PHP**
```bash
sudo nano /etc/php/8.2/apache2/php.ini
# Set these values:
# memory_limit = 256M
# upload_max_filesize = 25M
# post_max_size = 30M
# max_execution_time = 60
# date.timezone = America/Vancouver
# === ADDITIONAL REQUIRED SETTINGS [PASS-8:7] ===
# display_errors = Off
# log_errors = On
# error_log = /var/www/fleetforge/logs/php_errors.log
# error_reporting = E_ALL
# session.cookie_httponly = 1
# session.cookie_samesite = Lax
# session.cookie_secure = 1
# session.use_strict_mode = 1
# session.use_only_cookies = 1
# session.sid_length = 48
# session.gc_maxlifetime = 28800
# max_input_vars = 5000
# expose_php = Off
# opcache.enable = 1
# opcache.memory_consumption = 128
# opcache.max_accelerated_files = 10000
```

**Step 5: Configure MySQL**
```bash
sudo mysql_secure_installation
sudo mysql -u root

CREATE DATABASE fleetforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleetforge_user'@'localhost' IDENTIFIED BY 'YOUR_STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON fleetforge.* TO 'fleetforge_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**Step 6: Install Composer**
```bash
cd ~
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**Step 7: Deploy Project**
```bash
cd /var/www
sudo git clone https://github.com/YOUR_REPO/fleetforge.git
cd fleetforge
sudo composer install --no-dev --optimize-autoloader
sudo chown -R www-data:www-data /var/www/fleetforge
sudo chmod -R 755 /var/www/fleetforge
sudo chmod -R 775 /var/www/fleetforge/storage
sudo chmod -R 775 /var/www/fleetforge/logs
sudo chmod -R 775 /var/www/fleetforge/cache
```

**Step 8: Configure Apache**
```bash
sudo nano /etc/apache2/sites-available/fleetforge.conf
```
Paste:
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/fleetforge/public

    <Directory /var/www/fleetforge>
        Require all denied
    </Directory>

    <Directory /var/www/fleetforge/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/fleetforge_error.log
    CustomLog ${APACHE_LOG_DIR}/fleetforge_access.log combined
</VirtualHost>
```
```bash
sudo a2ensite fleetforge.conf
sudo a2dissite 000-default.conf
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

**Step 9: SSL Certificate**
```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com
# Follow prompts — auto-configures HTTPS + HTTP→HTTPS redirect
# Certbot adds auto-renewal via systemd timer
```

**Step 10: Configure Environment**
```bash
cd /var/www/fleetforge
sudo cp .env.example .env
sudo nano .env
```
Fill in all values:
```
APP_ENV=production
APP_URL=https://yourdomain.com/fleetforge
APP_DEBUG=false
APP_TIMEZONE=America/Vancouver
FF_ASSET_VERSION=1.0.0
FF_VERSION=1.0.0 -- [PASS-12:M1] code version tracking

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fleetforge
DB_USERNAME=fleetforge_user
DB_PASSWORD=YOUR_STRONG_PASSWORD

# Auth: no external service required
# Session secret used for CSRF token generation
APP_SECRET=generate_with_openssl_rand_hex_32

GPS_SAMSARA_API_KEY=your_samsara_api_key
GPS_SAMSARA_ORG_ID=your_org_id

AI_ANTHROPIC_API_KEY=your_anthropic_key
AI_ENABLED=false
AI_DAILY_TOKEN_LIMIT=500000

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your@email.com
SMTP_PASSWORD=your_smtp_password
SMTP_FROM_EMAIL=noreply@yourdomain.com
SMTP_FROM_NAME=Mainland Truck & Trailer
```

**Step 11: Run Database Schema**
```bash
# Run each schema file in order
for f in /var/www/fleetforge/database/schema/*.sql; do
    mysql -u fleetforge_user -p'YOUR_PASSWORD' fleetforge < "$f"
    echo "Ran: $f"
done

# Run seeds in order
mysql -u fleetforge_user -p'YOUR_PASSWORD' fleetforge < database/seeds/001_user_roles.sql
mysql -u fleetforge_user -p'YOUR_PASSWORD' fleetforge < database/seeds/002_permissions.sql
mysql -u fleetforge_user -p'YOUR_PASSWORD' fleetforge < database/seeds/003_settings.sql
mysql -u fleetforge_user -p'YOUR_PASSWORD' fleetforge < database/seeds/004_yards.sql
mysql -u fleetforge_user -p'YOUR_PASSWORD' fleetforge < database/seeds/005_tax_rates.sql
```

**Step 12: Create First Admin User**
Run the seed script to create the first super_admin user:
```bash
php database/seeds/create_admin.php
```
Then log in at https://yourdomain.com with the credentials shown.
**Step 13: Install Crontab**
```bash
sudo -u www-data crontab -e
# Paste all entries from cron/README.md
```

**Step 14: Create First Admin User**
```bash
php /var/www/fleetforge/cron/create_admin.php \
  --name="Avi" \
  --email="your@email.com"
# This creates the user and sends an invite email via the built-in mailer
```

**Step 15: Verify Installation**
```bash
# Check Apache is running
sudo systemctl status apache2

# Check MySQL is running
sudo systemctl status mysql

# Check PHP works
php -r "echo PHP_VERSION;"

# Check SSL
curl -I https://yourdomain.com/fleetforge

# Check login page is reachable
curl -I https://yourdomain.com/fleetforge/auth/login -- [PASS-1:L3] callback.php was Auth0 leftover, corrected

# Check cron is installed
sudo -u www-data crontab -l

# Check storage is writable
ls -la /var/www/fleetforge/storage/uploads
```

### Deployment Updates
```bash
# Put in maintenance mode
touch /var/www/fleetforge/storage/maintenance.flag

# Pull changes
cd /var/www/fleetforge
sudo git pull origin main
sudo composer install --no-dev --optimize-autoloader

# Run any new migrations
# mysql -u fleetforge_user -p'PASSWORD' fleetforge < database/migrations/XXX.sql

# Bump asset version in config/app.php

# Reload PHP
sudo systemctl reload apache2

# Remove maintenance mode
rm /var/www/fleetforge/storage/maintenance.flag
```

### Backup Setup
```bash
sudo mkdir -p /var/backups/fleetforge
sudo chown www-data:www-data /var/backups/fleetforge

# Add to www-data crontab:
# 0 1 * * * mysqldump -u fleetforge_user -p'PASSWORD' fleetforge | gzip > /var/backups/fleetforge/db_$(date +\%Y\%m\%d).sql.gz
# 0 2 * * 0 tar -czf /var/backups/fleetforge/files_$(date +\%Y\%m\%d).tar.gz /var/www/fleetforge/storage/uploads/
# 0 3 * * * find /var/backups/fleetforge/ -name "db_*.sql.gz" -mtime +30 -delete
```

---

## 18. MULTI-TENANCY

**Model: Separate install per client.**

Each client gets their own:
- Lightsail instance (or their own subdomain if shared server)
- MySQL database
- `.env` configuration
- `storage/` folder
- First admin user created (run seed script)

No `tenant_id` anywhere in the schema. Not needed. This model provides complete data isolation, simpler code, easier compliance, and one client's load doesn't affect others.

**White-labeling:** via `settings` table:
- `company.name` → topbar, PDFs, emails, portal
- `company.logo` → everywhere
- `company.address`, `company.phone`, `company.email`
- `company.timezone` → all datetime display

"FleetForge" branding only in: admin sidebar logo, PDF footer ("Powered by FleetForge"). All configurable.

---

## 19. BUILD ORDER

**Phase 1 — Foundation (Session 1)**
config/app.php, .env.example, includes/db.php, includes/functions.php, includes/auth.php, public/.htaccess, public/index.php, public/assets/css/app.css, public/assets/js/app.js, includes/header.php, includes/sidebar.php, includes/topbar.php, includes/footer.php, config/navigation.php, config/permissions.php, app/auth/login.php, app/auth/logout.php, app/auth/forgot_password.php, app/auth/reset_password.php, app/auth/accept_invite.php

**Phase 2 — Database (Session 2)**
All 62 schema files, all seed files, verify all FK constraints, verify indexes, run full schema on dev DB

**Phase 3 — Dashboard (Session 3)**
api/v1/dashboard/kpis.php, api/v1/dashboard/charts.php, api/v1/dashboard/activity_feed.php, app/admin/dashboard/home.php (all 8 charts, all 6 KPI tiles with drilldown links, AI panel, compliance alerts widget, recent leases widget)

**Phase 4 — Customers (Sessions 4–5)**
All customer API endpoints, customer list page (filters/search/bulk/export), customer create, customer edit, customer view (profile with 5 charts, tabs: leases, invoices, documents, notes, rate history, contacts)

**Phase 5 — Equipment (Sessions 6–7)**
Templates CRUD, Units list/create/edit/view (unit command center with 6 charts, compliance section, Track in Samsara button, QR code)

**Phase 6 — Leases (Sessions 8–9)**
Lease list/create/edit/view, lib/Billing/ProRateCalculator.php (+ unit tests), billing period preview on create form, lib/GPS/SamsaraClient.php, close lease flow with mileage auto-fill

**Phase 7 — Billing Engine (Sessions 10–11)**
All lib/Billing/ classes, api/v1/billing/ endpoints, cron/invoice_generate_monthly.php, first invoice generation, final invoice generation, invoice PDF (lib/PDF/InvoicePDF.php), invoice view page

**Phase 8 — Invoices & Payments (Sessions 12–13)**
Invoice list/view/edit, payment recording, payment allocations, credit notes, credit note applications, AR aging calculations

**Phase 9 — Reservations (Session 14)**
Reservation list/create/edit/view, mark out flow, calendar view

**Phase 10 — Rates (Session 15)**
Rate cards CRUD, customer equipment rates, rate history view

**Phase 11 — Fleet Operations (Sessions 16–18)**
Vendors, maintenance work orders, inspections (with photo upload), damage claims, compliance dashboard, mileage logs

**Phase 12 — Reports & Analytics (Sessions 19–20)**
All report pages, all charts, CSV/PDF export, report scheduling, saved reports

**Phase 13 — Documents & Contracts (Session 21)**
Document upload/serve/download, contract template editor, contract generator, PDF generation

**Phase 14 — Admin (Sessions 22–23)**
Users/RBAC, notification rules, notification center, audit log, settings module, yard management

**Phase 15 — AI Layer (Sessions 24–25)**
Anthropic client, all AI endpoints, chat panel, lease summaries, customer insights, fleet brief cron (AI_ENABLED=false until real data exists)

**Phase 16 — Customer Portal (Sessions 26–27)**
Portal auth, portal dashboard, lease view, invoice view/download, document download, service requests

**Phase 17 — QuickBooks Online Sync (Session 39 — placeholder)**
One-way push to QBO: customers, invoices, payments, credit memos. OAuth 2.0. Builds after core platform AND accounting module are live. Full spec in FLEETFORGE_ACCOUNTING_SPEC.md Phase 26.

**Phase 18–26 — Accounting Module (Sessions 29–39)**
Complete in-house accounting system. Read FLEETFORGE_ACCOUNTING_SPEC.md for full specification before starting any accounting session. Covers: Chart of Accounts, General Ledger, AR accounting layer, Accounts Payable, Bank Reconciliation, Fixed Assets & Depreciation, Tax Management (GST/PST/HST), Financial Statements, Budgeting, FX revaluation, Year-end close, QuickBooks sync placeholder. 33 new tables (acc_ prefix). Total platform tables: 94.

---

## 20. CALCULATOR BUG FIX

The existing `lease_calculator_vfinal.html` has a bug on line ~68:
```javascript
// BROKEN:
const dailyRate = parseFlo1
      at(document.getElementById('dailyRate').value);

// FIXED:
const dailyRate = parseFloat(document.getElementById('dailyRate').value);
```
This causes a JavaScript syntax error that silently breaks the Calculate button.

---



---

## 4.1 GLOBAL UX INTERACTION STANDARDS

### The Core Rule: Every Number Is a Link
**Every number, count, or summary value displayed anywhere in the platform must be clickable and drill down to the full detail view of whatever that number represents.** No exceptions. If a user sees a number, they should be able to click it to understand what makes up that number.

This applies to:
- KPI tiles on every page
- Badge counts in the sidebar navigation
- Totals in table footer rows
- Summary counts on profile pages
- Numbers inside chart tooltips (where ApexCharts supports it)
- Any inline text like "4 overdue", "7 alerts", "12 active leases"

### Dashboard KPI Tile Drilldowns

Every KPI tile on the dashboard home page is clickable. Clicking navigates to the relevant module with the appropriate filter pre-applied via URL query parameters:

| KPI Tile | Clicks to | Pre-applied filter |
|---|---|---|
| Active Revenue | `modules/reports/index.php` | Current month, revenue breakdown view |
| Fleet Utilization % | `modules/equipment/index.php` | Units tab, grouped by status |
| Open Leases | `modules/leases/index.php` | Active/Pending tab |
| Overdue Invoices | `modules/invoices/index.php` | Filter: status=overdue |
| Compliance Alerts | `modules/compliance/index.php` | Filter: expiring within 30 days |
| Today's Pickups | `modules/reservations/index.php` | Filter: pickup_date=today |

URL pattern for pre-filtered views:
```
modules/leases/index.php?status=active
modules/invoices/index.php?filter=overdue
modules/compliance/index.php?window=30
modules/reservations/index.php?pickup_date=today
modules/equipment/index.php?view=status_breakdown
```

### Module-Level KPI Tile Drilldowns

KPI tiles that appear at the top of list pages also drill down:

**Customers list page:**
- Total Customers tile → removes all filters (shows everyone)
- Active tile → filters list to status=active
- New This Month tile → filters to created_at >= first of month
- At Risk tile → filters to risk_score=high OR status=credit_hold

**Equipment list page:**
- Available tile → filters units to status=available
- On Lease tile → filters units to status=on_lease
- In Maintenance tile → filters units to status=maintenance
- Expiring Soon tile → jumps to Compliance module

**Invoices list page:**
- Total Outstanding tile → filters to unpaid statuses
- Overdue tile → filters to status=overdue
- Paid This Month tile → filters to paid_date >= first of month

### Profile Page Number Drilldowns

On every entity profile page, summary counts are clickable:

**Customer profile:**
- "8 Active Leases" count → scrolls to lease history tab, filtered to active
- "Total Revenue: $142,800" → opens customer revenue report in Reports module
- "Outstanding Balance: $2,400" → opens Invoices module filtered to this customer, unpaid
- "14 Total Leases" → scrolls to lease history tab, all statuses

**Equipment unit profile:**
- "Leased 23 times" → scrolls to lease history tab
- "Total Revenue: $84,200" → opens unit revenue report
- "3 Open Work Orders" → scrolls to maintenance tab

**Lease profile:**
- Customer name → opens customer profile
- Unit number → opens unit profile

### Sidebar Badge Counts
Sidebar badges (e.g. "Invoices 4", "Compliance 7") are part of the nav link — clicking the nav item naturally takes you to that module. The badge count must reflect a pre-filtered view:
- Invoices badge → count of overdue invoices → clicking goes to Invoices filtered to overdue
- Compliance badge → count of units expiring in 30 days → clicking goes to Compliance filtered to 30-day window

### Chart Drilldowns (ApexCharts)
Where ApexCharts supports it, clicking a bar, slice, or data point drills down:
- Revenue chart bar (a specific month) → opens Reports filtered to that month
- Fleet status donut slice (e.g. "On Lease") → opens Equipment filtered to that status
- Top customers bar → opens that customer's profile
- AR aging bar → opens Invoices filtered to that aging bucket
- Compliance chart slice → opens Compliance filtered to that type

Implementation: Use ApexCharts `chart.events.dataPointSelection` callback to build the target URL and navigate.

### Visual Treatment of Clickable Numbers
All clickable numbers/counts must have a visual affordance so users know they're interactive:
- `cursor: pointer` always
- On hover: `color: var(--color-accent)` transition
- On hover: subtle `text-decoration: underline` for inline text links
- KPI tiles: `transform: translateY(-1px)` and `box-shadow` increase on hover (already in CSS)
- Never use `cursor: pointer` on non-clickable numbers — don't mislead

### Back Navigation & Filter Banners
Every drilldown view reached via a KPI click must show a dismissible filter banner at the top:
```
"Showing: Overdue invoices only    [×  Clear filter]"
```
This banner appears below the page header, styled as an info alert. Clicking × removes the filter and returns to the unfiltered list. The browser back button always works — no JS navigation that breaks history.

---

---

## 7. MODULE SPECIFICATIONS

### 7.1 Dashboard
**File:** `modules/dashboard/home.php`
**APIs:** `api/dashboard/kpis.php`, `api/dashboard/charts.php`, `api/dashboard/activity_feed.php`

**KPI tiles (top row):**
- Active Revenue (sum of all active lease monthly rates)
- Fleet Utilization % (on_lease / total active units)
- Overdue Invoices (count + total $)
- Compliance Alerts (units expiring in 30 days)
- Open Leases count
- Today's Pickups count

**Charts on dashboard:**
- Revenue over time (12-month area chart, current vs prior year)
- Fleet status donut (available / on_lease / reserved / maintenance)
- AR aging horizontal bar (0-30 / 31-60 / 61-90 / 90+)
- Top 5 customers by revenue (horizontal bar)
- Leases opened vs closed per month (grouped bar)
- Utilization trend (12-month line)
- Revenue by equipment type (donut)
- Weekly revenue heatmap

**Widgets:**
- Today's pickups list
- Compliance alerts list (red/orange/yellow coded)
- Recent lease activity feed
- Units on lease map (mini Mapbox embed)
- AI fleet health summary paragraph (generated nightly, cached)

---

### 7.2 Customers
**Files:** `modules/customers/index.php`, `create.php`, `edit.php`, `view.php`

**List page features:**
- DataTable with column filters: name, company, email, status, risk, tags
- KPI row: total, active, new this month, at-risk
- Bulk actions: export CSV, bulk tag
- Risk badge visible on every row
- Quick stats mini-chart: customer growth over 6 months

**Create/Edit form sections:**
1. Company & Contact Identity
2. Address
3. Regulatory (DOT, MC, Tax ID)
4. Billing Contact
5. Commercial (credit limit, payment terms, preferred yard)
6. Tags (multi-select)
7. Notes / Internal Notes

**View/Profile page sections:**
- Header: company name, status badge, risk score, lease count, outstanding balance
- Contact Info card
- Address card
- Regulatory Numbers card
- Billing Info card
- Financial Summary card (total revenue, credit limit, payment terms)
- Charts: revenue over time, lease frequency calendar, payment behavior
- Active Leases table
- Lease History table
- Custom Rates table
- Rate History table (toggle-able)
- Documents section
- Customer Notes (with pin functionality)
- Tags display
- AI Insights button (generates customer analysis)
- Audit trail for this customer

---

### 7.3 Equipment Templates
**Integrated into:** `modules/equipment/index.php` (top section)

Features: Full CRUD, category filtering, usage count (how many units use this template), cannot delete if units exist.

---

### 7.4 Equipment Units
**Files:** `modules/equipment/units/create.php`, `edit.php`, `view.php`

**Unit Profile (view.php) — the command center:**

**Hero section:**
- Unit number (large, DM Mono)
- Template type badge
- Status badge (color coded)
- Health Score gauge (0-100)
- GPS: last known location + "Track Live" button (opens map modal)
- "Scan QR" button to download/print QR code

**Tab navigation:**
1. **Overview** — all specs in field grid format
2. **Compliance** — expiry countdowns with progress bars, document uploads
3. **Lease History** — all leases as timeline + table
4. **GPS & Location** — mini map, last 10 trips, mileage chart
5. **Maintenance** — work order history, cost chart
6. **Documents** — all uploaded documents
7. **Status Log** — every status change ever
8. **Analytics** — revenue chart, utilization chart, ROI vs maintenance cost
9. **Activity** — full timeline of every event

**Status lock:** If unit is on active lease, status dropdown is locked. "Override" button requires reason and is logged.

---

### 7.5 Leases
**Files:** `modules/leases/index.php`, `create.php`, `edit.php`, `view.php`

**List page:** Three tabs — Active/Pending | Closed | Deleted. Each tab has its own filterable table.

**Create page:**
- Customer dropdown (auto-fills rates if custom rates exist — shows suggestion banner)
- Equipment unit dropdown (shows only available units by default, toggle to show all)
- Contract number (auto-generated suggestion, editable)
- Rates section (pre-fills from customer rates or rate card)
- Mileage section
- Add-ons section
- Dates section
- Document uploads
- Right panel: "Before you create" checklist + smart suggestions from AI

**View page:**
- Header: contract number, status badge, customer name, unit number
- Charge breakdown donut chart
- Mileage progress bar (estimated vs actual)
- All lease details in section cards
- Equipment snapshot section
- GPS mileage verification (if GPS connected)
- Lease timeline chart
- Documents section
- Damage claims linked to this lease
- Amendments log
- AI lease summary button
- Delete (soft) and Permanently Delete (requires typing "DELETE") options

---

### 7.6 Reservations
**Files:** `modules/reservations/index.php`, `create.php`, `edit.php`, `view.php`

**List page:** Two tables — Equipment In (pending/confirmed) | Equipment Out (completed)

**Create form:**
- Mode selector: Existing Customer | Manual Entry
- Existing mode: customer dropdown → auto-loads their active leased units
- Unit selection: dropdown (system units) + textarea (manual unit numbers)
- Selected units preview list (updates live as user selects)
- Quantity field: must match total selected units — validated before submit
- Conflict detection runs on both system and manual units

---

### 7.7 Invoices
Auto-generate from lease or create manually. PDF generation via mPDF. Full line item editor. Aging dashboard at top of list page. Email delivery hook (sends PDF attachment).

---

### 7.8 Payments
Record payments, allocate to one or multiple invoices. Customer balance visible everywhere. Receipt PDF generation. Overpayment creates credit.

---

### 7.9 Compliance & Expiry
**Full fleet compliance dashboard.** Grid view: every unit as a row, CVI/Registration/MVI/Insurance as columns. Color coded cells. Click any cell to update. Filter by yard, status, expiry window (7/14/30/60/90 days). Export to CSV for renewal prep. Compliance cost tracker.

---

### 7.10 Reports
Date range filters on everything. Every report exportable as PDF + CSV. Charts on every report. Saved report configurations. Report categories: Financial, Fleet, Customer, Compliance.

---

### 7.11 Analytics
AI-powered analysis page. Revenue forecasting chart (historical + projected + confidence band). Customer concentration risk. Utilization efficiency matrix scatter plot. Seasonal radar chart. Fleet composition optimizer. Cohort analysis. All with downloadable charts.

---

---

## 9. CHARTS & ANALYTICS SPECIFICATION

**Library:** ApexCharts v3 (CDN)
**Theme:** Auto-switches dark/light via `FF_Charts.updateTheme()`
**Downloads:** PNG, SVG, CSV built into every chart toolbar
**Colors:** `['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#f97316','#84cc16']`

### Dashboard Charts (8)
1. Revenue over time — area, 12 months, YoY comparison
2. Fleet status — donut, 5 segments
3. AR aging — horizontal bar, 4 buckets
4. Top customers — horizontal bar, top 5
5. Leases opened vs closed — grouped bar, 12 months
6. Utilization trend — line, 12 months
7. Revenue by equipment type — donut
8. Weekly revenue heatmap — custom heatmap grid

### Customer Profile Charts (5)
1. Revenue over time from customer — area chart
2. Lease frequency calendar — GitHub-style contribution graph
3. Payment behavior — bar (days to pay per invoice)
4. Equipment type usage — donut
5. Spend by category per lease — stacked bar

### Unit Profile Charts (6)
1. Revenue generated over time — area
2. Utilization per month — bar (days leased vs total)
3. Mileage accumulation — line (odometer over time)
4. Maintenance cost over time — bar
5. Lease history Gantt — horizontal timeline bars
6. Revenue vs maintenance cost — dual line

### Lease Profile Charts (3)
1. Charge breakdown — donut
2. Mileage tracking — bar (estimated vs actual)
3. Daily revenue accumulation — line

### Financial Charts (8)
1. Invoice status funnel
2. Aging buckets bar
3. Collection rate trend — line
4. Average days to payment — line
5. Payment volume by month — bar
6. Payment method breakdown — donut
7. Outstanding balance trend — line
8. P&L summary — bar with table

### Reports Charts (12)
1. Revenue by customer — ranked bar
2. Revenue by equipment type — ranked horizontal bar
3. Revenue by yard — bar
4. Lease value distribution — histogram
5. Fleet ROI ranking — horizontal bar
6. Idle time ranking — horizontal bar
7. Utilization comparison — multi-bar
8. Lease duration distribution — histogram
9. Customer lifetime value — ranked bar
10. New vs returning revenue — stacked bar monthly
11. Geographic distribution — map choropleth
12. Compliance expiry timeline

### Analytics Module Charts (8)
1. Revenue forecasting — line (historical solid + projected dashed with confidence band)
2. Utilization efficiency matrix — scatter plot
3. Customer concentration risk — pie
4. Seasonal pattern — radar/spider chart
5. Cohort revenue — stacked area
6. Fleet composition optimizer — bar (current vs recommended)
7. Lead time analysis — line
8. Average lease value trend — line

**Total: ~50 distinct charts across the platform**

---

---

## 10. CLAUDE AI INTEGRATION

**API:** Anthropic API, model `claude-sonnet-4-20250514`
**Location:** `ai/` folder + `api/ai/` endpoints
**When to build:** After core platform complete and has real data

### Features
1. **AI Chat Panel** — slide-out panel accessible from every page. Natural language queries. Can generate charts on the fly. Remembers conversation context per session.
2. **Lease Summaries** — "Summarize" button on lease profile. Reads full lease record and writes plain English summary. Cached 24 hours.
3. **Customer Insights** — "AI Insights" button on customer profile. Analyzes full history, surfaces patterns, flags risks.
4. **Fleet Health Brief** — Generated nightly. Plain English paragraph on dashboard about fleet state.
5. **Revenue Forecasting** — Analyzes historical lease patterns → projects 30/60/90 day revenue with reasoning.
6. **Anomaly Detection** — Nightly scan for data anomalies → morning digest notification.
7. **Smart Search** — "Ask" mode on global search. Natural language → DB query → results.
8. **Pre-Lease Sanity Check** — Before lease creation, checks: rate vs average, outstanding balance, customer risk.
9. **Damage Claim Draft** — Auto-drafts claim description from inspection notes + GPS event context.

### Caching Strategy
- Lease summaries: 24h cache, invalidated on lease update
- Customer insights: 24h cache, invalidated on customer/lease update
- Fleet health: 12h cache
- Chat messages: stored in `ai_chat_messages` table, not re-generated
- Forecasts: 6h cache

### Cost Controls
- `ai_query_log` table tracks all token usage and cost
- Daily token limit configurable in settings
- All prompt templates optimized to minimize tokens
- Caching aggressively to avoid redundant API calls

### Prompt Architecture (in `ai/prompts/`)
Each prompt file pulls structured data from DB, formats as a clear context block, appends the task instruction. No raw SQL in prompt files — use DB helper functions.

---

---

## 11. API CONVENTIONS

**Every API file:**
```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/api/bootstrap.php';
require_method('POST'); // or 'GET'
```

**Success response:**
```json
{ "success": true, "data": {...}, "meta": {...} }
```

**Error response:**
```json
{ "success": false, "error_code": "SNAKE_CASE_CODE", "message": "Human readable string" }
```

**List endpoints include headers:**
```
X-Total-Count: 247
X-Page: 1
X-Per-Page: 25
X-Pages: 10
```

**HTTP status codes used:**
- 200: success
- 201: created
- 400: bad request / validation
- 401: unauthorized
- 403: forbidden
- 404: not found
- 409: conflict (duplicate, constraint violation)
- 422: validation error (detailed field errors)
- 500: server error


---

---

## 14. AUTHENTICATION & ACCESS CONTROL

### Two Completely Separate Login Systems

**System 1 — Admin Panel** (`/fleetforge/auth/login.php`)
For internal staff only. Leads to the full admin dashboard.

**System 2 — Customer Portal** (`/fleetforge/portal/login.php`)
For customers only. Leads to a stripped-down self-service portal.
These two systems share no sessions, no cookies, no auth logic. Completely isolated.

---

### 14.1 ADMIN LOGIN PAGE

**Files:**
- `auth/login.php` — the login form page
- `auth/logout.php` — destroys session, redirects to login
- `auth/forgot_password.php` — email input, sends reset link
- `auth/reset_password.php` — token-validated new password form
- `includes/auth.php` — session guard, role checking helpers

**Login page design:**
- Full-page centered layout, no sidebar
- FleetForge logo + company name at top
- Email + Password fields
- "Remember me" checkbox (extends session to 30 days via secure cookie)
- "Forgot password?" link
- No "Sign up" link — accounts are admin-created only
- Failed login: generic error "Invalid email or password" (never specify which is wrong)
- After 5 failed attempts: account locked for 15 minutes, admin notified

**Session behavior:**
- Session starts on successful login
- Session ID regenerated immediately on login (prevents session fixation)
- Session expires after 8 hours of inactivity (configurable in Settings)
- `$_SESSION['ff_user']` stores: id, name, email, role_id, role_slug, permissions array
- Every page checks session via `includes/auth.php` — redirect to login if not authenticated
- Force logout available from Users module (invalidates all active sessions for that user)

**Password reset flow:**
1. User enters email on forgot_password.php
2. If email exists: generate a cryptographically secure token, store in DB with 2-hour expiry, send email with reset link
3. If email does not exist: same success message shown (prevents email enumeration)
4. User clicks link → reset_password.php validates token → allows new password entry
5. On success: token deleted, user redirected to login with success message

**Auth helper functions (in `includes/auth.php`):**
```php
require_auth()              // Redirect to login if not logged in
require_role(string $role)  // Abort 403 if user doesn't have this role
can(string $module, string $action) // Returns bool — can current user do $action on $module?
current_user()              // Returns $_SESSION['ff_user'] array or null
is_super_admin()            // Returns bool
```

**Every page file starts with:**
```php
require_once dirname(__DIR__) . '/includes/auth.php';
require_auth(); // Redirects to login if not authenticated
```

**Every sensitive API endpoint starts with:**
```php
require_once dirname(__DIR__, 2) . '/api/bootstrap.php';
require_auth_api(); // Returns 401 JSON if not authenticated
require_permission('leases', 'create'); // Returns 403 JSON if not permitted
```

---

### 14.2 ROLE-BASED ACCESS CONTROL (RBAC)

**Five built-in system roles (seeded on install, cannot be deleted):**

#### Super Admin
- Full access to everything
- Only role that can: manage users, assign roles, permanently delete records, view audit log, change system settings, manage API keys
- Should only be assigned to the business owner (you)

#### Manager
- Full operational access
- Can: create/edit/close all leases, invoices, customers, equipment, reservations, maintenance, documents
- Can: view all reports and financial data
- Cannot: manage users, change roles, permanently delete, change system settings

#### Dispatcher
- Day-to-day operational access only
- Can: view customers (no edit), create/view reservations, create/view leases, view/update equipment status
- Cannot: see invoice amounts, payment data, financial reports, customer credit limits, rate cards
- Cannot: edit or delete customers, equipment templates, or closed leases

#### Accountant
- Financial modules only
- Can: create/edit invoices, record payments, view all financial reports, manage rate cards
- Can: view customers (read-only), view leases (read-only, amounts visible)
- Cannot: create or modify leases, reservations, equipment records

#### Read Only
- View everything the Manager can view, but zero write access
- No create, edit, or delete buttons rendered for this role
- Good for auditors, silent partners, temporary contractors

**Custom roles:**
- Created in Settings → Users & Roles → New Role
- Per-module permission grid: View | Create | Edit | Delete | Export checkboxes for each module
- Named and described by admin
- Can be assigned to any user

**Permission enforcement — two layers:**

Layer 1 — UI layer (PHP):
```php
// In page files — hide elements the user can't use
<?php if (can('leases', 'create')): ?>
    <button class="btn btn-primary">New Lease</button>
<?php endif; ?>
```

Layer 2 — API layer (PHP):
```php
// In api/bootstrap.php helpers — reject unauthorized API calls
function require_permission(string $module, string $action): void {
    if (!can($module, $action)) {
        json_error('FORBIDDEN', 'You do not have permission to perform this action.', 403);
    }
}
```

Both layers always enforced. UI hiding is UX. API rejection is security.

**Sidebar visibility:**
The sidebar dynamically shows only modules the current user's role has `can_view` permission for. A Dispatcher never sees the Invoices, Payments, or Reports links. An Accountant never sees Reservations or Maintenance.

---

### 14.3 USER MANAGEMENT (modules/users/)

**User list page:**
- Table: name, email, role badge, status badge, last login, actions
- Filter by role, status
- Invite New User button
- Bulk deactivate

**Creating a user:**
1. Admin fills in: name, email, role
2. System generates invite token, sends invite email
3. User receives email: "You've been invited to FleetForge. Click here to set your password."
4. Link goes to `auth/accept_invite.php?token=XXX`
5. User sets password, account activates
6. Invite token expires in 48 hours — admin can resend

**User profile/edit:**
- Change name, email, role
- Reset password (sends reset email)
- Deactivate account (immediately invalidates session, blocks login)
- Reactivate account
- View login history (last 10 logins with IP and timestamp)
- Force logout (destroys all active sessions)

**Login history table:**
Every login attempt recorded in DB — timestamp, IP, user agent, success/failure. Visible to Super Admin in audit log and on user profile.

---

### 14.4 ADMIN LOGIN SECURITY CHECKLIST

Every item below must be implemented — not optional:

- [ ] Passwords hashed with `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])`
- [ ] Session ID regenerated on every login: `session_regenerate_id(true)`
- [ ] HttpOnly + SameSite=Lax + Secure (HTTPS) session cookies
- [ ] CSRF token on login form (prevents cross-site form submission)
- [ ] Rate limiting: 5 failed attempts → 15-minute lockout
- [ ] Generic error messages (never "email not found" vs "wrong password")
- [ ] Password reset tokens: cryptographically random, single-use, 2-hour expiry
- [ ] Remember me: separate long-lived cookie, not just session extension
- [ ] All auth checks server-side — never rely on hidden form fields or JS-only checks
- [ ] Logout destroys session data completely: `session_destroy()`
- [ ] `.env` keeps session secret key for token signing

---

---

## 15. CUSTOMER PORTAL — FULL SPECIFICATION

### Overview
The customer portal is a completely separate application within FleetForge.
- **URL:** `/fleetforge/portal/`
- **Login:** `portal/login.php` — separate from admin login
- **Auth:** `portal/includes/auth.php` — separate session namespace (`ff_portal_user`)
- **Layout:** `portal/includes/header.php`, `sidebar.php`, `footer.php` — different design from admin
- **Branding:** Your company name and logo, but cleaner/simpler than the admin panel
- **Data isolation:** Portal users can ONLY see their own company's data — enforced at every query level

### Portal File Structure
```
portal/
├── login.php
├── logout.php
├── forgot_password.php
├── reset_password.php
├── accept_invite.php
├── index.php                  → dashboard
├── leases/
│   ├── index.php              → all their leases
│   └── view.php               → individual lease detail
├── invoices/
│   ├── index.php              → all their invoices
│   └── view.php               → individual invoice + pay instructions
├── equipment/
│   └── index.php              → their currently leased units
├── documents/
│   └── index.php              → all their documents
├── requests/
│   ├── index.php              → service request list
│   ├── create.php             → submit new request
│   └── view.php               → request thread
├── account/
│   ├── index.php              → profile & settings
│   └── users.php              → manage their sub-users
└── includes/
    ├── auth.php               → portal session guard
    ├── header.php
    ├── sidebar.php
    └── footer.php
```

---

### 15.1 PORTAL LOGIN PAGE

Same security standards as admin login:
- Email + password
- Forgot password flow (separate from admin reset)
- 5 failed attempts → 15-minute lockout
- Session expires after 24 hours (longer than admin — customers use this less frequently)
- "Remember me" for 30 days

Portal users are stored in `portal_users` table (NOT the `users` table — completely separate).
A portal user belongs to exactly one customer. Multiple portal users can belong to the same customer (e.g. a trucking company's dispatcher and accountant both get portal access).

---

### 15.2 PORTAL DASHBOARD

**Top KPI row:**
- Active Leases (their count) → links to leases page
- Outstanding Balance ($) → links to invoices filtered to unpaid
- Units Currently Out (count) → links to equipment page
- Documents Expiring (count) → links to documents filtered to expiring

**Main content:**
- Active leases list (compact — contract #, unit, start date, days active, monthly rate)
- Outstanding invoices (invoice #, amount, due date, status badge)
- Recent activity feed (new lease created, invoice generated, payment received, service request updated)
- Compact fleet map showing all their currently leased units as pins

**Alerts banner (if applicable):**
- If they have an overdue invoice: red banner at top of every page
- If a unit's compliance is expiring soon: yellow banner

---

### 15.3 PORTAL — LEASES

**Lease list page:**
- Tabs: Active | Historical | All
- Columns: Contract #, Unit, Start Date, End Date, Status, Monthly Rate, Mileage Used
- Click any row → lease detail page
- Download all as CSV

**Lease detail page:**
- All lease information (customer-facing fields only — no internal notes visible)
- Rates section: daily/weekly/monthly, mileage rate
- Mileage tracker: contracted miles vs used miles (from GPS if available), progress bar showing how close to overage
- Charges section: base rental, mileage, add-ons, adjustments, total
- Documents section: download contract PDF, inspection-in, inspection-out
- Unit info: unit number, type, GPS location link
- Timeline: key events (lease started, inspection completed, etc.)
- **Action buttons:**
  - "Request Extension" → opens service request form pre-filled as lease_extension
  - "Report Early Return" → opens service request form pre-filled as early_return
  - "Report Damage" → opens service request form pre-filled as damage_report
  - "Download Contract" → downloads PDF

---

### 15.4 PORTAL — INVOICES

**Invoice list page:**
- Tabs: Outstanding | Paid | All
- Outstanding tab shown first with overdue highlighted in red
- Columns: Invoice #, Date, Due Date, Amount, Paid, Balance, Status
- Total outstanding shown prominently at top
- Download all as CSV

**Invoice detail page:**
- Full line item breakdown visible (base rental, mileage, insurance, adjustments, tax)
- Payment history for this invoice (if partially paid)
- Payment instructions section (bank details, check payable to, etc.) — from settings
- Download as PDF button
- If overdue: days overdue shown clearly
- Balance due shown large and prominently

---

### 15.5 PORTAL — EQUIPMENT

**Currently leased units page:**
- One card per active leased unit
- Each card shows: unit number, type, yard it was picked up from, lease start date, mileage used vs contracted
- **"Track Live" button** — opens GPS map in modal if unit has GPS device
- **"View Documents"** — CVI, registration PDFs for that unit (useful at roadside inspections)
- Compliance status for their unit shown (so they know if their trailer's CVI is expiring)
- Mileage progress bar: 0% → 100% = contracted miles. Turns orange at 80%, red at 95%

---

### 15.6 PORTAL — DOCUMENTS

**Document vault (their documents only):**
- All documents organized by type: Lease Contracts, Inspection Reports, Invoices, Compliance Docs, Other
- Each document: title, type, date uploaded, expiry date (if applicable), download button
- Search by document name
- Filter by type or date range
- Documents uploaded by admin staff are visible here automatically
- Customer cannot upload to this vault — uploads go through Service Requests

---

### 15.7 PORTAL — SERVICE REQUESTS

**Request list:**
- Table: request #, type, subject, status, submitted date, last update
- Status badges: Open (blue), In Review (yellow), Resolved (green), Closed (gray)
- Click any row → request thread

**Create new request form:**
- Request type dropdown:
  - Lease Extension Request
  - Early Return Notice
  - Damage Report
  - Billing Inquiry
  - Document Request
  - New Lease Inquiry
  - General Question
- Subject line
- Message textarea
- Equipment unit dropdown (pre-populated with their active leased units)
- Lease reference dropdown
- Photo/file upload (for damage reports — up to 5 photos, PDF)
- Submit button

**Request thread view:**
- Initial request shown at top
- Response thread below (admin replies visible here)
- Customer can add follow-up messages
- Status shown at top
- Expected response time shown (configurable in settings — e.g. "We typically respond within 1 business day")
- When admin resolves: customer sees resolution notes and "Mark as Closed" button

---

### 15.8 PORTAL — ACCOUNT SETTINGS

**Profile tab:**
- Update display name
- Update email (requires current password confirmation)
- Update phone number
- Change password (requires current password)

**Sub-Users tab:**
- List of all portal users linked to their customer account
- Invite a new sub-user: enter name + email → they receive invite email
- Deactivate a sub-user
- Only the primary account holder (first portal user created by admin) can manage sub-users

**Notification Preferences tab:**
- Toggle email notifications:
  - [ ] New invoice generated
  - [ ] Invoice overdue reminder
  - [ ] Lease ending in 14 days
  - [ ] Lease ending in 7 days
  - [ ] Unit compliance expiring (CVI, registration)
  - [ ] Service request status update
  - [ ] Payment received confirmation

**Payment Details tab (read-only):**
- Shows their payment terms (net-30, COD, etc.)
- Shows their outstanding balance
- Links to invoices page

---

### 15.9 PORTAL DATA ISOLATION — CRITICAL RULE

Every single database query in the portal must include a customer_id filter tied to the logged-in portal user's customer_id. No exceptions.

```php
// ALWAYS in portal queries:
$customerId = $_SESSION['ff_portal_user']['customer_id'];

// Example — never query without this filter:
$leases = db_select(
    "SELECT * FROM leases WHERE customer_id = ? AND deleted_at IS NULL ORDER BY created_at DESC",
    [$customerId]
);
```

The portal auth helper must expose:
```php
function portal_customer_id(): int  // Returns the logged-in customer's ID
function require_portal_auth(): void // Redirects to portal login if not authenticated
```

If a portal user somehow constructs a URL with a different customer's lease ID — the query will return no results because the customer_id filter won't match. Defense in depth.

---

### 15.10 PORTAL DESIGN

- Same DM Sans typography, same CSS variable system
- BUT different color accent — slightly warmer/less corporate than the admin panel
- Simpler sidebar: only 6-7 items, no admin-heavy sections
- Topbar shows their company name and logo (if uploaded)
- Mobile responsive — customers may access from phones
- Clean, consumer-grade feel — not as data-dense as the admin panel
- No charts except the mileage progress bars and the mini fleet map

---

---

## 4.6 FORM STANDARDS & VALIDATION

### Required vs Optional
Every form must clearly distinguish required from optional fields:
- Required fields: label ends with a red asterisk `*` (`.req` class)
- Optional fields: label ends with `(optional)` in muted small text
- No field is silently required — never let a form fail on submit without the user knowing why

### Client-Side Validation (runs on submit, before API call)
Every form validates client-side first using `FF_Form.validateRequired()` and custom validators:
- Required string fields: not empty after trim
- Email fields: valid email format
- Number fields: is numeric, within reasonable range (e.g. rates 0–99999)
- Date fields: valid date format, start_date <= end_date where applicable
- Phone fields: at least 7 digits after stripping non-numeric chars
- File upload fields: validate type and size client-side before upload begins

### Server-Side Validation (always runs, even if client passes)
Client-side validation is a UX convenience, not a security measure. Every API endpoint validates independently:
- Required fields checked via `require_input()`
- Business rules checked (e.g. unit is actually available before creating lease)
- Duplicate checks (e.g. contract_number unique, company_name+email unique)
- Return 422 with field-level error map:
```json
{
  "success": false,
  "error_code": "VALIDATION_ERROR",
  "message": "Please fix the errors below.",
  "fields": {
    "email": "This email is already registered to another customer.",
    "start_date": "Start date cannot be in the past."
  }
}
```

### Form Auto-Population Rules
When creating a lease and a customer is selected:
- If `customer_equipment_rates` exist for this customer + selected equipment type → pre-fill rate fields + show green banner: "Custom rates loaded for this customer"
- If no custom rates → check active `rate_cards` for a matching rate → pre-fill if found + show info banner: "Standard rate card applied"
- If no rate card either → leave fields empty

When creating a unit and a template is selected:
- Auto-fill: wheel_size, tire_size, axle_count, ownership_type, yard_location, cvi_interval_days, mvi_interval_days, registration_interval_days, notes
- All fields remain editable after auto-fill
- Show subtle banner: "Defaults loaded from template — edit as needed"

### Unsaved Changes Warning
Any form page with user input must warn before navigation away:
```javascript
window.addEventListener('beforeunload', (e) => {
    if (formHasUnsavedChanges()) {
        e.preventDefault();
        e.returnValue = '';
    }
});
```
Track `formHasUnsavedChanges()` by comparing current form values to original values on page load.

### Date Picker Behavior
All date inputs use native `<input type="date">`. No third-party date picker to keep things fast.
- Date format in UI: display as `MMM D, YYYY` (e.g. "Mar 17, 2025") everywhere except form inputs
- Date format in DB: always `Y-m-d`
- Date range validation: on leases, if both start_date and end_date are set, end_date >= start_date

### File Upload Standards
| Upload point | Allowed types | Max size | Storage path |
|---|---|---|---|
| Customer documents | PDF only | 10MB | `storage/uploads/customers/{id}/` |
| Equipment CVI | PDF only | 10MB | `storage/uploads/equipment/{id}/cvi/` |
| Equipment Registration | PDF only | 10MB | `storage/uploads/equipment/{id}/registration/` |
| Lease contract | PDF only | 20MB | `storage/uploads/leases/{id}/` |
| Lease inspection in | PDF, JPG, PNG | 20MB | `storage/uploads/leases/{id}/` |
| Lease inspection out | PDF, JPG, PNG | 20MB | `storage/uploads/leases/{id}/` |
| Inspection photos | JPG, PNG, HEIC | 10MB each, 20 per inspection | `storage/uploads/inspections/{id}/` |
| Damage claim photos | JPG, PNG, HEIC | 10MB each, 10 per claim | `storage/uploads/damage/{id}/` |
| Company logo (settings) | JPG, PNG, SVG | 2MB | `storage/uploads/branding/` |

Validation server-side uses `finfo_file()` to verify actual MIME type — never trust `$_FILES['type']`.
File naming after upload: `{entity_id}_{document_type}_{timestamp}.{ext}` — never keep original filename (security).

### Drag & Drop Upload
All file upload fields support drag & drop in addition to click-to-browse. Visual: dashed border with "Drop file here" text that pulses when a file is dragged over it.

---

---

## 4.7 TABLE STANDARDS

### Column Widths & Alignment
- ID/number columns (contract #, unit #): fixed width, left-aligned, DM Mono font
- Name/text columns: flexible width, left-aligned
- Date columns: fixed ~120px, left-aligned, DM Mono font
- Currency/number columns: fixed ~110px, **right-aligned**, DM Mono font
- Status columns: fixed ~100px, centered
- Actions column: fixed width (fits buttons), right-aligned, always last column

### Row Click Behavior
The entire table row is clickable and navigates to the view page for that record. Exception: the Actions column buttons have their own specific actions and stop event propagation.
- Row hover: background changes to `--table-hover`
- Row cursor: `pointer`
- Exception: if a row's record is soft-deleted (status = deleted/inactive), row is visually muted (0.6 opacity) and clicking does nothing — show a tooltip "This record has been deleted"

### Bulk Selection
All list tables support bulk selection:
- Checkbox in header row: select/deselect all visible rows
- Checkbox on each row: individual selection
- On selection: a bulk actions bar appears above the table with count and available actions
- Bulk actions available per module:
  - Customers: Export CSV, Bulk add tag, Bulk change status
  - Equipment: Export CSV, Bulk update yard, Bulk compliance export
  - Leases: Export CSV
  - Invoices: Export CSV, Bulk mark sent, Bulk send email (future)
- Bulk delete is NOT available for any module — too dangerous

### URL-Persistent Filters
All table filters are reflected in the URL as query parameters so filtered views can be bookmarked and shared:
```
/modules/leases/index.php?status=active&customer_id=47&sort=start_date&dir=desc&page=2
```
On page load, read URL params and pre-apply to filters. The back button restores previous filter state.

### Default Sort
| Module | Default sort | Direction |
|---|---|---|
| Customers | created_at | DESC |
| Equipment Units | unit_number | ASC |
| Leases | created_at | DESC |
| Invoices | invoice_date | DESC |
| Payments | payment_date | DESC |
| Reservations | pickup_date | ASC |
| Maintenance | created_at | DESC |
| Audit Log | created_at | DESC |

### Items Per Page
Default: 25 per page. User can change to 10, 25, 50, 100. Preference stored in localStorage per module. Never show more than 100 per page without export option.

---

---

## 4.13 PRINT & EXPORT STANDARDS

### Pages with Print Views
The following pages have print-optimized layouts triggered by `window.print()` or `Ctrl+P`:
- Lease view page → prints as a lease summary sheet
- Invoice view page → prints as a formatted invoice (same as PDF)
- Inspection view page → prints as an inspection report
- Damage claim view page → prints as a claim summary
- Compliance dashboard → prints as a fleet compliance report
- Any report page → prints the report with company header

### Print CSS rules (already referenced in app.css @media print)
- Hide: sidebar, topbar, action buttons, filters, pagination, AI panel, chart download buttons
- Show: all data tables in full (no pagination — expand fully)
- Charts: render as static images (ApexCharts supports this via `exportToSVG`)
- Font size: 11px for all body text in print
- Margins: 0.75in on all sides
- Company logo prints at top left of first page
- Page numbers print at bottom right: "Page X of Y"
- Date/time of print in footer: "Printed: March 17, 2025 3:42 PM"

### CSV Export
Every list table has an "Export CSV" button. Rules:
- Export respects active filters (exports the filtered set, not everything)
- Column headers are human-readable labels, not DB column names
- Dates formatted as `YYYY-MM-DD` in CSV (Excel-compatible)
- Currency values exported as plain numbers (1234.56 not $1,234.56)
- File name: `{module}_{filter_description}_{YYYY-MM-DD}.csv` e.g. `leases_active_2025-03-17.csv`
- Large exports (> 500 rows) show a "Generating export..." loading state

### PDF Generation (mPDF)
PDFs generated for: invoices, lease contracts, inspection reports, damage claims.
- Company logo in header
- Company name, address, phone in header
- Document title, number, and date prominent
- Page numbers in footer
- Generated at timestamp in footer
- FleetForge watermark in footer (subtle, light gray)
- All PDFs stored at generation path in `storage/uploads/` and path saved to DB
- Re-generation always replaces the existing file at the same path

---

---

## 4.14 AUDIT LOG — WHAT GETS LOGGED

Every action below creates a row in `audit_log`. "Old values" and "New values" are JSON snapshots of changed fields only (not entire records).

| Action | Module | Logged fields |
|---|---|---|
| Customer created | customers | All fields |
| Customer updated | customers | Changed fields only (diff) |
| Customer deleted | customers | id, company_name, deleted_by |
| Customer restored | customers | id, company_name, restored_by |
| Customer status changed | customers | old_status, new_status |
| Customer risk score changed | customers | old_score, new_score, reason |
| Equipment unit created | equipment | All fields |
| Equipment unit status changed | equipment | unit_number, old_status, new_status, reason, lease_id |
| Equipment unit deleted | equipment | id, unit_number |
| Lease created | leases | contract_number, customer, unit, rates, dates |
| Lease updated | leases | Changed fields only |
| Lease status changed | leases | contract_number, old_status, new_status, reason |
| Lease deleted | leases | contract_number, deleted_by, reason |
| Invoice created | invoices | invoice_number, customer, total_amount |
| Invoice voided | invoices | invoice_number, void_reason, voided_by |
| Payment recorded | payments | payment_number, amount, method, customer |
| Payment void | payments | payment_number, void_reason |
| User login | users | email, ip_address, user_agent |
| User login failed | users | email, ip_address, attempt_count |
| User created | users | name, email, role |
| User role changed | users | email, old_role, new_role |
| User deleted | users | email, deleted_by |
| Settings changed | settings | key, old_value, new_value |
| Document uploaded | documents | entity_type, entity_id, document_type, file_name |
| Document deleted | documents | entity_type, entity_id, document_type |
| Bulk action performed | any | action_type, record_count, affected_ids |
| Export performed | any | module, filter_description, row_count, exported_by |
| AI query | ai | query_type, tokens_used, cached |

---

---

## 4.16 ONBOARDING & FIRST-RUN EXPERIENCE

When a fresh installation has zero data (new customer of the SaaS product), the platform handles this gracefully:

### Empty Dashboard
- Instead of showing $0 KPIs and empty charts, show a "Getting Started" welcome panel
- Checklist: "Set up your company profile → Add your first yard → Create equipment templates → Add equipment units → Add your first customer → Create your first lease"
- Each item links directly to the relevant create/setup page
- Checklist progress persists — once step is complete, it shows a checkmark

### Guided Setup Flow (Settings first run)
On first login after installation:
1. Company info setup (name, address, phone, logo)
2. Add your yards (at minimum one yard required before adding units)
3. Redirect to dashboard with getting started checklist

### Sample Data Option
On first run, offer a "Load sample data" button that creates:
- 2 equipment templates (Chassis, Dry Van)
- 3 equipment units
- 2 customers
- 1 active lease
- 1 reservation
This lets the user explore the platform with real-looking data before entering their own. Sample data is tagged and can be bulk-deleted from Settings with one click.

---

---

## 4.17 SESSION & AUTHENTICATION STANDARDS

### Session Behavior
- Session lifetime: 8 hours of inactivity (configurable in `.env`)
- Session regenerated on: login, role change, privilege escalation
- Session destroyed on: logout, manual invalidation by Super Admin
- Session data stored: `ff_user` array with id, name, email, role_id, role_slug, permissions array

### Session Expiry Handling
- If session expires mid-form: user is redirected to login. After login, redirect back to the page they were on (store `redirect_after_login` in session before redirect).
- If an API call is made with an expired session: return 401 JSON response (not an HTML redirect). The JS `API` client handles 401 by redirecting to login page.

### Auth Guard
Every module page starts with:
```php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_auth(); // redirects to login if not authenticated
require_permission('customers', 'view'); // checks role permissions
```

### Login Page
- Email + password form
- "Remember me" checkbox (extends session to 30 days)
- "Forgot password" link → email reset flow
- Failed login: increment attempt counter. After 5 failed attempts, lock account for 15 minutes. Log each attempt in audit_log.
- No username enumeration: always show "Invalid email or password" regardless of whether email exists

---

---

## 4.18 REPORTS — DATE RANGE PRESETS

All report pages and chart date filters offer these preset ranges:

| Preset | Date range |
|---|---|
| Today | today → today |
| Yesterday | yesterday → yesterday |
| This Week | Monday of current week → today |
| Last Week | Monday → Sunday of previous week |
| This Month | 1st of current month → today |
| Last Month | 1st → last day of previous month |
| Last 30 Days | today-30 → today |
| Last 90 Days | today-90 → today |
| This Quarter | 1st of current quarter → today |
| Last Quarter | Previous full quarter |
| This Year | Jan 1 current year → today |
| Last Year | Full previous calendar year |
| All Time | First record in DB → today |
| Custom | Date picker for start + end |

Default range on first page load: **This Month**
User's last-used range is remembered per module in localStorage.

---

---

## 4.19 SETTINGS MODULE — FULL CONFIGURATION LIST

All settings stored in the `settings` table. Grouped by category:

**Company**
- `company.name` — displayed in topbar, PDFs, portal
- `company.address` — used in invoice PDF headers
- `company.phone`, `company.email`, `company.website`
- `company.logo` — file path, displayed in topbar and PDFs
- `company.timezone` — all dates display in this timezone
- `company.gst_number` — GST/HST registration number (BN-RT format) — REQUIRED on all invoices [PASS-13:T3/I1]
- `company.pst_number` — PST registration number [PASS-13:T3/I1]
- `company.currency_symbol` — default `$`
- `company.tax_rate` — default tax rate for invoices (e.g. 0.0 for no tax, 0.13 for 13%)

**Invoices & Payments**
- `invoice.due_days_default` — days after invoice_date that invoice is due (default: 30)
- `invoice.prefix` — prefix for invoice numbers (default: `INV`)
- `invoice.payment_instructions` — default text shown on all invoice PDFs
- `invoice.late_fee_percentage` — auto-added monthly on overdue invoices (default: 0 = disabled)

**Alerts & Compliance**
- `alerts.compliance_warning_days` — how many days before expiry to trigger warning (default: 30)
- `alerts.compliance_critical_days` — days before expiry for critical alert (default: 7)
- `alerts.lease_end_reminder_days` — days before end_date to send reminder (default: 7)
- `alerts.overdue_invoice_days` — days past due before escalation notification (default: 15)

**GPS Integration**
- `gps.primary_provider` — `samsara` or `geotab`
- `gps.samsara_api_key` — encrypted at rest
- `gps.samsara_org_id`
- `gps.geotab_database`, `gps.geotab_username`, `gps.geotab_password`
- `gps.sync_interval_minutes` — how often to poll for location updates (default: 5)

**AI**
- `ai.enabled` — toggle all AI features on/off
- `ai.daily_token_limit` — max tokens per day across all users (default: 500000)
- `ai.model` — default: `claude-sonnet-4-20250514`
- `ai.cache_summaries` — whether to cache AI responses (default: true)

**Yards**
- Yards are managed via the Yards module, not settings — but `yard.default` stores the default yard slug used in dropdowns

**Notifications**
- `notifications.email_enabled`
- `notifications.smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_from`, `smtp_from_name`
- `notifications.sms_enabled` (future)

---

---

## 4.20 GLOBAL SEARCH — FULL SPECIFICATION

The global search bar (⌘K) is a first-class feature, not an afterthought.

### Search Scope
Searches across: customers, equipment_units, leases, reservations, invoices, vendors

### Result Display
Results appear as a dropdown below the search bar, grouped by entity type:
```
CUSTOMERS (3)
  ABC Trucking          active     → /modules/customers/view.php?id=12
  ABC Logistics Inc     suspended  → /modules/customers/view.php?id=47

LEASES (2)
  CN-A3F9K2-2025  ABC Trucking  Unit #1042  → /modules/leases/view.php?id=284
  CN-B7K2P9-2025  Delta Freight Unit #887   → /modules/leases/view.php?id=271

EQUIPMENT UNITS (1)
  #1042 — Chassis  on_lease  → /modules/equipment/units/view.php?id=8
```

### Search Fields by Entity
| Entity | Searches in |
|---|---|
| Customers | company_name, contact_name, email, phone, dot_number, mc_number |
| Equipment Units | unit_number, vin, gps_device_id, license_plate |
| Leases | contract_number, customer_name_snapshot, company_name_snapshot, unit_number_snapshot |
| Reservations | contact_name, company_name, unit numbers in reservation_units |
| Invoices | invoice_number, customer snapshot fields |
| Vendors | name, contact_name, email |

### Search Behavior
- Minimum 2 characters before search fires
- Debounce: 250ms after last keystroke
- Maximum 5 results per entity type in dropdown
- "See all X results" link at bottom of each group → goes to full search results page
- Full search results page: same groups, more results, filterable by entity type
- Keyboard navigation: arrow keys move through results, Enter opens the highlighted result
- Recent searches stored in localStorage, shown when search is focused with empty input (max 8 recent)

---

---

---

## 4.22 BACKUP & DISASTER RECOVERY

### Database Backups
Daily automated MySQL dump via cron — add to crontab:
```bash
# Daily DB backup at 1:00 AM
0 1 * * * mysqldump -u fleetforge_user -p'password' fleetforge | gzip > /var/backups/fleetforge/db_$(date +\%Y\%m\%d).sql.gz 2>> /var/www/fleetforge/logs/cron.log

# Keep only last 30 days of DB backups
0 2 * * * find /var/backups/fleetforge/ -name "db_*.sql.gz" -mtime +30 -delete
```

### File Backups
Weekly backup of `storage/uploads/` to S3 or Lightsail snapshot:
```bash
# Weekly file backup (Sunday 3 AM)
0 3 * * 0 tar -czf /var/backups/fleetforge/files_$(date +\%Y\%m\%d).tar.gz /var/www/fleetforge/storage/uploads/
```

Lightsail automated snapshots: enable in AWS console, retain 7 snapshots.

### Recovery Procedure
Document stored in `database/RECOVERY.md`:
1. Restore latest Lightsail snapshot OR
2. Re-install server + restore DB dump + restore files backup
3. Target RTO (Recovery Time Objective): 2 hours
4. Target RPO (Recovery Point Objective): 24 hours (daily backups)

### What Cannot Be Recovered
- AI chat session history older than the last backup
- GPS location history older than the last backup (high-volume table)
- Any changes made between last backup and failure

---

---

## 4.24 TESTING CHECKLIST PER MODULE

Before a module is considered complete, every item in this checklist must pass.

### API Endpoint Testing (for every endpoint)
- [ ] Returns correct JSON structure `{success, data, meta}`
- [ ] Returns 422 with field errors on invalid input
- [ ] Returns 404 for non-existent record
- [ ] Returns 409 for constraint violations (duplicate, unavailable unit)
- [ ] Soft-deleted records excluded from GET all responses
- [ ] Pagination works correctly (page 1, page 2, last page, beyond last page)
- [ ] Sorting works correctly (ASC and DESC)
- [ ] Filters work correctly when combined
- [ ] `X-Total-Count` header correct after filtering
- [ ] `deleted_at IS NULL` enforced on all queries
- [ ] Rate limiting doesn't break normal usage

### UI Page Testing (for every list page)
- [ ] Loading skeleton shows during data fetch
- [ ] Empty state shows with correct icon and message when no records
- [ ] Empty state changes correctly when filters are active but return nothing
- [ ] Error state shows with retry button when API fails
- [ ] KPI tiles show correct counts and link to correct filtered views
- [ ] Filter inputs work individually and in combination
- [ ] URL updates with filter params and page reload restores filters
- [ ] Sort by each sortable column works
- [ ] Bulk select checkbox selects/deselects all visible rows
- [ ] Bulk actions appear on selection, work correctly
- [ ] Pagination navigates correctly
- [ ] Row click navigates to view page
- [ ] Action buttons (view/edit/delete) work and are permission-gated
- [ ] Export CSV button downloads correct data with active filters applied

### UI Page Testing (for every create/edit form)
- [ ] Required fields show asterisk
- [ ] Submit with empty required fields shows inline errors
- [ ] Submit with invalid data shows field-specific errors
- [ ] Successful submit shows success toast and redirects
- [ ] Failed submit shows error toast and keeps form populated
- [ ] Auto-populate works (template → unit, customer → lease rates)
- [ ] Unsaved changes warning shows when navigating away
- [ ] File upload fields accept correct types and reject wrong types
- [ ] File upload shows progress for large files
- [ ] Date fields validate range (start ≤ end)
- [ ] Form is keyboard navigable (tab order correct)
- [ ] Form submits correctly on Ctrl+S / Cmd+S

### UI Page Testing (for every view/profile page)
- [ ] All sections load independently (one failed section doesn't break the page)
- [ ] All clickable numbers drill down to correct filtered views
- [ ] Charts render in both dark and light mode
- [ ] Chart download buttons work (PNG, SVG, CSV)
- [ ] Timeline/history sections show correct data in correct order
- [ ] Status badges show correct color for each status value
- [ ] Edit button opens edit form pre-populated with current data
- [ ] Delete button shows confirmation, soft-deletes, shows success toast

### Business Logic Testing
- [ ] Double-lease prevention: try to lease a unit that's already on_lease
- [ ] Status machine: attempt invalid status transitions and confirm they're blocked
- [ ] Lease total calculation: verify formula with known inputs
- [ ] Invoice total calculation: verify with line items, tax, discount
- [ ] Customer balance: add payment, verify balance_due updates on invoice
- [ ] Risk score: trigger each threshold and verify score changes
- [ ] Health score: expire a compliance doc and verify score decreases

---

---

## 4.25 DATA RETENTION & HIGH-VOLUME TABLE MANAGEMENT

### Tables that grow without bound
| Table | Growth rate | Retention policy | Archive strategy |
|---|---|---|---|
| `audit_log` | ~500 rows/day | Keep 2 years live | Archive older rows to `audit_log_archive` yearly |
| `notification_log` | ~200 rows/day | Keep 6 months | Delete older rows monthly |
| `report_cache` | Varies | Delete on expiry | `cache_cleanup.php` cron handles this |
| `ai_query_log` | ~50 rows/day | Keep 1 year | Delete older rows monthly |

### Archive cron job (monthly, 4 AM on the 1st)
```bash
0 4 1 * * php /var/www/fleetforge/cron/archive_old_data.php
```

`archive_old_data.php` runs:
```sql
-- Archive audit log older than 2 years
INSERT INTO audit_log_archive SELECT * FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);

-- Clean notification log older than 6 months
DELETE FROM notification_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);

-- Clean AI query log older than 1 year
DELETE FROM ai_query_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

### Full-Text Search Indexes
FULLTEXT indexes are defined directly in the CREATE TABLE statements (v2.2). The following tables have FULLTEXT indexes built in:
```sql
-- Already in CREATE TABLE definitions:
-- customers: FULLTEXT idx_ft_customers (company_name, contact_name, email, dot_number, mc_number)
-- equipment_units: FULLTEXT idx_ft_units (unit_number, vin, license_plate)
-- leases: FULLTEXT idx_ft_leases (contract_number, company_name_snapshot, unit_number_snapshot)
-- invoices: FULLTEXT idx_ft_invoices (invoice_number, company_name_snapshot)
-- vendors: FULLTEXT idx_ft_vendors (name, contact_name)
```

Global search query pattern:
```sql
SELECT 'customer' AS entity_type, id, company_name AS label, status
FROM customers
WHERE MATCH(company_name, contact_name, email, dot_number, mc_number) AGAINST (? IN BOOLEAN MODE)
AND deleted_at IS NULL
LIMIT 5
```

---

---

## 4.26 TIMEZONE HANDLING

### Storage Rule
**All datetimes stored in UTC in the database.** The MySQL connection is set to `+00:00`:
```sql
SET time_zone = '+00:00';
```
This is already in `db.php` via `MYSQL_ATTR_INIT_COMMAND`.

### Display Rule
All datetimes displayed in the company's configured timezone (`settings.company.timezone`).
Conversion happens in PHP using `format_datetime()` helper, never in SQL.

```php
// In functions.php - already exists, but must use company timezone
function format_datetime(mixed $value, string $format = 'M j, Y g:i A'): string {
    if (empty($value)) return '—';
    try {
        $tz = new DateTimeZone(settings_get('company.timezone', 'America/Los_Angeles'));
        return (new DateTime($value, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
    } catch (Throwable) { return '—'; }
}
```

### Date-only fields (no time component)
Fields like `start_date`, `end_date`, `invoice_date`, `due_date`, `pickup_date` are stored as `DATE` type (not DATETIME). No timezone conversion needed — they represent calendar dates, not moments in time.

### JavaScript timezone handling
ApexCharts and any JS date formatting must also use the company timezone:
```javascript
// Set in header.php as a global JS variable
window.FF_TIMEZONE = '<?= e(settings_get("company.timezone", "America/Los_Angeles")) ?>';

// In app.js, date formatting uses this
FF.date = (value) => {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-US', {
        timeZone: window.FF_TIMEZONE,
        month: 'short', day: 'numeric', year: 'numeric'
    });
};
```

### DST (Daylight Saving Time)
PHP's `DateTime` with named timezone handles DST automatically. Never use numeric offsets like `+07:00` for display — always use named timezones like `America/Los_Angeles`.

---

---

## 4.27 GRACEFUL DEGRADATION (GPS & AI FAILURE MODES)

### GPS Provider Unavailable
When Samsara/Geotab API returns errors or times out:
- `gps_sync.php` cron: log the failure to `logs/gps.log`, skip this cycle, try again in 5 minutes
- Unit profile "Track Live" button: if last GPS ping > 30 minutes old, show "GPS data unavailable — last seen [timestamp]" instead of a map. Do NOT show a broken map embed.
- Fleet map view: if GPS data is stale, show all units as gray dots with "GPS sync delayed" banner
- Mileage auto-sync at lease start/close: if GPS unavailable, fall back to manual entry mode with a warning: "GPS sync unavailable — enter mileage manually"
- Webhook handler (`webhooks/samsara.php`): if DB is unavailable when webhook arrives, return HTTP 500 so Samsara retries. Implement webhook idempotency key (see Section 4.33)

GPS timeout: all GPS API calls have a 10-second timeout. Never let a GPS API hang block a page load.

### Anthropic API Unavailable
When Anthropic API returns errors, is rate-limited, or times out:
- AI Chat panel: show "AI is temporarily unavailable. Please try again shortly." in the chat window. Do NOT crash the panel.
- Lease summary button: show "Unable to generate summary right now" inline. The lease page still works fully.
- Customer insights: same — graceful "unavailable" message.
- Dashboard AI fleet brief: show last cached brief with a timestamp "Generated X hours ago". If no cached brief exists, show nothing (no widget).
- Cron jobs (`ai_fleet_brief.php`, `ai_anomaly_detection.php`): log failure, skip this run, try again next scheduled time. Do NOT retry in a loop (could exhaust rate limit).
- AI timeout: all Anthropic API calls have a 30-second timeout.

### Cron Job Failure Detection
If a critical cron job hasn't run successfully in 25 hours, show a warning banner in the admin dashboard:
- "Compliance alerts last run: 2 days ago — check cron configuration"
This is detected by checking `audit_log` for the most recent entry with `entity_type = 'cron'`.

---

---

## 4.28 MAINTENANCE MODE

A simple file-based maintenance mode. When `storage/maintenance.flag` exists, all requests to `public/index.php` redirect to a maintenance page.

```php
// In public/index.php - first thing after require config
if (file_exists(dirname(__DIR__) . '/storage/maintenance.flag')) {
    // Allow specific IPs to bypass (your office IP)
    $allowed_ips = explode(',', env('MAINTENANCE_BYPASS_IPS', ''));
    if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
        http_response_code(503);
        include dirname(__DIR__) . '/app/errors/maintenance.php';
        exit;
    }
}
```

`app/errors/maintenance.php` — a simple branded page:
- FleetForge logo
- "We'll be right back" message
- Estimated downtime (read from `maintenance.flag` contents if it contains a timestamp)
- "Retry" button

Enable: `touch storage/maintenance.flag`
Disable: `rm storage/maintenance.flag`
With ETA: `echo "2025-03-17 15:00 PST" > storage/maintenance.flag`

---

---

## 4.29 CUSTOM ERROR PAGES

Apache default 403/404/500 pages expose server information and look nothing like FleetForge. Override them:

In `public/.htaccess`:
```apache
ErrorDocument 400 /error.php?code=400
ErrorDocument 403 /error.php?code=403
ErrorDocument 404 /error.php?code=404
ErrorDocument 500 /error.php?code=500
ErrorDocument 503 /error.php?code=503
```

`public/error.php` — a minimal branded error page:
- FleetForge sidebar-style dark panel on left
- Error code and message centered
- Helpful action: "Go to Dashboard" button
- Does NOT expose file paths, line numbers, or stack traces
- In debug mode (`FF_DEBUG=true`): shows additional technical detail for developers only

Error messages per code:
- 400: "Bad Request — The request was invalid."
- 403: "Access Denied — You don't have permission to view this."
- 404: "Page Not Found — The page you're looking for doesn't exist or has been moved."
- 500: "Server Error — Something went wrong on our end. Please try again."
- 503: "Service Unavailable — FleetForge is temporarily down for maintenance."

---

---

## 4.30 REPORT SCHEDULING

Users can schedule any report to be automatically emailed on a recurring basis.

Stored in the `scheduled_reports` table — see `FLEETFORGE_DATABASE_MASTER.sql` for authoritative schema. Key fields: `user_id`, `report_type`, `parameters` (JSON), `frequency` (daily/weekly/monthly), `send_day`, `send_time`, `recipients` (JSON), `format` (pdf/csv/both), `is_active`, `last_sent_at`, `next_send_at`.

**`send_day` validation rules [PASS-1:M4]:**
- `frequency = 'daily'` → `send_day` MUST be NULL (runs every day, no specific day)
- `frequency = 'weekly'` → `send_day` = 1–7 (1=Monday, 7=Sunday)
- `frequency = 'monthly'` → `send_day` = 1–28 (avoids month-length issues; use 28 for end-of-month)
- API returns 422 if `send_day` is invalid for the selected frequency

The `notification_digest.php` cron checks this table daily and sends any due reports.

Example scheduled reports a user might set up:
- Weekly revenue summary every Monday 8 AM to owner
- Monthly AR aging report on the 1st to accountant
- Daily compliance alerts digest every morning to dispatcher

---

---

## 4.31 DATA IMPORT (CSV)

On fresh install, new SaaS clients often have existing data in spreadsheets. FleetForge supports CSV import for:
- Customers (company name, contact, email, phone, DOT, MC, etc.)
- Equipment Templates
- Equipment Units

Import UI lives in `app/admin/settings/import.php`. Process:
1. User downloads a CSV template with the expected columns and example row
2. User uploads their populated CSV
3. System validates: checks required columns, checks data types, checks for duplicates
4. Shows a preview table: "X rows will be imported, Y rows have errors"
5. Errors are highlighted with specific messages per row
6. User confirms — import runs, success count shown
7. All imported records tagged with `source = 'csv_import'` in internal_notes for traceability

### Import validation rules
- `company_name` is required for customers
- `unit_number` is required and must be unique for equipment units
- `template_id` in units must reference a valid template
- Invalid email formats are flagged but don't block import (email is optional)
- Duplicate records (same company+email for customers, same unit_number for units) are flagged — user chooses skip or overwrite

### Import limits
- Maximum 500 rows per CSV import
- Maximum 5MB file size for import CSV

---

---

## 4.32 ASSET VERSIONING & CACHE BUSTING

When `app.css` or `app.js` is updated, browsers that have the old version cached won't pick up changes. Solution: version query string appended to all asset URLs.

In `config/app.php`:
```php
define('FF_ASSET_VERSION', '1.4.0'); // increment on every deploy that changes CSS/JS
```

In `includes/header.php`:
```php
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= FF_ASSET_VERSION ?>">
```

In `includes/footer.php`:
```php
<script src="<?= base_url('assets/js/app.js') ?>?v=<?= FF_ASSET_VERSION ?>"></script>
```

Apache serves `app.css?v=1.4.0` as the same file but browsers treat it as a new URL and fetch fresh.
Increment `FF_ASSET_VERSION` in `config/app.php` any time CSS or JS changes are deployed.

---

---

## 4.33 WEBHOOK SECURITY & IDEMPOTENCY

**NOTE (v2.2):** Samsara webhooks are NOT in scope for this build. GPS integration is limited to two features only: the tracking URL button and the mileage API call at lease close (see Section 10). The `webhooks/samsara.php` handler, `gps_events` table, and idempotency logic described in earlier versions have been removed. The `webhooks/` folder stub can be retained for future use but no webhook handler is built.

General webhook security principles (for any future integrations):
- Always verify HMAC-SHA256 signatures before processing any payload
- Always return HTTP 200 immediately and process async to avoid provider timeouts
- Always use a unique constraint on provider event IDs to prevent double-processing

---

---

## 4.34 DOCUMENT ACCESS SECURITY

Documents stored in `storage/uploads/` are outside the webroot — Apache cannot serve them directly. They must be served through an authenticated PHP file download handler.

All document links in the UI point to: `api/v1/documents/serve.php?id={document_id}`

`api/v1/documents/serve.php`:
```php
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_auth(); // must be logged in

$doc_id = require_id('id');
$doc = db_row("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$doc_id]);

if (!$doc) json_error('NOT_FOUND', 'Document not found.', 404);

// Permission check: user must have access to the entity this doc belongs to
if (!user_can_access_document($doc)) {
    json_error('FORBIDDEN', 'Access denied.', 403);
}

// Serve the file
$path = dirname(__DIR__, 3) . '/storage/' . $doc['file_path'];
if (!file_exists($path)) json_error('NOT_FOUND', 'File not found.', 404);

header('Content-Type: ' . $doc['mime_type']);
header('Content-Disposition: inline; filename="' . basename($doc['file_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
```

This means:
- Only logged-in users can access documents
- Users can only access documents for entities they have permission to view
- Direct file system paths are never exposed to the browser

---

---

## 4.35 PERMISSION-GATED UI ELEMENTS

Role permissions affect not just what pages a user can access but what UI elements they see on each page. Never show a button for an action the user can't perform.

### Implementation pattern
In `includes/auth.php`:
```php
function can(string $module, string $action): bool {
    $user = current_user();
    if (!$user) return false;
    // Super admin can do everything
    if ($user['role_slug'] === 'super_admin') return true;
    return !empty($user['permissions'][$module][$action]);
}
```

In PHP pages:
```php
<?php if (can('leases', 'create')): ?>
    <a href="create.php" class="btn btn-primary">New Lease</a>
<?php endif; ?>

<?php if (can('leases', 'delete')): ?>
    <button class="btn btn-delete">Delete</button>
<?php endif; ?>
```

### Permission matrix (default roles)
| Module | super_admin | manager | dispatcher | accountant | read_only |
|---|---|---|---|---|---|
| customers | VCEDS | VCED | VC | V | V |
| equipment | VCEDS | VCED | VCE | V | V |
| leases | VCEDS | VCED | VCE | V | V |
| reservations | VCEDS | VCED | VCED | V | V |
| invoices | VCEDS | VCED | V | VCED | V |
| payments | VCEDS | VCED | — | VCED | V |
| rates | VCEDS | VCE | — | V | V |
| maintenance | VCEDS | VCED | VCE | V | V |
| compliance | VCEDS | VCED | VE | V | V |
| reports | VCEDS | VCE | — | VCE | V |
| analytics | VCEDS | VCE | — | V | V |
| users | VCEDS | V | — | — | — |
| settings | VCEDS | V | — | — | — |
| audit | VCEDS | V | — | V | V |

Key: V=view, C=create, E=edit, D=delete, S=settings/config
`—` = no access (menu item hidden, direct URL access returns 403)

---

---

## 4.36 USER AUTHENTICATION FLOWS

### Login Flow
1. User visits `app/auth/login.php`
2. Enters email + password
3. Server checks: user exists, password matches, account not locked/suspended
4. On success: create session, log login in `audit_log`, redirect to dashboard (or `redirect_after_login` if set)
5. On failure: increment `login_attempts` counter in cache, log attempt in `audit_log`
6. After 5 failures in 60 minutes: lock account for 15 minutes. Counter stored in `users.login_attempts` (DB column, not cache). [PASS-4:1.3] Log in `audit_log`, show "Account temporarily locked" message
7. "Remember me": if checked, set session cookie lifetime to 30 days

### Password Reset Flow
1. User clicks "Forgot Password" on login page
2. Enters email address
3. Server always responds: "If that email is registered, you'll receive a reset link" (no enumeration)
4. If email exists: generate a `token = bin2hex(random_bytes(32))`, store hashed in `users.invite_token` with expiry 1 hour, send reset email
5. User clicks link: `app/auth/reset.php?token=XXX`
6. Token verified (not expired, not used): show new password form
7. User sets new password: validate minimum 10 chars, hash and save, clear token, invalidate all other sessions, log in `audit_log`
8. Redirect to login with toast: "Password updated. Please sign in."

### User Invitation Flow
1. Admin goes to Users module, clicks "Invite User"
2. Enters name, email, role
3. Server creates `users` record with status=`invited`, generates invite token, sends invitation email
4. Email contains link: `app/auth/accept_invite.php?token=XXX`
5. New user sets their password
6. Account becomes `active`, token cleared, redirected to dashboard
7. Invites expire after 7 days — admin can resend

### Session Data Structure
```php
$_SESSION['ff_user'] = [
    'id'          => 12,
    'name'        => 'Avi',
    'email'       => 'avi@mainlandtruck.com',
    'role_id'     => 1,
    'role_slug'   => 'super_admin',
    'permissions' => [
        'customers'  => ['view'=>1,'create'=>1,'edit'=>1,'delete'=>1,'export'=>1],
        'leases'     => ['view'=>1,'create'=>1,'edit'=>1,'delete'=>1,'export'=>1],
        // ... all modules
    ],
    'theme'       => 'dark',  // synced from users table
    'login_at'    => '2025-03-17 14:23:11',
];
```

---

---

## 4.37 THEME PREFERENCE PERSISTENCE

Theme preference (dark/light) is stored in TWO places:
1. `localStorage` (instant, no server round-trip, prevents flash on page load)
2. `users.theme_preference` column in DB (persists across devices/browsers)

Add column to users table:
```sql
ALTER TABLE users ADD COLUMN theme_preference ENUM('dark','light') NOT NULL DEFAULT 'dark' AFTER status;
```

On theme toggle:
```javascript
Theme.toggle = function() {
    this.apply(this.current === 'dark' ? 'light' : 'dark', true);
    // Also save to server
    API.post('v1/users/save_preference.php', { theme: this.current }).catch(() => {});
};
```

On login: load user's `theme_preference` from DB into session, write to response header as cookie so PHP header knows before JS runs.

---

---

## 4.38 CHART DATA GRANULARITY RULES

Charts that show time-series data must auto-select the right granularity based on the selected date range. Never show 365 daily data points on a year chart — it becomes unreadable.

| Date range | Granularity | Max data points |
|---|---|---|
| 1–7 days | Daily | 7 |
| 8–90 days | Daily | 90 |
| 91–365 days | Weekly | 52 |
| 366–730 days | Monthly | 24 |
| 730+ days | Monthly | 24 (cap) |

SQL aggregation adjusts accordingly:
```php
function get_chart_granularity(string $start, string $end): array {
    $days = (new DateTime($start))->diff(new DateTime($end))->days;
    if ($days <= 90)  return ['format' => '%Y-%m-%d', 'label' => 'D MMM',   'unit' => 'day'];
    if ($days <= 365) return ['format' => '%Y-%u',    'label' => 'Www YYYY', 'unit' => 'week'];
    return              ['format' => '%Y-%m',        'label' => 'MMM YYYY', 'unit' => 'month'];
}
```

---

---

## 4.39 CONTENT SECURITY POLICY HEADERS

Add to `public/.htaccess`:
```apache
<IfModule mod_headers.c>
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://fonts.gstatic.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'" -- [PASS-1:L4] Mapbox removed (not in tech stack)
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(self)"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
</IfModule>
```

`'unsafe-inline'` is needed for Alpine.js and inline styles — acceptable for an internal admin tool. If this becomes a public-facing app, move all inline scripts to external files and remove `unsafe-inline`.

---

---

## 4.40 SOFT DELETE ENFORCEMENT

Every query that fetches records must exclude soft-deleted records. This is the most common source of bugs in systems with soft deletes. **Hard rule: every query on a soft-deletable table must include `AND {table}.deleted_at IS NULL`.**

Tables with soft deletes (15 — payments added [PASS-13:F2]):
`users`, `customers`, `customer_notes`, `equipment_templates`, `equipment_units`, `leases`, `damage_claims`, `invoices`, `maintenance_work_orders`, `documents`, `vendors`, `credit_notes`, `reservations`, `rate_cards`, `payments`

Tables WITHOUT soft deletes (hard immutable records):
`audit_log`, `equipment_status_log`, `lease_status_log`, `customer_rate_history`, `payment_allocations`, `inspection_photos`, `damage_claim_photos`

### Enforcement at DB layer
All `db_select()`, `db_row()`, `db_count()` helper calls on soft-deletable tables must include the condition. There is no magic "global filter" — it must be explicit in every query. This is intentional: explicit is safer than magic.

To prevent accidental omission, soft-deletable table names are listed in a constant in `includes/db.php`:
```php
const SOFT_DELETE_TABLES = [
    'users', 'customers', 'customer_notes',
    'equipment_templates', 'equipment_units', 'leases',
    'damage_claims', 'invoices', 'maintenance_work_orders',
    'documents', 'vendors', 'credit_notes',
    'reservations', 'rate_cards', 'payments', // [PASS-13:F2]
];
```

Code review rule: any query on a SOFT_DELETE_TABLE without `deleted_at IS NULL` is a bug and must be fixed before merging.

---

---

## 4.41 NUMBER & CURRENCY DISPLAY STANDARDS

Consistent formatting across every page, every module, every context.

| Value type | Display format | Example | Font |
|---|---|---|---|
| Currency | `$X,XXX.XX` | `$84,200.00` | DM Mono |
| Large currency | `$X.XXM` or `$XXXK` (compact) | `$1.2M` / `$842K` | DM Mono |
| Percentage | `XX.X%` | `69.3%` | DM Mono |
| Integer count | `X,XXX` | `1,247` | DM Mono |
| Mileage | `X,XXX mi` | `84,200 mi` | DM Mono |
| Days | `X days` | `47 days` | DM Mono |
| Decimal rate | `$X.XX/day` | `$145.00/day` | DM Mono |
| Mileage rate | `$X.XXXX/mi` | `$0.1500/mi` | DM Mono |
| Zero amounts | `$0.00` not blank | `$0.00` | DM Mono |
| Null/missing | `—` (em dash) not blank/0 | `—` | DM Mono |

All currency values in the DB are `DECIMAL(12,2)`. PHP formats for display using:
```php
function format_currency(mixed $amount, string $symbol = '$'): string {
    if ($amount === null) return '—';
    return $symbol . number_format((float)$amount, 2, '.', ',');
}
```

In JavaScript, all currency formatted via:
```javascript
FF.currency = (amount) => '$' + Number(amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
```

---

---

## 4.42 CUSTOMER PORTAL ROUTING

The customer portal is completely separate from the admin. Routing rules:

`public/index.php` checks the URL and routes accordingly:
```php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = '/fleetforge';

if (str_starts_with($path, $base . '/portal')) {
    // Portal routes — use portal auth, portal layout
    require dirname(__DIR__) . '/app/portal/' . resolve_portal_route($path);
} elseif (str_starts_with($path, $base . '/api')) {
    // API routes
    require dirname(__DIR__) . '/api/' . resolve_api_route($path);
} elseif (str_starts_with($path, $base . '/webhooks')) {
    // Webhook routes
    require dirname(__DIR__) . '/webhooks/' . resolve_webhook_route($path);
} else {
    // Admin routes
    require dirname(__DIR__) . '/app/admin/' . resolve_admin_route($path);
}
```

Portal URL structure: `yourdomain.com/fleetforge/portal/`
Admin URL structure: `yourdomain.com/fleetforge/`

Portal has its own:
- Login page (`app/portal/auth/login.php`)
- Session namespace (`$_SESSION['ff_portal_user']` not `ff_user`)
- Layout (no admin sidebar — customer-facing branded layout)
- Data access (only their own data — enforced at every API call with `portal_user.customer_id`)

---

---

## 4.43 EQUIPMENT QR CODES

Every equipment unit gets a unique QR code generated on creation (and regenerated on demand).

QR code encodes: `https://yourdomain.com/fleetforge/public/?unit={unit_number}&scan=1`

**Security [PASS-4:2.3]:** The unauthenticated scan view shows ONLY: unit number, generic "managed by [company]" message, and "Report Issue" button linking to portal login. Customer name, contract number, GPS location, and compliance details require authentication.

The `scan=1` parameter triggers a mobile-optimized quick-view showing:
- Unit number (large)
- Current status badge
- Current active lease (if any) — customer name and contract number
- Compliance status summary (green/yellow/red)
- Last GPS location
- "Report Issue" button → portal service request form
- "View Full Profile" button → admin unit profile (requires login)

QR code is generated using a PHP QR library (`lib/QR/QRGenerator.php`) and stored as PNG at `storage/generated/qrcodes/unit_{unit_number}.png`.

On the unit profile, a "QR Code" button opens a modal with:
- The QR code image (large, printable)
- Print button
- Download button (PNG)
- Instructions: "Print and attach to the physical unit"

---

*End of FleetForge Master Specification v1.4*
*Total: 40+ sections covering every aspect of the platform*
*Every Claude Code session starts with: "Read FLEETFORGE_SPEC.md before writing any code."*

*Total estimated build: 32-40 Claude Code sessions*
*Every session starts with: "Read FLEETFORGE_SPEC.md before writing any code."*

---

*End of FleetForge Master Specification v2.3 FINAL*
*59 core tables | 93 total with accounting | 20 phases | 28–30 Claude Code sessions | Every session reads this file first*
*Architect / Designer / Programmer: Claude Sonnet 4.6*
*Owner: Avi — Mainland Truck & Trailer Sales*
*59 core tables | 93 total with accounting | 20 phases | 28–30 Claude Code sessions | Every session reads this file first*
*Architect / Designer / Programmer: Claude Sonnet 4.6*
*Owner: Avi — Mainland Truck & Trailer Sales*
