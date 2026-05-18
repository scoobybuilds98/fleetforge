# FleetForge — Accountants Portal Specification

**Version:** 1.0
**Date:** 2026-05-18
**Status:** PRE-IMPLEMENTATION (Phase E not yet started)
**Owner:** Avi (Mainland Truck & Trailer Sales)
**Companion docs:** `FLEETFORGE_ACCOUNTING_SPEC.md` v1.3; `FLEETFORGE_QUICKBOOKS_SPEC.md` v1.0; `FLEETFORGE_SPEC_FINAL.md`; `FLEETFORGE_CLAUDE_CODE_REFERENCE.md`; `FLEETFORGE_PROGRESS.md`; `FLEETFORGE_CURRENT_SESSIONS.md`; `FLEETFORGE_DATABASE_MASTER.sql`
**Implementation arc:** Phase E sessions S-PORT-1 through S-PORT-6 (6 sessions).
**Built LAST** — after Phase A (integrity), Phase B (spec completion), Phase C (ASPE extensions), Phase D (lessor module), and Phase QBO (QuickBooks integration) all complete.

This is the canonical reference document for the FleetForge Accountants Portal. Every portal session reads this document first. Decisions locked here are normative — sessions that need to deviate must lock new D-PORT-* decisions and update this spec.

---

## TABLE OF CONTENTS

1. Why this exists
2. Architecture
3. Locked decisions
4. Authentication and invitation flow
5. Layout and navigation
6. Dashboard
7. Engagement file structure
8. PBC (Prepared by Client) list management
9. Workpaper annotations and tickmarks
10. Year-end package generator
11. Compilation engagement (CSRS 4200)
12. Review engagement (CSRE 2400)
13. Period locking
14. Read-only external CPA access mode
15. Integration with FleetForge accounting module
16. Integration with QuickBooks data
17. Schema additions
18. UI surfaces
19. Permissions and roles
20. Notifications
21. Settings keys
22. Open questions
23. Glossary
24. Changelog

---

## 1. WHY THIS EXISTS

### 1.1 The accountant's working environment today

Today (pre-portal), Mainland's external accountant works in QuickBooks Online. The accountant has full QBO access via Intuit Practitioner credentials and conducts year-end activities (compilation report preparation, T2 prep input, GST34 filing) within the QBO surface area plus their own Excel workpapers.

After Phase QBO (`FLEETFORGE_QUICKBOOKS_SPEC.md` integration) is live, the accountant's data flows from FleetForge to QBO automatically. Customers, vendors, invoices, payments, bills, JEs all originate in FF and mirror to QBO. The accountant continues to use QBO for bank reconciliation, GST34 filing via NETFILE, and payment processing via QBO Payments.

So far the accountant has not had to enter FleetForge at all. Phase E adds a reason to.

### 1.2 What the portal adds

The portal gives the accountant a **dedicated workspace** inside FleetForge for the workpaper-grade tasks that QBO doesn't do well or doesn't do at all:

- **Working trial balance v2** with lead schedule mapping (CaseWare-style) — already built in Phase C §23.2 of accounting spec.
- **Per-balance-sheet-account lead schedules** with annotations and tickmarks — Phase C §23.2.
- **CCA Schedule 8 continuity** and book-vs-tax temporary-difference reconciliation — Phase C §23.3 + §23.4.
- **PBC (Prepared by Client) checklist** — items the accountant needs from Avi before close.
- **Engagement file structure** organizing all workpapers for a fiscal year.
- **Adjusting journal entry workflow** with two-eyes review — Phase C §23.1.
- **Year-end close ceremony** with closing JEs and packaged ZIP — Phase B §22.2.
- **Compilation report templates** (CSRS 4200) — auto-populated from FF data.
- **Optional review engagement mode** (CSRE 2400) — for lender-required limited assurance work.
- **Period locking** that prevents posting to closed periods.

All of this presented in a **single accountant-focused surface** with no operational-side noise (no leases, no equipment, no mileage, no GPS — just the accounting work).

### 1.3 What problem the portal solves

Two problems:

1. **Workpaper quality.** QBO is a transactional system, not a workpaper system. The accountant has been doing CaseWare-style work in Excel. The portal brings that work into FleetForge with proper structure (lead schedules + tickmarks + annotations + period locking + immutable audit trail). This is the same shift the rest of FleetForge made years ago — bring the work into the system instead of leaving it in spreadsheets.

2. **Cross-system separation.** The accountant should be able to log in and see only what they need to see. The admin sidebar today (Customers, Equipment, Leases, Invoices, Mileage, Maintenance, etc.) is noise from their perspective. The portal is a focused environment — Dashboard, Engagements, WTB, Lead Schedules, AJEs, CCA, GST34, Period Lock, Year-End Close.

### 1.4 Internal vs external accountant

The default deployment serves Mainland's **internal accountant** (currently external to Mainland but using credentials Avi creates and manages — operationally treated as internal because of the workflow trust).

The portal also supports a **read-only external CPA access mode** (§14) for scenarios where a different practitioner (e.g., a lender-engaged CPA doing a review) needs to verify the books without write access. This is an optional mode reserved for future use.

### 1.5 Where this fits in the build sequence

Phase E (this portal) is **built last** because it surfaces and orchestrates features built in prior phases:

- Phase A (integrity fixes) must be complete — portal can't surface drift that hasn't been resolved.
- Phase B (S036 + S037) must be complete — portal exposes reports + year-end workflow.
- Phase C (ASPE extensions) must be complete — portal exposes AJE workflow, WTB v2, CCA, disclosure notes.
- Phase D (lessor module) must be complete — portal exposes lease classification sign-off, residual reviews, impairment tests.
- Phase QBO must be complete — portal surfaces QBO sync status as part of engagement workpapers (drift summary, reconciliation tie-out).

Phase E doesn't introduce new accounting features. It introduces a new **surface** that makes existing features usable by the accountant in their natural workflow.

---

## 2. ARCHITECTURE

### 2.1 Pattern A — mirror of the customer portal

The accountants portal follows **Pattern A**: a separate app surface with separate URL, separate auth namespace, separate layout — mirroring the customer portal pattern established in S024.

```
FleetForge URL structure (production)
─────────────────────────────────────────────────────────────────────

https://mainlandrentals.com/fleetforge/                  ← Admin app (Avi + staff)
                                  /auth/                 ← Admin auth
                                  /admin/                ← Admin pages
                                  /api/v1/               ← Internal API

https://mainlandrentals.com/fleetforge/portal/           ← Customer portal (S024)
                                       /auth/            ← Portal auth (separate)
                                       /invoices/        ← Portal invoice list
                                       /lease/           ← Portal lease detail
                                       (etc.)

https://mainlandrentals.com/fleetforge/accountant/       ← Accountants portal (Phase E)
                                       /auth/            ← Accountant auth (separate)
                                       /dashboard.php    ← Accountant dashboard
                                       /engagements/     ← Engagement files
                                       /wtb/             ← Working trial balance
                                       /aje/             ← Adjusting journal entries
                                       /lead-schedules/  ← Lead schedules
                                       /cca/             ← CCA Schedule 8
                                       /year-end/        ← Year-end close ceremony
                                       /pbc/             ← PBC list
                                       /period-lock/     ← Period locking
                                       (etc.)
```

Each surface has:
- Its own `bootstrap.php` (loads only the modules it needs).
- Its own session namespace (`accountant_session` is distinct from `admin_session` and `portal_session`).
- Its own login page and credential storage.
- Its own layout template (admin layout ≠ portal layout ≠ accountant layout).
- Its own permission model.

### 2.2 What the accountant CANNOT do via the portal

The portal is **accounting-focused**. The accountant has no operational write access. Specifically:

| Action | Available in portal? |
|---|---|
| View customers | Yes (read-only, with billing-relevant fields) |
| Create / edit customers | No (admin app only) |
| View leases | No (operational data — irrelevant to accounting work) |
| View equipment units | No |
| View mileage logs | No |
| View damage claims | No (but views the related JEs / invoices / bills) |
| View invoices | Yes (read-only, with full GL drill-down) |
| Create / edit invoices | No |
| View payments | Yes (read-only) |
| Record payments | No |
| View bills | Yes |
| Create / edit bills | Yes — this is one of the portal's primary writeable surfaces (the accountant enters vendor bills in FF now, replacing the QBO entry pattern per Phase QBO workflow shift §3.2) |
| Pay bills | Yes |
| Manual journal entries | Yes — primary AJE workflow |
| Year-end close ceremony | Yes |
| CCA continuity | Yes — view + edit operator-overridable fields |
| Lead schedule annotations | Yes |
| Tickmarks | Yes |
| Period lock / unlock | Yes (with super_admin secondary approval for unlock) |
| GST34 generation + filing prep | Yes |
| Disclosure note edits | Yes |
| Configure CoA | No (admin only) |
| Edit accounting settings | No (admin only) |
| View QBO sync status | Yes (read-only — same drift dashboard) |

The clear principle: **the accountant is given write access to anything they would otherwise do in Excel or in QBO; they're given read-only access to operational FleetForge data; they have no access to FleetForge configuration.**

### 2.3 What's shared between admin and accountant

The portal is a different **surface**, not a different **system**. The underlying accounting data is shared:

- Same `acc_journal_entries` table.
- Same `acc_accounts` table.
- Same `invoices`, `payments`, `bills` tables.
- Same `acc_periods`, `acc_year_end_closures` tables.
- Same FF GL.

When the accountant posts an AJE in the portal, it's written to the same `acc_journal_entries` table that admin sees. When the accountant locks a period in the portal, the period `status='closed'` is reflected everywhere. The portal isn't a sandbox — it's a curated view onto the live data.

### 2.4 Multi-user accountant support

The portal supports multiple accountant user accounts. Common scenario:

- **Lead practitioner** (CPA, CA designation). Has full portal access including period unlock initiation.
- **Staff accountant / bookkeeper**. Has portal access for routine work; cannot initiate period unlock.

Roles distinguish these (§19).

### 2.5 No file storage / new infrastructure

The portal does not introduce new file storage. It uses the existing `StorageClient` (D9: S3 in production, local in dev) and `acc_documents` table (wired in Phase A S-ACCT-FIX-DOCS). Workpapers and engagement documents attach via that mechanism.

### 2.6 Stateless and additive

Phase E adds new tables (`accountant_users`, `acct_engagements`, etc.) and new pages, but doesn't modify any existing accounting tables in ways that affect the admin app. Admin behavior is unchanged. The portal is purely additive.

---

## 3. LOCKED DECISIONS

These decisions are normative. Sessions that need to deviate must lock new D-PORT-* decisions and amend this spec.

### 3.1 D-PORT-CORE-* (architectural)

| ID | Decision |
|---|---|
| D-PORT-CORE-1 | Accountants portal follows Pattern A: separate URL (`/accountant`), separate auth namespace, separate layout — mirroring the customer portal pattern from S024. |
| D-PORT-CORE-2 | Phase E is built last (after Phase A, B, C, D, and Phase QBO all complete). |
| D-PORT-CORE-3 | The portal is read-only for operational FleetForge data (customers, leases, equipment, mileage, GPS, damage claims, maintenance). |
| D-PORT-CORE-4 | The portal is write-enabled for accounting-domain work: bills, bill payments, AJEs, lead schedule annotations, tickmarks, period locking, year-end close, CCA continuity, GST34 prep, disclosure note edits. |
| D-PORT-CORE-5 | Default engagement type is CSRS 4200 compilation. CSRE 2400 review mode is optional (per-engagement setting). |
| D-PORT-CORE-6 | Reporting framework is ASPE (locked in D-ARCH-5). Portal does not support IFRS. |
| D-PORT-CORE-7 | Period locking is bidirectional with the admin app: locking via portal closes the period everywhere; unlocking requires super_admin secondary approval. |
| D-PORT-CORE-8 | Accountant users are invited by super_admin; they cannot self-register. |
| D-PORT-CORE-9 | The portal is internal-users-only by default. External CPA read-only mode (§14) is reserved for future use; not built unless explicitly scheduled. |
| D-PORT-CORE-10 | Year-end package ZIP generation is portal-initiated but uses the shared `lib/Accounting/YearEndCloseService.php` built in Phase B. Portal doesn't duplicate logic. |
| D-PORT-CORE-11 | Engagement files are fiscal-year-scoped. One compilation engagement file per fiscal year; optional review engagement file alongside it if applicable. |
| D-PORT-CORE-12 | Tickmarks and annotations live in `acc_workpaper_annotations` (built in Phase C §23.2). Portal extends but doesn't duplicate. |

### 3.2 D-PORT-N-* per-session

Each Phase E session (S-PORT-1 through S-PORT-6) locks 3-8 D-PORT-N-* decisions. The decision log lives in `FLEETFORGE_PROGRESS.md`; this spec mirrors the portal-relevant subset as sessions complete.

---

## 4. AUTHENTICATION AND INVITATION FLOW

### 4.1 The accountant_users table

```sql
CREATE TABLE accountant_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NULL,
  designation ENUM('CPA','CA','CPA-CA','CGA','CMA','staff','bookkeeper','external_review') NOT NULL DEFAULT 'staff',
  firm_name VARCHAR(255) NULL,
  role ENUM('lead_practitioner','staff','external_readonly') NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mfa_secret VARCHAR(255) NULL,
  invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  invited_by INT UNSIGNED NOT NULL,
  invitation_token VARCHAR(64) NULL,
  invitation_token_expires_at DATETIME NULL,
  first_login_at DATETIME NULL,
  last_login_at DATETIME NULL,
  remember_token VARCHAR(100) NULL,
  email_disabled TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_acct_user_inviter FOREIGN KEY (invited_by) REFERENCES users(id),
  INDEX idx_active (is_active),
  INDEX idx_role (role)
);
```

Notes:

- `users` table is the admin user table; `accountant_users` is a separate parallel table (D-PORT-CORE-1: separate auth namespace).
- `email` UNIQUE across the accountant_users table only (an accountant's email can be different from any admin user; or could be the same string and there's no conflict because they're separate auth domains).
- `email_disabled` mirrors the S-PROD-2 SES bounce protection pattern (per D77).
- `mfa_enabled` and `mfa_secret` support TOTP-based MFA (using `pragmarx/google2fa` library; mirrors planned admin MFA implementation).

### 4.2 Invitation flow

Initiated by a super_admin in the admin app at `app/admin/users/accountants/index.php` (new page in S-PORT-1).

1. Super_admin clicks "Invite Accountant."
2. Form: email, first_name, last_name, designation, firm_name, role.
3. On submit:
   - INSERT into `accountant_users` with `password_hash=NULL`, `invitation_token=` random 64-byte hex, `invitation_token_expires_at=NOW() + 7 days` (D23).
   - Email sent via `Mailer::send()` to the accountant: subject "FleetForge Accountants Portal — You're invited," body containing the invitation URL.
   - Audit log row.
4. Accountant clicks invitation link: `https://mainlandrentals.com/fleetforge/accountant/auth/accept-invitation.php?token=<token>`.
5. FF validates token (matches `accountant_users.invitation_token` and not expired).
6. Accountant lands on a password-setup page. Enters new password (with strength rules: ≥12 chars, ≥1 number, ≥1 symbol). Optionally configures MFA via QR code.
7. On submit:
   - `password_hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`.
   - `invitation_token = NULL`, `invitation_token_expires_at = NULL`.
   - `first_login_at = NOW()`.
   - Session created.
   - Redirect to `/accountant/dashboard.php`.

### 4.3 Login

`/accountant/auth/login.php`:

- Email + password.
- Optional MFA code (if `mfa_enabled=1`).
- Brute-force protection: 5 failed attempts in 10 minutes → 15-minute lockout per IP + per email.
- Session created with `accountant_session` namespace.
- Session timeout: 8 hours of inactivity (vs admin's 4 hours — accountants often have long workpaper sessions).
- Remember-me token stored in `accountant_users.remember_token` (mirrors D29 pattern on admin users).

### 4.4 Password reset

`/accountant/auth/forgot-password.php`:

- Accountant enters email.
- FF generates a one-time reset token, stores in `accountant_users.invitation_token` (reuses the column with `invitation_token_expires_at=NOW() + 1 hour`).
- Email sent with reset link.
- Accountant clicks → password reset page → new password → session created.

### 4.5 Logout

Standard pattern: `accountant_session` destroyed, redirect to login page.

### 4.6 MFA enrollment

Self-service in `/accountant/account/security.php`:

- Show QR code (generated from `mfa_secret`).
- Accountant scans with Google Authenticator / Authy / 1Password.
- Accountant enters 6-digit verification code.
- On verify: `mfa_enabled = 1`.
- Backup codes generated (10 single-use codes; stored hashed).

### 4.7 Account deactivation

Super_admin in admin app can deactivate an accountant: `accountant_users.is_active = 0`. All sessions invalidated immediately (session middleware checks `is_active` on every request).

Deactivation reasons:
- Engagement ended.
- Accountant changed firms.
- Departure.
- Security concern (suspected credential compromise).

Deactivated accountants cannot log in. Their historical activity (AJEs, annotations, signed-off engagement files) is preserved with their name showing as "<Name> (deactivated)."

---

## 5. LAYOUT AND NAVIGATION

### 5.1 Layout template

New layout at `app/accountant/layout.php`. Mirrors the admin layout in structure but:

- Different color theme (default: neutral gray + navy accent — visually distinct from admin).
- Top bar: FleetForge logo, "Accountants Portal" label, accountant name + firm, environment badge (production/sandbox), current engagement selector.
- Left sidebar: accounting-focused nav (per §5.2).
- Main content area.
- Right sidebar: workpaper notes / quick-reference (collapsible).
- Footer: same as admin (version, support link, copyright).

### 5.2 Sidebar navigation

```
Dashboard
─────────────
Current Engagement
  Engagement Summary
  PBC Checklist
  Workpapers
  Audit Trail

Working Files
  Trial Balance (WTB)
  Lead Schedules
  Adjusting Journal Entries
  CCA Schedule 8
  Book-vs-Tax Reconciliation

Period Operations
  Period Status
  Period Lock / Unlock
  Year-End Close
  
Reports
  P&L
  Balance Sheet
  Cash Flow
  Trial Balance
  Asset Schedule
  AR Aging
  AP Aging
  Budget vs Actual
  
Tax
  GST34 Generator
  Tax Filings History
  Tax Detail Reports
  
Accounts Payable
  Bills (entry)
  Bill Payments
  Vendors

Disclosure
  Note Pack
  Related Party Transactions
  Subsequent Events
  
QuickBooks
  Sync Status
  Drift Dashboard
  Reconciliation Tie-Out

Engagements (history)
  All Past Engagements
  Annual Compilation
  Annual Review (if applicable)

Documents
  Workpaper Storage
  Year-End Packages
  Engagement Letters
  Reports Produced

Account
  My Profile
  Security (MFA, password)
  Notifications
  Sign Out
```

### 5.3 Current-engagement context

Most portal pages operate in the context of a **selected engagement**. The current engagement is shown prominently in the top bar:

`Current: 2026 Compilation Engagement — Active`

Selector dropdown lets the accountant switch between engagements (e.g., to review the 2025 engagement while working on 2026). The selection is sticky per session.

Pages that don't have engagement context (Account, QuickBooks sync status) operate independently.

### 5.4 Mobile responsiveness

The portal is desktop-first. Lead schedules, WTB, and CCA continuity are inherently wide tables that don't render usefully on phones. Mobile rendering: warning banner ("This portal is best viewed on desktop"); essential pages (login, notifications) work but workpaper pages don't render.

This is a deliberate scope choice — accountants do this work on laptops/desktops, not phones.

---

## 6. DASHBOARD

`/accountant/dashboard.php` — the landing page after login.

### 6.1 At-a-glance tiles

Top row of tiles showing current state:

| Tile | Content |
|---|---|
| Current Engagement | "2026 Compilation — In Progress (47% complete)" with engagement progress bar |
| PBC Items | "12 of 17 PBC items received" with link to PBC checklist |
| Open AJEs | "3 AJEs pending review" with link to AJE workflow |
| Period Status | "April 2026 — Open; May 2026 — Pending close" |
| AR / AP Drift | "AR ± $0.00 / AP ± $0.00 — all clean" or drift values if applicable |
| QBO Sync | "Sync healthy — 0 errors today / 1 minor drift" |

### 6.2 Activity feed

A scrolling activity stream showing recent events relevant to the accountant:

- Invoices generated.
- Payments received.
- Bills entered (by accountant or admin).
- AJEs posted.
- Documents attached.
- Period locks/unlocks.
- Disclosure note edits.
- Year-end close milestones.

Filterable by event type, date range, user.

### 6.3 Critical alerts panel

Right side of dashboard. Highlights items requiring attention:

- "AJE #JE-2026-00135 awaiting your approval (submitted by [staff name] 2 days ago)" — clickable.
- "Period March 2026 has 1 unposted JE — investigate before close."
- "Bank reconciliation pending in QBO for RBC Operating (CDC last pulled 2 days ago, expected daily)."
- "Compilation engagement letter draft ready for review."

Each alert has a "Resolve" or "Acknowledge" action.

### 6.4 Quick actions

Buttons for frequent operations:
- "Post New AJE" → AJE create form.
- "Enter New Bill" → bill create form.
- "Generate WTB" → WTB report.
- "Start Year-End Close" → year-end ceremony entry (only when fiscal year period allows).
- "Generate Year-End Package" → package builder.

---

## 7. ENGAGEMENT FILE STRUCTURE

### 7.1 The acct_engagements table

```sql
CREATE TABLE acct_engagements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year INT NOT NULL,
  engagement_type ENUM('compilation','review','other') NOT NULL DEFAULT 'compilation',
  status ENUM('draft','active','signed_off','archived') NOT NULL DEFAULT 'draft',
  lead_practitioner_id INT UNSIGNED NULL,
  engagement_letter_signed_at DATETIME NULL,
  engagement_letter_signed_by VARCHAR(255) NULL,
  fieldwork_started_at DATETIME NULL,
  fieldwork_completed_at DATETIME NULL,
  report_issued_at DATETIME NULL,
  report_document_id INT UNSIGNED NULL,
  
  -- Compilation-specific
  basis_of_accounting ENUM('aspe','aspe_modified','cash','other') NOT NULL DEFAULT 'aspe',
  
  -- Review-specific (NULL for compilation)
  materiality_amount DECIMAL(15,2) NULL,
  materiality_method VARCHAR(50) NULL,
  performance_materiality DECIMAL(15,2) NULL,
  trivial_threshold DECIMAL(15,2) NULL,
  
  -- Progress tracking
  pbc_items_total INT NOT NULL DEFAULT 0,
  pbc_items_received INT NOT NULL DEFAULT 0,
  aje_count INT NOT NULL DEFAULT 0,
  workpaper_count INT NOT NULL DEFAULT 0,
  
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  CONSTRAINT fk_engagement_lead FOREIGN KEY (lead_practitioner_id) REFERENCES accountant_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_engagement_report_doc FOREIGN KEY (report_document_id) REFERENCES acc_documents(id) ON DELETE SET NULL,
  UNIQUE KEY uk_year_type (fiscal_year, engagement_type)
);
```

One row per fiscal year per engagement type. Default: one compilation engagement per fiscal year. Optional: a review engagement alongside.

### 7.2 Engagement lifecycle

```
[DRAFT]
   │ engagement letter prepared
   │ accountant signs off
   ▼
[ACTIVE]
   │ PBC items requested + received
   │ fieldwork performed
   │ AJEs posted
   │ workpapers attached
   │ year-end close executed
   │ report drafted
   │ lead practitioner final review
   ▼
[SIGNED_OFF]
   │ retention period
   ▼
[ARCHIVED]
```

State transitions are user-driven and audited:

- `draft → active`: when accountant marks "Engagement letter signed; fieldwork begins."
- `active → signed_off`: when lead practitioner clicks "Sign Off Engagement" after report is issued.
- `signed_off → archived`: automatic after 7 years (CRA retention).

### 7.3 The engagement file UI

`/accountant/engagements/show.php?id=N` — the engagement file viewer.

Layout:

**Header:**
- Fiscal year + engagement type + status badge.
- Lead practitioner name.
- Key dates (engagement letter signed, fieldwork started/completed, report issued).
- Progress bar.

**Tabs:**

1. **Summary** — overview, basis of accounting, materiality (if review).
2. **PBC Checklist** — see §8.
3. **Workpapers** — categorized list of all workpapers (WTB, lead schedules, CCA, AJE listing, bank rec summaries, etc.).
4. **AJEs** — all AJEs for this fiscal year.
5. **Documents** — attached PDFs, scanned source documents, engagement letters, signed reports.
6. **Audit Trail** — chronological log of every action on this engagement.
7. **Sign-Off** — final approval checkboxes + lead practitioner signature.

### 7.4 Engagement letter

The portal includes a templated engagement letter generator at `/accountant/engagements/letter.php?id=N`:

- Pre-populated with Mainland's name, address, fiscal year, engagement type, basis of accounting.
- Standard CSRS 4200 boilerplate (for compilation) or CSRE 2400 (for review).
- Lead practitioner name + firm + designation.
- Engagement scope language (what's included, what's excluded).
- Fee arrangement section (left blank or pre-filled if standardized).
- Signatures area (electronic signature support via DocuSign-like flow — out of MVP scope; for MVP, generated as PDF and signed externally).

Generated as a PDF via mPDF, saved to `acc_documents` linked to the engagement.

### 7.5 Workpaper organization

Workpapers are organized by lead schedule code (CaseWare-aligned per §23.2 of accounting spec):

```
A-100 Cash and equivalents
├── A-100.1 RBC Operating bank reconciliation summary
├── A-100.2 RBC Savings bank reconciliation summary
└── A-100.3 Petty cash count

B-100 Accounts Receivable
├── B-100.1 AR aging summary
├── B-100.2 AR confirmation responses (if review)
└── B-100.3 Allowance for doubtful accounts analysis

E-100 PP&E continuity
├── E-100.1 Fleet additions schedule
├── E-100.2 Fleet disposals schedule
└── E-100.3 Componentization analysis

E-200 CCA continuity
└── E-200.1 Schedule 8 export

AA-100 AP Trade
├── AA-100.1 AP aging summary
└── AA-100.2 Vendor confirmations (if applicable)

CC-100 Sales tax reconciliation
├── CC-100.1 GST34 generator output
├── CC-100.2 PST reconciliation
└── CC-100.3 ITC documentation summary
```

Each workpaper has tickmarks and annotations attached via `acc_workpaper_annotations` (per §23.2 of accounting spec).

---

## 8. PBC (PREPARED BY CLIENT) LIST MANAGEMENT

### 8.1 What it is

PBC = Prepared by Client. The list of items the accountant needs from Avi before/during fieldwork. Each item has a description, requested date, due date, status (pending/received), and attached documents.

### 8.2 The acct_pbc_items table

```sql
CREATE TABLE acct_pbc_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  engagement_id INT UNSIGNED NOT NULL,
  category VARCHAR(100) NOT NULL,
  item_description TEXT NOT NULL,
  requested_at DATETIME NULL,
  due_date DATE NULL,
  status ENUM('pending','requested','received','not_applicable','waived') NOT NULL DEFAULT 'pending',
  received_at DATETIME NULL,
  received_by INT UNSIGNED NULL,
  document_ids JSON NULL,
  notes TEXT NULL,
  is_critical TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pbc_engagement FOREIGN KEY (engagement_id) REFERENCES acct_engagements(id) ON DELETE CASCADE,
  CONSTRAINT fk_pbc_receiver FOREIGN KEY (received_by) REFERENCES accountant_users(id) ON DELETE SET NULL,
  INDEX idx_engagement_status (engagement_id, status)
);
```

### 8.3 Default PBC template

When a new engagement is created, default PBC items are auto-populated:

| Category | Item |
|---|---|
| General | Trial balance as of fiscal year-end |
| General | General ledger detail for the year |
| General | Bank statements for all bank accounts (12 months) |
| General | Bank reconciliations as of year-end |
| Sales | Revenue summary by month |
| Sales | Top 10 customers + balances |
| Sales | AR aging at year-end |
| Sales | Customer confirmations sent / received (review only) |
| Purchases | AP aging at year-end |
| Purchases | Outstanding bills as of year-end |
| Payroll | Payroll summary (if applicable) |
| Payroll | T4 summary (if applicable) |
| PP&E | List of asset additions during the year + invoices |
| PP&E | List of asset disposals + proceeds + cost details |
| PP&E | Depreciation schedule (CCA + book) |
| Tax | GST/HST/PST returns filed during the year |
| Tax | Notice of Assessment (prior year T2) |
| Leases | Lease classifications + amortization schedules (if any sales-type / direct financing) |
| Leases | Operating lease commitments waterfall |
| Insurance | Insurance policy documents |
| Insurance | Insurance claim history (if any) |
| Legal | Legal correspondence / outstanding claims |
| Subsequent Events | Significant events after year-end |
| Related Parties | Related-party transaction summary |
| Sign-Off | Management representation letter (signed) |

Operator (Avi) can add custom items, mark items not applicable, or waive optional ones.

### 8.4 PBC checklist UI

`/accountant/engagements/pbc/index.php?engagement_id=N`:

- Categorized list of all PBC items.
- Status badges + filter.
- Per-item: "Mark Received" with document upload, "Mark Not Applicable" with note, "Mark Waived" with note.
- Bulk operations: "Send Reminder Email" to Avi for all pending items.
- Progress bar: 12/17 received → 70% complete.

### 8.5 Reminder workflow

Pending PBC items past their due date trigger a notification:

- To Avi (admin): "PBC item '<description>' is X days overdue. Please upload the document."
- To accountant: "PBC item '<description>' is X days overdue. Awaiting response from Avi."

Reminders dispatched by `cron/acct_pbc_reminders.php` (new cron in S-PORT-4).

---

## 9. WORKPAPER ANNOTATIONS AND TICKMARKS

The annotations layer (built in Phase C §23.2) is exposed through the portal as the accountant's main interaction with workpapers.

### 9.1 Tickmark legend (locked in S-ACCT-WTB)

| Tickmark | Meaning |
|---|---|
| A | Agreed to source document |
| B | Balance confirmed |
| T | Traced to GL |
| V | Vouched to support |
| F | Footed (math verified) |
| ✓ | Reviewed and accepted |
| ⊥ | Cross-referenced |

Tickmarks are applied per cell in WTB / lead schedules / per-line in CCA continuity / etc. Each tickmark links to an optional note explaining the work performed.

### 9.2 Tickmark application UI

On any workpaper page (e.g., `/accountant/wtb/index.php`):

- Each cell or line has a small "Add Tickmark" icon.
- Click → modal with tickmark dropdown + note textarea.
- On save: row inserted into `acc_workpaper_annotations`.
- Visual indicator: cell now shows the tickmark letter + hover-tooltip with note.

### 9.3 Annotation types

Per `acc_workpaper_annotations` schema:

- `workpaper_type`: 'trial_balance' | 'lead_schedule' | 'report'
- `workpaper_ref`: identifier of the specific workpaper (e.g., 'A-100' for lead schedule A-100, or 'WTB-2026' for the working trial balance for 2026)
- `period_id`: the period being reviewed
- `account_id`: optional, for per-account annotations
- `tickmark`: optional, for tickmark applications
- `note`: free-form text

### 9.4 Annotation visibility and audit

- Annotations are visible to all accountant_users with access to the engagement.
- Annotations cannot be deleted, only added (immutability is a CRA defensibility property).
- Annotation history: every annotation has `created_by` and `created_at`; older annotations remain visible even if accountant_user later deactivated.
- Bulk annotation reports: "Show all tickmarks for engagement N" produces a workpaper audit summary.

### 9.5 Crossover with AJEs

When an AJE is posted, the accountant can optionally link it to a workpaper annotation:

- AJE form: "Optional — link to workpaper annotation."
- Dropdown of recent unresolved annotations.
- On post: annotation `note` updated with `[Resolved by AJE-{N}]`; AJE description gets `[Per workpaper A-100 annotation #X]`.

This creates a cross-reference trail valuable for CRA defensibility ("the AJE was posted because the workpaper identified a discrepancy").

---

## 10. YEAR-END PACKAGE GENERATOR

### 10.1 What it generates

A ZIP file containing all year-end workpapers, ready for the lead practitioner to review or for archival.

Contents (auto-assembled at year-end close per §22.2 of accounting spec):

```
year_end_package_2026.zip
├── 00_engagement_summary.pdf
├── 01_working_trial_balance.xlsx
├── 01_working_trial_balance.pdf
├── 02_lead_schedules/
│   ├── A-100_cash_and_equivalents.pdf
│   ├── B-100_accounts_receivable.pdf
│   ├── B-110_ar_aging_detail.pdf
│   ├── (etc. per §23.2 of accounting spec)
├── 03_general_ledger_detail.pdf
├── 04_ppe_continuity.pdf
├── 04_cca_schedule_8.csv
├── 04_cca_schedule_8.pdf
├── 05_gst_hst_pst_reconciliation.pdf
├── 06_ar_aging_year_end.pdf
├── 07_ap_aging_year_end.pdf
├── 08_bank_reconciliation_summaries.pdf
├── 09_fx_revaluation_summary.pdf
├── 10_lease_amortization_schedules.pdf (if any capital leases)
├── 11_disclosure_note_pack.pdf
├── 12_compilation_report_draft.pdf
├── 13_management_representation_letter.pdf
├── 14_engagement_letter_signed.pdf
└── manifest.json  (contains SHA-256 hash of every file)
```

### 10.2 Generator workflow

`/accountant/year-end/package.php?fiscal_year=YYYY`:

1. Pre-flight check (same as year-end close ceremony per §22.2 of accounting spec):
   - All 12 periods status `open` or `closed`.
   - AR drift ≤ $1.
   - AP drift ≤ $1.
   - No unposted JEs.
   - QBO drift = 0 on critical entities.
   - PBC items all received or marked not-applicable.
2. Click "Generate Package."
3. Async job (`cron/acct_year_end_package.php` worker) runs:
   - Generates each report as PDF/Excel/CSV.
   - Writes to `/var/www/fleetforge/storage/year_end_packages/YYYY/`.
   - ZIPs with `zip -r` shell command.
   - Computes SHA-256 of each file → manifest.json.
   - Uploads ZIP to S3 (storage tier per D9) for off-server retention.
   - Writes `acct_year_end_packages` row.
4. Notification dispatched to accountant: "Year-end package for 2026 ready. Download link valid for 30 days."

### 10.3 Package storage and retention

```sql
CREATE TABLE acct_year_end_packages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  engagement_id INT UNSIGNED NOT NULL,
  fiscal_year INT NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_by INT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_size_bytes BIGINT NOT NULL,
  manifest_hash VARCHAR(64) NOT NULL,
  download_count INT NOT NULL DEFAULT 0,
  expires_at DATETIME NULL,
  CONSTRAINT fk_yep_engagement FOREIGN KEY (engagement_id) REFERENCES acct_engagements(id),
  CONSTRAINT fk_yep_user FOREIGN KEY (generated_by) REFERENCES accountant_users(id),
  UNIQUE KEY uk_year (fiscal_year)
);
```

- Stored in S3 for 7 years (CRA retention).
- Download link signed and valid for 30 days from generation.
- Re-generation possible if year-end data changes (rare — would require period unlock); writes a new row with version suffix.

---

## 11. COMPILATION ENGAGEMENT (CSRS 4200)

### 11.1 What CSRS 4200 requires

CSRS 4200 is the Canadian standard for compilation engagements. A compilation engagement is **not assurance** — the practitioner does not express an opinion or conclusion on the financial statements. The practitioner compiles management's information into the form of financial statements.

Key requirements:
- Engagement letter signed by both parties.
- Compilation report (separate from financial statements) accompanying the financial information.
- Disclosure of basis of accounting (typically ASPE).
- Disclosure that no assurance is provided.
- Distinction between management's responsibility and practitioner's role.

### 11.2 Compilation report template

The portal generates a CSRS 4200-compliant compilation report:

```
COMPILATION ENGAGEMENT REPORT

To the Management of Mainland Truck & Trailer Sales

On the basis of information provided by management, we have compiled the
balance sheet of Mainland Truck & Trailer Sales as at [year-end date],
and the statement of income and statement of cash flows for the year then
ended, and Note 1, which describes the basis of accounting applied in the
preparation of the compiled financial information ("financial information").

Management is responsible for the accompanying financial information,
including the accuracy and completeness of the underlying information used
to compile it and the selection of the basis of accounting.

We performed this engagement in accordance with Canadian Standard on Related
Services (CSRS) 4200, Compilation Engagements, which requires us to comply
with relevant ethical requirements. Our responsibility is to assist
management in the preparation of the financial information.

We did not perform an audit engagement or a review engagement, nor were
we required to perform procedures to verify the accuracy or completeness
of the information provided by management. Accordingly, we do not express
an audit opinion or a review conclusion, or provide any form of assurance
on the financial information.

Readers are cautioned that the financial information may not be appropriate
for their purposes.

[Practitioner firm name]
[Practitioner designation]
[City, Province]
[Date of report]
```

### 11.3 Auto-population

The template at `/accountant/engagements/compilation-report.php?id=N` auto-populates:

- Mainland's legal name.
- Fiscal year-end date.
- Basis of accounting (ASPE per engagement setting).
- Practitioner firm name + designation + city (from `accountant_users`).
- Date of report (current date when finalized).

Practitioner can edit any field before finalizing.

### 11.4 Note 1 — basis of accounting

Auto-generated note describing the basis:

```
Note 1 — Basis of Accounting

The financial information has been prepared in accordance with
Accounting Standards for Private Enterprises (ASPE) per CPA Canada
Handbook Part II, with the following accounting policies:

[List of significant accounting policies per §23.9 disclosure builder]
```

### 11.5 Finalization

When the compilation report is finalized:
- PDF generated via mPDF.
- Stored in `acc_documents` linked to the engagement.
- Engagement status → `signed_off`.
- Audit log row.
- Period(s) corresponding to the fiscal year are locked (if not already).

---

## 12. REVIEW ENGAGEMENT (CSRE 2400)

### 12.1 When this applies

Optional mode for engagements requiring **limited assurance** (typically lender-required). CSRE 2400 is the Canadian standard for review engagements.

Key differences from compilation:
- The practitioner does perform procedures (analytical procedures + inquiries) to obtain limited assurance.
- A conclusion is expressed.
- Materiality must be determined and documented.
- The practitioner reports on whether anything came to their attention causing them to believe the financial information is not prepared, in all material respects, in accordance with the basis of accounting.

### 12.2 Materiality calculator

`/accountant/engagements/materiality.php?id=N` (review mode only):

- Method selection: Income before tax | Revenue | Total assets | Equity | Other (with explanation).
- Benchmark amount: auto-populated from FF reports.
- Percentage applied: configurable (default 5% of income before tax for benchmark, 1% of revenue for low-margin businesses).
- Performance materiality: typically 50-75% of overall materiality.
- Trivial threshold: typically 5% of performance materiality.

On save: materiality values stored in `acct_engagements.materiality_amount`, `performance_materiality`, `trivial_threshold`.

### 12.3 Analytical procedures workpaper

`/accountant/engagements/analytical/index.php?id=N` (review mode only):

Auto-populated procedures based on FF data:

- Revenue trend (current year vs prior year, by month).
- Gross margin analysis (current vs prior).
- Operating expense ratios.
- AR DSO trend.
- AP DPO trend.
- Inventory turnover (if applicable).
- Customer concentration.
- Per-unit profitability outliers (§23.10 of accounting spec).
- Cash flow analysis.

Each procedure has an "Expectation" field (practitioner's expected result), "Actual" (auto-populated from FF), "Variance" (computed), and "Explanation Required?" flag (when variance exceeds threshold).

### 12.4 Inquiries log

Practitioner records inquiries made of management:

- Inquiry topic.
- Date.
- Person inquired (Avi, accountant, etc.).
- Question asked.
- Response received.
- Practitioner's conclusion.

Logged in `acct_inquiries` (new table in S-PORT-5).

### 12.5 Review report template

When review engagement is finalized, the report uses CSRE 2400 language:

```
INDEPENDENT PRACTITIONER'S REVIEW ENGAGEMENT REPORT

To the Management of Mainland Truck & Trailer Sales

We have reviewed the accompanying financial statements of Mainland Truck
& Trailer Sales, which comprise the balance sheet as at [year-end date],
and the statements of income, retained earnings, and cash flows for the
year then ended, and a summary of significant accounting policies and
other explanatory information.

Management's Responsibility for the Financial Statements
[Standard paragraph]

Practitioner's Responsibility
Our responsibility is to express a conclusion on the financial statements
based on our review. We conducted our review in accordance with Canadian
generally accepted standards for review engagements, which require us to
comply with relevant ethical requirements.

[Procedures performed paragraph]

A review is substantially less in scope than an audit and consequently does
not enable us to obtain assurance that we would become aware of all
significant matters that might be identified in an audit. Accordingly, we
do not express an audit opinion on these financial statements.

Conclusion
Based on our review, nothing has come to our attention that causes us to
believe that these financial statements do not present fairly, in all
material respects, the financial position of Mainland Truck & Trailer
Sales as at [year-end date], and its financial performance and its cash
flows for the year then ended in accordance with Accounting Standards for
Private Enterprises.

[Practitioner firm name]
[Practitioner designation]
[City, Province]
[Date of report]
```

### 12.6 Review mode toggle

Per-engagement setting `engagement_type` (compilation | review | other) determines which workflow is active. Default: compilation. Switching to review activates the materiality, analytical procedures, and inquiries surfaces.

---

## 13. PERIOD LOCKING

### 13.1 What it does

Locking a period prevents any further JE posting to that period — admin or accountant. This is the operational mechanism that protects financial statements from after-the-fact tampering.

### 13.2 Lock workflow

`/accountant/period-lock/index.php`:

- List of periods with status (open / closed).
- "Lock Period" button next to each open period.
- Pre-flight checks before lock:
  - All draft JEs for the period must be posted, reversed, or moved to next period.
  - AR/AP drift within tolerance.
  - Bank reconciliations marked complete.
- On lock: `acc_periods.status = 'closed'`, audit log row.
- Period now closed everywhere (admin app respects same status).

### 13.3 Unlock workflow

Unlocking is restricted because it's a sensitive operation (allows post-hoc edits).

- Accountant clicks "Request Unlock" with reason.
- Notification sent to super_admin: "[Accountant] is requesting unlock of period [N]. Reason: [X]. Approve / Deny."
- Super_admin reviews + approves or denies.
- On approval: `acc_periods.status = 'open'`, both users' actions logged.

This is the **two-person rule** for period unlocking — prevents either party from solo unlocks.

### 13.4 Locked periods and downstream effects

When a period is locked:
- AutoEntryBridge calls that would post to that period **fail** with explicit error.
- Operator/accountant must either: (a) request unlock + post, or (b) post to the current open period with a back-dated description.
- AJEs intended for the locked period must be approved via the unlock workflow.
- QBO sync of new JEs into closed QBO periods is also disabled (QBO has its own period close that mirrors).

### 13.5 Year-end close vs period lock

These are related but distinct:

- **Period lock**: locks a single month. Routine; happens after every month-end as part of standard month-close.
- **Year-end close**: §22.2 of accounting spec. Comprehensive ceremony at fiscal year-end. Includes period lock of all 12 months, closing JEs, new fiscal year periods, year-end package.

The portal supports both flows. Period lock is a quick action; year-end close is a multi-hour ceremony.

---

## 14. READ-ONLY EXTERNAL CPA ACCESS MODE

### 14.1 When this applies

Reserved for scenarios where an **external CPA practitioner** (not Mainland's regular accountant) needs to verify the books. Common scenarios:

- Lender-engaged review for loan covenant testing.
- Acquisition due diligence.
- CRA audit support (with explicit Avi approval).

This is an **optional mode** — not built in MVP unless explicitly scheduled.

### 14.2 The external_readonly role

`accountant_users.role = 'external_readonly'`:

- Full read access to all engagement files for the period(s) granted.
- No write access (cannot post AJEs, cannot annotate, cannot lock periods).
- No access to operational data (same restriction as accountant role).
- Limited time access (expiration date set on invitation).
- Activity heavily logged (every page view, every download).

### 14.3 Invitation flow

Super_admin invites with extra scope:

- Email + name.
- Firm name (mandatory).
- Engagement period(s) — which fiscal years can they view?
- Access expiration date (default 90 days).

The external CPA receives an invitation link, sets password + MFA, and logs in. Their dashboard shows only the engagements they're authorized for.

### 14.4 Activity monitoring

`acct_external_access_log` (new table):

```sql
CREATE TABLE acct_external_access_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  action ENUM('login','view_page','download_file','generate_report','export_data','logout') NOT NULL,
  resource VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_external_access_user FOREIGN KEY (user_id) REFERENCES accountant_users(id),
  INDEX idx_user_time (user_id, occurred_at)
);
```

Super_admin can view the access log at any time. Excessive access patterns (e.g., bulk downloads) trigger notifications.

### 14.5 Access expiration

When `accountant_users.is_active = 1` AND user has `role='external_readonly'` AND today exceeds an expiration date stored in `accountant_users.notes` (parsed) or in a separate scope field: user is auto-deactivated.

Cron `cron/acct_external_expiration_check.php` runs daily.

---

## 15. INTEGRATION WITH FLEETFORGE ACCOUNTING MODULE

The portal is a surface on the existing accounting module. Integration touchpoints:

### 15.1 Shared tables

All tables from the accounting module (`acc_accounts`, `acc_journal_entries`, `acc_periods`, `acc_year_end_closures`, `acc_workpaper_annotations`, `acc_cca_continuity`, `acc_fixed_assets`, etc.) are shared between admin and portal.

### 15.2 Shared services

The portal calls existing service classes:

- `JournalEntryService` for AJE create / post / reverse.
- `AccountingService` for COA + settings.
- `BankService` for bank reconciliation views.
- `FixedAssetService` for asset + CCA workflows.
- `TaxFilingService` for GST34 generation.
- `YearEndCloseService` (built in Phase B §22.2) for year-end ceremony.
- `DisclosureNoteService` (built in Phase C §23.9) for note pack generation.

The portal doesn't duplicate logic — it presents the same logic with a different UI surface.

### 15.3 Bridge between AJE workflow and admin

When the accountant posts an AJE through the portal:

1. The AJE is created in `acc_journal_entries` with `entry_status='posted'` (after the two-eyes review per §23.1 of accounting spec).
2. It's visible in both portal and admin app immediately.
3. AutoEntryBridge does NOT fire for adjusting entries — they're already complete entries.
4. QBO sync fires per §8.10 of QBO spec (the JE pushes to QBO).

### 15.4 Period lock coordination

Period lock initiated via portal is reflected in admin app immediately. Admin app trying to post to a locked period via AutoEntryBridge fails with error: "Period [N] is locked. Post to current period or request unlock."

---

## 16. INTEGRATION WITH QUICKBOOKS DATA

The portal surfaces QBO data alongside FF data for the accountant's tie-out workflow.

### 16.1 QBO sync status page

`/accountant/quickbooks/sync-status.php`:

- Read-only view of `acc_qbo_sync_queue` summary (queued / processing / failed counts).
- Last successful push timestamps per entity type.
- Connection status.

This is the same data shown in the admin app's `/admin/quickbooks/dashboard.php` but presented as a workpaper-context view.

### 16.2 Drift dashboard (portal view)

`/accountant/quickbooks/drift.php`:

- Same drift events shown in admin app.
- Accountant can resolve drift via FF action (re-sync) — limited writes (not unrestricted admin actions).
- Accountant can mark drift as "accepted divergence" with notes.

### 16.3 Reconciliation tie-out workpaper

`/accountant/quickbooks/reconciliation.php` — a workpaper specifically for the year-end engagement:

- FF GL balance per account vs QBO balance per account.
- Variance (within tolerance / outside tolerance).
- Tickmark column.
- Annotation column.
- Auto-populates from QBO via `QuickBooksClient::getEntity('account', ...)`.

This serves as the "FF reconciles to QBO" workpaper for the engagement file.

### 16.4 Workflow shift recap

The accountant's daily workflow (per §3.2 of QBO spec):

- Customer entry → FleetForge admin (Avi or accountant via portal).
- Vendor entry → FleetForge (portal).
- Bill entry → FleetForge (portal — primary writeable surface).
- AJE → FleetForge (portal).
- Bank reconciliation → QBO (unchanged).
- GST34 filing → QBO NETFILE (after FF generates the GST34 line-by-line breakdown).
- Year-end → FleetForge (portal — primary writeable surface).

---

## 17. SCHEMA ADDITIONS

Consolidated list of new tables introduced in Phase E.

### 17.1 New tables

| Table | Created in | Purpose |
|---|---|---|
| `accountant_users` | S-PORT-1 | Portal user accounts |
| `acct_engagements` | S-PORT-3 | Per-fiscal-year engagement files |
| `acct_pbc_items` | S-PORT-4 | PBC checklist items per engagement |
| `acct_year_end_packages` | S-PORT-3 | Generated year-end ZIP package records |
| `acct_inquiries` | S-PORT-5 | Review engagement inquiry log |
| `acct_external_access_log` | S-PORT-6 | External CPA activity log |

Total new tables in Phase E: 6.

### 17.2 Tables extended (not modified, just queried by portal)

The portal queries existing tables but doesn't alter them:

- `acc_journal_entries` (queried by AJE views; written via JournalEntryService)
- `acc_workpaper_annotations` (queried + written)
- `acc_periods` (queried + updated for lock/unlock)
- `acc_year_end_closures` (queried + written via YearEndCloseService)
- `acc_documents` (queried + written for workpaper attachments)
- `acc_disclosure_notes` (queried + edited)
- `acc_cca_continuity` (queried + edited)
- `acc_qbo_sync_log` + `acc_qbo_drift_events` (queried)
- `customers`, `vendors`, `invoices`, `payments`, `bills` (queried read-only)

### 17.3 Total Phase E schema impact

6 new tables, 0 modifications to existing tables. Phase E is purely additive at the schema level (D-PORT-CORE-12).

---

## 18. UI SURFACES

### 18.1 Page inventory

Approximate page count by section:

| Section | Pages |
|---|---|
| Auth | 5 (login, logout, accept-invitation, forgot-password, reset-password) |
| Dashboard | 1 |
| Engagements | 5 (index, show, create, letter, sign-off) |
| PBC | 2 (list, item-detail) |
| Workpapers | 4 (WTB, lead schedules index + show, tickmark log) |
| AJE | 4 (index, create, show, approve/post) |
| CCA | 3 (continuity, edit, export) |
| Period Lock | 2 (status, request-unlock) |
| Year-End Close | 3 (pre-flight, run, package) |
| Reports | 8 (P&L, BS, CF, TB, Asset Schedule, AR Aging, AP Aging, Budget Variance) |
| Tax | 3 (GST34, filings history, tax detail) |
| AP | 3 (bills index + create, payments index + create, vendors index) |
| Disclosure | 3 (note pack, related parties, subsequent events) |
| QuickBooks | 3 (sync status, drift, reconciliation tie-out) |
| Account | 3 (profile, security, notifications) |
| **Total** | **~52 pages** |

### 18.2 Consistent UI patterns

- Top bar always shows current engagement context.
- Sidebar always shows current section highlighted.
- Workpaper pages have consistent tickmark + annotation icons in margin.
- Tables sortable, filterable, paginatable consistently.
- Action confirmation modals for destructive operations (delete, void, unlock).
- Audit log link at bottom of every entity show-page.

### 18.3 Loading states and progressive rendering

Reports and workpaper pages with heavy queries (full-year WTB, full GL detail) show skeleton loaders and progressively render. Server-side rendering for the basic structure; AJAX for the data.

---

## 19. PERMISSIONS AND ROLES

### 19.1 Portal-specific roles

Within the portal, three roles:

| Role | Capabilities |
|---|---|
| `lead_practitioner` | Full portal access. Can sign off engagements, initiate period unlock requests, finalize reports |
| `staff` | Full portal access except: cannot sign off engagements, cannot initiate period unlock |
| `external_readonly` | View-only. No writes. Limited to assigned engagements |

### 19.2 Cross-app permissions

| Action | super_admin (admin) | accountant (lead_practitioner) | accountant (staff) | accountant (external_readonly) |
|---|---|---|---|---|
| View admin pages | ✅ | ❌ | ❌ | ❌ |
| View portal pages | ✅ (with switch UI) | ✅ | ✅ | ✅ (limited) |
| Post AJEs | ✅ | ✅ | ✅ (subject to two-eyes review) | ❌ |
| Approve AJEs | ✅ | ✅ | ✅ (if not the drafter) | ❌ |
| Lock period | ✅ | ✅ | ❌ | ❌ |
| Unlock period (initiate) | ✅ | ✅ | ❌ | ❌ |
| Unlock period (approve) | ✅ (acts as super_admin) | ❌ | ❌ | ❌ |
| Sign off engagement | ✅ | ✅ | ❌ | ❌ |
| Invite new accountant_user | ✅ | ❌ | ❌ | ❌ |
| Deactivate accountant_user | ✅ | ❌ | ❌ | ❌ |

### 19.3 Module key

New module key `accountants_portal`. Required for any accountant_users login. Granted by default to all roles in accountant_users (the role distinction is internal to the module).

---

## 20. NOTIFICATIONS

### 20.1 Notification category

`accountants_portal` — new category. Default recipients:

- Lead practitioner: all severities for their engagements.
- Staff accountants: medium and high for their engagements.
- Super_admin: high and critical (for cross-cutting issues like period unlock requests).

### 20.2 Notification types

| Type | Severity | Trigger | Recipients |
|---|---|---|---|
| `accountants_portal.aje_awaiting_approval` | Medium | AJE submitted for review | Lead practitioner |
| `accountants_portal.aje_approved` | Low | AJE approved + posted | Drafter |
| `accountants_portal.pbc_overdue` | Medium | PBC item past due | Avi + accountant |
| `accountants_portal.pbc_received` | Low | Avi marks PBC item received | Accountant |
| `accountants_portal.period_unlock_requested` | High | Accountant requests period unlock | Super_admin |
| `accountants_portal.period_unlock_approved` | Medium | Super_admin approves | Accountant |
| `accountants_portal.period_unlock_denied` | Medium | Super_admin denies | Accountant |
| `accountants_portal.year_end_package_ready` | Low | Year-end package generation complete | Accountant |
| `accountants_portal.engagement_signed_off` | Info | Lead practitioner signs off | Avi + super_admin |
| `accountants_portal.external_access_granted` | Info | External CPA invited | Super_admin |
| `accountants_portal.external_access_expiring` | Medium | External access expires in 7 days | Super_admin + external user |
| `accountants_portal.qbo_drift_critical` | High | Drift > tolerance during engagement | Accountant |

---

## 21. SETTINGS KEYS

### 21.1 Portal-specific settings

| Key | Type | Default | Notes |
|---|---|---|---|
| `accountants_portal.enabled` | boolean | '0' | Master toggle; '1' after S-PORT-1 deployment |
| `accountants_portal.default_engagement_type` | enum | 'compilation' | 'compilation' or 'review' |
| `accountants_portal.session_timeout_hours` | int | 8 | Inactivity timeout |
| `accountants_portal.mfa_required` | boolean | '0' | When '1', all accountant_users must enroll MFA |
| `accountants_portal.invitation_expiration_days` | int | 7 | Token validity |
| `accountants_portal.external_access_default_days` | int | 90 | External CPA access duration |
| `accountants_portal.pbc_reminder_days_before_due` | int | 7 | When to first remind about pending PBC |
| `accountants_portal.year_end_package_retention_days` | int | 2555 | 7 years CRA retention |

---

## 22. OPEN QUESTIONS

### 22.1 Pre-Phase E

| ID | Question | Resolution timing |
|---|---|---|
| Q-PORT-1 | Will Mainland have any review engagements (vs only compilation)? | Confirms whether CSRE 2400 mode (§12) needs full implementation or stub |
| Q-PORT-2 | Will there be more than one accountant_user (staff in addition to lead)? | Affects two-eyes review enforcement |
| Q-PORT-3 | Does the accountant want electronic engagement letter signing (DocuSign-like) or PDF + external signing? | Affects MVP scope of engagement letter feature |
| Q-PORT-4 | Tickmark customization — accept the locked legend (§9.1) or customize? | Operator + accountant confirmation at S-ACCT-WTB time |
| Q-PORT-5 | Color theme preference for portal (vs admin)? | Cosmetic; default to neutral gray + navy |

### 22.2 Resolved at S-PORT-1

- Portal subdomain vs path (path locked: `/accountant`).
- Authentication library reuse (mirrors customer portal S024).
- Theme primary color and logo placement.

### 22.3 Resolved at S-PORT-3

- Default PBC template (locked per §8.3).
- Engagement file tab order (locked per §7.3).
- Year-end package contents (locked per §10.1).

---

## 23. GLOSSARY

| Term | Meaning |
|---|---|
| **AJE** | Adjusting Journal Entry — a manual JE posted to adjust prior balances, typically at period-end or year-end |
| **ASPE** | Accounting Standards for Private Enterprises (CPA Canada Handbook Part II) |
| **CaseWare** | Industry-standard practitioner workpaper software; FleetForge's lead schedule conventions are CaseWare-aligned |
| **CSRE 2400** | Canadian Standard on Review Engagements 2400 — the standard for review engagements |
| **CSRS 4200** | Canadian Standard on Related Services 4200 — the standard for compilation engagements |
| **Engagement** | A formal accounting service performed for a fiscal year — typically a compilation or review |
| **Engagement letter** | The signed agreement between Mainland and the practitioner defining scope, fees, responsibilities |
| **Engagement file** | All workpapers, AJEs, documents, and reports associated with one engagement |
| **External CPA** | A practitioner who is not Mainland's regular accountant; engaged for a specific limited purpose |
| **Lead practitioner** | The CPA/CA with primary responsibility for signing off the engagement |
| **Lead schedule** | A summary workpaper for a balance sheet section (e.g., A-100 Cash, B-100 AR); the top-level workpaper that supporting workpapers reference |
| **Materiality** | The threshold above which a misstatement would affect a user's decisions (review engagement concept) |
| **PBC** | Prepared by Client — items the practitioner needs from management before/during fieldwork |
| **Performance materiality** | A sub-threshold (typically 50-75% of overall materiality) used to size individual procedures |
| **Period lock** | The mechanism preventing new JEs from posting to a closed period |
| **Practitioner** | The accountant or CPA performing the engagement |
| **Review engagement** | An engagement providing limited assurance (CSRE 2400) |
| **Sign-off** | Final approval by lead practitioner that engagement work is complete |
| **Tickmark** | A single-character notation indicating procedure performed (per the legend in §9.1) |
| **Trivial threshold** | Misstatements below this level need not be aggregated for evaluation (review engagement) |
| **Working trial balance** | The accountant's working version of the trial balance with PY comparison + AJE column + lead schedule mapping |
| **WTB v2** | The CaseWare-style enhanced working trial balance built in Phase C §23.2 of accounting spec |
| **Year-end close** | The annual ceremony closing the books for a fiscal year — closing JEs, period locks, new year periods, package generation |
| **Year-end package** | The ZIP file containing all workpapers and reports for a fiscal year |

---

## 24. CHANGELOG

### v1.0 (2026-05-18) — Initial canonical spec

Comprehensive specification for the FleetForge Accountants Portal. Covers:

- Pattern A architecture (separate URL `/accountant`, separate auth namespace, separate layout — mirrors customer portal S024 pattern).
- 12 D-PORT-CORE-* decisions locked.
- Authentication: accountant_users table, invitation flow, MFA support, brute-force protection, password reset.
- Layout and navigation with sidebar covering Dashboard, Engagement, Workpapers, Period Operations, Reports, Tax, AP, Disclosure, QuickBooks, Documents, Account.
- Dashboard with engagement status, PBC progress, AJE queue, period status, drift summary, activity feed, critical alerts.
- Engagement file structure (acct_engagements table; lifecycle draft → active → signed_off → archived; engagement letter generator).
- PBC checklist with default template (25+ items) + reminder workflow.
- Workpaper annotations and tickmarks (built on §23.2 of accounting spec).
- Year-end package generator with manifest + S3 retention for 7 years.
- CSRS 4200 compilation engagement template + Note 1 auto-population.
- Optional CSRE 2400 review engagement mode with materiality calculator, analytical procedures workpaper, inquiries log.
- Period locking with two-person rule for unlock.
- Optional read-only external CPA access mode with activity logging.
- Integration with FF accounting module (shared tables and services).
- Integration with QBO data (sync status, drift dashboard, reconciliation tie-out workpaper).
- 6 new tables; no modifications to existing tables.
- ~52 portal pages.
- 3 portal roles (lead_practitioner, staff, external_readonly) with detailed permission matrix.
- 12 notification types in new `accountants_portal` category.
- Settings keys.
- 5 open questions for pre-Phase E resolution.

Anticipates 6 sessions (S-PORT-1 through S-PORT-6) over 12-18 working days.

Built **last** — after Phase A (integrity), Phase B (spec completion), Phase C (ASPE extensions), Phase D (lessor module), and Phase QBO (QuickBooks integration) all complete.

---

*End of FLEETFORGE_ACCOUNTANTS_PORTAL_SPEC.md v1.0.*
*Implementation begins with S-PORT-1 after all prior phases complete per master roadmap dependency chain.*
*Companion: `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §10 for session-by-session execution plan.*
