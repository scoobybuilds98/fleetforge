# FleetForge QBO Integration — Remaining Work as of 2026-05-26

**Session:** S-QBO-INVENTORY-AND-ROADMAP-2026-05-26 (RO; no production code or schema changes)
**Owner:** Avi (Mainland Truck & Trailer Sales)
**Purpose:** Single navigation doc for the remaining QBO integration build-out + paused testing arc, sequenced so the operator can fire the next 5-10 sessions without first re-deriving status from [FLEETFORGE_QUICKBOOKS_PROGRESS.md](FLEETFORGE_QUICKBOOKS_PROGRESS.md), [FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md), [FLEETFORGE_QUICKBOOKS_SPEC.md](FLEETFORGE_QUICKBOOKS_SPEC.md), and [FLEETFORGE_CURRENT_SESSIONS.md](FLEETFORGE_CURRENT_SESSIONS.md).
**Scope:** Factual inventory + neutral recommendations. Cross-links to canonical docs; does not duplicate their content. No new architectural decisions are made here — items requiring a decision are flagged for a planning chat.

**Progress markers added 2026-05-27** (point-in-time deltas since the 2026-05-26 inventory snapshot):
- ✅ **S-QBO-12** SHIPPED 2026-05-27 (Phase QBO-5 COMPLETE; pushUpdate + pushVoid; D-QBO-12-1/2/3/4/5)
- ✅ **S-VENDOR-CURRENCY-COLUMN** SHIPPED 2026-05-27 (closes D-QBO-FIXPACK-8 backlog; D-VENDOR-CURRENCY-COLUMN-1/4)
- ✅ **S-DOC-FRESHNESS-EXPAND** SHIPPED 2026-05-27 (infrastructure paydown — `_smoke_doc_freshness.php` +5 classes; D-DOC-FRESHNESS-EXPAND-1)
- ✅ **S-QBO-18** SHIPPED 2026-05-27 (Phase QBO-8 / 1 of 2 — bill push; first AP-direction Pusher; D-QBO-18-1/2/3/4/5/6/7)
- ✅ **S-QBO-BILL-SYNC-UI** SHIPPED 2026-05-27 (admin surface mirror of /quickbooks/invoices for bills; no D-* lock — pattern-mirror)
- ✅ **S-QBO-BILL-GOTCHAS-PAYDOWN** SHIPPED 2026-05-27 (closes 4 gotchas from S-QBO-18 live test: currency-mismatch gate 8, typed field_too_long status, input-time DocNumber validation, AccountValidator empty-cat hint improvement; D-QBO-BILL-GOTCHAS-1/2)
- ✅ **S-DOC-FRESHNESS-EXPAND-2** SHIPPED 2026-05-27 (infrastructure paydown — closes blind spots in S-DOC-FRESHNESS-EXPAND; 4 defense-in-depth layers including CLASS 10 git-log anchor that caught S-SES-DIAG; D-DOC-FRESHNESS-EXPAND-2-1/2/3)
- ✅ **S-QBO-13** SHIPPED 2026-05-27 (Phase QBO-6 / 1 of 3 — QBO Payment pull webhook; Exception #1 per D-QBO-CORE-2; QboWebhookSignature + PaymentWebhookHandler + webhook endpoint + 2 new tables + payments.origin column; D-QBO-13-1/2/3/4/5/6)
- ✅ **S-QBO-14** SHIPPED 2026-05-28 (Phase QBO-6 / 2 of 3 — payment push FF → QBO; PaymentPusher 8 inline preflight gates + PaymentEnqueuer gate 0 origin='ff_native' + status='cleared'; **NO migration — reuses acc_qbo_payment_map from S-QBO-13**; D-QBO-14-1 closes D-QBO-13-1/2 bidirectional dedup at push layer via defense-in-depth at both enqueue and dispatch layers; D-QBO-14-1/2/3/4/5/6/7)
- Top-5 progress: ✅ ✅ ✅ ✅ ✅ **ALL COMPLETE** (S-QBO-12 + S-VENDOR-CURRENCY-COLUMN + S-QBO-18 + S-QBO-13 + S-QBO-14); operator's next pick — **S-QBO-15** (M Sonnet QBO Payments portal embed; completes Phase QBO-6) OR pivot to other slates (S-QBO-16 credit_memo, S-QBO-19 bill_payment, S-QBO-20 deposits, S-QBO-21 journal_entry) OR defragment debt-paydowns
- Phase QBO numbered total: 11/30 → 14/30 → **15/30** (S-QBO-12 + S-QBO-18 + S-QBO-13 + S-QBO-14 advance the counter; SYNC-UI + GOTCHAS-PAYDOWN are debt-paydowns)
- Debt-paydown/infra total: 27 → **30** (S-VENDOR-CURRENCY-COLUMN + S-QBO-BILL-SYNC-UI + S-QBO-BILL-GOTCHAS-PAYDOWN registered as QBO paydowns; S-DOC-FRESHNESS-EXPAND/EXPAND-2 not counted — project infrastructure)
- D131 smoke count: 22 → 24 → **25** (S-QBO-18 added qbo_bill_push; S-QBO-13 added qbo_payment_webhook; S-QBO-14 added qbo_payment_push; SYNC-UI + GOTCHAS-PAYDOWN + DOC-FRESHNESS-EXPAND-2 extend existing smokes in-place)
- Migrate count: 65 → **71** (6 migrations in 2026-05-27 burst; S-QBO-14 ships NO migration — reuses S-QBO-13 schema)
- **Phase QBO-6 progress**: 2/3 (S-QBO-13 + S-QBO-14 ✅; S-QBO-15 portal embed remaining)
- QBO live verification: **2nd real FF→QBO push on record** — Bill #148 (LIVETEST-BILL-20260527-162957 / FF#999992, $262.50 CAD) pushed to sandbox realm 9341457119548719 during S-QBO-18 live test 2026-05-27 16:29:59. 1st was Invoice #147 from S-QBO-LIVE-VERIFY-RERUN-2026-05-26.

---

## 0. Top 5 recommended next sessions (read this first)

Tomorrow-actionable picks, ordered to maximize forward progress on the build-out arc per operator pivot.

| # | Session | Class | Size | Model | Rationale |
|---|---|---|---|---|---|
| 1 | ✅ **S-QBO-12** SHIPPED 2026-05-27 | BUILD | L | Opus | Closes Phase QBO-5 by implementing `pushUpdate` (was stubbed in S-QBO-11 per D-QBO-11-4) + the void operation. Uses the `QuickBooksClient::voidEntity()` already shipped in S-QBO-11-POSTVERIFY-FIXES (proactive infrastructure paid down 2026-05-25). Unblocks every downstream "drift detected — re-push the FF state" workflow. **Outcome:** D-QBO-12-1/2/3/4/5 locked; smoke 52→61; migration 66→67/0/0 (push_status ENUM +voided); D131 22/22 PASS. |
| 2 | ✅ **S-VENDOR-CURRENCY-COLUMN** SHIPPED 2026-05-27 | DEBT | XS | Sonnet | Closes D-QBO-FIXPACK-8 backlog. Migration adds `vendors.currency ENUM('CAD','USD') NOT NULL DEFAULT 'CAD'` (67→68/0/0; mirrors customers.currency). VendorPusher reads per-row currency via `strtoupper((string) ($ff['currency'] ?? 'CAD'))`. API endpoints accept currency. **Outcome:** D-VENDOR-CURRENCY-COLUMN-1/4 locked; D-QBO-FIXPACK-8 SUPERSEDED; smoke 18→20 (C11 reworded, C12 flipped, C12b/C12c new); D131 22/22 PASS. K-22 catch surfaced: MySQL ALTER COLUMN syntax — COMMENT must precede AFTER (now Trap #69 in REFERENCE.md). |
| 3 | ✅ **S-QBO-18** SHIPPED 2026-05-27 | BUILD | M | Sonnet | Bill push (FF → QBO). Highest *workflow-shift* value session — Phase QBO-8 is the first time bill entry moves from QBO to FF (D-CPA-5 conversation deliverable). **Outcome:** Migration acc_qbo_bill_map (18 cols mirroring invoice_map with bill-specific deltas); BillPusher + BillEnqueuer with 7 preflight gates + AccountBasedExpenseLineDetail per D-QBO-18-3 + tax-override per D-QBO-18-2 + DocNumber = vendor_bill_number ?? bill_number per D-QBO-18-7; pushUpdate stubbed → S-QBO-19 (D-QBO-18-5). approve.php +enqueue. 3 K-22 catches mid-session (account_type ENUM, sync_log column names, sync_queue no last_error). Smoke 20/20 NEW; D131 22→23. 7 decisions locked. ITC tax-rate mapping per QBO_SPEC §8.8 deferred to S-QBO-BILL-ITC-TAX-RATE-MAPPING follow-up because S-QBO-9 only maps tax CODES, not RATES. |
| 4 | ✅ **S-QBO-13** SHIPPED 2026-05-27 | BUILD | L | Opus | Payment pull from QBO via webhook (Exception #1 per D-QBO-CORE-2). Foundation for both S-QBO-14 (payment push) and S-QBO-15 (portal embed). Reuses the [SES webhook pattern](FLEETFORGE_QUICKBOOKS_SPEC.md#L1737) from S-PROD-2 (D75-D78) — signature verification, idempotency, Sentry instrumentation. **Outcome:** acc_qbo_payment_map + acc_qbo_webhook_events + payments.origin shipped; QboWebhookSignature + PaymentWebhookHandler + endpoint; D-QBO-13-1/2/3/4/5/6 locked; smoke 20/20 NEW; D131 23→24; migrate 70→71/0/0. |
| 5 | ✅ **S-QBO-14** SHIPPED 2026-05-28 | BUILD | M | Sonnet | Payment push (FF → QBO). Pairs naturally with S-QBO-13. Completes the payment surface so cutover blockers around AR/UF clearing are unblocked. Requires `assertReadyForPaymentPush()` (DONE) + customer mapping (DONE). **Outcome:** PaymentPusher + PaymentEnqueuer; **NO migration — reuses acc_qbo_payment_map from S-QBO-13**; D-QBO-14-1 closes D-QBO-13-1/2 bidirectional dedup at push layer via defense-in-depth at both enqueue and dispatch layers; 8 inline preflight gates incl. UF resolution + currency-mismatch + 21-char PaymentRefNum; pushUpdate stubbed → S-QBO-14-UPDATE-FOLLOWUP. D-QBO-14-1/2/3/4/5/6/7 locked; smoke 20/20 NEW; qbo_queue C8/C9 updated for shipped Pusher accounting; D131 24→25; migrate held at 71/0/0 (NO MIGRATION). Operator follow-up: UndepositedFunds account mapping must be tagged BEFORE first push works. |

**If operator prefers all-build (skip the debt paydown):** swap #2 for **S-QBO-19** (Bill payment push, M, Sonnet — completes the AP surface alongside #3). [S-VENDOR-CURRENCY-COLUMN](#5-debt--backlog-debt-class) remains queued in backlog.

**If operator prefers conservative early-cutover-path coverage:** swap #4 + #5 for **S-QBO-21** (JE push, L, Opus) + **S-QBO-20** (Bank mirror, M, Sonnet). JE push is the largest single integration (touches all 12 AutoEntryBridge methods); bank mirror is read-only and Exception #2.

See [§6 Recommended next 5-10 sessions](#6-recommended-next-5-10-sessions-operator-actionable) for the wider 10-deep slate.

---

## TABLE OF CONTENTS

1. [Status snapshot](#1-status-snapshot)
2. [Build sessions remaining (BUILD class)](#2-build-sessions-remaining-build-class)
3. [Testing arc status (TESTING class)](#3-testing-arc-status-testing-class)
4. [Pre-cutover and operator sessions](#4-pre-cutover-and-operator-sessions)
5. [Debt + backlog (DEBT class)](#5-debt--backlog-debt-class)
6. [Recommended next 5-10 sessions (operator-actionable)](#6-recommended-next-5-10-sessions-operator-actionable)
7. [Dependency graph](#7-dependency-graph)
8. [Cutover critical path](#8-cutover-critical-path)
9. [Items requiring a planning chat before a session can fire](#9-items-requiring-a-planning-chat-before-a-session-can-fire)
10. [Drift between canonical docs](#10-drift-between-canonical-docs)

---

## 1. STATUS SNAPSHOT

### 1.1 Shipped sessions (ship counts by phase, as of 2026-05-26)

Counted from [FLEETFORGE_PROGRESS.md SESSION LOG](FLEETFORGE_PROGRESS.md) rows tagged `S-QBO-*` plus the 6-session arc that closed today (2026-05-26 / 27).

| Phase | Numbered sessions shipped | Source rows |
|---|---|---|
| **Phase QBO-1 — Foundation** (4 of 4 ✅) | S-QBO-1, S-QBO-2, S-QBO-3, S-QBO-4 | [PROGRESS rows 128-131](FLEETFORGE_PROGRESS.md) |
| **Phase QBO-2 — Customers** (2 of 2 ✅) | S-QBO-5, S-QBO-6 | PROGRESS rows 133, 135 |
| **Phase QBO-3 — Vendors** (1 of 1 ✅) | S-QBO-7 | PROGRESS row 139 |
| **Phase QBO-4 — Reference data** (3 of 3 ✅) | S-QBO-8, S-QBO-9, S-QBO-10 | PROGRESS rows 137-138 + 136 |
| **Phase QBO-5 — Invoices** (1 of 2 ✅; S-QBO-11 DONE; S-QBO-12 pending) | S-QBO-11 (+ FIXPACKs) | PROGRESS row 140 + FIXPACK rows |
| **Phase QBO-6/7/8/9/10/11/12/13/14** | 0 of 19 | — |

**Numbered-phase total shipped: 11 of 30.** Confirms the [QUICKBOOKS_PROGRESS.md §1 header](FLEETFORGE_QUICKBOOKS_PROGRESS.md) "Phase QBO: 11/30" tally in the S-QBO-11 SESSION LOG row (which lags the "9 of 30" claim in the file-level header — see §10).

### 1.2 Debt-paydown / infra sessions shipped (not on numbered-phase counter)

| Session | Date | Purpose |
|---|---|---|
| S-QBO-OAUTH-FIX | 2026-05-20 | DB-backed OAuth state tokens (K-22 Trap #59) |
| S-QBO-5-FIX-1 | 2026-05-21 | Centralized QBO `QueryResponse` normalization (K-22 Trap #60) |
| S-QBO-MATCHER-GREEDY-FIX | 2026-05-24 | AccountMatcher claimed-set + subtype-agreement gate |
| S-QBO-VALIDATOR-SCOPE-SPLIT | 2026-05-24 | AccountValidator per-session gates (`assertReadyFor*Push()` ×6) + `critical_category` column |
| S-QBO-11-FIXPACK-1 | 2026-05-24 | InvoicePusher F2 (CurrencyRef CAD→USD silent coercion) + F3 (DocNumber 21-char overflow) |
| S-QBO-FIXPACK-2 | 2026-05-25 | CustomerPusher + VendorPusher CurrencyRef emission (sibling to FIXPACK-1) |
| S-QBO-FIXPACK-3 | 2026-05-25 | Multi-currency auto-detection via `CompanyInfoSync` + worker failure-handling audit (Bugs A/B/C) |
| S-QBO-11-POSTVERIFY-FIXES | 2026-05-25 | D131 19→22 (settings audit smokes) + `QuickBooksClient::voidEntity()` for S-QBO-12 |
| S-QBO-PHASE-1-TRAP-SWEEP | 2026-05-26 | K-22 trap-pattern audit (12 patterns × 5 dirs); 1 CRITICAL surfaced |
| S-PORTAL-INVOICE-TAX-DISPLAY-FIX | 2026-05-26 | Phase 1 CRITICAL fix (portal `gst_amount`/`pst_amount` → `tax_*_amount`) |
| S-QBO-PHASE-2-CONTRACT-AUDIT | 2026-05-26 | §6.8/§6.9 conformance audit; 0 CRITICAL / 2 MEDIUM / 5 LOW |
| S-QBO-PUSHER-CONTRACT-PAYDOWN | 2026-05-26 | Closes Phase 2 MEDIUM-C6 (return-shape parity) + MEDIUM-C9 (FIXPACK-10 backport to Invoice) |
| S-QBO-PHASE-3-VALIDATOR-GATE-AUDIT | 2026-05-26 | 6×5 coverage matrix of `assertReadyFor*Push()`; 1 CRITICAL / 6 MEDIUM / 3 LOW (all SMOKE ADDITION) |
| S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE | 2026-05-26 | Closes Phase 3 CRITICAL + 6 MEDIUM (3 LOWs deferred → F-P3-08/09/10) |
| S-D131-BASELINE-RESTORE | 2026-05-26 | D131 baseline diagnostic; locked D-D131-DISCIPLINE (actual-not-aspirational SC reporting) |
| S-INVOICE-COUNTER-BUMP | 2026-05-26 | Closed model_b S07 via 1-line counter UPDATE + defensive smoke WARN |
| S-QBO-WORKER-FALSE-COMPLETE-DIAGNOSIS | 2026-05-26 | RO diagnosis of silent false-complete bug class (queue 180 root cause) |
| S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE | 2026-05-27 | Closes the false-complete bug class for invoice path; universal `outcome` field + worker dispatch |
| S-QBO-LIVE-VERIFY-RERUN-2026-05-26 | 2026-05-27 | Live FF→QBO push against sandbox; invoice 42 skip verified, invoice 236 pushed (QBO #147) |
| S-QBO-ENQUEUER-ELIGIBILITY-GATE | 2026-05-27 | Defensive gate-0 in all 3 Enqueuers (closes upstream side of false-complete class) |
| S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA | 2026-05-27 | Mirrored Invoice push-state infra (`push_status` ENUM + helpers) to Customer + Vendor maps |
| S-QBO-MATCHER-WEDGE-RECOVERY | 2026-05-27 | Pass-0 `rescueHalfStateRows()` across all 5 Matchers; closes wedged half-state recovery gap |
| S-SETTINGS-ENDPOINTS-PREVIEW-SKIP | 2026-05-26 | Closed last D131 persistent failure (`_smoke_settings_endpoints.php` skip-when-unreachable pre-flight) |
| S-DEMO-WIPE-COUNTER-SYNC | 2026-05-26 | Fixed recurring counter drift root cause in `scripts/demo_wipe.php` (MAX+1 computation replaces hardcoded value=1) |
| S-COUNTER-DRIFT-SECOND-PATH-AUDIT | 2026-05-26 | Per-smoke isolation found culprit (`_smoke_settings_roundtrip.php` snapshot SELECT filter excluding label-less keys); 1-line fix |
| S-QBO-INVOICE-LIST-BADGE | 2026-05-26 | Per-row QBO push-state column on `app/admin/invoices/index.php` main invoice list (4-classification vocabulary) |
| S-QBO-INVOICE-SHOW-RICH-PANEL | 2026-05-26 | Replaced S-QBO-11 simple header badge with maximalist inline sync panel on invoice show (6-state vocab + push history table + actions) |

**Debt-paydown / infra count: 27.** Combined with 11 numbered phases = **38 QBO-prefixed sessions on record** through 2026-05-26 (+27).

### 1.3 Pushers operational

| Pusher | Class | Status | Notes |
|---|---|---|---|
| `CustomerPusher` (S-QBO-6) | §6.8 dispatcher | ✅ DONE | pushCreate + pushUpdate live; CurrencyRef emission gated on multi_currency_enabled (D-QBO-FIXPACK-12); D-QBO-FIXPACK-10 mismatch warning live; push-state infra mirrored (S-QBO-CUSTOMER-VENDOR-PUSH-STATE-INFRA) |
| `VendorPusher` (S-QBO-7) | §6.8 dispatcher | ✅ DONE | pushCreate + pushUpdate live; CurrencyRef hardcoded 'CAD' (D-QBO-FIXPACK-8); push-state infra mirrored |
| `InvoicePusher` (S-QBO-11) | §6.8 dispatcher | ✅ DONE (pushCreate) / 🟡 STUB (pushUpdate per D-QBO-11-4 → S-QBO-12); pushVoid absent (→ S-QBO-12); tax-override + FX + engine-version dispatch + 6 pre-flight gates live |
| `PaymentPusher` (S-QBO-14) | §6.8 dispatcher | 📋 PLANNED | Sync_mode default 'sync' per S-QBO-3 |
| `CreditMemoPusher` (S-QBO-16) | §6.8 dispatcher | 📋 PLANNED | |
| `RefundReceiptPusher` (S-QBO-17) | §6.8 dispatcher | 📋 PLANNED | CPA-blocked on [D-I (A)](FLEETFORGE_PROGRESS.md) / D176 / S-MILEAGE-3-ACCT-SPEC |
| `BillPusher` (S-QBO-18) | §6.8 dispatcher | 📋 PLANNED | Default sync_mode 'queue' |
| `BillPaymentPusher` (S-QBO-19) | §6.8 dispatcher | 📋 PLANNED | Default sync_mode 'queue' |
| `JournalEntryPusher` (S-QBO-21) | §6.8 dispatcher | 📋 PLANNED | Touches all 12 AutoEntryBridge methods (7 active + 5 hook stubs); default sync_mode 'sync' for most JE classes |

### 1.4 Pullers + matchers operational

| Class | Source session | Status |
|---|---|---|
| `CustomerPuller` / `CustomerMatcher` | S-QBO-5 | ✅ DONE |
| `VendorPuller` / `VendorMatcher` | S-QBO-7 | ✅ DONE |
| `AccountPuller` / `AccountMatcher` / `AccountValidator` | S-QBO-8 (extended S-QBO-VALIDATOR-SCOPE-SPLIT) | ✅ DONE |
| `TaxCodePuller` / `TaxCodeMatcher` | S-QBO-9 | ✅ DONE |
| `ItemPuller` / `ItemMatcher` / `ItemCreator` | S-QBO-10 | ✅ DONE (Creator pattern per D-QBO-10-3/4, not §6.8 Pusher) |
| `CompanyInfoSync` | S-QBO-FIXPACK-3 | ✅ DONE (multi_currency_enabled gate source) |
| `PaymentPuller` (webhook handler) | S-QBO-13 | 📋 PLANNED |
| Bank CDC | S-QBO-20 | 📋 PLANNED |

### 1.5 Full-compliance gate status

`AccountValidator::assertReadyForFullCompliance()` requires all 6 critical categories mapped: `ar_clearing`, `ap_clearing`, `undeposited_funds`, `tax_receivable`, `tax_payable`, `sales_revenue` (per [D-QBO-VALIDATOR-3](FLEETFORGE_PROGRESS.md)).

**Live state (per S-QBO-LIVE-VERIFY-RERUN-2026-05-26 sandbox state):** 2 of 6 mapped (ar_clearing via FF 1030 manually rescued on row 4302; sales_revenue via FF 4122 → QBO #79 auto-matched). 4 categories still unmapped/empty in sandbox: `ap_clearing`, `undeposited_funds` (empty-cat per Trap #68 — no FF account tagged), `tax_receivable`, `tax_payable`.

Live-prod (cutover-time) full-compliance is a S-QBO-30 gate, not a per-session gate. The per-session `assertReadyFor*Push()` gates currently in effect: `assertReadyForInvoicePush()` (ar_clearing + sales_revenue — both mapped in sandbox ✅), `assertReadyForPaymentPush()` (ar_clearing + UF — UF empty in v1 chart per D-QBO-VALIDATOR-3 operator resolution), `assertReadyForBillPush()` (ap_clearing — unmapped), etc.

### 1.6 Current production sync state

| Setting | Live value | Source |
|---|---|---|
| `quickbooks.sync_enabled` | `'0'` (kill-switch OFF) | Locked per [D-CPA-5](FLEETFORGE_PROGRESS.md) until S-QBO-30 |
| `quickbooks.dry_run_mode` | `'0'` | Default; operator flips temporarily for live verification |
| `quickbooks.payments_enabled` | `'0'` | Default; flips at S-QBO-15 portal embed cutover |
| `quickbooks.multi_currency_enabled` | `'0'` (Intuit US sandbox is single-currency) | Auto-set by `CompanyInfoSync` per D-QBO-FIXPACK-11 |
| `quickbooks.home_currency` | `'CAD'` (default seed) / live sandbox: `'USD'` | `CompanyInfoSync.countryToCurrency` (CA→CAD, US→USD) |
| `quickbooks.tax_override_code_id` | populated by S-QBO-9 `identifyOverrideTarget('NON')` | Load-bearing for invoice tax-override per [D-QBO-CORE-6](FLEETFORGE_QUICKBOOKS_PROGRESS.md) |

### 1.7 D131 baseline state (post S-D131-BASELINE-RESTORE + S-INVOICE-COUNTER-BUMP)

**21/22 PASS** with 1 environmental DEFERRED (`_smoke_settings_endpoints.php` requires `php -S` on localhost:8899 — DEFERRED per S-D131-BASELINE-RESTORE; not a regression).

D131 discipline locked: [D-D131-DISCIPLINE](FLEETFORGE_PROGRESS.md) requires SESSION LOG SC column to state *actual* not aspirational state.

---

## 2. BUILD SESSIONS REMAINING (BUILD CLASS)

19 numbered S-QBO-* sessions remain plus 4 pre-cutover operator-execution sessions (per [ROADMAP §20](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md)). Effort and model assignments lifted from [ROADMAP v1.1 §11 session index](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md) and [QUICKBOOKS_PROGRESS.md §1 status board](FLEETFORGE_QUICKBOOKS_PROGRESS.md).

| ID | Title | Phase | Size | Model | Depends on | Status | Notes |
|---|---|---|---|---|---|---|---|
| **S-QBO-12** | Invoice modification + void semantics | QBO-5 | L | Opus | S-QBO-11 ✅ | 📋 PLANNED | Implements `InvoicePusher::pushUpdate` (stubbed in S-QBO-11 per D-QBO-11-4) + `pushVoid` using `QuickBooksClient::voidEntity()` already shipped in S-QBO-11-POSTVERIFY-FIXES; respects D14 immutability for sent invoices |
| **S-QBO-13** | Payment pull (QBO → FF) via webhook | QBO-6 | L | Opus | S-QBO-12 | ✅ DONE 2026-05-27 | Exception #1 per D-QBO-CORE-2; new `acc_qbo_webhook_events` table; HMAC-SHA256 signature verification per Intuit spec; reuses S-PROD-2 webhook pattern (D75-D78); idempotent handler. D-QBO-13-1/2/3/4/5/6 locked. |
| **S-QBO-14** | Payment push (FF → QBO) | QBO-6 | M | Sonnet | S-QBO-13 | ✅ DONE 2026-05-28 | `PaymentPusher` allocates to invoice via LinkedTxn (TxnType='Invoice' per D-QBO-14-3); D-QBO-14-1 closes D-QBO-13-1/2 bidirectional dedup at push layer; requires `assertReadyForPaymentPush()` (UF + AR clearing — DONE). NO migration — reuses acc_qbo_payment_map from S-QBO-13. D-QBO-14-1/2/3/4/5/6/7 locked. |
| **S-QBO-15** | QBO Payments embed in customer portal | QBO-6 | XL | Opus | S-QBO-14 | 📋 PLANNED | "Pay Online" button on portal invoice show; hosted-page redirect; webhook back to FF on success; 4 D-QBO-15-* decisions anticipated |
| **S-QBO-16** | Credit memo push | QBO-7 | M | Sonnet | S-QBO-15 | 📋 PLANNED | `CreditMemoPusher`; two-step JE bridge (creation + application); LinkedTxn to QBO Invoice |
| **S-QBO-17** | Refund receipt push | QBO-7 | M | Sonnet | S-QBO-16 | 📋 PLANNED ⚠️ | **CPA-blocked** on S-MILEAGE-3-ACCT-SPEC resolution (D-I (A) / D176; CPA-blocked on 5 questions); can defer if S-MILEAGE-3 unresolved at scheduling time |
| **S-QBO-18** | Bill push | QBO-8 | M | Sonnet | S-QBO-7 ✅ + `assertReadyForBillPush` ✅ | 📋 PLANNED | `BillPusher`; ITC tax handling; account-based expense lines; new `acc_qbo_bill_map` table |
| **S-QBO-19** | Bill payment push | QBO-8 | M | Sonnet | S-QBO-18 + S-QBO-20 BankAccountRef | 📋 PLANNED | `BillPaymentPusher`; check/EFT pay types; `BankAccountRef` from `acc_qbo_bank_account_map` (S-QBO-20) |
| **S-QBO-20** | Bank account mapping + read-only CDC mirror | QBO-9 | M | Sonnet | None new | 📋 PLANNED | Exception #2 per D-QBO-CORE-2; `acc_qbo_bank_account_map` + `acc_qbo_bank_transaction_map`; `qbo_bank_cdc.php` daily cron; Bank Mirror page |
| **S-QBO-21** | Journal Entry push (FF → QBO) | QBO-10 | L | Opus | S-QBO-20 + `assertReadyForJournalEntryPush` ✅ | 📋 PLANNED | All 12 AutoEntryBridge methods get QBO sync hooks (7 active + 5 hook stubs); push depreciation / tax remittance / year-end / recurring / manual / adjusting / reversing JEs only (skips bridge-derived) |
| **S-QBO-22** | Fixed asset JE sync | QBO-11 | M | Sonnet | S-QBO-21 | 📋 PLANNED | Depreciation + disposal JEs only (asset records stay FF-only per D-QBO-CORE-3) |
| **S-QBO-23** | Tax remittance JE sync | QBO-11 | M | Sonnet | S-QBO-21 | 📋 PLANNED | Supports accountant filing GST34 through QBO NETFILE |
| **S-QBO-24** | Drift detection cron `qbo_drift_check.php` | QBO-12 | L | Opus | All Pushers shipped through S-QBO-21 | 📋 PLANNED | Full per-entity comparison; tolerance configuration; notification dispatch via `NotificationService` |
| **S-QBO-25** | Drift resolution workflows | QBO-12 | L | Opus | S-QBO-24 | 📋 PLANNED | Resolve via FF action / resolve via QBO action / accept divergence / suppress; bulk operations |
| **S-QBO-26** | Manual Sync page | QBO-12 | L | Sonnet | S-QBO-25 | 📋 PLANNED | Force re-sync per entity; force full type re-sync; force pull from QBO |
| **S-QBO-27** | Historical pull from QBO (XL) | QBO-13 | **XL** | Opus | S-QBO-26 ✅ | 📋 PLANNED ⚠️ | **Absorbs AR drift remediation** per D-QBO-CORE-11 / D-ARCH-14: H5 LP Logistics 5 orphan payments + H6 Lepore 4 inflated DR-AR JEs (1.375× ratio). 5-phase execution: sandbox dry-run → sandbox full → prod dry-run → prod execution → verification. **H6 stop-gate** if root cause neither (a) cleanly resolved by QBO data nor (b) confirmed as FF-side bug |
| **S-QBO-28** | Historical verification | QBO-13 | M | Sonnet | S-QBO-27 | 📋 PLANNED | Post-pull drift = 0 confirmation; manual review report; accountant sign-off |
| **S-QBO-29** | Production OAuth + realm switch | QBO-14 | M | Sonnet | S-QBO-28 | 📋 PLANNED | Register production Intuit Developer app; production credentials; environment toggle; production webhook URL (D8 unlocked per D-ARCH-13 — production already live at mainlandrentals.com/fleetforge) |
| **S-QBO-30** | Cutover dry run + execution + 14-day monitoring | QBO-14 | L | Opus | S-QBO-29 + all pre-cutover sessions §4 | 📋 PLANNED | The kill-switch flip session per [§20 cutover sequence](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md). 14-day monitoring window with rollback procedure documented |

**BUILD numbered sessions remaining: 19.**

---

## 3. TESTING ARC STATUS (TESTING CLASS)

Per planning chat 2026-05-26, **this arc is PAUSED** pending build-out completion. Operator will resume after the build sessions ship (likely after S-QBO-21 JE push or as part of S-QBO-30 cutover gating).

The current state reflects what shipped during the 6-session rigorous-testing arc on 2026-05-26.

### Phase 1 — RO contract audit (K-22 trap sweep)

**STATUS: ✅ CLOSED** — shipped by [S-QBO-PHASE-1-TRAP-SWEEP](FLEETFORGE_QUICKBOOKS_PROGRESS.md#L361) 2026-05-26 + [S-PORTAL-INVOICE-TAX-DISPLAY-FIX](FLEETFORGE_PROGRESS.md) (combined commit).

**Outcome:** 1 CRITICAL surfaced (portal invoice view bound `$inv['gst_amount']` / `$inv['pst_amount']` instead of `tax_*_amount` per canonical schema; HST branch absent entirely; `?? '0'` fallback caused silent zero rendering). Fixed same commit. All 11 other patterns clean (zero hits or false-positive comments/correct-equivalence-map). Full enumeration in [QUICKBOOKS_PROGRESS.md §12.1](FLEETFORGE_QUICKBOOKS_PROGRESS.md).

### Phase 2 — Conformance audit (§6.8 / §6.9 contracts)

**STATUS: ✅ CLOSED** — shipped by [S-QBO-PHASE-2-CONTRACT-AUDIT](FLEETFORGE_QUICKBOOKS_PROGRESS.md#L402) 2026-05-26 + [S-QBO-PUSHER-CONTRACT-PAYDOWN](FLEETFORGE_QUICKBOOKS_PROGRESS.md#L448) 2026-05-26.

**Outcome:** 0 CRITICAL / 2 MEDIUM / 5 LOW. Both MEDIUMs closed by PAYDOWN session — C6 (return shape parity via `RESULT_BASE` const) + C9 (FIXPACK-10 backport to InvoicePusher). 5 LOWs remain as documented intentional deviations (D4 Enqueuer no-idempotency; E1 ItemCreator Creator-pattern; E2 ItemCreator no internal idempotency guard; etc.). [QUICKBOOKS_PROGRESS.md §12.2](FLEETFORGE_QUICKBOOKS_PROGRESS.md) carries full matrix.

**Side finding §12.3:** `_smoke_qbo_account_mapping.php` and `_smoke_qbo_invoice_push.php` cannot run in the same parallel batch (sub-check C13 fails due to test-isolation interference). Run sequentially when both in scope.

### Phase 3 — Validator gate audit (`assertReadyFor*Push()` × 6 gates × 5 states)

**STATUS: ✅ CLOSED with deferred LOWs** — shipped by [S-QBO-PHASE-3-VALIDATOR-GATE-AUDIT](FLEETFORGE_QUICKBOOKS_PROGRESS.md#L480) 2026-05-26 + [S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE](FLEETFORGE_QUICKBOOKS_PROGRESS.md) 2026-05-26.

**Outcome:** 1 CRITICAL + 6 MEDIUM closed (CRITICAL F-P3-01 InvoicePush × S4 empty-cat → C30 + C31; MEDIUM F-P3-02 InvoicePush × S3 message detail → C13 strengthened; MEDIUM F-P3-03/04 PaymentPush coverage → C32/C33/C34; MEDIUM F-P3-05 BillPush → C35/C36; MEDIUM F-P3-06 BillPaymentPush → C37/C38/C39; MEDIUM F-P3-07 JournalEntryPush → C40/C41/C42). `_smoke_qbo_account_mapping.php` extended from 29 → 42 sub-checks. **Coverage 15/30 cells** (8 originally + 7 RESOLVED). Validator architecture confirmed spec-conformant; SESSION_REQUIREMENTS const matches D-QBO-VALIDATOR-3 exactly; message construction conforms to all D1-D4 criteria.

**3 LOWs DEFERRED (revisit after Phase 4 results, per [QUICKBOOKS_PROGRESS.md §12.5](FLEETFORGE_QUICKBOOKS_PROGRESS.md)):**

- **F-P3-08** | FullCompliance × S1 (no sub-check verifies pass state when all 6 categories mapped; manual in practice, not CI-exercised). LOW; not blocking.
- **F-P3-09** | FullCompliance × S2 (no sub-check tests exactly-one-blocking; singular `'category'` inflection unverified for this gate — singular IS now verified by C33/C35/C38/C41 against Payment/Bill/BillPayment/JE). LOW; partially covered by sibling gates.
- **F-P3-10** | BillPush × S4 + JournalEntryPush × S4 (empty-category path for `ap_clearing` / `tax_receivable` / `tax_payable` not tested; not naturally reachable — all 3 categories have FF accounts in live chart). LOW; risk further reduced because identical code path to ar_clearing/sales_revenue + UF is already smoke-covered via C30/C31/C32/C37.

### Phase 4 — Pre-flight Gate Audit

**STATUS: 📋 NOT STARTED** (PAUSED per operator pivot 2026-05-26).

**Purpose:** Audit `InvoicePreflightGate` (6 gates currently, was 5 pre-FIXPACK-1) for ordering, idempotency, currency-mismatch coverage, field-length validation completeness, and stop-condition correctness. Mirror the Phase 3 30-cell matrix approach for the 6 pre-flight gates × N invoice states.

**Estimated value pre-cutover:** HIGH — pre-flight gates are the gatekeepers preventing wasted HTTP calls and silent QBO-side poisoning (FIXPACK-1 surfaced 2 such defects). Audit before more Pushers grow their own pre-flight gates.

### Phase 5 — Idempotency + Replay

**STATUS: 📋 NOT STARTED** (PAUSED per operator pivot).

**Purpose:** Comprehensive replay/idempotency testing — re-enqueue, double-dispatch, simultaneous-worker scenarios. The D-QBO-FIXPACK-10 mismatch warning (already_mapped re-probe) is the existing defence; this phase audits coverage and adds chaos-style tests.

**Estimated value pre-cutover:** HIGH — idempotency under the production cutover's first-week traffic spike is exactly where bugs surface.

### Phase 6 — Schema Integrity

**STATUS: 📋 NOT STARTED** (per planning notes, **likely redundant** — D131 parity gate + DATABASE_MASTER discipline + per-session migration verification covers most concerns).

**Recommendation:** Operator-confirm whether Phase 6 still has unique value beyond D131. If not, skip; otherwise scope as a targeted FK + index audit.

### Phase 7 — Adversarial Inputs

**STATUS: 📋 NOT STARTED** (PAUSED per operator pivot).

**Purpose:** Inject malformed payloads (oversized strings, embedded HTML, null bytes, encoding issues, unusual Unicode, intentionally-truncated payloads) at every Pusher entry point. Catch defects before production traffic surfaces them.

### Phase 8 — Chaos / Network

**STATUS: 📋 NOT STARTED** (PAUSED per operator pivot).

**Purpose:** Network failure injection — HTTP 5xx storms, partial reads, connection timeouts, rate-limit triggers, retry exhaustion. Validates `QuickBooksClient`'s retry orchestration + `QuickBooksTransientException` cascade.

### Phase 9 — Cross-Pusher Integration

**STATUS: 📋 NOT STARTED** (per planning notes, **premature without more Pushers** — only 3 Pushers operational; meaningful cross-Pusher scenarios start emerging after S-QBO-18/19/21).

**Recommendation:** Defer until at least S-QBO-21 (JE push) ships, then revisit scope.

### Phase 10 — Live E2E

**STATUS: 📋 NOT STARTED** (PAUSED per operator pivot; S-QBO-LIVE-VERIFY-RERUN-2026-05-26 was a manual one-shot; Phase 10 would systematize live E2E into a repeatable pre-cutover validation).

**Estimated value pre-cutover:** CRITICAL — the final gate before S-QBO-30 flip; runs the full sandbox flow end-to-end with realistic data volume.

---

## 4. PRE-CUTOVER AND OPERATOR SESSIONS

Per [ROADMAP §20 cutover sequence](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md). These sessions sit between S-QBO-28 (historical verification) and S-QBO-30 (cutover flip). Numbered as TBD because they were inserted post-roadmap-v1.1 via D-S-QBO-4-DOCS-LOCK 2026-05-20.

| ID | Title | Size | Model | Depends on | Notes |
|---|---|---|---|---|---|
| **S-SAMSARA-LENDER-TAGS-CONFIG** | Pre-cutover lender list refresh | S | Sonnet | None | Update `settings.samsara.lender_tag_patterns` with current lender list (was hardcoded in SAMSARA-3: Bennington Financial, National Bank, Sonoma Capital). Operator confirms against current financing relationships |
| **S-QBO-CUTOVER-WIPE** | Truncate FF transactional + master data tables | L | Opus | All numbered S-QBO-* ✅ | Preserve: `users`, `user_roles`, `user_permissions`, `user_permission_overrides`, `settings` (most keys), `schema_migrations`, base `acc_accounts` structure. Truncate: customer/vendor/lease/equipment/invoice/payment/credit_note/JE tables + all `acc_qbo_*_map` tables. Smoke verifies wipe completeness + preservation rules |
| **S-SAMSARA-FLEET-IMPORT** | Identity-layer re-population with tag classification | L | Opus | S-QBO-CUTOVER-WIPE | Pull all Samsara trailers via `SamsaraClient::getTrailers()`; create `equipment_units` rows with full identity layer (17 `samsara_*` fields). Tag classification per **D-SAMSARA-TAGS-DUAL** (lender tags → `owner_company_id` + `ownership_type='leased'`; lessee tags → customer stubs flagged `requires_qbo_review=1` per **D-CUTOVER-ORPHAN-CUSTOMERS**). Idempotent (matches by `samsara_vehicle_id`) |
| **S-QBO-CUTOVER-IMPORT** | Customer/vendor/COA pull + reconciliation UI | L | Opus | S-SAMSARA-FLEET-IMPORT + S-QBO-7/8/9/10 ✅ | Pull customers + vendors + COA from production QBO; reconcile Samsara-derived customer stubs against QBO master per **D-CUTOVER-CUSTOMER-CONFLICT** (QBO wins; FF stub data discarded). Customer Reconciliation Review page populated |

**Operator-driven (no Claude session per se):**
- Operator creates `equipment_templates` (per D-CUTOVER-TEMPLATES) — 8-15 templates with daily/weekly/monthly + mileage rates
- Operator assigns `template_id` per `equipment_unit` (200+ units, bulk-action UI)
- Operator fills per-unit dimensions, inspection dates (CVI/registration/MVI/insurance), uploads compliance docs
- Operator sets `yard_location` per unit
- Operator re-creates active leases via existing lease wizard (estimated 30-60 min per lease × N active leases)
- Operator does dry-run via `quickbooks.dry_run_mode='1'` then restores
- Operator runs final D131 + manual sandbox push verification

---

## 5. DEBT + BACKLOG (DEBT CLASS)

### 5.1 Registered backlog items (in CURRENT_SESSIONS.md BACKLOG block)

Source: [FLEETFORGE_CURRENT_SESSIONS.md §BACKLOG lines 97-107](FLEETFORGE_CURRENT_SESSIONS.md).

- **S-SMOKE-NAV-DECOUPLE** | Sonnet XS | Extract nav-children count from `config/navigation.php` into a single source-of-truth constant so future sessions adding nav entries only need ONE bump instead of N (currently 6 smokes inspect nav config independently). Schedule before any session touching the QuickBooks nav group (likely Phase E S-PORT-1 or a portal-nav session).
- **S-QBO-MATCHER-PRIORITY-ORDER** | Sonnet XS | `AccountMatcher::matchAll()` iteration currently sorts by `id ASC` (insertion order). Future: rank by priority — critical accounts first (`is_critical=1`), then exact_code candidates, then high-confidence partials. Companion to D-MATCHER-CLAIMED-SET. Applies symmetrically to Customer/Vendor/TaxCode/Item matchers when their domains grow.
- **S-ROADMAP-V1-2-ITEM-TYPE-RECONCILE** | Sonnet XS | Reconcile [ROADMAP v1.1 §9.4](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md) hypothetical `item_type` list against the actual ENUM (7 of 17 values diverged at S-QBO-10 ship; see K-22 Trap #67). Either edit roadmap inline or bump to v1.2 with brief changelog entry.
- **S-QBO-CUSTOMER-MAPPING-RE-EVALUATE** | Sonnet XS — UI-driven | Pre-S-QBO-MATCHER-GREEDY-FIX, ~20 medium-confidence rows in `acc_qbo_account_map` were created with the buggy greedy-claim + non-subtype-aware matcher. New logic applies when operator re-runs auto_match from UI. Mark SHIPPED with a one-line note if operator handles via UI.
- ✅ **S-VENDOR-CURRENCY-COLUMN** SHIPPED 2026-05-27 | Sonnet XS | Closed D-QBO-FIXPACK-8 backlog. Migration added `vendors.currency ENUM('CAD','USD') NOT NULL DEFAULT 'CAD'` mirroring customers.currency. VendorPusher refactored to read per-row currency; API endpoints accept currency input. D-VENDOR-CURRENCY-COLUMN-1 supersedes D-QBO-FIXPACK-8. Admin UI deferred to follow-up S-VENDOR-UI-CURRENCY-SELECTOR (D-VENDOR-CURRENCY-COLUMN-4).

### 5.2 Mentioned-but-unregistered debt items

Three items surfaced in today's planning chat per the inventory prompt; only one is registered in canonical docs (above). The other two need a single-line BACKLOG entry in CURRENT_SESSIONS.md before they can be fired:

- **S-QBO-ACCOUNT-MAPPING-SMOKE-PRODUCTION-STATE-CONTAMINATION** (P2) | **NOT REGISTERED** | Grep of all 3 canonical docs (CURRENT_SESSIONS.md, PROGRESS.md, QUICKBOOKS_PROGRESS.md) returns zero hits. The related issue surfaced in [QUICKBOOKS_PROGRESS.md §12.3](FLEETFORGE_QUICKBOOKS_PROGRESS.md) (C13 smoke-parallelism side-finding — `_smoke_qbo_account_mapping.php` + `_smoke_qbo_invoice_push.php` cannot run in same parallel batch due to test-isolation interference; sibling smoke maps accounts mid-run, contaminating the unmapped state C13 expects). Probable scope: add `acc_qbo_account_map` cleanup to `_smoke_qbo_invoice_push.php`'s teardown to restore pre-test unmapped state. → **Surface for separate registration** before scheduling.
- **S-QBO-CV-PAYLOAD-BUILD-CATCH** (P3) | **NOT REGISTERED** | Zero hits in canonical docs. Probable scope (inferring from session-name pattern): wrap `buildQboPayload()` in CustomerPusher + VendorPusher with try/catch akin to InvoicePusher's payload-build exception path (which got `'status' => 'payload_build_failed'` in S-QBO-PUSHER-CONTRACT-PAYDOWN per MEDIUM-C6 closure). → **Surface for separate registration** before scheduling.

### 5.3 Phase 3 LOW gaps (deferred from S-QBO-PHASE-3-VALIDATOR-GATE-AUDIT)

See [§3 Phase 3 status](#phase-3--validator-gate-audit-assertreadyforpush--6-gates--5-states). The 3 LOWs (F-P3-08/09/10) are revisit-after-Phase-4 per session disposition.

### 5.4 Other queued / surfaced debt

- **S-DEMO-WIPE-COUNTER-SYNC** | potential follow-up | Surfaced in S-INVOICE-COUNTER-BUMP investigation: `scripts/demo_wipe.php:119-123` DELETEs + INSERTs `invoice.next_number.%` at value='1' for current year. Durable fix: either (a) re-bump counter to MAX(invoice_number)+1 AFTER any seeding step, or (b) add a CHECK/trigger preventing counter < MAX(invoice_number). Out of scope this session per DO NOT clause.

---

## 6. RECOMMENDED NEXT 5-10 SESSIONS (operator-actionable)

Sorted to maximize forward progress on the build-out arc. Each recommendation includes the rationale, size, model, and 1-2 sentence why-this-slot.

### Build-forward primary slate

1. **S-QBO-12** | BUILD | L | Opus | Closes Phase QBO-5 with `pushUpdate` + `pushVoid`. Foundation work (`voidEntity()`) already paid down in S-QBO-11-POSTVERIFY-FIXES 2026-05-25. **Slot reason:** smallest dependency-free build item that unblocks every downstream drift-resolution and re-push workflow.
2. **S-VENDOR-CURRENCY-COLUMN** | DEBT | XS | Sonnet | Retires registered D-QBO-FIXPACK-8 debt before more Pusher work (S-QBO-14/16/18/19/21) inherits the same hardcode pattern. **Slot reason:** XS surgical paydown; defragments the codebase cheaply before more vendor-coupled work lands.
3. **S-QBO-18** | BUILD | M | Sonnet | Bill push (first AP-direction Pusher). Highest accountant-workflow-shift value per D-CPA-5 conversation. **Slot reason:** Sonnet-sized; dependencies all DONE (vendor mapping + validator gate); decouples AP from invoice arc so parallel testing on different Pushers becomes possible.
4. **S-QBO-13** | BUILD | L | Opus | Payment pull via webhook (Exception #1). Reuses S-PROD-2 webhook pattern. **Slot reason:** foundation for both S-QBO-14 (payment push) and S-QBO-15 (portal embed); largest non-JE remaining single integration; security-sensitive (HMAC signature verification) so Opus-tier.
5. **S-QBO-14** | BUILD | M | Sonnet | Payment push. **Slot reason:** Pairs naturally with S-QBO-13 + completes the payment surface so AR drift becomes detectable bidirectionally.

### Build-forward secondary slate

6. **S-QBO-19** | BUILD | M | Sonnet | Bill payment push. **Slot reason:** Completes the AP surface; together with S-QBO-18 closes Phase QBO-8. Requires `BankAccountRef` from S-QBO-20 — schedule S-QBO-20 first OR scope S-QBO-19 to assume manual bank account mapping in interim.
7. **S-QBO-20** | BUILD | M | Sonnet | Bank account mapping + read-only CDC. **Slot reason:** Exception #2; read-only so low risk; provides `BankAccountRef` required by S-QBO-19; populates Bank Mirror admin page.
8. **S-QBO-21** | BUILD | L | Opus | JE push (largest single integration). All 12 AutoEntryBridge methods get QBO sync hooks. **Slot reason:** unlocks comprehensive FF→QBO JE coverage; required before drift detection (S-QBO-24) can produce meaningful results; biggest "remaining" surface area to retire.
9. **S-QBO-16** | BUILD | M | Sonnet | Credit memo push. **Slot reason:** Continues Phase QBO-7; smaller and lower-risk than S-QBO-17 (which is CPA-blocked); two-step JE bridge pattern is reusable.
10. **S-QBO-15** | BUILD | XL | Opus | QBO Payments portal embed. **Slot reason:** XL highest-effort session in the remaining slate; PCI considerations + webhook signature verification under load; defer until S-QBO-13/14 ship and webhook pattern is proven in production.

### Alternative: Mostly-paydown defragment slate (operator picks if build velocity is constrained)

Use this slate if today's 6-session arc + Phase 1/2/3 testing arc has left the operator wanting a debt-paydown breath before more build:

1. **S-VENDOR-CURRENCY-COLUMN** | DEBT | XS | Sonnet
2. **S-SMOKE-NAV-DECOUPLE** | DEBT | XS | Sonnet (refactor)
3. **S-ROADMAP-V1-2-ITEM-TYPE-RECONCILE** | DEBT | XS | Sonnet (doc reconciliation)
4. **S-QBO-MATCHER-PRIORITY-ORDER** | DEBT | XS | Sonnet (matcher cascade improvement)
5. **S-QBO-12** | BUILD | L | Opus (after the paydowns, return to build)

This alternative slate ships ~4 BACKLOG items in a single afternoon (each XS) before re-engaging the L+ build sessions.

### Notes on F-P3-08/09/10 (Phase 3 deferred LOWs)

These 3 SMOKE ADDITION items could be bundled into a single XS session if the operator wants to fully close Phase 3 before pivoting. Not recommended above because (a) they were explicitly deferred-by-the-session-that-found-them, (b) the deferred LOWs are documented as low-risk (sibling-gate coverage already protects the equivalent code paths), and (c) operator pivot is to build-out, not test polish.

---

## 7. DEPENDENCY GRAPH

Per-session "depends on" relations, derived from [ROADMAP v1.1 §11 session index](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md) + [QUICKBOOKS_PROGRESS.md §2 SESSION LOG](FLEETFORGE_QUICKBOOKS_PROGRESS.md). Only forward dependencies shown.

### 7.1 Phase QBO-5/6/7 chain

```
S-QBO-12 (Invoice void/update)
  depends on: S-QBO-11 ✅ + QuickBooksClient::voidEntity() ✅ (S-QBO-11-POSTVERIFY-FIXES)

S-QBO-13 (Payment pull webhook)
  depends on: S-QBO-12 [roadmap-implicit; webhook architecture independent of invoice modifications]
  alt-fire: independent of S-QBO-12 if scoped narrowly (webhook handler is self-contained)

S-QBO-14 (Payment push)
  depends on: S-QBO-13 (acc_qbo_webhook_events table) + assertReadyForPaymentPush ✅ (ar_clearing + undeposited_funds)

S-QBO-15 (Portal embed)
  depends on: S-QBO-14 (payment push handshake for webhook-originated)

S-QBO-16 (Credit memo push)
  depends on: S-QBO-15 [roadmap-sequential; can fire after S-QBO-12 if portal embed deferred]

S-QBO-17 (Refund receipt push)
  depends on: S-QBO-16 + S-MILEAGE-3-ACCT-SPEC ⚠️ CPA-BLOCKED
```

### 7.2 Phase QBO-8/9 chain

```
S-QBO-18 (Bill push)
  depends on: S-QBO-7 ✅ + assertReadyForBillPush ✅ (ap_clearing)
  no hard upstream dep on S-QBO-12 through S-QBO-17 — can fire in parallel with QBO-6/7 work

S-QBO-20 (Bank mapping + CDC)
  depends on: none new; uses existing CDC pattern documented in spec §6.2

S-QBO-19 (Bill payment push)
  depends on: S-QBO-18 + S-QBO-20 (BankAccountRef from acc_qbo_bank_account_map)
  alt-fire: scope S-QBO-19 to assume manual bank account mapping pre-S-QBO-20
```

### 7.3 Phase QBO-10/11/12 chain

```
S-QBO-21 (JE push)
  depends on: S-QBO-20 (bank mapping for some JE BankAccountRef cases) + assertReadyForJournalEntryPush ✅
  touches: all 12 AutoEntryBridge methods

S-QBO-22 (Fixed asset JE)
  depends on: S-QBO-21

S-QBO-23 (Tax remittance JE)
  depends on: S-QBO-21

S-QBO-24 (Drift detection cron)
  depends on: every push-direction Pusher shipped through S-QBO-23
  meaningful results require: Customer ✅ + Vendor ✅ + Invoice ✅ + S-QBO-12/13/14/16/18/19/21/22/23

S-QBO-25 (Drift resolution)
  depends on: S-QBO-24

S-QBO-26 (Manual sync page)
  depends on: S-QBO-25
```

### 7.4 Phase QBO-13/14 chain (historical + cutover)

```
S-QBO-27 (Historical pull from QBO + AR drift remediation)
  depends on: S-QBO-26 + all earlier S-QBO-* ✅
  XL: absorbs $17,064.62 AR drift per D-QBO-CORE-11

S-QBO-28 (Historical verification)
  depends on: S-QBO-27
  target: AR drift = $0.00 ± $1

S-QBO-CUTOVER-WIPE (operator-execution; ROADMAP §20)
  depends on: S-QBO-28 + S-SAMSARA-LENDER-TAGS-CONFIG

S-SAMSARA-FLEET-IMPORT
  depends on: S-QBO-CUTOVER-WIPE

S-QBO-CUTOVER-IMPORT
  depends on: S-SAMSARA-FLEET-IMPORT + S-QBO-7/8/9/10 ✅

S-QBO-29 (Production OAuth + realm switch)
  depends on: S-QBO-CUTOVER-IMPORT

S-QBO-30 (Cutover dry run + execution + 14-day monitoring)
  depends on: S-QBO-29 + all upstream ✅
  THE flip session
```

### 7.5 Critical engine-version constraint (D-QBO-11-5)

S-QBO-11's `pushCreate` carries an engine-version dispatch that compiles but never triggers the `period_independent` recon-credit branch because `invoices.engine_version` column does not exist on disk (D-QBO-11-5: degrades to `'unknown'` literal). **This becomes load-bearing when Model B drawdown invoices start emitting `base_rental_reconciliation_credit` lines** — at that point an `invoices.engine_version` column must exist + be populated by `InvoiceGenerator`.

**Currently:** 23 active+pending leases locked to `period_independent` engine never emit recon credits (so the dormant branch is correctly never triggered). The 8 closed-leases-with-holistic-default never generate further invoices.

**Forward dep:** if a future session activates the holistic engine for new lease generation (which is the default per S-BILLING-HOLISTIC-ENGINE), the engine-version dispatch becomes load-bearing for S-QBO-11 invoice push. **Surface for planning chat** before that session fires.

---

## 8. CUTOVER CRITICAL PATH

What MUST ship before production cutover (S-QBO-30) can fire, distinguished from nice-to-have-but-not-blocking. Derived from [ROADMAP §9.2 Phase QBO exit criteria](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md) + [§20 cutover sequence](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md).

### 8.1 MUST-SHIP (cutover-gating)

| ID | Why blocking |
|---|---|
| **S-QBO-12** | Invoice void path required — sent FF invoices that get voided must propagate to QBO; otherwise drift accumulates from day-1 |
| **S-QBO-13** | Payment pull from QBO webhook — Exception #1; required because QBO Payments processes money first |
| **S-QBO-14** | Payment push — required so FF-originated payments (manual cash receipts) reach QBO |
| **S-QBO-15** | QBO Payments portal embed — required for customer self-service payment workflow per D-ARCH-3 |
| **S-QBO-16** | Credit memo push — required because credit notes are a core FF entity (`credit_notes` table active) and must mirror to QBO |
| **S-QBO-18** | Bill push — D-CPA-5 workflow shift requires bills to originate in FF, not QBO |
| **S-QBO-19** | Bill payment push — completes AP surface |
| **S-QBO-20** | Bank account mapping — required for S-QBO-19 BankAccountRef + Exception #2 read-only mirror |
| **S-QBO-21** | JE push — required because AutoEntryBridge fires 7+ JE classes; without this every FF JE creates drift |
| **S-QBO-22** | Fixed asset depreciation/disposal JE sync — required because FF runs depreciation crons that emit JEs |
| **S-QBO-23** | Tax remittance JE sync — required because GST34 filing JEs originate in FF |
| **S-QBO-24** | Drift detection cron — required for ongoing operations confidence |
| **S-QBO-25** | Drift resolution workflows — required to act on drift_events |
| **S-QBO-26** | Manual sync page — required for operator recovery if drift accumulates |
| **S-QBO-27** | Historical pull from QBO — required to bring FF state into alignment with Mainland's real books; absorbs $17,064.62 AR drift remediation per D-QBO-CORE-11 |
| **S-QBO-28** | Historical verification — required to confirm AR drift = $0 ± $1 |
| **S-SAMSARA-LENDER-TAGS-CONFIG** | Pre-cutover lender list refresh — required for S-SAMSARA-FLEET-IMPORT tag classification |
| **S-QBO-CUTOVER-WIPE** | Truncate FF transactional data — required because current FF is on dummy data |
| **S-SAMSARA-FLEET-IMPORT** | Identity-layer re-population from Samsara — required because FF will be empty post-wipe |
| **S-QBO-CUTOVER-IMPORT** | Customer/vendor/COA pull from production QBO + reconciliation — required because mapping tables will be empty post-wipe |
| **S-QBO-29** | Production OAuth + realm switch — required for production credentials |
| **S-QBO-30** | The kill-switch flip + 14-day monitoring + rollback procedure |

**MUST-SHIP count: 22 sessions** (15 numbered + 4 pre-cutover + S-SAMSARA-LENDER-TAGS-CONFIG + S-QBO-29 + S-QBO-30).

### 8.2 NICE-TO-HAVE (not cutover-blocking)

| ID | Why deferrable |
|---|---|
| **S-QBO-17** | Refund receipt push — CPA-blocked on S-MILEAGE-3-ACCT-SPEC; defer until refund concept concretely defined in FF; until then refunds can route through manual QBO entry |
| **Testing Arc Phases 4-10** | Internal-quality gates; useful pre-cutover but not architecturally required. Phase 10 (Live E2E) is the closest to cutover-gating; could replace ad-hoc S-QBO-LIVE-VERIFY-RERUN-style verifications |
| **All BACKLOG items** | S-SMOKE-NAV-DECOUPLE, S-QBO-MATCHER-PRIORITY-ORDER, S-ROADMAP-V1-2-ITEM-TYPE-RECONCILE, S-QBO-CUSTOMER-MAPPING-RE-EVALUATE, S-VENDOR-CURRENCY-COLUMN — none block cutover |
| **F-P3-08/09/10** | Smoke coverage gaps — none block cutover; sibling-gate coverage protects equivalent code paths |
| **S-DEMO-WIPE-COUNTER-SYNC** | Counter-drift durable fix; the symptom is paid down via S-INVOICE-COUNTER-BUMP |

### 8.3 Critical-path estimate

Per [ROADMAP v1.1 §4.4](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md): "QBO 28 sessions ~50-70 working days" (28 effective × ~2 days avg). Adjusted for 11 already shipped (33 days), remaining estimate ≈ **30-40 working days for the MUST-SHIP slate** assuming 1.5-2 days per remaining session and no major architectural surprises.

Cumulative: roadmap estimated 107-152 working days for entire 59-session arc; with Phase A/B/C/D shipped, QBO Phase 1-4 shipped, Phase 5 partly shipped, the remaining QBO + Portal slate is the back half of that estimate.

---

## 9. ITEMS REQUIRING A PLANNING CHAT BEFORE A SESSION CAN FIRE

Per the prompt's E2 requirement: any item where the dependency graph requires architectural alignment before a session can fire. These are flagged with options framed neutrally; no recommendation is pre-resolved.

### 9.1 Two unregistered debt items (P2 + P3)

Per [§5.2](#52-mentioned-but-unregistered-debt-items): **S-QBO-ACCOUNT-MAPPING-SMOKE-PRODUCTION-STATE-CONTAMINATION** (P2) and **S-QBO-CV-PAYLOAD-BUILD-CATCH** (P3) need a one-line BACKLOG registration in CURRENT_SESSIONS.md (or full session prompt) before they can be fired. The session names alone don't carry enough scope detail to fire as build sessions.

**Options:** (A) Register as one-line BACKLOG entries with rough scope (cheap, defers detail to fire-time); (B) Write full session prompts (more upfront work but enables direct fire).

### 9.2 S-MILEAGE-3-ACCT-SPEC resolution (blocks S-QBO-17)

S-MILEAGE-3-ACCT-SPEC is CPA-blocked on 5 questions per D-I (A) / D176. Until that unblocks, S-QBO-17 (refund receipt push) cannot fire with confidence about the refund accounting treatment.

**Options:** (A) Wait for CPA conversation to unblock S-MILEAGE-3-ACCT-SPEC; (B) Defer S-QBO-17 entirely from cutover (refunds via manual QBO entry post-cutover until CPA-resolved); (C) Ship S-QBO-17 with explicit "TBD pending CPA decision" branches and revisit post-cutover.

### 9.3 Engine-version dispatch activation (S-QBO-11 D-QBO-11-5 dormant code)

Per [§7.5](#75-critical-engine-version-constraint-d-qbo-11-5): `invoices.engine_version` column doesn't exist; S-QBO-11's dispatch degrades to `'unknown'` literal. **Becomes load-bearing when:** holistic engine generates new lease invoices that emit `base_rental_reconciliation_credit` lines.

**Options:** (A) Add `invoices.engine_version` column to `InvoiceGenerator` write path in a dedicated migration session before activating holistic-engine for new leases; (B) Defer until first holistic-engine recon-credit invoice is observed in production, then react; (C) Inline the column add inside the next holistic-engine adjustment session.

### 9.4 Pre-cutover scope ownership (S-QBO-CUTOVER-WIPE preserve-list)

Per [ROADMAP §20.2](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md), the wipe session's preserve-list and truncate-list are not yet locked. The pattern is documented but the exact table-by-table classification needs a planning chat.

**Options:** (A) Lock the lists in the S-QBO-CUTOVER-WIPE prompt at fire-time; (B) Pre-lock in a separate XS planning session (S-QBO-CUTOVER-WIPE-MANIFEST) so the wipe session is purely-execution; (C) Use the roadmap's documented partial list as authoritative.

### 9.5 Phase QBO testing arc resume cadence

Per operator pivot 2026-05-26, the testing arc is paused. Decision needed: at what build milestone does testing resume? Options: (A) resume after S-QBO-21 (JE push complete); (B) resume as part of S-QBO-30 cutover-gate; (C) resume only if pre-cutover smoke surfaces an issue; (D) interleave Phase 4 alongside next build session.

---

## 10. DRIFT BETWEEN CANONICAL DOCS

Items noticed during this audit where canonical docs are out-of-sync with each other or with the SESSION LOG state. None are STOP-condition triggers but operator may want to schedule a docs-reconciliation session.

### 10.1 QUICKBOOKS_PROGRESS.md §1 status board is behind SESSION LOG

[QUICKBOOKS_PROGRESS.md §1 header](FLEETFORGE_QUICKBOOKS_PROGRESS.md) says:
- "Current arc status (as of 2026-05-25)"
- "9 of 30 sessions shipped"
- "Next session up: S-QBO-10"

The SESSION LOG (same file, §2) shows S-QBO-10 already shipped 2026-05-21, S-QBO-11 shipped 2026-05-24, then FIXPACKs through 2026-05-25/26, then today's 6-session arc through 2026-05-27. The "11/30" tally appears in the S-QBO-11 SESSION LOG row, not the file header.

**Impact:** The status board's per-phase status flags (e.g., S-QBO-11 📋 PLANNED in §1.5) are stale. Operator should not read the status board as authoritative; SESSION LOG is the truth.

**Recommendation:** Bump status board entries to ✅ DONE for everything through S-QBO-11 + add a "Current arc status (as of 2026-05-27): 11/30 numbered shipped + 22 debt-paydowns" line. Doc-only single-line edits. Could fold into the next S-QBO-* session that touches QUICKBOOKS_PROGRESS.md.

### 10.2 CURRENT_SESSIONS.md IN-FLIGHT block contains a SHIPPED entry

[CURRENT_SESSIONS.md line 75-85](FLEETFORGE_CURRENT_SESSIONS.md) shows S-QBO-11-POSTVERIFY-FIXES under `### IN-FLIGHT` with the body `"— SHIPPED 2026-05-25"`. The active session has shipped; IN-FLIGHT should be empty or move to SHIPPED block.

**Impact:** Per D136 pre-flight check, future agents may see a stale IN-FLIGHT entry and incorrectly believe a session is in progress.

**Recommendation:** Clear the IN-FLIGHT block as part of the next write-session's normal CURRENT_SESSIONS.md hygiene.

### 10.3 Roadmap v1.1 §9.4 item_type list outdated

Per registered backlog item **S-ROADMAP-V1-2-ITEM-TYPE-RECONCILE** ([CURRENT_SESSIONS.md line 105](FLEETFORGE_CURRENT_SESSIONS.md)): 7 of 17 item_type ENUM values diverged at S-QBO-10 ship time. K-22 Trap #67 carries the diff.

**Impact:** A planner reading ROADMAP §9.4 will see hypothetical item types that don't match the live ENUM. Operator-blind for build sessions; visible for any future planning chat that uses §9.4 as source.

**Recommendation:** Schedule S-ROADMAP-V1-2-ITEM-TYPE-RECONCILE (XS) before any planning chat that consumes ROADMAP §9.4 as authoritative.

### 10.4 QUICKBOOKS_PROGRESS.md §8 KNOWN ISSUES checkboxes are stale

[QUICKBOOKS_PROGRESS.md §8 Pre-Phase QBO FleetForge state](FLEETFORGE_QUICKBOOKS_PROGRESS.md) has unchecked boxes for:
- `[ ] Phase A complete (S-ACCT-FIX-AP, S-ACCT-FIX-DOCS)`
- `[ ] Phase B complete (...)`
- `[ ] Phase C complete (11 S-ACCT-* sessions)`
- `[ ] Phase D complete (6 S-ACCT-LESSOR-* sessions)`

But per [ROADMAP v1.1 §11 session index](FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md):
- Phase A: 2 sessions still 🟡 QUEUED + 📋 PLANNED (NOT complete)
- Phase B: 5 of 6 sessions ✅ DONE (S036 still PLANNED; FX/YE/REC/CRONS/CRUD shipped 2026-05-19)
- Phase C: 11 of 11 sessions ✅ DONE 2026-05-19
- Phase D: 6 of 6 sessions ✅ DONE 2026-05-19

**Impact:** §8 checkboxes don't match Phase C/D actual completion. The checkbox for Phase A correctly remains unchecked.

**Recommendation:** Bump §8 checkboxes for Phase B (partial — S036 still pending), Phase C (✅), Phase D (✅) as part of next QUICKBOOKS_PROGRESS.md write touch.

### 10.5 Today's 6-session arc not yet on QUICKBOOKS_PROGRESS.md SESSION LOG

QUICKBOOKS_PROGRESS.md §2 SESSION LOG ends at S-QBO-MATCHER-GREEDY-FIX (2026-05-24). The 6-session arc from 2026-05-26/27 (DIAGNOSIS, SKIP-RECORD-FIX-INVOICE, LIVE-VERIFY-RERUN, ENQUEUER-ELIGIBILITY, CV-PUSH-STATE-INFRA, MATCHER-WEDGE-RECOVERY) is present in PROGRESS.md SESSION LOG and CURRENT_SESSIONS.md recent ship history but not in QUICKBOOKS_PROGRESS.md.

**Impact:** Future planning chats reading QUICKBOOKS_PROGRESS.md as the QBO-specific authoritative log will miss today's 6 ships.

**Recommendation:** Backfill the 6 rows + Phase 1/2/3 testing arc rows into QUICKBOOKS_PROGRESS.md SESSION LOG. Doc-only single-touch. Could fold into the next QBO build session's normal doc-update path.

---

*End of FLEETFORGE QBO Integration — Remaining Work as of 2026-05-26.*

*Next update trigger: next planning chat that re-sequences the build slate, OR after S-QBO-12 / S-QBO-13 / S-QBO-14 ship (one of the recommended next 5).*
