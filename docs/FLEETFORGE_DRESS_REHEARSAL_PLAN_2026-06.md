# FleetForge Cutover Dress-Rehearsal — Execution Plan

**Session:** S-DRESS-REHEARSAL-RECON
**Date:** 2026-06-02
**Type:** Read-only recon + plan (no wipe, no seed, no DB mutation executed)
**Goal:** De-risk the destructive cutover dress rehearsal — wipe FF dev
transactional data, pull QBO-sandbox reference data, repopulate with
production-shaped data, and sync it all to the sandbox (realm
`9341457119548719`), exercising the cutover sequence end-to-end in **dev**
before the real production cutover (S-QBO-28/29/30).

> ⚠️ This document is a PLAN. Nothing in Parts A–E mutated state. The recon
> probes were read-only (`SELECT`, `SHOW COLUMNS`, file greps). No TRUNCATE,
> DELETE, pull-write, or seed was executed.

---

## 1. TOOLING INVENTORY SUMMARY

| Capability | Tool on disk | Status | Notes |
|---|---|---|---|
| Wipe transactional data | `scripts/demo_wipe.php` | ✅ EXISTS | ⚠️ **NO production guard** (see Safety) |
| Counter recompute post-wipe | inside `demo_wipe.php` | ✅ EXISTS | S-DEMO-WIPE-COUNTER-SYNC present (invoice + JE counters resync to MAX+1) |
| Pull QBO accounts | `AccountPuller::pullAll` | ✅ EXISTS | verified working (S-QBO-LIVE-VERIFY-RERUN) |
| Pull QBO customers | `CustomerPuller::pullAll` | ✅ EXISTS | side-effect-free; upserts to **map only**, not FF customers |
| Pull QBO vendors | `VendorPuller::pullAll` | ✅ EXISTS | same map-only pattern |
| Pull QBO tax codes | `TaxCodePuller::pullAll` | ✅ EXISTS | |
| Pull QBO items | `ItemPuller::pullAll` | ✅ EXISTS | |
| Cutover-import orchestrator | — | ❌ MISSING | no `CutoverImport` / `ReconciliationReview` artifact on disk |
| Auto-create FF customer from QBO | — | ❌ MISSING | `CustomerMatcher` only **matches** existing FF customers (see Gap G2) |
| Seed customers/leases/invoices | `scripts/demo_seed.php` | ✅ EXISTS | production-shaped; programmatic lease + invoice create |
| Programmatic lease create | `db_insert('leases', …)` in `demo_seed.php` | ✅ EXISTS | direct insert (no reusable LeaseService) — see Gap G3 |
| Invoice generation | `lib/Billing/InvoiceGenerator.php` (driven by `demo_seed.php`) | ✅ EXISTS | seed explicitly generates invoices per lease |
| Samsara bulk equipment import | `scripts/samsara_bulk_import.php` | ✅ EXISTS | requires live Samsara API — **not connected in dev** (see Gap G4) |
| Push FF → sandbox | Enqueuer → worker → `*Pusher` pipeline | ✅ EXISTS | the standard QBO sync path |

**Missing roadmap artifacts (verified absent on disk):**
`S-QBO-CUTOVER-WIPE`, `S-SAMSARA-FLEET-IMPORT`, `S-QBO-CUTOVER-IMPORT` do **not
exist** as named artifacts. The roadmap listed them; the operator's session
inventory did not. **Resolution: they were never built.** Their function is
partially covered by `demo_wipe.php` (wipe), `samsara_bulk_import.php` (fleet),
and the individual Pullers + push pipeline (import) — but there is **no unified
cutover-import orchestrator or reconciliation-review flow**.

---

## 2. WIPE SAFETY VERDICT

**`scripts/demo_wipe.php` has NO production guard. This is the #1 gap and a
hard blocker for a safe dress rehearsal.**

What it does:
- `SET FOREIGN_KEY_CHECKS = 0` then `TRUNCATE` across ~70 transactional tables.
- **Preserves:** `equipment_units` (+ resets their counters/status to
  `available`), `equipment_templates`, `acc_accounts` (COA), `acc_periods`,
  `settings`, `tax_rates`, `exchange_rates`, `yards`, users/roles/permissions,
  `portal_users`, `samsara_location_history`.
- **Recomputes counters post-wipe:** invoice `next_number` syncs to
  `MAX(invoice_number)+1` per year; JE counter reset. ✅ (S-DEMO-WIPE-COUNTER-SYNC).

What it does **NOT** do:
- ❌ No `APP_ENV` check. `APP_ENV` exists in `config/app.php` (defaults to
  `'production'`), but `demo_wipe.php` never reads it.
- ❌ No confirmation flag / `--yes` prompt.
- ❌ No DB-name check, no realm check, no interactive guard.

It connects via `config/app.php` and truncates whatever DB that config points
at. **If run with production config loaded, it would wipe production.**

**REQUIRED PRE-WIPE ADDITION (Gap G1):** add a guard to `demo_wipe.php` that
hard-refuses unless `APP_ENV !== 'production'` AND an explicit confirmation
token is passed (e.g. `php scripts/demo_wipe.php --i-understand-this-wipes-dev`).
Model it on the existing **D-QBO-FIXTURE-2 production guard** in
`QuickBooksClient` (refuses fixture mode when `environment='production'` OR a
real Intuit realm is set) — that pattern is already proven in this codebase.

---

## 3. STEP-BY-STEP EXECUTION SEQUENCE

> Each step lists the tool, whether it exists, and the gap (if any). Do **not**
> run any step until Gap G1 (wipe guard) is closed.

| # | Step | Tool / Session | Exists? |
|---|---|---|---|
| 0 | **Add wipe guard** (APP_ENV + confirm token) | new ~15-line edit to `demo_wipe.php` | ❌ BUILD (Gap G1) |
| 1 | **Verify dev context** — confirm `APP_ENV != production`, QBO `environment=sandbox`, realm = `9341457119548719` | manual `settings_get` probe | ✅ |
| 2 | **Wipe transactional data** | `scripts/demo_wipe.php` (guarded) | ✅ (after step 0) |
| 3 | **Refresh QBO token** — sandbox access token expired `2026-06-01`; refresh before any live pull | OAuth refresh (`QuickBooksClient`) | ✅ |
| 4 | **Pull reference data** (accounts, tax codes, items, vendors, customers) | `*Puller::pullAll` → map tables | ✅ |
| 5 | **Reconcile QBO customers → FF customers** | — | ❌ BUILD / decide (Gap G2) |
| 6 | **Seed equipment** | `samsara_bulk_import.php` (live) **or** keep existing 258 units **or** manual seed | ⚠️ decision (Gap G4) |
| 7 | **Seed leases** (~5–8, varied lifecycle) | `demo_seed.php` lease block (`db_insert('leases')`) | ✅ |
| 8 | **Generate invoices** | `demo_seed.php` → `InvoiceGenerator` | ✅ |
| 9 | **Seed payments / bills / JEs** | `demo_seed.php` + accounting seed | ✅ |
| 10 | **Run JE crons** (auto-post invoice/payment JEs) | existing cron / `AutoEntryBridge` | ✅ |
| 11 | **Sync all FF → sandbox** | Enqueuer → worker → `*Pusher` | ✅ |
| 12 | **Verify audit trail** — drift check, sync_log rows, map push_status | `DriftChecker::runCheck` + `/quickbooks/drift` | ✅ |

---

## 4. GAPS

- **G1 — Wipe has no production guard (BLOCKER).** `demo_wipe.php` truncates
  whatever DB the loaded config points at, with no `APP_ENV` / confirm check.
  **The dress rehearsal cannot safely proceed until a guarded wipe exists.**
  Fix: small edit modeled on D-QBO-FIXTURE-2.

- **G2 — No auto-create-from-QBO customer path.** `CustomerPuller::pullAll`
  upserts only into `acc_qbo_customer_map` (the QBO↔FF mapping table); the only
  caller, `api/v1/quickbooks/customers/pull.php`, writes the map, **not the FF
  `customers` table**. `CustomerMatcher` only **matches** pulled QBO customers
  against **pre-existing** FF customers (name/email/phone cascade) — it never
  creates an FF customer. So **"pull customers → have the same customers in FF"
  does NOT work out of the box.** Either (a) build a small
  "create-FF-customer-from-unmatched-map-row" helper, or (b) seed realistic FF
  customers first and push them to the sandbox (the demo_seed direction). See
  Scoping Decision SD1.

- **G3 — No reusable programmatic LeaseService.** Lease creation is done by
  direct `db_insert('leases', …)` inside `demo_seed.php` (plus the lease
  wizard UI). It is **not UI-only** — `demo_seed.php` already creates 20 leases
  programmatically with varied complexity, so ~5–8 dress-rehearsal leases are
  fully seedable. But there's no clean `LeaseService::create()` to call from a
  thin script; reuse/adapt `demo_seed.php`'s lease block. **(C3 stop-condition
  NOT triggered — leases can be seeded programmatically.)**

- **G4 — Samsara not connected in dev.** Only `samsara.fixture_mode` exists in
  `settings` (value `0`); there is **no Samsara API key/token** present. So
  `samsara_bulk_import.php` cannot pull real fleet data in dev. Equipment must
  be either (a) the 258 existing `equipment_units` (preserved by the wipe), or
  (b) manually seeded. See Scoping Decision SD2.

- **G5 — No cutover-import orchestrator / reconciliation-review flow.** The
  roadmap's `S-QBO-CUTOVER-IMPORT` was never built. Steps 4–5 are currently a
  sequence of individual Puller calls plus a (missing, per G2) customer
  reconcile. If the operator wants a single guided "pull + review + create"
  flow, that's a build session.

---

## 5. SCOPING DECISIONS (operator to decide before execution)

- **SD1 — Customer data source.** The sandbox (realm `9341457119548719`)
  currently holds **31 customers**, the bulk of which are **Intuit's default
  sandbox seed** — *Amy's Bird Sanctuary, Bill's Windsurf Shop, Cool Cars,
  Dukes Basketball Camp, Freeman Sporting Goods, Kookies by Kathy, Pye's
  Cakes, Weiskopf Consulting*, etc. — **not trucking customers**. Only 4 are
  realistic FF-pushed records mapped to FF customers (`LP Logistics Inc.`,
  `Shara Barnett`, `Sushi by Katsuyuki`, `Travis Waldron`). The remaining ~27
  are `qbo_only` Intuit defaults.
  **Choice:** (a) pull the nonsensical-but-real sandbox defaults into FF
  (requires G2 build), **or** (b) **[recommended]** seed ~10–15 realistic
  trucking customers in FF first, push them to the sandbox, then proceed — this
  exercises the same push path and yields production-shaped data without
  inheriting bird sanctuaries.

- **SD2 — Equipment source.** (a) Keep the 258 existing `equipment_units` (the
  wipe preserves them and resets counters), (b) wipe-and-manually-seed ~20–30
  production-shaped units, or (c) connect Samsara in dev and bulk-import
  (requires a Samsara API key — not present, G4).

- **SD3 — Cutover-import flow.** Run the dress rehearsal as a sequence of
  individual scripts/Puller calls (no new build beyond G1+G2), **or** invest in
  a guided cutover-import orchestrator (`S-QBO-CUTOVER-IMPORT`) first.

---

## 6. SAFETY

- **Target DB:** dev only. Step 1 must assert `APP_ENV != 'production'` before
  any wipe.
- **Target realm:** QBO sandbox `9341457119548719` only. Current state verified:
  `quickbooks.environment = sandbox`, `connection_status = connected`, realm
  present (16 chars). **Access token expired `2026-06-01` — refresh required
  (step 3) before any live pull.**
- **Wipe guard:** does **not exist yet** (Gap G1). Must be added before step 2.
  Model on D-QBO-FIXTURE-2 (production-realm refusal already in
  `QuickBooksClient`).
- **Fixture vs live:** `quickbooks.fixture_mode = 0`, `dry_run_mode = 0` — the
  pipeline is in **live** mode against the sandbox. The offline fixture testbed
  (S-QBO-OFFLINE-TESTBED) is available to dry-run the push pipeline **without**
  touching the sandbox, and is the recommended first pass before a live sync.
- **Never production:** this entire sequence runs against the dev DB + sandbox
  realm. No production `.php`, schema, or migration is touched.

---

## 7. CURRENT DEV STATE SNAPSHOT (read-only, 2026-06-02)

| Table | Rows |
|---|---|
| `customers` | 5 |
| `leases` | 42 |
| `invoices` | 88 |
| `vendors` | 5 |
| `equipment_units` | 258 |
| `acc_accounts` (COA) | 93 |
| `acc_qbo_customer_map` | 31 (≈27 Intuit defaults + 4 FF-pushed) |

---

## 8. RECOMMENDED PATH (minimal-build dress rehearsal)

1. **Build G1** (wipe guard) — small, mandatory.
2. **Decide SD1 = (b)** seed realistic trucking customers in FF, push to sandbox
   (sidesteps G2 build and the Intuit-defaults problem).
3. **Decide SD2 = (a)** keep existing equipment (wipe preserves it) or seed a
   focused ~20–30 set; skip Samsara (G4).
4. Dry-run the push pipeline against the **fixture** first (S-QBO-OFFLINE-TESTBED).
5. Then run the live sequence (steps 2–12) against dev DB + sandbox.

This avoids building G2 (customer auto-create) and G5 (cutover orchestrator)
for the rehearsal, while still exercising wipe → seed → push → drift-verify
end-to-end.
