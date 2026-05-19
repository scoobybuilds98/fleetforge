# FleetForge — Accounting + QuickBooks + Accountants Portal Master Roadmap

**Version:** 1.1
**Date:** 2026-05-18
**Owner:** Avi (Mainland Truck & Trailer Sales)
**Supersedes:** v1.0 (2026-05-06; retained as historical reference)
**Purpose:** Single canonical reference for the multi-phase build that takes FleetForge's accounting module from its current state through ASPE-grade comprehensive accounting, into a master-mirror QuickBooks Online integration, and finishing with a dedicated accountants portal. Updated ~59 sessions across six phases.

**Status:** LIVING DOCUMENT. Updated session-by-session as work lands. Each session's row in this doc moves from `📋 PLANNED` → `🟡 QUEUED` → `🔄 IN-PROGRESS` → `✅ DONE` as it progresses.

**v1.0 → v1.1 changelog at Section 19.**

---

## TABLE OF CONTENTS

1. [What this document is](#1-what-this-document-is)
2. [Locked architecture decisions](#2-locked-architecture-decisions)
3. [Current state diagnostic (refreshed 2026-05-18)](#3-current-state-diagnostic)
4. [Master phase order and dependencies](#4-master-phase-order-and-dependencies)
5. [Phase A — Integrity fixes (revised)](#5-phase-a--integrity-fixes-2-sessions-revised)
6. [Phase B — Spec v1.2 completion](#6-phase-b--spec-v12-completion-6-sessions)
7. [Phase C — ASPE-grade extensions](#7-phase-c--aspe-grade-extensions-11-sessions)
8. [Phase D — Lessor capital-lease module](#8-phase-d--lessor-capital-lease-module-6-sessions)
9. [Phase QBO — QuickBooks integration (updated)](#9-phase-qbo--quickbooks-integration-28-sessions-updated)
10. [Phase E — Accountants portal](#10-phase-e--accountants-portal-6-sessions)
11. [Total session index](#11-total-session-index)
12. [Decision log](#12-decision-log)
13. [Document map](#13-document-map)
14. [Workflow conventions (updated with D131 / D136 / K-22)](#14-workflow-conventions)
15. [Stop conditions and safety patterns](#15-stop-conditions-and-safety-patterns)
16. [Risks and mitigations](#16-risks-and-mitigations)
17. [Open questions](#17-open-questions)
18. [Glossary](#18-glossary)
19. [v1.0 → v1.1 changelog](#19-v10--v11-changelog)

---

## 1. WHAT THIS DOCUMENT IS

### 1.1 Purpose

This is the **master roadmap** for the next ~59 development sessions, covering three logically distinct but operationally chained workstreams:

1. **Accounting gap-closing** (Phases A–D, 25 sessions): bring FleetForge's accounting module from its current S028–S035 state to comprehensive ASPE-grade — including Section 3065 lease accounting (lessor side), CCA Schedule 8, working trial balance with lead schedules, year-end close, FX revaluation, and per-unit profitability.

2. **QuickBooks Online integration** (Phase QBO, 28 sessions): build a master-mirror integration where FleetForge is the canonical source of truth and QBO is a downstream mirror, with three documented exceptions (payments processed via QBO Payments, bank feed transactions, and one-time reference-data ID mapping).

3. **Accountants portal** (Phase E, 6 sessions): a dedicated portal with separate auth/URL for the accountant to do period-close, year-end work, and engagement-grade workpaper review — supporting both compilation (CSRS 4200) and optional review (CSRE 2400) engagements.

### 1.2 Audience

This doc is written for **future Avi** (and Claude) to reference at any point during the build:

- "Where are we?" → check Section 11 session index
- "Why did we decide X?" → check Section 12 decision log
- "What does the next session look like?" → check the relevant phase section
- "What's blocking what?" → check Section 4 phase order
- "What changed in v1.1?" → check Section 19

### 1.3 How to read

- **First-time read:** Sections 1, 2, 3, 4, 11. Then skim each phase section.
- **Active session prep:** jump to the relevant phase, find the session, read its row.
- **Decision context:** Section 12 is the source of truth for locked decisions.

### 1.4 Scope coverage map

Unchanged from v1.0. See v1.0 for full table. Net: in scope is everything in `FLEETFORGE_ACCOUNTING_SPEC.md` plus the ASPE-grade extensions surfaced in the May 2026 research target state, plus the QBO master-mirror, plus the accountants portal. Out of scope: payroll, T2 e-filing, multi-entity consolidation, IFRS, QBO Desktop, direct card-form PCI scope, full external CPA self-service.

### 1.5 Relationship to canonical docs

This roadmap is the **planning document**. As work lands, content migrates into the canonical specs:

- **Accounting work** → `FLEETFORGE_ACCOUNTING_SPEC.md` (currently v1.2; will become v1.3 with new §20–§23 for the extensions)
- **QBO work** → `FLEETFORGE_QUICKBOOKS_SPEC.md` (created in S-QBO-1)
- **Portal work** → `FLEETFORGE_SPEC_FINAL.md` (extended) and `FLEETFORGE_ACCOUNTING_SPEC.md` v1.3 §23
- **Decision log** → consolidated here, mirrored in canonical doc decision tables per session
- **Progress** → `FLEETFORGE_PROGRESS.md` (session-by-session SESSION LOG row, mandatory before commit per K-14)
- **Active queue** → `FLEETFORGE_CURRENT_SESSIONS.md` (QUEUED / IN-FLIGHT / SHIPPED — per D136 multi-agent discipline)
- **Pre-deploy obligations** → `FLEETFORGE_PREDEPLOY_CHECKLIST.md` (per K-14 separation)
- **Audit baseline** → `FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md` (point-in-time, not modified)

This doc remains the master roadmap. Canonical specs are the operational truth.

---

## 2. LOCKED ARCHITECTURE DECISIONS

### 2.1 The core architecture decision: FF = canonical, QBO = mirror

**FleetForge is the canonical source of truth for everything financial.** Every entity — customers, vendors, invoices, credit notes, refunds, bills, AP payments, journal entries, fixed assets, depreciation runs, tax remittances — is created, edited, voided, and finalized in FleetForge first. FleetForge then pushes to QuickBooks Online. The accountant works in FleetForge; QuickBooks mirrors the result and continues to handle the operational pieces it owns (payment processing, bank feeds, sales-tax filing).

This is **not bidirectional sync**. It is a master-with-mirror architecture. The simplification has cascading consequences:

- No conflict-resolution policy needed for outbound entities (FleetForge always wins).
- CDC pull from QBO is limited to the three exceptions below.
- AutoEntryBridge stays live indefinitely (FleetForge keeps posting its own JEs).
- The accounting module is **not deprecated**. Both systems run permanently.
- CRA defensibility story becomes "FleetForge is the truth, QBO is the mirror, the mirror reconciles on demand."

### 2.2 The three QBO → FF exceptions

| Exception | Why | Pattern |
|---|---|---|
| **Payments via QBO Payments** | Money lands in QBO first when a customer pays online. FleetForge can't be source of truth for an event that physically happens at the payment processor. | QBO webhook on payment success → FleetForge creates payment record → clears AR → re-pushes to QBO for state confirmation. **Reuses the S-PROD-2 webhook pattern** (`AWS\Sns\MessageValidator` signature check; SubscriptionConfirmation auto-confirm; Sentry instrumentation). |
| **Bank feed transactions** | QBO has connected bank feeds; FleetForge does not ingest from banks directly. | Daily CDC pull from QBO bank transactions → `acc_bank_transactions` as **read-only mirror**. Reconciliation actions still happen in QBO; FleetForge displays the data for operator visibility. |
| **Reference data ID mapping** | QBO already has accounts, items, tax codes, customers, vendors with QBO-assigned IDs. FleetForge needs those IDs to push correctly. | One-time inbound mapping at setup, then dormant. After cutover, FleetForge originates everything and QBO assigns new IDs to the FF-pushed entities. |

### 2.3 The accounting module is not deprecated

Both systems run permanently. The FleetForge accounting module (chart of accounts, journal entries, AR/AP, bank, fixed assets, tax) keeps operating. Every JE FleetForge posts internally also pushes to QBO as a mirrored entry. No tables get dropped, no APIs retired.

Tradeoffs accepted: 2× write operations on every financial event (FleetForge JE + QBO push); drift detection runs forever as recurring ops tooling; two systems must agree at every reconciliation point.

### 2.4 Workflow shifts for the accountant

(Unchanged from v1.0; needs explicit accountant sign-off before QBO sessions begin. See v1.0 §2.4 for full table.)

### 2.5 Reporting framework: ASPE

Mainland is a private Canadian SMB. Reporting framework is **ASPE (Accounting Standards for Private Enterprises)** — not IFRS. Per Section 3061, 3063, 3065, 3400, 3475, 3856, 3465, 1540, 1651.

### 2.6 Engagement type with external CPA-CA

Most likely: **CSRS 4200 compilation engagement**. Possibly CSRE 2400 review if Mainland's lender requires limited assurance. Build target is **compilation-ready by default**, with **review-ready optional** via the accountants portal's materiality and analytical-procedures features.

### 2.7 The CA bar: feature comprehensiveness, internal users

The "every report a CA could ever need" bar is about *what's in the system*, not *who logs in*. The actual users are internal — Avi and his accountant. But the accountant should never need to "fall back to Excel" for any standard year-end task.

### 2.8 Accountants portal: separate pattern, sequenced last

Separate portal app (pattern A), distinct URL (`/accountant`), separate auth namespace, dedicated layout — mirroring the customer portal pattern from S024. Built **last**, after all accounting features and the QBO integration are stable.

### 2.9 Decision summary table (locked)

| ID | Decision | Status |
|---|---|---|
| D-ARCH-1 | FleetForge is canonical source of truth | LOCKED |
| D-ARCH-2 | QuickBooks Online is downstream mirror | LOCKED |
| D-ARCH-3 | Three documented QBO → FF exceptions: payments, bank feeds, reference data | LOCKED |
| D-ARCH-4 | Accounting module is not deprecated; both systems run permanently | LOCKED |
| D-ARCH-5 | Reporting framework: ASPE | LOCKED |
| D-ARCH-6 | Engagement target: CSRS 4200 compilation (optional CSRE 2400 review readiness) | LOCKED |
| D-ARCH-7 | Accountants portal: separate URL `/accountant`, separate auth, pattern A | LOCKED |
| D-ARCH-8 | Phase order: A integrity → B spec completion → C extensions → D lessor → QBO → E portal | LOCKED |
| D-ARCH-9 | Strict bucket order, no interleave within accounting phases | LOCKED |
| D-ARCH-10 | Lease-to-own currently 0% volume but program offered; lessor module staged in Phase D | LOCKED |
| D-ARCH-11 | Internal users only for now; external CPA via review mode reserved as future option | LOCKED |
| D-ARCH-12 | Documentation home: master roadmap + ACCOUNTING_SPEC.md v1.3 + QUICKBOOKS_SPEC.md | LOCKED |
| **D-ARCH-13** | **Production live at https://mainlandrentals.com/fleetforge since 2026-05-16; Lightsail Oregon us-west-2; SSL via Let's Encrypt; D8 (Lightsail deferred) unlocked** | **LOCKED v1.1** |
| **D-ARCH-14** | **AR drift remediation ($17,064.62) absorbed into QBO arc S-QBO-27 historical pull cross-reference — not a standalone Phase A session** | **LOCKED v1.1 (pending operator confirm)** |

---

## 3. CURRENT STATE DIAGNOSTIC

**Refreshed 2026-05-18 against on-disk canonical state per K-22 discipline.**

### 3.1 The 2026-05-07 audit (baseline)

The accounting audit (`FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md`) found a partially complete module:

**Built and working (✅):**
- Foundation: 81-account chart of accounts, 24 periods, 18 spec settings + 13 derived counters.
- General Ledger: 59 JEs across 5 source types, gap-free `JE-YYYY-NNNNN` numbering, balanced trial balance.
- AutoEntryBridge: 7 spec methods + 5 hook stubs, wired into 5 billing endpoints + lease close.
- Bank module: 5 CSV formats, reconciliation workflow, NSF processing, FX transfers.
- Fixed assets: 3 depreciation methods, 20 assets, 4 depreciation runs.
- Tax module: GST/HST/PST filing periods, calculation, remittance JE.
- AP subledger: $4,272.80 GL = $4,272.80 subledger (zero drift).

**Broken (❌):**
- AR drift $17,064.62.
- 6 orphan AP-payment JEs.
- `acc_documents` is dead schema.

**Missing entirely (❌):**
- Phase 24 (S036) Reports & Budgeting.
- Phase 25 (S037) Polish/FX/Year-End/Documents.
- 4 of 6 spec crons.

### 3.2 What has shipped since the audit (2026-05-07 → 2026-05-18)

Material accounting-adjacent ships, per `FLEETFORGE_PROGRESS.md` SESSION LOG:

**Production deployment (2026-05-16):**
- Production live at `https://mainlandrentals.com/fleetforge`.
- Lightsail Oregon us-west-2, static IP 44.226.100.133, Ubuntu 22.04, 4GB/2vCPU.
- MySQL 8.0, 26 migrations applied (was 19 at HANDOFF-PREP 2026-05-13; bumped by S-BILLING-HOLISTIC-ENGINE +6 and S-DESIGN-SETTINGS-FOOTER-LOGIN +1).
- SSL via Let's Encrypt; nginx as web server (locked D202 in S-NGINX-PROD-CONFIG).
- Super admin verified login working.
- 9 outstanding operator items in PREDEPLOY_CHECKLIST.md (B6 SES SMTP creds, D10 cron jobs, D11 S3 test, D12 CloudWatch billing alarm, D13 SNS+SES bounce webhook, E1 rate cards seed, G5 second admin, G6 super admin MFA, I4 error log monitoring).

**S-BILLING-HOLISTIC-ENGINE (2026-05-17) — the boss's-bug fix.** Replaces `ProRateCalculator` THE LAW (period-independent) with `lib/Billing/HolisticLeaseEngine.php` (running-reconciliation). New schema:
- `leases.engine_version` ENUM('period_independent','holistic') DEFAULT 'holistic'.
- 23 active+pending leases locked to 'period_independent' (continue billing on old engine until close); 8 closed leases got 'holistic' default (harmless).
- `invoice_line_items.item_type` ENUM extended +1 → **`base_rental_reconciliation_credit`** (16→17 values).
- `credit_notes.source` ENUM extended +1 → `base_rental_reconciliation_overflow` (8→9).
- 3 new invoice audit columns: `total_days_at_period_end`, `cumulative_correct_amount`, `already_billed_before_this`.
- New settings flag `billing.engine_version` = 'holistic'.
- 6 migrations 0100-0105, all idempotent.
- `InvoiceGenerator` dispatches on `lease.engine_version`; holistic path uses `FOR UPDATE` on lease row (D20).
- Tests 88/88 PASS.

**S-LEASE-GPS-COST (shipped before audit, but stale in roadmap v1.0):** Per-lease GPS tracking add-on.
- `leases.gps_opt_in` TINYINT DEFAULT 1.
- `leases.gps_cost` DECIMAL(10,2) DEFAULT 1.00.
- `invoice_line_items.item_type` ENUM extended → **`gps`**.
- Engine emits `gps` line when opt_in=1 AND cost>0; amount = gps_cost × billing_days.
- Existing leases backfilled to opt_in=1 / cost=$1.00.

**S-PROD-2 (2026-05-02):** Production-readiness instrumentation.
- Sentry SDK at `lib/Observability/Sentry.php` with PII scrub (bcrypt hashes, ENC: ciphertext, 12 sensitive key names).
- SES bounce/complaint webhook at `api/v1/webhooks/ses_notifications.php` (`Aws\Sns\MessageValidator` signature check, SubscriptionConfirmation auto-confirm).
- `email_bounces` table + `email_disabled` columns on customers + portal_users.
- Mailer::isEmailDisabled() pre-send guard.
- Manager re-enable via `api/v1/customers/reenable_email.php`.
- Key rotation runbook at `docs/runbooks/key_rotation.md`.
- DECISIONS D75–D78 locked.

**Model B mileage arc closed:** S-MILEAGE-1 / -2A / -2B / -3 / -3-FIX-0 / -5 all SHIPPED 2026-05-13. Plus S-PORTAL-MILEAGE-MODEL-B (portal + helper retirement). Final retirement of `Mileage::monthlyAllowance` complete.

**S-LEASE-RATE-AMENDMENT shipped** under Option (A) per D130 (reuse existing table, no new migration). The amendment surface is sealed at the production-code layer.

**S-FIX-2 counter drift fix shipped 2026-05-02:** `scripts/fix_counter_drift_2026_05_02.php` — corrected LP Logistics -$47,194.80 and Lepore Enterprise -$14,650.00 customer.outstanding_balance counters. (Note: customer.outstanding_balance ≠ GL AR; these are FF-side counters tracking lease/invoice totals. The acc_journal_entries side AR drift is separate and remains open.)

### 3.3 Disciplines locked since v1.0 of this roadmap

**D131 (pre-commit gate):** Every commit must pass: `_smoke_master_schema_parity.php` PARITY OK + `_smoke_billing_invariants.php` INVARIANTS OK (I1-I10 all PASS) + `_smoke_samsara_distance.php` 16/16 + `_smoke_model_b_lifecycle.php` 20/20 + `_smoke_doc_freshness.php` 17/17 + `bin/migrate.php --verify` 26/0/0. Every future session in this roadmap goes through this gate.

**D136 (multi-agent discipline):** Hybrid single-agent serialization + branch isolation fallback. Write sessions must register in `FLEETFORGE_CURRENT_SESSIONS.md` IN-FLIGHT block BEFORE any write, with start timestamp + agent ID + touching domains. Only one IN-FLIGHT write session at a time on main. IN-FLIGHT-RO (read-only audits) can run concurrently. Collision detection via Touching field.

**K-14 (pre-deploy obligations file separation):** Pre-deploy operator tasks file in `FLEETFORGE_PREDEPLOY_CHECKLIST.md` under categorized ITEMs (A-I), not under KNOWN ISSUES (bug-shaped) or CURRENT_SESSIONS (queue-shaped). Documentation divergence is a K-12 class violation.

**K-22 (trust on-disk over chat narrative):** Planning-chat prompt-drafting must verify on-disk canonical-file state before drafting build prompts. Trust `FLEETFORGE_PROGRESS.md` SESSION LOG + `FLEETFORGE_CURRENT_SESSIONS.md` + `FLEETFORGE_DATABASE_MASTER.sql` over chat-history narrative. **v1.0 of this roadmap violated K-22**; v1.1 corrects.

### 3.4 What's still open in the accounting module today

| Open item | Status | Pickup point |
|---|---|---|
| AR drift $17,064.62 | UNCHANGED since audit. Diagnostic in S-ACCT-FIX-A1 (2026-05-07) **crashed mid-flight, never committed**. Root cause known: H5 LP Logistics +$20,764.80 + H6 Lepore −$3,700.18. | Absorbed into **S-QBO-27 historical pull cross-reference** (D-ARCH-14 pending confirm) |
| 6 orphan AP-payment JEs | Unchanged. | **Phase A S-ACCT-FIX-AP** (this v1.1 update) |
| `acc_documents` dead schema | Unchanged. | **Phase A S-ACCT-FIX-DOCS** (this v1.1 update) |
| Phase 24 (Reports & Budgeting) | Unchanged. | Phase B S036 |
| Phase 25 (Polish/FX/Year-End/Documents) | Unchanged. | Phase B S037-FX / S037-YE / S037-REC / S037-CRONS / S037-CRUD |
| 4 missing accounting crons | Unchanged. Period exhaustion still 6 months away (periods through Dec 2026). | Phase B S037-CRONS |

### 3.5 What the research target requires beyond the spec

(Unchanged from v1.0 §3.2; see v1.0 for full list.) CCA Schedule 8, AccII, working trial balance v2 with PY+AJE+lead schedules, AJE workflow, place-of-supply rule engine, GST34 generator, componentization, ASPE 3063 impairment workflow, disclosure note builder, per-unit profitability, lessor capital lease (3065), principal/agent revenue toggle for GPS, damage claims subledger.

### 3.6 Diff matrix summary

25 gap items from research, refreshed for what shipped since the audit:

| Status | Count | Examples |
|---|---|---|
| ✅ Working | 4 | Operating-lease accounting, PP&E depreciation, AP reconciliation, JE numbering |
| ⚠️ Partial | 9 | Tax module, disposal flow, payment FX, JE service, collections CRUD, recurring JEs, single-asset impairment, revenue recognition, multi-currency |
| ❌ Missing | 12 | CCA, lessor capital lease, WTB v2, year-end close, FX revaluation, P&L/BS/Cash Flow, Budget, place-of-supply, GST34, componentization, disclosure note builder, practitioner portal |

### 3.7 Top 5 critical gaps (refreshed)

1. **AR drift $17,064.62** — root cause identified, remediation deferred to S-QBO-27 cross-reference.
2. **Reports & Budgeting (S036) absent** — CA cannot produce financial statements from system today.
3. **FX revaluation (S037 sub) absent** — ASPE 1651 non-compliance.
4. **CCA Schedule 8 absent** — T2 preparer still builds in Excel.
5. **Working trial balance v2 absent** — CA workpaper-quality not yet met.

---

## 4. MASTER PHASE ORDER AND DEPENDENCIES

### 4.1 Phase sequence (refreshed)

```
PHASE A — Integrity Fixes (2 sessions, revised down from 5)
   ↓ [hard dependency: AP orphans + documents must resolve before reports build on them]

PHASE B — Spec v1.2 Completion (6 sessions)
   ↓ [hard dependency: reports must exist before extensions extend them]

PHASE C — ASPE-Grade Extensions (11 sessions)
   ↓ [soft dependency: most extensions can run in any sub-order within C]

PHASE D — Lessor Capital-Lease Module (6 sessions)
   ↓ [soft dependency: can start parallel with Phase C-3 or C-4 if needed]

PHASE QBO — QuickBooks Integration (28 sessions)
   ↓ [hard dependency: accounting must be ASPE-grade before mirror starts]
   ↓ [S-QBO-27 absorbs AR drift remediation per D-ARCH-14]

PHASE E — Accountants Portal (6 sessions)
   [final phase; built on top of stable feature set]

TOTAL: 59 sessions (down from 62 in v1.0)
```

### 4.2 Hard prerequisites between phases (refreshed)

| Successor phase | Cannot start until |
|---|---|
| Phase B | Phase A complete (AP orphans resolved, documents wired) |
| Phase C | Phase B complete (reports/budget/FX/year-end/recurring/crons all landed) |
| Phase D | Phase B complete; first 4 sessions of Phase C complete (AJE workflow, WTB v2 used in lessor disclosure) |
| Phase QBO Phase 5 (invoice push) | Phase C complete (place-of-supply + GST34 required for tax-code mapping); **S-MILEAGE-3-ACCT-SPEC unblocked or deferred** (currently CPA-blocked on 5 questions per D-I (A) / D176) |
| Phase QBO Phase 13 (S-QBO-27) | AR drift root cause confirmed (already done in A1 diagnostic) |
| Phase QBO Phase 14 (cutover) | **D-ARCH-13 already locked** — production live, Lightsail provisioned, SSL active. **D8 unlocked.** No Lightsail gate. |
| Phase E | Phase QBO complete (portal surfaces QBO sync status) |

### 4.3 Parallelism opportunities (refreshed)

- Phase C is internally parallelizable across four sub-groups (C-1 foundations, C-2 tax module, C-3 reporting polish, C-4 unit profitability + damage claims). Up to two sessions could run on alternating days under D136 (one IN-FLIGHT write + multiple IN-FLIGHT-RO).
- Phase D can start in parallel with Phase C-3 or C-4 if Avi prioritizes lease-to-own readiness.
- Phase QBO can interleave with Phase D after QBO Phase 4 lands (foundation + customer + vendor + reference data — those don't touch lease accounting).

Per **D-ARCH-9 (strict order)**, default plan is sequential. Parallelism is opt-in if calendar pressure requires it, and must respect D136.

### 4.4 Estimated calendar (refreshed)

| Phase | Sessions | Avg effort | Estimated working days |
|---|---|---|---|
| A | 2 | M | 3–4 |
| B | 6 | M-L | 10–14 |
| C | 11 | M-L | 20–28 |
| D | 6 | L | 12–18 |
| QBO | 28 | M-L | 50–70 |
| E | 6 | L | 12–18 |
| **Total** | **59** | | **~107–152 working days** |

---

## 5. PHASE A — INTEGRITY FIXES (2 SESSIONS, REVISED)

### 5.1 Phase purpose (refreshed)

Resolve the two remaining audit-flagged integrity issues that are NOT absorbed into QBO arc. AR drift remediation moved to S-QBO-27 per D-ARCH-14.

### 5.2 Phase exit criteria

- AP reconciliation: GL = subledger ± $1 (already met at GL level; verify orphan JEs resolved into subledger or reversed)
- `acc_documents` wired across bills, JEs, AP payments, fixed assets, bank transactions
- Both Phase A sessions land their `FLEETFORGE_PROGRESS.md` SESSION LOG row and decision log entries
- Phase A leaves AR drift unchanged ($17,064.62) for S-QBO-27 to resolve

### 5.3 Sessions

#### S-ACCT-FIX-AP — AP-Payment Orphan Resolution 🟡 QUEUED NEXT

| Attribute | Value |
|---|---|
| Status | 🟡 QUEUED (next-up; replaces v1.0's S-ACCT-FIX-A2) |
| Effort | M |
| Model | Opus |
| Dependencies | None within roadmap; D131 gate green |
| Scope | Resolve the 6 orphan `ap_payment` JEs (JE-2026-00036/00038/00040/00042/00045/00047, source_id 1–4 + 6 + 7) whose `acc_ap_payments` rows are missing. Phase 1 diagnostic (read-only): trace each JE description and audit_log to determine whether real AP payment occurred or this is demo-data residue. Phase 2 (writes): branch on findings — (a) if reconstructable, INSERT into `acc_ap_payments` with idempotency tag `[FIX-AP-source_id-N]`; (b) if not reconstructable, reverse the orphan JEs via `JournalEntryService::reverse()` with same idempotency tag. Then build dedicated `app/admin/accounting/ap-payments/` page (spec §15 gap, listed as "AP → Payments" sidebar item in spec but currently folded into bills). Investigate the code path that allowed JEs to write without subledger rows (per audit's hypothesis, likely `scripts/demo_accounting.php` truncation order; verify by re-running demo with explicit ordering and observing whether the drift reappears). |
| Decisions to lock | D-ACCT-AP-1 through AP-4 (reconstructability rubric, page UX shape, JE reversal vs subledger insert rule, demo-script ordering fix) |
| Stop conditions | If real AP payment data exists with cash settled but no subledger row, STOP — data recovery strategy needed (out of session scope). |

#### S-ACCT-FIX-DOCS — Documents Wiring 📋 PLANNED

| Attribute | Value |
|---|---|
| Status | 📋 PLANNED (replaces v1.0's S-ACCT-FIX-A3) |
| Effort | M |
| Model | Sonnet |
| Dependencies | S-ACCT-FIX-AP complete; D131 gate green |
| Scope | Wire `acc_documents` table across bills, JEs, AP payments, fixed assets, bank transactions. Each entity gets an upload button and a display surface. Every accounting transaction can now attach scanned source documents (invoices, bills, checks, statements). Source-doc trail prerequisite for CSRS 4200 compliance. Uses existing `StorageClient` (D9) for S3 or local-file storage. Document categories: invoice / bill / payment / je_support / asset / bank_statement / tax_filing. Per-row permission gate (accountant + super_admin can upload; managers can view). |
| Decisions to lock | D-ACCT-DOCS-1 through DOCS-3 (storage tier S3 vs local, file size limit, supported MIME types) |
| Stop conditions | None expected. |

### 5.4 What got dropped from v1.0 Phase A

- **S-ACCT-FIX-A1** (AR drift diagnostic) — completed read-only on 2026-05-07 but crashed mid-flight before commit; root cause documented (H5 + H6); deferred per D-ARCH-14 to S-QBO-27.
- **S-ACCT-FIX-A1a** (LP Logistics back-fill) — absorbed into S-QBO-27.
- **S-ACCT-FIX-A1b** (Lepore investigation) — absorbed into S-QBO-27 (the QBO pull will reveal whether the 1.375× ratio matches a known QBO-side tax/markup pattern that informs root cause).

### 5.5 Bucket A summary

| Session | Status | Effort | Target outcome |
|---|---|---|---|
| S-ACCT-FIX-AP | 🟡 QUEUED | M | 6 AP orphan JEs resolved; AP-payments page built |
| S-ACCT-FIX-DOCS | 📋 PLANNED | M | acc_documents wired everywhere |

---

## 6. PHASE B — SPEC v1.2 COMPLETION (6 SESSIONS)

(Substantially unchanged from v1.0. Sessions still pending. Migration count baseline now 26+.)

### 6.1 Phase purpose

Close the gap between what `FLEETFORGE_ACCOUNTING_SPEC.md` v1.2 promised and what was actually delivered in S028–S035.

### 6.2 Phase exit criteria

- P&L, Balance Sheet, Cash Flow Statement, Trial Balance, Asset Schedule, AR/AP Aging all callable via API and rendered as admin pages
- Budgeting module operating
- FX revaluation monthly cron operating
- Year-end close end-to-end workflow
- Recurring JE module operating
- All 4 missing crons scheduled

### 6.3 Sessions

(Identical scope to v1.0 §6.3. Sessions: **S036**, **S037-FX**, **S037-YE**, **S037-REC**, **S037-CRONS**, **S037-CRUD**. See v1.0 for per-session detail. No changes.)

### 6.4 Bucket B summary

| Session | Status | Effort | Target outcome |
|---|---|---|---|
| S036 | 📋 PLANNED | XL | 5 reports + Budget module |
| S037-FX | ✅ DONE | L | FX revaluation operating (shipped 2026-05-19) |
| S037-YE | ✅ DONE | L | Year-end close end-to-end (shipped 2026-05-19) |
| S037-REC | ✅ DONE | M | Recurring JE templates + cron (shipped 2026-05-19) |
| S037-CRONS | ✅ DONE | M | 3 missing crons live (shipped 2026-05-19) |
| S037-CRUD | ✅ DONE | M | All spec CRUD complete (shipped 2026-05-19) |

---

## 7. PHASE C — ASPE-GRADE EXTENSIONS (11 SESSIONS)

(Substantially unchanged from v1.0. One scope adjustment: S-ACCT-GPS now has concrete production data — `$1.00/day` default, opt_in=1 default — so the principal/agent toggle work has a real revenue stream to operate against, not a hypothetical.)

### 7.1 Phase purpose

Extend the accounting module beyond the v1.2 spec to the CA-comprehensive bar identified in the research target state.

### 7.2 Phase exit criteria

(Unchanged from v1.0.)

### 7.3 Sessions

(Identical scope to v1.0 §7.3. Sessions: **S-ACCT-AJE**, **S-ACCT-WTB**, **S-ACCT-CCA-1**, **S-ACCT-CCA-2**, **S-ACCT-COMP**, **S-ACCT-POS**, **S-ACCT-GST34**, **S-ACCT-GPS**, **S-ACCT-DISC**, **S-ACCT-UNIT**, **S-ACCT-DMG**.)

**S-ACCT-GPS scope note (refreshed for v1.1):** Existing production state — `gps` item_type live, `leases.gps_opt_in` + `leases.gps_cost` columns active, all leases backfilled to opt_in=1 / $1.00/day. S-ACCT-GPS adds the principal/agent revenue presentation toggle on top: per-customer (or per-contract) flag `gps_revenue_presentation` ENUM('net','gross') with default 'net' (recommended per research). Affects ChartOfAccounts: net presentation routes the `gps` line revenue to a "GPS recharge revenue net of cost" account; gross routes the full charge to revenue + matching Samsara cost to expense. Disclosure auto-generated in compilation note pack (S-ACCT-DISC). Same toggle pattern can apply to future pass-through revenue streams (fuel surcharge, etc.).

### 7.4 Bucket C summary

**✅ PHASE C COMPLETE — 11/11 sessions shipped (2026-05-19).**

Sessions in ship order: S-ACCT-AJE → S-ACCT-WTB → S-ACCT-CCA-1 → S-ACCT-CCA-2 → S-ACCT-COMP → S-ACCT-POS → S-ACCT-GST34 → S-ACCT-GPS → S-ACCT-DISC → S-ACCT-UNIT → S-ACCT-DMG.

Migrations applied during Phase C: 31 → 37 (6 net new migrations covering AJE workflow, WTB lead-schedule annotations, CCA Schedule 8 + classes, componentization + betterment, place-of-supply rules, GPS presentation toggle, disclosure-note table, damage source_type ENUM extension). No Phase-C schema removals.

(Unchanged from v1.0. 11 sessions, mix of M/L/S/L effort.)

---

## 8. PHASE D — LESSOR CAPITAL-LEASE MODULE (6 SESSIONS)

**🔜 NEXT UP — Phase C complete (2026-05-19) → Phase D unblocked.**

(Unchanged from v1.0. 6 sessions. See v1.0 §8 for full detail.)

### 8.1 Phase purpose

Mainland offers lease-to-own programs but has zero active units. Stage the ASPE 3065 sales-type / direct-financing machinery before first contract originates.

### 8.2 Sessions

**S-ACCT-LESSOR-1** through **-6**: classification wizard + schema, effective-interest amortization engine, sales-type JE patterns, direct financing JE patterns, NI presentation + residual review, ASPE 3063 fleet impairment two-step test.

---

## 9. PHASE QBO — QUICKBOOKS INTEGRATION (28 SESSIONS, UPDATED)

### 9.1 Phase purpose

Build the master-mirror QuickBooks Online integration per D-ARCH-1 through D-ARCH-3. Comprehensive bidirectional-with-three-exceptions coverage. **Now also responsible for AR drift remediation via S-QBO-27 cross-reference (per D-ARCH-14).**

### 9.2 Phase exit criteria

- OAuth + sandbox + production credentials operating with refresh-token pinger
- Bidirectional customer / vendor / invoice / payment / credit-memo / bill / JE sync (with three documented exceptions for QBO → FF)
- Place-of-supply rate consistency between FleetForge tax codes and QBO TaxCodes
- QBO Payments embedded in customer portal (Pay Online button)
- Bank feed read-only mirror operating
- Reconciliation drift dashboard with nightly cron
- Historical QBO → FF backfill complete (Mainland's existing real data migrated into FleetForge)
- **AR drift = $0.00 ± $1 after S-QBO-27 (H5 + H6 cross-referenced against QBO source-of-record data)**
- Production cutover dry-run + execution + 14-day monitoring window
- All sessions land via D131 gate + D136 IN-FLIGHT registration

### 9.3 Architecture summary

(Unchanged from v1.0 §9.3.)

### 9.4 Sessions, grouped by phase

#### Phase QBO-1: Foundation (4 sessions)

(Unchanged from v1.0. **S-QBO-1**, **S-QBO-2**, **S-QBO-3**, **S-QBO-4**.)

#### Phase QBO-2: Customers (2 sessions)

(Unchanged. **S-QBO-5**, **S-QBO-6**.)

#### Phase QBO-3: Vendors (1 session)

(Unchanged. **S-QBO-7**.)

#### Phase QBO-4: Reference Data Mapping (3 sessions)

**S-QBO-8 — Chart of Accounts Mapping** (unchanged).

**S-QBO-9 — Tax Code Mapping** (unchanged).

##### S-QBO-10 — Item / Product Mapping (REFRESHED FOR v1.1) 📋 PLANNED

| Attribute | Value |
|---|---|
| Effort | M |
| Model | Sonnet |
| Dependencies | S-QBO-9 complete |
| Scope | Map FleetForge invoice line item types to QBO `Item.Id`. **Updated for v1.1:** the current production ENUM has 17 values (was 14 when v1.0 was written). Mapping target: `base_rental`, **`base_rental_reconciliation_credit`** (NEW from S-BILLING-HOLISTIC-ENGINE — is_credit=1 line; mapping decision needed: negative-quantity on `base_rental` Item, or dedicated "Rental Reconciliation Credit" Item, or routed to separate CreditMemo — locked in this session as D-QBO-10-1), **`gps`** (NEW from S-LEASE-GPS-COST), `mileage_overage`, `mileage_credit`, `mileage_drawdown_credit`, `damage_recovery`, `late_fee`, `early_termination_fee`, `insurance`, `warranty`, `setup_fee`, `delivery_fee`, `manual`, `discount`, `tax_adjustment`, `prepayment`. Create QBO Items where missing (FleetForge as the canonical line-item taxonomy). New table `acc_qbo_item_map` with FF item_type → QBO Item.Id + presentation rules per item type. |
| Decisions to lock | **D-QBO-10-1: how to represent `base_rental_reconciliation_credit` in QBO** (negative line on base_rental Item, OR dedicated Reconciliation Credit Item, OR separate CreditMemo emission). Recommendation: dedicated Item with negative-amount line for clean drill-down. D-QBO-10-2: GPS Item account mapping (depends on S-ACCT-GPS principal/agent decision). |

#### Phase QBO-5: Invoices (2 sessions)

##### S-QBO-11 — Invoice Push (FF → QBO) on Send (REFRESHED FOR v1.1) 📋 PLANNED

| Attribute | Value |
|---|---|
| Effort | XL |
| Model | Opus |
| Dependencies | S-QBO-10 complete |
| Scope | Hook into `api/v1/invoices/send.php` (synchronous push) + scheduled cron for billing-engine-generated invoices (queued push to avoid blocking cron). Tax-override pattern (FF computes tax, QBO accepts via `TxnTaxDetail.TotalTax` override with `TaxCodeRef='NON'`). FX rate pinning for USD invoices. SyncToken handshake for any future updates. Immutability guard: sent FF invoices (D14) cannot be modified by QBO inbound. **Updated for v1.1: engine-version dispatch.** The push handler reads `lease.engine_version` and adjusts line-item construction accordingly: (a) for `period_independent` leases (23 currently active+pending), no reconciliation credits are possible — straightforward line push; (b) for `holistic` leases (default for all new leases), the invoice may include a `base_rental_reconciliation_credit` line that needs the v1.1 D-QBO-10-1 mapping rule applied. The 3 new invoice audit columns (`total_days_at_period_end`, `cumulative_correct_amount`, `already_billed_before_this`) push to QBO Invoice `PrivateNote` field as structured JSON for audit trail (not visible to customer, visible to accountant). |
| Decisions to lock | D-QBO-11-1 through 11-5 (sync mode synchronous-vs-queued threshold; tax-override field; FX rate field source; SyncToken refresh cadence; engine-version dispatch error handling) |

##### S-QBO-12 — Invoice Modification & Void Semantics (unchanged from v1.0)

#### Phase QBO-6: Payments + Portal Embed (3 sessions)

**S-QBO-13 — Payment Pull (QBO → FF)** (unchanged).

**S-QBO-14 — Payment Push (FF → QBO)** (unchanged).

##### S-QBO-15 — QBO Payments Embed in Customer Portal (REFRESHED FOR v1.1) 📋 PLANNED

| Attribute | Value |
|---|---|
| Effort | XL |
| Model | Opus |
| Dependencies | S-QBO-14 complete |
| Scope | "Pay Online" button on FleetForge customer portal invoice view. Hosted-page redirect pattern: FF generates a QBO Payments URL per invoice, customer clicks, redirected to QBO-hosted payment page, QBO processes, **webhook back to FF on success**. **Refreshed for v1.1: reuses the S-PROD-2 webhook pattern.** The existing SES bounce webhook at `api/v1/webhooks/ses_notifications.php` (shipped 2026-05-02) provides the template: `Aws\Sns\MessageValidator` signature check, SubscriptionConfirmation auto-confirm, Sentry instrumentation on all paths, structured audit_log row per webhook event. New webhook at `api/v1/webhooks/qbo_payment_notifications.php` follows the same shape. Signature verification per Intuit Webhooks spec (HMAC-SHA256 with verifier token). PCI-DSS scope avoided by not hosting card form in FF. Success/cancel return URL handling. |
| Decisions to lock | D-QBO-15-1 through 15-4 (webhook signature verification approach, retry policy on webhook failure, FF-side payment record timing on webhook receipt, success/cancel URL format) |

#### Phase QBO-7: Credit Memos & Refunds (2 sessions)

(Unchanged. **S-QBO-16**, **S-QBO-17**.)

**S-QBO-17 dependency note:** depends on `S-MILEAGE-3-ACCT-SPEC` resolution (currently CPA-blocked on 5 questions per D-I (A) / D176). If S-MILEAGE-3-ACCT-SPEC remains blocked, S-QBO-17 can defer until refund concept is concretely defined in FF.

#### Phase QBO-8: Bills & AP (2 sessions)

(Unchanged. **S-QBO-18**, **S-QBO-19**.)

#### Phase QBO-9: Banking (1 session)

(Unchanged. **S-QBO-20**.)

#### Phase QBO-10: Journal Entries (1 session)

##### S-QBO-21 — JE Push (FF → QBO) (REFRESHED FOR v1.1) 📋 PLANNED

| Attribute | Value |
|---|---|
| Effort | L |
| Model | Opus |
| Dependencies | S-QBO-20 complete |
| Scope | All FF JEs sync to QBO as `JournalEntry` (AutoEntryBridge-originated + manual + recurring + closing + adjusting). **Refreshed for v1.1: the AutoEntryBridge has 7 spec methods + 5 hook stubs (12 total per audit), all of which need QBO sync hooks added.** Specifically: `onInvoiceSent`, `onInvoiceVoided`, `onPaymentReceived`, `onCreditNoteIssued`, `onCreditNoteApplied`, `onBadDebtWriteOff`, `onOverpaymentReceived` (the 7 active) + `onAssetDisposed`, `onAssetImpaired`, `onDepreciationPosted`, `onDepreciationReversed`, `onTaxRemittancePosted` (the 5 hook stubs — these may be activated by Phase B/C work; Phase QBO must accommodate). Period-locking respected (closed period in FF = closed in QBO). Line-by-line mapping via COA map (S-QBO-8). Account references via `qbo_account_id`. Since FF is canonical, no JE pull-from-QBO except the rare case of historical backfill in S-QBO-27. |
| Decisions to lock | D-QBO-21-1 through 21-3 (sync timing relative to AutoEntryBridge call, batch vs per-JE push, error handling for closed-period scenarios) |

#### Phase QBO-11: Fixed Assets & Tax Remittances (2 sessions)

(Unchanged. **S-QBO-22**, **S-QBO-23**.)

#### Phase QBO-12: Reconciliation & Monitoring (3 sessions)

(Unchanged. **S-QBO-24**, **S-QBO-25**, **S-QBO-26**.)

**S-QBO-25 scope note (refreshed for v1.1):** the nightly reconciliation cron `qbo_drift_check.php` runs `AccountingService::arReconciliationCheck()` as one of its checks. Post-S-QBO-27, this cron is the ongoing guard against AR drift recurrence.

#### Phase QBO-13: Historical Migration (2 sessions)

##### S-QBO-27 — Historical Pull from QBO (REFRESHED FOR v1.1) 📋 PLANNED

| Attribute | Value |
|---|---|
| Effort | XL (was L in v1.0; bumped due to AR drift remediation absorption) |
| Model | Opus |
| Dependencies | S-QBO-26 complete; D131 gate green |
| Scope | The one-time inbound migration. Pull Mainland's existing real QBO data into FleetForge (since FF currently has only dummy data + the H5 LP Logistics + H6 Lepore data that has the drift). Customers, vendors, COA, items, tax codes, all historical invoices, payments, bills, JEs. Rate-limit-aware throttling (sandbox 40/min, production 500/min). Idempotent (resumable on failure). Dry-run mode first. Subset run on sandbox, then full run on production. **Refreshed for v1.1: AR drift remediation absorbed.** Per D-ARCH-14, this session is responsible for resolving the $17,064.62 AR drift identified in 2026-05-07 audit + 2026-05-07 A1 diagnostic. Sub-task: H5 LP Logistics 5 orphan payment AR-CR JEs whose underlying invoices (32, 43, 44, 45, 46) have no DR-AR JE — the QBO pull will provide the source-of-truth invoice data; for each of these 5 invoices, the historical pull either (a) creates the FF mirror invoice with proper JE that matches QBO, OR (b) if the FF invoice exists but is missing its DR-AR JE, the pull infers the JE from QBO data and posts a compensating JE with idempotency tag `[A1-FIX-invoice-N]`. Sub-task: H6 Lepore 4 inflated DR-AR JEs at 1.375× total_amount — the QBO pull will reveal whether the 1.375× ratio matches a known QBO-side tax/markup/surcharge pattern. If yes (root cause is QBO-side, FF JE was correct relative to QBO), no FF correction needed — drift resolves by virtue of bringing FF in alignment with QBO. If no (root cause is FF-side, JE was wrong), post 4 compensating CR-AR JEs with idempotency tag `[A1-FIX-invoice-N]` AND open a bug investigation against the InvoiceGenerator path that produced the inflated JEs. Stop-gate: if neither (a) nor (b) cleanly resolves H6, HARD STOP and report — do not post compensating JEs that mask an unknown code bug. |
| Decisions to lock | D-QBO-27-1 through 27-6 (pull batch size, resumability checkpoint cadence, dry-run vs apply gating, H5 reconstruction approach, H6 stop-gate trigger criteria, post-pull AR reconciliation verification) |
| Stop conditions | Multiple — see scope. Most important: H6 root cause must be identified and confirmed before any compensating JE is posted. |
| Target drift after | $0.00 ± $1 |

**S-QBO-28 — Historical Verification** (unchanged from v1.0).

#### Phase QBO-14: Production Cutover (2 sessions)

##### S-QBO-29 — Production OAuth + Realm Switch (REFRESHED FOR v1.1) 📋 PLANNED

| Attribute | Value |
|---|---|
| Effort | M |
| Model | Sonnet |
| Dependencies | S-QBO-28 complete |
| Scope | **Refreshed for v1.1: D8 (Lightsail deferred) is unlocked.** Production environment is already live at `https://mainlandrentals.com/fleetforge` (Lightsail Oregon, SSL via Let's Encrypt, nginx web server per D202). Register production Intuit Developer app (separate from sandbox). Production credentials. Production realm ID. Settings environment toggle flipped to 'production'. Production webhook endpoint at `https://mainlandrentals.com/fleetforge/api/v1/webhooks/qbo_payment_notifications.php`. SSL already operational (Let's Encrypt). Final connection verification. |
| Decisions to lock | D-QBO-29-1 through 29-3 (production credential storage location, webhook URL format, sandbox/production rollback procedure) |

**S-QBO-30 — Cutover Dry Run + Execution + Monitoring** (unchanged from v1.0).

### 9.5 Bucket QBO summary (refreshed)

(28 effective sessions; 30 numbered slots; S-QBO-5 absorbs mapping UI; S-QBO-7 collapses vendor pull+push. Effort table per v1.0; **S-QBO-27 bumped L → XL** for v1.1 due to AR drift absorption.)

---

## 10. PHASE E — ACCOUNTANTS PORTAL (6 SESSIONS)

(Unchanged from v1.0. 6 sessions. See v1.0 §10 for full detail.)

**S-PORT-1** through **-6**: portal foundation + auth, dashboard + nav, engagement file structure, PBC list + workpaper annotations, review engagement mode, read-only review mode + external access.

---

## 11. TOTAL SESSION INDEX

The complete 59-session plan, refreshed for v1.1. Status as of 2026-05-18.

| # | Session ID | Phase | Status | Effort | Model | Dependency |
|---|---|---|---|---|---|---|
| 1 | S-ACCT-FIX-AP | A | 🟡 QUEUED | M | Opus | None |
| 2 | S-ACCT-FIX-DOCS | A | 📋 PLANNED | M | Sonnet | AP |
| 3 | S036 | B | 📋 PLANNED | XL | Opus | DOCS |
| 4 | S037-FX | B | ✅ DONE | L | Opus | S036 |
| 5 | S037-YE | B | ✅ DONE | L | Opus | S037-FX |
| 6 | S037-REC | B | ✅ DONE | M | Sonnet | S037-YE |
| 7 | S037-CRONS | B | ✅ DONE | M | Sonnet | S037-REC |
| 8 | S037-CRUD | B | ✅ DONE | M | Sonnet | S037-CRONS |
| 9 | S-ACCT-AJE | C | ✅ DONE | M | Sonnet | S037-CRUD |
| 10 | S-ACCT-WTB | C | ✅ DONE | L | Opus | S-ACCT-AJE |
| 11 | S-ACCT-CCA-1 | C | ✅ DONE | L | Opus | S-ACCT-WTB |
| 12 | S-ACCT-CCA-2 | C | ✅ DONE | M | Opus | S-ACCT-CCA-1 |
| 13 | S-ACCT-COMP | C | ✅ DONE | M | Sonnet | S-ACCT-CCA-2 |
| 14 | S-ACCT-POS | C | ✅ DONE | L | Opus | S-ACCT-COMP |
| 15 | S-ACCT-GST34 | C | ✅ DONE | L | Opus | S-ACCT-POS |
| 16 | S-ACCT-GPS | C | ✅ DONE | S | Sonnet | S-ACCT-GST34 |
| 17 | S-ACCT-DISC | C | ✅ DONE | L | Opus | S-ACCT-GPS |
| 18 | S-ACCT-UNIT | C | ✅ DONE | L | Opus | S-ACCT-DISC |
| 19 | S-ACCT-DMG | C | ✅ DONE | M | Sonnet | S-ACCT-UNIT |
| 20 | S-ACCT-LESSOR-1 | D | ✅ DONE | L | Opus | S-ACCT-DMG |
| 21 | S-ACCT-LESSOR-2 | D | ✅ DONE | L | Opus | LESSOR-1 |
| 22 | S-ACCT-LESSOR-3 | D | 📋 PLANNED | M | Opus | LESSOR-2 |
| 23 | S-ACCT-LESSOR-4 | D | 📋 PLANNED | M | Opus | LESSOR-3 |
| 24 | S-ACCT-LESSOR-5 | D | 📋 PLANNED | M | Sonnet | LESSOR-4 |
| 25 | S-ACCT-LESSOR-6 | D | 📋 PLANNED | M | Sonnet | LESSOR-5 |
| 26 | S-QBO-1 | QBO-1 | 📋 PLANNED | M | Sonnet | LESSOR-6 |
| 27 | S-QBO-2 | QBO-1 | 📋 PLANNED | M | Sonnet | S-QBO-1 |
| 28 | S-QBO-3 | QBO-1 | 📋 PLANNED | L | Opus | S-QBO-2 |
| 29 | S-QBO-4 | QBO-1 | 📋 PLANNED | L | Opus | S-QBO-3 |
| 30 | S-QBO-5 | QBO-2 | 📋 PLANNED | L | Sonnet | S-QBO-4 |
| 31 | S-QBO-6 | QBO-2 | 📋 PLANNED | M | Sonnet | S-QBO-5 |
| 32 | S-QBO-7 | QBO-3 | 📋 PLANNED | M | Sonnet | S-QBO-6 |
| 33 | S-QBO-8 | QBO-4 | 📋 PLANNED | M | Opus | S-QBO-7 |
| 34 | S-QBO-9 | QBO-4 | 📋 PLANNED | M | Opus | S-QBO-8 |
| 35 | S-QBO-10 | QBO-4 | 📋 PLANNED | M | Sonnet | S-QBO-9 |
| 36 | S-QBO-11 | QBO-5 | 📋 PLANNED | XL | Opus | S-QBO-10 |
| 37 | S-QBO-12 | QBO-5 | 📋 PLANNED | L | Opus | S-QBO-11 |
| 38 | S-QBO-13 | QBO-6 | 📋 PLANNED | L | Opus | S-QBO-12 |
| 39 | S-QBO-14 | QBO-6 | 📋 PLANNED | M | Sonnet | S-QBO-13 |
| 40 | S-QBO-15 | QBO-6 | 📋 PLANNED | XL | Opus | S-QBO-14 |
| 41 | S-QBO-16 | QBO-7 | 📋 PLANNED | M | Sonnet | S-QBO-15 |
| 42 | S-QBO-17 | QBO-7 | 📋 PLANNED | M | Sonnet | S-QBO-16 |
| 43 | S-QBO-18 | QBO-8 | 📋 PLANNED | M | Sonnet | S-QBO-17 |
| 44 | S-QBO-19 | QBO-8 | 📋 PLANNED | M | Sonnet | S-QBO-18 |
| 45 | S-QBO-20 | QBO-9 | 📋 PLANNED | M | Sonnet | S-QBO-19 |
| 46 | S-QBO-21 | QBO-10 | 📋 PLANNED | L | Opus | S-QBO-20 |
| 47 | S-QBO-22 | QBO-11 | 📋 PLANNED | M | Sonnet | S-QBO-21 |
| 48 | S-QBO-23 | QBO-11 | 📋 PLANNED | M | Sonnet | S-QBO-22 |
| 49 | S-QBO-24 | QBO-12 | 📋 PLANNED | L | Opus | S-QBO-23 |
| 50 | S-QBO-25 | QBO-12 | 📋 PLANNED | L | Opus | S-QBO-24 |
| 51 | S-QBO-26 | QBO-12 | 📋 PLANNED | L | Sonnet | S-QBO-25 |
| 52 | S-QBO-27 | QBO-13 | 📋 PLANNED | **XL** | Opus | S-QBO-26 |
| 53 | S-QBO-28 | QBO-13 | 📋 PLANNED | M | Sonnet | S-QBO-27 |
| 54 | S-QBO-29 | QBO-14 | 📋 PLANNED | M | Sonnet | S-QBO-28 |
| 55 | S-QBO-30 | QBO-14 | 📋 PLANNED | L | Opus | S-QBO-29 |
| 56 | S-PORT-1 | E | 📋 PLANNED | L | Opus | S-QBO-30 |
| 57 | S-PORT-2 | E | 📋 PLANNED | M | Sonnet | S-PORT-1 |
| 58 | S-PORT-3 | E | 📋 PLANNED | L | Opus | S-PORT-2 |
| 59 | S-PORT-4 | E | 📋 PLANNED | M | Sonnet | S-PORT-3 |
| 60 | S-PORT-5 | E | 📋 PLANNED | L | Opus | S-PORT-4 |
| 61 | S-PORT-6 | E | 📋 PLANNED | M | Sonnet | S-PORT-5 |

(61 rows; v1.1 has 59 effective sessions across Phase A-E. The 2-row reduction vs v1.0 reflects AR drift remediation absorbed into S-QBO-27 instead of standalone A1/A1a/A1b sessions.)

---

## 12. DECISION LOG

### 12.1 D-ARCH-* (architectural)

| ID | Decision | Status |
|---|---|---|
| D-ARCH-1 through D-ARCH-12 | (See v1.0 §12.1) | LOCKED |
| D-ARCH-13 | Production live at https://mainlandrentals.com/fleetforge since 2026-05-16; D8 unlocked | LOCKED v1.1 |
| D-ARCH-14 | AR drift remediation absorbed into S-QBO-27 historical pull cross-reference | LOCKED v1.1 (pending operator confirm) |

### 12.2 D-ACCT-A1-* (locked in S-ACCT-FIX-A1, deferred)

| ID | Decision | Status |
|---|---|---|
| D-ACCT-A1-1 through A1-7 | (See v1.0 §12.2) | **DEFERRED — applies to S-QBO-27 remediation** |

### 12.3 D-ACCT-* (to be locked in upcoming sessions)

| Range | Phase | Purpose |
|---|---|---|
| D-ACCT-AP-* / DOCS-* | A | Phase A integrity fix decisions |
| D-ACCT-B-* | B | Spec completion decisions |
| D-ACCT-C-* | C | ASPE extensions decisions |
| D-ACCT-D-* | D | Lessor module decisions |

### 12.4 D-QBO-* (to be locked in upcoming sessions)

| Range | Phase | Purpose |
|---|---|---|
| D-QBO-1-* through D-QBO-30-* | QBO | One range per session, expected ~5–10 decisions per session |

**Anticipated to lock in S-QBO-1:**
- D-QBO-1-1: OAuth callback URL format (sandbox: localhost+ngrok; production: `https://mainlandrentals.com/fleetforge/oauth/qbo/callback`)
- D-QBO-1-2: Token storage format (settings table with is_sensitive=1)
- D-QBO-1-3: Refresh-token pinger schedule (daily at 02:00 server time)
- D-QBO-1-4: Realm ID change runbook

**Anticipated to lock in S-QBO-10 (v1.1 update):**
- D-QBO-10-1: `base_rental_reconciliation_credit` QBO representation
- D-QBO-10-2: GPS Item account mapping (depends on S-ACCT-GPS principal/agent decision)

**Anticipated to lock in S-QBO-11 (v1.1 update):**
- D-QBO-11-1 through 11-5 (sync mode, tax-override, FX, SyncToken, engine-version dispatch)

**Anticipated to lock in S-QBO-15 (v1.1 update):**
- D-QBO-15-1 through 15-4 (webhook signature, retry, FF-side timing, return URLs)

**Anticipated to lock in S-QBO-27 (v1.1 — major):**
- D-QBO-27-1 through 27-6 (pull batch, resumability, gating, H5 reconstruction, H6 stop-gate, AR verification)

### 12.5 D-PORT-* (to be locked in upcoming sessions)

Reserved for Phase E.

---

## 13. DOCUMENT MAP

### 13.1 Doc inventory (refreshed for v1.1)

| Document | Purpose | Status |
|---|---|---|
| **FLEETFORGE_ACCOUNTING_QBO_ROADMAP.md** (this doc) | Master roadmap, 59 sessions, decision log, planning | LIVING (v1.1) |
| `FLEETFORGE_SPEC_FINAL.md` | Core FleetForge spec | Living, gets §X added for accountants portal in Phase E |
| `FLEETFORGE_DATABASE_MASTER.sql` | Authoritative DDL for all tables (currently 26 migrations applied) | Updated per session |
| `FLEETFORGE_ACCOUNTING_SPEC.md` | Accounting module spec — currently v1.2 | Becomes v1.3 with new §20–§23 |
| `FLEETFORGE_QUICKBOOKS_SPEC.md` | QBO integration spec | Created in S-QBO-1 |
| `FLEETFORGE_PROGRESS.md` | Session-by-session SESSION LOG | Updated per session (mandatory before commit per K-14) |
| `FLEETFORGE_CURRENT_SESSIONS.md` | Active queue: QUEUED / IN-FLIGHT / SHIPPED | Updated per session per D136 |
| `FLEETFORGE_PREDEPLOY_CHECKLIST.md` | Pre-deploy operator-side obligations (categories A-I) | Updated as operator items surface |
| `FLEETFORGE_CLAUDE_CODE_REFERENCE.md` | Patterns, helper signatures, traps | Updated per session |
| `FLEETFORGE_DESIGN_DETAILS.md` | UI/UX patterns, styling decisions | Updated as needed |
| `FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md` | Audit snapshot baseline | Point-in-time, not modified |

### 13.2 Where content lives (refreshed)

**Accounting work:**
- Original spec → `FLEETFORGE_ACCOUNTING_SPEC.md` §1–§19
- Phase A integrity fixes → §20 (new in v1.3)
- Phase B spec completion → §10/§12/§14 refresh
- Phase C ASPE extensions → §21 (new in v1.3)
- Phase D lessor module → §22 (new in v1.3)
- Phase E portal → §23 (new in v1.3)

**QBO work:** Everything → `FLEETFORGE_QUICKBOOKS_SPEC.md`. Sections: Architecture / OAuth / Sync Infrastructure / Sync Modes / Conflict Resolution / Mapping Tables / Entity Sync Rules / Tax Handling / Multi-Currency / QBO Payments / Error Handling / Rate Limit / CRA Defensibility / Drift Detection / Historical Backfill / Cutover Runbook / UI Surfaces / Cron Jobs / AI Tool Integration / Schema Changes / Open Questions / Changelog.

**Portal work:** Architecture → `FLEETFORGE_SPEC_FINAL.md`; accounting UX → `FLEETFORGE_ACCOUNTING_SPEC.md` v1.3 §23.

**Pre-deploy obligations (K-14):** `FLEETFORGE_PREDEPLOY_CHECKLIST.md` categories A-I.

**Active queue (D136):** `FLEETFORGE_CURRENT_SESSIONS.md` IN-FLIGHT block + queued sections.

### 13.3 Update protocol (refreshed for D131 + D136)

When a session ships:
1. Session must register IN-FLIGHT in CURRENT_SESSIONS.md BEFORE first write (D136).
2. Session must pass D131 gate (6 smokes + migrate verify) before every commit.
3. Session updates its own `FLEETFORGE_PROGRESS.md` SESSION LOG row (K-14 mandatory).
4. Session updates the canonical spec section it modifies.
5. Session flips CURRENT_SESSIONS.md entry IN-FLIGHT → SHIPPED with commit refs.
6. **This roadmap gets one update** to move the session's status row from QUEUED/PLANNED to DONE.
7. New decisions land in this roadmap's Section 12 decision log AND in the relevant canonical spec.

---

## 14. WORKFLOW CONVENTIONS (UPDATED WITH D131 / D136 / K-14 / K-22)

### 14.1 Session prompt format

(Unchanged structure from v1.0; see v1.0 §14.1.)

### 14.2 Mid-session clarification rule

When Claude Code asks D-* clarification questions mid-session, Avi responds with a **complete drop-in replacement prompt block** — never prose sign-offs.

### 14.3 Locked patterns (project-wide)

**Architectural:**
- D5 Soft-delete enforcement on listed tables
- D7 Base path `/fleetforge`
- D14 Sent invoice fields immutable
- D15 Gap-free invoice numbering
- D16 `bcmath` only for monetary math
- D19 Optimistic locking on all updates
- D20 `FOR UPDATE` row locks on lease create/close/payment allocation
- D21 MySQL `GET_LOCK` advisory locks on write-heavy crons
- D25 `function_exists` guards
- D26 `/auth/` route before admin catch-all
- D27 `APP_URL` = origin only
- D28 Never edit `.env` with TextEdit
- D29 `remember_token` on `users` table directly
- D30 Static assets via `asset_url()`
- D75–D78 Sentry SDK + SES bounce webhook patterns (per S-PROD-2)
- D202 Production web server is nginx (not Apache); `.htaccess` inert

**Process (new in v1.1):**
- **D131 Pre-commit gate** — every commit must pass: `_smoke_master_schema_parity.php` PARITY OK + `_smoke_billing_invariants.php` I1-I10 PASS + `_smoke_samsara_distance.php` 16/16 + `_smoke_model_b_lifecycle.php` 20/20 + `_smoke_doc_freshness.php` 17/17 + `bin/migrate.php --verify` (current baseline 26/0/0)
- **D136 Multi-agent discipline** — IN-FLIGHT registration in CURRENT_SESSIONS.md before any write; one IN-FLIGHT at a time on main; multiple IN-FLIGHT-RO permitted; collision detection via Touching field
- **K-14 Pre-deploy obligations separation** — file in PREDEPLOY_CHECKLIST.md categories A-I, not KNOWN ISSUES or CURRENT_SESSIONS
- **K-22 Trust on-disk over chat narrative** — planning-chat prompt-drafting must verify on-disk canonical-file state before drafting build prompts

### 14.4 Commit conventions (unchanged from v1.0)

### 14.5 Model selection (unchanged from v1.0)

### 14.6 Pre-execution review (unchanged from v1.0)

---

## 15. STOP CONDITIONS AND SAFETY PATTERNS

### 15.1 Universal stop conditions

Every Claude Code session must stop and report (not proceed) if:

1. A decision in `DECISIONS LOCKED` would have to be modified to make the session work.
2. A locked pattern (D5/D14/D15/D16/D19/D20/D21/D131/D136) would have to be violated.
3. A read-only audit reveals data that contradicts the session's assumptions.
4. The diagnostic phase of a fix session finds drift in an unexpected direction or magnitude.
5. A spec change would be required mid-session.
6. The session would have to modify a canonical spec file not listed in its deliverables.
7. **(v1.1)** D131 gate fails on any commit attempt.
8. **(v1.1)** D136 IN-FLIGHT collision detected (another write session already running with overlapping touching domains).

### 15.2 Idempotency requirements (unchanged from v1.0)

### 15.3 Locking patterns (unchanged from v1.0)

### 15.4 CRA defensibility patterns (unchanged from v1.0)

---

## 16. RISKS AND MITIGATIONS

### 16.1 Top risks by phase (refreshed for v1.1)

#### Phase A (integrity)

| Risk | Mitigation |
|---|---|
| AP orphans contain real money data | AP prompt has STOP condition if real payment data detected; data recovery strategy needed |
| Documents wiring conflicts with existing StorageClient patterns | Sonnet model session; pre-execution review optional |

(v1.0's H6 Lepore risk moved to Phase QBO — see below.)

#### Phase B (spec completion)

(Unchanged from v1.0.)

#### Phase C (ASPE extensions)

(Unchanged from v1.0.)

#### Phase D (lessor)

(Unchanged from v1.0.)

#### Phase QBO (integration, refreshed for v1.1)

| Risk | Mitigation |
|---|---|
| Refresh token expires silently during low-usage window | Daily refresh-token pinger cron (S-QBO-1) with 14-day-out alert |
| Realm ID changes (accountant migrates QBO file) | Documented runbook in `FLEETFORGE_QUICKBOOKS_SPEC.md`; manual remap process |
| Tax recalculation drift between FF and QBO | S-QBO-9 locks tax-override pattern; drift tolerance $0.05 per invoice |
| Customer name collision in QBO at initial sync | S-QBO-5 mapping UI requires manual confirmation per pair |
| Performance regression on invoice send | S-QBO-11 routes cron-generated invoices to async queue |
| QBO Payments embed has PCI implications | S-QBO-15 locks pattern as hosted-page redirect |
| Production cutover fails | S-QBO-30 has dry-run + 14-day monitoring window + rollback |
| **(v1.1) Dual-engine invoice sync — holistic vs period_independent both push to QBO** | **S-QBO-11 engine-version dispatch reads `lease.engine_version` and applies appropriate mapping; reconciliation_credit line type follows D-QBO-10-1 rule; the 23 active period_independent leases never emit reconciliation credits so no QBO-side mapping ambiguity for those; the 8 closed leases that received 'holistic' default harmlessly never generate further invoices.** |
| **(v1.1) H6 Lepore 1.375× ratio root cause not in QBO data either** | **S-QBO-27 has explicit STOP if H6 doesn't resolve via QBO cross-reference; escalate to dedicated bug investigation in InvoiceGenerator before posting compensating JEs that would mask an active bug** |
| **(v1.1) S-QBO-27 historical pull surfaces drift > $20K (significantly more than the known $17K)** | **S-QBO-27 STOP condition fires above $30K threshold; rescope to dedicated drift session** |
| **(v1.1) Webhook signature verification fails repeatedly under load** | **S-QBO-15 reuses S-PROD-2 Sentry instrumentation for visibility; webhook returns 200 on signature-fail to prevent QBO retry storm; alert via NotificationService when failure rate > 5%** |

#### Phase E (portal)

(Unchanged from v1.0.)

### 16.2 Cross-cutting risks (refreshed for v1.1)

| Risk | Mitigation |
|---|---|
| 59 sessions is a lot; momentum loss mid-Phase C | This roadmap as the keep-on-track artifact; weekly progress review |
| Accountant rejects workflow shift | Pre-Phase QBO conversation; demo S032 AP module to show parity |
| In-flight FleetForge work blocks Phase QBO | **(v1.1 update) Model B mileage arc closed; S-LEASE-RATE-AMENDMENT shipped; S-MILEAGE-3-ACCT-SPEC still CPA-blocked but does not block QBO arc per project memory** |
| Anthropic API pricing changes / model deprecation | Each session's model is upgradeable |
| **(v1.1) D131 gate failure on a commit during a long session** | **Session must pause, fix the gate, then resume — never bypass. K-14 covers when to file as a pre-deploy obligation vs a bug.** |
| **(v1.1) D136 collision: two write sessions trying to run simultaneously** | **Latter session waits in QUEUED state; or upgrades to IN-FLIGHT-RO if read-only work is available; or branches per D136 fallback** |

---

## 17. OPEN QUESTIONS

### 17.1 Pending operator decisions (refreshed)

| ID | Question | Blocker for |
|---|---|---|
| ~~Q-OP-1~~ | ~~Canonical QBO doc filename~~ | **CLOSED v1.1: `FLEETFORGE_QUICKBOOKS_SPEC.md`** |
| ~~Q-OP-2~~ | ~~S-LEASE-RATE-AMENDMENT status~~ | **CLOSED v1.1: shipped 2026-05-13 per D130** |
| Q-OP-3 | When does the accountant get pre-Phase-QBO briefing on the workflow shift? | Pre S-QBO-1 |
| **Q-OP-AR-DRIFT** | **Confirm D-ARCH-14: "AR drift deferred to QBO arc" means S-QBO-27 cross-reference (not a separate pre-QBO cleanup session)** | **S-QBO-27 scope** |

### 17.2 Pending accountant decisions (unchanged from v1.0)

| ID | Question | Blocker for |
|---|---|---|
| Q-CPA-1 | Does the accountant currently use QBO Classes or Locations? | S-QBO-11 |
| Q-CPA-2 | What's the QBO tier? (Plus confirmed; double-check after re-talk) | S-QBO-1 |
| Q-CPA-3 | Custom fields, custom tax codes, custom GL accounts in your QBO file already? | S-QBO-8, S-QBO-9 |
| Q-CPA-4 | Does the accountant ever create invoices directly in QBO that didn't originate in FF? | S-QBO-12 |
| Q-CPA-5 | Acceptable bill-entry workflow shift (FF instead of QBO)? | Phase QBO start |
| Q-CPA-6 | Compilation vs review engagement target? | Phase E scope |
| Q-CPA-7 | Sign-off on lease classification wizard before first sales-type lease activates | S-ACCT-LESSOR-3 |

### 17.3 Pending external dependencies (refreshed)

| ID | Question | Blocker for |
|---|---|---|
| ~~Q-EXT-1~~ | ~~AWS Lightsail provisioning~~ | **CLOSED v1.1: Production live since 2026-05-16 per D-ARCH-13** |
| Q-EXT-2 | 2024 FES AccII reinstatement — enacted or still proposed? | S-ACCT-CCA-2 (configurable, can ship and toggle later) |
| Q-EXT-3 | Production QBO app registration (Intuit Developer) | S-QBO-29 |

---

## 18. GLOSSARY

### 18.1 ASPE terms (unchanged from v1.0)

### 18.2 FleetForge / QBO terms (refreshed for v1.1)

(See v1.0 §18.2 for base list. Additions:)

- **HolisticLeaseEngine** — `lib/Billing/HolisticLeaseEngine.php`, the running-reconciliation billing engine that replaces ProRateCalculator THE LAW for new leases (shipped S-BILLING-HOLISTIC-ENGINE 2026-05-17).
- **ProRateCalculator** — the legacy period-independent billing engine, still used by 23 active+pending leases that were locked to `engine_version='period_independent'` at S-BILLING-HOLISTIC-ENGINE migration time.
- **`base_rental_reconciliation_credit`** — new invoice line item type (is_credit=1) emitted by the holistic engine when a prior period overcharged; reconciles via running cumulative correctness.
- **`base_rental_reconciliation_overflow`** — new credit_notes source type emitted by the S-FIX-2 Bug #3 overflow handler when a holistic reconciliation credit exceeds the invoice's positive total.
- **`gps`** — new invoice line item type for the per-day GPS tracking add-on (S-LEASE-GPS-COST).
- **D131** — pre-commit gate (parity + invariants + samsara + model_b + doc_freshness + migrate verify).
- **D136** — multi-agent discipline (IN-FLIGHT registration before write).
- **D202** — production web server is nginx; `.htaccess` inert.
- **K-14** — pre-deploy obligations separation (file in PREDEPLOY_CHECKLIST, not KNOWN ISSUES or CURRENT_SESSIONS).
- **K-22** — planning-chat prompts must trust on-disk canonical state over chat narrative.
- **SES bounce webhook** — `api/v1/webhooks/ses_notifications.php`, the S-PROD-2 template that S-QBO-15 webhook will mirror.
- **PREDEPLOY_CHECKLIST.md** — pre-deploy operator-side obligations file (categories A-I).
- **CURRENT_SESSIONS.md** — active session queue (QUEUED / IN-FLIGHT / IN-FLIGHT-RO / SHIPPED / DEFERRED / SUPERSEDED).

### 18.3 Status icons (unchanged from v1.0)

---

## 19. V1.0 → V1.1 CHANGELOG

This is the canonical record of what changed between v1.0 (2026-05-06) and v1.1 (2026-05-18).

### 19.1 Why v1.1 was needed

v1.0 was built from chat history without verifying on-disk canonical state. Between v1.0 ship and 2026-05-18, multiple material changes happened in the codebase that invalidated specific roadmap sections. Per K-22 (locked 2026-05-13 in S-K22-LOCK), this is a planning-discipline violation. v1.1 corrects by reading the on-disk state and refreshing.

### 19.2 Material changes since v1.0

| Event | Date | Impact on roadmap |
|---|---|---|
| **S-ACCT-FIX-A1 ran read-only diagnostic but crashed before commit** | 2026-05-07 | Per operator: AR drift remediation deferred to QBO arc. Phase A drops A1/A1a/A1b. |
| **S-PROD-2 shipped** (Sentry + SES bounce webhook + key rotation runbook) | 2026-05-02 | S-QBO-15 webhook design reuses S-PROD-2 pattern. New D75–D78 locked. |
| **Model B mileage arc closed** (S-MILEAGE-1 through -5 + portal) | 2026-05-13 | Blockers cleared for Phase QBO. |
| **S-LEASE-RATE-AMENDMENT shipped** under D130 | 2026-05-13 | Q-OP-2 closed. |
| **HANDOFF-PREP + S-D136-COMMIT-DISCIPLINE + multi-agent discipline locks** | 2026-05-13 | D136 + K-22 added to workflow conventions. |
| **Production deployment to AWS Lightsail** | 2026-05-16 | D8 unlocked; D-ARCH-13 locked; D202 (nginx) locked; Q-EXT-1 closed. |
| **S-BILLING-HOLISTIC-ENGINE shipped** | 2026-05-17 | New ENUMs in invoice_line_items + credit_notes + leases; S-QBO-10 + S-QBO-11 need engine-version dispatch. |
| **S-LEASE-GPS-COST shipped** (already pre-v1.0 but stale in roadmap) | (before 2026-05-06) | New `gps` item_type in S-QBO-10 mapping. |
| **D131 pre-commit gate operational** | (cumulative) | Added to §14 + §15. |
| **Migration count 19 → 26** | (cumulative) | §3.1 baseline + §14 D131 reference. |

### 19.3 Sections rewritten in v1.1

| Section | Change |
|---|---|
| §1.1 Purpose | Session count 62 → 59 |
| §1.5 Canonical docs | Added CURRENT_SESSIONS.md + PREDEPLOY_CHECKLIST.md |
| §2.9 Decision summary | Added D-ARCH-13 + D-ARCH-14 |
| §3 Current state diagnostic | Complete rewrite — added §3.2 (what shipped), §3.3 (disciplines locked), §3.4 (what's still open), §3.7 (top 5 refreshed) |
| §4 Phase order | D8 unlocked; calendar refreshed |
| §5 Phase A | Reduced from 5 sessions to 2; A1/A1a/A1b absorbed into S-QBO-27; renamed A2→AP and A3→DOCS |
| §7.3 S-ACCT-GPS | Added production state context ($1/day default, opt_in=1) |
| §9.4 S-QBO-10 | Refreshed item_type list; added D-QBO-10-1 for reconciliation_credit |
| §9.4 S-QBO-11 | Added engine-version dispatch |
| §9.4 S-QBO-15 | Reused S-PROD-2 webhook pattern |
| §9.4 S-QBO-21 | Updated to reference 12 AutoEntryBridge methods (7 active + 5 stubs) |
| §9.4 S-QBO-27 | Effort bumped L → XL; AR drift remediation absorbed; H5 + H6 sub-task detail |
| §9.4 S-QBO-29 | D8 unlocked context |
| §11 Session index | Refreshed: 59 effective sessions |
| §12.1 D-ARCH | Added D-ARCH-13 + D-ARCH-14 |
| §12.2 D-ACCT-A1 | Marked deferred |
| §12.4 D-QBO | Added anticipated locks for S-QBO-10/-11/-15/-27 |
| §13.1 Doc inventory | Added CURRENT_SESSIONS.md + PREDEPLOY_CHECKLIST.md |
| §13.3 Update protocol | Added D131 gate + D136 IN-FLIGHT steps |
| §14.3 Locked patterns | Added D131 + D136 + K-14 + K-22 + D75–D78 + D202 |
| §15.1 Stop conditions | Added D131 gate + D136 collision |
| §16.1 Phase A risks | Reduced (H6 moved to Phase QBO) |
| §16.1 Phase QBO risks | Added dual-engine sync risk + H6 cross-reference risk + drift > $20K risk + webhook failure risk |
| §16.2 Cross-cutting risks | Added D131 + D136 failure modes; updated in-flight blockers |
| §17.1 Operator questions | Closed Q-OP-1 + Q-OP-2; added Q-OP-AR-DRIFT |
| §17.3 External dependencies | Closed Q-EXT-1 |
| §18.2 Glossary | Added HolisticLeaseEngine, ProRateCalculator, new ENUM values, D131, D136, D202, K-14, K-22, SES bounce webhook, PREDEPLOY_CHECKLIST.md, CURRENT_SESSIONS.md |
| §19 (new) | This changelog |

### 19.4 Sections that did NOT change

§1.2 Audience, §1.3 How to read, §1.4 Scope coverage, §2.1–§2.8 (all but §2.9 decision summary), §6 Phase B (sessions unchanged), §7 Phase C sessions list (only S-ACCT-GPS scope adjusted), §8 Phase D, §9.4 QBO Phase 1 / 2 / 3 / 6 (most) / 7 / 8 / 9 / 11 / 12 / 14 (S-QBO-30) / Phase E §10, §14.1, §14.2, §14.4, §14.5, §14.6.

### 19.5 Next planned roadmap version

**v1.2 triggers:** any of the following:
- S-ACCT-FIX-AP ships → status update + Phase A progress reflection
- S036 ships → Phase B progress reflection
- Material new ASPE / CRA regulation (e.g., AccII reinstatement enacted) → §3.5 and §12 refresh
- New audit run against current state → §3 refresh
- Schema migration major shift → §3.2 + §14 D131 baseline refresh
- Phase QBO session 1 ships → roadmap responsibilities migrate to `FLEETFORGE_QUICKBOOKS_SPEC.md`

---

*End of FleetForge — Accounting + QuickBooks + Accountants Portal Master Roadmap v1.1.*
*Next update trigger: S-ACCT-FIX-AP completion → status flip + any new D-ACCT-AP-* decisions added to §12.*
