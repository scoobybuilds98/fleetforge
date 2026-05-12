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

**S-MILEAGE-3-SPEC-LOCK** — IN-FLIGHT
  Started: 2026-05-12T17:48 UTC by desktop-1
  Touching: FLEETFORGE_CURRENT_SESSIONS.md, FLEETFORGE_PROGRESS.md

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

**S-MILEAGE-2B** — SHIPPED 2026-05-12 (9.5-commit arc: 7acde72 C1 IN-FLIGHT + d4923c7 C2 ENUM/dispatch + a24cb49 C3 drawdown emit + Samsara + 64b37cb C3.5 silent-bug cleanup + 840c71f C4 Model C retirement + INV-87 void+regen + 6ed9529 C5 send gate retirement + f7b7e87 C6 panel/breakdown + 6dd449c C7 I8/T8/T10 + 47acbb9 C8 spec rewrite + this C9 docs commit — see PROGRESS.md SESSION LOG)
Outcome: Full Model B drawdown lifecycle on subsequent invoices end-to-end + Model C plumbing wholesale retirement. C3 lands InvoiceGenerator drawdown emit replacing the S-LEASE-MILEAGE per-period excess block (lines 559-713 wholesale) with 3-clause drawdown math (POSITIVE amount + is_credit=1 K-16 convention per D166 — locked D-B "negative amount" wording was abstract financial intent, codebase column convention at InvoiceGenerator.php:357-362 is canonical) + Samsara getDistanceForPeriod fallback fetch when caller doesn't pre-populate AND lease equipment_unit has samsara_vehicle_id. C3.5 fixes a silent JOIN bug (samsara_vehicle_id lives on equipment_units not leases — operator-authorized cleanup; K-18 KEY LEARNING locked: "stress test pre-population can hide bugs in fallback paths"). C4 retires 7 Model C columns (excess_distance_km, excess_charge_amount, mileage_review_status, mileage_override_amount, mileage_reviewed_at, mileage_reviewed_by_user_id, mileage_review_notes) + residual idx_mileage_review index via migration 202605120907 with backup table invoices_model_c_backup_S_MILEAGE_2B per D107; INV-87 void+regen produced INV-2026-00094 at $1370.52 mileage_usage line (was $1280.71 Model C draft — operator-confirmed amount delta, no D14 conflict since INV-87 never sent); Mileage::periodExcess DELETED; Mileage::monthlyAllowance RETAINED pending S-PORTAL-MILEAGE-MODEL-B refactor (D-H refined post-pre-C4-scan per D154 + D167); I6 invariant refactored for Model B with created_at >= MODEL_B_SHIP_DATE scope filter. C5 deletes api/v1/invoices/review_mileage.php (316 lines) + HARD send gate (D84) + show.php Mileage Review card markup + Alpine modal state. C6 lands the Drawdown Reconciliation panel + Odometer card rewrite (Period Charge row + Lease-to-Date Distance label + Samsara warnings banner) + Financial Summary 3-row breakdown render (Mileage usage / Precharge credit applied / Net mileage charge) at the S-INVOICE-DISPLAY-COMPREHENSIVE C3 HTML marker. FF_ASSET_VERSION 1.0.26 → 1.0.27 dev .env; filed under PREDEPLOY_CHECKLIST.md A2 per K-14. C7 lands I8 invariant (drawdown math sanity: credit == min(usage, pre_balance) AND post == pre − credit) + 6-case stress test using inline-predicate execution + T8/T10 real fixture-mode coverage replacing source-inspection placeholders. C8 rewrites FLEETFORGE_SPEC_FINAL.md Invoice Generation Flow + Invoice Calculation Order + Mileage Billing sections (Model A → Model B; precharge + drawdown only per D-M (ii); close-refund deferred to S-MILEAGE-3 spec rewrite). C9 docs: 21 DECISIONS rows D147-D167 (D-A through D-S + K-16 emit-shape clarification D166 + D-H refinement D167) + K-18 KEY LEARNING + REFERENCE.md §13.4 retirement (Model C → Model B) + §13.4.1 lifecycle table update + §13.6 T8/T10 status flip. **D131 gate clean on every commit:** PARITY OK + INVARIANTS OK (I1-I7 through C6, I1-I8 from C7) + samsara distance 16/16 + migrate 11 → 13 ok / 0 drift / 0 missing. **NOT TOUCHED:** api/v1/leases/close.php priorExcessKm safeguard (D-J / D156, S-MILEAGE-3 owns); FLEETFORGE_ACCOUNTING_SPEC.md (D-N DEFERRED per D160, S-MILEAGE-3 owns); lease_billing_periods table (load-bearing per K-15); lease_close_adjustments table (S-MILEAGE-3 territory). **T1 visual walk pending operator** per D146 pattern (8-step walk: 1) activate fresh precharge lease, 2) confirm precharge_balance init, 3) generate Invoice 1 → mileage_precharge line, 4) send Invoice 1 → stamp + balance unchanged, 5) re-edit lease post-send → PRECHARGE_LOCKED 409, 6) generate Invoice 2 → mileage_usage + mileage_drawdown_credit emit + balance decrement, 7) view Invoice 2 show page → Drawdown Reconciliation panel + Financial Summary breakdown render, 8) confirm no Mileage Review card + no Review Required label).


**S-MILEAGE-3** — QUEUED

Scope: close the Model B mileage lifecycle. Wire `precharge_refund_method` + `precharge_refund_settled_at` at lease close with a cash/credit picker; emit the chosen refund (credit_note row for credit branch; cash-refund treatment per D-B); retire the S-MILEAGE-FIX-0 priorExcessKm transitional safeguard + Model C `lease_close_adjustments` table + `closeReconciliation` Alpine surface; rewrite `FLEETFORGE_SPEC_FINAL.md` close-refund section (D-H, deferred by S-MILEAGE-2B C8 D-M (ii)); land `FLEETFORGE_ACCOUNTING_SPEC.md` updates (D-I, deferred by S-MILEAGE-2B D-N / D160) conditional on CPA input.

Out of Scope:
- Partial refunds (full residual `precharge_balance` only — partial-refund UX is a separate session).
- Void-of-drawdown-invoice reversal of `precharge_balance` (carry-forward from S-MILEAGE-2B D148; surfaces as a KNOWN ISSUE for a follow-up session).
- `Mileage::monthlyAllowance` final deletion (S-PORTAL-MILEAGE-MODEL-B owns; portal still consumes per D154 + D167).
- Customer-portal Model B rendering (S-PORTAL-MILEAGE-MODEL-B owns).
- Mileage-Lite (`precharge_enabled=0`) leases — they have no precharge_balance and skip the refund picker entirely.
- Cumulative-Total label rider (S-INVOICE-CUMULATIVE-TOTAL-LABEL owns the repo-wide grep beyond the C6 incidental).
- Backdate-warning rider (S-INVOICE-BACKDATE-WARNING owns).

Pre-work scan findings (verified 2026-05-12 against current code state, S-MILEAGE-3-SPEC-WRITE session):
- A. `priorExcessKm`/`prior_excess_km`/`prior_excess` refs in [api/v1/leases/close.php](api/v1/leases/close.php) = **26** (operator estimated ~24). Spread: SELECT at 803-810; computation + km→lease-unit conversion at 837-843; close_adjustment-block subtraction at 1051-1069; audit_log payload at 1218 + 1267 + 1306 + 1339; audit_log no-touch path at 1322-1339; response payload at 1358. All retire under D-F.
- B. `lease_close_adjustments` table row count = **0** in production. Active code refs at close.php:1025 + 1085 + 1178 (insertion site + comments); no FK consumers in `api/v1/`, `lib/`, `app/admin/`, `tests/`. Archived ref at `scripts/archive/fix_ar_drift_2026_05_07.php` (not active). Backup table per D107 capture-all discipline will snapshot 0 rows — forensic-only insurance, retirement is clean per K-15 disposition.
- C. `credit_notes.source` ENUM at FLEETFORGE_DATABASE_MASTER.sql:1247 = `('mileage_overpayment','invoice_adjustment','damage_resolution','goodwill','payment_returned','overpayment','other')`. `precharge_refund` not present — D-J adds via end-of-ENUM append per D126.
- D. `payments` table has `payment_method` ENUM `('check','ach','wire','credit_card','cash','e_transfer','account_credit','other')` and per-row refund fields (`refund_amount`, `refund_date`, `refund_method`, `refund_reference`, `refunded_by`) but **no `payment_type` column** — outbound cash refunds have no natural payments-table row shape today. Surfaced as scope-shaping constraint on D-B; the prompt's "auto-record as payments row with payment_type='precharge_refund'" must be reshaped.
- E. `closeReconciliation` Alpine getter at [app/admin/leases/show.php:2190+](app/admin/leases/show.php) (returns `priorExcessKm`/`rawOverageKm`/`priorOverbillKm` etc.); panel consumers at lines 1146-1281 (~135 LOC). Both retire under D-F.
- F. `SELECT COUNT(*) FROM leases WHERE precharge_refund_method IS NOT NULL OR precharge_refund_settled_at IS NOT NULL` = **0**. First-emitter status confirmed; D-J's backup-skip per D128 applies.
- G. K-19 verification: `/Users/avi/Documents/fleetforge/.claude/dev_credentials.json` exists, `chmod 600`, last modified 2026-05-12 22:25, gitignored via `.claude/` entry. T1 walk reuses persistent user per K-19 discipline (no soft-delete cycle).

Locked decisions D-A through D-N:

- **D-A — Close UI cash/credit refund picker (placement, default, conditional rendering).**
  Surface lives in the existing close modal at [app/admin/leases/show.php:1047-1352](app/admin/leases/show.php) (the canonical close UX — there is no separate `close.php` admin file; the modal is rendered inline on the lease show page). New section added between the Actual Mileage form-group and the ADV-BILL-1 D-H Advance-Billing Reconciliation block, positioned where the Mileage Reconciliation panel (lines 1146-1281) lives today after that panel is removed per D-F. Section shape:
  - Header row: "Precharge Refund" + badge "Model B" (mirrors S-MILEAGE-2B D158 Drawdown Reconciliation panel chip).
  - Read-only display: current `lease.precharge_balance` formatted as `$X.XX CAD`.
  - Refund-method picker: native `<input type="radio">` group with two options — `cash` and `credit`. Mirrors the ADV-BILL-1 D-H radio pattern at lines 1310-1335 (consistent close-modal UX vocabulary).
  - Default selection: **`credit`** (CRA-friendly + reuses existing credit_note flow + matches D85's underage_credit precedent).
  - Conditional render: only when `lease.precharge_enabled = 1 AND lease.precharge_balance > 0`. Skipped when `precharge_balance == 0` (no refund needed — typical case when drawdown fully consumed the balance) OR when `precharge_enabled = 0` (Model B Lite — no precharge concept).
  - Optional manager-notes textarea (free-text, captured in audit_log per D-L; no required-field gate since the refund-method itself is the audited decision).

- **D-B — Cash refund execution flow. OPERATOR DECISION REQUIRED.**
  Pre-work scan D surfaced that the prompt's option (ii) "write a payments row with payment_type='precharge_refund'" is non-viable as written — the `payments` table has no `payment_type` column. The viable shapes:
  - **(i) Manual** *(recommended)*: operator records the cash payment-out externally (cheque issued, EFT, etc.); the close transaction stamps `precharge_refund_method='cash'` immediately, but `precharge_refund_settled_at` stays NULL until the operator confirms physical disbursement via a follow-up UI action (a "Mark cash refund settled" button on the lease show page, gated to Manager+ role per D84-equivalent). No payments-table row is written. CRA paper trail lives in `audit_log` per D-L. Simplest implementation; respects schema as-shipped.
  - **(ii) Negative-amount payments row** *(non-standard)*: insert a `payments` row with `amount = -(precharge_balance)`, `payment_method='cash'`, `status='cleared'`, `notes='Precharge refund — lease #N'`. Negative amounts are not designed into the payments table's invoice-allocation flow (would require defensive filtering in payment-listing queries). Adds AR-ledger visibility at the cost of schema churn beyond this session's scope.
  - **(iii) Out-of-scope cash-refund-payment table extension** *(deferred)*: design a proper outbound-refunds table (separate from `payments`). Larger scope, deferred to a future session if the operator picks (i) here and later wants a richer cash-refund ledger.
  **Recommendation:** lock (i) Manual. Effort/risk fits the session. (ii) and (iii) are deferred; the audit_log row from D-L gives the CRA-defensible paper trail without payments-table risk. **Surface to operator at session open; halt before C3 if pushback on (i).**

- **D-C — Credit refund execution flow.**
  When `precharge_refund_method='credit'` is picked, the close transaction inserts a `credit_notes` row with `source='precharge_refund'` (added in D-J), `customer_id` from the lease, `amount = precharge_balance`, `amount_remaining = amount`, `currency` matching the lease's existing invoice currency, `status='active'`, `notes='Precharge refund issued at lease close — lease #N (contract <num>)'`, `created_by_user_id` from session. The credit is applicable against any future invoice for that customer per D85 Path B precedent (existing `api/v1/credit_notes/apply.php` flow consumes it unchanged). `precharge_refund_settled_at = NOW()` stamps in the same transaction since credit-note creation IS the refund-settled event (no external dispatch step like cash). Audit_log per D-L captures `credit_note_id`.

- **D-D — `precharge_refund_method` state machine + validation.**
  - NULL at lease activation (D-J first-emitter status confirmed).
  - Set to `'cash'` or `'credit'` at lease close, ONLY when `precharge_balance > 0`. If `precharge_balance == 0` at close, the column stays NULL (no refund needed).
  - **Immutable after set:** any subsequent attempt to change the value returns `409 PRECHARGE_REFUND_LOCKED` from the close endpoint (mirrors D113 PRECHARGE_LOCKED pattern + D140 PRECHARGE_ALREADY_BILLED naming family). Error message: *"Precharge refund method already locked at close. Method cannot be changed once the close transaction has committed."*
  - Re-close of an already-closed lease is blocked by the existing lease-status gate (lease must be `active` to close); D-D's 409 fires only on edge cases where status was reverted and the column was previously written.

- **D-E — `precharge_refund_settled_at` stamp timing.**
  Two semantic options:
  - **(i) Stamp at close transaction commit** — `settled_at` means refund "intent" was finalized at close.
  - **(ii) Stamp when refund actually posts** — for credit branch: stamp at the same close transaction commit (credit_note creation IS the posting). For cash branch under D-B option (i): stamp only when the operator confirms physical disbursement via the "Mark cash refund settled" button.
  **Locked: (ii).** Rationale: `settled_at` means money moved (or the credit was issued and is consumable). For the credit branch, both events collapse into the close commit so the stamp lands there. For the cash branch under D-B (i), the stamp lands later — this distinguishes "method chosen" from "money disbursed" and matches the operator's likely audit-trail mental model.

- **D-F — `priorExcessKm` transitional safeguard retirement (S-MILEAGE-FIX-0 D98).**
  Full removal from [api/v1/leases/close.php](api/v1/leases/close.php). Specifically:
  - DELETE the SELECT at lines 803-810 (prior-excess sum query).
  - DELETE the legacy partial-end overage subtraction block at lines 837-843 (D100 km→lease-unit conversion; the legacy block continues to compute overage, but no `priorExcessKm` subtraction).
  - DELETE the close_adjustment-block subtraction at lines 1051-1069 (D98) — moot once D-G drops the close_adjustment block entirely.
  - DELETE the D102 audit_log workaround paths at 1239-1267 (close_adjustment branch) + 1322-1339 (legacy-path no-touch branch).
  - DELETE the `prior_excess_km` field from the close response at 1306, 1339, 1358 (`api/v1/leases/show.php` response shape unchanged per D156 verification — the S-MILEAGE-FIX-0 D-B getter dependency on `lease.prior_excess_km` becomes a non-issue once show.php's `closeReconciliation` getter retires under D-F + D-K).
  - DELETE the `closeReconciliation` Alpine getter at [app/admin/leases/show.php:2190+](app/admin/leases/show.php) + the Mileage Reconciliation panel at lines 1146-1281 + the `prior_excess_km` template references at 2201, 2207, 2218-2223, 2230-2264.
  - Backup-table or grep-for-callers verification per K-15: pre-work scan A enumerates all 26 ref sites; the retirement scan must re-run before C4 to catch any post-spec-write additions.
  Audit: the priorExcessKm safeguard's purpose (preventing double-billing kilometres already charged via Model C per-period excess) is structurally obsolete post-S-MILEAGE-2B C3 InvoiceGenerator drawdown rewrite — Model B never bills "excess" on monthly invoices, so there is no quantity to subtract at close. The safeguard's audit_log paper trail (S-MILEAGE-FIX-0 D102) was load-bearing only during the Model C → Model B transition window (2026-05-04 → 2026-05-12); historical audit_log rows remain queryable.

- **D-G — `lease_close_adjustments` table retirement.**
  Migration `202605MMDDhhmm_S-MILEAGE-3_close_adjustments_retirement.sql` (timestamp at apply time):
  1. CREATE backup table `lease_close_adjustments_backup_S_MILEAGE_3` mirroring the existing CREATE TABLE shape per D107 capture-all discipline.
  2. `INSERT INTO lease_close_adjustments_backup_S_MILEAGE_3 SELECT * FROM lease_close_adjustments` (idempotent via empty-check guard + columns-still-exist guard).
  3. `DROP TABLE lease_close_adjustments` via `ff_drop_table_if_present` (mirrors S-MILEAGE-2B C4 D107/D153 pattern; helper may need adding if absent — verify pre-migration).
  4. Mirror the DROP into FLEETFORGE_DATABASE_MASTER.sql by removing lines 2116+ (the CREATE TABLE block).
  Pre-work scan B confirms 0 rows + no FK consumers — DROP is clean. Backup snapshot will capture 0 rows; the empty backup is the discipline marker per K-15 (backup-table cost is one extra CREATE statement; cost-of-skip would be no recovery path if a post-S-MILEAGE-3 audit surfaces a missed historical Model C close).
  Also DELETE the close.php close_adjustment processing block (lines 813-1218 wholesale — the input parsing at lines 76-101 + the close_adjustment block in the close transaction at 1022-1218 + the audit_log rows at 1197-1267). The block's full deletion is large (~250 LOC) but mechanically straightforward — no live data shape depends on it post-S-MILEAGE-2B C4 Model C plumbing retirement.

- **D-H — `FLEETFORGE_SPEC_FINAL.md` close-refund section rewrite.**
  S-MILEAGE-2B C8 (commit 47acbb9, D-M (ii) D159) explicitly deferred the close-refund spec text. Now write it:
  - Replace the current "Lease close + refund (S-MILEAGE-3 — pending)" placeholder section with the full Model B close-refund semantics:
    - Cash/credit refund picker at close (D-A + D-K).
    - State machine for `precharge_refund_method` (D-D).
    - Cash-branch flow per D-B locked option.
    - Credit-branch flow per D-C.
    - `settled_at` stamp timing per D-E.
    - 409 PRECHARGE_REFUND_LOCKED state (D-D).
  - Preserve the D135 three-config matrix intact across close (Model B precharge → refund residue at close; Model B Lite → no refund needed; Disabled → no precharge concept). The close-refund section must reference D135 explicitly so the matrix's invariant readers know close is covered.
  - Update the "Mileage line item types" section to flip `mileage_credit` from CLOSED CATEGORY to DELETED CATEGORY (Model C close-time underage refund — table retired in this session; the ENUM value stays for invoice line-item ENUM but zero new emitters).

- **D-I — `FLEETFORGE_ACCOUNTING_SPEC.md` updates. OPERATOR DECISION REQUIRED — ACCOUNTANT INPUT BLOCKING.**
  S-MILEAGE-2B C8 D-N / D160 deferred accounting-spec edits to this session pending CPA conversation. The Model B precharge lifecycle's accounting treatment:
  - **Activation** (S-MILEAGE-2A C2 D137): Cash IN ↔ Liability OUT (precharge_balance is a customer-paid liability the company owes back until consumed or refunded).
  - **Drawdown** (S-MILEAGE-2B C3 D148): Liability ↓ ↔ Revenue ↑ (drawdown amount converts liability to revenue on each invoice send).
  - **Cash refund at close** (D-B locked branch): Cash OUT ↔ Liability ↓.
  - **Credit refund at close** (D-C): Liability ↓ ↔ Customer Credit ↑ (existing credit_notes liability account, per S-FIX-2 D47/D48 pattern).
  Specific CPA questions blocking D-I lock:
  - (a) Which GL accounts? Liability account ID for precharge_balance; revenue recognition account for drawdown conversion; customer-credit liability for credit-branch refund.
  - (b) Recognition timing — drawdown converts liability → revenue at invoice **emission** OR at invoice **send/settlement**? S-FIX-2 D47 already established a precedent for AR/credit treatment at send-time; CPA should confirm parity.
  - (c) Cash refund flow under D-B locked option (i) — pass-through Accounts Payable as a customer refund, OR direct cash-out journal entry at `precharge_refund_settled_at` stamp time? D-B (i) defers the cash event from close-commit to operator-confirmed disbursement, so the JE date is the settle date, not the close date.
  - (d) Credit refund flow under D-C — does `credit_notes` row creation drive the JE via existing AutoEntryBridge (`lib/Accounting/AutoEntryBridge.php`'s `onCreditNoteIssued` per S-FIX-2), OR does precharge-source credit need a separate JE branch?
  - (e) Tax handling on refund — original `mileage_precharge` line on Invoice 1 was taxable (S-MILEAGE-2A D-C); does the refund REVERSE the tax (CRA standard for customer refunds), or is the tax retained as a non-refundable component? If reversed, what's the JE shape for the tax leg?
  **Workflow:** the main S-MILEAGE-3 session may proceed with engine + UI work (D-A through D-G + D-J through D-N) while D-I is accountant-blocked. C8 docs commit lands D-I as DEFERRED status if CPA answers haven't arrived by then; a separate small follow-up session `S-MILEAGE-3-ACCT-SPEC` (≤30 min, single-commit) lands the accounting-spec text once answers come in. Surface as OPERATOR DECISION REQUIRED at session open with explicit "are CPA answers in?" check.

- **D-J — New `credit_notes.source` ENUM value `precharge_refund`.**
  Schema migration `202605MMDDhhmm_S-MILEAGE-3_credit_notes_source_ext.sql` (timestamp at apply time):
  - `ALTER TABLE credit_notes MODIFY COLUMN source ENUM('mileage_overpayment','invoice_adjustment','damage_resolution','goodwill','payment_returned','overpayment','other','precharge_refund') NOT NULL` — appended at end per D126 (preserves ordinals; no row rewrite for legacy data).
  - Mirror into FLEETFORGE_DATABASE_MASTER.sql line 1247.
  - Backup table per D107 skipped — pre-work scan F confirms zero pre-migration rows with the new value (first emitter); D128 trivial-data backup-skip pattern applies.
  - PARITY OK post-edit; migrate `13 → 14 ok / 0 drift / 0 missing` (the lease_close_adjustments DROP under D-G is migration `15`).

- **D-K — Close flow UI updates (full UX shape per D-A placement).**
  The close modal's JavaScript `closeForm` data shape extends with two new keys: `precharge_refund_method` (string, default `'credit'` per D-A) and `precharge_refund_notes` (string, default `''`). The `closeLease()` function at [app/admin/leases/show.php:2297-2356](app/admin/leases/show.php) extends the payload-construction block:
  ```js
  // S-MILEAGE-3 D-K: precharge refund picker payload assembly
  if (this.lease && this.lease.precharge_enabled && parseFloat(this.lease.precharge_balance || 0) > 0) {
      payload.precharge_refund = {
          method: this.closeForm.precharge_refund_method,
          notes:  this.closeForm.precharge_refund_notes || null,
      };
  }
  ```
  Server-side [api/v1/leases/close.php](api/v1/leases/close.php) receives the new `precharge_refund` block, validates `method ∈ {cash, credit}`, and dispatches:
  - `method='credit'` → emit credit_notes row per D-C; UPDATE `leases.precharge_refund_method='credit'` + `precharge_refund_settled_at=NOW()` inside the close transaction (D20 FOR UPDATE on lease row already held).
  - `method='cash'` → UPDATE `leases.precharge_refund_method='cash'` inside close transaction; `precharge_refund_settled_at` stays NULL per D-E (i)/D-B (i) deferred-settle pattern.
  - When `lease.precharge_enabled=0` OR `lease.precharge_balance == 0`: silent skip — the picker UI didn't render, the payload doesn't carry the block, and the close transaction proceeds unchanged.
  Validation: missing `precharge_refund` block when `precharge_enabled=1 AND precharge_balance > 0` returns `422 PRECHARGE_REFUND_REQUIRED` with explicit message ("Precharge refund method is required when closing a lease with a positive precharge balance.").
  New "Mark cash refund settled" follow-up button on lease show page (post-close) when `precharge_refund_method='cash' AND precharge_refund_settled_at IS NULL` — Manager+ role gate (D84 pattern), single endpoint `api/v1/leases/precharge_refund_settle.php` (NEW), stamps `precharge_refund_settled_at=NOW()`, audit_log row per D-L, 409 `PRECHARGE_REFUND_ALREADY_SETTLED` on retry.

- **D-L — Refund audit_log entries (D102 workaround pattern).**
  Two audit_log entity_types added (both use `action='update'` per audit_log.action ENUM constraint workaround pattern from D102):
  - `lease_precharge_refund_issued` — written at close transaction commit when refund method is set. `new_values` JSON captures: `method` (cash|credit), `amount` (= precharge_balance at close), `precharge_balance_at_close`, `related_credit_note_id` (credit branch only; NULL for cash), `notes` (optional manager input), `closed_by_user_id`.
  - `lease_precharge_refund_settled` — written at cash-branch settle stamp commit (only fires for `method='cash'` under D-B (i)). `new_values` JSON captures: `method`, `settled_at`, `settled_by_user_id`. Skipped for credit branch since settled_at is stamped at close-commit time.
  Sentry capture: silent on success (matches D125 precedent); WARNING-level on validation failure paths (e.g., re-settle attempt on already-settled refund). Both wrapped in try/catch with `error_log` fallback per D102 (observability MUST NOT block close transaction).

- **D-M — T1 visual walk shape (8-step, uses K-19 persistent dev user).**
  Reuses the persistent user at `/.claude/dev_credentials.json` per K-19 discipline (no create/delete cycle). 8-step walk:
  1. Restore equipment_unit + lease + fixture state (samsara.fixture_mode='1', equipment_unit 14 samsara_vehicle_id='FIX_STD' per S-MILEAGE-2B-T1 pattern). Create a fresh precharge-enabled lease via SQL (per S-MILEAGE-2B-T1 operator preference noted) with `precharge_amount=$500`.
  2. Activate the lease → verify `precharge_balance=$500` initialized (D137); Invoice 1 auto-generated with `mileage_precharge $500` line; send Invoice 1 → verify `precharge_invoiced_at` stamps; balance unchanged.
  3. Generate Invoice 2 with `period_distance_km=1234.56` (fixture FIX_STD); send Invoice 2 → verify `mileage_usage` + `mileage_drawdown_credit` lines emit; `precharge_balance` decrements from $500 to a residual value (e.g., $277.78 if rate=$0.18/km).
  4. Open close lease modal → verify the "Precharge Refund" section renders (D-A condition met: `precharge_enabled=1 AND precharge_balance > 0`); default selection is `credit`; balance display shows `$277.78`.
  5. Pick **`credit`** → submit close → verify: (a) `credit_notes` row created with `source='precharge_refund'`, `amount=$277.78`, `customer_id` matches lease; (b) `leases.precharge_refund_method='credit'`; (c) `leases.precharge_refund_settled_at` stamped at close-commit time; (d) audit_log entity_type=`lease_precharge_refund_issued` row with full new_values JSON.
  6. Attempt to re-close the same lease (UI shouldn't allow this — lease status now `completed`; if force-call via API, expect existing `INVALID_TRANSITION` 409 from close.php). Validates D-D 409 path is unreachable in normal flow.
  7. Repeat with a new precharge-enabled lease, pick **`cash`** → submit close → verify: (a) NO credit_notes row created; (b) `leases.precharge_refund_method='cash'`; (c) `leases.precharge_refund_settled_at` is NULL (D-E ii / D-B i deferred-settle); (d) audit_log row written. Then click "Mark cash refund settled" button on lease show page → verify settled_at stamps + `lease_precharge_refund_settled` audit_log row + 409 PRECHARGE_REFUND_ALREADY_SETTLED on retry.
  8. Confirm Model C surface is fully absent post-D-F+D-G retirement: (a) close modal renders without the Mileage Reconciliation panel; (b) lease show page response has no `prior_excess_km` field; (c) `SELECT COUNT(*) FROM lease_close_adjustments` returns "Table doesn't exist"; (d) close.php response payload contains no priorExcessKm fields.
  Stop conditions per S-MILEAGE-2B-T1 pattern: all 8 steps PASS; failures filed as KNOWN ISSUES (#NNN) in PROGRESS.md per K-12/K-14 routing.

- **D-N — Smoke invariant I9 addition.**
  New invariant in [tests/_smoke_billing_invariants.php](tests/_smoke_billing_invariants.php) at the I8 slot's logical successor location (~line 466 region). Predicate: for every closed lease (`status='completed'`, `deleted_at IS NULL`) with `precharge_enabled=1 AND precharge_invoiced_at IS NOT NULL AND precharge_balance > 0 AT CLOSE TIME`, **must** have non-NULL `precharge_refund_method` AND (for `method='credit'`) non-NULL `precharge_refund_settled_at`. The "at close time" condition is reconstructible via the audit_log row entity_type=`lease_precharge_refund_issued` `new_values.precharge_balance_at_close` field — the live `precharge_balance` column post-close will be 0 (refunded) or unchanged depending on D-B locked branch, so direct column-read is insufficient. Two violation classes:
  - (a) Method NULL when refund should have fired (residual balance at close without method set — data integrity gap; would only surface if a close transaction bypassed the D-K validator).
  - (b) `method='credit'` AND `settled_at IS NULL` (credit-branch should stamp at close-commit; NULL indicates an aborted transaction).
  Cash-branch `method='cash' AND settled_at IS NULL` is **NOT** a violation by design (D-E ii / D-B i defer-settle pattern).
  Smoke summary line updated: I1 → I9 (was I1 → I8).
  Stress test file `tests/_stress_smoke_invariants_i9.php` mirrors S-MILEAGE-2B C7 D161's inline-predicate execution pattern (CREATE TEMPORARY TABLE fault-injection trick where applicable; otherwise direct row-insert + assertion + ROLLBACK in same connection).

Implementation notes — proposed commit arc (7-9 commits + T1):

  - **C1**: `S-MILEAGE-3 C1 — register IN-FLIGHT before edits (D136)` — standalone IN-FLIGHT registration commit per S-D136-COMMIT-DISCIPLINE; touching domains `FLEETFORGE_DATABASE_MASTER.sql`, `db_migrations/`, `api/v1/leases/`, `app/admin/leases/`, `tests/`, FLEETFORGE_SPEC_FINAL.md, FLEETFORGE_ACCOUNTING_SPEC.md (conditional on D-I unblock).
  - **C2**: `S-MILEAGE-3 C2 — credit_notes.source ENUM ext + master mirror (D-J + D-A)` — migration `_credit_notes_source_ext.sql` (ENUM append); master file line 1247 mirror; PARITY OK post-edit; migrate `13 → 14 ok / 0 drift / 0 missing`.
  - **C3**: `S-MILEAGE-3 C3 — close.php precharge refund dispatch + 409 gate (D-B + D-C + D-D + D-K + D-L)` — server-side close API extension. `precharge_refund` block validation (422 PRECHARGE_REFUND_REQUIRED) + cash/credit dispatch + audit_log rows (entity_type `lease_precharge_refund_issued`) + 409 PRECHARGE_REFUND_LOCKED state-machine guard. NEW endpoint `api/v1/leases/precharge_refund_settle.php` for D-B (i) cash-branch deferred-settle (Manager+ role + 409 PRECHARGE_REFUND_ALREADY_SETTLED). Stress test `tests/_stress_precharge_refund.php` (4 cases: credit branch happy path + cash branch happy path + missing-block-when-required 422 + double-settle 409). PARITY OK + INVARIANTS OK (I1-I8 — I9 not yet added) + samsara distance 16/16 + migrate 14/0/0.
  - **C4**: `S-MILEAGE-3 C4 — priorExcessKm safeguard retirement + lease_close_adjustments DROP (D-F + D-G)` — close.php deletion (lines 76-101 input parsing + 803-843 priorExcessKm SELECT/computation + 813-1218 close_adjustment block + 1239-1339 audit_log workaround paths + 1306-1358 response payload `prior_excess_km` fields); app/admin/leases/show.php deletion (lines 1146-1281 Mileage Reconciliation panel + 2190+ closeReconciliation Alpine getter + `prior_excess_km` template refs); migration `_close_adjustments_retirement.sql` (backup table per D107 + DROP TABLE per ff_drop_table_if_present); master file lease_close_adjustments CREATE TABLE block deletion (lines 2116+). PARITY OK + INVARIANTS OK (I1-I8 — I9 not yet added) + migrate `14 → 15 ok / 0 drift / 0 missing`.
  - **C5**: `S-MILEAGE-3 C5 — close UI precharge refund picker (D-A + D-K)` — app/admin/leases/show.php close modal extension (new section between Actual Mileage form-group and ADV-BILL-1 D-H Advance-Billing block); closeForm data shape extension; closeLease() payload assembly; "Mark cash refund settled" button on lease show page (conditional render: `precharge_refund_method='cash' AND precharge_refund_settled_at IS NULL`). FF_ASSET_VERSION bump per K-14 PREDEPLOY_CHECKLIST.md A2.
  - **C6**: `S-MILEAGE-3 C6 — Smoke I9 + stress (D-N)` — tests/_smoke_billing_invariants.php I9 invariant + summary-line update; tests/_stress_smoke_invariants_i9.php fault-injection stress. INVARIANTS OK I1 → I9 post-edit.
  - **C7**: `S-MILEAGE-3 C7 — FLEETFORGE_SPEC_FINAL.md close-refund rewrite (D-H)` — replace placeholder section with full Model B close-refund semantics; D135 three-config matrix preservation; mileage_credit ENUM value flipped to DELETED CATEGORY.
  - **C8** *(conditional on D-I unblock)*: `S-MILEAGE-3 C8 — FLEETFORGE_ACCOUNTING_SPEC.md updates (D-I)` — full liability-extinguishment JE pattern per CPA-locked answers (a) through (e). If CPA answers not in by C8-time, this commit DEFERS to a follow-up session `S-MILEAGE-3-ACCT-SPEC` (file QUEUED entry in CURRENT_SESSIONS.md Documentation cleanup; ≤30 min single-commit follow-up).
  - **C9**: `S-MILEAGE-3 C9 — Docs (SESSION LOG + DECISIONS + REFERENCE.md + CURRENT_SESSIONS SHIPPED)` — DECISIONS rows D168-D181 (D-A through D-N + any mid-arc refinements); KEY LEARNINGS row(s) if any surface; REFERENCE.md §13.4 update (close stage flips from "pending S-MILEAGE-3" to "SHIPPED"); REFERENCE.md §13.4.1 lifecycle table flips `precharge_refund_method` + `precharge_refund_settled_at` to ✓ S-MILEAGE-3 SHIPPED; REFERENCE.md §13.4.1 "What S-MILEAGE-3 must do" section replaced with summary; CURRENT_SESSIONS.md S-MILEAGE-3 entry flipped to SHIPPED with full Outcome block.
  - **T1**: visual walk per D-M (K-19 persistent user; 8-step walk; PARTIAL/PASS filed in Recent ship history per D146 pattern).

Effort: **~75-90 min** for C1-C9 sans T1 (smaller surface than S-MILEAGE-2B given Model C plumbing already retired; the lease_close_adjustments DROP is mechanically trivial post-close.php deletion). T1 adds ~30-45 min depending on Step 7 cash-branch walk-through duration.

Pre-work scan re-run items (must execute at main S-MILEAGE-3 session open, immediately post-IN-FLIGHT commit):
1. `grep -c "priorExcessKm\|prior_excess_km\|prior_excess" api/v1/leases/close.php` — expect 26 (verify no drift since 2026-05-12).
2. `SELECT COUNT(*) FROM lease_close_adjustments` — expect 0 (verify no late writers between SPEC-WRITE ship and main session open).
3. `SHOW COLUMNS FROM credit_notes LIKE 'source'` — verify ENUM still missing `precharge_refund` (no parallel migrator).
4. `SELECT COUNT(*) FROM leases WHERE precharge_refund_method IS NOT NULL` — expect 0 (verify first-emitter status preserved).
5. `ls -la /Users/avi/Documents/fleetforge/.claude/dev_credentials.json` — verify K-19 persistent user file intact (chmod 600, gitignored).
Surface any drift to operator before C2 begins.

NOT TOUCHED IN THIS SESSION:
- `Mileage::monthlyAllowance` helper deletion (S-PORTAL-MILEAGE-MODEL-B owns final deletion; portal still consumes per D154 + D167).
- Customer portal `app/portal/leases/view.php` Model B refactor (S-PORTAL-MILEAGE-MODEL-B owns).
- `lease_billing_periods` table (load-bearing per K-15; S-CLEANUP-LBP-TABLE owns audit).
- `payments` table schema (D-B locked option (i) Manual avoids any payments-table changes; (ii) and (iii) options deferred).
- Cumulative-Total label repo-wide grep (S-INVOICE-CUMULATIVE-TOTAL-LABEL owns).
- Backdate-warning rider (S-INVOICE-BACKDATE-WARNING owns).
- Existing `mileage_credit` invoice line-item ENUM value (preserved for audit trail on historical Model C close-time refunds; zero new emitters post-D-G).

Dependencies:
- ✓ S-MILEAGE-2B SHIPPED 2026-05-12 (drawdown engine groundwork).
- ✓ S-MILEAGE-2A SHIPPED 2026-05-12 (precharge schema + Invoice 1 lifecycle).
- ✓ S-MILEAGE-1 SHIPPED 2026-05-04 (precharge column schema).
- ✓ S-PREDEPLOY-CHECKLIST-CREATE SHIPPED 2026-05-12 (K-14 routing for the C5 FF_ASSET_VERSION bump).
- ⏳ D-I CPA conversation — accountant-blocked; main session may proceed with engine + UI work and defer C8 conditionally.

Status: **QUEUED** (full spec locked 2026-05-12 via S-MILEAGE-3-SPEC-WRITE).

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

**S-INVOICE-CUMULATIVE-TOTAL-LABEL** — QUEUED
Scope: [TBD — originally Bug 1 rider on S-MILEAGE-2B, scoped out 2026-05-12 to a separate session; rider definition to be surfaced when scheduled.]
Effort: ~30 min.
Dependencies: none.
Discussed: 2026-05-12 in planning chat (S-MILEAGE-2B-SPEC-LOCK).

**S-INVOICE-BACKDATE-WARNING** — QUEUED
Scope: [TBD — originally Bug 4 rider on S-MILEAGE-2B, scoped out 2026-05-12 to a separate session; rider definition to be surfaced when scheduled.]
Effort: ~30 min.
Dependencies: none.
Discussed: 2026-05-12 in planning chat (S-MILEAGE-2B-SPEC-LOCK).

**S-PORTAL-MILEAGE-MODEL-B** — QUEUED
Scope: refactor `app/portal/leases/view.php` to render Model B drawdown semantics for the customer-facing lease view — drop the Model C "monthly allowance" card; surface precharge balance + drawdown history if precharge configured; otherwise per-km usage summary. Includes final deletion of `Mileage::monthlyAllowance` helper once portal stops consuming it (per D-H refined / D154 + D167). The show.php caller died in S-MILEAGE-2B C6 (D-L Mileage Review card → Drawdown Reconciliation panel conversion); portal/leases/view.php is the last surviving caller.
Effort: ~60-90 min (depends on UX decision shape — surface for operator at session open).
Dependencies: S-MILEAGE-2B SHIPPED (✓ 2026-05-12 — engine groundwork in place).
Discussed: 2026-05-12 in planning chat (S-MILEAGE-2B pre-C4 halt resolution, D-H refined).

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
- S-MILEAGE-2B-T1 PARTIAL-PASS — 8-step T1 visual walk completed in preview by Claude Code (commits 85cde5f IN-FLIGHT + this C2 docs commit). 6/8 steps PASS + 1 PARTIAL (Step 7) + 1 PASS (Step 8). **Steps 1-4 PASS**: lease activated → precharge_balance init'd ($500) + Invoice 1 auto-generated with mileage_precharge $500 line + send stamped precharge_invoiced_at + balance unchanged + audit_log entries (entity_types: lease_precharge_balance_init #926, lease_precharge_invoiced_at_stamp #928). **Step 5 PASS**: PRECHARGE_LOCKED 409 fires on both precharge_amount AND precharge_enabled changes (D113). **Step 6 PASS**: Invoice 2 drawdown emit produced mileage_usage $222.22 + mileage_drawdown_credit $222.22 (POSITIVE amount + is_credit=1 per K-16 convention D166); lease.precharge_balance decremented $500.00 → $277.78; audit_log entity_type=lease_precharge_balance_drawdown #932 captures old/new + drawdown_amount. **Step 7 PARTIAL**: ✓ D-F Financial Summary breakdown renders correctly (Mileage usage / Precharge credit applied / Net mileage charge); ✗ D-L Drawdown Reconciliation panel has 2 display-only bugs surfaced + filed as KNOWN ISSUES #104 + #105 in PROGRESS.md (panel renders "Period Distance: 0.00 km" because invoice_line_items.mileage_distance is NULL — InvoiceGenerator db_insert tuple missing the mileage_* columns; AND panel renders the Model B Lite ELSE-branch note instead of pre/applied/post balance rows because the audit_log JSON_EXTRACT lookup fails under PDO param binding). Financial math is 100% correct on both invoices; only the display panel shows wrong values. **Step 8 PASS**: no "Mileage Review" text, no "Review Required" label, no `mileage_review_status` references in DOM; Send button is plain "Send Invoice" (D-I retirement clean). **Test user pattern (K-1)**: Manager-role test user (id=22, claude-t1-20260512-093519@fleetforge.test) created with disposable 16-char hex password, used for browser automation, soft-deleted at tear-down. Test lease 172 (T1-WALK-20260512-093721) created via SQL (operator's "via UI" preference noted but lease-create form is S-MILEAGE-1's surface, not S-MILEAGE-2B's — created via SQL for control), walked through full Model B lifecycle, cancelled at tear-down with lease_status_log entry. Test invoices 237 + 238 voided + soft-deleted with structured void_reason; Path B counters reversed. Fixture infrastructure (samsara.fixture_mode='1' + equipment_unit 14 samsara_vehicle_id='FIX_STD') flipped at setup, restored at tear-down (mode was already 1; vehicle ID back to NULL). Smoke gates green throughout: PARITY OK + INVARIANTS OK (I1-I8) + samsara distance 16/16 + migrate 13/0/0. **Carry-forward:** S-MILEAGE-2B-FIX-0 (queued for ~30 min display-only fix: 2-line InvoiceGenerator.php db_insert + 1-line show.php CAST AS UNSIGNED + optional I9 smoke invariant + visual T1 re-walk on patched panel; not gating S-MILEAGE-3).
- S-MILEAGE-2B SHIPPED (9.5-commit arc: 7acde72 C1 IN-FLIGHT + d4923c7 C2 ENUM/dispatch + a24cb49 C3 drawdown emit + Samsara + 64b37cb C3.5 silent-bug cleanup + 840c71f C4 Model C retirement + INV-87 void+regen + 6ed9529 C5 send gate retirement + f7b7e87 C6 Drawdown Reconciliation panel + Odometer card + Financial Summary breakdown + 6dd449c C7 I8 invariant + T8/T10 real coverage + 47acbb9 C8 FLEETFORGE_SPEC_FINAL.md rewrite + this C9 docs commit — see PROGRESS.md SESSION LOG row) — Model B drawdown lifecycle complete on Invoice 2..N + Model C plumbing wholesale retired. 21 DECISIONS rows locked (D147-D167: D-A through D-S + K-16 emit-shape clarification D166 + D-H refinement D167). K-18 KEY LEARNING locked (stress test pre-population hiding fallback-path bugs — surfaced via the C3.5 silent JOIN bug fix). T1 visual walk pending operator per D146 pattern (8-step walk). New S-PORTAL-MILEAGE-MODEL-B session QUEUED in Architectural follow-ups (final Mileage::monthlyAllowance deletion + portal Model B refactor). FF_ASSET_VERSION 1.0.26 → 1.0.27 dev .env; filed under PREDEPLOY_CHECKLIST.md A2 per K-14. D131 gate clean on every commit: PARITY OK + INVARIANTS OK (I1-I7 through C6, I1-I8 from C7) + samsara distance 16/16 + migrate 11 → 13 ok / 0 drift / 0 missing. INV-87 void+regen produced INV-2026-00094 at $1370.52 (was $1280.71 broken Model C draft — operator-confirmed amount delta per Model B Lite D135 every-km billing; no D14 conflict since INV-87 never sent).
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
