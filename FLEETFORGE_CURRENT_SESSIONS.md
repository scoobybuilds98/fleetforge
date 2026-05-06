# FleetForge — Current Sessions Queue

**Purpose:** Forward-looking queue of sessions discussed in planning but not yet shipped. Companion to FLEETFORGE_PROGRESS.md (which is the historical archive).

**Discipline:**
- Every session discussed in planning (claude.ai web chat) gets an entry here at the moment it's discussed, not retroactively.
- Each entry includes: session label, scope summary, estimated effort, dependencies, status, source-of-discussion timestamp.
- When a session ships, its entry is marked SHIPPED with the commit refs and date, then archived to PROGRESS.md SESSION LOG. The entry can be removed from this file or kept with SHIPPED tag for a short grace period — operator's call.
- When a session is descoped or superseded, mark DEFERRED or SUPERSEDED with rationale; do not delete silently.
- Update this file in lockstep with FLEETFORGE_PROGRESS.md whenever ship-state changes.

---

## Status legend
- **QUEUED** — discussed, prompt drafted (or near-drafted), not yet shipped
- **IN-FLIGHT** — currently executing in Claude Code Desktop
- **BLOCKED** — waiting on operator input or upstream session
- **SHIPPED** — landed on origin/main; archive to PROGRESS.md
- **DEFERRED** — scoped out indefinitely with reason
- **SUPERSEDED** — replaced by another session with reason

---

## Active queue (as of 2026-05-07)

### Documentation cleanup (queued, small)

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

**S-INVOICE-CREATION-UX** — QUEUED
Scope: invoice creation form auto-fill (period_start from prior invoice's period_end + 1 day OR lease.start_date; period_end from billing_cycle + period_start, capped at lease.end_date or actual_return_date). Add "Generate Invoice" button on lease profile page (active + completed leases) navigating to /admin/invoices/create?lease_id={id}. Edge-case handling for early-closed, open-ended, gap-coverage leases.
Effort: ~60-90 min.
Dependencies: none.
Discussed: 2026-05-07 in planning chat.

**S-UNIT-STATUS-COLOR** — QUEUED
Scope: small color indicator (dot or pill) next to equipment_unit references across all surfaces — fleet list, unit profile, lease references, invoice references, reservation references, maintenance work orders, universal search results, customer portal. Uses existing semantic tokens from FLEETFORGE_DESIGN_DETAILS.md (green=available, blue=on_lease, amber=maintenance, red=out_of_service/damaged, gray=retired).
Effort: ~30-45 min.
Dependencies: none.
Discussed: 2026-05-07 in planning chat.

### Bug investigation outcomes

**INV-2026-00090 mileage charge investigation** — RESOLVED via S-INVOICE-CREATION-UX C1 (2026-05-07)
Outcome: VALIDATION GAP classification. Lease 52 has `mileage_rate_km=$0.18` but `estimated_mileage_km=0.000`; InvoiceGenerator's `$mileageBillingExpected` gate at lib/Billing/InvoiceGenerator.php:585 silently skips the excess block when allowance=0, so the 507.04 km recorded distance produced no charge despite Model C math giving expected_charge=$91.27. 13 production leases share this shape (#4, #6, #7, #8, #11, #14, #15, #18, #20, #33 SMOKE, #40, #41, #52). Documented as KNOWN ISSUE #103; fix queued as S-MILEAGE-RATE-VALIDATION-FOLLOWUP (below).
Side-findings flagged out of scope: (a) duplicate-period drafts INV-2026-00089 + INV-2026-00090 on lease 52; (b) INV-2026-00090 billing_period_end overshoots lease end_date by 1 day (C2 period auto-fill will cap going forward).

**S-MILEAGE-RATE-VALIDATION-FOLLOWUP** — QUEUED
Scope: close the fourth-shape gap surfaced by KNOWN ISSUE #103 — leases with `estimated_mileage_km=0 AND mileage_rate_km>0` silently skip mileage billing despite recorded distance. Three layers parallel to D133's HARD/SOFT split: (1) API + form-side guard at api/v1/leases/create.php + app/admin/leases/create.php requiring `estimated_mileage_km > 0` when `mileage_rate_km > 0` (or explicit "rate-only / unlimited allowance" operator opt-in if business intent supports it); (2) new I6 smoke invariant in tests/_smoke_billing_invariants.php flagging the shape on existing leases; (3) InvoiceGenerator decision (HARD throw vs SOFT warning) for residual cases that slip through; (4) data-side backfill of the 13 affected leases mirroring S-MILEAGE-RATE-ZERO-FIX's 12-lease scope.
Effort: ~90-120 min (parallels S-MILEAGE-RATE-VALIDATION's three-commit shape).
Dependencies: operator decision on the "rate>0, allowance=0" semantic — is it always a misconfiguration, or is "unlimited / no excess" a valid intent worth preserving via opt-in flag? Backfill writes blocked on that decision.
Discussed: 2026-05-07 (S-INVOICE-CREATION-UX C1 pre-work diagnostic).
Notes: INV-2026-00090 itself remains as-is until backfill decision lands; void+regenerate is operator's call per usual void pattern.

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
- S-MILEAGE-RATE-VALIDATION SHIPPED (commits TBD — confirm with operator)
- S-LOOKUP-RATES-NAMESPACE SHIPPED (7a45534, 7941148, 32293dd) — docs gap supplementary commit pending (S-LOOKUP-RATES-NAMESPACE-COMPLETE)

**2026-05-06:**
- S-MILEAGE-RATE-ZERO-FIX SHIPPED (bc4db87, 23268f7) — C2 (engine + smoke) and INV-27/INV-84 voids contracted out, deferred to S-MILEAGE-RATE-VALIDATION

(Older entries archived in FLEETFORGE_PROGRESS.md SESSION LOG.)

---
End of CURRENT_SESSIONS file.
