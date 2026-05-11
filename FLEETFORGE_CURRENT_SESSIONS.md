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

**S-REVIEW-MILEAGE-TAX-FIX** — QUEUED
Scope: surfaced in S-MILEAGE-ALLOWANCE-ZERO-FIX C3. `api/v1/invoices/review_mileage.php` lines 213-218 divides line tax by 100 (e.g. `bcdiv(bcmul($amount, $gstRate, 6), '100', 2)`) but `tax_rates` table stores rates as decimal fractions (0.13 = 13%), confirmed via TaxCalculator at lib/Billing/TaxCalculator.php:62. Manager-approved mileage charges would underbill HST by 100× (e.g. $91.26 × 0.13 = $11.86 correct, but $91.26 × 0.13 ÷ 100 = $0.12 produced). Dormant — zero existing `mileage_adjustment` line items in production at fix time. Now exercisable post S-MILEAGE-ALLOWANCE-ZERO-FIX (Model B Lite invoices flow through review approval). Fix: drop the `bcdiv(..., '100', 2)` wrapper to match TaxCalculator's direct multiplication.
Effort: ~15-30 min (one-line code fix + stress test + smoke gate).
Dependencies: none.
Discussed: 2026-05-07 (S-MILEAGE-ALLOWANCE-ZERO-FIX C3 pre-work).

### Mileage refactor arc (Model B — Avi's preferred billing model)

**S-MILEAGE-2A** — QUEUED
Scope: Invoice 1 precharge line item + activation balance initialization. Implementation notes: entity_type='samsara_history_query' for audit query (not action), import FleetForge\GPS\SamsaraClient (not Samsara namespace), getDistanceForPeriod is black box, must include integration tests for T8/T10/T12.
Effort: ~90 min.
Dependencies: none.

**S-MILEAGE-2B** — QUEUED
Scope: drawdown logic on subsequent invoices, retire excess gate, Odometer card rewrite, riders Bug 1 (Cumulative Total label) + Bug 4 (backdate warning). DO NOT delete priorExcessKm safeguard (that's S-MILEAGE-3).
Effort: ~2 hrs.
Dependencies: S-MILEAGE-2A shipped.

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
- S-PREDEPLOY-CHECKLIST-CREATE SHIPPED (commits 7d5e62d IN-FLIGHT registration + 2c5d146 file creation + C3 cross-refs commit) — created `FLEETFORGE_PREDEPLOY_CHECKLIST.md` as canonical pre-deploy operations file. 21 backfilled obligations across 9 categories (operator-framed planning count; 26 in final file after D + G expansion: A1 asset cache, B1-B5 prod .env keys, C1 DNS, D1-D9 AWS infra, E empty placeholder, F1 AR drift DEFERRED-TO-QBO, G1-G4 smoke, H1-H2 rollback, I1-I3 monitoring + J references). Each item carries Originating session + Surfaced into checklist sub-fields + "Original source:" pointer + Action + Owner + Status. K-14 locked (pre-deploy obligations category separation discipline, K-12 extension): bug-shaped → KNOWN ISSUES (in PROGRESS.md), session-shaped → CURRENT_SESSIONS, pre-deploy → PREDEPLOY_CHECKLIST. Cross-refs added to REFERENCE.md §0 LOCKED DECISIONS K-14 row + §1 SEVEN FILES "Deployment-time companion" note; CURRENT_SESSIONS.md Pre-flight check section extended with category-separation note + AWS Lightsail cutover entry extended with pointer. D131 gate: PARITY OK + INVARIANTS OK (I1-I6 all PASS).

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
