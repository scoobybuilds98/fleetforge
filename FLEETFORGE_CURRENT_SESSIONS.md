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

## Active queue (as of 2026-05-07)

### Documentation cleanup (queued, small)

**S-D135-REFERENCE-PROMOTE** — QUEUED
Scope: promote D135 (mileage three-configuration matrix — Model C / Model B Lite / Disabled, locked 2026-05-07 via S-MILEAGE-ALLOWANCE-ZERO-FIX) into FLEETFORGE_CLAUDE_CODE_REFERENCE.md. Two pieces: (1) §0 LOCKED DECISIONS index row pointing back to PROGRESS.md DECISIONS for full body (terse one-row format matching D134's pattern); (2) full content body as a §13.8 subsection extension parallel to the existing D132/D133 mileage tier extension. Pre-work scan in S-MULTI-AGENT-DISCIPLINE-IMPL confirmed D135 currently exists ONLY in PROGRESS.md DECISIONS — REFERENCE.md has zero D135 hits.
Effort: ~15-20 min (single docs commit).
Dependencies: none.
Discussed: 2026-05-07 (S-MULTI-AGENT-DISCIPLINE-IMPL pre-work HALT round; operator confirmed conditional handling — pre-work returned 0 REFERENCE.md hits → branched to defer + queue here).
Notes: low priority. D135 is already authoritative in PROGRESS.md DECISIONS; the REFERENCE.md promotion is for quick-lookup discoverability only. Can be bundled with the next session that already touches REFERENCE.md.

**S-LOOKUP-RATES-NAMESPACE-COMPLETE** — QUEUED
Scope: close docs rigor gaps from S-LOOKUP-RATES-NAMESPACE original session — D-NEXT architectural lock in CLAUDE_CODE_REFERENCE.md ("category, not template name, is canonical equipment_type for rate tables"); KNOWN ISSUES close in PROGRESS.md; KEY LEARNINGS standalone extraction.
Effort: ~10-15 min (single docs commit).
Dependencies: none.
Discussed: 2026-05-07 in planning chat.
Notes: Process gaps from original session (skipped D-* sign-off, inline pre-work) cannot be retroactively closed; this session covers only closeable gaps.

**S-D130-EXTENSION** — QUEUED
Scope: extend D130 in CLAUDE_CODE_REFERENCE.md to cover scope contraction symmetrically with expansion. Triggered by S-MILEAGE-RATE-ZERO-FIX C1 silently dropping smoke gate work + INV-27/INV-84 voids without operator re-authorization.
Effort: ~10 min (single docs commit).
Dependencies: ship S-LOOKUP-RATES-NAMESPACE-COMPLETE first (operator preference).
Discussed: 2026-05-07 in planning chat.

### UX session set (from real-use testing 2026-05-07)

**S-INVOICE-DISPLAY-COMPREHENSIVE** — QUEUED (highest leverage of the UX set)
Scope: comprehensive invoice show.php — full line item breakdown (base rental, mileage with allowance/excess math, insurance, warranty, damage, fees, custom items, mileage adjustments), separate tax rows (GST/PST/HST with rates visible), credit applications, balance due. Resolves Issue 1's UX confusion (Model C "no charge" becomes visibly justified) + improves CRA-defensibility.
Effort: ~60-90 min.
Dependencies: none. Operates on app/admin/invoices/show.php and related lib/Billing helpers.
Open design questions:
- Show $0 rows or hide? (lean: hide pure $0 fee rows, keep informative ones like mileage allowance context)
- Transparency level for tax math? (lean: always show full GST/PST/HST breakdown for B2B trucking + CRA defensibility)
Discussed: 2026-05-07 in planning chat.

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

**S-EQUIPMENT-SHOW-RESPONSIVE** — QUEUED
Scope: equipment/show.php responsive at 375px — currently 5 hardcoded grids, ~10 unwrapped tables, Leaflet map. Time-boxed at 30 min for the map.
Effort: ~60-90 min.
Dependencies: none.

**S-DOC-FRESHNESS-DISCIPLINE** — QUEUED
Scope: automated staleness detection across the 6 canonical docs; D131-style discipline gate. Closes the missing tests/_smoke_doc_freshness.php that was referenced as a D131 gate but never built.
Effort: ~45-60 min.
Dependencies: none.

**S-MILEAGE-1B-FOLLOWUP** — QUEUED
Scope: T13 stress test for FIX_GAP scenario (large_gap_detected warning coverage, deferred from S-MILEAGE-1B).
Effort: ~15 min.
Dependencies: none.

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

**S-PROD-3** — QUEUED
Scope: self-host CDN deps (Google Fonts, ApexCharts, Leaflet — items #79-81 from prod prep audit).
Effort: TBD.

---

## Recent ship history (rolling — older entries archived to PROGRESS.md)

**2026-05-07:**
- S-MULTI-AGENT-DISCIPLINE-IMPL SHIPPED (this commit) — locks D136 (multi-agent discipline: hybrid single-agent serialization + branch isolation fallback) + K-11 (lock-discipline-before-frequency learning). Upgrades CURRENT_SESSIONS.md status schema with IN-FLIGHT / IN-FLIGHT-RO entries, multi-line entry format, and Pre-flight check section. Backfills S-ACCT-AUDIT SESSION LOG row to close the dangling reference in D136. D135 §0 promotion deferred to separate session (S-D135-REFERENCE-PROMOTE queued). Discipline takes effect immediately for next session.
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
