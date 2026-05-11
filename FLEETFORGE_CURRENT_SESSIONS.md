# FleetForge — Current Sessions Queue

**Purpose:** Forward-looking queue of sessions discussed in planning but not yet shipped. Companion to FLEETFORGE_PROGRESS.md (which is the historical archive).

**Discipline:**
- Every session discussed in planning (claude.ai web chat) gets an entry here at the moment it's discussed, not retroactively.
- Each entry includes: session label, scope summary, estimated effort, dependencies, status, source-of-discussion timestamp.
- When a session ships, its entry is marked SHIPPED with the commit refs and date, then archived to PROGRESS.md SESSION LOG. The entry can be removed from this file or kept with SHIPPED tag for a short grace period — operator's call.
- When a session is descoped or superseded, mark DEFERRED or SUPERSEDED with rationale; do not delete silently.
- Update this file in lockstep with FLEETFORGE_PROGRESS.md whenever ship-state changes.

---

## Pre-flight check (D136)

Per D136, every Code Desktop session begins with a pre-flight read of this file:

1. Read FLEETFORGE_CURRENT_SESSIONS.md.
2. If any session is marked IN-FLIGHT with Touching domains overlapping the planned scope → halt and surface to operator for direction (serialize and wait, or opt into branch isolation per D136 fallback). IN-FLIGHT-RO sessions never block write sessions; multiple IN-FLIGHT-RO may coexist.
3. If no IN-FLIGHT collision, register the new session entry here with status IN-FLIGHT (write) or IN-FLIGHT-RO (read-only), start timestamp, agent identifier, and Touching domains (write only). Commit the registration as part of the session's first commit, OR as a standalone single-line edit if the operator wants to register before any work.
4. On session end, update the entry to SHIPPED (or DEFERRED / SUPERSEDED as applicable) per existing CURRENT_SESSIONS.md discipline. Remove the IN-FLIGHT multi-line details.

Read-only sessions skip the collision check (step 2) but still register (step 3) for operator visibility.

**Category separation (K-14, locked 2026-05-12 via S-PREDEPLOY-CHECKLIST-CREATE):** This file is the **session queue**. Items that surface during a session and are bug-shaped (a defect in shipped code) file to `FLEETFORGE_PROGRESS.md` → KNOWN ISSUES (`#NNN`). Items that are pre-deploy-shaped (a `.env` key needing a real value, an AWS resource to provision, a DNS record, a smoke/rollback procedure) file to `FLEETFORGE_PREDEPLOY_CHECKLIST.md` in the appropriate category — NOT here, and NOT to KNOWN ISSUES. Filing under the wrong category is documentation divergence (K-12 class).

---

## Status legend
- **QUEUED** — discussed, prompt drafted (or near-drafted), not yet shipped
- **IN-FLIGHT** — write-mode session currently executing in Code Desktop. Per D136, only one allowed at a time on main. Entry includes start timestamp, agent identifier, and touching domains.
- **IN-FLIGHT-RO** — read-only session (audit, survey, decision-surfacing) running concurrently. Multiple IN-FLIGHT-RO may coexist with one IN-FLIGHT.
- **BLOCKED** — waiting on operator input or upstream session
- **SHIPPED** — landed on origin/main; archive to PROGRESS.md
- **DEFERRED** — scoped out indefinitely with reason
- **SUPERSEDED** — replaced by another session with reason

---

## Active session format

For sessions in IN-FLIGHT or IN-FLIGHT-RO status, render the entry across multiple lines:

**S-EXAMPLE-LABEL** — IN-FLIGHT
  Started: 2026-05-07T14:32 UTC by desktop-1
  Touching: lib/Billing/, app/admin/leases/, FLEETFORGE_DATABASE_MASTER.sql

Required fields: STATUS keyword, Started (ISO 8601 timestamp + operator-supplied agent identifier).
Optional metadata: Touching (file paths or directories the session expects to write — used for collision detection in pre-flight check).

For IN-FLIGHT-RO, the Touching field is not required (read-only doesn't lock):

**S-EXAMPLE-AUDIT** — IN-FLIGHT-RO
  Started: 2026-05-07T14:32 UTC by desktop-2

When the session ships, update the entry to status SHIPPED with commit refs (per existing convention) and remove the multi-line IN-FLIGHT details.

---

## Active queue (as of 2026-05-12)

### IN-FLIGHT

*(none)*

### Documentation cleanup (queued, small)

**S-PREDEPLOY-CHECKLIST-CREATE** — SHIPPED 2026-05-12 (commits 7d5e62d + 2c5d146 + this C3 commit — see PROGRESS.md SESSION LOG)
Outcome: Created `FLEETFORGE_PREDEPLOY_CHECKLIST.md` as the canonical pre-deploy operations file. 21 backfilled obligations originally scoped (1 operator-specified A1 FF_ASSET_VERSION + 1 operator-specified F1 AR drift + 19 pre-existing scattered across SPEC_FINAL.md / REFERENCE.md / runbooks / .env) — expanded to 26 obligations across 8 active categories (A asset cache, B prod .env keys, C DNS, D AWS infra, F accounting state, G smoke, H rollback, I monitoring) + 1 empty placeholder (E data migrations) + 1 References section (J). D-B refinement: each item carries Originating session + Surfaced into checklist sub-fields. D-C refinement: "Original source:" pointer inside Detail. D-D refinement: bidirectional file-header cross-refs to K-14 + S-PREDEPLOY-CHECKLIST-CREATE. K-14 (Pre-deploy obligations category-separation discipline, K-12 extension) locked in PROGRESS.md KEY LEARNINGS. Cross-refs added to REFERENCE.md §0 LOCKED DECISIONS + §1 SEVEN FILES "Deployment-time companion" note; AWS Lightsail cutover entry (this file) extended with pointer to checklist; Pre-flight check section (D136) extended with category-separation note.

**S-D136-COMMIT-DISCIPLINE** — SHIPPED 2026-05-11 (bundled into S-DOCS-CLUSTER-2026-05-11 — see PROGRESS.md SESSION LOG)
Outcome: D-A of S-DOCS-CLUSTER-2026-05-11. Extended D136 wording in REFERENCE.md §0 LOCKED DECISIONS with a new "**Registration commit requirement (locked 2026-05-11 via S-D136-COMMIT-DISCIPLINE):** IN-FLIGHT registration must be COMMITTED to main before any subsequent operation (file edit, DB write, branch creation). Working-tree-only registration does not count as registered..." clause; cites S-ACCT-FIX-A1 + S-DOC-STATUS-RECONCILE-CLOSE-FIXUP as origin; clarifies branch isolation fallback addresses collision avoidance, not state-divergence avoidance. This session exercised the discipline being locked — IN-FLIGHT registration committed standalone (bd8dbb6) before content edits.

**S-D135-REFERENCE-PROMOTE** — SHIPPED 2026-05-11 (bundled into S-DOCS-CLUSTER-2026-05-11 — see PROGRESS.md SESSION LOG)
Outcome: D-B of S-DOCS-CLUSTER-2026-05-11. Added D135 index row to REFERENCE.md §0 LOCKED DECISIONS after D134, before D136 (terse-pointer format matching D134's pattern). Renamed existing §13.8 subsection heading from "Three valid configurations (D132/D133 clarified, S-MILEAGE-ALLOWANCE-ZERO-FIX, 2026-05-07)" to "Three valid configurations (D135, S-MILEAGE-ALLOWANCE-ZERO-FIX, 2026-05-07 — clarifies D132/D133)" so the §0 cross-reference resolves. Added D135 to the §13.8 "See also" list. Body content was already present in §13.8 (pre-existing from S-MILEAGE-ALLOWANCE-ZERO-FIX); promotion was the labeling + index row.

**S-LOOKUP-RATES-NAMESPACE-COMPLETE** — SHIPPED 2026-05-07 (commit d83c4e3 — see PROGRESS.md SESSION LOG)
Outcome: Single docs commit closing the docs rigor gaps from the original S-LOOKUP-RATES-NAMESPACE arc. Three pieces: D134 architectural lock added to PROGRESS.md DECISIONS + REFERENCE.md §0 LOCKED DECISIONS (canonical contract: `equipment_type` columns store `equipment_templates.category`, not `name`); KNOWN ISSUE #102 closed with full trace + commit refs; new `## KEY LEARNINGS — searchable index` section introduced with K-1 entry capturing namespace-mismatch fix protocol. SESSION LOG row backfilled in S-DOC-STATUS-RECONCILE-CLOSE (gap surfaced by S-QUEUE-STATUS-RECONCILE diagnostic).

**S-D130-EXTENSION** — SHIPPED 2026-05-11 (bundled into S-DOCS-CLUSTER-2026-05-11 — see PROGRESS.md SESSION LOG)
Outcome: D-C of S-DOCS-CLUSTER-2026-05-11. Extended D130 in PROGRESS.md DECISIONS with: "**Symmetric rule applies to scope CONTRACTION (locked 2026-05-11 via S-D130-EXTENSION):** if mid-session it becomes clear an originally-scoped item is out of scope, already shipped, or being deferred to another session, the contraction also requires explicit operator re-authorization + SESSION LOG diff capturing original-scope vs final-scope. Silent omission of scoped items is equally a discipline failure as silent addition." Origin cites S-MILEAGE-RATE-ZERO-FIX C1 silently dropping smoke gate work + INV-27/INV-84 voids. Treated as parallel promote-with-extension since D130 had zero REFERENCE.md presence pre-session — added §0 LOCKED DECISIONS row carrying combined expansion + contraction wording.

### UX session set (from real-use testing 2026-05-07)

**S-INVOICE-DISPLAY-COMPREHENSIVE** — SHIPPED 2026-05-11 (commits 8bc4e5c + aabb087 + 5dc2af2 + df1bd20 + f9130a2 — see PROGRESS.md SESSION LOG)
Outcome: 5-commit arc covering D-A through D-G + a T1-surfaced print-overlay fix. C2 (aabb087): backend data load extension — `$creditApplications` query against `credit_note_applications` mirroring `$invoicePayments` shape. C3 (5dc2af2): comprehensive line-item rendering with D-A draft-only $0 hide, dispatch contract comment block for S-MILEAGE-2A/2B extension, Credit Applications card between Line Items and Financial Summary (D-C), step-by-step Financial Summary with taxable/non-taxable split + "RATE% on $BASE" inline tax base + per-tax tooltip (D-B + D-D), dual S-MILEAGE-2B extension markers (HTML + PHP). C3 refinement (df1bd20): Subtotal label adapts to tax shape — plain "Subtotal" for single-class invoices, "(taxable lines) / (non-taxable lines)" only when both exist. C3-T1-FIX (f9130a2): global @media print hide list extended with `.search-modal, .search-overlay, .modal-overlay, .modal-backdrop, .sidebar-overlay` after T1 surfaced a dark semi-transparent overlay in browser Cmd+P / Save as PDF rendering. Side-fix disclosed per D130: pre-existing tax-rate display bug (rendered "0.05%" instead of "5%" since rates are stored as decimal fractions) was incidentally corrected in C3 by adding ×100 conversion in the label. T1 verification: technical via PHP CLI auth-faked render + synthetic CN apply against INV-13 with BEGIN/ROLLBACK isolation (rollback verified clean); operator visual T1 PASS on the 3 reference invoices (INV-19 tax-exempt sent, INV-13 paid ON HST, INV-21 BC GST+PST + discount). KEY LEARNING K-13 locked: global include CSS as universal print concern. `.env` FF_ASSET_VERSION 1.0.25 → 1.0.26 (uncommitted; .env gitignored).

**S-INVOICE-CREATION-UX** — SHIPPED 2026-05-07 (commits 044ffef + 6feb94c + cdb59ca + C4 — see PROGRESS.md SESSION LOG)
Outcome: 4-commit arc covering all three issues. C1 classified Issue 1 as VALIDATION GAP (KNOWN ISSUE #103, queued S-MILEAGE-RATE-VALIDATION-FOLLOWUP). C2 wired period auto-fill on invoice create form (12-fixture node smoke 12/12 PASS). C3 added Generate Invoice button on lease profile (8-cell PHP truth-table smoke 13/13 PASS). C4 = tests + this entry. Both new permanent smoke tests committed at tests/_smoke_invoice_create_period_autofill.js + tests/_smoke_lease_show_generate_invoice_button.php.

**S-UNIT-STATUS-COLOR** — QUEUED
Scope: small color indicator (dot or pill) next to equipment_unit references across all surfaces — fleet list, unit profile, lease references, invoice references, reservation references, maintenance work orders, universal search results, customer portal. Uses existing semantic tokens from FLEETFORGE_DESIGN_DETAILS.md (green=available, blue=on_lease, amber=maintenance, red=out_of_service/damaged, gray=retired).
Effort: ~30-45 min.
Dependencies: none.
Discussed: 2026-05-07 in planning chat.

### Bug investigation outcomes

**INV-2026-00090 mileage charge investigation** — RESOLVED via S-MILEAGE-ALLOWANCE-ZERO-FIX (2026-05-07)
Outcome: VALIDATION GAP classification (S-INVOICE-CREATION-UX C1) was reframed by operator as VALID-CONFIGURATION-NOT-RECOGNIZED. Lease 52's shape (rate=$0.18, allowance=0) is treated as Model B Lite ("bill every km from km 0") rather than a data hole. Engine fix in S-MILEAGE-ALLOWANCE-ZERO-FIX C1 (commit 2168bd5) restructured `$mileageBillingExpected` to key on `mileage_rate_km > 0`. INV-2026-00090 voided + regenerated as INV-2026-00091 with mileage line $91.26 + HST $11.86, total $2803.37 in C3 (commit ef050e7). KNOWN ISSUE #103 closed.
Side-findings closed: (a) duplicate-period draft INV-2026-00089 voided in C3; (b) period overshoot resolved by S-INVOICE-CREATION-UX C2 form auto-fill cap.

**S-MILEAGE-RATE-VALIDATION-FOLLOWUP** — SUPERSEDED 2026-05-07 by S-MILEAGE-ALLOWANCE-ZERO-FIX
Reason: original framing assumed defensive validation (reject the rate>0 + allowance=0 shape, queue 13-lease backfill). Operator reframed as engine-fix (admit the shape as Model B Lite, reframe Mileage::periodExcess to handle allowance=0 natively). The four planned layers reduced to one engine-guard restructure plus an I6 smoke invariant — no API rejection, no data backfill needed (12 of 13 affected leases had no exposed invoices; only lease 52's INV-90 needed regen). See S-MILEAGE-ALLOWANCE-ZERO-FIX SESSION LOG entry for full trace.

**S-REVIEW-MILEAGE-TAX-FIX** — SHIPPED 2026-05-12 (commits 3582e78 + 79934a0 + b1adbcd + docs — see PROGRESS.md SESSION LOG)
Outcome: 4-commit arc (1 IN-FLIGHT registration + 1 fix + 1 INV-92 remediation + 1 docs). C2 replaced the buggy `bcdiv(..., '100', 2)` wrapper at api/v1/invoices/review_mileage.php:213-218 with the canonical `bcround(bcmul(..., 6), 2)` pattern matching TaxCalculator.php:62; added WHY comment block referencing TaxCalculator.php:62 from the calling site; new stress test tests/_stress_review_mileage_tax.php (14 cases across canonical formula + source-pattern regression sentinel; pre-fix 9/14, post-fix 14/14 PASS exit 0). Pre-work scan #7 (full SELECT for ALL `mileage_adjustment` line items per K-15 discipline) surfaced INV-2026-00092 line 175 ($699.84 base, line_hst=$0.90 → $90.98) — created post-C5 audit-pass and missed by inv91_tax_correct_2026_05_07.php. C3 single-transaction audited UPDATE in scripts/inv92_tax_correct_2026_05_12.php mirrored C5's inv91 pattern (--dry-run/--execute + idempotent guard). Visible impact: line tax_hst $0.90 → $90.98 (delta +$90.08); invoice tax_hst_amount $106.21 → $196.29; tax_total $107.11 → $197.19; total_amount $1623.95 → $1714.03; balance_due $1623.95 → $1714.03; lease 52 total_invoiced += $90.08. K-15 locked (data-path coverage discipline). D131 gate clean on every commit.

### Mileage refactor arc (Model B — Avi's preferred billing model)

**S-MILEAGE-2A** — SHIPPED 2026-05-12 (commits b602bd9 IN-FLIGHT + 253b294 C2 activate.php balance init + e1918df C3 InvoiceGenerator precharge emit + (b) gate + engine markers + c8e459a C4 send.php stamp + 409 + 365d541 C5 T14/T15/T16 smoke ADD + b16c0fc C6 I7 invariant + 8d17548 C7 docs + this T1 PASS addendum — see PROGRESS.md SESSION LOG)
Outcome: Full Model B Invoice 1 lifecycle landed end-to-end — precharge_balance init on lease activation (D137), `mileage_precharge` line emitted on Invoice 1 generation under the 3-clause gate (D138: lifecycle + (b) cross-invoice uniqueness + billing_type exclusion), precharge_invoiced_at stamp on Invoice 1 send (D140 happy path) + 409 PRECHARGE_ALREADY_BILLED backstop for duplicate-send protection (D140 unhappy path), I7 invariant for precharge-tier rate-tier completeness (D143 / D132 extension). Decisions D137-D146 (D-A through D-J) locked. Mid-session clarifications surfaced + operator-approved: (b) cross-invoice uniqueness gate at C3 emission site paired with C4 send-time 409 (belt + suspenders defense-in-depth); ADD-not-REPLACE for T14/T15/T16 (preserves T8/T10/T12 placeholders as 2B carry-forward per REFERENCE.md §13.6 framing). All 5 stress tests + 16/16 smoke + I1-I7 invariants + parity + migrate clean on every commit. **T1 visual sign-off (5-step walk per D146): ✓ PASS — operator-confirmed 2026-05-12.**

---
### S-MILEAGE-2B — Drawdown on subsequent invoices + Model C retirement + Odometer card rewrite + Bug 1/Bug 4 riders + FLEETFORGE_SPEC_FINAL.md rewrite
**Status:** QUEUED
**Estimated effort:** ~3–4 hrs (revised from operator's original ~2 hr — pre-work scan surfaces 19 D-decisions and a proposed 10-commit arc; surface is materially bigger than 2A's 7-commit / ~90-min arc)
**Dependencies:** S-MILEAGE-2A shipped (✅ 2026-05-12 — see commits b602bd9 → 253b294 → e1918df → c8e459a → 365d541 → b16c0fc → 8d17548 + T1 PASS addendum e758f13)
**Subsequent sessions:** S-MILEAGE-3 (lease close + cash/credit refund toggle + priorExcessKm safeguard retirement) → S-MILEAGE-5 (20 hermetic scenario tests against Model B)

#### PRE-WORK SCAN FINDINGS (run 2026-05-11/12 during S-MILEAGE-2B-SPEC-WRITE — surface to operator for sign-off before C2 of the main 2B session)
- **A. K-15 Model C data coverage:** 3 invoices carry non-trivial Model C columns — INV-2026-00087 (id=87, status=draft, mrs=`pending`, excess_distance_km=7115.04, excess_charge_amount=$1280.70), INV-2026-00091 (id=112, status=draft, mrs=`approved`, edk=507.04, eca=$91.26), INV-2026-00092 (id=148, status=draft, mrs=`approved`, edk=3888.00, eca=$699.84). 2 `mileage_adjustment` line items in production: li.id=124 ($91.26 on INV-91) + li.id=175 ($699.84 on INV-92). Zero `mileage_credit` line items. All 3 affected invoices are DRAFTS — no `sent` / `paid` / `partially_paid` rows in scope. No AR ledger impact. Operator has effective flexibility on D-G retirement shape.
- **B. lease_billing_periods table:** 67 rows total, 40 in last 30 days. Actively written by `lib/Billing/InvoiceGenerator.php:883` (one row per generated invoice). 0 invoices carry `billing_period_id` FK to LBP (the linkage is one-way — InvoiceGenerator writes LBP, but `invoices.billing_period_id` is not populated). LBP stays **load-bearing per K-15 framing**; out of 2B retirement scope (separate cleanup session if the unused FK is to be retired).
- **C. review_mileage.php endpoint usage:** 1 pending review in production — INV-2026-00087 (id=87, draft, $1280.70 excess). Endpoint called from `app/admin/invoices/show.php:2846` Alpine state. Operator decision required in **D-I** for endpoint disposition + INV-87 handling pre-retirement.
- **D. priorExcessKm safeguard:** confirmed extensively in `api/v1/leases/close.php` (~24 references at lines 810, 834-843, 1051-1069, 1218, 1239-1267, 1306, 1322-1339, 1358). **NOT TOUCHED this session** per S-MILEAGE-3 ownership. Explicit no-touch marker in D-J.
- **E. Engine marker locations:** 1 hit in `lib/Billing/InvoiceGenerator.php:219` (2A C3 plant — comment block at lines 217-245 carrying the forward-looking 2B drawdown contract). 7 hits across `app/admin/invoices/show.php` in 3 marker sites — PHP marker block at lines 1754-1782 (taxable subtotal docblock with S-MILEAGE-2A/2B note at 1775-1781), dispatch contract block at 1866-1906 (comment 1867 + pending-types comment 1882-1884), HTML marker at line 2182 (template comment between line items aggregate and tax block). All markers placed by S-INVOICE-DISPLAY-COMPREHENSIVE C3 (commit 5dc2af2, brought to main via merge ab122eb).
- **F. Post-2A canonical state verified:** D137-D146 present in PROGRESS.md DECISIONS table (D-A through D-J of S-MILEAGE-2A). I7 present in `tests/_smoke_billing_invariants.php` summary line ("I1 ... I7"); 0 fails on D131 gate. T14/T15/T16 present in `tests/_smoke_samsara_distance.php` (count 16 — T8/T10/T12 placeholders preserved as 2B carry-forward per REFERENCE.md §13.6 framing). REFERENCE.md §13.4.1 lifecycle table shows `precharge_balance` + `precharge_invoiced_at` as ✓ S-MILEAGE-2A SHIPPED 2026-05-12 (not "S-MILEAGE-2" forecast). D131 gate clean at spec-write time: PARITY OK + INVARIANTS OK + migrate 11/0/0.

#### SCOPE (locked — owns full Model B drawdown lifecycle, Model C plumbing retirement, spec rewrite)
- Add `mileage_drawdown_credit` + `mileage_usage` to `invoice_line_items.item_type` ENUM via dedicated migration (D-A).
- Rewrite `lib/Billing/InvoiceGenerator::createFromLease` drawdown emission — replaces the S-LEASE-MILEAGE per-period excess block at `lib/Billing/InvoiceGenerator.php:559-713` wholesale with 3-clause drawdown math (D-B).
- Integrate `SamsaraClient::getDistanceForPeriod` into the invoice generator distance fetch path (D-C). Replace legacy `getMileageForLease` / `getOdometerReading` callsites where present.
- Tax handling for the negative `mileage_drawdown_credit` line — TaxCalculator must apply tax to NET (usage − credit) per the S-INVOICE-DISPLAY-COMPREHENSIVE C3 marker contract (D-D).
- Extend `app/admin/invoices/show.php` LINE TYPE DISPATCH CONTRACT block (1866-1906) — ENUM count 14 → 16, add badge match arms (D-E).
- Render Financial Summary mileage breakdown between line items aggregate and tax block per the HTML marker at `app/admin/invoices/show.php:2182` (D-F).
- Retire Model C plumbing: `invoices.excess_distance_km`, `invoices.excess_charge_amount`, `invoices.mileage_review_status` columns + ENUM (D-G — **OPERATOR DECISION REQUIRED**).
- Retire `Mileage::monthlyAllowance` + `Mileage::periodExcess` helpers; keep `leaseDurationMonths` + `toDisplayUnit` + `formatDistance` (D-H).
- Retire HARD send gate at `api/v1/invoices/send.php:50-63` (D84 `MILEAGE_REVIEW_REQUIRED` 422); decide disposition of `api/v1/invoices/review_mileage.php` + INV-87 pending row (D-I — **OPERATOR DECISION REQUIRED**).
- Keep priorExcessKm transitional safeguard intact at `api/v1/leases/close.php` — explicit no-touch (D-J).
- Rewrite Odometer & Distance card at `app/admin/invoices/show.php:1480-1545` (D-K).
- Mileage Review card disposition at `app/admin/invoices/show.php:1554-1640` (D-L — **OPERATOR DECISION REQUIRED**).
- Rewrite `FLEETFORGE_SPEC_FINAL.md` mileage section: Model A → Model B per D144 inherited from S-MILEAGE-2A (D-M — **OPERATOR DECISION REQUIRED** on scope: full-lifecycle vs precharge+drawdown-only).
- `FLEETFORGE_ACCOUNTING_SPEC.md` drawdown accounting entries (D-N — **OPERATOR DECISION REQUIRED**; likely DEFER to S-MILEAGE-3 + accountant conversation).
- New smoke invariant I8 for drawdown math sanity (D-O).
- Real T8/T10 coverage activation — production HTTP loop + parser paths finally exercise (D-P).
- Riders: Bug 1 (Cumulative Total label) + Bug 4 (backdate warning) (D-Q — **OPERATOR DECISION REQUIRED** on rider wording).
- Schema migration count + ordering — expected 2–3 migrations (D-R).
- Single PR vs multi-commit arc breakdown — proposed 10-commit shape (D-S).

#### OUT OF SCOPE for 2B (deferred to subsequent sessions)
- Cash/credit refund toggle at lease close (S-MILEAGE-3)
- `lease_close_adjustments` table retirement (S-MILEAGE-3)
- priorExcessKm safeguard retirement at close.php (S-MILEAGE-3)
- `precharge_refund_method` + `precharge_refund_settled_at` column writes (S-MILEAGE-3)
- Customer portal rendering of drawdown balance (S-MILEAGE-4 placeholder)
- 20 hermetic scenario tests against Model B (S-MILEAGE-5)
- LBP table retirement / FK cleanup (separate cleanup session; load-bearing per scan B)
- Accounting deferred-revenue ledger entries (DEFERRED to S-MILEAGE-3 + accountant — see D-N)

#### HYBRID STATE DURING 2B EXECUTION (live-coherent by design across the 10-commit arc)
- C1–C2: ENUM + dispatch landed; no engine emit yet. Engine still emits Model C per-period excess on Invoice 2+. Live state unchanged from post-2A.
- C3: engine flips to drawdown emit. Invoice 1 on precharge-enabled leases continues to emit `mileage_precharge` (2A path). Invoice 2+ on precharge-enabled leases (post-D140 stamp) emits `mileage_usage` + optional `mileage_drawdown_credit` per the drawdown math. **Model C per-period excess block deleted in same commit** — no two-engines hybrid state.
- C4: Model C plumbing retirement. `excess_distance_km` / `excess_charge_amount` / `mileage_review_status` columns drop (per D-G option (i)) + backup table per D107 + `Mileage::monthlyAllowance` / `periodExcess` retire per D-H. Pre-DROP: K-15 confirmation that INV-87 is dispositioned per D-I in same commit OR earlier (commit ordering depends on operator's D-G / D-I choices).
- C5: HARD send gate at send.php:50-63 retires; review_mileage.php endpoint disposition per D-I.
- C6–C10: display polish + riders + smoke + spec rewrite. No engine state change after C3.

#### LOCKED DECISIONS

**D-A — ENUM additions to `invoice_line_items.item_type`**
- Add: `mileage_drawdown_credit`, `mileage_usage`. ENUM grows from 14 → 16 values.
- Migration filename per D90 UTC-prefix convention: `YYYYMMDDHHMM_S-MILEAGE-2B_drawdown_line_items.sql`.
- ENUM column ordering: append at end of value list (NOT slot in at the position of any retired value). Confirmed via D126 master-file column-add discipline applied to ENUMs by analogy: `ALTER TABLE … MODIFY COLUMN item_type ENUM(...append...)` semantics preserve ordinal index of existing values when appending.
- Backup table: skipped per D128 trivial-data rule — pre-migration SELECT confirms ZERO production rows have either new ENUM value (this session is the first emitter). Migration carries explanatory comment block documenting the omission per D128 discipline.
- Same idempotent ALTER pattern as S-MILEAGE-1's `precharge_schema.sql` — wraps in `ff_alter_enum_if_missing` helper (define if absent in lib).
- D127 parity smoke + D131 invariants gate run pre-commit; `FLEETFORGE_DATABASE_MASTER.sql` ENUM definition at line 1898 updated in same commit per D87.

**D-B — Drawdown emit shape in `InvoiceGenerator::createFromLease`**
- Site: replaces the S-LEASE-MILEAGE per-period excess block at `lib/Billing/InvoiceGenerator.php:559-713` wholesale. The engine marker comment block from 2A C3 (lines 217-245, greppable via "S-MILEAGE-2B inserts drawdown logic") is replaced with a forward-looking 2B-shipped + S-MILEAGE-3 close-refund comment block.
- Three-clause math (bcmath per D16; all operands strings; results bcround to 2 dp):
  ```
  period_charge    = bcround(bcmul(period_distance_km, mileage_rate_km, 6), 2)
  drawdown_amount  = bccomp(period_charge, precharge_balance, 2) <= 0
                       ? period_charge : precharge_balance
  remaining_charge = bcsub(period_charge, drawdown_amount, 2)
  ```
- Line emission rules:
  - **IF `precharge_balance > 0` (bccomp > 0):**
    - emit `mileage_usage` line — `amount = period_charge` (positive), `is_credit = 0`, `taxable = 1`, `mileage_distance = period_distance_km`, `mileage_rate = mileage_rate_km`, `mileage_unit = 'km'` per D82, description "Mileage usage: X.XX km × $RATE/km".
    - emit `mileage_drawdown_credit` line — `amount = -drawdown_amount` (negative), `is_credit = 1`, `taxable = 1`, `mileage_distance = NULL`, `mileage_rate = NULL`, description "Precharge credit applied: drawdown of $X.XX from balance".
    - UPDATE `leases.precharge_balance = precharge_balance - drawdown_amount` inside the same invoice-generation transaction (FOR UPDATE on lease per D20 — already locked by 2A C3 pattern).
    - audit_log row: `entity_type='lease_precharge_balance_drawdown'`, `action='update'`, `new_values` JSON carrying `pre_balance` / `drawdown_amount` / `post_balance` / `invoice_id` / `invoice_number` / `period_charge` for searchable trail.
  - **IF `precharge_balance == 0` OR NULL (bccomp <= 0 / NULL coalesce to 0):**
    - emit `mileage_usage` line only at per-km rate (same shape as above).
    - NO `mileage_drawdown_credit` line. NO `precharge_balance` UPDATE (already at zero — no-op).
- **Emission gate** (mirrors D138 lifecycle gate shape; complementary predicate):
  - `period_distance_km IS NOT NULL AND period_distance_km > 0`
  - AND `mileage_rate_km > 0` (D135 intent signal — Model B Lite emits at this gate too)
  - AND `billing_type NOT IN ('mileage_only', 'adjustment', 'credit_note')` (matches the `$zeroAllowed` set at InvoiceGenerator.php:175)
  - The `precharge_invoiced_at IS NOT NULL` discriminator is NOT a gate — drawdown is silent for Model B Lite leases (precharge_enabled=0 → precharge_balance is NULL → falls to second branch, emits usage only). Engine doesn't branch on Model identity; same surface for Model B (full) and Model B Lite.
- **Optimistic-lock retry (D19) consideration:** drawdown UPDATE on `leases.precharge_balance` runs under FOR UPDATE (D20) within the InvoiceGenerator transaction — serializes correctly against parallel cron + manual gen. If `updated_at` mismatch is needed for cross-transaction retry, fold into existing optimistic-lock pattern. Initial implementation: simple FOR UPDATE.

**D-C — `SamsaraClient::getDistanceForPeriod` integration into InvoiceGenerator**
- Replace any legacy `getMileageForLease` / `getOdometerReading` callsites in `InvoiceGenerator.php` with `getDistanceForPeriod(vehicleId, period_start, period_end)` per the S-MILEAGE-1B contract at `lib/GPS/SamsaraClient.php:1245`. Import as `\FleetForge\GPS\SamsaraClient`.
- Bookend timestamps + warnings + source field surface to invoice form per D116-D125:
  - `period_distance_km`, `odometer_at_period_start_km`, `odometer_at_period_end_km` ALREADY present on invoices schema.
  - `odometer_source`, `odometer_fetched_at` ALREADY present.
  - **NEW fields possibly needed** (D-R determines): `samsara_first_reading_at` DATETIME NULL, `samsara_last_reading_at` DATETIME NULL, `samsara_warnings_json` JSON NULL — IF the warnings advisory set (large_gap_detected, sparse_readings, reading_outside_period, gateway_reset_detected per D121) is to persist on the invoice row for D-K render. Alternative: render warnings only in the invoice creation form (transient) and don't persist. **Pre-work scan G** (run at C1 of main session) reads `lib/GPS/SamsaraClient.php` end-to-end to determine which fields are wire-only vs need persistence.
- audit_log entry per Samsara call from the InvoiceGenerator path: `entity_type='samsara_history_query'`, `action='cron'` per D102/D123 ENUM workaround.
- Every distance field MUST remain manually editable per project memory rule ("Samsara is source-of-truth-by-default, never source-of-truth-by-force"). Form-side UX preserved: Samsara source + bookend timestamps surface as helper-text annotations on the user-editable distance input, NOT as a `:readonly` lock.

**D-D — Tax handling for the drawdown credit line — OPERATOR DECISION REQUIRED on TaxCalculator behavior**
- The credit line is `taxable=1, is_credit=1, amount` = negative. The PHP marker block at `app/admin/invoices/show.php:1775-1781` notes "taxable subtotal must include NET amount" — tax computes on (usage − credit), not on each line independently.
- **Pre-work scan G (in main 2B session, before C3):** read `lib/Billing/TaxCalculator.php` end-to-end. Determine whether `TaxCalculator::calculate($taxableSubtotal, ...)` is called with a pre-aggregated subtotal (signed sum of line amounts) or whether tax is computed per-line and summed.
  - **If pre-aggregated (signed sum):** contract holds with no TaxCalculator change. The `$subtotal` at InvoiceGenerator.php:482 already sums line amounts respecting `is_credit` semantics — verify this.
  - **If line-by-line absolute-value:** scope expansion surface — TaxCalculator must be extended to sign-aware aggregation per D130 scope-expansion discipline. Surface to operator before C3.
- **Verification stress case:** precharge-enabled lease, Invoice 2 with $100 mileage_usage + $40 mileage_drawdown_credit on a 5% GST invoice → expected $60 taxable subtotal → $3.00 GST (NOT $5.00 − $2.00 = $3.00 by coincidence — the principle is "tax on net". Verifiable by flipping to 13% HST → $7.80 from $60 base, NOT $13.00 − $5.20 = $7.80 by coincidence). Both rates yield the same dollar result by arithmetic equivalence; the discriminator is mixed-rate scenarios (e.g. BC GST+PST where line-by-line absolute-value would over-tax the credit at the wrong rate).
- Stress test `tests/_stress_tax_calculator_drawdown_net.php` ships with C6.

**D-E — `app/admin/invoices/show.php` LINE TYPE DISPATCH CONTRACT extension (3-step protocol per show.php:1866-1906)**
- **Step 1: ENUM migration** — handled by D-A.
- **Step 2: `$itemTypeBadge` match arm additions** at lines 1889-1906:
  - `'mileage_drawdown_credit' => 'badge-success'` (customer credit — green family, mirrors `mileage_credit` semantic at line 1899)
  - `'mileage_usage' => 'badge-info'` (neutral metering — info family, mirrors `mileage_precharge` semantic at line 1891 set by S-INVOICE-DISPLAY-COMPREHENSIVE C3)
- **Step 3: Per-row template treatment** — the `$isMileage` detection at `show.php:1857` already includes the new types via the dispatch contract comment at 1879-1885 ("Currently supported … Pending types (S-MILEAGE-2A/2B): mileage_drawdown_credit + mileage_usage"). After 2B:
  - Mileage detail span at 1929-1944 renders `mileage_distance` + `mileage_rate` per-km annotation for `mileage_usage` rows (populated in D-B).
  - `mileage_drawdown_credit` rows render with `mileage_distance = NULL` + `mileage_rate = NULL` (suppresses the detail span per the 2A precharge pattern — no per-km math on the credit row, just the flat credit amount).
- Update dispatch contract comment block at 1879-1885: bump ENUM count from 14 to 16; strike "Pending types (S-MILEAGE-2A/2B)" line (both types now supported). Add a S-MILEAGE-3 note IF close-refund line types are forecast for that session.
- Update PHP marker at 1769-1782: strike "S-MILEAGE-2A/2B note" (both arcs landed); add a S-MILEAGE-3 note IF refund line types are forecast.

**D-F — Financial Summary mileage breakdown rendering**
- HTML marker site: `app/admin/invoices/show.php:2182` (template comment "Model B mileage breakdown (usage − credit) slots here between line items aggregate and tax block"). Replace with active rendering.
- Format proposal (locked subject to D-M spec text alignment):
  ```
  Subtotal (taxable lines)            $X.XX
    Mileage usage                       $Y.YY
    Precharge credit applied          −$Z.ZZ
    Net mileage charge                  $W.WW
  GST 5% on $A.AA                       $G.GG
  HST 13% on $H.HH                      $T.TT
  Total                                 $F.FF
  ```
- Rendering rules:
  - Full drawdown case (both `mileage_usage` + `mileage_drawdown_credit` present): render the 3-row breakdown block (usage / credit / net) between line items aggregate and tax block.
  - Model B Lite (usage line only, no credit): standard line items aggregate only — no breakdown block.
  - Pure-precharge Invoice 1 (precharge line only): inherits 2A rendering — no breakdown block.
- Tax base tooltip ("Applied to N taxable lines totaling $X" from S-INVOICE-DISPLAY-COMPREHENSIVE C3) reconciles against the NET mileage charge, NOT the gross usage amount.
- Reuse existing "RATE% on $BASE" inline format + per-tax tooltip from S-INVOICE-DISPLAY-COMPREHENSIVE C3 (df1bd20 single-class vs both-class subtotal label adaptation preserved).

**D-G — Model C plumbing retirement — OPERATOR DECISION REQUIRED**
- Affected columns on `invoices`: `excess_distance_km` DECIMAL(10,2), `excess_charge_amount` DECIMAL(12,2), `mileage_review_status` ENUM. Also `mileage_override_amount`, `mileage_reviewed_at`, `mileage_reviewed_by` columns IF they exist (re-verify at C1 pre-work scan).
- K-15 data: 3 affected invoice rows (drafts INV-87 / INV-91 / INV-92); 2 affected line items (`mileage_adjustment` on INV-91 + INV-92). Zero `mileage_credit` in production.
- **Two retirement options for operator pick:**
  - **(i) Wholesale DROP with backup table snapshot:** mirror D107 pattern — `CREATE TABLE invoices_model_c_backup_S_MILEAGE_2B` capturing `(invoice_id, excess_distance_km, excess_charge_amount, mileage_review_status, mileage_override_amount, mileage_reviewed_at, mileage_reviewed_by, snapshot_taken_at)` for ALL invoice rows per D107 capture-all discipline (not just non-NULL — cheap insurance). Then DROP the columns + retire the `mileage_review_status` ENUM type. Drafts INV-91 + INV-92 KEEP their `mileage_adjustment` line items (line items preserved in `invoice_line_items` table; only per-invoice aggregate columns drop). Pre-DROP: INV-87 dispositioned per D-I (`mrs='pending'` becomes structurally invalid after column drop).
  - **(ii) Preserve in place + nullify forward:** keep columns; new invoices set to NULL via no-op (default behavior post-engine retirement). Risk per D104 / K-15: schema carries dead columns indefinitely; future readers may wire them up by mistake.
- **Recommended:** option (i) — wholesale DROP with backup table — for schema cleanliness + K-15 / D129 discipline (no dead-column ambiguity; future grep for the column names returns zero hits in production paths).
- Pre-DROP scan re-run with NO scope filter per D129 (audit-scope-vs-general-usage discipline): repo-wide grep for `excess_distance_km`, `excess_charge_amount`, `mileage_review_status`, `mileage_override_amount`, `mileage_reviewed_at`, `mileage_reviewed_by` across `*.php` / `*.md` / `*.sql` / `*.json` / `*.js` (excluding vendor / node_modules) before destructive ops. Categorize hits per D129 (SCHEMA / HISTORY / PRODUCTION CODE / TESTS / SCRIPTS) so production hits stand out.
- **Disposition for the 2 historical `mileage_adjustment` line items on INV-91 + INV-92:** preserve as-is. They're drafts; no AR impact; legacy display path renders them as `badge-warning` per D104. The retirement removes the per-period gate but not the historical line item type — `mileage_adjustment` stays in the ENUM as a closed historical category.

**D-H — Mileage helper retirement**
- **Delete:** `Mileage::monthlyAllowance` (lib/Billing/Mileage.php:101-125) — Model B drawdown doesn't compute allowance; the engine math is `distance × rate`, no allowance subtraction. Re-grep callsites pre-DELETE per D129 — InvoiceGenerator drawdown emit (post-C3) doesn't call this. Lease close path at close.php still calls it indirectly via the `Mileage::periodExcess` chain → covered by D-J no-touch on close.php. Confirm at edit time.
- **Delete:** `Mileage::periodExcess` (lib/Billing/Mileage.php:142-167) — same reasoning. The `max(0, distance − allowance) × rate` math is replaced by direct `distance × rate` in the drawdown emit.
- **Keep:** `Mileage::leaseDurationMonths` (used by close.php), `Mileage::toDisplayUnit` (used by lease/show + invoice/show), `Mileage::formatDistance` (used by display surfaces).
- D103 pattern applies — vestigial scaffolding deletion only when grep confirms zero callers in production paths.

**D-I — HARD send gate retirement + review_mileage.php disposition — OPERATOR DECISION REQUIRED**
- **HARD send gate at `api/v1/invoices/send.php:50-63`** (D84 `MILEAGE_REVIEW_REQUIRED` 422): cleanly removed. Drawdown is silent (operator pre-commits the precharge amount at lease activation; the per-invoice drawdown is a deterministic ledger operation, not a policy decision requiring manager review).
- **review_mileage.php endpoint disposition — OPERATOR DECISION REQUIRED:**
  - **(i) Wholesale retire:** delete `api/v1/invoices/review_mileage.php` + show.php caller at line 2846 + Alpine state in the Mileage Review card. INV-87 (the lone pending invoice) MUST be dispositioned pre-retirement: (a) void INV-87 + regenerate as a Model B drawdown invoice on the same lease, OR (b) auto-approve INV-87 via direct DB UPDATE setting `mileage_review_status='approved'` + the manager-reviewed audit_log row, OR (c) defer disposition to S-MILEAGE-3 (NOT recommended — D-G column DROP requires `mrs` to be structurally valid pre-DROP).
  - **(ii) Keep for legacy invoices only:** endpoint stays callable but only resolves the existing `mileage_review_status='pending'` row(s). Future invoices never set `pending` (no engine emits it). Endpoint structurally dead-ends after INV-87 is resolved.
- **Recommended:** option (i) wholesale retire + INV-87 disposition handled inline in C5. The endpoint has 1 production use; option (ii) leaves a half-retired endpoint that's confusing to future readers + couples C4 (column DROP) to a stale production row. Operator confirms in C1 of main 2B session.
- **INV-87 disposition recommendation:** option (a) — void + regenerate as Model B drawdown. The $1280.70 excess charge is post-2B redundant (Model B's drawdown handles the same distance billing without a review gate). Regen on the lease with whatever Model B precharge config the lease carries today. If lease's `precharge_enabled=0` (Model B Lite), regen produces a `mileage_usage` line with the same per-km math + no review gate. Operator confirms in C1.

**D-J — priorExcessKm transitional safeguard from S-MILEAGE-FIX-0 (D98) — NOT TOUCHED THIS SESSION**
- Confirmed: `api/v1/leases/close.php` carries the safeguard at lines 810, 834-843, 1051-1069, 1218, 1239-1267, 1306, 1322-1339, 1358 (~24 references including the `priorExcessRow` query at line 810, the unit conversion at 834-843, the inverse-case detection at 1066-1069, the WARNING banner audit at 1239-1267, the SKIPPED waived case at 1322-1339, and the `prior_excess_km` field on the api response).
- S-MILEAGE-3 owns retirement per the queue entry + REFERENCE.md §13.4.1 "What S-MILEAGE-3 must do" section + REFERENCE.md §13.4 "What replaces this in S-MILEAGE-1+" list.
- **Explicit NO TOUCH** marker on `api/v1/leases/close.php` for the main 2B session — any commit attempting to edit close.php triggers a halt + operator re-authorization per D130 scope-expansion discipline.

**D-K — Odometer & Distance card rewrite**
- Site: `app/admin/invoices/show.php:1480-1545` (current 180px `<dl>` grid pattern from S-INVOICE-SHOW-RESPONSIVE D-E rider).
- New content (full drawdown invoice — both `mileage_usage` + `mileage_drawdown_credit` lines present):
  ```
  Period Distance        XXX.XX km   [GPS]
  Period Charge          $YY.YY      (XXX.XX × $RATE/km)
  Precharge Balance Pre   $ZZ.ZZ
  Drawdown Applied       −$WW.WW
  Precharge Balance Post  $AA.AA
  ```
- Bookend timestamps section (Samsara source only): "First reading at YYYY-MM-DD HH:MM, last reading at YYYY-MM-DD HH:MM" + advisory warning chips (large_gap_detected, sparse_readings, reading_outside_period, gateway_reset_detected per D121). Source for these data: either persisted columns per D-R OR the audit_log `entity_type='samsara_history_query'` row for the same lease + period (lookup by lease_id + period_start + period_end ordered by created_at DESC LIMIT 1).
- Model B Lite (usage line only, no precharge): Period Distance + Period Charge only — no balance pre/post rows.
- Pure-precharge Invoice 1: card stays in 2A shape (Odometer Start / Period Start / Period End readings, no balance — 2A doesn't touch precharge_balance on Invoice 1 send).
- Responsive treatment: extend S-INVOICE-SHOW-RESPONSIVE pattern (single-column at <768px, 2-column at wider). `ff-print-hide` class preserved (Odometer detail is internal telematics context per K-13).
- Pull current `lease.precharge_balance` for the post-render lookup OR pull from the audit_log `lease_precharge_balance_drawdown` row keyed on `new_values.invoice_id = $invoice->id` for historical accuracy at the moment the invoice was generated.

**D-L — Mileage Review card disposition — OPERATOR DECISION REQUIRED**
- Site: `app/admin/invoices/show.php:1554-1640+` (~110 line `<div id="mileage-review-card">` block + Alpine state at line 2846).
- **Two options for operator pick:**
  - **(i) Retire wholesale:** delete the card + Alpine state + the api/v1/invoices/review_mileage.php caller. Model B drawdown is silent — no review needed. Page layout gap closes naturally (Financial Summary follows Line Items directly).
  - **(ii) Convert to "Drawdown Reconciliation" panel:** repurpose the card to display the precharge balance lifecycle on the invoice — pre-drawdown balance, applied amount, post-drawdown balance, link to the lease's full precharge history. Useful for operator inspection / audit trail surfacing on the invoice show page. Reuses existing card surface (badge + 13px heading + paragraphs).
- **Recommended:** option (ii) — Drawdown Reconciliation panel — for operator UX continuity + audit visibility. The card surface is proven; option (i) leaves a layout gap that some readers will find jarring. The reconciliation data is already needed for D-K's Odometer card; option (ii) extends the visibility without new data fetch surface.
- Operator confirms in C1 of main 2B session.

**D-M — `FLEETFORGE_SPEC_FINAL.md` Model A → Model B rewrite scope — OPERATOR DECISION REQUIRED**
- Inherited from D144 (S-MILEAGE-2A): spec rewrite owned by 2B.
- **Two scope options:**
  - **(i) Full Model B picture:** precharge (S-MILEAGE-2A) + drawdown (S-MILEAGE-2B this session) + close-refund (S-MILEAGE-3 forecast). Section reads as the full lifecycle including the cash/credit refund toggle, with forward-looking text for unimplemented sections (the original Model A section was speculative until S-MILEAGE-FIX-0 confirmed Model C shipped instead — precedent for forward-looking text in the spec).
  - **(ii) Precharge + drawdown only:** defer close-refund text to S-MILEAGE-3 owning that section. Section reads as the active state through drawdown; close-refund left as a forward-looking note "see §X for close-refund — S-MILEAGE-3."
- **Recommended:** option (i) — full Model B picture — the spec's value is describing the canonical model end-to-end, not a snapshot mid-arc.
- Operator confirms in C1 of main 2B session.

**D-N — `FLEETFORGE_ACCOUNTING_SPEC.md` drawdown accounting entries — OPERATOR DECISION REQUIRED + LIKELY DEFERRED**
- Accounting framing (preview — not authoritative without accountant sign-off): the precharge is a liability on lease activation (DR Cash / CR Deferred Revenue OR DR Cash / CR Customer Deposit). The drawdown is revenue recognition (DR Deferred Revenue / CR Mileage Revenue + tax accrual on the net amount). The close-time refund is liability extinguishment (DR Deferred Revenue / CR Cash OR CR Customer Credit).
- **Operator decision:** in scope for 2B, or DEFERRED to S-MILEAGE-3 + accountant conversation?
- **Recommended:** DEFER to S-MILEAGE-3 + accountant conversation. Accounting entries are accountant-conversation-blocked — the framing above is standard but the specific account code mapping (existing 1030 AR vs new 2030/2040-style Deferred Revenue account) needs accountant sign-off. S-MILEAGE-3 close session is the natural anchor since refund routing is the surface that exercises the full liability-extinguishment path.
- 2B ships drawdown without accounting-spec edits since the engine emits ledger-neutral line items — the `AutoEntryBridge` at `lib/Accounting/AutoEntryBridge.php::onInvoiceSent` (called from send.php:230) already handles `mileage_*` lines via its existing pattern. Net AR impact is correct because tax accrues on NET subtotal per D-D, and revenue is recognized on the same NET.
- Operator confirms DEFER in C1 of main 2B session; if NOT deferred, the spec text + AutoEntryBridge extension lands as an additional commit before C9.

**D-O — New smoke invariant I8 for drawdown math sanity**
- Predicate: "for every invoice carrying a `mileage_usage` + `mileage_drawdown_credit` line pair, the absolute credit amount must equal `min(usage_amount, pre_emission_precharge_balance)` AND the post-emission `precharge_balance` (from the audit_log `lease_precharge_balance_drawdown` row) must equal `pre − abs(credit)`."
- SQL shape: JOIN `invoice_line_items` to itself on `invoice_id` filtering `item_type IN ('mileage_usage', 'mileage_drawdown_credit')`; LEFT JOIN to the `audit_log` row with matching `entity_type='lease_precharge_balance_drawdown'` + `JSON_EXTRACT(new_values, '$.invoice_id') = i.id`; compute expected credit from usage_amount + audit_log's `pre_balance`; fail rows where recorded `abs(credit) != min(usage, pre_balance)` OR where `post_balance != pre_balance - abs(credit)`.
- Smoke summary line: "INVARIANTS OK — I1 ... I8" — bump count from 7 to 8.
- Stress test: `tests/_stress_smoke_invariants_i8.php` — BEGIN/ROLLBACK fault-injection pattern, mirroring I7 stress test discipline (CREATE TEMPORARY TABLE clones if needed for fault injection without violating CHECK constraints). Cases: (a) credit > usage on a pair; (b) credit > pre_balance on a pair; (c) post_balance ≠ pre_balance − abs(credit); (d) valid pair passes; (e) Model B Lite invoice (usage only, no credit) passes; (f) pure-precharge Invoice 1 (precharge only, no usage/credit) passes.
- D131 gate runs I8 on every commit C2-C10.

**D-P — T8/T10 placeholder coverage activation**
- With 2B wiring `getDistanceForPeriod` into InvoiceGenerator (D-C), the production HTTP loop + parser paths finally exercise end-to-end through the same fixture-mode hermetic shell as T14 (S-MILEAGE-2A).
- **T8 (pagination cap per D120):** synthesize a precharge-enabled lease + invoice generation cycle invoking Samsara fixture in a deliberately oversized fictional date range that triggers the 90-day cap → assert `reason='period_too_long'` surfaces into InvoiceGenerator's failure path AND the invoice form's operator-facing error. Replaces the 2A-era source-inspection placeholder at T8.
- **T10 (malformed response):** use FIX_NONE fixture path OR a new FIX_MALFORMED fixture (D-C surfaces gap if needed) — assert structured failure returns `reason='api_error'` AND the invoice form re-prompts for manual distance entry. Replaces the 2A-era source-inspection placeholder at T10.
- **T12 already exercised by T16 source-inspection in 2A;** T12 either retires (consolidated into T8/T10) or is repurposed as a new dispatch case — confirm at edit time. Smoke count delta: 16 → 16-17 (T8/T10 become real; T12 may retire or convert).
- Test shape: BEGIN/ROLLBACK isolation, FIX_STD baseline + targeted fixture variants, assert `getDistanceForPeriod` return shape AND InvoiceGenerator downstream behavior.

**D-Q — Bug 1 + Bug 4 rider scope — OPERATOR DECISION REQUIRED on rider wording**
- **Bug 1 (Cumulative Total label):** per the Master Plan (PROGRESS.md §Model B Mileage Refactor § Out-of-scope-but-tracked items) — "Cumulative Total" label on invoice show page is misleading (should read "Lease-to-Date Distance" or similar). Site: `app/admin/invoices/show.php:1521` (the `<dt class="text-secondary">Cumulative Total</dt>` row in the Odometer & Distance card). Single-string edit. Per K-13 / project memory: also confirm any other surface using the same label (lease show page, customer portal, search results, equipment unit profile) is consistent — repo-wide grep for the exact string `"Cumulative Total"` at edit time.
- **Bug 4 (backdate warning):** soft validation when `billing_period_start < lease.start_date` on the invoice creation form. Site: `app/admin/invoices/create.php` invoice form. New Alpine soft-validation banner (`x-show="form.billing_period_start && lease.start_date && form.billing_period_start < lease.start_date"`) — does NOT block submit, just warns. May extend to `app/admin/invoices/edit.php` if it exists post-D14 review.
- **OPERATOR DECISION REQUIRED:** exact wording for both:
  - Bug 1 label: "Lease-to-Date Distance" (recommended — matches the "since lease start on YYYY-MM-DD" subtitle at show.php:1525) OR "Lease Total Distance" OR "Distance since lease start"?
  - Bug 4 warning: "Invoice period starts before lease start date — confirm intent" (recommended — soft warning, non-blocking) OR stronger wording requiring acknowledge checkbox?
- Riders kept narrow per the Master Plan framing — Bug 1 is a 1-line label edit + 1-2 grep confirmations; Bug 4 is a ~10-line UI addition. Combined scope budget: ~15 min. If Bug 4 wording demands more substantial work (acknowledge checkbox + new audit_log entry for backdated invoices) it expands per D130 scope-discipline.

**D-R — Schema migration count + ordering**
- **Migration 1: `YYYYMMDDHHMM_S-MILEAGE-2B_drawdown_line_items.sql`** (D-A) — ENUM addition. Pure additive; no backup table per D128. Lands in C2.
- **Migration 2 (conditional on D-G option (i)): `YYYYMMDDHHMM_S-MILEAGE-2B_model_c_retirement.sql`** — DROP 3+3 columns on `invoices` + backup table per D107 + retire `mileage_review_status` ENUM type (or its containing column). Idempotent ALTER via `ff_drop_column_if_present` helper. Backup INSERT BEFORE DROP per D126; column DROPs at the end. Lands in C4.
- **Migration 3 (conditional on D-C surface): `YYYYMMDDHHMM_S-MILEAGE-2B_samsara_invoice_metadata.sql`** — IF new fields (`samsara_first_reading_at` / `samsara_last_reading_at` / `samsara_warnings_json`) land on `invoices` per D-K rendering needs. Append-column-at-end pattern per D126. Lands in C3 (alongside InvoiceGenerator integration).
- Expected total: 2–3 migrations. `FLEETFORGE_DATABASE_MASTER.sql` updates land in the same commit as each migration per D87. D127 parity smoke + D131 invariants gate run on every commit C2-C10.
- Migration runner state post-arc: 11 → 13 or 14 applied migrations.

**D-S — Single PR vs multi-commit arc breakdown**
- Proposed 10-commit sequence (mirrors S-MILEAGE-2A's 7-commit shape, scaled for 2B's surface):
  - **C1:** IN-FLIGHT registration standalone per S-D136-COMMIT-DISCIPLINE (lock domains: lib/Billing/, api/v1/invoices/, app/admin/invoices/, tests/, db_migrations/, FLEETFORGE_DATABASE_MASTER.sql, FLEETFORGE_SPEC_FINAL.md, FLEETFORGE_CLAUDE_CODE_REFERENCE.md, FLEETFORGE_PROGRESS.md, FLEETFORGE_CURRENT_SESSIONS.md). Includes pre-work scan G (TaxCalculator behavior on negative line amounts) + re-confirm scans A-F.
  - **C2:** ENUM migration (D-A) + show.php dispatch additions (D-E) + DATABASE_MASTER.sql sync + ENUM stress test. PARITY OK confirms ENUM lands cleanly.
  - **C3:** InvoiceGenerator drawdown emit (D-B) + SamsaraClient::getDistanceForPeriod integration (D-C) + optional samsara_invoice_metadata migration (D-R Migration 3) + new engine markers replacing 2A's forward-looking block + stress test `tests/_stress_invoice_generator_drawdown.php` (6-8 cases mirroring 2A C3 stress shape).
  - **C4:** Model C plumbing retirement per D-G option (i) — backup table + column DROPs + ENUM retirement + Mileage helper retirement per D-H + `scripts/seed_dataset.php` / `bin/seed.php` cleanup. Stress test confirming retire surface. PARITY OK confirms DROP lands cleanly.
  - **C5:** HARD send gate retirement per D-I + review_mileage.php disposition (option (i) recommended) + INV-87 disposition (void + regen, recommended) + Mileage Review card disposition per D-L. Stress test confirming send.php no longer rejects on drawdown invoices.
  - **C6:** Odometer card rewrite (D-K) + Financial Summary drawdown breakdown (D-F) + tax-on-NET verification (D-D) + tax stress test `tests/_stress_tax_calculator_drawdown_net.php`. Visual surface — operator T1 walk includes these. FF_ASSET_VERSION bump consideration (CSS / JS changes likely) per D145.
  - **C7:** Riders Bug 1 + Bug 4 per D-Q (operator-confirmed wording).
  - **C8:** Smoke I8 (D-O) + real T8/T10 coverage activation (D-P) + stress `tests/_stress_smoke_invariants_i8.php`. Smoke summary I1-I8 across `_smoke_billing_invariants.php`; samsara_distance smoke gains real T8+T10 coverage.
  - **C9:** Spec rewrite (D-M option (i) recommended) — `FLEETFORGE_SPEC_FINAL.md` Model A → Model B section overhaul.
  - **C10:** Docs — SESSION LOG row + DECISIONS table D147-D165 (D-A through D-S of 2B; ~19 rows) + REFERENCE.md §13.4 retirement (replace "Model C is current-and-transitional" subsection with "Model B is current — Model C historical reference") + §13.4.1 lifecycle table closing (4 of 6 columns owned by SHIPPED arcs; 2 remaining for S-MILEAGE-3) + CURRENT_SESSIONS.md S-MILEAGE-2B entry flipped to SHIPPED one-line + Recent ship history extension + T1 visual walk addendum on operator sign-off.
- **T1 visual walk** extends D146's 5-step walk pattern. New steps (post the 5-step 2A walk):
  - (6) Generate Invoice 2 on the same precharge-enabled lease post-Invoice-1-send → confirm both `mileage_usage` + `mileage_drawdown_credit` lines emit at expected amounts in the line items table.
  - (7) Query `leases.precharge_balance` post-Invoice-2-generation → confirm decrement = min(usage, prior_balance) per D-B math.
  - (8) Confirm Financial Summary renders the breakdown block (usage / credit / net) per D-F + tax computes on NET per D-D.
  - (9) Generate Invoice 3 after `precharge_balance` hits zero → confirm only `mileage_usage` line emits, no `mileage_drawdown_credit`, `precharge_balance` remains at 0.
  - (10) Confirm INV-87 dispositioned per D-I (voided + regenerated, OR auto-approved per operator's pick).
  - (11) Test Model B Lite path: synthesize a precharge_enabled=0 lease with mileage_rate>0 + period_distance>0 → confirm `mileage_usage` line emits (no credit; no balance update).
- D131 gate (PARITY OK + INVARIANTS OK including new I8 + migrate 11+N/0/0) on every commit C2-C10.

#### Implementation notes
- **Engine-side markers:** the 2A C3 marker block at `lib/Billing/InvoiceGenerator.php:217-245` is REPLACED in C3 of 2B (not preserved). Its purpose was forward-looking — 2B implements the contract it described. New marker block at the same site documents the post-2B engine state + the S-MILEAGE-3 close-refund integration point.
- **Display-side markers (show.php):** PHP marker at 1769-1782 + HTML marker at 2182 STAY but rewrite to refer to the now-shipped Model B contract (strike "S-MILEAGE-2A/2B" forward-looking text). Dispatch contract block at 1866-1906 updated per D-E.
- **Audit log entity_types:** new `entity_type='lease_precharge_balance_drawdown'` per D-B; reuse `entity_type='samsara_history_query'` per D102/D123. `action='cron'` ENUM workaround per D102. Continue using `action='update'` with descriptive `entity_type` for non-`cron` events.
- **bcmath discipline (D16):** all drawdown math operands are strings; results bcround to 2 decimals at insert; comparisons via `bccomp`. No float operators on dollar or distance amounts. Distance retains 2dp via `bcdiv(meters, '1000', 2)` per D118.
- **Optimistic-lock retry (D19):** drawdown UPDATE on `leases.precharge_balance` runs under FOR UPDATE (D20) within the InvoiceGenerator transaction — serializes correctly. If a concurrent cron + manual gen contention surfaces, STALE_DATA 409 fires per existing pattern; client retries the invoice generation. Initial implementation: simple FOR UPDATE without explicit optimistic-lock retry inside the engine.
- **K-15 data-path coverage (per S-REVIEW-MILEAGE-TAX-FIX 2026-05-12):** before any D-G destructive op, the pre-work scan re-runs the Model C column SELECT — verifies count unchanged from 3 (or surfaces diff). Identifies all rows that would lose data on DROP. Operator confirms backup-table snapshot captures them.
- **K-16 mid-arc chat handoff discipline:** spec authored 2026-05-11/12 (this session) before main 2B session. If the operator surfaces a clarification mid-2B that contradicts a D-A through D-S lock here, the K-16 pattern applies: tighten the spec's literal wording where the spec didn't address an edge case, surface in SESSION LOG with original-scope-vs-final-scope diff per D130 scope-expansion/contraction discipline.
- **D131 gate discipline:** schema-touching session — both `tests/_smoke_master_schema_parity.php` (PARITY OK) AND `tests/_smoke_billing_invariants.php` (INVARIANTS OK including new I8) must pass before every commit C2-C10. The samsara_distance smoke also runs (16/16 → 16-17/16-17).
- **Concurrent IN-FLIGHT detection (D136):** pre-flight reads CURRENT_SESSIONS.md; collision check on `lib/Billing/InvoiceGenerator.php`, `lib/Billing/Mileage.php`, `lib/Billing/TaxCalculator.php`, `lib/Accounting/AutoEntryBridge.php`, `api/v1/invoices/send.php`, `api/v1/invoices/review_mileage.php`, `api/v1/invoices/create.php`, `app/admin/invoices/show.php`, `app/admin/invoices/create.php`, `tests/_smoke_billing_invariants.php`, `tests/_smoke_samsara_distance.php`, `db_migrations/`, `FLEETFORGE_DATABASE_MASTER.sql`, `FLEETFORGE_SPEC_FINAL.md`, `FLEETFORGE_PROGRESS.md`, `FLEETFORGE_CURRENT_SESSIONS.md`, `FLEETFORGE_CLAUDE_CODE_REFERENCE.md`.

#### Riders (in scope for 2B; small surface — see D-Q)
- **Bug 1 — Cumulative Total label** at `app/admin/invoices/show.php:1521`: replace "Cumulative Total" with operator-confirmed wording (recommended: "Lease-to-Date Distance"). Repo-wide grep for the exact string confirms no other surface uses the same misleading label. Lands in C7.
- **Bug 4 — Backdate warning** at `app/admin/invoices/create.php`: Alpine soft-validation banner when `form.billing_period_start < lease.start_date`. Does NOT block submit. Operator-confirmed wording (recommended: "Invoice period starts before lease start date — confirm intent"). Lands in C7.

#### Pre-work scan items for main 2B session (re-run at C1 for currency)
A. K-15 Model C column data — re-run SELECT on excess_distance_km / excess_charge_amount / mileage_review_status against `invoices`; confirm count unchanged from 3 (or surface diff). Run the same for `invoice_line_items.item_type IN ('mileage_adjustment','mileage_credit')`; confirm count unchanged from 2.
B. lease_billing_periods writers — re-grep `db_insert.*lease_billing_periods` across the repo; confirm only InvoiceGenerator.php:883 + demo_wipe.php:36. If new writer surfaces, surface to operator.
C. review_mileage.php pending — re-run SELECT on `mileage_review_status='pending'`; confirm count = 1 (INV-87) or surface diff.
D. priorExcessKm safeguard — confirm 24+ references at known line numbers in close.php still present.
E. Engine markers — re-grep "S-MILEAGE-2B inserts drawdown logic" — expect 1 in InvoiceGenerator.php (2A C3 plant); show.php markers per S-INVOICE-DISPLAY-COMPREHENSIVE C3 still in place.
F. Post-2A canonical state — D137-D146 present; I7 in smoke summary; T14/T15/T16 in samsara_distance smoke; REFERENCE.md §13.4.1 lifecycle table owners ✓ S-MILEAGE-2A SHIPPED.
G. **NEW for 2B**: TaxCalculator behavior on negative line amounts — read `lib/Billing/TaxCalculator.php` end-to-end to determine D-D scope expansion potential. Surface to operator before C3.

#### NOT TOUCHED in S-MILEAGE-2B
- `api/v1/leases/close.php` priorExcessKm safeguard (S-MILEAGE-3 owns retirement per D-J)
- `lease_close_adjustments` table (S-MILEAGE-3 owns retirement)
- `precharge_refund_method` + `precharge_refund_settled_at` columns on leases (S-MILEAGE-3 owns)
- `lib/Billing/InvoiceGenerator::createFromLease` precharge emit block at lines 246-280 (shipped in 2A C3; lifecycle gate stays load-bearing — 2B's drawdown ADDS-not-REPLACES)
- `api/v1/invoices/send.php` precharge_invoiced_at stamp + PRECHARGE_ALREADY_BILLED 409 at lines 70-150 + 179-212 (shipped in 2A C4; HARD send gate at lines 50-63 retires but stamp + 409 stay)
- `tests/_stress_activate_precharge_init.php` + `tests/_stress_invoice_generator_precharge.php` + `tests/_stress_send_precharge_stamp.php` + `tests/_stress_smoke_invariants_i7.php` (2A regression sentinels — stay in test suite)
- `lib/Billing/Mileage::leaseDurationMonths`, `Mileage::toDisplayUnit`, `Mileage::formatDistance` (Model B retains these)
- LBP table (load-bearing per pre-work scan B; out of 2B retirement scope)
- D131 / D132 / D135 / D136 / D137-D146 lock semantics (all stay in force; 2B extends D131 with I8 + extends D132 framing — Model C tier retires, drawdown becomes the active tier)
- `api/v1/leases/create.php` + `update.php` precharge validation (shipped in S-MILEAGE-1; immutability + whitelist per D109/D113 preserved)
- `app/admin/leases/{create,edit,show}.php` precharge form fields (shipped in S-MILEAGE-1; no UI change in 2B unless rider D-Q expands)
- `FLEETFORGE_ACCOUNTING_SPEC.md` (likely DEFERRED to S-MILEAGE-3 + accountant per D-N; operator confirms at C1)

---

**S-MILEAGE-3** — QUEUED
Scope: close + cash/credit refund toggle + retire priorExcessKm transitional safeguard.
Effort: ~90 min.
Dependencies: S-MILEAGE-2B shipped.

**S-MILEAGE-5** — QUEUED
Scope: 20 hermetic scenario tests against Model B.
Effort: ~1.5-2 hrs.
Dependencies: S-MILEAGE-3 shipped.

(S-MILEAGE-4 placeholder may dissolve into 2B/3 — remove from queue when confirmed.)

### Architectural follow-ups (lower priority but real)

**S-TEMPLATE-MILEAGE-DEFAULTS** — QUEUED
Scope: backfill default_mileage_rate on 4 NULL production templates (1, 3, 4, 5) using seed_rate_cards.php Standard 2025 rubric ($0.18 dry van, $0.17 flatbed, $0.15 container, $0.13 chassis); decide policy on 9 zero-default seed templates (legitimate non-billable vs latent bug); migrate equipment_templates.default_mileage_rate to NOT NULL DEFAULT 0 once values populated.
Effort: ~45-60 min.
Dependencies: none.
Discussed: 2026-05-06 in planning chat (Desktop research surfaced).

**S-LEASE-RATE-AMENDMENT** — QUEUED
Scope: full rate amendment workflow with audit + amendment record + UPDATE statement on lease rate columns. Closes the "rate fields immutable, amendment workflow not implemented" gap noted in api/v1/leases/update.php docblock (line 32-33).
Effort: ~90-120 min.
Dependencies: 4 unanswered design questions (retroactive vs prospective amendments; when amendments allowed; approval/signature workflow; automatic credit-note + reissue for affected sent invoices per D14).
Status: BLOCKED on operator design decisions; likely scope after accountant conversation.

**S-LEASE-21-CLEANUP** — QUEUED
Scope: investigate lease 21 (CN-7B5Z5B-2026, completed 2026-04-09 same-day-closed in 3 minutes) — verify whether real customer activity or seed/test data; decide replacement invoicing (after S-MILEAGE-RATE-VALIDATION C3 voided INV-27 + INV-84) or accept-as-test. Closes KNOWN ISSUES A2 + A4.
Effort: ~30-45 min.
Dependencies: none.

**S-REEFER-RATE-AUDIT** — QUEUED (low priority)
Scope: investigate rubric drift — scripts/seed_rate_cards.php says reefer = $0.22/km but live template default + customer rates say $0.18/km. Decide canonical and align.
Effort: ~30 min.
Dependencies: none.

**S-SEED-RATE-CARDS-LOAD** — OPEN QUESTION
Scope: should the 11-card "Standard 2025" rubric defined in scripts/seed_rate_cards.php be loaded as is_default=1, or are customer-specific rate cards the entire intended model? Operator decision.
Effort: TBD.
Dependencies: operator answer.

**S-EQUIPMENT-SHOW-RESPONSIVE** — SHIPPED 2026-05-05 (commits 110e6dd + 2294f09 — see PROGRESS.md SESSION LOG line 126)
Outcome: Responsive layout fixes for app/admin/equipment/show.php at 768px and 375px breakpoints. D-A through D-J locked + T1-T8 results + KEY LEARNINGS already inline in SESSION LOG entry at PROGRESS.md line 126. CURRENT_SESSIONS status was stale (still listed as QUEUED until S-DOC-STATUS-RECONCILE-CLOSE flipped it 2026-05-07) — gap surfaced by S-QUEUE-STATUS-RECONCILE diagnostic.

**S-DOC-FRESHNESS-DISCIPLINE** — QUEUED
Scope: automated staleness detection across the 6 canonical docs; D131-style discipline gate. Closes the missing tests/_smoke_doc_freshness.php that was referenced as a D131 gate but never built.
Effort: ~45-60 min.
Dependencies: none.

**S-MILEAGE-1B-FOLLOWUP** — SHIPPED 2026-05-06 (commit b886d1c — see PROGRESS.md SESSION LOG)
Outcome: T13 fixture coverage for FIX_GAP scenario (large_gap_detected warning) — closed audit-pass PARTIAL on S-MILEAGE-1B Item 10. T13 added to tests/_smoke_samsara_distance.php asserting distance=='2300.00' / source=='gps' / warnings includes 'large_gap_detected'. Counts updated (12 → 13) across smoke docblock + banner + REFERENCE.md §13.6. SESSION LOG row backfilled in S-DOC-STATUS-RECONCILE-CLOSE (gap surfaced by S-QUEUE-STATUS-RECONCILE diagnostic).

**S-LOOKUP-RATES-PRODUCTION-INVARIANT** — QUEUED (optional)
Scope: optional preventive smoke invariant from S-LOOKUP-RATES-NAMESPACE C3 (deferred). For every production template with active leases, the customer-equipment-rate AND rate-card-item lookup must yield non-NULL OR template default must be non-NULL.
Effort: ~20 min.
Dependencies: may be rendered redundant by S-TEMPLATE-MILEAGE-DEFAULTS' NOT NULL schema change. Skip if redundant.

### Strategic / multi-session arcs (planning phase)

**QuickBooks integration** — PLANNING
Scope: bidirectional sync with QBO as source of truth for customers + payments, FleetForge as source for invoices. Implies deprecating most of FleetForge accounting module (S028-S037). Estimated 6-7 sessions: S-QBO-1 discovery, S-QBO-2 customer sync, S-QBO-3 invoice export, S-QBO-4 payment import, S-QBO-5 reconciliation tooling, S-QBO-6 accounting module deprecation, S-QBO-7 cutover.
Status: planning will happen in a separate claude.ai web chat with FLEETFORGE_QUICKBOOKS_SPEC.md as the dedicated canonical doc. Not yet started.
Dependencies: defer until after Model B refactor (S-MILEAGE-2A/2B/3) and accountant conversation.

**AWS Lightsail cutover** — QUEUED (multi-session)
Scope: production deployment to AWS Lightsail Oregon. Currently deferred until feature-complete per D8.
Status: not yet feature-complete.
Pre-deploy obligations: see `FLEETFORGE_PREDEPLOY_CHECKLIST.md` — categories C (DNS), D (AWS infra D1-D9 — Lightsail provision, snapshots, S3 bucket + versioning, mysqldump cron, SES sandbox exit, SNS topic, IAM, CloudWatch alarms), G (smoke G1-G4), H (rollback H1-H2), I (monitoring I1-I3) all flow through this multi-session. Each ITEM there carries Originating session + Action + Owner; flip Status → ✅ COMPLETE in the checklist as the cutover progresses. Discipline locked as K-14 (pre-deploy obligations file separate from KNOWN ISSUES and CURRENT_SESSIONS) — see PROGRESS.md KEY LEARNINGS.

**S-PROD-3** — QUEUED
Scope: self-host CDN deps (Google Fonts, ApexCharts, Leaflet — items #79-81 from prod prep audit).
Effort: TBD.

---

## Recent ship history (rolling — older entries archived to PROGRESS.md)

**2026-05-12:**
- S-MILEAGE-2A SHIPPED (b602bd9 IN-FLIGHT registration + 253b294 C2 activate.php precharge_balance init + e1918df C3 InvoiceGenerator mileage_precharge emit + (b) cross-invoice uniqueness gate + engine markers + c8e459a C4 send.php precharge_invoiced_at stamp + PRECHARGE_ALREADY_BILLED 409 + 365d541 C5 T14/T15/T16 smoke ADD + b16c0fc C6 I7 D132-precharge-extension invariant + this docs commit) — 7-commit arc closing Invoice 1 Model B lifecycle end-to-end. C2 lands activation transaction extension (FOR UPDATE on lease per D20 + idempotent UPDATE under WHERE-clause guard + dedicated audit_log entity_type='lease_precharge_balance_init'); 6/6 stress PASS. C3 lands InvoiceGenerator emission with 3-clause gate (lifecycle + (b) cross-invoice uniqueness + billing_type exclusion) + 67-line engine marker block carrying forward-looking 2B drawdown contract (greppable via "S-MILEAGE-2B inserts drawdown logic"); 6/6 stress PASS. C4 lands send.php dispatch (NULL → stamp NOW(), NOT NULL → 409 PRECHARGE_ALREADY_BILLED with locked message wording) under FOR UPDATE on lease + dedicated audit_log entity_type='lease_precharge_invoiced_at_stamp'; PRECHARGE_LOCKED 409 D113 activates for free off the stamp; WHY-comment block at 409 site documents C3 (b) gate ↔ C4 409 paired defense-in-depth (defends against racy concurrent gen, manual UI/API line insertion, future regression weakening C3 gate); 5/5 stress PASS. C5 ADDs T14/T15/T16 to tests/_smoke_samsara_distance.php (preserving T8/T10/T12 placeholders as 2B carry-forward per REFERENCE.md §13.6 framing — operator overrode spec's REPLACE wording to ADD); smoke 13/13 → 16/16 PASS. C6 lands I7 invariant (D132 extension into precharge tier — precharge_enabled=1 must have precharge_amount > 0) + stress test using CREATE TEMPORARY TABLE clone trick (column types copied without CHECK constraints, allowing fault-injection of violation rows); INVARIANTS OK I1 → I7 (6/6 stress PASS). 10 D-decisions locked D137-D146 (D-A through D-J). Mid-session clarifications: D-D 409 status code (409 vs 422 for state-conflict family with D113/D19), D-B (b) uniqueness gate (advance-batch multi-draft gap), D-E ADD framing (preserve 2B placeholders), D-G I7 scope (D132 extension vs activation-init check — operator pick). FLEETFORGE_SPEC_FINAL.md Model A→Model B rewrite deferred to S-MILEAGE-2B per D144; no FF_ASSET_VERSION bump per D145. D131 gate clean on every commit: PARITY OK + INVARIANTS OK + migrate 11/0/0. **T1 visual sign-off (5-step walk per D146): ✓ PASS — operator-confirmed 2026-05-12.** Activate fresh lease → confirmed precharge_balance init in DB → Invoice 1 carries mileage_precharge line → send invoice + confirmed stamp + balance unchanged → re-edit lease → PRECHARGE_LOCKED 409 fires.
- S-MILEAGE-2A-SPEC-WRITE SHIPPED (86353d4 IN-FLIGHT + 17b2776 C2 spec + 3a9351c C3 Master Plan + K-16 + this docs commit) — docs-only 4-commit arc closing the K-16 mid-arc chat handoff requirement before operator closes a long claude.ai web chat that scoped the Model B refactor across multiple sessions. C1 IN-FLIGHT registered standalone per S-D136-COMMIT-DISCIPLINE (pushed to main immediately). C2 wrote full S-MILEAGE-2A spec into CURRENT_SESSIONS.md (replacing the prior short QUEUED entry of 4 lines with a full spec block: SCOPE / OUT OF SCOPE / HYBRID STATE / LINE ITEM SHAPE / DISPATCH CONTRACT NOTE / INTEGRATION TESTS / D132 EXTENSION / ARCHITECTURAL NOTES). C3 inserted `## Model B Mileage Refactor — Multi-Session Master Plan` section into PROGRESS.md between KNOWN ISSUES and NEXT SESSION STARTS WITH (forward-looking-plans area) + locked K-16 (Mid-arc chat handoff discipline) in KEY LEARNINGS table between K-15 and K-17 per K-17's K-number convention. C4 (this commit) ships out of queue. **Pre-work scan surfaced 4 factual discrepancies between operator's narrative and actual code state, operator authorized path B (correct + transparent SESSION LOG note):** D-1 dual S-MILEAGE-2A/2B extension point markers are in `app/admin/invoices/show.php` (PHP at 1769-1782, HTML at 2182), NOT in `lib/Billing/InvoiceGenerator.php` — verified via `git show 5dc2af2 --stat` (C3 of S-INVOICE-DISPLAY-COMPREHENSIVE touched only show.php); D-2 `mileage_precharge` ENUM already exists at FLEETFORGE_DATABASE_MASTER.sql:1898 (Model A vestigial retained for Model B per S-MILEAGE-MODEL-AUDIT 2026-05-04 — no migration needed); D-3 dispatch case already at show.php:1891 ('badge-info', placed by C3 of S-INVOICE-DISPLAY-COMPREHENSIVE as forward-looking display contract); D-4 supported/pending types lists corrected to match code (14 currently-supported values per show.php:1879-1881 + DB ENUM; pending: mileage_drawdown_credit + mileage_usage only — mileage_precharge is supported but not emitted). Net effect: S-MILEAGE-2A scope shrinks (schema + dispatch pre-wired) but session shape unchanged — engine emit + balance init + precharge_invoiced_at write + InvoiceGenerator markers + D132 invariant I7 + T8/T10/T12 tests. **D131 gate not runnable from this worktree** — no `.env` (gitignored in main repo); smoke tests fail with "Access denied" — worktree environment limitation surfaced to operator before C2; docs-only session has no code/schema/migration changes so D131 gate would not catch anything specific to this session. K-16 locked.
- S-FORK-CLOSE-RESOLVE SHIPPED (d0a0947 IN-FLIGHT registration + ab122eb merge commit + 834adc2 Phase 2.1 gitignore + c441aa6 Phase 2.2 archive + this docs commit) — 5-commit reconciliation arc. Phase 1 rebase aborted at step 2/11 due to journal-file conflicts (CURRENT_SESSIONS.md + PROGRESS.md SESSION LOG); operator switched to Option B merge commit. Merge `ab122eb` brings 11 worktree-branch commits (S-DOCS-CLUSTER + S-INVOICE-DISPLAY-COMPREHENSIVE + S-PREDEPLOY-CHECKLIST-CREATE arcs) into main alongside the 4 main-side commits (S-REVIEW-MILEAGE-TAX-FIX arc); 4 conflict hunks resolved per sign-off (IN-FLIGHT keep HEAD, Recent ship history descending UTC within date, SESSION LOG ascending UTC, KEY LEARNINGS K-14 before K-15 by K-number convention with chronology-inversion note inline). Phase 2.1 (834adc2): gitignored operator's `FleetForge Prompt Review.md` (file kept in working tree, stops tracking surface area). Phase 2.2 (c441aa6): created `scripts/archive/` with README + moved S-ACCT-FIX-A1 abandoned `fix_ar_drift_2026_05_07.php` into it. K-17 locked (branch divergence reconciliation discipline — merge commit, not rebase, when journal files are in scope). Pre-rebase backup tag `pre-rebase-S-FORK-CLOSE-RESOLVE-backup` preserved at origin for 30 days. D131 gate clean post-merge: PARITY OK + INVARIANTS OK (I1-I6 all PASS) + migrate 11/0/0. Worktree + local + remote `claude/kind-sanderson-4ed75d` branches deleted; main is the only branch.
- S-FORK-CLOSE SHIPPED (read-only diagnostic — no commits) — 7-step clean-state verification: branch state (surfaced divergence), worktree merges (HALT per operator directive), working tree (2 untracked files surfaced — Prompt Review + fix_ar_drift), CURRENT_SESSIONS.md state (zero IN-FLIGHT both branches; neither branch had all 4 day's sessions in Recent ship history — the documentation-layer visualization of the divergence), D131 gate on main (PASS — confirmed main internally clean despite doc divergence), GitHub sync (matched + 1 expected orphan branch), K-14 discipline check (PASS — A1 captured; S-REVIEW-MILEAGE-TAX-FIX had no missed pre-deploy obligations). Verdict: "FORK NOT CLOSED — 3 issues" → resolved in S-FORK-CLOSE-RESOLVE.
- S-PREDEPLOY-CHECKLIST-CREATE SHIPPED (commits 7d5e62d IN-FLIGHT registration + 2c5d146 file creation + C3 cross-refs commit) — created `FLEETFORGE_PREDEPLOY_CHECKLIST.md` as canonical pre-deploy operations file. 21 backfilled obligations across 9 categories (operator-framed planning count; 26 in final file after D + G expansion: A1 asset cache, B1-B5 prod .env keys, C1 DNS, D1-D9 AWS infra, E empty placeholder, F1 AR drift DEFERRED-TO-QBO, G1-G4 smoke, H1-H2 rollback, I1-I3 monitoring + J references). Each item carries Originating session + Surfaced into checklist sub-fields + "Original source:" pointer + Action + Owner + Status. K-14 locked (pre-deploy obligations category separation discipline, K-12 extension): bug-shaped → KNOWN ISSUES (in PROGRESS.md), session-shaped → CURRENT_SESSIONS, pre-deploy → PREDEPLOY_CHECKLIST. Cross-refs added to REFERENCE.md §0 LOCKED DECISIONS K-14 row + §1 SEVEN FILES "Deployment-time companion" note; CURRENT_SESSIONS.md Pre-flight check section extended with category-separation note + AWS Lightsail cutover entry extended with pointer. D131 gate: PARITY OK + INVARIANTS OK (I1-I6 all PASS).
- S-REVIEW-MILEAGE-TAX-FIX SHIPPED (3582e78 + 79934a0 + b1adbcd + docs commit) — 4-commit arc closing the dormant tax-divide-by-100 bug at api/v1/invoices/review_mileage.php:213-218 surfaced in S-MILEAGE-ALLOWANCE-ZERO-FIX C5. C1 standalone IN-FLIGHT registration (per S-D136-COMMIT-DISCIPLINE forecast — first session to commit IN-FLIGHT before any other edit). C2 fix + stress test (replaces bcdiv-by-100 with canonical bcround-bcmul pattern matching TaxCalculator.php:62; new tests/_stress_review_mileage_tax.php 14/14 PASS post-fix). C3 audited UPDATE for INV-2026-00092 line 175 (the one production row with bug applied — surfaced by K-15 data-path coverage scan; +$90.08 HST correction). C4 docs + K-15 lock. D131 clean throughout.

**2026-05-11:**
- S-INVOICE-DISPLAY-COMPREHENSIVE SHIPPED (commits 8bc4e5c + aabb087 + 5dc2af2 + df1bd20 + f9130a2) — comprehensive invoice show.php with $0-line draft hide (D-A), per-line + per-tax-row tax base reconciliation in "RATE% on $BASE" inline format with "Applied to N taxable lines totaling $X" tooltip (D-B), Credit Applications card between Line Items and Financial Summary rendering `credit_note_applications` JOIN (D-C, D-G), step-by-step balance due breakdown with dual S-MILEAGE-2A/2B extension markers (D-D), print-parity preserved (D-E), 3 reference invoices verified (INV-19/INV-13/INV-21) (D-F). Incidental fixes per D130: pre-existing tax-rate display bug (0.05% → 5% via ×100 conversion) corrected in C3; print overlay regression on browser Cmd+P fixed in C3-T1-FIX by extending app.css @media print hide list with `.search-modal`+overlay siblings (universal — applies to every admin print). K-13 locked. FF_ASSET_VERSION bumped 1.0.25 → 1.0.26 (uncommitted, .env gitignored).
- S-DOCS-CLUSTER-2026-05-11 SHIPPED — bundled docs commit closing three small queued sessions in one shape: S-D136-COMMIT-DISCIPLINE + S-D135-REFERENCE-PROMOTE + S-D130-EXTENSION. Each was single-commit / ≤20 min / touched REFERENCE.md; bundling avoided ceremony tax. Also exercised S-D136-COMMIT-DISCIPLINE's own discipline — IN-FLIGHT registration committed to main standalone (bd8dbb6) BEFORE first content edit. D-A: D136 wording extension in REFERENCE.md §0 (Registration commit requirement clause). D-B: D135 promotion (§0 index row + §13.8 subsection heading labeled with D135 + See-also extension). D-C: D130 contraction extension (PROGRESS.md row extended + new REFERENCE.md §0 row carrying combined expansion+contraction wording). D-D: D136 backfill into PROGRESS.md DECISIONS table. D131 gate: PARITY OK + INVARIANTS OK (I1-I6 all PASS).

**2026-05-07:**
- S-DOC-STATUS-RECONCILE-CLOSE SHIPPED (15865c4, fast-forward merged from session/S-DOC-STATUS-RECONCILE-CLOSE-20260507-0740 branch per D136 isolation fallback) — closes 4 documentation gaps surfaced by S-QUEUE-STATUS-RECONCILE diagnostic (3 SESSION LOG backfills + 3 CURRENT_SESSIONS status flips). Locks K-12 (documentation divergence as bug class). Extends §Active session format with `(DB writes)` annotation convention per S-ACCT-FIX-A1's organic precedent. **Wording correction (S-DOC-STATUS-RECONCILE-CLOSE-FIXUP)**: original entry said "S-ACCT-FIX-A1 zombie IN-FLIGHT entry on main" — empirically incorrect, the IN-FLIGHT registration was carried over from the parallel agent's working tree but never committed to main. Carryover block was removed in the FIXUP commit. See S-DOC-STATUS-RECONCILE-CLOSE-FIXUP SESSION LOG row + S-ACCT-FIX-A1 abandonment row.
- S-DOC-STATUS-RECONCILE-CLOSE-FIXUP SHIPPED (this commit) — corrective fixup post-merge of S-DOC-STATUS-RECONCILE-CLOSE. Removed S-ACCT-FIX-A1 carryover IN-FLIGHT block from CURRENT_SESSIONS.md, corrected misleading "zombie on main" wording in 2 places (this Recent ship history entry + S-DOC-STATUS-RECONCILE-CLOSE SESSION LOG row in PROGRESS.md), added S-ACCT-FIX-A1 abandonment SESSION LOG row, queued S-D136-COMMIT-DISCIPLINE for the working-tree-vs-committed-state discipline gap surfaced by this incident.
- S-QUEUE-STATUS-RECONCILE SHIPPED (no commit — read-only diagnostic, IN-FLIGHT-RO under D136) — first post-D136 IN-FLIGHT-RO session. Cross-referenced 32 session labels; surfaced 4 doc-divergence gaps (closed in S-DOC-STATUS-RECONCILE-CLOSE) + bonus finding: parallel agent S-ACCT-FIX-A1 IN-FLIGHT registration mid-diagnostic verified D136 collision-check works correctly + organic Touching format extension `(DB writes)` annotation.
- S-MULTI-AGENT-DISCIPLINE-IMPL SHIPPED (f195bee) — locks D136 (multi-agent discipline: hybrid single-agent serialization + branch isolation fallback) + K-11 (lock-discipline-before-frequency learning). Upgrades CURRENT_SESSIONS.md status schema with IN-FLIGHT / IN-FLIGHT-RO entries, multi-line entry format, and Pre-flight check section. Backfills S-ACCT-AUDIT SESSION LOG row to close the dangling reference in D136. D135 §0 promotion deferred to separate session (S-D135-REFERENCE-PROMOTE queued). Discipline takes effect immediately for next session.
- S-LEASE-GPS-COST SHIPPED (71c3e5c + 21c7c58 + 05266e7 + C4) — adds per-lease GPS tracking add-on (gps_opt_in tinyint default 1 + gps_cost decimal default 1.00). Per-day billing rhythm: amount = gps_cost × billing_days. Engine emits 'gps' line item (ENUM extended) when opt_in=1 AND cost>0. Existing leases backfilled to opt_in=1 / cost=$1.00 per Option (i) — auto-bill on next cycle. Stress test 4/4 PASS. D-A through D-G locked.
- S-MILEAGE-ALLOWANCE-ZERO-FIX SHIPPED (2168bd5 + 764abf1 + ef050e7 + C4) — engine-side fix for the silent-skip class on Model B Lite leases (rate>0 + allowance=0). Closed KNOWN ISSUE #103. D135 locked. Multi-agent reconciliation: parallel agent's commit ef050e7 combined C2+C3; my C1+C2 stand alone. Side-finding queued as S-REVIEW-MILEAGE-TAX-FIX (dormant tax bug in review_mileage.php).
- S-INVOICE-CREATION-UX SHIPPED (044ffef + 6feb94c + cdb59ca + 430fd91) — 3 issues from real-use testing: C1 docs-only VALIDATION GAP classification (KNOWN ISSUE #103, since RESOLVED above); C2 period auto-fill on invoice create form; C3 Generate Invoice button on lease profile.
- S-CURRENT-SESSIONS-FILE SHIPPED (4e7da02) — created FLEETFORGE_CURRENT_SESSIONS.md as active session queue companion to PROGRESS.md.
- S-LOOKUP-RATES-NAMESPACE-COMPLETE SHIPPED (d83c4e3) — D134 architectural lock + KNOWN ISSUE #102 close + K-1 KEY LEARNINGS extraction.
- S-MILEAGE-RATE-VALIDATION SHIPPED (714e5d6 + 9291d6b + 11476c4 + 61f23df) — defensive engine + API validation + smoke invariants for the mileage rate tier.
- S-LOOKUP-RATES-NAMESPACE SHIPPED (7a45534 + 7941148 + 32293dd) — rate-lookup endpoint namespace fix.

**2026-05-06:**
- S-MILEAGE-RATE-ZERO-FIX SHIPPED (bc4db87, 23268f7) — C2 (engine + smoke) and INV-27/INV-84 voids contracted out, deferred to S-MILEAGE-RATE-VALIDATION

(Older entries archived in FLEETFORGE_PROGRESS.md SESSION LOG.)

---
End of CURRENT_SESSIONS file.
