# D131 Baseline Restore — 2026-05-26

**Session:** S-D131-BASELINE-RESTORE
**Type:** Read-only diagnosis + scope-bounded triage (no in-scope fixes applied)
**Trigger:** Process integrity P0 — prior SESSION LOG rows asserted "D131 22/22 PASS" without running the full gate. Predecessor session (S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE) caught the drift by running the full gate end-to-end and surfaced 3 reported failures (model_b S20, samsara T14, settings_endpoints). This session re-verifies each failure individually, classifies, and establishes the true baseline.

---

## Baseline state — actual D131 result

**20/22 PASS** (was over-asserted as 22/22 in multiple prior SESSION LOG rows). Two persistent failures:

| # | Smoke | Result | Sub-check | Failure type |
|---|---|---|---|---|
| 4 | `_smoke_model_b_lifecycle.php` | 19/20 FAIL | **S07** (NOT S20 as prior report claimed) | FIXTURE DRIFT |
| 22 | `_smoke_settings_endpoints.php` | FAIL | login bootstrap | ENVIRONMENTAL |

20 passing smokes — full breakdown:

```
PARITY OK — master matches live DB
INVARIANTS OK — I1–I10
samsara_distance:        16/16 PASS  ← previous reports of T14 failing were inaccurate
doc_freshness:           17/17 PASS
qbo_client:               9/9 PASS
qbo_queue:                9/9 PASS
qbo_admin_ui:             8/8 PASS
oauth_state:              9/9 PASS
qbo_customer_mapping:    12/12 PASS
qbo_customer_push:       16/16 PASS
qbo_vendor_mapping:      12/12 PASS
qbo_vendor_push:         14/14 PASS
qbo_account_mapping:     42/42 PASS  ← bumped 29→42 in S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE
qbo_tax_code_mapping:    14/14 PASS
qbo_item_mapping:        18/18 PASS
qbo_invoice_push:        41/41 PASS
intelligence_tab:        12/12 PASS
intelligence_v2:         30/30 PASS
settings_roundtrip:      95/95 PASS (1 SKIP)
settings_form_keys:      24/24 PASS
migrate:                  64 ok / 0 drift / 0 missing
```

---

## Failure 1 — `_smoke_model_b_lifecycle.php` S07

**Predecessor reports were wrong about the sub-check ID.** S-QBO-PUSHER-CONTRACT-PAYDOWN and S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE both cited "S20 INV-2026-00001 duplicate", but the actual failing sub-check is **S07 (multi-invoice drawdown exhausts then bypasses)**. S20 passes — it runs AFTER S07's failure because `s5_scenario()` wraps each sub-check in its own BEGIN/ROLLBACK (so S07's exception doesn't terminate the suite).

### Failure summary

- File: `tests/_smoke_model_b_lifecycle.php:414` (S07 sub-check entry; assertion structure at `:449-461`)
- Exception: `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'INV-2026-00001' for key 'invoices.invoice_number'` at `includes/db.php:109`
- Sub-check intent: build a lease with precharge_balance=$300 and walk it through 4 monthly invoices (2026-02 → 2026-05), asserting that the first 3 emit `mileage_drawdown_credit=$100` each (300 → 200 → 100 → 0), the 4th has NO drawdown credit (balance is 0), and that the 4th has a `mileage_usage=$100` line representing the over-balance distance charge.
- Where it dies: the 2nd `InvoiceGenerator::createFromLease()` call inside S07's `foreach ($periods as $p)` loop, when `generateInvoiceNumber()` attempts to insert `INV-2026-00001`.

### Production code path exercised

- `lib/Billing/InvoiceGenerator.php:1732` — `generateInvoiceNumber()` reads/increments `settings.invoice.next_number.{YYYY}` atomically under `FOR UPDATE` lock, formats as `INV-YYYY-NNNNN`.
- `invoices.invoice_number` is `UNIQUE` (D15 + D20 gap-free counter).

### Root cause

The `invoice.next_number.2026` settings counter is at **0**, but the live `invoices` table already contains `INV-2026-00001` through `INV-2026-00110` (88 real 2026 invoices, max = `INV-2026-00110`).

- Invoice 1 of S07: counter reads `0` → generates `INV-2026-00000` (no existing row) → INSERT OK → counter increments to `1`
- Invoice 2 of S07: counter reads `1` → generates `INV-2026-00001` → **collides with existing real invoice id=1** → `UNIQUE` constraint fires → exception → ROLLBACK reverts the entire S07 scenario (including the counter increment, so counter stays at `0`).

This is the *exact* recurrence of the bug fixed by `S-QBO-11-POSTVERIFY-FIXES` 2026-05-25, where the counter was bumped from `0` to `111`. Something between then and 2026-05-26 reset the counter back to `0`. No commits in that window touched `lib/Billing/InvoiceGenerator.php` or any cron that writes the counter, so the reset was either an out-of-band SQL `UPDATE` (manual via mysql client) or `scripts/demo_wipe.php` running and DELETEing the row (which subsequent app activity may have re-INSERTed at value=0 via `generateInvoiceNumber()` itself? — actually no, the INSERT branch in InvoiceGenerator.php:1755-1759 writes `(string)($next + 1)` so first-call would set value=1, not 0). The exact reset path is unknown; what matters is the current state.

### Most recent commit that touched relevant files

- Smoke: `e16d3f6 S-MILEAGE-5 C2 — 20 hermetic Model B lifecycle scenarios + I10 invariant` (S07 added in this commit; no subsequent edits).
- InvoiceGenerator: `52782ef S-BILLING-HOLISTIC-ENGINE` (last touched the counter logic — but that commit didn't change `generateInvoiceNumber()`; the function has been stable through several earlier sessions).

### Classification: **FIXTURE DRIFT**

The smoke is structurally correct. The production code is correct. The DB state (counter setting) is out of sync with `invoices.invoice_number` reality.

### Disposition this session: **DEFERRED**

The fix is a one-line SQL `UPDATE`:

```sql
UPDATE settings SET value = '111' WHERE `key` = 'invoice.next_number.2026';
```

Per the prompt's PART B in-scope criteria ("Fix is ≤10 lines in the smoke file ONLY"), a DB state UPDATE is not a smoke file change. A precedent for the exact fix exists (`S-QBO-11-POSTVERIFY-FIXES` 2026-05-25 bumped 0 → 111), but it's not in this session's scope.

### Follow-up scope for fix session

A 1-commit session can:
1. `UPDATE settings SET value = '200' WHERE \`key\` = 'invoice.next_number.2026'` — bump above current max INV-2026-00110 with a safety margin so future smoke runs that COMMIT (rare but possible) don't immediately re-collide.
2. Verify `model_b S07` passes by running `php tests/_smoke_model_b_lifecycle.php` (expect 20/20).
3. Optionally add a 1-line pre-flight read inside `_smoke_model_b_lifecycle.php` that WARNs (not fails) if `MAX(invoice_number)` exceeds `next_number-1`, so future drift surfaces with a clear hint instead of an opaque UNIQUE constraint exception.
4. SESSION LOG row + commit + push.

---

## Failure 2 — `_smoke_samsara_distance.php` T14

### Failure summary

T14 (`precharge_invoice_emit`) was reported failing in S-QBO-PUSHER-CONTRACT-PAYDOWN and S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE. **Current state: 16/16 PASS.** Re-ran 3 times in this session, all PASS.

### Production code path exercised

T14 (`tests/_smoke_samsara_distance.php:295-378`) inserts a synthetic lease with `precharge_enabled=1`, calls `InvoiceGenerator::createFromLease()` for one period, and asserts the generated invoice has a `mileage_precharge` line with `amount=$500.00`, `unit='precharge'`, `quantity=1`, `taxable=1`, `is_credit=0`. The entire scenario is wrapped in BEGIN/ROLLBACK at the file level (lines 321 + 377).

### Most recent commit that touched relevant files

- Smoke: `6dd449c S-MILEAGE-2B C7 — Smoke I8 (drawdown math sanity) + T8/T10 real coverage` (T14 added in `365d541 S-MILEAGE-2A C5`).
- InvoiceGenerator: same as Failure 1.

### Classification: **PREVIOUS REPORTING ERROR — likely state-dependent flake**

T14 generates ONE invoice. With counter=0 (current state), `generateInvoiceNumber()` produces `INV-2026-00000`, which doesn't collide with any existing invoice. With counter=1 (a possible historical state), it would produce `INV-2026-00001` and collide. The earlier failure reports likely fired in a state where the counter was at `1+`. Now that it's at `0`, T14 passes.

This means the predecessor SESSION LOG rows under-reported the failure surface: they correctly identified TWO smokes failing (model_b + settings_endpoints) and INCORRECTLY identified samsara as a third failure when its passing/failing actually depends on the counter state at run time. The pattern is genuinely flaky: under counter=0, T14 passes; under counter=1, T14 fails (same collision path as model_b S07's second invoice).

### Disposition this session: **NO FIX (CURRENTLY PASSING)**

Once the counter is bumped (Failure 1's fix), T14 will continue to pass under all reasonable counter values until the counter again reaches a state that collides with existing invoices — which is the same root-cause condition as model_b S07. Fixing Failure 1 fixes the underlying class.

### Follow-up scope

None directly. After the counter bump in Failure 1's follow-up session, re-running samsara should remain 16/16. If it flakes again post-fix, the same DB state hygiene problem has recurred and the fix session should include a permanent guard (e.g. a pre-flight in either the smoke or in `generateInvoiceNumber()` that detects counter-vs-max-invoice drift and emits a WARN to error_log).

---

## Failure 3 — `_smoke_settings_endpoints.php`

### Failure summary

- File: `tests/_smoke_settings_endpoints.php`
- Error: `Login page returned 0 — preview server not running?`
- Where it dies: the very first HTTP call in the smoke, against `http://localhost:8899/fleetforge/auth/login`. curl returns status `0` because there is no listener on port 8899.

### Production code path exercised

The smoke is purely end-to-end HTTP: it expects a running PHP development server (`php -S localhost:8899 -t public/`) and uses `curl` to log in as `test-superadmin@fleetforge.test` (no-MFA test user, password from the smoke file), then walks every Settings UI endpoint. The smoke header (lines 38-41) declares the prerequisite explicitly.

### Most recent commit that touched relevant files

- Smoke: `7de5111 S-SETTINGS-AUDIT-3: fix 3 broken Intelligence/System endpoints surfaced by full Settings audit`. No subsequent edits.
- Production code: no relevant commits in the past week — the API endpoints the smoke hits are stable.

### Classification: **ENVIRONMENTAL**

Smoke is correct. Production endpoints are correct. The test runner environment lacks a running PHP dev server on port 8899. This smoke is the only D131 gate member that has this prerequisite; the rest are pure DB/static-code-inspection smokes that don't need any HTTP listener.

### Disposition this session: **DEFERRED**

Fix is not a smoke file change. It's either:
1. Start a `php -S` process before running the gate (operator-side or harness-side change), or
2. Refactor the smoke to spawn its own ephemeral PHP server (10–20 lines of `proc_open` + signal handling for cleanup), or
3. Have D131 gate pre-flight check whether port 8899 is reachable and SKIP this smoke if not (treating it as conditional rather than always-required).

None of those fit "≤10 lines in the smoke file ONLY" as a trivial fix per the prompt's scope criteria.

### Follow-up scope

A small dedicated session can:
1. Choose option 1, 2, or 3 above (option 3 is the most flexible — a "preview-server-required" tag that the gate skips when unreachable, with a hard PASS bar of "if preview server is up, all 22 sub-checks must pass").
2. If option 2: add a `proc_open()`-based dev-server lifecycle into the smoke's setup/teardown blocks (cleanup must kill the server PID under all exit paths including PHP fatal errors — use `pcntl_signal()` + a `register_shutdown_function()` for SIGTERM hygiene).
3. SESSION LOG row + commit + push.

Until then, the gate is effectively a 21-smoke gate when run from a workstation without a preview server, and the SESSION LOG SC column should report `21/22 PASS (settings_endpoints requires preview server)` rather than `22/22 PASS`.

---

## Process insight — why this drift went undetected

**Root cause:** Multiple prior sessions asserted "D131 22/22 PASS" in their SESSION LOG SC column without actually running all 22 smokes. They likely ran only the smokes they directly touched (e.g. their own QBO smoke files) plus a handful of representative gates (parity, doc_freshness, migrate), then assumed the rest were still green because their changes shouldn't have broken anything. That assumption held *for their changes* — but the gate had been silently degraded by an unrelated cause (the counter drift) before they ran.

The deeper problem is that the SC column became aspirational: it described the gate's *theoretical* clean state rather than the *observed* state at commit time. Once one session got away with reporting 22/22 when actual was 19/22 (or 20/22), the next session inherited the false floor and the next assertion compounded.

S-QBO-VALIDATOR-GATE-SMOKE-COVERAGE earlier today caught the drift by running the full gate end-to-end and reporting `19/22 PASS` with the 3 named pre-existing failures. That session correctly identified TWO of three real failures (model_b + settings_endpoints) but incorrectly included samsara T14 in the failure list — likely because samsara T14 was failing at run time due to whatever transient counter state existed in that session. This session's run shows T14 passing under the current counter state (0), confirming the prior reports were state-dependent rather than persistent.

## Recommendation (new discipline)

**D-D131-DISCIPLINE** — locked in this session's PROGRESS.md DECISIONS row.

SESSION LOG SC column going forward MUST state the actual D131 result observed at commit time, never aspirational state. Every session must:

1. Run the full 22-smoke gate end-to-end before commit. Capture per-smoke counts.
2. State the pre-existing-failure landscape explicitly: "D131 N/22 PASS — failing: [list with one-line classification per failure]".
3. If a session legitimately improves the gate (e.g. closes a previously-failing smoke), the SC column states the new count + previous count for trail visibility: "D131 N/22 PASS (was N-1/22; fixed X by Y)".
4. If a session degrades the gate, the SESSION LOG row must explicitly call out the regression in its description and either justify it or fix it before commit.

Aspirational state ("D131 22/22 PASS" when actual is 20/22) is a discipline violation. Future audit sessions should periodically run the full gate to detect drift; this session establishes the audit baseline.

---

## Touched files this session

- `docs/D131_BASELINE_RESTORE_2026-05-26.md` (NEW — this file)
- `docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md` (+§12.6 pointer)
- `docs/FLEETFORGE_PROGRESS.md` (SESSION LOG row + DECISIONS row D-D131-DISCIPLINE)
- `docs/FLEETFORGE_CURRENT_SESSIONS.md` (SHIPPED entry under 2026-05-26)

No smoke files modified. No production .php files modified. No DB state modified. No schema/migration motion.

## Post-session new baseline

**D131 20/22 PASS.** Two persistent failures (model_b S07 FIXTURE DRIFT, settings_endpoints ENVIRONMENTAL) both DEFERRED with documented follow-up scope above. Samsara T14 is currently passing; was a previously-reported phantom failure rooted in state-dependent counter behavior.
